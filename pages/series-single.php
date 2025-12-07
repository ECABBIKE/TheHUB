<?php
/**
 * V3 Series Single Page - Series standings with per-event points
 * Uses series_results table for series-specific points (matches V2)
 * Club standings use 100%/50% rule from club-points-system.php
 */

require_once HUB_V3_ROOT . '/includes/club-points-system.php';

$db = hub_db();
$seriesId = intval($pageInfo['params']['id'] ?? 0);

if (!$seriesId) {
    header('Location: /series');
    exit;
}

// Check if series_results table exists and has data for this series
$useSeriesResults = false;
try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM series_results WHERE series_id = ?");
    $stmt->execute([$seriesId]);
    $useSeriesResults = ($stmt->fetchColumn() > 0);
} catch (Exception $e) {
    // Table doesn't exist yet, use old system
    $useSeriesResults = false;
}

try {
    // Fetch series details
    $stmt = $db->prepare("
        SELECT s.*, COUNT(DISTINCT e.id) as event_count
        FROM series s
        LEFT JOIN events e ON s.id = e.series_id
        WHERE s.id = ?
        GROUP BY s.id
    ");
    $stmt->execute([$seriesId]);
    $series = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$series) {
        include HUB_V3_ROOT . '/pages/404.php';
        return;
    }

    // Get filter parameters
    $selectedClass = isset($_GET['class']) ? $_GET['class'] : 'all';
    $searchName = isset($_GET['search']) ? trim($_GET['search']) : '';

    // Get all events in this series (using series_events junction table like V2)
    $stmt = $db->prepare("
        SELECT
            e.id,
            e.name,
            e.date,
            e.location,
            se.template_id,
            COUNT(DISTINCT r.id) as result_count
        FROM series_events se
        JOIN events e ON se.event_id = e.id
        LEFT JOIN results r ON e.id = r.event_id
        WHERE se.series_id = ?
        GROUP BY e.id
        ORDER BY e.date ASC
    ");
    $stmt->execute([$seriesId]);
    $seriesEvents = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // If no series_events, fall back to events with series_id
    if (empty($seriesEvents)) {
        $stmt = $db->prepare("
            SELECT
                e.id,
                e.name,
                e.date,
                e.location,
                NULL as template_id,
                COUNT(DISTINCT r.id) as result_count
            FROM events e
            LEFT JOIN results r ON e.id = r.event_id
            WHERE e.series_id = ?
            GROUP BY e.id
            ORDER BY e.date ASC
        ");
        $stmt->execute([$seriesId]);
        $seriesEvents = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Debug: Log event count
    error_log("SERIES {$seriesId}: Found " . count($seriesEvents) . " events, series year: " . ($series['year'] ?? 'NULL'));

    // Filter events that have templates (these will show in standings columns)
    $eventsWithPoints = array_filter($seriesEvents, function($e) {
        return !empty($e['template_id']);
    });
    // If no template-based events, use all events
    if (empty($eventsWithPoints)) {
        $eventsWithPoints = $seriesEvents;
    }

    // Get all classes that have results in this series (only series-eligible classes that award points)
    $stmt = $db->prepare("
        SELECT DISTINCT c.id, c.name, c.display_name, c.sort_order,
               COUNT(DISTINCT r.cyclist_id) as rider_count
        FROM classes c
        JOIN results r ON c.id = r.class_id
        JOIN events e ON r.event_id = e.id
        WHERE e.series_id = ?
          AND COALESCE(c.series_eligible, 1) = 1
          AND COALESCE(c.awards_points, 1) = 1
        GROUP BY c.id
        ORDER BY c.sort_order ASC
    ");
    $stmt->execute([$seriesId]);
    $activeClasses = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Build standings
    $standingsByClass = [];
    $countBest = $series['count_best_results'] ?? null;

    // Get class filter condition
    $classFilter = '';
    $params = [$seriesId];
    if ($selectedClass !== 'all' && is_numeric($selectedClass)) {
        $classFilter = 'AND r.class_id = ?';
        $params[] = $selectedClass;
    }

    // Get all riders who have results in this series (check both events.series_id AND series_events table)
    $stmt = $db->prepare("
        SELECT DISTINCT
            riders.id,
            riders.firstname,
            riders.lastname,
            riders.birth_year,
            c.name as club_name,
            cls.id as class_id,
            cls.name as class_name,
            cls.display_name as class_display_name,
            cls.sort_order as class_sort_order
        FROM riders
        LEFT JOIN clubs c ON riders.club_id = c.id
        JOIN results r ON riders.id = r.cyclist_id
        JOIN events e ON r.event_id = e.id
        LEFT JOIN series_events se ON e.id = se.event_id AND se.series_id = ?
        LEFT JOIN classes cls ON r.class_id = cls.id
        WHERE (e.series_id = ? OR se.series_id = ?)
          AND COALESCE(cls.series_eligible, 1) = 1
          AND COALESCE(cls.awards_points, 1) = 1
          {$classFilter}
        ORDER BY cls.sort_order ASC, riders.lastname, riders.firstname
    ");
    // Add extra params for the series_id checks
    $queryParams = [$seriesId, $seriesId, $seriesId];
    if ($selectedClass !== 'all' && is_numeric($selectedClass)) {
        $queryParams[] = $selectedClass;
    }
    $stmt->execute($queryParams);
    $ridersInSeries = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // For each rider, get their points from each event
    foreach ($ridersInSeries as $rider) {
        $fullname = $rider['firstname'] . ' ' . $rider['lastname'];

        // Apply name search filter early
        if ($searchName !== '' && stripos($fullname, $searchName) === false) {
            continue;
        }

        $riderData = [
            'rider_id' => $rider['id'],
            'firstname' => $rider['firstname'],
            'lastname' => $rider['lastname'],
            'club_name' => $rider['club_name'],
            'class_name' => $rider['class_display_name'] ?? $rider['class_name'] ?? 'Okänd',
            'class_id' => $rider['class_id'],
            'event_points' => [],
            'excluded_events' => [],
            'total_points' => 0
        ];

        // Get points for each event
        $allPoints = [];
        foreach ($seriesEvents as $event) {
            $points = 0;

            if ($useSeriesResults) {
                // Use series_results table (series-specific points)
                $stmt = $db->prepare("
                    SELECT points
                    FROM series_results
                    WHERE series_id = ? AND cyclist_id = ? AND event_id = ? AND class_id <=> ?
                    LIMIT 1
                ");
                $stmt->execute([$seriesId, $rider['id'], $event['id'], $rider['class_id']]);
            } else {
                // Fallback: Use results.points
                $stmt = $db->prepare("
                    SELECT points
                    FROM results
                    WHERE cyclist_id = ? AND event_id = ? AND class_id = ?
                    LIMIT 1
                ");
                $stmt->execute([$rider['id'], $event['id'], $rider['class_id']]);
            }

            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $points = $result ? (int)$result['points'] : 0;

            $riderData['event_points'][$event['id']] = $points;
            if ($points > 0) {
                $allPoints[] = ['event_id' => $event['id'], 'points' => $points];
            }
        }

        // Apply count_best_results rule
        if ($countBest && count($allPoints) > $countBest) {
            // Sort by points descending
            usort($allPoints, function($a, $b) {
                return $b['points'] - $a['points'];
            });

            // Mark events beyond the best X as excluded
            for ($i = $countBest; $i < count($allPoints); $i++) {
                $riderData['excluded_events'][$allPoints[$i]['event_id']] = true;
            }

            // Sum only the best results
            for ($i = 0; $i < $countBest; $i++) {
                $riderData['total_points'] += $allPoints[$i]['points'];
            }
        } else {
            // Sum all points
            foreach ($allPoints as $p) {
                $riderData['total_points'] += $p['points'];
            }
        }

        // Skip 0-point riders
        if ($riderData['total_points'] > 0) {
            $classKey = $rider['class_id'] ?? 0;
            if (!isset($standingsByClass[$classKey])) {
                $standingsByClass[$classKey] = [
                    'class_id' => $rider['class_id'],
                    'class_name' => $rider['class_name'] ?? 'Oklassificerad',
                    'class_display_name' => $rider['class_display_name'] ?? 'Oklassificerad',
                    'class_sort_order' => $rider['class_sort_order'] ?? 999,
                    'riders' => []
                ];
            }
            $standingsByClass[$classKey]['riders'][] = $riderData;
        }
    }

    // Sort riders within each class by total points
    foreach ($standingsByClass as &$classData) {
        usort($classData['riders'], function($a, $b) {
            return $b['total_points'] - $a['total_points'];
        });
    }
    unset($classData);

    // Sort classes by sort_order
    uasort($standingsByClass, function($a, $b) {
        return ($a['class_sort_order'] ?? 999) - ($b['class_sort_order'] ?? 999);
    });

    $totalParticipants = count($ridersInSeries);

    // Calculate club standings with 100%/50% rule
    // Best rider per club/class/event = 100%, second best = 50%, others = 0%
    $clubStandings = [];
    $clubRiderContributions = []; // Track individual rider contributions

    foreach ($seriesEvents as $event) {
        $eventId = $event['id'];

        // Get all results for this event with series points, grouped by club and class
        // Only include riders with clubs and classes that award points
        if ($useSeriesResults) {
            $stmt = $db->prepare("
                SELECT
                    sr.cyclist_id,
                    sr.class_id,
                    sr.points,
                    rd.firstname,
                    rd.lastname,
                    c.id as club_id,
                    c.name as club_name,
                    cls.name as class_name,
                    cls.display_name as class_display_name
                FROM series_results sr
                JOIN riders rd ON sr.cyclist_id = rd.id
                LEFT JOIN clubs c ON rd.club_id = c.id
                LEFT JOIN classes cls ON sr.class_id = cls.id
                WHERE sr.series_id = ? AND sr.event_id = ?
                AND c.id IS NOT NULL
                AND sr.points > 0
                AND COALESCE(cls.series_eligible, 1) = 1
                AND COALESCE(cls.awards_points, 1) = 1
                ORDER BY c.id, sr.class_id, sr.points DESC
            ");
            $stmt->execute([$seriesId, $eventId]);
        } else {
            // Fallback to results table
            $stmt = $db->prepare("
                SELECT
                    r.cyclist_id,
                    r.class_id,
                    r.points,
                    rd.firstname,
                    rd.lastname,
                    c.id as club_id,
                    c.name as club_name,
                    cls.name as class_name,
                    cls.display_name as class_display_name
                FROM results r
                JOIN riders rd ON r.cyclist_id = rd.id
                LEFT JOIN clubs c ON rd.club_id = c.id
                LEFT JOIN classes cls ON r.class_id = cls.id
                WHERE r.event_id = ?
                AND r.status = 'finished'
                AND c.id IS NOT NULL
                AND r.points > 0
                AND COALESCE(cls.series_eligible, 1) = 1
                AND COALESCE(cls.awards_points, 1) = 1
                ORDER BY c.id, r.class_id, r.points DESC
            ");
            $stmt->execute([$eventId]);
        }
        $eventResults = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Group by club and class
        $clubClassResults = [];
        foreach ($eventResults as $result) {
            $key = $result['club_id'] . '_' . $result['class_id'];
            if (!isset($clubClassResults[$key])) {
                $clubClassResults[$key] = [];
            }
            $clubClassResults[$key][] = $result;
        }

        // Apply 100%/50% rule for each club/class combo
        foreach ($clubClassResults as $riders) {
            $rank = 1;
            foreach ($riders as $rider) {
                $clubId = $rider['club_id'];
                $clubName = $rider['club_name'];
                $originalPoints = (float)$rider['points'];
                $clubPoints = 0;
                $percentage = 0;

                if ($rank === 1) {
                    $clubPoints = $originalPoints;
                    $percentage = 100;
                } elseif ($rank === 2) {
                    $clubPoints = round($originalPoints * 0.5, 0);
                    $percentage = 50;
                }

                // Initialize club if not exists
                if (!isset($clubStandings[$clubId])) {
                    $clubStandings[$clubId] = [
                        'club_id' => $clubId,
                        'club_name' => $clubName,
                        'riders' => [],
                        'total_points' => 0,
                        'event_points' => [],
                        'rider_count' => 0,
                        'scoring_riders' => 0
                    ];
                    foreach ($seriesEvents as $e) {
                        $clubStandings[$clubId]['event_points'][$e['id']] = 0;
                    }
                }

                // Add club points for this event
                $clubStandings[$clubId]['event_points'][$eventId] += $clubPoints;
                $clubStandings[$clubId]['total_points'] += $clubPoints;

                // Track rider contribution
                $riderId = $rider['cyclist_id'];
                $riderKey = $clubId . '_' . $riderId;
                if (!isset($clubRiderContributions[$riderKey])) {
                    $clubRiderContributions[$riderKey] = [
                        'rider_id' => $riderId,
                        'club_id' => $clubId,
                        'name' => $rider['firstname'] . ' ' . $rider['lastname'],
                        'class_name' => $rider['class_display_name'] ?? $rider['class_name'],
                        'total_club_points' => 0,
                        'events_scored' => 0
                    ];
                }
                $clubRiderContributions[$riderKey]['total_club_points'] += $clubPoints;
                if ($clubPoints > 0) {
                    $clubRiderContributions[$riderKey]['events_scored']++;
                }

                $rank++;
            }
        }
    }

    // Add riders to their clubs and count
    foreach ($clubRiderContributions as $riderData) {
        $clubId = $riderData['club_id'];
        if (isset($clubStandings[$clubId])) {
            $clubStandings[$clubId]['riders'][] = [
                'rider_id' => $riderData['rider_id'],
                'name' => $riderData['name'],
                'class_name' => $riderData['class_name'],
                'points' => $riderData['total_club_points']
            ];
            $clubStandings[$clubId]['rider_count']++;
            if ($riderData['total_club_points'] > 0) {
                $clubStandings[$clubId]['scoring_riders']++;
            }
        }
    }

    // Sort clubs by total points
    uasort($clubStandings, function($a, $b) {
        return $b['total_points'] - $a['total_points'];
    });
    // Sort riders within each club by points
    foreach ($clubStandings as &$club) {
        usort($club['riders'], function($a, $b) {
            return $b['points'] - $a['points'];
        });
    }
    unset($club);

} catch (Exception $e) {
    $error = $e->getMessage();
    $series = null;
}

if (!$series) {
    include HUB_V3_ROOT . '/pages/404.php';
    return;
}
?>

<?php if (isset($error)): ?>
<section class="card mb-lg">
  <div class="card-title" style="color: var(--color-error)">Fel</div>
  <p><?= htmlspecialchars($error) ?></p>
</section>
<?php endif; ?>

<!-- Series Info Card -->
<section class="info-card mb-md">
  <div class="info-card-stripe"></div>
  <div class="info-card-content">
    <div class="info-card-main">
      <h1 class="info-card-title"><?= htmlspecialchars($series['name']) ?></h1>
      <div class="info-card-meta">
        <span><?= $series['year'] ?></span>
        <?php if (!empty($series['count_best_results'])): ?>
          <span class="info-card-sep">•</span>
          <span>Räknar <?= $series['count_best_results'] ?> bästa</span>
        <?php endif; ?>
        <?php if (!empty($series['description'])): ?>
          <span class="info-card-sep">•</span>
          <span><?= htmlspecialchars($series['description']) ?></span>
        <?php endif; ?>
      </div>
    </div>
    <div class="info-card-stats">
      <div class="info-card-stat">
        <span class="info-card-stat-value"><?= count($seriesEvents) ?></span>
        <span class="info-card-stat-label">tävlingar</span>
      </div>
      <div class="info-card-stat">
        <span class="info-card-stat-value"><?= $totalParticipants ?></span>
        <span class="info-card-stat-label">deltagare</span>
      </div>
    </div>
  </div>
</section>

<!-- Collapsible Events Section -->
<details class="events-dropdown mb-md">
  <summary class="events-dropdown-header">
    <span class="events-dropdown-icon">📅</span>
    <span class="events-dropdown-title">Tävlingar i serien</span>
    <span class="events-dropdown-count"><?= count($seriesEvents) ?> st</span>
    <span class="events-dropdown-arrow">▾</span>
  </summary>
  <div class="events-dropdown-content">
    <?php $eventNum = 1; ?>
    <?php foreach ($seriesEvents as $event): ?>
    <a href="/event/<?= $event['id'] ?>" class="event-dropdown-item">
      <span class="event-item-num">#<?= $eventNum ?></span>
      <span class="event-item-date"><?= $event['date'] ? date('j M', strtotime($event['date'])) : '-' ?></span>
      <span class="event-item-name"><?= htmlspecialchars($event['name']) ?></span>
      <span class="event-item-results"><?= $event['result_count'] ?> resultat</span>
    </a>
    <?php $eventNum++; ?>
    <?php endforeach; ?>
  </div>
</details>

<!-- Standings Type Tabs -->
<div class="standings-tabs mb-md">
  <button class="standings-tab active" data-tab="individual" onclick="switchStandingsTab('individual')">
    👤 Individuellt
  </button>
  <button class="standings-tab" data-tab="club" onclick="switchStandingsTab('club')">
    🛡️ Klubbmästerskap
  </button>
</div>

<!-- Individual Standings Section -->
<div id="individual-standings">

<!-- Filters: Class + Search -->
<div class="filter-row mb-lg">
  <div class="filter-field">
    <label class="filter-label">Klass</label>
    <select class="filter-select" id="classFilter" onchange="applyFilters()">
      <option value="all" <?= $selectedClass === 'all' ? 'selected' : '' ?>>Alla klasser</option>
      <?php foreach ($activeClasses as $class): ?>
      <option value="<?= $class['id'] ?>" <?= $selectedClass == $class['id'] ? 'selected' : '' ?>>
        <?= htmlspecialchars($class['display_name'] ?? $class['name']) ?> (<?= $class['rider_count'] ?>)
      </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="filter-field">
    <label class="filter-label">Sök åkare</label>
    <input type="text" class="filter-input" id="searchFilter" placeholder="Namn..." value="<?= htmlspecialchars($searchName) ?>" oninput="applyFilters()">
  </div>
</div>

<!-- Standings by Class -->
<?php if (empty($standingsByClass)): ?>
<section class="card">
  <div class="empty-state">
    <div class="empty-state-icon">🏆</div>
    <p><?= $searchName ? 'Inga resultat för "' . htmlspecialchars($searchName) . '"' : 'Inga resultat registrerade ännu' ?></p>
  </div>
</section>
<?php else: ?>

<?php foreach ($standingsByClass as $classData): ?>
<section class="card mb-lg standings-card class-section" data-class="<?= $classData['class_id'] ?>">
  <div class="card-header">
    <div>
      <h2 class="card-title"><?= htmlspecialchars($classData['class_display_name'] ?? $classData['class_name']) ?></h2>
      <p class="card-subtitle"><?= count($classData['riders']) ?> deltagare</p>
    </div>
  </div>

  <div class="table-wrapper">
    <table class="table table--striped standings-table">
      <thead>
        <tr>
          <th class="col-place">#</th>
          <th class="col-rider">Åkare</th>
          <th class="col-club table-col-hide-portrait">Klubb</th>
          <?php $eventNum = 1; ?>
          <?php foreach ($eventsWithPoints as $event): ?>
          <th class="col-event" style="display:table-cell !important;visibility:visible !important;" title="<?= htmlspecialchars($event['name']) ?>">
            #<?= $eventNum ?>
          </th>
          <?php $eventNum++; ?>
          <?php endforeach; ?>
          <th class="col-total">Totalt</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($classData['riders'] as $pos => $rider):
            $searchData = strtolower($rider['firstname'] . ' ' . $rider['lastname'] . ' ' . ($rider['club_name'] ?? ''));
        ?>
        <tr class="result-row" onclick="window.location='/rider/<?= $rider['rider_id'] ?>'" style="cursor:pointer" data-search="<?= htmlspecialchars($searchData) ?>">
          <td class="col-place <?= ($pos + 1) <= 3 ? 'col-place--' . ($pos + 1) : '' ?>">
            <?php if ($pos == 0): ?>🥇
            <?php elseif ($pos == 1): ?>🥈
            <?php elseif ($pos == 2): ?>🥉
            <?php else: ?><?= $pos + 1 ?>
            <?php endif; ?>
          </td>
          <td class="col-rider">
            <a href="/rider/<?= $rider['rider_id'] ?>" class="rider-link">
              <?= htmlspecialchars($rider['firstname'] . ' ' . $rider['lastname']) ?>
            </a>
          </td>
          <td class="col-club table-col-hide-portrait text-muted">
            <?= htmlspecialchars($rider['club_name'] ?? '-') ?>
          </td>
          <?php foreach ($eventsWithPoints as $event): ?>
          <?php
          $pts = $rider['event_points'][$event['id']] ?? 0;
          $isExcluded = isset($rider['excluded_events'][$event['id']]);
          ?>
          <td class="col-event <?= $pts > 0 ? 'has-points' : '' ?> <?= $isExcluded ? 'excluded' : '' ?>" style="display:table-cell !important;visibility:visible !important;">
            <?php if ($pts > 0): ?>
              <?php if ($isExcluded): ?>
                <span class="excluded-points" title="Räknas ej"><?= $pts ?></span>
              <?php else: ?>
                <?= $pts ?>
              <?php endif; ?>
            <?php else: ?>
              –
            <?php endif; ?>
          </td>
          <?php endforeach; ?>
          <td class="col-total">
            <strong><?= $rider['total_points'] ?></strong>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Mobile Card View -->
  <div class="result-list">
    <?php foreach ($classData['riders'] as $pos => $rider):
        $searchData = strtolower($rider['firstname'] . ' ' . $rider['lastname'] . ' ' . ($rider['club_name'] ?? ''));
    ?>
    <a href="/rider/<?= $rider['rider_id'] ?>" class="result-item" data-search="<?= htmlspecialchars($searchData) ?>">
      <div class="result-place <?= ($pos + 1) <= 3 ? 'top-3' : '' ?>">
        <?php if ($pos == 0): ?>🥇
        <?php elseif ($pos == 1): ?>🥈
        <?php elseif ($pos == 2): ?>🥉
        <?php else: ?><?= $pos + 1 ?>
        <?php endif; ?>
      </div>
      <div class="result-info">
        <div class="result-name"><?= htmlspecialchars($rider['firstname'] . ' ' . $rider['lastname']) ?></div>
        <div class="result-club"><?= htmlspecialchars($rider['club_name'] ?? '-') ?></div>
      </div>
      <div class="result-points">
        <div class="points-big"><?= $rider['total_points'] ?></div>
        <div class="points-label">poäng</div>
      </div>
    </a>
    <?php endforeach; ?>
  </div>
</section>
<?php endforeach; ?>

<?php endif; ?>

</div><!-- End individual-standings -->

<!-- Club Standings Section -->
<div id="club-standings" style="display: none;">

<?php if (empty($clubStandings)): ?>
<section class="card">
  <div class="empty-state">
    <div class="empty-state-icon">🛡️</div>
    <p>Inga klubbresultat ännu</p>
  </div>
</section>
<?php else: ?>

<section class="card mb-lg standings-card">
  <div class="card-header">
    <div>
      <h2 class="card-title">🛡️ Klubbmästerskap</h2>
      <p class="card-subtitle"><?= count($clubStandings) ?> klubbar • Bästa åkare per klass: 100%, näst bästa: 50%</p>
    </div>
  </div>

  <div class="table-wrapper">
    <table class="table table--striped standings-table">
      <thead>
        <tr>
          <th class="col-place">#</th>
          <th class="col-club-name">Klubb</th>
          <th class="col-riders table-col-hide-portrait">Åkare</th>
          <?php $eventNum = 1; ?>
          <?php foreach ($eventsWithPoints as $event): ?>
          <th class="col-event" style="display:table-cell !important;visibility:visible !important;" title="<?= htmlspecialchars($event['name']) ?>">
            #<?= $eventNum ?>
          </th>
          <?php $eventNum++; ?>
          <?php endforeach; ?>
          <th class="col-total">Totalt</th>
        </tr>
      </thead>
      <tbody>
        <?php $clubPos = 0; ?>
        <?php foreach ($clubStandings as $club): $clubPos++; ?>
        <tr class="result-row club-row" data-club="<?= htmlspecialchars($club['club_name']) ?>">
          <td class="col-place <?= $clubPos <= 3 ? 'col-place--' . $clubPos : '' ?>">
            <?php if ($clubPos == 1): ?>🥇
            <?php elseif ($clubPos == 2): ?>🥈
            <?php elseif ($clubPos == 3): ?>🥉
            <?php else: ?><?= $clubPos ?>
            <?php endif; ?>
          </td>
          <td class="col-club-name">
            <div class="club-info">
              <span class="club-name-text"><?= htmlspecialchars($club['club_name']) ?></span>
              <button class="club-expand-btn" onclick="toggleClubRiders(this, event)">▸</button>
            </div>
          </td>
          <td class="col-riders table-col-hide-portrait text-muted">
            <?= $club['rider_count'] ?>
          </td>
          <?php foreach ($eventsWithPoints as $event): ?>
          <?php $pts = $club['event_points'][$event['id']] ?? 0; ?>
          <td class="col-event <?= $pts > 0 ? 'has-points' : '' ?>" style="display:table-cell !important;visibility:visible !important;">
            <?= $pts > 0 ? $pts : '–' ?>
          </td>
          <?php endforeach; ?>
          <td class="col-total">
            <strong><?= $club['total_points'] ?></strong>
          </td>
        </tr>
        <!-- Hidden club riders sub-rows -->
        <?php foreach ($club['riders'] as $rIdx => $clubRider): ?>
        <tr class="club-rider-row" data-parent-club="<?= htmlspecialchars($club['club_name']) ?>" style="display: none;">
          <td class="col-place"></td>
          <td class="col-club-name" colspan="2">
            <a href="/rider/<?= $clubRider['rider_id'] ?>" class="club-rider-link">
              ↳ <?= htmlspecialchars($clubRider['name']) ?>
              <span class="club-rider-class">(<?= htmlspecialchars($clubRider['class_name']) ?>)</span>
            </a>
          </td>
          <?php foreach ($eventsWithPoints as $event): ?>
          <td class="col-event" style="display:table-cell !important;visibility:visible !important;"></td>
          <?php endforeach; ?>
          <td class="col-total text-muted"><?= $clubRider['points'] ?> p</td>
        </tr>
        <?php endforeach; ?>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Mobile Card View for Clubs -->
  <div class="result-list">
    <?php $clubPos = 0; ?>
    <?php foreach ($clubStandings as $club): $clubPos++; ?>
    <div class="club-result-item">
      <div class="result-place <?= $clubPos <= 3 ? 'top-3' : '' ?>">
        <?php if ($clubPos == 1): ?>🥇
        <?php elseif ($clubPos == 2): ?>🥈
        <?php elseif ($clubPos == 3): ?>🥉
        <?php else: ?><?= $clubPos ?>
        <?php endif; ?>
      </div>
      <div class="result-info">
        <div class="result-name"><?= htmlspecialchars($club['club_name']) ?></div>
        <div class="result-club"><?= $club['rider_count'] ?> åkare</div>
      </div>
      <div class="result-points">
        <div class="points-big"><?= $club['total_points'] ?></div>
        <div class="points-label">poäng</div>
      </div>
    </div>
    <!-- Mobile club riders -->
    <div class="club-riders-mobile" data-mobile-club="<?= htmlspecialchars($club['club_name']) ?>">
      <?php foreach (array_slice($club['riders'], 0, 5) as $clubRider): ?>
      <a href="/rider/<?= $clubRider['rider_id'] ?>" class="club-rider-mobile">
        <span class="club-rider-mobile-name"><?= htmlspecialchars($clubRider['name']) ?></span>
        <span class="club-rider-mobile-pts"><?= $clubRider['points'] ?> p</span>
      </a>
      <?php endforeach; ?>
      <?php if (count($club['riders']) > 5): ?>
      <div class="club-rider-mobile more">+<?= count($club['riders']) - 5 ?> fler</div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
</section>

<?php endif; ?>

</div><!-- End club-standings -->

<script>
function applyFilters() {
  const classFilter = document.getElementById('classFilter').value;
  const searchFilter = document.getElementById('searchFilter').value.toLowerCase().trim();

  document.querySelectorAll('.class-section').forEach(section => {
    const classId = section.dataset.class;
    const showClass = classFilter === 'all' || classFilter === classId;
    section.style.display = showClass ? '' : 'none';

    if (showClass) {
      section.querySelectorAll('.result-row, .result-item').forEach(row => {
        const searchData = row.dataset.search || '';
        const matchesSearch = !searchFilter || searchData.includes(searchFilter);
        row.style.display = matchesSearch ? '' : 'none';
      });
    }
  });
}

function switchStandingsTab(tab) {
  // Update tab buttons
  document.querySelectorAll('.standings-tab').forEach(btn => {
    btn.classList.toggle('active', btn.dataset.tab === tab);
  });
  // Show/hide sections
  document.getElementById('individual-standings').style.display = tab === 'individual' ? '' : 'none';
  document.getElementById('club-standings').style.display = tab === 'club' ? '' : 'none';
}

function toggleClubRiders(btn, event) {
  event.stopPropagation();
  const row = btn.closest('tr');
  const clubName = row.dataset.club;
  const isExpanded = btn.textContent === '▾';

  btn.textContent = isExpanded ? '▸' : '▾';

  document.querySelectorAll(`tr[data-parent-club="${clubName}"]`).forEach(subRow => {
    subRow.style.display = isExpanded ? 'none' : '';
  });
}
</script>

<style>
.mb-md { margin-bottom: var(--space-md); }
.mb-lg { margin-bottom: var(--space-lg); }
.text-secondary { color: var(--color-text-secondary); }
.text-muted { color: var(--color-text-muted); }

/* Events Dropdown (slim, collapsible) */
.events-dropdown {
  background: var(--color-bg-surface);
  border-radius: var(--radius-md);
  border: 1px solid var(--color-border);
  overflow: hidden;
}
.events-dropdown-header {
  display: flex;
  align-items: center;
  gap: var(--space-sm);
  padding: var(--space-sm) var(--space-md);
  cursor: pointer;
  user-select: none;
  list-style: none;
  font-size: var(--text-sm);
}
.events-dropdown-header::-webkit-details-marker {
  display: none;
}
.events-dropdown-icon {
  font-size: 1em;
}
.events-dropdown-title {
  font-weight: var(--weight-medium);
  flex: 1;
}
.events-dropdown-count {
  color: var(--color-text-muted);
  font-size: var(--text-xs);
}
.events-dropdown-arrow {
  color: var(--color-text-muted);
  transition: transform var(--transition-fast);
}
.events-dropdown[open] .events-dropdown-arrow {
  transform: rotate(180deg);
}
.events-dropdown-content {
  border-top: 1px solid var(--color-border);
  max-height: 300px;
  overflow-y: auto;
}
.event-dropdown-item {
  display: flex;
  gap: var(--space-sm);
  padding: var(--space-xs) var(--space-md);
  font-size: var(--text-sm);
  border-bottom: 1px solid var(--color-border-light);
  text-decoration: none;
  color: inherit;
  transition: background var(--transition-fast);
}
.event-dropdown-item:last-child {
  border-bottom: none;
}
.event-dropdown-item:hover {
  background: var(--color-bg-hover);
}
.event-item-num {
  color: var(--color-accent-text);
  font-weight: var(--weight-medium);
  min-width: 28px;
}
.event-item-date {
  color: var(--color-text-muted);
  min-width: 50px;
}
.event-item-name {
  flex: 1;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.event-item-results {
  color: var(--color-text-muted);
  font-size: var(--text-xs);
}

/* Standings Tabs */
.standings-tabs {
  display: flex;
  gap: var(--space-xs);
  background: var(--color-bg-surface);
  padding: var(--space-xs);
  border-radius: var(--radius-md);
  border: 1px solid var(--color-border);
}
.standings-tab {
  flex: 1;
  padding: var(--space-sm) var(--space-md);
  border: none;
  border-radius: var(--radius-sm);
  background: transparent;
  color: var(--color-text-secondary);
  font-weight: var(--weight-medium);
  font-size: var(--text-sm);
  cursor: pointer;
  transition: all var(--transition-fast);
}
.standings-tab:hover {
  background: var(--color-bg-hover);
  color: var(--color-text);
}
.standings-tab.active {
  background: var(--color-accent);
  color: white;
}

/* Club Standings specific */
.col-club-name {
  min-width: 150px;
}
.club-info {
  display: flex;
  align-items: center;
  gap: var(--space-sm);
}
.club-name-text {
  font-weight: var(--weight-medium);
}
.club-expand-btn {
  background: none;
  border: none;
  color: var(--color-text-muted);
  cursor: pointer;
  padding: 2px 6px;
  font-size: var(--text-xs);
  border-radius: var(--radius-sm);
  transition: all var(--transition-fast);
}
.club-expand-btn:hover {
  background: var(--color-bg-hover);
  color: var(--color-text);
}
.club-rider-row {
  background: var(--color-bg-sunken);
}
.club-rider-row td {
  padding-top: var(--space-2xs);
  padding-bottom: var(--space-2xs);
  font-size: var(--text-sm);
}
.club-rider-link {
  color: var(--color-text-secondary);
  text-decoration: none;
  display: flex;
  align-items: center;
  gap: var(--space-xs);
}
.club-rider-link:hover {
  color: var(--color-accent-text);
}
.club-rider-class {
  font-size: var(--text-xs);
  color: var(--color-text-muted);
}

/* Mobile club cards */
.club-result-item {
  display: flex;
  align-items: center;
  gap: var(--space-md);
  padding: var(--space-sm) var(--space-md);
  border-bottom: 1px solid var(--color-border-light);
}
.club-riders-mobile {
  display: none;
  padding: var(--space-xs) var(--space-md) var(--space-sm);
  padding-left: calc(var(--space-md) + 40px);
  background: var(--color-bg-sunken);
  border-bottom: 1px solid var(--color-border-light);
}
.club-rider-mobile {
  display: flex;
  justify-content: space-between;
  padding: var(--space-2xs) 0;
  font-size: var(--text-sm);
  color: var(--color-text-secondary);
  text-decoration: none;
}
.club-rider-mobile:hover {
  color: var(--color-accent-text);
}
.club-rider-mobile.more {
  color: var(--color-text-muted);
  font-style: italic;
}

/* Info Card */
.info-card {
  background: var(--color-bg-surface);
  border-radius: var(--radius-md);
  overflow: hidden;
  box-shadow: var(--shadow-sm);
}
.info-card-stripe {
  height: 4px;
  background: linear-gradient(90deg, var(--color-accent) 0%, #00A3E0 100%);
}
.info-card-content {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: var(--space-md);
  padding: var(--space-md);
}
.info-card-main {
  flex: 1;
  min-width: 0;
}
.info-card-title {
  font-size: var(--text-lg);
  font-weight: var(--weight-bold);
  margin: 0;
  line-height: 1.3;
}
.info-card-meta {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--space-xs);
  font-size: var(--text-sm);
  color: var(--color-text-secondary);
  margin-top: var(--space-2xs);
}
.info-card-sep {
  color: var(--color-text-muted);
}
.info-card-stats {
  display: flex;
  gap: var(--space-sm);
  flex-shrink: 0;
}
.info-card-stat {
  text-align: center;
  padding: var(--space-xs) var(--space-sm);
  background: var(--color-bg-sunken);
  border-radius: var(--radius-sm);
  display: flex;
  align-items: baseline;
  gap: var(--space-2xs);
}
.info-card-stat-value {
  font-size: var(--text-lg);
  font-weight: var(--weight-bold);
  color: var(--color-accent-text);
}
.info-card-stat-label {
  font-size: var(--text-xs);
  color: var(--color-text-muted);
}

/* Filter Row */
.filter-row {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: var(--space-md);
}
.filter-field {
  display: flex;
  flex-direction: column;
  gap: var(--space-xs);
}
.filter-label {
  font-size: var(--text-sm);
  font-weight: var(--weight-medium);
  color: var(--color-text-secondary);
}
.filter-select,
.filter-input {
  padding: var(--space-sm) var(--space-md);
  font-size: var(--text-sm);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  background: var(--color-bg-surface);
  color: var(--color-text);
  width: 100%;
  box-sizing: border-box;
}
.filter-select {
  cursor: pointer;
  appearance: none;
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%236b7280' d='M3 4.5L6 7.5L9 4.5'/%3E%3C/svg%3E");
  background-repeat: no-repeat;
  background-position: right 12px center;
  padding-right: var(--space-xl);
}
.filter-select:focus,
.filter-input:focus {
  outline: none;
  border-color: var(--color-accent);
}
.filter-input::placeholder {
  color: var(--color-text-muted);
}

/* Horizontal scroll for tables with many columns */
.standings-card .table-wrapper {
  overflow-x: auto;
  -webkit-overflow-scrolling: touch;
}

.standings-table {
  border-collapse: separate;
  border-spacing: 0;
}

/* Sticky columns for place and rider name */
.standings-table .col-place {
  position: sticky;
  left: 0;
  background: var(--color-bg-card);
  z-index: 2;
}

.standings-table .col-rider,
.standings-table .col-club-name {
  position: sticky;
  left: 40px;
  background: var(--color-bg-card);
  z-index: 2;
}

.standings-table thead .col-place,
.standings-table thead .col-rider,
.standings-table thead .col-club-name {
  background: var(--color-bg-surface);
  z-index: 3;
}

/* Add shadow to indicate scrollable content */
.standings-table .col-rider::after,
.standings-table .col-club-name::after {
  content: '';
  position: absolute;
  top: 0;
  right: -8px;
  bottom: 0;
  width: 8px;
  background: linear-gradient(to right, rgba(0,0,0,0.08), transparent);
  pointer-events: none;
}

.standings-table .col-event {
  text-align: center;
  font-size: var(--text-sm);
  min-width: 40px;
  color: var(--color-text-muted);
}
.standings-table .col-event.has-points {
  color: var(--color-text);
  font-weight: var(--weight-medium);
}
.standings-table .col-event.excluded {
  opacity: 0.5;
}
.excluded-points {
  text-decoration: line-through;
  color: var(--color-text-muted);
}
.standings-table .col-total {
  text-align: right;
  font-weight: var(--weight-bold);
  color: var(--color-accent-text);
}

.col-place {
  width: 40px;
  text-align: center;
  font-weight: var(--weight-bold);
}
.col-place--1 { color: #FFD700; }
.col-place--2 { color: #C0C0C0; }
.col-place--3 { color: #CD7F32; }

.rider-link {
  color: var(--color-text);
  font-weight: var(--weight-medium);
}
.rider-link:hover {
  color: var(--color-accent-text);
}

.result-place.top-3 {
  background: var(--color-accent-light);
}
.result-points {
  text-align: right;
}
.points-big {
  font-size: var(--text-lg);
  font-weight: var(--weight-bold);
  color: var(--color-accent-text);
}
.points-label {
  font-size: var(--text-xs);
  color: var(--color-text-muted);
}

.empty-state {
  text-align: center;
  padding: var(--space-2xl);
  color: var(--color-text-muted);
}
.empty-state-icon {
  font-size: 48px;
  margin-bottom: var(--space-md);
}

/* Default: hide result-list (mobile card view) */
.result-list {
  display: none;
}

@media (max-width: 599px) {
  .info-card-content {
    flex-direction: column;
    align-items: stretch;
    gap: var(--space-sm);
  }
  .info-card-title {
    font-size: var(--text-base);
  }
  .info-card-stats {
    justify-content: center;
    padding-top: var(--space-sm);
    border-top: 1px solid var(--color-border);
  }
  .filter-row {
    grid-template-columns: 1fr;
    gap: var(--space-sm);
  }
}

/*
 * V2 APPROACH: Event columns (.col-event) are ALWAYS visible.
 * Table scrolls horizontally. Only hide table in portrait on small screens.
 */

/* Default: Table visible, cards hidden, event columns ALWAYS visible */
.standings-card .table-wrapper {
  display: block;
}
.standings-card .result-list {
  display: none;
}

/* Event columns - always visible, compact for mobile */
.standings-table .col-event {
  min-width: 36px;
  text-align: center;
  font-size: var(--text-xs);
  padding: var(--space-xs);
}

/* Mobile portrait: hide table, show cards */
@media (max-width: 599px) and (orientation: portrait) {
  .standings-card .table-wrapper {
    display: none;
  }
  .standings-card .result-list {
    display: block;
  }
}

/* LANDSCAPE MODE - Force table and all columns visible */
@media (orientation: landscape) {
  .standings-card .table-wrapper {
    display: block !important;
  }
  .standings-card .result-list {
    display: none !important;
  }
  .col-event,
  .standings-table .col-event,
  th.col-event,
  td.col-event {
    display: table-cell !important;
    visibility: visible !important;
    opacity: 1 !important;
  }
}

/* Fallback: Aspect ratio based landscape detection */
@media (min-aspect-ratio: 1/1) {
  .standings-card .table-wrapper {
    display: block !important;
  }
  .standings-card .result-list {
    display: none !important;
  }
  .col-event,
  .standings-table .col-event,
  th.col-event,
  td.col-event {
    display: table-cell !important;
    visibility: visible !important;
    opacity: 1 !important;
  }
}

/* Fallback: Screen width > height detection for iOS */
@media screen and (min-width: 500px) and (max-height: 500px) {
  .standings-card .table-wrapper {
    display: block !important;
  }
  .standings-card .result-list {
    display: none !important;
  }
  .col-event,
  .standings-table .col-event,
  th.col-event,
  td.col-event {
    display: table-cell !important;
    visibility: visible !important;
    opacity: 1 !important;
  }
}

/* JavaScript landscape class fallback */
body.is-landscape .standings-card .table-wrapper {
  display: block !important;
}
body.is-landscape .standings-card .result-list {
  display: none !important;
}
body.is-landscape .col-event,
body.is-landscape .standings-table .col-event,
body.is-landscape th.col-event,
body.is-landscape td.col-event {
  display: table-cell !important;
  visibility: visible !important;
  opacity: 1 !important;
}
</style>

<script>
// JavaScript landscape detection for iOS Safari
function updateLandscapeClass() {
  const isLandscape = window.innerWidth > window.innerHeight;
  document.body.classList.toggle('is-landscape', isLandscape);
  document.body.classList.toggle('is-portrait', !isLandscape);
}
updateLandscapeClass();
window.addEventListener('resize', updateLandscapeClass);
window.addEventListener('orientationchange', function() {
  setTimeout(updateLandscapeClass, 100);
});

</script>

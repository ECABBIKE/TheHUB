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
    // Fetch series details with brand logo
    $stmt = $db->prepare("
        SELECT s.*, sb.logo as brand_logo, sb.name as brand_name, COUNT(DISTINCT e.id) as event_count
        FROM series s
        LEFT JOIN series_brands sb ON s.brand_id = sb.id
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

    // Get club standings from cache (uses club-points-system.php)
    // This reads from club_standings_cache table which is populated via admin
    $clubStandings = [];
    $clubRiderContributions = []; // For compatibility

    // First try to get cached data
    $useCachedClubStandings = false;
    try {
        // Use getDB() wrapper which has getAll() method
        $dbWrapper = getDB();
        $clubStandingsRaw = getClubStandings($dbWrapper, $seriesId);
        if (!empty($clubStandingsRaw)) {
            $useCachedClubStandings = true;
            foreach ($clubStandingsRaw as $club) {
                $clubStandings[$club['club_id']] = [
                    'club_id' => $club['club_id'],
                    'club_name' => $club['club_name'],
                    'short_name' => $club['short_name'] ?? null,
                    'city' => $club['city'] ?? null,
                    'logo' => $club['logo'] ?? null,
                    'total_points' => $club['total_points'],
                    'rider_count' => $club['total_participants'],
                    'scoring_riders' => $club['total_participants'],
                    'events_count' => $club['events_count'] ?? 0,
                    'best_event_points' => $club['best_event_points'] ?? 0,
                    'ranking' => $club['ranking'],
                    'event_points' => [],
                    'riders' => []
                ];
            }
        }
    } catch (Exception $e) {
        // Cache tables don't exist or query failed - fall back to calculation
        error_log("CLUB_STANDINGS: Cache query failed: " . $e->getMessage());
    }

    // Fallback to on-the-fly calculation if no cached data
    if (!$useCachedClubStandings && !empty($seriesEvents)) {
        $firstEventId = $seriesEvents[0]['id'];
        $debugStmt = $db->prepare("
            SELECT
                COUNT(*) as total_results,
                COUNT(rd.club_id) as results_with_direct_club,
                COUNT(DISTINCT rd.club_id) as unique_direct_clubs
            FROM results r
            JOIN riders rd ON r.cyclist_id = rd.id
            WHERE r.event_id = ? AND r.status = 'finished' AND r.points > 0
        ");
        $debugStmt->execute([$firstEventId]);
        $debugCounts = $debugStmt->fetch(PDO::FETCH_ASSOC);
        error_log("CLUB_DEBUG: Event {$firstEventId} - Total results: {$debugCounts['total_results']}, With direct club: {$debugCounts['results_with_direct_club']}, Unique clubs: {$debugCounts['unique_direct_clubs']}");

        // Also check rider_club_seasons
        $seriesYear = $series['year'] ?? date('Y');
        $debugStmt2 = $db->prepare("
            SELECT COUNT(DISTINCT rcs.rider_id) as riders_with_season_club
            FROM results r
            JOIN riders rd ON r.cyclist_id = rd.id
            JOIN rider_club_seasons rcs ON rd.id = rcs.rider_id AND rcs.season_year = ?
            WHERE r.event_id = ? AND r.status = 'finished' AND r.points > 0
        ");
        $debugStmt2->execute([$seriesYear, $firstEventId]);
        $seasonCount = $debugStmt2->fetchColumn();
        error_log("CLUB_DEBUG: Riders with season club (year {$seriesYear}): {$seasonCount}");

    foreach ($seriesEvents as $event) {
        $eventId = $event['id'];

        // Get all results for this event with series points, grouped by club and class
        // Only include riders with clubs and classes that award points
        // Check both riders.club_id AND rider_club_seasons for club membership
        $seriesYear = $series['year'] ?? date('Y');

        // Debug logging for club standings
        error_log("CLUB_STANDINGS: Processing event {$eventId} ({$event['name']}), seriesYear={$seriesYear}, useSeriesResults=" . ($useSeriesResults ? 'true' : 'false'));

        if ($useSeriesResults) {
            $stmt = $db->prepare("
                SELECT
                    sr.cyclist_id,
                    sr.class_id,
                    sr.points,
                    rd.firstname,
                    rd.lastname,
                    COALESCE(rd.club_id, rcs.club_id) as club_id,
                    COALESCE(c.name, c2.name) as club_name,
                    cls.name as class_name,
                    cls.display_name as class_display_name
                FROM series_results sr
                JOIN riders rd ON sr.cyclist_id = rd.id
                LEFT JOIN clubs c ON rd.club_id = c.id
                LEFT JOIN rider_club_seasons rcs ON rd.id = rcs.rider_id AND rcs.season_year = ?
                LEFT JOIN clubs c2 ON rcs.club_id = c2.id
                LEFT JOIN classes cls ON sr.class_id = cls.id
                WHERE sr.series_id = ? AND sr.event_id = ?
                AND COALESCE(rd.club_id, rcs.club_id) IS NOT NULL
                AND sr.points > 0
                AND COALESCE(cls.series_eligible, 1) = 1
                AND COALESCE(cls.awards_points, 1) = 1
                ORDER BY COALESCE(rd.club_id, rcs.club_id), sr.class_id, sr.points DESC
            ");
            $stmt->execute([$seriesYear, $seriesId, $eventId]);
        } else {
            // Fallback to results table
            $stmt = $db->prepare("
                SELECT
                    r.cyclist_id,
                    r.class_id,
                    r.points,
                    rd.firstname,
                    rd.lastname,
                    COALESCE(rd.club_id, rcs.club_id) as club_id,
                    COALESCE(c.name, c2.name) as club_name,
                    cls.name as class_name,
                    cls.display_name as class_display_name
                FROM results r
                JOIN riders rd ON r.cyclist_id = rd.id
                LEFT JOIN clubs c ON rd.club_id = c.id
                LEFT JOIN rider_club_seasons rcs ON rd.id = rcs.rider_id AND rcs.season_year = ?
                LEFT JOIN clubs c2 ON rcs.club_id = c2.id
                LEFT JOIN classes cls ON r.class_id = cls.id
                WHERE r.event_id = ?
                AND r.status = 'finished'
                AND COALESCE(rd.club_id, rcs.club_id) IS NOT NULL
                AND r.points > 0
                AND COALESCE(cls.series_eligible, 1) = 1
                AND COALESCE(cls.awards_points, 1) = 1
                ORDER BY COALESCE(rd.club_id, rcs.club_id), r.class_id, r.points DESC
            ");
            $stmt->execute([$seriesYear, $eventId]);
        }
        $eventResults = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Debug logging
        error_log("CLUB_STANDINGS: Event {$eventId} returned " . count($eventResults) . " results with clubs");
        if (count($eventResults) > 0) {
            error_log("CLUB_STANDINGS: First result: " . json_encode($eventResults[0]));
        }

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

        error_log("CLUB_STANDINGS: Fallback calculation done - " . count($clubStandings) . " clubs");
    } // End of: if (!$useCachedClubStandings && !empty($seriesEvents))

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
  <div class="card-title text-error">Fel</div>
  <p><?= htmlspecialchars($error) ?></p>
</section>
<?php endif; ?>

<!-- Series Info Card -->
<section class="info-card mb-md">
  <div class="info-card-stripe"></div>
  <div class="info-card-content">
    <?php if (!empty($series['brand_logo'])): ?>
    <img src="<?= htmlspecialchars($series['brand_logo']) ?>" alt="<?= htmlspecialchars($series['brand_name'] ?? '') ?>" class="info-card-logo" style="max-width: 80px; max-height: 80px; object-fit: contain; margin-right: var(--space-md);">
    <?php endif; ?>
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
    <span class="events-dropdown-icon"><i data-lucide="calendar"></i></span>
    <span class="events-dropdown-title">Tävlingar i serien</span>
    <span class="events-dropdown-count"><?= count($seriesEvents) ?> st</span>
    <span class="events-dropdown-arrow">▾</span>
  </summary>
  <div class="events-dropdown-content">
    <?php foreach ($seriesEvents as $event):
        $parts = [];
        if ($event['date']) $parts[] = date('j M', strtotime($event['date']));
        $parts[] = htmlspecialchars($event['name']);
        if (!empty($event['location'])) $parts[] = htmlspecialchars($event['location']);
        $parts[] = $event['result_count'] . ' resultat';
    ?>
    <a href="/event/<?= $event['id'] ?>" class="event-simple-row">
      <?= implode(' - ', $parts) ?>
    </a>
    <?php endforeach; ?>
  </div>
</details>

<!-- Standings Type Tabs -->
<div class="standings-tabs mb-md">
  <button class="tab-pill active" data-tab="individual" onclick="switchStandingsTab('individual')">
    <i data-lucide="user"></i> Individuellt
  </button>
  <button class="tab-pill" data-tab="club" onclick="switchStandingsTab('club')">
    <i data-lucide="shield"></i> Klubbmästerskap
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
    <div class="empty-state-icon"><i data-lucide="trophy"></i></div>
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
          <th class="col-event" class="col-fixed" title="<?= htmlspecialchars($event['name']) ?>">
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
        <tr class="result-row" onclick="window.location='/rider/<?= $rider['rider_id'] ?>'" class="cursor-pointer" data-search="<?= htmlspecialchars($searchData) ?>">
          <td class="col-place <?= ($pos + 1) <= 3 ? 'col-place--' . ($pos + 1) : '' ?>">
            <?= $pos + 1 ?>
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
          <td class="col-event <?= $pts > 0 ? 'has-points' : '' ?> <?= $isExcluded ? 'excluded' : '' ?>" class="col-fixed">
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
        <?php if ($pos == 0): ?>
          <img src="/assets/icons/medal-1st.svg" alt="1:a" class="medal-icon-mobile">
        <?php elseif ($pos == 1): ?>
          <img src="/assets/icons/medal-2nd.svg" alt="2:a" class="medal-icon-mobile">
        <?php elseif ($pos == 2): ?>
          <img src="/assets/icons/medal-3rd.svg" alt="3:e" class="medal-icon-mobile">
        <?php else: ?>
          <?= $pos + 1 ?>
        <?php endif; ?>
      </div>
      <div class="result-info">
        <div class="result-name"><?= htmlspecialchars($rider['firstname'] . ' ' . $rider['lastname']) ?></div>
        <div class="result-club"><?= htmlspecialchars($rider['club_name'] ?? '-') ?> &middot; <span class="result-class"><?= htmlspecialchars($classData['class_display_name'] ?? $classData['class_name']) ?></span></div>
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
<div id="club-standings" class="hidden">

<?php if (empty($clubStandings)): ?>
<section class="card">
  <div class="empty-state">
    <div class="empty-state-icon"><i data-lucide="shield"></i></div>
    <p>Inga klubbresultat ännu</p>
  </div>
</section>
<?php else: ?>

<section class="card mb-lg standings-card">
  <div class="card-header">
    <div>
      <h2 class="card-title"><i data-lucide="shield"></i> Klubbmästerskap</h2>
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
          <th class="col-event" class="col-fixed" title="<?= htmlspecialchars($event['name']) ?>">
            #<?= $eventNum ?>
          </th>
          <?php $eventNum++; ?>
          <?php endforeach; ?>
          <th class="col-total">Totalt</th>
          <th class="col-action"></th>
        </tr>
      </thead>
      <tbody>
        <?php $clubPos = 0; ?>
        <?php foreach ($clubStandings as $club): $clubPos++; ?>
        <tr class="result-row club-row" data-club="<?= htmlspecialchars($club['club_name']) ?>">
          <td class="col-place <?= $clubPos <= 3 ? 'col-place--' . $clubPos : '' ?>">
            <?= $clubPos ?>
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
          <td class="col-event <?= $pts > 0 ? 'has-points' : '' ?>" class="col-fixed">
            <?= $pts > 0 ? $pts : '–' ?>
          </td>
          <?php endforeach; ?>
          <td class="col-total">
            <strong><?= $club['total_points'] ?></strong>
          </td>
          <td class="col-action">
            <button type="button" class="btn-icon" title="Visa detaljer" onclick="openClubModal(<?= $club['club_id'] ?>, <?= $seriesId ?>, '<?= htmlspecialchars(addslashes($club['club_name'])) ?>'); event.stopPropagation();">
              <i data-lucide="eye"></i>
            </button>
          </td>
        </tr>
        <!-- Hidden club riders sub-rows -->
        <?php foreach ($club['riders'] as $rIdx => $clubRider): ?>
        <tr class="club-rider-row" data-parent-club="<?= htmlspecialchars($club['club_name']) ?>" class="hidden">
          <td class="col-place"></td>
          <td class="col-club-name" colspan="2">
            <a href="/rider/<?= $clubRider['rider_id'] ?>" class="club-rider-link">
              ↳ <?= htmlspecialchars($clubRider['name']) ?>
              <span class="club-rider-class">(<?= htmlspecialchars($clubRider['class_name']) ?>)</span>
            </a>
          </td>
          <?php foreach ($eventsWithPoints as $event): ?>
          <td class="col-event" class="col-fixed"></td>
          <?php endforeach; ?>
          <td class="col-total text-muted"><?= $clubRider['points'] ?> p</td>
          <td class="col-action"></td>
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
    <a href="/club-points?club_id=<?= $club['club_id'] ?>&series_id=<?= $seriesId ?>" class="club-result-item">
      <div class="result-place <?= $clubPos <= 3 ? 'top-3' : '' ?>">
        <?php if ($clubPos == 1): ?>
          <img src="/assets/icons/medal-1st.svg" alt="1:a" class="medal-icon-mobile">
        <?php elseif ($clubPos == 2): ?>
          <img src="/assets/icons/medal-2nd.svg" alt="2:a" class="medal-icon-mobile">
        <?php elseif ($clubPos == 3): ?>
          <img src="/assets/icons/medal-3rd.svg" alt="3:e" class="medal-icon-mobile">
        <?php else: ?>
          <?= $clubPos ?>
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
      <div class="result-action">
        <i data-lucide="eye"></i>
      </div>
    </a>
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
  document.querySelectorAll('.tab-pill').forEach(btn => {
    btn.classList.toggle('active', btn.dataset.tab === tab);
  });
  
  // Show/hide sections using CSS classes
  const individualDiv = document.getElementById('individual-standings');
  const clubDiv = document.getElementById('club-standings');
  
  if (tab === 'individual') {
    individualDiv.classList.remove('hidden');
    clubDiv.classList.add('hidden');
  } else {
    individualDiv.classList.add('hidden');
    clubDiv.classList.remove('hidden');
  }
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

// Club Detail Modal Functions
function openClubModal(clubId, seriesId, clubName) {
  const modal = document.getElementById('club-detail-modal');
  const iframe = document.getElementById('club-detail-iframe');
  const title = document.getElementById('club-modal-title');

  title.textContent = clubName + ' - Klubbpoäng';
  iframe.src = '/club-points?club_id=' + clubId + '&series_id=' + seriesId + '&modal=1';
  modal.style.display = 'flex';
  document.body.style.overflow = 'hidden';

  // Re-render lucide icons in modal
  if (typeof lucide !== 'undefined') {
    lucide.createIcons();
  }
}

function closeClubModal() {
  const modal = document.getElementById('club-detail-modal');
  const iframe = document.getElementById('club-detail-iframe');

  modal.style.display = 'none';
  iframe.src = '';
  document.body.style.overflow = '';
}

// Close modal on escape key
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') {
    const modal = document.getElementById('club-detail-modal');
    if (modal && modal.style.display !== 'none') {
      closeClubModal();
    }
  }
});

// Close modal on overlay click
document.getElementById('club-detail-modal')?.addEventListener('click', function(e) {
  if (e.target === this) {
    closeClubModal();
  }
});
</script>

<!-- Club Detail Modal -->
<div id="club-detail-modal" class="modal-overlay" style="display: none;">
  <div class="modal-container modal-lg">
    <div class="modal-header">
      <h2 id="club-modal-title">Klubbpoäng</h2>
      <button type="button" class="modal-close" onclick="closeClubModal()">
        <i data-lucide="x"></i>
      </button>
    </div>
    <div class="modal-body">
      <iframe id="club-detail-iframe" src="" style="width: 100%; height: 70vh; border: none;"></iframe>
    </div>
  </div>
</div>

<style>
/* Club Detail Modal Styles */
.modal-overlay {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background: rgba(0, 0, 0, 0.6);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 9999;
  padding: var(--space-md);
}

.modal-container {
  background: var(--color-bg-card);
  border-radius: var(--radius-lg);
  max-width: 900px;
  width: 100%;
  max-height: 90vh;
  display: flex;
  flex-direction: column;
  box-shadow: var(--shadow-xl);
}

.modal-lg {
  max-width: 1000px;
}

.modal-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: var(--space-md) var(--space-lg);
  border-bottom: 1px solid var(--color-border);
}

.modal-header h2 {
  margin: 0;
  font-size: var(--text-lg);
  font-weight: var(--weight-semibold);
}

.modal-close {
  background: none;
  border: none;
  padding: var(--space-xs);
  cursor: pointer;
  color: var(--color-text-muted);
  border-radius: var(--radius-sm);
  display: flex;
  align-items: center;
  justify-content: center;
}

.modal-close:hover {
  background: var(--color-bg-hover);
  color: var(--color-text-primary);
}

.modal-body {
  flex: 1;
  overflow: auto;
  padding: 0;
}

@media (max-width: 767px) {
  .modal-overlay {
    padding: 0;
  }

  .modal-container {
    max-width: 100%;
    max-height: 100%;
    border-radius: 0;
    height: 100%;
  }
}
</style>

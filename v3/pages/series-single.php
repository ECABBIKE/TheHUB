<?php
/**
 * V3 Series Single Page - Series standings with per-event points
 * Uses series_results table for series-specific points (matches V2)
 */

$db = hub_db();
$seriesId = intval($pageInfo['params']['id'] ?? 0);

if (!$seriesId) {
    header('Location: /v3/series');
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

    // Get all riders who have results in this series
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
        LEFT JOIN classes cls ON r.class_id = cls.id
        WHERE e.series_id = ?
          AND COALESCE(cls.series_eligible, 1) = 1
          AND COALESCE(cls.awards_points, 1) = 1
          {$classFilter}
        ORDER BY cls.sort_order ASC, riders.lastname, riders.firstname
    ");
    $stmt->execute($params);
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

} catch (Exception $e) {
    $error = $e->getMessage();
    $series = null;
}

if (!$series) {
    include HUB_V3_ROOT . '/pages/404.php';
    return;
}
?>

<nav class="breadcrumb mb-md">
  <a href="/v3/series" class="breadcrumb-link">Serier</a>
  <span class="breadcrumb-separator">›</span>
  <span class="breadcrumb-current"><?= htmlspecialchars($series['name']) ?></span>
</nav>

<div class="page-header">
  <h1 class="page-title"><?= htmlspecialchars($series['name']) ?></h1>
  <div class="page-meta">
    <span class="chip chip--primary"><?= $series['year'] ?></span>
    <span class="chip"><?= count($seriesEvents) ?> tävlingar</span>
    <?php if (!empty($series['count_best_results'])): ?>
      <span class="chip chip--info">Räknar <?= $series['count_best_results'] ?> bästa</span>
    <?php endif; ?>
  </div>
</div>

<?php if (!empty($series['description'])): ?>
<p class="text-secondary mb-lg"><?= htmlspecialchars($series['description']) ?></p>
<?php endif; ?>

<?php if (isset($error)): ?>
<section class="card mb-lg">
  <div class="card-title" style="color: var(--color-error)">Fel</div>
  <p><?= htmlspecialchars($error) ?></p>
</section>
<?php endif; ?>

<!-- Class Filter and Search -->
<?php if (count($activeClasses) > 1 || $searchName): ?>
<div class="filter-bar mb-lg">
  <?php if (count($activeClasses) > 1): ?>
  <label class="filter-select-wrapper">
    <span class="filter-label">Klass:</span>
    <select class="filter-select" onchange="if(this.value) window.location=this.value">
      <option value="/v3/series/<?= $seriesId ?><?= $searchName ? '?search=' . urlencode($searchName) : '' ?>" <?= $selectedClass === 'all' ? 'selected' : '' ?>>Alla klasser</option>
      <?php foreach ($activeClasses as $class): ?>
      <option value="/v3/series/<?= $seriesId ?>?class=<?= $class['id'] ?><?= $searchName ? '&search=' . urlencode($searchName) : '' ?>" <?= $selectedClass == $class['id'] ? 'selected' : '' ?>>
        <?= htmlspecialchars($class['display_name'] ?? $class['name']) ?> (<?= $class['rider_count'] ?>)
      </option>
      <?php endforeach; ?>
    </select>
  </label>
  <?php endif; ?>

  <form method="get" action="/v3/series/<?= $seriesId ?>" class="search-inline">
    <?php if ($selectedClass !== 'all'): ?>
      <input type="hidden" name="class" value="<?= htmlspecialchars($selectedClass) ?>">
    <?php endif; ?>
    <input type="text" name="search" placeholder="Sök namn..." value="<?= htmlspecialchars($searchName) ?>" class="search-input-inline">
    <button type="submit" class="btn btn--sm">Sök</button>
    <?php if ($searchName): ?>
      <a href="/v3/series/<?= $seriesId ?><?= $selectedClass !== 'all' ? '?class=' . $selectedClass : '' ?>" class="btn btn--sm btn--ghost">×</a>
    <?php endif; ?>
  </form>
</div>
<?php endif; ?>

<?php if ($searchName): ?>
<div class="search-info mb-md">
  <span class="chip chip--info">Sökresultat för "<?= htmlspecialchars($searchName) ?>"</span>
</div>
<?php endif; ?>

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
<section class="card mb-lg standings-card">
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
          <th class="col-event table-col-hide-portrait" title="<?= htmlspecialchars($event['name']) ?>">
            #<?= $eventNum ?>
          </th>
          <?php $eventNum++; ?>
          <?php endforeach; ?>
          <th class="col-total">Totalt</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($classData['riders'] as $pos => $rider): ?>
        <tr onclick="window.location='/v3/rider/<?= $rider['rider_id'] ?>'" style="cursor:pointer">
          <td class="col-place <?= ($pos + 1) <= 3 ? 'col-place--' . ($pos + 1) : '' ?>">
            <?php if ($pos == 0): ?>🥇
            <?php elseif ($pos == 1): ?>🥈
            <?php elseif ($pos == 2): ?>🥉
            <?php else: ?><?= $pos + 1 ?>
            <?php endif; ?>
          </td>
          <td class="col-rider">
            <a href="/v3/rider/<?= $rider['rider_id'] ?>" class="rider-link">
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
          <td class="col-event table-col-hide-portrait <?= $pts > 0 ? 'has-points' : '' ?> <?= $isExcluded ? 'excluded' : '' ?>">
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
    <?php foreach ($classData['riders'] as $pos => $rider): ?>
    <a href="/v3/rider/<?= $rider['rider_id'] ?>" class="result-item">
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

<!-- Events in Series (compact list at bottom) -->
<section class="card events-section">
  <details class="events-details">
    <summary class="events-summary">
      <span class="events-title">Tävlingar i serien</span>
      <span class="events-count"><?= count($seriesEvents) ?> st</span>
    </summary>
    <div class="events-compact">
      <?php $eventNum = 1; ?>
      <?php foreach ($seriesEvents as $event): ?>
      <a href="/v3/event/<?= $event['id'] ?>" class="event-compact-row">
        <span class="event-compact-num">#<?= $eventNum ?></span>
        <span class="event-compact-date"><?= $event['date'] ? date('j M', strtotime($event['date'])) : '-' ?></span>
        <span class="event-compact-name"><?= htmlspecialchars($event['name']) ?></span>
      </a>
      <?php $eventNum++; ?>
      <?php endforeach; ?>
    </div>
  </details>
</section>

<style>
.breadcrumb {
  display: flex;
  align-items: center;
  gap: var(--space-xs);
  font-size: var(--text-sm);
  color: var(--color-text-muted);
}
.breadcrumb-link {
  color: var(--color-text-secondary);
}
.breadcrumb-link:hover {
  color: var(--color-accent-text);
}
.breadcrumb-current {
  color: var(--color-text);
  font-weight: var(--weight-medium);
}

.page-header {
  margin-bottom: var(--space-lg);
}
.page-title {
  font-size: var(--text-2xl);
  font-weight: var(--weight-bold);
  margin: 0 0 var(--space-sm) 0;
}
.page-meta {
  display: flex;
  gap: var(--space-sm);
  flex-wrap: wrap;
}
.chip--primary {
  background: var(--color-accent);
  color: var(--color-text-inverse);
}
.chip--info {
  background: rgba(59, 130, 246, 0.1);
  color: var(--color-accent-text);
}

.mb-md { margin-bottom: var(--space-md); }
.mb-lg { margin-bottom: var(--space-lg); }
.text-secondary { color: var(--color-text-secondary); }
.text-muted { color: var(--color-text-muted); }

/* Compact events section */
.events-section {
  margin-top: var(--space-xl);
}
.events-details {
  cursor: pointer;
}
.events-summary {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: var(--space-sm) var(--space-md);
  list-style: none;
  user-select: none;
}
.events-summary::-webkit-details-marker {
  display: none;
}
.events-summary::before {
  content: '▸';
  margin-right: var(--space-sm);
  transition: transform var(--transition-fast);
}
details[open] .events-summary::before {
  transform: rotate(90deg);
}
.events-title {
  font-weight: var(--weight-medium);
  font-size: var(--text-sm);
}
.events-count {
  font-size: var(--text-sm);
  color: var(--color-text-muted);
}
.events-compact {
  display: flex;
  flex-direction: column;
  border-top: 1px solid var(--color-border);
}
.event-compact-row {
  display: flex;
  gap: var(--space-sm);
  padding: var(--space-xs) var(--space-md);
  font-size: var(--text-sm);
  border-bottom: 1px solid var(--color-border);
  transition: background var(--transition-fast);
}
.event-compact-row:last-child {
  border-bottom: none;
}
.event-compact-row:hover {
  background: var(--color-bg-hover);
}
.event-compact-num {
  color: var(--color-accent-text);
  font-weight: var(--weight-medium);
  min-width: 24px;
}
.event-compact-date {
  color: var(--color-text-muted);
  min-width: 50px;
}
.event-compact-name {
  flex: 1;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.filter-bar {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-sm);
  align-items: center;
}
.filter-select-wrapper {
  display: flex;
  align-items: center;
  gap: var(--space-xs);
}
.filter-label {
  font-size: var(--text-sm);
  color: var(--color-text-secondary);
  white-space: nowrap;
}
.filter-select {
  padding: var(--space-xs) var(--space-sm);
  padding-right: var(--space-lg);
  font-size: var(--text-sm);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  background: var(--color-bg-surface);
  color: var(--color-text);
  cursor: pointer;
  appearance: none;
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%236b7280' d='M3 4.5L6 7.5L9 4.5'/%3E%3C/svg%3E");
  background-repeat: no-repeat;
  background-position: right 8px center;
  min-width: 140px;
}
.filter-select:focus {
  outline: none;
  border-color: var(--color-accent);
}
.search-inline {
  display: flex;
  gap: var(--space-xs);
  align-items: center;
}
.search-input-inline {
  padding: var(--space-xs) var(--space-sm);
  font-size: var(--text-sm);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  background: var(--color-bg-surface);
  color: var(--color-text);
  width: 140px;
}
.search-input-inline:focus {
  outline: none;
  border-color: var(--color-accent);
}
.btn--sm {
  padding: var(--space-xs) var(--space-sm);
  font-size: var(--text-sm);
}
.search-info {
  display: flex;
  gap: var(--space-sm);
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

@media (max-width: 599px) {
  .page-title {
    font-size: var(--text-xl);
  }
  .filter-bar {
    flex-direction: column;
    align-items: stretch;
  }
  .filter-select-wrapper {
    width: 100%;
  }
  .filter-select {
    flex: 1;
    min-width: 0;
  }
  .search-inline {
    width: 100%;
  }
  .search-input-inline {
    flex: 1;
    width: auto;
  }
}
</style>

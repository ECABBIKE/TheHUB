<?php
/**
 * V3 Series Single Page - Series standings with per-event points
 */

$db = hub_db();
$seriesId = intval($pageInfo['params']['id'] ?? 0);

if (!$seriesId) {
    header('Location: /v3/series');
    exit;
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

    // Get selected class filter
    $selectedClass = isset($_GET['class']) ? $_GET['class'] : 'all';

    // Get all events in this series
    $stmt = $db->prepare("
        SELECT e.id, e.name, e.date, e.location,
               COUNT(DISTINCT r.id) as result_count
        FROM events e
        LEFT JOIN results r ON e.id = r.event_id
        WHERE e.series_id = ?
        GROUP BY e.id
        ORDER BY e.date ASC
    ");
    $stmt->execute([$seriesId]);
    $seriesEvents = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get all classes that have results in this series
    $stmt = $db->prepare("
        SELECT DISTINCT c.id, c.name, c.display_name, c.sort_order,
               COUNT(DISTINCT r.cyclist_id) as rider_count
        FROM classes c
        JOIN results r ON c.id = r.class_id
        JOIN events e ON r.event_id = e.id
        WHERE e.series_id = ?
        GROUP BY c.id
        ORDER BY c.sort_order ASC
    ");
    $stmt->execute([$seriesId]);
    $activeClasses = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Build standings
    $standingsByClass = [];

    // Get all riders with points in this series
    $classFilter = '';
    $params = [$seriesId];
    if ($selectedClass !== 'all' && is_numeric($selectedClass)) {
        $classFilter = 'AND r.class_id = ?';
        $params[] = $selectedClass;
    }

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
        WHERE e.series_id = ? {$classFilter}
        ORDER BY cls.sort_order ASC, riders.lastname, riders.firstname
    ");
    $stmt->execute($params);
    $ridersInSeries = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // For each rider, get their points from each event
    foreach ($ridersInSeries as $rider) {
        $riderData = [
            'rider_id' => $rider['id'],
            'firstname' => $rider['firstname'],
            'lastname' => $rider['lastname'],
            'club_name' => $rider['club_name'],
            'class_name' => $rider['class_display_name'] ?? $rider['class_name'] ?? 'Okänd',
            'class_id' => $rider['class_id'],
            'event_points' => [],
            'total_points' => 0
        ];

        // Get points for each event
        foreach ($seriesEvents as $event) {
            $stmt = $db->prepare("
                SELECT points
                FROM results
                WHERE cyclist_id = ? AND event_id = ? AND class_id = ?
                LIMIT 1
            ");
            $stmt->execute([$rider['id'], $event['id'], $rider['class_id']]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            $points = $result ? (int)$result['points'] : 0;
            $riderData['event_points'][$event['id']] = $points;
            $riderData['total_points'] += $points;
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

<!-- Events in Series -->
<section class="card mb-lg">
  <div class="card-header">
    <h2 class="card-title">Tävlingar i serien</h2>
  </div>
  <div class="events-grid">
    <?php foreach ($seriesEvents as $event): ?>
    <a href="/v3/event/<?= $event['id'] ?>" class="event-card">
      <div class="event-date"><?= $event['date'] ? date('j M', strtotime($event['date'])) : '-' ?></div>
      <div class="event-info">
        <div class="event-name"><?= htmlspecialchars($event['name']) ?></div>
        <div class="event-location"><?= htmlspecialchars($event['location'] ?? '') ?></div>
      </div>
      <div class="event-results"><?= $event['result_count'] ?> resultat</div>
    </a>
    <?php endforeach; ?>
  </div>
</section>

<!-- Class Filter -->
<?php if (count($activeClasses) > 1): ?>
<section class="card mb-lg">
  <div class="filter-row">
    <span class="filter-label">Klass:</span>
    <a href="/v3/series/<?= $seriesId ?>" class="btn <?= $selectedClass === 'all' ? 'btn--primary' : 'btn--ghost' ?>">Alla klasser</a>
    <?php foreach ($activeClasses as $class): ?>
    <a href="/v3/series/<?= $seriesId ?>?class=<?= $class['id'] ?>"
       class="btn <?= $selectedClass == $class['id'] ? 'btn--primary' : 'btn--ghost' ?>">
      <?= htmlspecialchars($class['display_name'] ?? $class['name']) ?>
      <span class="badge-count"><?= $class['rider_count'] ?></span>
    </a>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<!-- Standings by Class -->
<?php if (empty($standingsByClass)): ?>
<section class="card">
  <div class="empty-state">
    <div class="empty-state-icon">🏆</div>
    <p>Inga resultat registrerade ännu</p>
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
          <?php foreach ($seriesEvents as $event): ?>
          <th class="col-event table-col-hide-portrait" title="<?= htmlspecialchars($event['name']) ?>">
            <?= $event['date'] ? date('j/n', strtotime($event['date'])) : 'E' . $event['id'] ?>
          </th>
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
          <?php foreach ($seriesEvents as $event): ?>
          <td class="col-event table-col-hide-portrait <?= ($rider['event_points'][$event['id']] ?? 0) > 0 ? 'has-points' : '' ?>">
            <?php
            $pts = $rider['event_points'][$event['id']] ?? 0;
            echo $pts > 0 ? $pts : '-';
            ?>
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

.events-grid {
  display: flex;
  flex-direction: column;
  gap: var(--space-sm);
}
.event-card {
  display: flex;
  align-items: center;
  gap: var(--space-md);
  padding: var(--space-sm) var(--space-md);
  background: var(--color-bg-sunken);
  border-radius: var(--radius-md);
  transition: background var(--transition-fast);
}
.event-card:hover {
  background: var(--color-bg-hover);
}
.event-date {
  font-weight: var(--weight-semibold);
  color: var(--color-accent-text);
  min-width: 50px;
}
.event-info {
  flex: 1;
  min-width: 0;
}
.event-name {
  font-weight: var(--weight-medium);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.event-location {
  font-size: var(--text-sm);
  color: var(--color-text-muted);
}
.event-results {
  font-size: var(--text-sm);
  color: var(--color-text-secondary);
  white-space: nowrap;
}

.filter-row {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-xs);
  align-items: center;
}
.filter-label {
  font-size: var(--text-sm);
  color: var(--color-text-secondary);
  margin-right: var(--space-xs);
}
.badge-count {
  font-size: var(--text-xs);
  background: var(--color-bg-sunken);
  padding: 1px 6px;
  border-radius: var(--radius-full);
  margin-left: var(--space-2xs);
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
  .filter-row {
    gap: 4px;
  }
  .filter-row .btn {
    padding: var(--space-xs) var(--space-sm);
    font-size: var(--text-xs);
  }
  .page-title {
    font-size: var(--text-xl);
  }
  .events-grid {
    gap: var(--space-xs);
  }
  .event-card {
    padding: var(--space-xs) var(--space-sm);
  }
}
</style>

<?php
/**
 * V3 Results Page - Shows recent results (actual results, not just events)
 */

$db = hub_db();

// Get filter parameters
$filterSeries = isset($_GET['series']) && is_numeric($_GET['series']) ? intval($_GET['series']) : null;
$filterYear = isset($_GET['year']) && is_numeric($_GET['year']) ? intval($_GET['year']) : null;
$filterEvent = isset($_GET['event']) && is_numeric($_GET['event']) ? intval($_GET['event']) : null;

try {
    // Build query for results
    $where = ["r.id IS NOT NULL"];
    $params = [];

    if ($filterSeries) {
        $where[] = "s.id = ?";
        $params[] = $filterSeries;
    }
    if ($filterYear) {
        $where[] = "YEAR(e.date) = ?";
        $params[] = $filterYear;
    }
    if ($filterEvent) {
        $where[] = "e.id = ?";
        $params[] = $filterEvent;
    }

    $whereClause = 'WHERE ' . implode(' AND ', $where);

    // Get recent results with rider, event, and class info
    $sql = "SELECT
        r.id, r.position, r.finish_time, r.points, r.status,
        c.id as rider_id, c.firstname, c.lastname,
        cl.name as club_name,
        e.id as event_id, e.name as event_name, e.date as event_date,
        s.name as series_name, s.id as series_id,
        cls.display_name as class_name
    FROM results r
    JOIN riders c ON r.cyclist_id = c.id
    LEFT JOIN clubs cl ON c.club_id = cl.id
    JOIN events e ON r.event_id = e.id
    LEFT JOIN series s ON e.series_id = s.id
    LEFT JOIN classes cls ON r.class_id = cls.id
    {$whereClause}
    ORDER BY e.date DESC, r.position ASC
    LIMIT 200";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Group results by event for display
    $resultsByEvent = [];
    foreach ($results as $result) {
        $eventId = $result['event_id'];
        if (!isset($resultsByEvent[$eventId])) {
            $resultsByEvent[$eventId] = [
                'event_id' => $eventId,
                'event_name' => $result['event_name'],
                'event_date' => $result['event_date'],
                'series_name' => $result['series_name'],
                'series_id' => $result['series_id'],
                'results' => []
            ];
        }
        $resultsByEvent[$eventId]['results'][] = $result;
    }

    // Get series for filter
    $allSeries = $db->query("
        SELECT DISTINCT s.id, s.name
        FROM series s
        INNER JOIN events e ON s.id = e.series_id
        INNER JOIN results r ON e.id = r.event_id
        WHERE s.status = 'active'
        ORDER BY s.name
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Get years for filter
    $allYears = $db->query("
        SELECT DISTINCT YEAR(e.date) as year
        FROM events e
        INNER JOIN results r ON e.id = r.event_id
        WHERE e.date IS NOT NULL
        ORDER BY year DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $totalResults = count($results);
    $totalEvents = count($resultsByEvent);

} catch (Exception $e) {
    $results = [];
    $resultsByEvent = [];
    $allSeries = [];
    $allYears = [];
    $totalResults = 0;
    $totalEvents = 0;
    $error = $e->getMessage();
}
?>

<div class="page-header">
  <h1 class="page-title">Resultat</h1>
  <div class="page-meta">
    <span class="chip chip--primary"><?= number_format($totalResults) ?> resultat</span>
    <span class="chip"><?= $totalEvents ?> event</span>
  </div>
</div>

<!-- Filters -->
<div class="filter-bar mb-lg">
  <label class="filter-select-wrapper">
    <span class="filter-label">Serie:</span>
    <select class="filter-select" onchange="if(this.value) window.location=this.value">
      <option value="/v3/results<?= $filterYear ? '?year=' . $filterYear : '' ?>" <?= !$filterSeries ? 'selected' : '' ?>>Alla serier</option>
      <?php foreach ($allSeries as $s): ?>
      <option value="/v3/results?series=<?= $s['id'] ?><?= $filterYear ? '&year=' . $filterYear : '' ?>" <?= $filterSeries == $s['id'] ? 'selected' : '' ?>>
        <?= htmlspecialchars($s['name']) ?>
      </option>
      <?php endforeach; ?>
    </select>
  </label>

  <?php if (!empty($allYears)): ?>
  <label class="filter-select-wrapper">
    <span class="filter-label">År:</span>
    <select class="filter-select" onchange="if(this.value) window.location=this.value">
      <option value="/v3/results<?= $filterSeries ? '?series=' . $filterSeries : '' ?>" <?= !$filterYear ? 'selected' : '' ?>>Alla år</option>
      <?php foreach ($allYears as $y): ?>
      <option value="/v3/results?year=<?= $y['year'] ?><?= $filterSeries ? '&series=' . $filterSeries : '' ?>" <?= $filterYear == $y['year'] ? 'selected' : '' ?>>
        <?= $y['year'] ?>
      </option>
      <?php endforeach; ?>
    </select>
  </label>
  <?php endif; ?>
</div>

<?php if (isset($error)): ?>
<section class="card mb-lg">
  <div class="card-title" style="color: var(--color-error)">Fel</div>
  <p><?= htmlspecialchars($error) ?></p>
</section>
<?php endif; ?>

<?php if (empty($resultsByEvent)): ?>
<section class="card">
  <div class="empty-state">
    <div class="empty-state-icon">🏁</div>
    <p>Inga resultat hittades med dessa filter</p>
  </div>
</section>
<?php else: ?>

<?php foreach ($resultsByEvent as $eventData): ?>
<section class="card mb-lg">
  <div class="card-header">
    <div>
      <h2 class="card-title">
        <a href="/v3/event/<?= $eventData['event_id'] ?>"><?= htmlspecialchars($eventData['event_name']) ?></a>
      </h2>
      <p class="card-subtitle">
        <?= htmlspecialchars($eventData['series_name'] ?? '') ?>
        <?php if ($eventData['event_date']): ?>
          • <?= date('j M Y', strtotime($eventData['event_date'])) ?>
        <?php endif; ?>
        • <?= count($eventData['results']) ?> resultat
      </p>
    </div>
    <a href="/v3/event/<?= $eventData['event_id'] ?>" class="btn btn--ghost">Visa alla →</a>
  </div>

  <div class="table-wrapper">
    <table class="table table--striped table--hover">
      <thead>
        <tr>
          <th class="col-place">#</th>
          <th class="col-rider">Åkare</th>
          <th class="table-col-hide-portrait">Klubb</th>
          <th class="table-col-hide-portrait">Klass</th>
          <th class="col-time">Tid</th>
          <th class="col-points table-col-hide-portrait">Poäng</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach (array_slice($eventData['results'], 0, 10) as $result): ?>
        <tr onclick="window.location='/v3/rider/<?= $result['rider_id'] ?>'" style="cursor:pointer">
          <td class="col-place <?= $result['position'] <= 3 ? 'col-place--' . $result['position'] : '' ?>">
            <?php if ($result['position'] == 1): ?>🥇
            <?php elseif ($result['position'] == 2): ?>🥈
            <?php elseif ($result['position'] == 3): ?>🥉
            <?php else: ?><?= $result['position'] ?>
            <?php endif; ?>
          </td>
          <td class="col-rider">
            <a href="/v3/rider/<?= $result['rider_id'] ?>" class="rider-link">
              <?= htmlspecialchars($result['firstname'] . ' ' . $result['lastname']) ?>
            </a>
          </td>
          <td class="table-col-hide-portrait text-muted"><?= htmlspecialchars($result['club_name'] ?? '-') ?></td>
          <td class="table-col-hide-portrait text-muted"><?= htmlspecialchars($result['class_name'] ?? '-') ?></td>
          <td class="col-time"><?= htmlspecialchars($result['finish_time'] ?? '-') ?></td>
          <td class="col-points table-col-hide-portrait">
            <?php if ($result['points']): ?>
              <span class="points-value"><?= $result['points'] ?></span>
            <?php else: ?>-<?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Mobile Card View -->
  <div class="result-list">
    <?php foreach (array_slice($eventData['results'], 0, 10) as $result): ?>
    <a href="/v3/rider/<?= $result['rider_id'] ?>" class="result-item">
      <div class="result-place <?= $result['position'] <= 3 ? 'top-3' : '' ?>">
        <?php if ($result['position'] == 1): ?>🥇
        <?php elseif ($result['position'] == 2): ?>🥈
        <?php elseif ($result['position'] == 3): ?>🥉
        <?php else: ?><?= $result['position'] ?>
        <?php endif; ?>
      </div>
      <div class="result-info">
        <div class="result-name"><?= htmlspecialchars($result['firstname'] . ' ' . $result['lastname']) ?></div>
        <div class="result-club"><?= htmlspecialchars($result['club_name'] ?? '-') ?> • <?= htmlspecialchars($result['class_name'] ?? '') ?></div>
      </div>
      <div class="result-time-col">
        <div class="time-value"><?= htmlspecialchars($result['finish_time'] ?? '-') ?></div>
        <?php if ($result['points']): ?>
          <div class="points-small"><?= $result['points'] ?> p</div>
        <?php endif; ?>
      </div>
    </a>
    <?php endforeach; ?>
  </div>
</section>
<?php endforeach; ?>

<?php endif; ?>

<style>
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
  min-width: 120px;
}
.filter-select:focus {
  outline: none;
  border-color: var(--color-accent);
}
.mb-lg { margin-bottom: var(--space-lg); }

.col-place {
  width: 40px;
  text-align: center;
  font-weight: var(--weight-bold);
}
.col-place--1 { color: #FFD700; }
.col-place--2 { color: #C0C0C0; }
.col-place--3 { color: #CD7F32; }

.col-time {
  text-align: right;
  font-family: var(--font-mono);
  white-space: nowrap;
}
.col-points {
  text-align: right;
}
.points-value {
  font-weight: var(--weight-semibold);
  color: var(--color-accent-text);
}

.rider-link {
  color: var(--color-text);
  font-weight: var(--weight-medium);
}
.rider-link:hover {
  color: var(--color-accent-text);
}
.text-muted {
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

.result-place.top-3 {
  background: var(--color-accent-light);
}
.result-time-col {
  text-align: right;
}
.time-value {
  font-family: var(--font-mono);
  font-size: var(--text-sm);
}
.points-small {
  font-size: var(--text-xs);
  color: var(--color-accent-text);
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
}
</style>

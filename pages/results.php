<?php
/**
 * V3 Results Page - Shows events with results
 */

$db = hub_db();

// Get filter parameters
$filterSeries = isset($_GET['series']) && is_numeric($_GET['series']) ? intval($_GET['series']) : null;
$filterYear = isset($_GET['year']) && is_numeric($_GET['year']) ? intval($_GET['year']) : null;

try {
    // Build query for events with results
    $where = ["1=1"];
    $params = [];

    if ($filterSeries) {
        $where[] = "e.series_id = ?";
        $params[] = $filterSeries;
    }
    if ($filterYear) {
        $where[] = "YEAR(e.date) = ?";
        $params[] = $filterYear;
    }

    $whereClause = 'WHERE ' . implode(' AND ', $where);

    // Get events with result counts
    $sql = "SELECT
        e.id, e.name, e.date, e.location,
        s.id as series_id, s.name as series_name,
        COUNT(DISTINCT r.id) as result_count,
        COUNT(DISTINCT r.cyclist_id) as rider_count
    FROM events e
    LEFT JOIN series s ON e.series_id = s.id
    LEFT JOIN results r ON e.id = r.event_id
    {$whereClause}
    GROUP BY e.id
    HAVING result_count > 0
    ORDER BY e.date DESC
    LIMIT 100";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

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

    $totalEvents = count($events);

} catch (Exception $e) {
    $events = [];
    $allSeries = [];
    $allYears = [];
    $totalEvents = 0;
    $error = $e->getMessage();
}
?>

<div class="page-header">
  <h1 class="page-title">Resultat</h1>
  <div class="page-meta">
    <span class="chip chip--primary"><?= $totalEvents ?> tävlingar</span>
  </div>
</div>

<!-- Filters -->
<div class="filter-bar mb-lg">
  <label class="filter-select-wrapper">
    <span class="filter-label">Serie:</span>
    <select class="filter-select" onchange="if(this.value) window.location=this.value">
      <option value="/results<?= $filterYear ? '?year=' . $filterYear : '' ?>" <?= !$filterSeries ? 'selected' : '' ?>>Alla serier</option>
      <?php foreach ($allSeries as $s): ?>
      <option value="/results?series=<?= $s['id'] ?><?= $filterYear ? '&year=' . $filterYear : '' ?>" <?= $filterSeries == $s['id'] ? 'selected' : '' ?>>
        <?= htmlspecialchars($s['name']) ?>
      </option>
      <?php endforeach; ?>
    </select>
  </label>

  <?php if (!empty($allYears)): ?>
  <label class="filter-select-wrapper">
    <span class="filter-label">År:</span>
    <select class="filter-select" onchange="if(this.value) window.location=this.value">
      <option value="/results<?= $filterSeries ? '?series=' . $filterSeries : '' ?>" <?= !$filterYear ? 'selected' : '' ?>>Alla år</option>
      <?php foreach ($allYears as $y): ?>
      <option value="/results?year=<?= $y['year'] ?><?= $filterSeries ? '&series=' . $filterSeries : '' ?>" <?= $filterYear == $y['year'] ? 'selected' : '' ?>>
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

<?php if (empty($events)): ?>
<section class="card">
  <div class="empty-state">
    <div class="empty-state-icon">🏁</div>
    <p>Inga resultat hittades</p>
  </div>
</section>
<?php else: ?>

<section class="card">
  <div class="events-list">
    <?php foreach ($events as $event): ?>
    <a href="/event/<?= $event['id'] ?>" class="event-row">
      <div class="event-date-col">
        <?php if ($event['date']): ?>
          <span class="event-day"><?= date('j', strtotime($event['date'])) ?></span>
          <span class="event-month"><?= date('M', strtotime($event['date'])) ?></span>
        <?php else: ?>
          <span class="event-day">-</span>
        <?php endif; ?>
      </div>
      <div class="event-main">
        <div class="event-name"><?= htmlspecialchars($event['name']) ?></div>
        <div class="event-details">
          <?php if ($event['series_name']): ?>
            <span class="event-series"><?= htmlspecialchars($event['series_name']) ?></span>
          <?php endif; ?>
          <?php if ($event['location']): ?>
            <span class="event-location"><?= htmlspecialchars($event['location']) ?></span>
          <?php endif; ?>
        </div>
      </div>
      <div class="event-stats">
        <span class="stat-value"><?= $event['rider_count'] ?></span>
        <span class="stat-label">deltagare</span>
      </div>
      <div class="event-arrow">→</div>
    </a>
    <?php endforeach; ?>
  </div>
</section>

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
  gap: var(--space-md);
  margin-bottom: var(--space-lg);
  padding: var(--space-md);
  background: var(--color-bg-card);
  border-radius: var(--radius-lg);
  border: 1px solid var(--color-border);
}
.filter-select-wrapper {
  display: flex;
  flex-direction: column;
  gap: var(--space-2xs);
  flex: 1;
  min-width: 140px;
}
.filter-label {
  font-size: var(--text-xs);
  color: var(--color-text-secondary);
  text-transform: uppercase;
  font-weight: var(--weight-medium);
}
.filter-select {
  padding: var(--space-sm) var(--space-md);
  padding-right: var(--space-xl);
  font-size: var(--text-sm);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  background: var(--color-bg-surface);
  color: var(--color-text-primary);
  cursor: pointer;
  appearance: none;
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%236b7280' d='M3 4.5L6 7.5L9 4.5'/%3E%3C/svg%3E");
  background-repeat: no-repeat;
  background-position: right 8px center;
  transition: border-color var(--transition-fast);
}
.filter-select:focus {
  outline: none;
  border-color: var(--color-accent);
  box-shadow: 0 0 0 3px rgba(59, 158, 255, 0.1);
}
.mb-lg { margin-bottom: var(--space-lg); }

.events-list {
  display: flex;
  flex-direction: column;
  gap: var(--space-xs);
}
.event-row {
  display: flex;
  align-items: center;
  gap: var(--space-md);
  padding: var(--space-md);
  background: var(--color-bg-card);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  transition: all var(--transition-fast);
  text-decoration: none;
  color: inherit;
}
.event-row:hover {
  border-color: var(--color-accent);
  transform: translateY(-2px);
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

.event-date-col {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  min-width: 56px;
  height: 56px;
  padding: var(--space-sm);
  background: var(--color-accent);
  border-radius: var(--radius-md);
  color: white;
  text-align: center;
}
.event-day {
  font-size: var(--text-xl);
  font-weight: var(--weight-bold);
  line-height: 1;
  margin-bottom: 2px;
}
.event-month {
  font-size: var(--text-xs);
  text-transform: uppercase;
  opacity: 0.9;
  font-weight: var(--weight-medium);
}

.event-main {
  flex: 1;
  min-width: 0;
}
.event-name {
  font-weight: var(--weight-medium);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.event-details {
  display: flex;
  gap: var(--space-sm);
  font-size: var(--text-sm);
  color: var(--color-text-muted);
}
.event-series {
  color: var(--color-accent-text);
}

.event-stats {
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
}
.stat-value {
  font-weight: var(--weight-bold);
  font-size: var(--text-lg);
}
.stat-label {
  font-size: var(--text-xs);
  color: var(--color-text-muted);
}

.event-arrow {
  color: var(--color-text-muted);
  font-size: var(--text-lg);
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
    padding: var(--space-sm);
    gap: var(--space-sm);
  }
  .filter-select-wrapper {
    width: 100%;
    min-width: 0;
  }
  .filter-select {
    width: 100%;
  }
  .event-row {
    padding: var(--space-sm);
    gap: var(--space-sm);
  }
  .event-row:hover {
    transform: none;
  }
  .event-date-col {
    min-width: 48px;
    height: 48px;
  }
  .event-day {
    font-size: var(--text-lg);
  }
  .event-details {
    flex-direction: column;
    gap: var(--space-3xs);
  }
  .event-stats {
    display: none;
  }
  .event-arrow {
    display: none;
  }
}
</style>

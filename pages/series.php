<?php
/**
 * V3 Series Page - All competition series
 * Simple filtering: Serie + År
 */

require_once HUB_V3_ROOT . '/components/series-badge.php';

$db = hub_db();

// Get filter parameters
$filterSeries = isset($_GET['series']) && is_numeric($_GET['series']) ? intval($_GET['series']) : null;
$filterYear = isset($_GET['year']) && is_numeric($_GET['year']) ? intval($_GET['year']) : null;

try {
    // Build query for series
    $where = ["s.status IN ('active', 'completed')"];
    $params = [];

    if ($filterSeries) {
        $where[] = "s.id = ?";
        $params[] = $filterSeries;
    }

    if ($filterYear) {
        $where[] = "s.year = ?";
        $params[] = $filterYear;
    }

    $whereClause = 'WHERE ' . implode(' AND ', $where);

    // Get series with event counts
    $sql = "SELECT
        s.id, s.name, s.slug, s.description, s.year, s.status,
        s.type, s.discipline,
        s.logo, s.logo_light, s.logo_dark,
        s.gradient_start, s.gradient_end, s.accent_color,
        COUNT(DISTINCT e.id) as event_count,
        (SELECT COUNT(DISTINCT r.cyclist_id)
         FROM results r
         INNER JOIN events e2 ON r.event_id = e2.id
         WHERE e2.series_id = s.id) as participant_count
    FROM series s
    LEFT JOIN events e ON s.id = e.series_id
    {$whereClause}
    GROUP BY s.id
    ORDER BY s.year DESC, s.name ASC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $series = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get all series for filter dropdown
    $allSeries = $db->query("
        SELECT s.id, s.name, s.year
        FROM series s
        WHERE s.status IN ('active', 'completed')
        ORDER BY s.year DESC, s.name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Get years for filter
    $allYears = $db->query("
        SELECT DISTINCT year
        FROM series
        WHERE status IN ('active', 'completed') AND year IS NOT NULL
        ORDER BY year DESC
    ")->fetchAll(PDO::FETCH_COLUMN);

    // Calculate stats
    $totalSeries = count($series);
    $totalEvents = array_sum(array_column($series, 'event_count'));
    $totalParticipants = array_sum(array_column($series, 'participant_count'));

} catch (Exception $e) {
    $series = [];
    $allSeries = [];
    $allYears = [];
    $totalSeries = 0;
    $totalEvents = 0;
    $totalParticipants = 0;
    $error = $e->getMessage();
}

// Build page title
$pageTitle = 'Tävlingsserier';
if ($filterSeries && !empty($series)) {
    $pageTitle = $series[0]['name'];
} elseif ($filterYear) {
    $pageTitle = "Serier $filterYear";
}
?>

<div class="page-header">
  <h1 class="page-title">
    <i data-lucide="trophy" class="page-icon"></i>
    <?= htmlspecialchars($pageTitle) ?>
  </h1>
  <p class="page-subtitle"><?= $totalSeries ?> serier · <?= $totalEvents ?> event · <?= number_format($totalParticipants) ?> deltagare</p>
</div>

<!-- Filters -->
<div class="filters-bar">
  <div class="filter-group">
    <label class="filter-label">Serie</label>
    <select class="filter-select" onchange="window.location=this.value">
      <option value="/series<?= $filterYear ? '?year=' . $filterYear : '' ?>">Alla serier</option>
      <?php foreach ($allSeries as $s): ?>
      <option value="/series?series=<?= $s['id'] ?><?= $filterYear ? '&year=' . $filterYear : '' ?>" <?= $filterSeries == $s['id'] ? 'selected' : '' ?>>
        <?= htmlspecialchars($s['name']) ?>
      </option>
      <?php endforeach; ?>
    </select>
  </div>

  <?php if (!empty($allYears)): ?>
  <div class="filter-group">
    <label class="filter-label">År</label>
    <select class="filter-select" onchange="window.location=this.value">
      <option value="/series<?= $filterSeries ? '?series=' . $filterSeries : '' ?>">Alla år</option>
      <?php foreach ($allYears as $y): ?>
      <option value="/series?<?= $filterSeries ? 'series=' . $filterSeries . '&' : '' ?>year=<?= $y ?>" <?= $filterYear == $y ? 'selected' : '' ?>>
        <?= $y ?>
      </option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php endif; ?>
</div>

<?php if (isset($error)): ?>
<section class="card mb-lg">
  <div class="card-title" style="color: var(--color-error)">Fel</div>
  <p><?= htmlspecialchars($error) ?></p>
</section>
<?php endif; ?>

<?php if (empty($series)): ?>
<section class="card">
  <div class="empty-state">
    <i data-lucide="trophy" style="width: 48px; height: 48px; color: var(--color-text-muted); margin-bottom: var(--space-md);"></i>
    <p>Inga serier hittades</p>
  </div>
</section>
<?php else: ?>

<?php render_series_badge_grid($series, [
    'badge_options' => [
        'show_discipline' => true,
        'show_cta' => true,
        'cta_text' => 'Visa ställning'
    ]
]); ?>

<?php endif; ?>

<style>
.page-header {
  margin-bottom: var(--space-xl);
}
.page-title {
  display: flex;
  align-items: center;
  gap: var(--space-sm);
  font-size: var(--text-2xl);
  font-weight: var(--weight-bold);
  margin: 0 0 var(--space-xs) 0;
  color: var(--color-text-primary);
}
.page-icon {
  width: 32px;
  height: 32px;
  color: var(--color-accent);
}
.page-subtitle {
  font-size: var(--text-md);
  color: var(--color-text-secondary);
  margin: 0;
}

.filters-bar {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-md);
  margin-bottom: var(--space-lg);
  padding: var(--space-md);
  background: var(--color-bg-card);
  border-radius: var(--radius-lg);
  border: 1px solid var(--color-border);
}
.filter-group {
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
  background: var(--color-bg-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  color: var(--color-text-primary);
  font-size: var(--text-sm);
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

.empty-state {
  text-align: center;
  padding: var(--space-2xl);
  color: var(--color-text-muted);
}

@media (max-width: 599px) {
  .page-title {
    font-size: var(--text-xl);
  }
  .filters-bar {
    flex-direction: column;
    padding: var(--space-sm);
    gap: var(--space-sm);
  }
  .filter-group {
    width: 100%;
    min-width: 0;
  }
  .filter-select {
    width: 100%;
  }
}
</style>

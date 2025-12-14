<?php
require_once __DIR__ . '/config.php';

$db = getDB();

// Get filter parameters
$filterSeries = isset($_GET['series_id']) && is_numeric($_GET['series_id']) ? intval($_GET['series_id']) : null;
$filterYear = isset($_GET['year']) && is_numeric($_GET['year']) ? intval($_GET['year']) : null;

// Build WHERE clause
$where = [];
$params = [];

if ($filterSeries) {
 $where[] ="e.series_id = ?";
 $params[] = $filterSeries;
}

if ($filterYear) {
 $where[] ="YEAR(e.date) = ?";
 $params[] = $filterYear;
}

$whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// Get all events with result counts
$sql ="SELECT
 e.id, e.name, e.advent_id, e.date, e.location, e.status,
 s.name as series_name,
 s.id as series_id,
 s.logo as series_logo,
 COUNT(DISTINCT r.id) as result_count,
 COUNT(DISTINCT r.category_id) as category_count,
 COUNT(DISTINCT CASE WHEN r.status = 'finished' THEN r.id END) as finished_count
FROM events e
LEFT JOIN results r ON e.id = r.event_id
LEFT JOIN series s ON e.series_id = s.id
{$whereClause}
GROUP BY e.id
HAVING result_count > 0
ORDER BY e.date DESC";

try {
 $events = $db->getAll($sql, $params);
} catch (Exception $e) {
 $events = [];
 $error = $e->getMessage();
}

// Get all series for filter buttons (only series with results)
$allSeries = $db->getAll("
 SELECT DISTINCT s.id, s.name
 FROM series s
 INNER JOIN events e ON s.id = e.series_id
 INNER JOIN results r ON e.id = r.event_id
 WHERE s.active = 1
 ORDER BY s.name
");

// Get all years from events with results
$allYears = $db->getAll("
 SELECT DISTINCT YEAR(e.date) as year
 FROM events e
 INNER JOIN results r ON e.id = r.event_id
 WHERE e.date IS NOT NULL
 ORDER BY year DESC
");

$pageTitle = 'Resultat';
$pageType = 'public';
include __DIR__ . '/includes/layout-header.php';
?>

<main class="main-content">
 <div class="container">
 <div class="mb-lg">
  <h1 class="text-primary mb-sm">
  <i data-lucide="trophy"></i>
  Resultat
  </h1>
  <p class="text-secondary">
  <?= count($events) ?> tävlingar med resultat
  </p>
 </div>

 <?php if (isset($error)): ?>
  <div class="alert alert-danger mb-lg">
  <strong>Fel:</strong> <?= htmlspecialchars($error) ?>
  </div>
 <?php endif; ?>

 <!-- Filter Section -->
 <div class="card mb-lg">
  <div class="card-body">
  <form method="GET" class="grid grid-cols-1 md-grid-cols-2 gap-md">
   <!-- Year Filter -->
   <div>
   <label for="year-filter" class="label">
    <i data-lucide="calendar"></i>
    År
   </label>
   <select id="year-filter" name="year" class="input" onchange="this.form.submit()">
    <option value="">Alla år</option>
    <?php foreach ($allYears as $yearRow): ?>
    <option value="<?= $yearRow['year'] ?>" <?= $filterYear == $yearRow['year'] ? 'selected' : '' ?>>
     <?= $yearRow['year'] ?>
    </option>
    <?php endforeach; ?>
   </select>
   </div>

   <!-- Series Filter -->
   <div>
   <label for="series-filter" class="label">
    <i data-lucide="trophy"></i>
    Serie<?= $filterYear ? ' (' . $filterYear . ')' : '' ?>
   </label>
   <select id="series-filter" name="series_id" class="input" onchange="this.form.submit()">
    <option value="">Alla serier</option>
    <?php foreach ($allSeries as $series): ?>
    <option value="<?= $series['id'] ?>" <?= $filterSeries == $series['id'] ? 'selected' : '' ?>>
     <?= htmlspecialchars($series['name']) ?>
    </option>
    <?php endforeach; ?>
   </select>
   </div>
  </form>

  <!-- Active Filters Info -->
  <?php if ($filterSeries || $filterYear): ?>
   <div class="mt-md section-divider">
   <div class="flex items-center gap-sm flex-wrap">
    <span class="text-sm text-secondary">Visar:</span>
    <?php if ($filterSeries): ?>
    <span class="badge badge-primary">
     <?php
     $seriesName = array_filter($allSeries, function($s) use ($filterSeries) {
     return $s['id'] == $filterSeries;
     });
     echo $seriesName ? htmlspecialchars(reset($seriesName)['name']) : 'Serie #' . $filterSeries;
     ?>
    </span>
    <?php endif; ?>
    <?php if ($filterYear): ?>
    <span class="badge badge-accent"><?= $filterYear ?></span>
    <?php endif; ?>
    <a href="/results" class="btn btn--sm btn--secondary">
    <i data-lucide="x"></i>
    Visa alla
    </a>
   </div>
   </div>
  <?php endif; ?>
  </div>
 </div>

 <?php if (empty($events)): ?>
  <div class="card">
  <div class="card-body">
   <div class="alert alert--warning">
   <p>Inga resultat hittades. Skapa ett event först eller importera resultat.</p>
   </div>
  </div>
  </div>
 <?php else: ?>
  <!-- Events Grid - 2 columns on desktop -->
  <div class="grid grid-cols-1 md-grid-cols-2 gap-md">
  <?php foreach ($events as $event): ?>
   <a href="/results/<?= $event['id'] ?>" class="gs-event-card-link">
   <div class="card card-hover gs-result-card gs-event-card-transition">
    <!-- Logo Left -->
    <div class="gs-result-logo">
    <?php if ($event['series_logo']): ?>
     <img src="<?= h($event['series_logo']) ?>"
      alt="<?= h($event['series_name']) ?>">
    <?php else: ?>
     <div class="gs-event-no-logo">
     <?= h($event['series_name'] ?? 'Event') ?>
     </div>
    <?php endif; ?>
    </div>

    <!-- Info Right -->
    <div class="gs-result-info">
    <div class="gs-result-date">
     <i data-lucide="calendar" class="icon-sm"></i>
     <?= date('d M Y', strtotime($event['date'])) ?>
    </div>

    <div class="gs-result-title">
     <?= h($event['name']) ?>
    </div>

    <div class="gs-result-meta">
     <?php if ($event['series_name']): ?>
     <span>
      <i data-lucide="trophy" class="gs-icon-12"></i>
      <?= h($event['series_name']) ?>
     </span>
     <?php endif; ?>
     <?php if ($event['location']): ?>
     <span>
      <i data-lucide="map-pin" class="gs-icon-12"></i>
      <?= h($event['location']) ?>
     </span>
     <?php endif; ?>
    </div>

    <div class="gs-result-stats">
     <span>
     <strong><?= $event['result_count'] ?></strong> deltagare
     </span>
     <?php if ($event['category_count'] > 0): ?>
     <span>
      <strong><?= $event['category_count'] ?></strong> <?= $event['category_count'] == 1 ? 'klass' : 'klasser' ?>
     </span>
     <?php endif; ?>
    </div>
    </div>
   </div>
   </a>
  <?php endforeach; ?>
  </div>
 <?php endif; ?>
 </div>
</main>

<style>
/* V3-style CSS with CSS variables for dark mode support */
.gs-event-card-link {
  text-decoration: none;
  color: inherit;
  display: block;
}

.gs-event-card-link:hover {
  text-decoration: none;
}

.gs-result-card {
  display: grid;
  grid-template-columns: 120px 1fr;
  gap: var(--space-md);
  padding: var(--space-md);
}

.gs-result-logo {
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--color-bg-sunken);
  border-radius: var(--radius-md);
  padding: var(--space-sm);
}

.gs-result-logo img {
  max-width: 100%;
  max-height: 70px;
  object-fit: contain;
}

.gs-result-info {
  display: flex;
  flex-direction: column;
  gap: var(--space-sm);
}

.gs-result-date {
  display: inline-flex;
  align-items: center;
  gap: var(--space-sm);
  padding: var(--space-xs) var(--space-sm);
  background: var(--color-accent);
  color: var(--color-text-inverse);
  border-radius: var(--radius-sm);
  font-size: var(--text-sm);
  font-weight: var(--weight-semibold);
  width: fit-content;
}

.gs-result-title {
  font-size: var(--text-lg);
  font-weight: var(--weight-bold);
  color: var(--color-text-primary);
  line-height: 1.3;
}

.gs-result-meta {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-sm);
  font-size: var(--text-sm);
  color: var(--color-text-secondary);
}

.gs-result-meta span {
  display: flex;
  align-items: center;
  gap: var(--space-2xs);
}

.gs-result-stats {
  display: flex;
  gap: var(--space-md);
  font-size: var(--text-sm);
  color: var(--color-text-muted);
}

/* Mobile edge-to-edge - 2025 standard */
@media (max-width: 767px) {
  .gs-result-card {
    grid-template-columns: 80px 1fr;
    gap: var(--space-sm);
    margin-left: -16px !important;
    margin-right: -16px !important;
    padding: 16px !important;
    border-radius: 0 !important;
    border-left: none !important;
    border-right: none !important;
    width: calc(100% + 32px) !important;
    max-width: none !important;
  }

  .gs-result-logo img {
    max-height: 50px;
  }

  .gs-result-title {
    font-size: var(--text-md);
  }
}
</style>

<?php include __DIR__ . '/includes/layout-footer.php'; ?>

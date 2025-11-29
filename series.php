<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Load config first
if (!file_exists(__DIR__ . '/config.php')) {
 die('ERROR: config.php not found! Current directory: ' . __DIR__);
}
require_once __DIR__ . '/config.php';

$db = getDB();

// Check if series_events table exists
$seriesEventsTableExists = false;
try {
 $tables = $db->getAll("SHOW TABLES LIKE 'series_events'");
 $seriesEventsTableExists = !empty($tables);
} catch (Exception $e) {
 // Table doesn't exist, that's ok
}

// Get all series with event and participant counts
if ($seriesEventsTableExists) {
 // Use series_events table (many-to-many)
 $series = $db->getAll("
 SELECT s.id, s.name, s.description, s.year, s.status, s.logo, s.start_date, s.end_date,
 COUNT(DISTINCT se.event_id) as event_count,
 (SELECT COUNT(DISTINCT r.cyclist_id)
 FROM results r
 INNER JOIN series_events se2 ON r.event_id = se2.event_id
 WHERE se2.series_id = s.id) as participant_count
 FROM series s
 LEFT JOIN series_events se ON s.id = se.series_id
 WHERE s.status = 'active'
 GROUP BY s.id
 ORDER BY s.year DESC, s.name ASC
");
} else {
 // Fallback to old series_id column in events
 $series = $db->getAll("
 SELECT s.id, s.name, s.description, s.year, s.status, s.logo, s.start_date, s.end_date,
 COUNT(DISTINCT e.id) as event_count,
 (SELECT COUNT(DISTINCT r.cyclist_id)
 FROM results r
 INNER JOIN events e2 ON r.event_id = e2.id
 WHERE e2.series_id = s.id) as participant_count
 FROM series s
 LEFT JOIN events e ON s.id = e.series_id
 WHERE s.status = 'active'
 GROUP BY s.id
 ORDER BY s.year DESC, s.name ASC
");
}

$pageTitle = 'Serier';
$pageType = 'public';
include __DIR__ . '/includes/layout-header.php';
?>

 <main class="main-content">
 <div class="container">
 <!-- Header -->
 <div class="mb-lg">
 <h1 class="text-primary mb-sm">
  <i data-lucide="trophy"></i>
  Tävlingsserier
 </h1>
 <p class="text-secondary">
  Alla GravitySeries och andra tävlingsserier
 </p>
 </div>

 <!-- Series List -->
 <?php if (empty($series)): ?>
 <div class="card text-center gs-empty-state-container">
  <i data-lucide="inbox" class="gs-empty-state-icon-lg"></i>
  <h3 class="mb-sm">Inga serier ännu</h3>
  <p class="text-secondary">
  Det finns inga tävlingsserier registrerade ännu.
  </p>
 </div>
 <?php else: ?>
 <div class="gs-series-list">
  <?php foreach ($series as $s): ?>
  <a href="/series/<?= $s['id'] ?>" class="link-inherit">
  <div class="card card-hover gs-series-card gs-transition-card">
  <!-- Logo Left -->
  <div class="gs-series-logo">
   <?php if ($s['logo']): ?>
   <img src="<?= h($s['logo']) ?>"
   alt="<?= h($s['name']) ?>">
   <?php else: ?>
   <div class="gs-placeholder-icon">
   <i data-lucide="award" class="gs-icon-48"></i>
   </div>
   <?php endif; ?>
  </div>

  <!-- Info Right -->
  <div class="gs-series-info">
   <div class="gs-series-header">
   <div class="gs-series-title">
   <?= h($s['name']) ?>
   </div>
   <?php if ($s['year']): ?>
   <span class="gs-series-year">
   <?= $s['year'] ?>
   </span>
   <?php endif; ?>
   </div>

   <?php if ($s['description']): ?>
   <div class="gs-series-description">
   <?= h($s['description']) ?>
   </div>
   <?php endif; ?>

   <div class="gs-series-meta">
   <i data-lucide="calendar" class="icon-sm"></i>
   <span class="font-semibold gs-text-purple">
   <?= $s['event_count'] ?> <?= $s['event_count'] == 1 ? 'tävling' : 'tävlingar' ?>
   </span>
   <?php if ($s['participant_count'] > 0): ?>
   <span class="gs-ml-1">•</span>
   <i data-lucide="users" class="icon-sm"></i>
   <span class="font-semibold gs-text-purple">
   <?= number_format($s['participant_count'], 0, ',', ' ') ?> deltagare
   </span>
   <?php endif; ?>
   <?php if ($s['start_date'] && $s['end_date']): ?>
   <span class="gs-ml-1">•</span>
   <span>
   <?= date('M Y', strtotime($s['start_date'])) ?> - <?= date('M Y', strtotime($s['end_date'])) ?>
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
/* V3-style Series CSS with CSS variables for dark mode support */
.gs-series-list {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
  gap: var(--space-md);
}

.gs-series-card {
  display: grid;
  grid-template-columns: 100px 1fr;
  gap: var(--space-md);
  padding: var(--space-md);
  transition: all var(--transition-fast);
}

.gs-series-logo {
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--color-bg-sunken);
  border-radius: var(--radius-md);
  padding: var(--space-sm);
  min-height: 80px;
}

.gs-series-logo img {
  max-width: 100%;
  max-height: 70px;
  object-fit: contain;
}

.gs-placeholder-icon {
  display: flex;
  align-items: center;
  justify-content: center;
  color: var(--color-text-muted);
}

.gs-series-info {
  display: flex;
  flex-direction: column;
  gap: var(--space-xs);
}

.gs-series-header {
  display: flex;
  align-items: center;
  gap: var(--space-sm);
  flex-wrap: wrap;
}

.gs-series-title {
  font-size: var(--text-lg);
  font-weight: var(--weight-bold);
  color: var(--color-text-primary);
  line-height: 1.3;
}

.gs-series-year {
  display: inline-flex;
  padding: var(--space-2xs) var(--space-sm);
  background: var(--color-accent);
  color: var(--color-text-inverse);
  border-radius: var(--radius-full);
  font-size: var(--text-xs);
  font-weight: var(--weight-semibold);
}

.gs-series-description {
  font-size: var(--text-sm);
  color: var(--color-text-secondary);
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.gs-series-meta {
  display: flex;
  align-items: center;
  gap: var(--space-xs);
  flex-wrap: wrap;
  font-size: var(--text-sm);
  color: var(--color-text-muted);
  margin-top: var(--space-xs);
}

.gs-text-purple {
  color: var(--color-accent-text);
}

.gs-ml-1 {
  margin-left: var(--space-2xs);
}

.gs-icon-48 {
  width: 48px;
  height: 48px;
}

.gs-empty-state-container {
  padding: var(--space-2xl);
}

.gs-empty-state-icon-lg {
  width: 64px;
  height: 64px;
  color: var(--color-text-muted);
  margin-bottom: var(--space-md);
}

.link-inherit {
  text-decoration: none;
  color: inherit;
  display: block;
}

.link-inherit:hover {
  text-decoration: none;
}

.gs-transition-card {
  transition: transform var(--transition-fast), box-shadow var(--transition-fast);
}

.gs-transition-card:hover {
  transform: translateY(-2px);
  box-shadow: var(--shadow-md);
}

@media (max-width: 640px) {
  .gs-series-list {
    grid-template-columns: 1fr;
    gap: var(--space-sm);
  }

  .gs-series-card {
    grid-template-columns: 70px 1fr;
    gap: var(--space-sm);
    padding: var(--space-sm);
  }

  .gs-series-logo {
    min-height: 60px;
  }

  .gs-series-logo img {
    max-height: 50px;
  }

  .gs-series-title {
    font-size: var(--text-md);
  }

  .gs-series-description {
    display: none;
  }
}
</style>

<?php include __DIR__ . '/includes/layout-footer.php'; ?>

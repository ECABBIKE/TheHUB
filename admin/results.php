<?php
require_once __DIR__ . '/../config.php';
require_admin();
require_once __DIR__ . '/../includes/admin-layout.php';

global $pdo;
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
 COUNT(DISTINCT r.id) as result_count,
 COUNT(DISTINCT r.category_id) as category_count,
 COUNT(DISTINCT CASE WHEN r.status = 'finished' THEN r.id END) as finished_count,
 COUNT(DISTINCT CASE WHEN r.status = 'dnf' THEN r.id END) as dnf_count,
 COUNT(DISTINCT CASE WHEN r.status = 'dns' THEN r.id END) as dns_count
FROM events e
LEFT JOIN results r ON e.id = r.event_id
LEFT JOIN series s ON e.series_id = s.id
{$whereClause}
GROUP BY e.id
ORDER BY e.date DESC";

try {
 $events = $db->getAll($sql, $params);
} catch (Exception $e) {
 $events = [];
 $error = $e->getMessage();
}

// Get all series for filter buttons
$allSeries = $db->getAll("SELECT id, name FROM series WHERE active = 1 ORDER BY name");

// Get all years from events
$allYears = $db->getAll("SELECT DISTINCT YEAR(date) as year FROM events WHERE date IS NOT NULL ORDER BY year DESC");

$pageTitle = 'Resultat - Event';
$pageType = 'admin';
include __DIR__ . '/../includes/layout-header.php';
?>

<main class="main-content">
 <div class="container">
 <?php
 render_admin_header('Tävlingar', [
  ['label' => 'Importera Resultat', 'url' => '/admin/import-results.php', 'icon' => 'upload', 'class' => 'btn--primary']
 ]);
 ?>

 <!-- Events Tabs -->
 <div class="admin-tabs">
  <a href="/admin/events" class="admin-tab">
   <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 16px; height: 16px;"><path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/></svg>
   Events
  </a>
  <a href="/admin/results.php" class="admin-tab active">
   <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 16px; height: 16px;"><path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M4 22h16"/><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"/><path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"/><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/></svg>
   Resultat
  </a>
  <a href="/admin/classes" class="admin-tab">
   <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 16px; height: 16px;"><path d="m12.83 2.18a2 2 0 0 0-1.66 0L2.6 6.08a1 1 0 0 0 0 1.83l8.58 3.91a2 2 0 0 0 1.66 0l8.58-3.9a1 1 0 0 0 0-1.83Z"/><path d="m22 17.65-9.17 4.16a2 2 0 0 1-1.66 0L2 17.65"/><path d="m22 12.65-9.17 4.16a2 2 0 0 1-1.66 0L2 12.65"/></svg>
   Klasser
  </a>
 </div>

 <?php if (isset($_SESSION['recalc_message'])): ?>
  <div class="alert alert-<?= h($_SESSION['recalc_type'] ?? 'info') ?> mb-lg">
  <i data-lucide="<?= ($_SESSION['recalc_type'] ?? 'info') === 'success' ? 'check-circle' : 'alert-circle' ?>"></i>
  <?= h($_SESSION['recalc_message']) ?>
  </div>
  <?php
  unset($_SESSION['recalc_message']);
  unset($_SESSION['recalc_type']);
  ?>
 <?php endif; ?>

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
    <a href="/admin/results.php" class="btn btn--sm btn--secondary">
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
   <p>Inga event hittades. Skapa ett event först.</p>
   </div>
  </div>
  </div>
 <?php else: ?>
  <!-- Compact Events Table -->
  <div class="card">
  <div class="table-responsive">
   <table class="table">
   <thead>
    <tr>
    <th>Datum</th>
    <th>Event</th>
    <th>Plats</th>
    <th>Serie</th>
    <th class="text-center">Deltagare</th>
    <th class="text-right">Åtgärder</th>
    </tr>
   </thead>
   <tbody>
    <?php foreach ($events as $event): ?>
    <tr>
     <td class="gs-text-nowrap">
     <?= date('Y-m-d', strtotime($event['date'])) ?>
     </td>
     <td>
     <strong><?= h($event['name']) ?></strong>
     <?php if ($event['advent_id']): ?>
      <span class="text-secondary text-sm">#<?= h($event['advent_id']) ?></span>
     <?php endif; ?>
     </td>
     <td><?= h($event['location'] ?? '-') ?></td>
     <td><?= h($event['series_name'] ?? '-') ?></td>
     <td class="text-center">
     <span class="badge badge-<?= $event['result_count'] > 0 ? 'success' : 'secondary' ?>">
      <?= $event['result_count'] ?>
     </span>
     <?php if ($event['dnf_count'] > 0 || $event['dns_count'] > 0): ?>
      <span class="text-secondary text-xs">
      (<?= $event['dnf_count'] ?>/<?= $event['dns_count'] ?>)
      </span>
     <?php endif; ?>
     </td>
     <td class="text-right gs-text-nowrap">
     <a href="/event.php?id=<?= $event['id'] ?>" class="btn btn--secondary btn-xs" title="Visa">
      <i data-lucide="eye" class="gs-icon-12"></i>
     </a>
     <?php if ($event['result_count'] > 0): ?>
      <a href="/admin/edit-results.php?event_id=<?= $event['id'] ?>" class="btn btn--primary btn-xs" title="Editera">
      <i data-lucide="edit" class="gs-icon-12"></i>
      </a>
      <a href="/admin/recalculate-results.php?event_id=<?= $event['id'] ?>" class="btn btn--secondary btn-xs" title="Räkna om">
      <i data-lucide="refresh-cw" class="gs-icon-12"></i>
      </a>
     <?php else: ?>
      <a href="/admin/import-results.php" class="btn btn--secondary btn-xs" title="Importera">
      <i data-lucide="upload" class="gs-icon-12"></i>
      </a>
     <?php endif; ?>
     </td>
    </tr>
    <?php endforeach; ?>
   </tbody>
   </table>
  </div>
  </div>
 <?php endif; ?>
 </div>
</main>

<script src="https://unpkg.com/lucide@latest"></script>
<script>
 lucide.createIcons();
</script>

<?php render_admin_footer(); ?>
<?php include __DIR__ . '/../includes/layout-footer.php'; ?>

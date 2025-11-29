<?php
require_once __DIR__ . '/../config.php';
require_admin();

global $pdo;

// Get statistics
$stats = [];

try {
 $stats['riders'] = $pdo->query("SELECT COUNT(*) FROM riders")->fetchColumn();
 $stats['events'] = $pdo->query("SELECT COUNT(*) FROM events")->fetchColumn();
 $stats['clubs'] = $pdo->query("SELECT COUNT(*) FROM clubs")->fetchColumn();
 $stats['series'] = $pdo->query("SELECT COUNT(*) FROM series")->fetchColumn();
} catch (Exception $e) {
 $stats = ['riders' => 0, 'events' => 0, 'clubs' => 0, 'series' => 0];
}

$pageTitle = 'Dashboard';
$pageType = 'admin';
include __DIR__ . '/../includes/layout-header.php';
?>

<main class="main-content">
 <div class="container">
 <h1 class="mb-lg">
 <i data-lucide="layout-dashboard"></i>
 Dashboard
 </h1>

 <!-- Stats Grid -->
 <div class="grid grid-cols-2 gs-md-grid-cols-4 gap-md mb-lg">
 
 <div class="stat-card">
 <i data-lucide="users" class="icon-lg text-primary mb-md"></i>
 <div class="stat-number"><?= number_format($stats['riders']) ?></div>
 <div class="stat-label">Deltagare</div>
 <a href="/admin/riders.php" class="btn btn--sm btn--secondary mt-md">
  Visa alla →
 </a>
 </div>

 <div class="stat-card">
 <i data-lucide="calendar" class="icon-lg text-success mb-md"></i>
 <div class="stat-number"><?= number_format($stats['events']) ?></div>
 <div class="stat-label">Events</div>
 <a href="/admin/events.php" class="btn btn--sm btn--secondary mt-md">
  Visa alla →
 </a>
 </div>

 <div class="stat-card">
 <i data-lucide="building" class="icon-lg text-accent mb-md"></i>
 <div class="stat-number"><?= number_format($stats['clubs']) ?></div>
 <div class="stat-label">Klubbar</div>
 <a href="/admin/clubs.php" class="btn btn--sm btn--secondary mt-md">
  Visa alla →
 </a>
 </div>

 <div class="stat-card">
 <i data-lucide="trophy" class="icon-lg text-warning mb-md"></i>
 <div class="stat-number"><?= number_format($stats['series']) ?></div>
 <div class="stat-label">Serier</div>
 <a href="/admin/series.php" class="btn btn--sm btn--secondary mt-md">
  Visa alla →
 </a>
 </div>

 </div>

 <!-- Quick Actions -->
 <div class="card">
 <div class="card-header">
 <h2 class="">Snabbåtgärder</h2>
 </div>
 <div class="card-body">
 <div class="flex gap-md flex-wrap">
  <a href="/admin/import-uci.php" class="btn btn--primary">
  <i data-lucide="upload"></i>
  Importera cyklister
  </a>
  <a href="/admin/events.php" class="btn btn--secondary">
  <i data-lucide="calendar-plus"></i>
  Nytt event
  </a>
  <a href="/admin/import-history.php" class="btn btn--secondary">
  <i data-lucide="history"></i>
  Import-historik
  </a>
 </div>
 </div>
 </div>

 </div>
</main>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>

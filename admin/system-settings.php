<?php
/**
 * System Settings - Database Information
 * Shows system info and database statistics
 */

require_once __DIR__ . '/../config.php';
require_admin();
require_once __DIR__ . '/../includes/admin-layout.php';

$db = getDB();
$current_admin = get_current_admin();

// Redirect old tabs to their new locations
if (isset($_GET['tab'])) {
 $redirects = [
 'point-templates' => '/admin/point-scales.php',
 'classes' => '/admin/classes.php',
 'debug' => '/admin/debug.php',
 'global-texts' => '/admin/global-texts.php'
 ];
 if (isset($redirects[$_GET['tab']])) {
 header('Location: ' . $redirects[$_GET['tab']]);
 exit;
 }
}

// System Info
$systemInfo = [
 'php_version' => phpversion(),
 'mysql_version' => $db->getRow("SELECT VERSION() as version")['version'] ?? 'N/A',
 'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'N/A',
 'document_root' => $_SERVER['DOCUMENT_ROOT'] ?? 'N/A',
];

$pageTitle = 'Systeminställningar';
$pageType = 'admin';
include __DIR__ . '/../includes/layout-header.php';
?>

<main class="main-content">
 <div class="container">
 <?php render_admin_header('Databas', 'settings'); ?>

 <!-- SYSTEM INFO -->
 <div class="card">
 <div class="card-header">
 <h2 class="text-primary">
  <i data-lucide="server"></i>
  Systeminformation
 </h2>
 </div>
 <div class="card-body">
 <div class="gs-info-grid">
  <div class="gs-info-item">
  <div class="gs-info-label">PHP Version</div>
  <div class="gs-info-value"><?= h($systemInfo['php_version']) ?></div>
  </div>
  <div class="gs-info-item">
  <div class="gs-info-label">MySQL Version</div>
  <div class="gs-info-value"><?= h($systemInfo['mysql_version']) ?></div>
  </div>
  <div class="gs-info-item">
  <div class="gs-info-label">Server</div>
  <div class="gs-info-value"><?= h($systemInfo['server_software']) ?></div>
  </div>
  <div class="gs-info-item">
  <div class="gs-info-label">Document Root</div>
  <div class="gs-info-value gs-info-value-sm"><?= h($systemInfo['document_root']) ?></div>
  </div>
 </div>

 <h3 class="mt-lg mb-md">Databas Statistik</h3>
 <div class="gs-info-grid">
  <?php
  $stats = [
  ['Deltagare', $db->getRow("SELECT COUNT(*) as c FROM riders")['c']],
  ['Klubbar', $db->getRow("SELECT COUNT(*) as c FROM clubs")['c']],
  ['Events', $db->getRow("SELECT COUNT(*) as c FROM events")['c']],
  ['Resultat', $db->getRow("SELECT COUNT(*) as c FROM results")['c']],
  ['Serier', $db->getRow("SELECT COUNT(*) as c FROM series")['c']],
  ['Klasser', $db->getRow("SELECT COUNT(*) as c FROM classes")['c']],
  ];
  foreach ($stats as $stat):
  ?>
  <div class="gs-info-item">
  <div class="gs-info-label"><?= $stat[0] ?></div>
  <div class="gs-info-value"><?= number_format($stat[1]) ?></div>
  </div>
  <?php endforeach; ?>
 </div>
 </div>
 </div>

 <?php render_admin_footer(); ?>
 </div>
</main>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>

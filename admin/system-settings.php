<?php
/**
 * System Settings - V3 Unified Design System
 * Shows system info and database statistics
 */

require_once __DIR__ . '/../config.php';
require_admin();

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

// Page config for unified layout
$page_title = 'Systeminställningar';
$breadcrumbs = [
    ['label' => 'Inställningar', 'url' => '/admin/settings'],
    ['label' => 'System']
];

// Include unified layout
include __DIR__ . '/components/unified-layout.php';
?>

<!-- Settings Tabs -->
<div class="admin-tabs">
    <a href="/admin/settings" class="admin-tab">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 16px; height: 16px;"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
        Översikt
    </a>
    <a href="/admin/global-texts.php" class="admin-tab">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 16px; height: 16px;"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><line x1="10" y1="9" x2="8" y2="9"/></svg>
        Texter
    </a>
    <a href="/admin/role-permissions.php" class="admin-tab">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 16px; height: 16px;"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
        Roller
    </a>
    <a href="/admin/pricing-templates.php" class="admin-tab">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 16px; height: 16px;"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
        Prismallar
    </a>
    <a href="/admin/system-settings.php" class="admin-tab active">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 16px; height: 16px;"><rect width="20" height="8" x="2" y="2" rx="2" ry="2"/><rect width="20" height="8" x="2" y="14" rx="2" ry="2"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/></svg>
        System
    </a>
</div>

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

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>

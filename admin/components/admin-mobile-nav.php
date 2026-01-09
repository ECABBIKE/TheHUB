<?php
/**
 * TheHUB Admin Mobile Navigation
 * Horizontally scrollable bottom nav for admin pages
 * MUST match admin-sidebar.php exactly!
 *
 * Role-based access:
 * - promotor: Dashboard, Tävlingar, Serier
 * - admin/super_admin: + Konfiguration, Databas, Import, System
 */
require_once __DIR__ . '/../../hub-config.php';
require_once __DIR__ . '/../../components/icons.php';

// Get current user's role for filtering
$currentAdminRole = $_SESSION['admin_role'] ?? 'admin';
$isPromotor = ($currentAdminRole === 'promotor');
$roleHierarchy = ['promotor' => 1, 'admin' => 2, 'super_admin' => 3];
$userRoleLevel = $roleHierarchy[$currentAdminRole] ?? 0;

// Admin navigation - IDENTICAL to admin-sidebar.php
$adminNav = [
    ['id' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'layout-dashboard', 'url' => $isPromotor ? '/admin/promotor' : '/admin/dashboard', 'min_role' => 'promotor'],
    ['id' => 'events', 'label' => 'Tävlingar', 'icon' => 'calendar', 'url' => '/admin/events', 'min_role' => 'promotor'],
    ['id' => 'series', 'label' => 'Serier', 'icon' => 'trophy', 'url' => '/admin/series', 'min_role' => 'promotor'],
    ['id' => 'config', 'label' => 'Konfig', 'icon' => 'sliders', 'url' => '/admin/classes', 'min_role' => 'admin'],
    ['id' => 'riders', 'label' => 'Databas', 'icon' => 'users', 'url' => '/admin/riders', 'min_role' => 'admin'],
    ['id' => 'import', 'label' => 'Import', 'icon' => 'upload', 'url' => '/admin/import', 'min_role' => 'admin'],
    ['id' => 'settings', 'label' => 'System', 'icon' => 'settings', 'url' => '/admin/settings', 'min_role' => 'admin'],
];

// Filter navigation based on user role
$adminNav = array_filter($adminNav, function($item) use ($roleHierarchy, $userRoleLevel) {
    $requiredLevel = $roleHierarchy[$item['min_role'] ?? 'admin'] ?? 2;
    return $userRoleLevel >= $requiredLevel;
});

// Determine active page from URL
$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$currentPath = parse_url($requestUri, PHP_URL_PATH);

function isAdminNavActive($item, $currentPath) {
    $itemPath = parse_url($item['url'], PHP_URL_PATH);

    // Dashboard special case
    if ($item['id'] === 'dashboard') {
        return $currentPath === '/admin/dashboard' ||
               $currentPath === '/admin/promotor' ||
               $currentPath === '/admin/' ||
               $currentPath === '/admin';
    }

    // Check if current path starts with this item's path
    return strpos($currentPath, $itemPath) === 0;
}
?>
<nav class="admin-mobile-nav" role="navigation" aria-label="Admin navigering">
    <div class="admin-mobile-nav-inner">
        <?php foreach ($adminNav as $item): ?>
            <?php $isActive = isAdminNavActive($item, $currentPath); ?>
            <a href="<?= htmlspecialchars($item['url']) ?>"
               class="admin-mobile-nav-link<?= $isActive ? ' active' : '' ?>"
               <?= $isActive ? 'aria-current="page"' : '' ?>>
                <span class="admin-mobile-nav-icon"><?= hub_icon($item['icon']) ?></span>
                <span class="admin-mobile-nav-label"><?= htmlspecialchars($item['label']) ?></span>
            </a>
        <?php endforeach; ?>
    </div>
</nav>

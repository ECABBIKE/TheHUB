<?php
/**
 * TheHUB Admin Mobile Navigation
 * Horizontally scrollable bottom nav for admin pages
 * CSS styles are in /admin/assets/css/admin.css
 * JS functionality is in /admin/assets/js/admin.js
 *
 * Navigation matches sidebar groups from admin-tabs-config.php
 *
 * Role-based access:
 * - promotor: Only tävlingar (their assigned events)
 * - admin/super_admin: Full access
 */
require_once __DIR__ . '/../../hub-config.php';
require_once __DIR__ . '/../../components/icons.php';
require_once __DIR__ . '/../../includes/config/admin-tabs-config.php';

// Get current user's role for filtering
$currentAdminRole = $_SESSION['admin_role'] ?? 'admin';
$roleHierarchy = ['promotor' => 1, 'admin' => 2, 'super_admin' => 3];
$userRoleLevel = $roleHierarchy[$currentAdminRole] ?? 0;

// Admin navigation - matches sidebar groups
// 'min_role': 'promotor' = all, 'admin' = admin+super, 'super_admin' = super only
// 'promotor_only': true = only show for promotors (not for admins)
$adminNav = [
    ['id' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'layout-dashboard', 'url' => '/admin/dashboard', 'pages' => ['dashboard.php', 'index.php'], 'min_role' => 'admin'],
    // Promotor-specific navigation (hidden from admins)
    ['id' => 'promotor-events', 'label' => 'Tävlingar', 'icon' => 'calendar', 'url' => '/admin/promotor.php', 'pages' => ['promotor.php'], 'min_role' => 'promotor', 'promotor_only' => true],
    ['id' => 'promotor-series', 'label' => 'Serier', 'icon' => 'medal', 'url' => '/admin/promotor-series.php', 'pages' => ['promotor-series.php'], 'min_role' => 'promotor', 'promotor_only' => true],
    ['id' => 'sponsors', 'label' => 'Sponsorer', 'icon' => 'image', 'url' => '/admin/sponsors.php', 'pages' => ['sponsors.php'], 'min_role' => 'promotor'],
    ['id' => 'onsite', 'label' => 'Direktanmälan', 'icon' => 'user-plus', 'url' => '/admin/onsite-registration.php', 'pages' => ['onsite-registration.php'], 'min_role' => 'promotor', 'promotor_only' => true],
    // Admin navigation
    ['id' => 'competitions', 'label' => 'Tävlingar', 'icon' => 'calendar', 'url' => '/admin/events.php', 'pages' => get_pages_in_group('competitions'), 'min_role' => 'admin'],
    ['id' => 'standings', 'label' => 'Serier', 'icon' => 'medal', 'url' => '/admin/series.php', 'pages' => get_pages_in_group('standings'), 'min_role' => 'admin'],
    ['id' => 'database', 'label' => 'Databas', 'icon' => 'database', 'url' => '/admin/riders.php', 'pages' => get_pages_in_group('database'), 'min_role' => 'admin'],
    ['id' => 'import', 'label' => 'Import', 'icon' => 'upload', 'url' => '/admin/import.php', 'pages' => get_pages_in_group('import'), 'min_role' => 'admin'],
    ['id' => 'settings', 'label' => 'System', 'icon' => 'settings', 'url' => '/admin/users.php', 'pages' => get_pages_in_group('settings'), 'min_role' => 'admin'],
];

// Filter navigation based on user role
$isPromotorRole = $currentAdminRole === 'promotor';
$adminNav = array_filter($adminNav, function($item) use ($roleHierarchy, $userRoleLevel, $isPromotorRole) {
    $requiredLevel = $roleHierarchy[$item['min_role'] ?? 'admin'] ?? 2;

    // Check if this item is promotor-only (should not show for admins)
    if (!empty($item['promotor_only']) && !$isPromotorRole) {
        return false;
    }

    return $userRoleLevel >= $requiredLevel;
});

// Determine active page from URL
$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$currentPage = basename(parse_url($requestUri, PHP_URL_PATH));

function isAdminNavActiveByPages($item, $currentPage) {
    // Dashboard special case
    if ($item['id'] === 'dashboard') {
        return in_array($currentPage, ['dashboard.php', 'index.php', '']) || $currentPage === 'admin';
    }
    // Check if current page is in this group's pages
    if (isset($item['pages']) && is_array($item['pages'])) {
        return in_array($currentPage, $item['pages']);
    }
    return false;
}
?>
<nav class="admin-mobile-nav" role="navigation" aria-label="Admin navigering">
    <div class="admin-mobile-nav-inner">
        <?php foreach ($adminNav as $item): ?>
            <?php $isActive = isAdminNavActiveByPages($item, $currentPage); ?>
            <a href="<?= htmlspecialchars($item['url']) ?>"
               class="admin-mobile-nav-link<?= $isActive ? ' active' : '' ?>"
               <?= $isActive ? 'aria-current="page"' : '' ?>>
                <span class="admin-mobile-nav-icon"><?= hub_icon($item['icon']) ?></span>
                <span class="admin-mobile-nav-label"><?= htmlspecialchars($item['label']) ?></span>
            </a>
        <?php endforeach; ?>
    </div>
</nav>

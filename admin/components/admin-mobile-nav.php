<?php
/**
 * TheHUB Admin Mobile Navigation
 * Horizontally scrollable bottom nav for admin pages
 *
 * IMPORTANT: This navigation reads from the SAME source as the desktop sidebar:
 *   - Admin/Super Admin: Uses $ADMIN_TABS from /includes/config/admin-tabs-config.php
 *   - Promotor: Uses $PROMOTOR_NAV defined here (must match sidebar.php promotor nav)
 *
 * All platforms (desktop, mobile, PWA) must show identical navigation.
 */
require_once __DIR__ . '/../../hub-config.php';
require_once __DIR__ . '/../../components/icons.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/config/admin-tabs-config.php';

// Get current user's role for filtering
$currentAdminRole = $_SESSION['admin_role'] ?? 'admin';
$isPromotor = ($currentAdminRole === 'promotor');

// Check analytics access
$hasAnalytics = function_exists('hasAnalyticsAccess') && hasAnalyticsAccess();

// Build navigation
$adminNav = [];

if ($isPromotor) {
    // ========================================
    // PROMOTOR navigation
    // Unique menu adapted to what promotors need
    // MUST be identical in sidebar.php and here
    // ========================================
    $adminNav = [
        ['id' => 'dashboard', 'label' => 'Event', 'icon' => 'calendar', 'url' => '/admin/promotor.php?tab=event'],
        ['id' => 'series', 'label' => 'Serier', 'icon' => 'medal', 'url' => '/admin/promotor.php?tab=serier'],
        ['id' => 'ekonomi', 'label' => 'Ekonomi', 'icon' => 'wallet', 'url' => '/admin/promotor.php?tab=ekonomi'],
        ['id' => 'media', 'label' => 'Media', 'icon' => 'image', 'url' => '/admin/promotor.php?tab=media'],
    ];
} else {
    // ========================================
    // ADMIN / SUPER ADMIN navigation
    // Built from $ADMIN_TABS - same source as desktop sidebar
    // ========================================

    // Dashboard first (not in $ADMIN_TABS)
    $adminNav[] = ['id' => 'admin-dashboard', 'label' => 'Dashboard', 'icon' => 'layout-dashboard', 'url' => '/admin/dashboard.php'];

    // Add main groups from ADMIN_TABS config (same logic as sidebar.php)
    foreach ($ADMIN_TABS as $groupId => $group) {
        // Skip super_admin_only groups for non-super admins
        if (isset($group['super_admin_only']) && $group['super_admin_only']) {
            if ($groupId === 'analytics') {
                if (!$hasAnalytics) {
                    continue;
                }
            } else {
                if (!function_exists('hasRole') || !hasRole('super_admin')) {
                    continue;
                }
            }
        }

        // Use first tab's URL as the group URL (same as sidebar.php)
        $firstTabUrl = $group['tabs'][0]['url'] ?? '/admin/';

        $adminNav[] = [
            'id' => 'admin-' . $groupId,
            'label' => $group['title'],
            'icon' => $group['icon'],
            'url' => $firstTabUrl,
            'pages' => get_pages_in_group($groupId)
        ];
    }
}

// Determine active page from URL
$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$currentPath = parse_url($requestUri, PHP_URL_PATH);
$currentPageFile = basename($currentPath);

function isAdminMobileNavActive($item, $currentPath, $currentPageFile) {
    $currentTab = $_GET['tab'] ?? '';
    $isPromotorPage = ($currentPath === '/admin/promotor.php' || $currentPath === '/admin/promotor');

    // Promotor tab-based navigation
    if ($item['id'] === 'dashboard') {
        if ($isPromotorPage && ($currentTab === 'event' || $currentTab === '')) return true;
        return $currentPath === '/admin/dashboard' || $currentPath === '/admin/dashboard.php' ||
               $currentPath === '/admin/' || $currentPath === '/admin';
    }

    if ($item['id'] === 'series') {
        if ($isPromotorPage && $currentTab === 'serier') return true;
        return strpos($currentPath, '/admin/promotor-series') !== false;
    }

    if ($item['id'] === 'ekonomi') {
        return $isPromotorPage && $currentTab === 'ekonomi';
    }

    if ($item['id'] === 'media') {
        if ($isPromotorPage && $currentTab === 'media') return true;
        return strpos($currentPath, '/admin/media') !== false;
    }

    // Admin groups - check if current page is in this group's pages
    if (isset($item['pages']) && is_array($item['pages'])) {
        $pageWithExt = $currentPageFile;
        if (!str_ends_with($pageWithExt, '.php')) {
            $pageWithExt .= '.php';
        }
        return in_array($pageWithExt, $item['pages']);
    }

    // Fallback: check by URL prefix
    $itemPath = parse_url($item['url'], PHP_URL_PATH);
    return strpos($currentPath, $itemPath) === 0;
}
?>
<nav class="admin-mobile-nav" role="navigation" aria-label="Admin navigering">
    <div class="admin-mobile-nav-inner">
        <?php foreach ($adminNav as $item): ?>
            <?php $isActive = isAdminMobileNavActive($item, $currentPath, $currentPageFile); ?>
            <a href="<?= htmlspecialchars($item['url']) ?>"
               class="admin-mobile-nav-link<?= $isActive ? ' active' : '' ?>"
               <?= $isActive ? 'aria-current="page"' : '' ?>>
                <span class="admin-mobile-nav-icon"><?= hub_icon($item['icon']) ?></span>
                <span class="admin-mobile-nav-label"><?= htmlspecialchars($item['label']) ?></span>
            </a>
        <?php endforeach; ?>
    </div>
</nav>

<?php
/**
 * TheHUB Admin Mobile Navigation
 * Horizontally scrollable bottom nav for admin pages
 * CSS styles are in /admin/assets/css/admin.css
 * JS functionality is in /admin/assets/js/admin.js
 */
require_once __DIR__ . '/../../v3-config.php';
require_once __DIR__ . '/../../components/icons.php';

// Admin navigation items - SYNCED with admin-sidebar.php
// Keep these in sync with the sidebar navigation
$adminNav = [
    ['id' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'layout-dashboard', 'url' => '/admin/dashboard'],
    ['id' => 'events', 'label' => 'Events', 'icon' => 'calendar', 'url' => '/admin/events'],
    ['id' => 'series', 'label' => 'Serier', 'icon' => 'trophy', 'url' => '/admin/series'],
    ['id' => 'riders', 'label' => 'Deltagare', 'icon' => 'users', 'url' => '/admin/riders'],
    ['id' => 'clubs', 'label' => 'Klubbar', 'icon' => 'building', 'url' => '/admin/clubs'],
    ['id' => 'import', 'label' => 'Import', 'icon' => 'upload', 'url' => '/admin/import'],
    ['id' => 'ranking', 'label' => 'Ranking', 'icon' => 'bar-chart-2', 'url' => '/admin/ranking'],
    ['id' => 'media', 'label' => 'Media', 'icon' => 'image', 'url' => '/admin/media'],
    ['id' => 'sponsors', 'label' => 'Sponsorer', 'icon' => 'heart', 'url' => '/admin/sponsors'],
    ['id' => 'settings', 'label' => 'Inst.', 'icon' => 'settings', 'url' => '/admin/settings'],
];

// Determine active page from URL
$requestUri = $_SERVER['REQUEST_URI'] ?? '';
function isAdminNavActive($id, $uri) {
    // Special case for dashboard - must be exact match
    if ($id === 'dashboard') {
        return preg_match('#^/admin/(dashboard)?$#', parse_url($uri, PHP_URL_PATH));
    }
    return strpos($uri, '/admin/' . $id) !== false;
}
?>
<nav class="admin-mobile-nav" role="navigation" aria-label="Admin navigering">
    <div class="admin-mobile-nav-inner">
        <?php foreach ($adminNav as $item): ?>
            <?php $isActive = isAdminNavActive($item['id'], $requestUri); ?>
            <a href="<?= htmlspecialchars($item['url']) ?>"
               class="admin-mobile-nav-link<?= $isActive ? ' active' : '' ?>"
               <?= $isActive ? 'aria-current="page"' : '' ?>>
                <span class="admin-mobile-nav-icon"><?= hub_icon($item['icon']) ?></span>
                <span class="admin-mobile-nav-label"><?= htmlspecialchars($item['label']) ?></span>
            </a>
        <?php endforeach; ?>
    </div>
</nav>

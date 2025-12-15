<?php
/**
 * TheHUB Admin Mobile Navigation
 * Horizontally scrollable bottom nav for admin pages
 * CSS styles are in /admin/assets/css/admin.css
 * JS functionality is in /admin/assets/js/admin.js
 *
 * Navigation matches sidebar groups from admin-tabs-config.php
 */
require_once __DIR__ . '/../../v3-config.php';
require_once __DIR__ . '/../../components/icons.php';
require_once __DIR__ . '/../../includes/config/admin-tabs-config.php';

// Admin navigation - matches sidebar groups
$adminNav = [
    ['id' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'layout-dashboard', 'url' => '/admin/dashboard', 'pages' => ['dashboard.php', 'index.php']],
    ['id' => 'competitions', 'label' => 'Tävlingar', 'icon' => 'calendar-check', 'url' => '/admin/events.php', 'pages' => get_pages_in_group('competitions')],
    ['id' => 'standings', 'label' => 'Serier', 'icon' => 'medal', 'url' => '/admin/series.php', 'pages' => get_pages_in_group('standings')],
    ['id' => 'database', 'label' => 'Databas', 'icon' => 'database', 'url' => '/admin/riders.php', 'pages' => get_pages_in_group('database')],
    ['id' => 'config', 'label' => 'Konfig', 'icon' => 'sliders', 'url' => '/admin/classes.php', 'pages' => get_pages_in_group('config')],
    ['id' => 'import', 'label' => 'Import', 'icon' => 'upload', 'url' => '/admin/import.php', 'pages' => get_pages_in_group('import')],
    ['id' => 'settings', 'label' => 'System', 'icon' => 'settings', 'url' => '/admin/users.php', 'pages' => get_pages_in_group('settings')],
];

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

<?php
/**
 * TheHUB Sidebar Component
 * Icon-only navigation sidebar (72px width)
 */

$current_page = $pageSection ?? 'home';
$is_admin = function_exists('isLoggedIn') ? isLoggedIn() : false;
$is_super_admin = function_exists('hasRole') ? hasRole('super_admin') : false;

// Navigation items with icons
$navItems = [
    ['id' => 'home', 'label' => 'Hem', 'icon' => 'home', 'url' => '/'],
    ['id' => 'calendar', 'label' => 'Kalender', 'icon' => 'calendar', 'url' => '/calendar'],
    ['id' => 'results', 'label' => 'Resultat', 'icon' => 'trophy', 'url' => '/results'],
    ['id' => 'series', 'label' => 'Serier', 'icon' => 'award', 'url' => '/series'],
    ['id' => 'riders', 'label' => 'Deltagare', 'icon' => 'users', 'url' => '/riders'],
    ['id' => 'clubs', 'label' => 'Klubbar', 'icon' => 'flag', 'url' => '/clubs'],
    ['id' => 'ranking', 'label' => 'Ranking', 'icon' => 'trending-up', 'url' => '/ranking'],
];

$adminItems = [
    ['id' => 'admin-dashboard', 'label' => 'Dashboard', 'icon' => 'layout-dashboard', 'url' => '/admin/dashboard.php'],
    ['id' => 'admin-events', 'label' => 'Tavlingar', 'icon' => 'calendar-check', 'url' => '/admin/events.php'],
    ['id' => 'admin-series', 'label' => 'Serier', 'icon' => 'medal', 'url' => '/admin/series.php'],
    ['id' => 'admin-riders', 'label' => 'Deltagare', 'icon' => 'users', 'url' => '/admin/riders.php'],
    ['id' => 'admin-config', 'label' => 'Config', 'icon' => 'sliders', 'url' => '/admin/classes.php'],
    ['id' => 'admin-import', 'label' => 'Import', 'icon' => 'upload', 'url' => '/admin/import.php'],
];
?>
<aside class="sidebar" role="navigation" aria-label="Huvudnavigering">
    <nav class="sidebar-nav">
        <?php foreach ($navItems as $item): ?>
        <a href="<?= $item['url'] ?>"
           class="sidebar-link<?= hub_is_nav_active($item['id'], $current_page) ? ' active' : '' ?>"
           data-nav="<?= $item['id'] ?>"
           aria-label="<?= $item['label'] ?>"
           <?= hub_is_nav_active($item['id'], $current_page) ? 'aria-current="page"' : '' ?>>
            <span class="sidebar-icon" aria-hidden="true">
                <i data-lucide="<?= $item['icon'] ?>"></i>
            </span>
            <span class="sidebar-label"><?= $item['label'] ?></span>
        </a>
        <?php endforeach; ?>

        <?php if ($is_admin): ?>
        <!-- Admin Divider -->
        <div style="height: 1px; background: var(--color-border); margin: var(--space-sm) 0;"></div>

        <?php foreach ($adminItems as $item): ?>
        <a href="<?= $item['url'] ?>"
           class="sidebar-link"
           aria-label="<?= $item['label'] ?>">
            <span class="sidebar-icon" aria-hidden="true">
                <i data-lucide="<?= $item['icon'] ?>"></i>
            </span>
            <span class="sidebar-label"><?= $item['label'] ?></span>
        </a>
        <?php endforeach; ?>

        <?php if ($is_super_admin): ?>
        <a href="/admin/users.php"
           class="sidebar-link"
           aria-label="System">
            <span class="sidebar-icon" aria-hidden="true">
                <i data-lucide="shield"></i>
            </span>
            <span class="sidebar-label">System</span>
        </a>
        <?php endif; ?>
        <?php endif; ?>
    </nav>
</aside>

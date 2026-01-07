<?php
/**
 * Admin Submenu Component
 * Automatically renders the correct submenu tabs based on current page
 *
 * Uses admin-tabs-config.php to determine which group and tabs to show
 * Include this in layout-header.php for admin pages
 *
 * Special handling for event-specific pages (shows event context menu)
 * Promotors get a simplified navigation (back to their panel)
 */

// Check if user is promotor-only (not admin)
$isPromotorOnly = function_exists('isRole') && isRole('promotor');

// Promotors get a limited submenu with only their allowed tabs
if ($isPromotorOnly) {
    // Define what tabs promotors can see
    $promotorAllowedTabs = [
        'competitions' => [
            'title' => 'Mina Tävlingar',
            'tabs' => [
                ['id' => 'dashboard', 'label' => 'Översikt', 'url' => '/admin/promotor.php', 'pages' => ['promotor.php']],
                ['id' => 'events', 'label' => 'Events', 'url' => '/admin/events.php', 'pages' => ['events.php', 'event-edit.php']],
                ['id' => 'results', 'label' => 'Resultat', 'url' => '/admin/results.php', 'pages' => ['results.php', 'edit-results.php']],
                ['id' => 'sponsors', 'label' => 'Sponsorer', 'url' => '/admin/sponsors.php', 'pages' => ['sponsors.php', 'sponsor-edit.php']],
                ['id' => 'registrations', 'label' => 'Anmälningar', 'url' => '/admin/promotor-registrations.php', 'pages' => ['promotor-registrations.php', 'event-registrations.php']],
                ['id' => 'payments', 'label' => 'Betalningar', 'url' => '/admin/promotor-payments.php', 'pages' => ['promotor-payments.php', 'orders.php']]
            ]
        ]
    ];

    $current_page = basename($_SERVER['PHP_SELF']);
    $active_tab = null;

    // Find active tab
    foreach ($promotorAllowedTabs['competitions']['tabs'] as $tab) {
        if (in_array($current_page, $tab['pages'])) {
            $active_tab = $tab['id'];
            break;
        }
    }
    ?>
    <div class="admin-submenu admin-submenu--promotor">
        <div class="admin-submenu-container">
            <h2 class="admin-submenu-title"><?= $promotorAllowedTabs['competitions']['title'] ?></h2>
            <nav class="admin-submenu-tabs" role="tablist">
                <?php foreach ($promotorAllowedTabs['competitions']['tabs'] as $tab): ?>
                <a href="<?= $tab['url'] ?>"
                   class="admin-submenu-tab<?= $active_tab === $tab['id'] ? ' active' : '' ?>"
                   role="tab"
                   aria-selected="<?= $active_tab === $tab['id'] ? 'true' : 'false' ?>">
                    <?= htmlspecialchars($tab['label']) ?>
                </a>
                <?php endforeach; ?>
            </nav>
        </div>
    </div>
    <?php
    return; // Don't show regular admin submenu
}

// Load tab configuration
require_once __DIR__ . '/../config/admin-tabs-config.php';

// Get current page - handle subfolders like tools/yearly-rebuild.php
$current_path = $_SERVER['PHP_SELF'];
$current_page = basename($current_path);

// Check if we're in a subfolder under /admin/
if (preg_match('#/admin/([^/]+)/([^/]+\.php)$#', $current_path, $matches)) {
    // It's a subfolder page like /admin/tools/yearly-rebuild.php
    $current_page = $matches[1] . '/' . $matches[2]; // e.g., "tools/yearly-rebuild.php"
}

// Only show on admin pages
if (strpos($current_path, '/admin/') === false) {
    return;
}

// Dashboard doesn't need submenu
if ($current_page === 'dashboard.php') {
    return;
}


// Find which group this page belongs to
$current_group = get_group_for_page($current_page);

if (!$current_group || !isset($ADMIN_TABS[$current_group])) {
    return;
}

$group = $ADMIN_TABS[$current_group];
$active_tab = get_active_tab($current_group, $current_page);

// Check super admin restriction
if (isset($group['super_admin_only']) && $group['super_admin_only'] && !hasRole('super_admin')) {
    return;
}

// Don't show submenu for single-page sections (they have their own sidebar entry)
if (isset($group['single_page']) && $group['single_page']) {
    return;
}
?>
<!-- Admin Submenu -->
<div class="admin-submenu">
    <div class="admin-submenu-container">
        <h2 class="admin-submenu-title">
            <?= htmlspecialchars($group['title']) ?>
        </h2>
        <nav class="admin-submenu-tabs" role="tablist">
            <?php foreach ($group['tabs'] as $tab): ?>
            <a href="<?= $tab['url'] ?>"
               class="admin-submenu-tab<?= $active_tab === $tab['id'] ? ' active' : '' ?>"
               role="tab"
               aria-selected="<?= $active_tab === $tab['id'] ? 'true' : 'false' ?>">
                <?= htmlspecialchars($tab['label']) ?>
            </a>
            <?php endforeach; ?>
        </nav>
    </div>
</div>

<style>
/* Admin Submenu Styles - Mobile First */
.admin-submenu {
    background: var(--color-bg-surface, #fff);
    border-bottom: 1px solid var(--color-border, #e5e7eb);
    position: sticky;
    top: 0;
    z-index: 90;
}

.admin-submenu-container {
    max-width: 1400px;
    margin: 0 auto;
    display: flex;
    align-items: center;
    gap: var(--space-md, 1rem);
}

.admin-submenu-title {
    font-size: var(--text-xs, 0.75rem);
    font-weight: var(--weight-semibold, 600);
    color: var(--color-text-secondary, #6b7280);
    text-transform: uppercase;
    letter-spacing: 0.05em;
    margin: 0;
    padding: var(--space-sm, 0.5rem) 0 var(--space-sm, 0.5rem) var(--space-md, 1rem);
    white-space: nowrap;
    flex-shrink: 0;
}

.admin-submenu-tabs {
    display: flex;
    gap: 2px;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: none;
    -ms-overflow-style: none;
    flex: 1;
    padding-right: var(--space-md, 1rem);
}

.admin-submenu-tabs::-webkit-scrollbar {
    display: none;
}

.admin-submenu-tab {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 44px; /* Touch target */
    padding: 0 var(--space-sm, 0.5rem);
    font-size: var(--text-xs, 0.75rem);
    font-weight: var(--weight-medium, 500);
    color: var(--color-text-secondary, #6b7280);
    text-decoration: none;
    white-space: nowrap;
    border-bottom: 2px solid transparent;
    transition: all 0.15s ease;
    margin-bottom: -1px;
}

.admin-submenu-tab:hover {
    color: var(--color-text-primary, #171717);
    background: var(--color-bg-hover, rgba(0,0,0,0.04));
}

.admin-submenu-tab.active {
    color: var(--color-accent, #004a98);
    border-bottom-color: var(--color-accent, #004a98);
    font-weight: var(--weight-semibold, 600);
}

/* Tablet and up */
@media (min-width: 640px) {
    .admin-submenu-tab {
        padding: 0 var(--space-md, 1rem);
        font-size: var(--text-sm, 0.875rem);
    }
}

/* Desktop */
@media (min-width: 1024px) {
    .admin-submenu-container {
        padding: 0 var(--space-md, 1rem);
    }

    .admin-submenu-title {
        font-size: var(--text-sm, 0.875rem);
        padding-left: 0;
    }

    .admin-submenu-tabs {
        padding-right: 0;
    }
}
</style>

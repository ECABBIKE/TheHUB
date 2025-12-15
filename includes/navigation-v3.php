<?php
/**
 * TheHUB V3.5 Icon-Only Navigation
 * Simplified sidebar navigation with icon-only design (72px width)
 * Using Lucide SVG icons
 */

$current_page = basename($_SERVER['PHP_SELF']);
$current_path = $_SERVER['PHP_SELF'];
$is_admin = isLoggedIn();
$is_super_admin = hasRole('super_admin');

/**
 * Get Lucide SVG icon
 */
function nav_icon($name, $class = 'sidebar-icon-svg') {
    $icons = [
        'home' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>',
        'calendar' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="4" rx="2" ry="2"/><line x1="16" x2="16" y1="2" y2="6"/><line x1="8" x2="8" y1="2" y2="6"/><line x1="3" x2="21" y1="10" y2="10"/></svg>',
        'trophy' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M4 22h16"/><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"/><path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"/><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/></svg>',
        'flag' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" x2="4" y1="22" y2="15"/></svg>',
        'award' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="6"/><path d="M15.477 12.89 17 22l-5-3-5 3 1.523-9.11"/></svg>',
        'users' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
        'building' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="16" height="20" x="4" y="2" rx="2" ry="2"/><path d="M9 22v-4h6v4"/><path d="M8 6h.01"/><path d="M16 6h.01"/><path d="M12 6h.01"/><path d="M12 10h.01"/><path d="M12 14h.01"/><path d="M16 10h.01"/><path d="M16 14h.01"/><path d="M8 10h.01"/><path d="M8 14h.01"/></svg>',
        'trending-up' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/></svg>',
        'layout-dashboard' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg>',
        'calendar-check' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="4" rx="2" ry="2"/><line x1="16" x2="16" y1="2" y2="6"/><line x1="8" x2="8" y1="2" y2="6"/><line x1="3" x2="21" y1="10" y2="10"/><path d="m9 16 2 2 4-4"/></svg>',
        'medal' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7.21 15 2.66 7.14a2 2 0 0 1 .13-2.2L4.4 2.8A2 2 0 0 1 6 2h12a2 2 0 0 1 1.6.8l1.6 2.14a2 2 0 0 1 .14 2.2L16.79 15"/><path d="M11 12 5.12 2.2"/><path d="m13 12 5.88-9.8"/><path d="M8 7h8"/><circle cx="12" cy="17" r="5"/><path d="M12 18v-2h-.5"/></svg>',
        'sliders' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="4" x2="4" y1="21" y2="14"/><line x1="4" x2="4" y1="10" y2="3"/><line x1="12" x2="12" y1="21" y2="12"/><line x1="12" x2="12" y1="8" y2="3"/><line x1="20" x2="20" y1="21" y2="16"/><line x1="20" x2="20" y1="12" y2="3"/><line x1="2" x2="6" y1="14" y2="14"/><line x1="10" x2="14" y1="8" y2="8"/><line x1="18" x2="22" y1="16" y2="16"/></svg>',
        'upload' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" x2="12" y1="3" y2="15"/></svg>',
        'settings' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg>',
        'log-out' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" x2="9" y1="12" y2="12"/></svg>',
        'log-in' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" x2="3" y1="12" y2="12"/></svg>',
    ];

    $svg = $icons[$name] ?? $icons['home'];
    return '<span class="sidebar-icon ' . $class . '" aria-hidden="true">' . $svg . '</span>';
}
?>

<aside class="sidebar" role="navigation" aria-label="Huvudnavigering">
    <!-- Logo from branding settings -->
    <?php
    $sidebarLogo = getBranding('logos.sidebar');
    if ($sidebarLogo):
    ?>
    <a href="/" class="sidebar-logo" aria-label="TheHUB Hem">
        <img src="<?= h($sidebarLogo) ?>" alt="TheHUB" width="40" height="40">
    </a>
    <?php endif; ?>

    <nav class="sidebar-nav">
        <!-- PUBLIC NAVIGATION -->
        <a href="/"
           class="sidebar-link<?= $current_page == 'index.php' && strpos($current_path, '/admin/') === false ? ' active' : '' ?>"
           aria-label="Hem"
           <?= $current_page == 'index.php' && strpos($current_path, '/admin/') === false ? 'aria-current="page"' : '' ?>>
            <?= nav_icon('home') ?>
            <span class="sidebar-label">Hem</span>
        </a>

        <a href="/calendar"
           class="sidebar-link<?= $current_page == 'events.php' && strpos($current_path, '/admin/') === false ? ' active' : '' ?>"
           aria-label="Kalender"
           <?= $current_page == 'events.php' && strpos($current_path, '/admin/') === false ? 'aria-current="page"' : '' ?>>
            <?= nav_icon('calendar') ?>
            <span class="sidebar-label">Kalender</span>
        </a>

        <a href="/results"
           class="sidebar-link<?= $current_page == 'results.php' && strpos($current_path, '/admin/') === false ? ' active' : '' ?>"
           aria-label="Resultat"
           <?= $current_page == 'results.php' && strpos($current_path, '/admin/') === false ? 'aria-current="page"' : '' ?>>
            <?= nav_icon('flag') ?>
            <span class="sidebar-label">Resultat</span>
        </a>

        <a href="/series"
           class="sidebar-link<?= $current_page == 'series.php' && strpos($current_path, '/admin/') === false ? ' active' : '' ?>"
           aria-label="Serier"
           <?= $current_page == 'series.php' && strpos($current_path, '/admin/') === false ? 'aria-current="page"' : '' ?>>
            <?= nav_icon('trophy') ?>
            <span class="sidebar-label">Serier</span>
        </a>

        <a href="/database"
           class="sidebar-link<?= $current_page == 'riders.php' && strpos($current_path, '/admin/') === false ? ' active' : '' ?>"
           aria-label="Databas"
           <?= $current_page == 'riders.php' && strpos($current_path, '/admin/') === false ? 'aria-current="page"' : '' ?>>
            <?= nav_icon('users') ?>
            <span class="sidebar-label">Databas</span>
        </a>

        <a href="/ranking"
           class="sidebar-link<?= strpos($current_path, '/ranking/') !== false && strpos($current_path, '/admin/') === false ? ' active' : '' ?>"
           aria-label="Ranking"
           <?= strpos($current_path, '/ranking/') !== false && strpos($current_path, '/admin/') === false ? 'aria-current="page"' : '' ?>>
            <?= nav_icon('trending-up') ?>
            <span class="sidebar-label">Ranking</span>
        </a>

        <?php if ($is_admin): ?>
        <!-- ADMIN NAVIGATION -->
        <div class="sidebar-divider"></div>

        <a href="/admin/dashboard.php"
           class="sidebar-link<?= strpos($current_path, '/admin/dashboard.php') !== false ? ' active' : '' ?>"
           aria-label="Dashboard"
           <?= strpos($current_path, '/admin/dashboard.php') !== false ? 'aria-current="page"' : '' ?>>
            <?= nav_icon('layout-dashboard') ?>
            <span class="sidebar-label">Dashboard</span>
        </a>

        <a href="/admin/events.php"
           class="sidebar-link<?= strpos($current_path, '/admin/events') !== false || strpos($current_path, '/admin/results') !== false || strpos($current_path, '/admin/venues') !== false || strpos($current_path, '/admin/ticket') !== false ? ' active' : '' ?>"
           aria-label="Tävlingar"
           <?= strpos($current_path, '/admin/events') !== false ? 'aria-current="page"' : '' ?>>
            <?= nav_icon('calendar-check') ?>
            <span class="sidebar-label">Tävlingar</span>
        </a>

        <a href="/admin/series.php"
           class="sidebar-link<?= strpos($current_path, '/admin/series') !== false || strpos($current_path, '/admin/ranking') !== false || strpos($current_path, '/admin/club-points') !== false ? ' active' : '' ?>"
           aria-label="Serier"
           <?= strpos($current_path, '/admin/series') !== false ? 'aria-current="page"' : '' ?>>
            <?= nav_icon('medal') ?>
            <span class="sidebar-label">Serier</span>
        </a>

        <a href="/admin/riders.php"
           class="sidebar-link<?= strpos($current_path, '/admin/riders') !== false || strpos($current_path, '/admin/clubs') !== false || strpos($current_path, '/admin/find-duplicates') !== false || strpos($current_path, '/admin/cleanup-') !== false ? ' active' : '' ?>"
           aria-label="Databas"
           <?= strpos($current_path, '/admin/riders') !== false || strpos($current_path, '/admin/clubs') !== false ? 'aria-current="page"' : '' ?>>
            <?= nav_icon('users') ?>
            <span class="sidebar-label">Databas</span>
        </a>

        <a href="/admin/classes.php"
           class="sidebar-link<?= strpos($current_path, '/admin/classes') !== false || strpos($current_path, '/admin/license') !== false || strpos($current_path, '/admin/point-') !== false || strpos($current_path, '/admin/registration-rules') !== false || strpos($current_path, '/admin/public-settings') !== false || strpos($current_path, '/admin/global-texts') !== false ? ' active' : '' ?>"
           aria-label="Konfiguration"
           <?= strpos($current_path, '/admin/classes') !== false ? 'aria-current="page"' : '' ?>>
            <?= nav_icon('sliders') ?>
            <span class="sidebar-label">Config</span>
        </a>

        <a href="/admin/import.php"
           class="sidebar-link<?= strpos($current_path, '/admin/import') !== false ? ' active' : '' ?>"
           aria-label="Import"
           <?= strpos($current_path, '/admin/import') !== false ? 'aria-current="page"' : '' ?>>
            <?= nav_icon('upload') ?>
            <span class="sidebar-label">Import</span>
        </a>

        <?php if ($is_super_admin): ?>
        <a href="/admin/users.php"
           class="sidebar-link<?= strpos($current_path, '/admin/users') !== false || strpos($current_path, '/admin/role-') !== false || strpos($current_path, '/admin/system-') !== false || strpos($current_path, '/admin/settings') !== false || strpos($current_path, '/admin/setup-') !== false || strpos($current_path, '/admin/run-') !== false ? ' active' : '' ?>"
           aria-label="System"
           <?= strpos($current_path, '/admin/users') !== false ? 'aria-current="page"' : '' ?>>
            <?= nav_icon('settings') ?>
            <span class="sidebar-label">System</span>
        </a>
        <?php endif; ?>
        <?php endif; ?>
    </nav>
</aside>

<style>
/* Admin sidebar styles */
.sidebar-nav {
    display: flex;
    flex-direction: column;
    gap: 4px;
    padding: 8px;
}
.sidebar-link {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 12px;
    color: var(--color-text-secondary, #6b7280);
    text-decoration: none;
    border-radius: 8px;
    transition: all 0.15s ease;
}
.sidebar-link:hover {
    background: var(--color-bg-hover, rgba(0,0,0,0.04));
    color: var(--color-text-primary, #171717);
}
.sidebar-link.active,
.sidebar-link[aria-current="page"] {
    background: var(--color-accent-light, #e8f0fb);
    color: var(--color-accent, #004a98);
}
.sidebar-icon-svg {
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.sidebar-icon-svg svg {
    width: 20px;
    height: 20px;
    stroke: currentColor;
}
.sidebar-label {
    font-size: 14px;
    font-weight: 500;
    white-space: nowrap;
}
.sidebar-divider {
    height: 1px;
    background: var(--color-border, #E5E7EB);
    margin: 8px 0;
}

/* Desktop: icon-only compact sidebar */
@media (min-width: 1024px) {
    .sidebar-link {
        flex-direction: column;
        gap: 4px;
        padding: 8px;
        text-align: center;
    }
    .sidebar-label {
        font-size: 10px;
    }
}
</style>

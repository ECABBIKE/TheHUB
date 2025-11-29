<?php
/**
 * TheHUB Navigation v2.1 - Restructured
 * 6 huvudgrupper med fliknavigation
 *
 * Struktur:
 * - Dashboard
 * - Tävlingar (Events, Resultat, Venues, Biljetter)
 * - Serier (Serier, Ranking, Klubbpoäng)
 * - Deltagare (Deltagare, Klubbar)
 * - Konfiguration (Klasser, Licenser, Poängskalor, Regler, Texter)
 * - Import (Översikt, Deltagare, Resultat, Events, UCI, Historik)
 * - System (Användare, Behörigheter, Databas) [Super Admin]
 */

$current_page = basename($_SERVER['PHP_SELF']);
$current_path = $_SERVER['PHP_SELF'];
$is_admin = isLoggedIn();
$is_super_admin = hasRole('super_admin');

/**
 * Bestäm vilken huvudgrupp som är aktiv
 */
function get_active_admin_group($current_page, $current_path) {
    // Dashboard
    if ($current_page === 'dashboard.php') {
        return 'dashboard';
    }

    // Tävlingar (Events, Resultat, Venues, Biljetter)
    $competition_pages = [
        'events.php', 'event-create.php', 'event-edit.php', 'event-delete.php',
        'results.php', 'edit-results.php', 'recalculate-results.php', 'clear-event-results.php', 'reset-results.php',
        'venues.php',
        'ticketing.php', 'event-pricing.php', 'event-tickets.php', 'event-ticketing.php', 'refund-requests.php', 'pricing-templates.php'
    ];
    if (in_array($current_page, $competition_pages) && strpos($current_path, '/admin/') !== false) {
        return 'competitions';
    }

    // Serier (Serier, Ranking, Klubbpoäng)
    $standings_pages = [
        'series.php', 'series-events.php', 'series-pricing.php',
        'ranking.php', 'ranking-debug.php', 'ranking-minimal.php', 'setup-ranking-system.php',
        'club-points.php', 'club-points-detail.php'
    ];
    if (in_array($current_page, $standings_pages) && strpos($current_path, '/admin/') !== false) {
        return 'standings';
    }

    // Deltagare (Deltagare, Klubbar)
    $participants_pages = [
        'riders.php', 'rider-edit.php', 'rider-delete.php',
        'clubs.php', 'club-edit.php', 'cleanup-clubs.php'
    ];
    if (in_array($current_page, $participants_pages) && strpos($current_path, '/admin/') !== false) {
        return 'participants';
    }

    // Konfiguration (Klasser, Licenser, Poängskalor, Regler, Texter)
    $config_pages = [
        'classes.php', 'reassign-classes.php', 'reset-classes.php', 'move-class-results.php',
        'license-class-matrix.php',
        'point-scales.php', 'point-scale-edit.php', 'point-templates.php',
        'registration-rules.php',
        'public-settings.php', 'global-texts.php'
    ];
    if (in_array($current_page, $config_pages) && strpos($current_path, '/admin/') !== false) {
        return 'config';
    }

    // Import
    $import_pages = [
        'import.php', 'import-history.php',
        'import-riders.php', 'import-riders-flexible.php', 'import-riders-extended.php',
        'import-results.php', 'import-results-preview.php',
        'import-events.php', 'import-series.php', 'import-classes.php', 'import-clubs.php',
        'import-uci-preview.php', 'import-uci-simple.php', 'import-gravity-id.php'
    ];
    if (in_array($current_page, $import_pages) && strpos($current_path, '/admin/') !== false) {
        return 'import';
    }

    // System (super admin)
    $settings_pages = [
        'users.php', 'user-edit.php', 'user-events.php', 'user-rider.php',
        'role-permissions.php',
        'system-settings.php', 'settings.php', 'setup-database.php', 'run-migrations.php'
    ];
    if (in_array($current_page, $settings_pages) && strpos($current_path, '/admin/') !== false) {
        return 'settings';
    }

    return 'dashboard';
}

$active_group = get_active_admin_group($current_page, $current_path);
?>

<nav class="sidebar">
    <!-- PUBLIC MENU -->
    <div class="sidebar-section main-menu">
        <h3 class="sidebar-title"><a href="<?= SITE_URL ?>" style="color: inherit; text-decoration: none;">TheHUB</a></h3>
        <ul class="sidebar-nav">
            <li>
                <a href="<?= SITE_URL ?>" class="<?= $current_page == 'index.php' && strpos($current_path, '/admin/') === false ? 'active' : '' ?>">
                    <i data-lucide="home"></i>
                    <span>Hem</span>
                </a>
            </li>
            <li>
                <a href="/events.php" class="<?= $current_page == 'events.php' && strpos($current_path, '/admin/') === false ? 'active' : '' ?>">
                    <i data-lucide="calendar"></i>
                    <span>Kalender</span>
                </a>
            </li>
            <li>
                <a href="/results.php" class="<?= $current_page == 'results.php' && strpos($current_path, '/admin/') === false ? 'active' : '' ?>">
                    <i data-lucide="trophy"></i>
                    <span>Resultat</span>
                </a>
            </li>
            <li>
                <a href="/series.php" class="<?= $current_page == 'series.php' && strpos($current_path, '/admin/') === false ? 'active' : '' ?>">
                    <i data-lucide="award"></i>
                    <span>Serier</span>
                </a>
            </li>
            <li>
                <a href="/riders.php" class="<?= $current_page == 'riders.php' && strpos($current_path, '/admin/') === false ? 'active' : '' ?>">
                    <i data-lucide="users"></i>
                    <span>Deltagare</span>
                </a>
            </li>
            <li>
                <a href="/clubs/leaderboard.php" class="<?= strpos($current_path, '/clubs/') !== false && strpos($current_path, '/admin/') === false ? 'active' : '' ?>">
                    <i data-lucide="trophy"></i>
                    <span>Klubbar</span>
                </a>
            </li>
            <li>
                <a href="/ranking/" class="<?= strpos($current_path, '/ranking/') !== false && strpos($current_path, '/admin/') === false ? 'active' : '' ?>">
                    <i data-lucide="trending-up"></i>
                    <span>Ranking</span>
                </a>
            </li>
        </ul>
    </div>

    <?php if ($is_admin): ?>
    <!-- ADMIN MENU v2.1 - 6 GRUPPER -->
    <div class="sidebar-section">
        <h3 class="sidebar-title">Admin</h3>
        <ul class="sidebar-nav">
            <li>
                <a href="/admin/dashboard.php" class="<?= $active_group === 'dashboard' ? 'active' : '' ?>">
                    <i data-lucide="layout-dashboard"></i>
                    <span>Dashboard</span>
                </a>
            </li>
            <li>
                <a href="/admin/events.php" class="<?= $active_group === 'competitions' ? 'active' : '' ?>">
                    <i data-lucide="calendar-check"></i>
                    <span>Tävlingar</span>
                </a>
            </li>
            <li>
                <a href="/admin/series.php" class="<?= $active_group === 'standings' ? 'active' : '' ?>">
                    <i data-lucide="medal"></i>
                    <span>Serier</span>
                </a>
            </li>
            <li>
                <a href="/admin/riders.php" class="<?= $active_group === 'participants' ? 'active' : '' ?>">
                    <i data-lucide="users"></i>
                    <span>Deltagare</span>
                </a>
            </li>
            <li>
                <a href="/admin/classes.php" class="<?= $active_group === 'config' ? 'active' : '' ?>">
                    <i data-lucide="sliders"></i>
                    <span>Konfiguration</span>
                </a>
            </li>
            <li>
                <a href="/admin/import.php" class="<?= $active_group === 'import' ? 'active' : '' ?>">
                    <i data-lucide="upload"></i>
                    <span>Import</span>
                </a>
            </li>
            <?php if ($is_super_admin): ?>
            <li>
                <a href="/admin/users.php" class="<?= $active_group === 'settings' ? 'active' : '' ?>">
                    <i data-lucide="settings"></i>
                    <span>System</span>
                </a>
            </li>
            <?php endif; ?>
        </ul>
    </div>

    <!-- LOGOUT -->
    <div class="sidebar-section" style="margin-top: auto;">
        <div class="sidebar-footer">
            <a href="/admin/logout.php" class="btn btn--secondary btn--sm w-full">
                <i data-lucide="log-out"></i>
                <span>Logga ut</span>
            </a>
        </div>
    </div>
    <?php else: ?>
    <!-- ADMIN LOGIN -->
    <div class="sidebar-section admin-login-section" style="margin-top: auto; padding-top: 1rem;">
        <a href="/admin/login.php" class="btn btn--primary btn--sm w-full">
            <i data-lucide="log-in"></i>
            <span>Admin Login</span>
        </a>
    </div>
    <?php endif; ?>
</nav>

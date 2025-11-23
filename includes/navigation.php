<?php
/**
 * Navigation sidebar for TheHUB
 */

// Get current page
$current_page = basename($_SERVER['PHP_SELF']);
$is_admin = isLoggedIn();
?>
<nav class="gs-sidebar">
    <div class="gs-menu-section gs-main-menu">
        <h3 class="gs-menu-title"><a href="<?= SITE_URL ?>" style="color: inherit; text-decoration: none;">TheHUB</a></h3>
        <ul class="gs-menu">
            <li><a href="<?= SITE_URL ?>" class="<?= $current_page == 'index.php' ? 'active' : '' ?>">
                <i data-lucide="home"></i> Hem
            </a></li>
            <li><a href="/events.php" class="<?= $current_page == 'events.php' && strpos($_SERVER['PHP_SELF'], '/admin/') === false ? 'active' : '' ?>">
                <i data-lucide="calendar"></i> Kalender
            </a></li>
            <li><a href="/results.php" class="<?= $current_page == 'results.php' && strpos($_SERVER['PHP_SELF'], '/admin/') === false ? 'active' : '' ?>">
                <i data-lucide="trophy"></i> Resultat
            </a></li>
            <li><a href="/series.php" class="<?= $current_page == 'series.php' && strpos($_SERVER['PHP_SELF'], '/admin/') === false ? 'active' : '' ?>">
                <i data-lucide="award"></i> Serier
            </a></li>
            <li><a href="/riders.php" class="<?= $current_page == 'riders.php' && strpos($_SERVER['PHP_SELF'], '/admin/') === false ? 'active' : '' ?>">
                <i data-lucide="users"></i> Deltagare
            </a></li>
            <li><a href="/clubs/leaderboard.php" class="<?= $current_page == 'leaderboard.php' ? 'active' : '' ?>">
                <i data-lucide="trophy"></i> Klubbar
            </a></li>
        </ul>
    </div>

    <?php if ($is_admin): ?>
    <div class="gs-menu-section">
        <h3 class="gs-menu-title">Admin</h3>
        <ul class="gs-menu">
            <li><a href="/admin/dashboard.php" class="<?= $current_page == 'dashboard.php' ? 'active' : '' ?>">
                <i data-lucide="layout-dashboard"></i> Dashboard
            </a></li>
            <li><a href="/admin/events.php" class="<?= $current_page == 'events.php' && strpos($_SERVER['PHP_SELF'], '/admin/') !== false ? 'active' : '' ?>">
                <i data-lucide="calendar-check"></i> Events
            </a></li>
            <li><a href="/admin/ticketing.php" class="<?= in_array($current_page, ['ticketing.php', 'event-pricing.php', 'event-tickets.php', 'refund-requests.php', 'pricing-templates.php']) ? 'active' : '' ?>">
                <i data-lucide="ticket"></i> Ticketing
            </a></li>
            <li><a href="/admin/series.php" class="<?= $current_page == 'series.php' && strpos($_SERVER['PHP_SELF'], '/admin/') !== false ? 'active' : '' ?>">
                <i data-lucide="award"></i> Serier
            </a></li>
            <li><a href="/admin/riders.php" class="<?= $current_page == 'riders.php' && strpos($_SERVER['PHP_SELF'], '/admin/') !== false ? 'active' : '' ?>">
                <i data-lucide="user-circle"></i> Deltagare
            </a></li>
            <li><a href="/admin/clubs.php" class="<?= $current_page == 'clubs.php' ? 'active' : '' ?>">
                <i data-lucide="building"></i> Klubbar
            </a></li>
            <li><a href="/admin/club-points.php" class="<?= $current_page == 'club-points.php' || $current_page == 'club-points-detail.php' ? 'active' : '' ?>">
                <i data-lucide="trophy"></i> Klubbpoäng
            </a></li>
            <li><a href="/admin/venues.php" class="<?= $current_page == 'venues.php' ? 'active' : '' ?>">
                <i data-lucide="mountain"></i> Venues
            </a></li>
            <li><a href="/admin/results.php" class="<?= $current_page == 'results.php' && strpos($_SERVER['PHP_SELF'], '/admin/') !== false ? 'active' : '' ?>">
                <i data-lucide="trophy"></i> Resultat
            </a></li>
            <li><a href="/admin/import.php" class="<?= in_array($current_page, ['import.php', 'import-history.php']) ? 'active' : '' ?>">
                <i data-lucide="upload"></i> Import
            </a></li>
            <li><a href="/admin/public-settings.php" class="<?= $current_page == 'public-settings.php' ? 'active' : '' ?>">
                <i data-lucide="settings"></i> Publika Inställningar
            </a></li>
            <li><a href="/admin/system-settings.php" class="<?= $current_page == 'system-settings.php' ? 'active' : '' ?>">
                <i data-lucide="cog"></i> Systeminställningar
            </a></li>
        </ul>
        <div class="gs-menu-footer">
            <a href="/admin/logout.php" class="gs-btn gs-btn-sm gs-btn-outline gs-w-full">
                <i data-lucide="log-out"></i> Logga ut
            </a>
        </div>
    </div>
    <?php else: ?>
    <!-- Admin Login (shown at bottom on desktop, top on mobile) -->
    <div class="gs-menu-section gs-admin-login-section" style="margin-top: auto; padding-top: 1rem;">
        <a href="/admin/login.php" class="gs-btn gs-btn-sm gs-btn-primary gs-w-full">
            <i data-lucide="log-in"></i> Admin Login
        </a>
    </div>
    <?php endif; ?>
</nav>

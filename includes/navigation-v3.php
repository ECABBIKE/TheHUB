<?php
/**
 * TheHUB V3.5 Icon-Only Navigation
 * Simplified sidebar navigation with icon-only design (72px width)
 */

$current_page = basename($_SERVER['PHP_SELF']);
$current_path = $_SERVER['PHP_SELF'];
$is_admin = isLoggedIn();
$is_super_admin = hasRole('super_admin');

// Emoji icons matching V3 design
$icons = [
    'home' => '🏠',
    'calendar' => '📅',
    'trophy' => '🏆',
    'award' => '🏅',
    'users' => '👥',
    'trending-up' => '📈',
    'layout-dashboard' => '📊',
    'calendar-check' => '✅',
    'medal' => '🎖️',
    'sliders' => '⚙️',
    'upload' => '📤',
    'settings' => '🛡️',
    'log-out' => '👋',
    'log-in' => '🔐'
];
?>

<aside class="sidebar" role="navigation" aria-label="Huvudnavigering">
    <nav class="sidebar-nav">
        <!-- PUBLIC NAVIGATION -->
        <a href="<?= SITE_URL ?>"
           class="sidebar-link<?= $current_page == 'index.php' && strpos($current_path, '/admin/') === false ? ' active' : '' ?>"
           aria-label="Hem"
           <?= $current_page == 'index.php' && strpos($current_path, '/admin/') === false ? 'aria-current="page"' : '' ?>>
            <span class="sidebar-icon" aria-hidden="true"><?= $icons['home'] ?></span>
            <span class="sidebar-label">Hem</span>
        </a>

        <a href="/events.php"
           class="sidebar-link<?= $current_page == 'events.php' && strpos($current_path, '/admin/') === false ? ' active' : '' ?>"
           aria-label="Kalender"
           <?= $current_page == 'events.php' && strpos($current_path, '/admin/') === false ? 'aria-current="page"' : '' ?>>
            <span class="sidebar-icon" aria-hidden="true"><?= $icons['calendar'] ?></span>
            <span class="sidebar-label">Kalender</span>
        </a>

        <a href="/results.php"
           class="sidebar-link<?= $current_page == 'results.php' && strpos($current_path, '/admin/') === false ? ' active' : '' ?>"
           aria-label="Resultat"
           <?= $current_page == 'results.php' && strpos($current_path, '/admin/') === false ? 'aria-current="page"' : '' ?>>
            <span class="sidebar-icon" aria-hidden="true"><?= $icons['trophy'] ?></span>
            <span class="sidebar-label">Resultat</span>
        </a>

        <a href="/series.php"
           class="sidebar-link<?= $current_page == 'series.php' && strpos($current_path, '/admin/') === false ? ' active' : '' ?>"
           aria-label="Serier"
           <?= $current_page == 'series.php' && strpos($current_path, '/admin/') === false ? 'aria-current="page"' : '' ?>>
            <span class="sidebar-icon" aria-hidden="true"><?= $icons['award'] ?></span>
            <span class="sidebar-label">Serier</span>
        </a>

        <a href="/riders.php"
           class="sidebar-link<?= $current_page == 'riders.php' && strpos($current_path, '/admin/') === false ? ' active' : '' ?>"
           aria-label="Deltagare"
           <?= $current_page == 'riders.php' && strpos($current_path, '/admin/') === false ? 'aria-current="page"' : '' ?>>
            <span class="sidebar-icon" aria-hidden="true"><?= $icons['users'] ?></span>
            <span class="sidebar-label">Deltagare</span>
        </a>

        <a href="/clubs/leaderboard.php"
           class="sidebar-link<?= strpos($current_path, '/clubs/') !== false && strpos($current_path, '/admin/') === false ? ' active' : '' ?>"
           aria-label="Klubbar"
           <?= strpos($current_path, '/clubs/') !== false && strpos($current_path, '/admin/') === false ? 'aria-current="page"' : '' ?>>
            <span class="sidebar-icon" aria-hidden="true"><?= $icons['trophy'] ?></span>
            <span class="sidebar-label">Klubbar</span>
        </a>

        <a href="/ranking/"
           class="sidebar-link<?= strpos($current_path, '/ranking/') !== false && strpos($current_path, '/admin/') === false ? ' active' : '' ?>"
           aria-label="Ranking"
           <?= strpos($current_path, '/ranking/') !== false && strpos($current_path, '/admin/') === false ? 'aria-current="page"' : '' ?>>
            <span class="sidebar-icon" aria-hidden="true"><?= $icons['trending-up'] ?></span>
            <span class="sidebar-label">Ranking</span>
        </a>

        <?php if ($is_admin): ?>
        <!-- ADMIN NAVIGATION -->
        <div style="height: 1px; background: var(--color-border); margin: var(--space-sm) 0;"></div>

        <a href="/admin/dashboard.php"
           class="sidebar-link<?= strpos($current_path, '/admin/dashboard.php') !== false ? ' active' : '' ?>"
           aria-label="Dashboard"
           <?= strpos($current_path, '/admin/dashboard.php') !== false ? 'aria-current="page"' : '' ?>>
            <span class="sidebar-icon" aria-hidden="true"><?= $icons['layout-dashboard'] ?></span>
            <span class="sidebar-label">Dashboard</span>
        </a>

        <a href="/admin/events.php"
           class="sidebar-link<?= strpos($current_path, '/admin/events') !== false || strpos($current_path, '/admin/results') !== false || strpos($current_path, '/admin/venues') !== false || strpos($current_path, '/admin/ticket') !== false ? ' active' : '' ?>"
           aria-label="Tävlingar"
           <?= strpos($current_path, '/admin/events') !== false ? 'aria-current="page"' : '' ?>>
            <span class="sidebar-icon" aria-hidden="true"><?= $icons['calendar-check'] ?></span>
            <span class="sidebar-label">Tävlingar</span>
        </a>

        <a href="/admin/series.php"
           class="sidebar-link<?= strpos($current_path, '/admin/series') !== false || strpos($current_path, '/admin/ranking') !== false || strpos($current_path, '/admin/club-points') !== false ? ' active' : '' ?>"
           aria-label="Serier"
           <?= strpos($current_path, '/admin/series') !== false ? 'aria-current="page"' : '' ?>>
            <span class="sidebar-icon" aria-hidden="true"><?= $icons['medal'] ?></span>
            <span class="sidebar-label">Serier</span>
        </a>

        <a href="/admin/riders.php"
           class="sidebar-link<?= strpos($current_path, '/admin/riders') !== false || strpos($current_path, '/admin/clubs') !== false ? ' active' : '' ?>"
           aria-label="Deltagare"
           <?= strpos($current_path, '/admin/riders') !== false ? 'aria-current="page"' : '' ?>>
            <span class="sidebar-icon" aria-hidden="true"><?= $icons['users'] ?></span>
            <span class="sidebar-label">Deltagare</span>
        </a>

        <a href="/admin/classes.php"
           class="sidebar-link<?= strpos($current_path, '/admin/classes') !== false || strpos($current_path, '/admin/license') !== false || strpos($current_path, '/admin/point-') !== false || strpos($current_path, '/admin/registration-rules') !== false || strpos($current_path, '/admin/public-settings') !== false || strpos($current_path, '/admin/global-texts') !== false ? ' active' : '' ?>"
           aria-label="Konfiguration"
           <?= strpos($current_path, '/admin/classes') !== false ? 'aria-current="page"' : '' ?>>
            <span class="sidebar-icon" aria-hidden="true"><?= $icons['sliders'] ?></span>
            <span class="sidebar-label">Config</span>
        </a>

        <a href="/admin/import.php"
           class="sidebar-link<?= strpos($current_path, '/admin/import') !== false ? ' active' : '' ?>"
           aria-label="Import"
           <?= strpos($current_path, '/admin/import') !== false ? 'aria-current="page"' : '' ?>>
            <span class="sidebar-icon" aria-hidden="true"><?= $icons['upload'] ?></span>
            <span class="sidebar-label">Import</span>
        </a>

        <?php if ($is_super_admin): ?>
        <a href="/admin/users.php"
           class="sidebar-link<?= strpos($current_path, '/admin/users') !== false || strpos($current_path, '/admin/role-') !== false || strpos($current_path, '/admin/system-') !== false || strpos($current_path, '/admin/settings') !== false || strpos($current_path, '/admin/setup-') !== false || strpos($current_path, '/admin/run-') !== false ? ' active' : '' ?>"
           aria-label="System"
           <?= strpos($current_path, '/admin/users') !== false ? 'aria-current="page"' : '' ?>>
            <span class="sidebar-icon" aria-hidden="true"><?= $icons['settings'] ?></span>
            <span class="sidebar-label">System</span>
        </a>
        <?php endif; ?>
        <?php endif; ?>
    </nav>
</aside>

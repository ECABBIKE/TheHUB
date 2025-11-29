<?php
/**
 * TheHUB V2 SPA Entry Point
 *
 * Central entry point for the modular SPA architecture.
 * Handles both full page loads and AJAX content requests.
 *
 * Usage:
 *   /results     -> loads pages/results.php
 *   /series/5    -> loads pages/series-standings.php with id=5
 *   /rider/123   -> loads pages/rider.php with id=123
 */

// Load core configuration
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/router.php';

// Get current page info from URL
$pageInfo = hub_get_current_page();

// Make page params available globally (for id, etc.)
if (!empty($pageInfo['params'])) {
    foreach ($pageInfo['params'] as $key => $value) {
        $_GET[$key] = $value;
    }
}

// AJAX request = return only page content (no layout)
if (hub_is_ajax()) {
    header('Content-Type: text/html; charset=utf-8');
    header('X-Page-Title: ' . htmlspecialchars($pageInfo['title'] ?? 'TheHUB') . ' - TheHUB');
    header('X-Page-Section: ' . htmlspecialchars($pageInfo['section'] ?? ''));

    // Include breadcrumb for AJAX requests
    include __DIR__ . '/components/breadcrumb.php';

    // Load page content
    if (file_exists($pageInfo['file'])) {
        include $pageInfo['file'];
    } else {
        include __DIR__ . '/pages/404.php';
    }
    exit;
}

// Full page load - include complete layout
$pageTitle = $pageInfo['title'] ?? 'TheHUB';
$pageSection = $pageInfo['section'] ?? 'home';
?>
<!DOCTYPE html>
<html lang="sv" data-theme="light">
<head>
    <?php include __DIR__ . '/components/head.php'; ?>
</head>
<body class="public-page">
    <!-- Skip Link for Accessibility -->
    <a href="#main-content" class="skip-link">Hoppa till huvudinnehall</a>

    <!-- Mobile Menu Toggle (hidden on desktop) -->
    <button class="mobile-menu-toggle" onclick="toggleMenu()" aria-label="Toggle menu">
        <i data-lucide="menu"></i>
    </button>

    <!-- Sidebar Navigation -->
    <?php include __DIR__ . '/components/sidebar.php'; ?>

    <!-- Overlay for mobile menu -->
    <div class="sidebar-overlay" onclick="closeMenu()"></div>

    <!-- Main Content Area -->
    <main id="main-content" class="main-content" role="main" aria-live="polite" tabindex="-1">
        <?php include __DIR__ . '/components/breadcrumb.php'; ?>

        <div id="page-content" class="page-content">
            <?php
            if (file_exists($pageInfo['file'])) {
                include $pageInfo['file'];
            } else {
                include __DIR__ . '/pages/404.php';
            }
            ?>
        </div>
    </main>

    <!-- Footer -->
    <?php include __DIR__ . '/components/footer.php'; ?>

    <!-- Bottom Navigation (mobile) -->
    <?php include __DIR__ . '/includes/nav-bottom.php'; ?>

    <!-- Theme Switcher -->
    <?php include __DIR__ . '/components/theme-switcher.php'; ?>

    <!-- Scripts -->
    <script src="https://unpkg.com/lucide@latest"></script>
    <script src="/assets/js/theme.js?v=<?= filemtime(__DIR__ . '/assets/js/theme.js') ?>"></script>
    <script src="/assets/js/router.js?v=<?= time() ?>"></script>
    <script src="/assets/js/app.js?v=<?= time() ?>"></script>

    <script>
        // Initialize Lucide icons
        lucide.createIcons();

        // Mobile menu functions
        function toggleMenu() {
            const sidebar = document.querySelector('.sidebar');
            const overlay = document.querySelector('.sidebar-overlay');
            if (!sidebar || !overlay) return;

            const isOpen = sidebar.classList.contains('open');
            sidebar.classList.toggle('open', !isOpen);
            overlay.classList.toggle('active', !isOpen);
            document.body.style.overflow = isOpen ? '' : 'hidden';
        }

        function closeMenu() {
            const sidebar = document.querySelector('.sidebar');
            const overlay = document.querySelector('.sidebar-overlay');
            if (sidebar && overlay) {
                sidebar.classList.remove('open');
                overlay.classList.remove('active');
                document.body.style.overflow = '';
            }
        }

        // Auto-close menu on desktop resize
        window.addEventListener('resize', () => {
            if (window.innerWidth >= 1024) closeMenu();
        });
    </script>
</body>
</html>

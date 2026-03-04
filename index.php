<?php
// CRITICAL: Enable errors FIRST before anything else
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

// Performance timing - outputs as HTML comment at page bottom
$_pageTimings = ['start' => microtime(true)];

// Start output buffering to allow redirects from included pages (like login.php)
ob_start();

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    // CRITICAL: Set server-side session lifetime to match cookie lifetime
    // PHP default is 1440s (24min) which causes premature session expiration
    ini_set('session.gc_maxlifetime', 2592000); // 30 days

    // Configure session cookie with longer lifetime
    session_set_cookie_params([
        'lifetime' => 2592000, // 30 days
        'path' => '/',
        'domain' => '',
        'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_name('thehub_session');
    session_start();
}

// Load config files
$hubConfigPath = __DIR__ . '/hub-config.php';
if (file_exists($hubConfigPath)) {
    require_once $hubConfigPath;
}
$_pageTimings['config'] = microtime(true);

$routerPath = __DIR__ . '/router.php';
if (file_exists($routerPath)) {
    require_once $routerPath;
}
$_pageTimings['router'] = microtime(true);

// Fallback for hub_get_theme
if (!function_exists('hub_get_theme')) {
    function hub_get_theme() {
        return 'dark';
    }
}

// Fallback for hub_is_ajax
if (!function_exists('hub_is_ajax')) {
    function hub_is_ajax() {
        return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
               strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }
}

// Fallback for hub_get_current_page
if (!function_exists('hub_get_current_page')) {
    function hub_get_current_page() {
        return [
            'page' => 'welcome',
            'section' => null,
            'params' => [],
            'file' => __DIR__ . '/pages/welcome.php'
        ];
    }
}

$pageInfo = hub_get_current_page();
$theme = hub_get_theme();

// CRITICAL: Release session lock early to prevent blocking other requests.
// PHP holds an exclusive lock on the session file during the entire request.
// If a page takes 5+ seconds to render, ALL other tabs/requests from the same
// user are blocked until this request finishes. session_write_close() releases
// the lock while keeping $_SESSION readable for the rest of the request.
// Only for GET requests - POST requests may need to write to session.
if (session_status() === PHP_SESSION_ACTIVE && $_SERVER['REQUEST_METHOD'] === 'GET') {
    session_write_close();
}

// AJAX request = return only content
if (hub_is_ajax()) {
    header('Content-Type: text/html; charset=utf-8');
    header('X-Page-Title: ' . ucfirst($pageInfo['page']) . ' – TheHUB');

    include __DIR__ . '/components/breadcrumb.php';

    if (file_exists($pageInfo['file'])) {
        include $pageInfo['file'];
    } else {
        include HUB_ROOT . '/pages/404.php';
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="sv">
<head>
    <?php include __DIR__ . '/components/head.php'; ?>
</head>
<body>
    <a href="#main-content" class="skip-link">Hoppa till huvudinnehåll</a>

    <?php include __DIR__ . '/components/header.php'; ?>

    <div class="app-layout">
        <?php include __DIR__ . '/components/sidebar.php'; ?>

        <main id="main-content" class="main-content" role="main" aria-live="polite" tabindex="-1">
            <?php include __DIR__ . '/components/breadcrumb.php'; ?>

            <div id="page-content" class="page-content">
                <?php
                $_pageTimings['before_page'] = microtime(true);
                if (file_exists($pageInfo['file'])) {
                    include $pageInfo['file'];
                } else {
                    include HUB_ROOT . '/pages/404.php';
                }
                $_pageTimings['after_page'] = microtime(true);
                ?>
            </div>
        </main>
    </div>

    <?php include __DIR__ . '/components/mobile-nav.php'; ?>
    <?php include __DIR__ . '/components/footer.php'; ?>
    <?php include __DIR__ . '/components/woocommerce-modal.php'; ?>
    <?php
    // Performance timing
    $_pageTimings['end'] = microtime(true);
    $s = $_pageTimings['start'];
    $_perfConfig = round(($_pageTimings['config'] - $s) * 1000);
    $_perfRouter = round(($_pageTimings['router'] - $s) * 1000);
    $_perfBeforePage = round(($_pageTimings['before_page'] - $s) * 1000);
    $_perfPage = round(($_pageTimings['after_page'] - $_pageTimings['before_page']) * 1000);
    $_perfTotal = round(($_pageTimings['end'] - $s) * 1000);
    // Always in HTML comment
    echo "\n<!-- PERF: config={$_perfConfig}ms router={$_perfRouter}ms before_page={$_perfBeforePage}ms page={$_perfPage}ms total={$_perfTotal}ms -->\n";
    // Visible bar with ?perf=1
    if (isset($_GET['perf'])) {
        $_perfColor = $_perfTotal < 500 ? '#10b981' : ($_perfTotal < 1500 ? '#fbbf24' : '#ef4444');
        echo '<div style="position:fixed;bottom:0;left:0;right:0;z-index:999999;background:#111;color:#fff;font:12px monospace;padding:6px 12px;display:flex;gap:12px;justify-content:center;">';
        echo "<span style='color:{$_perfColor};font-weight:bold;'>PHP {$_perfTotal}ms</span>";
        echo "<span>config {$_perfConfig}ms</span>";
        echo "<span>layout " . ($_perfBeforePage - $_perfRouter) . "ms</span>";
        echo "<span style='color:{$_perfColor};'>page {$_perfPage}ms</span>";
        echo '</div>';
    }
    ?>

    <!-- Floating Feedback Button - only on welcome/front page -->
    <?php
    $currentPage = $pageInfo['page'] ?? '';
    if ($currentPage === 'welcome'):
    ?>
    <a href="/feedback" class="feedback-fab" title="Rapportera problem" aria-label="Rapportera problem">
        <i data-lucide="bug"></i>
        <span class="feedback-fab-text">Rapportera</span>
    </a>
    <style>
    .feedback-fab {
        position: fixed;
        bottom: 90px;
        right: var(--space-md);
        height: 42px;
        padding: 0 var(--space-md) 0 var(--space-sm);
        border-radius: var(--radius-full);
        background: var(--color-accent);
        color: var(--color-bg-page);
        display: flex;
        align-items: center;
        gap: var(--space-2xs);
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
        z-index: 900;
        transition: transform 0.15s ease, box-shadow 0.15s ease;
        text-decoration: none;
        font-size: 0.8125rem;
        font-weight: 600;
        letter-spacing: 0.02em;
    }
    .feedback-fab:hover {
        transform: scale(1.05);
        box-shadow: 0 6px 16px rgba(0, 0, 0, 0.4);
    }
    .feedback-fab i {
        width: 18px;
        height: 18px;
    }
    @media (max-width: 1023px) {
        .feedback-fab {
            bottom: calc(70px + env(safe-area-inset-bottom, 0px));
            right: var(--space-sm);
            height: 38px;
            padding: 0 var(--space-sm) 0 var(--space-xs);
            font-size: 0.75rem;
        }
        .feedback-fab i {
            width: 16px;
            height: 16px;
        }
    }
    </style>
    <?php endif; ?>

    <!-- Toast Container -->
    <div id="toast-container" class="toast-container"></div>

    <script src="<?= hub_asset('js/theme.js') ?>"></script>
    <script src="<?= hub_asset('js/router.js') ?>"></script>
    <script src="<?= hub_asset('js/app.js') ?>"></script>
    <script src="<?= hub_asset('js/search.js') ?>"></script>
    <script src="<?= hub_asset('js/registration.js') ?>"></script>
    <script src="<?= hub_asset('js/woocommerce.js') ?>"></script>
    <script src="<?= hub_asset('js/badge-system.js') ?>"></script>
    <script src="<?= hub_asset('js/pwa.js') ?>"></script>

    <!-- FOUC Prevention: Reveal content after CSS loaded -->
    <script>
        (function() {
            var main = document.querySelector('.main-content');
            if (main) {
                requestAnimationFrame(function() {
                    requestAnimationFrame(function() {
                        main.classList.add('css-ready');
                    });
                });
            }
        })();
    </script>

    <!-- Initialize Lucide icons (works with defer) -->
    <script>
        function _initLucideIcons() {
            if (typeof lucide !== 'undefined') lucide.createIcons();
        }
        // Initialize after deferred scripts have loaded
        document.addEventListener('DOMContentLoaded', _initLucideIcons);
        // Re-initialize icons after AJAX page loads
        document.addEventListener('hub:contentloaded', _initLucideIcons);
    </script>
</body>
</html>

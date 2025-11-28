<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/router.php';

$pageInfo = hub_get_current_page();
$theme = hub_get_theme();

// AJAX request = return only content
if (hub_is_ajax()) {
    header('Content-Type: text/html; charset=utf-8');
    header('X-Page-Title: ' . ucfirst($pageInfo['page']) . ' – TheHUB');

    include __DIR__ . '/components/breadcrumb.php';

    if (file_exists($pageInfo['file'])) {
        include $pageInfo['file'];
    } else {
        include HUB_V3_ROOT . '/pages/404.php';
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="sv" data-theme="<?= htmlspecialchars($theme) ?>">
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
                if (file_exists($pageInfo['file'])) {
                    include $pageInfo['file'];
                } else {
                    include HUB_V3_ROOT . '/pages/404.php';
                }
                ?>
            </div>
        </main>
    </div>

    <?php include __DIR__ . '/components/mobile-nav.php'; ?>
    <?php include __DIR__ . '/components/footer.php'; ?>
    <?php include __DIR__ . '/components/woocommerce-modal.php'; ?>

    <!-- Toast Container -->
    <div id="toast-container" class="toast-container"></div>

    <script src="<?= hub_asset('js/theme.js') ?>"></script>
    <script src="<?= hub_asset('js/router.js') ?>"></script>
    <script src="<?= hub_asset('js/app.js') ?>"></script>
    <script src="<?= hub_asset('js/search.js') ?>"></script>
    <script src="<?= hub_asset('js/registration.js') ?>"></script>
    <script src="<?= hub_asset('js/woocommerce.js') ?>"></script>
    <script src="<?= hub_asset('js/pwa.js') ?>"></script>
</body>
</html>

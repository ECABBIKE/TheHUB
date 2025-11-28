<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/router.php';

$pageInfo = hub_get_current_page();
$theme = hub_get_theme();

// DEBUG - remove after testing
if (isset($_GET['debug'])) {
    echo '<pre style="background:#111;color:#0f0;padding:20px;margin:20px;">';
    echo "REQUEST: " . ($_GET['page'] ?? 'empty') . "\n";
    echo "PAGE INFO:\n";
    print_r($pageInfo);
    echo "FILE EXISTS: " . (file_exists($pageInfo['file']) ? 'YES' : 'NO') . "\n";
    echo '</pre>';
}

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

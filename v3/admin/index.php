<?php
/**
 * TheHUB V3.5 - Admin Panel
 */
session_start();
require_once dirname(__DIR__) . '/config.php';

// Check auth
if (!hub_is_logged_in() || !hub_is_admin()) {
    header('Location: ' . HUB_V3_URL . '/login?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$currentUser = hub_current_user();
require_once __DIR__ . '/router.php';

$route = admin_parse_route();
?>
<!DOCTYPE html>
<html lang="sv" data-theme="<?= hub_get_theme() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - TheHUB</title>

    <link rel="stylesheet" href="<?= hub_asset('css/reset.css') ?>">
    <link rel="stylesheet" href="<?= hub_asset('css/tokens.css') ?>">
    <link rel="stylesheet" href="<?= hub_asset('css/theme.css') ?>">
    <link rel="stylesheet" href="<?= HUB_V3_URL ?>/admin/assets/css/admin.css">
</head>
<body class="admin-body">

    <?php include __DIR__ . '/components/sidebar.php'; ?>

    <div class="admin-main">
        <?php include __DIR__ . '/components/header.php'; ?>

        <main class="admin-content">
            <?php
            if (file_exists($route['file'])) {
                include $route['file'];
            } else {
                echo '<div class="admin-card"><p>Sidan hittades inte: ' . htmlspecialchars($route['file']) . '</p></div>';
            }
            ?>
        </main>
    </div>

    <script>
    // Mobile menu toggle
    document.querySelector('.admin-menu-toggle')?.addEventListener('click', function() {
        document.querySelector('.admin-sidebar').classList.toggle('is-open');
    });
    </script>
</body>
</html>

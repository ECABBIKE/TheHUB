<?php
/**
 * Organizer App - Header
 * Enkel header för iPad-optimerad app
 */

if (!defined('THEHUB_INIT')) {
    die('Direct access not allowed');
}
?>
<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="mobile-web-app-capable" content="yes">
    <title><?= htmlspecialchars($pageTitle ?? 'Platsregistrering') ?> - <?= ORGANIZER_APP_NAME ?></title>

    <!-- Favicon -->
    <link rel="icon" type="image/png" href="<?= SITE_URL ?>/assets/images/favicon.png">
    <link rel="apple-touch-icon" href="<?= SITE_URL ?>/assets/images/apple-touch-icon.png">

    <!-- CSS -->
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/base.css">
    <link rel="stylesheet" href="<?= ORGANIZER_BASE_URL ?>/assets/css/organizer.css">

    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>
</head>
<body>
<div class="org-app">
<?php if (isset($showHeader) && $showHeader): ?>
    <header class="org-header">
        <div>
            <h1 class="org-header__title"><?= htmlspecialchars($headerTitle ?? $pageTitle ?? ORGANIZER_APP_NAME) ?></h1>
            <?php if (isset($headerSubtitle)): ?>
                <div class="org-header__subtitle"><?= htmlspecialchars($headerSubtitle) ?></div>
            <?php endif; ?>
        </div>
        <div class="org-header__actions">
            <?php if (isset($showBackButton) && $showBackButton): ?>
                <a href="<?= htmlspecialchars($backUrl ?? 'dashboard.php') ?>" class="org-btn org-btn--ghost org-btn--icon">
                    <i data-lucide="arrow-left"></i>
                </a>
            <?php endif; ?>
            <?php if (isset($showLogout) && $showLogout): ?>
                <a href="logout.php" class="org-btn org-btn--ghost org-btn--icon" title="Logga ut">
                    <i data-lucide="log-out"></i>
                </a>
            <?php endif; ?>
        </div>
    </header>
<?php endif; ?>
    <main class="org-main">

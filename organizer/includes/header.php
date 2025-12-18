<?php
/**
 * Organizer App - Header
 * PWA-optimerad för mobil och iPad
 */

if (!defined('THEHUB_INIT')) {
    die('Direct access not allowed');
}
?>
<!DOCTYPE html>
<html lang="sv" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Registrering">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="theme-color" content="#171717">
    <meta name="format-detection" content="telephone=no">
    <title><?= htmlspecialchars($pageTitle ?? 'Platsregistrering') ?> - <?= ORGANIZER_APP_NAME ?></title>

    <!-- PWA Manifest -->
    <link rel="manifest" href="<?= ORGANIZER_BASE_URL ?>/manifest.json">

    <!-- Icons -->
    <link rel="icon" type="image/svg+xml" href="<?= ORGANIZER_BASE_URL ?>/assets/icons/icon.svg">
    <link rel="apple-touch-icon" href="<?= ORGANIZER_BASE_URL ?>/assets/icons/icon.svg">

    <!-- Global CSS -->
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/reset.css">
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/tokens.css">
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/theme.css">
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/components.css">
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/pwa.css">
    <!-- App-specific overrides -->
    <link rel="stylesheet" href="<?= ORGANIZER_BASE_URL ?>/assets/css/organizer.css">

    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>

    <!-- PWA Service Worker -->
    <script>
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => {
            navigator.serviceWorker.register('<?= ORGANIZER_BASE_URL ?>/sw.js')
                .then(reg => console.log('SW registered'))
                .catch(err => console.log('SW failed:', err));
        });
    }
    </script>
</head>
<body>
<div class="org-app">
<?php if (isset($showHeader) && $showHeader): ?>
    <header class="org-header">
        <div class="org-header__left">
            <?php if (isset($showBackButton) && $showBackButton): ?>
                <a href="<?= htmlspecialchars($backUrl ?? 'dashboard.php') ?>" aria-label="Tillbaka">
                    <i data-lucide="arrow-left"></i>
                </a>
            <?php endif; ?>
        </div>
        <div class="org-header__center">
            <h1 class="org-header__title"><?= htmlspecialchars($headerTitle ?? $pageTitle ?? ORGANIZER_APP_NAME) ?></h1>
            <?php if (isset($headerSubtitle)): ?>
                <div class="org-header__subtitle"><?= htmlspecialchars($headerSubtitle) ?></div>
            <?php endif; ?>
        </div>
        <div class="org-header__right">
            <?php if (isset($showLogout) && $showLogout): ?>
                <a href="logout.php" title="Logga ut" aria-label="Logga ut">
                    <i data-lucide="log-out"></i>
                </a>
            <?php endif; ?>
        </div>
    </header>
<?php endif; ?>
    <main class="org-main">

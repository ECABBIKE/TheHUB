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
<html lang="sv">
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

    <!-- CSS -->
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/base.css">
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
                <a href="<?= htmlspecialchars($backUrl ?? 'dashboard.php') ?>" class="org-btn org-btn--ghost org-btn--icon" aria-label="Tillbaka">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m12 19-7-7 7-7"/><path d="M19 12H5"/></svg>
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
                <a href="logout.php" class="org-btn org-btn--ghost org-btn--icon" title="Logga ut" aria-label="Logga ut">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" x2="9" y1="12" y2="12"/></svg>
                </a>
            <?php endif; ?>
        </div>
    </header>
<?php endif; ?>
    <main class="org-main">

<?php
$pageTitle = ucfirst($pageInfo['page'] ?? 'Dashboard') . ' – TheHUB';
$currentTheme = function_exists('hub_get_theme') ? hub_get_theme() : 'dark';
$themeColor = $currentTheme === 'dark' ? '#0A0C14' : '#004A98';
$hubUrl = defined('HUB_V3_URL') ? HUB_V3_URL : '';

// Fallback for hub_asset if not defined
if (!function_exists('hub_asset')) {
    function hub_asset($path) {
        return '/assets/' . ltrim($path, '/');
    }
}
?>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, user-scalable=no">
<meta name="description" content="TheHUB – Sveriges plattform för gravity cycling">

<!-- CRITICAL: FOUC Prevention - must be FIRST -->
<style>
    /* Hide content until CSS is ready */
    .main-content { opacity: 0; }
    .main-content.css-ready { opacity: 1; transition: opacity 0.1s ease-out; }
    /* Fallback: show after 300ms if JS fails */
    @keyframes fouc-fallback { to { opacity: 1; } }
    .main-content { animation: fouc-fallback 0.1s ease-out 0.3s forwards; }
    /* Prevent layout shift */
    html, body { background: #F4F5F7; margin: 0; padding: 0; }
</style>

<!-- PWA Meta Tags -->
<meta name="application-name" content="TheHUB">
<meta name="mobile-web-app-capable" content="yes">
<meta name="theme-color" content="<?= $themeColor ?>" id="theme-color-meta">

<!-- iOS PWA Meta Tags (Apple specific) -->
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="TheHUB">

<!-- iOS Icons -->
<link rel="apple-touch-icon" href="/uploads/icons/GSIkon.png">
<link rel="apple-touch-icon" sizes="180x180" href="/uploads/icons/GSIkon.png">
<link rel="apple-touch-icon" sizes="167x167" href="/uploads/icons/GSIkon.png">

<!-- Web App Manifest -->
<link rel="manifest" href="<?= $hubUrl ?>/manifest.json">

<!-- Favicon -->
<link rel="icon" type="image/png" sizes="32x32" href="/uploads/icons/GSIkon.png">
<link rel="icon" type="image/png" sizes="16x16" href="/uploads/icons/GSIkon.png">
<link rel="icon" type="image/png" href="/uploads/icons/GSIkon.png">

<!-- Preconnect & Google Fonts -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Cabin+Condensed:wght@400;500;600;700&family=Manrope:wght@300;400;500;600;700&family=Oswald:wght@400;500;600;700&family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">

<!-- Lucide Icons -->
<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>

<title><?= htmlspecialchars($pageTitle) ?></title>

<!-- CSS with cache busting -->
<link rel="stylesheet" href="<?= hub_asset('css/reset.css') ?>">
<link rel="stylesheet" href="<?= hub_asset('css/tokens.css') ?>">
<link rel="stylesheet" href="<?= hub_asset('css/theme.css') ?>">
<link rel="stylesheet" href="<?= hub_asset('css/layout.css') ?>">
<link rel="stylesheet" href="<?= hub_asset('css/components.css') ?>">
<link rel="stylesheet" href="<?= hub_asset('css/tables.css') ?>">
<link rel="stylesheet" href="<?= hub_asset('css/utilities.css') ?>">
<link rel="stylesheet" href="<?= hub_asset('css/badge-system.css') ?>">
<link rel="stylesheet" href="<?= hub_asset('css/pwa.css') ?>">

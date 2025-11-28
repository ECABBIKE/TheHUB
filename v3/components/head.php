<?php
$pageTitle = ucfirst($pageInfo['page'] ?? 'Dashboard') . ' – TheHUB';
$themeColor = hub_get_theme() === 'dark' ? '#0A0C14' : '#004A98';
?>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, user-scalable=no">
<meta name="description" content="TheHUB – Sveriges plattform för gravity cycling">

<!-- PWA Meta Tags -->
<meta name="application-name" content="TheHUB">
<meta name="mobile-web-app-capable" content="yes">
<meta name="theme-color" content="<?= $themeColor ?>" id="theme-color-meta">

<!-- iOS PWA Meta Tags (Apple specific) -->
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="TheHUB">

<!-- iOS Icons -->
<link rel="apple-touch-icon" href="<?= HUB_V3_URL ?>/assets/icons/icon-152.png">
<link rel="apple-touch-icon" sizes="180x180" href="<?= HUB_V3_URL ?>/assets/icons/icon-180.png">
<link rel="apple-touch-icon" sizes="167x167" href="<?= HUB_V3_URL ?>/assets/icons/icon-167.png">

<!-- Web App Manifest -->
<link rel="manifest" href="<?= HUB_V3_URL ?>/manifest.json">

<!-- Favicon -->
<link rel="icon" type="image/png" sizes="32x32" href="<?= HUB_V3_URL ?>/assets/icons/favicon-32.png">
<link rel="icon" type="image/png" sizes="16x16" href="<?= HUB_V3_URL ?>/assets/icons/favicon-16.png">
<link rel="icon" type="image/svg+xml" href="<?= HUB_V3_URL ?>/assets/favicon.svg">

<!-- Preconnect -->
<link rel="preconnect" href="https://fonts.googleapis.com">

<title><?= htmlspecialchars($pageTitle) ?></title>

<!-- CSS with cache busting -->
<link rel="stylesheet" href="<?= hub_asset('css/reset.css') ?>">
<link rel="stylesheet" href="<?= hub_asset('css/tokens.css') ?>">
<link rel="stylesheet" href="<?= hub_asset('css/theme.css') ?>">
<link rel="stylesheet" href="<?= hub_asset('css/layout.css') ?>">
<link rel="stylesheet" href="<?= hub_asset('css/components.css') ?>">
<link rel="stylesheet" href="<?= hub_asset('css/tables.css') ?>">
<link rel="stylesheet" href="<?= hub_asset('css/utilities.css') ?>">
<link rel="stylesheet" href="<?= hub_asset('css/pwa.css') ?>">

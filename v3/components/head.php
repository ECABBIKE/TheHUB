<?php $pageTitle = ucfirst($pageInfo['page'] ?? 'Dashboard') . ' – TheHUB V3'; ?>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="description" content="TheHUB – Sveriges plattform för gravity cycling">
<meta name="theme-color" content="#004A98" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#0A0C14" media="(prefers-color-scheme: dark)">
<title><?= htmlspecialchars($pageTitle) ?></title>

<link rel="stylesheet" href="<?= hub_asset('css/reset.css') ?>">
<link rel="stylesheet" href="<?= hub_asset('css/tokens.css') ?>">
<link rel="stylesheet" href="<?= hub_asset('css/theme.css') ?>">
<link rel="stylesheet" href="<?= hub_asset('css/layout.css') ?>">
<link rel="stylesheet" href="<?= hub_asset('css/components.css') ?>">
<link rel="stylesheet" href="<?= hub_asset('css/tables.css') ?>">
<link rel="stylesheet" href="<?= hub_asset('css/utilities.css') ?>">
<link rel="icon" type="image/svg+xml" href="<?= HUB_V3_URL ?>/assets/favicon.svg">

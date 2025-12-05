<?php
/**
 * Admin Unified Layout - Uses same layout as public site
 *
 * Usage:
 *   $page_title = 'Dashboard';
 *   $breadcrumbs = [['label' => 'Admin'], ['label' => 'Dashboard']];
 *   $page_actions = '<a href="..." class="btn btn-primary">Action</a>'; // Optional
 *   include __DIR__ . '/components/unified-layout.php';
 *
 * The content should be placed in $admin_content variable before including this file.
 */

// Ensure we have the V3 config
require_once __DIR__ . '/../../v3-config.php';
require_once __DIR__ . '/../../config.php';

// Ensure admin authentication
require_admin();

// Get theme
$theme = hub_get_theme();

// Create a mock pageInfo for the sidebar
$pageInfo = [
    'page' => 'admin',
    'section' => 'admin',
    'params' => []
];
?>
<!DOCTYPE html>
<html lang="sv" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title ?? 'Admin') ?> - TheHUB Admin</title>

    <!-- V3 CSS -->
    <link rel="stylesheet" href="<?= hub_asset('css/reset.css') ?>">
    <link rel="stylesheet" href="<?= hub_asset('css/tokens.css') ?>">
    <link rel="stylesheet" href="<?= hub_asset('css/theme.css') ?>">
    <link rel="stylesheet" href="<?= hub_asset('css/layout.css') ?>">
    <link rel="stylesheet" href="<?= hub_asset('css/components.css') ?>">
    <link rel="stylesheet" href="<?= hub_asset('css/tables.css') ?>">
    <link rel="stylesheet" href="<?= hub_asset('css/utilities.css') ?>">
    <link rel="stylesheet" href="<?= hub_asset('css/grid.css') ?>">

    <!-- Admin-specific CSS -->
    <link rel="stylesheet" href="/admin/assets/css/admin.css?v=<?= filemtime(__DIR__ . '/../assets/css/admin.css') ?>">
</head>
<body>
    <a href="#main-content" class="skip-link">Hoppa till huvudinnehåll</a>

    <?php include HUB_V3_ROOT . '/components/header.php'; ?>

    <div class="app-layout">
        <?php include HUB_V3_ROOT . '/components/sidebar.php'; ?>

        <main id="main-content" class="main-content" role="main">
            <!-- Admin Submenu (automatic based on current page) -->
            <?php include __DIR__ . '/../../includes/components/admin-submenu.php'; ?>

            <!-- Breadcrumbs -->
            <?php if (isset($breadcrumbs) && !empty($breadcrumbs)): ?>
                <nav class="admin-breadcrumbs">
                    <a href="/admin/dashboard">Admin</a>
                    <?php foreach ($breadcrumbs as $crumb): ?>
                        <span class="separator">/</span>
                        <?php if (isset($crumb['url'])): ?>
                            <a href="<?= htmlspecialchars($crumb['url']) ?>"><?= htmlspecialchars($crumb['label']) ?></a>
                        <?php else: ?>
                            <span class="current"><?= htmlspecialchars($crumb['label']) ?></span>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </nav>
            <?php endif; ?>

            <!-- Page Header -->
            <div class="page-header">
                <h1 class="page-title"><?= htmlspecialchars($page_title ?? 'Admin') ?></h1>
                <?php if (isset($page_actions)): ?>
                    <div class="page-actions">
                        <?= $page_actions ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Flash Messages -->
            <?php if (has_flash('success')): ?>
                <div class="alert alert-success">
                    <?= htmlspecialchars(get_flash('success')) ?>
                </div>
            <?php endif; ?>

            <?php if (has_flash('error')): ?>
                <div class="alert alert-error">
                    <?= htmlspecialchars(get_flash('error')) ?>
                </div>
            <?php endif; ?>

            <?php if (has_flash('warning')): ?>
                <div class="alert alert-warning">
                    <?= htmlspecialchars(get_flash('warning')) ?>
                </div>
            <?php endif; ?>

            <!-- Page Content -->
            <div class="page-content admin-content">

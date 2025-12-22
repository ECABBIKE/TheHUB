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

// Ensure we have the hub config
require_once __DIR__ . '/../../hub-config.php';
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

    <!-- Prevent icon flash: hide data-lucide elements until JS replaces them -->
    <style>
        [data-lucide] {
            display: none !important;
        }
        /* Once lucide replaces them with SVG, they become visible */
        svg.lucide {
            display: inline-block !important;
        }
    </style>
</head>
<body>
    <a href="#main-content" class="skip-link">Hoppa till huvudinnehåll</a>

    <?php include HUB_V3_ROOT . '/components/header.php'; ?>

    <div class="app-layout">
        <?php include HUB_V3_ROOT . '/components/sidebar.php'; ?>

        <main id="main-content" class="main-content" role="main">
            <!-- Admin Submenu (automatic based on current page) -->
            <?php include __DIR__ . '/../../includes/components/admin-submenu.php'; ?>

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

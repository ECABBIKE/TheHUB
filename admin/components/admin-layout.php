<?php
/**
 * Admin Layout Wrapper - V3 Design System
 *
 * Usage:
 *   $page_title = 'Dashboard';
 *   $breadcrumbs = [['label' => 'Admin'], ['label' => 'Dashboard']];
 *   $tabs = [...]; // Optional
 *   $page_actions = '<a href="..." class="btn btn-primary">Action</a>'; // Optional
 *   include __DIR__ . '/components/admin-layout.php';
 */

// Ensure admin authentication
require_admin();

// Get current page for active state
$current_admin_page = basename($_SERVER['PHP_SELF'], '.php');
$current_admin_dir = basename(dirname($_SERVER['PHP_SELF']));

// Get user info
$admin_user = getCurrentAdmin();
$admin_name = $admin_user['name'] ?? 'Admin';

// Theme
$userTheme = $_COOKIE['hub_theme'] ?? 'auto';
?>
<!DOCTYPE html>
<html lang="sv" data-theme="<?= htmlspecialchars($userTheme) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title ?? 'Admin') ?> - TheHUB Admin</title>

    <!-- V3 CSS -->
    <link rel="stylesheet" href="/assets/css/reset.css">
    <link rel="stylesheet" href="/assets/css/tokens.css">
    <link rel="stylesheet" href="/assets/css/theme.css">
    <link rel="stylesheet" href="/assets/css/layout.css">
    <link rel="stylesheet" href="/assets/css/components.css">
    <link rel="stylesheet" href="/assets/css/tables.css">
    <link rel="stylesheet" href="/assets/css/utilities.css">

    <!-- Admin-specific CSS -->
    <link rel="stylesheet" href="/admin/assets/css/admin.css">

    <!-- Theme Script (prevent flash) -->
    <script>
    (function() {
        const saved = localStorage.getItem('thehub-theme');
        let theme = 'light';
        if (saved === 'dark') {
            theme = 'dark';
        } else if (!saved || saved === 'auto') {
            theme = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
        }
        document.documentElement.setAttribute('data-theme', theme);
    })();
    </script>
</head>
<body class="admin-body">
    <!-- Admin Header -->
    <?php include __DIR__ . '/admin-header.php'; ?>

    <!-- Main Layout Grid -->
    <div class="admin-layout">
        <!-- Admin Sidebar -->
        <?php include __DIR__ . '/admin-sidebar.php'; ?>

        <!-- Main Content Area -->
        <main class="admin-main">
            <!-- Breadcrumbs -->
            <?php if (isset($breadcrumbs) && !empty($breadcrumbs)): ?>
                <nav class="admin-breadcrumbs">
                    <a href="/admin/dashboard.php">Admin</a>
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
            <div class="admin-page-header">
                <h1><?= htmlspecialchars($page_title ?? 'Admin') ?></h1>

                <?php if (isset($page_actions)): ?>
                    <div class="page-actions">
                        <?= $page_actions ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Flash Messages -->
            <?php if (has_flash('success')): ?>
                <div class="alert alert-success">
                    <svg class="lucide" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                    <?= htmlspecialchars(get_flash('success')) ?>
                </div>
            <?php endif; ?>

            <?php if (has_flash('error')): ?>
                <div class="alert alert-error">
                    <svg class="lucide" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/></svg>
                    <?= htmlspecialchars(get_flash('error')) ?>
                </div>
            <?php endif; ?>

            <?php if (has_flash('warning')): ?>
                <div class="alert alert-warning">
                    <svg class="lucide" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" x2="12" y1="9" y2="13"/><line x1="12" x2="12.01" y1="17" y2="17"/></svg>
                    <?= htmlspecialchars(get_flash('warning')) ?>
                </div>
            <?php endif; ?>

            <!-- Tabs (for sub-sections) -->
            <?php if (isset($tabs) && !empty($tabs)): ?>
                <div class="admin-tabs">
                    <?php foreach ($tabs as $tab): ?>
                        <a
                            href="<?= htmlspecialchars($tab['url']) ?>"
                            class="admin-tab <?= !empty($tab['active']) ? 'active' : '' ?>"
                        >
                            <?php if (isset($tab['icon'])): ?>
                                <span class="tab-icon"><?= $tab['icon'] ?></span>
                            <?php endif; ?>
                            <span><?= htmlspecialchars($tab['label']) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Page Content -->
            <div class="admin-content">

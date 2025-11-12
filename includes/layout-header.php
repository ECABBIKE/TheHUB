<?php
/**
 * Standardized Layout Header for TheHUB
 *
 * Usage:
 *   $pageTitle = 'Dashboard';
 *   $pageType = 'admin'; // or 'public'
 *   include __DIR__ . '/../includes/layout-header.php';
 *
 * Variables:
 *   - $pageTitle (required): The page title
 *   - $pageType (required): 'admin' or 'public'
 *   - $bodyClass (optional): Additional body classes
 */

// Validate required variables
if (!isset($pageTitle)) {
    $pageTitle = 'TheHUB';
}

if (!isset($pageType)) {
    $pageType = 'public';
}

// Determine title suffix and body class
$titleSuffix = ($pageType === 'admin') ? ' - TheHUB Admin' : ' - TheHUB';
$defaultBodyClass = ($pageType === 'admin') ? 'gs-admin-page' : 'gs-public-page';
$bodyClass = isset($bodyClass) ? $defaultBodyClass . ' ' . $bodyClass : $defaultBodyClass;
?>
<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($pageTitle) ?><?= $titleSuffix ?></title>
    <link rel="stylesheet" href="/assets/gravityseries-theme.css">
</head>
<body class="<?= $bodyClass ?>">
    <?php if ($pageType === 'admin'): ?>
        <!-- Admin: Mobile Menu Toggle -->
        <button class="gs-mobile-menu-toggle" onclick="toggleMenu()" aria-label="Toggle menu">
            <i data-lucide="menu"></i>
            <span>Meny</span>
        </button>

        <!-- Mobile Overlay -->
        <div class="gs-sidebar-overlay" onclick="closeMenu()"></div>

        <!-- Navigation -->
        <?php include __DIR__ . '/navigation.php'; ?>
    <?php else: ?>
        <!-- Public: Mobile Menu Toggle -->
        <button class="gs-mobile-menu-toggle" onclick="toggleMenu()" aria-label="Toggle menu">
            <i data-lucide="menu"></i>
        </button>

        <!-- Navigation -->
        <?php include __DIR__ . '/navigation.php'; ?>

        <!-- Mobile Overlay -->
        <div class="gs-sidebar-overlay" onclick="closeMenu()"></div>
    <?php endif; ?>

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

// ============================================================================
// SECURITY HEADERS
// ============================================================================
// Prevent page from being displayed in an iframe (clickjacking protection)
header("X-Frame-Options: DENY");

// Prevent MIME type sniffing
header("X-Content-Type-Options: nosniff");

// Control referrer information
header("Referrer-Policy: no-referrer-when-downgrade");

// Permissions policy (disable unnecessary features)
header("Permissions-Policy: geolocation=(), microphone=(), camera=()");

// XSS Protection (for older browsers)
header("X-XSS-Protection: 1; mode=block");

// Strict Transport Security (HSTS) - only when using HTTPS
if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
    header("Strict-Transport-Security: max-age=31536000; includeSubDomains");
}

// Content Security Policy (allow self + unpkg.com for Lucide icons)
$csp = implode('; ', [
    "default-src 'self'",
    "script-src 'self' 'unsafe-inline' https://unpkg.com",
    "style-src 'self' 'unsafe-inline'",
    "img-src 'self' data: https:",
    "font-src 'self' data:",
    "connect-src 'self'",
    "frame-ancestors 'none'"
]);
header("Content-Security-Policy: {$csp}");

// ============================================================================
// PAGE SETUP
// ============================================================================
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

    <!-- CRITICAL CSS - INLINE to ensure it loads FIRST -->
    <style id="critical-sidebar-css">
        /* Force sidebar permanent on desktop - INLINE to bypass loading issues */
        @media (min-width: 1024px) {
            /* Hide hamburger completely on desktop */
            .gs-mobile-menu-toggle {
                display: none !important;
                visibility: hidden !important;
                opacity: 0 !important;
                pointer-events: none !important;
            }

            /* Show sidebar permanently */
            .gs-sidebar {
                display: flex !important;
                position: fixed !important;
                left: 0 !important;
                top: 0 !important;
                width: 280px !important;
                height: 100vh !important;
                transform: translateX(0) !important;
                transition: none !important;
                z-index: 100 !important;
                background: #FFFFFF !important;
                border-right: 1px solid #E5E7EB !important;
            }

            /* Hide overlay completely on desktop */
            .gs-sidebar-overlay {
                display: none !important;
                visibility: hidden !important;
                opacity: 0 !important;
                pointer-events: none !important;
            }

            /* Offset content for sidebar */
            .gs-main-content,
            .gs-content-with-sidebar {
                margin-left: 280px !important;
                width: calc(100% - 280px) !important;
            }

            /* Prevent body scroll lock */
            body {
                overflow: auto !important;
            }
        }

        /* Mobile behavior unchanged */
        @media (max-width: 1023px) {
            .gs-sidebar {
                transform: translateX(-100%);
                z-index: 1100;
            }

            .gs-sidebar.open {
                transform: translateX(0);
            }

            .gs-mobile-menu-toggle {
                display: flex !important;
            }
        }
    </style>

    <!-- GravitySeries v4.0 CSS -->
    <link rel="stylesheet" href="/public/css/gravityseries-main.css?v=<?= filemtime(__DIR__ . '/../public/css/gravityseries-main.css') ?>">
    <?php if (isset($pageType) && $pageType === 'admin'): ?>
    <link rel="stylesheet" href="/public/css/gravityseries-admin.css?v=<?= filemtime(__DIR__ . '/../public/css/gravityseries-admin.css') ?>">
    <?php endif; ?>
</head>
<body class="<?= $bodyClass ?>">
    <!-- Hamburger (hidden on desktop via inline CSS) -->
    <button class="gs-mobile-menu-toggle" onclick="toggleMenu()" aria-label="Toggle menu">
        <i data-lucide="menu"></i>
    </button>

    <!-- Navigation -->
    <?php include __DIR__ . '/navigation.php'; ?>

    <!-- Overlay (hidden on desktop via inline CSS) -->
    <div class="gs-sidebar-overlay" onclick="closeMenu()"></div>

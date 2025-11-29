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
$defaultBodyClass = ($pageType === 'admin') ? 'admin-page' : 'public-page';
$bodyClass = isset($bodyClass) ? $defaultBodyClass . ' ' . $bodyClass : $defaultBodyClass;
?>
<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?= h($pageTitle) ?><?= $titleSuffix ?></title>

    <!-- CRITICAL CSS - INLINE to ensure it loads FIRST -->
    <style id="critical-sidebar-css">
        /* Force sidebar permanent on desktop - INLINE to bypass loading issues */
        @media (min-width: 1024px) {
            /* Hide hamburger completely on desktop */
            .mobile-menu-toggle {
                display: none !important;
                visibility: hidden !important;
                opacity: 0 !important;
                pointer-events: none !important;
            }

            /* Show sidebar permanently */
            .sidebar {
                display: flex !important;
                position: fixed !important;
                left: 0 !important;
                top: 0 !important;
                width: 72px !important;
                height: 100vh !important;
                transform: translateX(0) !important;
                transition: none !important;
                z-index: 100 !important;
                background: #FFFFFF !important;
                border-right: 1px solid #E5E7EB !important;
            }

            /* Hide overlay completely on desktop */
            .sidebar-overlay {
                display: none !important;
                visibility: hidden !important;
                opacity: 0 !important;
                pointer-events: none !important;
            }

            /* Offset content for sidebar */
            .main-content {
                margin-left: 72px !important;
                width: calc(100% - 72px) !important;
            }

            /* Prevent body scroll lock */
            body {
                overflow: auto !important;
            }
        }

        /* Mobile behavior unchanged */
        @media (max-width: 1023px) {
            .sidebar {
                transform: translateX(-100%);
                z-index: 1100;
            }

            .sidebar.open {
                transform: translateX(0);
            }

            .mobile-menu-toggle {
                display: flex !important;
            }
        }
    </style>

    <!-- V3.5 Design System - Complete Migration (FAS 1) -->
    <!-- 1. CSS Reset -->
    <link rel="stylesheet" href="/assets/css/reset.css?v=<?= filemtime(__DIR__ . '/../assets/css/reset.css') ?>">

    <!-- 2. Design Tokens -->
    <link rel="stylesheet" href="/assets/css/tokens.css?v=<?= filemtime(__DIR__ . '/../assets/css/tokens.css') ?>">

    <!-- 3. Theme Variables (Light/Dark/Auto Support) -->
    <link rel="stylesheet" href="/assets/css/theme.css?v=<?= filemtime(__DIR__ . '/../assets/css/theme.css') ?>">

    <!-- 4. Layout System -->
    <link rel="stylesheet" href="/assets/css/layout.css?v=<?= filemtime(__DIR__ . '/../assets/css/layout.css') ?>">

    <!-- 5. UI Components -->
    <link rel="stylesheet" href="/assets/css/components.css?v=<?= filemtime(__DIR__ . '/../assets/css/components.css') ?>">

    <!-- 6. Table Styles -->
    <link rel="stylesheet" href="/assets/css/tables.css?v=<?= filemtime(__DIR__ . '/../assets/css/tables.css') ?>">

    <!-- 7. Utility Classes -->
    <link rel="stylesheet" href="/assets/css/utilities.css?v=<?= filemtime(__DIR__ . '/../assets/css/utilities.css') ?>">

    <!-- 8. PWA Support -->
    <link rel="stylesheet" href="/assets/css/pwa.css?v=<?= filemtime(__DIR__ . '/../assets/css/pwa.css') ?>">

    <!-- 9. GS Compatibility Layer (maps old gs-* classes to V3.5) -->
    <link rel="stylesheet" href="/assets/css/compatibility.css?v=<?= filemtime(__DIR__ . '/../assets/css/compatibility.css') ?>">


    <!-- PWA Support -->
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#2563EB">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="TheHUB">
    <link rel="apple-touch-icon" href="/assets/icons/icon-192.png">

    <!-- Theme Prevention Script (prevents flash of wrong theme) -->
    <?php
    // Ladda tema från profil för inloggade användare
    $userTheme = 'auto';
    $isLoggedIn = false;
    if (isset($_SESSION['rider_id']) && $_SESSION['rider_id'] > 0) {
        $isLoggedIn = true;
        try {
            if (function_exists('get_current_rider')) {
                $currentUser = get_current_rider();
                if (isset($currentUser['theme_preference'])) {
                    $userTheme = $currentUser['theme_preference'];
                }
            }
        } catch (Exception $e) {
            // Ignorera fel, använd localStorage istället
        }
    }
    ?>
    <script>
    // HUB global object
    window.HUB = window.HUB || {};
    window.HUB.isLoggedIn = <?= $isLoggedIn ? 'true' : 'false' ?>;
    <?php if ($isLoggedIn): ?>
    window.HUB.userTheme = '<?= htmlspecialchars($userTheme) ?>';

    // Synka med localStorage om server har annan preferens
    if (window.HUB.userTheme !== localStorage.getItem('thehub-theme')) {
        localStorage.setItem('thehub-theme', window.HUB.userTheme);
    }
    <?php endif; ?>

    // Förhindra flash of wrong theme
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
<body class="<?= $bodyClass ?>">
    <!-- Hamburger (hidden on desktop via inline CSS) -->
    <button class="mobile-menu-toggle" onclick="toggleMenu()" aria-label="Toggle menu">
        <i data-lucide="menu"></i>
    </button>

    <!-- Navigation -->
    <?php include __DIR__ . '/navigation-v3.php'; ?>

    <!-- Overlay (hidden on desktop via inline CSS) -->
    <div class="sidebar-overlay" onclick="closeMenu()"></div>

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

// ============================================================================
// AUTOMATIC PAGE TYPE DETECTION
// ============================================================================
// Detect event pages automatically based on URL pattern /event/{ID}
$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$isEventPage = preg_match('#^/event/\d+#', $requestUri) === 1;
$isAdminPage = strpos($requestUri, '/admin') === 0;

// Build body classes array
$bodyClasses = [];

// Page type class
if ($pageType === 'admin' || $isAdminPage) {
    $bodyClasses[] = 'admin-page';
    $bodyClasses[] = 'is-admin';
} else {
    $bodyClasses[] = 'public-page';
    $bodyClasses[] = 'is-public';
}

// Event page isolation - automatic detection (NO manual override allowed)
if ($isEventPage) {
    $bodyClasses[] = 'event-page';
}

// Add any custom body classes
if (isset($bodyClass) && !empty($bodyClass)) {
    $bodyClasses[] = $bodyClass;
}

// Determine title suffix and final body class string
$titleSuffix = ($pageType === 'admin') ? ' - TheHUB Admin' : ' - TheHUB';
$bodyClass = implode(' ', array_unique($bodyClasses));

// Get theme from user profile or default to dark
$userTheme = 'dark';
$isLoggedIn = isset($_SESSION['rider_id']) && $_SESSION['rider_id'] > 0;
if ($isLoggedIn && function_exists('get_current_rider')) {
    try {
        $currentUser = get_current_rider();
        if (isset($currentUser['theme_preference'])) {
            $userTheme = $currentUser['theme_preference'];
        }
    } catch (Exception $e) {
        // Use default
    }
}
// Resolve 'auto' to dark on server side
if ($userTheme === 'auto') {
    $userTheme = 'dark';
}
?>
<!DOCTYPE html>
<html lang="sv" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?= h($pageTitle) ?><?= $titleSuffix ?></title>

    <!-- Force light theme always - no switcher to prevent flash -->
    <script>
    (function() {
        // Always use light theme
        document.documentElement.setAttribute('data-theme', 'light');
    })();
    window.HUB = window.HUB || {};
    window.HUB.isLoggedIn = <?= $isLoggedIn ? 'true' : 'false' ?>;
    </script>

    <!-- CRITICAL: Inline CSS for light theme and FOUC prevention -->
    <style>
        html, body {
            background: #F4F5F7;
            color: #1A1A1A;
            margin: 0;
            padding: 0;
        }
        /* FOUC Prevention: Hide main content until CSS is loaded */
        .main-content {
            opacity: 0;
            transition: opacity 0.1s ease-out;
        }
        .main-content.css-ready {
            opacity: 1;
        }
        /* Fallback: Show content after 500ms even if JS fails */
        @keyframes fouc-fallback {
            to { opacity: 1; }
        }
        .main-content {
            animation: fouc-fallback 0.1s ease-out 0.5s forwards;
        }
    </style>

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

        /* Mobile menu toggle button styling */
        .mobile-menu-toggle {
            position: fixed;
            top: 12px;
            left: 12px;
            z-index: 1200;
            width: 44px;
            height: 44px;
            display: none;
            align-items: center;
            justify-content: center;
            background: var(--color-bg-surface, #fff);
            border: 1px solid var(--color-border, #e5e7eb);
            border-radius: 8px;
            cursor: pointer;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .mobile-menu-toggle svg {
            width: 24px;
            height: 24px;
            stroke: currentColor;
        }

        /* Sidebar overlay */
        .sidebar-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.5);
            z-index: 1050;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.3s ease;
        }
        .sidebar-overlay.active {
            opacity: 1;
            visibility: visible;
        }

        /* Mobile PORTRAIT behavior - bottom nav, hidden sidebar */
        @media (max-width: 1023px) and (orientation: portrait) {
            .sidebar {
                position: fixed !important;
                left: 0 !important;
                top: 0 !important;
                width: 260px !important;
                height: 100vh !important;
                height: 100dvh !important;
                background: var(--color-bg-surface, #fff) !important;
                transform: translateX(-100%) !important;
                transition: transform 0.3s ease !important;
                z-index: 1100 !important;
                padding-top: 70px !important;
                display: flex !important;
                flex-direction: column !important;
            }

            .sidebar.open {
                transform: translateX(0) !important;
            }

            .mobile-menu-toggle {
                display: flex !important;
            }

            /* Ensure main content has no sidebar offset on mobile portrait */
            .main-content {
                margin-left: 0 !important;
                width: 100% !important;
            }

            /* Bottom nav visible on portrait */
            .nav-bottom, .mobile-nav {
                display: flex !important;
            }
        }

        /* Mobile LANDSCAPE behavior - icon-only sidebar (like bottom nav), no bottom nav */
        @media (max-width: 1023px) and (orientation: landscape) {
            .sidebar {
                position: fixed !important;
                left: 0 !important;
                top: var(--header-height, 60px) !important;
                width: 56px !important;
                height: calc(100vh - var(--header-height, 60px)) !important;
                height: calc(100dvh - var(--header-height, 60px)) !important;
                background: var(--color-bg-surface, #fff) !important;
                transform: translateX(0) !important;
                z-index: 200 !important;
                padding: 8px 0 !important;
                display: flex !important;
                flex-direction: column !important;
                border-right: 1px solid var(--color-border, #e5e7eb) !important;
                align-items: center !important;
            }

            /* Icon-only sidebar links - hide text labels */
            .sidebar .sidebar-link span:not(.sidebar-icon),
            .sidebar .sidebar-label,
            .sidebar .nav-text {
                display: none !important;
            }

            .sidebar .sidebar-link {
                padding: 12px !important;
                min-width: 44px !important;
            }

            .mobile-menu-toggle {
                display: none !important;
            }

            /* Main content offset for sidebar on landscape */
            .main-content {
                margin-left: 56px !important;
                width: calc(100% - 56px) !important;
            }

            /* Hide bottom nav on landscape - more vertical space */
            .nav-bottom, .mobile-nav {
                display: none !important;
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

    <!-- 8. Grid System -->
    <link rel="stylesheet" href="/assets/css/grid.css?v=<?= filemtime(__DIR__ . '/../assets/css/grid.css') ?>">

    <!-- 9. PWA Support -->
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

</head>
<body class="<?= $bodyClass ?>">
    <!-- Hamburger (hidden on desktop via inline CSS) -->
    <button class="mobile-menu-toggle" onclick="toggleMenu()" aria-label="Toggle menu">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <line x1="4" x2="20" y1="12" y2="12"/><line x1="4" x2="20" y1="6" y2="6"/><line x1="4" x2="20" y1="18" y2="18"/>
        </svg>
    </button>

    <!-- Navigation -->
    <?php include __DIR__ . '/navigation-v3.php'; ?>

    <!-- Overlay (hidden on desktop via inline CSS) -->
    <div class="sidebar-overlay" onclick="closeMenu()"></div>

    <!-- Admin Submenu (automatic based on current page) -->
    <?php
    if ($pageType === 'admin') {
        include __DIR__ . '/components/admin-submenu.php';
    }
    ?>

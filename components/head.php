<?php
/**
 * TheHUB Head Component
 * Contains all <head> content for the SPA
 */

// Get theme preference
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
        // Ignore errors
    }
}

$pageTitle = $pageTitle ?? 'TheHUB';
?>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title><?= h($pageTitle) ?> - TheHUB</title>

<!-- CRITICAL CSS - INLINE to ensure it loads FIRST -->
<style id="critical-sidebar-css">
    @media (min-width: 1024px) {
        .mobile-menu-toggle {
            display: none !important;
            visibility: hidden !important;
        }
        .sidebar {
            display: flex !important;
            position: fixed !important;
            left: 0 !important;
            top: 0 !important;
            width: 72px !important;
            height: 100vh !important;
            transform: translateX(0) !important;
            z-index: 100 !important;
            background: var(--color-bg-surface) !important;
            border-right: 1px solid var(--color-border) !important;
        }
        .sidebar-overlay {
            display: none !important;
        }
        .main-content {
            margin-left: 72px !important;
            width: calc(100% - 72px) !important;
        }
    }
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
    /* SPA Loading State */
    #page-content.loading {
        opacity: 0.5;
        pointer-events: none;
        transition: opacity 0.15s ease;
    }
    .skip-link {
        position: absolute;
        left: -9999px;
        top: 0;
        z-index: 10000;
        padding: 0.5rem 1rem;
        background: var(--color-accent);
        color: var(--color-text-inverse);
    }
    .skip-link:focus {
        left: 0;
    }
</style>

<!-- V3.5 Design System CSS -->
<link rel="stylesheet" href="/assets/css/reset.css?v=<?= filemtime(__DIR__ . '/../assets/css/reset.css') ?>">
<link rel="stylesheet" href="/assets/css/tokens.css?v=<?= filemtime(__DIR__ . '/../assets/css/tokens.css') ?>">
<link rel="stylesheet" href="/assets/css/theme.css?v=<?= filemtime(__DIR__ . '/../assets/css/theme.css') ?>">
<link rel="stylesheet" href="/assets/css/layout.css?v=<?= filemtime(__DIR__ . '/../assets/css/layout.css') ?>">
<link rel="stylesheet" href="/assets/css/components.css?v=<?= filemtime(__DIR__ . '/../assets/css/components.css') ?>">
<link rel="stylesheet" href="/assets/css/tables.css?v=<?= filemtime(__DIR__ . '/../assets/css/tables.css') ?>">
<link rel="stylesheet" href="/assets/css/utilities.css?v=<?= filemtime(__DIR__ . '/../assets/css/utilities.css') ?>">
<link rel="stylesheet" href="/assets/css/pwa.css?v=<?= filemtime(__DIR__ . '/../assets/css/pwa.css') ?>">
<link rel="stylesheet" href="/assets/css/compatibility.css?v=<?= filemtime(__DIR__ . '/../assets/css/compatibility.css') ?>">

<!-- PWA Support -->
<link rel="manifest" href="/manifest.json">
<meta name="theme-color" content="#2563EB">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="TheHUB">
<link rel="apple-touch-icon" href="/assets/icons/icon-192.png">

<!-- Theme Prevention Script -->
<script>
window.HUB = window.HUB || {};
window.HUB.isLoggedIn = <?= $isLoggedIn ? 'true' : 'false' ?>;
<?php if ($isLoggedIn): ?>
window.HUB.userTheme = '<?= htmlspecialchars($userTheme) ?>';
if (window.HUB.userTheme !== localStorage.getItem('thehub-theme')) {
    localStorage.setItem('thehub-theme', window.HUB.userTheme);
}
<?php endif; ?>

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

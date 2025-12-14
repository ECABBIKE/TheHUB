<?php
$pageTitle = ucfirst($pageInfo['page'] ?? 'Dashboard') . ' – TheHUB';
$currentTheme = function_exists('hub_get_theme') ? hub_get_theme() : 'dark';
$themeColor = $currentTheme === 'dark' ? '#0A0C14' : '#004A98';
$hubUrl = defined('HUB_V3_URL') ? HUB_V3_URL : '';

// Fallback for hub_asset if not defined
if (!function_exists('hub_asset')) {
    function hub_asset($path) {
        return '/assets/' . ltrim($path, '/');
    }
}
?>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, user-scalable=no">
<meta name="description" content="TheHUB – Sveriges plattform för gravity cycling">

<!-- CRITICAL: FOUC Prevention - must be FIRST -->
<style>
    /* Hide content until CSS is ready */
    .main-content { opacity: 0; }
    .main-content.css-ready { opacity: 1; transition: opacity 0.1s ease-out; }
    /* Fallback: show after 300ms if JS fails */
    @keyframes fouc-fallback { to { opacity: 1; } }
    .main-content { animation: fouc-fallback 0.1s ease-out 0.3s forwards; }
    /* Prevent layout shift */
    html, body { background: #F4F5F7; margin: 0; padding: 0; }
</style>

<!-- PWA Meta Tags -->
<meta name="application-name" content="TheHUB">
<meta name="mobile-web-app-capable" content="yes">
<meta name="theme-color" content="<?= $themeColor ?>" id="theme-color-meta">

<!-- iOS PWA Meta Tags (Apple specific) -->
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="TheHUB">

<!-- iOS Icons -->
<link rel="apple-touch-icon" href="/uploads/icons/GSIkon.png">
<link rel="apple-touch-icon" sizes="180x180" href="/uploads/icons/GSIkon.png">
<link rel="apple-touch-icon" sizes="167x167" href="/uploads/icons/GSIkon.png">

<!-- Web App Manifest -->
<link rel="manifest" href="<?= $hubUrl ?>/manifest.json">

<!-- Favicon -->
<link rel="icon" type="image/png" sizes="32x32" href="/uploads/icons/GSIkon.png">
<link rel="icon" type="image/png" sizes="16x16" href="/uploads/icons/GSIkon.png">
<link rel="icon" type="image/png" href="/uploads/icons/GSIkon.png">

<!-- Preconnect & Google Fonts -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Cabin+Condensed:wght@400;500;600;700&family=Manrope:wght@300;400;500;600;700&family=Oswald:wght@400;500;600;700&family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">

<!-- Lucide Icons - pinned to specific version for stability -->
<script src="https://unpkg.com/lucide@0.460.0/dist/umd/lucide.min.js"></script>

<title><?= htmlspecialchars($pageTitle) ?></title>

<!-- CSS with cache busting (filemtime) -->
<?php
$cssDir = __DIR__ . '/../assets/css/';
$cssVersion = function($file) use ($cssDir) {
    $path = $cssDir . $file;
    return file_exists($path) ? filemtime($path) : time();
};
?>
<link rel="stylesheet" href="<?= hub_asset('css/reset.css') ?>?v=<?= $cssVersion('reset.css') ?>">
<link rel="stylesheet" href="<?= hub_asset('css/tokens.css') ?>?v=<?= $cssVersion('tokens.css') ?>">
<link rel="stylesheet" href="<?= hub_asset('css/theme.css') ?>?v=<?= $cssVersion('theme.css') ?>">
<link rel="stylesheet" href="<?= hub_asset('css/layout.css') ?>?v=<?= $cssVersion('layout.css') ?>">
<link rel="stylesheet" href="<?= hub_asset('css/components.css') ?>?v=<?= $cssVersion('components.css') ?>">
<link rel="stylesheet" href="<?= hub_asset('css/tables.css') ?>?v=<?= $cssVersion('tables.css') ?>">
<link rel="stylesheet" href="<?= hub_asset('css/utilities.css') ?>?v=<?= $cssVersion('utilities.css') ?>">
<link rel="stylesheet" href="<?= hub_asset('css/badge-system.css') ?>?v=<?= $cssVersion('badge-system.css') ?>">
<link rel="stylesheet" href="<?= hub_asset('css/pwa.css') ?>?v=<?= $cssVersion('pwa.css') ?>">
<link rel="stylesheet" href="<?= hub_asset('css/viewport.css') ?>?v=<?= $cssVersion('viewport.css') ?>">

<!-- Page-Specific CSS (loaded conditionally based on current page) -->
<?php
/**
 * Conditional Page CSS Loader
 *
 * Loads page-specific CSS from /assets/css/pages/ if it exists.
 * This allows gradual migration of inline styles to external files.
 *
 * Naming convention:
 *   - /pages/event.php  -> /assets/css/pages/event.css
 *   - /pages/rider.php  -> /assets/css/pages/rider.css
 *   - /pages/results.php -> /assets/css/pages/results.css
 */
$currentPage = $pageInfo['page'] ?? null;
$pageCssDir = __DIR__ . '/../assets/css/pages/';

if ($currentPage) {
    // Sanitize page name (only allow alphanumeric and hyphens)
    $safePage = preg_replace('/[^a-z0-9\-]/', '', strtolower($currentPage));
    $pageCssFile = $pageCssDir . $safePage . '.css';

    if (file_exists($pageCssFile)) {
        $pageCssVersion = filemtime($pageCssFile);
        echo '<link rel="stylesheet" href="' . hub_asset('css/pages/' . $safePage . '.css') . '?v=' . $pageCssVersion . '">' . "\n";
    }
}
?>

<!-- Dynamic Branding System -->
<?php
/**
 * Load custom colors and responsive settings from admin branding panel
 * File: /uploads/branding.json
 * Admin panel: /admin/branding.php
 */
$brandingFile = __DIR__ . '/../uploads/branding.json';

if (file_exists($brandingFile)) {
    $brandingData = json_decode(file_get_contents($brandingFile), true);

    if (is_array($brandingData)) {
        $cssOutput = '';
        $colorCount = 0;

        // Process custom colors
        if (!empty($brandingData['colors'])) {
            foreach ($brandingData['colors'] as $cssVar => $value) {
                // Security: Only allow CSS custom properties (start with --)
                if (strpos($cssVar, '--') === 0) {
                    // Security: Sanitize value
                    $safeValue = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

                    // Validate it's a reasonable CSS value (hex, rgb, rgba, hsl, etc.)
                    if (preg_match('/^(#[0-9A-Fa-f]{3,8}|rgba?\([^)]+\)|hsla?\([^)]+\)|[a-z]+)$/', $value)) {
                        $cssOutput .= $cssVar . ':' . $safeValue . ';';
                        $colorCount++;
                    }
                }
            }
        }

        // Process responsive layout settings
        $responsive = $brandingData['responsive'] ?? null;
        $responsiveCss = '';

        if ($responsive) {
            // Desktop defaults (1024px+) - set in :root
            $desktopPadding = intval($responsive['desktop']['padding'] ?? 32);
            $desktopRadius = intval($responsive['desktop']['radius'] ?? 12);

            // Desktop spacing tokens (derived from padding)
            $desktopCardPadding = max(16, $desktopPadding - 8);  // Slightly less than container
            $desktopSpaceMd = 16;  // Standard
            $desktopSpaceSm = 12;
            $desktopSpaceLg = 24;

            $cssOutput .= '--container-padding:' . $desktopPadding . 'px;';
            $cssOutput .= '--card-padding:' . $desktopCardPadding . 'px;';
            $cssOutput .= '--content-padding:' . $desktopCardPadding . 'px;';
            $cssOutput .= '--table-cell-padding-y:' . $desktopSpaceSm . 'px;';
            $cssOutput .= '--table-cell-padding-x:' . $desktopSpaceMd . 'px;';
            $cssOutput .= '--radius-sm:' . $desktopRadius . 'px;';
            $cssOutput .= '--radius-md:' . $desktopRadius . 'px;';
            $cssOutput .= '--radius-lg:' . $desktopRadius . 'px;';
            $cssOutput .= '--radius-xl:' . $desktopRadius . 'px;';

            // Tablet / Landscape (768-1023px)
            $tabletPadding = intval($responsive['tablet']['padding'] ?? 24);
            $tabletRadius = intval($responsive['tablet']['radius'] ?? 8);
            $tabletCardPadding = max(12, $tabletPadding - 8);
            $tabletSpaceSm = 10;
            $tabletSpaceMd = 14;

            $responsiveCss .= '@media (min-width:768px) and (max-width:1023px){';
            $responsiveCss .= ':root{';
            $responsiveCss .= '--container-padding:' . $tabletPadding . 'px;';
            $responsiveCss .= '--card-padding:' . $tabletCardPadding . 'px;';
            $responsiveCss .= '--content-padding:' . $tabletCardPadding . 'px;';
            $responsiveCss .= '--table-cell-padding-y:' . $tabletSpaceSm . 'px;';
            $responsiveCss .= '--table-cell-padding-x:' . $tabletSpaceMd . 'px;';
            $responsiveCss .= '--radius-sm:' . $tabletRadius . 'px;';
            $responsiveCss .= '--radius-md:' . $tabletRadius . 'px;';
            $responsiveCss .= '--radius-lg:' . $tabletRadius . 'px;';
            $responsiveCss .= '--radius-xl:' . $tabletRadius . 'px;';
            $responsiveCss .= '}}';

            // Mobile Portrait (0-767px)
            $mobilePadding = intval($responsive['mobile_portrait']['padding'] ?? 12);
            $mobileRadius = intval($responsive['mobile_portrait']['radius'] ?? 0);
            $mobileCardPadding = max(8, $mobilePadding - 4);
            $mobileSpaceSm = 8;
            $mobileSpaceMd = 12;

            $responsiveCss .= '@media (max-width:767px){';
            $responsiveCss .= ':root{';
            $responsiveCss .= '--container-padding:' . $mobilePadding . 'px;';
            $responsiveCss .= '--card-padding:' . $mobileCardPadding . 'px;';
            $responsiveCss .= '--content-padding:' . $mobileCardPadding . 'px;';
            $responsiveCss .= '--table-cell-padding-y:' . $mobileSpaceSm . 'px;';
            $responsiveCss .= '--table-cell-padding-x:' . $mobileSpaceMd . 'px;';
            $responsiveCss .= '--radius-sm:' . $mobileRadius . 'px;';
            $responsiveCss .= '--radius-md:' . $mobileRadius . 'px;';
            $responsiveCss .= '--radius-lg:' . $mobileRadius . 'px;';
            $responsiveCss .= '--radius-xl:' . $mobileRadius . 'px;';
            $responsiveCss .= '}}';
        }

        // Process layout settings (content-max-width, sidebar-width, header-height)
        $layout = $brandingData['layout'] ?? null;
        if ($layout) {
            // Content max width
            $contentMaxWidth = $layout['content_max_width'] ?? '1400';
            if ($contentMaxWidth === 'none') {
                $cssOutput .= '--content-max-width:none;';
            } else {
                $cssOutput .= '--content-max-width:' . intval($contentMaxWidth) . 'px;';
            }

            // Sidebar width
            $sidebarWidth = intval($layout['sidebar_width'] ?? 72);
            $cssOutput .= '--sidebar-width:' . $sidebarWidth . 'px;';

            // Header height
            $headerHeight = intval($layout['header_height'] ?? 60);
            $cssOutput .= '--header-height:' . $headerHeight . 'px;';
        }

        // Output if we have anything to output
        if ($colorCount > 0 || $responsiveCss || $layout) {
            echo '<style id="custom-branding" data-colors="' . $colorCount . '">';
            echo ':root{' . $cssOutput . '}';
            echo $responsiveCss;
            echo '</style>';
        }
    }
}
?>

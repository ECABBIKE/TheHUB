<?php
/**
 * TheHUB V3 Configuration
 *
 * Parallel evaluation structure using the same database as production
 */

// Enable error reporting for debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', '1');

// ============================================================================
// DATABASE CONNECTION (uses parent config)
// ============================================================================
// Include the main config to get database connection
$parentConfig = dirname(__DIR__) . '/config.php';
if (file_exists($parentConfig)) {
    require_once $parentConfig;
} else {
    die('Error: Parent config.php not found at: ' . $parentConfig);
}

// Verify database connection exists
if (!isset($GLOBALS['pdo'])) {
    die('Error: Database connection not established');
}

// ============================================================================
// V3 VERSION INFO (only define if not already defined)
// ============================================================================
if (!defined('HUB_VERSION')) define('HUB_VERSION', '3.0.3');
if (!defined('CSS_VERSION')) define('CSS_VERSION', '3.0.3');
if (!defined('JS_VERSION')) define('JS_VERSION', '3.0.3');

if (!defined('HUB_V3_ROOT')) define('HUB_V3_ROOT', __DIR__);
if (!defined('HUB_V3_URL')) define('HUB_V3_URL', '/v3');

// ============================================================================
// V3 NAVIGATION & PAGES
// ============================================================================
if (!defined('HUB_VALID_PAGES')) {
    define('HUB_VALID_PAGES', [
        'dashboard', 'series', 'results', 'event',
        'riders', 'rider', 'clubs', 'club', '404'
    ]);
}

if (!defined('HUB_NAV')) {
    define('HUB_NAV', [
        ['id' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'home', 'url' => '/v3/', 'aria' => 'Gå till startsidan'],
        ['id' => 'series', 'label' => 'Serier', 'icon' => 'trophy', 'url' => '/v3/series', 'aria' => 'Visa alla serier'],
        ['id' => 'results', 'label' => 'Resultat', 'icon' => 'flag', 'url' => '/v3/results', 'aria' => 'Visa tävlingsresultat'],
        ['id' => 'riders', 'label' => 'Åkare', 'icon' => 'users', 'url' => '/v3/riders', 'aria' => 'Sök bland åkare'],
        ['id' => 'clubs', 'label' => 'Klubbar', 'icon' => 'shield', 'url' => '/v3/clubs', 'aria' => 'Visa klubbar och lag']
    ]);
}

// ============================================================================
// HELPER FUNCTIONS
// ============================================================================
if (!function_exists('hub_get_theme')) {
    function hub_get_theme(): string {
        $theme = $_COOKIE['hub_theme'] ?? 'auto';
        return in_array($theme, ['light', 'dark', 'auto']) ? $theme : 'auto';
    }
}

if (!function_exists('hub_asset')) {
    function hub_asset(string $path): string {
        $version = (strpos($path, '.css') !== false) ? CSS_VERSION : JS_VERSION;
        return HUB_V3_URL . '/assets/' . $path . '?v=' . $version;
    }
}

if (!function_exists('hub_db')) {
    /**
     * Get database connection (PDO)
     * @return PDO
     */
    function hub_db(): PDO {
        return $GLOBALS['pdo'];
    }
}

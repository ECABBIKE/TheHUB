<?php
/**
 * TheHUB V3 Configuration
 *
 * Parallel evaluation structure using the same database as production
 */

// ============================================================================
// DATABASE CONNECTION (uses parent config)
// ============================================================================
// Include the main config to get database connection
require_once dirname(__DIR__) . '/config.php';

// Now $pdo is available globally from the parent config
// Use hub_db() to get the PDO connection

// ============================================================================
// V3 VERSION INFO
// ============================================================================
define('HUB_VERSION', '3.0.1');
define('CSS_VERSION', '3.0.1');
define('JS_VERSION', '3.0.1');

define('HUB_V3_ROOT', __DIR__);
define('HUB_V3_URL', '/v3');

// ============================================================================
// V3 NAVIGATION & PAGES
// ============================================================================
define('HUB_VALID_PAGES', [
    'dashboard', 'series', 'results', 'event',
    'riders', 'rider', 'clubs', 'club', '404'
]);

define('HUB_NAV', [
    ['id' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'home', 'url' => '/v3/', 'aria' => 'Gå till startsidan'],
    ['id' => 'series', 'label' => 'Serier', 'icon' => 'trophy', 'url' => '/v3/series', 'aria' => 'Visa alla serier'],
    ['id' => 'results', 'label' => 'Resultat', 'icon' => 'flag', 'url' => '/v3/results', 'aria' => 'Visa tävlingsresultat'],
    ['id' => 'riders', 'label' => 'Åkare', 'icon' => 'users', 'url' => '/v3/riders', 'aria' => 'Sök bland åkare'],
    ['id' => 'clubs', 'label' => 'Klubbar', 'icon' => 'shield', 'url' => '/v3/clubs', 'aria' => 'Visa klubbar och lag']
]);

// ============================================================================
// HELPER FUNCTIONS
// ============================================================================
function hub_get_theme(): string {
    $theme = $_COOKIE['hub_theme'] ?? 'auto';
    return in_array($theme, ['light', 'dark', 'auto']) ? $theme : 'auto';
}

function hub_asset(string $path): string {
    $version = (strpos($path, '.css') !== false) ? CSS_VERSION : JS_VERSION;
    return HUB_V3_URL . '/assets/' . $path . '?v=' . $version;
}

/**
 * Get database connection (PDO)
 * @return PDO
 */
function hub_db(): PDO {
    return $GLOBALS['pdo'];
}

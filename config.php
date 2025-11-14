<?php
/**
 * TheHUB Configuration
 * Main configuration file that loads all necessary dependencies
 */

// Define base paths first
define('BASE_PATH', __DIR__);

/**
 * Load environment variables from .env file
 */
function loadEnv($path) {
    if (!file_exists($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        // Skip comments and empty lines
        if (strpos(trim($line), '#') === 0 || empty(trim($line))) {
            continue;
        }

        // Parse KEY=VALUE
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            $key = trim($parts[0]);
            $value = trim($parts[1]);

            // Remove quotes if present
            if ((substr($value, 0, 1) === '"' && substr($value, -1) === '"') ||
                (substr($value, 0, 1) === "'" && substr($value, -1) === "'")) {
                $value = substr($value, 1, -1);
            }

            // Set as environment variable
            $_ENV[$key] = $value;
            putenv("$key=$value");
        }
    }
}

// Load .env file
loadEnv(BASE_PATH . '/.env');

// Get environment helper function
function env($key, $default = null) {
    return $_ENV[$key] ?? getenv($key) ?: $default;
}

// Application settings
$appEnv = env('APP_ENV', 'development');
$appDebug = env('APP_DEBUG', 'true') === 'true';

// Set error reporting based on environment
if ($appEnv === 'production' && !$appDebug) {
    error_reporting(E_ALL);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
    ini_set('error_log', BASE_PATH . '/logs/error.log');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
}

// Define other paths
define('INCLUDES_PATH', BASE_PATH . '/includes');
define('ASSETS_PATH', BASE_PATH . '/assets');
define('UPLOADS_PATH', BASE_PATH . '/uploads');

// Session configuration
define('SESSION_NAME', env('SESSION_NAME', 'thehub_session'));
define('SESSION_LIFETIME', (int)env('SESSION_LIFETIME', '86400')); // 24 hours

// File upload limits
define('MAX_UPLOAD_SIZE', (int)env('MAX_UPLOAD_SIZE', 10 * 1024 * 1024)); // 10MB
$allowedExtensions = env('ALLOWED_EXTENSIONS', 'xlsx,xls,csv');
define('ALLOWED_EXTENSIONS', explode(',', $allowedExtensions));
define('EVENTS_PER_PAGE', 12);
define('RESULTS_PER_PAGE', 50);

// Admin credentials (for fallback)
define('DEFAULT_ADMIN_USERNAME', env('ADMIN_USERNAME', 'admin'));
define('DEFAULT_ADMIN_PASSWORD', env('ADMIN_PASSWORD', 'admin'));

// Database configuration (define constants before loading db.php)
if (!defined('DB_HOST')) {
    define('DB_HOST', env('DB_HOST', 'localhost'));
}
if (!defined('DB_NAME')) {
    define('DB_NAME', env('DB_NAME', 'thehub'));
}
if (!defined('DB_USER')) {
    define('DB_USER', env('DB_USER', 'root'));
}
if (!defined('DB_PASS')) {
    define('DB_PASS', env('DB_PASS', ''));
}
if (!defined('DB_CHARSET')) {
    define('DB_CHARSET', env('DB_CHARSET', 'utf8mb4'));
}
if (!defined('DB_ERROR_DISPLAY')) {
    define('DB_ERROR_DISPLAY', $appEnv !== 'production');
}

// Load core dependencies
require_once INCLUDES_PATH . '/db.php';
require_once INCLUDES_PATH . '/helpers.php';
require_once INCLUDES_PATH . '/auth.php';

/**
 * Require admin authentication
 * Redirects to login page if not authenticated
 */
function require_admin() {
    requireLogin();
}

/**
 * Get current admin user
 */
function get_current_admin() {
    return getCurrentAdmin();
}

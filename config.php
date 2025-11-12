<?php
/**
 * TheHUB Configuration
 * Main configuration file that loads all necessary dependencies
 */

// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Define base paths
define('BASE_PATH', __DIR__);
define('INCLUDES_PATH', BASE_PATH . '/includes');
define('ASSETS_PATH', BASE_PATH . '/assets');
define('UPLOADS_PATH', BASE_PATH . '/uploads');

// Session configuration
define('SESSION_NAME', 'thehub_session');
define('SESSION_LIFETIME', 86400); // 24 hours

// File upload limits
define('MAX_UPLOAD_SIZE', 10 * 1024 * 1024); // 10MB
define('ALLOWED_EXTENSIONS', ['xlsx', 'xls', 'csv']);
define('EVENTS_PER_PAGE', 12);
define('RESULTS_PER_PAGE', 50);

// Load core dependencies
require_once INCLUDES_PATH . '/db.php';
require_once INCLUDES_PATH . '/functions.php';
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

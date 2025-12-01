<?php
define('THEHUB_INIT', true);
error_reporting(E_ALL);
ini_set('display_errors', '0');

function env($key, $default = null) {
    $value = getenv($key);
    if ($value === false) {
        $envFile = __DIR__ . '/.env';
        if (file_exists($envFile)) {
            $lines = file($envFile);
            foreach ($lines as $line) {
                if (strpos($line, '=') !== false) {
                    list($k, $v) = explode('=', trim($line), 2);
                    if (trim($k) === $key) {
                        return trim($v);
                    }
                }
            }
        }
        return $default;
    }
    return $value;
}

define('ROOT_PATH', __DIR__);
define('INCLUDES_PATH', __DIR__ . '/includes');
define('UPLOADS_PATH', __DIR__ . '/uploads');
define('SITE_URL', 'https://thehub.gravityseries.se');
define('DB_HOST', env('DB_HOST', 'localhost'));
define('DB_NAME', env('DB_NAME', 'u994733455_thehub'));
define('DB_USER', env('DB_USER', 'u994733455_rogerthat'));
define('DB_PASS', env('DB_PASS', 'staggerMYnagger987!'));
define('APP_NAME', 'TheHUB');
define('DEFAULT_ADMIN_USERNAME', 'roger');
define('DEFAULT_ADMIN_PASSWORD', 'Jallemannen75!!!');
define('CSRF_TOKEN_NAME', 'csrf_token');
define('MAX_UPLOAD_SIZE', 10 * 1024 * 1024); // 10MB
define('EVENTS_PER_PAGE', 20);

// Version info
define('APP_VERSION', '2.5.2');
define('APP_VERSION_NAME', 'Mobile Optimization');
define('APP_BUILD', '2025-11-26');
define('DEPLOYMENT_OFFSET', 119); // Deployments before git repo

try {
    error_log('DEBUG: Attempting PDO connection to ' . DB_HOST . '/' . DB_NAME);
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        )
    );
    error_log('DEBUG: PDO connection successful, setting $GLOBALS[pdo]');
    $GLOBALS['pdo'] = $pdo;
    error_log('DEBUG: $GLOBALS[pdo] is now ' . (isset($GLOBALS['pdo']) ? 'SET' : 'NOT SET'));
} catch (PDOException $e) {
    error_log('PDO Connection Error: ' . $e->getMessage());
    error_log('Connection String: mysql:host=' . DB_HOST . ';dbname=' . DB_NAME);
    error_log('DB User: ' . DB_USER);
    die('Database connection failed: ' . $e->getMessage());
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Europe/Stockholm');

// ============================================================================
// DATABASE HELPER FUNCTION
// ============================================================================
if (!function_exists('hub_db')) {
    /**
     * Get the PDO database connection
     * @return PDO Database connection
     * @throws Exception if database connection is not available
     */
    function hub_db(): PDO {
        if (!isset($GLOBALS['pdo']) || !($GLOBALS['pdo'] instanceof PDO)) {
            throw new Exception('Database connection not available');
        }
        return $GLOBALS['pdo'];
    }
}

require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/auth.php';
?>

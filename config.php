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
define('SITE_URL', 'https://thehub.infinityfree.me');
define('DB_HOST', env('DB_HOST', 'sql100.infinityfree.com'));
define('DB_NAME', env('DB_NAME', 'if0_40400950_THEHUB'));
define('DB_USER', env('DB_USER', 'if0_40400950'));
define('DB_PASS', env('DB_PASS', ''));
define('APP_NAME', 'TheHUB');
define('DEFAULT_ADMIN_USERNAME', 'admin');
define('DEFAULT_ADMIN_PASSWORD', 'admin');
define('CSRF_TOKEN_NAME', 'csrf_token');
define('MAX_UPLOAD_SIZE', 10 * 1024 * 1024); // 10MB
define('EVENTS_PER_PAGE', 20);

// Version info
define('APP_VERSION', '2.2.0');
define('APP_VERSION_NAME', 'Import Fixes & UI Updates');
define('APP_BUILD', '2025-11-21-012');
define('DEPLOYMENT_OFFSET', 119); // Deployments before git repo

try {
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
    $GLOBALS['pdo'] = $pdo;
} catch (PDOException $e) {
    die('Database connection failed');
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Europe/Stockholm');

require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/auth.php';
?>

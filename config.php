<?php
/**
 * TheHUB Configuration File
 * Main configuration for TheHUB cycling results management system
 */

// Prevent direct access
if (!defined('THEHUB_INIT')) {
    define('THEHUB_INIT', true);
}

// ==============================================
// ERROR REPORTING (Set to false in production)
// ==============================================
error_reporting(E_ALL);
ini_set('display_errors', '0');  // Don't show errors to users
ini_set('log_errors', '1');      // But log them
ini_set('error_log', __DIR__ . '/logs/php-error.log');

// ==============================================
// ENVIRONMENT HELPER
// ==============================================
if (!function_exists('env')) {
    /**
     * Get environment variable with optional default
     */
    function env($key, $default = null) {
        $value = getenv($key);
        
        if ($value === false) {
            // Try $_ENV
            $value = $_ENV[$key] ?? null;
        }
        
        if ($value === false || $value === null) {
            // Try .env file
            static $envVars = null;
            if ($envVars === null) {
                $envVars = [];
                $envFile = __DIR__ . '/.env';
                if (file_exists($envFile)) {
                    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                    foreach ($lines as $line) {
                        if (strpos(trim($line), '#') === 0) continue;
                        if (strpos($line, '=') !== false) {
                            list($name, $value) = explode('=', $line, 2);
                            $envVars[trim($name)] = trim($value);
                        }
                    }
                }
            }
            $value = $envVars[$key] ?? $default;
        }
        
        return $value;
    }
}

// ==============================================
// PATH CONSTANTS
// ==============================================
define('ROOT_PATH', __DIR__);
define('INCLUDES_PATH', ROOT_PATH . '/includes');
define('ADMIN_PATH', ROOT_PATH . '/admin');
define('UPLOADS_PATH', ROOT_PATH . '/uploads');
define('LOGS_PATH', ROOT_PATH . '/logs');

// ==============================================
// URL CONSTANTS
// ==============================================
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
define('SITE_URL', $protocol . $host);
define('ADMIN_URL', SITE_URL . '/admin');

// ==============================================
// DATABASE CONFIGURATION
// ==============================================
define('DB_HOST', env('DB_HOST', 'sql100.infinityfree.com'));
define('DB_NAME', env('DB_NAME', 'if0_40400950_THEHUB'));
define('DB_USER', env('DB_USER', 'if0_40400950'));
define('DB_PASS', env('DB_PASS', ''));
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATION', 'utf8mb4_unicode_ci');

// ==============================================
// CREATE PDO DATABASE CONNECTION
// ==============================================
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET,
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . DB_CHARSET
        ]
    );
    
    // Set timezone to UTC
    $pdo->exec("SET time_zone = '+00:00'");
    
    // Make PDO available globally
    $GLOBALS['pdo'] = $pdo;
    
} catch (PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    die("Database connection failed. Please check configuration.");
}

// ==============================================
// SESSION CONFIGURATION
// ==============================================
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.use_strict_mode', 1);
    
    session_start();
}

// ==============================================
// TIMEZONE
// ==============================================
date_default_timezone_set(env('TIMEZONE', 'Europe/Stockholm'));

// ==============================================
// APPLICATION SETTINGS
// ==============================================
define('APP_NAME', env('APP_NAME', 'TheHUB'));
define('APP_VERSION', '2.0.0');
define('APP_ENV', env('APP_ENV', 'production'));
define('DEBUG', APP_ENV === 'development');

// ==============================================
// ADMIN CREDENTIALS (for simple auth)
// ==============================================
define('DEFAULT_ADMIN_USERNAME', env('ADMIN_USERNAME', 'admin'));
define('DEFAULT_ADMIN_PASSWORD', env('ADMIN_PASSWORD', 'admin'));

// ==============================================
// FILE UPLOAD SETTINGS
// ==============================================
define('MAX_UPLOAD_SIZE', 10 * 1024 * 1024); // 10MB
define('ALLOWED_UPLOAD_TYPES', ['csv', 'xlsx', 'xls']);

// ==============================================
// SECURITY SETTINGS
// ==============================================
define('CSRF_TOKEN_NAME', 'csrf_token');
define('SESSION_LIFETIME', 3600 * 2); // 2 hours

// ==============================================
// PAGINATION
// ==============================================
define('ITEMS_PER_PAGE', 50);
define('RESULTS_PER_PAGE', 100);

// ==============================================
// LOAD CORE HELPERS
// ==============================================
require_once INCLUDES_PATH . '/helpers.php';

// ==============================================
// LOAD AUTH FUNCTIONS
// ==============================================
require_once INCLUDES_PATH . '/auth.php';

// ==============================================
// AUTO-CREATE DIRECTORIES
// ==============================================
$directories = [
    UPLOADS_PATH,
    LOGS_PATH,
    UPLOADS_PATH . '/csv',
    UPLOADS_PATH . '/temp'
];

foreach ($directories as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
}

// ==============================================
// TIMEZONE HELPER
// ==============================================
if (!function_exists('formatDateTime')) {
    function formatDateTime($datetime, $format = 'Y-m-d H:i:s') {
        if (empty($datetime)) return '';
        if ($datetime instanceof DateTime) {
            return $datetime->format($format);
        }
        try {
            $dt = new DateTime($datetime);
            return $dt->format($format);
        } catch (Exception $e) {
            return $datetime;
        }
    }
}

// ==============================================
// UUID GENERATOR
// ==============================================
if (!function_exists('generateUuid')) {
    function generateUuid() {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}

// ==============================================
// SWE ID GENERATOR
// ==============================================
if (!function_exists('generateSweId')) {
    function generateSweId($pdo = null) {
        global $pdo;
        
        $prefix = 'SWE';
        $year = date('y');
        
        // Get last ID from database
        if ($pdo) {
            try {
                $stmt = $pdo->query("SELECT swe_id FROM riders WHERE swe_id LIKE 'SWE{$year}%' ORDER BY swe_id DESC LIMIT 1");
                $lastId = $stmt->fetchColumn();
                
                if ($lastId) {
                    $lastNumber = intval(substr($lastId, 5));
                    $nextNumber = $lastNumber + 1;
                } else {
                    $nextNumber = 1;
                }
            } catch (PDOException $e) {
                $nextNumber = mt_rand(1000, 9999);
            }
        } else {
            $nextNumber = mt_rand(1000, 9999);
        }
        
        return sprintf('%s%s%04d', $prefix, $year, $nextNumber);
    }
}

// ==============================================
// REDIRECT HELPER
// ==============================================
if (!function_exists('redirect')) {
    function redirect($url, $statusCode = 302) {
        // If relative URL, make it absolute
        if (strpos($url, 'http') !== 0) {
            if (strpos($url, '/') !== 0) {
                $url = '/' . $url;
            }
            $url = SITE_URL . $url;
        }
        
        header("Location: " . $url, true, $statusCode);
        exit;
    }
}

// ==============================================
// HTML ESCAPE HELPER
// ==============================================
if (!function_exists('h')) {
    function h($str) {
        return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
    }
}

// ==============================================
// DEBUG HELPER
// ==============================================
if (!function_exists('dd')) {
    function dd(...$vars) {
        echo '<pre style="background:#1a1a1a;color:#0f0;padding:20px;margin:20px;border-radius:5px;font-family:monospace;">';
        foreach ($vars as $var) {
            var_dump($var);
        }
        echo '</pre>';
        die();
    }
}

// ==============================================
// CONFIGURATION COMPLETE
// ==============================================
// All config loaded - application ready to use
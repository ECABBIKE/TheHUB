<?php
define('THEHUB_INIT', true);

// Centralized error handling - NEVER show errors in production
error_reporting(E_ALL);
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/logs/error.log');

function env($key, $default = null) {
    // Static cache - parse .env files ONCE per request instead of on every call
    static $envCache = null;
    static $stripeTestKeys = [
        'STRIPE_SECRET_KEY' => 'STRIPE_TEST_SECRET_KEY',
        'STRIPE_PUBLISHABLE_KEY' => 'STRIPE_TEST_PUBLISHABLE_KEY',
        'STRIPE_WEBHOOK_SECRET' => 'STRIPE_TEST_WEBHOOK_SECRET',
    ];

    // Load and cache all .env values on first call
    if ($envCache === null) {
        $envCache = [];
        $envFiles = [
            __DIR__ . '/.env.production',  // Load production first (lower priority)
            __DIR__ . '/.env'              // Then .env (higher priority, overwrites)
        ];
        foreach ($envFiles as $envFile) {
            if (file_exists($envFile)) {
                $lines = file($envFile);
                foreach ($lines as $line) {
                    $line = trim($line);
                    if (empty($line) || strpos($line, '#') === 0) continue;
                    if (strpos($line, '=') !== false) {
                        list($k, $v) = explode('=', $line, 2);
                        $envCache[trim($k)] = trim($v);
                    }
                }
            }
        }
    }

    // Stripe test/live mode toggle
    if (isset($stripeTestKeys[$key])) {
        $mode = env('STRIPE_MODE', 'live');
        if ($mode === 'test') {
            return env($stripeTestKeys[$key], $default);
        }
    }

    // Check real environment variables first, then cached .env values
    $value = getenv($key);
    if ($value !== false) {
        return $value;
    }

    return $envCache[$key] ?? $default;
}

define('ROOT_PATH', __DIR__);
define('INCLUDES_PATH', __DIR__ . '/includes');
define('UPLOADS_PATH', __DIR__ . '/uploads');
define('SITE_URL', env('SITE_URL', 'https://thehub.gravityseries.se'));

// Database configuration - MUST be set in .env file
define('DB_HOST', env('DB_HOST', 'localhost'));
define('DB_NAME', env('DB_NAME'));
define('DB_USER', env('DB_USER'));
define('DB_PASS', env('DB_PASS'));

// Check required database configuration
if (!DB_NAME || !DB_USER || !DB_PASS) {
    die('Database configuration missing. Please create a .env file with DB_NAME, DB_USER, and DB_PASS.');
}

define('APP_NAME', 'TheHUB');

// Admin credentials - MUST be set via environment variables in production
define('DEFAULT_ADMIN_USERNAME', env('ADMIN_USERNAME', 'admin'));
define('DEFAULT_ADMIN_PASSWORD_HASH', env('ADMIN_PASSWORD_HASH'));

// Security settings
define('CSRF_TOKEN_NAME', 'csrf_token');
define('MAX_UPLOAD_SIZE', 10 * 1024 * 1024); // 10MB
define('EVENTS_PER_PAGE', 20);
define('FORCE_HTTPS', env('FORCE_HTTPS', 'true'));

// Environment
define('APP_ENV', env('APP_ENV', 'production'));

// Force HTTPS in production (skip for API/webhook requests and CLI)
if (FORCE_HTTPS === 'true' && APP_ENV !== 'development' && !defined('HUB_API_REQUEST')) {
    if (!isset($_SERVER['HTTPS']) || $_SERVER['HTTPS'] !== 'on') {
        // Allow CLI and certain trusted proxies
        $behindProxy = isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https';
        if (!$behindProxy && php_sapi_name() !== 'cli') {
            $redirect = 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
            header('HTTP/1.1 301 Moved Permanently');
            header('Location: ' . $redirect);
            exit;
        }
    }
}

// Display errors only in development
if (APP_ENV === 'development') {
    ini_set('display_errors', '1');
} else {
    ini_set('display_errors', '0');
}

// Version info
define('APP_VERSION', '1.0');
define('APP_VERSION_NAME', 'Release');
define('APP_BUILD', '2026-02-23');
define('DEPLOYMENT_OFFSET', 131);


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

// Skip session and security headers for API/webhook requests (they set their own headers)
if (!defined('HUB_API_REQUEST')) {
    if (session_status() === PHP_SESSION_NONE) {
        // CRITICAL: Set server-side session lifetime to match cookie lifetime
        // PHP default is 1440s (24min) which causes premature session expiration
        ini_set('session.gc_maxlifetime', 2592000); // 30 days

        // Configure session cookie with longer lifetime
        session_set_cookie_params([
            'lifetime' => 2592000, // 30 days
            'path' => '/',
            'domain' => '',
            'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
        session_name('thehub_session');
        session_start();
    }

    // Security headers (only for web requests, not CLI)
    if (php_sapi_name() !== 'cli') {
        // Prevent MIME type sniffing
        header("X-Content-Type-Options: nosniff");

        // Prevent clickjacking
        header("X-Frame-Options: SAMEORIGIN");

        // Enable XSS filter in older browsers
        header("X-XSS-Protection: 1; mode=block");

        // Control referrer information
        header("Referrer-Policy: strict-origin-when-cross-origin");

        // Disable dangerous browser features
        header("Permissions-Policy: geolocation=(), microphone=(), camera=()");

        // HSTS - Force HTTPS for 1 year (only if HTTPS is enabled)
        if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
            header("Strict-Transport-Security: max-age=31536000; includeSubDomains");
        }

        // Content Security Policy - Allow Lucide icons from unpkg.com
        header("Content-Security-Policy: default-src 'self'; script-src 'self' https://unpkg.com 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self' data:;");
    }
} else {
    // For API requests, still start session if needed for auth functions
    // but don't set security headers that could interfere
    if (session_status() === PHP_SESSION_NONE && php_sapi_name() !== 'cli') {
        session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
        session_name('thehub_session');
        @session_start();
    }
}

date_default_timezone_set('Europe/Stockholm');

require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/auth.php';
?>

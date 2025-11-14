<?php
/**
 * TheHUB Configuration File
 */

define('THEHUB_INIT', true);

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

if (!function_exists('env')) {
    function env($key, $default = null) {
        $value = getenv($key);
        if ($value === false) {
            $value = isset($_ENV[$key]) ? $_ENV[$key] : null;
        }
        if ($value === false || $value === null) {
            static $envVars = null;
            if ($envVars === null) {
                $envVars = array();
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
            $value = isset($envVars[$key]) ? $envVars[$key] : $default;
        }
        return $value;
    }
}

define('ROOT_PATH', __DIR__);
define('INCLUDES_PATH', ROOT_PATH . '/includes');

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
$host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
define('SITE_URL', $protocol . $host);

define('DB_HOST', env('DB_HOST', 'sql100.infinityfree.com'));
define('DB_NAME', env('DB_NAME', 'if0_40400950_THEHUB'));
define('DB_USER', env('DB_USER', 'if0_40400950'));
define('DB_PASS', env('DB_PASS', ''));

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
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
    error_log("DB Error: " . $e->getMessage());
    die("Database connection failed");
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Europe/Stockholm');
define('APP_NAME', 'TheHUB');
define('DEFAULT_ADMIN_USERNAME', env('ADMIN_USERNAME', 'admin'));
define('DEFAULT_ADMIN_PASSWORD', env('ADMIN_PASSWORD', 'admin'));
define('CSRF_TOKEN_NAME', 'csrf_token');

require_once INCLUDES_PATH . '/helpers.php';
require_once INCLUDES_PATH . '/auth.php';
```

---

## 🎯 **VAD JAG ÄNDRADE:**

1. ✅ Inga backticks (`)
2. ✅ Inga short array syntax `[]` - använder `array()`
3. ✅ Inga null coalescing operators `??` - använder `isset() ? : default`
4. ✅ Ren, enkel PHP som fungerar överallt

---

## 📝 **TESTA:**

1. **Ersätt `/config.php`**
2. **Gå till:** `https://thehub.infinityfree.me/test-basic.php`
3. **Ska visa:**
```
   ✅ config.php loaded!
   ✅ PDO exists
    

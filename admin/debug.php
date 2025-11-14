<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Debug Test</h1>";

echo "<h2>PHP Info:</h2>";
echo "PHP Version: " . phpversion() . "<br>";
echo "Error Reporting: " . error_reporting() . "<br>";

echo "<h2>File Exists Check:</h2>";
echo "config.php: " . (file_exists('../config.php') ? '✅' : '❌') . "<br>";
echo "includes/auth.php: " . (file_exists('../includes/auth.php') ? '✅' : '❌') . "<br>";
echo "includes/db.php: " . (file_exists('../includes/db.php') ? '✅' : '❌') . "<br>";

echo "<h2>Include Test:</h2>";
try {
    require_once '../config.php';
    echo "config.php loaded ✅<br>";
} catch (Exception $e) {
    echo "config.php ERROR: " . $e->getMessage() . "<br>";
}

echo "<h2>Database Test:</h2>";
try {
    if (isset($pdo)) {
        echo "PDO exists ✅<br>";
        $count = $pdo->query("SELECT COUNT(*) FROM riders")->fetchColumn();
        echo "Riders count: $count ✅<br>";
    } else {
        echo "PDO missing ❌<br>";
    }
} catch (Exception $e) {
    echo "DB ERROR: " . $e->getMessage() . "<br>";
}

echo "<h2>Session Test:</h2>";
session_start();
echo "Session started ✅<br>";
echo "Session ID: " . session_id() . "<br>";
echo "Session data: <pre>" . print_r($_SESSION, true) . "</pre>";

echo "<h2>Auth Test:</h2>";
if (function_exists('require_admin')) {
    echo "require_admin() exists ✅<br>";
} else {
    echo "require_admin() MISSING ❌<br>";
}

echo "<h2>Constants:</h2>";
echo "SITE_URL: " . (defined('SITE_URL') ? SITE_URL : 'NOT DEFINED') . "<br>";
echo "DB_HOST: " . (defined('DB_HOST') ? DB_HOST : 'NOT DEFINED') . "<br>";
echo "DEBUG: " . (defined('DEBUG') ? (DEBUG ? 'TRUE' : 'FALSE') : 'NOT DEFINED') . "<br>";

phpinfo();
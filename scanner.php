<?php
/**
 * PERFORMANCE AUDITOR - Summary Version
 * Körs på servern och hittar de 10 största problemen.
 */
header('Content-Type: text/plain; charset=utf-8');
echo "=== SNABB-AUDIT: THE HUB ===\n\n";

// 1. KOLLA PHP & SERVER
echo "1. SERVER-INFO:\n";
echo "- PHP Version: " . PHP_VERSION . "\n";
echo "- Memory Limit: " . ini_get('memory_limit') . "\n";
echo "- OPcache aktivt: " . (function_exists('opcache_get_status') && opcache_get_status() ? "JA" : "NEJ") . "\n\n";

// 2. KOLLA DATABAS-BELASTNING (OM WP FINNS)
if (file_exists('wp-config.php')) {
    echo "2. DATABAS-STATUS (WordPress):\n";
    include 'wp-config.php';
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
    
    // Kolla storlek på wp_options (vanligaste flaskhalsen)
    $res = $conn->query("SELECT SUM(LENGTH(option_value)) as size FROM wp_options WHERE autoload = 'yes'");
    $row = $res->fetch_assoc();
    echo "- Autoloaded options storlek: " . round($row['size'] / 1024 / 1024, 2) . " MB (Bör vara < 1MB)\n";
    
    // Kolla antal rader i tunga tabeller
    $tables = ['wp_posts', 'wp_postmeta', 'wp_users', 'user_registration_sessions']; // Lägg till egna tabeller här
    foreach ($tables as $t) {
        $r = $conn->query("SHOW TABLES LIKE '$t'");
        if ($r->num_rows > 0) {
            $count = $conn->query("SELECT COUNT(*) as c FROM $t")->fetch_assoc();
            echo "- Tabell $t: " . $count['c'] . " rader\n";
        }
    }
}

// 3. Hitta den tyngsta filen i din källkod
echo "\n3. TYNGSTA KODFILER (Top 5):\n";
$root = __DIR__;
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
$fileList = [];
foreach ($files as $file) {
    if ($file->getExtension() == 'php' && strpos($file->getPath(), 'vendor') === false) {
        $fileList[$file->getPathname()] = $file->getSize();
    }
}
arsort($fileList);
foreach (array_slice($fileList, 0, 5) as $path => $size) {
    echo "- " . basename($path) . " (" . round($size / 1024, 1) . " KB)\n";
}

echo "\n=== AUDIT KLAR ===\n";


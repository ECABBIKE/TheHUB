php<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>GetDB Function Test</h1>";

echo "<h2>Step 1: Load config</h2>";
require_once '../config.php';
echo "✅ Config loaded<br>";

echo "<h2>Step 2: Check if getDB exists</h2>";
if (function_exists('getDB')) {
    echo "✅ getDB() function exists<br>";
} else {
    echo "❌ getDB() function MISSING<br>";
    die("STOP: getDB() not found!");
}

echo "<h2>Step 3: Call getDB()</h2>";
try {
    $db = getDB();
    echo "✅ getDB() called successfully<br>";
} catch (Throwable $e) {
    echo "❌ getDB() error: " . $e->getMessage() . "<br>";
    echo "File: " . $e->getFile() . " Line: " . $e->getLine() . "<br>";
    die();
}

echo "<h2>Step 4: Test getAll()</h2>";
try {
    $clubs = $db->getAll("SELECT id, name FROM clubs ORDER BY name LIMIT 5");
    echo "✅ getAll() works! Found " . count($clubs) . " clubs<br>";
    echo "<pre>";
    print_r($clubs);
    echo "</pre>";
} catch (Throwable $e) {
    echo "❌ getAll() error: " . $e->getMessage() . "<br>";
    die();
}

echo "<h2>Step 5: Test getRow()</h2>";
try {
    $rider = $db->getRow("SELECT * FROM riders LIMIT 1");
    echo "✅ getRow() works!<br>";
    echo "<pre>";
    print_r($rider);
    echo "</pre>";
} catch (Throwable $e) {
    echo "❌ getRow() error: " . $e->getMessage() . "<br>";
    die();
}

echo "<h2>Step 6: Check other functions</h2>";
echo "checkCsrf exists: " . (function_exists('checkCsrf') ? '✅' : '❌') . "<br>";
echo "get_current_admin exists: " . (function_exists('get_current_admin') ? '✅' : '❌') . "<br>";
echo "calculateAge exists: " . (function_exists('calculateAge') ? '✅' : '❌') . "<br>";
echo "checkLicense exists: " . (function_exists('checkLicense') ? '✅' : '❌') . "<br>";

echo "<h1>✅ ALL TESTS PASSED!</h1>";
echo "<p>If you see this, getDB() works perfectly!</p>";
echo "<p><a href='riders.php'>Try riders.php now</a></p>";

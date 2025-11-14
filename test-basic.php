<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "PHP Version: " . phpversion() . "<br>";
echo "Testing basic file loading...<br><br>";

echo "Step 1: Check config.php exists<br>";
if (file_exists('config.php')) {
    echo "✅ config.php file exists<br>";
} else {
    echo "❌ config.php MISSING!<br>";
    die();
}

echo "<br>Step 2: Try to include config.php<br>";
try {
    require_once 'config.php';
    echo "✅ config.php loaded!<br>";
} catch (Throwable $e) {
    echo "❌ ERROR: " . $e->getMessage() . "<br>";
    echo "File: " . $e->getFile() . "<br>";
    echo "Line: " . $e->getLine() . "<br>";
    die();
}

echo "<br>Step 3: Check PDO<br>";
if (isset($pdo)) {
    echo "✅ PDO exists<br>";
} else {
    echo "❌ PDO missing<br>";
}

echo "<br>✅ Basic test passed!";

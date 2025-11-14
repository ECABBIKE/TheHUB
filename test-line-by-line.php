<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "Testing config.php line by line...<br><br>";

echo "Step 1: Define constant<br>";
define('THEHUB_INIT', true);
echo "✅ Constant defined<br><br>";

echo "Step 2: Load helpers.php directly<br>";
if (file_exists('includes/helpers.php')) {
    echo "✅ helpers.php exists<br>";
    try {
        require_once 'includes/helpers.php';
        echo "✅ helpers.php loaded!<br>";
    } catch (Throwable $e) {
        echo "❌ helpers.php ERROR:<br>";
        echo "Message: " . $e->getMessage() . "<br>";
        echo "File: " . $e->getFile() . "<br>";
        echo "Line: " . $e->getLine() . "<br>";
        die();
    }
} else {
    echo "❌ helpers.php missing<br>";
}

echo "<br>Step 3: Load auth.php directly<br>";
if (file_exists('includes/auth.php')) {
    echo "✅ auth.php exists<br>";
    try {
        require_once 'includes/auth.php';
        echo "✅ auth.php loaded!<br>";
    } catch (Throwable $e) {
        echo "❌ auth.php ERROR:<br>";
        echo "Message: " . $e->getMessage() . "<br>";
        echo "File: " . $e->getFile() . "<br>";
        echo "Line: " . $e->getLine() . "<br>";
        die();
    }
} else {
    echo "❌ auth.php missing<br>";
}

echo "<br>✅ ALL TESTS PASSED!";

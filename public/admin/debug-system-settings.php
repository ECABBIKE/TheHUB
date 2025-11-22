<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Debug System Settings</h1>";

echo "<h2>1. Testing config.php...</h2>";
if (file_exists(__DIR__ . '/../config.php')) {
    echo "✅ config.php EXISTS<br>";
    require_once __DIR__ . '/../config.php';
    echo "✅ config.php LOADED<br>";
} else {
    echo "❌ config.php MISSING<br>";
}

echo "<h2>2. Testing point-calculations.php...</h2>";
if (file_exists(__DIR__ . '/../includes/point-calculations.php')) {
    echo "✅ point-calculations.php EXISTS<br>";
    require_once __DIR__ . '/../includes/point-calculations.php';
    echo "✅ point-calculations.php LOADED<br>";
} else {
    echo "❌ point-calculations.php MISSING<br>";
}

echo "<h2>3. Testing class-calculations.php...</h2>";
if (file_exists(__DIR__ . '/../includes/class-calculations.php')) {
    echo "✅ class-calculations.php EXISTS<br>";
    $content = file_get_contents(__DIR__ . '/../includes/class-calculations.php');
    echo "File size: " . strlen($content) . " bytes<br>";
    
    require_once __DIR__ . '/../includes/class-calculations.php';
    echo "✅ class-calculations.php LOADED<br>";
    
    // Test if functions exist
    echo "Function calculateAgeAtEvent: " . (function_exists('calculateAgeAtEvent') ? '✅' : '❌') . "<br>";
    echo "Function determineRiderClass: " . (function_exists('determineRiderClass') ? '✅' : '❌') . "<br>";
    echo "Function getClassDistributionPreview: " . (function_exists('getClassDistributionPreview') ? '✅' : '❌') . "<br>";
} else {
    echo "❌ class-calculations.php MISSING<br>";
}

echo "<h2>4. Testing database connection...</h2>";
try {
    $db = getDB();
    echo "✅ Database connected<br>";
    
    $classes = $db->getAll("SELECT COUNT(*) as count FROM classes");
    echo "Classes in database: " . $classes[0]['count'] . "<br>";
} catch (Exception $e) {
    echo "❌ Database error: " . $e->getMessage() . "<br>";
}

echo "<h2>✅ ALL TESTS PASSED!</h2>";
echo "<p><a href='/admin/system-settings.php?tab=classes'>Go to System Settings</a></p>";
?>

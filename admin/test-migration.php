<?php
/**
 * Simple Migration Test - Debug Version
 */

// Show EVERYTHING
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<!DOCTYPE html><html><head><title>Migration Test</title></head><body>";
echo "<h1>Migration Debug Test</h1>";

echo "<p>Step 1: Starting...</p>";
flush();

try {
    echo "<p>Step 2: Loading config...</p>";
    flush();

    require_once __DIR__ . '/../config.php';

    echo "<p>Step 3: Config loaded ✓</p>";
    flush();

    echo "<p>Step 4: Checking admin...</p>";
    flush();

    // Try to check if we're admin without redirecting
    if (function_exists('is_admin')) {
        if (is_admin()) {
            echo "<p>Step 5: You are admin ✓</p>";
        } else {
            echo "<p>Step 5: You are NOT admin ✗</p>";
        }
    } else {
        echo "<p>Step 5: is_admin() function not found, trying require_admin()...</p>";
        flush();

        // This might redirect, so let's catch it
        ob_start();
        require_admin();
        ob_end_flush();

        echo "<p>Step 5b: require_admin() passed ✓</p>";
    }
    flush();

    echo "<p>Step 6: Getting database connection...</p>";
    flush();

    $db = getDB();

    echo "<p>Step 7: Database connection obtained ✓</p>";
    flush();

    echo "<p>Step 8: Testing simple query...</p>";
    flush();

    $test = $db->getRow("SELECT 1 as test");

    if ($test) {
        echo "<p>Step 9: Database query works ✓ Result: " . print_r($test, true) . "</p>";
    } else {
        echo "<p>Step 9: Database query failed ✗</p>";
    }
    flush();

    echo "<p>Step 10: Testing ALTER TABLE query...</p>";
    flush();

    // Try a harmless query
    $result = $db->query("SHOW COLUMNS FROM riders");

    if ($result) {
        echo "<p>Step 11: Can query riders table ✓</p>";
    } else {
        echo "<p>Step 11: Cannot query riders table ✗</p>";
    }
    flush();

    echo "<h2 style='color: green;'>All steps completed successfully!</h2>";

} catch (Exception $e) {
    echo "<h2 style='color: red;'>ERROR at some step:</h2>";
    echo "<pre style='background: #fee; padding: 20px; border: 2px solid red;'>";
    echo "Message: " . htmlspecialchars($e->getMessage()) . "\n\n";
    echo "File: " . $e->getFile() . "\n";
    echo "Line: " . $e->getLine() . "\n\n";
    echo "Stack trace:\n" . htmlspecialchars($e->getTraceAsString());
    echo "</pre>";
}

echo "</body></html>";
?>

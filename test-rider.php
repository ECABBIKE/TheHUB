<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config.php';
$db = getDB();

// Test rider ID 7761
$riderId = 7761;

echo "<h1>Testing Rider ID: {$riderId}</h1>";

// Check if rider exists
$rider = $db->getRow("SELECT * FROM riders WHERE id = ?", [$riderId]);

if (!$rider) {
    echo "<p style='color: red;'>❌ Rider {$riderId} does NOT exist in database</p>";

    // Show some existing rider IDs
    $existingRiders = $db->getAll("SELECT id, firstname, lastname FROM riders ORDER BY id DESC LIMIT 10");
    echo "<h2>First 10 riders in database:</h2><ul>";
    foreach ($existingRiders as $r) {
        echo "<li>ID: {$r['id']} - {$r['firstname']} {$r['lastname']} - <a href='/rider.php?id={$r['id']}'>View</a></li>";
    }
    echo "</ul>";
} else {
    echo "<p style='color: green;'>✓ Rider exists!</p>";
    echo "<pre>";
    print_r($rider);
    echo "</pre>";

    echo "<p><a href='/rider.php?id={$riderId}'>Go to rider page</a></p>";
}
?>

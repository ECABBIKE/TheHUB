<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config.php';

$db = getDB();

echo "<h2>Table Structure Check</h2>";

// Check ranking_points table structure
echo "<h3>1. ranking_points table structure:</h3>";
$cols = $db->getAll("SHOW COLUMNS FROM ranking_points");
echo "<pre>";
foreach ($cols as $col) {
    echo $col['Field'] . " (" . $col['Type'] . ")\n";
}
echo "</pre>";

// Check if rider_id 7726 exists
echo "<h3>2. Check rider_id 7726 in ranking_points:</h3>";
$count = $db->getRow("SELECT COUNT(*) as cnt FROM ranking_points WHERE rider_id = 7726");
echo "<p>Count: " . ($count['cnt'] ?? 0) . "</p>";

if ($count['cnt'] == 0) {
    echo "<p style='color:red;'>No data found for rider_id = 7726</p>";

    // Check what rider_ids exist
    echo "<h3>3. Sample rider_ids in ranking_points:</h3>";
    $ids = $db->getAll("SELECT DISTINCT rider_id FROM ranking_points ORDER BY rider_id LIMIT 10");
    echo "<pre>";
    print_r($ids);
    echo "</pre>";

    // Check if maybe it's stored as cyclist_id instead
    echo "<h3>4. Check if table has cyclist_id column:</h3>";
    try {
        $test = $db->getRow("SELECT COUNT(*) as cnt FROM ranking_points WHERE cyclist_id = 7726");
        echo "<p style='color:green;'>Table has cyclist_id column! Count: " . ($test['cnt'] ?? 0) . "</p>";
    } catch (Exception $e) {
        echo "<p>No cyclist_id column (expected)</p>";
    }
} else {
    echo "<h3>3. Sample data for rider 7726:</h3>";
    $sample = $db->getAll("
        SELECT rp.*, e.name as event_name, e.date as event_date
        FROM ranking_points rp
        LEFT JOIN events e ON rp.event_id = e.id
        WHERE rp.rider_id = 7726
        LIMIT 3
    ");
    echo "<pre>";
    print_r($sample);
    echo "</pre>";
}

// Check results table
echo "<h3>5. Check results table for cyclist_id 7726:</h3>";
$resultsCount = $db->getRow("SELECT COUNT(*) as cnt FROM results WHERE cyclist_id = 7726");
echo "<p>Count: " . ($resultsCount['cnt'] ?? 0) . "</p>";

if (($resultsCount['cnt'] ?? 0) > 0) {
    $resultsSample = $db->getAll("
        SELECT r.*, e.name as event_name, e.date as event_date
        FROM results r
        JOIN events e ON r.event_id = e.id
        WHERE r.cyclist_id = 7726
        AND r.status = 'finished'
        AND e.discipline IN ('ENDURO', 'DH')
        AND e.date >= DATE_SUB(NOW(), INTERVAL 24 MONTH)
        LIMIT 3
    ");
    echo "<h3>6. Sample results for cyclist_id 7726 (last 24 months, ENDURO/DH):</h3>";
    echo "<pre>";
    print_r($resultsSample);
    echo "</pre>";
}

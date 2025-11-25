<?php
require_once __DIR__ . '/config.php';

$db = getDB();
$riderId = 7726; // Ella

echo "<h2>Ranking Points Table for Rider $riderId</h2>";

// Check if there are any ranking points
$count = $db->getRow("SELECT COUNT(*) as cnt FROM ranking_points WHERE rider_id = ?", [$riderId]);
echo "<p>Total ranking_points records: " . ($count['cnt'] ?? 0) . "</p>";

// Get sample data
$points = $db->getAll("
    SELECT
        rp.*,
        e.name as event_name,
        e.date as event_date
    FROM ranking_points rp
    LEFT JOIN events e ON rp.event_id = e.id
    WHERE rp.rider_id = ?
    ORDER BY e.date DESC
    LIMIT 10
", [$riderId]);

echo "<h3>Sample Data:</h3>";
echo "<pre>";
print_r($points);
echo "</pre>";

// Check events without ranking_points
echo "<h3>Events from results table (last 24 months):</h3>";
$results = $db->getAll("
    SELECT
        r.event_id,
        r.class_id,
        r.position,
        r.points,
        e.name as event_name,
        e.date as event_date,
        e.discipline
    FROM results r
    JOIN events e ON r.event_id = e.id
    WHERE r.cyclist_id = ?
    AND r.status = 'finished'
    AND e.date >= DATE_SUB(NOW(), INTERVAL 24 MONTH)
    AND e.discipline IN ('ENDURO', 'DH')
    ORDER BY e.date DESC
", [$riderId]);

echo "<p>Total results: " . count($results) . "</p>";
echo "<pre>";
print_r($results);
echo "</pre>";

<?php
require_once __DIR__ . '/config.php';

$db = getDB();
$riderId = 7726; // Ella
$discipline = 'GRAVITY';

echo "<h2>Debug Ranking Race Details for Rider $riderId</h2>";

// Check ranking_points table
echo "<h3>1. Ranking Points Table:</h3>";
$disciplineFilter = "AND e.discipline IN ('ENDURO', 'DH')";
$params = [$riderId];

$rankingData = $db->getAll("
    SELECT
        rp.ranking_points,
        rp.original_points,
        e.name as event_name,
        e.date as event_date
    FROM ranking_points rp
    JOIN events e ON rp.event_id = e.id
    WHERE rp.rider_id = ?
    AND e.date >= DATE_SUB(NOW(), INTERVAL 24 MONTH)
    {$disciplineFilter}
    ORDER BY e.date DESC
", $params);

echo "<p>Count: " . count($rankingData) . "</p>";
if (!empty($rankingData)) {
    echo "<pre>" . print_r($rankingData, true) . "</pre>";
} else {
    echo "<p style='color:orange;'>Empty - will fall back to results table</p>";
}

// Check results table (fallback)
echo "<h3>2. Results Table (Fallback):</h3>";
$rawResults = $db->getAll("
    SELECT
        r.event_id,
        r.class_id,
        r.position,
        r.points as original_points,
        e.name as event_name,
        e.date as event_date,
        e.location as event_location,
        e.discipline,
        cls.display_name as class_name
    FROM results r
    JOIN events e ON r.event_id = e.id
    LEFT JOIN classes cls ON r.class_id = cls.id
    WHERE r.cyclist_id = ?
    AND r.status = 'finished'
    AND r.points > 0
    AND e.date >= DATE_SUB(NOW(), INTERVAL 24 MONTH)
    {$disciplineFilter}
    ORDER BY e.date DESC
", $params);

echo "<p>Count: " . count($rawResults) . "</p>";
echo "<pre>" . print_r($rawResults, true) . "</pre>";

// Check if cyclist_id vs rider_id issue
echo "<h3>3. Check cyclist_id in results:</h3>";
$check = $db->getRow("
    SELECT COUNT(*) as cnt
    FROM results
    WHERE cyclist_id = ?
", [$riderId]);
echo "<p>Results with cyclist_id=$riderId: " . ($check['cnt'] ?? 0) . "</p>";

<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<!-- Starting debug script -->\n";
flush();

require_once __DIR__ . '/config.php';

echo "<!-- Config loaded -->\n";
flush();

$db = getDB();
echo "<!-- DB connected -->\n";
flush();

$riderId = 7726; // Ella
$discipline = 'GRAVITY';

echo "<h2>Debug Ranking Race Details for Rider $riderId</h2>";
flush();

// Check ranking_points table
echo "<h3>1. Ranking Points Table:</h3>";
flush();

$disciplineFilter = "AND e.discipline IN ('ENDURO', 'DH')";
$params = [$riderId];

try {
    echo "<p>Querying ranking_points table...</p>";
    flush();

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

    echo "<p>Query completed. Count: " . count($rankingData) . "</p>";
    flush();

    if (!empty($rankingData)) {
        echo "<pre>" . print_r($rankingData, true) . "</pre>";
    } else {
        echo "<p style='color:orange;'>Empty - will fall back to results table</p>";
    }
} catch (Exception $e) {
    echo "<p style='color:red;'>ERROR: " . htmlspecialchars($e->getMessage()) . "</p>";
}
flush();

// Check results table (fallback)
echo "<h3>2. Results Table (Fallback):</h3>";
flush();

try {
    echo "<p>Querying results table...</p>";
    flush();

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

    echo "<p>Query completed. Count: " . count($rawResults) . "</p>";
    flush();

    if (!empty($rawResults)) {
        echo "<pre>" . print_r($rawResults, true) . "</pre>";
    } else {
        echo "<p style='color:red;'>No results found in results table!</p>";
    }
} catch (Exception $e) {
    echo "<p style='color:red;'>ERROR: " . htmlspecialchars($e->getMessage()) . "</p>";
}
flush();

// Check if cyclist_id vs rider_id issue
echo "<h3>3. Check cyclist_id in results:</h3>";
flush();

try {
    echo "<p>Checking total results for cyclist_id=$riderId...</p>";
    flush();

    $check = $db->getRow("
        SELECT COUNT(*) as cnt
        FROM results
        WHERE cyclist_id = ?
    ", [$riderId]);

    echo "<p>Results with cyclist_id=$riderId: " . ($check['cnt'] ?? 0) . "</p>";
    flush();
} catch (Exception $e) {
    echo "<p style='color:red;'>ERROR: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<h3>4. Complete - Script finished successfully</h3>";
flush();

<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config.php';

$db = getDB();
$riderId = 7726;

echo "<h2>Debug Rider Points - Rider $riderId</h2>";

// Check what's in rankingRaceDetails after fallback
$discipline = 'GRAVITY';
$disciplineFilter = "AND e.discipline IN ('ENDURO', 'DH')";
$params = [$riderId];

// Try ranking_points first
echo "<h3>1. Try ranking_points table:</h3>";
try {
    $rankingData = $db->getAll("
        SELECT
            rp.ranking_points,
            rp.original_points,
            rp.field_size,
            rp.field_multiplier,
            rp.event_level_multiplier,
            rp.time_multiplier,
            rp.position,
            e.name as event_name,
            e.date as event_date
        FROM ranking_points rp
        JOIN events e ON rp.event_id = e.id
        WHERE rp.rider_id = ?
        AND e.date >= DATE_SUB(NOW(), INTERVAL 24 MONTH)
        {$disciplineFilter}
        ORDER BY e.date DESC
    ", $params);
    echo "<p>Success! Count: " . count($rankingData) . "</p>";
} catch (Exception $e) {
    echo "<p style='color:red;'>Failed: " . htmlspecialchars($e->getMessage()) . "</p>";
    $rankingData = [];
}

// Fallback to results
echo "<h3>2. Fallback to results table:</h3>";
if (empty($rankingData)) {
    echo "<p>Ranking_points empty, using fallback...</p>";

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

    echo "<p>Raw results count: " . count($rawResults) . "</p>";

    // Convert to expected format
    $finalData = [];
    foreach ($rawResults as $result) {
        $finalData[] = [
            'ranking_points' => $result['original_points'],
            'original_points' => $result['original_points'],
            'field_size' => 0,
            'field_multiplier' => 1.0,
            'event_level_multiplier' => 1.0,
            'time_multiplier' => 1.0,
            'position' => $result['position'],
            'event_name' => $result['event_name'],
            'event_date' => $result['event_date'],
            'event_location' => $result['event_location'],
            'discipline' => $result['discipline'],
            'class_name' => $result['class_name']
        ];
    }

    echo "<h3>3. Final data array:</h3>";
    echo "<p>Count: " . count($finalData) . "</p>";

    if (!empty($finalData)) {
        echo "<h4>First event:</h4>";
        echo "<pre style='background: #f5f5f5; padding: 1rem; border-radius: 4px;'>";
        print_r($finalData[0]);
        echo "</pre>";

        echo "<h4>All events:</h4>";
        echo "<table border='1' cellpadding='5' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr>
            <th>Position</th>
            <th>Event</th>
            <th>Date</th>
            <th>ranking_points</th>
            <th>original_points</th>
        </tr>";

        foreach ($finalData as $event) {
            echo "<tr>";
            echo "<td>" . ($event['position'] ?? '-') . "</td>";
            echo "<td>" . htmlspecialchars($event['event_name'] ?? '-') . "</td>";
            echo "<td>" . ($event['event_date'] ?? '-') . "</td>";
            echo "<td style='font-weight: bold; color: blue;'>" . ($event['ranking_points'] ?? 'NULL') . "</td>";
            echo "<td>" . ($event['original_points'] ?? 'NULL') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
}

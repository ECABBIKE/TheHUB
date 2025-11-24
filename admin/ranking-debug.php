<?php
/**
 * Ranking System Diagnostic Tool
 * Checks what data exists and identifies issues
 */
require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();

// Get the cutoff date for 24 months
$cutoffDate = date('Y-m-d', strtotime('-24 months'));

echo "<h1>Ranking System Diagnostics</h1>";
echo "<p>Checking data from the last 24 months (since $cutoffDate)</p>";

echo "<hr>";

// 1. Check events table
echo "<h2>1. Events with Enduro/DH discipline</h2>";
$events = $db->getAll("
    SELECT
        id,
        name,
        date,
        discipline,
        event_level,
        point_scale_id
    FROM events
    WHERE date >= ?
    AND discipline IN ('ENDURO', 'DH')
    ORDER BY date DESC
", [$cutoffDate]);

if (empty($events)) {
    echo "<p style='color: red;'><strong>❌ PROBLEM: No events found with discipline 'ENDURO' or 'DH' in the last 24 months!</strong></p>";
    echo "<p>You need to set the discipline field for your events. Go to Admin > Events and set the discipline.</p>";
} else {
    echo "<p style='color: green;'>✅ Found " . count($events) . " events with ENDURO/DH discipline</p>";
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>ID</th><th>Name</th><th>Date</th><th>Discipline</th><th>Event Level</th><th>Point Scale ID</th></tr>";
    foreach ($events as $event) {
        $hasScale = $event['point_scale_id'] ? '✅' : '❌ Missing!';
        echo "<tr>";
        echo "<td>{$event['id']}</td>";
        echo "<td>{$event['name']}</td>";
        echo "<td>{$event['date']}</td>";
        echo "<td>{$event['discipline']}</td>";
        echo "<td>{$event['event_level']}</td>";
        echo "<td>{$hasScale} {$event['point_scale_id']}</td>";
        echo "</tr>";
    }
    echo "</table>";
}

echo "<hr>";

// 2. Check results with points
echo "<h2>2. Results with points from ENDURO/DH events</h2>";
$resultsWithPoints = $db->getRow("
    SELECT
        COUNT(*) as total_results,
        COUNT(CASE WHEN r.points > 0 OR COALESCE(r.run_1_points, 0) > 0 OR COALESCE(r.run_2_points, 0) > 0 THEN 1 END) as results_with_points
    FROM results r
    JOIN events e ON r.event_id = e.id
    WHERE e.date >= ?
    AND e.discipline IN ('ENDURO', 'DH')
    AND r.status = 'finished'
", [$cutoffDate]);

echo "<p>Total finished results: {$resultsWithPoints['total_results']}</p>";
echo "<p>Results with points: {$resultsWithPoints['results_with_points']}</p>";

if ($resultsWithPoints['results_with_points'] == 0 && $resultsWithPoints['total_results'] > 0) {
    echo "<p style='color: red;'><strong>❌ PROBLEM: Results exist but no points are assigned!</strong></p>";
    echo "<p>Events need to have a point_scale_id assigned. Check the table above.</p>";
}

echo "<hr>";

// 3. Check classes that should be eligible
echo "<h2>3. Classes eligible for ranking</h2>";
$classes = $db->getAll("
    SELECT
        id,
        name,
        display_name,
        discipline,
        COALESCE(series_eligible, 1) as series_eligible,
        COALESCE(awards_points, 1) as awards_points
    FROM classes
    WHERE discipline IN ('ENDURO', 'DH', 'GRAVITY')
    ORDER BY name
");

echo "<table border='1' cellpadding='5'>";
echo "<tr><th>ID</th><th>Name</th><th>Display Name</th><th>Discipline</th><th>Series Eligible</th><th>Awards Points</th></tr>";
foreach ($classes as $class) {
    $eligible = $class['series_eligible'] && $class['awards_points'] ? '✅' : '❌';
    echo "<tr>";
    echo "<td>{$class['id']}</td>";
    echo "<td>{$class['name']}</td>";
    echo "<td>{$class['display_name']}</td>";
    echo "<td>{$class['discipline']}</td>";
    echo "<td>{$eligible} {$class['series_eligible']}</td>";
    echo "<td>{$eligible} {$class['awards_points']}</td>";
    echo "</tr>";
}
echo "</table>";

echo "<hr>";

// 4. Check ranking_points table
echo "<h2>4. Ranking Points Calculated</h2>";
$rankingPoints = $db->getRow("
    SELECT
        COUNT(*) as total,
        COUNT(DISTINCT rider_id) as unique_riders,
        COUNT(DISTINCT event_id) as unique_events,
        SUM(CASE WHEN discipline = 'ENDURO' THEN 1 ELSE 0 END) as enduro_count,
        SUM(CASE WHEN discipline = 'DH' THEN 1 ELSE 0 END) as dh_count
    FROM ranking_points
");

if ($rankingPoints['total'] == 0) {
    echo "<p style='color: red;'><strong>❌ No ranking points calculated yet!</strong></p>";
    echo "<p>Go to Admin > Ranking and click 'Kör beräkning' to calculate ranking points.</p>";
} else {
    echo "<p style='color: green;'>✅ Ranking points exist:</p>";
    echo "<ul>";
    echo "<li>Total entries: {$rankingPoints['total']}</li>";
    echo "<li>Unique riders: {$rankingPoints['unique_riders']}</li>";
    echo "<li>Unique events: {$rankingPoints['unique_events']}</li>";
    echo "<li>Enduro results: {$rankingPoints['enduro_count']}</li>";
    echo "<li>DH results: {$rankingPoints['dh_count']}</li>";
    echo "</ul>";
}

echo "<hr>";

// 5. Check ranking_snapshots table
echo "<h2>5. Ranking Snapshots Created</h2>";
$snapshots = $db->getAll("
    SELECT
        discipline,
        snapshot_date,
        COUNT(*) as rider_count
    FROM ranking_snapshots
    GROUP BY discipline, snapshot_date
    ORDER BY snapshot_date DESC, discipline
    LIMIT 10
");

if (empty($snapshots)) {
    echo "<p style='color: red;'><strong>❌ No ranking snapshots created yet!</strong></p>";
    echo "<p>After calculating ranking points, snapshots need to be created.</p>";
} else {
    echo "<p style='color: green;'>✅ Ranking snapshots exist:</p>";
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>Discipline</th><th>Snapshot Date</th><th>Rider Count</th></tr>";
    foreach ($snapshots as $snapshot) {
        echo "<tr>";
        echo "<td>{$snapshot['discipline']}</td>";
        echo "<td>{$snapshot['snapshot_date']}</td>";
        echo "<td>{$snapshot['rider_count']}</td>";
        echo "</tr>";
    }
    echo "</table>";
}

echo "<hr>";

// 6. Summary and recommendations
echo "<h2>6. Summary and Recommendations</h2>";

$issues = [];
$recommendations = [];

if (empty($events)) {
    $issues[] = "No events have discipline set to ENDURO or DH";
    $recommendations[] = "Go to Admin > Events and set the discipline for your events";
}

if ($resultsWithPoints['results_with_points'] == 0 && $resultsWithPoints['total_results'] > 0) {
    $issues[] = "Results exist but have no points";
    $recommendations[] = "Assign point scales to events (events need point_scale_id)";
}

if ($rankingPoints['total'] == 0) {
    $issues[] = "No ranking points have been calculated";
    $recommendations[] = "Go to Admin > Ranking and click 'Kör beräkning'";
}

if (empty($snapshots)) {
    $issues[] = "No ranking snapshots have been created";
    $recommendations[] = "Run the ranking calculation to create snapshots";
}

if (empty($issues)) {
    echo "<p style='color: green; font-size: 18px;'><strong>✅ Everything looks good!</strong></p>";
    echo "<p>If ranking still doesn't show, check the public ranking page at /ranking/</p>";
} else {
    echo "<p style='color: red; font-size: 18px;'><strong>❌ Issues found:</strong></p>";
    echo "<ol>";
    foreach ($issues as $issue) {
        echo "<li>{$issue}</li>";
    }
    echo "</ol>";

    echo "<p style='font-size: 18px;'><strong>Recommendations:</strong></p>";
    echo "<ol>";
    foreach ($recommendations as $rec) {
        echo "<li>{$rec}</li>";
    }
    echo "</ol>";
}

echo "<hr>";
echo "<p><a href='/admin/ranking.php'>Go to Ranking Admin</a> | <a href='/admin/events.php'>Go to Events Admin</a></p>";
?>

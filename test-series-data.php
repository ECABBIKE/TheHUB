<?php
require_once __DIR__ . '/config.php';

$db = getDB();

echo "<h1>SweCup Enduro 2025 - Data Check</h1>";

// Get series
$series = $db->getRow("
    SELECT * FROM series WHERE name LIKE '%SweCup Enduro 2025%'
");

if (!$series) {
    echo "<p>No series found with name containing 'SweCup Enduro 2025'</p>";

    // Show all series
    $allSeries = $db->getAll("SELECT id, name, year FROM series ORDER BY year DESC");
    echo "<h2>All series:</h2><ul>";
    foreach ($allSeries as $s) {
        echo "<li>ID: {$s['id']}, Name: {$s['name']}, Year: {$s['year']}</li>";
    }
    echo "</ul>";
    exit;
}

$seriesId = $series['id'];
echo "<p><strong>Series ID: {$seriesId}</strong></p>";
echo "<p>Name: {$series['name']}</p>";
echo "<p>Year: {$series['year']}</p>";

// Get events in this series
$events = $db->getAll("
    SELECT id, name, date FROM events WHERE series_id = ? ORDER BY date ASC
", [$seriesId]);

echo "<h2>Events in this series: " . count($events) . "</h2>";
foreach ($events as $event) {
    echo "<p>Event ID: {$event['id']}, Name: {$event['name']}, Date: {$event['date']}</p>";

    // Get results count for this event
    $resultCount = $db->getOne("SELECT COUNT(*) FROM results WHERE event_id = ?", [$event['id']]);
    echo "<p style='margin-left: 20px;'>Results: {$resultCount}</p>";

    // Get classes in this event
    $classesInEvent = $db->getAll("
        SELECT DISTINCT c.id, c.name, c.display_name, c.active,
               COUNT(r.id) as result_count
        FROM results r
        JOIN classes c ON r.class_id = c.id
        WHERE r.event_id = ?
        GROUP BY c.id
        ORDER BY c.sort_order ASC
    ", [$event['id']]);

    echo "<p style='margin-left: 20px;'>Classes with results:</p>";
    echo "<ul style='margin-left: 40px;'>";
    foreach ($classesInEvent as $class) {
        $activeStatus = $class['active'] ? 'ACTIVE' : 'INACTIVE';
        echo "<li>Class ID: {$class['id']}, Name: {$class['name']} ({$class['display_name']}), Active: {$activeStatus}, Results: {$class['result_count']}</li>";
    }
    echo "</ul>";
}

// Get all classes that have results in this series (same query as in series-standings.php)
$activeClasses = $db->getAll("
    SELECT DISTINCT c.id, c.name, c.display_name, c.sort_order, c.active,
           COUNT(DISTINCT r.cyclist_id) as rider_count
    FROM classes c
    JOIN results r ON c.id = r.class_id
    JOIN events e ON r.event_id = e.id
    WHERE e.series_id = ? AND c.active = 1
    GROUP BY c.id
    ORDER BY c.sort_order ASC
", [$seriesId]);

echo "<h2>Active classes in series (from series-standings.php query): " . count($activeClasses) . "</h2>";
if (empty($activeClasses)) {
    echo "<p><strong>NO ACTIVE CLASSES FOUND!</strong></p>";

    // Try without the active = 1 requirement
    $allClasses = $db->getAll("
        SELECT DISTINCT c.id, c.name, c.display_name, c.sort_order, c.active,
               COUNT(DISTINCT r.cyclist_id) as rider_count
        FROM classes c
        JOIN results r ON c.id = r.class_id
        JOIN events e ON r.event_id = e.id
        WHERE e.series_id = ?
        GROUP BY c.id
        ORDER BY c.sort_order ASC
    ", [$seriesId]);

    echo "<h3>All classes (including inactive): " . count($allClasses) . "</h3>";
    foreach ($allClasses as $class) {
        $activeStatus = $class['active'] ? 'ACTIVE' : 'INACTIVE';
        echo "<p>Class ID: {$class['id']}, Name: {$class['name']} ({$class['display_name']}), Active: {$activeStatus}, Riders: {$class['rider_count']}</p>";
    }
} else {
    foreach ($activeClasses as $class) {
        echo "<p>Class ID: {$class['id']}, Name: {$class['name']} ({$class['display_name']}), Riders: {$class['rider_count']}</p>";
    }
}

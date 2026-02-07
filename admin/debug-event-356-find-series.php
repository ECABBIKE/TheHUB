<?php
require_once __DIR__ . '/../config.php';
$db = getDB();

echo '<pre>';
echo "=== FIND CORRECT SERIES FOR EVENT 356 ===\n\n";

// Get Event 356
$event = $db->getOne("SELECT id, name, date, location FROM events WHERE id = 356");
echo "Event 356:\n";
echo "  Name: {$event['name']}\n";
echo "  Date: {$event['date']}\n";
echo "  Location: {$event['location']}\n\n";

// Find matching series
$year = date('Y', strtotime($event['date']));
echo "Looking for series in year: $year\n\n";

$series = $db->getAll("
    SELECT id, name, year
    FROM series
    WHERE year = ?
    ORDER BY name
", [$year]);

echo "Available series for $year:\n";
foreach ($series as $s) {
    echo "  ID: {$s['id']}, Name: {$s['name']}\n";
}

echo "\n=== ALL SERIES (all years) ===\n";
$allSeries = $db->getAll("SELECT id, name, year FROM series ORDER BY year DESC, name");
foreach ($allSeries as $s) {
    echo "  ID: {$s['id']}, Name: {$s['name']}, Year: {$s['year']}\n";
}

echo '</pre>';
?>

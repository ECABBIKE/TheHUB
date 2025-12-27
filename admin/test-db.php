<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "1. Start<br>";

require_once __DIR__ . '/../config.php';
echo "2. Config loaded<br>";

$db = getDB();
echo "3. DB connected<br>";

$count = $db->query("SELECT COUNT(*) FROM riders")->fetchColumn();
echo "4. Riders count: {$count}<br>";

$minMax = $db->query("SELECT MIN(id) as min_id, MAX(id) as max_id FROM riders")->fetch(PDO::FETCH_ASSOC);
echo "5. Rider IDs: {$minMax['min_id']} - {$minMax['max_id']}<br>";

$achCount = $db->query("SELECT COUNT(*) FROM rider_achievements")->fetchColumn();
echo "6. Achievements count: {$achCount}<br>";

$resMinMax = $db->query("SELECT MIN(cyclist_id) as min_id, MAX(cyclist_id) as max_id FROM results WHERE cyclist_id IS NOT NULL")->fetch(PDO::FETCH_ASSOC);
echo "7. Results cyclist_id: {$resMinMax['min_id']} - {$resMinMax['max_id']}<br>";

// Check orphaned
$orphaned = $db->query("
    SELECT COUNT(*) FROM results r
    LEFT JOIN riders rd ON r.cyclist_id = rd.id
    WHERE rd.id IS NULL AND r.cyclist_id IS NOT NULL
")->fetchColumn();
echo "8. Orphaned results (cyclist_id not in riders): {$orphaned}<br>";

echo "<br><strong>Done!</strong>";

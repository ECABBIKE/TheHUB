<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "1. Start<br>";

require_once __DIR__ . '/../config.php';
echo "2. Config loaded<br>";

require_once __DIR__ . '/../includes/auth.php';
echo "3. Auth loaded<br>";

requireAdmin();
echo "4. Admin verified<br>";

$db = getDB();
echo "5. DB connected<br>";

$count = $db->query("SELECT COUNT(*) FROM riders")->fetchColumn();
echo "6. Riders count: {$count}<br>";

$achCount = $db->query("SELECT COUNT(*) FROM rider_achievements")->fetchColumn();
echo "7. Achievements count: {$achCount}<br>";

echo "Done!";

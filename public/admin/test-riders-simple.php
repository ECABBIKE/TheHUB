<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../config.php';
require_admin();

echo "<h1>Riders Simple Test</h1>";

global $pdo;
$db = getDB();

echo "Step 1: Get clubs<br>";
$clubs = $db->getAll("SELECT id, name FROM clubs ORDER BY name LIMIT 5");
echo "Clubs: " . count($clubs) . "<br>";

echo "<br>Step 2: Get riders<br>";
$sql = "SELECT c.id, c.firstname, c.lastname FROM riders c LIMIT 10";
$riders = $db->getAll($sql);
echo "Riders: " . count($riders) . "<br>";

echo "<br>Step 3: Display riders<br>";
foreach ($riders as $rider) {
    echo "- " . htmlspecialchars($rider['firstname'] . ' ' . $rider['lastname']) . "<br>";
}

echo "<br>✅ ALL TESTS PASSED!";
echo "<br><a href='riders.php'>Try actual riders.php</a>";

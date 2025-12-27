<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config.php';
$db = getDB();

echo "<h3>Series table columns:</h3>";
$cols = $db->query("DESCRIBE series")->fetchAll(PDO::FETCH_ASSOC);
echo "<pre>";
foreach ($cols as $col) {
    echo $col['Field'] . " - " . $col['Type'] . "\n";
}
echo "</pre>";

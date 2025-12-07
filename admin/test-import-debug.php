<?php
/**
 * Debug file to test import-results-preview dependencies
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

echo "Step 1: Loading config.php...<br>";
require_once __DIR__ . '/../config.php';
echo "OK<br>";

echo "Step 2: Loading import-history.php...<br>";
require_once __DIR__ . '/../includes/import-history.php';
echo "OK<br>";

echo "Step 3: Loading class-calculations.php...<br>";
require_once __DIR__ . '/../includes/class-calculations.php';
echo "OK<br>";

echo "Step 4: Loading point-calculations.php...<br>";
require_once __DIR__ . '/../includes/point-calculations.php';
echo "OK<br>";

echo "Step 5: Loading import-functions.php...<br>";
require_once __DIR__ . '/../includes/import-functions.php';
echo "OK<br>";

echo "Step 6: Loading series-points.php...<br>";
require_once __DIR__ . '/../includes/series-points.php';
echo "OK<br>";

echo "Step 7: Loading rebuild-rider-stats.php...<br>";
require_once __DIR__ . '/../includes/rebuild-rider-stats.php';
echo "OK<br>";

echo "Step 8: Checking require_admin...<br>";
require_admin();
echo "OK - Admin authenticated<br>";

echo "Step 9: Testing getDB()...<br>";
$db = getDB();
echo "OK - DB type: " . get_class($db) . "<br>";

echo "Step 10: Testing database query...<br>";
$test = $db->getAll("SELECT COUNT(*) as cnt FROM events");
echo "OK - Events count: " . $test[0]['cnt'] . "<br>";

echo "Step 11: Checking session variables...<br>";
echo "import_preview_file: " . ($_SESSION['import_preview_file'] ?? 'NOT SET') . "<br>";
echo "import_selected_event: " . ($_SESSION['import_selected_event'] ?? 'NOT SET') . "<br>";

echo "<br><strong>All tests passed!</strong>";

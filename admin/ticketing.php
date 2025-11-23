<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "STEG 1: Start<br>";

require_once __DIR__ . '/../config.php';
echo "STEG 2: Config loaded<br>";

require_admin();
echo "STEG 3: Admin checked<br>";

$db = getDB();
echo "STEG 4: DB connected<br>";

// Check columns
$ticketingColumnsExist = false;
try {
    $columns = $db->getAll("SHOW COLUMNS FROM events WHERE Field IN ('ticketing_enabled', 'woo_product_id', 'ticket_deadline_days')");
    $ticketingColumnsExist = count($columns) >= 3;
    echo "STEG 5: Columns check done - " . count($columns) . " found<br>";
} catch (Exception $e) {
    echo "STEG 5: ERROR - " . $e->getMessage() . "<br>";
}

// Check tables
$ticketingTablesExist = false;
try {
    $tables = $db->getAll("SHOW TABLES LIKE 'event_tickets'");
    $ticketingTablesExist = !empty($tables);
    echo "STEG 6: Tables check done<br>";
} catch (Exception $e) {
    echo "STEG 6: ERROR - " . $e->getMessage() . "<br>";
}

// Simple query
$events = [];
try {
    $events = $db->getAll("SELECT id, name, date FROM events WHERE date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) ORDER BY date ASC LIMIT 5");
    echo "STEG 7: Events query done - " . count($events) . " events<br>";
} catch (Exception $e) {
    echo "STEG 7: ERROR - " . $e->getMessage() . "<br>";
}

echo "STEG 8: Before header<br>";

$pageTitle = 'Ticketing';
$pageType = 'admin';
include __DIR__ . '/../includes/layout-header.php';

echo "STEG 9: After header<br>";
?>

<main class="gs-main-content">
    <div class="gs-container">
        <h1>Ticketing Dashboard - v2.4.2-068</h1>
        <p>Debug complete!</p>
        <p>Events found: <?= count($events) ?></p>
    </div>
</main>

<?php
echo "STEG 10: Before footer<br>";
include __DIR__ . '/../includes/layout-footer.php';
echo "STEG 11: Done<br>";
?>

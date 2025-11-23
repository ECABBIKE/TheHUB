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

// Get event ID
$eventId = isset($_GET['id']) ? intval($_GET['id']) : 0;
echo "STEG 5: Event ID = $eventId<br>";

if ($eventId <= 0) {
    die("ERROR: No event ID");
}

// Fetch event
$event = $db->getRow("SELECT * FROM events WHERE id = ?", [$eventId]);
echo "STEG 6: Event fetched - " . ($event ? $event['name'] : 'NOT FOUND') . "<br>";

if (!$event) {
    die("ERROR: Event not found");
}

// Get classes
$classes = $db->getAll("SELECT id, name FROM classes ORDER BY sort_order ASC");
echo "STEG 7: Classes fetched - " . count($classes) . " classes<br>";

echo "STEG 8: Before header<br>";

$pageTitle = 'Ticketing - ' . $event['name'];
$pageType = 'admin';
include __DIR__ . '/../includes/layout-header.php';

echo "STEG 9: After header<br>";
?>

<main class="gs-content-with-sidebar">
    <div class="gs-container">
        <h1>Event Ticketing - v2.4.2-072</h1>
        <p>Event: <?= htmlspecialchars($event['name']) ?></p>
        <p>Classes: <?= count($classes) ?></p>
        <p>Ticketing enabled: <?= $event['ticketing_enabled'] ?? 'N/A' ?></p>
        <p>Woo Product ID: <?= $event['woo_product_id'] ?? 'N/A' ?></p>

        <a href="/admin/ticketing.php" class="gs-btn gs-btn-outline">
            &larr; Tillbaka till Ticketing Dashboard
        </a>
    </div>
</main>

<?php
echo "STEG 10: Before footer<br>";
include __DIR__ . '/../includes/layout-footer.php';
echo "STEG 11: Done<br>";
?>

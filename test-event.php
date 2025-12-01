<?php
/**
 * Event & Database Debug Test
 * Tests if event 256 exists and database connection works
 * DELETE THIS FILE AFTER DEBUGGING
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/v3-config.php';

echo "<style>
body { font-family: -apple-system, sans-serif; background: #0f0f1a; color: #e5e5e5; padding: 40px; }
.card { background: #1a1a2e; border-radius: 12px; padding: 20px; margin-bottom: 20px; }
.success { color: #10B981; }
.error { color: #EF4444; }
h2 { color: #3B82F6; }
pre { background: #0f0f1a; padding: 15px; border-radius: 8px; overflow-x: auto; }
</style>";

$eventId = 256;

echo "<h1>Event Debug Test</h1>";

// Test 1: Database Connection
echo "<div class='card'>";
echo "<h2>1. Database Connection</h2>";
try {
    $db = hub_db();
    echo "<p class='success'>✓ Database connection successful</p>";
} catch (Exception $e) {
    echo "<p class='error'>✗ Database error: " . htmlspecialchars($e->getMessage()) . "</p>";
    exit;
}
echo "</div>";

// Test 2: Event Exists
echo "<div class='card'>";
echo "<h2>2. Event ID $eventId</h2>";
try {
    $stmt = $db->prepare("SELECT * FROM events WHERE id = ?");
    $stmt->execute([$eventId]);
    $event = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($event) {
        echo "<p class='success'>✓ Event found!</p>";
        echo "<pre>";
        print_r($event);
        echo "</pre>";
    } else {
        echo "<p class='error'>✗ Event ID $eventId does NOT exist in database</p>";

        // Show existing events
        echo "<h3>Available Events (most recent):</h3>";
        $stmt = $db->query("SELECT id, name, date FROM events ORDER BY id DESC LIMIT 10");
        echo "<table style='width:100%; border-collapse: collapse;'>";
        echo "<tr style='background:#2a2a4e;'><th style='padding:10px;text-align:left;'>ID</th><th style='padding:10px;text-align:left;'>Name</th><th style='padding:10px;text-align:left;'>Date</th></tr>";
        while ($row = $stmt->fetch()) {
            echo "<tr style='border-bottom:1px solid #333;'>";
            echo "<td style='padding:10px;'><a href='/calendar/{$row['id']}' style='color:#3B82F6;'>{$row['id']}</a></td>";
            echo "<td style='padding:10px;'>" . htmlspecialchars($row['name']) . "</td>";
            echo "<td style='padding:10px;'>{$row['date']}</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
} catch (Exception $e) {
    echo "<p class='error'>✗ Query error: " . htmlspecialchars($e->getMessage()) . "</p>";
}
echo "</div>";

// Test 3: File Check
echo "<div class='card'>";
echo "<h2>3. Event Page File</h2>";
$eventFile = __DIR__ . '/pages/calendar/event.php';
echo "<p>Path: <code>$eventFile</code></p>";
echo "<p>Exists: " . (file_exists($eventFile) ? "<span class='success'>YES ✓</span>" : "<span class='error'>NO ✗</span>") . "</p>";
echo "<p>Readable: " . (is_readable($eventFile) ? "<span class='success'>YES ✓</span>" : "<span class='error'>NO ✗</span>") . "</p>";
echo "</div>";

// Test 4: Total Events Count
echo "<div class='card'>";
echo "<h2>4. Database Stats</h2>";
try {
    $count = $db->query("SELECT COUNT(*) FROM events")->fetchColumn();
    echo "<p>Total events in database: <strong>$count</strong></p>";

    $upcoming = $db->query("SELECT COUNT(*) FROM events WHERE date >= CURDATE()")->fetchColumn();
    echo "<p>Upcoming events: <strong>$upcoming</strong></p>";
} catch (Exception $e) {
    echo "<p class='error'>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
}
echo "</div>";

echo "<p style='margin-top:30px; color:#666;'>⚠️ DELETE THIS FILE AFTER DEBUGGING!</p>";

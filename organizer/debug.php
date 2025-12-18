<?php
/**
 * Debug file to test organizer configuration
 */

// Enable all error reporting
ini_set('display_errors', '1');
error_reporting(E_ALL);

echo "Step 1: Loading config...<br>\n";
flush();

require_once __DIR__ . '/config.php';
echo "Step 2: Config loaded OK<br>\n";
flush();

echo "Step 3: Testing isLoggedIn()...<br>\n";
$loggedIn = isLoggedIn();
echo "Step 4: isLoggedIn() = " . ($loggedIn ? 'true' : 'false') . "<br>\n";
flush();

if (!$loggedIn) {
    echo "Not logged in. Please log in first at <a href='index.php'>index.php</a><br>\n";
    exit;
}

echo "Step 5: Testing hasRole('promotor')...<br>\n";
$hasPromotor = hasRole('promotor');
echo "Step 6: hasRole('promotor') = " . ($hasPromotor ? 'true' : 'false') . "<br>\n";
flush();

$eventId = 263;
echo "Step 7: Testing canAccessEvent($eventId)...<br>\n";
$canAccess = canAccessEvent($eventId);
echo "Step 8: canAccessEvent($eventId) = " . ($canAccess ? 'true' : 'false') . "<br>\n";
flush();

echo "Step 9: Testing getEventWithClasses($eventId)...<br>\n";
try {
    $event = getEventWithClasses($eventId);
    echo "Step 10: getEventWithClasses() returned: " . ($event ? 'event data' : 'null') . "<br>\n";

    if ($event) {
        echo "Event name: " . htmlspecialchars($event['name']) . "<br>\n";
        echo "Event date: " . htmlspecialchars($event['date']) . "<br>\n";
        echo "Classes count: " . count($event['classes'] ?? []) . "<br>\n";
        echo "Payment config: " . ($event['payment_config'] ? 'set' : 'null') . "<br>\n";

        if ($event['payment_config']) {
            echo "Swish number: " . htmlspecialchars($event['payment_config']['swish_number'] ?? 'not set') . "<br>\n";
        }
    }
} catch (Exception $e) {
    echo "Step 10: ERROR - " . htmlspecialchars($e->getMessage()) . "<br>\n";
}
flush();

echo "Step 11: Testing countEventRegistrations($eventId)...<br>\n";
try {
    $counts = countEventRegistrations($eventId);
    echo "Step 12: Counts - total: " . ($counts['total'] ?? 0) . ", onsite: " . ($counts['onsite'] ?? 0) . "<br>\n";
} catch (Exception $e) {
    echo "Step 12: ERROR - " . htmlspecialchars($e->getMessage()) . "<br>\n";
}
flush();

echo "<br>All tests completed!<br>\n";

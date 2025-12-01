<?php
/**
 * CRITICAL: Recalculate ALL points to apply class filtering
 *
 * This will set points to 0 for ALL non-point classes:
 * - Motion Kids
 * - Motion Kort
 * - Motion Mellan
 * - Sportmotion Lång
 * - Any class with awards_points=0 OR series_eligible=0
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/point-calculations.php';
require_admin();

$db = getDB();

echo "<h1>OMRÄKNAR ALLA POÄNG - Applicerar klassfiltrer</h1>";
echo "<p><strong>Detta kommer ta bort poäng från Motion Kids och andra non-point klasser!</strong></p>";

// Get all events
$events = $db->getAll("SELECT id, name FROM events WHERE active = 1 ORDER BY date DESC");

echo "<p>Hittat " . count($events) . " aktiva events att omräkna...</p>";
echo "<hr>";

$totalUpdated = 0;
$totalZeroed = 0;

foreach ($events as $event) {
    echo "<h3>Event: {$event['name']} (ID: {$event['id']})</h3>";

    // Recalculate points using the new class filtering
    $stats = recalculateEventPoints($db, $event['id']);

    // Count how many were set to zero (Motion Kids etc)
    $zeroedResults = $db->getOne("
        SELECT COUNT(*)
        FROM results r
        INNER JOIN classes c ON r.class_id = c.id
        WHERE r.event_id = ?
        AND (c.awards_points = 0 OR c.series_eligible = 0)
        AND r.points = 0
    ", [$event['id']]);

    echo "<p>✅ Uppdaterade: {$stats['updated']} resultat</p>";
    echo "<p>🚫 Poäng nollställda för non-point klasser: {$zeroedResults}</p>";

    $totalUpdated += $stats['updated'];
    $totalZeroed += $zeroedResults;

    echo "<hr>";
    flush();
}

echo "<h2>KLART!</h2>";
echo "<p><strong>Totalt uppdaterade: {$totalUpdated} resultat</strong></p>";
echo "<p><strong>Totalt nollställda: {$totalZeroed} Motion Kids/non-point resultat</strong></p>";
echo "<p><a href='/admin/dashboard' class='btn btn--primary'>Tillbaka till Dashboard</a></p>";
?>

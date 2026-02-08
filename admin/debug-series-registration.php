<?php
/**
 * Debug Series Registration Availability
 */

require_once __DIR__ . '/../hub-config.php';

$pdo = hub_db();
$eventId = intval($_GET['event_id'] ?? 344);

header('Content-Type: text/plain; charset=utf-8');

echo "SERIES REGISTRATION DEBUG - Event #$eventId\n";
echo str_repeat('=', 80) . "\n\n";

// Get event details
$stmt = $pdo->prepare("
    SELECT e.*, s.name as series_name, s.id as series_id
    FROM events e
    LEFT JOIN series_events se ON e.id = se.event_id
    LEFT JOIN series s ON se.series_id = s.id
    WHERE e.id = ?
");
$stmt->execute([$eventId]);
$event = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$event) {
    echo "ERROR: Event $eventId not found!\n";
    exit;
}

echo "EVENT INFO:\n";
echo "  Name: {$event['name']}\n";
echo "  Date: {$event['date']}\n";
echo "  Series ID: " . ($event['series_id'] ?: 'NONE') . "\n";
echo "  Series Name: " . ($event['series_name'] ?: 'NONE') . "\n";
echo "  Pricing Template: " . ($event['pricing_template_id'] ?: 'NONE') . "\n";
echo "  Registration Opens: " . ($event['registration_opens'] ?: 'NOT SET') . "\n";
echo "  Registration Closes: " . ($event['registration_closes'] ?: 'NOT SET') . "\n";
echo "\n";

// Check registration status
$now = time();
$registrationOpen = true;

if (!empty($event['registration_opens']) && strtotime($event['registration_opens']) > $now) {
    $registrationOpen = false;
    echo "❌ Registration NOT YET OPEN (opens: {$event['registration_opens']})\n";
} elseif (!empty($event['registration_closes']) && strtotime($event['registration_closes']) < $now) {
    $registrationOpen = false;
    echo "❌ Registration CLOSED (closed: {$event['registration_closes']})\n";
} else {
    echo "✓ Registration OPEN\n";
}
echo "\n";

if (!$event['series_id']) {
    echo "❌ PROBLEM: Event is NOT in a series (series_id is NULL)\n";
    echo "\nFIX: Add event to a series via series_events table\n";
    exit;
}

echo str_repeat('-', 80) . "\n";
echo "SERIES EVENTS:\n\n";

// Get all events in series
$stmt = $pdo->prepare("
    SELECT e.id, e.name, e.date, e.pricing_template_id, e.active,
           pt.name as template_name
    FROM series_events se
    JOIN events e ON se.event_id = e.id
    LEFT JOIN pricing_templates pt ON e.pricing_template_id = pt.id
    WHERE se.series_id = ?
    ORDER BY e.date
");
$stmt->execute([$event['series_id']]);
$seriesEvents = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Total events in series: " . count($seriesEvents) . "\n\n";

if (count($seriesEvents) <= 1) {
    echo "❌ PROBLEM: Series only has " . count($seriesEvents) . " event\n";
    echo "   Series registration requires at least 2 events!\n\n";
}

$eventsWithPricing = 0;
$eventsMissingPricing = [];

foreach ($seriesEvents as $se) {
    $status = $se['pricing_template_id'] ? '✓' : '❌';
    $pricing = $se['pricing_template_id']
        ? "Template #{$se['pricing_template_id']} ({$se['template_name']})"
        : "NO PRICING";
    $active = $se['active'] ? 'Active' : 'Inactive';

    echo "{$status} Event #{$se['id']} - {$se['name']}\n";
    echo "   Date: {$se['date']} | $active | $pricing\n";

    if ($se['pricing_template_id']) {
        $eventsWithPricing++;
    } else {
        $eventsMissingPricing[] = $se['id'];
    }
    echo "\n";
}

echo str_repeat('-', 80) . "\n";
echo "SUMMARY:\n\n";

$allHavePricing = (count($seriesEvents) === $eventsWithPricing);

echo "Events in series: " . count($seriesEvents) . "\n";
echo "Events with pricing: $eventsWithPricing\n";
echo "Events missing pricing: " . count($eventsMissingPricing) . "\n";
echo "\n";

if ($allHavePricing && count($seriesEvents) > 1 && $registrationOpen) {
    echo "✓✓✓ SERIES REGISTRATION SHOULD BE AVAILABLE! ✓✓✓\n";
} else {
    echo "❌ SERIES REGISTRATION NOT AVAILABLE\n\n";
    echo "Reasons:\n";

    if (count($seriesEvents) <= 1) {
        echo "  ❌ Series must have at least 2 events\n";
    }

    if (!$registrationOpen) {
        echo "  ❌ Registration is not open for this event\n";
    }

    if (!$allHavePricing) {
        echo "  ❌ Not all events have pricing templates configured\n";
        echo "     Missing pricing on event IDs: " . implode(', ', $eventsMissingPricing) . "\n";
        echo "\n";
        echo "FIX: Go to Admin > Events > Edit Event > Set Pricing Template\n";
        echo "     for each event missing pricing.\n";
    }
}

echo "\n";
echo str_repeat('=', 80) . "\n";
echo "DEBUG COMPLETE\n";

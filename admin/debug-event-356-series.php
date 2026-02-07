<?php
require_once __DIR__ . '/../config.php';
$db = getDB();

echo '<pre>';
echo "=== EVENT 356 SERIES CONNECTION DEBUG ===\n\n";

// Check series_events
$seriesEvents = $db->getAll("
    SELECT se.*, s.name as series_name, s.year
    FROM series_events se
    LEFT JOIN series s ON se.series_id = s.id
    WHERE se.event_id = 356
");

echo "Series connections: " . count($seriesEvents) . "\n\n";
foreach ($seriesEvents as $se) {
    echo "Series: {$se['series_name']} ({$se['year']})\n";
    echo "series_events.template_id: " . ($se['template_id'] ?? 'NULL') . "\n\n";
}

// Check event
$event = $db->getOne("SELECT * FROM events WHERE id = 356");
echo "Event 356:\n";
echo "Name: {$event['name']}\n";
echo "events.pricing_template_id: " . ($event['pricing_template_id'] ?? 'NULL') . "\n";
echo "events.series_id: " . ($event['series_id'] ?? 'NULL') . "\n\n";

// Check event_pricing_rules
$rules = $db->getAll("
    SELECT epr.*, c.name as class_name
    FROM event_pricing_rules epr
    LEFT JOIN classes c ON epr.class_id = c.id
    WHERE epr.event_id = 356
");

echo "Event Pricing Rules: " . count($rules) . "\n";
foreach ($rules as $rule) {
    echo "  - {$rule['class_name']}: {$rule['base_price']} kr\n";
}

echo '</pre>';
?>

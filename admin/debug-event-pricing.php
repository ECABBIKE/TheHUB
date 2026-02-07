<?php
require_once __DIR__ . '/../config.php';
$db = getDB();

$eventId = 356;

echo '<pre>';
echo "=== EVENT $eventId PRICING DEBUG ===\n\n";

// Get event
$event = $db->getOne("SELECT * FROM events WHERE id = ?", [$eventId]);
if (!$event) {
    die("Event not found!");
}

echo "Event: {$event['name']}\n";
echo "pricing_template_id: " . ($event['pricing_template_id'] ?? 'NULL') . "\n\n";

if ($event['pricing_template_id']) {
    // Get pricing template
    $template = $db->getOne("SELECT * FROM pricing_templates WHERE id = ?", [$event['pricing_template_id']]);
    if ($template) {
        echo "Pricing Template: {$template['name']}\n";
        echo "Template ID: {$template['id']}\n\n";

        // Get template rules
        $rules = $db->getAll("
            SELECT ptr.*, c.name as class_name
            FROM pricing_template_rules ptr
            LEFT JOIN classes c ON ptr.class_id = c.id
            WHERE ptr.template_id = ?
        ", [$template['id']]);

        echo "Template Rules (" . count($rules) . "):\n";
        foreach ($rules as $rule) {
            echo "  - {$rule['class_name']}: Base {$rule['base_price']} kr\n";
        }
    } else {
        echo "ERROR: Pricing template not found!\n";
    }
}

// Check event pricing rules
$eventRules = $db->getAll("SELECT * FROM event_pricing_rules WHERE event_id = ?", [$eventId]);
echo "\nEvent-specific pricing rules: " . count($eventRules) . "\n";

// Check series_events
$seriesEvent = $db->getOne("SELECT * FROM series_events WHERE event_id = ?", [$eventId]);
if ($seriesEvent) {
    echo "\nSeries Event mapping found:\n";
    echo "  Series ID: {$seriesEvent['series_id']}\n";
    echo "  Template ID: " . ($seriesEvent['template_id'] ?? 'NULL') . "\n";
}

echo '</pre>';
?>

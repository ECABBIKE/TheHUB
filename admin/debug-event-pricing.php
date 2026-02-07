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

// Check event pricing rules (without JOIN)
$eventRules = $db->getAll("SELECT * FROM event_pricing_rules WHERE event_id = ?", [$eventId]);
echo "\nEvent-specific pricing rules (raw): " . count($eventRules) . "\n";

// Check event pricing rules (WITH JOIN like frontend does)
$eventRulesWithJoin = $db->getAll("
    SELECT epr.class_id, epr.base_price, epr.early_bird_price, epr.late_fee,
           c.name as class_name, c.display_name
    FROM event_pricing_rules epr
    JOIN classes c ON epr.class_id = c.id
    WHERE epr.event_id = ?
", [$eventId]);
echo "Event-specific pricing rules (with JOIN classes): " . count($eventRulesWithJoin) . "\n";

if (count($eventRules) != count($eventRulesWithJoin)) {
    echo "\n⚠️ WARNING: Some pricing rules have invalid class_id!\n";
    echo "Checking which class_ids are missing:\n";

    foreach ($eventRules as $rule) {
        $classExists = $db->getOne("SELECT id FROM classes WHERE id = ?", [$rule['class_id']]);
        if (!$classExists) {
            echo "  - Pricing rule for class_id={$rule['class_id']} - CLASS DOES NOT EXIST!\n";
        }
    }
}

// Check series_events
$seriesEvent = $db->getOne("SELECT * FROM series_events WHERE event_id = ?", [$eventId]);
if ($seriesEvent) {
    echo "\nSeries Event mapping found:\n";
    echo "  Series ID: {$seriesEvent['series_id']}\n";
    echo "  Template ID: " . ($seriesEvent['template_id'] ?? 'NULL') . "\n";
}

echo '</pre>';
?>

<?php
require_once __DIR__ . '/../config.php';
$db = getDB();

echo '<pre>';
echo "=== SET EVENT 356 PRICING TEMPLATE ===\n\n";

// Just set pricing_template_id to 1
try {
    $db->update('events', ['pricing_template_id' => 1], 'id = ?', [356]);
    echo "✓ Set pricing_template_id = 1\n\n";
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n\n";
}

// Verify
$event = $db->getOne("SELECT id, name, pricing_template_id FROM events WHERE id = 356");
echo "Event 356:\n";
echo "  Name: {$event['name']}\n";
echo "  pricing_template_id: {$event['pricing_template_id']}\n\n";

// Get template info
if ($event['pricing_template_id']) {
    $template = $db->getOne("SELECT id, name FROM pricing_templates WHERE id = ?", [$event['pricing_template_id']]);
    echo "Template:\n";
    echo "  ID: {$template['id']}\n";
    echo "  Name: {$template['name']}\n\n";

    $rulesCount = $db->getOne("SELECT COUNT(*) as cnt FROM pricing_template_rules WHERE template_id = ?", [$template['id']]);
    echo "  Pricing rules in template: {$rulesCount['cnt']}\n";
}

echo "\nFrontend now loads pricing from template automatically!\n";
echo "Test: /event/356?tab=anmalan\n";

echo '</pre>';
?>

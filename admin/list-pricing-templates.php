<?php
require_once __DIR__ . '/../config.php';
$db = getDB();

echo '<pre>';
echo "=== ALL PRICING TEMPLATES ===\n\n";

$templates = $db->getAll("SELECT id, name, created_at FROM pricing_templates ORDER BY name");

echo "Found " . count($templates) . " templates:\n\n";

foreach ($templates as $t) {
    echo "ID: {$t['id']}\n";
    echo "Name: {$t['name']}\n";
    echo "Created: {$t['created_at']}\n";

    // Get rules for this template
    $rules = $db->getAll("
        SELECT ptr.*, c.name as class_name
        FROM pricing_template_rules ptr
        LEFT JOIN classes c ON ptr.class_id = c.id
        WHERE ptr.template_id = ?
    ", [$t['id']]);

    echo "Rules: " . count($rules) . "\n";
    if (count($rules) > 0) {
        echo "Classes: ";
        $classNames = array_map(function($r) { return $r['class_name']; }, $rules);
        echo implode(', ', $classNames) . "\n";
    }
    echo "\n---\n\n";
}

echo "\n=== EVENT 356 CURRENT STATE ===\n\n";
$event = $db->getOne("SELECT id, name, pricing_template_id FROM events WHERE id = 356");
echo "Event: {$event['name']}\n";
echo "pricing_template_id: " . ($event['pricing_template_id'] ?? 'NULL') . "\n";

echo '</pre>';
?>

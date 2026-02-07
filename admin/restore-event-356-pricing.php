<?php
require_once __DIR__ . '/../config.php';
$db = getDB();

echo '<html><head><meta charset="UTF-8"><title>Restore Event 356 Pricing</title></head>';
echo '<body style="font-family: system-ui; padding: 40px; background: #f5f5f5;">';
echo '<div style="background: white; padding: 30px; border-radius: 8px; max-width: 900px; margin: 0 auto;">';
echo '<h1>🔧 Restore Event 356 Pricing</h1>';

$templateId = 1; // Götaland Gravity Series

// Get template info
$template = $db->getOne("SELECT * FROM pricing_templates WHERE id = ?", [$templateId]);
if (!$template) {
    echo '<p style="color: red;">ERROR: Template not found!</p>';
    exit;
}

echo '<h2>1. Using template</h2>';
echo '<p>Template: ' . htmlspecialchars($template['name']) . '</p>';

// Set pricing_template_id
echo '<h2>2. Setting pricing_template_id</h2>';
try {
    $db->update('events', ['pricing_template_id' => $templateId], 'id = ?', [356]);
    echo '<p style="color: green;">✓ Set pricing_template_id to ' . $templateId . '</p>';
} catch (Exception $e) {
    echo '<p style="color: red;">✗ Error: ' . htmlspecialchars($e->getMessage()) . '</p>';
}

// Copy template rules to event_pricing_rules
echo '<h2>3. Creating event_pricing_rules from template</h2>';

// Get template rules
$templateRules = $db->getAll("
    SELECT class_id, base_price, early_bird_price, late_fee
    FROM pricing_template_rules
    WHERE template_id = ?
", [$templateId]);

echo '<p>Found ' . count($templateRules) . ' pricing rules in template</p>';

// Delete old rules first
$db->query("DELETE FROM event_pricing_rules WHERE event_id = 356");

// Insert new rules
$inserted = 0;
foreach ($templateRules as $rule) {
    try {
        $db->insert('event_pricing_rules', [
            'event_id' => 356,
            'class_id' => $rule['class_id'],
            'base_price' => $rule['base_price'],
            'early_bird_price' => $rule['early_bird_price'],
            'late_fee' => $rule['late_fee']
        ]);
        $inserted++;
    } catch (Exception $e) {
        echo '<p style="color: red;">✗ Failed to insert rule for class_id ' . $rule['class_id'] . ': ' . htmlspecialchars($e->getMessage()) . '</p>';
    }
}

echo '<p style="color: green;">✓ Created ' . $inserted . ' pricing rules</p>';

// Verify
echo '<h2>4. Verification</h2>';
$event = $db->getOne("SELECT id, name, pricing_template_id FROM events WHERE id = 356");
$rulesCount = $db->getOne("SELECT COUNT(*) as cnt FROM event_pricing_rules WHERE event_id = 356");

echo '<table style="width: 100%; border-collapse: collapse; margin: 10px 0;">';
echo '<tr><td style="padding: 8px; border: 1px solid #ddd;"><strong>Event:</strong></td>';
echo '<td style="padding: 8px; border: 1px solid #ddd;">' . htmlspecialchars($event['name']) . '</td></tr>';
echo '<tr><td style="padding: 8px; border: 1px solid #ddd;"><strong>pricing_template_id:</strong></td>';
echo '<td style="padding: 8px; border: 1px solid #ddd;">' . $event['pricing_template_id'] . '</td></tr>';
echo '<tr><td style="padding: 8px; border: 1px solid #ddd;"><strong>Pricing rules:</strong></td>';
echo '<td style="padding: 8px; border: 1px solid #ddd;">' . $rulesCount['cnt'] . '</td></tr>';
echo '</table>';

// Show pricing
$pricing = $db->getAll("
    SELECT epr.*, c.name as class_name
    FROM event_pricing_rules epr
    LEFT JOIN classes c ON epr.class_id = c.id
    WHERE epr.event_id = 356
    ORDER BY c.sort_order, c.name
");

echo '<h3>Pricing Rules:</h3>';
echo '<table style="width: 100%; border-collapse: collapse; margin: 10px 0; font-size: 0.9rem;">';
echo '<tr><th style="padding: 8px; border: 1px solid #ddd; text-align: left;">Klass</th>';
echo '<th style="padding: 8px; border: 1px solid #ddd; text-align: right;">Early Bird</th>';
echo '<th style="padding: 8px; border: 1px solid #ddd; text-align: right;">Ordinarie</th>';
echo '<th style="padding: 8px; border: 1px solid #ddd; text-align: right;">Efteranmälan</th></tr>';

foreach ($pricing as $p) {
    echo '<tr>';
    echo '<td style="padding: 8px; border: 1px solid #ddd;">' . htmlspecialchars($p['class_name']) . '</td>';
    echo '<td style="padding: 8px; border: 1px solid #ddd; text-align: right;">' . number_format($p['early_bird_price'], 0, ',', ' ') . ' kr</td>';
    echo '<td style="padding: 8px; border: 1px solid #ddd; text-align: right;">' . number_format($p['base_price'], 0, ',', ' ') . ' kr</td>';
    echo '<td style="padding: 8px; border: 1px solid #ddd; text-align: right;">' . number_format($p['late_fee'], 0, ',', ' ') . ' kr</td>';
    echo '</tr>';
}
echo '</table>';

echo '<p style="margin-top: 30px;"><a href="/event/356?tab=anmalan" style="display: inline-block; padding: 12px 24px; background: #635bff; color: white; text-decoration: none; border-radius: 6px;">→ TEST EVENT 356 NU!</a></p>';

echo '</div></body></html>';
?>

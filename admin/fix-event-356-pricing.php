<?php
require_once __DIR__ . '/../config.php';
$db = getDB();

echo '<html><head><meta charset="UTF-8"><title>Fix Event 356 Pricing</title></head>';
echo '<body style="font-family: system-ui; padding: 40px; background: #f5f5f5;">';
echo '<div style="background: white; padding: 30px; border-radius: 8px; max-width: 800px; margin: 0 auto;">';
echo '<h1>🔧 Fix Event 356 Pricing</h1>';

// Find Götaland Gravity Series pricing template
$template = $db->getOne("SELECT id, name FROM pricing_templates WHERE name LIKE '%Götaland%Gravity%'");

if (!$template) {
    echo '<p style="color: red;">ERROR: Could not find "Götaland Gravity Series" pricing template!</p>';

    // Show all templates
    echo '<h2>Available pricing templates:</h2>';
    $templates = $db->getAll("SELECT id, name FROM pricing_templates ORDER BY name");
    echo '<ul>';
    foreach ($templates as $t) {
        echo '<li>ID: ' . $t['id'] . ', Name: ' . htmlspecialchars($t['name']) . '</li>';
    }
    echo '</ul>';
    exit;
}

echo '<h2>1. Found pricing template</h2>';
echo '<p>Template ID: ' . $template['id'] . ', Name: ' . htmlspecialchars($template['name']) . '</p>';

// Update Event 356 pricing_template_id
echo '<h2>2. Updating events.pricing_template_id</h2>';
try {
    $db->update('events', [
        'pricing_template_id' => $template['id']
    ], 'id = ?', [356]);
    echo '<p style="color: green;">✓ Event 356 pricing_template_id set to ' . $template['id'] . '</p>';
} catch (Exception $e) {
    echo '<p style="color: red;">✗ Error: ' . htmlspecialchars($e->getMessage()) . '</p>';
}

// Delete old event_pricing_rules
echo '<h2>3. Cleaning up old event_pricing_rules</h2>';
try {
    $db->query("DELETE FROM event_pricing_rules WHERE event_id = 356");
    echo '<p style="color: green;">✓ Deleted old pricing rules</p>';
} catch (Exception $e) {
    echo '<p style="color: red;">✗ Error: ' . htmlspecialchars($e->getMessage()) . '</p>';
}

// Verify
echo '<h2>4. Verification</h2>';
$event = $db->getOne("SELECT id, name, pricing_template_id FROM events WHERE id = 356");
echo '<table style="width: 100%; border-collapse: collapse; margin: 10px 0;">';
echo '<tr><td style="padding: 8px; border: 1px solid #ddd;"><strong>Event:</strong></td>';
echo '<td style="padding: 8px; border: 1px solid #ddd;">' . htmlspecialchars($event['name']) . '</td></tr>';
echo '<tr><td style="padding: 8px; border: 1px solid #ddd;"><strong>pricing_template_id:</strong></td>';
echo '<td style="padding: 8px; border: 1px solid #ddd;">' . ($event['pricing_template_id'] ?? 'NULL') . '</td></tr>';
echo '</table>';

$rulesCount = $db->getOne("SELECT COUNT(*) as cnt FROM event_pricing_rules WHERE event_id = 356");
echo '<p>Event-specific pricing rules remaining: ' . $rulesCount['cnt'] . '</p>';

echo '<p style="margin-top: 30px;"><a href="/event/356?tab=anmalan" style="display: inline-block; padding: 12px 24px; background: #635bff; color: white; text-decoration: none; border-radius: 6px;">→ TEST EVENT 356 NU!</a></p>';

echo '</div></body></html>';
?>

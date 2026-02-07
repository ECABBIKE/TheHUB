<?php
/**
 * Fix EVERYTHING - Event 356 + ECAB status
 */
require_once __DIR__ . '/../config.php';
$db = getDB();

echo '<html><head><meta charset="UTF-8"><title>Fix All</title></head>';
echo '<body style="font-family: system-ui; padding: 40px; background: #f5f5f5;">';
echo '<div style="background: white; padding: 30px; border-radius: 8px; max-width: 800px; margin: 0 auto;">';
echo '<h1>🔧 Fixar ALLT</h1>';

echo '<h2>1. Uppdaterar ECAB status till active</h2>';
try {
    $db->update('payment_recipients', [
        'stripe_account_status' => 'active'
    ], 'id = ?', [2]);
    echo '<p style="color: green;">✓ ECAB är nu active</p>';
} catch (Exception $e) {
    echo '<p style="color: red;">✗ Fel: ' . htmlspecialchars($e->getMessage()) . '</p>';
}

echo '<h2>2. Sätter Event 356 payment_recipient_id = 2 (ECAB)</h2>';
try {
    $result = $db->query("UPDATE events SET payment_recipient_id = 2 WHERE id = 356");
    echo '<p style="color: green;">✓ Event 356 kopplat till ECAB</p>';
} catch (Exception $e) {
    echo '<p style="color: red;">✗ Fel: ' . htmlspecialchars($e->getMessage()) . '</p>';
}

echo '<h2>3. Verifierar:</h2>';
$event = $db->getOne("
    SELECT e.id, e.name, e.payment_recipient_id,
           pr.name as recipient_name, pr.stripe_account_id, pr.stripe_account_status
    FROM events e
    LEFT JOIN payment_recipients pr ON e.payment_recipient_id = pr.id
    WHERE e.id = 356
");

if ($event) {
    echo '<table style="width: 100%; border-collapse: collapse; margin: 10px 0;">';
    echo '<tr><td style="padding: 8px; border: 1px solid #ddd;"><strong>Event ID:</strong></td>';
    echo '<td style="padding: 8px; border: 1px solid #ddd;">356</td></tr>';
    echo '<tr><td style="padding: 8px; border: 1px solid #ddd;"><strong>Payment Recipient:</strong></td>';
    echo '<td style="padding: 8px; border: 1px solid #ddd;">' . htmlspecialchars($event['recipient_name'] ?? 'NULL') . '</td></tr>';
    echo '<tr><td style="padding: 8px; border: 1px solid #ddd;"><strong>Stripe Account:</strong></td>';
    echo '<td style="padding: 8px; border: 1px solid #ddd;"><code>' . htmlspecialchars($event['stripe_account_id'] ?? 'NULL') . '</code></td></tr>';
    echo '<tr><td style="padding: 8px; border: 1px solid #ddd;"><strong>Status:</strong></td>';
    echo '<td style="padding: 8px; border: 1px solid #ddd;"><strong>' . htmlspecialchars($event['stripe_account_status'] ?? 'NULL') . '</strong></td></tr>';
    echo '</table>';
}

echo '<p style="margin-top: 30px;"><a href="/event/356?id=356&tab=anmalan" style="display: inline-block; padding: 12px 24px; background: #635bff; color: white; text-decoration: none; border-radius: 6px;">→ TESTA EVENT 356 NU!</a></p>';

echo '</div></body></html>';
?>

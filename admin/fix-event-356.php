<?php
/**
 * Fix Event 356 - Set payment recipient to ECAB
 */
require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();

echo '<html><head><meta charset="UTF-8"><title>Fix Event 356</title></head>';
echo '<body style="font-family: system-ui; padding: 40px; background: #f5f5f5;">';
echo '<div style="background: white; padding: 30px; border-radius: 8px; max-width: 800px; margin: 0 auto;">';
echo '<h1>Fixar Event 356</h1>';

// Set payment recipient for event 356
try {
    $db->update('events', [
        'payment_recipient_id' => 2  // ECAB
    ], 'id = ?', [356]);

    echo '<div style="background: #d1fae5; padding: 15px; border-radius: 6px; margin: 20px 0;">';
    echo '<strong style="color: #065f46;">✓ Klart!</strong><br>';
    echo 'Event 356 har nu ECAB som betalningsmottagare.';
    echo '</div>';

    // Show updated info
    $event = $db->getOne("
        SELECT e.id, e.name, e.payment_recipient_id,
               pr.name as recipient_name, pr.stripe_account_id
        FROM events e
        LEFT JOIN payment_recipients pr ON e.payment_recipient_id = pr.id
        WHERE e.id = 356
    ");

    echo '<h3>Uppdaterad info:</h3>';
    echo '<table style="width: 100%; border-collapse: collapse;">';
    echo '<tr><td style="padding: 8px; border: 1px solid #ddd;"><strong>Event ID:</strong></td><td style="padding: 8px; border: 1px solid #ddd;">356</td></tr>';
    echo '<tr><td style="padding: 8px; border: 1px solid #ddd;"><strong>Mottagare:</strong></td><td style="padding: 8px; border: 1px solid #ddd;">' . htmlspecialchars($event['recipient_name'] ?? 'Ingen') . '</td></tr>';
    echo '<tr><td style="padding: 8px; border: 1px solid #ddd;"><strong>Stripe Account:</strong></td><td style="padding: 8px; border: 1px solid #ddd;"><code>' . htmlspecialchars($event['stripe_account_id'] ?? 'NULL') . '</code></td></tr>';
    echo '</table>';

} catch (Exception $e) {
    echo '<div style="background: #fee; padding: 15px; border-radius: 6px; color: #c00;">';
    echo '<strong>✗ Fel:</strong> ' . htmlspecialchars($e->getMessage());
    echo '</div>';
}

echo '<p style="margin-top: 20px;"><a href="/event/356?id=356&tab=anmalan" style="display: inline-block; padding: 12px 24px; background: #635bff; color: white; text-decoration: none; border-radius: 6px;">→ Testa eventet</a></p>';

echo '</div></body></html>';
?>

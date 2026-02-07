<?php
require_once __DIR__ . '/../config.php';
$db = getDB();

echo '<pre>';
echo "=== PAYMENT RECIPIENTS (RAW) ===\n\n";
$recipients = $db->getAll("SELECT * FROM payment_recipients ORDER BY id");
foreach ($recipients as $r) {
    echo "ID: {$r['id']}\n";
    echo "Name: {$r['name']}\n";
    echo "Stripe Account ID: " . ($r['stripe_account_id'] ?? 'NULL') . "\n";
    echo "Status: " . ($r['stripe_account_status'] ?? 'NULL') . "\n";
    echo "Platform Fee: " . ($r['platform_fee_percent'] ?? 'NULL') . "\n";
    echo "---\n";
}

echo "\n=== EVENT 356 ===\n\n";
$event = $db->getOne("SELECT * FROM events WHERE id = 356");
if ($event) {
    echo "ID: {$event['id']}\n";
    echo "Name: {$event['name']}\n";
    echo "Payment Recipient ID: " . ($event['payment_recipient_id'] ?? 'NULL') . "\n";
} else {
    echo "Event 356 not found!\n";
}

echo '</pre>';
?>

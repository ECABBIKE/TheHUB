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
    echo "FOUND EVENT 356:\n";
    foreach ($event as $key => $value) {
        echo "$key: " . ($value ?? 'NULL') . "\n";
    }
} else {
    echo "Event 356 NOT FOUND in database!\n";
}

echo "\n=== ALL EVENTS (limited) ===\n\n";
$events = $db->getAll("SELECT id, name, payment_recipient_id FROM events ORDER BY id DESC LIMIT 5");
foreach ($events as $e) {
    echo "ID: {$e['id']}, Name: {$e['name']}, Recipient: " . ($e['payment_recipient_id'] ?? 'NULL') . "\n";
}

echo '</pre>';
?>

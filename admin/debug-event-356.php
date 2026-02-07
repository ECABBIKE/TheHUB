<?php
require_once __DIR__ . '/../config.php';
$db = getDB();

echo '<pre>';
echo "=== EVENT 356 DEBUG ===\n\n";

// Get event details
$event = $db->getOne("SELECT * FROM events WHERE id = 356");
if ($event) {
    echo "Event: {$event['name']}\n";
    echo "Payment Recipient ID: " . ($event['payment_recipient_id'] ?? 'NULL') . "\n\n";

    if ($event['payment_recipient_id']) {
        $recipient = $db->getOne("SELECT * FROM payment_recipients WHERE id = ?", [$event['payment_recipient_id']]);
        if ($recipient) {
            echo "Recipient: {$recipient['name']}\n";
            echo "Stripe Account ID: " . ($recipient['stripe_account_id'] ?? 'NULL') . "\n";
            echo "Status: " . ($recipient['stripe_account_status'] ?? 'NULL') . "\n";
            echo "Platform Fee: " . ($recipient['platform_fee_percent'] ?? '2.00') . "%\n";
        }
    }
} else {
    echo "Event 356 not found!\n";
}

echo "\n=== STRIPE CONFIG ===\n";
echo "Stripe Secret Key: " . (env('STRIPE_SECRET_KEY') ? substr(env('STRIPE_SECRET_KEY'), 0, 20) . '...' : 'NOT SET') . "\n";
echo "Stripe Publishable Key: " . (env('STRIPE_PUBLISHABLE_KEY') ? substr(env('STRIPE_PUBLISHABLE_KEY'), 0, 20) . '...' : 'NOT SET') . "\n";

echo "\n=== TEST ORDER ===\n";
$testOrder = $db->getOne("SELECT * FROM orders WHERE event_id = 356 ORDER BY id DESC LIMIT 1");
if ($testOrder) {
    echo "Latest order: {$testOrder['order_number']}\n";
    echo "Status: {$testOrder['payment_status']}\n";
    echo "Amount: {$testOrder['total_amount']} SEK\n";
} else {
    echo "No orders found for event 356\n";
}

echo '</pre>';
?>

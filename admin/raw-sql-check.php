<?php
require_once __DIR__ . '/../config.php';
$pdo = $GLOBALS['pdo'];

echo '<pre>';
echo "=== DIRECT SQL CHECK ===\n\n";

// Check event 356
echo "Event 356:\n";
$stmt = $pdo->query("SELECT * FROM events WHERE id = 356");
$event = $stmt->fetch(PDO::FETCH_ASSOC);
if ($event) {
    echo "ID: " . ($event['id'] ?? 'NULL') . "\n";
    echo "Name: " . ($event['name'] ?? 'NULL') . "\n";
    echo "payment_recipient_id: " . ($event['payment_recipient_id'] ?? 'NULL') . "\n";
    echo "\nAll columns:\n";
    print_r(array_keys($event));
} else {
    echo "Event 356 NOT FOUND!\n";
}

echo "\n\n";

// Check payment_recipients id=2
echo "Payment Recipient ID=2:\n";
$stmt = $pdo->query("SELECT * FROM payment_recipients WHERE id = 2");
$recipient = $stmt->fetch(PDO::FETCH_ASSOC);
if ($recipient) {
    echo "ID: " . ($recipient['id'] ?? 'NULL') . "\n";
    echo "Name: " . ($recipient['name'] ?? 'NULL') . "\n";
    echo "stripe_account_id: " . ($recipient['stripe_account_id'] ?? 'NULL') . "\n";
    echo "stripe_account_status: " . ($recipient['stripe_account_status'] ?? 'NULL') . "\n";
} else {
    echo "Recipient ID=2 NOT FOUND!\n";
}

echo "\n\n";

// Try UPDATE directly
echo "Trying UPDATE:\n";
$stmt = $pdo->prepare("UPDATE events SET payment_recipient_id = ? WHERE id = ?");
$result = $stmt->execute([2, 356]);
echo "Result: " . ($result ? 'SUCCESS' : 'FAILED') . "\n";
echo "Rows affected: " . $stmt->rowCount() . "\n";

echo '</pre>';
?>

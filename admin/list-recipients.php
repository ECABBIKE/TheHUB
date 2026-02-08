<?php
require_once __DIR__ . '/../config.php';

if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    die('Admin access required');
}

$pdo = $GLOBALS['pdo'];

$stmt = $pdo->query("SELECT id, name, email, stripe_account_id, stripe_account_status FROM payment_recipients ORDER BY id");
$recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: text/plain');
echo "Payment Recipients:\n\n";

foreach ($recipients as $r) {
    echo "ID: {$r['id']} | {$r['name']} | {$r['email']} | Stripe: " . ($r['stripe_account_id'] ?? 'none') . "\n";
}

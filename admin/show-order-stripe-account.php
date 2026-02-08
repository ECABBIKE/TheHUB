<?php
require_once __DIR__ . '/../config.php';

if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    die('Admin access required');
}

$orderId = intval($_GET['order_id'] ?? 0);

if (!$orderId) {
    die('Ange ?order_id=X');
}

$pdo = $GLOBALS['pdo'];

$stmt = $pdo->prepare("
    SELECT
        o.id as order_id,
        o.order_number,
        o.total_amount,
        e.name as event_name,
        pr.id as recipient_id,
        pr.name as recipient_name,
        pr.email as recipient_email,
        pr.stripe_account_id,
        pr.stripe_account_status
    FROM orders o
    LEFT JOIN events e ON o.event_id = e.id
    LEFT JOIN payment_recipients pr ON o.payment_recipient_id = pr.id
    WHERE o.id = ?
");
$stmt->execute([$orderId]);
$result = $stmt->fetch(PDO::FETCH_ASSOC);

header('Content-Type: text/plain; charset=utf-8');

if (!$result) {
    echo "Order $orderId hittades inte\n";
    exit;
}

echo "ORDER INFORMATION\n";
echo "================\n\n";
echo "Order ID: {$result['order_id']}\n";
echo "Order Number: {$result['order_number']}\n";
echo "Event: {$result['event_name']}\n";
echo "Belopp: {$result['total_amount']} kr\n\n";

if ($result['stripe_account_id']) {
    echo "STRIPE CONNECTED ACCOUNT\n";
    echo "========================\n\n";
    echo "Recipient ID: {$result['recipient_id']}\n";
    echo "Recipient Name: {$result['recipient_name']}\n";
    echo "Recipient Email: {$result['recipient_email']}\n";
    echo "Stripe Account ID: {$result['stripe_account_id']}\n";
    echo "Status: {$result['stripe_account_status']}\n\n";
    echo "DETTA är kontot som används för betalningen.\n";
    echo "Business name på detta konto visas i checkout.\n\n";
    echo "Ändra i Stripe Dashboard:\n";
    echo "https://dashboard.stripe.com/connect/accounts/{$result['stripe_account_id']}\n";
} else {
    echo "INGEN CONNECTED ACCOUNT\n";
    echo "=======================\n\n";
    echo "Denna order använder INTE Stripe Connect.\n";
    echo "Betalning går direkt till plattformskontot.\n";
}

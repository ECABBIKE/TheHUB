<?php
/**
 * Check Order Payment Status API
 *
 * Returns the payment_status of an order.
 * If order has a Stripe session and is still pending, verifies with Stripe directly
 * and processes payment if Stripe confirms it was paid (webhook fallback).
 */

require_once dirname(__DIR__) . '/hub-config.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$orderId = intval($_GET['order_id'] ?? 0);

if (!$orderId) {
    echo json_encode(['payment_status' => 'unknown']);
    exit;
}

$pdo = hub_db();

// Get order with Stripe session info
$stmt = $pdo->prepare("
    SELECT payment_status, gateway_code, gateway_transaction_id
    FROM orders WHERE id = ?
");
$stmt->execute([$orderId]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    echo json_encode(['payment_status' => 'unknown']);
    exit;
}

$status = $order['payment_status'];

// If order is still pending and has a Stripe session, verify with Stripe directly
// This is the fallback for when webhooks fail (302 redirect, timeout, etc.)
if ($status === 'pending' && $order['gateway_code'] === 'stripe' && !empty($order['gateway_transaction_id'])) {
    $stripeKey = defined('STRIPE_SECRET_KEY') ? STRIPE_SECRET_KEY : (function_exists('env') ? env('STRIPE_SECRET_KEY', '') : '');

    if (!empty($stripeKey)) {
        try {
            require_once dirname(__DIR__) . '/includes/payment/StripeClient.php';
            $stripe = new \TheHUB\Payment\StripeClient($stripeKey);

            // Retrieve the checkout session from Stripe
            $sessionId = $order['gateway_transaction_id'];
            $session = $stripe->request('GET', '/checkout/sessions/' . $sessionId);

            if (!empty($session['payment_status']) && $session['payment_status'] === 'paid') {
                // Stripe confirms payment! Process it now (webhook fallback)
                $paymentIntentId = $session['payment_intent'] ?? '';

                require_once dirname(__DIR__) . '/includes/payment.php';

                if (markOrderPaid($orderId, $paymentIntentId)) {
                    $status = 'paid';
                    error_log("check-order-status: Webhook fallback - confirmed payment for order {$orderId} via Stripe session check");
                }
            }
        } catch (\Throwable $e) {
            error_log("check-order-status: Stripe verification error for order {$orderId}: " . $e->getMessage());
            // Don't fail - just return current DB status
        }
    }
}

echo json_encode([
    'payment_status' => $status ?: 'unknown'
]);

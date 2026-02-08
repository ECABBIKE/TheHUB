<?php
/**
 * Create Mollie Payment
 *
 * Creates a Mollie payment for an order and returns the checkout URL.
 * Called from the checkout page when user clicks "Gå till betalning".
 * Supports Swish and card payments.
 *
 * POST params:
 *   order_id - Order ID to pay for
 *
 * Returns JSON: { success, url, payment_id, error }
 */

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/payment/MollieClient.php';

use TheHUB\Payment\MollieClient;

$pdo = $GLOBALS['pdo'];

// Allow both logged-in and guest users to create checkout sessions
// Order contains customer email and name for receipts

$orderId = intval($_POST['order_id'] ?? 0);
if (!$orderId) {
    echo json_encode(['success' => false, 'error' => 'Order-ID saknas']);
    exit;
}

// Get Mollie API key
$mollieKey = env('MOLLIE_API_KEY', '');
if (empty($mollieKey)) {
    echo json_encode(['success' => false, 'error' => 'Mollie ej konfigurerat']);
    exit;
}

try {
    // Get order details
    $stmt = $pdo->prepare("
        SELECT o.*,
               e.name as event_name,
               r.firstname, r.lastname, r.id as rider_id
        FROM orders o
        LEFT JOIN events e ON o.event_id = e.id
        LEFT JOIN riders r ON o.rider_id = r.id
        WHERE o.id = ?
    ");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        echo json_encode(['success' => false, 'error' => 'Order hittades inte']);
        exit;
    }

    if ($order['payment_status'] === 'paid') {
        echo json_encode(['success' => false, 'error' => 'Ordern är redan betald']);
        exit;
    }

    // Build payment description from order items
    $stmt = $pdo->prepare("SELECT * FROM order_items WHERE order_id = ?");
    $stmt->execute([$orderId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Create description
    $description = 'Anmälan';
    if (!empty($order['event_name'])) {
        $description .= ' - ' . $order['event_name'];
    }
    if (count($items) > 0) {
        $description .= ' (' . count($items) . ' st)';
    }

    // Build URLs
    $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];

    $mollie = new MollieClient($mollieKey);

    // Create Mollie payment
    // All payments go to platform account, manual payouts to organizers
    $paymentData = [
        'amount' => (float)$order['total_amount'],
        'currency' => 'SEK',
        'description' => $description,
        'redirectUrl' => $baseUrl . '/checkout?order=' . $orderId,
        'webhookUrl' => $baseUrl . '/webhooks/mollie-webhook.php',
        'metadata' => [
            'order_id' => (string)$orderId,
            'order_number' => $order['order_number'] ?? '',
            'rider_id' => (string)($order['rider_id'] ?? ''),
            'event_id' => (string)($order['event_id'] ?? ''),
            'payment_recipient_id' => (string)($order['payment_recipient_id'] ?? '')  // För rapportering
        ]
    ];

    $response = $mollie->createPayment($paymentData);

    if (!$response['success']) {
        error_log("Mollie payment error: " . ($response['error'] ?? 'Unknown error'));
        echo json_encode([
            'success' => false,
            'error' => $response['error'] ?? 'Mollie-fel'
        ]);
        exit;
    }

    $paymentId = $response['payment_id'];
    $checkoutUrl = $response['checkout_url'];

    if (!$checkoutUrl) {
        echo json_encode(['success' => false, 'error' => 'Kunde inte skapa betalningssession']);
        exit;
    }

    // Store payment ID on order for webhook matching
    // gateway_transaction_id is used by mollie-webhook.php to find the order
    $updateSql = "UPDATE orders SET payment_method = 'mollie', updated_at = NOW()";
    $updateParams = [];

    // Set gateway fields (used by webhook to match the order)
    $updateSql .= ", gateway_code = 'mollie', gateway_transaction_id = ?";
    $updateParams[] = $paymentId;

    $updateSql .= " WHERE id = ?";
    $updateParams[] = $orderId;

    $stmt = $pdo->prepare($updateSql);
    $stmt->execute($updateParams);

    echo json_encode([
        'success' => true,
        'url' => $checkoutUrl,
        'payment_id' => $paymentId
    ]);

} catch (Exception $e) {
    error_log("create-checkout-session error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Serverfel: ' . $e->getMessage()]);
}

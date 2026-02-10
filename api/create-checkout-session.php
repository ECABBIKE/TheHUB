<?php
/**
 * Create Stripe Checkout Session (Direct Platform Charges)
 *
 * Creates a Stripe Checkout Session for an order and returns the URL.
 * All payments go directly to the platform's single Stripe account.
 *
 * POST params:
 *   order_id - Order ID to pay for
 *
 * Returns JSON: { success, url, error }
 */

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/payment/StripeClient.php';

use TheHUB\Payment\StripeClient;

$pdo = $GLOBALS['pdo'];

// Allow both logged-in and guest users to create checkout sessions
// Order contains customer email and name for receipts

$orderId = intval($_POST['order_id'] ?? 0);
if (!$orderId) {
    echo json_encode(['success' => false, 'error' => 'Order-ID saknas']);
    exit;
}

// Get Stripe key
$stripeKey = env('STRIPE_SECRET_KEY', '');
if (empty($stripeKey)) {
    echo json_encode(['success' => false, 'error' => 'Stripe ej konfigurerat']);
    exit;
}

$publishableKey = env('STRIPE_PUBLISHABLE_KEY', '');

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

    // Get order items for line_items
    $stmt = $pdo->prepare("SELECT * FROM order_items WHERE order_id = ?");
    $stmt->execute([$orderId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Build Stripe line items
    $lineItems = [];
    foreach ($items as $item) {
        $lineItems[] = [
            'price_data' => [
                'currency' => 'sek',
                'unit_amount' => (int)($item['total_price'] * 100),
                'product_data' => [
                    'name' => $item['description'] ?: ('Anmälan - ' . ($order['event_name'] ?? 'Event'))
                ]
            ],
            'quantity' => 1
        ];
    }

    // If no items, use total amount
    if (empty($lineItems)) {
        $lineItems[] = [
            'price_data' => [
                'currency' => 'sek',
                'unit_amount' => (int)($order['total_amount'] * 100),
                'product_data' => [
                    'name' => $order['event_name'] ? 'Anmälan - ' . $order['event_name'] : 'Betalning'
                ]
            ],
            'quantity' => 1
        ];
    }

    // Build checkout session params
    // All payments go directly to the platform's single Stripe account
    $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];

    $stripe = new StripeClient($stripeKey);

    $sessionParams = [
        'mode' => 'payment',
        'line_items' => $lineItems,
        'success_url' => $baseUrl . '/checkout?order=' . $orderId . '&stripe_success=1',
        'cancel_url' => $baseUrl . '/checkout?order=' . $orderId . '&stripe_cancelled=1',
        'payment_method_types' => ['card'],
        'metadata' => [
            'order_id' => $orderId,
            'order_number' => $order['order_number'] ?? '',
            'rider_id' => $order['rider_id'] ?? '',
            'event_id' => $order['event_id'] ?? ''
        ],
        'payment_intent_data' => [
            'statement_descriptor' => 'THEHUB EVENT',
            'metadata' => [
                'order_id' => $orderId,
                'order_number' => $order['order_number'] ?? ''
            ]
        ]
    ];

    // Add customer email if available
    if (!empty($order['customer_email'])) {
        $sessionParams['customer_email'] = $order['customer_email'];
    }

    // Create Checkout Session via Stripe API
    $response = $stripe->request('POST', '/checkout/sessions', $sessionParams);

    if (isset($response['error'])) {
        error_log("Stripe Checkout error: " . json_encode($response['error']));
        echo json_encode([
            'success' => false,
            'error' => $response['error']['message'] ?? 'Stripe-fel'
        ]);
        exit;
    }

    $sessionId = $response['id'] ?? null;
    $checkoutUrl = $response['url'] ?? null;

    if (!$checkoutUrl) {
        echo json_encode(['success' => false, 'error' => 'Kunde inte skapa betalningssession']);
        exit;
    }

    // Store session ID on order for webhook matching
    // gateway_transaction_id is used by stripe-webhook.php to find the order
    $updateSql = "UPDATE orders SET payment_method = 'card', updated_at = NOW()";
    $updateParams = [];

    // Set gateway fields (used by webhook to match the order)
    $updateSql .= ", gateway_code = 'stripe', gateway_transaction_id = ?";
    $updateParams[] = $sessionId;

    // Also set stripe_session_id if column exists
    try {
        $chk = $pdo->query("SHOW COLUMNS FROM orders LIKE 'stripe_session_id'");
        if (count($chk->fetchAll()) > 0) {
            $updateSql .= ", stripe_session_id = ?";
            $updateParams[] = $sessionId;
        }
    } catch (Exception $e) {}

    // Store payment_intent_id if available
    $paymentIntentId = $response['payment_intent'] ?? null;
    if ($paymentIntentId) {
        try {
            $chk = $pdo->query("SHOW COLUMNS FROM orders LIKE 'stripe_payment_intent_id'");
            if (count($chk->fetchAll()) > 0) {
                $updateSql .= ", stripe_payment_intent_id = ?";
                $updateParams[] = $paymentIntentId;
            }
        } catch (Exception $e) {}
    }

    $updateSql .= " WHERE id = ?";
    $updateParams[] = $orderId;

    $stmt = $pdo->prepare($updateSql);
    $stmt->execute($updateParams);

    echo json_encode([
        'success' => true,
        'url' => $checkoutUrl,
        'session_id' => $sessionId
    ]);

} catch (Exception $e) {
    error_log("create-checkout-session error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Serverfel: ' . $e->getMessage()]);
}

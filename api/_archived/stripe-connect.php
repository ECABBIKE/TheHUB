<?php
/**
 * Stripe Connect API Endpoints
 *
 * Handles connected account creation, onboarding, and checkout sessions.
 *
 * Endpoints:
 * POST ?action=create_account - Create new connected account
 * POST ?action=create_account_link - Create onboarding link
 * POST ?action=create_checkout_session - Create checkout session for payment
 * GET ?action=account_status&account_id=xxx - Get account status
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/payment/StripeClient.php';

use TheHUB\Payment\StripeClient;

// Get Stripe API key
$stripeSecretKey = env('STRIPE_SECRET_KEY', '');
if (empty($stripeSecretKey)) {
    http_response_code(500);
    echo json_encode(['error' => 'Stripe is not configured']);
    exit;
}

$stripe = new StripeClient($stripeSecretKey);
$pdo = $GLOBALS['pdo'];

// Get action
$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {

        // =====================================================================
        // CREATE CONNECTED ACCOUNT
        // =====================================================================
        case 'create_account':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('Method not allowed', 405);
            }

            require_admin();

            $recipientId = intval($_POST['recipient_id'] ?? 0);
            $email = trim($_POST['email'] ?? '');

            if (!$recipientId) {
                throw new Exception('recipient_id required');
            }

            // Get recipient
            $stmt = $pdo->prepare("SELECT * FROM payment_recipients WHERE id = ?");
            $stmt->execute([$recipientId]);
            $recipient = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$recipient) {
                throw new Exception('Recipient not found');
            }

            // Check if already has Stripe account
            if (!empty($recipient['stripe_account_id'])) {
                throw new Exception('Recipient already has a Stripe account');
            }

            // Create connected account
            $result = $stripe->createConnectedAccount([
                'email' => $email ?: null,
                'country' => 'SE',
                'business_type' => 'company', // or 'individual'
                'metadata' => [
                    'recipient_id' => $recipientId,
                    'recipient_name' => $recipient['name']
                ]
            ]);

            if (!$result['success']) {
                throw new Exception($result['error'] ?? 'Failed to create account');
            }

            // Save account ID to recipient
            $stmt = $pdo->prepare("
                UPDATE payment_recipients
                SET stripe_account_id = ?,
                    stripe_account_status = 'pending',
                    gateway_type = 'stripe'
                WHERE id = ?
            ");
            $stmt->execute([$result['account_id'], $recipientId]);

            echo json_encode([
                'success' => true,
                'account_id' => $result['account_id']
            ]);
            break;

        // =====================================================================
        // CREATE ACCOUNT LINK (ONBOARDING)
        // =====================================================================
        case 'create_account_link':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('Method not allowed', 405);
            }

            require_admin();

            $recipientId = intval($_POST['recipient_id'] ?? 0);

            if (!$recipientId) {
                throw new Exception('recipient_id required');
            }

            // Get recipient with Stripe account
            $stmt = $pdo->prepare("
                SELECT stripe_account_id FROM payment_recipients WHERE id = ?
            ");
            $stmt->execute([$recipientId]);
            $recipient = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$recipient || empty($recipient['stripe_account_id'])) {
                throw new Exception('Recipient has no Stripe account');
            }

            $baseUrl = SITE_URL;
            $returnUrl = $baseUrl . '/admin/stripe-connect?action=return&recipient_id=' . $recipientId;
            $refreshUrl = $baseUrl . '/admin/stripe-connect?action=refresh&recipient_id=' . $recipientId;

            $result = $stripe->createAccountLink(
                $recipient['stripe_account_id'],
                $returnUrl,
                $refreshUrl
            );

            if (!$result['success']) {
                throw new Exception($result['error'] ?? 'Failed to create account link');
            }

            echo json_encode([
                'success' => true,
                'url' => $result['url'],
                'expires_at' => $result['expires_at']
            ]);
            break;

        // =====================================================================
        // GET ACCOUNT STATUS
        // =====================================================================
        case 'account_status':
            require_admin();

            $accountId = $_GET['account_id'] ?? '';

            if (empty($accountId)) {
                throw new Exception('account_id required');
            }

            $result = $stripe->getAccount($accountId);

            if (!$result['success']) {
                throw new Exception($result['error'] ?? 'Failed to get account');
            }

            // Determine status
            $status = 'pending';
            if ($result['charges_enabled'] && $result['payouts_enabled']) {
                $status = 'active';
            } elseif ($result['details_submitted']) {
                $status = 'pending_verification';
            }

            echo json_encode([
                'success' => true,
                'status' => $status,
                'charges_enabled' => $result['charges_enabled'],
                'payouts_enabled' => $result['payouts_enabled'],
                'details_submitted' => $result['details_submitted']
            ]);
            break;

        // =====================================================================
        // CREATE CHECKOUT SESSION
        // =====================================================================
        case 'create_checkout_session':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('Method not allowed', 405);
            }

            $orderId = intval($_POST['order_id'] ?? 0);

            if (!$orderId) {
                throw new Exception('order_id required');
            }

            // Get order with recipient info
            $stmt = $pdo->prepare("
                SELECT o.*,
                       COALESCE(e.payment_recipient_id, s.payment_recipient_id) as recipient_id
                FROM orders o
                LEFT JOIN events e ON o.event_id = e.id
                LEFT JOIN series s ON o.series_id = s.id
                WHERE o.id = ? AND o.payment_status = 'pending'
            ");
            $stmt->execute([$orderId]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$order) {
                throw new Exception('Order not found or already paid');
            }

            // Get payment recipient
            if (empty($order['recipient_id'])) {
                throw new Exception('No payment recipient configured for this event');
            }

            $stmt = $pdo->prepare("
                SELECT * FROM payment_recipients WHERE id = ? AND stripe_account_id IS NOT NULL
            ");
            $stmt->execute([$order['recipient_id']]);
            $recipient = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$recipient) {
                throw new Exception('Payment recipient not configured for Stripe');
            }

            // Get order items for line items
            $stmt = $pdo->prepare("SELECT * FROM order_items WHERE order_id = ?");
            $stmt->execute([$orderId]);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Build line items
            $lineItems = [];
            foreach ($items as $item) {
                $lineItems[] = [
                    'price_data' => [
                        'currency' => 'sek',
                        'product_data' => [
                            'name' => $item['description']
                        ],
                        'unit_amount' => (int)($item['unit_price'] * 100)
                    ],
                    'quantity' => $item['quantity']
                ];
            }

            // Calculate platform fee (2%)
            $platformFeeAmount = (int)($order['total_amount'] * 2); // 2% in ore

            $baseUrl = SITE_URL;

            // Create Checkout Session using Stripe API
            $sessionParams = [
                'mode' => 'payment',
                'line_items' => $lineItems,
                'payment_intent_data' => [
                    'application_fee_amount' => $platformFeeAmount,
                    'transfer_data' => [
                        'destination' => $recipient['stripe_account_id']
                    ],
                    'metadata' => [
                        'order_id' => $orderId,
                        'order_number' => $order['order_number']
                    ]
                ],
                'success_url' => $baseUrl . '/checkout/success?order_id=' . $orderId . '&session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => $baseUrl . '/checkout?order_id=' . $orderId . '&cancelled=1',
                'customer_email' => $order['customer_email'],
                'metadata' => [
                    'order_id' => $orderId,
                    'order_number' => $order['order_number']
                ]
            ];

            $response = $stripe->request('POST', '/checkout/sessions', $sessionParams);

            if (isset($response['error'])) {
                throw new Exception($response['error']['message'] ?? 'Failed to create checkout session');
            }

            // Update order with session ID
            $stmt = $pdo->prepare("
                UPDATE orders
                SET gateway_code = 'stripe',
                    gateway_transaction_id = ?,
                    payment_method = 'card'
                WHERE id = ?
            ");
            $stmt->execute([$response['id'], $orderId]);

            echo json_encode([
                'success' => true,
                'session_id' => $response['id'],
                'checkout_url' => $response['url']
            ]);
            break;

        // =====================================================================
        // CREATE LOGIN LINK (FOR DASHBOARD ACCESS)
        // =====================================================================
        case 'create_login_link':
            require_admin();

            $accountId = $_POST['account_id'] ?? $_GET['account_id'] ?? '';

            if (empty($accountId)) {
                throw new Exception('account_id required');
            }

            $response = $stripe->request('POST', "/accounts/{$accountId}/login_links");

            if (isset($response['error'])) {
                throw new Exception($response['error']['message'] ?? 'Failed to create login link');
            }

            echo json_encode([
                'success' => true,
                'url' => $response['url']
            ]);
            break;

        default:
            throw new Exception('Unknown action: ' . $action, 400);
    }

} catch (Exception $e) {
    $code = $e->getCode() ?: 400;
    http_response_code($code);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

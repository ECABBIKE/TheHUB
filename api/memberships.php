<?php
/**
 * Memberships API
 * Handle membership subscriptions via Stripe
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/payment/StripeClient.php';

$pdo = $GLOBALS['pdo'];

// Try to get logged-in rider_id from session
session_start();
$sessionRiderId = $_SESSION['rider_id'] ?? $_SESSION['hub_user_id'] ?? null;

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    $stripeKey = env('STRIPE_SECRET_KEY', '');
    if (!$stripeKey) {
        throw new Exception('Stripe is not configured');
    }

    $stripe = new \TheHUB\Payment\StripeClient($stripeKey);

    switch ($action) {
        case 'get_plans':
            // Get all active membership plans
            $stmt = $pdo->prepare("
                SELECT id, name, description, price_amount, currency, billing_interval,
                       billing_interval_count, benefits, discount_percent, stripe_price_id
                FROM membership_plans
                WHERE active = 1
                ORDER BY sort_order, price_amount
            ");
            $stmt->execute();
            $plans = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Parse benefits JSON
            foreach ($plans as &$plan) {
                $plan['benefits'] = json_decode($plan['benefits'] ?? '[]', true);
                $plan['price_formatted'] = number_format($plan['price_amount'] / 100, 0) . ' kr';
            }

            echo json_encode(['success' => true, 'plans' => $plans]);
            break;

        case 'create_checkout':
            // Create a checkout session for a membership plan
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('POST required');
            }

            $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

            $planId = (int)($input['plan_id'] ?? 0);
            $email = trim($input['email'] ?? '');
            $name = trim($input['name'] ?? '');
            $successUrl = $input['success_url'] ?? SITE_URL . '/membership/success?session_id={CHECKOUT_SESSION_ID}';
            $cancelUrl = $input['cancel_url'] ?? SITE_URL . '/membership';

            if (!$planId || !$email) {
                throw new Exception('Plan ID and email are required');
            }

            // Get plan
            $stmt = $pdo->prepare("SELECT * FROM membership_plans WHERE id = ? AND active = 1");
            $stmt->execute([$planId]);
            $plan = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$plan || !$plan['stripe_price_id']) {
                throw new Exception('Plan not found or not configured in Stripe');
            }

            // Look up rider_id by email if not in session
            $riderId = $sessionRiderId;
            if (!$riderId) {
                $rStmt = $pdo->prepare("SELECT id FROM riders WHERE email = ? AND password IS NOT NULL LIMIT 1");
                $rStmt->execute([$email]);
                $riderRow = $rStmt->fetch(PDO::FETCH_ASSOC);
                if ($riderRow) {
                    $riderId = (int)$riderRow['id'];
                }
            }

            // Get or create Stripe customer
            $customerMeta = ['source' => 'membership_signup'];
            if ($riderId) {
                $customerMeta['rider_id'] = $riderId;
            }
            $customer = $stripe->getOrCreateCustomer([
                'email' => $email,
                'name' => $name,
                'metadata' => $customerMeta
            ]);

            if (!$customer['success']) {
                throw new Exception('Failed to create customer: ' . $customer['error']);
            }

            // Store customer mapping with rider_id
            $stmt = $pdo->prepare("
                INSERT INTO stripe_customers (email, name, stripe_customer_id, rider_id)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE name = VALUES(name), rider_id = COALESCE(VALUES(rider_id), rider_id), updated_at = NOW()
            ");
            $stmt->execute([$email, $name, $customer['customer_id'], $riderId]);

            // Create checkout session with rider_id in metadata
            $checkoutMeta = [
                'plan_id' => $planId,
                'email' => $email,
                'name' => $name
            ];
            if ($riderId) {
                $checkoutMeta['rider_id'] = $riderId;
            }

            $checkout = $stripe->createSubscriptionCheckout([
                'price_id' => $plan['stripe_price_id'],
                'customer_id' => $customer['customer_id'],
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
                'metadata' => $checkoutMeta
            ]);

            if (!$checkout['success']) {
                throw new Exception('Failed to create checkout: ' . $checkout['error']);
            }

            echo json_encode([
                'success' => true,
                'checkout_url' => $checkout['url'],
                'session_id' => $checkout['session_id']
            ]);
            break;

        case 'get_subscription':
            // Get subscription status for a customer
            $email = $_GET['email'] ?? '';

            if (!$email) {
                throw new Exception('Email required');
            }

            // Find subscription
            $stmt = $pdo->prepare("
                SELECT ms.*, mp.name as plan_name, mp.benefits, mp.discount_percent
                FROM member_subscriptions ms
                JOIN membership_plans mp ON ms.plan_id = mp.id
                WHERE ms.email = ? AND ms.stripe_subscription_status IN ('active', 'trialing')
                ORDER BY ms.created_at DESC
                LIMIT 1
            ");
            $stmt->execute([$email]);
            $subscription = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($subscription) {
                $subscription['benefits'] = json_decode($subscription['benefits'] ?? '[]', true);
                echo json_encode(['success' => true, 'subscription' => $subscription]);
            } else {
                echo json_encode(['success' => true, 'subscription' => null]);
            }
            break;

        case 'create_portal':
            // Create a billing portal session for subscription management
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('POST required');
            }

            $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
            $email = trim($input['email'] ?? '');
            $returnUrl = $input['return_url'] ?? SITE_URL . '/membership';

            if (!$email) {
                throw new Exception('Email required');
            }

            // Find Stripe customer
            $stmt = $pdo->prepare("SELECT stripe_customer_id FROM stripe_customers WHERE email = ?");
            $stmt->execute([$email]);
            $customer = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$customer) {
                throw new Exception('No subscription found for this email');
            }

            // Create portal session
            $portal = $stripe->createBillingPortalSession($customer['stripe_customer_id'], $returnUrl);

            if (!$portal['success']) {
                throw new Exception('Failed to create portal: ' . $portal['error']);
            }

            echo json_encode([
                'success' => true,
                'portal_url' => $portal['url']
            ]);
            break;

        case 'cancel':
            // Cancel subscription
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('POST required');
            }

            $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
            $email = trim($input['email'] ?? '');
            $immediately = (bool)($input['immediately'] ?? false);

            if (!$email) {
                throw new Exception('Email required');
            }

            // Find active subscription
            $stmt = $pdo->prepare("
                SELECT stripe_subscription_id FROM member_subscriptions
                WHERE email = ? AND stripe_subscription_status = 'active'
            ");
            $stmt->execute([$email]);
            $subscription = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$subscription) {
                throw new Exception('No active subscription found');
            }

            $result = $stripe->cancelSubscription($subscription['stripe_subscription_id'], !$immediately);

            if (!$result['success']) {
                throw new Exception('Failed to cancel: ' . $result['error']);
            }

            // Update local record
            if ($immediately) {
                $stmt = $pdo->prepare("
                    UPDATE member_subscriptions
                    SET stripe_subscription_status = 'canceled', canceled_at = NOW()
                    WHERE stripe_subscription_id = ?
                ");
            } else {
                $stmt = $pdo->prepare("
                    UPDATE member_subscriptions
                    SET cancel_at_period_end = 1
                    WHERE stripe_subscription_id = ?
                ");
            }
            $stmt->execute([$subscription['stripe_subscription_id']]);

            echo json_encode([
                'success' => true,
                'message' => $immediately ? 'Subscription canceled' : 'Subscription will cancel at period end'
            ]);
            break;

        case 'get_invoices':
            // Get invoices for a subscription
            $email = $_GET['email'] ?? '';

            if (!$email) {
                throw new Exception('Email required');
            }

            $stmt = $pdo->prepare("
                SELECT si.*
                FROM subscription_invoices si
                JOIN member_subscriptions ms ON si.subscription_id = ms.id
                WHERE ms.email = ?
                ORDER BY si.created_at DESC
                LIMIT 12
            ");
            $stmt->execute([$email]);
            $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'invoices' => $invoices]);
            break;

        default:
            throw new Exception('Unknown action: ' . $action);
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

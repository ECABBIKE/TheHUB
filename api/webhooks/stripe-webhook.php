<?php
/**
 * Stripe Webhook Handler
 * Receives events from Stripe
 *
 * Endpoint: /api/webhooks/stripe-webhook.php
 */

header('Content-Type: application/json');

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/payment/StripeClient.php';
require_once __DIR__ . '/../../includes/mail.php';

// Include receipt manager for automatic receipt generation
if (file_exists(__DIR__ . '/../../includes/receipt-manager.php')) {
    require_once __DIR__ . '/../../includes/receipt-manager.php';
}

/**
 * Create transfers to sellers for multi-recipient orders
 * Called after successful payment
 *
 * @param PDO $pdo Database connection
 * @param int $orderId Order ID
 * @param string $chargeId Stripe charge ID (source_transaction)
 * @return array Result with transfer details
 */
function createOrderTransfers(PDO $pdo, int $orderId, string $chargeId): array {
    // Get Stripe client
    $stripeApiKey = getenv('STRIPE_SECRET_KEY');
    if (!$stripeApiKey && function_exists('env')) {
        $stripeApiKey = env('STRIPE_SECRET_KEY', '');
    }

    if (!$stripeApiKey) {
        return ['success' => false, 'error' => 'Stripe API key not configured'];
    }

    $client = new \TheHUB\Payment\StripeClient($stripeApiKey);

    // Get order with transfer_group
    $stmt = $pdo->prepare("
        SELECT id, order_number, transfer_group, total_amount, currency
        FROM orders WHERE id = ?
    ");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        return ['success' => false, 'error' => 'Order not found'];
    }

    $transferGroup = $order['transfer_group'] ?: 'order_' . $order['order_number'];

    // Get order items grouped by payment recipient
    // Only items where the recipient has a Stripe account
    $stmt = $pdo->prepare("
        SELECT
            oi.payment_recipient_id,
            pr.stripe_account_id,
            pr.name as recipient_name,
            SUM(COALESCE(oi.seller_amount, oi.total_price)) as total_amount
        FROM order_items oi
        JOIN payment_recipients pr ON oi.payment_recipient_id = pr.id
        WHERE oi.order_id = ?
          AND pr.stripe_account_id IS NOT NULL
          AND pr.stripe_account_status = 'active'
        GROUP BY oi.payment_recipient_id, pr.stripe_account_id, pr.name
    ");
    $stmt->execute([$orderId]);
    $recipientAmounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($recipientAmounts)) {
        // No recipients with Stripe accounts - mark as completed (nothing to transfer)
        $stmt = $pdo->prepare("
            UPDATE orders SET transfers_status = 'completed', transfers_completed_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$orderId]);
        return ['success' => true, 'transfers' => [], 'message' => 'No recipients require transfers'];
    }

    // Mark order as processing transfers
    $stmt = $pdo->prepare("UPDATE orders SET transfers_status = 'processing' WHERE id = ?");
    $stmt->execute([$orderId]);

    $transfers = [];
    $hasErrors = false;

    foreach ($recipientAmounts as $recipient) {
        // Create transfer record first
        $stmt = $pdo->prepare("
            INSERT INTO order_transfers
            (order_id, payment_recipient_id, stripe_account_id, amount, stripe_charge_id, transfer_group, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())
        ");
        $stmt->execute([
            $orderId,
            $recipient['payment_recipient_id'],
            $recipient['stripe_account_id'],
            $recipient['total_amount'],
            $chargeId,
            $transferGroup
        ]);
        $transferRecordId = $pdo->lastInsertId();

        // Create Stripe transfer
        $transferResult = $client->createTransfer([
            'amount' => (float)$recipient['total_amount'],
            'currency' => strtolower($order['currency'] ?? 'sek'),
            'destination' => $recipient['stripe_account_id'],
            'source_transaction' => $chargeId,
            'transfer_group' => $transferGroup,
            'description' => "Order {$order['order_number']} - {$recipient['recipient_name']}",
            'metadata' => [
                'order_id' => $orderId,
                'order_number' => $order['order_number'],
                'recipient_id' => $recipient['payment_recipient_id']
            ]
        ]);

        if ($transferResult['success']) {
            // Update transfer record with Stripe ID
            $stmt = $pdo->prepare("
                UPDATE order_transfers
                SET stripe_transfer_id = ?, status = 'completed', transferred_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$transferResult['transfer_id'], $transferRecordId]);

            $transfers[] = [
                'recipient_id' => $recipient['payment_recipient_id'],
                'recipient_name' => $recipient['recipient_name'],
                'amount' => $recipient['total_amount'],
                'transfer_id' => $transferResult['transfer_id']
            ];
        } else {
            // Mark transfer as failed
            $stmt = $pdo->prepare("
                UPDATE order_transfers
                SET status = 'failed', failed_at = NOW(), error_message = ?
                WHERE id = ?
            ");
            $stmt->execute([$transferResult['error'] ?? 'Unknown error', $transferRecordId]);
            $hasErrors = true;

            error_log("Transfer failed for order {$orderId}, recipient {$recipient['payment_recipient_id']}: " . ($transferResult['error'] ?? 'Unknown'));
        }
    }

    // Update order transfers status
    $finalStatus = $hasErrors ? 'failed' : 'completed';
    $stmt = $pdo->prepare("
        UPDATE orders
        SET transfers_status = ?,
            transfers_completed_at = CASE WHEN ? = 'completed' THEN NOW() ELSE NULL END
        WHERE id = ?
    ");
    $stmt->execute([$finalStatus, $finalStatus, $orderId]);

    return [
        'success' => !$hasErrors,
        'transfers' => $transfers,
        'has_errors' => $hasErrors
    ];
}

$pdo = $GLOBALS['pdo'];

// Read raw POST data
$payload = file_get_contents('php://input');
$sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

// Get webhook secret from environment
$webhookSecret = getenv('STRIPE_WEBHOOK_SECRET');
if (!$webhookSecret && function_exists('env')) {
    $webhookSecret = env('STRIPE_WEBHOOK_SECRET', '');
}

// Log webhook receipt
try {
    $stmt = $pdo->prepare("
        INSERT INTO webhook_logs
        (gateway_code, webhook_type, payload, signature, received_at)
        VALUES ('stripe', 'webhook', ?, ?, NOW())
    ");
    $stmt->execute([$payload, $sigHeader]);
    $logId = $pdo->lastInsertId();
} catch (Exception $e) {
    error_log("Failed to log webhook: " . $e->getMessage());
    $logId = null;
}

try {
    // Parse event data
    $event = json_decode($payload, true);

    if (!$event) {
        throw new Exception('Invalid JSON payload');
    }

    // Verify webhook signature if secret is configured
    if ($webhookSecret) {
        $stripeApiKey = getenv('STRIPE_SECRET_KEY');
        if (!$stripeApiKey && function_exists('env')) {
            $stripeApiKey = env('STRIPE_SECRET_KEY', '');
        }

        if ($stripeApiKey) {
            $client = new \TheHUB\Payment\StripeClient($stripeApiKey);
            $verification = $client->verifyWebhookSignature($payload, $sigHeader, $webhookSecret);

            if (!$verification['valid']) {
                throw new Exception('Invalid webhook signature: ' . ($verification['error'] ?? 'Unknown error'));
            }
        }
    }

    $type = $event['type'] ?? '';
    $data = $event['data']['object'] ?? [];

    // Update webhook log with type
    if ($logId) {
        $stmt = $pdo->prepare("UPDATE webhook_logs SET webhook_type = ? WHERE id = ?");
        $stmt->execute([$type, $logId]);
    }

    // Handle different event types
    switch ($type) {
        case 'payment_intent.succeeded':
            $paymentIntentId = $data['id'] ?? null;

            if (!$paymentIntentId) {
                throw new Exception('Missing payment_intent ID');
            }

            // Find order by payment intent ID
            $stmt = $pdo->prepare("
                SELECT id, payment_status, order_number
                FROM orders
                WHERE gateway_transaction_id = ? AND gateway_code = 'stripe'
            ");
            $stmt->execute([$paymentIntentId]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($order && $order['payment_status'] === 'pending') {
                $pdo->beginTransaction();

                try {
                    // Mark order as paid
                    $stmt = $pdo->prepare("
                        UPDATE orders
                        SET payment_status = 'paid',
                            payment_reference = ?,
                            paid_at = NOW(),
                            callback_received_at = NOW(),
                            gateway_metadata = JSON_SET(
                                COALESCE(gateway_metadata, '{}'),
                                '$.stripe_event', CAST(? AS JSON)
                            )
                        WHERE id = ?
                    ");
                    $stmt->execute([
                        $data['id'],
                        json_encode(['type' => $type, 'payment_intent' => $data['id']]),
                        $order['id']
                    ]);

                    // Update registrations
                    $stmt = $pdo->prepare("
                        UPDATE event_registrations
                        SET payment_status = 'paid',
                            status = 'confirmed',
                            confirmed_date = NOW()
                        WHERE order_id = ?
                    ");
                    $stmt->execute([$order['id']]);

                    $pdo->commit();

                    // Generate receipt(s) - may be multiple for multi-seller orders
                    try {
                        if (function_exists('createReceiptForOrder')) {
                            $receiptResult = createReceiptForOrder($pdo, $order['id']);
                            if (!$receiptResult['success']) {
                                error_log("Failed to create receipt for order {$order['id']}: " . ($receiptResult['error'] ?? 'Unknown error'));
                            }
                        }
                    } catch (Exception $receiptError) {
                        error_log("Receipt generation error: " . $receiptError->getMessage());
                    }

                    // Create transfers to sellers (multi-recipient support)
                    try {
                        $chargeId = $data['latest_charge'] ?? null;
                        if ($chargeId) {
                            $transferResult = createOrderTransfers($pdo, $order['id'], $chargeId);
                            if (!$transferResult['success'] && !empty($transferResult['error'])) {
                                error_log("Transfer creation warning for order {$order['id']}: " . $transferResult['error']);
                            }
                        }
                    } catch (Exception $transferError) {
                        error_log("Transfer creation error for order {$order['id']}: " . $transferError->getMessage());
                    }

                    // Send confirmation email
                    try {
                        hub_send_order_confirmation($order['id']);
                    } catch (Exception $emailError) {
                        error_log("Failed to send order confirmation email: " . $emailError->getMessage());
                    }

                    // Mark webhook as processed
                    if ($logId) {
                        $stmt = $pdo->prepare("
                            UPDATE webhook_logs
                            SET processed = 1, order_id = ?, processed_at = NOW()
                            WHERE id = ?
                        ");
                        $stmt->execute([$order['id'], $logId]);
                    }

                    $result = [
                        'status' => 'processed',
                        'message' => 'Payment confirmed for order ' . $order['order_number']
                    ];

                } catch (Exception $e) {
                    $pdo->rollBack();
                    throw $e;
                }
            } else {
                $result = [
                    'status' => 'ignored',
                    'message' => $order ? 'Order already processed' : 'Order not found'
                ];
            }
            break;

        case 'payment_intent.payment_failed':
            $paymentIntentId = $data['id'] ?? null;

            if ($paymentIntentId) {
                $stmt = $pdo->prepare("
                    UPDATE orders
                    SET payment_status = 'failed',
                        callback_received_at = NOW(),
                        gateway_metadata = JSON_SET(
                            COALESCE(gateway_metadata, '{}'),
                            '$.stripe_error', ?
                        )
                    WHERE gateway_transaction_id = ? AND gateway_code = 'stripe' AND payment_status = 'pending'
                ");
                $stmt->execute([
                    $data['last_payment_error']['message'] ?? 'Payment failed',
                    $paymentIntentId
                ]);

                $result = ['status' => 'processed', 'message' => 'Payment failure recorded'];
            } else {
                $result = ['status' => 'ignored', 'message' => 'Missing payment_intent ID'];
            }
            break;

        case 'checkout.session.completed':
            // Checkout session completed - payment successful
            $sessionId = $data['id'] ?? null;
            $paymentStatus = $data['payment_status'] ?? '';

            if ($sessionId && $paymentStatus === 'paid') {
                // Find order by session ID (stored in gateway_transaction_id)
                $stmt = $pdo->prepare("
                    SELECT id, payment_status, order_number
                    FROM orders
                    WHERE gateway_transaction_id = ? AND gateway_code = 'stripe'
                ");
                $stmt->execute([$sessionId]);
                $order = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($order && $order['payment_status'] === 'pending') {
                    $pdo->beginTransaction();

                    try {
                        // Mark order as paid
                        $stmt = $pdo->prepare("
                            UPDATE orders
                            SET payment_status = 'paid',
                                payment_reference = ?,
                                paid_at = NOW(),
                                callback_received_at = NOW(),
                                gateway_metadata = JSON_SET(
                                    COALESCE(gateway_metadata, '{}'),
                                    '$.checkout_session', CAST(? AS JSON)
                                )
                            WHERE id = ?
                        ");
                        $stmt->execute([
                            $data['payment_intent'] ?? $sessionId,
                            json_encode(['session_id' => $sessionId, 'customer_email' => $data['customer_email'] ?? '']),
                            $order['id']
                        ]);

                        // Update registrations
                        $stmt = $pdo->prepare("
                            UPDATE event_registrations
                            SET payment_status = 'paid',
                                status = 'confirmed',
                                confirmed_date = NOW()
                            WHERE order_id = ?
                        ");
                        $stmt->execute([$order['id']]);

                        $pdo->commit();

                        // Generate receipt(s) - may be multiple for multi-seller orders
                        try {
                            if (function_exists('createReceiptForOrder')) {
                                $receiptResult = createReceiptForOrder($pdo, $order['id']);
                                if (!$receiptResult['success']) {
                                    error_log("Failed to create receipt for order {$order['id']}: " . ($receiptResult['error'] ?? 'Unknown error'));
                                }
                            }
                        } catch (Exception $receiptError) {
                            error_log("Receipt generation error: " . $receiptError->getMessage());
                        }

                        // Create transfers to sellers (multi-recipient support)
                        // For checkout.session, we need to get the charge ID from payment_intent
                        try {
                            $paymentIntentId = $data['payment_intent'] ?? null;
                            if ($paymentIntentId) {
                                $stripeApiKey = getenv('STRIPE_SECRET_KEY');
                                if (!$stripeApiKey && function_exists('env')) {
                                    $stripeApiKey = env('STRIPE_SECRET_KEY', '');
                                }
                                if ($stripeApiKey) {
                                    $client = new \TheHUB\Payment\StripeClient($stripeApiKey);
                                    $chargeResult = $client->getChargeFromPaymentIntent($paymentIntentId);
                                    if ($chargeResult['success'] && $chargeResult['charge_id']) {
                                        $transferResult = createOrderTransfers($pdo, $order['id'], $chargeResult['charge_id']);
                                        if (!$transferResult['success'] && !empty($transferResult['error'])) {
                                            error_log("Transfer creation warning for order {$order['id']}: " . $transferResult['error']);
                                        }
                                    }
                                }
                            }
                        } catch (Exception $transferError) {
                            error_log("Transfer creation error for order {$order['id']}: " . $transferError->getMessage());
                        }

                        // Send confirmation email
                        try {
                            hub_send_order_confirmation($order['id']);
                        } catch (Exception $emailError) {
                            error_log("Failed to send order confirmation email: " . $emailError->getMessage());
                        }

                        // Mark webhook as processed
                        if ($logId) {
                            $stmt = $pdo->prepare("
                                UPDATE webhook_logs
                                SET processed = 1, order_id = ?, processed_at = NOW()
                                WHERE id = ?
                            ");
                            $stmt->execute([$order['id'], $logId]);
                        }

                        $result = [
                            'status' => 'processed',
                            'message' => 'Checkout completed for order ' . $order['order_number']
                        ];

                    } catch (Exception $e) {
                        $pdo->rollBack();
                        throw $e;
                    }
                } else {
                    $result = [
                        'status' => 'ignored',
                        'message' => $order ? 'Order already processed' : 'Order not found'
                    ];
                }
            } else {
                $result = ['status' => 'ignored', 'message' => 'Session not paid or missing ID'];
            }
            break;

        case 'checkout.session.async_payment_failed':
            // Async payment method failed (e.g., bank transfer that didn't complete)
            $sessionId = $data['id'] ?? null;

            if ($sessionId) {
                $stmt = $pdo->prepare("
                    UPDATE orders
                    SET payment_status = 'failed',
                        callback_received_at = NOW(),
                        gateway_metadata = JSON_SET(
                            COALESCE(gateway_metadata, '{}'),
                            '$.async_payment_error', 'Async payment failed'
                        )
                    WHERE gateway_transaction_id = ? AND gateway_code = 'stripe' AND payment_status = 'pending'
                ");
                $stmt->execute([$sessionId]);

                $result = ['status' => 'processed', 'message' => 'Async payment failure recorded'];
            } else {
                $result = ['status' => 'ignored', 'message' => 'Missing session ID'];
            }
            break;

        case 'charge.refunded':
            $paymentIntentId = $data['payment_intent'] ?? null;

            if ($paymentIntentId) {
                $stmt = $pdo->prepare("
                    UPDATE orders
                    SET payment_status = 'refunded',
                        refunded_at = NOW(),
                        callback_received_at = NOW()
                    WHERE gateway_transaction_id = ? AND gateway_code = 'stripe'
                ");
                $stmt->execute([$paymentIntentId]);

                $result = ['status' => 'processed', 'message' => 'Refund recorded'];
            } else {
                $result = ['status' => 'ignored', 'message' => 'Missing payment_intent ID'];
            }
            break;

        case 'account.updated':
            // Connected account status update
            $accountId = $data['id'] ?? null;

            if ($accountId) {
                $chargesEnabled = $data['charges_enabled'] ?? false;
                $payoutsEnabled = $data['payouts_enabled'] ?? false;
                $detailsSubmitted = $data['details_submitted'] ?? false;

                $status = 'pending';
                if ($chargesEnabled && $payoutsEnabled) {
                    $status = 'active';
                } elseif (!$detailsSubmitted) {
                    $status = 'pending';
                } else {
                    $status = 'disabled';
                }

                $stmt = $pdo->prepare("
                    UPDATE payment_recipients
                    SET stripe_account_status = ?
                    WHERE stripe_account_id = ?
                ");
                $stmt->execute([$status, $accountId]);

                $result = ['status' => 'processed', 'message' => 'Account status updated to: ' . $status];
            } else {
                $result = ['status' => 'ignored', 'message' => 'Missing account ID'];
            }
            break;

        // ============================================================
        // SUBSCRIPTION EVENTS (Stripe Billing)
        // ============================================================

        case 'customer.subscription.created':
        case 'customer.subscription.updated':
            $subscriptionId = $data['id'] ?? null;
            $customerId = $data['customer'] ?? null;
            $status = $data['status'] ?? '';

            if ($subscriptionId && $customerId) {
                // Check if subscription exists
                $stmt = $pdo->prepare("SELECT id FROM member_subscriptions WHERE stripe_subscription_id = ?");
                $stmt->execute([$subscriptionId]);
                $existing = $stmt->fetch(PDO::FETCH_ASSOC);

                $periodStart = isset($data['current_period_start'])
                    ? date('Y-m-d H:i:s', $data['current_period_start']) : null;
                $periodEnd = isset($data['current_period_end'])
                    ? date('Y-m-d H:i:s', $data['current_period_end']) : null;
                $cancelAtPeriodEnd = $data['cancel_at_period_end'] ?? false;
                $canceledAt = isset($data['canceled_at'])
                    ? date('Y-m-d H:i:s', $data['canceled_at']) : null;
                $trialStart = isset($data['trial_start'])
                    ? date('Y-m-d H:i:s', $data['trial_start']) : null;
                $trialEnd = isset($data['trial_end'])
                    ? date('Y-m-d H:i:s', $data['trial_end']) : null;

                if ($existing) {
                    // Update existing subscription
                    $stmt = $pdo->prepare("
                        UPDATE member_subscriptions SET
                            stripe_subscription_status = ?,
                            current_period_start = ?,
                            current_period_end = ?,
                            cancel_at_period_end = ?,
                            canceled_at = ?,
                            trial_start = ?,
                            trial_end = ?,
                            updated_at = NOW()
                        WHERE stripe_subscription_id = ?
                    ");
                    $stmt->execute([
                        $status,
                        $periodStart,
                        $periodEnd,
                        $cancelAtPeriodEnd ? 1 : 0,
                        $canceledAt,
                        $trialStart,
                        $trialEnd,
                        $subscriptionId
                    ]);
                } else {
                    // Get price/plan info from subscription items
                    $priceId = $data['items']['data'][0]['price']['id'] ?? null;

                    // Find matching plan
                    $stmt = $pdo->prepare("SELECT id FROM membership_plans WHERE stripe_price_id = ?");
                    $stmt->execute([$priceId]);
                    $plan = $stmt->fetch(PDO::FETCH_ASSOC);

                    if ($plan) {
                        // Get customer email from Stripe
                        $customerEmail = $data['metadata']['email'] ?? '';
                        $customerName = $data['metadata']['name'] ?? '';

                        // Create new subscription record
                        $stmt = $pdo->prepare("
                            INSERT INTO member_subscriptions (
                                plan_id, email, name, stripe_customer_id, stripe_subscription_id,
                                stripe_subscription_status, current_period_start, current_period_end,
                                cancel_at_period_end, trial_start, trial_end, metadata
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([
                            $plan['id'],
                            $customerEmail,
                            $customerName,
                            $customerId,
                            $subscriptionId,
                            $status,
                            $periodStart,
                            $periodEnd,
                            $cancelAtPeriodEnd ? 1 : 0,
                            $trialStart,
                            $trialEnd,
                            json_encode($data['metadata'] ?? [])
                        ]);
                    }
                }

                $result = ['status' => 'processed', 'message' => "Subscription {$type} processed"];
            } else {
                $result = ['status' => 'ignored', 'message' => 'Missing subscription or customer ID'];
            }
            break;

        case 'customer.subscription.deleted':
            $subscriptionId = $data['id'] ?? null;

            if ($subscriptionId) {
                $stmt = $pdo->prepare("
                    UPDATE member_subscriptions SET
                        stripe_subscription_status = 'canceled',
                        canceled_at = NOW(),
                        updated_at = NOW()
                    WHERE stripe_subscription_id = ?
                ");
                $stmt->execute([$subscriptionId]);

                $result = ['status' => 'processed', 'message' => 'Subscription canceled'];
            } else {
                $result = ['status' => 'ignored', 'message' => 'Missing subscription ID'];
            }
            break;

        case 'customer.subscription.trial_will_end':
            // Subscription trial ending soon (3 days before)
            $subscriptionId = $data['id'] ?? null;

            if ($subscriptionId) {
                // Get subscription with email
                $stmt = $pdo->prepare("
                    SELECT ms.*, mp.name as plan_name
                    FROM member_subscriptions ms
                    JOIN membership_plans mp ON ms.plan_id = mp.id
                    WHERE ms.stripe_subscription_id = ?
                ");
                $stmt->execute([$subscriptionId]);
                $subscription = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($subscription && $subscription['email']) {
                    // Send trial ending email notification
                    try {
                        $subject = 'Din provperiod avslutas snart - ' . $subscription['plan_name'];
                        $body = "<h2>Hej {$subscription['name']}!</h2>";
                        $body .= "<p>Din provperiod for <strong>{$subscription['plan_name']}</strong> avslutas snart.</p>";
                        $body .= "<p>Efter provperioden kommer din prenumeration att aktiveras automatiskt.</p>";
                        $body .= "<p>Om du vill avsluta innan dess, kan du gora det via din medlemssida.</p>";

                        hub_send_email($subscription['email'], $subject, $body);
                    } catch (Exception $emailError) {
                        error_log("Failed to send trial ending email: " . $emailError->getMessage());
                    }
                }

                $result = ['status' => 'processed', 'message' => 'Trial ending notification sent'];
            } else {
                $result = ['status' => 'ignored', 'message' => 'Missing subscription ID'];
            }
            break;

        case 'invoice.paid':
            // Invoice was paid - update subscription payment info
            $subscriptionId = $data['subscription'] ?? null;
            $amountPaid = $data['amount_paid'] ?? 0;
            $invoiceId = $data['id'] ?? null;

            if ($subscriptionId) {
                // Update last payment
                $stmt = $pdo->prepare("
                    UPDATE member_subscriptions SET
                        last_payment_at = NOW(),
                        last_payment_amount = ?,
                        updated_at = NOW()
                    WHERE stripe_subscription_id = ?
                ");
                $stmt->execute([$amountPaid, $subscriptionId]);

                // Store invoice record
                if ($invoiceId) {
                    $stmt = $pdo->prepare("SELECT id FROM member_subscriptions WHERE stripe_subscription_id = ?");
                    $stmt->execute([$subscriptionId]);
                    $sub = $stmt->fetch(PDO::FETCH_ASSOC);

                    if ($sub) {
                        $stmt = $pdo->prepare("
                            INSERT INTO subscription_invoices (
                                subscription_id, stripe_invoice_id, stripe_invoice_number,
                                stripe_invoice_pdf, stripe_hosted_invoice_url,
                                amount_due, amount_paid, currency, status,
                                period_start, period_end, paid_at
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'paid', ?, ?, NOW())
                            ON DUPLICATE KEY UPDATE
                                status = 'paid', paid_at = NOW(), amount_paid = ?
                        ");
                        $stmt->execute([
                            $sub['id'],
                            $invoiceId,
                            $data['number'] ?? null,
                            $data['invoice_pdf'] ?? null,
                            $data['hosted_invoice_url'] ?? null,
                            $data['amount_due'] ?? 0,
                            $amountPaid,
                            $data['currency'] ?? 'sek',
                            isset($data['period_start']) ? date('Y-m-d H:i:s', $data['period_start']) : null,
                            isset($data['period_end']) ? date('Y-m-d H:i:s', $data['period_end']) : null,
                            $amountPaid
                        ]);
                    }
                }

                $result = ['status' => 'processed', 'message' => 'Invoice payment recorded'];
            } else {
                $result = ['status' => 'ignored', 'message' => 'No subscription for this invoice'];
            }
            break;

        case 'invoice.payment_failed':
            // Invoice payment failed
            $subscriptionId = $data['subscription'] ?? null;
            $invoiceId = $data['id'] ?? null;

            if ($subscriptionId) {
                // Get subscription with email
                $stmt = $pdo->prepare("
                    SELECT ms.*, mp.name as plan_name
                    FROM member_subscriptions ms
                    JOIN membership_plans mp ON ms.plan_id = mp.id
                    WHERE ms.stripe_subscription_id = ?
                ");
                $stmt->execute([$subscriptionId]);
                $subscription = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($subscription && $subscription['email']) {
                    // Send payment failed email
                    try {
                        $subject = 'Betalning misslyckades - ' . $subscription['plan_name'];
                        $body = "<h2>Hej {$subscription['name']}!</h2>";
                        $body .= "<p>Vi kunde inte genomfora betalningen for din prenumeration <strong>{$subscription['plan_name']}</strong>.</p>";
                        $body .= "<p>Vanligen uppdatera din betalningsmetod for att undvika avbrott i ditt medlemskap.</p>";

                        hub_send_email($subscription['email'], $subject, $body);
                    } catch (Exception $emailError) {
                        error_log("Failed to send payment failed email: " . $emailError->getMessage());
                    }
                }

                $result = ['status' => 'processed', 'message' => 'Payment failure notification sent'];
            } else {
                $result = ['status' => 'ignored', 'message' => 'No subscription for this invoice'];
            }
            break;

        default:
            // Unhandled event type
            $result = ['status' => 'ignored', 'message' => 'Unhandled event type: ' . $type];
    }

    // Mark webhook as processed if not already done
    // These events handle their own logging within their case blocks
    $eventsWithCustomLogging = ['payment_intent.succeeded', 'checkout.session.completed'];
    if ($logId && !in_array($type, $eventsWithCustomLogging)) {
        $stmt = $pdo->prepare("
            UPDATE webhook_logs
            SET processed = 1, processed_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$logId]);
    }

    echo json_encode($result);

} catch (Exception $e) {
    // Log error
    if ($logId) {
        $stmt = $pdo->prepare("
            UPDATE webhook_logs
            SET error_message = ?
            WHERE id = ?
        ");
        $stmt->execute([$e->getMessage(), $logId]);
    }

    error_log("Stripe webhook error: " . $e->getMessage());

    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}

<?php
/**
 * Mollie Webhook Handler
 * Receives payment status updates from Mollie
 *
 * Endpoint: /webhooks/mollie-webhook.php
 *
 * Mollie sends: POST with 'id' parameter (payment ID)
 * We must fetch payment status from Mollie API (security best practice)
 */

header('Content-Type: application/json');

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/payment/MollieClient.php';
require_once __DIR__ . '/../includes/mail.php';

// Include receipt manager for automatic receipt generation
if (file_exists(__DIR__ . '/../includes/receipt-manager.php')) {
    require_once __DIR__ . '/../includes/receipt-manager.php';
}

use TheHUB\Payment\MollieClient;

$pdo = $GLOBALS['pdo'];

// Get payment ID from webhook
$paymentId = $_POST['id'] ?? '';

if (empty($paymentId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing payment ID']);
    exit;
}

// Log webhook receipt
try {
    $stmt = $pdo->prepare("
        INSERT INTO webhook_logs
        (gateway_code, webhook_type, payload, received_at)
        VALUES ('mollie', 'payment.update', ?, NOW())
    ");
    $stmt->execute([json_encode($_POST)]);
    $logId = $pdo->lastInsertId();
} catch (Exception $e) {
    error_log("Failed to log webhook: " . $e->getMessage());
    $logId = null;
}

try {
    // Get Mollie API key
    $mollieKey = env('MOLLIE_API_KEY', '');
    if (empty($mollieKey)) {
        throw new Exception('Mollie API key not configured');
    }

    $mollie = new MollieClient($mollieKey);

    // SECURITY: Always fetch payment from Mollie API
    // Never trust webhook data directly
    $paymentResult = $mollie->getPayment($paymentId);

    if (!$paymentResult['success']) {
        throw new Exception('Failed to fetch payment: ' . ($paymentResult['error'] ?? 'Unknown error'));
    }

    $payment = $paymentResult;
    $status = $payment['status'];
    $metadata = $payment['metadata'] ?? [];
    $orderId = isset($metadata['order_id']) ? (int)$metadata['order_id'] : null;

    // Update webhook log with order ID
    if ($logId && $orderId) {
        $stmt = $pdo->prepare("UPDATE webhook_logs SET order_id = ? WHERE id = ?");
        $stmt->execute([$orderId, $logId]);
    }

    // Find order in database
    $stmt = $pdo->prepare("
        SELECT id, payment_status, order_number, customer_email
        FROM orders
        WHERE gateway_transaction_id = ? AND gateway_code = 'mollie'
    ");
    $stmt->execute([$paymentId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        throw new Exception('Order not found for payment ID: ' . $paymentId);
    }

    // Handle payment status
    switch ($status) {
        case 'paid':
            // Payment successful
            if ($order['payment_status'] === 'paid') {
                // Already processed
                $result = ['status' => 'ignored', 'message' => 'Order already paid'];
                break;
            }

            $pdo->beginTransaction();

            try {
                // Mark order as paid
                $stmt = $pdo->prepare("
                    UPDATE orders
                    SET payment_status = 'paid',
                        payment_reference = ?,
                        paid_at = NOW(),
                        callback_received_at = NOW(),
                        gateway_metadata = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $paymentId,
                    json_encode([
                        'mollie_payment_id' => $paymentId,
                        'payment_method' => $payment['payment_method'] ?? null,
                        'amount' => $payment['amount'],
                        'currency' => $payment['currency']
                    ]),
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

                // Generate receipt(s)
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
                        SET processed = 1, processed_at = NOW()
                        WHERE id = ?
                    ");
                    $stmt->execute([$logId]);
                }

                $result = [
                    'status' => 'processed',
                    'message' => 'Payment confirmed for order ' . $order['order_number']
                ];

            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            break;

        case 'failed':
            // Payment failed
            $stmt = $pdo->prepare("
                UPDATE orders
                SET payment_status = 'failed',
                    callback_received_at = NOW(),
                    gateway_metadata = JSON_SET(
                        COALESCE(gateway_metadata, '{}'),
                        '$.error', 'Payment failed'
                    )
                WHERE id = ? AND payment_status = 'pending'
            ");
            $stmt->execute([$order['id']]);

            $result = ['status' => 'processed', 'message' => 'Payment failure recorded'];
            break;

        case 'expired':
            // Payment expired (customer didn't complete payment in time)
            $stmt = $pdo->prepare("
                UPDATE orders
                SET payment_status = 'expired',
                    callback_received_at = NOW(),
                    gateway_metadata = JSON_SET(
                        COALESCE(gateway_metadata, '{}'),
                        '$.error', 'Payment expired'
                    )
                WHERE id = ? AND payment_status = 'pending'
            ");
            $stmt->execute([$order['id']]);

            $result = ['status' => 'processed', 'message' => 'Payment expiration recorded'];
            break;

        case 'canceled':
            // Payment canceled by user
            $stmt = $pdo->prepare("
                UPDATE orders
                SET payment_status = 'cancelled',
                    callback_received_at = NOW(),
                    gateway_metadata = JSON_SET(
                        COALESCE(gateway_metadata, '{}'),
                        '$.error', 'Payment canceled'
                    )
                WHERE id = ? AND payment_status = 'pending'
            ");
            $stmt->execute([$order['id']]);

            $result = ['status' => 'processed', 'message' => 'Payment cancellation recorded'];
            break;

        case 'open':
        case 'pending':
            // Payment awaiting completion - nothing to do yet
            $result = ['status' => 'ignored', 'message' => 'Payment still pending'];
            break;

        default:
            // Unknown status
            $result = ['status' => 'ignored', 'message' => 'Unknown payment status: ' . $status];
    }

    // Mark webhook as processed if not already done
    if ($logId && !isset($result['already_logged'])) {
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

    error_log("Mollie webhook error: " . $e->getMessage());

    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}

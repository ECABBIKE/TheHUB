<?php
/**
 * RefundManager - Hanterar återbetalningar för multi-seller plattform
 *
 * Funktionalitet:
 * - Processar refunds via Stripe
 * - Automatisk återföring av transfers till säljare
 * - Partial/full refund support
 * - Spårar refund-historik
 *
 * ÅTERBETALNINGSPOLICY:
 * ---------------------
 * 1. Plattformen (TheHUB) hanterar ALLA återbetalningar
 * 2. Vid refund återförs säljarens andel automatiskt från deras konto
 * 3. Plattformen bär risken för eventuella underskott (negativ balans)
 * 4. Säljare behöver aldrig hantera chargebacks eller refunds
 *
 * @package TheHUB\Payment
 */

require_once __DIR__ . '/payment/StripeClient.php';

use TheHUB\Payment\StripeClient;

/**
 * Process a full or partial refund for an order
 *
 * @param PDO $pdo Database connection
 * @param int $orderId Order ID
 * @param float|null $amount Amount to refund (null for full refund)
 * @param string $reason Reason for refund
 * @param int|null $adminId Admin user processing the refund
 * @param array $itemsToRefund Optional: specific order_item IDs to refund (for partial)
 * @return array Result with refund details
 */
function processOrderRefund(
    PDO $pdo,
    int $orderId,
    ?float $amount = null,
    string $reason = '',
    ?int $adminId = null,
    array $itemsToRefund = []
): array {
    // Get Stripe client
    $stripeApiKey = getenv('STRIPE_SECRET_KEY');
    if (!$stripeApiKey && function_exists('env')) {
        $stripeApiKey = env('STRIPE_SECRET_KEY', '');
    }

    if (!$stripeApiKey) {
        return ['success' => false, 'error' => 'Stripe API key not configured'];
    }

    $client = new StripeClient($stripeApiKey);

    // Get order details
    $stmt = $pdo->prepare("
        SELECT o.*,
               COALESCE(o.gateway_transaction_id, '') as payment_intent_id
        FROM orders o
        WHERE o.id = ?
    ");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        return ['success' => false, 'error' => 'Order not found'];
    }

    if ($order['payment_status'] !== 'paid') {
        return ['success' => false, 'error' => 'Order is not in paid status. Current status: ' . $order['payment_status']];
    }

    if (empty($order['payment_intent_id'])) {
        return ['success' => false, 'error' => 'No Stripe payment intent found for this order'];
    }

    // Calculate refund amount
    $fullRefund = ($amount === null);
    $refundAmount = $amount ?? (float)$order['total_amount'];

    if ($refundAmount <= 0) {
        return ['success' => false, 'error' => 'Invalid refund amount'];
    }

    if ($refundAmount > (float)$order['total_amount']) {
        return ['success' => false, 'error' => 'Refund amount exceeds order total'];
    }

    // Begin transaction
    $pdo->beginTransaction();

    try {
        // Create refund record first
        $stmt = $pdo->prepare("
            INSERT INTO order_refunds
            (order_id, amount, reason, refund_type, status, admin_id, created_at)
            VALUES (?, ?, ?, ?, 'pending', ?, NOW())
        ");
        $stmt->execute([
            $orderId,
            $refundAmount,
            $reason,
            $fullRefund ? 'full' : 'partial',
            $adminId
        ]);
        $refundRecordId = $pdo->lastInsertId();

        // Process Stripe refund
        $stripeRefund = $client->createRefund($order['payment_intent_id'], $fullRefund ? null : $refundAmount);

        if (!$stripeRefund['success']) {
            // Mark refund as failed
            $stmt = $pdo->prepare("
                UPDATE order_refunds
                SET status = 'failed', error_message = ?, processed_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$stripeRefund['error'] ?? 'Unknown Stripe error', $refundRecordId]);
            $pdo->commit();

            return [
                'success' => false,
                'error' => 'Stripe refund failed: ' . ($stripeRefund['error'] ?? 'Unknown error'),
                'refund_id' => $refundRecordId
            ];
        }

        // Update refund record with Stripe ID
        $stmt = $pdo->prepare("
            UPDATE order_refunds
            SET stripe_refund_id = ?, status = 'processing', processed_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$stripeRefund['refund_id'], $refundRecordId]);

        // Now reverse transfers to sellers
        $transferReversals = reverseOrderTransfers($pdo, $client, $orderId, $refundAmount, $fullRefund, $refundRecordId);

        // Update refund status based on transfer reversals
        $allReversalsSuccess = empty($transferReversals['errors']);
        $finalStatus = $allReversalsSuccess ? 'completed' : 'partial_completed';

        $stmt = $pdo->prepare("
            UPDATE order_refunds
            SET status = ?,
                transfer_reversals_completed = ?,
                completed_at = CASE WHEN ? = 'completed' THEN NOW() ELSE NULL END
            WHERE id = ?
        ");
        $stmt->execute([
            $finalStatus,
            $allReversalsSuccess ? 1 : 0,
            $finalStatus,
            $refundRecordId
        ]);

        // Update order status
        $newOrderStatus = $fullRefund ? 'refunded' : 'partial_refund';
        $stmt = $pdo->prepare("
            UPDATE orders
            SET payment_status = ?,
                refunded_at = NOW(),
                refunded_amount = COALESCE(refunded_amount, 0) + ?
            WHERE id = ?
        ");
        $stmt->execute([$newOrderStatus, $refundAmount, $orderId]);

        // Update related registrations if full refund
        if ($fullRefund) {
            $stmt = $pdo->prepare("
                UPDATE event_registrations
                SET payment_status = 'refunded',
                    status = 'cancelled'
                WHERE order_id = ?
            ");
            $stmt->execute([$orderId]);

            // Update related tickets if any
            $stmt = $pdo->prepare("
                UPDATE event_tickets
                SET status = 'refunded'
                WHERE order_id = ?
            ");
            $stmt->execute([$orderId]);
        }

        // Update specific items if partial refund with specific items
        if (!$fullRefund && !empty($itemsToRefund)) {
            $placeholders = implode(',', array_fill(0, count($itemsToRefund), '?'));
            $stmt = $pdo->prepare("
                UPDATE order_items
                SET refunded = 1, refunded_at = NOW()
                WHERE id IN ($placeholders) AND order_id = ?
            ");
            $stmt->execute(array_merge($itemsToRefund, [$orderId]));
        }

        $pdo->commit();

        // Log the refund
        error_log("Refund processed for order {$orderId}: {$refundAmount} SEK, Stripe refund: {$stripeRefund['refund_id']}");

        return [
            'success' => true,
            'refund_id' => $refundRecordId,
            'stripe_refund_id' => $stripeRefund['refund_id'],
            'amount' => $refundAmount,
            'type' => $fullRefund ? 'full' : 'partial',
            'transfer_reversals' => $transferReversals,
            'order_status' => $newOrderStatus
        ];

    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Refund error for order {$orderId}: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Reverse transfers to sellers when a refund is processed
 *
 * @param PDO $pdo Database connection
 * @param StripeClient $client Stripe client
 * @param int $orderId Order ID
 * @param float $refundAmount Total refund amount
 * @param bool $fullRefund Whether this is a full refund
 * @param int $refundRecordId Refund record ID for tracking
 * @return array Result with reversal details
 */
function reverseOrderTransfers(
    PDO $pdo,
    StripeClient $client,
    int $orderId,
    float $refundAmount,
    bool $fullRefund,
    int $refundRecordId
): array {
    // Get transfers for this order
    $stmt = $pdo->prepare("
        SELECT ot.*,
               pr.name as recipient_name
        FROM order_transfers ot
        JOIN payment_recipients pr ON ot.payment_recipient_id = pr.id
        WHERE ot.order_id = ?
          AND ot.status = 'completed'
          AND ot.reversed = 0
    ");
    $stmt->execute([$orderId]);
    $transfers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($transfers)) {
        return ['success' => true, 'reversals' => [], 'message' => 'No transfers to reverse'];
    }

    // Calculate total transferred amount
    $totalTransferred = array_sum(array_column($transfers, 'amount'));

    $reversals = [];
    $errors = [];

    foreach ($transfers as $transfer) {
        // Calculate proportional reversal amount for partial refunds
        $reversalAmount = $fullRefund
            ? (float)$transfer['amount']
            : ((float)$transfer['amount'] / $totalTransferred) * $refundAmount;

        // Round to 2 decimals
        $reversalAmount = round($reversalAmount, 2);

        if ($reversalAmount <= 0) {
            continue;
        }

        // Create transfer reversal in Stripe
        $reversalResult = $client->createTransferReversal(
            $transfer['stripe_transfer_id'],
            $fullRefund ? null : $reversalAmount
        );

        if ($reversalResult['success']) {
            // Record the reversal
            $stmt = $pdo->prepare("
                INSERT INTO transfer_reversals
                (order_transfer_id, refund_id, stripe_reversal_id, amount, status, created_at)
                VALUES (?, ?, ?, ?, 'completed', NOW())
            ");
            $stmt->execute([
                $transfer['id'],
                $refundRecordId,
                $reversalResult['reversal_id'],
                $reversalAmount
            ]);

            // Mark transfer as reversed (or partially reversed)
            $stmt = $pdo->prepare("
                UPDATE order_transfers
                SET reversed = ?,
                    reversed_amount = COALESCE(reversed_amount, 0) + ?,
                    reversed_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([
                $fullRefund ? 1 : ($reversalAmount >= (float)$transfer['amount'] ? 1 : 0),
                $reversalAmount,
                $transfer['id']
            ]);

            $reversals[] = [
                'transfer_id' => $transfer['id'],
                'recipient_name' => $transfer['recipient_name'],
                'amount' => $reversalAmount,
                'stripe_reversal_id' => $reversalResult['reversal_id']
            ];
        } else {
            // Record failed reversal
            $stmt = $pdo->prepare("
                INSERT INTO transfer_reversals
                (order_transfer_id, refund_id, amount, status, error_message, created_at)
                VALUES (?, ?, ?, 'failed', ?, NOW())
            ");
            $stmt->execute([
                $transfer['id'],
                $refundRecordId,
                $reversalAmount,
                $reversalResult['error'] ?? 'Unknown error'
            ]);

            $errors[] = [
                'transfer_id' => $transfer['id'],
                'recipient_name' => $transfer['recipient_name'],
                'amount' => $reversalAmount,
                'error' => $reversalResult['error'] ?? 'Unknown error'
            ];

            error_log("Transfer reversal failed for transfer {$transfer['id']}: " . ($reversalResult['error'] ?? 'Unknown'));
        }
    }

    return [
        'success' => empty($errors),
        'reversals' => $reversals,
        'errors' => $errors,
        'total_reversed' => array_sum(array_column($reversals, 'amount'))
    ];
}

/**
 * Get refund history for an order
 *
 * @param PDO $pdo Database connection
 * @param int $orderId Order ID
 * @return array Refund history
 */
function getOrderRefunds(PDO $pdo, int $orderId): array {
    $stmt = $pdo->prepare("
        SELECT r.*,
               u.firstname as admin_firstname,
               u.lastname as admin_lastname
        FROM order_refunds r
        LEFT JOIN users u ON r.admin_id = u.id
        WHERE r.order_id = ?
        ORDER BY r.created_at DESC
    ");
    $stmt->execute([$orderId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get transfer reversals for a refund
 *
 * @param PDO $pdo Database connection
 * @param int $refundId Refund record ID
 * @return array Transfer reversals
 */
function getRefundTransferReversals(PDO $pdo, int $refundId): array {
    $stmt = $pdo->prepare("
        SELECT tr.*,
               ot.stripe_transfer_id,
               pr.name as recipient_name
        FROM transfer_reversals tr
        JOIN order_transfers ot ON tr.order_transfer_id = ot.id
        JOIN payment_recipients pr ON ot.payment_recipient_id = pr.id
        WHERE tr.refund_id = ?
        ORDER BY tr.created_at DESC
    ");
    $stmt->execute([$refundId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Check if an order can be refunded
 *
 * @param PDO $pdo Database connection
 * @param int $orderId Order ID
 * @return array Refund eligibility status
 */
function canOrderBeRefunded(PDO $pdo, int $orderId): array {
    $stmt = $pdo->prepare("
        SELECT o.*,
               COALESCE(o.refunded_amount, 0) as already_refunded
        FROM orders o
        WHERE o.id = ?
    ");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        return ['can_refund' => false, 'reason' => 'Order not found'];
    }

    if ($order['payment_status'] === 'refunded') {
        return ['can_refund' => false, 'reason' => 'Order is already fully refunded'];
    }

    if ($order['payment_status'] !== 'paid' && $order['payment_status'] !== 'partial_refund') {
        return [
            'can_refund' => false,
            'reason' => 'Order must be in paid status. Current: ' . $order['payment_status']
        ];
    }

    if (empty($order['gateway_transaction_id'])) {
        return ['can_refund' => false, 'reason' => 'No payment reference found'];
    }

    $remainingAmount = (float)$order['total_amount'] - (float)$order['already_refunded'];

    return [
        'can_refund' => true,
        'max_refund_amount' => $remainingAmount,
        'already_refunded' => (float)$order['already_refunded'],
        'total_amount' => (float)$order['total_amount'],
        'payment_gateway' => $order['gateway_code'] ?? 'stripe'
    ];
}

/**
 * Process a ticket-specific refund request
 *
 * @param PDO $pdo Database connection
 * @param int $ticketId Ticket ID
 * @param string $reason Refund reason
 * @param int|null $adminId Admin processing the refund
 * @return array Result
 */
function processTicketRefund(PDO $pdo, int $ticketId, string $reason = '', ?int $adminId = null): array {
    // Get ticket and order info
    $stmt = $pdo->prepare("
        SELECT t.*,
               oi.id as order_item_id,
               oi.total_price as item_price,
               oi.payment_recipient_id,
               o.id as order_id
        FROM event_tickets t
        JOIN order_items oi ON t.order_item_id = oi.id
        JOIN orders o ON oi.order_id = o.id
        WHERE t.id = ?
    ");
    $stmt->execute([$ticketId]);
    $ticket = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$ticket) {
        return ['success' => false, 'error' => 'Ticket not found'];
    }

    // Process partial refund for this ticket
    return processOrderRefund(
        $pdo,
        $ticket['order_id'],
        (float)$ticket['item_price'],
        $reason ?: 'Ticket refund: ' . $ticket['ticket_number'],
        $adminId,
        [$ticket['order_item_id']]
    );
}

/**
 * Get refund statistics for reporting
 *
 * @param PDO $pdo Database connection
 * @param string $startDate Start date (Y-m-d)
 * @param string $endDate End date (Y-m-d)
 * @return array Statistics
 */
function getRefundStatistics(PDO $pdo, string $startDate, string $endDate): array {
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) as total_refunds,
            COUNT(CASE WHEN refund_type = 'full' THEN 1 END) as full_refunds,
            COUNT(CASE WHEN refund_type = 'partial' THEN 1 END) as partial_refunds,
            SUM(amount) as total_amount,
            AVG(amount) as average_amount,
            COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed,
            COUNT(CASE WHEN status = 'failed' THEN 1 END) as failed
        FROM order_refunds
        WHERE created_at BETWEEN ? AND ?
    ");
    $stmt->execute([$startDate . ' 00:00:00', $endDate . ' 23:59:59']);

    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Retry failed transfer reversals
 *
 * @param PDO $pdo Database connection
 * @param int $refundId Refund ID to retry
 * @return array Result
 */
function retryFailedTransferReversals(PDO $pdo, int $refundId): array {
    // Get Stripe client
    $stripeApiKey = getenv('STRIPE_SECRET_KEY');
    if (!$stripeApiKey && function_exists('env')) {
        $stripeApiKey = env('STRIPE_SECRET_KEY', '');
    }

    if (!$stripeApiKey) {
        return ['success' => false, 'error' => 'Stripe API key not configured'];
    }

    $client = new StripeClient($stripeApiKey);

    // Get failed reversals
    $stmt = $pdo->prepare("
        SELECT tr.*,
               ot.stripe_transfer_id
        FROM transfer_reversals tr
        JOIN order_transfers ot ON tr.order_transfer_id = ot.id
        WHERE tr.refund_id = ?
          AND tr.status = 'failed'
    ");
    $stmt->execute([$refundId]);
    $failedReversals = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($failedReversals)) {
        return ['success' => true, 'message' => 'No failed reversals to retry'];
    }

    $results = [];
    $allSuccess = true;

    foreach ($failedReversals as $reversal) {
        $reversalResult = $client->createTransferReversal(
            $reversal['stripe_transfer_id'],
            (float)$reversal['amount'] > 0 ? (float)$reversal['amount'] : null
        );

        if ($reversalResult['success']) {
            $stmt = $pdo->prepare("
                UPDATE transfer_reversals
                SET status = 'completed',
                    stripe_reversal_id = ?,
                    error_message = NULL,
                    retried_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$reversalResult['reversal_id'], $reversal['id']]);

            $results[] = ['id' => $reversal['id'], 'status' => 'completed'];
        } else {
            $stmt = $pdo->prepare("
                UPDATE transfer_reversals
                SET error_message = ?,
                    retried_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$reversalResult['error'] ?? 'Retry failed', $reversal['id']]);

            $results[] = ['id' => $reversal['id'], 'status' => 'failed', 'error' => $reversalResult['error']];
            $allSuccess = false;
        }
    }

    // Update refund status if all reversals now complete
    if ($allSuccess) {
        $stmt = $pdo->prepare("
            UPDATE order_refunds
            SET status = 'completed',
                transfer_reversals_completed = 1,
                completed_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$refundId]);
    }

    return [
        'success' => $allSuccess,
        'results' => $results
    ];
}

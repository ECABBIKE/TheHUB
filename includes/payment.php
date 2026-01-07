<?php
/**
 * TheHUB V1.0 - Payment Functions
 *
 * Handles payment configuration, Swish links/QR, and order management.
 * Supports flexible payment config hierarchy: event > series > promotor > fallback
 */

require_once __DIR__ . '/../config.php';

// ============================================================================
// PAYMENT CONFIGURATION
// ============================================================================

/**
 * Get payment configuration for an event
 * Checks in order:
 * 1. Event's payment_recipient_id (new system)
 * 2. Series' payment_recipient_id (new system)
 * 3. Event-specific payment_configs (legacy)
 * 4. Series payment_configs (legacy)
 * 5. Promotor config (legacy)
 * 6. WooCommerce fallback
 *
 * @param int $eventId Event ID
 * @return array|null Payment config or null for WooCommerce fallback
 */
function getPaymentConfig(int $eventId): ?array {
    $pdo = hub_db();

    // Check if payment_recipients table exists (new system)
    $newSystemAvailable = false;
    try {
        $check = $pdo->query("SHOW TABLES LIKE 'payment_recipients'");
        $newSystemAvailable = $check->rowCount() > 0;
    } catch (Exception $e) {}

    if ($newSystemAvailable) {
        // 1. Check event's own payment_recipient_id
        try {
            $stmt = $pdo->prepare("
                SELECT pr.*, 'event_recipient' as config_source, e.name as source_name, 1 as swish_enabled
                FROM events e
                JOIN payment_recipients pr ON e.payment_recipient_id = pr.id
                WHERE e.id = ? AND pr.active = 1
            ");
            $stmt->execute([$eventId]);
            if ($config = $stmt->fetch(PDO::FETCH_ASSOC)) {
                return $config;
            }
        } catch (Exception $e) {}

        // 2. Check series' payment_recipient_id (if event has no recipient but belongs to series)
        try {
            $stmt = $pdo->prepare("
                SELECT pr.*, 'series_recipient' as config_source, s.name as source_name, 1 as swish_enabled
                FROM events e
                JOIN series s ON e.series_id = s.id
                JOIN payment_recipients pr ON s.payment_recipient_id = pr.id
                WHERE e.id = ? AND pr.active = 1 AND (e.payment_recipient_id IS NULL)
            ");
            $stmt->execute([$eventId]);
            if ($config = $stmt->fetch(PDO::FETCH_ASSOC)) {
                return $config;
            }
        } catch (Exception $e) {}
    }

    // Legacy system fallback (payment_configs table)

    // 3. Check event-specific config
    $stmt = $pdo->prepare("
        SELECT pc.*, 'event' as config_source
        FROM payment_configs pc
        WHERE pc.event_id = ?
    ");
    $stmt->execute([$eventId]);
    if ($config = $stmt->fetch(PDO::FETCH_ASSOC)) {
        return $config;
    }

    // 4. Check series config (if event belongs to a series)
    $stmt = $pdo->prepare("
        SELECT pc.*, 'series' as config_source, s.name as source_name
        FROM payment_configs pc
        JOIN series s ON s.id = pc.series_id
        JOIN events e ON e.series_id = s.id
        WHERE e.id = ?
    ");
    $stmt->execute([$eventId]);
    if ($config = $stmt->fetch(PDO::FETCH_ASSOC)) {
        return $config;
    }

    // 5. Check promotor config (user assigned to this event)
    $stmt = $pdo->prepare("
        SELECT pc.*, 'promotor' as config_source, au.full_name as source_name
        FROM payment_configs pc
        JOIN admin_users au ON au.id = pc.promotor_user_id
        JOIN promotor_events pe ON pe.user_id = au.id
        WHERE pe.event_id = ? AND pc.swish_enabled = 1
        LIMIT 1
    ");
    $stmt->execute([$eventId]);
    if ($config = $stmt->fetch(PDO::FETCH_ASSOC)) {
        return $config;
    }

    // 6. Check if any promotor for this event has swish directly on their profile
    $stmt = $pdo->prepare("
        SELECT au.swish_number, au.swish_name, au.full_name as source_name,
               'promotor_direct' as config_source, 1 as swish_enabled
        FROM admin_users au
        JOIN promotor_events pe ON pe.user_id = au.id
        WHERE pe.event_id = ? AND au.swish_number IS NOT NULL
        LIMIT 1
    ");
    $stmt->execute([$eventId]);
    if ($config = $stmt->fetch(PDO::FETCH_ASSOC)) {
        return $config;
    }

    // 7. No config found - use WooCommerce fallback
    return null;
}

/**
 * Check if Swish is available for an event
 */
function isSwishAvailable(int $eventId): bool {
    $config = getPaymentConfig($eventId);
    return $config !== null && !empty($config['swish_number']) && $config['swish_enabled'];
}

/**
 * Check if card payment is available for an event
 */
function isCardAvailable(int $eventId): bool {
    $config = getPaymentConfig($eventId);
    // Card is available via WooCommerce (fallback) or if explicitly enabled
    return $config === null || !empty($config['card_enabled']);
}

// ============================================================================
// SWISH LINK & QR GENERATION
// ============================================================================

/**
 * Generate Swish payment URL (opens Swish app on mobile)
 *
 * @param string $recipientNumber Swish number (with or without dashes)
 * @param float $amount Amount in SEK
 * @param string $message Payment reference/message (max 50 chars)
 * @return string Swish URL
 */
function generateSwishUrl(string $recipientNumber, float $amount, string $message = ''): string {
    // Clean phone number (remove spaces, dashes)
    $cleanNumber = preg_replace('/[^0-9]/', '', $recipientNumber);

    // Ensure it starts with 46 (Swedish country code) if it starts with 0
    if (substr($cleanNumber, 0, 1) === '0') {
        $cleanNumber = '46' . substr($cleanNumber, 1);
    }

    // Truncate message to 50 chars (Swish limit)
    $message = mb_substr($message, 0, 50);

    // Build Swish URL
    // Format: https://app.swish.nu/1/p/sw/?sw=PHONE&amt=AMOUNT&msg=MESSAGE
    $params = [
        'sw' => $cleanNumber,
        'amt' => number_format($amount, 0, '', ''),
        'msg' => $message
    ];

    return 'https://app.swish.nu/1/p/sw/?' . http_build_query($params);
}

/**
 * Generate Swish QR code data URL
 * Uses the Swish QR format for scanning with Swish app
 *
 * @param string $recipientNumber Swish number
 * @param float $amount Amount in SEK
 * @param string $message Payment reference
 * @param int $size QR code size in pixels
 * @return string Data URL for QR code image (SVG)
 */
function generateSwishQR(string $recipientNumber, float $amount, string $message = '', int $size = 200): string {
    // Clean phone number
    $cleanNumber = preg_replace('/[^0-9]/', '', $recipientNumber);
    if (substr($cleanNumber, 0, 1) === '0') {
        $cleanNumber = '46' . substr($cleanNumber, 1);
    }

    // Swish QR payload format
    // C = Create payment, phone number, amount (in öre), message
    $amountOre = (int)($amount * 100);
    $message = mb_substr($message, 0, 50);

    $payload = "C{$cleanNumber};{$amountOre};{$message}";

    // Use Google Charts API for QR generation (simple approach)
    // In production, consider using a PHP QR library like endroid/qr-code
    $qrUrl = 'https://chart.googleapis.com/chart?' . http_build_query([
        'cht' => 'qr',
        'chs' => "{$size}x{$size}",
        'chl' => $payload,
        'choe' => 'UTF-8'
    ]);

    return $qrUrl;
}

/**
 * Format Swish number for display
 */
function formatSwishNumber(string $number): string {
    $clean = preg_replace('/[^0-9]/', '', $number);

    // Mobile number format: 070-123 45 67
    if (strlen($clean) === 10 && substr($clean, 0, 1) === '0') {
        return substr($clean, 0, 3) . '-' .
               substr($clean, 3, 3) . ' ' .
               substr($clean, 6, 2) . ' ' .
               substr($clean, 8, 2);
    }

    // Company number format: 123-XXX XX XX
    if (strlen($clean) >= 7) {
        return substr($clean, 0, 3) . '-' .
               substr($clean, 3, 3) . ' ' .
               substr($clean, 6, 2) . ' ' .
               substr($clean, 8);
    }

    return $number;
}

// ============================================================================
// ORDER MANAGEMENT
// ============================================================================

/**
 * Generate unique order number
 * Format: ORD-YYYY-NNNNNN
 */
function generateOrderNumber(): string {
    $pdo = hub_db();
    $year = date('Y');

    // Get next sequence number for this year
    $stmt = $pdo->prepare("
        SELECT COUNT(*) + 1 as next_num
        FROM orders
        WHERE order_number LIKE ?
    ");
    $stmt->execute(["ORD-{$year}-%"]);
    $nextNum = $stmt->fetchColumn();

    return sprintf("ORD-%s-%06d", $year, $nextNum);
}

/**
 * Create a new order from registrations
 *
 * @param array $registrationIds Array of registration IDs
 * @param int $riderId Rider making the purchase
 * @param int $eventId Event ID
 * @return array Order data with order_id and payment info
 */
function createOrder(array $registrationIds, int $riderId, int $eventId): array {
    $pdo = hub_db();

    // Get rider info
    $stmt = $pdo->prepare("SELECT firstname, lastname, email FROM riders WHERE id = ?");
    $stmt->execute([$riderId]);
    $rider = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$rider) {
        throw new Exception('Rider not found');
    }

    // Get payment config for this event
    $paymentConfig = getPaymentConfig($eventId);

    // Get registrations with pricing
    $placeholders = implode(',', array_fill(0, count($registrationIds), '?'));
    $stmt = $pdo->prepare("
        SELECT er.id, er.first_name, er.last_name, er.category,
               COALESCE(epr.base_price, 0) as price
        FROM event_registrations er
        LEFT JOIN classes c ON c.name = er.category
        LEFT JOIN event_pricing_rules epr ON epr.event_id = er.event_id AND epr.class_id = c.id
        WHERE er.id IN ({$placeholders})
    ");
    $stmt->execute($registrationIds);
    $registrations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate total
    $subtotal = array_sum(array_column($registrations, 'price'));
    $total = $subtotal; // No discount for now

    // Generate order number and Swish message
    $orderNumber = generateOrderNumber();
    $swishMessage = substr($orderNumber, 4); // Remove "ORD-" prefix for shorter message

    $pdo->beginTransaction();

    try {
        // Create order
        $stmt = $pdo->prepare("
            INSERT INTO orders (
                order_number, rider_id, customer_email, customer_name,
                event_id, subtotal, discount, total_amount, currency,
                payment_method, payment_status,
                swish_number, swish_message,
                expires_at, created_at
            ) VALUES (
                ?, ?, ?, ?,
                ?, ?, 0, ?, 'SEK',
                'swish', 'pending',
                ?, ?,
                DATE_ADD(NOW(), INTERVAL 24 HOUR), NOW()
            )
        ");
        $stmt->execute([
            $orderNumber,
            $riderId,
            $rider['email'],
            $rider['firstname'] . ' ' . $rider['lastname'],
            $eventId,
            $subtotal,
            $total,
            $paymentConfig['swish_number'] ?? null,
            $swishMessage
        ]);

        $orderId = $pdo->lastInsertId();

        // Create order items
        foreach ($registrations as $reg) {
            $stmt = $pdo->prepare("
                INSERT INTO order_items (
                    order_id, item_type, registration_id,
                    description, unit_price, quantity, total_price
                ) VALUES (?, 'registration', ?, ?, ?, 1, ?)
            ");

            $description = $reg['first_name'] . ' ' . $reg['last_name'] . ' - ' . $reg['category'];
            $stmt->execute([
                $orderId,
                $reg['id'],
                $description,
                $reg['price'],
                $reg['price']
            ]);
        }

        // Update registrations with order_id
        $stmt = $pdo->prepare("
            UPDATE event_registrations
            SET order_id = ?
            WHERE id IN ({$placeholders})
        ");
        $stmt->execute(array_merge([$orderId], $registrationIds));

        $pdo->commit();

        // Generate payment links
        $swishUrl = null;
        $swishQR = null;

        if ($paymentConfig && !empty($paymentConfig['swish_number'])) {
            $swishUrl = generateSwishUrl(
                $paymentConfig['swish_number'],
                $total,
                $swishMessage
            );
            $swishQR = generateSwishQR(
                $paymentConfig['swish_number'],
                $total,
                $swishMessage
            );
        }

        return [
            'success' => true,
            'order_id' => $orderId,
            'order_number' => $orderNumber,
            'total' => $total,
            'swish_available' => $swishUrl !== null,
            'swish_url' => $swishUrl,
            'swish_qr' => $swishQR,
            'swish_number' => $paymentConfig['swish_number'] ?? null,
            'swish_name' => $paymentConfig['swish_name'] ?? null,
            'swish_message' => $swishMessage,
            'card_available' => isCardAvailable($eventId)
        ];

    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Mark order as paid
 *
 * @param int $orderId Order ID
 * @param string $paymentReference External reference (Swish ref, etc)
 * @return bool Success
 */
function markOrderPaid(int $orderId, string $paymentReference = ''): bool {
    $pdo = hub_db();

    $pdo->beginTransaction();

    try {
        // Update order
        $stmt = $pdo->prepare("
            UPDATE orders
            SET payment_status = 'paid',
                payment_reference = ?,
                paid_at = NOW()
            WHERE id = ? AND payment_status = 'pending'
        ");
        $stmt->execute([$paymentReference, $orderId]);

        if ($stmt->rowCount() === 0) {
            $pdo->rollBack();
            return false;
        }

        // Update linked registrations
        $stmt = $pdo->prepare("
            UPDATE event_registrations
            SET payment_status = 'paid', status = 'confirmed', confirmed_date = NOW()
            WHERE order_id = ?
        ");
        $stmt->execute([$orderId]);

        $pdo->commit();
        return true;

    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Get order by ID
 */
function getOrder(int $orderId): ?array {
    $pdo = hub_db();

    $stmt = $pdo->prepare("
        SELECT o.*, e.name as event_name, e.date as event_date
        FROM orders o
        LEFT JOIN events e ON o.event_id = e.id
        WHERE o.id = ?
    ");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        return null;
    }

    // Get order items
    $stmt = $pdo->prepare("
        SELECT * FROM order_items WHERE order_id = ?
    ");
    $stmt->execute([$orderId]);
    $order['items'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return $order;
}

/**
 * Get order by order number
 */
function getOrderByNumber(string $orderNumber): ?array {
    $pdo = hub_db();

    $stmt = $pdo->prepare("SELECT id FROM orders WHERE order_number = ?");
    $stmt->execute([$orderNumber]);
    $orderId = $stmt->fetchColumn();

    if (!$orderId) {
        return null;
    }

    return getOrder($orderId);
}

/**
 * Get pending orders for an event (for admin confirmation)
 */
function getPendingOrders(int $eventId): array {
    $pdo = hub_db();

    $stmt = $pdo->prepare("
        SELECT o.*, r.firstname, r.lastname
        FROM orders o
        LEFT JOIN riders r ON o.rider_id = r.id
        WHERE o.event_id = ? AND o.payment_status = 'pending'
        ORDER BY o.created_at DESC
    ");
    $stmt->execute([$eventId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

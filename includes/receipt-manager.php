<?php
/**
 * Receipt Manager
 *
 * Hanterar generering och lagring av kvitton.
 * Beräknar moms baserat på produkttyp.
 *
 * Svenska momssatser:
 * - 6% - Tävlingsanmälningar, kultur, sport
 * - 12% - Mat, hotell, camping
 * - 25% - Övrigt (merchandise, tjänster)
 *
 * @package TheHUB
 * @since 2026-01-29
 */

/**
 * Hämta momssats för en produkttyp
 *
 * @param PDO $pdo
 * @param string $productTypeCode
 * @return float
 */
function getVatRate($pdo, string $productTypeCode): float {
    // Standard momssatser om tabellen inte finns
    $defaultRates = [
        'registration' => 6.00,
        'series_registration' => 6.00,
        'merchandise' => 25.00,
        'food_drink' => 12.00,
        'camping' => 12.00,
        'service' => 25.00,
        'license' => 0.00,
    ];

    // Försök hämta från databasen
    try {
        $stmt = $pdo->prepare("SELECT vat_rate FROM product_types WHERE code = ? AND active = 1");
        $stmt->execute([$productTypeCode]);
        $rate = $stmt->fetchColumn();

        if ($rate !== false) {
            return (float)$rate;
        }
    } catch (Exception $e) {
        // Tabellen finns kanske inte än
    }

    return $defaultRates[$productTypeCode] ?? 25.00;
}

/**
 * Beräkna moms från pris inklusive moms
 *
 * @param float $priceInclVat Pris inklusive moms
 * @param float $vatRate Momssats i procent (t.ex. 6.00)
 * @return array ['price_excl_vat', 'vat_amount', 'price_incl_vat']
 */
function calculateVatFromInclusive(float $priceInclVat, float $vatRate): array {
    $vatMultiplier = $vatRate / 100;
    $priceExclVat = $priceInclVat / (1 + $vatMultiplier);
    $vatAmount = $priceInclVat - $priceExclVat;

    return [
        'price_excl_vat' => round($priceExclVat, 2),
        'vat_amount' => round($vatAmount, 2),
        'price_incl_vat' => round($priceInclVat, 2),
        'vat_rate' => $vatRate
    ];
}

/**
 * Beräkna moms från pris exklusive moms
 *
 * @param float $priceExclVat Pris exklusive moms
 * @param float $vatRate Momssats i procent
 * @return array
 */
function calculateVatFromExclusive(float $priceExclVat, float $vatRate): array {
    $vatMultiplier = $vatRate / 100;
    $vatAmount = $priceExclVat * $vatMultiplier;
    $priceInclVat = $priceExclVat + $vatAmount;

    return [
        'price_excl_vat' => round($priceExclVat, 2),
        'vat_amount' => round($vatAmount, 2),
        'price_incl_vat' => round($priceInclVat, 2),
        'vat_rate' => $vatRate
    ];
}

/**
 * Generera unikt kvittonummer
 *
 * @param PDO $pdo
 * @return string Format: REC-YYYY-NNNNNN
 */
function generateReceiptNumber($pdo): string {
    $year = date('Y');

    try {
        // Försök uppdatera och hämta nästa nummer atomiskt
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            INSERT INTO receipt_sequences (year, last_number)
            VALUES (?, 1)
            ON DUPLICATE KEY UPDATE last_number = last_number + 1
        ");
        $stmt->execute([$year]);

        $stmt = $pdo->prepare("SELECT last_number FROM receipt_sequences WHERE year = ?");
        $stmt->execute([$year]);
        $number = $stmt->fetchColumn();

        $pdo->commit();

        return sprintf("REC-%d-%06d", $year, $number);
    } catch (Exception $e) {
        $pdo->rollBack();
        // Fallback: använd timestamp
        return sprintf("REC-%d-%06d", $year, time() % 1000000);
    }
}

/**
 * Skapa kvitto för en order
 *
 * @param PDO $pdo
 * @param int $orderId
 * @return array ['success', 'receipt_id', 'receipt_number', 'error']
 */
function createReceiptForOrder($pdo, int $orderId): array {
    // Hämta order med all info
    $stmt = $pdo->prepare("
        SELECT o.*,
               pr.name AS seller_name,
               pr.org_number AS seller_org_number,
               pr.contact_email AS seller_email,
               pr.stripe_account_id
        FROM orders o
        LEFT JOIN payment_recipients pr ON o.payment_recipient_id = pr.id
        WHERE o.id = ?
    ");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        return ['success' => false, 'error' => 'Order hittades inte'];
    }

    // Hämta order items
    $stmt = $pdo->prepare("
        SELECT oi.*,
               COALESCE(pt.vat_rate, 6.00) AS product_vat_rate,
               COALESCE(pt.code, 'registration') AS product_type_code
        FROM order_items oi
        LEFT JOIN product_types pt ON oi.product_type_id = pt.id
        WHERE oi.order_id = ?
    ");
    $stmt->execute([$orderId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($items)) {
        return ['success' => false, 'error' => 'Inga orderrader hittades'];
    }

    $pdo->beginTransaction();

    try {
        // Generera kvittonummer
        $receiptNumber = generateReceiptNumber($pdo);

        // Beräkna totaler med moms
        $subtotal = 0;
        $totalVat = 0;
        $receiptItems = [];

        foreach ($items as $item) {
            $vatRate = (float)($item['product_vat_rate'] ?? 6.00);
            $totalPrice = (float)$item['total_price'];

            $vatCalc = calculateVatFromInclusive($totalPrice, $vatRate);

            $subtotal += $vatCalc['price_excl_vat'];
            $totalVat += $vatCalc['vat_amount'];

            $receiptItems[] = [
                'description' => $item['description'],
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
                'vat_rate' => $vatRate,
                'vat_amount' => $vatCalc['vat_amount'],
                'total_price' => $totalPrice,
                'order_item_id' => $item['id'],
                'product_type_code' => $item['product_type_code']
            ];
        }

        // Skapa kvitto
        $stmt = $pdo->prepare("
            INSERT INTO receipts (
                receipt_number, order_id, user_id, rider_id,
                payment_recipient_id,
                subtotal, vat_amount, discount, total_amount, currency,
                customer_name, customer_email,
                seller_name, seller_org_number,
                stripe_payment_intent_id,
                status, issued_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'issued', NOW())
        ");
        $stmt->execute([
            $receiptNumber,
            $orderId,
            $order['user_id'] ?? null,
            $order['rider_id'] ?? null,
            $order['payment_recipient_id'] ?? null,
            round($subtotal, 2),
            round($totalVat, 2),
            $order['discount'] ?? 0,
            $order['total_amount'],
            $order['currency'] ?? 'SEK',
            $order['customer_name'],
            $order['customer_email'],
            $order['seller_name'] ?? 'TheHUB',
            $order['seller_org_number'] ?? null,
            $order['gateway_transaction_id'] ?? null
        ]);

        $receiptId = $pdo->lastInsertId();

        // Skapa kvittorader
        $stmt = $pdo->prepare("
            INSERT INTO receipt_items (
                receipt_id, description, quantity, unit_price,
                vat_rate, vat_amount, total_price,
                order_item_id, product_type_code
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($receiptItems as $item) {
            $stmt->execute([
                $receiptId,
                $item['description'],
                $item['quantity'],
                $item['unit_price'],
                $item['vat_rate'],
                $item['vat_amount'],
                $item['total_price'],
                $item['order_item_id'],
                $item['product_type_code']
            ]);
        }

        // Uppdatera order med moms-info
        $stmt = $pdo->prepare("
            UPDATE orders SET vat_amount = ? WHERE id = ?
        ");
        $stmt->execute([round($totalVat, 2), $orderId]);

        $pdo->commit();

        return [
            'success' => true,
            'receipt_id' => $receiptId,
            'receipt_number' => $receiptNumber,
            'vat_amount' => round($totalVat, 2)
        ];

    } catch (Exception $e) {
        $pdo->rollBack();
        return ['success' => false, 'error' => 'Databasfel: ' . $e->getMessage()];
    }
}

/**
 * Hämta kvitto med all info
 *
 * @param PDO $pdo
 * @param int $receiptId
 * @return array|null
 */
function getReceipt($pdo, int $receiptId): ?array {
    $stmt = $pdo->prepare("
        SELECT r.*,
               o.order_number,
               o.payment_method,
               o.paid_at
        FROM receipts r
        JOIN orders o ON r.order_id = o.id
        WHERE r.id = ?
    ");
    $stmt->execute([$receiptId]);
    $receipt = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$receipt) {
        return null;
    }

    // Hämta rader
    $stmt = $pdo->prepare("
        SELECT * FROM receipt_items WHERE receipt_id = ? ORDER BY id
    ");
    $stmt->execute([$receiptId]);
    $receipt['items'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Gruppera moms per sats
    $vatBreakdown = [];
    foreach ($receipt['items'] as $item) {
        $rate = $item['vat_rate'];
        if (!isset($vatBreakdown[$rate])) {
            $vatBreakdown[$rate] = ['rate' => $rate, 'base' => 0, 'vat' => 0];
        }
        $vatBreakdown[$rate]['base'] += ($item['total_price'] - $item['vat_amount']);
        $vatBreakdown[$rate]['vat'] += $item['vat_amount'];
    }
    $receipt['vat_breakdown'] = array_values($vatBreakdown);

    return $receipt;
}

/**
 * Hämta kvitton för en användare/rider
 *
 * @param PDO $pdo
 * @param int|null $userId
 * @param int|null $riderId
 * @param int $limit
 * @return array
 */
function getUserReceipts($pdo, ?int $userId = null, ?int $riderId = null, int $limit = 50): array {
    $where = [];
    $params = [];

    if ($userId) {
        $where[] = "r.user_id = ?";
        $params[] = $userId;
    }
    if ($riderId) {
        $where[] = "r.rider_id = ?";
        $params[] = $riderId;
    }

    if (empty($where)) {
        return [];
    }

    $whereClause = implode(' OR ', $where);

    $stmt = $pdo->prepare("
        SELECT r.*,
               o.order_number,
               o.payment_method,
               pr.name AS seller_name
        FROM receipts r
        JOIN orders o ON r.order_id = o.id
        LEFT JOIN payment_recipients pr ON r.payment_recipient_id = pr.id
        WHERE ($whereClause)
        AND r.status = 'issued'
        ORDER BY r.issued_at DESC
        LIMIT ?
    ");
    $params[] = $limit;
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Hämta momsrapport för en betalningsmottagare
 *
 * @param PDO $pdo
 * @param int $paymentRecipientId
 * @param string $fromDate YYYY-MM-DD
 * @param string $toDate YYYY-MM-DD
 * @return array
 */
function getVatReport($pdo, int $paymentRecipientId, string $fromDate, string $toDate): array {
    $stmt = $pdo->prepare("
        SELECT
            ri.vat_rate,
            COUNT(DISTINCT r.id) AS receipt_count,
            SUM(ri.total_price - ri.vat_amount) AS total_excl_vat,
            SUM(ri.vat_amount) AS total_vat,
            SUM(ri.total_price) AS total_incl_vat
        FROM receipt_items ri
        JOIN receipts r ON ri.receipt_id = r.id
        WHERE r.payment_recipient_id = ?
        AND r.issued_at BETWEEN ? AND ?
        AND r.status = 'issued'
        GROUP BY ri.vat_rate
        ORDER BY ri.vat_rate
    ");
    $stmt->execute([$paymentRecipientId, $fromDate, $toDate . ' 23:59:59']);

    $breakdown = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Summering
    $totals = [
        'total_excl_vat' => 0,
        'total_vat' => 0,
        'total_incl_vat' => 0,
        'receipt_count' => 0
    ];

    foreach ($breakdown as $row) {
        $totals['total_excl_vat'] += $row['total_excl_vat'];
        $totals['total_vat'] += $row['total_vat'];
        $totals['total_incl_vat'] += $row['total_incl_vat'];
        $totals['receipt_count'] = max($totals['receipt_count'], $row['receipt_count']);
    }

    return [
        'payment_recipient_id' => $paymentRecipientId,
        'from_date' => $fromDate,
        'to_date' => $toDate,
        'breakdown' => $breakdown,
        'totals' => $totals
    ];
}

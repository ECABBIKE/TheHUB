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
 * MULTI-SELLER SUPPORT:
 * Om en order har produkter från flera säljare (payment_recipients)
 * skapas ett separat kvitto per säljare. Detta är korrekt enligt
 * svenska regler - kvittot måste visa rätt säljare med org.nr.
 *
 * @param PDO $pdo
 * @param int $orderId
 * @return array ['success', 'receipts' => [...], 'receipt_id', 'receipt_number', 'error']
 */
function createReceiptForOrder($pdo, int $orderId): array {
    // Hämta order
    $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        return ['success' => false, 'error' => 'Order hittades inte'];
    }

    // Hämta order items med säljarinformation
    $stmt = $pdo->prepare("
        SELECT oi.*,
               COALESCE(pt.vat_rate, 6.00) AS product_vat_rate,
               COALESCE(pt.code, 'registration') AS product_type_code,
               pr.id AS recipient_id,
               pr.name AS recipient_name,
               pr.org_number AS recipient_org_number,
               pr.contact_email AS recipient_email,
               pr.address AS recipient_address,
               pr.vat_number AS recipient_vat_number
        FROM order_items oi
        LEFT JOIN product_types pt ON oi.product_type_id = pt.id
        LEFT JOIN payment_recipients pr ON oi.payment_recipient_id = pr.id
        WHERE oi.order_id = ?
    ");
    $stmt->execute([$orderId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($items)) {
        return ['success' => false, 'error' => 'Inga orderrader hittades'];
    }

    // Gruppera items per säljare (payment_recipient_id)
    // NULL = TheHUB/plattformen som säljare
    $itemsBySeller = [];
    foreach ($items as $item) {
        $sellerId = $item['recipient_id'] ?? 'platform';
        if (!isset($itemsBySeller[$sellerId])) {
            $itemsBySeller[$sellerId] = [
                'recipient_id' => $item['recipient_id'],
                'recipient_name' => $item['recipient_name'] ?? 'TheHUB',
                'recipient_org_number' => $item['recipient_org_number'],
                'recipient_address' => $item['recipient_address'] ?? null,
                'recipient_vat_number' => $item['recipient_vat_number'] ?? null,
                'items' => []
            ];
        }
        $itemsBySeller[$sellerId]['items'][] = $item;
    }

    $pdo->beginTransaction();
    $receipts = [];
    $totalVatAll = 0;

    // Get order-level discount to distribute proportionally
    $orderDiscount = (float)($order['discount'] ?? 0);
    $orderSubtotal = (float)($order['subtotal'] ?? 0);

    try {
        foreach ($itemsBySeller as $sellerId => $sellerData) {
            // Generera kvittonummer för denna säljare
            $receiptNumber = generateReceiptNumber($pdo);

            // Beräkna totaler med moms för denna säljares produkter
            $sellerItemsTotal = 0;
            $receiptItems = [];

            // First pass: sum up seller items total (before discount)
            foreach ($sellerData['items'] as $item) {
                $sellerItemsTotal += (float)$item['total_price'];
            }

            // Calculate this seller's share of the order discount (proportional)
            $sellerDiscount = 0;
            if ($orderDiscount > 0 && $orderSubtotal > 0) {
                $sellerDiscount = round($orderDiscount * ($sellerItemsTotal / $orderSubtotal), 2);
            }

            // The actual amount paid for this seller's items
            $sellerPaidTotal = $sellerItemsTotal - $sellerDiscount;

            // Calculate VAT on the actual paid amount (after discount)
            $avgVatRate = 6.00; // Default, will be overridden if items have rates
            foreach ($sellerData['items'] as $item) {
                $avgVatRate = (float)($item['product_vat_rate'] ?? 6.00);
                break; // Use first item's rate (typically all same for registrations)
            }

            $vatCalcTotal = calculateVatFromInclusive($sellerPaidTotal, $avgVatRate);
            $totalVat = $vatCalcTotal['vat_amount'];
            $subtotal = $vatCalcTotal['price_excl_vat'];

            // Build receipt items (showing original prices before discount)
            foreach ($sellerData['items'] as $item) {
                $vatRate = (float)($item['product_vat_rate'] ?? 6.00);
                $totalPrice = (float)$item['total_price'];
                $vatCalc = calculateVatFromInclusive($totalPrice, $vatRate);

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

            $totalVatAll += $totalVat;

            // Skapa kvitto för denna säljare
            $stmt = $pdo->prepare("
                INSERT INTO receipts (
                    receipt_number, order_id, user_id, rider_id,
                    payment_recipient_id,
                    subtotal, vat_amount, discount, total_amount, currency,
                    customer_name, customer_email,
                    seller_name, seller_org_number, seller_address, seller_vat_number,
                    stripe_payment_intent_id,
                    status, issued_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'issued', NOW())
            ");
            $stmt->execute([
                $receiptNumber,
                $orderId,
                $order['user_id'] ?? null,
                $order['rider_id'] ?? null,
                $sellerData['recipient_id'],
                round($subtotal, 2),
                round($totalVat, 2),
                round($sellerDiscount, 2),
                round($sellerPaidTotal, 2),
                $order['currency'] ?? 'SEK',
                $order['customer_name'],
                $order['customer_email'],
                $sellerData['recipient_name'],
                $sellerData['recipient_org_number'],
                $sellerData['recipient_address'],
                $sellerData['recipient_vat_number'],
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

            $receipts[] = [
                'receipt_id' => $receiptId,
                'receipt_number' => $receiptNumber,
                'seller_name' => $sellerData['recipient_name'],
                'total_amount' => round($sellerTotal, 2),
                'vat_amount' => round($totalVat, 2)
            ];
        }

        // Uppdatera order med moms-info
        $stmt = $pdo->prepare("
            UPDATE orders SET vat_amount = ? WHERE id = ?
        ");
        $stmt->execute([round($totalVatAll, 2), $orderId]);

        $pdo->commit();

        // Return backwards-compatible + new format
        return [
            'success' => true,
            'receipts' => $receipts,
            // Backwards compatibility - returnera första kvittot
            'receipt_id' => $receipts[0]['receipt_id'] ?? null,
            'receipt_number' => $receipts[0]['receipt_number'] ?? null,
            'vat_amount' => round($totalVatAll, 2),
            'multi_seller' => count($receipts) > 1
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

// ============================================================
// SELLER WEEKLY REPORTS
// ============================================================

/**
 * Generera veckorapport för en säljare
 *
 * Skapar en rapport med alla försäljningar under veckan,
 * inklusive detaljer om kunder, produkter och överföringar.
 *
 * @param PDO $pdo
 * @param int $paymentRecipientId
 * @param string|null $weekStart Start av veckan (YYYY-MM-DD), null = förra veckan
 * @return array ['success', 'report_id', 'report' => [...]]
 */
function generateWeeklySellerReport($pdo, int $paymentRecipientId, ?string $weekStart = null): array {
    // Beräkna veckans start/slut
    if ($weekStart === null) {
        // Förra veckans måndag
        $weekStart = date('Y-m-d', strtotime('monday last week'));
    }
    $weekEnd = date('Y-m-d', strtotime($weekStart . ' +6 days'));

    // Hämta säljarinfo
    $stmt = $pdo->prepare("
        SELECT id, name, org_number, contact_email, stripe_account_id
        FROM payment_recipients WHERE id = ?
    ");
    $stmt->execute([$paymentRecipientId]);
    $seller = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$seller) {
        return ['success' => false, 'error' => 'Säljare hittades inte'];
    }

    // Kolla om rapport redan finns
    $stmt = $pdo->prepare("
        SELECT id FROM seller_reports
        WHERE payment_recipient_id = ? AND period_start = ? AND period_end = ?
    ");
    $stmt->execute([$paymentRecipientId, $weekStart, $weekEnd]);
    if ($stmt->fetch()) {
        return ['success' => false, 'error' => 'Rapport för denna period finns redan'];
    }

    // Hämta försäljningsdata för perioden
    $stmt = $pdo->prepare("
        SELECT
            oi.id AS item_id,
            oi.description,
            oi.quantity,
            oi.unit_price,
            oi.total_price,
            COALESCE(oi.seller_amount, oi.total_price) AS seller_amount,
            o.id AS order_id,
            o.order_number,
            o.paid_at AS order_date,
            o.customer_name,
            o.customer_email,
            e.name AS event_name,
            e.date AS event_date,
            ot.amount AS transfer_amount,
            ot.status AS transfer_status,
            ot.stripe_transfer_id
        FROM order_items oi
        JOIN orders o ON oi.order_id = o.id
        LEFT JOIN events e ON oi.event_id = e.id
        LEFT JOIN order_transfers ot ON ot.order_id = o.id AND ot.payment_recipient_id = ?
        WHERE oi.payment_recipient_id = ?
          AND o.payment_status = 'paid'
          AND DATE(o.paid_at) BETWEEN ? AND ?
        ORDER BY o.paid_at ASC
    ");
    $stmt->execute([$paymentRecipientId, $paymentRecipientId, $weekStart, $weekEnd]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($items)) {
        return [
            'success' => true,
            'report_id' => null,
            'report' => [
                'seller' => $seller,
                'period_start' => $weekStart,
                'period_end' => $weekEnd,
                'total_sales' => 0,
                'total_items' => 0,
                'total_orders' => 0,
                'items' => []
            ],
            'message' => 'Ingen försäljning under perioden'
        ];
    }

    // Beräkna totaler
    $totalSales = 0;
    $totalSellerAmount = 0;
    $orderIds = [];
    $transfersAmount = 0;
    $pendingAmount = 0;

    foreach ($items as $item) {
        $totalSales += $item['seller_amount'];
        $orderIds[$item['order_id']] = true;

        if ($item['transfer_status'] === 'completed') {
            $transfersAmount += $item['transfer_amount'] ?? 0;
        } else {
            $pendingAmount += $item['seller_amount'];
        }
    }

    // Beräkna plattformsavgift (skillnaden mellan total försäljning och transfers)
    $platformFees = $totalSales - $transfersAmount - $pendingAmount;
    if ($platformFees < 0) $platformFees = 0;

    $pdo->beginTransaction();

    try {
        // Skapa rapport
        $stmt = $pdo->prepare("
            INSERT INTO seller_reports (
                payment_recipient_id, report_type, period_start, period_end,
                total_sales, total_items, total_orders, platform_fees,
                net_amount, transfers_amount, pending_amount, status
            ) VALUES (?, 'weekly', ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft')
        ");
        $stmt->execute([
            $paymentRecipientId,
            $weekStart,
            $weekEnd,
            round($totalSales, 2),
            count($items),
            count($orderIds),
            round($platformFees, 2),
            round($totalSales - $platformFees, 2),
            round($transfersAmount, 2),
            round($pendingAmount, 2)
        ]);

        $reportId = $pdo->lastInsertId();

        // Skapa rapportrader
        $stmt = $pdo->prepare("
            INSERT INTO seller_report_items (
                report_id, order_id, order_number, order_date,
                item_description, quantity, unit_price, total_price,
                customer_name, customer_email, event_name, event_date
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($items as $item) {
            $stmt->execute([
                $reportId,
                $item['order_id'],
                $item['order_number'],
                $item['order_date'],
                $item['description'],
                $item['quantity'],
                $item['unit_price'],
                $item['seller_amount'],
                $item['customer_name'],
                $item['customer_email'],
                $item['event_name'],
                $item['event_date']
            ]);
        }

        $pdo->commit();

        return [
            'success' => true,
            'report_id' => $reportId,
            'report' => [
                'id' => $reportId,
                'seller' => $seller,
                'period_start' => $weekStart,
                'period_end' => $weekEnd,
                'total_sales' => round($totalSales, 2),
                'total_items' => count($items),
                'total_orders' => count($orderIds),
                'platform_fees' => round($platformFees, 2),
                'transfers_amount' => round($transfersAmount, 2),
                'pending_amount' => round($pendingAmount, 2),
                'items' => $items
            ]
        ];

    } catch (Exception $e) {
        $pdo->rollBack();
        return ['success' => false, 'error' => 'Databasfel: ' . $e->getMessage()];
    }
}

/**
 * Skicka veckorapport via e-post
 *
 * @param PDO $pdo
 * @param int $reportId
 * @return array
 */
function sendWeeklySellerReport($pdo, int $reportId): array {
    // Hämta rapport med säljarinfo
    $stmt = $pdo->prepare("
        SELECT sr.*, pr.name AS seller_name, pr.contact_email
        FROM seller_reports sr
        JOIN payment_recipients pr ON sr.payment_recipient_id = pr.id
        WHERE sr.id = ?
    ");
    $stmt->execute([$reportId]);
    $report = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$report) {
        return ['success' => false, 'error' => 'Rapport hittades inte'];
    }

    if (!$report['contact_email']) {
        return ['success' => false, 'error' => 'Säljaren har ingen e-postadress'];
    }

    // Hämta rapportrader
    $stmt = $pdo->prepare("
        SELECT * FROM seller_report_items WHERE report_id = ? ORDER BY order_date ASC
    ");
    $stmt->execute([$reportId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Bygg e-postinnehåll
    $periodStart = date('j M', strtotime($report['period_start']));
    $periodEnd = date('j M Y', strtotime($report['period_end']));

    $subject = "Veckorapport {$periodStart} - {$periodEnd} - TheHUB";

    $body = "<h2>Veckorapport för {$report['seller_name']}</h2>";
    $body .= "<p>Period: {$periodStart} - {$periodEnd}</p>";

    $body .= "<h3>Sammanfattning</h3>";
    $body .= "<table style='border-collapse: collapse; width: 100%; max-width: 400px;'>";
    $body .= "<tr><td style='padding: 8px; border-bottom: 1px solid #ddd;'>Antal ordrar</td><td style='padding: 8px; border-bottom: 1px solid #ddd; text-align: right;'><strong>{$report['total_orders']}</strong></td></tr>";
    $body .= "<tr><td style='padding: 8px; border-bottom: 1px solid #ddd;'>Antal produkter</td><td style='padding: 8px; border-bottom: 1px solid #ddd; text-align: right;'><strong>{$report['total_items']}</strong></td></tr>";
    $body .= "<tr><td style='padding: 8px; border-bottom: 1px solid #ddd;'>Total försäljning</td><td style='padding: 8px; border-bottom: 1px solid #ddd; text-align: right;'><strong>" . number_format($report['total_sales'], 2, ',', ' ') . " kr</strong></td></tr>";
    $body .= "<tr><td style='padding: 8px; border-bottom: 1px solid #ddd;'>Överförda medel</td><td style='padding: 8px; border-bottom: 1px solid #ddd; text-align: right;'>" . number_format($report['transfers_amount'], 2, ',', ' ') . " kr</td></tr>";

    if ($report['pending_amount'] > 0) {
        $body .= "<tr><td style='padding: 8px; border-bottom: 1px solid #ddd;'>Väntar på överföring</td><td style='padding: 8px; border-bottom: 1px solid #ddd; text-align: right;'>" . number_format($report['pending_amount'], 2, ',', ' ') . " kr</td></tr>";
    }
    $body .= "</table>";

    if (!empty($items)) {
        $body .= "<h3>Detaljer</h3>";
        $body .= "<table style='border-collapse: collapse; width: 100%;'>";
        $body .= "<thead><tr style='background: #f5f5f5;'>";
        $body .= "<th style='padding: 8px; text-align: left; border-bottom: 2px solid #ddd;'>Datum</th>";
        $body .= "<th style='padding: 8px; text-align: left; border-bottom: 2px solid #ddd;'>Order</th>";
        $body .= "<th style='padding: 8px; text-align: left; border-bottom: 2px solid #ddd;'>Produkt</th>";
        $body .= "<th style='padding: 8px; text-align: left; border-bottom: 2px solid #ddd;'>Kund</th>";
        $body .= "<th style='padding: 8px; text-align: right; border-bottom: 2px solid #ddd;'>Belopp</th>";
        $body .= "</tr></thead><tbody>";

        foreach ($items as $item) {
            $date = date('j/n', strtotime($item['order_date']));
            $body .= "<tr>";
            $body .= "<td style='padding: 8px; border-bottom: 1px solid #eee;'>{$date}</td>";
            $body .= "<td style='padding: 8px; border-bottom: 1px solid #eee;'>{$item['order_number']}</td>";
            $body .= "<td style='padding: 8px; border-bottom: 1px solid #eee;'>{$item['item_description']}</td>";
            $body .= "<td style='padding: 8px; border-bottom: 1px solid #eee;'>{$item['customer_name']}</td>";
            $body .= "<td style='padding: 8px; border-bottom: 1px solid #eee; text-align: right;'>" . number_format($item['total_price'], 2, ',', ' ') . " kr</td>";
            $body .= "</tr>";
        }

        $body .= "</tbody></table>";
    }

    $body .= "<p style='margin-top: 20px; color: #666;'>Detta är en automatisk rapport från TheHUB.</p>";

    // Skicka e-post
    try {
        if (function_exists('hub_send_email')) {
            hub_send_email($report['contact_email'], $subject, $body);
        } else {
            // Fallback till mail()
            $headers = "Content-Type: text/html; charset=UTF-8\r\n";
            $headers .= "From: TheHUB <noreply@gravityseries.se>\r\n";
            mail($report['contact_email'], $subject, $body, $headers);
        }

        // Markera som skickad
        $stmt = $pdo->prepare("
            UPDATE seller_reports SET status = 'sent', sent_at = NOW() WHERE id = ?
        ");
        $stmt->execute([$reportId]);

        return ['success' => true, 'message' => 'Rapport skickad till ' . $report['contact_email']];

    } catch (Exception $e) {
        return ['success' => false, 'error' => 'Kunde inte skicka e-post: ' . $e->getMessage()];
    }
}

/**
 * Generera och skicka veckorapporter för alla aktiva säljare
 *
 * @param PDO $pdo
 * @param string|null $weekStart
 * @return array
 */
function sendAllWeeklyReports($pdo, ?string $weekStart = null): array {
    // Hämta alla aktiva säljare med försäljning
    $stmt = $pdo->prepare("
        SELECT DISTINCT pr.id, pr.name
        FROM payment_recipients pr
        JOIN order_items oi ON oi.payment_recipient_id = pr.id
        JOIN orders o ON oi.order_id = o.id
        WHERE pr.active = 1
          AND o.payment_status = 'paid'
    ");
    $stmt->execute();
    $sellers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $results = [
        'success' => true,
        'generated' => 0,
        'sent' => 0,
        'skipped' => 0,
        'errors' => []
    ];

    foreach ($sellers as $seller) {
        $reportResult = generateWeeklySellerReport($pdo, $seller['id'], $weekStart);

        if ($reportResult['success'] && $reportResult['report_id']) {
            $results['generated']++;

            $sendResult = sendWeeklySellerReport($pdo, $reportResult['report_id']);
            if ($sendResult['success']) {
                $results['sent']++;
            } else {
                $results['errors'][] = "{$seller['name']}: {$sendResult['error']}";
            }
        } elseif ($reportResult['success'] && !$reportResult['report_id']) {
            // Ingen försäljning
            $results['skipped']++;
        } else {
            $results['errors'][] = "{$seller['name']}: {$reportResult['error']}";
        }
    }

    return $results;
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

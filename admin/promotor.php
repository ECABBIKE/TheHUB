<?php
/**
 * Promotor Panel
 * - Admin/Super Admin: Financial payout overview per payment recipient
 * - Promotor: Shows promotor's assigned events
 * Uses standard admin layout with sidebar
 */

require_once __DIR__ . '/../config.php';
require_admin();

// Require at least promotor role
if (!hasRole('promotor')) {
    set_flash('error', 'Du har inte behörighet till denna sida');
    redirect('/');
}

$db = getDB();
$currentUser = getCurrentAdmin();
$userId = $currentUser['id'] ?? 0;
$isAdmin = hasRole('admin');

// ============================================================
// AJAX: Update platform fee percent
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $isAdmin) {
    header('Content-Type: application/json');

    if ($_POST['action'] === 'update_platform_fee') {
        $recipientId = intval($_POST['recipient_id'] ?? 0);
        $newFee = floatval($_POST['platform_fee_percent'] ?? 2.00);

        if ($recipientId <= 0 || $newFee < 0 || $newFee > 100) {
            echo json_encode(['success' => false, 'error' => 'Ogiltigt värde']);
            exit;
        }

        try {
            $db->execute("UPDATE payment_recipients SET platform_fee_percent = ? WHERE id = ?", [$newFee, $recipientId]);
            echo json_encode(['success' => true, 'fee' => $newFee]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Okänd åtgärd']);
    exit;
}

// ============================================================
// ADMIN VIEW: Financial payout overview
// ============================================================
$payoutData = [];
$payoutTotals = [];
$filterYear = isset($_GET['year']) ? intval($_GET['year']) : (int)date('Y');
$filterRecipient = isset($_GET['recipient']) ? intval($_GET['recipient']) : 0;
$filterEvent = isset($_GET['event']) ? intval($_GET['event']) : 0;
$filterMonth = isset($_GET['month']) ? intval($_GET['month']) : 0; // 0 = helår

if ($isAdmin) {
    // Fee constants
    $STRIPE_PERCENT = 1.5;
    $STRIPE_FIXED = 2.00; // SEK per transaction (fallback estimate)
    $SWISH_FEE = 3.00;    // SEK per Swish transaction
    $VAT_RATE = 6;         // Standard sport event VAT

    // Check if stripe_fee column exists (migration 049)
    $hasStripeFeeCol = false;
    try {
        $colCheck = $db->getAll("SHOW COLUMNS FROM orders LIKE 'stripe_fee'");
        $hasStripeFeeCol = !empty($colCheck);
    } catch (Exception $e) {}

    // Get platform fee config (from first active recipient)
    $platformFeePct = 2.00;
    $platformFeeFixed = 0;
    $platformFeeType = 'percent';
    try {
        $prRow = $db->getRow("SELECT * FROM payment_recipients WHERE active = 1 ORDER BY id LIMIT 1");
        if ($prRow) {
            $platformFeePct = (float)($prRow['platform_fee_percent'] ?? 2.00);
            $platformFeeFixed = (float)($prRow['platform_fee_fixed'] ?? 0);
            $platformFeeType = $prRow['platform_fee_type'] ?? 'percent';
            $recipientInfo = $prRow;
        }
    } catch (Exception $e) {}

    // Fetch individual paid orders with filters
    $orderRows = [];
    try {
        $stripeFeeCol = $hasStripeFeeCol ? "o.stripe_fee," : "NULL as stripe_fee,";

        // Build WHERE conditions
        $conditions = ["o.payment_status = 'paid'", "YEAR(o.created_at) = ?"];
        $params = [$filterYear];

        if ($filterMonth > 0 && $filterMonth <= 12) {
            $conditions[] = "MONTH(o.created_at) = ?";
            $params[] = $filterMonth;
        }

        if ($filterEvent > 0) {
            $conditions[] = "o.event_id = ?";
            $params[] = $filterEvent;
        }

        if ($filterRecipient > 0) {
            $conditions[] = "(e.payment_recipient_id = ? OR s_series.payment_recipient_id = ?)";
            $params[] = $filterRecipient;
            $params[] = $filterRecipient;
        }

        $whereClause = implode(' AND ', $conditions);

        $orderRows = $db->getAll("
            SELECT o.id, o.order_number, o.total_amount, o.payment_method, o.payment_status,
                   {$stripeFeeCol}
                   o.event_id, o.created_at,
                   COALESCE(e.name, s_name.sname, '-') as event_name
            FROM orders o
            LEFT JOIN events e ON o.event_id = e.id
            LEFT JOIN series s_series ON e.series_id = s_series.id
            LEFT JOIN (
                SELECT oi2.order_id, CONCAT(s2.name, ' (serie)') as sname
                FROM order_items oi2
                JOIN series_registrations sr2 ON sr2.id = oi2.series_registration_id
                JOIN series s2 ON s2.id = sr2.series_id
                WHERE oi2.item_type = 'series_registration'
                GROUP BY oi2.order_id
            ) s_name ON s_name.order_id = o.id AND o.event_id IS NULL
            WHERE {$whereClause}
            ORDER BY o.created_at DESC
        ", $params);
    } catch (Exception $e) {
        error_log("Promotor orders query error: " . $e->getMessage());
    }

    // Calculate per-order fees
    $payoutTotals = [
        'gross' => 0, 'payment_fees' => 0, 'platform_fees' => 0, 'net' => 0,
        'order_count' => 0, 'card_count' => 0, 'swish_count' => 0
    ];

    foreach ($orderRows as &$order) {
        $amount = (float)$order['total_amount'];
        $method = $order['payment_method'] ?? 'card';

        // Payment processing fee
        if (in_array($method, ['swish', 'swish_csv'])) {
            $order['payment_fee'] = $SWISH_FEE;
            $order['fee_type'] = 'actual';
            $payoutTotals['swish_count']++;
        } elseif ($method === 'card') {
            if ($order['stripe_fee'] !== null && (float)$order['stripe_fee'] > 0) {
                $order['payment_fee'] = round((float)$order['stripe_fee'], 2);
                $order['fee_type'] = 'actual';
            } else {
                $order['payment_fee'] = round(($amount * $STRIPE_PERCENT / 100) + $STRIPE_FIXED, 2);
                $order['fee_type'] = 'estimated';
            }
            $payoutTotals['card_count']++;
        } else {
            // manual/free - no payment fee
            $order['payment_fee'] = 0;
            $order['fee_type'] = 'none';
        }

        // Platform fee (supports percent, fixed, or both)
        if ($platformFeeType === 'fixed') {
            $order['platform_fee'] = $platformFeeFixed;
        } elseif ($platformFeeType === 'both') {
            $order['platform_fee'] = round(($amount * $platformFeePct / 100) + $platformFeeFixed, 2);
        } else {
            $order['platform_fee'] = round($amount * $platformFeePct / 100, 2);
        }

        // Net after fees
        $order['net_amount'] = round($amount - $order['payment_fee'] - $order['platform_fee'], 2);

        // Totals
        $payoutTotals['gross'] += $amount;
        $payoutTotals['payment_fees'] += $order['payment_fee'];
        $payoutTotals['platform_fees'] += $order['platform_fee'];
        $payoutTotals['net'] += $order['net_amount'];
        $payoutTotals['order_count']++;
    }
    unset($order);

    // Get available years for filter
    $availableYears = [];
    try {
        $availableYears = $db->getAll("SELECT DISTINCT YEAR(created_at) as yr FROM orders WHERE payment_status = 'paid' ORDER BY yr DESC");
    } catch (Exception $e) {}

    // Get all recipients for filter dropdown
    $allRecipients = [];
    try {
        $allRecipients = $db->getAll("SELECT id, name FROM payment_recipients WHERE active = 1 ORDER BY name");
    } catch (Exception $e) {}

    // Get events that have paid orders (for filter)
    $filterEvents = [];
    try {
        $filterEvents = $db->getAll("
            SELECT DISTINCT e.id, e.name, e.date
            FROM events e
            JOIN orders o ON o.event_id = e.id
            WHERE o.payment_status = 'paid' AND YEAR(o.created_at) = ?
            ORDER BY e.date DESC
        ", [$filterYear]);
    } catch (Exception $e) {}

    // Swedish month names
    $monthNames = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Mars', 4 => 'April',
        5 => 'Maj', 6 => 'Juni', 7 => 'Juli', 8 => 'Augusti',
        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'December'
    ];
}

// ============================================================
// PROMOTOR VIEW: Their assigned events/series/economy/media
// ============================================================
$promotorTab = $_GET['tab'] ?? 'event';
$promotorSeries = [];
$promotorEvents = [];
$promotorOrders = [];
$promotorOrderTotals = [];

if (!$isAdmin) {
    // Fee constants for promotor economy view
    $STRIPE_PERCENT = 1.5;
    $STRIPE_FIXED = 2.00;
    $SWISH_FEE = 3.00;

    // Check if stripe_fee column exists
    $hasStripeFeeCol = false;
    try {
        $colCheck = $db->getAll("SHOW COLUMNS FROM orders LIKE 'stripe_fee'");
        $hasStripeFeeCol = !empty($colCheck);
    } catch (Exception $e) {}

    // Check if series_id column exists on orders
    $hasOrderSeriesId = false;
    try {
        $colCheck2 = $db->getAll("SHOW COLUMNS FROM orders LIKE 'series_id'");
        $hasOrderSeriesId = !empty($colCheck2);
    } catch (Exception $e) {}

    // Get promotor's series IDs
    $promotorSeriesIds = [];
    try {
        $psRows = $db->getAll("SELECT series_id FROM promotor_series WHERE user_id = ?", [$userId]);
        $promotorSeriesIds = array_column($psRows, 'series_id');
    } catch (Exception $e) {}

    // Get promotor's event IDs
    $promotorEventIds = [];
    try {
        $peRows = $db->getAll("SELECT event_id FROM promotor_events WHERE user_id = ?", [$userId]);
        $promotorEventIds = array_column($peRows, 'event_id');
    } catch (Exception $e) {}

    // Get promotor's series with settings
    try {
        $promotorSeries = $db->getAll("
            SELECT s.id, s.name, s.year, s.logo,
                   s.allow_series_registration, s.series_discount_percent,
                   s.default_pricing_template_id,
                   m.filepath as banner_url,
                   COUNT(DISTINCT e.id) as event_count,
                   pt.name as template_name
            FROM series s
            JOIN promotor_series ps ON ps.series_id = s.id
            LEFT JOIN media m ON s.banner_media_id = m.id
            LEFT JOIN events e ON e.series_id = s.id AND YEAR(e.date) = YEAR(CURDATE())
            LEFT JOIN pricing_templates pt ON pt.id = s.default_pricing_template_id
            WHERE ps.user_id = ?
            GROUP BY s.id
            ORDER BY s.name
        ", [$userId]);
    } catch (Exception $e) {
        error_log("Promotor series error: " . $e->getMessage());
    }

    // Get promotor's events with registration + revenue data
    // Sort: upcoming first (by date ASC), then past events after
    try {
        $promotorEvents = $db->getAll("
            SELECT e.id, e.name, e.date, e.location, e.active, e.series_id,
                   e.max_participants,
                   s.name as series_name, s.logo as series_logo,
                   COALESCE(reg.total_count, 0) as total_registrations,
                   COALESCE(reg.paid_count, 0) as paid_count,
                   COALESCE(reg.pending_count, 0) as pending_count,
                   COALESCE(ord.gross_revenue, 0) as event_gross_revenue,
                   COALESCE(ord.order_count, 0) as order_count,
                   COALESCE(series_reg.series_count, 0) as series_registration_count,
                   COALESCE(series_rev.series_revenue, 0) as series_revenue,
                   CASE WHEN e.date >= CURDATE() THEN 0 ELSE 1 END as is_past
            FROM events e
            LEFT JOIN series s ON e.series_id = s.id
            JOIN promotor_events pe ON pe.event_id = e.id
            LEFT JOIN (
                SELECT event_id,
                       COUNT(*) as total_count,
                       SUM(CASE WHEN payment_status = 'paid' THEN 1 ELSE 0 END) as paid_count,
                       SUM(CASE WHEN payment_status = 'pending' THEN 1 ELSE 0 END) as pending_count
                FROM event_registrations
                WHERE status != 'cancelled'
                GROUP BY event_id
            ) reg ON reg.event_id = e.id
            LEFT JOIN (
                SELECT event_id,
                       SUM(CASE WHEN payment_status = 'paid' THEN total_amount ELSE 0 END) as gross_revenue,
                       SUM(CASE WHEN payment_status = 'paid' THEN 1 ELSE 0 END) as order_count
                FROM orders
                GROUP BY event_id
            ) ord ON ord.event_id = e.id
            LEFT JOIN (
                SELECT sre.event_id, COUNT(DISTINCT sr.id) as series_count
                FROM series_registration_events sre
                JOIN series_registrations sr ON sr.id = sre.series_registration_id
                WHERE sr.payment_status = 'paid' AND sr.status != 'cancelled'
                GROUP BY sre.event_id
            ) series_reg ON series_reg.event_id = e.id
            LEFT JOIN (
                SELECT sre2.event_id,
                       SUM(sr2.final_price / GREATEST(
                           (SELECT COUNT(*) FROM series_registration_events sre3 WHERE sre3.series_registration_id = sr2.id), 1
                       )) as series_revenue
                FROM series_registration_events sre2
                JOIN series_registrations sr2 ON sr2.id = sre2.series_registration_id
                WHERE sr2.payment_status = 'paid' AND sr2.status != 'cancelled'
                GROUP BY sre2.event_id
            ) series_rev ON series_rev.event_id = e.id
            WHERE pe.user_id = ?
            ORDER BY is_past ASC,
                     CASE WHEN e.date >= CURDATE() THEN e.date END ASC,
                     CASE WHEN e.date < CURDATE() THEN e.date END DESC
        ", [$userId]);
    } catch (Exception $e) {
        error_log("Promotor events error: " . $e->getMessage());
    }

    // Calculate net revenue per event (after fees)
    // Get platform fee for promotor's recipient
    $promotorPlatformPct = 2.00;
    $promotorPlatformFixed = 0;
    $promotorFeeType = 'percent';
    $promotorRecipientInfo = null;
    try {
        // Find recipient via series or events
        $recipientQuery = null;
        if (!empty($promotorSeriesIds)) {
            $placeholders = implode(',', array_fill(0, count($promotorSeriesIds), '?'));
            $recipientQuery = $db->getRow("
                SELECT pr.* FROM payment_recipients pr
                JOIN series s ON s.payment_recipient_id = pr.id
                WHERE s.id IN ({$placeholders}) AND pr.active = 1
                LIMIT 1
            ", $promotorSeriesIds);
        }
        if (!$recipientQuery && !empty($promotorEventIds)) {
            $placeholders = implode(',', array_fill(0, count($promotorEventIds), '?'));
            $recipientQuery = $db->getRow("
                SELECT pr.* FROM payment_recipients pr
                JOIN events e ON e.payment_recipient_id = pr.id
                WHERE e.id IN ({$placeholders}) AND pr.active = 1
                LIMIT 1
            ", $promotorEventIds);
        }
        if ($recipientQuery) {
            $promotorRecipientInfo = $recipientQuery;
            $promotorPlatformPct = (float)($recipientQuery['platform_fee_percent'] ?? 2.00);
            $promotorPlatformFixed = (float)($recipientQuery['platform_fee_fixed'] ?? 0);
            $promotorFeeType = $recipientQuery['platform_fee_type'] ?? 'percent';
        }
    } catch (Exception $e) {}

    // For event cards: calculate gross (event + series share) and net per event
    foreach ($promotorEvents as &$ev) {
        $eventGross = (float)$ev['event_gross_revenue'];
        $seriesShare = (float)$ev['series_revenue'];
        $gross = $eventGross + $seriesShare;
        $ev['gross_revenue'] = $gross;

        $orderCnt = (int)$ev['order_count'];
        $seriesCnt = (int)$ev['series_registration_count'];
        $totalPaidItems = $orderCnt + $seriesCnt;

        // Estimate payment fees
        if ($totalPaidItems > 0) {
            $avgAmount = $gross / $totalPaidItems;
            $estPaymentFees = $totalPaidItems * (($avgAmount * $STRIPE_PERCENT / 100) + $STRIPE_FIXED);
        } else {
            $estPaymentFees = 0;
        }

        // Platform fee
        if ($promotorFeeType === 'fixed') {
            $estPlatformFee = $promotorPlatformFixed * $totalPaidItems;
        } elseif ($promotorFeeType === 'both') {
            $estPlatformFee = ($gross * $promotorPlatformPct / 100) + ($promotorPlatformFixed * $totalPaidItems);
        } else {
            $estPlatformFee = $gross * $promotorPlatformPct / 100;
        }
        $ev['net_revenue'] = round($gross - $estPaymentFees - $estPlatformFee, 2);
        $ev['total_with_series'] = (int)$ev['total_registrations'] + $seriesCnt;
        $ev['paid_with_series'] = (int)$ev['paid_count'] + $seriesCnt; // series regs are pre-paid
    }
    unset($ev);

    // ---- ECONOMY TAB DATA ----
    if ($promotorTab === 'ekonomi') {
        $ecoYear = isset($_GET['year']) ? intval($_GET['year']) : (int)date('Y');
        $ecoMonth = isset($_GET['month']) ? intval($_GET['month']) : 0;
        $ecoEvent = isset($_GET['event']) ? intval($_GET['event']) : 0;

        // Build conditions for promotor's orders (via their events or series)
        $allEventIds = $promotorEventIds;
        // Also get events from promotor's series
        if (!empty($promotorSeriesIds)) {
            try {
                $placeholders = implode(',', array_fill(0, count($promotorSeriesIds), '?'));
                $seriesEventRows = $db->getAll("SELECT id FROM events WHERE series_id IN ({$placeholders})", $promotorSeriesIds);
                foreach ($seriesEventRows as $ser) {
                    if (!in_array($ser['id'], $allEventIds)) $allEventIds[] = $ser['id'];
                }
            } catch (Exception $e) {}
        }

        // Collect ALL order IDs belonging to this promotor:
        // 1. Orders with event_id matching promotor's events
        // 2. Orders containing series_registration items for promotor's series
        // 3. Orders with series_id matching promotor's series (if column exists)
        $stripeFeeCol = $hasStripeFeeCol ? "o.stripe_fee," : "NULL as stripe_fee,";

        // Base conditions
        $baseConditions = ["o.payment_status = 'paid'", "YEAR(o.created_at) = ?"];
        $baseParams = [$ecoYear];

        if ($ecoMonth > 0 && $ecoMonth <= 12) {
            $baseConditions[] = "MONTH(o.created_at) = ?";
            $baseParams[] = $ecoMonth;
        }
        if ($ecoEvent > 0) {
            $baseConditions[] = "o.event_id = ?";
            $baseParams[] = $ecoEvent;
        }

        // Build ownership condition: event_id OR series via order_items OR orders.series_id
        $ownerParts = [];
        $ownerParams = [];

        if (!empty($allEventIds)) {
            $placeholders = implode(',', array_fill(0, count($allEventIds), '?'));
            $ownerParts[] = "o.event_id IN ({$placeholders})";
            $ownerParams = array_merge($ownerParams, $allEventIds);
        }

        // Find orders that contain series_registration items for promotor's series
        if (!empty($promotorSeriesIds)) {
            $sPlaceholders = implode(',', array_fill(0, count($promotorSeriesIds), '?'));
            $ownerParts[] = "o.id IN (
                SELECT oi.order_id FROM order_items oi
                JOIN series_registrations sr ON sr.id = oi.series_registration_id
                WHERE sr.series_id IN ({$sPlaceholders})
            )";
            $ownerParams = array_merge($ownerParams, $promotorSeriesIds);

            // Also via orders.series_id if column exists
            if ($hasOrderSeriesId) {
                $ownerParts[] = "o.series_id IN ({$sPlaceholders})";
                $ownerParams = array_merge($ownerParams, $promotorSeriesIds);
            }
        }

        if (!empty($ownerParts)) {
            $baseConditions[] = '(' . implode(' OR ', $ownerParts) . ')';
            $allParams = array_merge($baseParams, $ownerParams);
            $whereClause = implode(' AND ', $baseConditions);

            try {
                $promotorOrders = $db->getAll("
                    SELECT DISTINCT o.id, o.order_number, o.total_amount, o.payment_method,
                           {$stripeFeeCol}
                           o.event_id, o.created_at, o.discount,
                           COALESCE(e.name, s_name.name, 'Serieanmälan') as event_name,
                           COALESCE(dc.code, '') as discount_code
                    FROM orders o
                    LEFT JOIN events e ON o.event_id = e.id
                    LEFT JOIN (
                        SELECT oi2.order_id, s2.name
                        FROM order_items oi2
                        JOIN series_registrations sr2 ON sr2.id = oi2.series_registration_id
                        JOIN series s2 ON s2.id = sr2.series_id
                        WHERE oi2.item_type = 'series_registration'
                        GROUP BY oi2.order_id
                    ) s_name ON s_name.order_id = o.id AND o.event_id IS NULL
                    LEFT JOIN discount_codes dc ON o.discount_code_id = dc.id
                    WHERE {$whereClause}
                    ORDER BY o.created_at DESC
                ", $allParams);
            } catch (Exception $e) {
                // discount_code_id or series tables might not exist
                try {
                    $promotorOrders = $db->getAll("
                        SELECT DISTINCT o.id, o.order_number, o.total_amount, o.payment_method,
                               {$stripeFeeCol}
                               o.event_id, o.created_at, o.discount,
                               COALESCE(e.name, 'Serieanmälan') as event_name,
                               '' as discount_code
                        FROM orders o
                        LEFT JOIN events e ON o.event_id = e.id
                        WHERE {$whereClause}
                        ORDER BY o.created_at DESC
                    ", $allParams);
                } catch (Exception $e2) {
                    error_log("Promotor economy query error: " . $e2->getMessage());
                }
            }
        }

        // Calculate totals
        $promotorOrderTotals = [
            'gross' => 0, 'payment_fees' => 0, 'platform_fees' => 0,
            'net' => 0, 'order_count' => 0, 'discounts' => 0,
            'card_count' => 0, 'swish_count' => 0
        ];

        foreach ($promotorOrders as &$order) {
            $amount = (float)$order['total_amount'];
            $method = $order['payment_method'] ?? 'card';

            if (in_array($method, ['swish', 'swish_csv'])) {
                $order['payment_fee'] = $SWISH_FEE;
                $order['fee_type'] = 'actual';
                $promotorOrderTotals['swish_count']++;
            } elseif ($method === 'card') {
                if ($order['stripe_fee'] !== null && (float)$order['stripe_fee'] > 0) {
                    $order['payment_fee'] = round((float)$order['stripe_fee'], 2);
                    $order['fee_type'] = 'actual';
                } else {
                    $order['payment_fee'] = round(($amount * $STRIPE_PERCENT / 100) + $STRIPE_FIXED, 2);
                    $order['fee_type'] = 'estimated';
                }
                $promotorOrderTotals['card_count']++;
            } else {
                $order['payment_fee'] = 0;
                $order['fee_type'] = 'none';
            }

            // Platform fee calculation based on type
            if ($promotorFeeType === 'fixed') {
                $order['platform_fee'] = $promotorPlatformFixed;
            } elseif ($promotorFeeType === 'both') {
                $order['platform_fee'] = round(($amount * $promotorPlatformPct / 100) + $promotorPlatformFixed, 2);
            } else {
                $order['platform_fee'] = round($amount * $promotorPlatformPct / 100, 2);
            }

            $order['net_amount'] = round($amount - $order['payment_fee'] - $order['platform_fee'], 2);

            $promotorOrderTotals['gross'] += $amount;
            $promotorOrderTotals['payment_fees'] += $order['payment_fee'];
            $promotorOrderTotals['platform_fees'] += $order['platform_fee'];
            $promotorOrderTotals['net'] += $order['net_amount'];
            $promotorOrderTotals['discounts'] += (float)($order['discount'] ?? 0);
            $promotorOrderTotals['order_count']++;
        }
        unset($order);

        // Available years for promotor (include series orders too)
        $promotorYears = [];
        try {
            $yearOwnerParts = [];
            $yearOwnerParams = [];
            if (!empty($allEventIds)) {
                $placeholders = implode(',', array_fill(0, count($allEventIds), '?'));
                $yearOwnerParts[] = "event_id IN ({$placeholders})";
                $yearOwnerParams = array_merge($yearOwnerParams, $allEventIds);
            }
            if (!empty($promotorSeriesIds)) {
                $sPlaceholders = implode(',', array_fill(0, count($promotorSeriesIds), '?'));
                $yearOwnerParts[] = "id IN (
                    SELECT oi.order_id FROM order_items oi
                    JOIN series_registrations sr ON sr.id = oi.series_registration_id
                    WHERE sr.series_id IN ({$sPlaceholders})
                )";
                $yearOwnerParams = array_merge($yearOwnerParams, $promotorSeriesIds);
                if ($hasOrderSeriesId) {
                    $yearOwnerParts[] = "series_id IN ({$sPlaceholders})";
                    $yearOwnerParams = array_merge($yearOwnerParams, $promotorSeriesIds);
                }
            }
            if (!empty($yearOwnerParts)) {
                $yearWhere = "payment_status = 'paid' AND (" . implode(' OR ', $yearOwnerParts) . ")";
                $promotorYears = $db->getAll("
                    SELECT DISTINCT YEAR(created_at) as yr FROM orders
                    WHERE {$yearWhere}
                    ORDER BY yr DESC
                ", $yearOwnerParams);
            }
        } catch (Exception $e) {}

        // Events for filter
        $promotorFilterEvents = [];
        try {
            if (!empty($allEventIds)) {
                $placeholders = implode(',', array_fill(0, count($allEventIds), '?'));
                $promotorFilterEvents = $db->getAll("
                    SELECT id, name, date FROM events WHERE id IN ({$placeholders}) ORDER BY date DESC
                ", $allEventIds);
            }
        } catch (Exception $e) {}

        $monthNames = [
            1 => 'Januari', 2 => 'Februari', 3 => 'Mars', 4 => 'April',
            5 => 'Maj', 6 => 'Juni', 7 => 'Juli', 8 => 'Augusti',
            9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'December'
        ];
    }
}

// Page config for unified layout
$promotorTabLabels = [
    'event' => 'Event',
    'serier' => 'Serier',
    'ekonomi' => 'Ekonomi',
    'media' => 'Media'
];
$page_title = $isAdmin ? 'Utbetalningar & Ekonomi' : ($promotorTabLabels[$promotorTab] ?? 'Promotor');
$breadcrumbs = [
    ['label' => $isAdmin ? 'Utbetalningar & Ekonomi' : 'Promotor']
];

include __DIR__ . '/components/unified-layout.php';
?>

<?php if ($isAdmin): ?>
<!-- ===== ADMIN: FINANCIAL PAYOUT OVERVIEW ===== -->

<style>
/* Order table styles */
.order-table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
.order-table { font-variant-numeric: tabular-nums; min-width: 700px; }
.order-table th { white-space: nowrap; font-size: var(--text-xs); text-transform: uppercase; letter-spacing: 0.05em; }
.order-table td { vertical-align: middle; white-space: nowrap; }
.order-method { display: inline-flex; align-items: center; gap: var(--space-2xs); }
.order-method i { width: 14px; height: 14px; }
.fee-estimated { opacity: 0.6; font-style: italic; }
.order-event { font-size: var(--text-xs); color: var(--color-text-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 180px; }
.summary-row td { font-weight: 600; border-top: 2px solid var(--color-border-strong); background: var(--color-bg-hover); }
.platform-fee-info {
    display: flex; align-items: center; gap: var(--space-xs); font-size: var(--text-sm);
    color: var(--color-text-secondary); margin-bottom: var(--space-sm);
}
.platform-fee-info i { width: 14px; height: 14px; }
.btn-edit-fee {
    background: none; border: none; cursor: pointer; padding: 2px;
    color: var(--color-text-muted); opacity: 0.6; transition: opacity 0.15s; vertical-align: middle;
}
.btn-edit-fee:hover { opacity: 1; color: var(--color-accent); }
.btn-edit-fee i { width: 12px; height: 12px; }
.fee-edit-inline { display: inline-flex; align-items: center; gap: var(--space-xs); }
.fee-edit-inline input {
    width: 60px; padding: 2px var(--space-xs); border: 1px solid var(--color-accent);
    border-radius: var(--radius-sm); background: var(--color-bg-sunken); color: var(--color-text-primary);
    font-size: var(--text-sm); text-align: center;
}
.fee-edit-inline button {
    padding: 2px 6px; border: none; border-radius: var(--radius-sm); cursor: pointer;
    font-size: 11px; font-weight: 500;
}
.fee-edit-save { background: var(--color-success); color: white; }
.fee-edit-cancel { background: var(--color-bg-sunken); color: var(--color-text-secondary); }

/* Mobile: card view on all phones (portrait + landscape) */
.order-cards { display: none; }

@media (max-width: 767px) {
    .order-table-wrap { display: none; }
    .order-cards { display: block; }
}
</style>

<!-- Stats -->
<div class="admin-stats-grid">
    <div class="admin-stat-card">
        <div class="admin-stat-icon stat-icon-accent">
            <i data-lucide="wallet"></i>
        </div>
        <div class="admin-stat-content">
            <div class="admin-stat-value"><?= number_format($payoutTotals['gross'], 2, ',', ' ') ?> kr</div>
            <div class="admin-stat-label">Försäljning</div>
        </div>
    </div>
    <div class="admin-stat-card">
        <div class="admin-stat-icon stat-icon-danger">
            <i data-lucide="receipt"></i>
        </div>
        <div class="admin-stat-content">
            <div class="admin-stat-value"><?= number_format($payoutTotals['payment_fees'] + $payoutTotals['platform_fees'], 2, ',', ' ') ?> kr</div>
            <div class="admin-stat-label">Totala avgifter</div>
        </div>
    </div>
    <div class="admin-stat-card">
        <div class="admin-stat-icon stat-icon-success">
            <i data-lucide="banknote"></i>
        </div>
        <div class="admin-stat-content">
            <div class="admin-stat-value"><?= number_format($payoutTotals['net'], 2, ',', ' ') ?> kr</div>
            <div class="admin-stat-label">Netto efter avgifter</div>
        </div>
    </div>
    <div class="admin-stat-card">
        <div class="admin-stat-icon stat-icon-warning">
            <i data-lucide="hash"></i>
        </div>
        <div class="admin-stat-content">
            <div class="admin-stat-value"><?= $payoutTotals['order_count'] ?></div>
            <div class="admin-stat-label">Ordrar (<?= $payoutTotals['card_count'] ?> kort, <?= $payoutTotals['swish_count'] ?> Swish)</div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="admin-card mb-lg">
    <div class="admin-card-body">
        <form method="GET" class="flex flex-wrap gap-md items-end">
            <div class="admin-form-group mb-0">
                <label class="admin-form-label">År</label>
                <select name="year" class="admin-form-select" onchange="this.form.submit()">
                    <?php
                    $years = array_column($availableYears, 'yr');
                    if (!in_array($filterYear, $years)) $years[] = $filterYear;
                    rsort($years);
                    foreach ($years as $yr): ?>
                    <option value="<?= $yr ?>" <?= $yr == $filterYear ? 'selected' : '' ?>><?= $yr ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="admin-form-group mb-0">
                <label class="admin-form-label">Period</label>
                <select name="month" class="admin-form-select" onchange="this.form.submit()">
                    <option value="0" <?= $filterMonth == 0 ? 'selected' : '' ?>>Helår</option>
                    <?php foreach ($monthNames as $num => $name): ?>
                    <option value="<?= $num ?>" <?= $num == $filterMonth ? 'selected' : '' ?>><?= $name ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="admin-form-group mb-0">
                <label class="admin-form-label">Event</label>
                <select name="event" class="admin-form-select" onchange="this.form.submit()">
                    <option value="0">Alla event</option>
                    <?php foreach ($filterEvents as $ev): ?>
                    <option value="<?= $ev['id'] ?>" <?= $ev['id'] == $filterEvent ? 'selected' : '' ?>><?= h($ev['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="admin-form-group mb-0">
                <label class="admin-form-label">Mottagare</label>
                <select name="recipient" class="admin-form-select" onchange="this.form.submit()">
                    <option value="0">Alla mottagare</option>
                    <?php foreach ($allRecipients as $rec): ?>
                    <option value="<?= $rec['id'] ?>" <?= $rec['id'] == $filterRecipient ? 'selected' : '' ?>><?= h($rec['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>
    </div>
</div>

<!-- Per-order table -->
<?php
    // Build header label
    $headerLabel = 'Ordrar ' . $filterYear;
    if ($filterMonth > 0) $headerLabel .= ' ' . $monthNames[$filterMonth];
    if ($filterEvent > 0) {
        $evName = '';
        foreach ($filterEvents as $ev) { if ($ev['id'] == $filterEvent) { $evName = $ev['name']; break; } }
        if ($evName) $headerLabel .= ' — ' . $evName;
    }
?>
<div class="admin-card">
    <div class="admin-card-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: var(--space-sm);">
        <h2><?= h($headerLabel) ?></h2>
        <?php if (isset($recipientInfo)): ?>
        <div class="platform-fee-info" data-recipient-id="<?= $recipientInfo['id'] ?>">
            <i data-lucide="percent"></i>
            Plattformsavgift: <strong class="platform-fee-value"><?= number_format($platformFeePct, 1) ?>%</strong>
            <button type="button" class="btn-edit-fee" onclick="editPlatformFee(<?= $recipientInfo['id'] ?>, <?= $platformFeePct ?>)" title="Ändra plattformsavgift">
                <i data-lucide="pencil"></i>
            </button>
        </div>
        <?php endif; ?>
    </div>
    <div class="admin-card-body p-0">
        <?php if (empty($orderRows)): ?>
        <div class="admin-empty-state">
            <i data-lucide="inbox"></i>
            <h3>Inga betalda ordrar</h3>
            <p>Inga betalda ordrar hittades för <?= $filterYear ?>.</p>
        </div>
        <?php else: ?>

        <!-- Desktop/Landscape table -->
        <div class="admin-table-container order-table-wrap">
            <table class="admin-table order-table">
                <thead>
                    <tr>
                        <th>Ordernr</th>
                        <th>Event</th>
                        <th style="text-align: right;">Belopp</th>
                        <th>Betalsätt</th>
                        <th style="text-align: right;">Avgift betalning</th>
                        <th style="text-align: right;">Plattformsavgift</th>
                        <th style="text-align: right;">Netto</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orderRows as $order):
                        $method = $order['payment_method'] ?? 'card';
                        $methodLabel = match($method) {
                            'swish', 'swish_csv' => 'Swish',
                            'card' => 'Kort',
                            'manual' => 'Manuell',
                            'free' => 'Gratis',
                            default => ucfirst($method)
                        };
                        $methodIcon = match($method) {
                            'swish', 'swish_csv' => 'smartphone',
                            'card' => 'credit-card',
                            'manual' => 'hand',
                            'free' => 'gift',
                            default => 'circle'
                        };
                    ?>
                    <tr>
                        <td>
                            <code style="font-size: var(--text-sm);"><?= h($order['order_number'] ?? '#' . $order['id']) ?></code>
                            <div class="text-xs text-secondary"><?= date('j M', strtotime($order['created_at'])) ?></div>
                        </td>
                        <td>
                            <div class="order-event"><?= h($order['event_name'] ?? '-') ?></div>
                        </td>
                        <td style="text-align: right; font-weight: 500;">
                            <?= number_format($order['total_amount'], 2, ',', ' ') ?> kr
                        </td>
                        <td>
                            <span class="order-method">
                                <i data-lucide="<?= $methodIcon ?>"></i>
                                <?= $methodLabel ?>
                            </span>
                        </td>
                        <td style="text-align: right; color: var(--color-error);">
                            <?php if ($order['payment_fee'] > 0): ?>
                                <span class="<?= $order['fee_type'] === 'estimated' ? 'fee-estimated' : '' ?>">
                                    -<?= number_format($order['payment_fee'], 2, ',', ' ') ?> kr
                                </span>
                            <?php else: ?>
                                <span class="text-secondary">-</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align: right; color: var(--color-error);">
                            <?php if ($order['platform_fee'] > 0): ?>
                                -<?= number_format($order['platform_fee'], 2, ',', ' ') ?> kr
                            <?php else: ?>
                                <span class="text-secondary">-</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align: right; font-weight: 600; color: var(--color-success);">
                            <?= number_format($order['net_amount'], 2, ',', ' ') ?> kr
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="summary-row">
                        <td colspan="2" style="font-weight: 600;">Summa (<?= $payoutTotals['order_count'] ?> ordrar)</td>
                        <td style="text-align: right;"><?= number_format($payoutTotals['gross'], 2, ',', ' ') ?> kr</td>
                        <td></td>
                        <td style="text-align: right; color: var(--color-error);">-<?= number_format($payoutTotals['payment_fees'], 2, ',', ' ') ?> kr</td>
                        <td style="text-align: right; color: var(--color-error);">-<?= number_format($payoutTotals['platform_fees'], 2, ',', ' ') ?> kr</td>
                        <td style="text-align: right; color: var(--color-success);"><?= number_format($payoutTotals['net'], 2, ',', ' ') ?> kr</td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <!-- Mobile portrait card view -->
        <div class="order-cards">
            <?php foreach ($orderRows as $order):
                $method = $order['payment_method'] ?? 'card';
                $methodLabel = match($method) {
                    'swish', 'swish_csv' => 'Swish',
                    'card' => 'Kort',
                    'manual' => 'Manuell',
                    'free' => 'Gratis',
                    default => ucfirst($method)
                };
            ?>
            <div style="padding: var(--space-md); border-bottom: 1px solid var(--color-border);">
                <div style="margin-bottom: var(--space-xs);">
                    <div style="font-weight: 500; color: var(--color-text-primary); margin-bottom: 2px;"><?= h($order['event_name'] ?? '-') ?></div>
                    <div class="text-xs text-secondary">
                        <code><?= h($order['order_number'] ?? '#' . $order['id']) ?></code>
                        &middot; <?= date('j M Y', strtotime($order['created_at'])) ?>
                        &middot; <?= $methodLabel ?>
                    </div>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: var(--space-xs); font-size: var(--text-xs); text-align: center; background: var(--color-bg-sunken); padding: var(--space-xs); border-radius: var(--radius-sm);">
                    <div>
                        <div style="color: var(--color-text-muted); margin-bottom: 1px;">Belopp</div>
                        <div style="font-weight: 600;"><?= number_format($order['total_amount'], 0, ',', ' ') ?> kr</div>
                    </div>
                    <div>
                        <div style="color: var(--color-text-muted); margin-bottom: 1px;">Avgift</div>
                        <div style="color: var(--color-error);"><?= $order['payment_fee'] > 0 ? '-' . number_format($order['payment_fee'], 0, ',', ' ') : '-' ?></div>
                    </div>
                    <div>
                        <div style="color: var(--color-text-muted); margin-bottom: 1px;">Plattform</div>
                        <div style="color: var(--color-error);"><?= $order['platform_fee'] > 0 ? '-' . number_format($order['platform_fee'], 0, ',', ' ') : '-' ?></div>
                    </div>
                    <div>
                        <div style="color: var(--color-text-muted); margin-bottom: 1px;">Netto</div>
                        <div style="font-weight: 700; color: var(--color-success);"><?= number_format($order['net_amount'], 0, ',', ' ') ?> kr</div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            <!-- Mobile summary -->
            <div style="padding: var(--space-md); background: var(--color-bg-hover);">
                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: var(--space-xs); font-size: var(--text-xs); text-align: center;">
                    <div>
                        <div style="color: var(--color-text-muted); margin-bottom: 1px;">Brutto</div>
                        <div style="font-weight: 700;"><?= number_format($payoutTotals['gross'], 0, ',', ' ') ?> kr</div>
                    </div>
                    <div>
                        <div style="color: var(--color-text-muted); margin-bottom: 1px;">Avgifter</div>
                        <div style="font-weight: 700; color: var(--color-error);">-<?= number_format($payoutTotals['payment_fees'], 0, ',', ' ') ?></div>
                    </div>
                    <div>
                        <div style="color: var(--color-text-muted); margin-bottom: 1px;">Plattform</div>
                        <div style="font-weight: 700; color: var(--color-error);">-<?= number_format($payoutTotals['platform_fees'], 0, ',', ' ') ?></div>
                    </div>
                    <div>
                        <div style="color: var(--color-text-muted); margin-bottom: 1px;">Netto</div>
                        <div style="font-weight: 700; color: var(--color-success);"><?= number_format($payoutTotals['net'], 0, ',', ' ') ?> kr</div>
                    </div>
                </div>
                <div style="text-align: center; font-size: var(--text-xs); color: var(--color-text-muted); margin-top: var(--space-xs);">
                    <?= $payoutTotals['order_count'] ?> ordrar
                </div>
            </div>
        </div>

        <?php endif; ?>
    </div>
</div>

<script>
function editPlatformFee(recipientId, currentFee) {
    const container = document.querySelector(`.platform-fee-info[data-recipient-id="${recipientId}"]`);
    if (!container) return;

    const valueEl = container.querySelector('.platform-fee-value');
    const editBtn = container.querySelector('.btn-edit-fee');
    const originalText = valueEl.textContent;

    editBtn.style.display = 'none';
    valueEl.innerHTML = `
        <span class="fee-edit-inline">
            <input type="number" value="${currentFee}" min="0" max="100" step="0.1" id="feeInput${recipientId}">
            <span>%</span>
            <button type="button" class="fee-edit-save" onclick="savePlatformFee(${recipientId})">Spara</button>
            <button type="button" class="fee-edit-cancel" onclick="cancelFeeEdit(${recipientId}, '${originalText}')">Avbryt</button>
        </span>
    `;

    const input = document.getElementById('feeInput' + recipientId);
    input.focus();
    input.select();
    input.addEventListener('keydown', e => {
        if (e.key === 'Enter') savePlatformFee(recipientId);
        if (e.key === 'Escape') cancelFeeEdit(recipientId, originalText);
    });
}

async function savePlatformFee(recipientId) {
    const input = document.getElementById('feeInput' + recipientId);
    if (!input) return;

    const newFee = parseFloat(input.value);
    if (isNaN(newFee) || newFee < 0 || newFee > 100) {
        alert('Ange ett värde mellan 0 och 100');
        return;
    }

    try {
        const formData = new FormData();
        formData.append('action', 'update_platform_fee');
        formData.append('recipient_id', recipientId);
        formData.append('platform_fee_percent', newFee);

        const response = await fetch('/admin/promotor.php', { method: 'POST', body: formData });
        const result = await response.json();
        if (result.success) {
            location.reload();
        } else {
            alert('Kunde inte spara: ' + (result.error || 'Okänt fel'));
        }
    } catch (error) {
        console.error('Save error:', error);
        alert('Ett fel uppstod');
    }
}

function cancelFeeEdit(recipientId, originalText) {
    const container = document.querySelector(`.platform-fee-info[data-recipient-id="${recipientId}"]`);
    if (!container) return;
    container.querySelector('.platform-fee-value').textContent = originalText;
    container.querySelector('.btn-edit-fee').style.display = '';
    if (typeof lucide !== 'undefined') lucide.createIcons();
}
</script>

<?php else: ?>
<!-- ===== PROMOTOR VIEW ===== -->

<style>
/* Promotor tabs */
.promotor-tabs {
    display: flex; gap: 0; border-bottom: 2px solid var(--color-border);
    margin-bottom: var(--space-lg); overflow-x: auto; -webkit-overflow-scrolling: touch;
}
.promotor-tab {
    display: flex; align-items: center; gap: var(--space-xs); padding: var(--space-sm) var(--space-lg);
    color: var(--color-text-secondary); text-decoration: none; font-size: var(--text-sm); font-weight: 500;
    white-space: nowrap; border-bottom: 2px solid transparent; margin-bottom: -2px; transition: all 0.15s;
}
.promotor-tab i { width: 16px; height: 16px; }
.promotor-tab:hover { color: var(--color-text-primary); }
.promotor-tab.active { color: var(--color-accent); border-bottom-color: var(--color-accent); }

/* Event cards */
.promotor-grid { display: grid; gap: var(--space-lg); }
.event-card {
    background: var(--color-bg-surface); border-radius: var(--radius-lg);
    border: 1px solid var(--color-border); overflow: hidden;
}
.event-card.past { opacity: 0.7; }
.event-card-header {
    padding: var(--space-lg); display: flex; justify-content: space-between;
    align-items: flex-start; gap: var(--space-md); border-bottom: 1px solid var(--color-border);
}
.event-info { flex: 1; }
.event-title { font-size: var(--text-xl); font-weight: 600; color: var(--color-text-primary); margin: 0 0 var(--space-xs) 0; }
.event-meta { display: flex; flex-wrap: wrap; gap: var(--space-md); color: var(--color-text-secondary); font-size: var(--text-sm); }
.event-meta-item { display: flex; align-items: center; gap: var(--space-xs); }
.event-meta-item i { width: 16px; height: 16px; }
.event-series {
    display: flex; align-items: center; gap: var(--space-xs); font-size: var(--text-sm);
    color: var(--color-text-secondary); background: var(--color-bg-sunken);
    padding: var(--space-xs) var(--space-sm); border-radius: var(--radius-full);
}
.event-series img { width: 20px; height: 20px; object-fit: contain; }
.event-card-body { padding: var(--space-lg); }
.stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: var(--space-sm); margin-bottom: var(--space-lg); }
.stat-box { background: var(--color-bg-sunken); padding: var(--space-md); border-radius: var(--radius-md); text-align: center; }
.stat-value { font-size: var(--text-2xl); font-weight: 700; color: var(--color-accent); }
.stat-value.success { color: var(--color-success); }
.stat-value.warning { color: var(--color-warning); }
.stat-label { font-size: var(--text-xs); color: var(--color-text-secondary); text-transform: uppercase; letter-spacing: 0.05em; }
.event-actions { display: flex; flex-wrap: wrap; gap: var(--space-sm); }
.event-actions .btn { display: inline-flex; align-items: center; gap: var(--space-xs); min-height: 44px; }
.event-actions .btn i { width: 16px; height: 16px; }
.empty-state { text-align: center; padding: var(--space-2xl); color: var(--color-text-secondary); }
.empty-state i { width: 48px; height: 48px; margin-bottom: var(--space-md); opacity: 0.5; }
.empty-state h2 { margin: 0 0 var(--space-sm) 0; color: var(--color-text-primary); }

/* Series cards */
.series-grid { display: grid; gap: var(--space-lg); }
.series-card {
    background: var(--color-bg-surface); border-radius: var(--radius-lg);
    border: 1px solid var(--color-border); overflow: hidden;
}
.series-card-header { padding: var(--space-lg); display: flex; align-items: center; gap: var(--space-md); border-bottom: 1px solid var(--color-border); }
.series-logo {
    width: 48px; height: 48px; border-radius: var(--radius-md); background: var(--color-bg-sunken);
    display: flex; align-items: center; justify-content: center; overflow: hidden; flex-shrink: 0;
}
.series-logo img { max-width: 100%; max-height: 100%; object-fit: contain; }
.series-info h3 { margin: 0 0 var(--space-2xs) 0; font-size: var(--text-lg); }
.series-info p { margin: 0; font-size: var(--text-sm); color: var(--color-text-secondary); }
.series-card-body { padding: var(--space-lg); }
.series-detail { display: flex; align-items: center; gap: var(--space-sm); margin-bottom: var(--space-sm); font-size: var(--text-sm); color: var(--color-text-secondary); }
.series-detail i { width: 16px; height: 16px; flex-shrink: 0; }
.series-detail.on { color: var(--color-success); }
.series-detail.off { color: var(--color-text-muted); }
.series-card-footer { padding: var(--space-md) var(--space-lg); background: var(--color-bg-sunken); border-top: 1px solid var(--color-border); display: flex; gap: var(--space-sm); }
.series-card-footer .btn { flex: 1; }

/* Economy table (reuse admin styles) */
.eco-table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
.eco-table { font-variant-numeric: tabular-nums; min-width: 650px; }
.eco-table th { white-space: nowrap; font-size: var(--text-xs); text-transform: uppercase; letter-spacing: 0.05em; }
.eco-table td { vertical-align: middle; white-space: nowrap; }
.eco-event { font-size: var(--text-xs); color: var(--color-text-muted); max-width: 160px; overflow: hidden; text-overflow: ellipsis; }
.fee-est { opacity: 0.6; font-style: italic; }
.eco-cards { display: none; }
.eco-summary td { font-weight: 600; border-top: 2px solid var(--color-border-strong); background: var(--color-bg-hover); }

/* Badge for past/upcoming */
.badge-past { background: var(--color-bg-sunken); color: var(--color-text-muted); font-size: var(--text-xs); padding: 2px var(--space-xs); border-radius: var(--radius-full); }
.badge-upcoming { background: var(--color-accent-light); color: var(--color-accent-text); font-size: var(--text-xs); padding: 2px var(--space-xs); border-radius: var(--radius-full); }

/* Mobile */
@media (max-width: 767px) {
    .event-card, .series-card, .admin-card {
        margin-left: calc(-1 * var(--space-md)); margin-right: calc(-1 * var(--space-md));
        border-radius: 0 !important; border-left: none !important; border-right: none !important;
        width: calc(100% + var(--space-md) * 2);
    }
    .promotor-grid, .series-grid { gap: 0; }
    .event-card + .event-card, .series-card + .series-card { border-top: none; }
}
@media (max-width: 600px) {
    .stats-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 767px) {
    .eco-table-wrap { display: none; }
    .eco-cards { display: block; }
    .event-actions { display: grid; grid-template-columns: 1fr 1fr; gap: var(--space-xs); }
    .event-actions .btn { justify-content: center; font-size: var(--text-sm); padding: var(--space-sm); }
    .event-card-header { flex-direction: column; gap: var(--space-xs); padding: var(--space-md); }
    .event-card-body { padding: var(--space-md); }
    .event-title { font-size: var(--text-lg); }
    .stat-value { font-size: var(--text-xl); }
    .stat-box { padding: var(--space-sm); }
}
</style>

<!-- Promotor Tab Navigation -->
<nav class="promotor-tabs">
    <a href="?tab=event" class="promotor-tab <?= $promotorTab === 'event' ? 'active' : '' ?>">
        <i data-lucide="calendar"></i> Event
    </a>
    <a href="?tab=serier" class="promotor-tab <?= $promotorTab === 'serier' ? 'active' : '' ?>">
        <i data-lucide="medal"></i> Serier
    </a>
    <a href="?tab=ekonomi" class="promotor-tab <?= $promotorTab === 'ekonomi' ? 'active' : '' ?>">
        <i data-lucide="wallet"></i> Ekonomi
    </a>
    <a href="?tab=media" class="promotor-tab <?= $promotorTab === 'media' ? 'active' : '' ?>">
        <i data-lucide="image"></i> Media
    </a>
</nav>

<?php if ($promotorTab === 'event'): ?>
<!-- =============== EVENT TAB =============== -->
<?php if (empty($promotorEvents)): ?>
<div class="event-card">
    <div class="empty-state">
        <i data-lucide="calendar-x"></i>
        <h2>Inga event</h2>
        <p>Du har inga event tilldelade ännu. Kontakta administratören för att få tillgång.</p>
    </div>
</div>
<?php else: ?>
<div class="promotor-grid">
    <?php foreach ($promotorEvents as $event):
        $isPast = strtotime($event['date']) < strtotime('today');
    ?>
    <div class="event-card <?= $isPast ? 'past' : '' ?>">
        <div class="event-card-header">
            <div class="event-info">
                <h2 class="event-title"><?= h($event['name']) ?></h2>
                <div class="event-meta">
                    <span class="event-meta-item">
                        <i data-lucide="calendar"></i>
                        <?= date('j M Y', strtotime($event['date'])) ?>
                    </span>
                    <?php if ($event['location']): ?>
                    <span class="event-meta-item">
                        <i data-lucide="map-pin"></i>
                        <?= h($event['location']) ?>
                    </span>
                    <?php endif; ?>
                    <?php if ($isPast): ?>
                    <span class="badge-past">Genomfört</span>
                    <?php else: ?>
                    <span class="badge-upcoming">Kommande</span>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($event['series_name']): ?>
            <span class="event-series">
                <?php if ($event['series_logo']): ?>
                <img src="<?= h($event['series_logo']) ?>" alt="">
                <?php endif; ?>
                <?= h($event['series_name']) ?>
            </span>
            <?php endif; ?>
        </div>

        <div class="event-card-body">
            <div class="stats-grid">
                <div class="stat-box">
                    <div class="stat-value"><?= $event['total_with_series'] ?></div>
                    <div class="stat-label">Anmälda<?php if ($event['series_registration_count'] > 0): ?> <small>(<?= (int)$event['series_registration_count'] ?> serie)</small><?php endif; ?></div>
                </div>
                <div class="stat-box">
                    <div class="stat-value success"><?= (int)$event['paid_with_series'] ?></div>
                    <div class="stat-label">Betalda</div>
                </div>
                <div class="stat-box">
                    <div class="stat-value"><?= number_format($event['gross_revenue'], 0, ',', ' ') ?> kr</div>
                    <div class="stat-label">Brutto</div>
                </div>
                <div class="stat-box">
                    <div class="stat-value success"><?= number_format(max(0, $event['net_revenue']), 0, ',', ' ') ?> kr</div>
                    <div class="stat-label">Netto (est.)</div>
                </div>
            </div>

            <div class="event-actions">
                <a href="/admin/event-edit.php?id=<?= $event['id'] ?>" class="btn btn-primary">
                    <i data-lucide="pencil"></i>
                    Redigera
                </a>
                <a href="/admin/event-startlist.php?event_id=<?= $event['id'] ?>" class="btn btn-secondary">
                    <i data-lucide="clipboard-list"></i>
                    Startlista
                </a>
                <a href="/admin/promotor-registrations.php?event_id=<?= $event['id'] ?>" class="btn btn-secondary">
                    <i data-lucide="users"></i>
                    Anmälningar
                </a>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php elseif ($promotorTab === 'serier'): ?>
<!-- =============== SERIER TAB =============== -->
<?php if (empty($promotorSeries)): ?>
<div class="event-card">
    <div class="empty-state">
        <i data-lucide="medal"></i>
        <h2>Inga serier</h2>
        <p>Du har inga serier tilldelade. Kontakta administratören.</p>
    </div>
</div>
<?php else: ?>
<div class="series-grid">
    <?php foreach ($promotorSeries as $s): ?>
    <div class="series-card">
        <div class="series-card-header">
            <div class="series-logo">
                <?php if ($s['logo']): ?>
                    <img src="<?= h($s['logo']) ?>" alt="<?= h($s['name']) ?>">
                <?php else: ?>
                    <i data-lucide="medal"></i>
                <?php endif; ?>
            </div>
            <div class="series-info">
                <h3><?= h($s['name']) ?></h3>
                <p><?= (int)$s['event_count'] ?> event <?= date('Y') ?></p>
            </div>
        </div>
        <div class="series-card-body">
            <div class="series-detail <?= ($s['allow_series_registration'] ?? 0) ? 'on' : 'off' ?>">
                <i data-lucide="<?= ($s['allow_series_registration'] ?? 0) ? 'check-circle' : 'x-circle' ?>"></i>
                <span>Serieanmälan <?= ($s['allow_series_registration'] ?? 0) ? 'öppen' : 'stängd' ?></span>
            </div>
            <div class="series-detail">
                <i data-lucide="percent"></i>
                <span>Serierabatt: <?= number_format((float)($s['series_discount_percent'] ?? 15), 0) ?>%</span>
            </div>
            <div class="series-detail">
                <i data-lucide="tag"></i>
                <span>Prismall: <?= h($s['template_name'] ?? 'Ingen kopplad') ?></span>
            </div>
            <?php if ($s['banner_url'] ?? null): ?>
            <div class="series-detail on">
                <i data-lucide="image"></i>
                <span>Banner konfigurerad</span>
            </div>
            <?php endif; ?>
        </div>
        <div class="series-card-footer">
            <a href="/admin/promotor-series.php?id=<?= $s['id'] ?>" class="btn btn-primary">
                <i data-lucide="settings"></i>
                Inställningar
            </a>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php elseif ($promotorTab === 'ekonomi'): ?>
<!-- =============== EKONOMI TAB =============== -->
<?php
    $ecoYear = $ecoYear ?? (int)date('Y');
    $ecoMonth = $ecoMonth ?? 0;
    $ecoEvent = $ecoEvent ?? 0;
    $monthNames = $monthNames ?? [];
?>

<!-- Stats -->
<div class="admin-stats-grid">
    <div class="admin-stat-card">
        <div class="admin-stat-icon stat-icon-accent"><i data-lucide="wallet"></i></div>
        <div class="admin-stat-content">
            <div class="admin-stat-value"><?= number_format($promotorOrderTotals['gross'] ?? 0, 2, ',', ' ') ?> kr</div>
            <div class="admin-stat-label">Försäljning</div>
        </div>
    </div>
    <div class="admin-stat-card">
        <div class="admin-stat-icon stat-icon-danger"><i data-lucide="receipt"></i></div>
        <div class="admin-stat-content">
            <div class="admin-stat-value"><?= number_format(($promotorOrderTotals['payment_fees'] ?? 0) + ($promotorOrderTotals['platform_fees'] ?? 0), 2, ',', ' ') ?> kr</div>
            <div class="admin-stat-label">Totala avgifter</div>
        </div>
    </div>
    <div class="admin-stat-card">
        <div class="admin-stat-icon stat-icon-success"><i data-lucide="banknote"></i></div>
        <div class="admin-stat-content">
            <div class="admin-stat-value"><?= number_format($promotorOrderTotals['net'] ?? 0, 2, ',', ' ') ?> kr</div>
            <div class="admin-stat-label">Netto</div>
        </div>
    </div>
    <div class="admin-stat-card">
        <div class="admin-stat-icon stat-icon-warning"><i data-lucide="hash"></i></div>
        <div class="admin-stat-content">
            <div class="admin-stat-value"><?= $promotorOrderTotals['order_count'] ?? 0 ?></div>
            <div class="admin-stat-label">Ordrar</div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="admin-card mb-lg">
    <div class="admin-card-body">
        <form method="GET" class="flex flex-wrap gap-md items-end">
            <input type="hidden" name="tab" value="ekonomi">
            <div class="admin-form-group mb-0">
                <label class="admin-form-label">År</label>
                <select name="year" class="admin-form-select" onchange="this.form.submit()">
                    <?php
                    $years = array_column($promotorYears ?? [], 'yr');
                    if (!in_array($ecoYear, $years)) $years[] = $ecoYear;
                    rsort($years);
                    foreach ($years as $yr): ?>
                    <option value="<?= $yr ?>" <?= $yr == $ecoYear ? 'selected' : '' ?>><?= $yr ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="admin-form-group mb-0">
                <label class="admin-form-label">Period</label>
                <select name="month" class="admin-form-select" onchange="this.form.submit()">
                    <option value="0" <?= $ecoMonth == 0 ? 'selected' : '' ?>>Helår</option>
                    <?php foreach ($monthNames as $num => $name): ?>
                    <option value="<?= $num ?>" <?= $num == $ecoMonth ? 'selected' : '' ?>><?= $name ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="admin-form-group mb-0">
                <label class="admin-form-label">Event</label>
                <select name="event" class="admin-form-select" onchange="this.form.submit()">
                    <option value="0">Alla event</option>
                    <?php foreach ($promotorFilterEvents ?? [] as $ev): ?>
                    <option value="<?= $ev['id'] ?>" <?= $ev['id'] == $ecoEvent ? 'selected' : '' ?>><?= h($ev['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>
    </div>
</div>

<!-- Discount codes link -->
<div style="margin-bottom: var(--space-lg);">
    <a href="/admin/discount-codes.php" class="btn btn-secondary">
        <i data-lucide="ticket"></i>
        Hantera rabattkoder
    </a>
</div>

<!-- Order table -->
<div class="admin-card">
    <div class="admin-card-header">
        <h2>Betalningar <?= $ecoYear ?><?= $ecoMonth > 0 ? ' ' . ($monthNames[$ecoMonth] ?? '') : '' ?></h2>
    </div>
    <div class="admin-card-body p-0">
        <?php if (empty($promotorOrders)): ?>
        <div class="admin-empty-state">
            <i data-lucide="inbox"></i>
            <h3>Inga betalningar</h3>
            <p>Inga betalda ordrar hittades för vald period.</p>
        </div>
        <?php else: ?>

        <!-- Desktop table -->
        <div class="admin-table-container eco-table-wrap">
            <table class="admin-table eco-table">
                <thead>
                    <tr>
                        <th>Ordernr</th>
                        <th>Event</th>
                        <th style="text-align:right;">Belopp</th>
                        <th>Betalsätt</th>
                        <th style="text-align:right;">Avgift</th>
                        <th style="text-align:right;">Plattform</th>
                        <th style="text-align:right;">Netto</th>
                        <th>Rabatt</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($promotorOrders as $order):
                        $method = $order['payment_method'] ?? 'card';
                        $methodLabel = match($method) {
                            'swish', 'swish_csv' => 'Swish',
                            'card' => 'Kort',
                            'manual' => 'Manuell',
                            'free' => 'Gratis',
                            default => ucfirst($method)
                        };
                    ?>
                    <tr>
                        <td>
                            <code style="font-size:var(--text-sm);"><?= h($order['order_number'] ?? '#' . $order['id']) ?></code>
                            <div class="text-xs text-secondary"><?= date('j M', strtotime($order['created_at'])) ?></div>
                        </td>
                        <td><div class="eco-event"><?= h($order['event_name'] ?? 'Serie') ?></div></td>
                        <td style="text-align:right;font-weight:500;"><?= number_format($order['total_amount'], 2, ',', ' ') ?> kr</td>
                        <td><span style="font-size:var(--text-sm);"><?= $methodLabel ?></span></td>
                        <td style="text-align:right;color:var(--color-error);">
                            <?php if ($order['payment_fee'] > 0): ?>
                            <span class="<?= $order['fee_type'] === 'estimated' ? 'fee-est' : '' ?>">-<?= number_format($order['payment_fee'], 2, ',', ' ') ?></span>
                            <?php else: ?><span class="text-secondary">-</span><?php endif; ?>
                        </td>
                        <td style="text-align:right;color:var(--color-error);">
                            <?php if ($order['platform_fee'] > 0): ?>-<?= number_format($order['platform_fee'], 2, ',', ' ') ?><?php else: ?><span class="text-secondary">-</span><?php endif; ?>
                        </td>
                        <td style="text-align:right;font-weight:600;color:var(--color-success);"><?= number_format($order['net_amount'], 2, ',', ' ') ?> kr</td>
                        <td>
                            <?php if (!empty($order['discount_code'])): ?>
                            <span class="badge badge-warning"><?= h($order['discount_code']) ?></span>
                            <?php elseif ((float)($order['discount'] ?? 0) > 0): ?>
                            <span style="font-size:var(--text-xs);color:var(--color-warning);">-<?= number_format($order['discount'], 0) ?> kr</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="eco-summary">
                        <td colspan="2" style="font-weight:600;">Summa (<?= $promotorOrderTotals['order_count'] ?> ordrar)</td>
                        <td style="text-align:right;"><?= number_format($promotorOrderTotals['gross'], 2, ',', ' ') ?> kr</td>
                        <td></td>
                        <td style="text-align:right;color:var(--color-error);">-<?= number_format($promotorOrderTotals['payment_fees'], 2, ',', ' ') ?> kr</td>
                        <td style="text-align:right;color:var(--color-error);">-<?= number_format($promotorOrderTotals['platform_fees'], 2, ',', ' ') ?> kr</td>
                        <td style="text-align:right;color:var(--color-success);"><?= number_format($promotorOrderTotals['net'], 2, ',', ' ') ?> kr</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <!-- Mobile portrait card view -->
        <div class="eco-cards">
            <?php foreach ($promotorOrders as $order):
                $method = $order['payment_method'] ?? 'card';
                $methodLabel = match($method) { 'swish','swish_csv' => 'Swish', 'card' => 'Kort', 'manual' => 'Manuell', 'free' => 'Gratis', default => ucfirst($method) };
                $totalFees = $order['payment_fee'] + $order['platform_fee'];
            ?>
            <div style="padding:var(--space-md);border-bottom:1px solid var(--color-border);">
                <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:var(--space-xs);">
                    <div>
                        <div style="font-weight:500;color:var(--color-text-primary);margin-bottom:2px;"><?= h($order['event_name'] ?? 'Serie') ?></div>
                        <div class="text-xs text-secondary">
                            <code><?= h($order['order_number'] ?? '#' . $order['id']) ?></code>
                            &middot; <?= date('j M Y', strtotime($order['created_at'])) ?>
                            &middot; <?= $methodLabel ?>
                            <?php if (!empty($order['discount_code'])): ?>
                            &middot; <span style="color:var(--color-warning);"><?= h($order['discount_code']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:var(--space-xs);font-size:var(--text-xs);text-align:center;background:var(--color-bg-sunken);padding:var(--space-xs);border-radius:var(--radius-sm);">
                    <div>
                        <div style="color:var(--color-text-muted);margin-bottom:1px;">Belopp</div>
                        <div style="font-weight:600;"><?= number_format($order['total_amount'], 0, ',', ' ') ?> kr</div>
                    </div>
                    <div>
                        <div style="color:var(--color-text-muted);margin-bottom:1px;">Avgift</div>
                        <div style="color:var(--color-error);"><?= $order['payment_fee'] > 0 ? '-' . number_format($order['payment_fee'], 0, ',', ' ') : '-' ?></div>
                    </div>
                    <div>
                        <div style="color:var(--color-text-muted);margin-bottom:1px;">Plattform</div>
                        <div style="color:var(--color-error);"><?= $order['platform_fee'] > 0 ? '-' . number_format($order['platform_fee'], 0, ',', ' ') : '-' ?></div>
                    </div>
                    <div>
                        <div style="color:var(--color-text-muted);margin-bottom:1px;">Netto</div>
                        <div style="font-weight:700;color:var(--color-success);"><?= number_format($order['net_amount'], 0, ',', ' ') ?> kr</div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            <div style="padding:var(--space-md);background:var(--color-bg-hover);">
                <div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:var(--space-xs);font-size:var(--text-xs);text-align:center;">
                    <div>
                        <div style="color:var(--color-text-muted);margin-bottom:1px;">Brutto</div>
                        <div style="font-weight:700;"><?= number_format($promotorOrderTotals['gross'], 0, ',', ' ') ?> kr</div>
                    </div>
                    <div>
                        <div style="color:var(--color-text-muted);margin-bottom:1px;">Avgifter</div>
                        <div style="font-weight:700;color:var(--color-error);">-<?= number_format($promotorOrderTotals['payment_fees'], 0, ',', ' ') ?></div>
                    </div>
                    <div>
                        <div style="color:var(--color-text-muted);margin-bottom:1px;">Plattform</div>
                        <div style="font-weight:700;color:var(--color-error);">-<?= number_format($promotorOrderTotals['platform_fees'], 0, ',', ' ') ?></div>
                    </div>
                    <div>
                        <div style="color:var(--color-text-muted);margin-bottom:1px;">Netto</div>
                        <div style="font-weight:700;color:var(--color-success);"><?= number_format($promotorOrderTotals['net'], 0, ',', ' ') ?> kr</div>
                    </div>
                </div>
                <div style="text-align:center;font-size:var(--text-xs);color:var(--color-text-muted);margin-top:var(--space-xs);">
                    <?= $promotorOrderTotals['order_count'] ?> ordrar
                </div>
            </div>
        </div>

        <?php endif; ?>
    </div>
</div>

<?php elseif ($promotorTab === 'media'): ?>
<!-- =============== MEDIA TAB =============== -->
<div class="admin-card">
    <div class="admin-card-header" style="display:flex;justify-content:space-between;align-items:center;">
        <h2>Media</h2>
        <a href="/admin/media.php" class="btn btn-primary">
            <i data-lucide="folder-open"></i>
            Öppna mediabiblioteket
        </a>
    </div>
    <div class="admin-card-body">
        <p style="color:var(--color-text-secondary);margin-bottom:var(--space-lg);">
            Ladda upp bilder i mediabiblioteket som sedan kan kopplas till sponsorplatser på dina event.
        </p>
        <div style="display:grid;gap:var(--space-md);">
            <div style="display:flex;align-items:center;gap:var(--space-sm);padding:var(--space-md);background:var(--color-bg-sunken);border-radius:var(--radius-md);">
                <i data-lucide="image" style="width:20px;height:20px;color:var(--color-accent);flex-shrink:0;"></i>
                <div>
                    <strong style="font-size:var(--text-sm);">Banner</strong>
                    <div class="text-xs text-secondary">1200 x 150 px - Visas i resultatheader och eventsida</div>
                </div>
            </div>
            <div style="display:flex;align-items:center;gap:var(--space-sm);padding:var(--space-md);background:var(--color-bg-sunken);border-radius:var(--radius-md);">
                <i data-lucide="square" style="width:20px;height:20px;color:var(--color-accent);flex-shrink:0;"></i>
                <div>
                    <strong style="font-size:var(--text-sm);">Logo</strong>
                    <div class="text-xs text-secondary">600 x 150 px - Sponsorlogga i sidebar och kort</div>
                </div>
            </div>
            <div style="display:flex;align-items:center;gap:var(--space-sm);padding:var(--space-md);background:var(--color-bg-sunken);border-radius:var(--radius-md);">
                <i data-lucide="trophy" style="width:20px;height:20px;color:var(--color-accent);flex-shrink:0;"></i>
                <div>
                    <strong style="font-size:var(--text-sm);">Resultatheader</strong>
                    <div class="text-xs text-secondary">Max 40px höjd - Liten logga bredvid klassnamn</div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php endif; // end promotor tabs ?>

<?php endif; // end admin/promotor view toggle ?>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>

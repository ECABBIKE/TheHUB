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

if ($isAdmin) {
    // Estimate rates (fallback when actual fees aren't stored)
    $STRIPE_PERCENT = 1.5;
    $STRIPE_FIXED = 2.00; // SEK per transaction
    $SWISH_FEE = 2.00;    // SEK per transaction (approximate)
    $VAT_RATE = 6;         // Standard sport event VAT

    // Check if stripe_fee column exists (migration 049)
    $hasStripeFeeCol = false;
    try {
        $colCheck = $db->getAll("SHOW COLUMNS FROM orders LIKE 'stripe_fee'");
        $hasStripeFeeCol = !empty($colCheck);
    } catch (Exception $e) {}

    // Build actual Stripe fee sub-query per recipient (sum of stored fees)
    $actualFeeSelect = $hasStripeFeeCol
        ? "COALESCE(SUM(CASE WHEN o.payment_status = 'paid' AND o.payment_method = 'card' AND o.stripe_fee IS NOT NULL THEN o.stripe_fee ELSE 0 END), 0) as actual_stripe_fees,
           COUNT(DISTINCT CASE WHEN o.payment_status = 'paid' AND o.payment_method = 'card' AND o.stripe_fee IS NOT NULL THEN o.id END) as actual_fee_count,"
        : "0 as actual_stripe_fees, 0 as actual_fee_count,";

    // Get all payment recipients with their financial data
    try {
        $recipientWhere = $filterRecipient ? "AND pr.id = " . intval($filterRecipient) : "";

        $payoutData = $db->getAll("
            SELECT
                pr.id,
                pr.name,
                pr.org_number,
                pr.platform_fee_percent,
                pr.swish_number,
                pr.bankgiro,
                pr.bank_account,
                pr.bank_name,
                pr.bank_clearing,
                pr.contact_email,

                -- Gross revenue (paid orders only)
                COALESCE(SUM(CASE WHEN o.payment_status = 'paid' THEN oi.total_price ELSE 0 END), 0) as gross_revenue,

                -- By payment method
                COALESCE(SUM(CASE WHEN o.payment_status = 'paid' AND o.payment_method = 'card' THEN oi.total_price ELSE 0 END), 0) as card_revenue,
                COALESCE(SUM(CASE WHEN o.payment_status = 'paid' AND o.payment_method IN ('swish', 'swish_csv') THEN oi.total_price ELSE 0 END), 0) as swish_revenue,
                COALESCE(SUM(CASE WHEN o.payment_status = 'paid' AND o.payment_method IN ('manual', 'free') THEN oi.total_price ELSE 0 END), 0) as manual_revenue,

                -- Actual Stripe fees from webhook (migration 049)
                {$actualFeeSelect}

                -- Order counts by method
                COUNT(DISTINCT CASE WHEN o.payment_status = 'paid' AND o.payment_method = 'card' THEN o.id END) as card_order_count,
                COUNT(DISTINCT CASE WHEN o.payment_status = 'paid' AND o.payment_method IN ('swish', 'swish_csv') THEN o.id END) as swish_order_count,
                COUNT(DISTINCT CASE WHEN o.payment_status = 'paid' AND o.payment_method IN ('manual', 'free') THEN o.id END) as manual_order_count,
                COUNT(DISTINCT CASE WHEN o.payment_status = 'paid' THEN o.id END) as paid_order_count,

                -- Pending
                COALESCE(SUM(CASE WHEN o.payment_status = 'pending' THEN oi.total_price ELSE 0 END), 0) as pending_revenue,
                COUNT(DISTINCT CASE WHEN o.payment_status = 'pending' THEN o.id END) as pending_order_count,

                -- Refunded
                COALESCE(SUM(CASE WHEN o.payment_status = 'refunded' THEN oi.total_price ELSE 0 END), 0) as refunded_amount,

                -- Event count
                COUNT(DISTINCT o.event_id) as event_count

            FROM payment_recipients pr
            LEFT JOIN order_items oi ON oi.payment_recipient_id = pr.id
            LEFT JOIN orders o ON oi.order_id = o.id AND YEAR(o.created_at) = ?
            WHERE pr.active = 1 {$recipientWhere}
            GROUP BY pr.id
            ORDER BY gross_revenue DESC
        ", [$filterYear]);
    } catch (Exception $e) {
        error_log("Promotor payout query error: " . $e->getMessage());
    }

    // Calculate fees for each recipient
    $payoutTotals = [
        'gross' => 0, 'vat' => 0, 'stripe_fees' => 0, 'swish_fees' => 0,
        'platform_fees' => 0, 'net_payout' => 0, 'pending' => 0
    ];

    foreach ($payoutData as &$r) {
        $gross = (float)$r['gross_revenue'];
        $platformPct = (float)($r['platform_fee_percent'] ?? 2.00);

        // VAT (included in price): gross * VAT_RATE / (100 + VAT_RATE)
        $r['vat_amount'] = round($gross * $VAT_RATE / (100 + $VAT_RATE), 2);

        // Stripe fees: use actual fees where available, estimate the rest
        $actualStripeFees = (float)($r['actual_stripe_fees'] ?? 0);
        $actualFeeCount = (int)($r['actual_fee_count'] ?? 0);
        $totalCardOrders = (int)$r['card_order_count'];
        $estimatedCount = $totalCardOrders - $actualFeeCount;

        // Estimate fees for orders without actual fee data
        $estimatedStripeFees = 0;
        if ($estimatedCount > 0) {
            // Proportional card revenue for estimated orders
            $estimatedCardRevenue = $totalCardOrders > 0
                ? (float)$r['card_revenue'] * ($estimatedCount / $totalCardOrders)
                : 0;
            $estimatedStripeFees = round(
                ($estimatedCardRevenue * $STRIPE_PERCENT / 100) +
                ($estimatedCount * $STRIPE_FIXED),
                2
            );
        }

        $r['stripe_fees'] = round($actualStripeFees + $estimatedStripeFees, 2);
        $r['stripe_fees_actual'] = $actualStripeFees;
        $r['stripe_fees_estimated'] = $estimatedStripeFees;
        $r['has_actual_fees'] = $actualFeeCount > 0;
        $r['all_fees_actual'] = ($actualFeeCount >= $totalCardOrders && $totalCardOrders > 0);

        // Swish fees (always estimated - Swish doesn't provide per-transaction fee data)
        $r['swish_fees'] = round((int)$r['swish_order_count'] * $SWISH_FEE, 2);

        // Platform fee on gross
        $r['platform_fee'] = round($gross * $platformPct / 100, 2);

        // Net payout: gross - stripe - swish - platform fee
        $r['net_payout'] = round($gross - $r['stripe_fees'] - $r['swish_fees'] - $r['platform_fee'], 2);

        // Totals
        $payoutTotals['gross'] += $gross;
        $payoutTotals['vat'] += $r['vat_amount'];
        $payoutTotals['stripe_fees'] += $r['stripe_fees'];
        $payoutTotals['swish_fees'] += $r['swish_fees'];
        $payoutTotals['platform_fees'] += $r['platform_fee'];
        $payoutTotals['net_payout'] += $r['net_payout'];
        $payoutTotals['pending'] += (float)$r['pending_revenue'];
    }
    unset($r);

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
}

// ============================================================
// PROMOTOR VIEW: Their assigned events/series
// ============================================================
$series = [];
$events = [];

if (!$isAdmin) {
    // Get promotor's series
    try {
        $series = $db->getAll("
            SELECT s.*,
                   m.filepath as banner_url,
                   COUNT(DISTINCT e.id) as event_count
            FROM series s
            JOIN promotor_series ps ON ps.series_id = s.id
            LEFT JOIN media m ON s.banner_media_id = m.id
            LEFT JOIN events e ON e.series_id = s.id AND YEAR(e.date) = YEAR(CURDATE())
            WHERE ps.user_id = ?
            GROUP BY s.id
            ORDER BY s.name
        ", [$userId]);
    } catch (Exception $e) {
        error_log("Promotor series error: " . $e->getMessage());
    }

    // Get promotor's events
    try {
        $events = $db->getAll("
            SELECT e.*,
                   s.name as series_name,
                   s.logo as series_logo,
                   COALESCE(reg.registration_count, 0) as registration_count,
                   COALESCE(reg.confirmed_count, 0) as confirmed_count,
                   COALESCE(reg.pending_count, 0) as pending_count,
                   COALESCE(ord.total_paid, 0) as total_paid,
                   COALESCE(ord.total_pending, 0) as total_pending
            FROM events e
            LEFT JOIN series s ON e.series_id = s.id
            JOIN promotor_events pe ON pe.event_id = e.id
            LEFT JOIN (
                SELECT event_id,
                       COUNT(*) as registration_count,
                       SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) as confirmed_count,
                       SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count
                FROM event_registrations
                GROUP BY event_id
            ) reg ON reg.event_id = e.id
            LEFT JOIN (
                SELECT event_id,
                       SUM(CASE WHEN payment_status = 'paid' THEN total_amount ELSE 0 END) as total_paid,
                       SUM(CASE WHEN payment_status = 'pending' THEN total_amount ELSE 0 END) as total_pending
                FROM orders
                GROUP BY event_id
            ) ord ON ord.event_id = e.id
            WHERE pe.user_id = ?
            ORDER BY e.date DESC
        ", [$userId]);
    } catch (Exception $e) {
        error_log("Promotor events error: " . $e->getMessage());
    }
}

// Page config for unified layout
$page_title = $isAdmin ? 'Utbetalningar & Ekonomi' : 'Mina Tävlingar';
$breadcrumbs = [
    ['label' => $isAdmin ? 'Utbetalningar & Ekonomi' : 'Mina Tävlingar']
];

include __DIR__ . '/components/unified-layout.php';
?>

<style>
.promotor-grid {
    display: grid;
    gap: var(--space-lg);
}
.event-card {
    background: var(--color-bg-surface);
    border-radius: var(--radius-lg);
    border: 1px solid var(--color-border);
    overflow: hidden;
}
.event-card-header {
    padding: var(--space-lg);
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: var(--space-md);
    border-bottom: 1px solid var(--color-border);
}
.event-info {
    flex: 1;
}
.event-title {
    font-size: var(--text-xl);
    font-weight: 600;
    color: var(--color-text-primary);
    margin: 0 0 var(--space-xs) 0;
}
.event-meta {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-md);
    color: var(--color-text-secondary);
    font-size: var(--text-sm);
}
.event-meta-item {
    display: flex;
    align-items: center;
    gap: var(--space-xs);
}
.event-meta-item i {
    width: 16px;
    height: 16px;
}
.event-series {
    display: flex;
    align-items: center;
    gap: var(--space-xs);
    font-size: var(--text-sm);
    color: var(--color-text-secondary);
    background: var(--color-bg-sunken);
    padding: var(--space-xs) var(--space-sm);
    border-radius: var(--radius-full);
}
.event-series img {
    width: 20px;
    height: 20px;
    object-fit: contain;
}
.event-card-body {
    padding: var(--space-lg);
}
.stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: var(--space-sm);
    margin-bottom: var(--space-lg);
}
@media (max-width: 600px) {
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

/* Mobile edge-to-edge design */
@media (max-width: 767px) {
    .event-card,
    .series-card {
        margin-left: calc(-1 * var(--space-md));
        margin-right: calc(-1 * var(--space-md));
        border-radius: 0;
        border-left: none;
        border-right: none;
        width: calc(100% + var(--space-md) * 2);
    }
    .series-grid,
    .promotor-grid {
        gap: 0;
    }
    .series-grid {
        grid-template-columns: 1fr;
    }
    .event-card + .event-card,
    .series-card + .series-card {
        border-top: none;
    }
}
.stat-box {
    background: var(--color-bg-sunken);
    padding: var(--space-md);
    border-radius: var(--radius-md);
    text-align: center;
}
.stat-value {
    font-size: var(--text-2xl);
    font-weight: 700;
    color: var(--color-accent);
}
.stat-value.success {
    color: var(--color-success);
}
.stat-value.pending {
    color: var(--color-warning);
}
.stat-label {
    font-size: var(--text-xs);
    color: var(--color-text-secondary);
    text-transform: uppercase;
    letter-spacing: 0.05em;
}
.event-actions {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-sm);
}
.event-actions .btn {
    display: inline-flex;
    align-items: center;
    gap: var(--space-xs);
    min-height: 44px;
}
.event-actions .btn i {
    width: 16px;
    height: 16px;
}
@media (max-width: 599px) {
    .event-actions {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: var(--space-xs);
    }
    .event-actions .btn {
        justify-content: center;
        font-size: var(--text-sm);
        padding: var(--space-sm);
    }
    .event-card-header {
        flex-direction: column;
        gap: var(--space-xs);
        padding: var(--space-md);
    }
    .event-card-body {
        padding: var(--space-md);
    }
    .event-title {
        font-size: var(--text-lg);
    }
    .stat-value {
        font-size: var(--text-xl);
    }
    .stat-box {
        padding: var(--space-sm);
    }
}
.empty-state {
    text-align: center;
    padding: var(--space-2xl);
    color: var(--color-text-secondary);
}
.empty-state i {
    width: 48px;
    height: 48px;
    margin-bottom: var(--space-md);
    opacity: 0.5;
}
.empty-state h2 {
    margin: 0 0 var(--space-sm) 0;
    color: var(--color-text-primary);
}

/* Series section */
.section-title {
    font-size: var(--text-xl);
    font-weight: 600;
    margin: 0 0 var(--space-lg) 0;
    color: var(--color-text-primary);
}
.series-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: var(--space-lg);
    margin-bottom: var(--space-2xl);
}
.series-card {
    background: var(--color-bg-surface);
    border-radius: var(--radius-lg);
    border: 1px solid var(--color-border);
    overflow: hidden;
    display: flex;
    flex-direction: column;
}
.series-card-header {
    padding: var(--space-lg);
    display: flex;
    align-items: center;
    gap: var(--space-md);
    border-bottom: 1px solid var(--color-border);
}
.series-logo {
    width: 48px;
    height: 48px;
    border-radius: var(--radius-md);
    background: var(--color-bg-sunken);
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    flex-shrink: 0;
}
.series-logo img {
    max-width: 100%;
    max-height: 100%;
    object-fit: contain;
}
.series-info h3 {
    margin: 0 0 var(--space-2xs) 0;
    font-size: var(--text-lg);
}
.series-info p {
    margin: 0;
    font-size: var(--text-sm);
    color: var(--color-text-secondary);
}
.series-card-body {
    padding: var(--space-lg);
    flex: 1;
}
.series-detail {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    margin-bottom: var(--space-sm);
    font-size: var(--text-sm);
    color: var(--color-text-secondary);
}
.series-detail i {
    width: 16px;
    height: 16px;
    flex-shrink: 0;
}
.series-detail.missing {
    color: var(--color-warning);
}
.series-card-footer {
    padding: var(--space-md) var(--space-lg);
    background: var(--color-bg-sunken);
    border-top: 1px solid var(--color-border);
}
.series-card-footer .btn {
    width: 100%;
}

/* Modal styles */
.modal {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.8);
    z-index: 1000;
    padding: var(--space-lg);
    overflow-y: auto;
}
.modal.active {
    display: flex;
    align-items: flex-start;
    justify-content: center;
}
.modal-content {
    background: var(--color-bg-surface);
    border-radius: var(--radius-lg);
    max-width: 500px;
    width: 100%;
    margin-top: var(--space-xl);
}
.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: var(--space-md) var(--space-lg);
    border-bottom: 1px solid var(--color-border);
}
.modal-header h3 {
    margin: 0;
}
.modal-close {
    background: none;
    border: none;
    padding: var(--space-xs);
    cursor: pointer;
    color: var(--color-text-secondary);
    font-size: 24px;
    line-height: 1;
}
.modal-body {
    padding: var(--space-lg);
}
.modal-footer {
    padding: var(--space-md) var(--space-lg);
    background: var(--color-bg-sunken);
    border-top: 1px solid var(--color-border);
    display: flex;
    justify-content: flex-end;
    gap: var(--space-sm);
}
.form-group {
    margin-bottom: var(--space-md);
}
.form-label {
    display: block;
    margin-bottom: var(--space-xs);
    font-weight: 500;
    font-size: var(--text-sm);
}
.form-input {
    width: 100%;
    padding: var(--space-sm) var(--space-md);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    background: var(--color-bg-sunken);
    color: var(--color-text-primary);
}
.form-hint {
    font-size: var(--text-xs);
    color: var(--color-text-secondary);
    margin-top: var(--space-xs);
}
.logo-preview {
    width: 100%;
    height: 80px;
    background: var(--color-bg-sunken);
    border: 2px dashed var(--color-border);
    border-radius: var(--radius-md);
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: var(--space-sm);
    overflow: hidden;
}
.logo-preview img {
    max-width: 100%;
    max-height: 100%;
    object-fit: contain;
}
.logo-actions {
    display: flex;
    gap: var(--space-sm);
}

@media (max-width: 599px) {
    .modal {
        padding: 0;
    }
    .modal-content {
        max-width: 100%;
        height: 100%;
        margin: 0;
        border-radius: 0;
        display: flex;
        flex-direction: column;
    }
    .modal-body {
        flex: 1;
        overflow-y: auto;
    }
}

/* ===== Admin Financial View ===== */
.payout-filters {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-md);
    align-items: flex-end;
    margin-bottom: var(--space-lg);
}
.payout-filters .filter-group {
    display: flex;
    flex-direction: column;
    gap: var(--space-xs);
}
.payout-filters label {
    font-size: var(--text-sm);
    font-weight: 500;
    color: var(--color-text-secondary);
}
.payout-filters select {
    padding: var(--space-sm) var(--space-md);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    background: var(--color-bg-surface);
    color: var(--color-text-primary);
    font-size: var(--text-sm);
    min-width: 160px;
}
.totals-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: var(--space-md);
    margin-bottom: var(--space-xl);
}
.total-card {
    background: var(--color-bg-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    padding: var(--space-lg);
    text-align: center;
}
.total-value {
    font-size: var(--text-2xl);
    font-weight: 700;
    color: var(--color-text-primary);
}
.total-value.accent { color: var(--color-accent); }
.total-value.success { color: var(--color-success); }
.total-value.warning { color: var(--color-warning); }
.total-value.danger { color: var(--color-error); }
.total-label {
    font-size: var(--text-xs);
    color: var(--color-text-secondary);
    text-transform: uppercase;
    letter-spacing: 0.05em;
    margin-top: var(--space-xs);
}
.recipient-card {
    background: var(--color-bg-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-lg);
    overflow: hidden;
    margin-bottom: var(--space-lg);
}
.recipient-header {
    padding: var(--space-lg);
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: var(--space-md);
    border-bottom: 1px solid var(--color-border);
    flex-wrap: wrap;
}
.recipient-name {
    font-size: var(--text-xl);
    font-weight: 600;
    color: var(--color-text-primary);
    margin: 0 0 var(--space-xs) 0;
}
.recipient-meta {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-md);
    font-size: var(--text-sm);
    color: var(--color-text-secondary);
}
.recipient-meta-item {
    display: flex;
    align-items: center;
    gap: var(--space-xs);
}
.recipient-meta-item i { width: 14px; height: 14px; }
.recipient-payout {
    text-align: right;
    flex-shrink: 0;
}
.recipient-payout-label {
    font-size: var(--text-xs);
    color: var(--color-text-secondary);
    text-transform: uppercase;
    letter-spacing: 0.05em;
}
.recipient-payout-value {
    font-size: var(--text-2xl);
    font-weight: 700;
    color: var(--color-success);
}
.recipient-body {
    padding: var(--space-lg);
}
.fee-breakdown {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: var(--space-md);
}
@media (max-width: 767px) {
    .fee-breakdown { grid-template-columns: 1fr; }
    .recipient-header { flex-direction: column; }
    .recipient-payout { text-align: left; }
    .totals-grid { grid-template-columns: repeat(2, 1fr); }
    .recipient-card {
        margin-left: calc(-1 * var(--space-md));
        margin-right: calc(-1 * var(--space-md));
        border-radius: 0;
        border-left: none;
        border-right: none;
        width: calc(100% + var(--space-md) * 2);
    }
}
.fee-section {
    background: var(--color-bg-sunken);
    border-radius: var(--radius-md);
    padding: var(--space-md);
}
.fee-section h4 {
    font-size: var(--text-sm);
    font-weight: 600;
    color: var(--color-text-primary);
    margin: 0 0 var(--space-sm) 0;
    display: flex;
    align-items: center;
    gap: var(--space-xs);
}
.fee-section h4 i { width: 16px; height: 16px; color: var(--color-accent); }
.fee-row {
    display: flex;
    justify-content: space-between;
    padding: var(--space-xs) 0;
    font-size: var(--text-sm);
    border-bottom: 1px solid var(--color-border);
}
.fee-row:last-child { border-bottom: none; }
.fee-label { color: var(--color-text-secondary); }
.fee-value { font-weight: 500; color: var(--color-text-primary); }
.fee-value.negative { color: var(--color-error); }
.fee-value.positive { color: var(--color-success); }
.bank-info {
    margin-top: var(--space-md);
    padding: var(--space-md);
    background: var(--color-accent-light);
    border-radius: var(--radius-md);
    font-size: var(--text-sm);
}
.bank-info-title {
    font-weight: 600;
    color: var(--color-text-primary);
    margin-bottom: var(--space-xs);
    display: flex;
    align-items: center;
    gap: var(--space-xs);
}
.bank-info-title i { width: 16px; height: 16px; }
.bank-info-row {
    display: flex;
    gap: var(--space-sm);
    padding: 2px 0;
    color: var(--color-text-secondary);
}
.bank-info-label { font-weight: 500; min-width: 100px; }

/* Fee badges (actual/estimated indicators) */
.fee-badge {
    display: inline-block;
    font-size: 10px;
    padding: 1px 6px;
    border-radius: var(--radius-full);
    font-weight: 500;
    vertical-align: middle;
    margin-left: var(--space-2xs);
}
.fee-badge-actual {
    background: rgba(16, 185, 129, 0.15);
    color: var(--color-success);
}
.fee-badge-mixed {
    background: rgba(251, 191, 36, 0.15);
    color: var(--color-warning);
}
.fee-badge-estimated {
    background: rgba(134, 143, 162, 0.15);
    color: var(--color-text-muted);
}

/* Edit platform fee button */
.btn-edit-fee {
    background: none;
    border: none;
    cursor: pointer;
    padding: 2px;
    color: var(--color-text-muted);
    opacity: 0.6;
    transition: opacity 0.15s;
    vertical-align: middle;
}
.btn-edit-fee:hover { opacity: 1; color: var(--color-accent); }
.btn-edit-fee i { width: 12px; height: 12px; }

/* Inline fee edit */
.fee-edit-inline {
    display: inline-flex;
    align-items: center;
    gap: var(--space-xs);
}
.fee-edit-inline input {
    width: 60px;
    padding: 2px var(--space-xs);
    border: 1px solid var(--color-accent);
    border-radius: var(--radius-sm);
    background: var(--color-bg-sunken);
    color: var(--color-text-primary);
    font-size: var(--text-sm);
    text-align: center;
}
.fee-edit-inline button {
    padding: 2px 6px;
    border: none;
    border-radius: var(--radius-sm);
    cursor: pointer;
    font-size: 11px;
    font-weight: 500;
}
.fee-edit-save {
    background: var(--color-success);
    color: white;
}
.fee-edit-cancel {
    background: var(--color-bg-sunken);
    color: var(--color-text-secondary);
}
</style>

<?php if ($isAdmin): ?>
<!-- ===== ADMIN: FINANCIAL PAYOUT OVERVIEW ===== -->

<!-- Filters -->
<form method="GET" class="payout-filters">
    <div class="filter-group">
        <label>År</label>
        <select name="year" onchange="this.form.submit()">
            <?php
            $years = array_column($availableYears, 'yr');
            if (!in_array($filterYear, $years)) $years[] = $filterYear;
            rsort($years);
            foreach ($years as $yr): ?>
            <option value="<?= $yr ?>" <?= $yr == $filterYear ? 'selected' : '' ?>><?= $yr ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="filter-group">
        <label>Betalningsmottagare</label>
        <select name="recipient" onchange="this.form.submit()">
            <option value="0">Alla mottagare</option>
            <?php foreach ($allRecipients as $rec): ?>
            <option value="<?= $rec['id'] ?>" <?= $rec['id'] == $filterRecipient ? 'selected' : '' ?>><?= h($rec['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
</form>

<!-- Totals -->
<div class="totals-grid">
    <div class="total-card">
        <div class="total-value accent"><?= number_format($payoutTotals['gross'], 0, ',', ' ') ?> kr</div>
        <div class="total-label">Bruttointäkter</div>
    </div>
    <div class="total-card">
        <div class="total-value"><?= number_format($payoutTotals['vat'], 0, ',', ' ') ?> kr</div>
        <div class="total-label">Varav moms (6%)</div>
    </div>
    <div class="total-card">
        <div class="total-value danger"><?= number_format($payoutTotals['stripe_fees'] + $payoutTotals['swish_fees'], 0, ',', ' ') ?> kr</div>
        <div class="total-label">Betalningsavgifter</div>
    </div>
    <div class="total-card">
        <div class="total-value warning"><?= number_format($payoutTotals['platform_fees'], 0, ',', ' ') ?> kr</div>
        <div class="total-label">Plattformsavgifter</div>
    </div>
    <div class="total-card">
        <div class="total-value success"><?= number_format($payoutTotals['net_payout'], 0, ',', ' ') ?> kr</div>
        <div class="total-label">Att betala ut</div>
    </div>
    <div class="total-card">
        <div class="total-value warning"><?= number_format($payoutTotals['pending'], 0, ',', ' ') ?> kr</div>
        <div class="total-label">Ej betalda ordrar</div>
    </div>
</div>

<?php if (empty($payoutData)): ?>
<div class="recipient-card">
    <div style="text-align: center; padding: var(--space-2xl); color: var(--color-text-secondary);">
        <i data-lucide="inbox" style="width: 48px; height: 48px; margin-bottom: var(--space-md); opacity: 0.5;"></i>
        <h3>Inga betalningsmottagare</h3>
        <p>Inga aktiva betalningsmottagare med ordrar för <?= $filterYear ?>.</p>
    </div>
</div>
<?php else: ?>

<?php foreach ($payoutData as $r):
    if ((float)$r['gross_revenue'] == 0 && (float)$r['pending_revenue'] == 0 && !$filterRecipient) continue;
?>
<div class="recipient-card">
    <div class="recipient-header">
        <div>
            <h2 class="recipient-name"><?= h($r['name']) ?></h2>
            <div class="recipient-meta">
                <?php if ($r['org_number']): ?>
                <span class="recipient-meta-item">
                    <i data-lucide="building"></i>
                    Org.nr: <?= h($r['org_number']) ?>
                </span>
                <?php endif; ?>
                <span class="recipient-meta-item">
                    <i data-lucide="calendar"></i>
                    <?= (int)$r['event_count'] ?> event, <?= (int)$r['paid_order_count'] ?> betalda ordrar
                </span>
                <span class="recipient-meta-item platform-fee-display" data-recipient-id="<?= $r['id'] ?>">
                    <i data-lucide="percent"></i>
                    Plattformsavgift: <span class="platform-fee-value"><?= number_format($r['platform_fee_percent'], 1) ?>%</span>
                    <button type="button" class="btn-edit-fee" onclick="editPlatformFee(<?= $r['id'] ?>, <?= (float)$r['platform_fee_percent'] ?>)" title="Ändra plattformsavgift">
                        <i data-lucide="pencil"></i>
                    </button>
                </span>
            </div>
        </div>
        <div class="recipient-payout">
            <div class="recipient-payout-label">Att betala ut</div>
            <div class="recipient-payout-value"><?= number_format($r['net_payout'], 0, ',', ' ') ?> kr</div>
        </div>
    </div>

    <div class="recipient-body">
        <div class="fee-breakdown">
            <!-- Left: Revenue breakdown -->
            <div class="fee-section">
                <h4><i data-lucide="trending-up"></i> Intäkter</h4>
                <div class="fee-row">
                    <span class="fee-label">Bruttointäkter</span>
                    <span class="fee-value"><?= number_format($r['gross_revenue'], 2, ',', ' ') ?> kr</span>
                </div>
                <div class="fee-row">
                    <span class="fee-label">Varav moms (6%)</span>
                    <span class="fee-value"><?= number_format($r['vat_amount'], 2, ',', ' ') ?> kr</span>
                </div>
                <div class="fee-row">
                    <span class="fee-label">Kortbetalningar (<?= (int)$r['card_order_count'] ?> st)</span>
                    <span class="fee-value"><?= number_format($r['card_revenue'], 0, ',', ' ') ?> kr</span>
                </div>
                <div class="fee-row">
                    <span class="fee-label">Swish (<?= (int)$r['swish_order_count'] ?> st)</span>
                    <span class="fee-value"><?= number_format($r['swish_revenue'], 0, ',', ' ') ?> kr</span>
                </div>
                <?php if ((float)$r['manual_revenue'] > 0): ?>
                <div class="fee-row">
                    <span class="fee-label">Manuellt (<?= (int)$r['manual_order_count'] ?> st)</span>
                    <span class="fee-value"><?= number_format($r['manual_revenue'], 0, ',', ' ') ?> kr</span>
                </div>
                <?php endif; ?>
                <?php if ((float)$r['pending_revenue'] > 0): ?>
                <div class="fee-row">
                    <span class="fee-label">Ej betalda (<?= (int)$r['pending_order_count'] ?> st)</span>
                    <span class="fee-value warning"><?= number_format($r['pending_revenue'], 0, ',', ' ') ?> kr</span>
                </div>
                <?php endif; ?>
            </div>

            <!-- Right: Fee breakdown -->
            <div class="fee-section">
                <h4><i data-lucide="calculator"></i> Avgifter & Utbetalning</h4>
                <div class="fee-row">
                    <span class="fee-label">
                        Stripe-avgifter
                        <?php if ($r['all_fees_actual']): ?>
                            <span class="fee-badge fee-badge-actual">Faktiska</span>
                        <?php elseif ($r['has_actual_fees']): ?>
                            <span class="fee-badge fee-badge-mixed">Delvis faktiska</span>
                        <?php else: ?>
                            <span class="fee-badge fee-badge-estimated">Uppskattade</span>
                        <?php endif; ?>
                    </span>
                    <span class="fee-value negative">-<?= number_format($r['stripe_fees'], 2, ',', ' ') ?> kr</span>
                </div>
                <?php if ($r['swish_fees'] > 0): ?>
                <div class="fee-row">
                    <span class="fee-label">Swish-avgifter (~2 kr/order) <span class="fee-badge fee-badge-estimated">Uppskattade</span></span>
                    <span class="fee-value negative">-<?= number_format($r['swish_fees'], 2, ',', ' ') ?> kr</span>
                </div>
                <?php endif; ?>
                <div class="fee-row">
                    <span class="fee-label">Plattformsavgift (<span class="platform-fee-pct-<?= $r['id'] ?>"><?= number_format($r['platform_fee_percent'], 1) ?></span>%)</span>
                    <span class="fee-value negative">-<?= number_format($r['platform_fee'], 2, ',', ' ') ?> kr</span>
                </div>
                <?php if ((float)$r['refunded_amount'] > 0): ?>
                <div class="fee-row">
                    <span class="fee-label">Återbetalningar</span>
                    <span class="fee-value negative">-<?= number_format($r['refunded_amount'], 2, ',', ' ') ?> kr</span>
                </div>
                <?php endif; ?>
                <div class="fee-row" style="padding-top: var(--space-sm); border-top: 2px solid var(--color-border); font-weight: 600;">
                    <span class="fee-label" style="color: var(--color-text-primary); font-weight: 600;">Netto att betala ut</span>
                    <span class="fee-value positive" style="font-size: var(--text-lg);"><?= number_format($r['net_payout'], 2, ',', ' ') ?> kr</span>
                </div>
            </div>
        </div>

        <!-- Bank info -->
        <?php if ($r['swish_number'] || $r['bankgiro'] || $r['bank_account']): ?>
        <div class="bank-info">
            <div class="bank-info-title">
                <i data-lucide="landmark"></i>
                Utbetalningsuppgifter
            </div>
            <?php if ($r['swish_number']): ?>
            <div class="bank-info-row">
                <span class="bank-info-label">Swish:</span>
                <span><?= h($r['swish_number']) ?></span>
            </div>
            <?php endif; ?>
            <?php if ($r['bankgiro']): ?>
            <div class="bank-info-row">
                <span class="bank-info-label">Bankgiro:</span>
                <span><?= h($r['bankgiro']) ?></span>
            </div>
            <?php endif; ?>
            <?php if ($r['bank_account']): ?>
            <div class="bank-info-row">
                <span class="bank-info-label">Bankkonto:</span>
                <span><?= h(($r['bank_name'] ? $r['bank_name'] . ' ' : '') . ($r['bank_clearing'] ? $r['bank_clearing'] . '-' : '') . $r['bank_account']) ?></span>
            </div>
            <?php endif; ?>
            <?php if ($r['contact_email']): ?>
            <div class="bank-info-row">
                <span class="bank-info-label">E-post:</span>
                <span><?= h($r['contact_email']) ?></span>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<script>
function editPlatformFee(recipientId, currentFee) {
    const container = document.querySelector(`.platform-fee-display[data-recipient-id="${recipientId}"]`);
    if (!container) return;

    const valueSpan = container.querySelector('.platform-fee-value');
    const editBtn = container.querySelector('.btn-edit-fee');
    const originalText = valueSpan.textContent;

    // Replace with inline edit
    editBtn.style.display = 'none';
    valueSpan.innerHTML = `
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

    // Save on Enter, cancel on Escape
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

        const response = await fetch('/admin/promotor.php', {
            method: 'POST',
            body: formData
        });

        const result = await response.json();
        if (result.success) {
            // Update display
            const formatted = newFee.toFixed(1);
            cancelFeeEdit(recipientId, formatted + '%');

            // Update fee percentage labels in breakdown
            const pctSpan = document.querySelector('.platform-fee-pct-' + recipientId);
            if (pctSpan) pctSpan.textContent = formatted;

            // Reload page to recalculate totals
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
    const container = document.querySelector(`.platform-fee-display[data-recipient-id="${recipientId}"]`);
    if (!container) return;

    const valueSpan = container.querySelector('.platform-fee-value');
    const editBtn = container.querySelector('.btn-edit-fee');

    valueSpan.textContent = originalText;
    editBtn.style.display = '';
    if (typeof lucide !== 'undefined') lucide.createIcons();
}
</script>

<?php else: ?>
<!-- ===== PROMOTOR VIEW ===== -->

<!-- MINA SERIER -->
<?php if (!empty($series)): ?>
<h2 class="section-title">Mina Serier</h2>
<div class="series-grid">
    <?php foreach ($series as $s): ?>
    <div class="series-card" data-series-id="<?= $s['id'] ?>">
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
                <p><?= (int)$s['event_count'] ?> tävlingar <?= date('Y') ?></p>
            </div>
        </div>
        <div class="series-card-body">
            <?php if ($s['banner_media_id'] ?? null): ?>
            <div class="series-detail">
                <i data-lucide="image"></i>
                <span>Banner konfigurerad</span>
            </div>
            <?php else: ?>
            <div class="series-detail missing">
                <i data-lucide="image-off"></i>
                <span>Ingen banner</span>
            </div>
            <?php endif; ?>
        </div>
        <div class="series-card-footer" style="display: flex; gap: var(--space-sm);">
            <button class="btn btn-secondary" onclick="editSeries(<?= $s['id'] ?>)" style="flex: 1;">
                <i data-lucide="settings"></i>
                Inställningar
            </button>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- MINA TÄVLINGAR -->
<?php if (empty($events)): ?>
<div class="event-card">
    <div class="empty-state">
        <i data-lucide="calendar-x"></i>
        <h2>Inga tävlingar</h2>
        <p>Du har inga tävlingar tilldelade ännu. Kontakta administratören för att få tillgång.</p>
    </div>
</div>
<?php else: ?>
<div class="promotor-grid">
    <?php foreach ($events as $event): ?>
    <div class="event-card">
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
                    <div class="stat-value"><?= (int)$event['registration_count'] ?></div>
                    <div class="stat-label">Anmälda</div>
                </div>
                <div class="stat-box">
                    <div class="stat-value success"><?= (int)$event['confirmed_count'] ?></div>
                    <div class="stat-label">Bekräftade</div>
                </div>
                <div class="stat-box">
                    <div class="stat-value pending"><?= (int)$event['pending_count'] ?></div>
                    <div class="stat-label">Väntande</div>
                </div>
                <div class="stat-box">
                    <div class="stat-value"><?= number_format($event['total_paid'], 0, ',', ' ') ?> kr</div>
                    <div class="stat-label">Betalat</div>
                </div>
            </div>

            <div class="event-actions">
                <a href="/admin/event-edit.php?id=<?= $event['id'] ?>" class="btn btn-primary">
                    <i data-lucide="pencil"></i>
                    Redigera event
                </a>
                <a href="/admin/event-startlist.php?event_id=<?= $event['id'] ?>" class="btn btn-secondary">
                    <i data-lucide="clipboard-list"></i>
                    Startlista
                </a>
                <a href="/admin/promotor-registrations.php?event_id=<?= $event['id'] ?>" class="btn btn-secondary">
                    <i data-lucide="users"></i>
                    Anmälningar
                </a>
                <a href="/admin/promotor-payments.php?event_id=<?= $event['id'] ?>" class="btn btn-secondary">
                    <i data-lucide="credit-card"></i>
                    Betalningar
                </a>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Series Edit Modal -->
<div class="modal" id="seriesModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="seriesModalTitle">Redigera serie</h3>
            <button type="button" class="modal-close" onclick="closeSeriesModal()">&times;</button>
        </div>
        <form id="seriesForm" onsubmit="saveSeries(event)">
            <input type="hidden" id="seriesId" name="id">
            <div class="modal-body">
                <h4 style="margin: 0 0 var(--space-md) 0; font-size: var(--text-md);">
                    <i data-lucide="image" style="width: 18px; height: 18px; vertical-align: middle;"></i>
                    Serie-banner
                </h4>
                <p style="font-size: var(--text-sm); color: var(--color-text-secondary); margin-bottom: var(--space-md);">
                    Visas på alla tävlingar i serien (om inte tävlingen har egen banner).
                </p>

                <div class="form-group">
                    <label class="form-label">Banner <code style="background: var(--color-bg-sunken); padding: 2px 6px; border-radius: 4px; font-size: 0.7rem;">1200×150px</code></label>
                    <div class="logo-preview" id="seriesBannerPreview">
                        <i data-lucide="image-plus" style="width: 24px; height: 24px; opacity: 0.5;"></i>
                    </div>
                    <input type="hidden" id="seriesBannerMediaId" name="banner_media_id">
                    <div class="logo-actions">
                        <input type="file" id="seriesBannerUpload" accept="image/*" style="display:none" onchange="uploadSeriesBanner(this)">
                        <button type="button" class="btn btn-sm btn-primary" onclick="document.getElementById('seriesBannerUpload').click()">
                            <i data-lucide="upload"></i> Ladda upp
                        </button>
                        <button type="button" class="btn btn-sm btn-ghost" onclick="clearSeriesBanner()">
                            <i data-lucide="x"></i> Ta bort
                        </button>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeSeriesModal()">Avbryt</button>
                <button type="submit" class="btn btn-primary">Spara</button>
            </div>
        </form>
    </div>
</div>

<script>
// Store series data for modal
const seriesData = <?= json_encode(array_column($series, null, 'id')) ?>;
let currentSeriesId = null;

function editSeries(id) {
    const s = seriesData[id];
    if (!s) {
        alert('Kunde inte hitta seriedata');
        return;
    }

    currentSeriesId = id;
    document.getElementById('seriesId').value = id;
    document.getElementById('seriesModalTitle').textContent = 'Redigera ' + s.name;

    // Set Swish fields - try series-specific first, then payment recipient

    // Set banner preview
    clearSeriesBanner();
    if (s.banner_media_id && s.banner_url) {
        document.getElementById('seriesBannerMediaId').value = s.banner_media_id;
        document.getElementById('seriesBannerPreview').innerHTML = `<img src="${s.banner_url}" alt="Banner">`;
    }

    document.getElementById('seriesModal').classList.add('active');
    // Reinitialize Lucide icons in the modal
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

function closeSeriesModal() {
    document.getElementById('seriesModal').classList.remove('active');
    currentSeriesId = null;
}

async function uploadSeriesBanner(input) {
    const file = input.files[0];
    if (!file) return;

    if (!file.type.startsWith('image/')) {
        alert('Välj en bildfil (JPG, PNG, etc.)');
        return;
    }

    if (file.size > 10 * 1024 * 1024) {
        alert('Filen är för stor. Max 10MB.');
        return;
    }

    const preview = document.getElementById('seriesBannerPreview');
    preview.innerHTML = '<span style="font-size: 12px; color: var(--color-text-secondary);">Laddar upp...</span>';

    try {
        const formData = new FormData();
        formData.append('file', file);
        formData.append('folder', 'series');

        const response = await fetch('/api/media.php?action=upload', {
            method: 'POST',
            body: formData
        });

        const result = await response.json();

        if (result.success && result.media) {
            document.getElementById('seriesBannerMediaId').value = result.media.id;
            preview.innerHTML = `<img src="/${result.media.filepath}" alt="Banner">`;
        } else {
            alert('Uppladdning misslyckades: ' + (result.error || 'Okänt fel'));
            clearSeriesBanner();
        }
    } catch (error) {
        console.error('Upload error:', error);
        alert('Ett fel uppstod vid uppladdning');
        clearSeriesBanner();
    }

    input.value = '';
}

function clearSeriesBanner() {
    document.getElementById('seriesBannerMediaId').value = '';
    document.getElementById('seriesBannerPreview').innerHTML = '<i data-lucide="image-plus" style="width: 24px; height: 24px; opacity: 0.5;"></i>';
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

async function saveSeries(event) {
    event.preventDefault();

    const form = document.getElementById('seriesForm');
    const formData = new FormData(form);

    const data = {
        id: currentSeriesId,
        banner_media_id: formData.get('banner_media_id') || null
    };

    try {
        const response = await fetch('/api/series.php?action=update_promotor', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });

        const result = await response.json();

        if (result.success) {
            closeSeriesModal();
            location.reload();
        } else {
            alert(result.error || 'Kunde inte spara');
        }
    } catch (error) {
        console.error('Save error:', error);
        alert('Ett fel uppstod');
    }
}

// Close modal on escape or background click
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') closeSeriesModal();
});
document.getElementById('seriesModal').addEventListener('click', e => {
    if (e.target === document.getElementById('seriesModal')) closeSeriesModal();
});
</script>

<?php endif; // end admin/promotor view toggle ?>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>

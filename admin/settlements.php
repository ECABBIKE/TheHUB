<?php
/**
 * Avräkningar - Ekonomisk översikt per betalningsmottagare
 * Visar intäkter, avgifter, utbetalningar och saldo per mottagare.
 * Stödjer registrering av faktiska utbetalningar.
 *
 * Kopplingen: payment_recipients → (admin_user_id) → promotor_events/series → events → orders
 */
require_once __DIR__ . '/../config.php';
require_admin();

if (!hasRole('admin')) {
    set_flash('error', 'Du har inte behörighet till denna sida');
    redirect('/admin/dashboard');
}

$db = getDB();

// Shared helper: series order → per-event splitting
require_once __DIR__ . '/../includes/economy-helpers.php';

// Fee constants
$STRIPE_PERCENT = 1.5;
$STRIPE_FIXED = 2.00;
$SWISH_FEE = 3.00;

// Check stripe_fee column
$hasStripeFeeCol = false;
try {
    $colCheck = $db->getAll("SHOW COLUMNS FROM orders LIKE 'stripe_fee'");
    $hasStripeFeeCol = !empty($colCheck);
} catch (Exception $e) {}

// Check if settlement_payouts table exists
$hasPayoutsTable = false;
try {
    $db->getAll("SELECT 1 FROM settlement_payouts LIMIT 0");
    $hasPayoutsTable = true;
} catch (Exception $e) {}

// Check if admin_user_id column exists on payment_recipients
$hasAdminUserCol = false;
try {
    $colCheck = $db->getAll("SHOW COLUMNS FROM payment_recipients LIKE 'admin_user_id'");
    $hasAdminUserCol = !empty($colCheck);
} catch (Exception $e) {}

// ============================================================
// HANDLE POST: Create/cancel settlement payout
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $hasPayoutsTable) {
    checkCsrf();
    $postAction = $_POST['payout_action'] ?? '';

    if ($postAction === 'create_payout') {
        $payoutRecipientId = intval($_POST['payout_recipient_id'] ?? 0);
        $payoutAmount = floatval($_POST['payout_amount'] ?? 0);
        $payoutRef = trim($_POST['payout_reference'] ?? '');
        $payoutMethod = $_POST['payout_method'] ?? 'bank';
        $payoutNotes = trim($_POST['payout_notes'] ?? '');
        $payoutPeriodStart = $_POST['payout_period_start'] ?? null;
        $payoutPeriodEnd = $_POST['payout_period_end'] ?? null;

        if ($payoutRecipientId > 0 && $payoutAmount > 0) {
            try {
                $currentAdmin = getCurrentAdmin();
                $db->insert('settlement_payouts', [
                    'recipient_id' => $payoutRecipientId,
                    'amount' => $payoutAmount,
                    'period_start' => $payoutPeriodStart ?: null,
                    'period_end' => $payoutPeriodEnd ?: null,
                    'reference' => $payoutRef ?: null,
                    'payment_method' => $payoutMethod,
                    'notes' => $payoutNotes ?: null,
                    'status' => 'completed',
                    'created_by' => $currentAdmin['id'] ?? null
                ]);
                set_flash('success', 'Utbetalning registrerad: ' . number_format($payoutAmount, 2, ',', ' ') . ' kr');
            } catch (Exception $e) {
                set_flash('error', 'Kunde inte registrera utbetalning: ' . $e->getMessage());
            }
        }
        redirect('/admin/settlements.php?' . http_build_query(array_filter([
            'year' => $_POST['filter_year'] ?? '',
            'month' => $_POST['filter_month'] ?? '',
            'recipient' => $_POST['filter_recipient'] ?? ''
        ])));
    }

    if ($postAction === 'cancel_payout') {
        $payoutId = intval($_POST['payout_id'] ?? 0);
        if ($payoutId > 0) {
            try {
                $db->execute("UPDATE settlement_payouts SET status = 'cancelled' WHERE id = ?", [$payoutId]);
                set_flash('success', 'Utbetalning annullerad');
            } catch (Exception $e) {
                set_flash('error', 'Kunde inte annullera: ' . $e->getMessage());
            }
        }
        redirect('/admin/settlements.php?' . http_build_query(array_filter([
            'year' => $_POST['filter_year'] ?? '',
            'month' => $_POST['filter_month'] ?? '',
            'recipient' => $_POST['filter_recipient'] ?? ''
        ])));
    }
}

// ============================================================
// FILTERS
// ============================================================
$filterYear = isset($_GET['year']) ? intval($_GET['year']) : (int)date('Y');
$filterMonth = isset($_GET['month']) ? intval($_GET['month']) : 0;
$filterRecipient = isset($_GET['recipient']) ? intval($_GET['recipient']) : 0;

// Get all recipients (include admin_user info for promotor chain)
$allRecipients = [];
try {
    $adminJoin = $hasAdminUserCol ? "LEFT JOIN admin_users au ON pr.admin_user_id = au.id" : "";
    $adminSelect = $hasAdminUserCol ? ", pr.admin_user_id, au.full_name as admin_user_name" : ", NULL as admin_user_id, NULL as admin_user_name";
    $allRecipients = $db->getAll("
        SELECT pr.id, pr.name, pr.platform_fee_type, pr.platform_fee_percent, pr.platform_fee_fixed
            {$adminSelect}
        FROM payment_recipients pr
        {$adminJoin}
        ORDER BY pr.name
    ");
} catch (Exception $e) {}

// Get available years
$availableYears = [];
try {
    $availableYears = $db->getAll("SELECT DISTINCT YEAR(created_at) as yr FROM orders WHERE payment_status = 'paid' ORDER BY yr DESC");
} catch (Exception $e) {}

$monthNames = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Mars', 4 => 'April',
    5 => 'Maj', 6 => 'Juni', 7 => 'Juli', 8 => 'Augusti',
    9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'December'
];

// ============================================================
// Helper: Find all event/series IDs for a recipient
// Uses 3 paths: direct, series→events, PROMOTOR CHAIN
// ============================================================
function getRecipientEventAndSeriesIds($db, $rid, $recipientData, $hasAdminUserCol) {
    $eventIds = [];
    $seriesIds = [];

    // Path 1: events.payment_recipient_id (direct)
    try {
        $rows = $db->getAll("SELECT id FROM events WHERE payment_recipient_id = ?", [$rid]);
        $eventIds = array_column($rows, 'id');
    } catch (Exception $e) {}

    // Path 2: series.payment_recipient_id (direct)
    try {
        $rows = $db->getAll("SELECT id FROM series WHERE payment_recipient_id = ?", [$rid]);
        $seriesIds = array_column($rows, 'id');
    } catch (Exception $e) {}

    // Path 3: PROMOTOR CHAIN - payment_recipients.admin_user_id → promotor_events/series
    if ($hasAdminUserCol) {
        $adminUserId = $recipientData['admin_user_id'] ?? null;
        if ($adminUserId) {
            try {
                // Events via promotor_events
                $peRows = $db->getAll("SELECT event_id as id FROM promotor_events WHERE user_id = ?", [(int)$adminUserId]);
                foreach ($peRows as $row) {
                    if (!in_array($row['id'], $eventIds)) $eventIds[] = $row['id'];
                }
            } catch (Exception $e) {}

            try {
                // Series via promotor_series
                $psRows = $db->getAll("SELECT series_id as id FROM promotor_series WHERE user_id = ?", [(int)$adminUserId]);
                foreach ($psRows as $row) {
                    if (!in_array($row['id'], $seriesIds)) $seriesIds[] = $row['id'];
                }
            } catch (Exception $e) {}
        }
    }

    // Path 4: events belonging to recipient's series (via series_events + events.series_id)
    if (!empty($seriesIds)) {
        try {
            $sPlaceholders = implode(',', array_fill(0, count($seriesIds), '?'));
            $seriesEventRows = $db->getAll("
                SELECT DISTINCT event_id as id FROM series_events WHERE series_id IN ({$sPlaceholders})
                UNION
                SELECT DISTINCT id FROM events WHERE series_id IN ({$sPlaceholders})
            ", array_merge($seriesIds, $seriesIds));
            foreach ($seriesEventRows as $row) {
                if (!in_array($row['id'], $eventIds)) $eventIds[] = $row['id'];
            }
        } catch (Exception $e) {}
    }

    // Path 5: Multi-recipient series - find series that CONTAIN events owned by this recipient
    // (e.g. Swecup DH with 4 events, each having a different organizer)
    try {
        $multiRecipientSeries = $db->getAll("
            SELECT DISTINCT se.series_id as id
            FROM series_events se
            JOIN events e ON se.event_id = e.id
            WHERE e.payment_recipient_id = ?
        ", [$rid]);
        foreach ($multiRecipientSeries as $row) {
            if (!in_array($row['id'], $seriesIds)) $seriesIds[] = $row['id'];
        }
    } catch (Exception $e) {}

    // Path 6: Same via promotor chain (events owned by promotor in a series)
    if ($hasAdminUserCol) {
        try {
            $multiRecipientSeriesPC = $db->getAll("
                SELECT DISTINCT se.series_id as id
                FROM series_events se
                JOIN promotor_events pe ON pe.event_id = se.event_id
                JOIN payment_recipients pr ON pr.admin_user_id = pe.user_id
                WHERE pr.id = ?
            ", [$rid]);
            foreach ($multiRecipientSeriesPC as $row) {
                if (!in_array($row['id'], $seriesIds)) $seriesIds[] = $row['id'];
            }
        } catch (Exception $e) {}
    }

    return ['eventIds' => $eventIds, 'seriesIds' => $seriesIds];
}

// ============================================================
// BUILD SETTLEMENT DATA PER RECIPIENT
// ============================================================
$settlements = [];

$recipientsToShow = $allRecipients;
if ($filterRecipient > 0) {
    $recipientsToShow = array_filter($allRecipients, function($r) use ($filterRecipient) {
        return $r['id'] == $filterRecipient;
    });
}

foreach ($recipientsToShow as $recipient) {
    $rid = $recipient['id'];

    // Find all event/series IDs via all paths including promotor chain
    $ids = getRecipientEventAndSeriesIds($db, $rid, $recipient, $hasAdminUserCol);
    $eventIds = $ids['eventIds'];
    $seriesIds = $ids['seriesIds'];

    if (empty($eventIds) && empty($seriesIds)) {
        // Still show recipient with zero data if specifically filtered
        if ($filterRecipient > 0) {
            $settlements[] = [
                'recipient' => $recipient,
                'orders' => [],
                'totals' => ['gross' => 0, 'payment_fees' => 0, 'platform_fees' => 0, 'net' => 0, 'count' => 0],
                'payouts' => [],
                'total_settled' => 0,
                'balance' => 0,
                'event_count' => 0,
                'series_count' => count($seriesIds)
            ];
        }
        continue;
    }

    // Build WHERE clause for orders
    $conditions = ["o.payment_status = 'paid'", "YEAR(o.created_at) = ?"];
    $params = [$filterYear];

    if ($filterMonth > 0 && $filterMonth <= 12) {
        $conditions[] = "MONTH(o.created_at) = ?";
        $params[] = $filterMonth;
    }

    // Match orders via event_id OR series_id OR order_items chain
    $orClauses = [];
    if (!empty($eventIds)) {
        $placeholders = implode(',', array_fill(0, count($eventIds), '?'));
        $orClauses[] = "o.event_id IN ({$placeholders})";
        $params = array_merge($params, $eventIds);
    }
    if (!empty($seriesIds)) {
        $placeholders = implode(',', array_fill(0, count($seriesIds), '?'));
        $orClauses[] = "o.series_id IN ({$placeholders})";
        $params = array_merge($params, $seriesIds);

        // Also via order_items → series_registrations
        $orClauses[] = "o.id IN (
            SELECT oi.order_id FROM order_items oi
            JOIN series_registrations sr ON sr.id = oi.series_registration_id
            WHERE oi.item_type = 'series_registration' AND sr.series_id IN ({$placeholders})
        )";
        $params = array_merge($params, $seriesIds);
    }

    if (!empty($orClauses)) {
        $conditions[] = '(' . implode(' OR ', $orClauses) . ')';
    }

    $whereClause = implode(' AND ', $conditions);
    $stripeFeeCol = $hasStripeFeeCol ? "o.stripe_fee," : "NULL as stripe_fee,";

    $orders = [];
    try {
        $orders = $db->getAll("
            SELECT o.id, o.order_number, o.total_amount, o.payment_method,
                   {$stripeFeeCol}
                   o.event_id, o.series_id, o.created_at,
                   COALESCE(e.name, s.name, '-') as source_name,
                   CASE WHEN o.event_id IS NOT NULL THEN 'event' ELSE 'serie' END as source_type,
                   COALESCE(oi_count.participant_count, 1) as participant_count
            FROM orders o
            LEFT JOIN events e ON o.event_id = e.id
            LEFT JOIN series s ON o.series_id = s.id
            LEFT JOIN (
                SELECT order_id, COUNT(*) as participant_count
                FROM order_items
                WHERE item_type IN ('event_registration', 'series_registration')
                GROUP BY order_id
            ) oi_count ON oi_count.order_id = o.id
            WHERE {$whereClause}
            ORDER BY o.created_at DESC
        ", $params);
    } catch (Exception $e) {
        error_log("Settlement query error for recipient {$rid}: " . $e->getMessage());
    }

    // Split series orders into per-event rows
    $orders = explodeSeriesOrdersToEvents($orders, $db);

    // Filter split rows: only show events belonging to THIS recipient
    // Critical for multi-recipient series (e.g. Swecup DH with 4 organizers)
    $recipientEventIds = getRecipientEventIds($db, $rid);
    $orders = filterSplitRowsByRecipient($orders, $rid, $recipientEventIds);

    // Calculate fees per order
    $totals = ['gross' => 0, 'payment_fees' => 0, 'platform_fees' => 0, 'net' => 0, 'count' => 0];
    $feeType = $recipient['platform_fee_type'] ?? 'percent';
    $feePct = (float)($recipient['platform_fee_percent'] ?? 2);
    $feeFixed = (float)($recipient['platform_fee_fixed'] ?? 0);

    foreach ($orders as &$order) {
        $amount = (float)$order['total_amount'];
        $method = $order['payment_method'] ?? 'card';
        $isSplit = !empty($order['is_series_split']);
        $fraction = (float)($order['_split_fraction'] ?? 1.0);

        // Payment processing fee
        if (in_array($method, ['swish', 'swish_csv'])) {
            $order['payment_fee'] = $isSplit ? round($SWISH_FEE * $fraction, 2) : $SWISH_FEE;
            $order['fee_label'] = 'Swish';
        } elseif ($method === 'card') {
            if ($order['stripe_fee'] !== null && (float)$order['stripe_fee'] > 0) {
                $order['payment_fee'] = round((float)$order['stripe_fee'], 2);
                $order['fee_label'] = 'Stripe (faktisk)';
            } else {
                $order['payment_fee'] = $isSplit
                    ? round((($amount * $STRIPE_PERCENT / 100) + ($STRIPE_FIXED * $fraction)), 2)
                    : round(($amount * $STRIPE_PERCENT / 100) + $STRIPE_FIXED, 2);
                $order['fee_label'] = 'Stripe (uppskattad)';
            }
        } else {
            $order['payment_fee'] = 0;
            $order['fee_label'] = '-';
        }

        // Platform fee
        if ($feeType === 'fixed') {
            $order['platform_fee'] = $isSplit ? round($feeFixed * $fraction, 2) : $feeFixed;
        } elseif ($feeType === 'per_participant') {
            $pCount = (int)($order['participant_count'] ?? 1);
            $order['platform_fee'] = $isSplit ? round($feeFixed * $fraction, 2) : $feeFixed * $pCount;
        } elseif ($feeType === 'both') {
            $order['platform_fee'] = $isSplit
                ? round(($amount * $feePct / 100) + ($feeFixed * $fraction), 2)
                : round(($amount * $feePct / 100) + $feeFixed, 2);
        } else {
            $order['platform_fee'] = round($amount * $feePct / 100, 2);
        }

        $order['net'] = round($amount - $order['payment_fee'] - $order['platform_fee'], 2);

        $totals['gross'] += $amount;
        $totals['payment_fees'] += $order['payment_fee'];
        $totals['platform_fees'] += $order['platform_fee'];
        $totals['net'] += $order['net'];
        $totals['count']++;
    }
    unset($order);

    // Get settlement payouts for this recipient (all time, for balance calculation)
    $payouts = [];
    $totalSettled = 0;
    $yearPayouts = [];
    if ($hasPayoutsTable) {
        try {
            // All completed payouts for balance calculation
            $allPayouts = $db->getAll("
                SELECT SUM(amount) as total
                FROM settlement_payouts
                WHERE recipient_id = ? AND status = 'completed'
            ", [$rid]);
            $totalSettled = (float)($allPayouts[0]['total'] ?? 0);

            // Payouts for display (filtered by year)
            $yearPayouts = $db->getAll("
                SELECT sp.*, au.full_name as created_by_name
                FROM settlement_payouts sp
                LEFT JOIN admin_users au ON sp.created_by = au.id
                WHERE sp.recipient_id = ? AND sp.status != 'cancelled'
                AND YEAR(sp.created_at) = ?
                ORDER BY sp.created_at DESC
            ", [$rid, $filterYear]);
        } catch (Exception $e) {}
    }

    // Balance = all-time net - all-time settled
    // For the display, we use the filtered year's net
    $balance = $totals['net'] - $totalSettled;

    $settlements[] = [
        'recipient' => $recipient,
        'orders' => $orders,
        'totals' => $totals,
        'payouts' => $yearPayouts,
        'total_settled' => $totalSettled,
        'balance' => $balance,
        'event_count' => count($eventIds),
        'series_count' => count($seriesIds)
    ];
}

// Grand totals
$grandTotals = ['gross' => 0, 'payment_fees' => 0, 'platform_fees' => 0, 'net' => 0, 'count' => 0, 'settled' => 0, 'balance' => 0];
foreach ($settlements as $s) {
    $grandTotals['gross'] += $s['totals']['gross'];
    $grandTotals['payment_fees'] += $s['totals']['payment_fees'];
    $grandTotals['platform_fees'] += $s['totals']['platform_fees'];
    $grandTotals['net'] += $s['totals']['net'];
    $grandTotals['count'] += $s['totals']['count'];
    $grandTotals['settled'] += $s['total_settled'];
    $grandTotals['balance'] += $s['balance'];
}

$page_title = 'Avräkningar';
$breadcrumbs = [
    ['label' => 'Ekonomi'],
    ['label' => 'Avräkningar']
];
$current_admin_page = 'settlements';

include __DIR__ . '/components/unified-layout.php';
?>

<style>
.settlement-filters {
    display: flex;
    gap: var(--space-sm);
    flex-wrap: wrap;
    margin-bottom: var(--space-lg);
    align-items: flex-end;
}
.settlement-filters .form-group {
    margin: 0;
    min-width: 140px;
}
.settlement-filters .form-label {
    font-size: var(--text-xs);
    margin-bottom: var(--space-2xs);
}
.grand-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: var(--space-md);
    margin-bottom: var(--space-xl);
}
.grand-stat {
    background: var(--color-bg-card);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    padding: var(--space-md) var(--space-lg);
    text-align: center;
}
.grand-stat-value {
    font-size: 1.4rem;
    font-weight: 700;
    color: var(--color-text-primary);
}
.grand-stat-label {
    font-size: var(--text-xs);
    color: var(--color-text-muted);
    text-transform: uppercase;
    margin-top: var(--space-2xs);
}
.grand-stat-value.positive { color: var(--color-success); }
.grand-stat-value.warning { color: var(--color-warning); }
.settlement-section {
    margin-bottom: var(--space-xl);
}
.settlement-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-md);
    margin-bottom: var(--space-sm);
    flex-wrap: wrap;
}
.settlement-title {
    font-weight: 700;
    font-size: 1.1rem;
    color: var(--color-text-primary);
    display: flex;
    align-items: center;
    gap: var(--space-xs);
}
.settlement-meta {
    font-size: var(--text-sm);
    color: var(--color-text-muted);
}
.balance-bar {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
    gap: var(--space-sm);
    background: var(--color-bg-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    padding: var(--space-md);
    margin-bottom: var(--space-md);
}
.balance-item {
    text-align: center;
}
.balance-item-value {
    font-size: 1.1rem;
    font-weight: 700;
    color: var(--color-text-primary);
}
.balance-item-label {
    font-size: var(--text-xs);
    color: var(--color-text-muted);
    text-transform: uppercase;
}
.balance-item-value.net { color: var(--color-success); }
.balance-item-value.settled { color: var(--color-info); }
.balance-item-value.remaining { color: var(--color-warning); }
.balance-item-value.remaining.zero { color: var(--color-success); }
.payout-form {
    background: var(--color-bg-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    padding: var(--space-md);
    margin-bottom: var(--space-md);
    display: none;
}
.payout-form.active { display: block; }
.payout-form .form-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: var(--space-sm);
}
.payout-history {
    margin-bottom: var(--space-md);
}
.payout-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: var(--space-xs) var(--space-sm);
    border-bottom: 1px solid var(--color-border);
    font-size: var(--text-sm);
}
.payout-row:last-child { border-bottom: none; }
@media (max-width: 767px) {
    .settlement-filters { flex-direction: column; }
    .settlement-filters .form-group { width: 100%; }
    .balance-bar { grid-template-columns: repeat(2, 1fr); }
    .payout-form .form-grid { grid-template-columns: 1fr; }
}
</style>

<!-- Filter -->
<form method="get" class="settlement-filters">
    <div class="form-group">
        <label class="form-label">År</label>
        <select name="year" class="form-select" onchange="this.form.submit()">
            <?php foreach ($availableYears as $y): ?>
                <option value="<?= $y['yr'] ?>" <?= $y['yr'] == $filterYear ? 'selected' : '' ?>><?= $y['yr'] ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-group">
        <label class="form-label">Månad</label>
        <select name="month" class="form-select" onchange="this.form.submit()">
            <option value="0">Helår</option>
            <?php for ($m = 1; $m <= 12; $m++): ?>
                <option value="<?= $m ?>" <?= $m == $filterMonth ? 'selected' : '' ?>><?= $monthNames[$m] ?></option>
            <?php endfor; ?>
        </select>
    </div>
    <div class="form-group">
        <label class="form-label">Mottagare</label>
        <select name="recipient" class="form-select" onchange="this.form.submit()">
            <option value="0">Alla mottagare</option>
            <?php foreach ($allRecipients as $r): ?>
                <option value="<?= $r['id'] ?>" <?= $r['id'] == $filterRecipient ? 'selected' : '' ?>><?= htmlspecialchars($r['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
</form>

<!-- Sammanfattning -->
<div class="grand-stats">
    <div class="grand-stat">
        <div class="grand-stat-value"><?= number_format($grandTotals['gross'], 0, ',', ' ') ?> kr</div>
        <div class="grand-stat-label">Brutto</div>
    </div>
    <div class="grand-stat">
        <div class="grand-stat-value"><?= number_format($grandTotals['payment_fees'] + $grandTotals['platform_fees'], 0, ',', ' ') ?> kr</div>
        <div class="grand-stat-label">Totala avgifter</div>
    </div>
    <div class="grand-stat">
        <div class="grand-stat-value positive"><?= number_format($grandTotals['net'], 0, ',', ' ') ?> kr</div>
        <div class="grand-stat-label">Netto</div>
    </div>
    <div class="grand-stat">
        <div class="grand-stat-value settled"><?= number_format($grandTotals['settled'], 0, ',', ' ') ?> kr</div>
        <div class="grand-stat-label">Utbetalt</div>
    </div>
    <div class="grand-stat">
        <div class="grand-stat-value <?= $grandTotals['balance'] <= 0 ? 'positive' : 'warning' ?>"><?= number_format($grandTotals['balance'], 0, ',', ' ') ?> kr</div>
        <div class="grand-stat-label">Kvar att betala</div>
    </div>
    <div class="grand-stat">
        <div class="grand-stat-value"><?= $grandTotals['count'] ?></div>
        <div class="grand-stat-label">Ordrar</div>
    </div>
</div>

<?php if (empty($settlements)): ?>
    <div class="card">
        <div class="card-body" style="text-align: center; padding: var(--space-2xl); color: var(--color-text-muted);">
            Inga betalningsmottagare hittades. Koppla mottagare till event via
            <a href="/admin/payment-recipients.php">Betalningsmottagare</a> eller
            <a href="/admin/admin-users.php">Promotor-tilldelning</a>.
        </div>
    </div>
<?php endif; ?>

<!-- Per mottagare -->
<?php foreach ($settlements as $s):
    $r = $s['recipient'];
    $t = $s['totals'];
    $sectionId = 'recipient-' . $r['id'];
?>
<div class="settlement-section" id="<?= $sectionId ?>">
    <div class="settlement-header">
        <div>
            <div class="settlement-title">
                <i data-lucide="building-2" style="width:18px;height:18px;"></i>
                <?= htmlspecialchars($r['name']) ?>
                <?php if (!empty($r['admin_user_name'])): ?>
                    <span class="badge" style="font-size:11px;"><?= htmlspecialchars($r['admin_user_name']) ?></span>
                <?php endif; ?>
            </div>
            <div class="settlement-meta">
                <?= $s['event_count'] ?> event, <?= $s['series_count'] ?> serier
            </div>
        </div>
        <?php if ($hasPayoutsTable): ?>
        <button type="button" class="btn btn-primary" onclick="togglePayoutForm(<?= $r['id'] ?>)">
            <i data-lucide="banknote" style="width:14px;height:14px;"></i> Registrera utbetalning
        </button>
        <?php endif; ?>
    </div>

    <!-- Saldo-bar -->
    <div class="balance-bar">
        <div class="balance-item">
            <div class="balance-item-value"><?= number_format($t['gross'], 0, ',', ' ') ?> kr</div>
            <div class="balance-item-label">Brutto</div>
        </div>
        <div class="balance-item">
            <div class="balance-item-value"><?= number_format($t['payment_fees'], 0, ',', ' ') ?> kr</div>
            <div class="balance-item-label">Bet.avgifter</div>
        </div>
        <div class="balance-item">
            <div class="balance-item-value"><?= number_format($t['platform_fees'], 0, ',', ' ') ?> kr</div>
            <div class="balance-item-label">Plattform</div>
        </div>
        <div class="balance-item">
            <div class="balance-item-value net"><?= number_format($t['net'], 0, ',', ' ') ?> kr</div>
            <div class="balance-item-label">Netto</div>
        </div>
        <div class="balance-item">
            <div class="balance-item-value settled"><?= number_format($s['total_settled'], 0, ',', ' ') ?> kr</div>
            <div class="balance-item-label">Utbetalt</div>
        </div>
        <div class="balance-item">
            <div class="balance-item-value remaining <?= $s['balance'] <= 0 ? 'zero' : '' ?>"><?= number_format($s['balance'], 0, ',', ' ') ?> kr</div>
            <div class="balance-item-label">Kvar</div>
        </div>
    </div>

    <!-- Utbetalningsformulär (dolt tills knapp klickas) -->
    <?php if ($hasPayoutsTable): ?>
    <div class="payout-form" id="payout-form-<?= $r['id'] ?>">
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="payout_action" value="create_payout">
            <input type="hidden" name="payout_recipient_id" value="<?= $r['id'] ?>">
            <input type="hidden" name="filter_year" value="<?= $filterYear ?>">
            <input type="hidden" name="filter_month" value="<?= $filterMonth ?>">
            <input type="hidden" name="filter_recipient" value="<?= $filterRecipient ?>">

            <div style="font-weight: 600; margin-bottom: var(--space-sm);">
                <i data-lucide="banknote" style="width:16px;height:16px;vertical-align:-2px;"></i>
                Registrera utbetalning till <?= htmlspecialchars($r['name']) ?>
            </div>

            <div class="payout-form .form-grid" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: var(--space-sm);">
                <div class="form-group">
                    <label class="form-label">Belopp (SEK) *</label>
                    <input type="number" name="payout_amount" class="form-input" step="0.01" min="0" value="<?= number_format(max(0, $s['balance']), 2, '.', '') ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Metod</label>
                    <select name="payout_method" class="form-select">
                        <option value="bank">Banköverföring</option>
                        <option value="swish">Swish</option>
                        <option value="stripe">Stripe</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Referens</label>
                    <input type="text" name="payout_reference" class="form-input" placeholder="OCR, Swish-ref...">
                </div>
                <div class="form-group">
                    <label class="form-label">Period från</label>
                    <input type="date" name="payout_period_start" class="form-input" value="<?= $filterYear ?>-01-01">
                </div>
                <div class="form-group">
                    <label class="form-label">Period till</label>
                    <input type="date" name="payout_period_end" class="form-input" value="<?= date('Y-m-d') ?>">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Anteckningar</label>
                <input type="text" name="payout_notes" class="form-input" placeholder="Valfri kommentar">
            </div>
            <div style="display: flex; gap: var(--space-sm); margin-top: var(--space-sm);">
                <button type="submit" class="btn btn-primary">Registrera utbetalning</button>
                <button type="button" class="btn btn-ghost" onclick="togglePayoutForm(<?= $r['id'] ?>)">Avbryt</button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <!-- Utbetalningshistorik -->
    <?php if (!empty($s['payouts'])): ?>
    <div class="card payout-history">
        <div class="card-header" style="padding: var(--space-sm) var(--space-md);">
            <h4 style="font-size: var(--text-sm); margin: 0;">
                <i data-lucide="banknote" style="width:14px;height:14px;vertical-align:-2px;"></i>
                Utbetalningar <?= $filterYear ?>
            </h4>
        </div>
        <div class="card-body" style="padding: 0;">
            <?php foreach ($s['payouts'] as $p): ?>
            <div class="payout-row">
                <div>
                    <strong><?= number_format($p['amount'], 0, ',', ' ') ?> kr</strong>
                    <span style="color: var(--color-text-muted); margin-left: var(--space-xs);">
                        <?= date('Y-m-d', strtotime($p['created_at'])) ?>
                    </span>
                    <?php if (!empty($p['reference'])): ?>
                        <span style="color: var(--color-text-muted);">ref: <?= htmlspecialchars($p['reference']) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($p['notes'])): ?>
                        <span style="color: var(--color-text-muted);">(<?= htmlspecialchars($p['notes']) ?>)</span>
                    <?php endif; ?>
                </div>
                <div style="display: flex; align-items: center; gap: var(--space-xs);">
                    <span class="badge badge-<?= $p['status'] === 'completed' ? 'success' : ($p['status'] === 'pending' ? 'warning' : 'danger') ?>">
                        <?= $p['status'] === 'completed' ? 'Genomförd' : ($p['status'] === 'pending' ? 'Väntande' : 'Annullerad') ?>
                    </span>
                    <?php if ($p['status'] === 'completed'): ?>
                    <form method="post" style="display:inline;" onsubmit="return confirm('Annullera denna utbetalning?')">
                        <?= csrf_field() ?>
                        <input type="hidden" name="payout_action" value="cancel_payout">
                        <input type="hidden" name="payout_id" value="<?= $p['id'] ?>">
                        <input type="hidden" name="filter_year" value="<?= $filterYear ?>">
                        <input type="hidden" name="filter_month" value="<?= $filterMonth ?>">
                        <input type="hidden" name="filter_recipient" value="<?= $filterRecipient ?>">
                        <button type="submit" class="btn btn-ghost" style="padding:2px 6px;" title="Annullera">
                            <i data-lucide="x" style="width:12px;height:12px;"></i>
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Ordertabell -->
    <?php if (!empty($s['orders'])): ?>
    <div class="card" style="margin-bottom: 0;">
        <div class="card-body" style="padding: 0;">
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Order</th>
                            <th>Datum</th>
                            <th>Källa</th>
                            <th style="text-align:right;">Belopp</th>
                            <th style="text-align:right;">Bet.avgift</th>
                            <th style="text-align:right;">Plattform</th>
                            <th style="text-align:right;">Netto</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($s['orders'] as $order): ?>
                        <tr<?= !empty($order['is_series_split']) ? ' style="border-left: 3px solid var(--color-accent); opacity: 0.9;"' : '' ?>>
                            <td><a href="/admin/orders.php?search=<?= urlencode($order['order_number']) ?>"><?= htmlspecialchars($order['order_number']) ?></a></td>
                            <td><?= date('Y-m-d', strtotime($order['created_at'])) ?></td>
                            <td>
                                <?= htmlspecialchars($order['source_name'] ?? '-') ?>
                                <?php if (!empty($order['is_series_split'])): ?>
                                    <span class="badge" style="font-size:10px;background:var(--color-accent-light);color:var(--color-accent);">Serieanmälan</span>
                                <?php elseif (($order['source_type'] ?? '') === 'serie'): ?>
                                    <span class="badge" style="font-size:10px;">Serie</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:right;"><?= number_format($order['total_amount'], 0, ',', ' ') ?> kr</td>
                            <td style="text-align:right;" title="<?= htmlspecialchars($order['fee_label']) ?>">
                                <?= number_format($order['payment_fee'], 0, ',', ' ') ?> kr
                            </td>
                            <td style="text-align:right;"><?= number_format($order['platform_fee'], 0, ',', ' ') ?> kr</td>
                            <td style="text-align:right; font-weight: 600;"><?= number_format($order['net'], 0, ',', ' ') ?> kr</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="font-weight: 700; border-top: 2px solid var(--color-border);">
                            <td colspan="3">Summa (<?= $t['count'] ?> ordrar)</td>
                            <td style="text-align:right;"><?= number_format($t['gross'], 0, ',', ' ') ?> kr</td>
                            <td style="text-align:right;"><?= number_format($t['payment_fees'], 0, ',', ' ') ?> kr</td>
                            <td style="text-align:right;"><?= number_format($t['platform_fees'], 0, ',', ' ') ?> kr</td>
                            <td style="text-align:right; color: var(--color-success);"><?= number_format($t['net'], 0, ',', ' ') ?> kr</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
    <?php elseif ($filterRecipient > 0): ?>
    <div class="card">
        <div class="card-body" style="text-align: center; padding: var(--space-lg); color: var(--color-text-muted);">
            Inga betalda ordrar för vald period.
        </div>
    </div>
    <?php endif; ?>
</div>
<?php endforeach; ?>

<script>
function togglePayoutForm(recipientId) {
    var form = document.getElementById('payout-form-' + recipientId);
    if (form) form.classList.toggle('active');
}
</script>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>

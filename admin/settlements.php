<?php
/**
 * Avräkningar - Ekonomisk översikt per betalningsmottagare
 * Visar alla betalda ordrar kopplade till en mottagare via event/serie
 */
require_once __DIR__ . '/../config.php';
require_admin();

if (!hasRole('admin')) {
    set_flash('error', 'Du har inte behörighet till denna sida');
    redirect('/admin/dashboard');
}

$db = getDB();

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

// Filters
$filterYear = isset($_GET['year']) ? intval($_GET['year']) : (int)date('Y');
$filterMonth = isset($_GET['month']) ? intval($_GET['month']) : 0;
$filterRecipient = isset($_GET['recipient']) ? intval($_GET['recipient']) : 0;

// Get all active recipients
$allRecipients = [];
try {
    $allRecipients = $db->getAll("SELECT id, name, platform_fee_type, platform_fee_percent, platform_fee_fixed FROM payment_recipients ORDER BY name");
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

// Build settlement data per recipient
$settlements = [];

// Determine which recipients to show
$recipientsToShow = $allRecipients;
if ($filterRecipient > 0) {
    $recipientsToShow = array_filter($allRecipients, function($r) use ($filterRecipient) {
        return $r['id'] == $filterRecipient;
    });
}

foreach ($recipientsToShow as $recipient) {
    $rid = $recipient['id'];

    // Find all event IDs linked to this recipient
    $eventIds = [];
    try {
        $events = $db->getAll("SELECT id FROM events WHERE payment_recipient_id = ?", [$rid]);
        $eventIds = array_column($events, 'id');
    } catch (Exception $e) {}

    // Find all series IDs linked to this recipient
    $seriesIds = [];
    try {
        $series = $db->getAll("SELECT id FROM series WHERE payment_recipient_id = ?", [$rid]);
        $seriesIds = array_column($series, 'id');
    } catch (Exception $e) {}

    if (empty($eventIds) && empty($seriesIds)) {
        continue;
    }

    // Build WHERE clause for orders
    $conditions = ["o.payment_status = 'paid'", "YEAR(o.created_at) = ?"];
    $params = [$filterYear];

    if ($filterMonth > 0 && $filterMonth <= 12) {
        $conditions[] = "MONTH(o.created_at) = ?";
        $params[] = $filterMonth;
    }

    // Match orders: via event_id OR via series_id
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

        // Also find orders via order_items → series_registrations for this series
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

    if (empty($orders)) {
        continue;
    }

    // Calculate fees per order
    $totals = ['gross' => 0, 'payment_fees' => 0, 'platform_fees' => 0, 'net' => 0, 'count' => 0];
    $feeType = $recipient['platform_fee_type'] ?? 'percent';
    $feePct = (float)($recipient['platform_fee_percent'] ?? 2);
    $feeFixed = (float)($recipient['platform_fee_fixed'] ?? 0);

    foreach ($orders as &$order) {
        $amount = (float)$order['total_amount'];
        $method = $order['payment_method'] ?? 'card';

        // Payment processing fee
        if (in_array($method, ['swish', 'swish_csv'])) {
            $order['payment_fee'] = $SWISH_FEE;
            $order['fee_label'] = 'Swish';
        } elseif ($method === 'card') {
            if ($order['stripe_fee'] !== null && (float)$order['stripe_fee'] > 0) {
                $order['payment_fee'] = round((float)$order['stripe_fee'], 2);
                $order['fee_label'] = 'Stripe (faktisk)';
            } else {
                $order['payment_fee'] = round(($amount * $STRIPE_PERCENT / 100) + $STRIPE_FIXED, 2);
                $order['fee_label'] = 'Stripe (uppskattad)';
            }
        } else {
            $order['payment_fee'] = 0;
            $order['fee_label'] = '-';
        }

        // Platform fee
        if ($feeType === 'fixed') {
            $order['platform_fee'] = $feeFixed;
        } elseif ($feeType === 'per_participant') {
            $order['platform_fee'] = $feeFixed * (int)($order['participant_count'] ?? 1);
        } elseif ($feeType === 'both') {
            $order['platform_fee'] = round(($amount * $feePct / 100) + $feeFixed, 2);
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

    $settlements[] = [
        'recipient' => $recipient,
        'orders' => $orders,
        'totals' => $totals
    ];
}

// Grand totals
$grandTotals = ['gross' => 0, 'payment_fees' => 0, 'platform_fees' => 0, 'net' => 0, 'count' => 0];
foreach ($settlements as $s) {
    $grandTotals['gross'] += $s['totals']['gross'];
    $grandTotals['payment_fees'] += $s['totals']['payment_fees'];
    $grandTotals['platform_fees'] += $s['totals']['platform_fees'];
    $grandTotals['net'] += $s['totals']['net'];
    $grandTotals['count'] += $s['totals']['count'];
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
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
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
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--color-text-primary);
}
.grand-stat-label {
    font-size: var(--text-xs);
    color: var(--color-text-muted);
    text-transform: uppercase;
    margin-top: var(--space-2xs);
}
.grand-stat-value.net { color: var(--color-success); }
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
}
.settlement-summary {
    display: flex;
    gap: var(--space-lg);
    font-size: var(--text-sm);
    color: var(--color-text-secondary);
}
.settlement-summary strong {
    color: var(--color-text-primary);
}
@media (max-width: 767px) {
    .settlement-filters {
        flex-direction: column;
    }
    .settlement-filters .form-group {
        width: 100%;
    }
    .settlement-summary {
        flex-direction: column;
        gap: var(--space-xs);
    }
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
        <div class="grand-stat-value"><?= number_format($grandTotals['payment_fees'], 0, ',', ' ') ?> kr</div>
        <div class="grand-stat-label">Betalningsavgifter</div>
    </div>
    <div class="grand-stat">
        <div class="grand-stat-value"><?= number_format($grandTotals['platform_fees'], 0, ',', ' ') ?> kr</div>
        <div class="grand-stat-label">Plattformsavgift</div>
    </div>
    <div class="grand-stat">
        <div class="grand-stat-value net"><?= number_format($grandTotals['net'], 0, ',', ' ') ?> kr</div>
        <div class="grand-stat-label">Netto utbetalning</div>
    </div>
    <div class="grand-stat">
        <div class="grand-stat-value"><?= $grandTotals['count'] ?></div>
        <div class="grand-stat-label">Ordrar</div>
    </div>
</div>

<?php if (empty($settlements)): ?>
    <div class="card">
        <div class="card-body" style="text-align: center; padding: var(--space-2xl); color: var(--color-text-muted);">
            Inga betalda ordrar hittades för vald period.
        </div>
    </div>
<?php endif; ?>

<!-- Per mottagare -->
<?php foreach ($settlements as $s):
    $r = $s['recipient'];
    $t = $s['totals'];
?>
<div class="settlement-section">
    <div class="settlement-header">
        <div class="settlement-title">
            <i data-lucide="building-2" style="width:18px;height:18px;vertical-align:-3px;"></i>
            <?= htmlspecialchars($r['name']) ?>
        </div>
        <div class="settlement-summary">
            <span>Brutto: <strong><?= number_format($t['gross'], 0, ',', ' ') ?> kr</strong></span>
            <span>Avgifter: <strong><?= number_format($t['payment_fees'] + $t['platform_fees'], 0, ',', ' ') ?> kr</strong></span>
            <span>Netto: <strong style="color: var(--color-success);"><?= number_format($t['net'], 0, ',', ' ') ?> kr</strong></span>
            <span><?= $t['count'] ?> ordrar</span>
        </div>
    </div>

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
                        <tr>
                            <td><a href="/admin/orders.php?search=<?= urlencode($order['order_number']) ?>"><?= htmlspecialchars($order['order_number']) ?></a></td>
                            <td><?= date('Y-m-d', strtotime($order['created_at'])) ?></td>
                            <td>
                                <?= htmlspecialchars($order['source_name']) ?>
                                <?php if ($order['source_type'] === 'serie'): ?>
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
</div>
<?php endforeach; ?>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>

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

    // Get platform fee percent (from first active recipient)
    $platformFeePct = 2.00;
    try {
        $prRow = $db->getRow("SELECT id, name, platform_fee_percent, swish_number, bankgiro, bank_account, bank_name, bank_clearing, contact_email, org_number FROM payment_recipients WHERE active = 1 ORDER BY id LIMIT 1");
        if ($prRow) {
            $platformFeePct = (float)($prRow['platform_fee_percent'] ?? 2.00);
            $recipientInfo = $prRow;
        }
    } catch (Exception $e) {}

    // Fetch individual paid orders for the selected year
    $orderRows = [];
    try {
        $stripeFeeCol = $hasStripeFeeCol ? "o.stripe_fee," : "NULL as stripe_fee,";
        $yearFilter = $filterYear;
        $recipientFilter = $filterRecipient ? " AND pr_match.id = " . intval($filterRecipient) : "";

        // Simple approach: fetch all paid orders for the year, join event name
        $orderRows = $db->getAll("
            SELECT o.id, o.order_number, o.total_amount, o.payment_method, o.payment_status,
                   {$stripeFeeCol}
                   o.event_id, o.created_at,
                   e.name as event_name
            FROM orders o
            LEFT JOIN events e ON o.event_id = e.id
            WHERE o.payment_status = 'paid'
            AND YEAR(o.created_at) = ?
            ORDER BY o.created_at DESC
        ", [$yearFilter]);
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

        // Platform fee
        $order['platform_fee'] = round($amount * $platformFeePct / 100, 2);

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

<?php if ($isAdmin): ?>
<!-- ===== ADMIN: FINANCIAL PAYOUT OVERVIEW ===== -->

<style>
/* Order table styles */
.order-table { font-variant-numeric: tabular-nums; }
.order-table th { white-space: nowrap; font-size: var(--text-xs); text-transform: uppercase; letter-spacing: 0.05em; }
.order-table td { vertical-align: middle; }
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

/* Mobile: card view for portrait phones */
.order-cards { display: none; }

@media (max-width: 599px) and (orientation: portrait) {
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
            <div class="admin-stat-value"><?= number_format($payoutTotals['gross'], 0, ',', ' ') ?> kr</div>
            <div class="admin-stat-label">Försäljning</div>
        </div>
    </div>
    <div class="admin-stat-card">
        <div class="admin-stat-icon stat-icon-danger">
            <i data-lucide="receipt"></i>
        </div>
        <div class="admin-stat-content">
            <div class="admin-stat-value"><?= number_format($payoutTotals['payment_fees'] + $payoutTotals['platform_fees'], 0, ',', ' ') ?> kr</div>
            <div class="admin-stat-label">Totala avgifter</div>
        </div>
    </div>
    <div class="admin-stat-card">
        <div class="admin-stat-icon stat-icon-success">
            <i data-lucide="banknote"></i>
        </div>
        <div class="admin-stat-content">
            <div class="admin-stat-value"><?= number_format($payoutTotals['net'], 0, ',', ' ') ?> kr</div>
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
        </form>
    </div>
</div>

<!-- Per-order table -->
<div class="admin-card">
    <div class="admin-card-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: var(--space-sm);">
        <h2>Ordrar <?= $filterYear ?></h2>
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
                            <?= number_format($order['total_amount'], 0, ',', ' ') ?> kr
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
                            <?= number_format($order['net_amount'], 0, ',', ' ') ?> kr
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="summary-row">
                        <td colspan="2" style="font-weight: 600;">Summa (<?= $payoutTotals['order_count'] ?> ordrar)</td>
                        <td style="text-align: right;"><?= number_format($payoutTotals['gross'], 0, ',', ' ') ?> kr</td>
                        <td></td>
                        <td style="text-align: right; color: var(--color-error);">-<?= number_format($payoutTotals['payment_fees'], 0, ',', ' ') ?> kr</td>
                        <td style="text-align: right; color: var(--color-error);">-<?= number_format($payoutTotals['platform_fees'], 0, ',', ' ') ?> kr</td>
                        <td style="text-align: right; color: var(--color-success);"><?= number_format($payoutTotals['net'], 0, ',', ' ') ?> kr</td>
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
                $totalFees = $order['payment_fee'] + $order['platform_fee'];
            ?>
            <div style="padding: var(--space-md); border-bottom: 1px solid var(--color-border);">
                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: var(--space-2xs);">
                    <div>
                        <code style="font-size: var(--text-sm);"><?= h($order['order_number'] ?? '#' . $order['id']) ?></code>
                        <span class="text-xs text-secondary" style="margin-left: var(--space-xs);"><?= date('j M', strtotime($order['created_at'])) ?></span>
                    </div>
                    <div style="text-align: right;">
                        <div style="font-weight: 600; color: var(--color-success); font-variant-numeric: tabular-nums;"><?= number_format($order['net_amount'], 0, ',', ' ') ?> kr</div>
                    </div>
                </div>
                <div class="text-xs text-secondary" style="margin-bottom: var(--space-2xs);"><?= h($order['event_name'] ?? '-') ?></div>
                <div style="display: flex; justify-content: space-between; font-size: var(--text-xs); color: var(--color-text-muted);">
                    <span><?= $methodLabel ?> &middot; <?= number_format($order['total_amount'], 0, ',', ' ') ?> kr</span>
                    <?php if ($totalFees > 0): ?>
                    <span style="color: var(--color-error);">-<?= number_format($totalFees, 0, ',', ' ') ?> kr avg.</span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
            <!-- Mobile summary -->
            <div style="padding: var(--space-md); background: var(--color-bg-hover); font-size: var(--text-sm);">
                <div style="display: flex; justify-content: space-between; font-weight: 600; margin-bottom: var(--space-2xs);">
                    <span>Summa (<?= $payoutTotals['order_count'] ?> ordrar)</span>
                    <span style="color: var(--color-success);"><?= number_format($payoutTotals['net'], 0, ',', ' ') ?> kr</span>
                </div>
                <div style="display: flex; justify-content: space-between; font-size: var(--text-xs); color: var(--color-text-muted);">
                    <span>Försäljning: <?= number_format($payoutTotals['gross'], 0, ',', ' ') ?> kr</span>
                    <span style="color: var(--color-error);">Avgifter: -<?= number_format($payoutTotals['payment_fees'] + $payoutTotals['platform_fees'], 0, ',', ' ') ?> kr</span>
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
.promotor-grid { display: grid; gap: var(--space-lg); }
.event-card {
    background: var(--color-bg-surface); border-radius: var(--radius-lg);
    border: 1px solid var(--color-border); overflow: hidden;
}
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
.stat-value.pending { color: var(--color-warning); }
.stat-label { font-size: var(--text-xs); color: var(--color-text-secondary); text-transform: uppercase; letter-spacing: 0.05em; }
.event-actions { display: flex; flex-wrap: wrap; gap: var(--space-sm); }
.event-actions .btn { display: inline-flex; align-items: center; gap: var(--space-xs); min-height: 44px; }
.event-actions .btn i { width: 16px; height: 16px; }
.empty-state { text-align: center; padding: var(--space-2xl); color: var(--color-text-secondary); }
.empty-state i { width: 48px; height: 48px; margin-bottom: var(--space-md); opacity: 0.5; }
.empty-state h2 { margin: 0 0 var(--space-sm) 0; color: var(--color-text-primary); }
.section-title { font-size: var(--text-xl); font-weight: 600; margin: 0 0 var(--space-lg) 0; color: var(--color-text-primary); }
.series-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: var(--space-lg); margin-bottom: var(--space-2xl); }
.series-card {
    background: var(--color-bg-surface); border-radius: var(--radius-lg);
    border: 1px solid var(--color-border); overflow: hidden; display: flex; flex-direction: column;
}
.series-card-header { padding: var(--space-lg); display: flex; align-items: center; gap: var(--space-md); border-bottom: 1px solid var(--color-border); }
.series-logo {
    width: 48px; height: 48px; border-radius: var(--radius-md); background: var(--color-bg-sunken);
    display: flex; align-items: center; justify-content: center; overflow: hidden; flex-shrink: 0;
}
.series-logo img { max-width: 100%; max-height: 100%; object-fit: contain; }
.series-info h3 { margin: 0 0 var(--space-2xs) 0; font-size: var(--text-lg); }
.series-info p { margin: 0; font-size: var(--text-sm); color: var(--color-text-secondary); }
.series-card-body { padding: var(--space-lg); flex: 1; }
.series-detail { display: flex; align-items: center; gap: var(--space-sm); margin-bottom: var(--space-sm); font-size: var(--text-sm); color: var(--color-text-secondary); }
.series-detail i { width: 16px; height: 16px; flex-shrink: 0; }
.series-detail.missing { color: var(--color-warning); }
.series-card-footer { padding: var(--space-md) var(--space-lg); background: var(--color-bg-sunken); border-top: 1px solid var(--color-border); }
.series-card-footer .btn { width: 100%; }

/* Modal */
.modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.8); z-index: 1000; padding: var(--space-lg); overflow-y: auto; }
.modal.active { display: flex; align-items: flex-start; justify-content: center; }
.modal-content { background: var(--color-bg-surface); border-radius: var(--radius-lg); max-width: 500px; width: 100%; margin-top: var(--space-xl); }
.modal-header { display: flex; justify-content: space-between; align-items: center; padding: var(--space-md) var(--space-lg); border-bottom: 1px solid var(--color-border); }
.modal-header h3 { margin: 0; }
.modal-close { background: none; border: none; padding: var(--space-xs); cursor: pointer; color: var(--color-text-secondary); font-size: 24px; line-height: 1; }
.modal-body { padding: var(--space-lg); }
.modal-footer { padding: var(--space-md) var(--space-lg); background: var(--color-bg-sunken); border-top: 1px solid var(--color-border); display: flex; justify-content: flex-end; gap: var(--space-sm); }
.form-group { margin-bottom: var(--space-md); }
.form-label { display: block; margin-bottom: var(--space-xs); font-weight: 500; font-size: var(--text-sm); }
.form-input { width: 100%; padding: var(--space-sm) var(--space-md); border: 1px solid var(--color-border); border-radius: var(--radius-sm); background: var(--color-bg-sunken); color: var(--color-text-primary); }
.logo-preview { width: 100%; height: 80px; background: var(--color-bg-sunken); border: 2px dashed var(--color-border); border-radius: var(--radius-md); display: flex; align-items: center; justify-content: center; margin-bottom: var(--space-sm); overflow: hidden; }
.logo-preview img { max-width: 100%; max-height: 100%; object-fit: contain; }
.logo-actions { display: flex; gap: var(--space-sm); }

/* Mobile edge-to-edge */
@media (max-width: 767px) {
    .event-card, .series-card {
        margin-left: calc(-1 * var(--space-md)); margin-right: calc(-1 * var(--space-md));
        border-radius: 0; border-left: none; border-right: none; width: calc(100% + var(--space-md) * 2);
    }
    .series-grid, .promotor-grid { gap: 0; }
    .series-grid { grid-template-columns: 1fr; }
    .event-card + .event-card, .series-card + .series-card { border-top: none; }
}
@media (max-width: 600px) {
    .stats-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 599px) {
    .event-actions { display: grid; grid-template-columns: 1fr 1fr; gap: var(--space-xs); }
    .event-actions .btn { justify-content: center; font-size: var(--text-sm); padding: var(--space-sm); }
    .event-card-header { flex-direction: column; gap: var(--space-xs); padding: var(--space-md); }
    .event-card-body { padding: var(--space-md); }
    .event-title { font-size: var(--text-lg); }
    .stat-value { font-size: var(--text-xl); }
    .stat-box { padding: var(--space-sm); }
    .modal { padding: 0; }
    .modal-content { max-width: 100%; height: 100%; margin: 0; border-radius: 0; display: flex; flex-direction: column; }
    .modal-body { flex: 1; overflow-y: auto; }
}
</style>

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
                    <label class="form-label">Banner <code style="background: var(--color-bg-sunken); padding: 2px 6px; border-radius: 4px; font-size: 0.7rem;">1200x150px</code></label>
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
const seriesData = <?= json_encode(array_column($series, null, 'id')) ?>;
let currentSeriesId = null;

function editSeries(id) {
    const s = seriesData[id];
    if (!s) { alert('Kunde inte hitta seriedata'); return; }

    currentSeriesId = id;
    document.getElementById('seriesId').value = id;
    document.getElementById('seriesModalTitle').textContent = 'Redigera ' + s.name;

    clearSeriesBanner();
    if (s.banner_media_id && s.banner_url) {
        document.getElementById('seriesBannerMediaId').value = s.banner_media_id;
        document.getElementById('seriesBannerPreview').innerHTML = `<img src="${s.banner_url}" alt="Banner">`;
    }

    document.getElementById('seriesModal').classList.add('active');
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

function closeSeriesModal() {
    document.getElementById('seriesModal').classList.remove('active');
    currentSeriesId = null;
}

async function uploadSeriesBanner(input) {
    const file = input.files[0];
    if (!file) return;
    if (!file.type.startsWith('image/')) { alert('Välj en bildfil (JPG, PNG, etc.)'); return; }
    if (file.size > 10 * 1024 * 1024) { alert('Filen är för stor. Max 10MB.'); return; }

    const preview = document.getElementById('seriesBannerPreview');
    preview.innerHTML = '<span style="font-size: 12px; color: var(--color-text-secondary);">Laddar upp...</span>';

    try {
        const formData = new FormData();
        formData.append('file', file);
        formData.append('folder', 'series');

        const response = await fetch('/api/media.php?action=upload', { method: 'POST', body: formData });
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

    try {
        const response = await fetch('/api/series.php?action=update_promotor', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: currentSeriesId, banner_media_id: formData.get('banner_media_id') || null })
        });
        const result = await response.json();
        if (result.success) { closeSeriesModal(); location.reload(); }
        else { alert(result.error || 'Kunde inte spara'); }
    } catch (error) {
        console.error('Save error:', error);
        alert('Ett fel uppstod');
    }
}

document.addEventListener('keydown', e => { if (e.key === 'Escape') closeSeriesModal(); });
document.getElementById('seriesModal').addEventListener('click', e => {
    if (e.target === document.getElementById('seriesModal')) closeSeriesModal();
});
</script>

<?php endif; // end admin/promotor view toggle ?>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>

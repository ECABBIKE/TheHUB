<?php
/**
 * Admin: Process Refunds
 * Hantera återbetalningar för orders med automatisk transfer-återföring
 *
 * ÅTERBETALNINGSPOLICY (från Allmänna Villkor):
 * - Ingen ångerrätt för idrottsevenemang (5.1)
 * - Återbetalning sker endast om Arrangören godkänner (5.2)
 * - Plattformen processar återbetalningen tekniskt
 * - Automatisk återföring av säljartransfers
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/refund-manager.php';
require_admin();

$db = getDB();
$pdo = $GLOBALS['pdo'];

$message = '';
$messageType = 'info';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();

    $action = $_POST['action'] ?? '';

    if ($action === 'process_refund') {
        $orderId = intval($_POST['order_id'] ?? 0);
        $refundType = $_POST['refund_type'] ?? 'full';
        $refundAmount = $refundType === 'partial' ? floatval($_POST['refund_amount'] ?? 0) : null;
        $reason = trim($_POST['reason'] ?? '');

        if ($orderId > 0) {
            // Get current admin ID
            $adminId = $_SESSION['user_id'] ?? null;

            $result = processOrderRefund(
                $pdo,
                $orderId,
                $refundAmount,
                $reason,
                $adminId
            );

            if ($result['success']) {
                $message = 'Återbetalning på ' . number_format($result['amount'], 0) . ' kr processad. ';
                if (!empty($result['transfer_reversals']['reversals'])) {
                    $reversed = count($result['transfer_reversals']['reversals']);
                    $message .= $reversed . ' transfer(s) återförda till plattformen.';
                }
                $messageType = 'success';
            } else {
                $message = 'Fel vid återbetalning: ' . ($result['error'] ?? 'Okänt fel');
                $messageType = 'danger';
            }
        }
    } elseif ($action === 'retry_reversals') {
        $refundId = intval($_POST['refund_id'] ?? 0);

        if ($refundId > 0) {
            $result = retryFailedTransferReversals($pdo, $refundId);

            if ($result['success']) {
                $message = 'Alla transfer-återföringar lyckades nu.';
                $messageType = 'success';
            } else {
                $message = 'Vissa återföringar misslyckades fortfarande. Kontrollera listan.';
                $messageType = 'warning';
            }
        }
    }
}

// Get order for refund (if provided)
$orderId = intval($_GET['order_id'] ?? 0);
$orderToRefund = null;
$canRefund = null;

if ($orderId > 0) {
    $canRefund = canOrderBeRefunded($pdo, $orderId);

    if ($canRefund['can_refund']) {
        $orderToRefund = $db->getRow("
            SELECT o.*,
                   CONCAT(r.firstname, ' ', r.lastname) as customer_name,
                   r.email as customer_email
            FROM orders o
            LEFT JOIN riders r ON o.rider_id = r.id
            WHERE o.id = ?
        ", [$orderId]);

        // Get order items with seller info
        if ($orderToRefund) {
            $orderToRefund['items'] = $db->getAll("
                SELECT oi.*,
                       pr.name as seller_name,
                       pr.org_number as seller_org
                FROM order_items oi
                LEFT JOIN payment_recipients pr ON oi.payment_recipient_id = pr.id
                WHERE oi.order_id = ?
            ", [$orderId]);

            // Get transfers for this order
            $orderToRefund['transfers'] = $db->getAll("
                SELECT ot.*,
                       pr.name as recipient_name
                FROM order_transfers ot
                JOIN payment_recipients pr ON ot.payment_recipient_id = pr.id
                WHERE ot.order_id = ?
            ", [$orderId]);
        }
    }
}

// Get recent refunds
$recentRefunds = $db->getAll("
    SELECT r.*,
           o.order_number,
           o.total_amount as order_total,
           CONCAT(rd.firstname, ' ', rd.lastname) as customer_name,
           u.firstname as admin_firstname,
           u.lastname as admin_lastname
    FROM order_refunds r
    JOIN orders o ON r.order_id = o.id
    LEFT JOIN riders rd ON o.rider_id = rd.id
    LEFT JOIN users u ON r.admin_id = u.id
    ORDER BY r.created_at DESC
    LIMIT 50
");

// Get pending refund requests (from ticket refund requests)
$pendingRequests = $db->getAll("
    SELECT
        err.*,
        et.ticket_number,
        et.paid_price,
        o.id as order_id,
        o.order_number,
        e.name as event_name,
        e.date as event_date,
        r.firstname,
        r.lastname,
        r.email as rider_email
    FROM event_refund_requests err
    JOIN event_tickets et ON err.ticket_id = et.id
    JOIN orders o ON et.order_id = o.id
    JOIN events e ON et.event_id = e.id
    JOIN riders r ON err.rider_id = r.id
    WHERE err.status = 'pending'
    ORDER BY err.created_at ASC
");

// Page config
$page_title = 'Processa Återbetalningar';
$page_group = 'economy';
$breadcrumbs = [
    ['label' => 'Ekonomi', 'url' => '/admin/ekonomi'],
    ['label' => 'Återbetalningar']
];
include __DIR__ . '/components/unified-layout.php';
?>

<?php if ($message): ?>
<div class="alert alert-<?= $messageType ?> mb-lg">
    <?= h($message) ?>
</div>
<?php endif; ?>

<!-- Admin Instructions -->
<div class="admin-card mb-lg" style="background: linear-gradient(135deg, var(--color-bg-surface), var(--color-bg-hover)); border: 2px solid var(--color-accent-light);">
    <div class="admin-card-body">
        <h3 style="margin-bottom: var(--space-md);">
            <i data-lucide="clipboard-list" style="width: 20px; height: 20px; vertical-align: middle; color: var(--color-accent);"></i>
            Admin: Sa har fungerar aterbetalningar
        </h3>

        <div style="display: grid; gap: var(--space-md); grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));">
            <!-- Admin Steps -->
            <div style="background: var(--color-bg-page); padding: var(--space-md); border-radius: var(--radius-md); border: 1px solid var(--color-border);">
                <h4 style="margin: 0 0 var(--space-sm) 0; display: flex; align-items: center; gap: var(--space-xs);">
                    <i data-lucide="shield-check" style="width: 18px; height: 18px; color: var(--color-accent);"></i>
                    Dina steg som admin
                </h4>
                <ol style="color: var(--color-text-secondary); margin: 0; padding-left: 1.2rem; font-size: var(--text-sm);">
                    <li>Arrangoren kontaktar dig med refund-begaran</li>
                    <li>Verifiera att arrangoren godkanner refund</li>
                    <li>Sok upp ordern nedan (Order ID)</li>
                    <li>Valj hel/delvis aterbetalning</li>
                    <li>Klicka "Processa Aterbetalning"</li>
                </ol>
                <p class="text-xs text-secondary" style="margin: var(--space-sm) 0 0 0;">
                    <i data-lucide="info" style="width: 12px; height: 12px;"></i>
                    Saljartransfers aterfors <strong>automatiskt</strong>
                </p>
            </div>

            <!-- Policy Box -->
            <div style="background: var(--color-bg-page); padding: var(--space-md); border-radius: var(--radius-md); border: 1px solid var(--color-border);">
                <h4 style="margin: 0 0 var(--space-sm) 0; display: flex; align-items: center; gap: var(--space-xs);">
                    <i data-lucide="book-open" style="width: 18px; height: 18px; color: var(--color-warning);"></i>
                    Aterbetalningspolicy (Allmanna Villkor)
                </h4>
                <ul style="color: var(--color-text-secondary); margin: 0; padding-left: 1.2rem; font-size: var(--text-sm);">
                    <li><strong>5.1:</strong> Ingen angerratt for idrottsevenemang</li>
                    <li><strong>5.2:</strong> Refund endast om <strong>Arrangoren godkanner</strong></li>
                    <li><strong>5.3:</strong> Aterbetalning till samma betalmetod</li>
                </ul>
                <p class="text-xs text-secondary" style="margin: var(--space-sm) 0 0 0;">
                    <i data-lucide="external-link" style="width: 12px; height: 12px;"></i>
                    <a href="/villkor" target="_blank" style="color: var(--color-accent);">Las fullstandiga villkor</a>
                </p>
            </div>
        </div>
    </div>
</div>

<!-- Promotor/Arrangor Info -->
<div class="admin-card mb-lg" style="border: 1px solid var(--color-border); background: rgba(251, 191, 36, 0.03);">
    <div class="admin-card-body">
        <h3 style="margin-bottom: var(--space-sm);">
            <i data-lucide="users" style="width: 20px; height: 20px; vertical-align: middle; color: var(--color-warning);"></i>
            For arrangorer: Sa fungerar det
        </h3>
        <p style="color: var(--color-text-secondary); font-size: var(--text-sm); margin-bottom: var(--space-sm);">
            Information att dela med arrangorer som fragar om aterbetalningar:
        </p>

        <div style="background: var(--color-bg-page); padding: var(--space-md); border-radius: var(--radius-md); font-size: var(--text-sm);">
            <p style="margin: 0 0 var(--space-sm) 0;"><strong>For arrangorer/promotors:</strong></p>
            <ul style="color: var(--color-text-secondary); margin: 0; padding-left: 1.2rem;">
                <li><strong>Deltagare kontaktar dig</strong> (arrangoren) for aterbetalning</li>
                <li><strong>Du beslutar</strong> om aterbetalning ska godkannas</li>
                <li>Meddela TheHUB-admin med: Order-ID + ditt beslut</li>
                <li>Admin processar aterbetalningen tekniskt</li>
                <li>Pengarna aterfors fran ditt Stripe-konto automatiskt</li>
            </ul>
            <div style="margin-top: var(--space-sm); padding: var(--space-sm); background: var(--color-bg-hover); border-radius: var(--radius-sm);">
                <strong style="color: var(--color-text-primary);">Kontakt for refund-begaran:</strong>
                <span style="color: var(--color-text-secondary);">info@gravityseries.se</span>
            </div>
        </div>
    </div>
</div>

<!-- Technical Info -->
<div class="admin-card mb-lg" style="border: 1px solid var(--color-border);">
    <div class="admin-card-body">
        <details>
            <summary style="cursor: pointer; font-weight: 600; display: flex; align-items: center; gap: var(--space-sm);">
                <i data-lucide="code" style="width: 18px; height: 18px; color: var(--color-accent);"></i>
                Teknisk information (for admins)
            </summary>
            <div style="margin-top: var(--space-md); padding: var(--space-md); background: var(--color-bg-page); border-radius: var(--radius-md);">
                <ul style="color: var(--color-text-secondary); margin: 0; padding-left: 1.2rem; font-size: var(--text-sm);">
                    <li><strong>Refunds via Stripe Dashboard</strong> synkas automatiskt via webhook (charge.refunded)</li>
                    <li><strong>Automatisk transfer reversal</strong> - vid refund aterfors saljartransfers proportionellt</li>
                    <li><strong>Partial refunds</strong> - mojligt att aterbetala del av order</li>
                    <li><strong>Plattformen bar risken</strong> for chargebacks (negativ balans)</li>
                    <li><strong>Retry-funktion</strong> for misslyckade transfer-aterforingar</li>
                </ul>
                <div style="margin-top: var(--space-md); display: flex; gap: var(--space-sm); flex-wrap: wrap;">
                    <a href="https://dashboard.stripe.com/refunds" target="_blank" class="btn-admin btn-admin-secondary btn-admin-sm">
                        <i data-lucide="external-link"></i> Stripe Dashboard
                    </a>
                    <a href="/admin/stripe-connect" class="btn-admin btn-admin-secondary btn-admin-sm">
                        <i data-lucide="link"></i> Stripe Connect
                    </a>
                    <a href="/admin/ekonomi" class="btn-admin btn-admin-secondary btn-admin-sm">
                        <i data-lucide="wallet"></i> Ekonomi
                    </a>
                </div>
            </div>
        </details>
    </div>
</div>

<?php if ($orderToRefund): ?>
<!-- Process Refund Form -->
<div class="admin-card mb-lg">
    <div class="admin-card-header">
        <h2>
            <i data-lucide="credit-card"></i>
            Återbetala Order #<?= h($orderToRefund['order_number']) ?>
        </h2>
    </div>
    <div class="admin-card-body">
        <div style="display: grid; gap: var(--space-lg); grid-template-columns: 1fr 1fr;">
            <!-- Order Info -->
            <div>
                <h4 style="margin-bottom: var(--space-sm);">Orderinfo</h4>
                <table class="admin-table" style="font-size: 0.9rem;">
                    <tr>
                        <td style="width: 140px;"><strong>Kund</strong></td>
                        <td><?= h($orderToRefund['customer_name']) ?></td>
                    </tr>
                    <tr>
                        <td><strong>E-post</strong></td>
                        <td><?= h($orderToRefund['customer_email']) ?></td>
                    </tr>
                    <tr>
                        <td><strong>Totalbelopp</strong></td>
                        <td><strong><?= number_format($orderToRefund['total_amount'], 0) ?> kr</strong></td>
                    </tr>
                    <tr>
                        <td><strong>Redan återbetalt</strong></td>
                        <td><?= number_format($canRefund['already_refunded'], 0) ?> kr</td>
                    </tr>
                    <tr>
                        <td><strong>Max refund</strong></td>
                        <td class="text-success"><strong><?= number_format($canRefund['max_refund_amount'], 0) ?> kr</strong></td>
                    </tr>
                    <tr>
                        <td><strong>Betaldatum</strong></td>
                        <td><?= $orderToRefund['paid_at'] ? date('Y-m-d H:i', strtotime($orderToRefund['paid_at'])) : '-' ?></td>
                    </tr>
                </table>
            </div>

            <!-- Refund Form -->
            <div>
                <h4 style="margin-bottom: var(--space-sm);">Processera Återbetalning</h4>
                <form method="POST" onsubmit="return confirm('Bekräfta återbetalning?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="process_refund">
                    <input type="hidden" name="order_id" value="<?= $orderId ?>">

                    <div class="form-group">
                        <label class="form-label">Typ av återbetalning</label>
                        <div style="display: flex; gap: var(--space-md);">
                            <label style="display: flex; align-items: center; gap: var(--space-xs);">
                                <input type="radio" name="refund_type" value="full" checked onchange="togglePartialAmount(this)">
                                Hel återbetalning (<?= number_format($canRefund['max_refund_amount'], 0) ?> kr)
                            </label>
                            <label style="display: flex; align-items: center; gap: var(--space-xs);">
                                <input type="radio" name="refund_type" value="partial" onchange="togglePartialAmount(this)">
                                Delvis återbetalning
                            </label>
                        </div>
                    </div>

                    <div class="form-group" id="partial_amount_group" style="display: none;">
                        <label class="form-label">Belopp att återbetala (SEK)</label>
                        <input type="number" name="refund_amount" class="form-input"
                               min="1" max="<?= $canRefund['max_refund_amount'] ?>"
                               step="1" placeholder="Ange belopp">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Anledning (visas i loggen)</label>
                        <textarea name="reason" class="form-input" rows="2"
                                  placeholder="T.ex. 'Godkänd av arrangör pga sjukdom'"></textarea>
                    </div>

                    <?php if (!empty($orderToRefund['transfers'])): ?>
                    <div class="alert alert-warning" style="margin-bottom: var(--space-md);">
                        <i data-lucide="alert-triangle" style="width: 16px; height: 16px;"></i>
                        <strong><?= count($orderToRefund['transfers']) ?> transfer(s)</strong> till säljare kommer automatiskt att återföras.
                    </div>
                    <?php endif; ?>

                    <button type="submit" class="btn-admin btn-admin-danger">
                        <i data-lucide="arrow-left"></i>
                        Processa Återbetalning
                    </button>
                    <a href="/admin/process-refunds" class="btn-admin btn-admin-secondary">Avbryt</a>
                </form>
            </div>
        </div>

        <!-- Order Items -->
        <?php if (!empty($orderToRefund['items'])): ?>
        <h4 style="margin-top: var(--space-lg); margin-bottom: var(--space-sm);">Orderrader</h4>
        <div class="table-responsive">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Produkt</th>
                        <th>Säljare</th>
                        <th class="text-right">Pris</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orderToRefund['items'] as $item): ?>
                    <tr>
                        <td><?= h($item['description']) ?></td>
                        <td>
                            <?php if ($item['seller_name']): ?>
                            <span class="badge badge-info"><?= h($item['seller_name']) ?></span>
                            <?php else: ?>
                            <span class="text-secondary">Plattformen</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-right"><?= number_format($item['total_price'], 0) ?> kr</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <!-- Transfers -->
        <?php if (!empty($orderToRefund['transfers'])): ?>
        <h4 style="margin-top: var(--space-lg); margin-bottom: var(--space-sm);">Säljartransfers</h4>
        <div class="table-responsive">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Säljare</th>
                        <th class="text-right">Belopp</th>
                        <th>Status</th>
                        <th>Transfer ID</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orderToRefund['transfers'] as $transfer): ?>
                    <tr>
                        <td><?= h($transfer['recipient_name']) ?></td>
                        <td class="text-right"><?= number_format($transfer['amount'], 0) ?> kr</td>
                        <td>
                            <?php if ($transfer['reversed']): ?>
                            <span class="badge badge-warning">Återförd</span>
                            <?php elseif ($transfer['status'] === 'completed'): ?>
                            <span class="badge badge-success">Genomförd</span>
                            <?php else: ?>
                            <span class="badge badge-secondary"><?= h($transfer['status']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td><code style="font-size: 0.8rem;"><?= h($transfer['stripe_transfer_id'] ?? '-') ?></code></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php elseif ($orderId > 0 && !$canRefund['can_refund']): ?>
<!-- Cannot Refund -->
<div class="alert alert-warning mb-lg">
    <i data-lucide="alert-triangle"></i>
    <strong>Kan inte återbetala order #<?= $orderId ?>:</strong> <?= h($canRefund['reason']) ?>
</div>
<?php endif; ?>

<!-- Pending Refund Requests -->
<?php if (!empty($pendingRequests)): ?>
<div class="admin-card mb-lg">
    <div class="admin-card-header">
        <h2>
            <i data-lucide="clock"></i>
            Väntande Förfrågningar
            <span class="badge badge-warning" style="margin-left: var(--space-sm);"><?= count($pendingRequests) ?></span>
        </h2>
    </div>
    <div class="admin-card-body">
        <div class="table-responsive">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Datum</th>
                        <th>Deltagare</th>
                        <th>Event</th>
                        <th>Biljett</th>
                        <th class="text-right">Belopp</th>
                        <th>Anledning</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pendingRequests as $request): ?>
                    <tr>
                        <td><?= date('d M', strtotime($request['created_at'])) ?></td>
                        <td>
                            <strong><?= h($request['firstname'] . ' ' . $request['lastname']) ?></strong>
                            <div class="text-xs text-secondary"><?= h($request['rider_email']) ?></div>
                        </td>
                        <td>
                            <?= h($request['event_name']) ?>
                            <div class="text-xs text-secondary"><?= date('d M Y', strtotime($request['event_date'])) ?></div>
                        </td>
                        <td><code><?= h($request['ticket_number']) ?></code></td>
                        <td class="text-right"><strong><?= number_format($request['refund_amount'], 0) ?> kr</strong></td>
                        <td>
                            <span class="text-sm" title="<?= h($request['reason']) ?>">
                                <?= h(substr($request['reason'] ?? '-', 0, 30)) ?>...
                            </span>
                        </td>
                        <td class="text-right">
                            <a href="/admin/process-refunds?order_id=<?= $request['order_id'] ?>"
                               class="btn-admin btn-admin-primary btn-admin-sm">
                                <i data-lucide="arrow-right"></i>
                                Hantera
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Search Order -->
<div class="admin-card mb-lg">
    <div class="admin-card-header">
        <h2>
            <i data-lucide="search"></i>
            Sök Order att Återbetala
        </h2>
    </div>
    <div class="admin-card-body">
        <form method="GET" style="display: flex; gap: var(--space-md); align-items: flex-end;">
            <div class="form-group" style="flex: 1; margin: 0;">
                <label class="form-label">Order ID</label>
                <input type="number" name="order_id" class="form-input"
                       placeholder="Ange order-ID" value="<?= $orderId ?: '' ?>">
            </div>
            <button type="submit" class="btn-admin btn-admin-primary">
                <i data-lucide="search"></i>
                Sök
            </button>
        </form>
    </div>
</div>

<!-- Recent Refunds -->
<div class="admin-card">
    <div class="admin-card-header">
        <h2>
            <i data-lucide="history"></i>
            Senaste Återbetalningar
        </h2>
    </div>
    <div class="admin-card-body">
        <?php if (empty($recentRefunds)): ?>
        <p class="text-secondary">Inga återbetalningar registrerade ännu.</p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Datum</th>
                        <th>Order</th>
                        <th>Kund</th>
                        <th class="text-right">Belopp</th>
                        <th>Typ</th>
                        <th>Status</th>
                        <th>Admin</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentRefunds as $refund): ?>
                    <tr>
                        <td><?= date('d M Y', strtotime($refund['created_at'])) ?></td>
                        <td>
                            <a href="/admin/orders?id=<?= $refund['order_id'] ?>">
                                #<?= h($refund['order_number']) ?>
                            </a>
                        </td>
                        <td><?= h($refund['customer_name'] ?: '-') ?></td>
                        <td class="text-right">
                            <strong><?= number_format($refund['amount'], 0) ?> kr</strong>
                            <div class="text-xs text-secondary">
                                av <?= number_format($refund['order_total'], 0) ?> kr
                            </div>
                        </td>
                        <td>
                            <?php if ($refund['refund_type'] === 'full'): ?>
                            <span class="badge badge-info">Hel</span>
                            <?php else: ?>
                            <span class="badge badge-secondary">Delvis</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php
                            $statusBadge = [
                                'pending' => 'badge-warning',
                                'processing' => 'badge-info',
                                'completed' => 'badge-success',
                                'partial_completed' => 'badge-warning',
                                'failed' => 'badge-danger'
                            ];
                            $statusText = [
                                'pending' => 'Väntande',
                                'processing' => 'Processar',
                                'completed' => 'Klar',
                                'partial_completed' => 'Delvis klar',
                                'failed' => 'Misslyckad'
                            ];
                            ?>
                            <span class="badge <?= $statusBadge[$refund['status']] ?? 'badge-secondary' ?>">
                                <?= $statusText[$refund['status']] ?? $refund['status'] ?>
                            </span>
                            <?php if ($refund['status'] === 'partial_completed'): ?>
                            <form method="POST" style="display: inline; margin-left: var(--space-xs);">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="retry_reversals">
                                <input type="hidden" name="refund_id" value="<?= $refund['id'] ?>">
                                <button type="submit" class="btn-admin btn-admin-warning btn-admin-sm"
                                        title="Försök igen med misslyckade överföringar">
                                    <i data-lucide="refresh-cw"></i>
                                </button>
                            </form>
                            <?php endif; ?>
                        </td>
                        <td class="text-secondary text-sm">
                            <?= $refund['admin_firstname'] ? h($refund['admin_firstname'] . ' ' . $refund['admin_lastname'][0] . '.') : 'System' ?>
                        </td>
                        <td>
                            <?php if ($refund['reason']): ?>
                            <span title="<?= h($refund['reason']) ?>">
                                <i data-lucide="message-square" style="width: 14px; height: 14px;"></i>
                            </span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
function togglePartialAmount(radio) {
    const partialGroup = document.getElementById('partial_amount_group');
    if (radio.value === 'partial') {
        partialGroup.style.display = 'block';
    } else {
        partialGroup.style.display = 'none';
    }
}
</script>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>

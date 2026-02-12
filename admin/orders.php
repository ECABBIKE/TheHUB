<?php
/**
 * Order Management - View and confirm payments
 * V3 Unified Layout
 *
 * Accessible by:
 * - Super Admin: Can manage all orders
 * - Promotor: Can manage orders for their events
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/payment.php';
require_admin();

$db = getDB();
$currentAdmin = getCurrentAdmin();
$isSuperAdmin = hasRole('super_admin');

$message = '';
$messageType = 'info';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();

    $action = $_POST['action'] ?? '';
    $orderId = intval($_POST['order_id'] ?? 0);

    if ($action === 'confirm_payment' && $orderId) {
        $paymentRef = trim($_POST['payment_reference'] ?? '');

        try {
            if (markOrderPaid($orderId, $paymentRef)) {
                $message = 'Betalning bekräftad!';
                $messageType = 'success';
            } else {
                $message = 'Kunde inte bekräfta betalningen.';
                $messageType = 'error';
            }
        } catch (\Throwable $e) {
            error_log("markOrderPaid error for order {$orderId}: " . $e->getMessage());
            $message = 'Fel: ' . $e->getMessage();
            $messageType = 'error';
        }

    } elseif ($action === 'cancel_order' && $orderId) {
        $db->update('orders', [
            'payment_status' => 'cancelled',
            'cancelled_at' => date('Y-m-d H:i:s')
        ], 'id = ?', [$orderId]);

        // Also cancel registrations
        $db->query("
            UPDATE event_registrations
            SET status = 'cancelled'
            WHERE order_id = ?
        ", [$orderId]);

        $message = 'Order avbruten!';
        $messageType = 'success';

    } elseif ($action === 'delete_order' && $orderId) {
        // Delete order items first, then registrations, then order
        $db->query("DELETE FROM order_items WHERE order_id = ?", [$orderId]);
        $db->query("DELETE FROM event_registrations WHERE order_id = ?", [$orderId]);
        $db->query("DELETE FROM series_registrations WHERE order_id = ?", [$orderId]);
        $db->query("DELETE FROM orders WHERE id = ?", [$orderId]);

        $message = 'Order raderad!';
        $messageType = 'success';

    } elseif ($action === 'bulk_delete' && !empty($_POST['order_ids'])) {
        $orderIds = array_map('intval', explode(',', $_POST['order_ids']));
        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));

        $db->query("DELETE FROM order_items WHERE order_id IN ({$placeholders})", $orderIds);
        $db->query("DELETE FROM event_registrations WHERE order_id IN ({$placeholders})", $orderIds);
        $db->query("DELETE FROM series_registrations WHERE order_id IN ({$placeholders})", $orderIds);
        $db->query("DELETE FROM orders WHERE id IN ({$placeholders})", $orderIds);

        $message = count($orderIds) . ' ordrar raderade!';
        $messageType = 'success';
    }
}

// Filter parameters
$filterStatus = $_GET['status'] ?? 'pending';
$filterEvent = isset($_GET['event_id']) ? intval($_GET['event_id']) : 0;
$filterRecipient = isset($_GET['recipient_id']) ? intval($_GET['recipient_id']) : 0;
$search = trim($_GET['search'] ?? '');

// Build query
$whereConditions = [];
$params = [];

// Status filter
if ($filterStatus && $filterStatus !== 'all') {
    $whereConditions[] = "o.payment_status = ?";
    $params[] = $filterStatus;
}

// Event filter - also match orders with event_registrations for this event (multi-event orders)
if ($filterEvent) {
    $whereConditions[] = "(o.event_id = ? OR o.id IN (SELECT DISTINCT order_id FROM event_registrations WHERE event_id = ? AND order_id IS NOT NULL))";
    $params[] = $filterEvent;
    $params[] = $filterEvent;
}

// Search
if ($search) {
    $whereConditions[] = "(o.order_number LIKE ? OR o.customer_name LIKE ?)";
    $searchTerm = "%{$search}%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

// Promotor restriction - only their events (including multi-event orders)
if (!$isSuperAdmin) {
    $whereConditions[] = "(o.event_id IN (SELECT event_id FROM promotor_events WHERE user_id = ?) OR o.id IN (SELECT er.order_id FROM event_registrations er JOIN promotor_events pe ON pe.event_id = er.event_id WHERE pe.user_id = ? AND er.order_id IS NOT NULL))";
    $params[] = $currentAdmin['id'];
    $params[] = $currentAdmin['id'];
}

$whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

// Get orders with item count for multi-rider support
$orders = $db->getAll("
    SELECT o.*, e.name as event_name, e.date as event_date,
           r.firstname, r.lastname,
           s.name as series_name,
           (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.id) as item_count
    FROM orders o
    LEFT JOIN events e ON o.event_id = e.id
    LEFT JOIN series s ON o.series_id = s.id
    LEFT JOIN riders r ON o.rider_id = r.id
    {$whereClause}
    ORDER BY o.created_at DESC
    LIMIT 200
", $params);

// Pre-load order items for all orders (for expandable detail)
$orderItemsByOrder = [];
if (!empty($orders)) {
    $orderIds = array_column($orders, 'id');
    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
    $itemRows = $db->getAll("
        SELECT oi.order_id, oi.description, oi.unit_price, oi.total_price,
               er.first_name, er.last_name, er.category, er.email,
               e.name as item_event_name, e.date as item_event_date
        FROM order_items oi
        LEFT JOIN event_registrations er ON oi.registration_id = er.id
        LEFT JOIN events e ON er.event_id = e.id
        WHERE oi.order_id IN ({$placeholders})
        ORDER BY oi.id
    ", $orderIds);
    foreach ($itemRows as $item) {
        $orderItemsByOrder[$item['order_id']][] = $item;
    }
}

// Get events for filter (only accessible events)
if ($isSuperAdmin) {
    $events = $db->getAll("
        SELECT DISTINCT e.id, e.name, e.date
        FROM events e
        JOIN orders o ON o.event_id = e.id
        ORDER BY e.date DESC
        LIMIT 50
    ");
} else {
    $events = $db->getAll("
        SELECT DISTINCT e.id, e.name, e.date
        FROM events e
        JOIN promotor_events pe ON pe.event_id = e.id
        WHERE pe.user_id = ?
        ORDER BY e.date DESC
    ", [$currentAdmin['id']]);
}

// Stats
$stats = $db->getRow("
    SELECT
        COUNT(CASE WHEN payment_status = 'pending' THEN 1 END) as pending_count,
        COUNT(CASE WHEN payment_status = 'paid' THEN 1 END) as paid_count,
        SUM(CASE WHEN payment_status = 'paid' THEN total_amount ELSE 0 END) as total_revenue
    FROM orders o
    " . ($isSuperAdmin ? '' : "WHERE o.event_id IN (SELECT event_id FROM promotor_events WHERE user_id = {$currentAdmin['id']})") . "
");

// Page config for unified layout
$page_title = 'Ordrar';
$breadcrumbs = [
    ['label' => 'Ekonomi']
];

include __DIR__ . '/components/unified-layout.php';
?>

<!-- Message -->
<?php if ($message): ?>
<div class="alert alert-<?= $messageType === 'success' ? 'success' : 'error' ?> mb-lg">
    <i data-lucide="<?= $messageType === 'success' ? 'check-circle' : 'alert-circle' ?>"></i>
    <?= h($message) ?>
</div>
<?php endif; ?>

<!-- Stats -->
<div class="admin-stats-grid">
    <div class="admin-stat-card">
        <div class="admin-stat-icon stat-icon-warning">
            <i data-lucide="clock"></i>
        </div>
        <div class="admin-stat-content">
            <div class="admin-stat-value"><?= $stats['pending_count'] ?? 0 ?></div>
            <div class="admin-stat-label">Väntar på betalning</div>
        </div>
    </div>
    <div class="admin-stat-card">
        <div class="admin-stat-icon stat-icon-success">
            <i data-lucide="check-circle"></i>
        </div>
        <div class="admin-stat-content">
            <div class="admin-stat-value"><?= $stats['paid_count'] ?? 0 ?></div>
            <div class="admin-stat-label">Betalda ordrar</div>
        </div>
    </div>
    <div class="admin-stat-card">
        <div class="admin-stat-icon stat-icon-accent">
            <i data-lucide="wallet"></i>
        </div>
        <div class="admin-stat-content">
            <div class="admin-stat-value"><?= number_format($stats['total_revenue'] ?? 0, 0, ',', ' ') ?> kr</div>
            <div class="admin-stat-label">Totalt inbetalt</div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="admin-card mb-lg">
    <div class="admin-card-body">
        <form method="GET" class="flex flex-wrap gap-md items-end">
            <div class="admin-form-group mb-0">
                <label class="admin-form-label">Status</label>
                <select name="status" class="admin-form-select" onchange="this.form.submit()">
                    <option value="pending" <?= $filterStatus === 'pending' ? 'selected' : '' ?>>Väntar</option>
                    <option value="paid" <?= $filterStatus === 'paid' ? 'selected' : '' ?>>Betalda</option>
                    <option value="cancelled" <?= $filterStatus === 'cancelled' ? 'selected' : '' ?>>Avbrutna</option>
                    <option value="all" <?= $filterStatus === 'all' ? 'selected' : '' ?>>Alla</option>
                </select>
            </div>

            <?php if (!empty($events)): ?>
            <div class="admin-form-group mb-0">
                <label class="admin-form-label">Event</label>
                <select name="event_id" class="admin-form-select" onchange="this.form.submit()">
                    <option value="">Alla event</option>
                    <?php foreach ($events as $event): ?>
                    <option value="<?= $event['id'] ?>" <?= $filterEvent == $event['id'] ? 'selected' : '' ?>>
                        <?= h($event['name']) ?> (<?= date('Y-m-d', strtotime($event['date'])) ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <?php if (!empty($recipients)): ?>
            <div class="admin-form-group mb-0">
                <label class="admin-form-label">Betalningsmottagare</label>
                <select name="recipient_id" class="admin-form-select" onchange="this.form.submit()">
                    <option value="">Alla mottagare</option>
                    <?php foreach ($recipients as $recipient): ?>
                    <option value="<?= $recipient['id'] ?>" <?= $filterRecipient == $recipient['id'] ? 'selected' : '' ?>>
                        <?= h($recipient['name']) ?><?= $recipient['identifier'] ? ' (' . h($recipient['identifier']) . ')' : '' ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <div class="admin-form-group mb-0 flex-1 min-w-200">
                <label class="admin-form-label">Sök</label>
                <input type="text" name="search" class="admin-form-input"
                       value="<?= h($search) ?>"
                       placeholder="Ordernummer, namn...">
            </div>

            <button type="submit" class="btn-admin btn-admin-primary">
                <i data-lucide="search"></i>
                Sök
            </button>
        </form>
    </div>
</div>

<!-- Bulk actions for cancelled orders -->
<?php
$cancelledCount = count(array_filter($orders, fn($o) => $o['payment_status'] === 'cancelled'));
if ($cancelledCount > 0): ?>
<div class="admin-card mb-lg">
    <div class="admin-card-body flex items-center justify-between gap-md flex-wrap">
        <span class="text-secondary"><?= $cancelledCount ?> avbrutna ordrar</span>
        <form method="POST" class="inline">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="bulk_delete">
            <input type="hidden" name="order_ids" value="<?= implode(',', array_column(array_filter($orders, fn($o) => $o['payment_status'] === 'cancelled'), 'id')) ?>">
            <button type="submit" class="btn-admin btn-admin-sm" style="background: var(--color-error); color: white;"
                    onclick="return confirm('Radera ALLA <?= $cancelledCount ?> avbrutna ordrar permanent?')">
                <i data-lucide="trash-2"></i>
                Radera alla avbrutna ordrar
            </button>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Orders list -->
<div class="admin-card">
    <div class="admin-card-header">
        <h2><?= count($orders) ?> ordrar</h2>
    </div>
    <div class="admin-card-body p-0">
        <?php if (empty($orders)): ?>
        <div class="admin-empty-state">
            <i data-lucide="inbox"></i>
            <h3>Inga ordrar hittades</h3>
            <p>Justera filtren för att se fler ordrar.</p>
        </div>
        <?php else: ?>
        <div class="admin-table-container">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Order</th>
                        <th>Kund</th>
                        <th>Event/Serie</th>
                        <th>Deltagare</th>
                        <th>Belopp</th>
                        <th>Ref</th>
                        <th>Status</th>
                        <th>Skapad</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orders as $order):
                        $statusClass = [
                            'pending' => 'admin-badge-warning',
                            'paid' => 'admin-badge-success',
                            'cancelled' => 'admin-badge-secondary',
                            'refunded' => 'admin-badge-error',
                            'failed' => 'admin-badge-error'
                        ][$order['payment_status']] ?? 'admin-badge-secondary';

                        $statusText = [
                            'pending' => 'Väntar',
                            'paid' => 'Betald',
                            'cancelled' => 'Avbruten',
                            'refunded' => 'Återbetald',
                            'failed' => 'Misslyckad'
                        ][$order['payment_status']] ?? $order['payment_status'];

                        $customerName = !empty($order['firstname']) ? $order['firstname'] . ' ' . $order['lastname'] : ($order['customer_name'] ?? '-');
                        $items = $orderItemsByOrder[$order['id']] ?? [];
                    ?>
                    <tr class="order-row" onclick="toggleOrderDetail(<?= $order['id'] ?>)" style="cursor: pointer;">
                        <td data-label="Order">
                            <code class="font-medium"><?= h($order['order_number']) ?></code>
                        </td>
                        <td data-label="Kund">
                            <div class="font-medium"><?= h($customerName) ?></div>
                            <div class="text-xs text-secondary"><?= h($order['customer_email']) ?></div>
                        </td>
                        <td data-label="Event/Serie">
                            <?php if ($order['series_name']): ?>
                            <div><span class="admin-badge admin-badge-info">Serie</span> <?= h($order['series_name']) ?></div>
                            <?php elseif ($order['event_name']): ?>
                            <div><?= h($order['event_name']) ?></div>
                            <div class="text-xs text-secondary"><?= date('Y-m-d', strtotime($order['event_date'])) ?></div>
                            <?php else: ?>
                            <span class="text-secondary">-</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Deltagare" class="text-center">
                            <?php if ($order['item_count'] > 1): ?>
                            <span class="admin-badge admin-badge-accent"><?= $order['item_count'] ?> st</span>
                            <?php elseif ($order['item_count'] == 1): ?>
                            <span class="text-secondary">1</span>
                            <?php else: ?>
                            <span class="text-secondary">-</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Belopp" class="font-medium">
                            <?= number_format($order['total_amount'], 0) ?> kr
                        </td>
                        <td data-label="Ref" class="orders-swish-col">
                            <?php if ($order['payment_reference']): ?>
                            <code><?= h($order['payment_reference']) ?></code>
                            <?php endif; ?>
                        </td>
                        <td data-label="Status">
                            <span class="admin-badge <?= $statusClass ?>"><?= $statusText ?></span>
                        </td>
                        <td data-label="Skapad" class="text-sm text-secondary">
                            <?= date('Y-m-d H:i', strtotime($order['created_at'])) ?>
                        </td>
                        <td data-label="" class="orders-actions-cell" onclick="event.stopPropagation()">
                            <div class="orders-actions">
                            <?php if ($order['payment_status'] === 'pending'): ?>
                                <button type="button" class="btn-admin btn-admin-sm orders-btn-confirm"
                                        onclick="openConfirmModal(<?= $order['id'] ?>, '<?= h($order['order_number']) ?>')">
                                    <i data-lucide="check"></i>
                                    <span class="orders-btn-label">Bekräfta</span>
                                </button>
                                <form method="POST" class="inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="cancel_order">
                                    <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                                    <button type="submit" class="btn-admin btn-admin-sm orders-btn-cancel"
                                            onclick="return confirm('Avbryt denna order?')">
                                        <i data-lucide="x"></i>
                                        <span class="orders-btn-label">Avbryt</span>
                                    </button>
                                </form>
                            <?php endif; ?>
                                <form method="POST" class="inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete_order">
                                    <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                                    <button type="submit" class="btn-admin btn-admin-sm orders-btn-delete"
                                            onclick="return confirm('Radera order <?= h($order['order_number']) ?> permanent? Detta kan inte ångras.')">
                                        <i data-lucide="trash-2"></i>
                                        <span class="orders-btn-label">Radera</span>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <!-- Expandable detail row -->
                    <tr class="order-detail-row" id="detail-<?= $order['id'] ?>" style="display: none;">
                        <td colspan="9" style="padding: 0; background: var(--color-bg-tertiary, var(--color-bg-hover));">
                            <div style="padding: var(--space-md) var(--space-lg);">
                                <?php if (!empty($items)): ?>
                                <div class="text-sm font-medium mb-sm">Orderinnehall:</div>
                                <table style="width: 100%; font-size: var(--text-sm);">
                                    <?php foreach ($items as $item): ?>
                                    <tr>
                                        <td style="padding: var(--space-2xs) var(--space-sm);">
                                            <?php if ($item['first_name']): ?>
                                                <strong><?= h($item['first_name'] . ' ' . $item['last_name']) ?></strong>
                                                <?php if ($item['category']): ?>
                                                    <span class="text-secondary"> - <?= h($item['category']) ?></span>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <?= h($item['description']) ?>
                                            <?php endif; ?>
                                        </td>
                                        <td style="padding: var(--space-2xs) var(--space-sm); white-space: nowrap;">
                                            <?php if ($item['item_event_name']): ?>
                                                <?= h($item['item_event_name']) ?>
                                                <span class="text-secondary">(<?= date('Y-m-d', strtotime($item['item_event_date'])) ?>)</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="padding: var(--space-2xs) var(--space-sm); text-align: right; white-space: nowrap; font-weight: 600;">
                                            <?= number_format($item['total_price'], 0) ?> kr
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </table>
                                <?php if ($order['discount'] > 0): ?>
                                <div style="margin-top: var(--space-xs); padding-top: var(--space-xs); border-top: 1px solid var(--color-border);">
                                    <span class="text-success text-sm">Rabatt: -<?= number_format($order['discount'], 0) ?> kr</span>
                                </div>
                                <?php endif; ?>
                                <?php else: ?>
                                <span class="text-secondary text-sm">Inga orderrader registrerade</span>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Confirm Payment Modal -->
<div id="confirm-modal" class="admin-modal hidden">
    <div class="admin-modal-overlay" onclick="closeModal('confirm-modal')"></div>
    <div class="admin-modal-content" style="max-width: 500px;">
        <div class="admin-modal-header">
            <h2>Bekräfta betalning</h2>
            <button type="button" class="admin-modal-close" onclick="closeModal('confirm-modal')">
                <i data-lucide="x"></i>
            </button>
        </div>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="confirm_payment">
            <input type="hidden" name="order_id" id="confirm-order-id">

            <div class="admin-modal-body">
                <p class="mb-md">
                    Bekräfta att betalning mottagits för order
                    <strong id="confirm-order-number"></strong>
                </p>

                <div class="admin-form-group">
                    <label class="admin-form-label">Betalningsreferens (valfritt)</label>
                    <input type="text" name="payment_reference" class="admin-form-input"
                           placeholder="T.ex. transaktions-ID">
                    <small class="text-secondary">Ange referens från betalningen</small>
                </div>
            </div>

            <div class="admin-modal-footer">
                <button type="button" class="btn-admin btn-admin-secondary" onclick="closeModal('confirm-modal')">Avbryt</button>
                <button type="submit" class="btn-admin" style="background: var(--color-success); color: white;">
                    <i data-lucide="check"></i>
                    Bekräfta betalning
                </button>
            </div>
        </form>
    </div>
</div>

<style>
/* Modal styles */
.admin-modal { position: fixed; top: 0; left: 0; right: 0; bottom: 0; z-index: 1000; display: flex; align-items: center; justify-content: center; }
.admin-modal.hidden { display: none; }
.admin-modal-overlay { position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0, 0, 0, 0.5); }
.admin-modal-content { position: relative; background: var(--color-bg-card, white); border-radius: var(--radius-lg); box-shadow: var(--shadow-xl); width: 90%; max-width: 500px; max-height: 90vh; overflow: hidden; display: flex; flex-direction: column; }
.admin-modal-header { display: flex; align-items: center; justify-content: space-between; padding: var(--space-lg); border-bottom: 1px solid var(--color-border); }
.admin-modal-header h2 { margin: 0; font-size: var(--text-xl); }
.admin-modal-close { background: none; border: none; padding: var(--space-xs); cursor: pointer; color: var(--color-text-secondary); border-radius: var(--radius-sm); }
.admin-modal-close:hover { background: var(--color-bg-tertiary); color: var(--color-text); }
.admin-modal-close svg, .admin-modal-close i { width: 20px; height: 20px; }
.admin-modal-body { padding: var(--space-lg); overflow-y: auto; flex: 1; }
.admin-modal-footer { display: flex; justify-content: flex-end; gap: var(--space-sm); padding: var(--space-lg); border-top: 1px solid var(--color-border); }
.admin-badge-warning { background: rgba(234, 179, 8, 0.2); color: #ca8a04; }
.admin-badge-error { background: rgba(239, 68, 68, 0.2); color: #dc2626; }
.admin-badge-info { background: rgba(56, 189, 248, 0.2); color: #0284c7; }
.admin-badge-accent { background: rgba(55, 212, 214, 0.2); color: var(--color-accent); font-weight: 600; }

/* Action buttons */
.orders-actions { display: flex; gap: var(--space-xs); }
.orders-btn-confirm { background: var(--color-success); color: white; }
.orders-btn-confirm:hover { opacity: 0.9; }
.orders-btn-cancel { background: transparent; border: 1px solid var(--color-border); color: var(--color-text-secondary); }
.orders-btn-cancel:hover { background: var(--color-error); color: white; border-color: var(--color-error); }
.orders-btn-delete { background: transparent; border: 1px solid var(--color-border); color: var(--color-text-muted); }
.orders-btn-delete:hover { background: var(--color-error); color: white; border-color: var(--color-error); }
.order-row:hover { background: var(--color-bg-hover); }
.order-detail-row td { border-top: none !important; }
.orders-btn-label { display: none; }

/* Mobile responsive orders table */
@media (max-width: 767px) {
    .orders-btn-label { display: inline; }

    .admin-table thead { display: none; }

    .admin-table tbody tr {
        display: block;
        background: var(--color-bg-surface);
        border: 1px solid var(--color-border);
        border-radius: var(--radius-md);
        margin-bottom: var(--space-md);
        padding: var(--space-md);
    }

    .admin-table tbody td {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: var(--space-xs) 0;
        border: none;
        text-align: right;
    }

    .admin-table tbody td::before {
        content: attr(data-label);
        font-weight: 600;
        color: var(--color-text-secondary);
        font-size: var(--text-sm);
        text-align: left;
        flex-shrink: 0;
        margin-right: var(--space-md);
    }

    /* Hide ref column on mobile to save space */
    .orders-swish-col { display: none; }

    .order-detail-row td { display: block; padding: 0 !important; }
    .order-detail-row td::before { display: none !important; }

    /* Actions cell: full-width buttons */
    .orders-actions-cell {
        border-top: 1px solid var(--color-border) !important;
        margin-top: var(--space-sm);
        padding-top: var(--space-md) !important;
    }

    .orders-actions-cell::before { display: none !important; }

    .orders-actions {
        width: 100%;
        justify-content: stretch;
        gap: var(--space-sm);
    }

    .orders-actions .btn-admin {
        flex: 1;
        justify-content: center;
        padding: var(--space-sm) var(--space-md);
        font-size: var(--text-sm);
    }

    .orders-actions form.inline { flex: 1; }
    .orders-actions form.inline .btn-admin { width: 100%; justify-content: center; }

    /* Filter form stacking */
    .admin-card .flex.flex-wrap { flex-direction: column; }
    .admin-card .flex.flex-wrap .admin-form-group { width: 100%; }
    .admin-card .flex.flex-wrap .min-w-200 { min-width: 0; }
}
</style>

<script>
function openConfirmModal(orderId, orderNumber) {
    document.getElementById('confirm-order-id').value = orderId;
    document.getElementById('confirm-order-number').textContent = orderNumber;
    document.getElementById('confirm-modal').classList.remove('hidden');
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.add('hidden');
}

function toggleOrderDetail(orderId) {
    const row = document.getElementById('detail-' + orderId);
    if (row) {
        row.style.display = row.style.display === 'none' ? 'table-row' : 'none';
    }
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.admin-modal').forEach(m => m.classList.add('hidden'));
    }
});
</script>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>

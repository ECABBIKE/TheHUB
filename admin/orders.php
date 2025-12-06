<?php
/**
 * Order Management - View and confirm payments
 * Uses Economy Tab System (Global context)
 *
 * Accessible by:
 * - Super Admin: Can manage all orders
 * - Promotor: Can manage orders for their events
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/payment.php';

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
        } catch (Exception $e) {
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
    }
}

// Filter parameters
$filterStatus = $_GET['status'] ?? 'pending';
$filterEvent = isset($_GET['event_id']) ? intval($_GET['event_id']) : 0;
$search = trim($_GET['search'] ?? '');

// Build query
$whereConditions = [];
$params = [];

// Status filter
if ($filterStatus && $filterStatus !== 'all') {
    $whereConditions[] = "o.payment_status = ?";
    $params[] = $filterStatus;
}

// Event filter
if ($filterEvent) {
    $whereConditions[] = "o.event_id = ?";
    $params[] = $filterEvent;
}

// Search
if ($search) {
    $whereConditions[] = "(o.order_number LIKE ? OR o.customer_name LIKE ? OR o.swish_message LIKE ?)";
    $searchTerm = "%{$search}%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

// Promotor restriction - only their events
if (!$isSuperAdmin) {
    $whereConditions[] = "o.event_id IN (SELECT event_id FROM promotor_events WHERE user_id = ?)";
    $params[] = $currentAdmin['id'];
}

$whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

// Get orders
$orders = $db->getAll("
    SELECT o.*, e.name as event_name, e.date as event_date,
           r.firstname, r.lastname
    FROM orders o
    LEFT JOIN events e ON o.event_id = e.id
    LEFT JOIN riders r ON o.rider_id = r.id
    {$whereClause}
    ORDER BY o.created_at DESC
    LIMIT 200
", $params);

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

// Page settings for economy layout
$economy_page_title = 'Ordrar';
include __DIR__ . '/components/economy-layout.php';
?>

        <!-- Message -->
        <?php if ($message): ?>
        <div class="alert alert-<?= h($messageType) ?> mb-lg">
            <i data-lucide="<?= $messageType === 'success' ? 'check-circle' : 'alert-circle' ?>"></i>
            <?= h($message) ?>
        </div>
        <?php endif; ?>

        <!-- Stats -->
        <div class="grid grid-3 gap-md mb-lg">
            <div class="card">
                <div class="card-body text-center">
                    <div class="text-3xl font-bold text-warning"><?= $stats['pending_count'] ?? 0 ?></div>
                    <div class="text-sm text-secondary">Väntar på betalning</div>
                </div>
            </div>
            <div class="card">
                <div class="card-body text-center">
                    <div class="text-3xl font-bold text-success"><?= $stats['paid_count'] ?? 0 ?></div>
                    <div class="text-sm text-secondary">Betalda ordrar</div>
                </div>
            </div>
            <div class="card">
                <div class="card-body text-center">
                    <div class="text-3xl font-bold"><?= number_format($stats['total_revenue'] ?? 0, 0, ',', ' ') ?> kr</div>
                    <div class="text-sm text-secondary">Totalt inbetalt</div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="card mb-lg">
            <div class="card-body">
                <form method="GET" class="flex flex-wrap gap-md items-end">
                    <div class="form-group mb-0">
                        <label class="label">Status</label>
                        <select name="status" class="input" onchange="this.form.submit()">
                            <option value="pending" <?= $filterStatus === 'pending' ? 'selected' : '' ?>>Väntar</option>
                            <option value="paid" <?= $filterStatus === 'paid' ? 'selected' : '' ?>>Betalda</option>
                            <option value="cancelled" <?= $filterStatus === 'cancelled' ? 'selected' : '' ?>>Avbrutna</option>
                            <option value="all" <?= $filterStatus === 'all' ? 'selected' : '' ?>>Alla</option>
                        </select>
                    </div>

                    <?php if (!empty($events)): ?>
                    <div class="form-group mb-0">
                        <label class="label">Event</label>
                        <select name="event_id" class="input" onchange="this.form.submit()">
                            <option value="">Alla event</option>
                            <?php foreach ($events as $event): ?>
                            <option value="<?= $event['id'] ?>" <?= $filterEvent == $event['id'] ? 'selected' : '' ?>>
                                <?= h($event['name']) ?> (<?= date('Y-m-d', strtotime($event['date'])) ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>

                    <div class="form-group mb-0">
                        <label class="label">Sök</label>
                        <input type="text" name="search" class="input"
                               value="<?= h($search) ?>"
                               placeholder="Ordernummer, namn, Swish-ref...">
                    </div>

                    <button type="submit" class="btn btn--primary">
                        <i data-lucide="search"></i>
                        Sök
                    </button>
                </form>
            </div>
        </div>

        <!-- Orders list -->
        <div class="card">
            <div class="card-body gs-p-0">
                <?php if (empty($orders)): ?>
                <div class="text-center text-secondary py-xl">
                    <i data-lucide="inbox" class="icon-xl mb-md"></i>
                    <p>Inga ordrar hittades</p>
                </div>
                <?php else: ?>
                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Order</th>
                                <th>Kund</th>
                                <th>Event</th>
                                <th>Belopp</th>
                                <th>Swish-ref</th>
                                <th>Status</th>
                                <th>Skapad</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($orders as $order): ?>
                            <tr>
                                <td>
                                    <code class="font-medium"><?= h($order['order_number']) ?></code>
                                </td>
                                <td>
                                    <div class="font-medium">
                                        <?= h($order['firstname'] . ' ' . $order['lastname']) ?>
                                    </div>
                                    <div class="text-xs text-secondary"><?= h($order['customer_email']) ?></div>
                                </td>
                                <td>
                                    <?php if ($order['event_name']): ?>
                                    <div><?= h($order['event_name']) ?></div>
                                    <div class="text-xs text-secondary"><?= date('Y-m-d', strtotime($order['event_date'])) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="font-medium">
                                    <?= number_format($order['total_amount'], 0) ?> kr
                                </td>
                                <td>
                                    <?php if ($order['swish_message']): ?>
                                    <code><?= h($order['swish_message']) ?></code>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    $statusClass = [
                                        'pending' => 'badge-warning',
                                        'paid' => 'badge-success',
                                        'cancelled' => 'badge-secondary',
                                        'refunded' => 'badge-error',
                                        'failed' => 'badge-error'
                                    ][$order['payment_status']] ?? 'badge-secondary';

                                    $statusText = [
                                        'pending' => 'Väntar',
                                        'paid' => 'Betald',
                                        'cancelled' => 'Avbruten',
                                        'refunded' => 'Återbetald',
                                        'failed' => 'Misslyckad'
                                    ][$order['payment_status']] ?? $order['payment_status'];
                                    ?>
                                    <span class="badge <?= $statusClass ?>"><?= $statusText ?></span>
                                </td>
                                <td class="text-sm text-secondary">
                                    <?= date('Y-m-d H:i', strtotime($order['created_at'])) ?>
                                </td>
                                <td class="text-right">
                                    <?php if ($order['payment_status'] === 'pending'): ?>
                                    <div class="flex gap-xs justify-end">
                                        <button type="button" class="btn btn--sm btn--success"
                                                onclick="openConfirmModal(<?= $order['id'] ?>, '<?= h($order['order_number']) ?>', '<?= h($order['swish_message']) ?>')">
                                            <i data-lucide="check"></i>
                                            Bekräfta
                                        </button>
                                        <form method="POST" style="display: inline;">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="cancel_order">
                                            <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                                            <button type="submit" class="btn btn--sm btn--secondary"
                                                    onclick="return confirm('Avbryt denna order?')">
                                                <i data-lucide="x"></i>
                                            </button>
                                        </form>
                                    </div>
                                    <?php elseif ($order['payment_status'] === 'paid'): ?>
                                    <span class="text-xs text-secondary">
                                        Betald <?= $order['paid_at'] ? date('Y-m-d', strtotime($order['paid_at'])) : '' ?>
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

<!-- Confirm Payment Modal -->
<div id="confirm-modal" class="modal hidden">
    <div class="modal-backdrop" onclick="closeModal('confirm-modal')"></div>
    <div class="modal-content">
        <div class="modal-header">
            <h3>Bekräfta betalning</h3>
            <button type="button" class="modal-close" onclick="closeModal('confirm-modal')">&times;</button>
        </div>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="confirm_payment">
            <input type="hidden" name="order_id" id="confirm-order-id">

            <div class="modal-body">
                <p class="mb-md">
                    Bekräfta att betalning mottagits för order
                    <strong id="confirm-order-number"></strong>
                </p>

                <div class="p-md bg-muted rounded-md mb-md">
                    <div class="text-sm text-secondary">Förväntat Swish-meddelande:</div>
                    <code id="confirm-swish-ref" class="text-lg"></code>
                </div>

                <div class="form-group">
                    <label class="label">Betalningsreferens (valfritt)</label>
                    <input type="text" name="payment_reference" class="input"
                           placeholder="T.ex. Swish-transaktions-ID">
                    <small class="text-secondary">Ange referens från Swish eller kontoutdrag</small>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn--secondary" onclick="closeModal('confirm-modal')">Avbryt</button>
                <button type="submit" class="btn btn--success">
                    <i data-lucide="check"></i>
                    Bekräfta betalning
                </button>
            </div>
        </form>
    </div>
</div>

<style>
.modal {
    position: fixed;
    inset: 0;
    z-index: 1000;
    display: flex;
    align-items: center;
    justify-content: center;
}
.modal.hidden {
    display: none;
}
.modal-backdrop {
    position: absolute;
    inset: 0;
    background: rgba(0, 0, 0, 0.5);
}
.modal-content {
    position: relative;
    background: var(--color-bg-surface);
    border-radius: var(--radius-lg);
    width: 100%;
    max-width: 500px;
    margin: var(--space-md);
}
.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: var(--space-md) var(--space-lg);
    border-bottom: 1px solid var(--color-border);
}
.modal-close {
    background: none;
    border: none;
    font-size: 1.5rem;
    cursor: pointer;
    color: var(--color-text-secondary);
}
.modal-body {
    padding: var(--space-lg);
}
.modal-footer {
    display: flex;
    justify-content: flex-end;
    gap: var(--space-sm);
    padding: var(--space-md) var(--space-lg);
    border-top: 1px solid var(--color-border);
}
.bg-muted {
    background: var(--color-bg-card);
}
.btn--success {
    background: var(--color-success, #22c55e);
    color: white;
    border: none;
}
.btn--success:hover {
    background: #16a34a;
}
.badge-warning {
    background: rgba(234, 179, 8, 0.2);
    color: #ca8a04;
}
.badge-success {
    background: rgba(34, 197, 94, 0.2);
    color: #16a34a;
}
.badge-error {
    background: rgba(239, 68, 68, 0.2);
    color: #dc2626;
}
</style>

<script>
function openConfirmModal(orderId, orderNumber, swishRef) {
    document.getElementById('confirm-order-id').value = orderId;
    document.getElementById('confirm-order-number').textContent = orderNumber;
    document.getElementById('confirm-swish-ref').textContent = swishRef;
    document.getElementById('confirm-modal').classList.remove('hidden');
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.add('hidden');
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal').forEach(m => m.classList.add('hidden'));
    }
});
</script>

<?php include __DIR__ . '/components/economy-layout-footer.php'; ?>

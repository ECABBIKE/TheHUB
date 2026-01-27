<?php
/**
 * Admin Refund Requests Management
 * Review and process ticket refund requests
 */

require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();

// Initialize message
$message = '';
$messageType = 'info';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();

    $action = $_POST['action'] ?? '';
    $requestId = intval($_POST['request_id'] ?? 0);

    if ($requestId > 0) {
        $request = $db->getRow("
            SELECT err.*, et.ticket_number, et.paid_price
            FROM event_refund_requests err
            JOIN event_tickets et ON err.ticket_id = et.id
            WHERE err.id = ?
        ", [$requestId]);

        if ($request) {
            if ($action === 'approve') {
                // Approve refund
                $db->execute("
                    UPDATE event_refund_requests
                    SET status = 'approved', processed_at = NOW(), admin_notes = ?
                    WHERE id = ?
                ", [$_POST['admin_notes'] ?? '', $requestId]);

                // Update ticket status
                $db->execute("
                    UPDATE event_tickets
                    SET status = 'refunded'
                    WHERE id = ?
                ", [$request['ticket_id']]);

                $message = 'Återbetalning godkänd för ' . $request['ticket_number'];
                $messageType = 'success';

            } elseif ($action === 'deny') {
                // Deny refund
                $db->execute("
                    UPDATE event_refund_requests
                    SET status = 'denied', processed_at = NOW(), admin_notes = ?
                    WHERE id = ?
                ", [$_POST['admin_notes'] ?? '', $requestId]);

                $message = 'Återbetalning nekad för ' . $request['ticket_number'];
                $messageType = 'warning';
            }
        }
    }
}

// Fetch pending requests
$pendingRequests = $db->getAll("
    SELECT
        err.*,
        et.ticket_number,
        et.paid_price,
        e.name as event_name,
        e.date as event_date,
        r.firstname,
        r.lastname,
        r.email as rider_email
    FROM event_refund_requests err
    JOIN event_tickets et ON err.ticket_id = et.id
    JOIN events e ON et.event_id = e.id
    JOIN riders r ON err.rider_id = r.id
    WHERE err.status = 'pending'
    ORDER BY err.created_at ASC
");

// Fetch processed requests (last 50)
$processedRequests = $db->getAll("
    SELECT
        err.*,
        et.ticket_number,
        et.paid_price,
        e.name as event_name,
        r.firstname,
        r.lastname
    FROM event_refund_requests err
    JOIN event_tickets et ON err.ticket_id = et.id
    JOIN events e ON et.event_id = e.id
    JOIN riders r ON err.rider_id = r.id
    WHERE err.status IN ('approved', 'denied')
    ORDER BY err.processed_at DESC
    LIMIT 50
");

// Page config
$page_title = 'Återbetalningar';
$page_group = 'economy';
$breadcrumbs = [
    ['label' => 'Ekonomi', 'url' => '/admin/ekonomi'],
    ['label' => 'Återbetalningar']
];
include __DIR__ . '/components/unified-layout.php';
?>

<?php if ($message): ?>
<div class="alert alert-<?= $messageType === 'success' ? 'success' : ($messageType === 'warning' ? 'warning' : 'info') ?> mb-lg">
    <?= h($message) ?>
</div>
<?php endif; ?>

<!-- Pending Requests -->
<div class="admin-card mb-lg">
    <div class="admin-card-header">
        <h2>
            <i data-lucide="clock"></i>
            Väntande begäran
            <?php if (count($pendingRequests) > 0): ?>
            <span class="badge badge-warning" style="margin-left: var(--space-sm);"><?= count($pendingRequests) ?></span>
            <?php endif; ?>
        </h2>
    </div>
    <div class="admin-card-body">
        <?php if (empty($pendingRequests)): ?>
        <div class="text-center" style="padding: var(--space-xl); color: var(--color-text-secondary);">
            <i data-lucide="check-circle" style="width: 48px; height: 48px; color: var(--color-success); margin-bottom: var(--space-md);"></i>
            <p>Inga väntande begäran</p>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Datum</th>
                        <th>Deltagare</th>
                        <th>Event</th>
                        <th>Biljett</th>
                        <th>Belopp</th>
                        <th>Anledning</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pendingRequests as $request): ?>
                    <?php
                    $eventDate = new DateTime($request['event_date']);
                    $daysUntilEvent = (new DateTime())->diff($eventDate)->days;
                    $isPastEvent = $eventDate < new DateTime();
                    ?>
                    <tr>
                        <td>
                            <span class="text-sm">
                                <?= date('d M', strtotime($request['created_at'])) ?>
                            </span>
                        </td>
                        <td>
                            <strong><?= h($request['firstname'] . ' ' . $request['lastname']) ?></strong>
                            <div class="text-xs text-secondary">
                                <?= h($request['rider_email']) ?>
                            </div>
                        </td>
                        <td>
                            <?= h($request['event_name']) ?>
                            <div class="text-xs text-secondary">
                                <?= date('d M Y', strtotime($request['event_date'])) ?>
                                <?php if ($isPastEvent): ?>
                                <span class="badge badge-danger" style="font-size: 0.65rem;">Passerat</span>
                                <?php else: ?>
                                (<?= $daysUntilEvent ?> dagar kvar)
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <code style="background: var(--color-bg-hover); padding: 2px 6px; border-radius: 4px;">
                                <?= h($request['ticket_number']) ?>
                            </code>
                        </td>
                        <td>
                            <strong><?= number_format($request['refund_amount'], 0) ?> kr</strong>
                            <div class="text-xs text-secondary">
                                av <?= number_format($request['paid_price'], 0) ?> kr
                            </div>
                        </td>
                        <td>
                            <?php if ($request['reason']): ?>
                            <span class="text-sm" title="<?= h($request['reason']) ?>">
                                <?= h(substr($request['reason'], 0, 50)) ?>
                                <?= strlen($request['reason']) > 50 ? '...' : '' ?>
                            </span>
                            <?php else: ?>
                            <span class="text-secondary text-sm">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-right">
                            <div style="display: flex; gap: var(--space-xs); justify-content: flex-end;">
                                <form method="POST" style="display: inline;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="approve">
                                    <input type="hidden" name="request_id" value="<?= $request['id'] ?>">
                                    <button type="submit"
                                            class="btn-admin btn-admin-success btn-admin-sm"
                                            onclick="return confirm('Godkänna återbetalning på <?= $request['refund_amount'] ?> kr?')"
                                            title="Godkänn">
                                        <i data-lucide="check"></i>
                                    </button>
                                </form>
                                <form method="POST" style="display: inline;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="deny">
                                    <input type="hidden" name="request_id" value="<?= $request['id'] ?>">
                                    <button type="submit"
                                            class="btn-admin btn-admin-danger btn-admin-sm"
                                            onclick="return confirm('Neka återbetalning?')"
                                            title="Neka">
                                        <i data-lucide="x"></i>
                                    </button>
                                </form>
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

<!-- Processed Requests -->
<div class="admin-card">
    <div class="admin-card-header">
        <h2>
            <i data-lucide="history"></i>
            Behandlade begäran
        </h2>
    </div>
    <div class="admin-card-body">
        <?php if (empty($processedRequests)): ?>
        <p class="text-secondary">Inga behandlade begäran ännu.</p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Datum</th>
                        <th>Deltagare</th>
                        <th>Event</th>
                        <th>Belopp</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($processedRequests as $request): ?>
                    <tr>
                        <td>
                            <?= date('d M Y', strtotime($request['processed_at'])) ?>
                        </td>
                        <td>
                            <?= h($request['firstname'] . ' ' . $request['lastname']) ?>
                        </td>
                        <td>
                            <?= h($request['event_name']) ?>
                        </td>
                        <td>
                            <?= number_format($request['refund_amount'], 0) ?> kr
                        </td>
                        <td>
                            <?php if ($request['status'] === 'approved'): ?>
                            <span class="badge badge-success">Godkänd</span>
                            <?php else: ?>
                            <span class="badge badge-danger">Nekad</span>
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

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>

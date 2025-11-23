<?php
/**
 * My Tickets Page
 * Shows rider's purchased tickets and allows refund requests
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/rider-auth.php';

// Require login
require_rider();

$db = getDB();
$riderId = $_SESSION['rider_id'];

// Get rider info
$rider = get_current_rider();

// Initialize message
$message = '';
$messageType = 'info';

// Handle refund request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'request_refund') {
        $ticketId = intval($_POST['ticket_id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');

        if ($ticketId <= 0) {
            $message = 'Ogiltig biljett';
            $messageType = 'error';
        } else {
            // Get ticket and verify ownership
            $ticket = $db->getRow("
                SELECT et.*, e.date as event_date, e.name as event_name
                FROM event_tickets et
                JOIN events e ON et.event_id = e.id
                WHERE et.id = ? AND et.rider_id = ?
            ", [$ticketId, $riderId]);

            if (!$ticket) {
                $message = 'Biljett hittades inte';
                $messageType = 'error';
            } elseif ($ticket['status'] !== 'sold') {
                $message = 'Endast sålda biljetter kan återbetalas';
                $messageType = 'error';
            } else {
                // Calculate refund eligibility
                $today = new DateTime();
                $eventDate = new DateTime($ticket['event_date']);
                $daysBeforeEvent = $eventDate->diff($today)->days;

                if ($today >= $eventDate) {
                    $message = 'Eventet har redan passerat';
                    $messageType = 'error';
                } elseif ($daysBeforeEvent < 7) {
                    $message = 'Återbetalning kan inte begäras mindre än 7 dagar före eventet';
                    $messageType = 'error';
                } else {
                    // Calculate refund amount (90% if >= 20 days before)
                    $refundPercent = $daysBeforeEvent >= 20 ? 90 : 0;
                    $refundAmount = $ticket['paid_price'] * $refundPercent / 100;

                    // Check if request already exists
                    $existingRequest = $db->getRow("
                        SELECT id FROM event_refund_requests
                        WHERE ticket_id = ? AND status = 'pending'
                    ", [$ticketId]);

                    if ($existingRequest) {
                        $message = 'En återbetalningsbegäran finns redan för denna biljett';
                        $messageType = 'warning';
                    } else {
                        // Create refund request
                        $db->execute("
                            INSERT INTO event_refund_requests
                            (ticket_id, rider_id, reason, refund_amount, status, created_at)
                            VALUES (?, ?, ?, ?, 'pending', NOW())
                        ", [$ticketId, $riderId, $reason, $refundAmount]);

                        $message = 'Återbetalningsbegäran skickad! Du får svar inom 3-5 arbetsdagar.';
                        $messageType = 'success';
                    }
                }
            }
        }
    }
}

// Fetch rider's tickets grouped by event
$tickets = $db->getAll("
    SELECT
        et.id,
        et.ticket_number,
        et.status,
        et.paid_price,
        et.created_at as purchased_at,
        e.id as event_id,
        e.name as event_name,
        e.date as event_date,
        e.location as event_location,
        c.display_name as class_name,
        (SELECT COUNT(*) FROM event_refund_requests err
         WHERE err.ticket_id = et.id AND err.status = 'pending') as has_pending_refund
    FROM event_tickets et
    JOIN events e ON et.event_id = e.id
    LEFT JOIN classes c ON et.class_id = c.id
    WHERE et.rider_id = ?
    ORDER BY e.date DESC, et.created_at DESC
", [$riderId]);

// Group tickets by event
$ticketsByEvent = [];
foreach ($tickets as $ticket) {
    $eventId = $ticket['event_id'];
    if (!isset($ticketsByEvent[$eventId])) {
        $ticketsByEvent[$eventId] = [
            'event_name' => $ticket['event_name'],
            'event_date' => $ticket['event_date'],
            'event_location' => $ticket['event_location'],
            'tickets' => []
        ];
    }
    $ticketsByEvent[$eventId]['tickets'][] = $ticket;
}

// Separate upcoming and past events
$upcomingEvents = [];
$pastEvents = [];
$today = date('Y-m-d');

foreach ($ticketsByEvent as $eventId => $eventData) {
    if ($eventData['event_date'] >= $today) {
        $upcomingEvents[$eventId] = $eventData;
    } else {
        $pastEvents[$eventId] = $eventData;
    }
}

$pageTitle = 'Mina biljetter';
$pageType = 'public';
include __DIR__ . '/includes/layout-header.php';
?>

<main class="gs-main-content">
    <div class="gs-container">
        <!-- Header -->
        <div class="gs-card gs-mb-lg">
            <div class="gs-card-content">
                <h1 class="gs-h2 gs-text-primary gs-mb-sm">
                    <i data-lucide="ticket" class="gs-icon-lg"></i>
                    Mina biljetter
                </h1>
                <p class="gs-text-secondary">
                    Hej <?= h($rider['firstname']) ?>! Här ser du alla dina köpta biljetter.
                </p>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="gs-alert gs-alert-<?= $messageType ?> gs-mb-lg">
                <?= h($message) ?>
            </div>
        <?php endif; ?>

        <?php if (empty($tickets)): ?>
            <!-- No tickets -->
            <div class="gs-card gs-empty-state">
                <i data-lucide="ticket" class="gs-empty-icon"></i>
                <h3 class="gs-h4 gs-mb-sm">Inga biljetter ännu</h3>
                <p class="gs-text-secondary gs-mb-md">
                    Du har inte köpt några biljetter ännu.
                </p>
                <a href="/events.php" class="gs-btn gs-btn-primary">
                    <i data-lucide="calendar" class="gs-icon-sm"></i>
                    Se kommande events
                </a>
            </div>
        <?php else: ?>

            <!-- Upcoming Events -->
            <?php if (!empty($upcomingEvents)): ?>
                <h2 class="gs-h4 gs-text-primary gs-mb-md">
                    <i data-lucide="calendar-check" class="gs-icon-md"></i>
                    Kommande events
                </h2>

                <?php foreach ($upcomingEvents as $eventId => $eventData): ?>
                    <div class="gs-card gs-mb-lg">
                        <div class="gs-card-header">
                            <div class="gs-flex gs-justify-between gs-items-start">
                                <div>
                                    <h3 class="gs-h5 gs-text-primary">
                                        <?= h($eventData['event_name']) ?>
                                    </h3>
                                    <div class="gs-flex gs-gap-md gs-text-secondary gs-text-sm gs-mt-xs">
                                        <span>
                                            <i data-lucide="calendar" class="gs-icon-xs"></i>
                                            <?= date('d M Y', strtotime($eventData['event_date'])) ?>
                                        </span>
                                        <?php if ($eventData['event_location']): ?>
                                            <span>
                                                <i data-lucide="map-pin" class="gs-icon-xs"></i>
                                                <?= h($eventData['event_location']) ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <a href="/event-results.php?id=<?= $eventId ?>"
                                   class="gs-btn gs-btn-outline gs-btn-sm">
                                    Visa event
                                </a>
                            </div>
                        </div>
                        <div class="gs-card-content">
                            <div class="gs-table-responsive">
                                <table class="gs-table">
                                    <thead>
                                        <tr>
                                            <th>Biljettnr</th>
                                            <th>Klass</th>
                                            <th>Pris</th>
                                            <th>Status</th>
                                            <th>Åtgärder</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($eventData['tickets'] as $ticket): ?>
                                            <?php
                                            $eventDate = new DateTime($eventData['event_date']);
                                            $daysUntilEvent = (new DateTime())->diff($eventDate)->days;
                                            $canRefund = $ticket['status'] === 'sold' &&
                                                         $daysUntilEvent >= 7 &&
                                                         !$ticket['has_pending_refund'];
                                            ?>
                                            <tr>
                                                <td>
                                                    <strong><?= h($ticket['ticket_number']) ?></strong>
                                                </td>
                                                <td>
                                                    <?= h($ticket['class_name'] ?? 'Okänd') ?>
                                                </td>
                                                <td>
                                                    <?= number_format($ticket['paid_price'], 0) ?> kr
                                                </td>
                                                <td>
                                                    <?php if ($ticket['status'] === 'sold'): ?>
                                                        <?php if ($ticket['has_pending_refund']): ?>
                                                            <span class="gs-badge gs-badge-warning">Återbetalning begärd</span>
                                                        <?php else: ?>
                                                            <span class="gs-badge gs-badge-success">Bekräftad</span>
                                                        <?php endif; ?>
                                                    <?php elseif ($ticket['status'] === 'refunded'): ?>
                                                        <span class="gs-badge gs-badge-secondary">Återbetald</span>
                                                    <?php else: ?>
                                                        <span class="gs-badge gs-badge-secondary"><?= h($ticket['status']) ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($canRefund): ?>
                                                        <button type="button"
                                                                class="gs-btn gs-btn-outline gs-btn-sm"
                                                                onclick="openRefundModal(<?= $ticket['id'] ?>, '<?= h($ticket['ticket_number']) ?>', <?= $ticket['paid_price'] ?>, <?= $daysUntilEvent ?>)">
                                                            <i data-lucide="rotate-ccw" class="gs-icon-xs"></i>
                                                            Begär återbetalning
                                                        </button>
                                                    <?php elseif ($ticket['has_pending_refund']): ?>
                                                        <span class="gs-text-secondary gs-text-sm">Väntar på svar</span>
                                                    <?php elseif ($daysUntilEvent < 7): ?>
                                                        <span class="gs-text-secondary gs-text-sm">
                                                            <i data-lucide="info" class="gs-icon-xs"></i>
                                                            Kan ej återbetalas
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <!-- Past Events -->
            <?php if (!empty($pastEvents)): ?>
                <h2 class="gs-h4 gs-text-secondary gs-mb-md gs-mt-xl">
                    <i data-lucide="history" class="gs-icon-md"></i>
                    Tidigare events
                </h2>

                <?php foreach ($pastEvents as $eventId => $eventData): ?>
                    <div class="gs-card gs-mb-md" style="opacity: 0.7;">
                        <div class="gs-card-content">
                            <div class="gs-flex gs-justify-between gs-items-center">
                                <div>
                                    <h3 class="gs-h5 gs-text-secondary">
                                        <?= h($eventData['event_name']) ?>
                                    </h3>
                                    <span class="gs-text-sm gs-text-secondary">
                                        <?= date('d M Y', strtotime($eventData['event_date'])) ?>
                                    </span>
                                </div>
                                <div class="gs-text-right">
                                    <span class="gs-badge gs-badge-secondary">
                                        <?= count($eventData['tickets']) ?> biljett(er)
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

        <?php endif; ?>
    </div>
</main>

<!-- Refund Modal -->
<div id="refundModal" class="gs-modal" style="display: none;">
    <div class="gs-modal-backdrop" onclick="closeRefundModal()"></div>
    <div class="gs-modal-content">
        <div class="gs-modal-header">
            <h3 class="gs-h4">Begär återbetalning</h3>
            <button type="button" class="gs-modal-close" onclick="closeRefundModal()">
                <i data-lucide="x"></i>
            </button>
        </div>
        <form method="POST">
            <div class="gs-modal-body">
                <input type="hidden" name="action" value="request_refund">
                <input type="hidden" name="ticket_id" id="refundTicketId">

                <p class="gs-mb-md">
                    Biljett: <strong id="refundTicketNumber"></strong>
                </p>

                <div class="gs-alert gs-alert-info gs-mb-md" id="refundInfo">
                    <!-- Filled by JS -->
                </div>

                <div class="gs-form-group">
                    <label class="gs-label">Anledning (valfritt)</label>
                    <textarea name="reason"
                              class="gs-textarea"
                              rows="3"
                              placeholder="Beskriv varför du vill återbetala..."></textarea>
                </div>
            </div>
            <div class="gs-modal-footer">
                <button type="button" class="gs-btn gs-btn-outline" onclick="closeRefundModal()">
                    Avbryt
                </button>
                <button type="submit" class="gs-btn gs-btn-primary">
                    Skicka begäran
                </button>
            </div>
        </form>
    </div>
</div>

<style>
.gs-modal {
    position: fixed;
    inset: 0;
    z-index: 1000;
    display: flex;
    align-items: center;
    justify-content: center;
}
.gs-modal-backdrop {
    position: absolute;
    inset: 0;
    background: rgba(0, 0, 0, 0.5);
}
.gs-modal-content {
    position: relative;
    background: var(--gs-bg-primary);
    border-radius: 0.75rem;
    max-width: 500px;
    width: 90%;
    max-height: 90vh;
    overflow-y: auto;
}
.gs-modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1rem 1.5rem;
    border-bottom: 1px solid var(--gs-border);
}
.gs-modal-close {
    background: none;
    border: none;
    cursor: pointer;
    padding: 0.5rem;
    color: var(--gs-text-secondary);
}
.gs-modal-body {
    padding: 1.5rem;
}
.gs-modal-footer {
    display: flex;
    gap: 0.75rem;
    justify-content: flex-end;
    padding: 1rem 1.5rem;
    border-top: 1px solid var(--gs-border);
}
</style>

<script>
function openRefundModal(ticketId, ticketNumber, paidPrice, daysUntilEvent) {
    document.getElementById('refundTicketId').value = ticketId;
    document.getElementById('refundTicketNumber').textContent = ticketNumber;

    let refundPercent = daysUntilEvent >= 20 ? 90 : 0;
    let refundAmount = Math.round(paidPrice * refundPercent / 100);

    let infoHtml = '';
    if (refundPercent > 0) {
        infoHtml = `<strong>Återbetalning: ${refundAmount} kr</strong> (${refundPercent}% av ${paidPrice} kr)<br>
                    <span class="gs-text-sm">Du har ${daysUntilEvent} dagar kvar till eventet.</span>`;
    } else {
        infoHtml = `<strong>Obs!</strong> Eftersom det är mindre än 20 dagar till eventet kan du inte få återbetalning.
                    Du kan fortfarande skicka en begäran som admin kan granska.`;
    }

    document.getElementById('refundInfo').innerHTML = infoHtml;
    document.getElementById('refundModal').style.display = 'flex';
}

function closeRefundModal() {
    document.getElementById('refundModal').style.display = 'none';
}

// Close modal on escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeRefundModal();
    }
});
</script>

<?php include __DIR__ . '/includes/layout-footer.php'; ?>

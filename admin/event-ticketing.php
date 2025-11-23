<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

/**
 * Consolidated Event Ticketing Management
 * All ticketing functions in one page with tabs
 */

require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();

// Get event ID
$eventId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($eventId <= 0) {
    $_SESSION['message'] = 'Välj ett event från ticketing-dashboarden';
    $_SESSION['messageType'] = 'warning';
    header('Location: /admin/ticketing.php');
    exit;
}

// Fetch event
$event = $db->getRow("SELECT * FROM events WHERE id = ?", [$eventId]);

if (!$event) {
    $_SESSION['message'] = 'Event hittades inte';
    $_SESSION['messageType'] = 'error';
    header('Location: /admin/ticketing.php');
    exit;
}

// Get active tab
$activeTab = $_GET['tab'] ?? 'settings';

// Initialize message
$message = '';
$messageType = 'info';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? '';

    // SETTINGS TAB ACTIONS
    if ($action === 'save_settings') {
        $enabled = isset($_POST['ticketing_enabled']) ? 1 : 0;
        $deadlineDays = intval($_POST['ticket_deadline_days'] ?? 7);
        $wooProductId = intval($_POST['woo_product_id'] ?? 0) ?: null;

        $db->execute("
            UPDATE events
            SET ticketing_enabled = ?, ticket_deadline_days = ?, woo_product_id = ?
            WHERE id = ?
        ", [$enabled, $deadlineDays, $wooProductId, $eventId]);

        $event = $db->getRow("SELECT * FROM events WHERE id = ?", [$eventId]);
        $message = 'Inställningar sparade';
        $messageType = 'success';
    }

    // PRICING TAB ACTIONS
    elseif ($action === 'save_pricing') {
        $classIds = $_POST['class_id'] ?? [];
        $basePrices = $_POST['base_price'] ?? [];
        $earlyBirdDiscounts = $_POST['early_bird_discount'] ?? [];
        $earlyBirdEndDates = $_POST['early_bird_end_date'] ?? [];

        $saved = 0;
        foreach ($classIds as $index => $classId) {
            $basePrice = floatval($basePrices[$index] ?? 0);
            $earlyBirdDiscount = floatval($earlyBirdDiscounts[$index] ?? 0);
            $earlyBirdEndDate = trim($earlyBirdEndDates[$index] ?? '');

            if ($basePrice > 0) {
                $existing = $db->getRow("SELECT id FROM event_pricing_rules WHERE event_id = ? AND class_id = ?", [$eventId, $classId]);

                if ($existing) {
                    $db->execute("UPDATE event_pricing_rules SET base_price = ?, early_bird_discount_percent = ?, early_bird_end_date = ?, updated_at = NOW() WHERE id = ?",
                        [$basePrice, $earlyBirdDiscount, $earlyBirdEndDate ?: null, $existing['id']]);
                } else {
                    $db->execute("INSERT INTO event_pricing_rules (event_id, class_id, base_price, early_bird_discount_percent, early_bird_end_date, created_at) VALUES (?, ?, ?, ?, ?, NOW())",
                        [$eventId, $classId, $basePrice, $earlyBirdDiscount, $earlyBirdEndDate ?: null]);
                }
                $saved++;
            } else {
                $db->execute("DELETE FROM event_pricing_rules WHERE event_id = ? AND class_id = ?", [$eventId, $classId]);
            }
        }
        $message = "Sparade $saved priser";
        $messageType = 'success';
        $activeTab = 'pricing';
    }

    // TICKETS TAB ACTIONS
    elseif ($action === 'generate_tickets') {
        $classId = intval($_POST['class_id'] ?? 0);
        $quantity = intval($_POST['quantity'] ?? 0);

        if ($classId > 0 && $quantity > 0) {
            $existingCount = $db->getValue("SELECT COUNT(*) FROM event_tickets WHERE event_id = ? AND class_id = ?", [$eventId, $classId]);
            $wooProductId = $event['woo_product_id'] ?? null;

            for ($i = 1; $i <= $quantity; $i++) {
                $ticketNum = $existingCount + $i;
                $ticketNumber = sprintf('E%d-C%d-%05d', $eventId, $classId, $ticketNum);
                $db->execute("INSERT INTO event_tickets (event_id, ticket_number, class_id, status, woo_product_id, created_at) VALUES (?, ?, ?, 'available', ?, NOW())",
                    [$eventId, $ticketNumber, $classId, $wooProductId]);
            }
            $message = "Skapade $quantity biljetter";
            $messageType = 'success';
        }
        $activeTab = 'tickets';
    }

    elseif ($action === 'delete_available') {
        $classId = intval($_POST['class_id'] ?? 0);
        if ($classId > 0) {
            $db->execute("DELETE FROM event_tickets WHERE event_id = ? AND class_id = ? AND status = 'available'", [$eventId, $classId]);
            $message = 'Raderade tillgängliga biljetter';
            $messageType = 'success';
        }
        $activeTab = 'tickets';
    }

    // REFUNDS TAB ACTIONS
    elseif ($action === 'approve_refund' || $action === 'deny_refund') {
        $requestId = intval($_POST['request_id'] ?? 0);
        $status = $action === 'approve_refund' ? 'approved' : 'denied';

        if ($requestId > 0) {
            $db->execute("UPDATE event_refund_requests SET status = ?, processed_at = NOW() WHERE id = ?", [$status, $requestId]);

            if ($status === 'approved') {
                $request = $db->getRow("SELECT ticket_id FROM event_refund_requests WHERE id = ?", [$requestId]);
                if ($request) {
                    $db->execute("UPDATE event_tickets SET status = 'refunded' WHERE id = ?", [$request['ticket_id']]);
                }
            }
            $message = $status === 'approved' ? 'Återbetalning godkänd' : 'Återbetalning nekad';
            $messageType = $status === 'approved' ? 'success' : 'warning';
        }
        $activeTab = 'refunds';
    }
}

// Fetch data for tabs
$classes = $db->getAll("SELECT id, name, display_name, sort_order FROM classes ORDER BY sort_order ASC");

$pricingRules = $db->getAll("SELECT * FROM event_pricing_rules WHERE event_id = ?", [$eventId]);
$rulesMap = [];
foreach ($pricingRules as $rule) {
    $rulesMap[$rule['class_id']] = $rule;
}

$ticketStats = $db->getAll("
    SELECT class_id,
        COUNT(*) as total,
        SUM(CASE WHEN status = 'available' THEN 1 ELSE 0 END) as available,
        SUM(CASE WHEN status = 'sold' THEN 1 ELSE 0 END) as sold
    FROM event_tickets WHERE event_id = ? GROUP BY class_id
", [$eventId]);
$statsMap = [];
foreach ($ticketStats as $stat) {
    $statsMap[$stat['class_id']] = $stat;
}

$totalStats = $db->getRow("
    SELECT COUNT(*) as total,
        SUM(CASE WHEN status = 'available' THEN 1 ELSE 0 END) as available,
        SUM(CASE WHEN status = 'sold' THEN 1 ELSE 0 END) as sold
    FROM event_tickets WHERE event_id = ?
", [$eventId]);

$refundRequests = $db->getAll("
    SELECT err.*, et.ticket_number, et.paid_price, r.firstname, r.lastname
    FROM event_refund_requests err
    JOIN event_tickets et ON err.ticket_id = et.id
    JOIN riders r ON err.rider_id = r.id
    WHERE et.event_id = ?
    ORDER BY err.created_at DESC
", [$eventId]);

$pendingRefunds = count(array_filter($refundRequests, fn($r) => $r['status'] === 'pending'));

// Calculate default early-bird date
$eventDate = new DateTime($event['date']);
$defaultEarlyBirdEnd = clone $eventDate;
$defaultEarlyBirdEnd->modify('-20 days');

$pageTitle = 'Ticketing - ' . $event['name'];
$pageType = 'admin';
include __DIR__ . '/../includes/layout-header.php';
?>

<main class="gs-main-content">
    <div class="gs-container">
        <!-- Header -->
        <div class="gs-card gs-mb-lg">
            <div class="gs-card-content">
                <div class="gs-flex gs-justify-between gs-items-start">
                    <div>
                        <div class="gs-mb-sm">
                            <a href="/admin/ticketing.php" class="gs-btn gs-btn-outline gs-btn-sm">
                                <i data-lucide="arrow-left" class="gs-icon-sm"></i>
                                Ticketing Dashboard
                            </a>
                        </div>
                        <h1 class="gs-h2 gs-text-primary gs-mb-xs">
                            <?= h($event['name']) ?>
                        </h1>
                        <p class="gs-text-secondary">
                            <?= date('d M Y', strtotime($event['date'])) ?>
                            <?php if ($event['location']): ?>
                                • <?= h($event['location']) ?>
                            <?php endif; ?>
                        </p>
                    </div>
                    <a href="/event-results.php?id=<?= $eventId ?>&tab=biljetter"
                       class="gs-btn gs-btn-outline"
                       target="_blank">
                        <i data-lucide="external-link" class="gs-icon-sm"></i>
                        Visa publik sida
                    </a>
                </div>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="gs-alert gs-alert-<?= $messageType ?> gs-mb-lg">
                <?= h($message) ?>
            </div>
        <?php endif; ?>

        <!-- Tab Navigation -->
        <div class="gs-tabs gs-mb-lg">
            <a href="?id=<?= $eventId ?>&tab=settings"
               class="gs-tab <?= $activeTab === 'settings' ? 'active' : '' ?>">
                <i data-lucide="settings" class="gs-icon-sm"></i>
                Inställningar
            </a>
            <a href="?id=<?= $eventId ?>&tab=pricing"
               class="gs-tab <?= $activeTab === 'pricing' ? 'active' : '' ?>">
                <i data-lucide="tag" class="gs-icon-sm"></i>
                Priser
                <?php if (count($pricingRules) > 0): ?>
                    <span class="gs-badge gs-badge-primary gs-badge-sm"><?= count($pricingRules) ?></span>
                <?php endif; ?>
            </a>
            <a href="?id=<?= $eventId ?>&tab=tickets"
               class="gs-tab <?= $activeTab === 'tickets' ? 'active' : '' ?>">
                <i data-lucide="ticket" class="gs-icon-sm"></i>
                Biljetter
                <?php if ($totalStats['total'] > 0): ?>
                    <span class="gs-badge gs-badge-secondary gs-badge-sm"><?= $totalStats['sold'] ?>/<?= $totalStats['total'] ?></span>
                <?php endif; ?>
            </a>
            <a href="?id=<?= $eventId ?>&tab=refunds"
               class="gs-tab <?= $activeTab === 'refunds' ? 'active' : '' ?>">
                <i data-lucide="rotate-ccw" class="gs-icon-sm"></i>
                Återbetalningar
                <?php if ($pendingRefunds > 0): ?>
                    <span class="gs-badge gs-badge-warning gs-badge-sm"><?= $pendingRefunds ?></span>
                <?php endif; ?>
            </a>
        </div>

        <!-- Tab Content -->
        <?php if ($activeTab === 'settings'): ?>
        <!-- SETTINGS TAB -->
        <div class="gs-card">
            <div class="gs-card-header">
                <h2 class="gs-h4">Ticketing-inställningar</h2>
            </div>
            <div class="gs-card-content">
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="save_settings">

                    <div class="gs-grid gs-grid-cols-1 gs-md-grid-cols-2 gs-gap-lg">
                        <div class="gs-form-group">
                            <label class="gs-checkbox">
                                <input type="checkbox" name="ticketing_enabled" value="1"
                                       <?= !empty($event['ticketing_enabled']) ? 'checked' : '' ?>>
                                <span>Aktivera biljettförsäljning</span>
                            </label>
                            <p class="gs-text-xs gs-text-secondary gs-mt-xs">
                                När aktiverat visas "Biljetter"-fliken på event-sidan
                            </p>
                        </div>

                        <div class="gs-form-group">
                            <label class="gs-label">WooCommerce Produkt-ID</label>
                            <input type="number" name="woo_product_id" class="gs-input"
                                   value="<?= h($event['woo_product_id'] ?? '') ?>"
                                   placeholder="T.ex. 1234">
                            <p class="gs-text-xs gs-text-secondary gs-mt-xs">
                                Produkt-ID från WooCommerce för köp-knappen
                            </p>
                        </div>

                        <div class="gs-form-group">
                            <label class="gs-label">Sista försäljningsdag (dagar före event)</label>
                            <input type="number" name="ticket_deadline_days" class="gs-input"
                                   value="<?= h($event['ticket_deadline_days'] ?? 7) ?>"
                                   min="0" max="365">
                            <p class="gs-text-xs gs-text-secondary gs-mt-xs">
                                Försäljningen stänger automatiskt detta antal dagar före
                            </p>
                        </div>
                    </div>

                    <div class="gs-mt-lg">
                        <button type="submit" class="gs-btn gs-btn-primary">
                            <i data-lucide="save" class="gs-icon-sm"></i>
                            Spara inställningar
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <?php elseif ($activeTab === 'pricing'): ?>
        <!-- PRICING TAB -->
        <div class="gs-card">
            <div class="gs-card-header">
                <h2 class="gs-h4">Priser per klass</h2>
            </div>
            <div class="gs-card-content">
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="save_pricing">

                    <div class="gs-table-responsive">
                        <table class="gs-table">
                            <thead>
                                <tr>
                                    <th>Klass</th>
                                    <th>Pris (kr)</th>
                                    <th>Early-bird %</th>
                                    <th>Early-bird t.o.m.</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($classes as $class): ?>
                                    <?php $rule = $rulesMap[$class['id']] ?? null; ?>
                                    <tr>
                                        <td>
                                            <input type="hidden" name="class_id[]" value="<?= $class['id'] ?>">
                                            <strong><?= h($class['display_name']) ?></strong>
                                        </td>
                                        <td>
                                            <input type="number" name="base_price[]" class="gs-input gs-input-sm"
                                                   value="<?= $rule ? h($rule['base_price']) : '' ?>"
                                                   min="0" step="10" style="width: 100px;">
                                        </td>
                                        <td>
                                            <input type="number" name="early_bird_discount[]" class="gs-input gs-input-sm"
                                                   value="<?= $rule ? h($rule['early_bird_discount_percent']) : '20' ?>"
                                                   min="0" max="100" style="width: 70px;">
                                        </td>
                                        <td>
                                            <input type="date" name="early_bird_end_date[]" class="gs-input gs-input-sm"
                                                   value="<?= $rule && $rule['early_bird_end_date'] ? h($rule['early_bird_end_date']) : $defaultEarlyBirdEnd->format('Y-m-d') ?>">
                                        </td>
                                        <td>
                                            <?php if ($rule && $rule['base_price'] > 0): ?>
                                                <span class="gs-badge gs-badge-success gs-badge-sm">Satt</span>
                                            <?php else: ?>
                                                <span class="gs-badge gs-badge-secondary gs-badge-sm">-</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="gs-mt-lg">
                        <button type="submit" class="gs-btn gs-btn-primary">
                            <i data-lucide="save" class="gs-icon-sm"></i>
                            Spara priser
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <?php elseif ($activeTab === 'tickets'): ?>
        <!-- TICKETS TAB -->
        <div class="gs-card gs-mb-lg">
            <div class="gs-card-header">
                <h2 class="gs-h4">Generera biljetter</h2>
            </div>
            <div class="gs-card-content">
                <form method="POST" class="gs-flex gs-gap-md gs-items-end gs-flex-wrap">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="generate_tickets">

                    <div class="gs-form-group">
                        <label class="gs-label">Klass</label>
                        <select name="class_id" class="gs-select" required>
                            <option value="">Välj...</option>
                            <?php foreach ($classes as $class): ?>
                                <?php $rule = $rulesMap[$class['id']] ?? null; ?>
                                <?php if ($rule && $rule['base_price'] > 0): ?>
                                    <option value="<?= $class['id'] ?>">
                                        <?= h($class['display_name']) ?> (<?= $rule['base_price'] ?> kr)
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="gs-form-group">
                        <label class="gs-label">Antal</label>
                        <input type="number" name="quantity" class="gs-input" value="50" min="1" max="500" required style="width: 100px;">
                    </div>

                    <button type="submit" class="gs-btn gs-btn-primary">
                        <i data-lucide="plus" class="gs-icon-sm"></i>
                        Generera
                    </button>
                </form>
            </div>
        </div>

        <div class="gs-card">
            <div class="gs-card-header">
                <h2 class="gs-h4">Biljetter per klass</h2>
            </div>
            <div class="gs-card-content">
                <div class="gs-table-responsive">
                    <table class="gs-table">
                        <thead>
                            <tr>
                                <th>Klass</th>
                                <th>Totalt</th>
                                <th>Tillgängliga</th>
                                <th>Sålda</th>
                                <th>Åtgärder</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($classes as $class): ?>
                                <?php
                                $stat = $statsMap[$class['id']] ?? null;
                                if (!$stat) continue;
                                ?>
                                <tr>
                                    <td><strong><?= h($class['display_name']) ?></strong></td>
                                    <td><?= $stat['total'] ?></td>
                                    <td>
                                        <?php if ($stat['available'] > 0): ?>
                                            <span class="gs-badge gs-badge-success"><?= $stat['available'] ?></span>
                                        <?php else: ?>
                                            0
                                        <?php endif; ?>
                                    </td>
                                    <td><?= $stat['sold'] ?></td>
                                    <td>
                                        <?php if ($stat['available'] > 0): ?>
                                            <form method="POST" style="display: inline;" onsubmit="return confirm('Radera <?= $stat['available'] ?> tillgängliga biljetter?');">
                                                <?= csrfField() ?>
                                                <input type="hidden" name="action" value="delete_available">
                                                <input type="hidden" name="class_id" value="<?= $class['id'] ?>">
                                                <button type="submit" class="gs-btn gs-btn-error gs-btn-sm">
                                                    <i data-lucide="trash-2" class="gs-icon-xs"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <?php elseif ($activeTab === 'refunds'): ?>
        <!-- REFUNDS TAB -->
        <div class="gs-card">
            <div class="gs-card-header">
                <h2 class="gs-h4">Återbetalningsbegäran</h2>
            </div>
            <div class="gs-card-content">
                <?php if (empty($refundRequests)): ?>
                    <p class="gs-text-secondary">Inga återbetalningsbegäran för detta event.</p>
                <?php else: ?>
                    <div class="gs-table-responsive">
                        <table class="gs-table">
                            <thead>
                                <tr>
                                    <th>Datum</th>
                                    <th>Deltagare</th>
                                    <th>Biljett</th>
                                    <th>Belopp</th>
                                    <th>Status</th>
                                    <th>Åtgärder</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($refundRequests as $request): ?>
                                    <tr>
                                        <td><?= date('d M', strtotime($request['created_at'])) ?></td>
                                        <td><?= h($request['firstname'] . ' ' . $request['lastname']) ?></td>
                                        <td><code><?= h($request['ticket_number']) ?></code></td>
                                        <td><?= number_format($request['refund_amount'], 0) ?> kr</td>
                                        <td>
                                            <?php if ($request['status'] === 'pending'): ?>
                                                <span class="gs-badge gs-badge-warning">Väntande</span>
                                            <?php elseif ($request['status'] === 'approved'): ?>
                                                <span class="gs-badge gs-badge-success">Godkänd</span>
                                            <?php else: ?>
                                                <span class="gs-badge gs-badge-error">Nekad</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($request['status'] === 'pending'): ?>
                                                <div class="gs-flex gs-gap-xs">
                                                    <form method="POST" style="display: inline;">
                                                        <?= csrfField() ?>
                                                        <input type="hidden" name="action" value="approve_refund">
                                                        <input type="hidden" name="request_id" value="<?= $request['id'] ?>">
                                                        <button type="submit" class="gs-btn gs-btn-success gs-btn-sm">
                                                            <i data-lucide="check" class="gs-icon-xs"></i>
                                                        </button>
                                                    </form>
                                                    <form method="POST" style="display: inline;">
                                                        <?= csrfField() ?>
                                                        <input type="hidden" name="action" value="deny_refund">
                                                        <input type="hidden" name="request_id" value="<?= $request['id'] ?>">
                                                        <button type="submit" class="gs-btn gs-btn-error gs-btn-sm">
                                                            <i data-lucide="x" class="gs-icon-xs"></i>
                                                        </button>
                                                    </form>
                                                </div>
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
        <?php endif; ?>
    </div>
</main>

<style>
.gs-tabs {
    display: flex;
    gap: 0.25rem;
    border-bottom: 2px solid var(--gs-border);
    padding-bottom: 0;
    overflow-x: auto;
}
.gs-tab {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.75rem 1rem;
    text-decoration: none;
    color: var(--gs-text-secondary);
    border-bottom: 2px solid transparent;
    margin-bottom: -2px;
    white-space: nowrap;
    transition: all 0.2s;
}
.gs-tab:hover {
    color: var(--gs-text-primary);
    background: var(--gs-bg-secondary);
}
.gs-tab.active {
    color: var(--gs-primary);
    border-bottom-color: var(--gs-primary);
    font-weight: 500;
}
</style>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>

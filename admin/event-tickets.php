<?php
/**
 * Admin Event Tickets Management
 * Generate and manage tickets for events
 */

require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();

// Get event ID
$eventId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($eventId <= 0) {
    $_SESSION['message'] = 'Ogiltigt event-ID';
    $_SESSION['messageType'] = 'error';
    header('Location: /admin/events.php');
    exit;
}

// Fetch event data
$event = $db->getRow("SELECT * FROM events WHERE id = ?", [$eventId]);

if (!$event) {
    $_SESSION['message'] = 'Event hittades inte';
    $_SESSION['messageType'] = 'error';
    header('Location: /admin/events.php');
    exit;
}

// Initialize message variables
$message = '';
$messageType = 'info';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();

    $action = $_POST['action'] ?? '';

    if ($action === 'generate_tickets') {
        $classId = intval($_POST['class_id'] ?? 0);
        $quantity = intval($_POST['quantity'] ?? 0);

        if ($classId <= 0 || $quantity <= 0) {
            $message = 'Välj klass och ange antal biljetter';
            $messageType = 'error';
        } else {
            // Generate tickets
            $existingCount = $db->getValue("
                SELECT COUNT(*) FROM event_tickets
                WHERE event_id = ? AND class_id = ?
            ", [$eventId, $classId]);

            $inserted = 0;
            $wooProductId = $event['woo_product_id'] ?? null;

            for ($i = 1; $i <= $quantity; $i++) {
                $ticketNum = $existingCount + $i;
                $ticketNumber = sprintf('E%d-C%d-%05d', $eventId, $classId, $ticketNum);

                $result = $db->execute("
                    INSERT INTO event_tickets
                    (event_id, ticket_number, class_id, status, woo_product_id, created_at)
                    VALUES (?, ?, ?, 'available', ?, NOW())
                ", [$eventId, $ticketNumber, $classId, $wooProductId]);

                if ($result) {
                    $inserted++;
                }
            }

            $message = "Skapade $inserted biljetter";
            $messageType = 'success';
        }
    } elseif ($action === 'delete_available') {
        // Delete all available tickets for a class
        $classId = intval($_POST['class_id'] ?? 0);

        if ($classId > 0) {
            $deleted = $db->execute("
                DELETE FROM event_tickets
                WHERE event_id = ? AND class_id = ? AND status = 'available'
            ", [$eventId, $classId]);

            $message = 'Raderade tillgängliga biljetter';
            $messageType = 'success';
        }
    }
}

// Fetch classes with pricing
$classes = $db->getAll("
    SELECT
        c.id,
        c.name,
        c.display_name,
        c.sort_order,
        epr.base_price
    FROM classes c
    LEFT JOIN event_pricing_rules epr ON c.id = epr.class_id AND epr.event_id = ?
    ORDER BY c.sort_order ASC
", [$eventId]);

// Fetch ticket statistics per class
$ticketStats = $db->getAll("
    SELECT
        class_id,
        COUNT(*) as total,
        SUM(CASE WHEN status = 'available' THEN 1 ELSE 0 END) as available,
        SUM(CASE WHEN status = 'sold' THEN 1 ELSE 0 END) as sold,
        SUM(CASE WHEN status = 'refunded' THEN 1 ELSE 0 END) as refunded
    FROM event_tickets
    WHERE event_id = ?
    GROUP BY class_id
", [$eventId]);

// Create stats map
$statsMap = [];
foreach ($ticketStats as $stat) {
    $statsMap[$stat['class_id']] = $stat;
}

// Total stats
$totalStats = $db->getRow("
    SELECT
        COUNT(*) as total,
        SUM(CASE WHEN status = 'available' THEN 1 ELSE 0 END) as available,
        SUM(CASE WHEN status = 'sold' THEN 1 ELSE 0 END) as sold,
        SUM(CASE WHEN status = 'refunded' THEN 1 ELSE 0 END) as refunded
    FROM event_tickets
    WHERE event_id = ?
", [$eventId]);

$pageTitle = 'Biljetter - ' . $event['name'];
$pageType = 'admin';
include __DIR__ . '/../includes/layout-header.php';
?>

<main class="gs-main-content">
    <div class="gs-container">
        <!-- Header -->
        <div class="gs-card gs-mb-lg">
            <div class="gs-card-content">
                <div class="gs-mb-md">
                    <a href="/admin/event-pricing.php?id=<?= $eventId ?>" class="gs-btn gs-btn-outline gs-btn-sm">
                        <i data-lucide="arrow-left" class="gs-icon-md"></i>
                        Tillbaka till prissättning
                    </a>
                </div>

                <h1 class="gs-h2 gs-text-primary gs-mb-sm">
                    <i data-lucide="ticket" class="gs-icon-lg"></i>
                    Biljetthantering
                </h1>
                <p class="gs-text-secondary">
                    <strong><?= h($event['name']) ?></strong> - <?= date('d M Y', strtotime($event['date'])) ?>
                </p>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="gs-alert gs-alert-<?= $messageType ?> gs-mb-lg">
                <?= h($message) ?>
            </div>
        <?php endif; ?>

        <!-- Total Statistics -->
        <div class="gs-card gs-mb-lg">
            <div class="gs-card-header">
                <h2 class="gs-h4 gs-text-primary">
                    <i data-lucide="bar-chart-2" class="gs-icon-md"></i>
                    Total statistik
                </h2>
            </div>
            <div class="gs-card-content">
                <div class="gs-grid gs-grid-cols-2 gs-md-grid-cols-4 gs-gap-md">
                    <div class="gs-stat-card">
                        <div class="gs-stat-value"><?= $totalStats['total'] ?? 0 ?></div>
                        <div class="gs-stat-label">Totalt skapade</div>
                    </div>
                    <div class="gs-stat-card">
                        <div class="gs-stat-value gs-text-success"><?= $totalStats['available'] ?? 0 ?></div>
                        <div class="gs-stat-label">Tillgängliga</div>
                    </div>
                    <div class="gs-stat-card">
                        <div class="gs-stat-value gs-text-primary"><?= $totalStats['sold'] ?? 0 ?></div>
                        <div class="gs-stat-label">Sålda</div>
                    </div>
                    <div class="gs-stat-card">
                        <div class="gs-stat-value gs-text-warning"><?= $totalStats['refunded'] ?? 0 ?></div>
                        <div class="gs-stat-label">Återbetalade</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Generate Tickets -->
        <div class="gs-card gs-mb-lg">
            <div class="gs-card-header">
                <h2 class="gs-h4 gs-text-primary">
                    <i data-lucide="plus-circle" class="gs-icon-md"></i>
                    Generera biljetter
                </h2>
            </div>
            <div class="gs-card-content">
                <form method="POST" class="gs-flex gs-gap-md gs-items-end gs-flex-wrap">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="generate_tickets">

                    <div class="gs-form-group">
                        <label class="gs-label">Klass</label>
                        <select name="class_id" class="gs-select" required>
                            <option value="">Välj klass...</option>
                            <?php foreach ($classes as $class): ?>
                                <?php if ($class['base_price'] > 0): ?>
                                    <option value="<?= $class['id'] ?>">
                                        <?= h($class['display_name']) ?> (<?= $class['base_price'] ?> kr)
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="gs-form-group">
                        <label class="gs-label">Antal biljetter</label>
                        <input type="number"
                               name="quantity"
                               class="gs-input"
                               value="50"
                               min="1"
                               max="1000"
                               required
                               style="width: 100px;">
                    </div>

                    <button type="submit" class="gs-btn gs-btn-primary">
                        <i data-lucide="plus" class="gs-icon-sm"></i>
                        Generera
                    </button>
                </form>
            </div>
        </div>

        <!-- Tickets per Class -->
        <div class="gs-card">
            <div class="gs-card-header">
                <h2 class="gs-h4 gs-text-primary">
                    <i data-lucide="layers" class="gs-icon-md"></i>
                    Biljetter per klass
                </h2>
            </div>
            <div class="gs-card-content">
                <div class="gs-table-responsive">
                    <table class="gs-table">
                        <thead>
                            <tr>
                                <th>Klass</th>
                                <th>Pris</th>
                                <th class="gs-table-center">Totalt</th>
                                <th class="gs-table-center">Tillgängliga</th>
                                <th class="gs-table-center">Sålda</th>
                                <th>Fyllning</th>
                                <th>Åtgärder</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($classes as $class): ?>
                                <?php
                                $stats = $statsMap[$class['id']] ?? null;
                                $total = $stats['total'] ?? 0;
                                $available = $stats['available'] ?? 0;
                                $sold = $stats['sold'] ?? 0;
                                $fillPercent = $total > 0 ? round(($sold / $total) * 100) : 0;
                                ?>
                                <tr>
                                    <td>
                                        <strong><?= h($class['display_name']) ?></strong>
                                    </td>
                                    <td>
                                        <?php if ($class['base_price'] > 0): ?>
                                            <?= number_format($class['base_price'], 0) ?> kr
                                        <?php else: ?>
                                            <span class="gs-text-secondary">Ej satt</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="gs-table-center"><?= $total ?></td>
                                    <td class="gs-table-center">
                                        <?php if ($available > 0): ?>
                                            <span class="gs-badge gs-badge-success"><?= $available ?></span>
                                        <?php else: ?>
                                            <span class="gs-text-secondary">0</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="gs-table-center">
                                        <?php if ($sold > 0): ?>
                                            <span class="gs-badge gs-badge-primary"><?= $sold ?></span>
                                        <?php else: ?>
                                            <span class="gs-text-secondary">0</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($total > 0): ?>
                                            <div style="width: 100px; height: 8px; background: var(--gs-bg-tertiary); border-radius: 4px; overflow: hidden;">
                                                <div style="width: <?= $fillPercent ?>%; height: 100%; background: var(--gs-primary);"></div>
                                            </div>
                                            <span class="gs-text-xs gs-text-secondary"><?= $fillPercent ?>%</span>
                                        <?php else: ?>
                                            <span class="gs-text-secondary gs-text-sm">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($available > 0): ?>
                                            <form method="POST" style="display: inline;"
                                                  onsubmit="return confirm('Radera <?= $available ?> tillgängliga biljetter?');">
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
    </div>
</main>


<?php include __DIR__ . '/../includes/layout-footer.php'; ?>

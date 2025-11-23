<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

/**
 * Ticketing Dashboard
 * Main hub for managing event ticketing, pricing, and sales
 */

require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();

// Initialize message
$message = '';
$messageType = 'info';

// Check if ticketing columns exist in events table
$ticketingColumnsExist = false;
try {
    $columns = $db->getAll("SHOW COLUMNS FROM events WHERE Field IN ('ticketing_enabled', 'woo_product_id', 'ticket_deadline_days')");
    $ticketingColumnsExist = count($columns) >= 3;
} catch (Exception $e) {
    // Columns don't exist
}

// Check if ticketing tables exist
$ticketingTablesExist = false;
try {
    $tables = $db->getAll("SHOW TABLES LIKE 'event_tickets'");
    $ticketingTablesExist = !empty($tables);
} catch (Exception $e) {
    // Table doesn't exist
}

$pricingTablesExist = false;
try {
    $tables = $db->getAll("SHOW TABLES LIKE 'event_pricing_rules'");
    $pricingTablesExist = !empty($tables);
} catch (Exception $e) {
    // Table doesn't exist
}

$refundTablesExist = false;
try {
    $tables = $db->getAll("SHOW TABLES LIKE 'event_refund_requests'");
    $refundTablesExist = !empty($tables);
} catch (Exception $e) {
    // Table doesn't exist
}

// Handle quick actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $ticketingColumnsExist) {
    checkCsrf();

    $action = $_POST['action'] ?? '';
    $eventId = intval($_POST['event_id'] ?? 0);

    if ($eventId > 0) {
        if ($action === 'enable_ticketing') {
            $db->execute("UPDATE events SET ticketing_enabled = 1 WHERE id = ?", [$eventId]);
            $message = 'Ticketing aktiverat!';
            $messageType = 'success';
        } elseif ($action === 'disable_ticketing') {
            $db->execute("UPDATE events SET ticketing_enabled = 0 WHERE id = ?", [$eventId]);
            $message = 'Ticketing avaktiverat';
            $messageType = 'warning';
        }
    }
}

// Build dynamic query based on available columns/tables
$ticketingSelect = $ticketingColumnsExist
    ? "e.ticketing_enabled, e.woo_product_id, e.ticket_deadline_days"
    : "0 as ticketing_enabled, NULL as woo_product_id, 7 as ticket_deadline_days";

$pricingSubquery = $pricingTablesExist
    ? "(SELECT COUNT(*) FROM event_pricing_rules WHERE event_id = e.id)"
    : "0";

$ticketsSubqueries = $ticketingTablesExist
    ? "(SELECT COUNT(*) FROM event_tickets WHERE event_id = e.id) as total_tickets,
       (SELECT COUNT(*) FROM event_tickets WHERE event_id = e.id AND status = 'available') as available_tickets,
       (SELECT COUNT(*) FROM event_tickets WHERE event_id = e.id AND status = 'sold') as sold_tickets,
       (SELECT SUM(paid_price) FROM event_tickets WHERE event_id = e.id AND status = 'sold') as total_revenue"
    : "0 as total_tickets, 0 as available_tickets, 0 as sold_tickets, 0 as total_revenue";

// Check if series table exists
$seriesTableExists = false;
try {
    $tables = $db->getAll("SHOW TABLES LIKE 'series'");
    $seriesTableExists = !empty($tables);
} catch (Exception $e) {
    // Table doesn't exist
}

$seriesJoin = $seriesTableExists ? "LEFT JOIN series s ON e.series_id = s.id" : "";
$seriesSelect = $seriesTableExists ? "s.name as series_name," : "NULL as series_name,";

// Fetch all events with ticketing status
$events = [];
try {
    $events = $db->getAll("
        SELECT
            e.id,
            e.name,
            e.date,
            e.location,
            {$ticketingSelect},
            {$seriesSelect}
            {$pricingSubquery} as pricing_rules_count,
            {$ticketsSubqueries}
        FROM events e
        {$seriesJoin}
        WHERE e.date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        ORDER BY e.date ASC
    ");
} catch (Exception $e) {
    // Query failed, show error in development
    $message = 'Databasfel: ' . $e->getMessage();
    $messageType = 'error';
}

// Separate upcoming and past events
$upcomingEvents = [];
$pastEvents = [];
$today = date('Y-m-d');

foreach ($events as $event) {
    if (isset($event['date']) && $event['date'] >= $today) {
        $upcomingEvents[] = $event;
    } else {
        $pastEvents[] = $event;
    }
}

// Get pending refund requests count
$pendingRefunds = 0;
if ($refundTablesExist) {
    $pendingRefunds = $db->getValue("
        SELECT COUNT(*) FROM event_refund_requests WHERE status = 'pending'
    ") ?: 0;
}

// Calculate overall stats
$totalStats = ['events_with_tickets' => 0, 'total_sold' => 0, 'total_revenue' => 0];
if ($ticketingTablesExist) {
    $totalStats = $db->getRow("
        SELECT
            COUNT(DISTINCT event_id) as events_with_tickets,
            SUM(CASE WHEN status = 'sold' THEN 1 ELSE 0 END) as total_sold,
            SUM(CASE WHEN status = 'sold' THEN paid_price ELSE 0 END) as total_revenue
        FROM event_tickets
    ") ?: $totalStats;
}

$pageTitle = 'Ticketing';
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
                        <h1 class="gs-h2 gs-text-primary gs-mb-sm">
                            <i data-lucide="ticket" class="gs-icon-lg"></i>
                            Ticketing Dashboard
                        </h1>
                        <p class="gs-text-secondary">
                            Hantera biljetter, priser och försäljning för alla events
                        </p>
                    </div>
                    <?php if ($pendingRefunds > 0): ?>
                        <a href="/admin/refund-requests.php" class="gs-btn gs-btn-warning">
                            <i data-lucide="alert-circle" class="gs-icon-sm"></i>
                            <?= $pendingRefunds ?> väntande återbetalningar
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="gs-alert gs-alert-<?= $messageType ?> gs-mb-lg">
                <?= h($message) ?>
            </div>
        <?php endif; ?>

        <?php if (!$ticketingColumnsExist || !$ticketingTablesExist): ?>
            <div class="gs-alert gs-alert-warning gs-mb-lg">
                <i data-lucide="alert-triangle" class="gs-icon-sm"></i>
                <strong>Ticketing-systemet är inte konfigurerat.</strong>
                Kör databasmigreringarna för att aktivera biljettfunktioner (ticketing_enabled, woo_product_id, event_tickets, etc).
            </div>
        <?php endif; ?>

        <!-- Overall Stats -->
        <div class="gs-grid gs-grid-cols-2 gs-md-grid-cols-4 gs-gap-md gs-mb-lg">
            <div class="gs-card">
                <div class="gs-card-content gs-text-center">
                    <div class="gs-stat-value"><?= $totalStats['events_with_tickets'] ?? 0 ?></div>
                    <div class="gs-stat-label">Events med biljetter</div>
                </div>
            </div>
            <div class="gs-card">
                <div class="gs-card-content gs-text-center">
                    <div class="gs-stat-value gs-text-success"><?= $totalStats['total_sold'] ?? 0 ?></div>
                    <div class="gs-stat-label">Sålda biljetter</div>
                </div>
            </div>
            <div class="gs-card">
                <div class="gs-card-content gs-text-center">
                    <div class="gs-stat-value gs-text-primary"><?= number_format($totalStats['total_revenue'] ?? 0, 0) ?></div>
                    <div class="gs-stat-label">Total intäkt (kr)</div>
                </div>
            </div>
            <div class="gs-card">
                <div class="gs-card-content gs-text-center">
                    <div class="gs-stat-value <?= $pendingRefunds > 0 ? 'gs-text-warning' : '' ?>"><?= $pendingRefunds ?></div>
                    <div class="gs-stat-label">Väntande återbetalningar</div>
                </div>
            </div>
        </div>

        <!-- Upcoming Events -->
        <div class="gs-card gs-mb-lg">
            <div class="gs-card-header">
                <h2 class="gs-h4 gs-text-primary">
                    <i data-lucide="calendar" class="gs-icon-md"></i>
                    Kommande events
                </h2>
            </div>
            <div class="gs-card-content">
                <?php if (empty($upcomingEvents)): ?>
                    <p class="gs-text-secondary">Inga kommande events</p>
                <?php else: ?>
                    <div class="gs-table-responsive">
                        <table class="gs-table">
                            <thead>
                                <tr>
                                    <th>Event</th>
                                    <th>Datum</th>
                                    <th>Status</th>
                                    <th>Woo ID</th>
                                    <th>Priser</th>
                                    <th>Biljetter</th>
                                    <th>Sålda</th>
                                    <th>Intäkt</th>
                                    <th>Åtgärder</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($upcomingEvents as $event): ?>
                                    <?php
                                    $hasTicketing = $event['ticketing_enabled'];
                                    $hasPricing = $event['pricing_rules_count'] > 0;
                                    $hasTickets = $event['total_tickets'] > 0;
                                    $hasWooProduct = !empty($event['woo_product_id']);
                                    $fillPercent = $event['total_tickets'] > 0
                                        ? round(($event['sold_tickets'] / $event['total_tickets']) * 100)
                                        : 0;
                                    ?>
                                    <tr>
                                        <td>
                                            <strong><?= h($event['name']) ?></strong>
                                            <?php if ($event['series_name']): ?>
                                                <br><span class="gs-text-xs gs-text-secondary"><?= h($event['series_name']) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?= date('d M Y', strtotime($event['date'])) ?>
                                        </td>
                                        <td>
                                            <?php if ($hasTicketing): ?>
                                                <span class="gs-badge gs-badge-success gs-badge-sm">Aktiv</span>
                                            <?php else: ?>
                                                <span class="gs-badge gs-badge-secondary gs-badge-sm">Inaktiv</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($hasWooProduct): ?>
                                                <code class="gs-text-xs"><?= h($event['woo_product_id']) ?></code>
                                            <?php else: ?>
                                                <span class="gs-text-secondary gs-text-sm">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($hasPricing): ?>
                                                <span class="gs-badge gs-badge-primary gs-badge-sm">
                                                    <?= $event['pricing_rules_count'] ?> klasser
                                                </span>
                                            <?php else: ?>
                                                <span class="gs-text-warning gs-text-sm">Ej satt</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($hasTickets): ?>
                                                <?= $event['available_tickets'] ?>/<?= $event['total_tickets'] ?>
                                                <div style="width: 60px; height: 4px; background: var(--gs-bg-tertiary); border-radius: 2px; margin-top: 2px;">
                                                    <div style="width: <?= $fillPercent ?>%; height: 100%; background: var(--gs-primary); border-radius: 2px;"></div>
                                                </div>
                                            <?php else: ?>
                                                <span class="gs-text-secondary">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($event['sold_tickets'] > 0): ?>
                                                <strong><?= $event['sold_tickets'] ?></strong>
                                            <?php else: ?>
                                                <span class="gs-text-secondary">0</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($event['total_revenue'] > 0): ?>
                                                <?= number_format($event['total_revenue'], 0) ?> kr
                                            <?php else: ?>
                                                <span class="gs-text-secondary">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="gs-flex gs-gap-xs gs-flex-wrap">
                                                <!-- Configure ticketing -->
                                                <a href="/admin/event-ticketing.php?id=<?= $event['id'] ?>"
                                                   class="gs-btn gs-btn-sm <?= !$hasPricing ? 'gs-btn-primary' : 'gs-btn-outline' ?>"
                                                   title="Konfigurera ticketing">
                                                    <i data-lucide="settings" class="gs-icon-xs"></i>
                                                    Konfigurera
                                                </a>

                                                <!-- Toggle ticketing -->
                                                <?php if ($hasPricing && $hasTickets): ?>
                                                    <form method="POST" style="display: inline;">
                                                        <?= csrfField() ?>
                                                        <input type="hidden" name="event_id" value="<?= $event['id'] ?>">
                                                        <?php if ($hasTicketing): ?>
                                                            <input type="hidden" name="action" value="disable_ticketing">
                                                            <button type="submit"
                                                                    class="gs-btn gs-btn-warning gs-btn-sm"
                                                                    title="Avaktivera"
                                                                    onclick="return confirm('Avaktivera biljettförsäljning?')">
                                                                <i data-lucide="pause" class="gs-icon-xs"></i>
                                                            </button>
                                                        <?php else: ?>
                                                            <input type="hidden" name="action" value="enable_ticketing">
                                                            <button type="submit"
                                                                    class="gs-btn gs-btn-success gs-btn-sm"
                                                                    title="Aktivera">
                                                                <i data-lucide="play" class="gs-icon-xs"></i>
                                                            </button>
                                                        <?php endif; ?>
                                                    </form>
                                                <?php endif; ?>

                                                <!-- View public page -->
                                                <a href="/event-results.php?id=<?= $event['id'] ?>&tab=biljetter"
                                                   class="gs-btn gs-btn-outline gs-btn-sm"
                                                   target="_blank"
                                                   title="Visa publik sida">
                                                    <i data-lucide="external-link" class="gs-icon-xs"></i>
                                                </a>
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

        <!-- Setup Guide -->
        <div class="gs-card gs-mb-lg">
            <div class="gs-card-header">
                <h2 class="gs-h4 gs-text-primary">
                    <i data-lucide="book-open" class="gs-icon-md"></i>
                    Så här sätter du upp ticketing
                </h2>
            </div>
            <div class="gs-card-content">
                <ol class="gs-setup-steps">
                    <li>
                        <strong>1. Sätt priser</strong>
                        <p class="gs-text-sm gs-text-secondary">
                            Klicka på <i data-lucide="tag" class="gs-icon-xs"></i> för att konfigurera pris per klass.
                            Sätt early-bird rabatt och WooCommerce-produkt-ID.
                        </p>
                    </li>
                    <li>
                        <strong>2. Generera biljetter</strong>
                        <p class="gs-text-sm gs-text-secondary">
                            Klicka på <i data-lucide="plus-circle" class="gs-icon-xs"></i> för att skapa biljetter.
                            Välj klass och antal (t.ex. 50 st per klass).
                        </p>
                    </li>
                    <li>
                        <strong>3. Aktivera försäljning</strong>
                        <p class="gs-text-sm gs-text-secondary">
                            Klicka på <i data-lucide="play" class="gs-icon-xs"></i> för att göra biljetter köpbara.
                            "Biljetter"-fliken visas nu på event-sidan.
                        </p>
                    </li>
                </ol>
            </div>
        </div>

        <!-- Quick Links -->
        <div class="gs-card">
            <div class="gs-card-header">
                <h2 class="gs-h4 gs-text-secondary">
                    <i data-lucide="link" class="gs-icon-md"></i>
                    Snabblänkar
                </h2>
            </div>
            <div class="gs-card-content">
                <div class="gs-flex gs-gap-md gs-flex-wrap">
                    <a href="/admin/refund-requests.php" class="gs-btn gs-btn-outline">
                        <i data-lucide="rotate-ccw" class="gs-icon-sm"></i>
                        Återbetalningar
                        <?php if ($pendingRefunds > 0): ?>
                            <span class="gs-badge gs-badge-warning gs-badge-sm"><?= $pendingRefunds ?></span>
                        <?php endif; ?>
                    </a>
                    <a href="/admin/events.php" class="gs-btn gs-btn-outline">
                        <i data-lucide="calendar" class="gs-icon-sm"></i>
                        Alla events
                    </a>
                </div>
            </div>
        </div>
    </div>
</main>

<style>
.gs-stat-value {
    font-size: 1.75rem;
    font-weight: 700;
    line-height: 1;
}
.gs-stat-label {
    font-size: 0.75rem;
    color: var(--gs-text-secondary);
    margin-top: 0.5rem;
}
.gs-setup-steps {
    list-style: none;
    padding: 0;
    margin: 0;
}
.gs-setup-steps li {
    padding: 0.75rem 0;
    border-bottom: 1px solid var(--gs-border);
}
.gs-setup-steps li:last-child {
    border-bottom: none;
}
</style>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>

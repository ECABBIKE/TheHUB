<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

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
        <h1>Ticketing Dashboard - v2.4.2-067</h1>

        <?php if (!$ticketingColumnsExist || !$ticketingTablesExist): ?>
            <div class="gs-alert gs-alert-warning gs-mb-lg">
                <strong>Ticketing-systemet är inte konfigurerat.</strong>
                Kör databasmigreringarna för att aktivera biljettfunktioner.
            </div>
        <?php endif; ?>

        <div class="gs-card gs-mb-lg">
            <div class="gs-card-content">
                <h2>Kommande events (<?= count($upcomingEvents) ?>)</h2>
                <?php if (empty($upcomingEvents)): ?>
                    <p>Inga kommande events</p>
                <?php else: ?>
                    <ul>
                        <?php foreach ($upcomingEvents as $event): ?>
                            <li><?= htmlspecialchars($event['name']) ?> - <?= $event['date'] ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>

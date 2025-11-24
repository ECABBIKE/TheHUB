<?php
/**
 * Ticketing Dashboard
 * Main hub for managing event ticketing, pricing, and sales
 */

require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();

// Get upcoming events with basic info
$events = $db->getAll("
    SELECT
        e.id,
        e.name,
        e.date,
        e.location
    FROM events e
    WHERE e.date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    ORDER BY e.date ASC
");

// Separate upcoming and past events
$upcomingEvents = [];
$pastEvents = [];
$today = date('Y-m-d');

foreach ($events as $event) {
    if ($event['date'] >= $today) {
        $upcomingEvents[] = $event;
    } else {
        $pastEvents[] = $event;
    }
}

$pageTitle = 'Ticketing';
$pageType = 'admin';
include __DIR__ . '/../includes/layout-header.php';
?>

<main class="gs-content-with-sidebar">
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
                    <a href="/admin/pricing-templates.php" class="gs-btn gs-btn-primary">
                        <i data-lucide="credit-card"></i>
                        Prismallar
                    </a>
                </div>
            </div>
        </div>

        <div class="gs-alert gs-alert-info gs-mb-lg">
            <i data-lucide="info"></i>
            <strong>Info:</strong> Ticketing-funktioner kräver att databasmigreringarna körs för att aktivera kolumner som ticketing_enabled, woo_product_id, etc.
        </div>

        <!-- Upcoming Events -->
        <div class="gs-card gs-mb-lg">
            <div class="gs-card-header">
                <h2 class="gs-h4">
                    <i data-lucide="calendar"></i>
                    Kommande events (<?= count($upcomingEvents) ?>)
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
                                    <th>Plats</th>
                                    <th>Åtgärder</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($upcomingEvents as $event): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($event['name']) ?></strong></td>
                                        <td><?= date('d M Y', strtotime($event['date'])) ?></td>
                                        <td><?= htmlspecialchars($event['location'] ?? '-') ?></td>
                                        <td>
                                            <a href="/admin/event-ticketing.php?id=<?= $event['id'] ?>" class="gs-btn gs-btn-sm gs-btn-primary">
                                                <i data-lucide="settings"></i>
                                                Konfigurera
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Past Events -->
        <?php if (!empty($pastEvents)): ?>
        <div class="gs-card">
            <div class="gs-card-header">
                <h2 class="gs-h4 gs-text-secondary">
                    <i data-lucide="history"></i>
                    Tidigare events (<?= count($pastEvents) ?>)
                </h2>
            </div>
            <div class="gs-card-content">
                <div class="gs-table-responsive">
                    <table class="gs-table">
                        <thead>
                            <tr>
                                <th>Event</th>
                                <th>Datum</th>
                                <th>Plats</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pastEvents as $event): ?>
                                <tr>
                                    <td><?= htmlspecialchars($event['name']) ?></td>
                                    <td><?= date('d M Y', strtotime($event['date'])) ?></td>
                                    <td><?= htmlspecialchars($event['location'] ?? '-') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</main>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>

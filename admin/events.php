<?php
require_once __DIR__ . '/../config.php';
require_admin();

global $pdo;
$db = getDB();

// Get events
$sql = "SELECT 
    e.id, e.name, e.date, e.location, e.discipline, e.status,
    v.name as venue_name
FROM events e
LEFT JOIN venues v ON e.venue_id = v.id
ORDER BY e.date DESC
LIMIT 100";

try {
    $events = $db->getAll($sql);
} catch (Exception $e) {
    $events = [];
    $error = $e->getMessage();
}

$pageTitle = 'Events';
$pageType = 'admin';
include __DIR__ . '/../includes/layout-header.php';
?>

<main class="gs-main-content">
    <div class="gs-container">
        <div class="gs-flex gs-justify-between gs-items-center gs-mb-lg">
            <h1 class="gs-h2">
                <i data-lucide="calendar"></i>
                Events (<?= count($events) ?>)
            </h1>
            <a href="/admin/event-create.php" class="gs-btn gs-btn-primary">
                <i data-lucide="plus"></i>
                Nytt Event
            </a>
        </div>

        <?php if (isset($error)): ?>
            <div class="gs-alert gs-alert-danger gs-mb-lg">
                <strong>Fel:</strong> <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <div class="gs-card">
            <div class="gs-card-content">
                <?php if (empty($events)): ?>
                    <div class="gs-alert gs-alert-warning">
                        <p>Inga events hittades.</p>
                    </div>
                <?php else: ?>
                    <div style="overflow-x: auto;">
                        <table class="gs-table">
                            <thead>
                                <tr>
                                    <th>Datum</th>
                                    <th>Namn</th>
                                    <th>Plats</th>
                                    <th>Venue</th>
                                    <th>Disciplin</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($events as $event): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($event['date'] ?? '-') ?></td>
                                        <td>
                                            <strong><?= htmlspecialchars($event['name']) ?></strong>
                                        </td>
                                        <td><?= htmlspecialchars($event['location'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($event['venue_name'] ?? '-') ?></td>
                                        <td>
                                            <?php if ($event['discipline']): ?>
                                                <span class="gs-badge"><?= htmlspecialchars($event['discipline']) ?></span>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($event['status'] === 'published'): ?>
                                                <span class="gs-badge gs-badge-success">Publicerad</span>
                                            <?php elseif ($event['status'] === 'draft'): ?>
                                                <span class="gs-badge gs-badge-warning">Utkast</span>
                                            <?php else: ?>
                                                <span class="gs-badge gs-badge-secondary">Okänd</span>
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
    </div>
</main>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>

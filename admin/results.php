<?php
require_once __DIR__ . '/../config.php';
require_admin();

global $pdo;
$db = getDB();

// Get recent results
$sql = "SELECT 
    r.id, r.position, r.time, r.points,
    e.name as event_name, e.date as event_date,
    c.firstname, c.lastname
FROM results r
JOIN events e ON r.event_id = e.id
JOIN riders c ON r.rider_id = c.id
ORDER BY e.date DESC, r.position ASC
LIMIT 100";

try {
    $results = $db->getAll($sql);
} catch (Exception $e) {
    $results = [];
    $error = $e->getMessage();
}

$pageTitle = 'Resultat';
$pageType = 'admin';
include __DIR__ . '/../includes/layout-header.php';
?>

<main class="gs-main-content">
    <div class="gs-container">
        <div class="gs-flex gs-justify-between gs-items-center gs-mb-lg">
            <h1 class="gs-h2">
                <i data-lucide="trophy"></i>
                Resultat (<?= count($results) ?>)
            </h1>
            <a href="/admin/import-results.php" class="gs-btn gs-btn-primary">
                <i data-lucide="upload"></i>
                Importera Resultat
            </a>
        </div>

        <?php if (isset($error)): ?>
            <div class="gs-alert gs-alert-danger gs-mb-lg">
                <strong>Fel:</strong> <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <div class="gs-card">
            <div class="gs-card-content">
                <?php if (empty($results)): ?>
                    <div class="gs-alert gs-alert-warning">
                        <p>Inga resultat hittades.</p>
                    </div>
                <?php else: ?>
                    <div style="overflow-x: auto;">
                        <table class="gs-table">
                            <thead>
                                <tr>
                                    <th>Datum</th>
                                    <th>Event</th>
                                    <th>Deltagare</th>
                                    <th>Placering</th>
                                    <th>Tid</th>
                                    <th>Poäng</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($results as $result): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($result['event_date'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($result['event_name']) ?></td>
                                        <td>
                                            <strong><?= htmlspecialchars($result['firstname'] . ' ' . $result['lastname']) ?></strong>
                                        </td>
                                        <td><?= htmlspecialchars($result['position'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($result['time'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($result['points'] ?? '-') ?></td>
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

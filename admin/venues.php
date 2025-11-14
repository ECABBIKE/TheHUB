<?php
require_once __DIR__ . '/../config.php';
require_admin();

global $pdo;
$db = getDB();

// Get venues
$sql = "SELECT 
    v.id, v.name, v.city, v.country, v.address, v.active
FROM venues v
ORDER BY v.name
LIMIT 100";

try {
    $venues = $db->getAll($sql);
} catch (Exception $e) {
    $venues = [];
    $error = $e->getMessage();
}

$pageTitle = 'Venues';
$pageType = 'admin';
include __DIR__ . '/../includes/layout-header.php';
?>

<main class="gs-main-content">
    <div class="gs-container">
        <div class="gs-flex gs-justify-between gs-items-center gs-mb-lg">
            <h1 class="gs-h2">
                <i data-lucide="map-pin"></i>
                Venues (<?= count($venues) ?>)
            </h1>
            <a href="/admin/venue-create.php" class="gs-btn gs-btn-primary">
                <i data-lucide="plus"></i>
                Ny Venue
            </a>
        </div>

        <?php if (isset($error)): ?>
            <div class="gs-alert gs-alert-danger gs-mb-lg">
                <strong>Fel:</strong> <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <div class="gs-card">
            <div class="gs-card-content">
                <?php if (empty($venues)): ?>
                    <div class="gs-alert gs-alert-warning">
                        <p>Inga venues hittades.</p>
                    </div>
                <?php else: ?>
                    <div style="overflow-x: auto;">
                        <table class="gs-table">
                            <thead>
                                <tr>
                                    <th>Namn</th>
                                    <th>Stad</th>
                                    <th>Land</th>
                                    <th>Adress</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($venues as $venue): ?>
                                    <tr>
                                        <td>
                                            <strong><?= htmlspecialchars($venue['name']) ?></strong>
                                        </td>
                                        <td><?= htmlspecialchars($venue['city'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($venue['country'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($venue['address'] ?? '-') ?></td>
                                        <td>
                                            <?php if ($venue['active']): ?>
                                                <span class="gs-badge gs-badge-success">Aktiv</span>
                                            <?php else: ?>
                                                <span class="gs-badge gs-badge-secondary">Inaktiv</span>
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

<?php
require_once __DIR__ . '/../config.php';
require_admin();

global $pdo;
$db = getDB();

// Get riders
$sql = "SELECT 
    c.id, c.firstname, c.lastname, c.birth_year, c.gender,
    c.license_number, c.license_category, c.discipline, c.active,
    cl.name as club_name
FROM riders c
LEFT JOIN clubs cl ON c.club_id = cl.id
ORDER BY c.lastname, c.firstname
LIMIT 100";

$riders = $db->getAll($sql);

$pageTitle = 'Deltagare';
$pageType = 'admin';
include __DIR__ . '/../includes/layout-header.php';
?>

<div class="gs-container">
    <div class="gs-flex gs-justify-between gs-items-center gs-mb-lg">
        <h1 class="gs-h2">
            Deltagare (<?= count($riders) ?>)
        </h1>
        <a href="/admin/import-uci.php" class="gs-btn gs-btn-primary">
            Importera
        </a>
    </div>

    <div class="gs-card">
        <div class="gs-card-content">
            <?php if (empty($riders)): ?>
                <p>Inga deltagare hittades.</p>
            <?php else: ?>
                <table class="gs-table">
                    <thead>
                        <tr>
                            <th>Namn</th>
                            <th>Födelseår</th>
                            <th>Klubb</th>
                            <th>License</th>
                            <th>Disciplin</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($riders as $rider): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($rider['firstname'] . ' ' . $rider['lastname']) ?></strong>
                                </td>
                                <td><?= htmlspecialchars($rider['birth_year'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($rider['club_name'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($rider['license_category'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($rider['discipline'] ?? '-') ?></td>
                                <td>
                                    <?php if ($rider['active']): ?>
                                        <span class="gs-badge gs-badge-success">Aktiv</span>
                                    <?php else: ?>
                                        <span class="gs-badge gs-badge-secondary">Inaktiv</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>

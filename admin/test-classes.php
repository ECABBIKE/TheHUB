<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/class-calculations.php';
require_admin();

$db = getDB();

// Get all classes
$classes = $db->getAll("
    SELECT c.*, ps.name as scale_name
    FROM classes c
    LEFT JOIN point_scales ps ON c.point_scale_id = ps.id
    ORDER BY c.sort_order ASC, c.name ASC
");

$pageTitle = 'Test - Klasser';
$pageType = 'admin';
include __DIR__ . '/../includes/layout-header.php';
?>

<main class="gs-main-content">
    <div class="gs-container">
        <h1 class="gs-h2">Klasser (Test)</h1>
        
        <div class="gs-card">
            <table class="gs-table">
                <thead>
                    <tr>
                        <th>Namn</th>
                        <th>Visningsnamn</th>
                        <th>Kön</th>
                        <th>Ålder</th>
                        <th>Disciplin</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($classes as $class): ?>
                        <tr>
                            <td><strong><?= h($class['name']) ?></strong></td>
                            <td><?= h($class['display_name']) ?></td>
                            <td><?= h($class['gender']) ?></td>
                            <td><?= $class['min_age'] ?? '–' ?> – <?= $class['max_age'] ?? '–' ?></td>
                            <td><?= h($class['discipline']) ?></td>
                            <td>
                                <?php if ($class['active']): ?>
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
        
        <p class="gs-mt-lg">
            ✅ <strong><?= count($classes) ?> klasser</strong> hittades i databasen!
        </p>
    </div>
</main>

<script src="https://unpkg.com/lucide@latest"></script>
<script>lucide.createIcons();</script>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>

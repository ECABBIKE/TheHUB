<?php
require_once __DIR__ . '/../config.php';
require_admin();

global $pdo;
$db = getDB();

// Get recent results - adjusted query based on actual schema
$sql = "SELECT
    r.id, r.position, r.points, r.status, r.finish_time,
    e.name as event_name, e.date as event_date, e.id as event_id,
    c.firstname, c.lastname, c.id as cyclist_id
FROM results r
JOIN events e ON r.event_id = e.id
JOIN riders c ON r.cyclist_id = c.id
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
                        <p>Inga resultat hittades. Importera resultat för att komma igång.</p>
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
                                    <th>Poäng</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($results as $result): ?>
                                    <tr>
                                        <td><?= date('Y-m-d', strtotime($result['event_date'])) ?></td>
                                        <td>
                                            <a href="/event.php?id=<?= $result['event_id'] ?>" class="gs-link">
                                                <?= h($result['event_name']) ?>
                                            </a>
                                        </td>
                                        <td>
                                            <a href="/rider.php?id=<?= $result['cyclist_id'] ?>" class="gs-link">
                                                <strong><?= h($result['firstname'] . ' ' . $result['lastname']) ?></strong>
                                            </a>
                                        </td>
                                        <td>
                                            <?php if ($result['status'] === 'dnf'): ?>
                                                <span class="gs-badge gs-badge-danger">DNF</span>
                                            <?php elseif ($result['status'] === 'dns'): ?>
                                                <span class="gs-badge gs-badge-warning">DNS</span>
                                            <?php elseif ($result['status'] === 'dq'): ?>
                                                <span class="gs-badge gs-badge-danger">DQ</span>
                                            <?php else: ?>
                                                <?= h($result['position'] ?? '-') ?>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= $result['points'] ? number_format($result['points'], 0) : '-' ?></td>
                                        <td>
                                            <?php
                                            $statusBadge = 'gs-badge-success';
                                            $statusText = 'Slutförd';
                                            if ($result['status'] === 'dnf') {
                                                $statusBadge = 'gs-badge-danger';
                                                $statusText = 'DNF';
                                            } elseif ($result['status'] === 'dns') {
                                                $statusBadge = 'gs-badge-warning';
                                                $statusText = 'DNS';
                                            } elseif ($result['status'] === 'dq') {
                                                $statusBadge = 'gs-badge-danger';
                                                $statusText = 'Diskad';
                                            }
                                            ?>
                                            <span class="gs-badge <?= $statusBadge ?>">
                                                <?= $statusText ?>
                                            </span>
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

<script src="https://unpkg.com/lucide@latest"></script>
<script>
    lucide.createIcons();
</script>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>

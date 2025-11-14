<?php
require_once __DIR__ . '/../config.php';
require_admin();

global $pdo;
$db = getDB();

// Get all events with result counts
$sql = "SELECT
    e.id, e.name, e.advent_id, e.date, e.location, e.status,
    s.name as series_name,
    COUNT(DISTINCT r.id) as result_count,
    COUNT(DISTINCT r.category_id) as category_count,
    COUNT(DISTINCT CASE WHEN r.status = 'finished' THEN r.id END) as finished_count,
    COUNT(DISTINCT CASE WHEN r.status = 'dnf' THEN r.id END) as dnf_count,
    COUNT(DISTINCT CASE WHEN r.status = 'dns' THEN r.id END) as dns_count
FROM events e
LEFT JOIN results r ON e.id = r.event_id
LEFT JOIN series s ON e.series_id = s.id
GROUP BY e.id
ORDER BY e.date DESC";

try {
    $events = $db->getAll($sql);
} catch (Exception $e) {
    $events = [];
    $error = $e->getMessage();
}

$pageTitle = 'Resultat - Event';
$pageType = 'admin';
include __DIR__ . '/../includes/layout-header.php';
?>

<main class="gs-main-content">
    <div class="gs-container">
        <div class="gs-flex gs-justify-between gs-items-center gs-mb-lg">
            <h1 class="gs-h2">
                <i data-lucide="trophy"></i>
                Resultat - Event (<?= count($events) ?>)
            </h1>
            <div class="gs-flex gs-gap-sm">
                <a href="/admin/import-results.php" class="gs-btn gs-btn-primary">
                    <i data-lucide="upload"></i>
                    Importera Resultat
                </a>
            </div>
        </div>

        <?php if (isset($error)): ?>
            <div class="gs-alert gs-alert-danger gs-mb-lg">
                <strong>Fel:</strong> <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <?php if (empty($events)): ?>
            <div class="gs-card">
                <div class="gs-card-content">
                    <div class="gs-alert gs-alert-warning">
                        <p>Inga event hittades. Skapa ett event först.</p>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <!-- Events Grid -->
            <div class="gs-grid gs-grid-cols-1 gs-gap-md">
                <?php foreach ($events as $event): ?>
                    <div class="gs-card">
                        <div class="gs-card-content">
                            <div class="gs-flex gs-justify-between gs-items-start">
                                <!-- Event Info -->
                                <div class="gs-flex-1">
                                    <div class="gs-flex gs-items-center gs-gap-md gs-mb-sm">
                                        <h3 class="gs-h4 gs-text-primary">
                                            <?= h($event['name']) ?>
                                        </h3>
                                        <?php
                                        $status_class = 'gs-badge-secondary';
                                        $status_text = $event['status'];
                                        if ($event['status'] == 'upcoming' || strtotime($event['date']) > time()) {
                                            $status_class = 'gs-badge-warning';
                                            $status_text = 'Kommande';
                                        } elseif ($event['status'] == 'completed' || strtotime($event['date']) < time()) {
                                            $status_class = 'gs-badge-success';
                                            $status_text = 'Avklarad';
                                        }
                                        ?>
                                        <span class="gs-badge <?= $status_class ?>">
                                            <?= h($status_text) ?>
                                        </span>
                                    </div>

                                    <div class="gs-flex gs-gap-md gs-text-sm gs-text-secondary gs-mb-md">
                                        <span>
                                            <i data-lucide="calendar" style="width: 14px; height: 14px;"></i>
                                            <?= date('Y-m-d', strtotime($event['date'])) ?>
                                        </span>
                                        <?php if ($event['location']): ?>
                                            <span>
                                                <i data-lucide="map-pin" style="width: 14px; height: 14px;"></i>
                                                <?= h($event['location']) ?>
                                            </span>
                                        <?php endif; ?>
                                        <?php if ($event['series_name']): ?>
                                            <span>
                                                <i data-lucide="award" style="width: 14px; height: 14px;"></i>
                                                <?= h($event['series_name']) ?>
                                            </span>
                                        <?php endif; ?>
                                        <?php if ($event['advent_id']): ?>
                                            <span>
                                                <i data-lucide="hash" style="width: 14px; height: 14px;"></i>
                                                <?= h($event['advent_id']) ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Result Statistics -->
                                    <div class="gs-flex gs-gap-md">
                                        <div class="gs-flex gs-items-center gs-gap-xs">
                                            <i data-lucide="users" style="width: 16px; height: 16px;"></i>
                                            <strong><?= $event['result_count'] ?></strong>
                                            <span class="gs-text-secondary gs-text-sm">deltagare</span>
                                        </div>

                                        <?php if ($event['category_count'] > 0): ?>
                                            <div class="gs-flex gs-items-center gs-gap-xs">
                                                <i data-lucide="layers" style="width: 16px; height: 16px;"></i>
                                                <strong><?= $event['category_count'] ?></strong>
                                                <span class="gs-text-secondary gs-text-sm"><?= $event['category_count'] == 1 ? 'kategori' : 'kategorier' ?></span>
                                            </div>
                                        <?php endif; ?>

                                        <?php if ($event['finished_count'] > 0): ?>
                                            <div class="gs-flex gs-items-center gs-gap-xs">
                                                <i data-lucide="check-circle" style="width: 16px; height: 16px; color: var(--gs-success);"></i>
                                                <strong><?= $event['finished_count'] ?></strong>
                                                <span class="gs-text-secondary gs-text-sm">slutförda</span>
                                            </div>
                                        <?php endif; ?>

                                        <?php if ($event['dnf_count'] > 0): ?>
                                            <div class="gs-flex gs-items-center gs-gap-xs">
                                                <i data-lucide="x-circle" style="width: 16px; height: 16px; color: var(--gs-danger);"></i>
                                                <strong><?= $event['dnf_count'] ?></strong>
                                                <span class="gs-text-secondary gs-text-sm">DNF</span>
                                            </div>
                                        <?php endif; ?>

                                        <?php if ($event['dns_count'] > 0): ?>
                                            <div class="gs-flex gs-items-center gs-gap-xs">
                                                <i data-lucide="minus-circle" style="width: 16px; height: 16px; color: var(--gs-warning);"></i>
                                                <strong><?= $event['dns_count'] ?></strong>
                                                <span class="gs-text-secondary gs-text-sm">DNS</span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- Actions -->
                                <div class="gs-flex gs-gap-sm">
                                    <a href="/event.php?id=<?= $event['id'] ?>"
                                       class="gs-btn gs-btn-outline gs-btn-sm"
                                       title="Visa resultat">
                                        <i data-lucide="eye" style="width: 14px; height: 14px;"></i>
                                        Visa
                                    </a>

                                    <?php if ($event['result_count'] > 0): ?>
                                        <a href="/admin/edit-results.php?event_id=<?= $event['id'] ?>"
                                           class="gs-btn gs-btn-primary gs-btn-sm"
                                           title="Editera resultat">
                                            <i data-lucide="edit" style="width: 14px; height: 14px;"></i>
                                            Editera Resultat
                                        </a>
                                    <?php else: ?>
                                        <a href="/admin/import-results.php"
                                           class="gs-btn gs-btn-secondary gs-btn-sm"
                                           title="Importera resultat">
                                            <i data-lucide="upload" style="width: 14px; height: 14px;"></i>
                                            Importera
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</main>

<script src="https://unpkg.com/lucide@latest"></script>
<script>
    lucide.createIcons();
</script>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>

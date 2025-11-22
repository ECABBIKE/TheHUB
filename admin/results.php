<?php
require_once __DIR__ . '/../config.php';
require_admin();

global $pdo;
$db = getDB();

// Get filter parameters
$filterSeries = isset($_GET['series_id']) && is_numeric($_GET['series_id']) ? intval($_GET['series_id']) : null;
$filterYear = isset($_GET['year']) && is_numeric($_GET['year']) ? intval($_GET['year']) : null;

// Build WHERE clause
$where = [];
$params = [];

if ($filterSeries) {
    $where[] = "e.series_id = ?";
    $params[] = $filterSeries;
}

if ($filterYear) {
    $where[] = "YEAR(e.date) = ?";
    $params[] = $filterYear;
}

$whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// Get all events with result counts
$sql = "SELECT
    e.id, e.name, e.advent_id, e.date, e.location, e.status,
    s.name as series_name,
    s.id as series_id,
    COUNT(DISTINCT r.id) as result_count,
    COUNT(DISTINCT r.category_id) as category_count,
    COUNT(DISTINCT CASE WHEN r.status = 'finished' THEN r.id END) as finished_count,
    COUNT(DISTINCT CASE WHEN r.status = 'dnf' THEN r.id END) as dnf_count,
    COUNT(DISTINCT CASE WHEN r.status = 'dns' THEN r.id END) as dns_count
FROM events e
LEFT JOIN results r ON e.id = r.event_id
LEFT JOIN series s ON e.series_id = s.id
{$whereClause}
GROUP BY e.id
ORDER BY e.date DESC";

try {
    $events = $db->getAll($sql, $params);
} catch (Exception $e) {
    $events = [];
    $error = $e->getMessage();
}

// Get all series for filter buttons
$allSeries = $db->getAll("SELECT id, name FROM series WHERE active = 1 ORDER BY name");

// Get all years from events
$allYears = $db->getAll("SELECT DISTINCT YEAR(date) as year FROM events WHERE date IS NOT NULL ORDER BY year DESC");

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

        <?php if (isset($_SESSION['recalc_message'])): ?>
            <div class="gs-alert gs-alert-<?= h($_SESSION['recalc_type'] ?? 'info') ?> gs-mb-lg">
                <i data-lucide="<?= ($_SESSION['recalc_type'] ?? 'info') === 'success' ? 'check-circle' : 'alert-circle' ?>"></i>
                <?= h($_SESSION['recalc_message']) ?>
            </div>
            <?php
            unset($_SESSION['recalc_message']);
            unset($_SESSION['recalc_type']);
            ?>
        <?php endif; ?>

        <?php if (isset($error)): ?>
            <div class="gs-alert gs-alert-danger gs-mb-lg">
                <strong>Fel:</strong> <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <!-- Filter Section -->
        <div class="gs-card gs-mb-lg">
            <div class="gs-card-content">
                <form method="GET" class="gs-grid gs-grid-cols-1 gs-md-grid-cols-2 gs-gap-md">
                    <!-- Year Filter -->
                    <div>
                        <label for="year-filter" class="gs-label">
                            <i data-lucide="calendar"></i>
                            År
                        </label>
                        <select id="year-filter" name="year" class="gs-input" onchange="this.form.submit()">
                            <option value="">Alla år</option>
                            <?php foreach ($allYears as $yearRow): ?>
                                <option value="<?= $yearRow['year'] ?>" <?= $filterYear == $yearRow['year'] ? 'selected' : '' ?>>
                                    <?= $yearRow['year'] ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Series Filter -->
                    <div>
                        <label for="series-filter" class="gs-label">
                            <i data-lucide="trophy"></i>
                            Serie<?= $filterYear ? ' (' . $filterYear . ')' : '' ?>
                        </label>
                        <select id="series-filter" name="series_id" class="gs-input" onchange="this.form.submit()">
                            <option value="">Alla serier</option>
                            <?php foreach ($allSeries as $series): ?>
                                <option value="<?= $series['id'] ?>" <?= $filterSeries == $series['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($series['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </form>

                <!-- Active Filters Info -->
                <?php if ($filterSeries || $filterYear): ?>
                    <div class="gs-mt-md gs-section-divider">
                        <div class="gs-flex gs-items-center gs-gap-sm gs-flex-wrap">
                            <span class="gs-text-sm gs-text-secondary">Visar:</span>
                            <?php if ($filterSeries): ?>
                                <span class="gs-badge gs-badge-primary">
                                    <?php
                                    $seriesName = array_filter($allSeries, function($s) use ($filterSeries) {
                                        return $s['id'] == $filterSeries;
                                    });
                                    echo $seriesName ? htmlspecialchars(reset($seriesName)['name']) : 'Serie #' . $filterSeries;
                                    ?>
                                </span>
                            <?php endif; ?>
                            <?php if ($filterYear): ?>
                                <span class="gs-badge gs-badge-accent"><?= $filterYear ?></span>
                            <?php endif; ?>
                            <a href="/admin/results.php" class="gs-btn gs-btn-sm gs-btn-outline">
                                <i data-lucide="x"></i>
                                Visa alla
                            </a>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

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
                                            <i data-lucide="calendar" class="gs-icon-14"></i>
                                            <?= date('Y-m-d', strtotime($event['date'])) ?>
                                        </span>
                                        <?php if ($event['location']): ?>
                                            <span>
                                                <i data-lucide="map-pin" class="gs-icon-14"></i>
                                                <?= h($event['location']) ?>
                                            </span>
                                        <?php endif; ?>
                                        <?php if ($event['series_name']): ?>
                                            <span>
                                                <i data-lucide="award" class="gs-icon-14"></i>
                                                <?= h($event['series_name']) ?>
                                            </span>
                                        <?php endif; ?>
                                        <?php if ($event['advent_id']): ?>
                                            <span>
                                                <i data-lucide="hash" class="gs-icon-14"></i>
                                                <?= h($event['advent_id']) ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Result Statistics -->
                                    <div class="gs-flex gs-gap-md gs-flex-wrap">
                                        <div class="gs-flex gs-items-center gs-gap-xs">
                                            <i data-lucide="users" class="gs-icon-md"></i>
                                            <strong><?= $event['result_count'] ?></strong>
                                            <span class="gs-text-secondary gs-text-sm">deltagare</span>
                                        </div>

                                        <?php if ($event['category_count'] > 0): ?>
                                            <div class="gs-flex gs-items-center gs-gap-xs">
                                                <i data-lucide="layers" class="gs-icon-md"></i>
                                                <strong><?= $event['category_count'] ?></strong>
                                                <span class="gs-text-secondary gs-text-sm"><?= $event['category_count'] == 1 ? 'kategori' : 'kategorier' ?></span>
                                            </div>
                                        <?php endif; ?>

                                        <?php if ($event['finished_count'] > 0): ?>
                                            <div class="gs-flex gs-items-center gs-gap-xs">
                                                <i data-lucide="check-circle" class="gs-icon-md gs-icon-success"></i>
                                                <strong><?= $event['finished_count'] ?></strong>
                                                <span class="gs-text-secondary gs-text-sm">slutförda</span>
                                            </div>
                                        <?php endif; ?>

                                        <?php if ($event['dnf_count'] > 0): ?>
                                            <div class="gs-flex gs-items-center gs-gap-xs">
                                                <i data-lucide="x-circle" class="gs-icon-md gs-icon-danger"></i>
                                                <strong><?= $event['dnf_count'] ?></strong>
                                                <span class="gs-text-secondary gs-text-sm">DNF</span>
                                            </div>
                                        <?php endif; ?>

                                        <?php if ($event['dns_count'] > 0): ?>
                                            <div class="gs-flex gs-items-center gs-gap-xs">
                                                <i data-lucide="minus-circle" class="gs-icon-md gs-icon-warning"></i>
                                                <strong><?= $event['dns_count'] ?></strong>
                                                <span class="gs-text-secondary gs-text-sm">DNS</span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- Actions -->
                                <div class="gs-flex gs-gap-sm gs-flex-wrap">
                                    <a href="/event.php?id=<?= $event['id'] ?>"
                                       class="gs-btn gs-btn-outline gs-btn-sm"
                                       title="Visa resultat">
                                        <i data-lucide="eye" class="gs-icon-14"></i>
                                        Visa
                                    </a>

                                    <?php if ($event['result_count'] > 0): ?>
                                        <a href="/admin/edit-results.php?event_id=<?= $event['id'] ?>"
                                           class="gs-btn gs-btn-primary gs-btn-sm"
                                           title="Editera resultat">
                                            <i data-lucide="edit" class="gs-icon-14"></i>
                                            Editera
                                        </a>
                                        <a href="/admin/recalculate-results.php?event_id=<?= $event['id'] ?>"
                                           class="gs-btn gs-btn-secondary gs-btn-sm"
                                           title="Räkna om placeringar och poäng">
                                            <i data-lucide="refresh-cw" class="gs-icon-14"></i>
                                            Räkna om
                                        </a>
                                    <?php else: ?>
                                        <a href="/admin/import-results.php"
                                           class="gs-btn gs-btn-secondary gs-btn-sm"
                                           title="Importera resultat">
                                            <i data-lucide="upload" class="gs-icon-14"></i>
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

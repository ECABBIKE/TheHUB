<?php
require_once __DIR__ . '/../config.php';
require_admin();
require_once __DIR__ . '/../includes/admin-layout.php';

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
        <?php
        render_admin_header('Tävlingar', [
            ['label' => 'Importera Resultat', 'url' => '/admin/import-results.php', 'icon' => 'upload', 'class' => 'gs-btn-primary']
        ]);
        ?>

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
            <!-- Compact Events Table -->
            <div class="gs-card">
                <div class="gs-table-responsive">
                    <table class="gs-table">
                        <thead>
                            <tr>
                                <th>Datum</th>
                                <th>Event</th>
                                <th>Plats</th>
                                <th>Serie</th>
                                <th class="gs-text-center">Deltagare</th>
                                <th class="gs-text-right">Åtgärder</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($events as $event): ?>
                                <tr>
                                    <td class="gs-text-nowrap">
                                        <?= date('Y-m-d', strtotime($event['date'])) ?>
                                    </td>
                                    <td>
                                        <strong><?= h($event['name']) ?></strong>
                                        <?php if ($event['advent_id']): ?>
                                            <span class="gs-text-secondary gs-text-sm">#<?= h($event['advent_id']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= h($event['location'] ?? '-') ?></td>
                                    <td><?= h($event['series_name'] ?? '-') ?></td>
                                    <td class="gs-text-center">
                                        <span class="gs-badge gs-badge-<?= $event['result_count'] > 0 ? 'success' : 'secondary' ?>">
                                            <?= $event['result_count'] ?>
                                        </span>
                                        <?php if ($event['dnf_count'] > 0 || $event['dns_count'] > 0): ?>
                                            <span class="gs-text-secondary gs-text-xs">
                                                (<?= $event['dnf_count'] ?>/<?= $event['dns_count'] ?>)
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="gs-text-right gs-text-nowrap">
                                        <a href="/event.php?id=<?= $event['id'] ?>" class="gs-btn gs-btn-outline gs-btn-xs" title="Visa">
                                            <i data-lucide="eye" class="gs-icon-12"></i>
                                        </a>
                                        <?php if ($event['result_count'] > 0): ?>
                                            <a href="/admin/edit-results.php?event_id=<?= $event['id'] ?>" class="gs-btn gs-btn-primary gs-btn-xs" title="Editera">
                                                <i data-lucide="edit" class="gs-icon-12"></i>
                                            </a>
                                            <a href="/admin/recalculate-results.php?event_id=<?= $event['id'] ?>" class="gs-btn gs-btn-secondary gs-btn-xs" title="Räkna om">
                                                <i data-lucide="refresh-cw" class="gs-icon-12"></i>
                                            </a>
                                        <?php else: ?>
                                            <a href="/admin/import-results.php" class="gs-btn gs-btn-secondary gs-btn-xs" title="Importera">
                                                <i data-lucide="upload" class="gs-icon-12"></i>
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>
</main>

<script src="https://unpkg.com/lucide@latest"></script>
<script>
    lucide.createIcons();
</script>

<?php render_admin_footer(); ?>
<?php include __DIR__ . '/../includes/layout-footer.php'; ?>

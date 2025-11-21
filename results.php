<?php
require_once __DIR__ . '/config.php';

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
    s.logo as series_logo,
    COUNT(DISTINCT r.id) as result_count,
    COUNT(DISTINCT r.category_id) as category_count,
    COUNT(DISTINCT CASE WHEN r.status = 'finished' THEN r.id END) as finished_count
FROM events e
LEFT JOIN results r ON e.id = r.event_id
LEFT JOIN series s ON e.series_id = s.id
{$whereClause}
GROUP BY e.id
HAVING result_count > 0
ORDER BY e.date DESC";

try {
    $events = $db->getAll($sql, $params);
} catch (Exception $e) {
    $events = [];
    $error = $e->getMessage();
}

// Get all series for filter buttons (only series with results)
$allSeries = $db->getAll("
    SELECT DISTINCT s.id, s.name
    FROM series s
    INNER JOIN events e ON s.id = e.series_id
    INNER JOIN results r ON e.id = r.event_id
    WHERE s.active = 1
    ORDER BY s.name
");

// Get all years from events with results
$allYears = $db->getAll("
    SELECT DISTINCT YEAR(e.date) as year
    FROM events e
    INNER JOIN results r ON e.id = r.event_id
    WHERE e.date IS NOT NULL
    ORDER BY year DESC
");

$pageTitle = 'Resultat';
$pageType = 'public';
include __DIR__ . '/includes/layout-header.php';
?>

<main class="gs-main-content">
    <div class="gs-container">
        <div class="gs-mb-xl">
            <h1 class="gs-h2 gs-text-primary gs-mb-sm">
                <i data-lucide="trophy"></i>
                Resultat
            </h1>
            <p class="gs-text-secondary">
                <?= count($events) ?> tävlingar med resultat
            </p>
        </div>

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
                            <a href="/results.php" class="gs-btn gs-btn-sm gs-btn-outline">
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
                        <p>Inga resultat hittades. Skapa ett event först eller importera resultat.</p>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <!-- Events Grid - 2 columns on desktop -->
            <div class="gs-grid gs-grid-cols-1 gs-md-grid-cols-2 gs-gap-md">
                <?php foreach ($events as $event): ?>
                    <div class="gs-card gs-card-hover">
                        <div class="gs-card-content gs-p-md">
                            <!-- Header: Logo + Title + Actions -->
                            <div class="gs-flex gs-justify-between gs-items-start gs-gap-sm gs-mb-sm">
                                <div class="gs-flex gs-items-center gs-gap-sm gs-flex-1">
                                    <?php if ($event['series_logo']): ?>
                                        <img src="<?= h($event['series_logo']) ?>"
                                             alt="<?= h($event['series_name']) ?>"
                                             class="gs-series-logo-sm"
                                             style="width: 32px; height: 32px;">
                                    <?php endif; ?>
                                    <div class="gs-flex-1">
                                        <h3 class="gs-text-sm gs-text-primary gs-mb-0" style="font-weight: 600; line-height: 1.3;">
                                            <?= h($event['name']) ?>
                                        </h3>
                                        <?php if ($event['series_name']): ?>
                                            <div class="gs-text-xs gs-text-secondary">
                                                <?= h($event['series_name']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <a href="/event-results.php?id=<?= $event['id'] ?>"
                                   class="gs-btn gs-btn-primary gs-btn-sm"
                                   title="Visa resultat">
                                    <i data-lucide="trophy" class="gs-icon-14"></i>
                                </a>
                            </div>

                            <!-- Meta: Date, Location -->
                            <div class="gs-flex gs-gap-md gs-text-xs gs-text-secondary gs-mb-sm">
                                <span>
                                    <i data-lucide="calendar" class="gs-icon-12"></i>
                                    <?= date('d M Y', strtotime($event['date'])) ?>
                                </span>
                                <?php if ($event['location']): ?>
                                    <span>
                                        <i data-lucide="map-pin" class="gs-icon-12"></i>
                                        <?= h($event['location']) ?>
                                    </span>
                                <?php endif; ?>
                            </div>

                            <!-- Stats Row -->
                            <div class="gs-flex gs-gap-md gs-text-xs">
                                <span>
                                    <strong><?= $event['result_count'] ?></strong> deltagare
                                </span>
                                <?php if ($event['category_count'] > 0): ?>
                                    <span>
                                        <strong><?= $event['category_count'] ?></strong> <?= $event['category_count'] == 1 ? 'klass' : 'klasser' ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</main>

<?php include __DIR__ . '/includes/layout-footer.php'; ?>

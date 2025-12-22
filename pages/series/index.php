<?php
/**
 * TheHUB V3.5 - Series List
 * Shows all competition series with Serie + År selectors
 */

// Prevent direct access
if (!defined('HUB_V3_ROOT')) {
    header('Location: /series');
    exit;
}

$pdo = hub_db();

// Check if series_events table exists
$useSeriesEvents = false;
try {
    $check = $pdo->query("SHOW TABLES LIKE 'series_events'");
    $useSeriesEvents = $check->rowCount() > 0;
} catch (Exception $e) {
    $useSeriesEvents = false;
}

// Get filter parameters
$filterSeriesName = isset($_GET['series']) ? trim($_GET['series']) : null;
$currentYear = (int)date('Y');

// Check if user has set any filter params (series or year)
// Note: Router sets $_GET['page'], so we can't use count($_GET) === 0
$hasFilterParams = isset($_GET['series']) || isset($_GET['year']);

if (!$hasFilterParams) {
    // Initial page load - default to current year
    $filterYear = $currentYear;
} elseif (isset($_GET['year']) && $_GET['year'] === 'all') {
    // Explicitly selected "Alla år"
    $filterYear = null;
} elseif (isset($_GET['year']) && is_numeric($_GET['year'])) {
    // Specific year selected
    $filterYear = intval($_GET['year']);
} else {
    // Has series param but no year - show all years for that series
    $filterYear = null;
}

// Get unique series names for dropdown
$allSeriesStmt = $pdo->query("
    SELECT DISTINCT name FROM series
    WHERE status IN ('active', 'completed')
    ORDER BY name ASC
");
$allSeriesNames = $allSeriesStmt->fetchAll(PDO::FETCH_COLUMN);

// Get available years
$yearStmt = $pdo->query("
    SELECT DISTINCT year FROM series
    WHERE status IN ('active', 'completed') AND year IS NOT NULL
    ORDER BY year DESC
");
$availableYears = $yearStmt->fetchAll(PDO::FETCH_COLUMN);

// Build WHERE clause
$where = ["s.status IN ('active', 'completed')"];
$params = [];

if ($filterSeriesName) {
    $where[] = "s.name = ?";
    $params[] = $filterSeriesName;
}

if ($filterYear) {
    $where[] = "s.year = ?";
    $params[] = $filterYear;
}

$whereClause = 'WHERE ' . implode(' AND ', $where);

// Get series for display
if ($useSeriesEvents) {
    $sql = "
        SELECT s.id, s.name, s.description, s.year, s.status, s.logo, s.start_date, s.end_date,
               COUNT(DISTINCT se.event_id) as event_count,
               (SELECT COUNT(DISTINCT r.cyclist_id)
                FROM results r
                INNER JOIN series_events se2 ON r.event_id = se2.event_id
                WHERE se2.series_id = s.id) as participant_count
        FROM series s
        LEFT JOIN series_events se ON s.id = se.series_id
        {$whereClause}
        GROUP BY s.id
        ORDER BY s.year DESC, s.name ASC
    ";
} else {
    $sql = "
        SELECT s.id, s.name, s.description, s.year, s.status, s.logo, s.start_date, s.end_date,
               COUNT(DISTINCT e.id) as event_count,
               (SELECT COUNT(DISTINCT r.cyclist_id)
                FROM results r
                INNER JOIN events e2 ON r.event_id = e2.id
                WHERE e2.series_id = s.id) as participant_count
        FROM series s
        LEFT JOIN events e ON s.id = e.series_id
        {$whereClause}
        GROUP BY s.id
        ORDER BY s.year DESC, s.name ASC
    ";
}
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$seriesList = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Page title
$pageTitle = 'Tävlingsserier';
if ($filterSeriesName && !empty($seriesList)) {
    $pageTitle = $seriesList[0]['name'];
} elseif ($filterYear) {
    $pageTitle = "Serier $filterYear";
}
?>

<div class="page-header">
    <h1 class="page-title">
        <i data-lucide="award" class="page-icon"></i>
        <?= htmlspecialchars($pageTitle) ?>
    </h1>
    <p class="page-subtitle">Alla GravitySeries och andra tävlingsserier</p>
</div>

<!-- Filters -->
<div class="filter-bar">
    <div class="filter-group">
        <label class="filter-label">Serie</label>
        <select class="filter-select" onchange="window.location=this.value">
            <option value="/series?<?= $filterYear ? 'year=' . $filterYear : 'year=all' ?>" <?= !$filterSeriesName ? 'selected' : '' ?>>Alla serier</option>
            <?php foreach ($allSeriesNames as $name): ?>
            <option value="/series?series=<?= urlencode($name) ?><?= $filterYear ? '&year=' . $filterYear : '&year=all' ?>" <?= $filterSeriesName === $name ? 'selected' : '' ?>>
                <?= htmlspecialchars($name) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="filter-group">
        <label class="filter-label">År</label>
        <select class="filter-select" onchange="window.location=this.value">
            <option value="/series?<?= $filterSeriesName ? 'series=' . urlencode($filterSeriesName) . '&' : '' ?>year=all" <?= $filterYear === null ? 'selected' : '' ?>>Alla år</option>
            <?php foreach ($availableYears as $year): ?>
            <option value="/series?<?= $filterSeriesName ? 'series=' . urlencode($filterSeriesName) . '&' : '' ?>year=<?= $year ?>" <?= $filterYear == $year ? 'selected' : '' ?>>
                <?= $year ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
</div>

<?php if (empty($seriesList)): ?>
    <div class="empty-state">
        <i data-lucide="trophy" style="width: 48px; height: 48px; color: var(--color-text-muted); margin-bottom: var(--space-md);"></i>
        <h2>Inga serier hittades</h2>
        <p>Prova ett annat filter.</p>
    </div>
<?php else: ?>
    <div class="series-logo-grid">
        <?php foreach ($seriesList as $s): ?>
        <a href="/series/<?= $s['id'] ?>" class="series-logo-card">
            <div class="series-logo-wrapper">
                <?php if ($s['logo']): ?>
                    <img src="<?= htmlspecialchars($s['logo']) ?>" alt="<?= htmlspecialchars($s['name']) ?>" class="series-logo-img">
                <?php else: ?>
                    <div class="series-logo-placeholder"><i data-lucide="trophy" style="width: 48px; height: 48px; color: var(--color-text-muted);"></i></div>
                <?php endif; ?>
                <span class="series-year-badge"><?= $s['year'] ?></span>
            </div>
            <div class="series-logo-info">
                <h3 class="series-logo-name"><?= htmlspecialchars($s['name']) ?></h3>
                <div class="series-logo-meta">
                    <span><?= $s['event_count'] ?> tävlingar</span>
                    <?php if ($s['participant_count']): ?>
                        <span class="meta-sep">•</span>
                        <span><?= $s['participant_count'] ?> deltagare</span>
                    <?php endif; ?>
                </div>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

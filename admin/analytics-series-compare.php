<?php
/**
 * Analytics Series Compare - Jämför Serier/Varumärken
 *
 * Samma layout som Trender men för enskilda serier.
 * Välj 1-3 varumärken och se dem jämförda i samma grafer.
 *
 * @package TheHUB Analytics
 * @version 1.1
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';

requireAnalyticsAccess();

global $pdo;

// Antal år att visa
$numYears = isset($_GET['years']) ? max(3, min(15, (int)$_GET['years'])) : 10;

// Hämta tillgängliga år
$availableYears = [];
try {
    $stmt = $pdo->query("SELECT DISTINCT season_year FROM rider_yearly_stats ORDER BY season_year ASC");
    $availableYears = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $error = $e->getMessage();
}

$yearsToShow = array_slice($availableYears, -$numYears);

// Hämta alla serier (varumärken) med statistik - sorterade efter popularitet
$allSeries = [];
try {
    $stmt = $pdo->query("
        SELECT
            s.id,
            s.name,
            s.series_level,
            s.region,
            COALESCE(
                (SELECT e.discipline FROM events e WHERE e.series_id = s.id AND e.discipline IS NOT NULL LIMIT 1),
                'Okänd'
            ) as discipline,
            (SELECT COUNT(DISTINCT sp.rider_id) FROM series_participation sp WHERE sp.series_id = s.id) as total_participants,
            (SELECT MAX(sp.season_year) FROM series_participation sp WHERE sp.series_id = s.id) as last_year
        FROM series s
        WHERE s.id IN (SELECT DISTINCT series_id FROM series_participation)
        ORDER BY total_participants DESC, s.name
    ");
    $allSeries = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Ignore
}

// Valda serier (max 3)
$selected = [];
if (isset($_GET['s1']) && $_GET['s1']) $selected[] = (int)$_GET['s1'];
if (isset($_GET['s2']) && $_GET['s2']) $selected[] = (int)$_GET['s2'];
if (isset($_GET['s3']) && $_GET['s3']) $selected[] = (int)$_GET['s3'];

/**
 * Beräkna KPIs för en serie
 */
function getSeriesKPIs($pdo, $seriesId, $year) {
    // Totalt antal unika deltagare i serien detta år
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT rider_id)
        FROM series_participation
        WHERE series_id = ? AND season_year = ?
    ");
    $stmt->execute([$seriesId, $year]);
    $totalRiders = (int)$stmt->fetchColumn();

    // Nya riders (första året i NÅGON serie i systemet)
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT sp.rider_id)
        FROM series_participation sp
        WHERE sp.series_id = ?
        AND sp.season_year = ?
        AND sp.rider_id IN (
            SELECT rider_id FROM rider_yearly_stats WHERE first_year = ?
        )
    ");
    $stmt->execute([$seriesId, $year, $year]);
    $newRiders = (int)$stmt->fetchColumn();

    // Retained - deltog i serien både detta och förra året
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT sp1.rider_id)
        FROM series_participation sp1
        WHERE sp1.series_id = ?
        AND sp1.season_year = ?
        AND EXISTS (
            SELECT 1 FROM series_participation sp2
            WHERE sp2.rider_id = sp1.rider_id
            AND sp2.series_id = ?
            AND sp2.season_year = ?
        )
    ");
    $stmt->execute([$seriesId, $year, $seriesId, $year - 1]);
    $retainedRiders = (int)$stmt->fetchColumn();

    // Förra årets deltagare (för retention rate)
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT rider_id)
        FROM series_participation
        WHERE series_id = ? AND season_year = ?
    ");
    $stmt->execute([$seriesId, $year - 1]);
    $prevYearRiders = (int)$stmt->fetchColumn();

    // Retention rate
    $retentionRate = $prevYearRiders > 0 ? round($retainedRiders / $prevYearRiders * 100, 1) : 0;

    // Churn rate
    $churnRate = 100 - $retentionRate;

    // Growth rate
    $growthRate = $prevYearRiders > 0 ? round(($totalRiders - $prevYearRiders) / $prevYearRiders * 100, 1) : 0;

    // Cross-participation (deltar i fler än en serie)
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT sp1.rider_id)
        FROM series_participation sp1
        WHERE sp1.series_id = ? AND sp1.season_year = ?
        AND EXISTS (
            SELECT 1 FROM series_participation sp2
            WHERE sp2.rider_id = sp1.rider_id
            AND sp2.season_year = sp1.season_year
            AND sp2.series_id != sp1.series_id
        )
    ");
    $stmt->execute([$seriesId, $year]);
    $crossCount = (int)$stmt->fetchColumn();
    $crossParticipation = $totalRiders > 0 ? round($crossCount / $totalRiders * 100, 1) : 0;

    // Genomsnittsålder
    $stmt = $pdo->prepare("
        SELECT AVG(? - r.birth_year) as avg_age
        FROM riders r
        WHERE r.id IN (
            SELECT DISTINCT rider_id FROM series_participation
            WHERE series_id = ? AND season_year = ?
        )
        AND r.birth_year IS NOT NULL AND r.birth_year > 1900
    ");
    $stmt->execute([$year, $seriesId, $year]);
    $avgAge = round((float)$stmt->fetchColumn(), 1);

    // Andel kvinnor
    $stmt = $pdo->prepare("
        SELECT
            SUM(CASE WHEN r.gender = 'F' THEN 1 ELSE 0 END) as female,
            COUNT(*) as total
        FROM riders r
        WHERE r.id IN (
            SELECT DISTINCT rider_id FROM series_participation
            WHERE series_id = ? AND season_year = ?
        )
        AND r.gender IN ('M', 'F')
    ");
    $stmt->execute([$seriesId, $year]);
    $genderRow = $stmt->fetch();
    $femalePct = $genderRow['total'] > 0 ? round($genderRow['female'] / $genderRow['total'] * 100, 1) : 0;

    return [
        'total_riders' => $totalRiders,
        'new_riders' => $newRiders,
        'retained_riders' => $retainedRiders,
        'retention_rate' => $retentionRate,
        'churn_rate' => $churnRate,
        'growth_rate' => $growthRate,
        'cross_participation' => $crossParticipation,
        'average_age' => $avgAge,
        'female_pct' => $femalePct
    ];
}

// Samla trenddata för valda serier
$seriesTrends = [];
$seriesLabels = [];
$seriesColors = ['#37d4d6', '#f97316', '#a855f7']; // Cyan, Orange, Lila

try {
    foreach ($selected as $idx => $seriesId) {
        // Hämta serienamn
        $seriesName = '';
        foreach ($allSeries as $s) {
            if ($s['id'] == $seriesId) {
                $seriesName = $s['name'];
                break;
            }
        }
        $seriesLabels[$idx] = $seriesName;

        // Hämta data för varje år
        $trends = [];
        foreach ($yearsToShow as $year) {
            $kpis = getSeriesKPIs($pdo, $seriesId, $year);
            $trends[] = array_merge(['year' => $year], $kpis);
        }
        $seriesTrends[$idx] = $trends;
    }
} catch (Exception $e) {
    $error = $e->getMessage();
}

$hasData = !empty($selected) && !empty($seriesTrends);

// Page config
$page_title = 'Jämför Serier';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => '/admin/dashboard.php'],
    ['label' => 'Analytics', 'url' => '/admin/analytics-dashboard.php'],
    ['label' => 'Jämför Serier']
];

$page_actions = '
<div class="btn-group">
    <a href="/admin/analytics-trends.php" class="btn-admin btn-admin-secondary">
        <i data-lucide="trending-up"></i> Trender (Totalt)
    </a>
    <a href="/admin/analytics-dashboard.php" class="btn-admin btn-admin-secondary">
        <i data-lucide="bar-chart-3"></i> Dashboard
    </a>
</div>
';

include __DIR__ . '/components/unified-layout.php';
?>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

<!-- Selection Panel -->
<div class="card mb-lg">
    <div class="card-header">
        <h3><i data-lucide="layers"></i> Välj varumärken att jämföra</h3>
    </div>
    <div class="card-body">
        <form method="get" id="compareForm">
            <input type="hidden" name="years" value="<?= $numYears ?>">

            <div class="series-selector">
                <!-- Serie 1 -->
                <div class="series-slot">
                    <div class="slot-header">
                        <span class="slot-color" style="background: <?= $seriesColors[0] ?>"></span>
                        <span class="slot-label">Varumärke 1</span>
                    </div>
                    <select name="s1" class="form-select" onchange="this.form.submit()">
                        <option value="">-- Välj serie --</option>
                        <?php foreach ($allSeries as $series): ?>
                            <option value="<?= $series['id'] ?>" <?= (isset($_GET['s1']) && $_GET['s1'] == $series['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($series['name']) ?> (<?= number_format($series['total_participants']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Serie 2 -->
                <div class="series-slot">
                    <div class="slot-header">
                        <span class="slot-color" style="background: <?= $seriesColors[1] ?>"></span>
                        <span class="slot-label">Varumärke 2 (valfritt)</span>
                    </div>
                    <select name="s2" class="form-select" onchange="this.form.submit()">
                        <option value="">-- Välj serie --</option>
                        <?php foreach ($allSeries as $series): ?>
                            <option value="<?= $series['id'] ?>" <?= (isset($_GET['s2']) && $_GET['s2'] == $series['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($series['name']) ?> (<?= number_format($series['total_participants']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Serie 3 -->
                <div class="series-slot">
                    <div class="slot-header">
                        <span class="slot-color" style="background: <?= $seriesColors[2] ?>"></span>
                        <span class="slot-label">Varumärke 3 (valfritt)</span>
                    </div>
                    <select name="s3" class="form-select" onchange="this.form.submit()">
                        <option value="">-- Välj serie --</option>
                        <?php foreach ($allSeries as $series): ?>
                            <option value="<?= $series['id'] ?>" <?= (isset($_GET['s3']) && $_GET['s3'] == $series['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($series['name']) ?> (<?= number_format($series['total_participants']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <?php if (!empty($selected)): ?>
            <div class="selected-info">
                <a href="?years=<?= $numYears ?>" class="btn btn-secondary btn-sm">
                    <i data-lucide="x"></i> Rensa val
                </a>
            </div>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- Year Range Selector -->
<div class="filter-bar">
    <form method="get" class="filter-form">
        <?php if (isset($_GET['s1'])): ?><input type="hidden" name="s1" value="<?= htmlspecialchars($_GET['s1']) ?>"><?php endif; ?>
        <?php if (isset($_GET['s2'])): ?><input type="hidden" name="s2" value="<?= htmlspecialchars($_GET['s2']) ?>"><?php endif; ?>
        <?php if (isset($_GET['s3'])): ?><input type="hidden" name="s3" value="<?= htmlspecialchars($_GET['s3']) ?>"><?php endif; ?>
        <div class="filter-group">
            <label class="filter-label">Antal år</label>
            <select name="years" class="form-select" onchange="this.form.submit()">
                <?php foreach ([5, 7, 10, 15] as $y): ?>
                    <option value="<?= $y ?>" <?= $y == $numYears ? 'selected' : '' ?>>
                        Senaste <?= $y ?> år
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-info">
            <span class="badge"><?= count($yearsToShow) ?> år data</span>
            <span class="text-muted"><?= min($yearsToShow) ?> - <?= max($yearsToShow) ?></span>
        </div>
    </form>
</div>

<?php if (isset($error)): ?>
<div class="alert alert-warning">
    <i data-lucide="alert-triangle"></i>
    <div>
        <strong>Fel vid datahämtning</strong><br>
        <small><?= htmlspecialchars($error) ?></small>
    </div>
</div>
<?php elseif (!$hasData): ?>
<div class="alert alert-info">
    <i data-lucide="info"></i>
    <div>
        <strong>Välj minst ett varumärke</strong><br>
        Använd dropdown-menyerna ovan för att välja 1-3 serier att jämföra.
    </div>
</div>
<?php else: ?>

<!-- Legend -->
<div class="chart-legend-bar">
    <?php foreach ($selected as $idx => $seriesId): ?>
    <div class="legend-item">
        <span class="legend-color" style="background: <?= $seriesColors[$idx] ?>"></span>
        <span class="legend-label"><?= htmlspecialchars($seriesLabels[$idx]) ?></span>
    </div>
    <?php endforeach; ?>
</div>

<!-- DELTAGARTRENDER -->
<div class="admin-card">
    <div class="admin-card-header">
        <h2><i data-lucide="users"></i> Deltagarutveckling</h2>
        <span class="badge badge-primary"><?= count($yearsToShow) ?> säsonger</span>
    </div>
    <div class="admin-card-body">
        <div class="chart-container" style="height: 350px;">
            <canvas id="participantChart"></canvas>
        </div>
        <div class="chart-legend-custom">
            <?php foreach ($selected as $idx => $seriesId): ?>
            <div class="legend-item">
                <span class="legend-color" style="background: <?= $seriesColors[$idx] ?>"></span>
                <?= htmlspecialchars($seriesLabels[$idx]) ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- RETENTION & GROWTH -->
<div class="grid grid-2 grid-gap-lg">
    <div class="admin-card">
        <div class="admin-card-header">
            <h2><i data-lucide="refresh-cw"></i> Retention Rate</h2>
        </div>
        <div class="admin-card-body">
            <div class="chart-container" style="height: 280px;">
                <canvas id="retentionChart"></canvas>
            </div>
            <p class="chart-description">Andel riders som återkom från föregående säsong</p>
        </div>
    </div>

    <div class="admin-card">
        <div class="admin-card-header">
            <h2><i data-lucide="trending-up"></i> Tillväxt</h2>
        </div>
        <div class="admin-card-body">
            <div class="chart-container" style="height: 280px;">
                <canvas id="growthChart"></canvas>
            </div>
            <p class="chart-description">Årlig procentuell förändring i deltagarantal</p>
        </div>
    </div>
</div>

<!-- CROSS-PARTICIPATION & NYA RIDERS -->
<div class="grid grid-2 grid-gap-lg">
    <div class="admin-card">
        <div class="admin-card-header">
            <h2><i data-lucide="git-branch"></i> Cross-Participation</h2>
        </div>
        <div class="admin-card-body">
            <div class="chart-container" style="height: 280px;">
                <canvas id="crossParticipationChart"></canvas>
            </div>
            <p class="chart-description">Andel riders som även deltar i andra serier</p>
        </div>
    </div>

    <div class="admin-card">
        <div class="admin-card-header">
            <h2><i data-lucide="user-plus"></i> Nya Riders (Rekrytering)</h2>
        </div>
        <div class="admin-card-body">
            <div class="chart-container" style="height: 280px;">
                <canvas id="newRidersChart"></canvas>
            </div>
            <p class="chart-description">Antal helt nya deltagare per år</p>
        </div>
    </div>
</div>

<!-- DEMOGRAPHICS -->
<div class="grid grid-2 grid-gap-lg">
    <div class="admin-card">
        <div class="admin-card-header">
            <h2><i data-lucide="users"></i> Könsfördelning</h2>
        </div>
        <div class="admin-card-body">
            <div class="chart-container" style="height: 280px;">
                <canvas id="genderChart"></canvas>
            </div>
            <p class="chart-description">Andel kvinnliga deltagare över tid</p>
        </div>
    </div>

    <div class="admin-card">
        <div class="admin-card-header">
            <h2><i data-lucide="calendar"></i> Genomsnittsålder</h2>
        </div>
        <div class="admin-card-body">
            <div class="chart-container" style="height: 280px;">
                <canvas id="ageChart"></canvas>
            </div>
            <p class="chart-description">Genomsnittsålder för aktiva deltagare per säsong</p>
        </div>
    </div>
</div>

<!-- SAMMANFATTNINGSTABELLER -->
<?php foreach ($selected as $idx => $seriesId): ?>
<div class="admin-card">
    <div class="admin-card-header">
        <h2>
            <span class="legend-color-inline" style="background: <?= $seriesColors[$idx] ?>"></span>
            <?= htmlspecialchars($seriesLabels[$idx]) ?>
        </h2>
    </div>
    <div class="admin-card-body" style="padding: 0;">
        <div class="admin-table-container">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Säsong</th>
                        <th class="text-right">Totalt</th>
                        <th class="text-right">Nya</th>
                        <th class="text-right">Återvändare</th>
                        <th class="text-right">Retention</th>
                        <th class="text-right">Tillväxt</th>
                        <th class="text-right">Cross-Part.</th>
                        <th class="text-right">Snittålder</th>
                        <th class="text-right">Kvinnor</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (array_reverse($seriesTrends[$idx]) as $row): ?>
                    <tr>
                        <td><strong><?= $row['year'] ?></strong></td>
                        <td class="text-right"><?= number_format($row['total_riders']) ?></td>
                        <td class="text-right"><?= number_format($row['new_riders']) ?></td>
                        <td class="text-right"><?= number_format($row['retained_riders']) ?></td>
                        <td class="text-right">
                            <span class="badge <?= $row['retention_rate'] >= 60 ? 'badge-success' : ($row['retention_rate'] >= 40 ? 'badge-warning' : 'badge-danger') ?>">
                                <?= $row['retention_rate'] ?>%
                            </span>
                        </td>
                        <td class="text-right">
                            <span class="trend-indicator <?= $row['growth_rate'] >= 0 ? 'positive' : 'negative' ?>">
                                <?= $row['growth_rate'] >= 0 ? '+' : '' ?><?= $row['growth_rate'] ?>%
                            </span>
                        </td>
                        <td class="text-right"><?= $row['cross_participation'] ?>%</td>
                        <td class="text-right"><?= $row['average_age'] ?> år</td>
                        <td class="text-right"><?= $row['female_pct'] ?>%</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endforeach; ?>

<?php endif; ?>

<style>
/* Series Selector */
.series-selector {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: var(--space-lg);
}

.series-slot {
    display: flex;
    flex-direction: column;
    gap: var(--space-sm);
}

.slot-header {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    font-weight: var(--weight-medium);
}

.slot-color {
    width: 16px;
    height: 16px;
    border-radius: var(--radius-sm);
}

.slot-label {
    font-size: var(--text-sm);
    color: var(--color-text-secondary);
}

.selected-info {
    margin-top: var(--space-lg);
    padding-top: var(--space-lg);
    border-top: 1px solid var(--color-border);
    text-align: center;
}

/* Legend Bar */
.chart-legend-bar {
    display: flex;
    justify-content: center;
    gap: var(--space-xl);
    margin-bottom: var(--space-lg);
    padding: var(--space-md);
    background: var(--color-bg-card);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
}

.chart-legend-bar .legend-item {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    font-weight: var(--weight-semibold);
    font-size: var(--text-base);
}

.chart-legend-bar .legend-color {
    width: 20px;
    height: 20px;
    border-radius: var(--radius-sm);
}

.legend-color-inline {
    display: inline-block;
    width: 16px;
    height: 16px;
    border-radius: var(--radius-sm);
    margin-right: var(--space-sm);
    vertical-align: middle;
}

/* Filter Bar */
.filter-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: var(--space-lg);
    margin-bottom: var(--space-xl);
    padding: var(--space-md);
    background: var(--color-bg-card);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
}

.filter-form {
    display: flex;
    gap: var(--space-lg);
    align-items: center;
}

.filter-group {
    display: flex;
    flex-direction: column;
    gap: var(--space-xs);
}

.filter-label {
    font-size: var(--text-sm);
    color: var(--color-text-secondary);
    font-weight: var(--weight-medium);
}

.filter-info {
    display: flex;
    gap: var(--space-sm);
    align-items: center;
}

/* Chart Container */
.chart-container {
    position: relative;
    width: 100%;
}

.chart-description {
    margin: var(--space-md) 0 0;
    font-size: var(--text-sm);
    color: var(--color-text-muted);
    text-align: center;
}

/* Custom Legend */
.chart-legend-custom {
    display: flex;
    justify-content: center;
    gap: var(--space-lg);
    margin-top: var(--space-md);
    flex-wrap: wrap;
}

.chart-legend-custom .legend-item {
    display: flex;
    align-items: center;
    gap: var(--space-xs);
    font-size: var(--text-sm);
    color: var(--color-text-secondary);
}

.chart-legend-custom .legend-color {
    width: 12px;
    height: 12px;
    border-radius: var(--radius-sm);
}

/* Grid */
.grid-2 {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
}

.grid-gap-lg {
    gap: var(--space-lg);
}

/* Trend Indicator */
.trend-indicator {
    font-weight: var(--weight-semibold);
}

.trend-indicator.positive {
    color: var(--color-success);
}

.trend-indicator.negative {
    color: var(--color-error);
}

/* Badge variants */
.badge-success {
    background: rgba(34, 197, 94, 0.15);
    color: var(--color-success);
}

.badge-warning {
    background: rgba(251, 191, 36, 0.15);
    color: var(--color-warning);
}

.badge-danger {
    background: rgba(239, 68, 68, 0.15);
    color: var(--color-error);
}

.badge-primary {
    background: var(--color-accent-light);
    color: var(--color-accent);
}

/* Text utilities */
.text-right {
    text-align: right;
}

.text-muted {
    color: var(--color-text-muted);
    font-size: var(--text-sm);
}

.mb-lg {
    margin-bottom: var(--space-lg);
}

/* Responsive */
@media (max-width: 899px) {
    .series-selector {
        grid-template-columns: 1fr;
    }

    .filter-bar {
        flex-direction: column;
        align-items: stretch;
    }

    .filter-form {
        flex-direction: column;
        align-items: stretch;
    }

    .grid-2 {
        grid-template-columns: 1fr;
    }

    .chart-legend-bar {
        flex-direction: column;
        align-items: center;
        gap: var(--space-sm);
    }
}

@media (max-width: 767px) {
    .filter-bar,
    .card,
    .chart-legend-bar {
        margin-left: calc(-1 * var(--container-padding, 16px));
        margin-right: calc(-1 * var(--container-padding, 16px));
        border-radius: 0;
        border-left: none;
        border-right: none;
    }

    .admin-table {
        font-size: var(--text-xs);
    }

    .admin-table th,
    .admin-table td {
        padding: var(--space-xs);
    }
}
</style>

<?php if ($hasData): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Data från PHP
    const seriesTrends = <?= json_encode($seriesTrends) ?>;
    const seriesLabels = <?= json_encode($seriesLabels) ?>;
    const seriesColors = <?= json_encode($seriesColors) ?>;
    const years = seriesTrends[0] ? seriesTrends[0].map(d => d.year) : [];

    // Gemensamma chart-options
    const commonOptions = {
        responsive: true,
        maintainAspectRatio: false,
        interaction: {
            intersect: false,
            mode: 'index'
        },
        plugins: {
            legend: {
                display: false
            },
            tooltip: {
                backgroundColor: 'rgba(11, 19, 30, 0.9)',
                titleColor: '#f8f2f0',
                bodyColor: '#c7cfdd',
                borderColor: 'rgba(55, 212, 214, 0.3)',
                borderWidth: 1,
                padding: 12,
                displayColors: true,
                callbacks: {
                    title: function(items) {
                        return 'Säsong ' + items[0].label;
                    }
                }
            }
        },
        scales: {
            x: {
                grid: {
                    color: 'rgba(55, 212, 214, 0.1)'
                },
                ticks: {
                    color: '#868fa2'
                }
            },
            y: {
                grid: {
                    color: 'rgba(55, 212, 214, 0.1)'
                },
                ticks: {
                    color: '#868fa2'
                }
            }
        }
    };

    // Skapa datasets för alla valda serier
    function createDatasets(dataKey, suffix = '') {
        const datasets = [];
        Object.keys(seriesTrends).forEach((idx) => {
            const data = seriesTrends[idx];
            datasets.push({
                label: seriesLabels[idx] + suffix,
                data: data.map(d => d[dataKey]),
                borderColor: seriesColors[idx],
                backgroundColor: seriesColors[idx] + '20',
                fill: false,
                tension: 0.3,
                borderWidth: 3,
                pointRadius: 4,
                pointHoverRadius: 6
            });
        });
        return datasets;
    }

    // 1. DELTAGARCHART
    new Chart(document.getElementById('participantChart'), {
        type: 'line',
        data: {
            labels: years,
            datasets: createDatasets('total_riders')
        },
        options: {
            ...commonOptions,
            plugins: {
                ...commonOptions.plugins,
                tooltip: {
                    ...commonOptions.plugins.tooltip,
                    callbacks: {
                        ...commonOptions.plugins.tooltip.callbacks,
                        label: function(context) {
                            return context.dataset.label + ': ' + context.parsed.y.toLocaleString() + ' riders';
                        }
                    }
                }
            }
        }
    });

    // 2. RETENTION CHART
    new Chart(document.getElementById('retentionChart'), {
        type: 'line',
        data: {
            labels: years,
            datasets: createDatasets('retention_rate')
        },
        options: {
            ...commonOptions,
            scales: {
                ...commonOptions.scales,
                y: {
                    ...commonOptions.scales.y,
                    min: 0,
                    max: 100,
                    ticks: {
                        ...commonOptions.scales.y.ticks,
                        callback: value => value + '%'
                    }
                }
            },
            plugins: {
                ...commonOptions.plugins,
                tooltip: {
                    ...commonOptions.plugins.tooltip,
                    callbacks: {
                        ...commonOptions.plugins.tooltip.callbacks,
                        label: function(context) {
                            return context.dataset.label + ': ' + context.parsed.y + '%';
                        }
                    }
                }
            }
        }
    });

    // 3. GROWTH CHART
    const growthDatasets = [];
    Object.keys(seriesTrends).forEach((idx) => {
        const data = seriesTrends[idx];
        growthDatasets.push({
            label: seriesLabels[idx],
            data: data.map(d => d.growth_rate),
            backgroundColor: seriesColors[idx] + '80',
            borderColor: seriesColors[idx],
            borderWidth: 2,
            borderRadius: 4
        });
    });

    new Chart(document.getElementById('growthChart'), {
        type: 'bar',
        data: {
            labels: years,
            datasets: growthDatasets
        },
        options: {
            ...commonOptions,
            scales: {
                ...commonOptions.scales,
                y: {
                    ...commonOptions.scales.y,
                    ticks: {
                        ...commonOptions.scales.y.ticks,
                        callback: value => value + '%'
                    }
                }
            },
            plugins: {
                ...commonOptions.plugins,
                tooltip: {
                    ...commonOptions.plugins.tooltip,
                    callbacks: {
                        ...commonOptions.plugins.tooltip.callbacks,
                        label: function(context) {
                            const val = context.parsed.y;
                            return context.dataset.label + ': ' + (val >= 0 ? '+' : '') + val + '%';
                        }
                    }
                }
            }
        }
    });

    // 4. CROSS-PARTICIPATION CHART
    new Chart(document.getElementById('crossParticipationChart'), {
        type: 'line',
        data: {
            labels: years,
            datasets: createDatasets('cross_participation')
        },
        options: {
            ...commonOptions,
            scales: {
                ...commonOptions.scales,
                y: {
                    ...commonOptions.scales.y,
                    min: 0,
                    ticks: {
                        ...commonOptions.scales.y.ticks,
                        callback: value => value + '%'
                    }
                }
            },
            plugins: {
                ...commonOptions.plugins,
                tooltip: {
                    ...commonOptions.plugins.tooltip,
                    callbacks: {
                        ...commonOptions.plugins.tooltip.callbacks,
                        label: function(context) {
                            return context.dataset.label + ': ' + context.parsed.y + '%';
                        }
                    }
                }
            }
        }
    });

    // 5. NEW RIDERS CHART
    new Chart(document.getElementById('newRidersChart'), {
        type: 'line',
        data: {
            labels: years,
            datasets: createDatasets('new_riders')
        },
        options: {
            ...commonOptions,
            plugins: {
                ...commonOptions.plugins,
                tooltip: {
                    ...commonOptions.plugins.tooltip,
                    callbacks: {
                        ...commonOptions.plugins.tooltip.callbacks,
                        label: function(context) {
                            return context.dataset.label + ': ' + context.parsed.y.toLocaleString() + ' nya';
                        }
                    }
                }
            }
        }
    });

    // 6. GENDER CHART
    new Chart(document.getElementById('genderChart'), {
        type: 'line',
        data: {
            labels: years,
            datasets: createDatasets('female_pct')
        },
        options: {
            ...commonOptions,
            scales: {
                ...commonOptions.scales,
                y: {
                    ...commonOptions.scales.y,
                    min: 0,
                    max: 50,
                    ticks: {
                        ...commonOptions.scales.y.ticks,
                        callback: value => value + '%'
                    }
                }
            },
            plugins: {
                ...commonOptions.plugins,
                tooltip: {
                    ...commonOptions.plugins.tooltip,
                    callbacks: {
                        ...commonOptions.plugins.tooltip.callbacks,
                        label: function(context) {
                            return context.dataset.label + ': ' + context.parsed.y + '% kvinnor';
                        }
                    }
                }
            }
        }
    });

    // 7. AGE CHART
    new Chart(document.getElementById('ageChart'), {
        type: 'line',
        data: {
            labels: years,
            datasets: createDatasets('average_age')
        },
        options: {
            ...commonOptions,
            scales: {
                ...commonOptions.scales,
                y: {
                    ...commonOptions.scales.y,
                    ticks: {
                        ...commonOptions.scales.y.ticks,
                        callback: value => value + ' år'
                    }
                }
            },
            plugins: {
                ...commonOptions.plugins,
                tooltip: {
                    ...commonOptions.plugins.tooltip,
                    callbacks: {
                        ...commonOptions.plugins.tooltip.callbacks,
                        label: function(context) {
                            return context.dataset.label + ': ' + context.parsed.y.toFixed(1) + ' år';
                        }
                    }
                }
            }
        }
    });
});
</script>
<?php endif; ?>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>

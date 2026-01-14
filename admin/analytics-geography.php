<?php
/**
 * Analytics - Geographic Analysis
 *
 * Regional analys av riderfordelning, tackning
 * och geografiska trender.
 *
 * Behorighet: super_admin ELLER statistics-permission
 *
 * @package TheHUB Analytics
 * @version 2.0
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../analytics/includes/KPICalculator.php';
require_once __DIR__ . '/../analytics/includes/AnalyticsConfig.php';

requireAnalyticsAccess();

global $pdo;

$currentYear = (int)date('Y');
$selectedYear = isset($_GET['year']) ? (int)$_GET['year'] : $currentYear;

$availableYears = [];
try {
    $stmt = $pdo->query("SELECT DISTINCT season_year FROM rider_yearly_stats ORDER BY season_year DESC");
    $availableYears = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $availableYears = range($currentYear, $currentYear - 5);
}

$kpiCalc = new KPICalculator($pdo);

$ridersByRegion = [];
$eventsByRegion = [];
$underservedRegions = [];
$regionalTrends = [];

try {
    $ridersByRegion = $kpiCalc->getRidersByRegion($selectedYear);
    $eventsByRegion = $kpiCalc->getEventsByRegion($selectedYear);
    $underservedRegions = $kpiCalc->getUnderservedRegions($selectedYear);
    $regionalTrends = $kpiCalc->getRegionalGrowthTrend(5);
} catch (Exception $e) {
    $error = $e->getMessage();
}

// Export
if (isset($_GET['export']) && $_GET['export'] === 'csv' && !isset($error)) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="thehub-geography-' . $selectedYear . '.csv"');
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

    fputcsv($output, ['Region', 'Antal Riders', 'Befolkning', 'Riders per 100k', 'Events']);
    foreach ($underservedRegions as $region) {
        $events = 0;
        foreach ($eventsByRegion as $er) {
            if ($er['region'] === $region['region']) {
                $events = $er['event_count'];
                break;
            }
        }
        fputcsv($output, [
            $region['region'],
            $region['rider_count'],
            $region['population'] ?? '',
            $region['riders_per_100k'] ?? '',
            $events
        ]);
    }
    fclose($output);
    exit;
}

$page_title = 'Geografisk Analys';
$breadcrumbs = [
    ['label' => 'Analytics', 'url' => '/admin/analytics-dashboard.php'],
    ['label' => 'Geografi']
];

$page_actions = '
<div class="btn-group">
    <a href="/admin/analytics-dashboard.php" class="btn-admin btn-admin-secondary">
        <i data-lucide="arrow-left"></i> Dashboard
    </a>
</div>
';

include __DIR__ . '/components/unified-layout.php';
?>

<!-- Year Selector -->
<div class="filter-bar">
    <form method="get" class="filter-form">
        <div class="filter-group">
            <label class="filter-label">Sasong</label>
            <select name="year" class="form-select" onchange="this.form.submit()">
                <?php foreach ($availableYears as $year): ?>
                    <option value="<?= $year ?>" <?= $year == $selectedYear ? 'selected' : '' ?>>
                        <?= $year ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <?php if (!empty($underservedRegions)): ?>
        <div class="filter-group">
            <a href="?year=<?= $selectedYear ?>&export=csv" class="btn-admin btn-admin-secondary">
                <i data-lucide="download"></i> Exportera CSV
            </a>
        </div>
        <?php endif; ?>
    </form>
</div>

<?php if (isset($error)): ?>
<div class="alert alert-warning">
    <i data-lucide="alert-triangle"></i>
    <div>
        <strong>Fel</strong><br>
        <?= htmlspecialchars($error) ?>
    </div>
</div>
<?php else: ?>

<!-- Overview Metrics -->
<?php
$totalRiders = array_sum(array_column($ridersByRegion, 'rider_count'));
$totalEvents = array_sum(array_column($eventsByRegion, 'event_count'));
$regionsWithRiders = count(array_filter($ridersByRegion, fn($r) => $r['rider_count'] > 0));
?>
<div class="dashboard-metrics">
    <div class="metric-card metric-card--primary">
        <div class="metric-icon">
            <i data-lucide="map"></i>
        </div>
        <div class="metric-content">
            <div class="metric-value"><?= $regionsWithRiders ?></div>
            <div class="metric-label">Regioner med riders</div>
        </div>
    </div>

    <div class="metric-card">
        <div class="metric-icon">
            <i data-lucide="users"></i>
        </div>
        <div class="metric-content">
            <div class="metric-value"><?= number_format($totalRiders) ?></div>
            <div class="metric-label">Totalt riders</div>
        </div>
    </div>

    <div class="metric-card">
        <div class="metric-icon">
            <i data-lucide="calendar"></i>
        </div>
        <div class="metric-content">
            <div class="metric-value"><?= number_format($totalEvents) ?></div>
            <div class="metric-label">Totalt events</div>
        </div>
    </div>
</div>

<!-- Main Grid -->
<div class="grid grid-2 grid-gap-lg">
    <!-- Riders per Region -->
    <div class="admin-card">
        <div class="admin-card-header">
            <h2>Riders per region <?= $selectedYear ?></h2>
        </div>
        <div class="admin-card-body">
            <canvas id="ridersChart" height="300"></canvas>
        </div>
    </div>

    <!-- Per Capita Coverage -->
    <div class="admin-card">
        <div class="admin-card-header">
            <h2>Tackning per capita</h2>
            <span class="badge badge-secondary">Riders per 100k inv</span>
        </div>
        <div class="admin-card-body" style="padding: 0;">
            <div class="table-responsive" style="max-height: 350px;">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Region</th>
                            <th>Riders</th>
                            <th>Per 100k</th>
                            <th>Tackning</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $maxPerCapita = max(array_filter(array_column($underservedRegions, 'riders_per_100k'))) ?: 1;
                        foreach ($underservedRegions as $region):
                            if ($region['riders_per_100k'] === null) continue;
                            $barWidth = ($region['riders_per_100k'] / $maxPerCapita) * 100;
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($region['region']) ?></td>
                            <td><?= number_format($region['rider_count']) ?></td>
                            <td><?= number_format($region['riders_per_100k'], 1) ?></td>
                            <td>
                                <div class="progress-cell">
                                    <div class="progress-bar-mini" style="width: 100px;">
                                        <div class="progress-fill" style="width: <?= $barWidth ?>%;"></div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Events by Region -->
<div class="admin-card">
    <div class="admin-card-header">
        <h2>Events per region <?= $selectedYear ?></h2>
    </div>
    <div class="admin-card-body" style="padding: 0;">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Region</th>
                    <th>Events</th>
                    <th>Deltagare</th>
                    <th>Enduro</th>
                    <th>DH</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($eventsByRegion as $region): ?>
                <tr>
                    <td><?= htmlspecialchars($region['region']) ?></td>
                    <td><strong><?= number_format($region['event_count']) ?></strong></td>
                    <td><?= number_format($region['participant_count']) ?></td>
                    <td><?= $region['enduro_events'] ?? 0 ?></td>
                    <td><?= $region['dh_events'] ?? 0 ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Regional Trends -->
<?php if (!empty($regionalTrends)): ?>
<div class="admin-card">
    <div class="admin-card-header">
        <h2>Regional tillvaxttend (5 ar)</h2>
    </div>
    <div class="admin-card-body" style="padding: 0;">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Region</th>
                    <th>2020</th>
                    <th>2021</th>
                    <th>2022</th>
                    <th>2023</th>
                    <th>2024</th>
                    <th>Tillvaxt</th>
                    <th>Trend</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (array_slice($regionalTrends, 0, 15) as $region): ?>
                <tr>
                    <td><?= htmlspecialchars($region['region']) ?></td>
                    <?php
                    $yearMap = [];
                    foreach ($region['years'] as $y) {
                        $yearMap[$y['year']] = $y['count'];
                    }
                    for ($y = $currentYear - 4; $y <= $currentYear; $y++):
                    ?>
                    <td><?= isset($yearMap[$y]) ? number_format($yearMap[$y]) : '-' ?></td>
                    <?php endfor; ?>
                    <td>
                        <span class="<?= $region['growth_pct'] >= 0 ? 'text-success' : 'text-danger' ?>">
                            <?= $region['growth_pct'] >= 0 ? '+' : '' ?><?= $region['growth_pct'] ?>%
                        </span>
                    </td>
                    <td>
                        <?php
                        $trendIcon = match($region['trend']) {
                            'growing' => 'trending-up',
                            'declining' => 'trending-down',
                            default => 'minus'
                        };
                        $trendClass = match($region['trend']) {
                            'growing' => 'text-success',
                            'declining' => 'text-danger',
                            default => 'text-muted'
                        };
                        ?>
                        <i data-lucide="<?= $trendIcon ?>" class="<?= $trendClass ?>" style="width:18px;height:18px;"></i>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('ridersChart');
    if (ctx) {
        const data = <?= json_encode(array_slice($ridersByRegion, 0, 15)) ?>;
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: data.map(r => r.region),
                datasets: [{
                    label: 'Antal riders',
                    data: data.map(r => r.rider_count),
                    backgroundColor: 'rgba(55, 212, 214, 0.8)',
                    borderColor: 'rgba(55, 212, 214, 1)',
                    borderWidth: 1
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    x: { beginAtZero: true }
                }
            }
        });
    }
});
</script>

<?php endif; ?>

<style>
.filter-bar {
    display: flex;
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
    align-items: flex-end;
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

.grid-2 {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
}

.grid-gap-lg {
    gap: var(--space-lg);
    margin-bottom: var(--space-xl);
}

.progress-cell {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
}

.progress-bar-mini {
    height: 6px;
    background: var(--color-bg-hover);
    border-radius: var(--radius-full);
    overflow: hidden;
}

.progress-fill {
    height: 100%;
    background: var(--color-accent);
    border-radius: var(--radius-full);
}

.text-success {
    color: var(--color-success);
}

.text-danger {
    color: var(--color-error);
}

.text-muted {
    color: var(--color-text-muted);
}

.table-responsive {
    overflow-x: auto;
}

@media (max-width: 899px) {
    .grid-2 {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 767px) {
    .filter-bar {
        margin-left: calc(-1 * var(--container-padding, 16px));
        margin-right: calc(-1 * var(--container-padding, 16px));
        border-radius: 0;
        border-left: none;
        border-right: none;
    }
}
</style>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>

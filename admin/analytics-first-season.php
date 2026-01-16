<?php
/**
 * Analytics - First Season Journey Analysis
 *
 * Analyserar rookies forsta sasong: engagemang, prestation,
 * retention patterns och brand comparison.
 *
 * Behorighet: super_admin ELLER statistics-permission
 *
 * @package TheHUB Analytics
 * @version 3.1
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../analytics/includes/KPICalculator.php';
require_once __DIR__ . '/../analytics/includes/AnalyticsConfig.php';

// Kraver super_admin eller statistics-behorighet
requireAnalyticsAccess();

global $pdo;

// Parameters
$currentYear = (int)date('Y');
$selectedCohort = isset($_GET['cohort']) ? (int)$_GET['cohort'] : $currentYear - 2;
$selectedBrands = [];
if (isset($_GET['brands'])) {
    if (is_array($_GET['brands'])) {
        $selectedBrands = array_map('intval', $_GET['brands']);
    } else {
        $selectedBrands = array_map('intval', explode(',', $_GET['brands']));
    }
    $selectedBrands = array_filter($selectedBrands);
}
$viewMode = $_GET['view'] ?? 'overview'; // overview, retention, patterns, brands

// Initialize KPI Calculator
$kpiCalc = new KPICalculator($pdo);

// Fetch available data
$availableCohorts = [];
$availableBrands = [];
$summary = [];
$longitudinal = [];
$patterns = [];
$retentionByStarts = [];
$brandComparison = [];
$error = null;

try {
    // Get available cohorts and brands
    $availableCohorts = $kpiCalc->getAvailableCohortYears($selectedBrands ?: null);
    $availableBrands = $kpiCalc->getAvailableBrandsForJourney($selectedCohort);

    if ($selectedCohort) {
        // Fetch journey data with optional brand filter
        $brandFilter = !empty($selectedBrands) ? $selectedBrands : null;

        $summary = $kpiCalc->getFirstSeasonJourneySummary($selectedCohort, $brandFilter);
        $longitudinal = $kpiCalc->getCohortLongitudinalOverview($selectedCohort, $brandFilter);
        $patterns = $kpiCalc->getJourneyTypeDistribution($selectedCohort, $brandFilter);
        $retentionByStarts = $kpiCalc->getRetentionByStartCount($selectedCohort, $brandFilter);

        // Brand comparison if multiple brands selected
        if (count($selectedBrands) >= 2) {
            $brandComparison = $kpiCalc->getBrandJourneyComparison($selectedCohort, $selectedBrands);
        }
    }
} catch (Exception $e) {
    $error = $e->getMessage();
}

// Export handler
if (isset($_GET['export']) && in_array($_GET['export'], ['csv', 'json']) && !$error) {
    $exportData = $kpiCalc->exportJourneyData(
        $selectedCohort,
        !empty($selectedBrands) ? $selectedBrands : null,
        $_GET['export']
    );

    if ($_GET['export'] === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $exportData['filename'] . '"');
        echo "\xEF\xBB\xBF"; // UTF-8 BOM
        echo $exportData['data'];
    } else {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $exportData['filename'] . '"');
        echo json_encode($exportData['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    // Log export
    try {
        $stmt = $pdo->prepare("INSERT INTO analytics_exports (export_type, export_params, exported_by, row_count, ip_address) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([
            'first_season_journey',
            json_encode(['cohort' => $selectedCohort, 'brands' => $selectedBrands]),
            $_SESSION['user_id'] ?? null,
            $summary['total_rookies'] ?? 0,
            $_SERVER['REMOTE_ADDR'] ?? null
        ]);
    } catch (Exception $e) { /* Ignore */ }

    exit;
}

// Page config
$page_title = 'First Season Journey';
$breadcrumbs = [
    ['label' => 'Analytics', 'url' => '/admin/analytics-dashboard.php'],
    ['label' => 'First Season Journey']
];

$page_actions = '
<div class="btn-group">
    <a href="/admin/analytics-dashboard.php" class="btn-admin btn-admin-secondary">
        <i data-lucide="arrow-left"></i> Dashboard
    </a>
</div>
';

// Include unified layout
include __DIR__ . '/components/unified-layout.php';
?>

<!-- Info Box -->
<div class="info-box">
    <div class="info-box-icon">
        <i data-lucide="baby"></i>
    </div>
    <div class="info-box-content">
        <strong>First Season Journey Analysis</strong>
        <p>Analyserar rookies (forsta-gangs-deltagare) under deras forsta sasong. Se hur olika faktorer som antal starter, brand och engagemangsniva paverkar retention och karriarlangd.</p>
        <p style="margin-top: var(--space-xs); font-size: var(--text-xs); color: var(--color-text-muted);">
            <i data-lucide="shield-check" style="width: 12px; height: 12px; display: inline-block; vertical-align: middle;"></i>
            GDPR-sakrad: Endast aggregerade varden visas (minimum 10 individer per segment)
        </p>
    </div>
</div>

<!-- Filter Bar -->
<div class="filter-bar">
    <form method="get" class="filter-form" id="filterForm">
        <div class="filter-group">
            <label class="filter-label">Kohort (startår)</label>
            <select name="cohort" class="form-select" onchange="this.form.submit()">
                <option value="">-- Valj kohort --</option>
                <?php foreach ($availableCohorts as $c): ?>
                    <option value="<?= $c['cohort_year'] ?>" <?= $c['cohort_year'] == $selectedCohort ? 'selected' : '' ?>>
                        <?= $c['cohort_year'] ?> (<?= number_format($c['rookie_count']) ?> rookies)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <?php if (!empty($availableBrands)): ?>
        <div class="filter-group filter-group--brands">
            <label class="filter-label">Varumarken (max 12)</label>
            <div class="brand-selector">
                <?php foreach (array_slice($availableBrands, 0, 12) as $brand): ?>
                <label class="brand-chip <?= in_array($brand['id'], $selectedBrands) ? 'selected' : '' ?>"
                       style="<?= $brand['color_primary'] ? '--chip-color: ' . htmlspecialchars($brand['color_primary']) : '' ?>">
                    <input type="checkbox" name="brands[]" value="<?= $brand['id'] ?>"
                           <?= in_array($brand['id'], $selectedBrands) ? 'checked' : '' ?>
                           onchange="document.getElementById('filterForm').submit()">
                    <span class="brand-chip-name"><?= htmlspecialchars($brand['short_code'] ?? $brand['name']) ?></span>
                    <span class="brand-chip-count"><?= number_format($brand['rookie_count']) ?></span>
                </label>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="filter-group filter-group--actions">
            <?php if ($selectedCohort && !empty($summary) && empty($summary['suppressed'])): ?>
            <div class="export-buttons">
                <a href="?cohort=<?= $selectedCohort ?><?= !empty($selectedBrands) ? '&brands=' . implode(',', $selectedBrands) : '' ?>&export=csv"
                   class="btn-admin btn-admin-secondary btn-sm">
                    <i data-lucide="download"></i> CSV
                </a>
                <a href="?cohort=<?= $selectedCohort ?><?= !empty($selectedBrands) ? '&brands=' . implode(',', $selectedBrands) : '' ?>&export=json"
                   class="btn-admin btn-admin-secondary btn-sm">
                    <i data-lucide="download"></i> JSON
                </a>
            </div>
            <?php endif; ?>
        </div>
    </form>
</div>

<?php if ($error): ?>
<div class="alert alert-warning">
    <i data-lucide="alert-triangle"></i>
    <div>
        <strong>Fel vid inlasning</strong><br>
        <?= htmlspecialchars($error) ?>
    </div>
</div>

<?php elseif (empty($availableCohorts)): ?>
<div class="alert alert-info">
    <i data-lucide="info"></i>
    <div>
        <strong>Ingen data tillganglig</strong><br>
        Kor <a href="/admin/analytics-populate.php">Populate Historical</a> for att generera journey-data.
    </div>
</div>

<?php elseif (!empty($summary) && empty($summary['suppressed'])): ?>

<!-- Selected Brands Indicator -->
<?php if (!empty($selectedBrands)): ?>
<div class="alert alert-info" style="margin-bottom: var(--space-lg);">
    <i data-lucide="filter"></i>
    <div>
        Filtrerar pa <?= count($selectedBrands) ?> varumarke(n).
        <a href="?cohort=<?= $selectedCohort ?>">Visa alla</a>
    </div>
</div>
<?php endif; ?>

<!-- Summary Metrics -->
<div class="dashboard-metrics">
    <div class="metric-card metric-card--primary">
        <div class="metric-icon">
            <i data-lucide="users"></i>
        </div>
        <div class="metric-content">
            <div class="metric-value"><?= number_format($summary['total_rookies']) ?></div>
            <div class="metric-label">Rookies <?= $selectedCohort ?></div>
        </div>
    </div>

    <div class="metric-card">
        <div class="metric-icon">
            <i data-lucide="flag"></i>
        </div>
        <div class="metric-content">
            <div class="metric-value"><?= $summary['avg_starts'] ?></div>
            <div class="metric-label">Snitt starter</div>
        </div>
    </div>

    <div class="metric-card metric-card--success">
        <div class="metric-icon">
            <i data-lucide="user-check"></i>
        </div>
        <div class="metric-content">
            <div class="metric-value"><?= $summary['return_rate_y2'] ?>%</div>
            <div class="metric-label">Aterkom ar 2</div>
        </div>
    </div>

    <div class="metric-card">
        <div class="metric-icon">
            <i data-lucide="trending-up"></i>
        </div>
        <div class="metric-content">
            <div class="metric-value"><?= $summary['avg_percentile'] ?></div>
            <div class="metric-label">Snitt percentil</div>
        </div>
    </div>

    <div class="metric-card metric-card--accent">
        <div class="metric-icon">
            <i data-lucide="zap"></i>
        </div>
        <div class="metric-content">
            <div class="metric-value"><?= $summary['avg_engagement'] ?></div>
            <div class="metric-label">Engagemang</div>
        </div>
    </div>

    <div class="metric-card">
        <div class="metric-icon">
            <i data-lucide="calendar"></i>
        </div>
        <div class="metric-content">
            <div class="metric-value"><?= $summary['avg_career_length'] ?></div>
            <div class="metric-label">Snitt karriar (ar)</div>
        </div>
    </div>
</div>

<!-- Main Content Grid -->
<div class="grid grid-2 grid-gap-lg">
    <!-- Retention Funnel -->
    <div class="admin-card">
        <div class="admin-card-header">
            <h2><i data-lucide="filter"></i> Retention Funnel</h2>
        </div>
        <div class="admin-card-body">
            <?php if (!empty($longitudinal) && empty($longitudinal['suppressed'])): ?>
            <div class="retention-funnel">
                <?php
                $baseSize = $longitudinal['funnel'][0]['cohort_size'] ?? 1;
                foreach ($longitudinal['funnel'] as $year):
                    $widthPct = ($year['active_count'] / $baseSize) * 100;
                ?>
                <div class="funnel-row">
                    <div class="funnel-label">
                        <span class="funnel-year">Ar <?= $year['year_offset'] ?></span>
                        <span class="funnel-stats"><?= number_format($year['active_count']) ?> aktiva</span>
                    </div>
                    <div class="funnel-bar-container">
                        <div class="funnel-bar" style="width: <?= $widthPct ?>%;">
                            <span class="funnel-pct"><?= $year['retention_rate'] ?>%</span>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <p class="text-muted">Data saknas eller ar GDPR-suppressad.</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Engagement Distribution -->
    <div class="admin-card">
        <div class="admin-card-header">
            <h2><i data-lucide="pie-chart"></i> Engagemang-fordelning</h2>
        </div>
        <div class="admin-card-body">
            <canvas id="engagementChart" height="250"></canvas>
        </div>
    </div>
</div>

<!-- Journey Patterns -->
<?php if (!empty($patterns) && empty($patterns['suppressed'])): ?>
<div class="admin-card">
    <div class="admin-card-header">
        <h2><i data-lucide="git-branch"></i> Journey Patterns</h2>
        <span class="badge"><?= $patterns['total_classified'] ?> klassificerade</span>
    </div>
    <div class="admin-card-body" style="padding: 0;">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Monster</th>
                    <th>Beskrivning</th>
                    <th style="text-align: right;">Antal</th>
                    <th style="text-align: right;">Andel</th>
                    <th>Fordelning</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $patternDescriptions = [
                    'continuous_4yr' => 'Aktiv alla 4 aren',
                    'continuous_3yr' => 'Aktiv 3 ar i rad, sedan slut',
                    'continuous_2yr' => 'Aktiv 2 ar i rad, sedan slut',
                    'one_and_done' => 'Endast forsta sasongen',
                    'gap_returner' => 'Tog paus, kom tillbaka',
                    'late_dropout' => 'Aktiv 2-3 ar, sedan borta'
                ];
                $patternColors = [
                    'continuous_4yr' => 'var(--color-success)',
                    'continuous_3yr' => 'rgb(16, 185, 129)',
                    'continuous_2yr' => 'rgb(251, 191, 36)',
                    'one_and_done' => 'rgb(239, 68, 68)',
                    'gap_returner' => 'rgb(139, 92, 246)',
                    'late_dropout' => 'rgb(156, 163, 175)'
                ];
                foreach ($patterns['distribution'] as $p):
                ?>
                <tr>
                    <td>
                        <span class="pattern-badge" style="background: <?= $patternColors[$p['pattern']] ?? 'var(--color-text-muted)' ?>">
                            <?= htmlspecialchars(str_replace('_', ' ', ucfirst($p['pattern']))) ?>
                        </span>
                    </td>
                    <td><?= $patternDescriptions[$p['pattern']] ?? '-' ?></td>
                    <td style="text-align: right;"><?= number_format($p['count']) ?></td>
                    <td style="text-align: right;"><?= $p['percentage'] ?>%</td>
                    <td>
                        <div class="progress-cell">
                            <div class="progress-bar-mini" style="width: 120px;">
                                <div class="progress-fill" style="width: <?= $p['percentage'] ?>%; background: <?= $patternColors[$p['pattern']] ?? 'var(--color-accent)' ?>;"></div>
                            </div>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Retention by Start Count -->
<?php if (!empty($retentionByStarts) && empty($retentionByStarts['suppressed'])): ?>
<div class="admin-card">
    <div class="admin-card-header">
        <h2><i data-lucide="bar-chart-2"></i> Retention per antal starter (forsta sasong)</h2>
    </div>
    <div class="admin-card-body">
        <div class="retention-bars">
            <?php foreach ($retentionByStarts['buckets'] as $bucket): ?>
            <div class="retention-bar-group">
                <div class="retention-bar-label">
                    <strong><?= htmlspecialchars($bucket['bucket']) ?></strong>
                    <span><?= number_format($bucket['rider_count']) ?> st</span>
                </div>
                <div class="retention-bar-visual">
                    <div class="bar-y2" style="width: <?= $bucket['return_rate_y2'] ?>%;" title="Ar 2: <?= $bucket['return_rate_y2'] ?>%">
                        <?= $bucket['return_rate_y2'] ?>%
                    </div>
                    <div class="bar-y3" style="width: <?= $bucket['return_rate_y3'] ?>%;" title="Ar 3: <?= $bucket['return_rate_y3'] ?>%">
                        <?= $bucket['return_rate_y3'] ?>%
                    </div>
                </div>
                <div class="retention-bar-meta">
                    <span class="career-avg">Snitt karriar: <?= $bucket['avg_career'] ?> ar</span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="legend" style="margin-top: var(--space-md);">
            <span class="legend-item"><span class="legend-color" style="background: var(--color-accent);"></span> Aterkom ar 2</span>
            <span class="legend-item"><span class="legend-color" style="background: var(--color-success);"></span> Aterkom ar 3</span>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Brand Comparison (if multiple brands selected) -->
<?php if (!empty($brandComparison) && !empty($brandComparison['brands'])): ?>
<div class="admin-card">
    <div class="admin-card-header">
        <h2><i data-lucide="git-compare"></i> Brand Comparison</h2>
        <span class="badge"><?= $brandComparison['brand_count'] ?> varumarken</span>
    </div>
    <div class="admin-card-body" style="padding: 0;">
        <div class="admin-table-container" style="overflow-x: auto;">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Varumarke</th>
                        <th style="text-align: right;">Rookies</th>
                        <th style="text-align: right;">Snitt starter</th>
                        <th style="text-align: right;">Snitt percentil</th>
                        <th style="text-align: right;">Engagemang</th>
                        <th style="text-align: right;">Return Y2</th>
                        <th style="text-align: right;">Return Y3</th>
                        <th style="text-align: right;">Snitt karriar</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($brandComparison['brands'] as $brand): ?>
                    <tr>
                        <td>
                            <span class="brand-indicator" style="background: <?= htmlspecialchars($brand['color'] ?? 'var(--color-accent)') ?>;"></span>
                            <?= htmlspecialchars($brand['brand_name']) ?>
                            <?php if ($brand['short_code']): ?>
                            <span class="text-muted">(<?= htmlspecialchars($brand['short_code']) ?>)</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align: right;"><?= number_format($brand['rookie_count']) ?></td>
                        <td style="text-align: right;"><?= $brand['avg_starts'] ?></td>
                        <td style="text-align: right;"><?= $brand['avg_percentile'] ?></td>
                        <td style="text-align: right;"><?= $brand['avg_engagement'] ?></td>
                        <td style="text-align: right;">
                            <span class="<?= $brand['return_rate_y2'] >= 50 ? 'text-success' : ($brand['return_rate_y2'] < 30 ? 'text-warning' : '') ?>">
                                <?= $brand['return_rate_y2'] ?>%
                            </span>
                        </td>
                        <td style="text-align: right;">
                            <span class="<?= $brand['return_rate_y3'] >= 40 ? 'text-success' : ($brand['return_rate_y3'] < 20 ? 'text-warning' : '') ?>">
                                <?= $brand['return_rate_y3'] ?>%
                            </span>
                        </td>
                        <td style="text-align: right;"><?= $brand['avg_career_seasons'] ?> ar</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Brand Comparison Chart -->
<div class="admin-card">
    <div class="admin-card-header">
        <h2><i data-lucide="bar-chart-horizontal"></i> Brand Retention Comparison</h2>
    </div>
    <div class="admin-card-body">
        <canvas id="brandComparisonChart" height="300"></canvas>
    </div>
</div>
<?php endif; ?>

<!-- Charts -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Engagement Distribution Chart
    const engagementCtx = document.getElementById('engagementChart');
    if (engagementCtx) {
        new Chart(engagementCtx, {
            type: 'doughnut',
            data: {
                labels: ['Hogt engagemang', 'Moderat', 'Lagt engagemang'],
                datasets: [{
                    data: [
                        <?= $summary['engagement_distribution']['high'] ?? 0 ?>,
                        <?= $summary['engagement_distribution']['moderate'] ?? 0 ?>,
                        <?= $summary['engagement_distribution']['low'] ?? 0 ?>
                    ],
                    backgroundColor: [
                        'rgb(16, 185, 129)',
                        'rgb(251, 191, 36)',
                        'rgb(239, 68, 68)'
                    ]
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { position: 'bottom' }
                }
            }
        });
    }

    <?php if (!empty($brandComparison) && !empty($brandComparison['brands'])): ?>
    // Brand Comparison Chart
    const brandCtx = document.getElementById('brandComparisonChart');
    if (brandCtx) {
        const brandData = <?= json_encode($brandComparison['brands']) ?>;
        new Chart(brandCtx, {
            type: 'bar',
            data: {
                labels: brandData.map(b => b.short_code || b.brand_name),
                datasets: [
                    {
                        label: 'Return Year 2 (%)',
                        data: brandData.map(b => b.return_rate_y2),
                        backgroundColor: 'rgba(55, 212, 214, 0.8)',
                        borderColor: 'rgb(55, 212, 214)',
                        borderWidth: 1
                    },
                    {
                        label: 'Return Year 3 (%)',
                        data: brandData.map(b => b.return_rate_y3),
                        backgroundColor: 'rgba(16, 185, 129, 0.8)',
                        borderColor: 'rgb(16, 185, 129)',
                        borderWidth: 1
                    }
                ]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { position: 'bottom' }
                },
                scales: {
                    y: {
                        min: 0,
                        max: 100,
                        title: { display: true, text: '%' }
                    }
                }
            }
        });
    }
    <?php endif; ?>
});
</script>

<?php elseif (!empty($summary) && !empty($summary['suppressed'])): ?>
<div class="alert alert-warning">
    <i data-lucide="shield-alert"></i>
    <div>
        <strong>Data suppressad (GDPR)</strong><br>
        <?= htmlspecialchars($summary['reason'] ?? 'For fa individer i urvalet') ?>
    </div>
</div>
<?php endif; ?>

<style>
/* Info Box */
.info-box {
    display: flex;
    gap: var(--space-md);
    padding: var(--space-md);
    margin-bottom: var(--space-lg);
    background: var(--color-accent-light);
    border: 1px solid var(--color-accent);
    border-radius: var(--radius-md);
}

.info-box-icon {
    flex-shrink: 0;
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--color-accent);
    border-radius: var(--radius-sm);
    color: white;
}

.info-box-icon i {
    width: 18px;
    height: 18px;
}

.info-box-content {
    flex: 1;
}

.info-box-content strong {
    display: block;
    margin-bottom: var(--space-xs);
    color: var(--color-text-primary);
}

.info-box-content p {
    margin: 0;
    font-size: var(--text-sm);
    color: var(--color-text-secondary);
    line-height: 1.5;
}

/* Filter Bar */
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
    align-items: flex-start;
    flex-wrap: wrap;
    width: 100%;
}

.filter-group {
    display: flex;
    flex-direction: column;
    gap: var(--space-xs);
}

.filter-group--brands {
    flex: 1;
    min-width: 300px;
}

.filter-group--actions {
    margin-left: auto;
    justify-content: flex-end;
}

.filter-label {
    font-size: var(--text-sm);
    color: var(--color-text-secondary);
    font-weight: var(--weight-medium);
}

/* Brand Selector */
.brand-selector {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-xs);
}

.brand-chip {
    display: inline-flex;
    align-items: center;
    gap: var(--space-xs);
    padding: var(--space-xs) var(--space-sm);
    background: var(--color-bg-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-full);
    cursor: pointer;
    font-size: var(--text-sm);
    transition: all 0.15s ease;
}

.brand-chip:hover {
    border-color: var(--chip-color, var(--color-accent));
}

.brand-chip.selected {
    background: var(--chip-color, var(--color-accent));
    border-color: var(--chip-color, var(--color-accent));
    color: white;
}

.brand-chip input {
    display: none;
}

.brand-chip-count {
    font-size: var(--text-xs);
    opacity: 0.7;
}

/* Export buttons */
.export-buttons {
    display: flex;
    gap: var(--space-xs);
}

/* Dashboard Metrics Grid */
.dashboard-metrics {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: var(--space-md);
    margin-bottom: var(--space-xl);
}

/* Metric Cards */
.metric-card {
    display: flex;
    align-items: flex-start;
    gap: var(--space-md);
    padding: var(--space-lg);
    background: var(--color-bg-card);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
}

.metric-card--primary { border-left: 3px solid var(--color-accent); }
.metric-card--success { border-left: 3px solid var(--color-success); }
.metric-card--warning { border-left: 3px solid var(--color-warning); }
.metric-card--accent { border-left: 3px solid var(--color-accent); }

.metric-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 40px;
    height: 40px;
    background: var(--color-bg-hover);
    border-radius: var(--radius-md);
    color: var(--color-accent);
    flex-shrink: 0;
}

.metric-icon i { width: 20px; height: 20px; }

.metric-content { flex: 1; min-width: 0; }

.metric-value {
    font-size: var(--text-2xl);
    font-weight: var(--weight-bold);
    color: var(--color-text-primary);
    line-height: 1.2;
}

.metric-label {
    font-size: var(--text-sm);
    color: var(--color-text-secondary);
    margin-top: var(--space-2xs);
}

/* Grid */
.grid-2 {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
}

.grid-gap-lg {
    gap: var(--space-lg);
    margin-bottom: var(--space-xl);
}

/* Retention Funnel */
.retention-funnel {
    display: flex;
    flex-direction: column;
    gap: var(--space-sm);
}

.funnel-row {
    display: flex;
    align-items: center;
    gap: var(--space-md);
}

.funnel-label {
    width: 100px;
    flex-shrink: 0;
}

.funnel-year {
    display: block;
    font-weight: var(--weight-semibold);
    color: var(--color-text-primary);
}

.funnel-stats {
    display: block;
    font-size: var(--text-xs);
    color: var(--color-text-muted);
}

.funnel-bar-container {
    flex: 1;
    background: var(--color-bg-hover);
    border-radius: var(--radius-sm);
    overflow: hidden;
}

.funnel-bar {
    height: 32px;
    background: linear-gradient(90deg, var(--color-accent), var(--color-success));
    border-radius: var(--radius-sm);
    display: flex;
    align-items: center;
    justify-content: flex-end;
    padding-right: var(--space-sm);
    min-width: 50px;
    transition: width 0.3s ease;
}

.funnel-pct {
    color: white;
    font-weight: var(--weight-bold);
    font-size: var(--text-sm);
    text-shadow: 0 1px 2px rgba(0,0,0,0.3);
}

/* Pattern Badge */
.pattern-badge {
    display: inline-block;
    padding: var(--space-2xs) var(--space-sm);
    border-radius: var(--radius-full);
    font-size: var(--text-xs);
    font-weight: var(--weight-medium);
    color: white;
    text-transform: capitalize;
}

/* Progress Cell */
.progress-cell {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
}

.progress-bar-mini {
    height: 8px;
    background: var(--color-bg-hover);
    border-radius: var(--radius-full);
    overflow: hidden;
}

.progress-fill {
    height: 100%;
    border-radius: var(--radius-full);
    transition: width 0.3s ease;
}

/* Retention Bars */
.retention-bars {
    display: flex;
    flex-direction: column;
    gap: var(--space-lg);
}

.retention-bar-group {
    display: flex;
    flex-direction: column;
    gap: var(--space-xs);
}

.retention-bar-label {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.retention-bar-label strong {
    color: var(--color-text-primary);
}

.retention-bar-label span {
    font-size: var(--text-sm);
    color: var(--color-text-muted);
}

.retention-bar-visual {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.bar-y2, .bar-y3 {
    height: 24px;
    border-radius: var(--radius-sm);
    display: flex;
    align-items: center;
    padding-left: var(--space-sm);
    font-size: var(--text-sm);
    font-weight: var(--weight-medium);
    color: white;
    min-width: 45px;
}

.bar-y2 {
    background: var(--color-accent);
}

.bar-y3 {
    background: var(--color-success);
}

.retention-bar-meta {
    font-size: var(--text-xs);
    color: var(--color-text-muted);
}

/* Legend */
.legend {
    display: flex;
    gap: var(--space-lg);
    justify-content: center;
}

.legend-item {
    display: flex;
    align-items: center;
    gap: var(--space-xs);
    font-size: var(--text-sm);
    color: var(--color-text-secondary);
}

.legend-color {
    width: 12px;
    height: 12px;
    border-radius: 2px;
}

/* Brand Indicator */
.brand-indicator {
    display: inline-block;
    width: 8px;
    height: 8px;
    border-radius: 50%;
    margin-right: var(--space-xs);
}

/* Text Colors */
.text-success { color: var(--color-success); }
.text-warning { color: var(--color-warning); }
.text-muted { color: var(--color-text-muted); }

/* Responsive */
@media (max-width: 899px) {
    .grid-2 {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 767px) {
    .filter-bar,
    .admin-card,
    .alert,
    .info-box {
        margin-left: -16px;
        margin-right: -16px;
        border-radius: 0 !important;
        border-left: none !important;
        border-right: none !important;
        width: calc(100% + 32px);
    }

    .filter-form {
        flex-direction: column;
        width: 100%;
    }

    .filter-group {
        width: 100%;
    }

    .filter-group--brands {
        min-width: unset;
    }

    .filter-group--actions {
        margin-left: 0;
    }

    .brand-selector {
        overflow-x: auto;
        flex-wrap: nowrap;
        padding-bottom: var(--space-sm);
        -webkit-overflow-scrolling: touch;
    }

    .brand-chip {
        flex: 0 0 auto;
        white-space: nowrap;
    }

    .dashboard-metrics {
        display: flex;
        gap: var(--space-sm);
        overflow-x: auto;
        padding-bottom: var(--space-sm);
        margin-left: -16px;
        margin-right: -16px;
        padding-left: 16px;
        padding-right: 16px;
        -webkit-overflow-scrolling: touch;
        scrollbar-width: none;
    }

    .dashboard-metrics::-webkit-scrollbar {
        display: none;
    }

    .metric-card {
        flex: 0 0 auto;
        min-width: 140px;
    }

    .admin-table th,
    .admin-table td {
        padding: var(--space-sm);
        font-size: var(--text-sm);
    }

    .admin-table th:nth-child(2),
    .admin-table td:nth-child(2),
    .admin-table th:nth-child(5),
    .admin-table td:nth-child(5) {
        display: none;
    }

    canvas {
        max-height: 250px !important;
    }

    .funnel-label {
        width: 80px;
    }

    .grid-gap-lg {
        gap: var(--space-md);
    }
}

@media (max-width: 479px) {
    .metric-card {
        min-width: 120px;
        padding: var(--space-sm);
    }

    .metric-value {
        font-size: var(--text-lg);
    }

    .metric-label {
        font-size: var(--text-xs);
    }

    .funnel-label {
        width: 60px;
        font-size: var(--text-xs);
    }
}
</style>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>

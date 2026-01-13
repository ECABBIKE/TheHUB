<?php
/**
 * Analytics - Report Generator
 *
 * Genererar rapporter for:
 * - Arssammanfattning
 * - Serieanalys
 * - Klubbrapport
 * - Retention-analys
 * - Demographic overview
 *
 * @package TheHUB Analytics
 * @version 1.0
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../analytics/includes/KPICalculator.php';

global $pdo;

// Arval
$currentYear = (int)date('Y');
$selectedYear = isset($_GET['year']) ? (int)$_GET['year'] : $currentYear;

// Hamta tillgangliga ar
$availableYears = [];
try {
    $stmt = $pdo->query("SELECT DISTINCT season_year FROM rider_yearly_stats ORDER BY season_year DESC");
    $availableYears = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $availableYears = range($currentYear, $currentYear - 5);
}

// Rapporttyp
$reportType = $_GET['report'] ?? 'summary';
$export = isset($_GET['export']);

// Initiera KPI Calculator
$kpiCalc = new KPICalculator($pdo);

// Hamta data baserat pa rapport
$reportData = [];
$reportTitle = '';

try {
    switch ($reportType) {
        case 'summary':
            $reportTitle = "Arssammanfattning $selectedYear";
            $reportData = [
                'kpis' => $kpiCalc->getAllKPIs($selectedYear),
                'trends' => $kpiCalc->getGrowthTrend(5),
                'topClubs' => $kpiCalc->getTopClubs($selectedYear, 10),
                'disciplines' => $kpiCalc->getDisciplineDistribution($selectedYear),
                'ages' => $kpiCalc->getAgeDistribution($selectedYear)
            ];
            break;

        case 'retention':
            $reportTitle = "Retention-analys $selectedYear";
            $reportData = [
                'retention_rate' => $kpiCalc->getRetentionRate($selectedYear),
                'churn_rate' => $kpiCalc->getChurnRate($selectedYear),
                'new_riders' => $kpiCalc->getNewRidersCount($selectedYear),
                'retained_riders' => $kpiCalc->getRetainedRidersCount($selectedYear),
                'trend' => $kpiCalc->getRetentionTrend(5)
            ];
            break;

        case 'series':
            $reportTitle = "Serie-analys $selectedYear";
            $reportData = [
                'entry_points' => $kpiCalc->getEntryPointDistribution($selectedYear),
                'feeder_matrix' => $kpiCalc->calculateFeederMatrix($selectedYear),
                'cross_rate' => $kpiCalc->getCrossParticipationRate($selectedYear)
            ];
            break;

        case 'clubs':
            $reportTitle = "Klubbrapport $selectedYear";
            $reportData = [
                'top_clubs' => $kpiCalc->getTopClubs($selectedYear, 50),
                'regions' => $kpiCalc->getRidersByRegion($selectedYear)
            ];
            break;

        case 'demographics':
            $reportTitle = "Demographic Overview $selectedYear";
            $reportData = [
                'average_age' => $kpiCalc->getAverageAge($selectedYear),
                'gender' => $kpiCalc->getGenderDistribution($selectedYear),
                'ages' => $kpiCalc->getAgeDistribution($selectedYear),
                'disciplines' => $kpiCalc->getDisciplineDistribution($selectedYear)
            ];
            break;
    }
} catch (Exception $e) {
    $error = $e->getMessage();
}

// Export to CSV
if ($export && !isset($error)) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="thehub-' . $reportType . '-' . $selectedYear . '.csv"');

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM

    switch ($reportType) {
        case 'summary':
            fputcsv($output, ['Nyckeltal', 'Varde']);
            foreach ($reportData['kpis'] as $key => $value) {
                if (!is_array($value)) {
                    fputcsv($output, [ucfirst(str_replace('_', ' ', $key)), $value]);
                }
            }
            fputcsv($output, []);
            fputcsv($output, ['Top Klubbar']);
            fputcsv($output, ['Klubb', 'Stad', 'Aktiva', 'Poang', 'Vinster']);
            foreach ($reportData['topClubs'] as $club) {
                fputcsv($output, [$club['club_name'], $club['city'] ?? '', $club['active_riders'], $club['total_points'], $club['wins']]);
            }
            break;

        case 'retention':
            fputcsv($output, ['Metric', 'Varde']);
            fputcsv($output, ['Retention Rate', $reportData['retention_rate'] . '%']);
            fputcsv($output, ['Churn Rate', $reportData['churn_rate'] . '%']);
            fputcsv($output, ['Nya Riders', $reportData['new_riders']]);
            fputcsv($output, ['Atervandare', $reportData['retained_riders']]);
            fputcsv($output, []);
            fputcsv($output, ['Trend']);
            fputcsv($output, ['Ar', 'Retention Rate']);
            foreach ($reportData['trend'] as $t) {
                fputcsv($output, [$t['year'], $t['retention_rate'] . '%']);
            }
            break;

        case 'clubs':
            fputcsv($output, ['Klubb', 'Stad', 'Aktiva Riders', 'Totala Poang', 'Vinster', 'Pallplatser']);
            foreach ($reportData['top_clubs'] as $club) {
                fputcsv($output, [
                    $club['club_name'],
                    $club['city'] ?? '',
                    $club['active_riders'],
                    $club['total_points'],
                    $club['wins'],
                    $club['podiums']
                ]);
            }
            break;

        case 'demographics':
            fputcsv($output, ['Demographic', 'Varde']);
            fputcsv($output, ['Snittålder', $reportData['average_age']]);
            fputcsv($output, ['Antal Man', $reportData['gender']['M']]);
            fputcsv($output, ['Antal Kvinnor', $reportData['gender']['F']]);
            fputcsv($output, []);
            fputcsv($output, ['Aldersfordelning']);
            fputcsv($output, ['Grupp', 'Antal']);
            foreach ($reportData['ages'] as $age) {
                fputcsv($output, [$age['age_group'], $age['count']]);
            }
            break;
    }

    fclose($output);
    exit;
}

// Page config
$page_title = 'Rapportgenerator';
$breadcrumbs = [
    ['label' => 'Analytics', 'url' => '/admin/analytics-dashboard.php'],
    ['label' => 'Rapporter']
];

// Include unified layout
include __DIR__ . '/components/unified-layout.php';
?>

<!-- Report Selector -->
<div class="report-selector">
    <form method="get" class="report-form">
        <div class="report-options">
            <label class="report-option <?= $reportType === 'summary' ? 'active' : '' ?>">
                <input type="radio" name="report" value="summary" <?= $reportType === 'summary' ? 'checked' : '' ?> onchange="this.form.submit()">
                <i data-lucide="file-text"></i>
                <span>Arssammanfattning</span>
            </label>

            <label class="report-option <?= $reportType === 'retention' ? 'active' : '' ?>">
                <input type="radio" name="report" value="retention" <?= $reportType === 'retention' ? 'checked' : '' ?> onchange="this.form.submit()">
                <i data-lucide="refresh-cw"></i>
                <span>Retention</span>
            </label>

            <label class="report-option <?= $reportType === 'series' ? 'active' : '' ?>">
                <input type="radio" name="report" value="series" <?= $reportType === 'series' ? 'checked' : '' ?> onchange="this.form.submit()">
                <i data-lucide="git-branch"></i>
                <span>Serie-analys</span>
            </label>

            <label class="report-option <?= $reportType === 'clubs' ? 'active' : '' ?>">
                <input type="radio" name="report" value="clubs" <?= $reportType === 'clubs' ? 'checked' : '' ?> onchange="this.form.submit()">
                <i data-lucide="building"></i>
                <span>Klubbar</span>
            </label>

            <label class="report-option <?= $reportType === 'demographics' ? 'active' : '' ?>">
                <input type="radio" name="report" value="demographics" <?= $reportType === 'demographics' ? 'checked' : '' ?> onchange="this.form.submit()">
                <i data-lucide="users"></i>
                <span>Demografi</span>
            </label>
        </div>

        <div class="report-controls">
            <select name="year" class="form-select" onchange="this.form.submit()">
                <?php foreach ($availableYears as $year): ?>
                    <option value="<?= $year ?>" <?= $year == $selectedYear ? 'selected' : '' ?>>
                        <?= $year ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <a href="?report=<?= $reportType ?>&year=<?= $selectedYear ?>&export=1" class="btn-admin btn-admin-primary">
                <i data-lucide="download"></i> Exportera CSV
            </a>
        </div>
    </form>
</div>

<?php if (isset($error)): ?>
<div class="alert alert-warning">
    <i data-lucide="alert-triangle"></i>
    <div>
        <strong>Ingen data tillganglig</strong><br>
        Kor <code>php analytics/populate-historical.php</code> for att generera historisk data.
    </div>
</div>
<?php else: ?>

<!-- Report Content -->
<div class="report-container">
    <div class="report-header">
        <h2><?= htmlspecialchars($reportTitle) ?></h2>
        <span class="report-date">Genererad: <?= date('Y-m-d H:i') ?></span>
    </div>

    <?php if ($reportType === 'summary'): ?>
    <!-- Summary Report -->
    <div class="report-section">
        <h3>Nyckeltal</h3>
        <div class="kpi-grid">
            <div class="kpi-item">
                <span class="kpi-value"><?= number_format($reportData['kpis']['total_riders'] ?? 0) ?></span>
                <span class="kpi-label">Aktiva Riders</span>
            </div>
            <div class="kpi-item">
                <span class="kpi-value"><?= number_format($reportData['kpis']['new_riders'] ?? 0) ?></span>
                <span class="kpi-label">Nya Riders</span>
            </div>
            <div class="kpi-item">
                <span class="kpi-value"><?= number_format($reportData['kpis']['retention_rate'] ?? 0, 1) ?>%</span>
                <span class="kpi-label">Retention Rate</span>
            </div>
            <div class="kpi-item">
                <span class="kpi-value"><?= ($reportData['kpis']['growth_rate'] ?? 0) >= 0 ? '+' : '' ?><?= number_format($reportData['kpis']['growth_rate'] ?? 0, 1) ?>%</span>
                <span class="kpi-label">Tillvaxt</span>
            </div>
        </div>
    </div>

    <div class="report-section">
        <h3>Top 10 Klubbar</h3>
        <table class="report-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Klubb</th>
                    <th>Stad</th>
                    <th>Aktiva</th>
                    <th>Poang</th>
                    <th>Vinster</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($reportData['topClubs'] as $i => $club): ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td><?= htmlspecialchars($club['club_name']) ?></td>
                    <td><?= htmlspecialchars($club['city'] ?? '-') ?></td>
                    <td><?= number_format($club['active_riders']) ?></td>
                    <td><?= number_format($club['total_points']) ?></td>
                    <td><?= number_format($club['wins']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php elseif ($reportType === 'retention'): ?>
    <!-- Retention Report -->
    <div class="report-section">
        <h3>Retention Oversikt</h3>
        <div class="kpi-grid">
            <div class="kpi-item kpi-item--large">
                <span class="kpi-value"><?= number_format($reportData['retention_rate'], 1) ?>%</span>
                <span class="kpi-label">Retention Rate</span>
            </div>
            <div class="kpi-item kpi-item--large">
                <span class="kpi-value"><?= number_format($reportData['churn_rate'], 1) ?>%</span>
                <span class="kpi-label">Churn Rate</span>
            </div>
        </div>

        <div class="retention-breakdown">
            <div class="retention-stat">
                <i data-lucide="user-plus"></i>
                <div>
                    <span class="retention-value"><?= number_format($reportData['new_riders']) ?></span>
                    <span class="retention-label">Nya riders detta ar</span>
                </div>
            </div>
            <div class="retention-stat">
                <i data-lucide="refresh-cw"></i>
                <div>
                    <span class="retention-value"><?= number_format($reportData['retained_riders']) ?></span>
                    <span class="retention-label">Atervandare fran forriga ar</span>
                </div>
            </div>
        </div>
    </div>

    <div class="report-section">
        <h3>Retention Trend (5 ar)</h3>
        <table class="report-table">
            <thead>
                <tr>
                    <th>Ar</th>
                    <th>Retention Rate</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($reportData['trend'] as $t): ?>
                <tr>
                    <td><?= $t['year'] ?></td>
                    <td>
                        <div class="progress-cell">
                            <div class="progress-bar-mini">
                                <div class="progress-fill" style="width: <?= $t['retention_rate'] ?>%;"></div>
                            </div>
                            <span><?= number_format($t['retention_rate'], 1) ?>%</span>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php elseif ($reportType === 'series'): ?>
    <!-- Series Report -->
    <div class="report-section">
        <h3>Cross-participation</h3>
        <div class="kpi-grid">
            <div class="kpi-item kpi-item--large">
                <span class="kpi-value"><?= number_format($reportData['cross_rate'], 1) ?>%</span>
                <span class="kpi-label">Riders i 2+ serier</span>
            </div>
        </div>
    </div>

    <div class="report-section">
        <h3>Entry Points - Var borjar nya riders?</h3>
        <table class="report-table">
            <thead>
                <tr>
                    <th>Serie</th>
                    <th>Niva</th>
                    <th>Nya Riders</th>
                    <th>Andel</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $totalEntry = array_sum(array_column($reportData['entry_points'], 'rider_count')) ?: 1;
                foreach ($reportData['entry_points'] as $ep):
                    $pct = ($ep['rider_count'] / $totalEntry) * 100;
                ?>
                <tr>
                    <td><?= htmlspecialchars($ep['series_name']) ?></td>
                    <td><?= ucfirst($ep['series_level']) ?></td>
                    <td><?= number_format($ep['rider_count']) ?></td>
                    <td><?= round($pct, 1) ?>%</td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php elseif ($reportType === 'clubs'): ?>
    <!-- Clubs Report -->
    <div class="report-section">
        <h3>Alla Klubbar</h3>
        <table class="report-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Klubb</th>
                    <th>Stad</th>
                    <th>Aktiva</th>
                    <th>Poang</th>
                    <th>Vinster</th>
                    <th>Pallplatser</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($reportData['top_clubs'] as $i => $club): ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td><?= htmlspecialchars($club['club_name']) ?></td>
                    <td><?= htmlspecialchars($club['city'] ?? '-') ?></td>
                    <td><?= number_format($club['active_riders']) ?></td>
                    <td><?= number_format($club['total_points']) ?></td>
                    <td><?= number_format($club['wins']) ?></td>
                    <td><?= number_format($club['podiums']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if (!empty($reportData['regions'])): ?>
    <div class="report-section">
        <h3>Riders per Region</h3>
        <table class="report-table">
            <thead>
                <tr>
                    <th>Region</th>
                    <th>Antal Riders</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($reportData['regions'] as $region): ?>
                <tr>
                    <td><?= htmlspecialchars($region['region']) ?></td>
                    <td><?= number_format($region['rider_count']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <?php elseif ($reportType === 'demographics'): ?>
    <!-- Demographics Report -->
    <div class="report-section">
        <h3>Oversikt</h3>
        <div class="kpi-grid">
            <div class="kpi-item">
                <span class="kpi-value"><?= number_format($reportData['average_age'], 0) ?> ar</span>
                <span class="kpi-label">Snittålder</span>
            </div>
            <div class="kpi-item">
                <span class="kpi-value"><?= number_format($reportData['gender']['M']) ?></span>
                <span class="kpi-label">Man</span>
            </div>
            <div class="kpi-item">
                <span class="kpi-value"><?= number_format($reportData['gender']['F']) ?></span>
                <span class="kpi-label">Kvinnor</span>
            </div>
            <?php
            $total = $reportData['gender']['M'] + $reportData['gender']['F'];
            $femalePct = $total > 0 ? round($reportData['gender']['F'] / $total * 100, 1) : 0;
            ?>
            <div class="kpi-item">
                <span class="kpi-value"><?= $femalePct ?>%</span>
                <span class="kpi-label">Andel kvinnor</span>
            </div>
        </div>
    </div>

    <div class="report-section">
        <h3>Aldersfordelning</h3>
        <table class="report-table">
            <thead>
                <tr>
                    <th>Aldersgrupp</th>
                    <th>Antal</th>
                    <th>Andel</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $totalAge = array_sum(array_column($reportData['ages'], 'count')) ?: 1;
                foreach ($reportData['ages'] as $age):
                    $pct = ($age['count'] / $totalAge) * 100;
                ?>
                <tr>
                    <td><?= htmlspecialchars($age['age_group']) ?></td>
                    <td><?= number_format($age['count']) ?></td>
                    <td><?= round($pct, 1) ?>%</td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="report-section">
        <h3>Disciplinfordelning</h3>
        <table class="report-table">
            <thead>
                <tr>
                    <th>Disciplin</th>
                    <th>Antal</th>
                    <th>Andel</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $totalDisc = array_sum(array_column($reportData['disciplines'], 'count')) ?: 1;
                foreach ($reportData['disciplines'] as $disc):
                    $pct = ($disc['count'] / $totalDisc) * 100;
                ?>
                <tr>
                    <td><?= htmlspecialchars($disc['discipline']) ?></td>
                    <td><?= number_format($disc['count']) ?></td>
                    <td><?= round($pct, 1) ?>%</td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

</div>

<?php endif; ?>

<style>
/* Report Selector */
.report-selector {
    margin-bottom: var(--space-xl);
    padding: var(--space-lg);
    background: var(--color-bg-card);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
}

.report-form {
    display: flex;
    flex-direction: column;
    gap: var(--space-lg);
}

.report-options {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-sm);
}

.report-option {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    padding: var(--space-sm) var(--space-md);
    background: var(--color-bg-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    cursor: pointer;
    transition: all 0.15s ease;
}

.report-option input {
    display: none;
}

.report-option i {
    width: 18px;
    height: 18px;
    color: var(--color-text-muted);
}

.report-option span {
    font-size: var(--text-sm);
    font-weight: var(--weight-medium);
}

.report-option:hover {
    border-color: var(--color-accent);
}

.report-option.active {
    background: var(--color-accent-light);
    border-color: var(--color-accent);
}

.report-option.active i {
    color: var(--color-accent);
}

.report-controls {
    display: flex;
    gap: var(--space-md);
    align-items: center;
}

/* Report Container */
.report-container {
    background: var(--color-bg-card);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    overflow: hidden;
}

.report-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: var(--space-lg);
    background: var(--color-bg-surface);
    border-bottom: 1px solid var(--color-border);
}

.report-header h2 {
    margin: 0;
    font-size: var(--text-xl);
}

.report-date {
    font-size: var(--text-sm);
    color: var(--color-text-muted);
}

.report-section {
    padding: var(--space-lg);
    border-bottom: 1px solid var(--color-border);
}

.report-section:last-child {
    border-bottom: none;
}

.report-section h3 {
    margin: 0 0 var(--space-md) 0;
    font-size: var(--text-lg);
    color: var(--color-text-secondary);
}

/* KPI Grid */
.kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: var(--space-md);
}

.kpi-item {
    text-align: center;
    padding: var(--space-md);
    background: var(--color-bg-surface);
    border-radius: var(--radius-md);
}

.kpi-item--large {
    padding: var(--space-xl);
}

.kpi-value {
    display: block;
    font-size: var(--text-2xl);
    font-weight: var(--weight-bold);
    color: var(--color-accent);
}

.kpi-item--large .kpi-value {
    font-size: var(--text-4xl);
}

.kpi-label {
    display: block;
    font-size: var(--text-sm);
    color: var(--color-text-secondary);
    margin-top: var(--space-xs);
}

/* Retention Breakdown */
.retention-breakdown {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: var(--space-md);
    margin-top: var(--space-lg);
}

.retention-stat {
    display: flex;
    align-items: center;
    gap: var(--space-md);
    padding: var(--space-md);
    background: var(--color-bg-surface);
    border-radius: var(--radius-md);
}

.retention-stat i {
    width: 32px;
    height: 32px;
    color: var(--color-accent);
}

.retention-value {
    display: block;
    font-size: var(--text-xl);
    font-weight: var(--weight-bold);
}

.retention-label {
    display: block;
    font-size: var(--text-sm);
    color: var(--color-text-secondary);
}

/* Report Table */
.report-table {
    width: 100%;
    border-collapse: collapse;
}

.report-table th,
.report-table td {
    padding: var(--space-sm) var(--space-md);
    text-align: left;
    border-bottom: 1px solid var(--color-border);
}

.report-table th {
    background: var(--color-bg-surface);
    font-weight: var(--weight-semibold);
    font-size: var(--text-sm);
    color: var(--color-text-secondary);
}

.report-table tbody tr:hover {
    background: var(--color-bg-hover);
}

/* Progress Cell */
.progress-cell {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
}

.progress-bar-mini {
    width: 80px;
    height: 6px;
    background: var(--color-bg-hover);
    border-radius: var(--radius-full);
    overflow: hidden;
}

.progress-fill {
    height: 100%;
    background: var(--color-success);
    border-radius: var(--radius-full);
}

/* Responsive */
@media (max-width: 767px) {
    .report-selector {
        margin-left: calc(-1 * var(--container-padding, 16px));
        margin-right: calc(-1 * var(--container-padding, 16px));
        border-radius: 0;
        border-left: none;
        border-right: none;
    }

    .report-container {
        margin-left: calc(-1 * var(--container-padding, 16px));
        margin-right: calc(-1 * var(--container-padding, 16px));
        border-radius: 0;
        border-left: none;
        border-right: none;
    }

    .report-options {
        flex-direction: column;
    }

    .report-controls {
        flex-direction: column;
        width: 100%;
    }

    .report-controls select,
    .report-controls a {
        width: 100%;
    }

    .kpi-grid {
        grid-template-columns: repeat(2, 1fr);
    }

    .report-table {
        font-size: var(--text-sm);
    }

    .report-table th,
    .report-table td {
        padding: var(--space-xs) var(--space-sm);
    }
}
</style>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>

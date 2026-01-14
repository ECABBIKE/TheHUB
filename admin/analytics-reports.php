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
 * Behorighet: super_admin ELLER statistics-permission
 *
 * @package TheHUB Analytics
 * @version 1.0
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../analytics/includes/KPICalculator.php';

// Kraver super_admin eller statistics-behorighet
requireAnalyticsAccess();

global $pdo;

// Arval
$currentYear = (int)date('Y');
$selectedYear = isset($_GET['year']) ? (int)$_GET['year'] : $currentYear;

// Hamta tillgangliga ar
$availableYears = [];
$dataWarning = null;
try {
    $stmt = $pdo->query("SELECT DISTINCT season_year FROM rider_yearly_stats ORDER BY season_year DESC");
    $availableYears = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (empty($availableYears)) {
        // Tabellen finns men ar tom - kolla om events finns
        $eventStmt = $pdo->query("SELECT DISTINCT YEAR(date) as year FROM events WHERE date IS NOT NULL ORDER BY year DESC LIMIT 10");
        $eventYears = $eventStmt->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($eventYears)) {
            $availableYears = $eventYears;
            $dataWarning = "Analytics-data saknas. Visar ar med events - kor Populate for att generera statistik.";
        }
    }
} catch (Exception $e) {
    $availableYears = range($currentYear, $currentYear - 5);
}

// Fallback om fortfarande tomt
if (empty($availableYears)) {
    $availableYears = range($currentYear, $currentYear - 5);
}

// Rapporttyp
$reportType = $_GET['report'] ?? 'summary';
$export = isset($_GET['export']);
$selectedSeries = isset($_GET['series']) ? (int)$_GET['series'] : null;

// Initiera KPI Calculator
$kpiCalc = new KPICalculator($pdo);

// Hamta serier med rookies for filtrering
$seriesWithRookies = [];

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

        case 'rookies':
            // Hamta serier for filtrering
            $seriesWithRookies = $kpiCalc->getSeriesWithRookies($selectedYear);

            $seriesName = '';
            if ($selectedSeries) {
                foreach ($seriesWithRookies as $s) {
                    if ((int)$s['id'] === $selectedSeries) {
                        $seriesName = ' - ' . $s['name'];
                        break;
                    }
                }
            }

            $reportTitle = "Nya Deltagare (Rookies) $selectedYear" . $seriesName;
            $reportData = [
                'total_rookies' => $kpiCalc->getNewRidersCount($selectedYear),
                'total_riders' => $kpiCalc->getTotalActiveRiders($selectedYear),
                'average_age' => $kpiCalc->getRookieAverageAge($selectedYear),
                'gender' => $kpiCalc->getRookieGenderDistribution($selectedYear),
                'ages' => $kpiCalc->getRookieAgeDistribution($selectedYear),
                'classes' => $kpiCalc->getRookieClassDistribution($selectedYear),
                'events' => $kpiCalc->getEventsWithMostRookies($selectedYear, 20),
                'clubs' => $kpiCalc->getClubsWithMostRookies($selectedYear, 20),
                'list' => $kpiCalc->getRookiesList($selectedYear, $selectedSeries),
                'series_filter' => $seriesWithRookies,
                'trend' => $kpiCalc->getRookieTrend(5),
                'age_trend' => $kpiCalc->getRookieAgeTrend(5)
            ];
            break;
    }
} catch (Throwable $e) {
    $error = $e->getMessage() . ' (Line: ' . $e->getLine() . ' in ' . basename($e->getFile()) . ')';
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

        case 'rookies':
            fputcsv($output, ['Rider ID', 'Fornamn', 'Efternamn', 'Alder', 'Fodelsear', 'Kon', 'Klubb', 'Serie', 'Events', 'Starter', 'Poang', 'Basta Placering', 'Disciplin', 'Profil URL']);
            foreach ($reportData['list'] as $rookie) {
                $profileUrl = 'https://thehub.se/rider/' . $rookie['rider_id'];
                fputcsv($output, [
                    $rookie['rider_id'],
                    $rookie['firstname'],
                    $rookie['lastname'],
                    $rookie['age'] ?? '-',
                    $rookie['birth_year'] ?? '-',
                    $rookie['gender'] ?? '-',
                    $rookie['club_name'] ?? 'Ingen klubb',
                    $rookie['series_name'] ?? '-',
                    $rookie['total_events'],
                    $rookie['total_starts'],
                    $rookie['total_points'],
                    $rookie['best_position'],
                    $rookie['primary_discipline'] ?? '-',
                    $profileUrl
                ]);
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

            <label class="report-option <?= $reportType === 'rookies' ? 'active' : '' ?>">
                <input type="radio" name="report" value="rookies" <?= $reportType === 'rookies' ? 'checked' : '' ?> onchange="this.form.submit()">
                <i data-lucide="user-plus"></i>
                <span>Nya Deltagare</span>
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

<?php if (isset($dataWarning)): ?>
<div class="alert alert-info" style="margin-bottom: var(--space-lg);">
    <i data-lucide="info"></i>
    <div>
        <?= htmlspecialchars($dataWarning) ?>
        <a href="/admin/analytics-populate.php" class="btn-admin btn-admin-sm" style="margin-left: var(--space-md);">Populate Data</a>
    </div>
</div>
<?php endif; ?>

<?php if (isset($error)): ?>
<div class="alert alert-warning">
    <i data-lucide="alert-triangle"></i>
    <div>
        <strong>Fel vid inlasning av data</strong><br>
        <?= htmlspecialchars($error) ?>
        <br><br>
        <small>Kor <a href="/admin/analytics-populate.php">Populate Historical</a> for att generera data.</small>
    </div>
</div>
<?php elseif (empty($availableYears)): ?>
<div class="alert alert-warning">
    <i data-lucide="alert-triangle"></i>
    <div>
        <strong>Ingen data tillganglig</strong><br>
        Kor <a href="/admin/analytics-populate.php">Populate Historical</a> for att generera historisk data.
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

    <?php elseif ($reportType === 'rookies'): ?>
    <!-- Rookies Report -->

    <?php if (!empty($reportData['series_filter'])): ?>
    <div class="report-section" style="padding-bottom: 0;">
        <div class="series-filter-row" style="display: flex; align-items: center; gap: var(--space-md); flex-wrap: wrap;">
            <label style="font-weight: var(--weight-medium);">Filtrera pa serie:</label>
            <select onchange="window.location.href='?report=rookies&year=<?= $selectedYear ?>&series=' + this.value" class="form-select" style="width: auto; min-width: 200px;">
                <option value="">Alla serier</option>
                <?php foreach ($reportData['series_filter'] as $series): ?>
                <option value="<?= $series['id'] ?>" <?= $selectedSeries == $series['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($series['name']) ?> (<?= $series['rookie_count'] ?> rookies)
                </option>
                <?php endforeach; ?>
            </select>
            <?php if ($selectedSeries): ?>
            <a href="?report=rookies&year=<?= $selectedYear ?>" class="btn-admin btn-admin-ghost btn-admin-sm">
                <i data-lucide="x" style="width:14px;height:14px;"></i> Rensa filter
            </a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="report-section">
        <h3>Oversikt <?= $selectedSeries ? '(filtrerad)' : '' ?></h3>
        <?php
        $rookiePct = $reportData['total_riders'] > 0
            ? round($reportData['total_rookies'] / $reportData['total_riders'] * 100, 1)
            : 0;
        $totalGender = $reportData['gender']['M'] + $reportData['gender']['F'];
        $femalePct = $totalGender > 0 ? round($reportData['gender']['F'] / $totalGender * 100, 1) : 0;
        ?>
        <div class="kpi-grid">
            <div class="kpi-item">
                <span class="kpi-value"><?= number_format($reportData['total_rookies']) ?></span>
                <span class="kpi-label">Nya Deltagare</span>
            </div>
            <div class="kpi-item">
                <span class="kpi-value"><?= $rookiePct ?>%</span>
                <span class="kpi-label">Andel av totalt</span>
            </div>
            <div class="kpi-item">
                <span class="kpi-value"><?= number_format($reportData['average_age'], 0) ?> ar</span>
                <span class="kpi-label">Snittålder</span>
            </div>
            <div class="kpi-item">
                <span class="kpi-value"><?= $femalePct ?>%</span>
                <span class="kpi-label">Andel kvinnor</span>
            </div>
        </div>
    </div>

    <?php if (!empty($reportData['trend'])): ?>
    <div class="report-section">
        <h3>Rookie-trend (5 ar)</h3>
        <div class="trend-chart-container" style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--space-lg);">
            <div>
                <canvas id="rookieTrendChart" height="200"></canvas>
            </div>
            <div>
                <table class="report-table">
                    <thead>
                        <tr>
                            <th>Ar</th>
                            <th>Nya</th>
                            <th>Totalt</th>
                            <th>Andel</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reportData['trend'] as $t): ?>
                        <tr>
                            <td><?= $t['year'] ?></td>
                            <td><strong><?= number_format($t['rookie_count']) ?></strong></td>
                            <td><?= number_format($t['total_riders']) ?></td>
                            <td><?= $t['rookie_percentage'] ?>%</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const ctx = document.getElementById('rookieTrendChart');
        if (ctx) {
            new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: <?= json_encode(array_column($reportData['trend'], 'year')) ?>,
                    datasets: [{
                        label: 'Nya deltagare',
                        data: <?= json_encode(array_map('intval', array_column($reportData['trend'], 'rookie_count'))) ?>,
                        backgroundColor: 'rgba(55, 212, 214, 0.8)',
                        borderColor: 'rgba(55, 212, 214, 1)',
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: { color: 'rgba(255,255,255,0.1)' }
                        },
                        x: {
                            grid: { display: false }
                        }
                    }
                }
            });
        }
    });
    </script>
    <?php endif; ?>

    <div class="report-section">
        <h3>Aldersfordelning - Nya Deltagare</h3>
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
                    <td>
                        <div class="progress-cell">
                            <div class="progress-bar-mini">
                                <div class="progress-fill" style="width: <?= $pct ?>%;"></div>
                            </div>
                            <span><?= round($pct, 1) ?>%</span>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="report-section">
        <h3>Klasser - Var startar nya deltagare?</h3>
        <table class="report-table">
            <thead>
                <tr>
                    <th>Klass</th>
                    <th>Antal Rookies</th>
                    <th>Andel</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $totalClass = array_sum(array_column($reportData['classes'], 'rookie_count')) ?: 1;
                foreach ($reportData['classes'] as $class):
                    $pct = ($class['rookie_count'] / $totalClass) * 100;
                ?>
                <tr>
                    <td><?= htmlspecialchars($class['class_name']) ?></td>
                    <td><?= number_format($class['rookie_count']) ?></td>
                    <td><?= round($pct, 1) ?>%</td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="report-section">
        <h3>Events med flest nya deltagare</h3>
        <table class="report-table">
            <thead>
                <tr>
                    <th>Event</th>
                    <th>Serie</th>
                    <th>Datum</th>
                    <th>Rookies</th>
                    <th>Totalt</th>
                    <th>Andel</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($reportData['events'] as $event): ?>
                <tr>
                    <td>
                        <a href="/event/<?= $event['event_id'] ?>" target="_blank">
                            <?= htmlspecialchars($event['event_name']) ?>
                        </a>
                    </td>
                    <td><?= htmlspecialchars($event['series_name'] ?? '-') ?></td>
                    <td><?= date('Y-m-d', strtotime($event['event_date'])) ?></td>
                    <td><strong><?= number_format($event['rookie_count']) ?></strong></td>
                    <td><?= number_format($event['total_participants']) ?></td>
                    <td><?= $event['rookie_percentage'] ?>%</td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="report-section">
        <h3>Klubbar med flest nya deltagare</h3>
        <table class="report-table">
            <thead>
                <tr>
                    <th>Klubb</th>
                    <th>Stad</th>
                    <th>Antal Rookies</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($reportData['clubs'] as $club): ?>
                <tr>
                    <td>
                        <a href="/club/<?= $club['club_id'] ?>" target="_blank">
                            <?= htmlspecialchars($club['club_name']) ?>
                        </a>
                    </td>
                    <td><?= htmlspecialchars($club['city'] ?? '-') ?></td>
                    <td><strong><?= number_format($club['rookie_count']) ?></strong></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="report-section">
        <h3>Alla Nya Deltagare (<?= number_format(count($reportData['list'])) ?> st)</h3>
        <p style="color: var(--color-text-secondary); margin-bottom: var(--space-md);">
            Klicka pa "Exportera CSV" for att ladda ner listan med profillänkar.
        </p>
        <div class="table-responsive" style="max-height: 500px; overflow-y: auto;">
        <table class="report-table">
            <thead>
                <tr>
                    <th>Namn</th>
                    <th>Alder</th>
                    <th>Klubb</th>
                    <th>Serie</th>
                    <th>Events</th>
                    <th>Poang</th>
                    <th>Basta</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($reportData['list'] as $rookie): ?>
                <tr>
                    <td>
                        <a href="/rider/<?= $rookie['rider_id'] ?>" target="_blank">
                            <?= htmlspecialchars($rookie['firstname'] . ' ' . $rookie['lastname']) ?>
                        </a>
                    </td>
                    <td><?= $rookie['age'] ?? '-' ?></td>
                    <td><?= htmlspecialchars($rookie['club_name'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($rookie['series_name'] ?? '-') ?></td>
                    <td><?= $rookie['total_events'] ?></td>
                    <td><?= $rookie['total_points'] ?></td>
                    <td><?= $rookie['best_position'] ? '#' . $rookie['best_position'] : '-' ?></td>
                    <td>
                        <a href="/rider/<?= $rookie['rider_id'] ?>" target="_blank" class="btn-icon" title="Visa profil">
                            <i data-lucide="external-link" style="width:16px;height:16px;"></i>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
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

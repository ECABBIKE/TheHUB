<?php
/**
 * Analytics Series Compare - Jämför Serier & Format
 *
 * Samma layout som Trender men med möjlighet att jämföra:
 * - Enskilda serier (Capital, GGS, SweCup etc.)
 * - Format/discipliner (Enduro, DH, XC)
 * - Kombinerade grupper (t.ex. "Alla regionala" vs "Nationella")
 *
 * @package TheHUB Analytics
 * @version 1.0
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

// Hämta alla serier med statistik
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
            (SELECT COUNT(DISTINCT sp.rider_id) FROM series_participation sp WHERE sp.series_id = s.id) as total_participants
        FROM series s
        WHERE s.id IN (SELECT DISTINCT series_id FROM series_participation)
        ORDER BY total_participants DESC, s.name
    ");
    $allSeries = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Ignore
}

// Hämta alla discipliner
$allDisciplines = [];
try {
    $stmt = $pdo->query("
        SELECT DISTINCT discipline, COUNT(DISTINCT id) as event_count
        FROM events
        WHERE discipline IS NOT NULL AND discipline != ''
        GROUP BY discipline
        ORDER BY event_count DESC
    ");
    $allDisciplines = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Ignore
}

// Valda serier/grupper från GET
$groupA = isset($_GET['groupA']) ? (array)$_GET['groupA'] : [];
$groupB = isset($_GET['groupB']) ? (array)$_GET['groupB'] : [];
$compareMode = $_GET['mode'] ?? 'series'; // 'series' eller 'discipline'
$disciplineA = $_GET['discA'] ?? '';
$disciplineB = $_GET['discB'] ?? '';

/**
 * Beräkna KPIs för en grupp serier
 */
function getSeriesGroupKPIs($pdo, $seriesIds, $year) {
    if (empty($seriesIds)) return null;

    $placeholders = implode(',', array_fill(0, count($seriesIds), '?'));

    // Totalt antal unika deltagare i gruppen detta år
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT rider_id)
        FROM series_participation
        WHERE series_id IN ($placeholders) AND season_year = ?
    ");
    $stmt->execute([...$seriesIds, $year]);
    $totalRiders = (int)$stmt->fetchColumn();

    // Nya riders (första året i NÅGON serie i systemet)
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT sp.rider_id)
        FROM series_participation sp
        WHERE sp.series_id IN ($placeholders)
        AND sp.season_year = ?
        AND sp.rider_id IN (
            SELECT rider_id FROM rider_yearly_stats WHERE first_year = ?
        )
    ");
    $stmt->execute([...$seriesIds, $year, $year]);
    $newRiders = (int)$stmt->fetchColumn();

    // Retained - deltog i gruppen både detta och förra året
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT sp1.rider_id)
        FROM series_participation sp1
        WHERE sp1.series_id IN ($placeholders)
        AND sp1.season_year = ?
        AND EXISTS (
            SELECT 1 FROM series_participation sp2
            WHERE sp2.rider_id = sp1.rider_id
            AND sp2.series_id IN ($placeholders)
            AND sp2.season_year = ?
        )
    ");
    $stmt->execute([...$seriesIds, $year, ...$seriesIds, $year - 1]);
    $retainedRiders = (int)$stmt->fetchColumn();

    // Förra årets deltagare (för retention rate)
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT rider_id)
        FROM series_participation
        WHERE series_id IN ($placeholders) AND season_year = ?
    ");
    $stmt->execute([...$seriesIds, $year - 1]);
    $prevYearRiders = (int)$stmt->fetchColumn();

    // Retention rate
    $retentionRate = $prevYearRiders > 0 ? round($retainedRiders / $prevYearRiders * 100, 1) : 0;

    // Churn rate
    $churnRate = 100 - $retentionRate;

    // Growth rate
    $growthRate = $prevYearRiders > 0 ? round(($totalRiders - $prevYearRiders) / $prevYearRiders * 100, 1) : 0;

    // Genomsnittsålder
    $stmt = $pdo->prepare("
        SELECT AVG(? - r.birth_year) as avg_age
        FROM riders r
        WHERE r.id IN (
            SELECT DISTINCT rider_id FROM series_participation
            WHERE series_id IN ($placeholders) AND season_year = ?
        )
        AND r.birth_year IS NOT NULL AND r.birth_year > 1900
    ");
    $stmt->execute([$year, ...$seriesIds, $year]);
    $avgAge = round((float)$stmt->fetchColumn(), 1);

    // Andel kvinnor
    $stmt = $pdo->prepare("
        SELECT
            SUM(CASE WHEN r.gender = 'F' THEN 1 ELSE 0 END) as female,
            COUNT(*) as total
        FROM riders r
        WHERE r.id IN (
            SELECT DISTINCT rider_id FROM series_participation
            WHERE series_id IN ($placeholders) AND season_year = ?
        )
        AND r.gender IN ('M', 'F')
    ");
    $stmt->execute([...$seriesIds, $year]);
    $genderRow = $stmt->fetch();
    $femalePct = $genderRow['total'] > 0 ? round($genderRow['female'] / $genderRow['total'] * 100, 1) : 0;

    return [
        'total_riders' => $totalRiders,
        'new_riders' => $newRiders,
        'retained_riders' => $retainedRiders,
        'retention_rate' => $retentionRate,
        'churn_rate' => $churnRate,
        'growth_rate' => $growthRate,
        'average_age' => $avgAge,
        'female_pct' => $femalePct
    ];
}

/**
 * Beräkna KPIs för en disciplin
 */
function getDisciplineKPIs($pdo, $discipline, $year) {
    if (empty($discipline)) return null;

    // Totalt antal unika deltagare i disciplinen detta år
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT r.rider_id)
        FROM results r
        JOIN events e ON r.event_id = e.id
        WHERE e.discipline = ? AND YEAR(e.date) = ?
    ");
    $stmt->execute([$discipline, $year]);
    $totalRiders = (int)$stmt->fetchColumn();

    // Nya riders
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT r.rider_id)
        FROM results r
        JOIN events e ON r.event_id = e.id
        WHERE e.discipline = ? AND YEAR(e.date) = ?
        AND r.rider_id IN (
            SELECT rider_id FROM rider_yearly_stats WHERE first_year = ?
        )
    ");
    $stmt->execute([$discipline, $year, $year]);
    $newRiders = (int)$stmt->fetchColumn();

    // Retained - deltog i disciplinen både detta och förra året
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT r1.rider_id)
        FROM results r1
        JOIN events e1 ON r1.event_id = e1.id
        WHERE e1.discipline = ? AND YEAR(e1.date) = ?
        AND EXISTS (
            SELECT 1 FROM results r2
            JOIN events e2 ON r2.event_id = e2.id
            WHERE r2.rider_id = r1.rider_id
            AND e2.discipline = ?
            AND YEAR(e2.date) = ?
        )
    ");
    $stmt->execute([$discipline, $year, $discipline, $year - 1]);
    $retainedRiders = (int)$stmt->fetchColumn();

    // Förra årets deltagare
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT r.rider_id)
        FROM results r
        JOIN events e ON r.event_id = e.id
        WHERE e.discipline = ? AND YEAR(e.date) = ?
    ");
    $stmt->execute([$discipline, $year - 1]);
    $prevYearRiders = (int)$stmt->fetchColumn();

    $retentionRate = $prevYearRiders > 0 ? round($retainedRiders / $prevYearRiders * 100, 1) : 0;
    $churnRate = 100 - $retentionRate;
    $growthRate = $prevYearRiders > 0 ? round(($totalRiders - $prevYearRiders) / $prevYearRiders * 100, 1) : 0;

    // Genomsnittsålder
    $stmt = $pdo->prepare("
        SELECT AVG(? - rid.birth_year) as avg_age
        FROM riders rid
        WHERE rid.id IN (
            SELECT DISTINCT r.rider_id FROM results r
            JOIN events e ON r.event_id = e.id
            WHERE e.discipline = ? AND YEAR(e.date) = ?
        )
        AND rid.birth_year IS NOT NULL AND rid.birth_year > 1900
    ");
    $stmt->execute([$year, $discipline, $year]);
    $avgAge = round((float)$stmt->fetchColumn(), 1);

    // Andel kvinnor
    $stmt = $pdo->prepare("
        SELECT
            SUM(CASE WHEN rid.gender = 'F' THEN 1 ELSE 0 END) as female,
            COUNT(*) as total
        FROM riders rid
        WHERE rid.id IN (
            SELECT DISTINCT r.rider_id FROM results r
            JOIN events e ON r.event_id = e.id
            WHERE e.discipline = ? AND YEAR(e.date) = ?
        )
        AND rid.gender IN ('M', 'F')
    ");
    $stmt->execute([$discipline, $year]);
    $genderRow = $stmt->fetch();
    $femalePct = $genderRow['total'] > 0 ? round($genderRow['female'] / $genderRow['total'] * 100, 1) : 0;

    return [
        'total_riders' => $totalRiders,
        'new_riders' => $newRiders,
        'retained_riders' => $retainedRiders,
        'retention_rate' => $retentionRate,
        'churn_rate' => $churnRate,
        'growth_rate' => $growthRate,
        'average_age' => $avgAge,
        'female_pct' => $femalePct
    ];
}

// Samla trenddata för valda grupper
$trendsA = [];
$trendsB = [];
$hasData = false;

try {
    if ($compareMode === 'discipline' && ($disciplineA || $disciplineB)) {
        foreach ($yearsToShow as $year) {
            if ($disciplineA) {
                $kpis = getDisciplineKPIs($pdo, $disciplineA, $year);
                if ($kpis) {
                    $trendsA[] = array_merge(['year' => $year], $kpis);
                    $hasData = true;
                }
            }
            if ($disciplineB) {
                $kpis = getDisciplineKPIs($pdo, $disciplineB, $year);
                if ($kpis) {
                    $trendsB[] = array_merge(['year' => $year], $kpis);
                }
            }
        }
    } elseif (!empty($groupA) || !empty($groupB)) {
        foreach ($yearsToShow as $year) {
            if (!empty($groupA)) {
                $kpis = getSeriesGroupKPIs($pdo, $groupA, $year);
                if ($kpis) {
                    $trendsA[] = array_merge(['year' => $year], $kpis);
                    $hasData = true;
                }
            }
            if (!empty($groupB)) {
                $kpis = getSeriesGroupKPIs($pdo, $groupB, $year);
                if ($kpis) {
                    $trendsB[] = array_merge(['year' => $year], $kpis);
                }
            }
        }
    }
} catch (Exception $e) {
    $error = $e->getMessage();
}

// Skapa labels för grupperna
$labelA = 'Grupp A';
$labelB = 'Grupp B';

if ($compareMode === 'discipline') {
    $labelA = $disciplineA ?: 'Välj disciplin';
    $labelB = $disciplineB ?: 'Välj disciplin';
} else {
    if (!empty($groupA)) {
        $names = array_filter(array_map(function($id) use ($allSeries) {
            foreach ($allSeries as $s) {
                if ($s['id'] == $id) return $s['name'];
            }
            return null;
        }, $groupA));
        $labelA = count($names) > 2 ? count($names) . ' serier' : implode(' + ', $names);
    }
    if (!empty($groupB)) {
        $names = array_filter(array_map(function($id) use ($allSeries) {
            foreach ($allSeries as $s) {
                if ($s['id'] == $id) return $s['name'];
            }
            return null;
        }, $groupB));
        $labelB = count($names) > 2 ? count($names) . ' serier' : implode(' + ', $names);
    }
}

// Page config
$page_title = 'Jämför Serier & Format';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => '/admin/dashboard.php'],
    ['label' => 'Analytics', 'url' => '/admin/analytics-dashboard.php'],
    ['label' => 'Jämför Serier']
];

$page_actions = '
<div class="btn-group">
    <a href="/admin/analytics-trends.php" class="btn-admin btn-admin-secondary">
        <i data-lucide="trending-up"></i> Trender
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
        <h3><i data-lucide="sliders"></i> Välj vad du vill jämföra</h3>
    </div>
    <div class="card-body">
        <form method="get" id="compareForm">
            <!-- Mode Toggle -->
            <div class="mode-toggle mb-lg">
                <label class="mode-option <?= $compareMode === 'series' ? 'active' : '' ?>">
                    <input type="radio" name="mode" value="series" <?= $compareMode === 'series' ? 'checked' : '' ?> onchange="this.form.submit()">
                    <i data-lucide="layers"></i> Jämför Serier
                </label>
                <label class="mode-option <?= $compareMode === 'discipline' ? 'active' : '' ?>">
                    <input type="radio" name="mode" value="discipline" <?= $compareMode === 'discipline' ? 'checked' : '' ?> onchange="this.form.submit()">
                    <i data-lucide="bike"></i> Jämför Format/Discipliner
                </label>
            </div>

            <input type="hidden" name="years" value="<?= $numYears ?>">

            <?php if ($compareMode === 'discipline'): ?>
            <!-- Discipline Selection -->
            <div class="compare-grid">
                <div class="compare-group compare-group-a">
                    <h4><span class="group-indicator group-a"></span> Disciplin A</h4>
                    <select name="discA" class="form-select" onchange="this.form.submit()">
                        <option value="">-- Välj disciplin --</option>
                        <?php foreach ($allDisciplines as $disc): ?>
                            <option value="<?= htmlspecialchars($disc['discipline']) ?>" <?= $disciplineA === $disc['discipline'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($disc['discipline']) ?> (<?= $disc['event_count'] ?> events)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="compare-vs">VS</div>
                <div class="compare-group compare-group-b">
                    <h4><span class="group-indicator group-b"></span> Disciplin B</h4>
                    <select name="discB" class="form-select" onchange="this.form.submit()">
                        <option value="">-- Välj disciplin --</option>
                        <?php foreach ($allDisciplines as $disc): ?>
                            <option value="<?= htmlspecialchars($disc['discipline']) ?>" <?= $disciplineB === $disc['discipline'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($disc['discipline']) ?> (<?= $disc['event_count'] ?> events)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <?php else: ?>
            <!-- Series Selection -->
            <div class="compare-grid">
                <div class="compare-group compare-group-a">
                    <h4><span class="group-indicator group-a"></span> Grupp A (välj en eller flera)</h4>
                    <div class="series-checkboxes">
                        <?php foreach ($allSeries as $series): ?>
                            <label class="series-checkbox <?= in_array($series['id'], $groupA) ? 'checked' : '' ?>">
                                <input type="checkbox" name="groupA[]" value="<?= $series['id'] ?>"
                                    <?= in_array($series['id'], $groupA) ? 'checked' : '' ?>>
                                <span class="series-name"><?= htmlspecialchars($series['name']) ?></span>
                                <span class="series-meta">
                                    <?= htmlspecialchars($series['discipline'] ?? '') ?>
                                    <span class="badge badge-sm"><?= number_format($series['total_participants']) ?></span>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="compare-vs">VS</div>
                <div class="compare-group compare-group-b">
                    <h4><span class="group-indicator group-b"></span> Grupp B (välj en eller flera)</h4>
                    <div class="series-checkboxes">
                        <?php foreach ($allSeries as $series): ?>
                            <label class="series-checkbox <?= in_array($series['id'], $groupB) ? 'checked' : '' ?>">
                                <input type="checkbox" name="groupB[]" value="<?= $series['id'] ?>"
                                    <?= in_array($series['id'], $groupB) ? 'checked' : '' ?>>
                                <span class="series-name"><?= htmlspecialchars($series['name']) ?></span>
                                <span class="series-meta">
                                    <?= htmlspecialchars($series['discipline'] ?? '') ?>
                                    <span class="badge badge-sm"><?= number_format($series['total_participants']) ?></span>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">
                    <i data-lucide="refresh-cw"></i> Uppdatera jämförelse
                </button>
                <a href="?mode=<?= $compareMode ?>&years=<?= $numYears ?>" class="btn btn-secondary">
                    <i data-lucide="x"></i> Rensa val
                </a>
            </div>
        </form>
    </div>
</div>

<!-- Year Range Selector -->
<div class="filter-bar">
    <form method="get" class="filter-form">
        <input type="hidden" name="mode" value="<?= $compareMode ?>">
        <?php if ($compareMode === 'discipline'): ?>
            <input type="hidden" name="discA" value="<?= htmlspecialchars($disciplineA) ?>">
            <input type="hidden" name="discB" value="<?= htmlspecialchars($disciplineB) ?>">
        <?php else: ?>
            <?php foreach ($groupA as $id): ?>
                <input type="hidden" name="groupA[]" value="<?= $id ?>">
            <?php endforeach; ?>
            <?php foreach ($groupB as $id): ?>
                <input type="hidden" name="groupB[]" value="<?= $id ?>">
            <?php endforeach; ?>
        <?php endif; ?>
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
        <strong>Välj serier eller discipliner att jämföra</strong><br>
        Använd panelen ovan för att välja vad du vill jämföra. Du kan välja enskilda serier eller kombinera flera.
    </div>
</div>
<?php else: ?>

<!-- Comparison Legend -->
<div class="comparison-legend">
    <div class="legend-item legend-a">
        <span class="legend-color"></span>
        <span class="legend-label"><?= htmlspecialchars($labelA) ?></span>
    </div>
    <?php if (!empty($trendsB)): ?>
    <div class="legend-item legend-b">
        <span class="legend-color"></span>
        <span class="legend-label"><?= htmlspecialchars($labelB) ?></span>
    </div>
    <?php endif; ?>
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
            <div class="legend-item"><span class="legend-color" style="background: #37d4d6;"></span> <?= htmlspecialchars($labelA) ?> - Totalt</div>
            <?php if (!empty($trendsB)): ?>
            <div class="legend-item"><span class="legend-color" style="background: #f97316;"></span> <?= htmlspecialchars($labelB) ?> - Totalt</div>
            <?php endif; ?>
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

<!-- NYA RIDERS & DEMOGRAPHICS -->
<div class="grid grid-2 grid-gap-lg">
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
</div>

<!-- GENOMSNITTSÅLDER -->
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

<!-- SAMMANFATTNINGSTABELL -->
<div class="admin-card">
    <div class="admin-card-header">
        <h2><i data-lucide="table"></i> Detaljerad Data - <?= htmlspecialchars($labelA) ?></h2>
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
                        <th class="text-right">Snittålder</th>
                        <th class="text-right">Kvinnor</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (array_reverse($trendsA) as $row): ?>
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
                        <td class="text-right"><?= $row['average_age'] ?> år</td>
                        <td class="text-right"><?= $row['female_pct'] ?>%</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if (!empty($trendsB)): ?>
<div class="admin-card">
    <div class="admin-card-header">
        <h2><i data-lucide="table"></i> Detaljerad Data - <?= htmlspecialchars($labelB) ?></h2>
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
                        <th class="text-right">Snittålder</th>
                        <th class="text-right">Kvinnor</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (array_reverse($trendsB) as $row): ?>
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
                        <td class="text-right"><?= $row['average_age'] ?> år</td>
                        <td class="text-right"><?= $row['female_pct'] ?>%</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php endif; ?>

<style>
/* Mode Toggle */
.mode-toggle {
    display: flex;
    gap: var(--space-md);
    justify-content: center;
}

.mode-option {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    padding: var(--space-md) var(--space-lg);
    background: var(--color-bg-hover);
    border: 2px solid var(--color-border);
    border-radius: var(--radius-md);
    cursor: pointer;
    transition: all 0.2s;
    font-weight: var(--weight-medium);
}

.mode-option:hover {
    border-color: var(--color-accent);
}

.mode-option.active {
    background: var(--color-accent-light);
    border-color: var(--color-accent);
    color: var(--color-accent);
}

.mode-option input {
    display: none;
}

/* Compare Grid */
.compare-grid {
    display: grid;
    grid-template-columns: 1fr auto 1fr;
    gap: var(--space-lg);
    align-items: start;
}

.compare-vs {
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    font-weight: bold;
    color: var(--color-text-muted);
    padding-top: 40px;
}

.compare-group h4 {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    margin-bottom: var(--space-md);
    font-size: 1rem;
}

.group-indicator {
    width: 12px;
    height: 12px;
    border-radius: 50%;
}

.group-indicator.group-a,
.compare-group-a .group-indicator {
    background: #37d4d6;
}

.group-indicator.group-b,
.compare-group-b .group-indicator {
    background: #f97316;
}

/* Series Checkboxes */
.series-checkboxes {
    display: flex;
    flex-direction: column;
    gap: var(--space-xs);
    max-height: 300px;
    overflow-y: auto;
    padding: var(--space-sm);
    background: var(--color-bg-page);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
}

.series-checkbox {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    padding: var(--space-sm);
    background: var(--color-bg-card);
    border: 1px solid transparent;
    border-radius: var(--radius-sm);
    cursor: pointer;
    transition: all 0.15s;
}

.series-checkbox:hover {
    background: var(--color-bg-hover);
}

.series-checkbox.checked {
    background: var(--color-accent-light);
    border-color: var(--color-accent);
}

.series-checkbox input {
    width: 16px;
    height: 16px;
    accent-color: var(--color-accent);
}

.series-name {
    flex: 1;
    font-weight: var(--weight-medium);
}

.series-meta {
    display: flex;
    align-items: center;
    gap: var(--space-xs);
    font-size: var(--text-sm);
    color: var(--color-text-muted);
}

.badge-sm {
    font-size: 0.7rem;
    padding: 2px 6px;
}

/* Form Actions */
.form-actions {
    display: flex;
    gap: var(--space-md);
    justify-content: center;
    margin-top: var(--space-lg);
    padding-top: var(--space-lg);
    border-top: 1px solid var(--color-border);
}

/* Comparison Legend */
.comparison-legend {
    display: flex;
    justify-content: center;
    gap: var(--space-xl);
    margin-bottom: var(--space-lg);
    padding: var(--space-md);
    background: var(--color-bg-card);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
}

.comparison-legend .legend-item {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    font-weight: var(--weight-medium);
}

.comparison-legend .legend-color {
    width: 16px;
    height: 16px;
    border-radius: var(--radius-sm);
}

.legend-a .legend-color {
    background: #37d4d6;
}

.legend-b .legend-color {
    background: #f97316;
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
    .compare-grid {
        grid-template-columns: 1fr;
    }

    .compare-vs {
        padding: var(--space-md) 0;
    }

    .mode-toggle {
        flex-direction: column;
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

    .comparison-legend {
        flex-direction: column;
        align-items: center;
        gap: var(--space-sm);
    }
}

@media (max-width: 767px) {
    .filter-bar,
    .card {
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

    .form-actions {
        flex-direction: column;
    }
}
</style>

<?php if ($hasData): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Data från PHP
    const trendsA = <?= json_encode($trendsA) ?>;
    const trendsB = <?= json_encode($trendsB) ?>;
    const labelA = <?= json_encode($labelA) ?>;
    const labelB = <?= json_encode($labelB) ?>;
    const years = trendsA.map(d => d.year);

    // Färgschema
    const colorA = '#37d4d6';
    const colorB = '#f97316';
    const colors = {
        success: '#10b981',
        warning: '#fbbf24',
        error: '#ef4444',
        blue: '#38bdf8'
    };

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

    // Skapa datasets beroende på om vi har B-data
    function createDatasets(dataKey, labelSuffix = '') {
        const datasets = [{
            label: labelA + labelSuffix,
            data: trendsA.map(d => d[dataKey]),
            borderColor: colorA,
            backgroundColor: colorA + '20',
            fill: true,
            tension: 0.3,
            borderWidth: 3,
            pointRadius: 4,
            pointHoverRadius: 6
        }];

        if (trendsB.length > 0) {
            datasets.push({
                label: labelB + labelSuffix,
                data: trendsB.map(d => d[dataKey]),
                borderColor: colorB,
                backgroundColor: colorB + '20',
                fill: true,
                tension: 0.3,
                borderWidth: 3,
                pointRadius: 4,
                pointHoverRadius: 6
            });
        }

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

    // 3. GROWTH CHART (Bar)
    const growthDatasets = [{
        label: labelA,
        data: trendsA.map(d => d.growth_rate),
        backgroundColor: colorA + '80',
        borderColor: colorA,
        borderWidth: 2,
        borderRadius: 4
    }];

    if (trendsB.length > 0) {
        growthDatasets.push({
            label: labelB,
            data: trendsB.map(d => d.growth_rate),
            backgroundColor: colorB + '80',
            borderColor: colorB,
            borderWidth: 2,
            borderRadius: 4
        });
    }

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

    // 4. NEW RIDERS CHART
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
                            return context.dataset.label + ': ' + context.parsed.y.toLocaleString() + ' nya riders';
                        }
                    }
                }
            }
        }
    });

    // 5. GENDER CHART
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

    // 6. AGE CHART
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

// Checkbox styling
document.querySelectorAll('.series-checkbox input').forEach(cb => {
    cb.addEventListener('change', function() {
        this.closest('.series-checkbox').classList.toggle('checked', this.checked);
    });
});
</script>
<?php endif; ?>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>

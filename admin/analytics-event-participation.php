<?php
/**
 * Analytics - Event Participation Analysis
 *
 * Analyserar deltagarmonster pa event-niva:
 * - Hur manga events deltar man i per serie?
 * - Vilka events har unika (single-event) deltagare?
 * - Aterkommer single-event deltagare till samma event?
 * - Event-retention ar for ar
 *
 * Behorighet: super_admin ELLER statistics-permission
 *
 * @package TheHUB Analytics
 * @version 3.2
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
$selectedSeriesId = isset($_GET['series_id']) ? (int)$_GET['series_id'] : null;
$selectedYear = isset($_GET['year']) ? (int)$_GET['year'] : $currentYear - 1;
$selectedBrands = [];
if (isset($_GET['brands'])) {
    if (is_array($_GET['brands'])) {
        $selectedBrands = array_map('intval', $_GET['brands']);
    } else {
        $selectedBrands = array_map('intval', explode(',', $_GET['brands']));
    }
    $selectedBrands = array_filter($selectedBrands);
}
$viewMode = $_GET['view'] ?? 'distribution'; // distribution, unique, retention, loyalty

// Initialize KPI Calculator
$kpiCalc = new KPICalculator($pdo);

// Fetch available data
$availableSeries = [];
$availableYears = [];
$availableBrands = [];
$distribution = [];
$uniqueEvents = [];
$eventRetention = [];
$loyalRiders = [];
$seriesInfo = null;
$error = null;

try {
    // Get available brands for filtering
    $stmt = $pdo->query("SELECT id, name, short_code, color_primary FROM brands WHERE active = 1 ORDER BY name");
    $availableBrands = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get available series
    $brandFilter = !empty($selectedBrands) ? $selectedBrands : null;
    $availableSeries = $kpiCalc->getAvailableSeriesForEventAnalysis($brandFilter);

    // Get selected series info
    if ($selectedSeriesId) {
        foreach ($availableSeries as $s) {
            if ($s['id'] == $selectedSeriesId) {
                $seriesInfo = $s;
                break;
            }
        }

        // Get available years for this series
        $availableYears = $kpiCalc->getAvailableYearsForSeries($selectedSeriesId);

        // Validate selected year
        $validYears = array_column($availableYears, 'year');
        if (!in_array($selectedYear, $validYears) && !empty($validYears)) {
            $selectedYear = $validYears[0];
        }

        // Fetch data based on view mode
        switch ($viewMode) {
            case 'distribution':
                $distribution = $kpiCalc->getSeriesParticipationDistribution(
                    $selectedSeriesId, $selectedYear, $brandFilter
                );
                break;

            case 'unique':
                $uniqueEvents = $kpiCalc->getEventsWithUniqueParticipants(
                    $selectedSeriesId, $selectedYear, $brandFilter
                );
                break;

            case 'retention':
                $eventRetention = $kpiCalc->getSeriesEventRetentionComparison(
                    $selectedSeriesId, $selectedYear, $selectedYear + 1, $brandFilter
                );
                break;

            case 'loyalty':
                $loyalRiders = $kpiCalc->getEventLoyalRiders($selectedSeriesId, null, 2);
                break;
        }
    }
} catch (Exception $e) {
    $error = $e->getMessage();
}

// Export handler
if (isset($_GET['export']) && in_array($_GET['export'], ['csv', 'json']) && !$error && $selectedSeriesId) {
    $exportData = [];

    switch ($viewMode) {
        case 'distribution':
            $exportData = $distribution;
            break;
        case 'unique':
            $exportData = $uniqueEvents;
            break;
        case 'retention':
            $exportData = $eventRetention;
            break;
        case 'loyalty':
            $exportData = $loyalRiders;
            break;
    }

    $filename = "event_participation_{$viewMode}_{$selectedSeriesId}_{$selectedYear}";

    if ($_GET['export'] === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.json"');
        echo json_encode($exportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    } else {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
        echo "\xEF\xBB\xBF"; // UTF-8 BOM

        // Convert to CSV based on data type
        if ($viewMode === 'distribution' && !empty($exportData['distribution'])) {
            echo "Events Attended,Count,Percentage\n";
            foreach ($exportData['distribution'] as $d) {
                echo "{$d['events']},{$d['count']},{$d['percentage']}\n";
            }
        } elseif ($viewMode === 'unique' && !empty($exportData['events'])) {
            echo "Event,Date,Venue,Total Participants,Unique Count,Unique %\n";
            foreach ($exportData['events'] as $e) {
                echo "\"{$e['event_name']}\",{$e['event_date']},\"{$e['venue_name']}\",{$e['total_participants']},{$e['unique_count']},{$e['unique_pct']}\n";
            }
        } elseif ($viewMode === 'retention' && !empty($exportData['events'])) {
            echo "Event,Venue,Participants,Returned Same Event,Returned Series,Retention Rate %\n";
            foreach ($exportData['events'] as $e) {
                $r = $e['retention'];
                echo "\"{$e['name']}\",\"{$e['venue_name']}\",{$r['participants_from_year']},{$r['returned_same_event']},{$r['returned_same_series']},{$r['same_event_retention_rate']}\n";
            }
        }
    }

    // Log export
    try {
        $stmt = $pdo->prepare("INSERT INTO analytics_exports (export_type, export_params, exported_by, ip_address) VALUES (?, ?, ?, ?)");
        $stmt->execute([
            'event_participation_' . $viewMode,
            json_encode(['series_id' => $selectedSeriesId, 'year' => $selectedYear, 'brands' => $selectedBrands]),
            $_SESSION['user_id'] ?? null,
            $_SERVER['REMOTE_ADDR'] ?? null
        ]);
    } catch (Exception $e) { /* Ignore */ }

    exit;
}

// Page config
$page_title = 'Event Participation Analysis';
$breadcrumbs = [
    ['label' => 'Analytics', 'url' => '/admin/analytics-dashboard.php'],
    ['label' => 'Event Participation']
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
        <i data-lucide="calendar-days"></i>
    </div>
    <div class="info-box-content">
        <strong>Event Participation Analysis</strong>
        <p>Analyserar deltagarmonster pa event-niva. Se hur manga event deltagare gar pa, vilka events som har unika deltagare, och hur bra event ar pa att locka tillbaka deltagare.</p>
        <p style="margin-top: var(--space-xs); font-size: var(--text-xs); color: var(--color-text-muted);">
            <i data-lucide="shield-check" style="width: 12px; height: 12px; display: inline-block; vertical-align: middle;"></i>
            GDPR-sakrad: Endast aggregerade varden visas (minimum 10 individer per segment)
        </p>
    </div>
</div>

<!-- Filter Bar -->
<div class="filter-bar">
    <form method="get" class="filter-form" id="filterForm">
        <!-- Brand Filter -->
        <div class="filter-group">
            <label class="filter-label">Varumarke</label>
            <div class="brand-chips">
                <?php foreach ($availableBrands as $brand): ?>
                    <label class="brand-chip <?= in_array($brand['id'], $selectedBrands) ? 'active' : '' ?>"
                           style="<?= $brand['color_primary'] ? '--chip-color: ' . $brand['color_primary'] : '' ?>">
                        <input type="checkbox" name="brands[]" value="<?= $brand['id'] ?>"
                               <?= in_array($brand['id'], $selectedBrands) ? 'checked' : '' ?>
                               onchange="this.form.submit()">
                        <?= htmlspecialchars($brand['short_code'] ?: $brand['name']) ?>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Series Filter -->
        <div class="filter-group">
            <label class="filter-label">Serie</label>
            <select name="series_id" class="form-select" onchange="this.form.submit()">
                <option value="">-- Valj serie --</option>
                <?php foreach ($availableSeries as $s): ?>
                    <option value="<?= $s['id'] ?>" <?= $s['id'] == $selectedSeriesId ? 'selected' : '' ?>>
                        <?= htmlspecialchars($s['name']) ?> (<?= number_format($s['total_participants']) ?> deltagare)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <?php if ($selectedSeriesId && !empty($availableYears)): ?>
        <!-- Year Filter -->
        <div class="filter-group">
            <label class="filter-label">Sasong</label>
            <select name="year" class="form-select" onchange="this.form.submit()">
                <?php foreach ($availableYears as $y): ?>
                    <option value="<?= $y['year'] ?>" <?= $y['year'] == $selectedYear ? 'selected' : '' ?>>
                        <?= $y['year'] ?> (<?= $y['event_count'] ?> events, <?= number_format($y['participant_count']) ?> deltagare)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- View Mode Tabs -->
        <div class="filter-group">
            <label class="filter-label">Vy</label>
            <div class="view-tabs">
                <a href="?series_id=<?= $selectedSeriesId ?>&year=<?= $selectedYear ?>&view=distribution<?= !empty($selectedBrands) ? '&brands=' . implode(',', $selectedBrands) : '' ?>"
                   class="view-tab <?= $viewMode === 'distribution' ? 'active' : '' ?>">
                    <i data-lucide="bar-chart-3"></i> Fordelning
                </a>
                <a href="?series_id=<?= $selectedSeriesId ?>&year=<?= $selectedYear ?>&view=unique<?= !empty($selectedBrands) ? '&brands=' . implode(',', $selectedBrands) : '' ?>"
                   class="view-tab <?= $viewMode === 'unique' ? 'active' : '' ?>">
                    <i data-lucide="user"></i> Unika
                </a>
                <a href="?series_id=<?= $selectedSeriesId ?>&year=<?= $selectedYear ?>&view=retention<?= !empty($selectedBrands) ? '&brands=' . implode(',', $selectedBrands) : '' ?>"
                   class="view-tab <?= $viewMode === 'retention' ? 'active' : '' ?>">
                    <i data-lucide="repeat"></i> Retention
                </a>
                <a href="?series_id=<?= $selectedSeriesId ?>&year=<?= $selectedYear ?>&view=loyalty<?= !empty($selectedBrands) ? '&brands=' . implode(',', $selectedBrands) : '' ?>"
                   class="view-tab <?= $viewMode === 'loyalty' ? 'active' : '' ?>">
                    <i data-lucide="heart"></i> Lojalitet
                </a>
            </div>
        </div>
        <?php endif; ?>
    </form>
</div>

<?php if ($error): ?>
<div class="alert alert-danger">
    <i data-lucide="alert-circle"></i>
    <?= htmlspecialchars($error) ?>
</div>
<?php endif; ?>

<?php if (!$selectedSeriesId): ?>
<div class="card">
    <div class="card-body" style="text-align: center; padding: var(--space-2xl);">
        <i data-lucide="hand-pointing" style="width: 48px; height: 48px; margin-bottom: var(--space-md); color: var(--color-text-muted);"></i>
        <h3>Valj en serie for att borja</h3>
        <p style="color: var(--color-text-secondary);">Valj ett varumarke och/eller en serie ovan for att se deltagarstatistik.</p>
    </div>
</div>
<?php elseif ($viewMode === 'distribution' && !empty($distribution) && empty($distribution['suppressed'])): ?>

<!-- Distribution View -->
<div class="row">
    <!-- Summary Cards -->
    <div class="col-md-3">
        <div class="stat-card">
            <div class="stat-value"><?= number_format($distribution['total_participants']) ?></div>
            <div class="stat-label">Deltagare totalt</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card">
            <div class="stat-value"><?= $distribution['total_events_in_series'] ?></div>
            <div class="stat-label">Event i serien</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card">
            <div class="stat-value"><?= $distribution['avg_events_per_rider'] ?></div>
            <div class="stat-label">Snitt events/deltagare</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card highlight">
            <div class="stat-value"><?= $distribution['single_event_pct'] ?>%</div>
            <div class="stat-label">Endast 1 event</div>
        </div>
    </div>
</div>

<!-- Distribution Chart -->
<div class="card">
    <div class="card-header">
        <h3>Deltagande per antal event</h3>
        <div class="card-actions">
            <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn-admin btn-admin-sm">
                <i data-lucide="download"></i> CSV
            </a>
        </div>
    </div>
    <div class="card-body">
        <div class="distribution-bars">
            <?php
            $maxPct = max(array_column($distribution['distribution'], 'percentage'));
            foreach ($distribution['distribution'] as $d):
                $barWidth = ($d['percentage'] / $maxPct) * 100;
            ?>
            <div class="distribution-row">
                <div class="distribution-label"><?= $d['events'] ?> event<?= $d['events'] > 1 ? 's' : '' ?></div>
                <div class="distribution-bar-container">
                    <div class="distribution-bar" style="width: <?= $barWidth ?>%;"></div>
                </div>
                <div class="distribution-values">
                    <span class="distribution-count"><?= number_format($d['count']) ?></span>
                    <span class="distribution-pct">(<?= $d['percentage'] ?>%)</span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Additional Stats -->
<div class="row">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header"><h3>Hela serien</h3></div>
            <div class="card-body">
                <p>
                    <strong><?= number_format($distribution['full_series_count']) ?></strong> deltagare
                    (<?= $distribution['full_series_pct'] ?>%) gjorde <strong>alla <?= $distribution['total_events_in_series'] ?> event</strong>.
                </p>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card">
            <div class="card-header"><h3>Single-event deltagare</h3></div>
            <div class="card-body">
                <p>
                    <strong><?= number_format($distribution['single_event_count']) ?></strong> deltagare
                    (<?= $distribution['single_event_pct'] ?>%) deltog i <strong>endast 1 event</strong>.
                </p>
                <p style="color: var(--color-text-secondary); font-size: var(--text-sm);">
                    Dessa ar potentiella mal for retention-atgarder.
                </p>
            </div>
        </div>
    </div>
</div>

<?php elseif ($viewMode === 'unique' && !empty($uniqueEvents) && empty($uniqueEvents['suppressed'])): ?>

<!-- Unique Participants View -->
<div class="card">
    <div class="card-header">
        <h3>Events med unika deltagare (endast detta event i serien)</h3>
        <div class="card-actions">
            <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn-admin btn-admin-sm">
                <i data-lucide="download"></i> CSV
            </a>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Event</th>
                    <th>Datum</th>
                    <th>Plats</th>
                    <th class="text-right">Deltagare</th>
                    <th class="text-right">Unika</th>
                    <th class="text-right">Unika %</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($uniqueEvents['events'] as $e): ?>
                <tr>
                    <td><?= htmlspecialchars($e['event_name']) ?></td>
                    <td><?= $e['event_date'] ?></td>
                    <td>
                        <?php if ($e['venue_name']): ?>
                            <?= htmlspecialchars($e['venue_name']) ?>
                            <?php if ($e['venue_city']): ?><span class="text-muted">, <?= htmlspecialchars($e['venue_city']) ?></span><?php endif; ?>
                        <?php else: ?>
                            <span class="text-muted">-</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-right"><?= number_format($e['total_participants']) ?></td>
                    <td class="text-right"><?= number_format($e['unique_count'] ?? 0) ?></td>
                    <td class="text-right">
                        <span class="badge <?= ($e['unique_pct'] ?? 0) > 40 ? 'badge-warning' : 'badge-info' ?>">
                            <?= $e['unique_pct'] ?? 0 ?>%
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="info-box" style="margin-top: var(--space-lg);">
    <div class="info-box-icon"><i data-lucide="info"></i></div>
    <div class="info-box-content">
        <strong>Tolkning</strong>
        <p>Hog andel "unika" deltagare kan betyda att eventet lockar lokala deltagare som inte foljer serien. Detta kan vara positivt (bredd) eller negativt (lag serie-engagement).</p>
    </div>
</div>

<?php elseif ($viewMode === 'retention' && !empty($eventRetention) && empty($eventRetention['suppressed'])): ?>

<!-- Retention View -->
<div class="card">
    <div class="card-header">
        <h3>Event Retention <?= $eventRetention['from_year'] ?> &rarr; <?= $eventRetention['to_year'] ?></h3>
        <div class="card-actions">
            <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn-admin btn-admin-sm">
                <i data-lucide="download"></i> CSV
            </a>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Event</th>
                    <th>Plats</th>
                    <th class="text-right">Deltagare <?= $eventRetention['from_year'] ?></th>
                    <th class="text-right">Samma event <?= $eventRetention['to_year'] ?></th>
                    <th class="text-right">Samma serie</th>
                    <th class="text-right">Retention %</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($eventRetention['events'] as $e):
                    $r = $e['retention'];
                ?>
                <tr>
                    <td><?= htmlspecialchars($e['name']) ?></td>
                    <td><?= htmlspecialchars($e['venue_name'] ?? '-') ?></td>
                    <td class="text-right"><?= number_format($r['participants_from_year']) ?></td>
                    <td class="text-right"><?= number_format($r['returned_same_event']) ?></td>
                    <td class="text-right"><?= number_format($r['returned_same_series']) ?></td>
                    <td class="text-right">
                        <span class="badge <?= $r['same_event_retention_rate'] >= 50 ? 'badge-success' : ($r['same_event_retention_rate'] >= 30 ? 'badge-warning' : 'badge-danger') ?>">
                            <?= $r['same_event_retention_rate'] ?>%
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php elseif ($viewMode === 'loyalty' && !empty($loyalRiders) && empty($loyalRiders['suppressed'])): ?>

<!-- Loyalty View -->
<div class="row">
    <div class="col-md-3">
        <div class="stat-card">
            <div class="stat-value"><?= number_format($loyalRiders['total_loyal_riders']) ?></div>
            <div class="stat-label">Lojala deltagare (2+ ar)</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card">
            <div class="stat-value"><?= $loyalRiders['avg_consecutive_years'] ?></div>
            <div class="stat-label">Snitt ar i rad</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card">
            <div class="stat-value"><?= $loyalRiders['max_consecutive_years'] ?></div>
            <div class="stat-label">Max ar i rad</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card highlight">
            <div class="stat-value"><?= $loyalRiders['single_event_loyalist_pct'] ?>%</div>
            <div class="stat-label">Single-event lojala</div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3>Single-Event Lojala</h3>
    </div>
    <div class="card-body">
        <p>
            <strong><?= number_format($loyalRiders['single_event_loyalists']) ?></strong> deltagare
            (<?= $loyalRiders['single_event_loyalist_pct'] ?>%) har deltagit i serien flera ar,
            men <strong>endast pa samma event varje ar</strong>.
        </p>
        <p style="color: var(--color-text-secondary); font-size: var(--text-sm);">
            Dessa ar "event-lojala" snarare an "serie-lojala" - de kommer till sitt lokala event.
        </p>
    </div>
</div>

<?php elseif (isset($distribution['suppressed']) || isset($uniqueEvents['suppressed']) || isset($eventRetention['suppressed']) || isset($loyalRiders['suppressed'])): ?>
<div class="alert alert-warning">
    <i data-lucide="shield-alert"></i>
    <?= $distribution['reason'] ?? $uniqueEvents['reason'] ?? $eventRetention['reason'] ?? $loyalRiders['reason'] ?? 'Otillracklig data' ?>
</div>
<?php endif; ?>

<style>
/* Brand chips */
.brand-chips {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-xs);
}

.brand-chip {
    display: inline-flex;
    align-items: center;
    padding: var(--space-xs) var(--space-sm);
    border-radius: var(--radius-full);
    background: var(--color-bg-surface);
    border: 1px solid var(--color-border);
    cursor: pointer;
    font-size: var(--text-sm);
    transition: all 0.15s ease;
}

.brand-chip input {
    display: none;
}

.brand-chip:hover {
    border-color: var(--chip-color, var(--color-accent));
}

.brand-chip.active {
    background: var(--chip-color, var(--color-accent));
    color: white;
    border-color: var(--chip-color, var(--color-accent));
}

/* View tabs */
.view-tabs {
    display: flex;
    gap: var(--space-xs);
}

.view-tab {
    display: inline-flex;
    align-items: center;
    gap: var(--space-xs);
    padding: var(--space-xs) var(--space-sm);
    border-radius: var(--radius-sm);
    background: var(--color-bg-surface);
    border: 1px solid var(--color-border);
    color: var(--color-text-secondary);
    text-decoration: none;
    font-size: var(--text-sm);
    transition: all 0.15s ease;
}

.view-tab:hover {
    border-color: var(--color-accent);
    color: var(--color-text-primary);
}

.view-tab.active {
    background: var(--color-accent);
    color: white;
    border-color: var(--color-accent);
}

.view-tab i {
    width: 14px;
    height: 14px;
}

/* Distribution bars */
.distribution-bars {
    display: flex;
    flex-direction: column;
    gap: var(--space-sm);
}

.distribution-row {
    display: flex;
    align-items: center;
    gap: var(--space-md);
}

.distribution-label {
    width: 80px;
    font-weight: 500;
    text-align: right;
}

.distribution-bar-container {
    flex: 1;
    height: 24px;
    background: var(--color-bg-hover);
    border-radius: var(--radius-sm);
    overflow: hidden;
}

.distribution-bar {
    height: 100%;
    background: var(--color-accent);
    border-radius: var(--radius-sm);
    transition: width 0.3s ease;
}

.distribution-values {
    width: 120px;
    text-align: right;
}

.distribution-count {
    font-weight: 600;
}

.distribution-pct {
    color: var(--color-text-muted);
    font-size: var(--text-sm);
}

/* Stat cards */
.stat-card {
    background: var(--color-bg-card);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    padding: var(--space-lg);
    text-align: center;
    margin-bottom: var(--space-md);
}

.stat-card.highlight {
    border-color: var(--color-accent);
    background: var(--color-accent-light);
}

.stat-value {
    font-size: var(--text-3xl);
    font-weight: 700;
    color: var(--color-text-primary);
}

.stat-label {
    color: var(--color-text-secondary);
    font-size: var(--text-sm);
    margin-top: var(--space-xs);
}

/* Row grid */
.row {
    display: flex;
    flex-wrap: wrap;
    margin: 0 calc(var(--space-md) * -0.5);
}

.col-md-3 {
    flex: 0 0 25%;
    max-width: 25%;
    padding: 0 calc(var(--space-md) * 0.5);
}

.col-md-6 {
    flex: 0 0 50%;
    max-width: 50%;
    padding: 0 calc(var(--space-md) * 0.5);
}

@media (max-width: 767px) {
    .col-md-3, .col-md-6 {
        flex: 0 0 100%;
        max-width: 100%;
    }

    .view-tabs {
        flex-wrap: wrap;
    }

    .distribution-label {
        width: 60px;
        font-size: var(--text-sm);
    }

    .distribution-values {
        width: 80px;
    }
}
</style>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>

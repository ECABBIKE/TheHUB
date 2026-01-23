<?php
/**
 * Class Structure Analysis
 * Analyze how different events structure their classes:
 * - Number of stages per class
 * - Winner times vs average times
 * - Class participation patterns
 *
 * @package TheHUB Analytics
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../analytics/includes/KPICalculator.php';
requireLogin();

global $pdo;

// Get available brands using KPICalculator
$brands = [];
try {
    $kpiCalc = new KPICalculator($pdo);
    $brands = $kpiCalc->getAllBrands();
} catch (Exception $e) {
    error_log("Class Structure brand error: " . $e->getMessage());
}

// Get available years
$availableYears = [];
try {
    $stmt = $pdo->query("SELECT DISTINCT YEAR(date) as year FROM events WHERE date IS NOT NULL ORDER BY year DESC");
    $availableYears = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}

// Parameters
$selectedBrands = isset($_GET['brands']) ? array_map('intval', (array)$_GET['brands']) : [];
$selectedYear = isset($_GET['year']) ? (int)$_GET['year'] : ($availableYears[0] ?? (int)date('Y'));
$groupBy = $_GET['group_by'] ?? 'event'; // event, class, series

// Build query for class structure analysis
$classData = [];
$eventSummary = [];

try {
    // Build brand filter
    $brandFilter = '';
    $brandParams = [];
    if (!empty($selectedBrands)) {
        $placeholders = implode(',', array_fill(0, count($selectedBrands), '?'));
        $brandFilter = "AND s.brand_id IN ($placeholders)";
        $brandParams = $selectedBrands;
    }

    // Get class structure data per event
    $sql = "
        SELECT
            e.id as event_id,
            e.name as event_name,
            e.date as event_date,
            s.name as series_name,
            sb.name as brand_name,
            sb.accent_color as brand_color,
            r.class_id,
            COALESCE(cl.name, CONCAT('Klass ', r.class_id)) as class_name,
            COUNT(DISTINCT r.cyclist_id) as participants,

            -- Winner time (position = 1)
            MIN(CASE WHEN r.position = 1 THEN TIME_TO_SEC(r.finish_time) END) as winner_time_sec,

            -- Average time (finished only)
            AVG(CASE WHEN r.status = 'finished' AND r.finish_time IS NOT NULL
                THEN TIME_TO_SEC(r.finish_time) END) as avg_time_sec,

            -- Median approximation (simple)
            COUNT(CASE WHEN r.status = 'finished' THEN 1 END) as finished_count,

            -- Count stages used (non-null ss columns)
            MAX(
                (CASE WHEN r.ss1 IS NOT NULL AND r.ss1 != '' AND r.ss1 != '00:00:00' THEN 1 ELSE 0 END) +
                (CASE WHEN r.ss2 IS NOT NULL AND r.ss2 != '' AND r.ss2 != '00:00:00' THEN 1 ELSE 0 END) +
                (CASE WHEN r.ss3 IS NOT NULL AND r.ss3 != '' AND r.ss3 != '00:00:00' THEN 1 ELSE 0 END) +
                (CASE WHEN r.ss4 IS NOT NULL AND r.ss4 != '' AND r.ss4 != '00:00:00' THEN 1 ELSE 0 END) +
                (CASE WHEN r.ss5 IS NOT NULL AND r.ss5 != '' AND r.ss5 != '00:00:00' THEN 1 ELSE 0 END) +
                (CASE WHEN r.ss6 IS NOT NULL AND r.ss6 != '' AND r.ss6 != '00:00:00' THEN 1 ELSE 0 END) +
                (CASE WHEN r.ss7 IS NOT NULL AND r.ss7 != '' AND r.ss7 != '00:00:00' THEN 1 ELSE 0 END) +
                (CASE WHEN r.ss8 IS NOT NULL AND r.ss8 != '' AND r.ss8 != '00:00:00' THEN 1 ELSE 0 END) +
                (CASE WHEN r.ss9 IS NOT NULL AND r.ss9 != '' AND r.ss9 != '00:00:00' THEN 1 ELSE 0 END) +
                (CASE WHEN r.ss10 IS NOT NULL AND r.ss10 != '' AND r.ss10 != '00:00:00' THEN 1 ELSE 0 END) +
                (CASE WHEN r.ss11 IS NOT NULL AND r.ss11 != '' AND r.ss11 != '00:00:00' THEN 1 ELSE 0 END) +
                (CASE WHEN r.ss12 IS NOT NULL AND r.ss12 != '' AND r.ss12 != '00:00:00' THEN 1 ELSE 0 END) +
                (CASE WHEN r.ss13 IS NOT NULL AND r.ss13 != '' AND r.ss13 != '00:00:00' THEN 1 ELSE 0 END) +
                (CASE WHEN r.ss14 IS NOT NULL AND r.ss14 != '' AND r.ss14 != '00:00:00' THEN 1 ELSE 0 END) +
                (CASE WHEN r.ss15 IS NOT NULL AND r.ss15 != '' AND r.ss15 != '00:00:00' THEN 1 ELSE 0 END)
            ) as stage_count,

            -- Which specific stages were used (bitmask style concat)
            CONCAT_WS(',',
                IF(MAX(CASE WHEN r.ss1 IS NOT NULL AND r.ss1 != '' AND r.ss1 != '00:00:00' THEN 1 END) = 1, 'SS1', NULL),
                IF(MAX(CASE WHEN r.ss2 IS NOT NULL AND r.ss2 != '' AND r.ss2 != '00:00:00' THEN 1 END) = 1, 'SS2', NULL),
                IF(MAX(CASE WHEN r.ss3 IS NOT NULL AND r.ss3 != '' AND r.ss3 != '00:00:00' THEN 1 END) = 1, 'SS3', NULL),
                IF(MAX(CASE WHEN r.ss4 IS NOT NULL AND r.ss4 != '' AND r.ss4 != '00:00:00' THEN 1 END) = 1, 'SS4', NULL),
                IF(MAX(CASE WHEN r.ss5 IS NOT NULL AND r.ss5 != '' AND r.ss5 != '00:00:00' THEN 1 END) = 1, 'SS5', NULL),
                IF(MAX(CASE WHEN r.ss6 IS NOT NULL AND r.ss6 != '' AND r.ss6 != '00:00:00' THEN 1 END) = 1, 'SS6', NULL),
                IF(MAX(CASE WHEN r.ss7 IS NOT NULL AND r.ss7 != '' AND r.ss7 != '00:00:00' THEN 1 END) = 1, 'SS7', NULL),
                IF(MAX(CASE WHEN r.ss8 IS NOT NULL AND r.ss8 != '' AND r.ss8 != '00:00:00' THEN 1 END) = 1, 'SS8', NULL),
                IF(MAX(CASE WHEN r.ss9 IS NOT NULL AND r.ss9 != '' AND r.ss9 != '00:00:00' THEN 1 END) = 1, 'SS9', NULL),
                IF(MAX(CASE WHEN r.ss10 IS NOT NULL AND r.ss10 != '' AND r.ss10 != '00:00:00' THEN 1 END) = 1, 'SS10', NULL),
                IF(MAX(CASE WHEN r.ss11 IS NOT NULL AND r.ss11 != '' AND r.ss11 != '00:00:00' THEN 1 END) = 1, 'SS11', NULL),
                IF(MAX(CASE WHEN r.ss12 IS NOT NULL AND r.ss12 != '' AND r.ss12 != '00:00:00' THEN 1 END) = 1, 'SS12', NULL),
                IF(MAX(CASE WHEN r.ss13 IS NOT NULL AND r.ss13 != '' AND r.ss13 != '00:00:00' THEN 1 END) = 1, 'SS13', NULL),
                IF(MAX(CASE WHEN r.ss14 IS NOT NULL AND r.ss14 != '' AND r.ss14 != '00:00:00' THEN 1 END) = 1, 'SS14', NULL),
                IF(MAX(CASE WHEN r.ss15 IS NOT NULL AND r.ss15 != '' AND r.ss15 != '00:00:00' THEN 1 END) = 1, 'SS15', NULL)
            ) as stages_used,

            -- Time spread (difference between winner and last finisher)
            MAX(CASE WHEN r.status = 'finished' THEN TIME_TO_SEC(r.finish_time) END) -
            MIN(CASE WHEN r.position = 1 THEN TIME_TO_SEC(r.finish_time) END) as time_spread_sec

        FROM results r
        JOIN events e ON r.event_id = e.id
        JOIN series s ON e.series_id = s.id
        LEFT JOIN series_brands sb ON s.brand_id = sb.id
        LEFT JOIN classes cl ON r.class_id = cl.id
        WHERE YEAR(e.date) = ?
        $brandFilter
        AND r.class_id IS NOT NULL
        GROUP BY e.id, r.class_id
        ORDER BY e.date DESC, class_name ASC
    ";

    $params = array_merge([$selectedYear], $brandParams);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $classData = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Group data by event for summary
    foreach ($classData as $row) {
        $eventId = $row['event_id'];
        if (!isset($eventSummary[$eventId])) {
            $eventSummary[$eventId] = [
                'event_name' => $row['event_name'],
                'event_date' => $row['event_date'],
                'series_name' => $row['series_name'],
                'brand_name' => $row['brand_name'],
                'brand_color' => $row['brand_color'],
                'classes' => [],
                'total_participants' => 0,
                'class_count' => 0
            ];
        }
        $eventSummary[$eventId]['classes'][] = $row;
        $eventSummary[$eventId]['total_participants'] += $row['participants'];
        $eventSummary[$eventId]['class_count']++;
    }

} catch (Exception $e) {
    error_log("Class Structure query error: " . $e->getMessage());
}

// Calculate aggregated stats per class across all events
$classAggregates = [];
foreach ($classData as $row) {
    $className = $row['class_name'];
    if (!isset($classAggregates[$className])) {
        $classAggregates[$className] = [
            'event_count' => 0,
            'total_participants' => 0,
            'avg_participants' => 0,
            'total_stages' => 0,
            'avg_winner_time' => 0,
            'winner_times' => [],
            'stage_counts' => []
        ];
    }
    $classAggregates[$className]['event_count']++;
    $classAggregates[$className]['total_participants'] += $row['participants'];
    if ($row['stage_count'] > 0) {
        $classAggregates[$className]['stage_counts'][] = $row['stage_count'];
    }
    if ($row['winner_time_sec'] > 0) {
        $classAggregates[$className]['winner_times'][] = $row['winner_time_sec'];
    }
}

// Calculate averages
foreach ($classAggregates as $className => &$agg) {
    $agg['avg_participants'] = $agg['event_count'] > 0
        ? round($agg['total_participants'] / $agg['event_count'], 1)
        : 0;
    $agg['avg_stages'] = !empty($agg['stage_counts'])
        ? round(array_sum($agg['stage_counts']) / count($agg['stage_counts']), 1)
        : 0;
    $agg['avg_winner_time'] = !empty($agg['winner_times'])
        ? array_sum($agg['winner_times']) / count($agg['winner_times'])
        : 0;
}
unset($agg);

// Sort by event count
uasort($classAggregates, function($a, $b) {
    return $b['event_count'] - $a['event_count'];
});

// Helper function to format time
function formatTime($seconds) {
    if (!$seconds || $seconds <= 0) return '-';
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    $secs = $seconds % 60;
    if ($hours > 0) {
        return sprintf('%d:%02d:%02d', $hours, $minutes, $secs);
    }
    return sprintf('%d:%02d', $minutes, $secs);
}

// Page config
$page_title = 'Klassanalys';
$breadcrumbs = [
    ['label' => 'Analytics', 'url' => '/admin/analytics-dashboard.php'],
    ['label' => 'Klassanalys']
];

$page_actions = '';

include __DIR__ . '/components/unified-layout.php';
?>

<style>
.filter-section {
    background: var(--color-bg-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    padding: var(--space-lg);
    margin-bottom: var(--space-xl);
}
.filter-row {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-md);
    align-items: flex-end;
}
.filter-group {
    flex: 1;
    min-width: 150px;
}
.filter-group.brands {
    flex: 2;
    min-width: 300px;
}

.brand-pills {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-xs);
}
.brand-pill {
    display: inline-flex;
    align-items: center;
    gap: var(--space-xs);
    padding: var(--space-xs) var(--space-sm);
    background: var(--color-bg-page);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-full);
    font-size: 0.8rem;
    cursor: pointer;
    transition: all 0.15s ease;
}
.brand-pill:hover {
    border-color: var(--color-accent);
}
.brand-pill.selected {
    background: var(--color-accent-light);
    border-color: var(--color-accent);
}
.brand-pill input {
    display: none;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: var(--space-md);
    margin-bottom: var(--space-xl);
}
.stat-card {
    background: var(--color-bg-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    padding: var(--space-lg);
    text-align: center;
}
.stat-value {
    font-size: 1.75rem;
    font-weight: 700;
    color: var(--color-accent);
}
.stat-label {
    font-size: 0.8rem;
    color: var(--color-text-muted);
    margin-top: var(--space-xs);
}

.event-card {
    background: var(--color-bg-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    margin-bottom: var(--space-md);
    overflow: hidden;
}
.event-header {
    padding: var(--space-md) var(--space-lg);
    background: var(--color-bg-page);
    border-bottom: 1px solid var(--color-border);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: var(--space-sm);
}
.event-title {
    font-weight: 600;
    color: var(--color-text-primary);
}
.event-meta {
    display: flex;
    gap: var(--space-md);
    font-size: 0.85rem;
    color: var(--color-text-muted);
}
.event-meta span {
    display: flex;
    align-items: center;
    gap: var(--space-xs);
}
.brand-tag {
    padding: 2px 8px;
    border-radius: var(--radius-full);
    font-size: 0.7rem;
    font-weight: 600;
    color: white;
}

.class-table {
    width: 100%;
    border-collapse: collapse;
}
.class-table th,
.class-table td {
    padding: var(--space-sm) var(--space-md);
    text-align: left;
    border-bottom: 1px solid var(--color-border);
}
.class-table th {
    background: var(--color-bg-page);
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--color-text-muted);
    text-transform: uppercase;
}
.class-table td {
    font-size: 0.875rem;
}
.class-table tr:last-child td {
    border-bottom: none;
}
.class-name {
    font-weight: 500;
    color: var(--color-text-primary);
}
.time-cell {
    font-family: monospace;
    color: var(--color-accent);
}
.stage-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 28px;
    height: 28px;
    padding: 0 var(--space-xs);
    background: var(--color-accent-light);
    color: var(--color-accent);
    border-radius: var(--radius-full);
    font-weight: 600;
    font-size: 0.85rem;
}
.stages-list {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    flex-wrap: wrap;
}
.stages-detail {
    font-size: 0.75rem;
    color: var(--color-text-muted);
    font-family: monospace;
}

.aggregate-section {
    margin-top: var(--space-2xl);
}
.aggregate-table {
    width: 100%;
    border-collapse: collapse;
    background: var(--color-bg-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    overflow: hidden;
}
.aggregate-table th,
.aggregate-table td {
    padding: var(--space-md);
    text-align: left;
    border-bottom: 1px solid var(--color-border);
}
.aggregate-table th {
    background: var(--color-bg-page);
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--color-text-muted);
    text-transform: uppercase;
}
.aggregate-table tr:last-child td {
    border-bottom: none;
}

.no-data {
    text-align: center;
    padding: var(--space-2xl);
    color: var(--color-text-muted);
}

/* Mobile */
@media (max-width: 767px) {
    .filter-section,
    .event-card,
    .stat-card {
        margin-left: -16px;
        margin-right: -16px;
        border-radius: 0;
        border-left: none;
        border-right: none;
    }
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    .class-table {
        font-size: 0.8rem;
    }
    .class-table th,
    .class-table td {
        padding: var(--space-xs) var(--space-sm);
    }
    .event-header {
        flex-direction: column;
        align-items: flex-start;
    }
}
</style>

<!-- Filter Section -->
<form method="get" class="filter-section">
    <div class="filter-row">
        <div class="filter-group">
            <label class="form-label">Sasong</label>
            <select name="year" class="form-select">
                <?php foreach ($availableYears as $y): ?>
                <option value="<?= $y ?>" <?= $y == $selectedYear ? 'selected' : '' ?>><?= $y ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="filter-group brands">
            <label class="form-label">Varumarken (<?= count($selectedBrands) ?: 'alla' ?>)</label>
            <div class="brand-pills">
                <?php foreach ($brands as $b): ?>
                <label class="brand-pill <?= in_array($b['id'], $selectedBrands) ? 'selected' : '' ?>">
                    <input type="checkbox" name="brands[]" value="<?= $b['id'] ?>"
                           <?= in_array($b['id'], $selectedBrands) ? 'checked' : '' ?>>
                    <?= htmlspecialchars($b['name']) ?>
                </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="filter-group" style="flex:0;">
            <button type="submit" class="btn-admin btn-admin-primary">
                <i data-lucide="filter"></i> Filtrera
            </button>
        </div>
    </div>
</form>

<!-- Stats Overview -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-value"><?= count($eventSummary) ?></div>
        <div class="stat-label">Events</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?= count($classAggregates) ?></div>
        <div class="stat-label">Unika klasser</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?= array_sum(array_column($classData, 'participants')) ?></div>
        <div class="stat-label">Totalt deltagare</div>
    </div>
    <div class="stat-card">
        <div class="stat-value">
            <?php
            $stageCounts = array_filter(array_column($classData, 'stage_count'));
            echo !empty($stageCounts) ? round(array_sum($stageCounts) / count($stageCounts), 1) : '-';
            ?>
        </div>
        <div class="stat-label">Snitt sträckor</div>
    </div>
</div>

<?php if (empty($eventSummary)): ?>
<div class="admin-card">
    <div class="no-data">
        <i data-lucide="inbox" style="width:48px;height:48px;margin-bottom:var(--space-md);opacity:0.5;"></i>
        <p>Ingen data hittades for valda filter.</p>
        <p style="font-size:0.85rem;">Valj varumarken och tryck Filtrera for att se data.</p>
    </div>
</div>
<?php else: ?>

<!-- Event Details -->
<div class="admin-card">
    <div class="admin-card-header">
        <h2><i data-lucide="calendar"></i> Events per klass (<?= $selectedYear ?>)</h2>
    </div>
    <div class="admin-card-body" style="padding:0;">
        <?php foreach ($eventSummary as $eventId => $event): ?>
        <div class="event-card" style="margin:var(--space-md);margin-bottom:var(--space-sm);">
            <div class="event-header">
                <div>
                    <span class="event-title"><?= htmlspecialchars($event['event_name']) ?></span>
                    <?php if ($event['brand_color']): ?>
                    <span class="brand-tag" style="background:<?= htmlspecialchars($event['brand_color']) ?>;">
                        <?= htmlspecialchars($event['brand_name']) ?>
                    </span>
                    <?php endif; ?>
                </div>
                <div class="event-meta">
                    <span><i data-lucide="calendar"></i> <?= date('Y-m-d', strtotime($event['event_date'])) ?></span>
                    <span><i data-lucide="users"></i> <?= $event['total_participants'] ?> deltagare</span>
                    <span><i data-lucide="layers"></i> <?= $event['class_count'] ?> klasser</span>
                </div>
            </div>
            <table class="class-table">
                <thead>
                    <tr>
                        <th>Klass</th>
                        <th>Deltagare</th>
                        <th>Sträckor</th>
                        <th>Vinnartid</th>
                        <th>Snitttid</th>
                        <th>Tidsspridning</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($event['classes'] as $class): ?>
                    <tr>
                        <td class="class-name"><?= htmlspecialchars($class['class_name']) ?></td>
                        <td><?= $class['participants'] ?></td>
                        <td>
                            <?php if (!empty($class['stages_used'])): ?>
                            <div class="stages-list">
                                <span class="stage-badge"><?= $class['stage_count'] ?></span>
                                <span class="stages-detail"><?= htmlspecialchars($class['stages_used']) ?></span>
                            </div>
                            <?php else: ?>
                            <span style="color:var(--color-text-muted);">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="time-cell"><?= formatTime($class['winner_time_sec']) ?></td>
                        <td class="time-cell"><?= formatTime($class['avg_time_sec']) ?></td>
                        <td class="time-cell">
                            <?php if ($class['time_spread_sec'] > 0): ?>
                            +<?= formatTime($class['time_spread_sec']) ?>
                            <?php else: ?>
                            -
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endforeach; ?>
        <div style="padding:var(--space-sm);"></div>
    </div>
</div>

<!-- Aggregate by Class -->
<div class="aggregate-section">
    <div class="admin-card">
        <div class="admin-card-header">
            <h2><i data-lucide="bar-chart-3"></i> Sammanfattning per klass</h2>
        </div>
        <div class="admin-card-body" style="padding:0;">
            <div class="table-responsive">
                <table class="aggregate-table">
                    <thead>
                        <tr>
                            <th>Klass</th>
                            <th>Antal events</th>
                            <th>Snitt deltagare</th>
                            <th>Snitt sträckor</th>
                            <th>Snitt vinnartid</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($classAggregates as $className => $agg): ?>
                        <tr>
                            <td class="class-name"><?= htmlspecialchars($className) ?></td>
                            <td><?= $agg['event_count'] ?></td>
                            <td><?= $agg['avg_participants'] ?></td>
                            <td>
                                <?php if ($agg['avg_stages'] > 0): ?>
                                <span class="stage-badge"><?= $agg['avg_stages'] ?></span>
                                <?php else: ?>
                                -
                                <?php endif; ?>
                            </td>
                            <td class="time-cell"><?= formatTime($agg['avg_winner_time']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php endif; ?>

<script>
// Toggle brand pill selection
document.querySelectorAll('.brand-pill').forEach(pill => {
    pill.addEventListener('click', function(e) {
        if (e.target.tagName !== 'INPUT') {
            const checkbox = this.querySelector('input');
            checkbox.checked = !checkbox.checked;
        }
        this.classList.toggle('selected', this.querySelector('input').checked);
    });
});

// Initialize Lucide icons
if (typeof lucide !== 'undefined') {
    lucide.createIcons();
}
</script>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>

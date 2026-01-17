<?php
/**
 * Analytics - Event Participation Analysis
 *
 * Analyserar deltagarmonster pa event-niva.
 * Forenklad version: Valj varumarke + ar.
 *
 * @package TheHUB Analytics
 * @version 3.3
 */
require_once __DIR__ . '/../config.php';
require_admin();

global $pdo;

// Parameters
$currentYear = (int)date('Y');
$selectedYear = isset($_GET['year']) ? (int)$_GET['year'] : $currentYear - 1;
$selectedBrandId = isset($_GET['brand']) ? (int)$_GET['brand'] : null;

// Fetch available brands
$brands = [];
try {
    $stmt = $pdo->query("
        SELECT b.id, b.name, b.short_code, b.color_primary,
               COUNT(DISTINCT s.id) as series_count
        FROM brands b
        LEFT JOIN brand_series_map bsm ON bsm.brand_id = b.id
        LEFT JOIN series s ON s.id = bsm.series_id
        WHERE b.active = 1
        GROUP BY b.id
        ORDER BY b.display_order, b.name
    ");
    $brands = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Fallback - hämta brands utan serie-count
    try {
        $stmt = $pdo->query("SELECT id, name, short_code, color_primary FROM brands WHERE active = 1 ORDER BY name");
        $brands = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e2) {}
}

// Fetch available years
$years = [];
try {
    $stmt = $pdo->query("
        SELECT DISTINCT YEAR(date) as year, COUNT(*) as event_count
        FROM events
        WHERE date IS NOT NULL AND active = 1
        GROUP BY YEAR(date)
        ORDER BY year DESC
    ");
    $years = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Validate year
$validYears = array_column($years, 'year');
if (!in_array($selectedYear, $validYears) && !empty($validYears)) {
    $selectedYear = $validYears[0];
}

// Fetch event participation data
$data = null;
$error = null;

try {
    // Bygg query baserat på filter
    $params = [$selectedYear];
    $brandJoin = '';
    $brandWhere = '';

    if ($selectedBrandId) {
        $brandJoin = "
            JOIN series_events se ON se.event_id = e.id
            JOIN series s ON s.id = se.series_id
            JOIN brand_series_map bsm ON bsm.series_id = s.id
        ";
        $brandWhere = "AND bsm.brand_id = ?";
        $params[] = $selectedBrandId;
    }

    // Hämta events med deltagare
    $sql = "
        SELECT
            e.id,
            e.name,
            e.date,
            e.location,
            COUNT(DISTINCT r.cyclist_id) as participants,
            COUNT(DISTINCT CASE WHEN r.position <= 3 THEN r.cyclist_id END) as podium_riders
        FROM events e
        JOIN results r ON r.event_id = e.id
        $brandJoin
        WHERE YEAR(e.date) = ?
        $brandWhere
        GROUP BY e.id
        ORDER BY e.date DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Beräkna statistik
    $totalParticipants = 0;
    $uniqueRiders = [];

    $riderSql = "
        SELECT DISTINCT r.cyclist_id
        FROM results r
        JOIN events e ON e.id = r.event_id
        $brandJoin
        WHERE YEAR(e.date) = ?
        $brandWhere
    ";
    $stmt = $pdo->prepare($riderSql);
    $stmt->execute($params);
    $uniqueRiders = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Deltagare per antal event
    $distributionSql = "
        SELECT
            events_attended,
            COUNT(*) as rider_count
        FROM (
            SELECT
                r.cyclist_id,
                COUNT(DISTINCT r.event_id) as events_attended
            FROM results r
            JOIN events e ON e.id = r.event_id
            $brandJoin
            WHERE YEAR(e.date) = ?
            $brandWhere
            GROUP BY r.cyclist_id
        ) rider_events
        GROUP BY events_attended
        ORDER BY events_attended
    ";
    $stmt = $pdo->prepare($distributionSql);
    $stmt->execute($params);
    $distribution = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $data = [
        'events' => $events,
        'total_events' => count($events),
        'total_participants' => array_sum(array_column($events, 'participants')),
        'unique_riders' => count($uniqueRiders),
        'distribution' => $distribution,
    ];

} catch (Exception $e) {
    $error = $e->getMessage();
}

// Page config
$page_title = 'Event Participation Analysis';
include __DIR__ . '/components/unified-layout.php';
?>

<style>
.brand-selector {
    display: flex;
    gap: var(--space-sm);
    flex-wrap: wrap;
    margin-bottom: var(--space-md);
}

.brand-btn {
    padding: var(--space-sm) var(--space-md);
    border-radius: var(--radius-md);
    border: 2px solid var(--color-border);
    background: var(--color-bg-card);
    color: var(--color-text-primary);
    cursor: pointer;
    transition: all 0.2s;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: var(--space-xs);
}

.brand-btn:hover {
    border-color: var(--color-accent);
}

.brand-btn.active {
    border-color: var(--color-accent);
    background: var(--color-accent-light);
}

.brand-btn .brand-dot {
    width: 12px;
    height: 12px;
    border-radius: 50%;
}

.year-selector {
    display: flex;
    gap: var(--space-xs);
    flex-wrap: wrap;
}

.year-btn {
    padding: var(--space-xs) var(--space-sm);
    border-radius: var(--radius-sm);
    border: 1px solid var(--color-border);
    background: var(--color-bg-card);
    color: var(--color-text-secondary);
    cursor: pointer;
    transition: all 0.2s;
    text-decoration: none;
    font-size: var(--text-sm);
}

.year-btn:hover {
    border-color: var(--color-accent);
}

.year-btn.active {
    border-color: var(--color-accent);
    background: var(--color-accent);
    color: white;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: var(--space-md);
    margin-bottom: var(--space-lg);
}

.stat-box {
    background: var(--color-bg-card);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    padding: var(--space-md);
    text-align: center;
}

.stat-box .value {
    font-family: var(--font-heading);
    font-size: var(--text-2xl);
    color: var(--color-accent);
}

.stat-box .label {
    font-size: var(--text-sm);
    color: var(--color-text-secondary);
    margin-top: var(--space-2xs);
}

.distribution-chart {
    display: flex;
    flex-direction: column;
    gap: var(--space-xs);
}

.dist-row {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
}

.dist-label {
    width: 80px;
    font-size: var(--text-sm);
    color: var(--color-text-secondary);
}

.dist-bar-bg {
    flex: 1;
    height: 24px;
    background: var(--color-bg-surface);
    border-radius: var(--radius-sm);
    overflow: hidden;
}

.dist-bar {
    height: 100%;
    background: linear-gradient(90deg, var(--color-accent), var(--color-accent-hover));
    border-radius: var(--radius-sm);
    display: flex;
    align-items: center;
    padding-left: var(--space-sm);
    color: white;
    font-size: var(--text-xs);
    font-weight: 600;
}

.dist-count {
    width: 60px;
    text-align: right;
    font-size: var(--text-sm);
    font-weight: 500;
}
</style>

<!-- Filter Section -->
<div class="card" style="margin-bottom: var(--space-lg);">
    <div class="card-body">
        <!-- Brand Selection -->
        <div style="margin-bottom: var(--space-md);">
            <label class="form-label">Varumarke</label>
            <div class="brand-selector">
                <a href="?year=<?= $selectedYear ?>" class="brand-btn <?= !$selectedBrandId ? 'active' : '' ?>">
                    Alla
                </a>
                <?php foreach ($brands as $brand): ?>
                <a href="?year=<?= $selectedYear ?>&brand=<?= $brand['id'] ?>"
                   class="brand-btn <?= $selectedBrandId == $brand['id'] ? 'active' : '' ?>">
                    <span class="brand-dot" style="background: <?= $brand['color_primary'] ?: 'var(--color-accent)' ?>;"></span>
                    <?= htmlspecialchars($brand['short_code'] ?: $brand['name']) ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Year Selection -->
        <div>
            <label class="form-label">Artal</label>
            <div class="year-selector">
                <?php foreach ($years as $y): ?>
                <a href="?year=<?= $y['year'] ?><?= $selectedBrandId ? '&brand=' . $selectedBrandId : '' ?>"
                   class="year-btn <?= $selectedYear == $y['year'] ? 'active' : '' ?>">
                    <?= $y['year'] ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<?php if ($error): ?>
<div class="alert alert-danger">
    <i data-lucide="alert-circle"></i>
    <?= htmlspecialchars($error) ?>
</div>
<?php elseif ($data): ?>

<!-- Stats Overview -->
<div class="stats-grid">
    <div class="stat-box">
        <div class="value"><?= number_format($data['total_events']) ?></div>
        <div class="label">Event</div>
    </div>
    <div class="stat-box">
        <div class="value"><?= number_format($data['unique_riders']) ?></div>
        <div class="label">Unika deltagare</div>
    </div>
    <div class="stat-box">
        <div class="value"><?= number_format($data['total_participants']) ?></div>
        <div class="label">Totalt starter</div>
    </div>
    <div class="stat-box">
        <div class="value"><?= $data['unique_riders'] > 0 ? round($data['total_participants'] / $data['unique_riders'], 1) : 0 ?></div>
        <div class="label">Snitt starter/person</div>
    </div>
</div>

<!-- Distribution Chart -->
<?php if (!empty($data['distribution'])): ?>
<div class="card">
    <div class="card-header">
        <h3>Deltagare per antal event</h3>
    </div>
    <div class="card-body">
        <div class="distribution-chart">
            <?php
            $maxCount = max(array_column($data['distribution'], 'rider_count'));
            $totalRiders = array_sum(array_column($data['distribution'], 'rider_count'));
            foreach ($data['distribution'] as $d):
                $pct = $totalRiders > 0 ? round(($d['rider_count'] / $totalRiders) * 100, 1) : 0;
                $barWidth = $maxCount > 0 ? ($d['rider_count'] / $maxCount) * 100 : 0;
            ?>
            <div class="dist-row">
                <div class="dist-label"><?= $d['events_attended'] ?> event</div>
                <div class="dist-bar-bg">
                    <div class="dist-bar" style="width: <?= $barWidth ?>%;">
                        <?= $pct ?>%
                    </div>
                </div>
                <div class="dist-count"><?= number_format($d['rider_count']) ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Events Table -->
<div class="card">
    <div class="card-header">
        <h3>Event <?= $selectedYear ?></h3>
    </div>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Event</th>
                    <th>Datum</th>
                    <th>Plats</th>
                    <th class="text-right">Deltagare</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($data['events'] as $event): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($event['name']) ?></strong></td>
                    <td><?= date('Y-m-d', strtotime($event['date'])) ?></td>
                    <td><?= htmlspecialchars($event['location'] ?: '-') ?></td>
                    <td class="text-right"><?= number_format($event['participants']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php endif; ?>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>

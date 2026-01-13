<?php
/**
 * Analytics Diagnostik
 *
 * Jamfor faktisk data i results/events med rider_yearly_stats
 * for att identifiera diskrepanser.
 *
 * @package TheHUB Analytics
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

global $pdo;

$diagnostics = [];
$errors = [];

// 1. Kolla om nodvandiga tabeller/views finns
$requiredObjects = [
    'rider_yearly_stats' => 'TABLE',
    'series_participation' => 'TABLE',
    'v_canonical_riders' => 'VIEW',
    'rider_merge_map' => 'TABLE'
];

foreach ($requiredObjects as $name => $type) {
    try {
        $check = $pdo->query("SHOW TABLES LIKE '$name'")->fetch();
        $diagnostics['objects'][$name] = $check ? 'EXISTS' : 'MISSING';
    } catch (Exception $e) {
        $diagnostics['objects'][$name] = 'ERROR: ' . $e->getMessage();
    }
}

// 2. Jamfor faktiska deltagare med rider_yearly_stats per ar
try {
    $stmt = $pdo->query("
        SELECT
            YEAR(e.date) as year,
            COUNT(DISTINCT r.cyclist_id) as actual_unique_riders,
            COUNT(*) as total_result_rows
        FROM results r
        JOIN events e ON r.event_id = e.id
        WHERE e.date IS NOT NULL
        GROUP BY YEAR(e.date)
        ORDER BY year DESC
    ");
    $diagnostics['actual_data'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $errors[] = "Kunde inte hamta faktisk data: " . $e->getMessage();
}

// 3. Hamta rider_yearly_stats per ar
try {
    $stmt = $pdo->query("
        SELECT
            season_year as year,
            COUNT(*) as analytics_riders
        FROM rider_yearly_stats
        GROUP BY season_year
        ORDER BY season_year DESC
    ");
    $diagnostics['analytics_data'] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Exception $e) {
    $errors[] = "Kunde inte hamta analytics data: " . $e->getMessage();
    $diagnostics['analytics_data'] = [];
}

// 4. Kolla v_canonical_riders VIEW
try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM v_canonical_riders");
    $diagnostics['canonical_riders_count'] = $stmt->fetchColumn();
} catch (Exception $e) {
    $errors[] = "v_canonical_riders VIEW saknas eller ar trasig: " . $e->getMessage();
    $diagnostics['canonical_riders_count'] = 'ERROR';
}

// 5. Kolla total riders i riders-tabellen
try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM riders");
    $diagnostics['total_riders'] = $stmt->fetchColumn();
} catch (Exception $e) {
    $diagnostics['total_riders'] = 'ERROR';
}

// 6. Storsta event per ar (for referens)
try {
    $stmt = $pdo->query("
        SELECT
            YEAR(e.date) as year,
            e.name as event_name,
            e.date,
            COUNT(DISTINCT r.cyclist_id) as participants
        FROM results r
        JOIN events e ON r.event_id = e.id
        WHERE e.date IS NOT NULL
        GROUP BY e.id
        ORDER BY year DESC, participants DESC
    ");
    $allEvents = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Gruppera och ta max per ar
    $maxPerYear = [];
    foreach ($allEvents as $ev) {
        $y = $ev['year'];
        if (!isset($maxPerYear[$y]) || $ev['participants'] > $maxPerYear[$y]['participants']) {
            $maxPerYear[$y] = $ev;
        }
    }
    $diagnostics['largest_events'] = $maxPerYear;
} catch (Exception $e) {
    $errors[] = "Kunde inte hamta event-data: " . $e->getMessage();
}

// 7. Kolla cron-runs status
try {
    $stmt = $pdo->query("
        SELECT job_name, run_key, status, started_at, finished_at, rows_affected
        FROM analytics_cron_runs
        ORDER BY started_at DESC
        LIMIT 20
    ");
    $diagnostics['cron_runs'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $diagnostics['cron_runs'] = [];
}

// 8. Testa JOIN mellan results och v_canonical_riders for 2024
try {
    $testYear = 2024;
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT v.canonical_rider_id)
        FROM results res
        JOIN events e ON res.event_id = e.id
        JOIN v_canonical_riders v ON res.cyclist_id = v.original_rider_id
        WHERE YEAR(e.date) = ?
    ");
    $stmt->execute([$testYear]);
    $diagnostics['join_test_2024'] = $stmt->fetchColumn();

    // Hur manga saknar mappning?
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT res.cyclist_id)
        FROM results res
        JOIN events e ON res.event_id = e.id
        LEFT JOIN v_canonical_riders v ON res.cyclist_id = v.original_rider_id
        WHERE YEAR(e.date) = ?
          AND v.original_rider_id IS NULL
    ");
    $stmt->execute([$testYear]);
    $diagnostics['unmapped_2024'] = $stmt->fetchColumn();
} catch (Exception $e) {
    $errors[] = "Join-test misslyckades: " . $e->getMessage();
}

$page_title = 'Analytics Diagnostik';
$breadcrumbs = [
    ['label' => 'Tools', 'url' => '/admin/tools.php'],
    ['label' => 'Analytics Diagnostik']
];
include __DIR__ . '/components/unified-layout.php';
?>

<style>
.diag-card {
    background: var(--color-bg-card);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    padding: var(--space-lg);
    margin-bottom: var(--space-lg);
}
.diag-card h3 {
    margin: 0 0 var(--space-md);
    font-size: var(--text-lg);
    color: var(--color-text-primary);
}
.status-ok { color: var(--color-success); font-weight: 600; }
.status-error { color: var(--color-error); font-weight: 600; }
.status-warning { color: var(--color-warning); font-weight: 600; }
.compare-table th { text-align: left; padding: var(--space-sm); }
.compare-table td { padding: var(--space-sm); }
.compare-table tr:nth-child(even) { background: var(--color-bg-hover); }
.discrepancy { background: rgba(239, 68, 68, 0.15) !important; }
.big-number {
    font-size: var(--text-2xl);
    font-weight: 700;
    color: var(--color-accent);
}
.info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: var(--space-md);
}
.info-box {
    background: var(--color-bg-surface);
    padding: var(--space-md);
    border-radius: var(--radius-sm);
    text-align: center;
}
.info-box .label {
    font-size: var(--text-sm);
    color: var(--color-text-muted);
}
</style>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger">
    <i data-lucide="alert-circle"></i>
    <div>
        <strong>Fel upptackta:</strong>
        <ul style="margin: var(--space-sm) 0 0; padding-left: var(--space-lg);">
            <?php foreach ($errors as $err): ?>
                <li><?= htmlspecialchars($err) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>
<?php endif; ?>

<!-- DATABAS-OBJEKT -->
<div class="diag-card">
    <h3>1. Databas-objekt</h3>
    <div class="info-grid">
        <?php foreach ($diagnostics['objects'] as $name => $status): ?>
        <div class="info-box">
            <div class="label"><?= htmlspecialchars($name) ?></div>
            <div class="<?= $status === 'EXISTS' ? 'status-ok' : 'status-error' ?>">
                <?= htmlspecialchars($status) ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php if (isset($diagnostics['canonical_riders_count'])): ?>
    <div style="margin-top: var(--space-md);">
        <strong>v_canonical_riders:</strong>
        <?= is_numeric($diagnostics['canonical_riders_count'])
            ? number_format($diagnostics['canonical_riders_count']) . ' rader'
            : '<span class="status-error">' . $diagnostics['canonical_riders_count'] . '</span>' ?>

        <?php if (isset($diagnostics['total_riders'])): ?>
        | <strong>riders-tabell:</strong> <?= number_format($diagnostics['total_riders']) ?> rader
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<!-- JAMFORELSE -->
<div class="diag-card">
    <h3>2. Jamforelse: Faktiska deltagare vs Analytics</h3>
    <p style="color: var(--color-text-secondary); margin-bottom: var(--space-md);">
        Rodfargade rader indikerar stor diskrepans (analytics < 50% av faktiska deltagare)
    </p>

    <div class="admin-table-container">
        <table class="admin-table compare-table">
            <thead>
                <tr>
                    <th>Ar</th>
                    <th>Faktiska deltagare</th>
                    <th>Analytics (rider_yearly_stats)</th>
                    <th>Differens</th>
                    <th>Storsta event</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($diagnostics['actual_data'] ?? [] as $row):
                    $year = $row['year'];
                    $actual = (int)$row['actual_unique_riders'];
                    $analytics = (int)($diagnostics['analytics_data'][$year] ?? 0);
                    $diff = $analytics - $actual;
                    $pct = $actual > 0 ? round($analytics / $actual * 100, 1) : 0;
                    $isDiscrepancy = $pct < 50 || $actual > 0 && $analytics === 0;

                    $largestEvent = $diagnostics['largest_events'][$year] ?? null;
                ?>
                <tr class="<?= $isDiscrepancy ? 'discrepancy' : '' ?>">
                    <td><strong><?= $year ?></strong></td>
                    <td><?= number_format($actual) ?></td>
                    <td>
                        <?= number_format($analytics) ?>
                        <?php if ($analytics === 0): ?>
                            <span class="status-error">(SAKNAS!)</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($actual > 0): ?>
                            <span class="<?= $pct >= 90 ? 'status-ok' : ($pct >= 50 ? 'status-warning' : 'status-error') ?>">
                                <?= $pct ?>%
                            </span>
                            (<?= $diff >= 0 ? '+' : '' ?><?= $diff ?>)
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($largestEvent): ?>
                            <?= htmlspecialchars($largestEvent['event_name']) ?>
                            (<?= number_format($largestEvent['participants']) ?>)
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- JOIN-TEST -->
<?php if (isset($diagnostics['join_test_2024'])): ?>
<div class="diag-card">
    <h3>3. Join-test for 2024</h3>
    <div class="info-grid">
        <div class="info-box">
            <div class="label">Riders via v_canonical_riders JOIN</div>
            <div class="big-number"><?= number_format($diagnostics['join_test_2024']) ?></div>
        </div>
        <div class="info-box">
            <div class="label">Riders UTAN mappning</div>
            <div class="big-number <?= $diagnostics['unmapped_2024'] > 0 ? 'status-error' : 'status-ok' ?>">
                <?= number_format($diagnostics['unmapped_2024'] ?? 0) ?>
            </div>
        </div>
    </div>

    <?php if (($diagnostics['unmapped_2024'] ?? 0) > 0): ?>
    <div class="alert alert-warning" style="margin-top: var(--space-md);">
        <i data-lucide="alert-triangle"></i>
        <div>
            <strong>Problem:</strong> <?= number_format($diagnostics['unmapped_2024']) ?> riders i 2024 har cyclist_id som inte finns i v_canonical_riders.<br>
            Detta kan bero pa att results.cyclist_id refererar till riders som inte langre finns, eller att VIEW:en inte ar korrekt.
        </div>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- CRON-KORNINGAR -->
<?php if (!empty($diagnostics['cron_runs'])): ?>
<div class="diag-card">
    <h3>4. Senaste Analytics-korningar</h3>
    <div class="admin-table-container">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Jobb</th>
                    <th>Ar/Key</th>
                    <th>Status</th>
                    <th>Rader</th>
                    <th>Startad</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($diagnostics['cron_runs'] as $run): ?>
                <tr>
                    <td><?= htmlspecialchars($run['job_name']) ?></td>
                    <td><?= htmlspecialchars($run['run_key']) ?></td>
                    <td>
                        <span class="<?= $run['status'] === 'success' ? 'status-ok' : ($run['status'] === 'started' ? 'status-warning' : 'status-error') ?>">
                            <?= htmlspecialchars($run['status']) ?>
                        </span>
                    </td>
                    <td><?= number_format($run['rows_affected'] ?? 0) ?></td>
                    <td><?= $run['started_at'] ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- ATGARDER -->
<div class="diag-card">
    <h3>5. Rekommenderade atgarder</h3>

    <?php
    $hasViewIssue = ($diagnostics['objects']['v_canonical_riders'] ?? '') !== 'EXISTS';
    $hasDataIssue = false;
    foreach ($diagnostics['actual_data'] ?? [] as $row) {
        $year = $row['year'];
        $actual = (int)$row['actual_unique_riders'];
        $analytics = (int)($diagnostics['analytics_data'][$year] ?? 0);
        if ($actual > 0 && ($analytics === 0 || $analytics < $actual * 0.5)) {
            $hasDataIssue = true;
            break;
        }
    }
    ?>

    <?php if ($hasViewIssue): ?>
    <div class="alert alert-danger">
        <i data-lucide="database"></i>
        <div>
            <strong>1. Skapa v_canonical_riders VIEW</strong><br>
            Kor migrations for att skapa nodvandiga tabeller och views:<br>
            <code>php analytics/setup-governance.php</code><br>
            <code>php analytics/setup-tables.php</code>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($hasDataIssue): ?>
    <div class="alert alert-warning">
        <i data-lucide="refresh-cw"></i>
        <div>
            <strong><?= $hasViewIssue ? '2' : '1' ?>. Kor populate-historical med --force</strong><br>
            For att regenerera all analytics-data:<br>
            <code>php analytics/populate-historical.php --force</code><br>
            Eller via webben: <a href="/analytics/populate-historical.php?force=1">Kor med force</a>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!$hasViewIssue && !$hasDataIssue): ?>
    <div class="alert alert-success">
        <i data-lucide="check-circle"></i>
        <div>
            <strong>Allt ser bra ut!</strong><br>
            Tabeller och data matchar.
        </div>
    </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>

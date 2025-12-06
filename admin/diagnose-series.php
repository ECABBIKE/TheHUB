<?php
/**
 * Diagnose Series Championship Issues
 * Helps identify why series champions aren't being calculated
 */
require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();
$pdo = $db->getPdo();

$currentYear = (int)date('Y');

$page_title = 'Diagnostik: Seriemästare';
$breadcrumbs = [
    ['label' => 'Verktyg', 'url' => '/admin/tools.php'],
    ['label' => 'Diagnos Seriemästare']
];
include __DIR__ . '/components/unified-layout.php';
?>

<div class="card mb-lg">
    <div class="card-header">
        <h2>🔍 Serie-status Översikt</h2>
    </div>
    <div class="card-body">
        <p class="text-secondary mb-md">
            <strong>Krav för seriemästare:</strong> Serien kvalificerar om minst ett av följande är sant:
        </p>
        <ul class="text-secondary mb-md" style="margin-left: 1.5rem;">
            <li><code>end_date</code> har passerat (automatiskt! ✨)</li>
            <li><code>status = 'completed'</code></li>
            <li>Events från tidigare år (före <?= $currentYear ?>)</li>
        </ul>

        <?php
        // Check if series_events junction table exists
        $seriesEventsExists = false;
        try {
            $check = $db->getAll("SHOW TABLES LIKE 'series_events'");
            $seriesEventsExists = !empty($check);
        } catch (Exception $e) {}

        // Get all series with their status and year info
        if ($seriesEventsExists) {
            $series = $db->getAll("
                SELECT
                    s.id,
                    s.name,
                    s.year as series_year,
                    s.status,
                    s.end_date,
                    MIN(YEAR(e.date)) as first_event_year,
                    MAX(YEAR(e.date)) as last_event_year,
                    COUNT(DISTINCT e.id) as event_count,
                    COUNT(DISTINCT r.cyclist_id) as rider_count
                FROM series s
                LEFT JOIN series_events se ON se.series_id = s.id
                LEFT JOIN events e ON e.id = se.event_id
                LEFT JOIN results r ON r.event_id = e.id AND r.status = 'finished'
                GROUP BY s.id
                ORDER BY s.year DESC, s.name
            ");
        } else {
            $series = $db->getAll("
                SELECT
                    s.id,
                    s.name,
                    s.year as series_year,
                    s.status,
                    s.end_date,
                    MIN(YEAR(e.date)) as first_event_year,
                    MAX(YEAR(e.date)) as last_event_year,
                    COUNT(DISTINCT e.id) as event_count,
                    COUNT(DISTINCT r.cyclist_id) as rider_count
                FROM series s
                LEFT JOIN events e ON e.series_id = s.id
                LEFT JOIN results r ON r.event_id = e.id AND r.status = 'finished'
                GROUP BY s.id
                ORDER BY s.year DESC, s.name
            ");
        }
        $today = date('Y-m-d');
        ?>

        <table class="table table--striped">
            <thead>
                <tr>
                    <th>Serie</th>
                    <th>År</th>
                    <th>End Date</th>
                    <th>Status</th>
                    <th>Events</th>
                    <th>Åkare</th>
                    <th>Kvalificerar?</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($series as $s):
                    $isCompleted = $s['status'] === 'completed';
                    $endDatePassed = $s['end_date'] && $s['end_date'] < $today;
                    $hasPastEvents = $s['last_event_year'] && $s['last_event_year'] < $currentYear;
                    $qualifies = $isCompleted || $endDatePassed || $hasPastEvents;
                ?>
                <tr class="<?= $qualifies ? 'bg-success-light' : '' ?>">
                    <td><strong><?= h($s['name']) ?></strong></td>
                    <td><?= $s['series_year'] ?? '<span class="text-warning">-</span>' ?></td>
                    <td>
                        <?php if ($s['end_date']): ?>
                            <?= $s['end_date'] ?>
                            <?php if ($endDatePassed): ?>
                                <span class="text-success">✓ passerat</span>
                            <?php else: ?>
                                <span class="text-warning">⏳ framtid</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="text-secondary">-</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($isCompleted): ?>
                            <span class="admin-badge admin-badge-success">completed ✓</span>
                        <?php else: ?>
                            <span class="admin-badge admin-badge-secondary"><?= h($s['status'] ?? 'N/A') ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?= $s['event_count'] ?></td>
                    <td><?= $s['rider_count'] ?></td>
                    <td>
                        <?php if ($qualifies): ?>
                            <span class="text-success"><strong>JA</strong></span>
                            <?php if ($endDatePassed): ?><br><small>via end_date</small><?php endif; ?>
                        <?php else: ?>
                            <span class="text-error">NEJ</span>
                            <br><small class="text-secondary">Sätt end_date eller status='completed'</small>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card mb-lg">
    <div class="card-header">
        <h2>🏆 Potentiella Seriemästare</h2>
    </div>
    <div class="card-body">
        <p class="text-secondary mb-md">
            Visar ledare per serie/klass. De som kvalificerar (grönmarkerade) räknas automatiskt som seriemästare vid rebuild.
        </p>

        <?php
        // Check if series_events junction table exists
        $seriesEventsExists = false;
        try {
            $check = $db->getAll("SHOW TABLES LIKE 'series_events'");
            $seriesEventsExists = !empty($check);
        } catch (Exception $e) {}

        // Debug: Show connection method and test each join step by step
        echo '<div class="alert alert-info mb-md">';
        echo '<strong>Debug:</strong> Använder ' . ($seriesEventsExists ? 'series_events' : 'events.series_id') . ' för koppling<br>';

        // Test each join step
        $test1 = $db->getRow("SELECT COUNT(*) as cnt FROM results WHERE status = 'finished'");
        echo "1. Results (finished): " . ($test1['cnt'] ?? 0) . "<br>";

        $test2 = $db->getRow("SELECT COUNT(*) as cnt FROM results r JOIN events e ON r.event_id = e.id WHERE r.status = 'finished'");
        echo "2. + events: " . ($test2['cnt'] ?? 0) . "<br>";

        $test3 = $db->getRow("SELECT COUNT(*) as cnt FROM results r JOIN events e ON r.event_id = e.id JOIN series_events se ON se.event_id = e.id WHERE r.status = 'finished'");
        echo "3. + series_events: " . ($test3['cnt'] ?? 0) . "<br>";

        $test4 = $db->getRow("SELECT COUNT(*) as cnt FROM results r JOIN events e ON r.event_id = e.id JOIN series_events se ON se.event_id = e.id JOIN series s ON se.series_id = s.id WHERE r.status = 'finished'");
        echo "4. + series: " . ($test4['cnt'] ?? 0) . "<br>";

        $test5 = $db->getRow("SELECT COUNT(*) as cnt FROM results r JOIN events e ON r.event_id = e.id JOIN series_events se ON se.event_id = e.id JOIN series s ON se.series_id = s.id JOIN riders rd ON r.cyclist_id = rd.id WHERE r.status = 'finished'");
        echo "5. + riders: " . ($test5['cnt'] ?? 0) . "<br>";

        echo '</div>';

        // Find potential series champions using a simpler two-step approach
        // Step 1: Get all rider totals per series/class (using both connection methods)
        try {
            if ($seriesEventsExists) {
                // Use series_events junction table
                // Note: Using MAX() for non-grouped columns to satisfy ONLY_FULL_GROUP_BY
                $allTotals = $db->getAll("
                    SELECT
                        s.id as series_id,
                        MAX(s.name) as series_name,
                        COALESCE(MAX(s.year), YEAR(MAX(e.date))) as effective_year,
                        MAX(s.status) as status,
                        MAX(s.end_date) as end_date,
                        r.class_id,
                        MAX(c.display_name) as class_name,
                        r.cyclist_id,
                        MAX(CONCAT(rd.first_name, ' ', rd.last_name)) as rider_name,
                        SUM(r.points) as total_points
                    FROM results r
                    JOIN events e ON r.event_id = e.id
                    JOIN series_events se ON se.event_id = e.id
                    JOIN series s ON se.series_id = s.id
                    LEFT JOIN classes c ON r.class_id = c.id
                    JOIN riders rd ON r.cyclist_id = rd.id
                    WHERE r.status = 'finished'
                    GROUP BY s.id, r.class_id, r.cyclist_id
                    ORDER BY series_name, class_name, total_points DESC
                ");
            } else {
                // Fallback: use events.series_id
                $allTotals = $db->getAll("
                    SELECT
                        s.id as series_id,
                        MAX(s.name) as series_name,
                        COALESCE(MAX(s.year), YEAR(MAX(e.date))) as effective_year,
                        MAX(s.status) as status,
                        MAX(s.end_date) as end_date,
                        r.class_id,
                        MAX(c.display_name) as class_name,
                        r.cyclist_id,
                        MAX(CONCAT(rd.first_name, ' ', rd.last_name)) as rider_name,
                        SUM(r.points) as total_points
                    FROM results r
                    JOIN events e ON r.event_id = e.id
                    JOIN series s ON e.series_id = s.id
                    LEFT JOIN classes c ON r.class_id = c.id
                    JOIN riders rd ON r.cyclist_id = rd.id
                    WHERE r.status = 'finished'
                      AND e.series_id IS NOT NULL
                    GROUP BY s.id, r.class_id, r.cyclist_id
                    ORDER BY series_name, class_name, total_points DESC
                ");
            }

            // Debug: Show query result count
            echo '<div class="alert alert-info mb-md">';
            echo '<strong>Debug:</strong> Hittade ' . count($allTotals) . ' rader från frågan';
            if (count($allTotals) > 0) {
                echo '<br>Första raden: ' . json_encode($allTotals[0]);
            }
            echo '</div>';

        } catch (Exception $e) {
            echo '<div class="alert alert-danger mb-md">';
            echo '<strong>Fel:</strong> ' . htmlspecialchars($e->getMessage());
            echo '</div>';
            $allTotals = [];
        }

        // Step 2: Filter to keep only the max scorers per series/class
        $maxPoints = [];
        foreach ($allTotals as $row) {
            $key = $row['series_id'] . '_' . $row['class_id'];
            if (!isset($maxPoints[$key]) || $row['total_points'] > $maxPoints[$key]) {
                $maxPoints[$key] = $row['total_points'];
            }
        }

        $potentialChampions = [];
        foreach ($allTotals as $row) {
            $key = $row['series_id'] . '_' . $row['class_id'];
            if ($row['total_points'] == $maxPoints[$key] && $row['total_points'] > 0) {
                $potentialChampions[] = $row;
            }
        }

        // Limit to 50
        $potentialChampions = array_slice($potentialChampions, 0, 50);

        if (empty($potentialChampions)):
        ?>
            <div class="alert alert-warning">Inga resultat hittades.</div>
        <?php else: ?>
        <table class="table table--striped">
            <thead>
                <tr>
                    <th>Serie</th>
                    <th>År</th>
                    <th>End Date</th>
                    <th>Status</th>
                    <th>Klass</th>
                    <th>Ledare</th>
                    <th>Poäng</th>
                    <th>Kvalificerar?</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($potentialChampions as $pc):
                    $isCompleted = $pc['status'] === 'completed';
                    $endDatePassed = $pc['end_date'] && $pc['end_date'] < $today;
                    $isPast = $pc['effective_year'] < $currentYear;
                    $wouldQualify = $isCompleted || $endDatePassed || $isPast;
                ?>
                <tr class="<?= $wouldQualify ? 'bg-success-light' : '' ?>">
                    <td><?= h($pc['series_name']) ?></td>
                    <td><?= $pc['effective_year'] ?></td>
                    <td>
                        <?php if ($pc['end_date']): ?>
                            <?= $pc['end_date'] ?>
                            <?= $endDatePassed ? '<span class="text-success">✓</span>' : '' ?>
                        <?php else: ?>
                            <span class="text-secondary">-</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="admin-badge <?= $isCompleted ? 'admin-badge-success' : 'admin-badge-secondary' ?>">
                            <?= h($pc['status']) ?>
                        </span>
                    </td>
                    <td><?= h($pc['class_name']) ?></td>
                    <td>
                        <a href="/admin/riders/edit/<?= $pc['cyclist_id'] ?>">
                            <?= h($pc['rider_name']) ?>
                        </a>
                    </td>
                    <td><strong><?= $pc['total_points'] ?></strong></td>
                    <td>
                        <?php if ($wouldQualify): ?>
                            <span class="text-success">✓ JA</span>
                        <?php else: ?>
                            <span class="text-warning">⏳ Nej</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<div class="card mb-lg">
    <div class="card-header">
        <h2>✅ Existerande Seriemästar-Achievements</h2>
    </div>
    <div class="card-body">
        <?php
        $existingChampions = $db->getAll("
            SELECT
                ra.rider_id,
                CONCAT(r.first_name, ' ', r.last_name) as rider_name,
                ra.achievement_value as series_name,
                ra.season_year,
                ra.earned_at
            FROM rider_achievements ra
            JOIN riders r ON ra.rider_id = r.id
            WHERE ra.achievement_type = 'series_champion'
            ORDER BY ra.season_year DESC, ra.earned_at DESC
            LIMIT 50
        ");

        if (empty($existingChampions)):
        ?>
            <div class="alert alert-warning">
                <strong>Inga seriemästare registrerade!</strong><br>
                Serier behöver ha <code>end_date</code> som passerat för automatisk mästare-beräkning.
            </div>

            <h4 class="mt-lg mb-md">Åtgärd:</h4>
            <ol>
                <li>Gå till <a href="/admin/series">/admin/series</a></li>
                <li>Klicka på en avslutad serie för att redigera</li>
                <li>Sätt <strong>Slutdatum</strong> till seriens sista dag</li>
                <li>Spara</li>
                <li>Gå till <a href="/admin/rebuild-stats">/admin/rebuild-stats</a></li>
                <li>Kör <strong>Rebuild alla åkare</strong></li>
            </ol>
            <p class="text-secondary mt-md">
                <strong>Tips:</strong> Så länge <code>end_date</code> har passerat räknas mästare automatiskt!
            </p>
        <?php else: ?>
        <table class="table table--striped">
            <thead>
                <tr>
                    <th>Åkare</th>
                    <th>Serie</th>
                    <th>År</th>
                    <th>Registrerad</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($existingChampions as $ec): ?>
                <tr>
                    <td>
                        <a href="/admin/riders/edit/<?= $ec['rider_id'] ?>">
                            <?= h($ec['rider_name']) ?>
                        </a>
                    </td>
                    <td><?= h($ec['series_name']) ?></td>
                    <td><?= $ec['season_year'] ?></td>
                    <td><?= date('Y-m-d H:i', strtotime($ec['earned_at'])) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2>📋 Snabbåtgärder</h2>
    </div>
    <div class="card-body">
        <div class="flex gap-md" style="flex-wrap: wrap;">
            <a href="/admin/series" class="btn btn--primary">
                → Hantera Serier
            </a>
            <a href="/admin/rebuild-stats" class="btn btn--secondary">
                → Rebuild Stats
            </a>
        </div>
    </div>
</div>

<style>
.bg-success-light {
    background: rgba(97, 206, 112, 0.15);
}
.text-success { color: var(--color-success); }
.text-error { color: var(--color-danger); }
.text-warning { color: var(--color-warning); }
.text-secondary { color: var(--color-text-secondary); }
code {
    background: var(--color-bg-tertiary);
    padding: 2px 6px;
    border-radius: 4px;
    font-size: 0.85em;
}
</style>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>

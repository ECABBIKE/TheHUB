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
            <li><code>status = 'completed'</code> (manuellt markerad som avslutad)</li>
            <li>Events från tidigare år (före <?= $currentYear ?>)</li>
        </ul>
        <p class="text-secondary mb-md" style="font-size: 0.9em;">
            <strong>OBS:</strong> <code>end_date</code> används inte längre automatiskt - serien måste markeras som <em>completed</em> manuellt för att undvika felaktiga mästarskap om resultat saknas.
        </p>

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
                    $hasPastEvents = $s['last_event_year'] && $s['last_event_year'] < $currentYear;
                    $qualifies = $isCompleted || $hasPastEvents;
                ?>
                <tr class="<?= $qualifies ? 'bg-success-light' : '' ?>">
                    <td><strong><?= h($s['name']) ?></strong></td>
                    <td><?= $s['series_year'] ?? '<span class="text-warning">-</span>' ?></td>
                    <td>
                        <?php if ($s['end_date']): ?>
                            <?= $s['end_date'] ?>
                            <span class="text-secondary">(info)</span>
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
                            <?php if ($isCompleted): ?><br><small>via completed</small><?php endif; ?>
                            <?php if ($hasPastEvents): ?><br><small>via tidigare år</small><?php endif; ?>
                        <?php else: ?>
                            <span class="text-error">NEJ</span>
                            <br><small class="text-secondary">Markera som completed</small>
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


        // Find potential series champions - use simple query that works, then enrich in PHP
        try {
            // Simple query that we know works from test #6
            $allTotals = $db->getAll("
                SELECT
                    s.id as series_id,
                    r.class_id,
                    r.cyclist_id,
                    SUM(r.points) as total_points
                FROM results r
                JOIN events e ON r.event_id = e.id
                JOIN series_events se ON se.event_id = e.id
                JOIN series s ON se.series_id = s.id
                JOIN riders rd ON r.cyclist_id = rd.id
                WHERE r.status = 'finished'
                GROUP BY s.id, r.class_id, r.cyclist_id
                ORDER BY s.id, r.class_id, total_points DESC
            ");


            // Enrich with series, class, and rider info (cast ids to int for reliable lookup)
            $seriesInfo = [];
            $seriesData = $db->getAll("SELECT id, name, year, status, end_date FROM series");
            foreach ($seriesData as $s) {
                $seriesInfo[(int)$s['id']] = $s;
            }

            $classInfo = [];
            $classData = $db->getAll("SELECT id, display_name FROM classes");
            foreach ($classData as $c) {
                $classInfo[(int)$c['id']] = $c['display_name'];
            }

            $riderInfo = [];
            $riderData = $db->getAll("SELECT id, first_name, last_name FROM riders");
            foreach ($riderData as $r) {
                $riderInfo[(int)$r['id']] = $r['first_name'] . ' ' . $r['last_name'];
            }

            // Add enriched data to results
            foreach ($allTotals as &$row) {
                $sid = (int)$row['series_id'];
                $cid = (int)$row['class_id'];
                $rid = (int)$row['cyclist_id'];
                $row['series_name'] = $seriesInfo[$sid]['name'] ?? 'Okänd';
                $row['effective_year'] = $seriesInfo[$sid]['year'] ?? date('Y');
                $row['status'] = $seriesInfo[$sid]['status'] ?? 'active';
                $row['end_date'] = $seriesInfo[$sid]['end_date'] ?? null;
                $row['class_name'] = $classInfo[$cid] ?? 'Okänd';
                $row['rider_name'] = $riderInfo[$rid] ?? 'Okänd';
            }
            unset($row);

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
                    $isPast = (int)$pc['effective_year'] < $currentYear;
                    $wouldQualify = $isCompleted || $isPast;
                ?>
                <tr class="<?= $wouldQualify ? 'bg-success-light' : '' ?>">
                    <td><?= h($pc['series_name']) ?></td>
                    <td><?= $pc['effective_year'] ?></td>
                    <td>
                        <?php if ($pc['end_date']): ?>
                            <?= $pc['end_date'] ?>
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

        // Debug: Detailed test of calculateSeriesChampionships for first QUALIFYING potential champion
        // Find first potential champion from a qualifying series (completed or past year)
        $testChampion = null;
        foreach ($potentialChampions as $pc) {
            $isCompleted = $pc['status'] === 'completed';
            $isPast = (int)$pc['effective_year'] < $currentYear;
            if ($isCompleted || $isPast) {
                $testChampion = $pc;
                break;
            }
        }
        // Fallback to first if no qualifying found
        if (!$testChampion && !empty($potentialChampions)) {
            $testChampion = $potentialChampions[0];
        }

        if ($testChampion) {
            $testRider = (int)$testChampion['cyclist_id'];
            $testRiderName = $testChampion['rider_name'];
            $testSeriesId = (int)$testChampion['series_id'];
            $testClassId = (int)$testChampion['class_id'];
            $testPoints = (int)$testChampion['total_points'];
            $testSeriesName = $testChampion['series_name'];
            $testYear = $testChampion['effective_year'];
            $testEndDate = $testChampion['end_date'];
            $testStatus = $testChampion['status'];

            echo '<div class="alert alert-info mb-md">';
            echo "<strong>Debug:</strong> Detaljerad test för {$testRiderName} (ID: {$testRider})<br>";
            echo "<strong>Serie:</strong> {$testSeriesName} (ID: {$testSeriesId}), Klass: {$testClassId}<br>";
            echo "<strong>Poäng enligt diagnos:</strong> {$testPoints}<br>";
            echo "<strong>Serie status:</strong> {$testStatus}, end_date: " . ($testEndDate ?: 'NULL') . ", year: {$testYear}<br><br>";

            // Step 1: Test simple query for rider results
            $stmt = $pdo->prepare("
                SELECT s.id as series_id, r.class_id, SUM(r.points) as total_points
                FROM results r
                JOIN events e ON r.event_id = e.id
                JOIN series_events se ON se.event_id = e.id
                JOIN series s ON se.series_id = s.id
                WHERE r.cyclist_id = ? AND r.status = 'finished'
                GROUP BY s.id, r.class_id
            ");
            $stmt->execute([$testRider]);
            $riderResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo "<strong>Steg 1:</strong> Hittade " . count($riderResults) . " serie/klass-kombinationer för åkare<br>";

            // Find the specific series/class combo
            $foundCombo = null;
            foreach ($riderResults as $rr) {
                if ((int)$rr['series_id'] == $testSeriesId && (int)$rr['class_id'] == $testClassId) {
                    $foundCombo = $rr;
                    break;
                }
            }
            if ($foundCombo) {
                echo "- Hittade serie {$testSeriesId}/klass {$testClassId} med " . $foundCombo['total_points'] . " poäng<br>";
            } else {
                echo "<span class='text-error'>- HITTADE INTE serie {$testSeriesId}/klass {$testClassId} i riderResults!</span><br>";
            }

            // Step 2: Check series info
            $seriesInfo = $pdo->query("SELECT id, name, year, status, end_date FROM series WHERE id = {$testSeriesId}")->fetch(PDO::FETCH_ASSOC);
            echo "<br><strong>Steg 2:</strong> Serie-info från DB:<br>";
            echo "- name: " . ($seriesInfo['name'] ?? 'NULL') . "<br>";
            echo "- year: " . ($seriesInfo['year'] ?? 'NULL') . "<br>";
            echo "- status: " . ($seriesInfo['status'] ?? 'NULL') . "<br>";
            echo "- end_date: " . ($seriesInfo['end_date'] ?? 'NULL') . "<br>";

            // Step 3: Check qualifying criteria (completed OR past year - NOT end_date anymore)
            $currentYear = (int)date('Y');
            $effectiveYear = (int)($seriesInfo['year'] ?? $currentYear);
            $isCompleted = ($seriesInfo['status'] ?? '') === 'completed';
            $isPastYear = $effectiveYear < $currentYear;
            $qualifies = $isCompleted || $isPastYear;

            echo "<br><strong>Steg 3:</strong> Kvalifikationskontroll:<br>";
            echo "- isCompleted: " . ($isCompleted ? 'JA' : 'NEJ') . "<br>";
            echo "- isPastYear: " . ($isPastYear ? 'JA (' . $effectiveYear . ' < ' . $currentYear . ')' : 'NEJ') . "<br>";
            echo "- <strong>KVALIFICERAR:</strong> " . ($qualifies ? '<span class="text-success">JA</span>' : '<span class="text-error">NEJ</span>') . "<br>";
            echo "<small class='text-secondary'>(end_date används inte längre automatiskt)</small><br>";

            // Step 4: Check max points for this series/class
            $stmt = $pdo->prepare("
                SELECT MAX(total) as max_points FROM (
                    SELECT SUM(r.points) as total
                    FROM results r
                    JOIN events e ON r.event_id = e.id
                    JOIN series_events se ON se.event_id = e.id
                    WHERE se.series_id = ? AND r.class_id = ? AND r.status = 'finished'
                    GROUP BY r.cyclist_id
                ) as subq
            ");
            $stmt->execute([$testSeriesId, $testClassId]);
            $maxPoints = (int)$stmt->fetchColumn();

            $riderPts = $foundCombo ? (int)$foundCombo['total_points'] : 0;
            echo "<br><strong>Steg 4:</strong> Poängjämförelse:<br>";
            echo "- Max poäng i serie/klass: {$maxPoints}<br>";
            echo "- Åkarens poäng: {$riderPts}<br>";
            echo "- Är mästare: " . ($riderPts == $maxPoints && $maxPoints > 0 ? '<span class="text-success">JA</span>' : '<span class="text-error">NEJ</span>') . "<br>";

            // Step 5: Now call the actual function with DEBUG enabled
            echo "<br><strong>Steg 5:</strong> Anropar calculateSeriesChampionships() med debug=true...<br>";
            require_once __DIR__ . '/../includes/rebuild-rider-stats.php';
            try {
                $result = calculateSeriesChampionships($pdo, $testRider, true); // debug=true
                $championships = $result['championships'];
                $debugLog = $result['debug'];

                echo "<br><strong>Debug log från funktionen:</strong><br>";
                echo "<div style='background: #f5f5f5; padding: 10px; border-radius: 4px; font-family: monospace; font-size: 12px; max-height: 300px; overflow-y: auto;'>";
                foreach ($debugLog as $line) {
                    echo htmlspecialchars($line) . "<br>";
                }
                echo "</div><br>";

                echo "<strong>Slutresultat:</strong> " . count($championships) . " seriemästarskap<br>";
                if (!empty($championships)) {
                    foreach ($championships as $c) {
                        echo "- {$c['series_name']} (år {$c['year']}, serie_id: {$c['series_id']})<br>";
                    }
                } else {
                    echo "<span class='text-warning'>Inga mästarskap returnerades.</span><br>";
                }
            } catch (Exception $e) {
                echo "<strong class='text-error'>FEL:</strong> " . htmlspecialchars($e->getMessage()) . "<br>";
                echo "<pre style='font-size: 10px;'>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
            }
            echo '</div>';
        }

        // Debug: Check if champions have club_id
        if (!empty($existingChampions)) {
            echo '<h4 class="mt-md mb-sm">Klubb-koppling för mästare:</h4>';
            $champWithClub = $db->getAll("
                SELECT ra.rider_id, CONCAT(r.first_name, ' ', r.last_name) as name,
                       ra.achievement_value as series_name, ra.season_year,
                       r.club_id, c.name as club_name
                FROM rider_achievements ra
                JOIN riders r ON ra.rider_id = r.id
                LEFT JOIN clubs c ON r.club_id = c.id
                WHERE ra.achievement_type = 'series_champion'
                ORDER BY ra.season_year DESC
                LIMIT 10
            ");
            echo '<table class="table table--striped" style="font-size: 0.85em;">';
            echo '<thead><tr><th>Åkare</th><th>Serie</th><th>År</th><th>Klubb</th></tr></thead><tbody>';
            foreach ($champWithClub as $ch) {
                $clubCell = $ch['club_id'] ? htmlspecialchars($ch['club_name']) : '<span class="text-error">Ingen klubb!</span>';
                echo "<tr><td>{$ch['name']}</td><td>{$ch['series_name']}</td><td>{$ch['season_year']}</td><td>{$clubCell}</td></tr>";
            }
            echo '</tbody></table>';
        }

        if (empty($existingChampions)):
        ?>
            <div class="alert alert-warning">
                <strong>Inga seriemästare registrerade!</strong><br>
                Serier måste markeras som <code>completed</code> för att mästare ska räknas.
            </div>

            <h4 class="mt-lg mb-md">Åtgärd:</h4>
            <ol>
                <li>Gå till <a href="/admin/series">/admin/series</a></li>
                <li>Klicka på en avslutad serie för att redigera</li>
                <li>Markera serien som <strong>Avslutad (completed)</strong></li>
                <li>Bekräfta att du vill räkna seriemästare</li>
            </ol>
            <p class="text-secondary mt-md">
                <strong>OBS:</strong> Markera bara en serie som avslutad när alla resultat är importerade!
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
        <?php
        // Check if rebuild was requested
        if (isset($_POST['rebuild_completed_series']) && isset($_POST['csrf_token'])) {
            if (verify_csrf_token($_POST['csrf_token'])) {
                require_once __DIR__ . '/../includes/rebuild-rider-stats.php';
                $pdo = $db->getPdo();

                // Get all qualifying series: completed OR from previous years
                $qualifyingSeries = $db->getAll("
                    SELECT id, name, year, status
                    FROM series
                    WHERE status = 'completed' OR year < " . $currentYear . "
                ");

                if (empty($qualifyingSeries)) {
                    echo '<div class="alert alert-warning mb-md">Inga kvalificerande serier hittades (varken completed eller från tidigare år).</div>';
                } else {
                    $totalRiders = 0;
                    $totalChampions = 0;
                    $processedRiders = []; // Avoid processing same rider twice

                    foreach ($qualifyingSeries as $qs) {
                        // Get all riders in this series
                        $ridersStmt = $pdo->prepare("
                            SELECT DISTINCT r.cyclist_id
                            FROM results r
                            JOIN events e ON r.event_id = e.id
                            JOIN series_events se ON se.event_id = e.id
                            WHERE se.series_id = ? AND r.cyclist_id IS NOT NULL
                        ");
                        $ridersStmt->execute([$qs['id']]);
                        $riderIds = $ridersStmt->fetchAll(PDO::FETCH_COLUMN);

                        foreach ($riderIds as $riderId) {
                            // Only process each rider once
                            if (!isset($processedRiders[$riderId])) {
                                rebuildRiderStats($pdo, $riderId);
                                $processedRiders[$riderId] = true;
                                $totalRiders++;
                            }
                        }

                        // Count champions for this series
                        $champCount = $pdo->prepare("
                            SELECT COUNT(*) FROM rider_achievements
                            WHERE achievement_type = 'series_champion' AND series_id = ?
                        ");
                        $champCount->execute([$qs['id']]);
                        $totalChampions += (int)$champCount->fetchColumn();
                    }

                    $completedCount = count(array_filter($qualifyingSeries, fn($s) => $s['status'] === 'completed'));
                    $pastYearCount = count($qualifyingSeries) - $completedCount;

                    echo '<div class="alert alert-success mb-md">';
                    echo "<strong>Klar!</strong> Bearbetade {$totalRiders} unika åkare i " . count($qualifyingSeries) . " serier.<br>";
                    echo "<small>({$completedCount} completed + {$pastYearCount} från tidigare år)</small><br>";
                    echo "Totalt {$totalChampions} seriemästare registrerade.<br>";
                    echo '<a href="/admin/diagnose-series.php" class="btn btn--secondary mt-sm" style="display:inline-block;">Ladda om sidan</a>';
                    echo '</div>';
                }
            }
        }
        ?>

        <form method="POST" style="display: inline;">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
            <button type="submit" name="rebuild_completed_series" class="btn btn--primary"
                    onclick="return confirm('Detta kommer beräkna seriemästare för alla kvalificerande serier:\n- Serier markerade som completed\n- Serier från tidigare år (<?= $currentYear - 1 ?> och tidigare)\n\nFortsätt?');">
                🏆 Beräkna Seriemästare Nu
            </button>
        </form>

        <div class="flex gap-md mt-md" style="flex-wrap: wrap;">
            <a href="/admin/series" class="btn btn--secondary">
                → Hantera Serier
            </a>
            <a href="/admin/rebuild-stats" class="btn btn--secondary">
                → Rebuild Stats (alla)
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

<?php
/**
 * Debug Series Points - Diagnose calculation issues
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/series-points.php';
require_admin();

$db = getDB();
$seriesId = isset($_GET['series_id']) ? intval($_GET['series_id']) : 5;

// Handle recalculate action with detailed logging
$recalcLog = [];
if (isset($_GET['recalculate']) && $_GET['recalculate'] == '1') {
    // Custom recalculation with detailed logging
    $seriesEvents = $db->getAll("SELECT event_id, template_id FROM series_events WHERE series_id = ?", [$seriesId]);

    foreach ($seriesEvents as $se) {
        $eventId = $se['event_id'];
        $templateId = $se['template_id'];

        $eventInfo = $db->getRow("SELECT name FROM events WHERE id = ?", [$eventId]);
        $eventLog = [
            'event_id' => $eventId,
            'event_name' => $eventInfo['name'] ?? 'Unknown',
            'template_id' => $templateId,
            'results_found' => 0,
            'points_calculated' => []
        ];

        // Get results for this event
        $results = $db->getAll("
            SELECT r.id, r.cyclist_id, r.class_id, r.position, r.status,
                   ri.firstname, ri.lastname,
                   cl.name as class_name, cl.awards_points, cl.series_eligible
            FROM results r
            LEFT JOIN riders ri ON r.cyclist_id = ri.id
            LEFT JOIN classes cl ON r.class_id = cl.id
            WHERE r.event_id = ?
            ORDER BY r.position
            LIMIT 20
        ", [$eventId]);

        $eventLog['results_found'] = count($results);

        foreach ($results as $r) {
            // Calculate what points would be
            $points = 0;
            if ($templateId) {
                $pointValue = $db->getRow(
                    "SELECT points FROM point_scale_values WHERE scale_id = ? AND position = ?",
                    [$templateId, $r['position']]
                );
                $points = $pointValue ? (int)$pointValue['points'] : 0;
            }

            $eventLog['points_calculated'][] = [
                'rider' => $r['firstname'] . ' ' . $r['lastname'],
                'position' => $r['position'],
                'class' => $r['class_name'],
                'awards_points' => $r['awards_points'],
                'series_eligible' => $r['series_eligible'],
                'calculated_points' => $points,
                'status' => $r['status']
            ];
        }

        $recalcLog[] = $eventLog;
    }
}

echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Debug Series Points</title>";
echo "<style>
body { font-family: monospace; padding: 20px; background: #1a1a1a; color: #e0e0e0; max-width: 1400px; margin: 0 auto; }
.success { color: #4caf50; }
.error { color: #f44336; }
.warning { color: #ff9800; }
.info { color: #2196f3; }
h1, h2, h3 { color: #fff; }
table { border-collapse: collapse; width: 100%; margin: 10px 0; background: #2a2a2a; }
th, td { border: 1px solid #444; padding: 8px; text-align: left; }
th { background: #333; }
tr:hover { background: #363636; }
.card { background: #2a2a2a; padding: 15px; margin: 15px 0; border-radius: 8px; border-left: 4px solid #2196f3; }
.btn { display: inline-block; padding: 10px 20px; background: #4caf50; color: white; text-decoration: none; border-radius: 4px; margin: 5px; }
.btn:hover { background: #45a049; }
.btn-warning { background: #ff9800; }
</style></head><body>";

echo "<h1>Debug Series Points - Serie #{$seriesId}</h1>";

// Navigation
echo "<div style='margin-bottom: 20px;'>";
echo "<a href='?series_id={$seriesId}' class='btn'>Uppdatera</a>";
echo "<a href='?series_id={$seriesId}&recalculate=1' class='btn btn-warning'>Test Beräkning (visa detaljer)</a>";
echo "<a href='/admin/series-events.php?series_id={$seriesId}' class='btn'>Hantera Events</a>";
echo "<a href='/series/{$seriesId}' class='btn'>Visa Serie</a>";
echo "</div>";

// 1. Check series exists
$series = $db->getRow("SELECT * FROM series WHERE id = ?", [$seriesId]);
if (!$series) {
    echo "<p class='error'>Serie {$seriesId} finns inte!</p></body></html>";
    exit;
}
echo "<p class='success'>Serie: <strong>{$series['name']}</strong> ({$series['year']})</p>";

// Show recalculation log if available
if (!empty($recalcLog)) {
    echo "<div class='card' style='border-color: #ff9800;'><h2>Testberäkning Resultat</h2>";
    foreach ($recalcLog as $eventLog) {
        echo "<h3>{$eventLog['event_name']} (Event #{$eventLog['event_id']})</h3>";
        echo "<p>Template ID: <strong>{$eventLog['template_id']}</strong> | Resultat hittade: <strong>{$eventLog['results_found']}</strong></p>";

        if (empty($eventLog['points_calculated'])) {
            echo "<p class='warning'>Inga resultat att beräkna!</p>";
        } else {
            echo "<table><tr><th>Åkare</th><th>Pos</th><th>Klass</th><th>awards_points</th><th>series_eligible</th><th>Beräknad poäng</th></tr>";
            foreach ($eventLog['points_calculated'] as $pc) {
                $pointsClass = $pc['calculated_points'] > 0 ? 'success' : 'error';
                $apClass = $pc['awards_points'] == 1 ? 'success' : 'error';
                $seClass = $pc['series_eligible'] == 1 ? 'success' : 'error';
                echo "<tr>
                    <td>{$pc['rider']}</td>
                    <td>{$pc['position']}</td>
                    <td>{$pc['class']}</td>
                    <td class='{$apClass}'>{$pc['awards_points']}</td>
                    <td class='{$seClass}'>{$pc['series_eligible']}</td>
                    <td class='{$pointsClass}'>{$pc['calculated_points']}</td>
                </tr>";
            }
            echo "</table>";
        }
    }
    echo "</div>";
}

// 2. Check series_events
echo "<div class='card'><h2>1. Series Events</h2>";
$seriesEvents = $db->getAll("
    SELECT se.*, e.name as event_name, e.date as event_date, ps.name as scale_name
    FROM series_events se
    JOIN events e ON se.event_id = e.id
    LEFT JOIN point_scales ps ON se.template_id = ps.id
    WHERE se.series_id = ?
    ORDER BY se.sort_order
", [$seriesId]);

echo "<table><tr><th>Event</th><th>Datum</th><th>template_id</th><th>Point Scale Name</th><th>Har values?</th></tr>";
foreach ($seriesEvents as $se) {
    $scaleStatus = $se['scale_name'] ? "<span class='success'>{$se['scale_name']}</span>" : "<span class='error'>EJ HITTAT!</span>";

    // Check if scale has values
    $valueCount = 0;
    if ($se['template_id']) {
        $vc = $db->getRow("SELECT COUNT(*) as cnt FROM point_scale_values WHERE scale_id = ?", [$se['template_id']]);
        $valueCount = $vc['cnt'] ?? 0;
    }
    $vcClass = $valueCount > 0 ? 'success' : 'error';

    echo "<tr><td>{$se['event_name']}</td><td>{$se['event_date']}</td><td>{$se['template_id']}</td><td>{$scaleStatus}</td><td class='{$vcClass}'>{$valueCount} värden</td></tr>";
}
echo "</table></div>";

// 3. Check point_scales and their values
echo "<div class='card'><h2>2. Tillgängliga Point Scales</h2>";
$scales = $db->getAll("SELECT * FROM point_scales ORDER BY name");
echo "<table><tr><th>ID</th><th>Namn</th><th>Active</th><th>Antal positioner</th><th>Topp 5 poäng</th></tr>";
foreach ($scales as $scale) {
    $values = $db->getAll("SELECT position, points FROM point_scale_values WHERE scale_id = ? ORDER BY position LIMIT 5", [$scale['id']]);
    $valueCount = $db->getRow("SELECT COUNT(*) as cnt FROM point_scale_values WHERE scale_id = ?", [$scale['id']]);
    $countClass = $valueCount['cnt'] > 0 ? 'success' : 'error';
    $activeClass = $scale['active'] ? 'success' : 'warning';

    $top5 = [];
    foreach ($values as $v) {
        $top5[] = "#{$v['position']}={$v['points']}";
    }

    echo "<tr>
        <td>{$scale['id']}</td>
        <td>{$scale['name']}</td>
        <td class='{$activeClass}'>" . ($scale['active'] ? 'Ja' : 'Nej') . "</td>
        <td class='{$countClass}'>{$valueCount['cnt']}</td>
        <td>" . (empty($top5) ? "<span class='error'>TOM!</span>" : implode(', ', $top5)) . "</td>
    </tr>";
}
echo "</table></div>";

// 4. Check series_results
echo "<div class='card'><h2>3. Series Results</h2>";
$resultsCount = $db->getRow("SELECT COUNT(*) as cnt FROM series_results WHERE series_id = ?", [$seriesId]);
echo "<p>Antal poster i series_results: <strong>{$resultsCount['cnt']}</strong></p>";

if ($resultsCount['cnt'] > 0) {
    $sampleResults = $db->getAll("
        SELECT sr.*, r.firstname, r.lastname, e.name as event_name
        FROM series_results sr
        JOIN riders r ON sr.cyclist_id = r.id
        JOIN events e ON sr.event_id = e.id
        WHERE sr.series_id = ?
        ORDER BY sr.points DESC
        LIMIT 15
    ", [$seriesId]);
    echo "<table><tr><th>Åkare</th><th>Event</th><th>Position</th><th>Poäng</th><th>template_id</th></tr>";
    foreach ($sampleResults as $sr) {
        $pointsClass = $sr['points'] > 0 ? 'success' : 'warning';
        echo "<tr><td>{$sr['firstname']} {$sr['lastname']}</td><td>{$sr['event_name']}</td><td>{$sr['position']}</td><td class='{$pointsClass}'>{$sr['points']}</td><td>{$sr['template_id']}</td></tr>";
    }
    echo "</table>";
} else {
    echo "<p class='warning'>Inga series_results finns! Systemet faller tillbaka på results.points</p>";
}
echo "</div>";

// 5. Check results table for events
echo "<div class='card'><h2>4. Results per Event</h2>";
foreach ($seriesEvents as $se) {
    $resultCount = $db->getRow("SELECT COUNT(*) as cnt FROM results WHERE event_id = ?", [$se['event_id']]);
    $countClass = $resultCount['cnt'] > 0 ? 'success' : 'error';
    echo "<p>{$se['event_name']}: <span class='{$countClass}'>{$resultCount['cnt']} resultat</span></p>";
}
echo "</div>";

// Recommendation
echo "<div class='card' style='border-color: #ff9800;'><h2>Diagnostik</h2>";
echo "<p>Om poängen inte beräknas korrekt:</p>";
echo "<ol>";
echo "<li><strong>Kolla att mallarna har värden</strong> - 'Antal positioner' ska vara > 0 och 'Topp 5 poäng' ska visa värden</li>";
echo "<li><strong>Kolla template_id</strong> - Varje event måste ha en template_id som finns i point_scales</li>";
echo "<li><strong>Kolla klasser</strong> - awards_points och series_eligible måste vara 1 för att ge poäng</li>";
echo "<li><strong>Kolla resultat</strong> - Events måste ha resultat i results-tabellen</li>";
echo "</ol>";
echo "<p><a href='/admin/point-scales.php' class='btn'>Hantera Poängmallar</a></p>";
echo "</div>";

echo "</body></html>";


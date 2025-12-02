<?php
/**
 * Debug Series Points - Diagnose calculation issues
 */
require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();
$seriesId = isset($_GET['series_id']) ? intval($_GET['series_id']) : 5;

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
</style></head><body>";

echo "<h1>Debug Series Points - Serie #{$seriesId}</h1>";

// 1. Check series exists
$series = $db->getRow("SELECT * FROM series WHERE id = ?", [$seriesId]);
if (!$series) {
    echo "<p class='error'>Serie {$seriesId} finns inte!</p></body></html>";
    exit;
}
echo "<p class='success'>Serie: <strong>{$series['name']}</strong> ({$series['year']})</p>";

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

echo "<table><tr><th>Event</th><th>Datum</th><th>template_id</th><th>Point Scale Name</th></tr>";
foreach ($seriesEvents as $se) {
    $scaleStatus = $se['scale_name'] ? "<span class='success'>{$se['scale_name']}</span>" : "<span class='error'>EJ HITTAT!</span>";
    echo "<tr><td>{$se['event_name']}</td><td>{$se['event_date']}</td><td>{$se['template_id']}</td><td>{$scaleStatus}</td></tr>";
}
echo "</table></div>";

// 3. Check point_scales
echo "<div class='card'><h2>2. Tillgängliga Point Scales</h2>";
$scales = $db->getAll("SELECT * FROM point_scales WHERE active = 1 ORDER BY name");
echo "<table><tr><th>ID</th><th>Namn</th><th>Discipline</th><th>Antal positioner</th></tr>";
foreach ($scales as $scale) {
    $valueCount = $db->getRow("SELECT COUNT(*) as cnt FROM point_scale_values WHERE scale_id = ?", [$scale['id']]);
    $countClass = $valueCount['cnt'] > 0 ? 'success' : 'error';
    echo "<tr><td>{$scale['id']}</td><td>{$scale['name']}</td><td>{$scale['discipline']}</td><td class='{$countClass}'>{$valueCount['cnt']}</td></tr>";
}
echo "</table></div>";

// 4. Check point_scale_values for used templates
echo "<div class='card'><h2>3. Point Scale Values (för använda mallar)</h2>";
$usedTemplateIds = array_filter(array_unique(array_column($seriesEvents, 'template_id')));
if (empty($usedTemplateIds)) {
    echo "<p class='warning'>Inga mallar valda för denna serie!</p>";
} else {
    foreach ($usedTemplateIds as $templateId) {
        $scale = $db->getRow("SELECT * FROM point_scales WHERE id = ?", [$templateId]);
        echo "<h3>Mall: " . ($scale ? $scale['name'] : "ID {$templateId}") . "</h3>";

        $values = $db->getAll("SELECT * FROM point_scale_values WHERE scale_id = ? ORDER BY position LIMIT 20", [$templateId]);
        if (empty($values)) {
            echo "<p class='error'>INGA POÄNGVÄRDEN FINNS FÖR DENNA MALL! Detta är problemet.</p>";
        } else {
            echo "<table><tr><th>Position</th><th>Poäng</th></tr>";
            foreach ($values as $v) {
                echo "<tr><td>{$v['position']}</td><td>{$v['points']}</td></tr>";
            }
            echo "</table>";
        }
    }
}
echo "</div>";

// 5. Check series_results
echo "<div class='card'><h2>4. Series Results</h2>";
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

// 6. Check qualification_point_templates (old system)
echo "<div class='card'><h2>5. Gamla systemet: qualification_point_templates</h2>";
try {
    $oldTemplates = $db->getAll("SELECT * FROM qualification_point_templates WHERE active = 1");
    if (empty($oldTemplates)) {
        echo "<p class='info'>Inga aktiva mallar i gamla systemet.</p>";
    } else {
        echo "<table><tr><th>ID</th><th>Namn</th><th>Poäng (JSON)</th></tr>";
        foreach ($oldTemplates as $ot) {
            $pointsPreview = strlen($ot['points']) > 50 ? substr($ot['points'], 0, 50) . '...' : $ot['points'];
            echo "<tr><td>{$ot['id']}</td><td>{$ot['name']}</td><td><code>{$pointsPreview}</code></td></tr>";
        }
        echo "</table>";
    }
} catch (Exception $e) {
    echo "<p class='info'>Tabellen qualification_point_templates finns inte (OK).</p>";
}
echo "</div>";

// 7. Test calculate function
echo "<div class='card'><h2>6. Test poängberäkning</h2>";
if (!empty($usedTemplateIds)) {
    $testTemplateId = reset($usedTemplateIds);
    echo "<p>Testar mall ID: {$testTemplateId}</p>";

    require_once __DIR__ . '/../includes/series-points.php';

    for ($pos = 1; $pos <= 5; $pos++) {
        $points = calculateSeriesPointsForPosition($db, $testTemplateId, $pos, 'finished', 'national');
        $statusClass = $points > 0 ? 'success' : 'error';
        echo "<p>Position {$pos}: <span class='{$statusClass}'>{$points} poäng</span></p>";
    }
}
echo "</div>";

// Recommendation
echo "<div class='card' style='border-color: #ff9800;'><h2>Rekommendation</h2>";
echo "<p>Om poängen är 0 ovan, kontrollera att:</p>";
echo "<ol>";
echo "<li>point_scales har rätt mallar (Sektion 2)</li>";
echo "<li>point_scale_values har poäng för varje position (Sektion 3)</li>";
echo "<li>series_events.template_id pekar på rätt point_scales.id</li>";
echo "</ol>";
echo "<p><a href='/admin/point-scales.php' style='color: #2196f3;'>Gå till Poängmallar</a> | ";
echo "<a href='/admin/series-events.php?series_id={$seriesId}' style='color: #2196f3;'>Hantera Series Events</a></p>";
echo "</div>";

echo "</body></html>";

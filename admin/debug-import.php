<?php
require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();

echo "<h1>Debug Import Data</h1>";
echo "<style>body{font-family:monospace;padding:20px;} table{border-collapse:collapse;margin:20px 0;} td,th{border:1px solid #ccc;padding:8px;text-align:left;} th{background:#f5f5f5;} .section{margin:30px 0;padding:20px;background:#f9f9f9;border-radius:8px;}</style>";

// 1. Check recent import history
echo "<div class='section'>";
echo "<h2>1. Senaste import-historik</h2>";
$imports = $db->getAll("
    SELECT id, import_type, filename, status, total_records, success_count,
           updated_count, failed_count, skipped_count, error_summary, imported_at
    FROM import_history
    ORDER BY imported_at DESC
    LIMIT 5
");

if ($imports) {
    echo "<table><tr><th>ID</th><th>Typ</th><th>Fil</th><th>Status</th><th>Total</th><th>OK</th><th>Uppdaterade</th><th>Fel</th><th>Skipped</th><th>Datum</th></tr>";
    foreach ($imports as $imp) {
        echo "<tr>";
        echo "<td>{$imp['id']}</td>";
        echo "<td>{$imp['import_type']}</td>";
        echo "<td>{$imp['filename']}</td>";
        echo "<td>{$imp['status']}</td>";
        echo "<td>{$imp['total_records']}</td>";
        echo "<td>{$imp['success_count']}</td>";
        echo "<td>{$imp['updated_count']}</td>";
        echo "<td>{$imp['failed_count']}</td>";
        echo "<td>{$imp['skipped_count']}</td>";
        echo "<td>{$imp['imported_at']}</td>";
        echo "</tr>";
        if (!empty($imp['error_summary'])) {
            echo "<tr><td colspan='10'><strong>Fel:</strong><pre>" . htmlspecialchars($imp['error_summary']) . "</pre></td></tr>";
        }
    }
    echo "</table>";
} else {
    echo "<p>Ingen import-historik hittades.</p>";
}
echo "</div>";

// 2. Count results in database
echo "<div class='section'>";
echo "<h2>2. Resultat i databasen</h2>";
$resultCount = $db->getRow("SELECT COUNT(*) as count FROM results");
echo "<p><strong>Totalt antal resultat:</strong> " . ($resultCount['count'] ?? 0) . "</p>";

// Recent results
$recentResults = $db->getAll("
    SELECT r.id, r.event_id, r.rider_id, r.position, r.finish_time, r.status,
           ri.firstname, ri.lastname, e.name as event_name
    FROM results r
    LEFT JOIN riders ri ON r.rider_id = ri.id
    LEFT JOIN events e ON r.event_id = e.id
    ORDER BY r.id DESC
    LIMIT 10
");

if ($recentResults) {
    echo "<h3>Senaste 10 resultat:</h3>";
    echo "<table><tr><th>ID</th><th>Event</th><th>Deltagare</th><th>Plac</th><th>Tid</th><th>Status</th></tr>";
    foreach ($recentResults as $res) {
        echo "<tr>";
        echo "<td>{$res['id']}</td>";
        echo "<td>{$res['event_name']} (ID: {$res['event_id']})</td>";
        echo "<td>{$res['firstname']} {$res['lastname']} (ID: {$res['rider_id']})</td>";
        echo "<td>{$res['position']}</td>";
        echo "<td>{$res['finish_time']}</td>";
        echo "<td>{$res['status']}</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p>Inga resultat i databasen.</p>";
}
echo "</div>";

// 3. Riders without UCI ID
echo "<div class='section'>";
echo "<h2>3. Deltagare utan UCI-ID (license_number)</h2>";
$ridersNoUCI = $db->getAll("
    SELECT id, firstname, lastname, license_number, gender, created_at
    FROM riders
    WHERE license_number IS NULL OR license_number = ''
    ORDER BY created_at DESC
    LIMIT 20
");

$countNoUCI = $db->getRow("SELECT COUNT(*) as count FROM riders WHERE license_number IS NULL OR license_number = ''");
echo "<p><strong>Antal utan UCI-ID:</strong> " . ($countNoUCI['count'] ?? 0) . "</p>";

if ($ridersNoUCI) {
    echo "<table><tr><th>ID</th><th>Namn</th><th>License</th><th>Kön</th><th>Skapad</th></tr>";
    foreach ($ridersNoUCI as $rider) {
        echo "<tr>";
        echo "<td>{$rider['id']}</td>";
        echo "<td>{$rider['firstname']} {$rider['lastname']}</td>";
        echo "<td>" . ($rider['license_number'] ?: '<em>tom</em>') . "</td>";
        echo "<td>{$rider['gender']}</td>";
        echo "<td>{$rider['created_at']}</td>";
        echo "</tr>";
    }
    echo "</table>";
}
echo "</div>";

// 4. Riders with SWE25 numbers
echo "<div class='section'>";
echo "<h2>4. Deltagare med SWE25-nummer</h2>";
$ridersSWE = $db->getAll("
    SELECT id, firstname, lastname, license_number, created_at
    FROM riders
    WHERE license_number LIKE 'SWE25%'
    ORDER BY created_at DESC
    LIMIT 20
");

$countSWE = $db->getRow("SELECT COUNT(*) as count FROM riders WHERE license_number LIKE 'SWE25%'");
echo "<p><strong>Antal med SWE25-nummer:</strong> " . ($countSWE['count'] ?? 0) . "</p>";

if ($ridersSWE) {
    echo "<table><tr><th>ID</th><th>Namn</th><th>License</th><th>Skapad</th></tr>";
    foreach ($ridersSWE as $rider) {
        echo "<tr>";
        echo "<td>{$rider['id']}</td>";
        echo "<td>{$rider['firstname']} {$rider['lastname']}</td>";
        echo "<td>{$rider['license_number']}</td>";
        echo "<td>{$rider['created_at']}</td>";
        echo "</tr>";
    }
    echo "</table>";
}
echo "</div>";

// 5. Total rider count
echo "<div class='section'>";
echo "<h2>5. Statistik</h2>";
$totalRiders = $db->getRow("SELECT COUNT(*) as count FROM riders");
$totalEvents = $db->getRow("SELECT COUNT(*) as count FROM events");
$totalClubs = $db->getRow("SELECT COUNT(*) as count FROM clubs");

echo "<ul>";
echo "<li><strong>Totalt antal deltagare:</strong> " . ($totalRiders['count'] ?? 0) . "</li>";
echo "<li><strong>Totalt antal events:</strong> " . ($totalEvents['count'] ?? 0) . "</li>";
echo "<li><strong>Totalt antal klubbar:</strong> " . ($totalClubs['count'] ?? 0) . "</li>";
echo "</ul>";
echo "</div>";

// 6. Check for the specific event
echo "<div class='section'>";
echo "<h2>6. Swecup Falun event</h2>";
$falunEvent = $db->getRow("SELECT * FROM events WHERE name LIKE '%Falun%' OR name LIKE '%Swecup%' ORDER BY date DESC LIMIT 1");
if ($falunEvent) {
    echo "<p><strong>Event:</strong> {$falunEvent['name']} (ID: {$falunEvent['id']})</p>";
    echo "<p><strong>Datum:</strong> {$falunEvent['date']}</p>";

    $resultsForEvent = $db->getRow("SELECT COUNT(*) as count FROM results WHERE event_id = ?", [$falunEvent['id']]);
    echo "<p><strong>Antal resultat för detta event:</strong> " . ($resultsForEvent['count'] ?? 0) . "</p>";
} else {
    echo "<p>Inget Falun/Swecup event hittat.</p>";
}
echo "</div>";

echo "<p><a href='/admin/import-results.php'>Tillbaka till import</a></p>";
?>

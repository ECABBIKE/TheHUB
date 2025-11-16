<?php
/**
 * Debug script to check what's causing white screen on series.php
 */

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Debug Information</h1>";

try {
    echo "<h2>1. Loading config...</h2>";
    require_once __DIR__ . '/../config.php';
    echo "✓ Config loaded<br>";

    echo "<h2>2. Checking admin authentication...</h2>";
    require_admin();
    echo "✓ Admin authenticated<br>";

    echo "<h2>3. Getting database connection...</h2>";
    $db = getDB();
    echo "✓ Database connected<br>";

    echo "<h2>4. Checking if 'series' table exists...</h2>";
    $tables = $db->getAll("SHOW TABLES LIKE 'series'");
    if (!empty($tables)) {
        echo "✓ 'series' table exists<br>";
    } else {
        echo "✗ 'series' table does NOT exist<br>";
        die();
    }

    echo "<h2>5. Checking 'series' table columns...</h2>";
    $columns = $db->getAll("SHOW COLUMNS FROM series");
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Default</th></tr>";
    foreach ($columns as $col) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($col['Field']) . "</td>";
        echo "<td>" . htmlspecialchars($col['Type']) . "</td>";
        echo "<td>" . htmlspecialchars($col['Null']) . "</td>";
        echo "<td>" . htmlspecialchars($col['Default'] ?? '') . "</td>";
        echo "</tr>";
    }
    echo "</table><br>";

    echo "<h2>6. Checking if 'format' column exists...</h2>";
    $formatExists = false;
    foreach ($columns as $col) {
        if ($col['Field'] === 'format') {
            $formatExists = true;
            break;
        }
    }
    echo $formatExists ? "✓ 'format' column exists<br>" : "✗ 'format' column does NOT exist<br>";

    echo "<h2>7. Checking if 'series_events' table exists...</h2>";
    $tables = $db->getAll("SHOW TABLES LIKE 'series_events'");
    if (!empty($tables)) {
        echo "✓ 'series_events' table exists<br>";
        $columns = $db->getAll("SHOW COLUMNS FROM series_events");
        echo "<table border='1' cellpadding='5'>";
        echo "<tr><th>Field</th><th>Type</th></tr>";
        foreach ($columns as $col) {
            echo "<tr><td>" . htmlspecialchars($col['Field']) . "</td><td>" . htmlspecialchars($col['Type']) . "</td></tr>";
        }
        echo "</table><br>";
    } else {
        echo "✗ 'series_events' table does NOT exist<br>";
    }

    echo "<h2>8. Checking if 'qualification_point_templates' table exists...</h2>";
    $tables = $db->getAll("SHOW TABLES LIKE 'qualification_point_templates'");
    if (!empty($tables)) {
        echo "✓ 'qualification_point_templates' table exists<br>";
    } else {
        echo "✗ 'qualification_point_templates' table does NOT exist<br>";
    }

    echo "<h2>9. Trying to run series query...</h2>";
    $formatSelect = $formatExists ? ', format' : ', "Championship" as format';
    $sql = "SELECT id, name, type{$formatSelect}, status, start_date, end_date, logo, organizer,
            (SELECT COUNT(*) FROM series_events WHERE series_id = series.id) as events_count
            FROM series
            ORDER BY start_date DESC LIMIT 5";

    echo "SQL: <pre>" . htmlspecialchars($sql) . "</pre>";

    $series = $db->getAll($sql);
    echo "✓ Query executed successfully<br>";
    echo "Found " . count($series) . " series<br>";

    if (!empty($series)) {
        echo "<table border='1' cellpadding='5'>";
        echo "<tr><th>ID</th><th>Name</th><th>Type</th><th>Format</th><th>Events Count</th></tr>";
        foreach ($series as $s) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($s['id']) . "</td>";
            echo "<td>" . htmlspecialchars($s['name']) . "</td>";
            echo "<td>" . htmlspecialchars($s['type'] ?? '') . "</td>";
            echo "<td>" . htmlspecialchars($s['format'] ?? 'N/A') . "</td>";
            echo "<td>" . htmlspecialchars($s['events_count']) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }

    echo "<h2>✅ All checks passed!</h2>";
    echo "<p>If you see this, the database structure is OK. The problem might be in the HTML/CSS rendering.</p>";
    echo "<p><a href='/admin/series.php'>Try series.php again</a></p>";

} catch (Exception $e) {
    echo "<h2 style='color: red;'>✗ Error occurred!</h2>";
    echo "<p><strong>Error:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p><strong>File:</strong> " . htmlspecialchars($e->getFile()) . "</p>";
    echo "<p><strong>Line:</strong> " . htmlspecialchars($e->getLine()) . "</p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}
?>

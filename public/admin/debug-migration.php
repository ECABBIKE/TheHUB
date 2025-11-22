<?php
require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();
$pdo = getPDO();

echo "<h1>Migration Debug</h1>";
echo "<pre class='gs-pre-gray'>";

// Check current events table structure
echo "=== CHECKING EVENTS TABLE ===\n";
try {
    $columns = $pdo->query("SHOW COLUMNS FROM events")->fetchAll(PDO::FETCH_ASSOC);
    echo "Current columns in 'events' table:\n";
    foreach ($columns as $col) {
        echo "  - {$col['Field']} ({$col['Type']})\n";
        if ($col['Field'] === 'event_format') {
            echo "    ✅ event_format EXISTS!\n";
        }
    }

    $hasEventFormat = false;
    foreach ($columns as $col) {
        if ($col['Field'] === 'event_format') {
            $hasEventFormat = true;
            break;
        }
    }

    if (!$hasEventFormat) {
        echo "\n❌ event_format column NOT FOUND\n";
        echo "\nAttempting to add event_format column...\n";

        try {
            $pdo->exec("ALTER TABLE events ADD COLUMN event_format VARCHAR(20) DEFAULT 'ENDURO' AFTER discipline");
            echo "✅ Successfully added event_format column!\n";
        } catch (Exception $e) {
            echo "❌ FAILED to add event_format: " . $e->getMessage() . "\n";
            echo "   Error Code: " . $e->getCode() . "\n";
        }
    } else {
        echo "\n✅ event_format column already exists\n";
    }

} catch (Exception $e) {
    echo "ERROR checking events table: " . $e->getMessage() . "\n";
}

echo "\n=== CHECKING RESULTS TABLE ===\n";
try {
    $columns = $pdo->query("SHOW COLUMNS FROM results")->fetchAll(PDO::FETCH_ASSOC);

    $dhColumns = ['run_1_time', 'run_2_time', 'run_1_points', 'run_2_points'];
    $foundColumns = [];

    foreach ($columns as $col) {
        if (in_array($col['Field'], $dhColumns)) {
            $foundColumns[] = $col['Field'];
        }
    }

    echo "DH columns status:\n";
    foreach ($dhColumns as $dhCol) {
        if (in_array($dhCol, $foundColumns)) {
            echo "  ✅ $dhCol EXISTS\n";
        } else {
            echo "  ❌ $dhCol MISSING\n";
        }
    }

} catch (Exception $e) {
    echo "ERROR checking results table: " . $e->getMessage() . "\n";
}

echo "\n=== DATABASE INFO ===\n";
echo "Database Name: " . $pdo->query("SELECT DATABASE()")->fetchColumn() . "\n";
echo "MySQL Version: " . $pdo->query("SELECT VERSION()")->fetchColumn() . "\n";

echo "</pre>";

echo "<p><a href='/admin/events.php'>Back to Events</a> | <a href='/admin/run-migrations.php'>Run Full Migration</a></p>";

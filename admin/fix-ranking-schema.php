<?php
/**
 * Quick Fix for Ranking Snapshots Schema
 * Checks and adds missing discipline column if needed
 */

require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();

echo "<!DOCTYPE html>";
echo "<html><head><title>Fix Ranking Schema</title>";
echo "<style>body { font-family: monospace; padding: 20px; } .success { color: green; } .error { color: red; } .info { color: blue; }</style>";
echo "</head><body>";
echo "<h1>Ranking Schema Fix</h1>";

// Check if discipline column exists
echo "<h2>Step 1: Checking ranking_snapshots structure</h2>";
try {
    $columns = $db->getAll("SHOW COLUMNS FROM ranking_snapshots");
    echo "<p class='info'>Current columns in ranking_snapshots:</p>";
    echo "<pre>";
    foreach ($columns as $col) {
        echo "- {$col['Field']} ({$col['Type']})\n";
    }
    echo "</pre>";

    $hasDiscipline = false;
    foreach ($columns as $col) {
        if ($col['Field'] === 'discipline') {
            $hasDiscipline = true;
            break;
        }
    }

    if ($hasDiscipline) {
        echo "<p class='success'>✅ Column 'discipline' exists!</p>";
    } else {
        echo "<p class='error'>❌ Column 'discipline' is missing!</p>";

        echo "<h2>Step 2: Adding discipline column</h2>";
        try {
            $db->query("ALTER TABLE ranking_snapshots ADD COLUMN discipline VARCHAR(50) NOT NULL DEFAULT 'GRAVITY' AFTER rider_id");
            echo "<p class='success'>✅ Added discipline column</p>";
        } catch (Exception $e) {
            echo "<p class='error'>❌ Failed to add column: " . $e->getMessage() . "</p>";
        }

        echo "<h2>Step 3: Updating unique key</h2>";
        try {
            // Drop old unique key if exists
            $indexes = $db->getAll("SHOW INDEX FROM ranking_snapshots WHERE Key_name = 'unique_rider_snapshot'");
            if (!empty($indexes)) {
                $db->query("ALTER TABLE ranking_snapshots DROP INDEX unique_rider_snapshot");
                echo "<p class='success'>✅ Dropped old unique_rider_snapshot index</p>";
            }
        } catch (Exception $e) {
            echo "<p class='info'>ℹ️ Old index not found or already removed</p>";
        }

        try {
            // Add new unique key
            $indexes = $db->getAll("SHOW INDEX FROM ranking_snapshots WHERE Key_name = 'unique_rider_discipline_snapshot'");
            if (empty($indexes)) {
                $db->query("ALTER TABLE ranking_snapshots ADD UNIQUE KEY unique_rider_discipline_snapshot (rider_id, discipline, snapshot_date)");
                echo "<p class='success'>✅ Added unique_rider_discipline_snapshot index</p>";
            } else {
                echo "<p class='info'>ℹ️ unique_rider_discipline_snapshot already exists</p>";
            }
        } catch (Exception $e) {
            echo "<p class='error'>❌ Failed to add unique key: " . $e->getMessage() . "</p>";
        }

        echo "<h2>Step 4: Adding discipline index</h2>";
        try {
            $indexes = $db->getAll("SHOW INDEX FROM ranking_snapshots WHERE Key_name = 'idx_discipline_ranking'");
            if (empty($indexes)) {
                $db->query("ALTER TABLE ranking_snapshots ADD INDEX idx_discipline_ranking (discipline, snapshot_date, ranking_position)");
                echo "<p class='success'>✅ Added idx_discipline_ranking index</p>";
            } else {
                echo "<p class='info'>ℹ️ idx_discipline_ranking already exists</p>";
            }
        } catch (Exception $e) {
            echo "<p class='error'>❌ Failed to add index: " . $e->getMessage() . "</p>";
        }
    }

    echo "<h2>Step 5: Checking club_ranking_snapshots structure</h2>";
    $clubColumns = $db->getAll("SHOW COLUMNS FROM club_ranking_snapshots");
    $hasClubDiscipline = false;
    foreach ($clubColumns as $col) {
        if ($col['Field'] === 'discipline') {
            $hasClubDiscipline = true;
            break;
        }
    }

    if ($hasClubDiscipline) {
        echo "<p class='success'>✅ club_ranking_snapshots has discipline column</p>";
    } else {
        echo "<p class='error'>❌ club_ranking_snapshots is missing discipline column</p>";
        try {
            $db->query("ALTER TABLE club_ranking_snapshots ADD COLUMN discipline VARCHAR(50) NOT NULL DEFAULT 'GRAVITY' AFTER club_id");
            echo "<p class='success'>✅ Added discipline column to club_ranking_snapshots</p>";
        } catch (Exception $e) {
            echo "<p class='error'>❌ Failed: " . $e->getMessage() . "</p>";
        }
    }

    echo "<h2>Final Structure Check</h2>";
    $finalColumns = $db->getAll("SHOW COLUMNS FROM ranking_snapshots");
    echo "<p class='info'>Updated ranking_snapshots columns:</p>";
    echo "<pre>";
    foreach ($finalColumns as $col) {
        echo "- {$col['Field']} ({$col['Type']})\n";
    }
    echo "</pre>";

    echo "<h2>✅ Schema Fix Complete!</h2>";
    echo "<p>You can now try running the ranking calculation again:</p>";
    echo "<ul>";
    echo "<li><a href='/admin/ranking-minimal.php'>Test Calculation (ranking-minimal.php)</a></li>";
    echo "<li><a href='/admin/ranking.php'>Full Ranking Admin (ranking.php)</a></li>";
    echo "</ul>";

} catch (Exception $e) {
    echo "<p class='error'>❌ Error: " . $e->getMessage() . "</p>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}

echo "</body></html>";
?>

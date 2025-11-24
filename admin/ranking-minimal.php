<?php
/**
 * Minimal ranking page without templates
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

echo "<!DOCTYPE html><html><head><title>Minimal Ranking Test</title></head><body>";
echo "<h1>Minimal Ranking Test</h1>";

try {
    require_once __DIR__ . '/../config.php';
    echo "<p>✅ Config loaded</p>";

    require_once __DIR__ . '/../includes/ranking_functions.php';
    echo "<p>✅ Functions loaded</p>";

    $db = getDB();
    echo "<p>✅ Database connected</p>";

    $exists = rankingTablesExist($db);
    echo "<p>✅ Tables exist: " . ($exists ? 'YES' : 'NO') . "</p>";

    $multipliers = getRankingFieldMultipliers($db);
    echo "<p>✅ Got " . count($multipliers) . " multipliers</p>";

    $disciplineStats = getRankingStats($db);
    echo "<p>✅ Got stats</p>";
    echo "<pre>" . print_r($disciplineStats, true) . "</pre>";

    echo "<h2>Now test calculation</h2>";
    echo "<form method='POST'>";
    echo "<button type='submit' name='calculate'>Run Calculation</button>";
    echo "</form>";

    if (isset($_POST['calculate'])) {
        echo "<h3>Running calculation...</h3>";
        flush();

        // Increase time limit
        set_time_limit(300); // 5 minutes
        ini_set('memory_limit', '512M');

        echo "<p>Memory limit: " . ini_get('memory_limit') . "</p>";
        echo "<p>Time limit: " . ini_get('max_execution_time') . "s</p>";
        flush();

        try {
            $startTime = microtime(true);
            $calcStats = calculateAllRankingPoints($db, true); // Enable debug mode
            $endTime = microtime(true);
            $duration = round($endTime - $startTime, 2);

            echo "<p>✅ Calculation done in {$duration}s!</p>";
            echo "<pre>" . print_r($calcStats, true) . "</pre>";
            flush();

            echo "<p>Creating snapshots...</p>";
            flush();
            $snapshotStats = createRankingSnapshot($db);
            echo "<p>✅ Snapshots created!</p>";
            echo "<pre>" . print_r($snapshotStats, true) . "</pre>";
            flush();
        } catch (Exception $e) {
            echo "<p>❌ Error during calculation:</p>";
            echo "<pre>";
            echo "Message: " . htmlspecialchars($e->getMessage()) . "\n\n";
            echo "File: " . htmlspecialchars($e->getFile()) . "\n";
            echo "Line: " . $e->getLine() . "\n\n";
            echo "Stack trace:\n" . htmlspecialchars($e->getTraceAsString());
            echo "</pre>";
        }
    }

} catch (Exception $e) {
    echo "<h2>❌ Error:</h2>";
    echo "<pre>";
    echo "Message: " . htmlspecialchars($e->getMessage()) . "\n\n";
    echo "File: " . htmlspecialchars($e->getFile()) . "\n";
    echo "Line: " . $e->getLine() . "\n\n";
    echo "Stack trace:\n" . htmlspecialchars($e->getTraceAsString());
    echo "</pre>";
}

echo "</body></html>";
?>

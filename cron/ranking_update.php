<?php
/**
 * Ranking Update Cron Job
 *
 * Automatically updates the ranking system on the 1st of each month.
 * Run this script via cron:
 *
 * 0 2 1 * * /usr/bin/php /path/to/TheHUB/cron/ranking_update.php >> /var/log/ranking.log 2>&1
 *
 * This will run at 02:00 on the 1st of every month.
 */

// Only run from CLI
if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

// Set working directory
chdir(__DIR__ . '/..');

// Load dependencies
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/ranking_functions.php';

// Start logging
$startTime = microtime(true);
$logDate = date('Y-m-d H:i:s');

echo "[$logDate] Ranking update started\n";

try {
    $db = getDB();

    // Check if tables exist
    if (!rankingTablesExist($db)) {
        echo "[$logDate] ERROR: Ranking tables do not exist. Run migration first.\n";
        exit(1);
    }

    // Step 1: Calculate all ranking points
    echo "[$logDate] Calculating ranking points...\n";
    $calcStats = calculateAllRankingPoints($db);
    echo "[$logDate] Processed {$calcStats['events_processed']} events, {$calcStats['riders_processed']} results\n";

    // Step 2: Create snapshot for current month
    $snapshotDate = date('Y-m-01'); // First of current month
    echo "[$logDate] Creating snapshot for $snapshotDate...\n";
    $snapshotStats = createRankingSnapshot($db, $snapshotDate);
    echo "[$logDate] Ranked riders - Enduro: {$snapshotStats['enduro']}, DH: {$snapshotStats['dh']}, Gravity: {$snapshotStats['gravity']}\n";

    // Step 3: Cleanup old data (older than 26 months)
    echo "[$logDate] Cleaning up old data...\n";
    cleanupOldRankingData($db, 26);
    echo "[$logDate] Cleanup complete\n";

    // Calculate execution time
    $endTime = microtime(true);
    $duration = round($endTime - $startTime, 2);

    echo "[$logDate] Ranking update completed successfully in {$duration}s\n";
    echo "[$logDate] Summary: {$calcStats['events_processed']} events, {$calcStats['riders_processed']} results, {$snapshotStats['riders_ranked']} riders ranked\n";

} catch (Exception $e) {
    echo "[$logDate] ERROR: " . $e->getMessage() . "\n";
    echo "[$logDate] Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}

exit(0);

<?php
/**
 * SCF License Sync - Webhook Endpoint
 *
 * This script can be triggered by external cron services (like cron-job.org)
 * without requiring SSH access or server-level cron configuration.
 *
 * URL: https://thehub.gravityseries.se/cron/webhook-sync.php?key=YOUR_SECRET_KEY
 */

// Set execution time limit
set_time_limit(600); // 10 minutes

// Load config
require_once dirname(__DIR__) . '/config.php';

// Security: Check secret key
$secretKey = getenv('SCF_WEBHOOK_KEY') ?: 'change-this-secret-key-in-production';
$providedKey = $_GET['key'] ?? '';

if ($providedKey !== $secretKey) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Invalid webhook key',
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    exit;
}

// Get parameters
$year = intval($_GET['year'] ?? date('Y'));
$limit = intval($_GET['limit'] ?? 0); // 0 = no limit
$debug = isset($_GET['debug']);

// Start output
if ($debug) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "SCF License Sync - Webhook Triggered\n";
    echo str_repeat('=', 60) . "\n";
    echo "Time: " . date('Y-m-d H:i:s') . "\n";
    echo "Year: $year\n";
    echo "Limit: " . ($limit > 0 ? $limit : 'none') . "\n";
    echo str_repeat('=', 60) . "\n\n";
} else {
    header('Content-Type: application/json');
}

// Capture output for logging
ob_start();

try {
    // Include the sync script logic
    require_once __DIR__ . '/sync_scf_licenses_lib.php';

    // Run sync
    $result = syncSCFLicenses($year, $limit, $debug);

    // Get captured output
    $output = ob_get_clean();

    // Log to file
    $logDir = dirname(__DIR__) . '/logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }

    $logFile = $logDir . '/scf-sync.log';
    $logEntry = sprintf(
        "[%s] Webhook sync completed: %d riders processed, %d updated, %d errors\n",
        date('Y-m-d H:i:s'),
        $result['processed'] ?? 0,
        $result['updated'] ?? 0,
        $result['errors'] ?? 0
    );
    file_put_contents($logFile, $logEntry, FILE_APPEND);

    // Output result
    if ($debug) {
        echo $output;
        echo "\n" . str_repeat('=', 60) . "\n";
        echo "SUMMARY:\n";
        echo "  Processed: " . ($result['processed'] ?? 0) . "\n";
        echo "  Updated: " . ($result['updated'] ?? 0) . "\n";
        echo "  Errors: " . ($result['errors'] ?? 0) . "\n";
        echo "  Duration: " . ($result['duration'] ?? 'N/A') . "\n";
    } else {
        echo json_encode([
            'success' => true,
            'result' => $result,
            'timestamp' => date('Y-m-d H:i:s'),
            'log_file' => $logFile
        ], JSON_PRETTY_PRINT);
    }

} catch (Exception $e) {
    $output = ob_get_clean();

    // Log error
    $logDir = dirname(__DIR__) . '/logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }

    $logFile = $logDir . '/scf-sync.log';
    $logEntry = sprintf(
        "[%s] Webhook sync FAILED: %s\n",
        date('Y-m-d H:i:s'),
        $e->getMessage()
    );
    file_put_contents($logFile, $logEntry, FILE_APPEND);

    http_response_code(500);

    if ($debug) {
        echo $output;
        echo "\nERROR: " . $e->getMessage() . "\n";
        echo $e->getTraceAsString() . "\n";
    } else {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage(),
            'timestamp' => date('Y-m-d H:i:s')
        ], JSON_PRETTY_PRINT);
    }
}

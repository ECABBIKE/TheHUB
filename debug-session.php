<?php
/**
 * Debug file - DELETE AFTER USE
 * Check session status, login state, and router output
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/v3-config.php';
require_once __DIR__ . '/router.php';

header('Content-Type: text/plain; charset=utf-8');

echo "=== SESSION DEBUG ===\n\n";

echo "Session ID: " . session_id() . "\n";
echo "Session Status: " . session_status() . " (1=disabled, 2=active)\n\n";

echo "=== LOGIN CHECK ===\n";
echo "hub_is_logged_in(): " . (hub_is_logged_in() ? 'TRUE' : 'FALSE') . "\n";
echo "hub_is_admin(): " . (hub_is_admin() ? 'TRUE' : 'FALSE') . "\n";

echo "\n=== ROUTER TEST ===\n";
// Simulate visiting the root
$_GET['page'] = '';
$pageInfo = hub_get_current_page();
echo "Router output for '/':\n";
print_r($pageInfo);

echo "\n=== FILE CHECK ===\n";
echo "Dashboard file exists: " . (file_exists(HUB_V3_ROOT . '/pages/dashboard.php') ? 'YES' : 'NO') . "\n";
echo "Dashboard file path: " . HUB_V3_ROOT . '/pages/dashboard.php' . "\n";
echo "Dashboard file readable: " . (is_readable(HUB_V3_ROOT . '/pages/dashboard.php') ? 'YES' : 'NO') . "\n";

echo "\n=== TRY LOADING DASHBOARD ===\n";
ob_start();
try {
    include HUB_V3_ROOT . '/pages/dashboard.php';
    $content = ob_get_clean();
    echo "Dashboard loaded successfully!\n";
    echo "Content length: " . strlen($content) . " bytes\n";
    echo "First 500 chars:\n" . substr($content, 0, 500) . "\n";
} catch (Exception $e) {
    ob_end_clean();
    echo "ERROR loading dashboard: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . "\n";
    echo "Line: " . $e->getLine() . "\n";
}

echo "\n\nDELETE THIS FILE AFTER DEBUGGING!\n";

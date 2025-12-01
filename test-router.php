<?php
/**
 * Router Debug Test
 * Tests if the router correctly handles /calendar/256 URL
 * DELETE THIS FILE AFTER DEBUGGING
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

$_GET['page'] = 'calendar/256';

require_once __DIR__ . '/v3-config.php';
require_once __DIR__ . '/router.php';

$pageInfo = hub_get_current_page();

echo "<pre style='font-family: monospace; background: #1a1a2e; color: #10B981; padding: 20px; border-radius: 8px;'>";
echo "=== ROUTER DEBUG TEST ===\n\n";
echo "URL: /calendar/256\n\n";
echo "Router Output:\n";
print_r($pageInfo);

echo "\n\nFile Check:\n";
echo "- File path: " . $pageInfo['file'] . "\n";
echo "- File exists: " . (file_exists($pageInfo['file']) ? 'YES ✓' : 'NO ✗') . "\n";

if (file_exists($pageInfo['file'])) {
    echo "- File readable: " . (is_readable($pageInfo['file']) ? 'YES ✓' : 'NO ✗') . "\n";
}

echo "\nParams:\n";
print_r($pageInfo['params']);

echo "\n\nConstants:\n";
echo "- HUB_V3_ROOT: " . (defined('HUB_V3_ROOT') ? HUB_V3_ROOT : 'NOT DEFINED') . "\n";
echo "- HUB_V3_URL: " . (defined('HUB_V3_URL') ? HUB_V3_URL : 'NOT DEFINED') . "\n";

echo "\n=========================\n";
echo "DELETE THIS FILE AFTER DEBUGGING!\n";
echo "</pre>";

<?php
/**
 * Debug file - DELETE AFTER USE
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/v3-config.php';
require_once __DIR__ . '/router.php';

header('Content-Type: text/plain; charset=utf-8');

echo "=== FULL REQUEST DEBUG ===\n\n";

echo "=== RAW REQUEST ===\n";
echo "REQUEST_URI: " . ($_SERVER['REQUEST_URI'] ?? 'not set') . "\n";
echo "QUERY_STRING: " . ($_SERVER['QUERY_STRING'] ?? 'not set') . "\n";
echo "\$_GET['page']: " . var_export($_GET['page'] ?? null, true) . "\n";
echo "Full \$_GET: ";
print_r($_GET);

echo "\n=== LOGIN CHECK ===\n";
echo "hub_is_logged_in(): " . (hub_is_logged_in() ? 'TRUE' : 'FALSE') . "\n";

echo "\n=== ROUTER TEST (with actual \$_GET) ===\n";
// Don't override $_GET, use what's actually there
$pageInfo = hub_get_current_page();
echo "Router output:\n";
print_r($pageInfo);

echo "\n=== FILE CHECK ===\n";
echo "File exists: " . (file_exists($pageInfo['file']) ? 'YES' : 'NO') . "\n";
echo "File path: " . $pageInfo['file'] . "\n";

echo "\n\nNow try visiting: https://thehub.gravityseries.se/?debug=1\n";
echo "And paste the output here.\n\n";

echo "DELETE THIS FILE AFTER DEBUGGING!\n";

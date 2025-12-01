<?php
/**
 * Debug file - DELETE AFTER USE
 * Check session status and login state
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/v3-config.php';

header('Content-Type: text/plain; charset=utf-8');

echo "=== SESSION DEBUG ===\n\n";

echo "Session ID: " . session_id() . "\n";
echo "Session Status: " . session_status() . " (1=disabled, 2=active)\n\n";

echo "=== SESSION DATA ===\n";
print_r($_SESSION);

echo "\n=== LOGIN CHECK ===\n";
echo "hub_is_logged_in(): " . (hub_is_logged_in() ? 'TRUE' : 'FALSE') . "\n";
echo "hub_is_admin(): " . (hub_is_admin() ? 'TRUE' : 'FALSE') . "\n";

$user = hub_current_user();
echo "hub_current_user(): " . ($user ? json_encode($user, JSON_PRETTY_PRINT) : 'NULL') . "\n";

echo "\n=== REQUEST INFO ===\n";
echo "REQUEST_URI: " . ($_SERVER['REQUEST_URI'] ?? 'not set') . "\n";
echo "page param: " . ($_GET['page'] ?? 'not set') . "\n";

echo "\n=== COOKIE INFO ===\n";
print_r($_COOKIE);

echo "\n\nDELETE THIS FILE AFTER DEBUGGING!\n";

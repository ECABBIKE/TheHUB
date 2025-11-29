<?php
/**
 * TheHUB Rider Page Module
 * Wrapper for rider.php with clean URL support
 *
 * Routes:
 *   /rider/123 -> rider.php?id=123
 */

$isSpaMode = defined('HUB_ROOT') && isset($pageInfo);

// Get rider ID from route params or query string
if ($isSpaMode && !empty($pageInfo['params']['id'])) {
    $_GET['id'] = $pageInfo['params']['id'];
}

// Include the original rider.php
include __DIR__ . '/../rider.php';

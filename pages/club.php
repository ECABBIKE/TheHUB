<?php
/**
 * TheHUB Club Page Module
 * Wrapper for club.php with clean URL support
 *
 * Routes:
 *   /club/456 -> club.php?id=456
 */

$isSpaMode = defined('HUB_ROOT') && isset($pageInfo);

// Get club ID from route params or query string
if ($isSpaMode && !empty($pageInfo['params']['id'])) {
    $_GET['id'] = $pageInfo['params']['id'];
}

// Include the original club.php
include __DIR__ . '/../club.php';

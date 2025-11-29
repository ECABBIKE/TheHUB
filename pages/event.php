<?php
/**
 * TheHUB Event/Results Page Module
 * Wrapper for event-results.php with clean URL support
 *
 * Routes:
 *   /results/243 -> event-results.php?id=243
 *   /event/243   -> event-results.php?id=243
 */

$isSpaMode = defined('HUB_ROOT') && isset($pageInfo);

// Get event ID from route params or query string
if ($isSpaMode && !empty($pageInfo['params']['id'])) {
    $_GET['id'] = $pageInfo['params']['id'];
}

// Include the original event-results.php
// It will detect SPA mode via the $isSpaMode variable
include __DIR__ . '/../event-results.php';

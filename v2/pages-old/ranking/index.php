<?php
/**
 * TheHUB Ranking Page Module
 * Wrapper that includes the original ranking page
 */

$isSpaMode = defined('HUB_ROOT') && isset($pageInfo);

// Set flag for the included file to detect SPA mode
define('SPA_MODE', $isSpaMode);

// The original ranking page handles everything
include __DIR__ . '/../../ranking/index.php';

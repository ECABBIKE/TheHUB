<?php
/**
 * TheHUB Clubs Index Page Module
 * Redirects to leaderboard
 */

$isSpaMode = defined('HUB_ROOT') && isset($pageInfo);

header('Location: ' . ($isSpaMode ? '/clubs/leaderboard' : '/clubs/leaderboard.php'));
exit;

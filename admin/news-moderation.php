<?php
/**
 * Redirect to race-reports.php
 * News moderation is now handled in the unified race-reports page
 */
header('Location: /admin/race-reports.php?filter=pending');
exit;

<?php
/**
 * Event page - redirects to event-results.php
 * event-results.php is now the main event page with tabs for results and info
 */
require_once __DIR__ . '/config.php';

// Get event ID from URL
$eventId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$eventId) {
    header('Location: /events.php');
    exit;
}

// Redirect to event-results.php with any tab parameter
$tab = isset($_GET['tab']) ? '&tab=' . urlencode($_GET['tab']) : '';
header('Location: /event-results.php?id=' . $eventId . $tab);
exit;

<?php
/**
 * Series Edit - Redirect to Series Manage
 *
 * All series management is now handled in series-manage.php
 * This file redirects for backwards compatibility.
 *
 * For NEW series: redirects to series-manage with new=1
 * For EXISTING series: redirects to series-manage with the ID
 */

require_once __DIR__ . '/../config.php';

// Get series ID from URL
$id = 0;
if (isset($_GET['id'])) {
    $id = intval($_GET['id']);
} else {
    $uri = $_SERVER['REQUEST_URI'];
    if (preg_match('#/admin/series/edit/(\d+)#', $uri, $matches)) {
        $id = intval($matches[1]);
    }
}

// Check if creating new series
$isNew = ($id === 0 && isset($_GET['new']));

// Map tabs: series-edit had info, events, rules
// series-manage has info, events, registration, payment, results
$tab = $_GET['tab'] ?? 'info';
$tabMapping = [
    'info' => 'info',
    'events' => 'events',
    'rules' => 'info'  // Rules functionality can be found in info or we add later
];
$mappedTab = $tabMapping[$tab] ?? 'info';

// Build redirect URL
if ($isNew) {
    // For new series, we need to handle this specially
    // For now, redirect to series list with a message to use the create button there
    $_SESSION['flash_message'] = 'Använd "Ny serie" knappen för att skapa en ny serie';
    $_SESSION['flash_type'] = 'info';
    header('Location: /admin/series');
    exit;
} else {
    // Redirect existing series to manage page
    $queryParams = [];
    if ($id > 0) {
        $queryParams['id'] = $id;
    }
    $queryParams['tab'] = $mappedTab;

    $redirectUrl = '/admin/series/manage/' . $id . '?' . http_build_query($queryParams);
    header('Location: ' . $redirectUrl);
    exit;
}

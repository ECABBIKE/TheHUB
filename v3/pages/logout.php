<?php
/**
 * TheHUB V3.5 - Logout
 */

// Prevent direct access
if (!defined('HUB_V3_ROOT')) {
    header('Location: /v3/');
    exit;
}

// Log out the user
hub_logout();

// Redirect to home or specified page
$redirect = $_GET['redirect'] ?? HUB_V3_URL . '/';

header('Location: ' . $redirect);
exit;

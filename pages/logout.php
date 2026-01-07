<?php
/**
 * TheHUB V1.0 - Logout
 */

// Prevent direct access
if (!defined('HUB_V3_ROOT')) {
    header('Location: /');
    exit;
}

// Log out the user
hub_logout();

// Redirect to home or specified page
$redirect = $_GET['redirect'] ?? HUB_V3_URL . '/';

header('Location: ' . $redirect);
exit;

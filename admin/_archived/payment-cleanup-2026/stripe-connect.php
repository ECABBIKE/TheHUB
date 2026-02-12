<?php
/**
 * Stripe Connect - Redirect to Payment Recipients
 *
 * All payment recipient management (including Stripe Connect)
 * is now handled in payment-recipients.php
 */

require_once __DIR__ . '/../config.php';
require_admin();

// Redirect to payment-recipients with any query parameters
$queryString = $_SERVER['QUERY_STRING'] ?? '';
$redirectUrl = '/admin/payment-recipients' . ($queryString ? '?' . $queryString : '');

header('Location: ' . $redirectUrl);
exit;

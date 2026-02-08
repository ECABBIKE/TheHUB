<?php
/**
 * Gravity ID Discount API
 * Returns discount amount for a rider and event
 */

require_once dirname(__DIR__) . '/hub-config.php';
require_once dirname(__DIR__) . '/includes/payment.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$riderId = intval($_GET['rider_id'] ?? 0);
$eventId = intval($_GET['event_id'] ?? 0);

if (!$riderId || !$eventId) {
    echo json_encode(['discount' => 0]);
    exit;
}

// Use existing checkGravityIdDiscount function
$gravityIdInfo = checkGravityIdDiscount($riderId, $eventId);

echo json_encode([
    'discount' => $gravityIdInfo['discount'] ?? 0,
    'has_gravity_id' => $gravityIdInfo['has_gravity_id'] ?? false,
    'gravity_id' => $gravityIdInfo['gravity_id'] ?? null
]);

<?php
/**
 * Check Order Payment Status API
 * Returns the payment_status of an order (lightweight, no full page load)
 */

require_once dirname(__DIR__) . '/hub-config.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$orderId = intval($_GET['order_id'] ?? 0);

if (!$orderId) {
    echo json_encode(['payment_status' => 'unknown']);
    exit;
}

$pdo = hub_db();

$stmt = $pdo->prepare("SELECT payment_status FROM orders WHERE id = ?");
$stmt->execute([$orderId]);
$status = $stmt->fetchColumn();

echo json_encode([
    'payment_status' => $status ?: 'unknown'
]);

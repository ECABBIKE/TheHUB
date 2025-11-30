<?php

require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/../../core/Database.php';
require_once __DIR__ . '/../../modules/results/ResultsModel.php';

header('Content-Type: application/json; charset=utf-8');

$eventId = isset($_GET['event_id']) ? (int) $_GET['event_id'] : 0;

if ($eventId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing event_id']);
    exit;
}

try {
    $rows = ResultsModel::forEvent($eventId);
    echo json_encode(['ok' => true, 'data' => $rows]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}

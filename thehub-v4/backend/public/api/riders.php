<?php

require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/../../core/Database.php';
require_once __DIR__ . '/../../modules/riders/RiderModel.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $items = RiderModel::all(500);
    echo json_encode(['ok' => true, 'data' => $items]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}

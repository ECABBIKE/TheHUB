<?php

require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/../../core/Database.php';
require_once __DIR__ . '/../../modules/riders/RiderModel.php';

header('Content-Type: application/json; charset=utf-8');

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing id']);
    exit;
}

try {
    $item = RiderModel::find($id);
    if (!$item) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Not found']);
        exit;
    }
    echo json_encode(['ok' => true, 'data' => $item]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}

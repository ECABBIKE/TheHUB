<?php

require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/../../core/Database.php';
require_once __DIR__ . '/../../modules/ranking/RankingEngine.php';

header('Content-Type: application/json; charset=utf-8');

$series = $_GET['series'] ?? 'capital';

try {
    $rows = RankingEngine::seriesRanking($series);
    echo json_encode(['ok' => true, 'series' => $series, 'data' => $rows]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}

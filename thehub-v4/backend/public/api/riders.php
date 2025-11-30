<?php
// backend/public/api/riders.php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../core/Database.php';

try {
    $pdo = Database::getInstance()->pdo();

    $stmt = $pdo->query("
        SELECT id, firstname, lastname, gravity_id, club_id, active, disciplines, license_number
        FROM riders
        ORDER BY lastname ASC, firstname ASC
        LIMIT 500
    ");
    $rows = $stmt->fetchAll();

    echo json_encode([
        'ok' => true,
        'count' => count($rows),
        'data' => $rows
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ]);
}

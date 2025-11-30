<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../core/Database.php';

try {
    $pdo = Database::pdo();

    $sql = "
        SELECT
            r.id,
            r.gravity_id,
            r.firstname,
            r.lastname,
            r.license_number,
            c.name AS club_name
        FROM riders r
        LEFT JOIN clubs c ON r.club_id = c.id
        WHERE (r.firstname IS NOT NULL AND r.firstname <> '')
           OR (r.lastname IS NOT NULL AND r.lastname <> '')
        ORDER BY r.lastname, r.firstname
        LIMIT 500
    ";

    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll();

    echo json_encode([
        'ok'   => true,
        'data' => $rows,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok'    => false,
        'error' => 'DB error: ' . $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}

<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

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
            r.club_id,
            r.gender,
            c.name AS club_name,
            COALESCE(SUM(rp.ranking_points), 0) AS total_points
        FROM riders r
        LEFT JOIN clubs c ON r.club_id = c.id
        LEFT JOIN ranking_points rp ON r.id = rp.rider_id
        WHERE (r.firstname IS NOT NULL AND r.firstname <> '')
           OR (r.lastname IS NOT NULL AND r.lastname <> '')
        GROUP BY r.id, r.gravity_id, r.firstname, r.lastname, r.license_number, r.club_id, r.gender, c.name
        ORDER BY total_points DESC, r.lastname, r.firstname
        LIMIT 1000
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

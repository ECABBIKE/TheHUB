<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../core/Database.php';

try {
    $pdo = Database::pdo();

    $sql = "
        SELECT
            c.id,
            c.name,
            c.location,
            COUNT(DISTINCT r.id) AS member_count,
            COALESCE(SUM(rp.ranking_points), 0) AS total_points,
            COUNT(DISTINCT res.event_id) AS events_participated
        FROM clubs c
        LEFT JOIN riders r ON c.id = r.club_id
        LEFT JOIN results res ON r.id = res.cyclist_id
        LEFT JOIN ranking_points rp ON r.id = rp.rider_id
        GROUP BY c.id, c.name, c.location
        ORDER BY total_points DESC, member_count DESC
    ";

    $stmt = $pdo->query($sql);
    $clubs = $stmt->fetchAll();

    echo json_encode(['ok' => true, 'data' => $clubs], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB error: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

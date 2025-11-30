<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../core/Database.php';

try {
    $pdo = Database::pdo();

    $discipline = isset($_GET['discipline']) && $_GET['discipline'] !== ''
        ? $_GET['discipline']
        : null;

    // 24 månader bakåt från idag
    $sql = "
        SELECT
            rp.rider_id,
            r.firstname,
            r.lastname,
            r.gravity_id,
            c.name AS club_name,
            COUNT(*) AS events_count,
            SUM(rp.ranking_points) AS total_points
        FROM ranking_points rp
        JOIN riders r ON r.id = rp.rider_id
        LEFT JOIN clubs c ON c.id = r.club_id
        WHERE rp.event_date >= DATE_SUB(CURDATE(), INTERVAL 730 DAY)
    ";

    $params = [];

    if ($discipline) {
        $sql .= " AND rp.discipline = :discipline";
        $params[':discipline'] = $discipline;
    }

    $sql .= "
        GROUP BY
            rp.rider_id,
            r.firstname,
            r.lastname,
            r.gravity_id,
            c.name
        HAVING total_points > 0
        ORDER BY total_points DESC
        LIMIT 300
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
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

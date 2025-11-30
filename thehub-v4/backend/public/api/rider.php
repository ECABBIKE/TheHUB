<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../core/Database.php';

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing or invalid id']);
    exit;
}

try {
    $pdo = Database::pdo();

    // Get rider info with club data and stats
    $stmt = $pdo->prepare("
        SELECT
            r.id,
            r.firstname,
            r.lastname,
            r.gravity_id,
            r.gender,
            r.birth_year,
            r.club_id,
            r.license_number,
            c.name AS club_name,
            YEAR(CURDATE()) - r.birth_year AS age,
            (SELECT COUNT(*) FROM results WHERE cyclist_id = r.id) AS total_starts,
            (SELECT COUNT(*) FROM results WHERE cyclist_id = r.id AND position IS NOT NULL AND position > 0) AS completed_races,
            (SELECT COUNT(*) FROM results WHERE cyclist_id = r.id AND position = 1) AS wins,
            (SELECT COUNT(*) FROM results WHERE cyclist_id = r.id AND position <= 3 AND position > 0) AS podiums,
            (SELECT SUM(rp.ranking_points) FROM ranking_points rp WHERE rp.rider_id = r.id) AS total_points
        FROM riders r
        LEFT JOIN clubs c ON r.club_id = c.id
        WHERE r.id = :id
        LIMIT 1
    ");

    $stmt->execute(['id' => $id]);
    $rider = $stmt->fetch();

    if (!$rider) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Rider not found']);
        exit;
    }

    // Get rider's results
    $stmt = $pdo->prepare("
        SELECT
            res.id,
            res.event_id,
            res.position,
            res.finish_time AS time,
            res.points,
            res.category_id AS category 
            e.name AS event_name,
            e.date,
            e.discipline,
            e.location
        FROM results res
        JOIN events e ON res.event_id = e.id
        WHERE res.cyclist_id = :rider_id
        ORDER BY e.date DESC
        LIMIT 50
    ");
    $stmt->execute(['rider_id' => $id]);
    $rider['results'] = $stmt->fetchAll();

    // Get rider's ranking points by discipline
    $stmt = $pdo->prepare("
        SELECT
            discipline,
            SUM(ranking_points) AS points,
            COUNT(*) AS events
        FROM ranking_points
        WHERE rider_id = :rider_id
        GROUP BY discipline
        ORDER BY points DESC
    ");
    $stmt->execute(['rider_id' => $id]);
    $rider['ranking_by_discipline'] = $stmt->fetchAll();

    echo json_encode(['ok' => true, 'data' => $rider], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB error: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

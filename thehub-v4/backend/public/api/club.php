<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../core/Database.php';

// Support both ID and name lookup
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$name = isset($_GET['name']) ? trim($_GET['name']) : '';

if ($id <= 0 && $name === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing id or name parameter']);
    exit;
}

try {
    $pdo = Database::pdo();

    // Get club info with stats
    if ($id > 0) {
        $stmt = $pdo->prepare("
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
            WHERE c.id = :id
            GROUP BY c.id
        ");
        $stmt->execute(['id' => $id]);
    } else {
        $stmt = $pdo->prepare("
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
            WHERE c.name = :name
            GROUP BY c.id
        ");
        $stmt->execute(['name' => $name]);
    }

    $club = $stmt->fetch();

    if (!$club) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Club not found']);
        exit;
    }

    $club_id = $club['id'];

    // Get top riders from this club
    $stmt = $pdo->prepare("
        SELECT
            r.id,
            CONCAT(r.firstname, ' ', r.lastname) AS name,
            r.gravity_id,
            COUNT(res.id) AS starts,
            COALESCE(SUM(rp.ranking_points), 0) AS points,
            COUNT(CASE WHEN res.position = 1 THEN 1 END) AS wins
        FROM riders r
        LEFT JOIN results res ON r.id = res.cyclist_id
        LEFT JOIN ranking_points rp ON r.id = rp.rider_id
        WHERE r.club_id = :club_id
        GROUP BY r.id
        ORDER BY points DESC
        LIMIT 20
    ");
    $stmt->execute(['club_id' => $club_id]);
    $club['members'] = $stmt->fetchAll();

    // Get recent results from club members
    $stmt = $pdo->prepare("
        SELECT
            CONCAT(r.firstname, ' ', r.lastname) AS rider_name,
            r.id AS rider_id,
            e.name AS event_name,
            e.id AS event_id,
            res.position,
            res.points,
            e.date,
            e.discipline
        FROM results res
        JOIN riders r ON res.cyclist_id = r.id
        JOIN events e ON res.event_id = e.id
        WHERE r.club_id = :club_id
        ORDER BY e.date DESC
        LIMIT 20
    ");
    $stmt->execute(['club_id' => $club_id]);
    $club['recent_results'] = $stmt->fetchAll();

    // Get series participation
    $stmt = $pdo->prepare("
        SELECT
            e.series_id AS series,
            COUNT(DISTINCT res.id) AS results_count,
            COUNT(DISTINCT r.id) AS riders_count,
            COALESCE(SUM(res.points), 0) AS total_points
        FROM results res
        JOIN riders r ON res.cyclist_id = r.id
        JOIN events e ON res.event_id = e.id
        WHERE r.club_id = :club_id
        GROUP BY e.series_id
        ORDER BY total_points DESC
    ");
    $stmt->execute(['club_id' => $club_id]);
    $club['series_participation'] = $stmt->fetchAll();

    echo json_encode(['ok' => true, 'data' => $club], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB error: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

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

    // Get event info with stats
    $stmt = $pdo->prepare("
        SELECT
            e.id,
            e.name,
            e.date,
            e.discipline,
            e.type AS series,
            e.location,
            e.organizer,
            e.status,
            COUNT(DISTINCT res.cyclist_id) AS participants_count,
            COUNT(DISTINCT r.club_id) AS clubs_count,
            COUNT(DISTINCT res.category) AS categories_count
        FROM events e
        LEFT JOIN results res ON e.id = res.event_id
        LEFT JOIN riders r ON res.cyclist_id = r.id
        WHERE e.id = :id
        GROUP BY e.id
    ");

    $stmt->execute(['id' => $id]);
    $event = $stmt->fetch();

    if (!$event) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Event not found']);
        exit;
    }

    // Get results for this event with rider and club names
    $stmt = $pdo->prepare("
        SELECT
            res.id,
            res.cyclist_id AS rider_id,
            res.position,
            res.time,
            res.points,
            res.category,
            CONCAT(r.firstname, ' ', r.lastname) AS rider_name,
            r.gravity_id,
            c.name AS club_name
        FROM results res
        LEFT JOIN riders r ON res.cyclist_id = r.id
        LEFT JOIN clubs c ON r.club_id = c.id
        WHERE res.event_id = :event_id
        ORDER BY
            res.category ASC,
            CASE WHEN res.position IS NULL THEN 1 ELSE 0 END,
            res.position ASC,
            res.time ASC
    ");
    $stmt->execute(['event_id' => $id]);
    $event['results'] = $stmt->fetchAll();

    // Get unique categories
    $categories = array_unique(array_filter(array_column($event['results'], 'category')));
    $event['categories'] = array_values($categories);

    echo json_encode(['ok' => true, 'data' => $event], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB error: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

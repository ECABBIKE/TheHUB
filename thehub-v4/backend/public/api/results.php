<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../core/Database.php';

try {
    $pdo = Database::pdo();

    $event_id = isset($_GET['event_id']) ? (int) $_GET['event_id'] : 0;
    $rider_id = isset($_GET['rider_id']) ? (int) $_GET['rider_id'] : 0;
    $year = isset($_GET['year']) && $_GET['year'] !== '' ? (int) $_GET['year'] : null;
    $series = isset($_GET['series']) && $_GET['series'] !== '' ? $_GET['series'] : null;

    if ($event_id > 0) {
        // Get results for specific event WITH rider and club names
        $sql = "
            SELECT
                res.id,
                res.cyclist_id AS rider_id,
                res.event_id,
                res.position,
                res.time,
                res.points,
                res.category,
                CONCAT(r.firstname, ' ', r.lastname) AS rider_name,
                r.gravity_id,
                r.club_id,
                c.name AS club_name,
                e.name AS event_name,
                e.date,
                e.type AS series,
                e.discipline
            FROM results res
            LEFT JOIN riders r ON res.cyclist_id = r.id
            LEFT JOIN clubs c ON r.club_id = c.id
            LEFT JOIN events e ON res.event_id = e.id
            WHERE res.event_id = :event_id
            ORDER BY
                res.category ASC,
                CASE WHEN res.position IS NULL THEN 1 ELSE 0 END,
                res.position ASC,
                res.time ASC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['event_id' => $event_id]);

    } elseif ($rider_id > 0) {
        // Get results for specific rider
        $sql = "
            SELECT
                res.id,
                res.cyclist_id AS rider_id,
                res.event_id,
                res.position,
                res.time,
                res.points,
                res.category,
                e.name AS event_name,
                e.date,
                e.type AS series,
                e.discipline,
                e.location,
                c.name AS club_name
            FROM results res
            LEFT JOIN events e ON res.event_id = e.id
            LEFT JOIN riders r ON res.cyclist_id = r.id
            LEFT JOIN clubs c ON r.club_id = c.id
            WHERE res.cyclist_id = :rider_id
            ORDER BY e.date DESC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['rider_id' => $rider_id]);

    } else {
        // Get all recent results with optional filters
        $where = [];
        $params = [];

        if ($year) {
            $where[] = "YEAR(e.date) = :year";
            $params[':year'] = $year;
        }

        if ($series) {
            $where[] = "e.type = :series";
            $params[':series'] = $series;
        }

        $whereSql = $where ? ("WHERE " . implode(" AND ", $where)) : "";

        $sql = "
            SELECT
                res.id,
                res.cyclist_id AS rider_id,
                res.event_id,
                res.position,
                res.time,
                res.points,
                res.category,
                CONCAT(r.firstname, ' ', r.lastname) AS rider_name,
                r.gravity_id,
                c.name AS club_name,
                e.name AS event_name,
                e.date,
                e.type AS series,
                e.discipline
            FROM results res
            LEFT JOIN riders r ON res.cyclist_id = r.id
            LEFT JOIN clubs c ON r.club_id = c.id
            LEFT JOIN events e ON res.event_id = e.id
            $whereSql
            ORDER BY e.date DESC, res.position ASC
            LIMIT 500
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    }

    $results = $stmt->fetchAll();
    echo json_encode(['ok' => true, 'data' => $results], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB error: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

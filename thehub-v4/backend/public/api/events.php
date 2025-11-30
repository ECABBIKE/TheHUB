<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../core/Database.php';

try {
    $pdo = Database::pdo();

    $year = isset($_GET['year']) && $_GET['year'] !== ''
        ? (int) $_GET['year']
        : null;

    $series = isset($_GET['series']) && $_GET['series'] !== ''
        ? $_GET['series']
        : null;

    $discipline = isset($_GET['discipline']) && $_GET['discipline'] !== ''
        ? $_GET['discipline']
        : null;

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

    if ($discipline) {
        $where[] = "e.discipline = :discipline";
        $params[':discipline'] = $discipline;
    }

    $whereSql = $where ? ("WHERE " . implode(" AND ", $where)) : "";

    $sql = "
        SELECT
            e.id,
            e.name,
            e.date,
            e.location,
            e.discipline,
            e.type AS series,
            e.status,
            e.organizer,
            COUNT(DISTINCT res.cyclist_id) AS participants_count
        FROM events e
        LEFT JOIN results res ON e.id = res.event_id
        $whereSql
        GROUP BY e.id, e.name, e.date, e.location, e.discipline, e.type, e.status, e.organizer
        ORDER BY e.date DESC
        LIMIT 500
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

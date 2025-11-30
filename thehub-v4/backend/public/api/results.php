<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../core/Database.php';

try {
    $pdo = Database::pdo();

    $year = isset($_GET['year']) && $_GET['year'] !== ''
        ? (int) $_GET['year']
        : null;

    $where = [];
    $params = [];

    if ($year) {
        $where[] = "YEAR(e.date) = :year";
        $params[':year'] = $year;
    }

    $whereSql = $where ? ("WHERE " . implode(" AND ", $where)) : "";

    $sql = "
        SELECT
            e.id,
            e.name,
            e.date,
            e.location,
            e.discipline,
            COUNT(DISTINCT r.cyclist_id) AS participants
        FROM events e
        JOIN results r ON r.event_id = e.id
        $whereSql
        GROUP BY e.id, e.name, e.date, e.location, e.discipline
        ORDER BY e.date DESC
        LIMIT 200
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

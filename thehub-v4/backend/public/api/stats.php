<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../core/Database.php';

try {
    $pdo = Database::pdo();

    // Get total events
    $stmt = $pdo->query("SELECT COUNT(*) AS count FROM events");
    $total_events = $stmt->fetch()['count'];

    // Get total riders (with valid names)
    $stmt = $pdo->query("
        SELECT COUNT(*) AS count FROM riders
        WHERE (firstname IS NOT NULL AND firstname <> '')
           OR (lastname IS NOT NULL AND lastname <> '')
    ");
    $total_riders = $stmt->fetch()['count'];

    // Get total clubs
    $stmt = $pdo->query("SELECT COUNT(*) AS count FROM clubs");
    $total_clubs = $stmt->fetch()['count'];

    // Get total results
    $stmt = $pdo->query("SELECT COUNT(*) AS count FROM results");
    $total_results = $stmt->fetch()['count'];

    // Get upcoming events (next 3 months)
    $stmt = $pdo->query("
        SELECT COUNT(*) AS count FROM events
        WHERE date >= CURDATE()
          AND date <= DATE_ADD(CURDATE(), INTERVAL 3 MONTH)
    ");
    $upcoming_events = $stmt->fetch()['count'];

    // Get events this year
    $stmt = $pdo->query("
        SELECT COUNT(*) AS count FROM events
        WHERE YEAR(date) = YEAR(CURDATE())
    ");
    $events_this_year = $stmt->fetch()['count'];

    // Get distinct disciplines
    $stmt = $pdo->query("SELECT COUNT(DISTINCT discipline) AS count FROM events WHERE discipline IS NOT NULL");
    $disciplines = $stmt->fetch()['count'];

    echo json_encode([
        'ok' => true,
        'data' => [
            'total_events'    => (int) $total_events,
            'total_riders'    => (int) $total_riders,
            'total_clubs'     => (int) $total_clubs,
            'total_results'   => (int) $total_results,
            'upcoming_events' => (int) $upcoming_events,
            'events_this_year' => (int) $events_this_year,
            'disciplines'     => (int) $disciplines,
            'last_updated'    => date('Y-m-d H:i:s'),
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB error: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

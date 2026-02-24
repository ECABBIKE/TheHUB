<?php
/**
 * GravityTiming API - Events endpoint
 * GET /api/v1/events.php - Lista tillgängliga events
 */
require_once __DIR__ . '/auth-middleware.php';

$auth = validateApiRequest('readonly');
$pdo = $GLOBALS['pdo'];

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    apiError('Metoden stöds inte. Använd GET.', 405);
}

// Query params
$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$status = $_GET['status'] ?? null; // upcoming, past, all

// Build query
$where = ['YEAR(e.date) = ?'];
$params = [$year];

if ($status === 'upcoming') {
    $where[] = 'e.date >= CURDATE()';
} elseif ($status === 'past') {
    $where[] = 'e.date < CURDATE()';
}

// Restrict to allowed events
if ($auth['event_ids'] !== null) {
    $placeholders = implode(',', array_fill(0, count($auth['event_ids']), '?'));
    $where[] = "e.id IN ($placeholders)";
    $params = array_merge($params, $auth['event_ids']);
}

$whereClause = implode(' AND ', $where);

$sql = "
    SELECT
        e.id,
        e.name,
        e.date,
        e.location,
        e.discipline,
        e.event_format,
        e.max_participants,
        e.stage_names,
        e.timing_live,
        s.name AS series_name,
        (SELECT COUNT(*) FROM event_registrations er
         WHERE er.event_id = e.id AND er.status != 'cancelled') AS registered_count
    FROM events e
    LEFT JOIN series_events se ON se.event_id = e.id
    LEFT JOIN series s ON se.series_id = s.id
    WHERE e.active = 1 AND $whereClause
    GROUP BY e.id
    ORDER BY e.date ASC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$events = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch classes for each event
$classStmt = $pdo->prepare("
    SELECT c.id, c.name, c.display_name
    FROM classes c
    JOIN event_classes ec ON ec.class_id = c.id
    WHERE ec.event_id = ?
    ORDER BY c.name
");

$result = [];
foreach ($events as $event) {
    $classStmt->execute([$event['id']]);
    $classes = $classStmt->fetchAll(PDO::FETCH_ASSOC);

    $stageNames = $event['stage_names'] ? json_decode($event['stage_names'], true) : null;
    $stageCount = $stageNames ? count($stageNames) : 0;

    $result[] = [
        'id' => (int)$event['id'],
        'name' => $event['name'],
        'date' => $event['date'],
        'location' => $event['location'],
        'discipline' => $event['discipline'],
        'event_format' => $event['event_format'],
        'series_name' => $event['series_name'],
        'max_participants' => $event['max_participants'] ? (int)$event['max_participants'] : null,
        'registered_count' => (int)$event['registered_count'],
        'timing_live' => (bool)$event['timing_live'],
        'classes' => $classes,
        'stage_names' => $stageNames,
        'stage_count' => $stageCount
    ];
}

logApiRequest($pdo, $auth['id'], '/api/v1/events', 'GET', null, 200);

apiResponse([
    'success' => true,
    'events' => $result,
    'total_count' => count($result)
]);

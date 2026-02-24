<?php
/**
 * GravityTiming API - Classes endpoint
 * GET /api/v1/event-classes.php?event_id=42 - Hämta klasser för ett event
 */
require_once __DIR__ . '/auth-middleware.php';

$auth = validateApiRequest('readonly');
$pdo = $GLOBALS['pdo'];

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    apiError('Metoden stöds inte. Använd GET.', 405);
}

$eventId = getEventIdFromPath() ?? (isset($_GET['event_id']) ? (int)$_GET['event_id'] : 0);
if (!$eventId) {
    apiError('event_id krävs', 400);
}

if (!checkEventAccess($auth, $eventId)) {
    apiError('Ingen åtkomst till detta event', 403);
}

// Verify event exists
$stmt = $pdo->prepare("SELECT id FROM events WHERE id = ? AND active = 1");
$stmt->execute([$eventId]);
if (!$stmt->fetch()) {
    apiError('Event hittades inte', 404);
}

// Fetch classes with participant counts and bib ranges
$stmt = $pdo->prepare("
    SELECT
        c.id,
        c.name,
        c.display_name,
        COUNT(er.id) AS participant_count,
        MIN(er.bib_number) AS bib_min,
        MAX(er.bib_number) AS bib_max
    FROM classes c
    JOIN event_classes ec ON ec.class_id = c.id AND ec.event_id = ?
    LEFT JOIN event_registrations er ON er.class_id = c.id AND er.event_id = ? AND er.status != 'cancelled'
    GROUP BY c.id
    ORDER BY c.name
");
$stmt->execute([$eventId, $eventId]);
$classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

$result = [];
foreach ($classes as $cls) {
    $result[] = [
        'id' => (int)$cls['id'],
        'name' => $cls['name'],
        'display_name' => $cls['display_name'],
        'participant_count' => (int)$cls['participant_count'],
        'bib_range' => [
            'min' => $cls['bib_min'] ? (int)$cls['bib_min'] : null,
            'max' => $cls['bib_max'] ? (int)$cls['bib_max'] : null
        ]
    ];
}

logApiRequest($pdo, $auth['id'], '/api/v1/events/' . $eventId . '/classes', 'GET', $eventId, 200);

apiResponse([
    'success' => true,
    'classes' => $result
]);

<?php
/**
 * GravityTiming API - Startlist endpoint
 * GET /api/v1/event-startlist.php?event_id=42 - Hämta startlista för ett event
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

// Fetch event info
$stmt = $pdo->prepare("
    SELECT id, name, date, discipline, event_format, stage_names, timing_live, location
    FROM events WHERE id = ? AND active = 1
");
$stmt->execute([$eventId]);
$event = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$event) {
    apiError('Event hittades inte', 404);
}

$stageNames = $event['stage_names'] ? json_decode($event['stage_names'], true) : null;

// Fetch participants - only paid, non-cancelled
$stmt = $pdo->prepare("
    SELECT
        er.id AS registration_id,
        er.rider_id,
        er.bib_number,
        er.class_id,
        er.category,
        r.firstname AS first_name,
        r.lastname AS last_name,
        r.birth_year,
        r.gender,
        r.nationality,
        r.license_number,
        r.license_type,
        c.name AS club_name,
        c.id AS club_id,
        cl.name AS class_name
    FROM event_registrations er
    JOIN riders r ON r.id = er.rider_id
    LEFT JOIN clubs c ON c.id = r.club_id
    LEFT JOIN classes cl ON cl.id = er.class_id
    WHERE er.event_id = ?
      AND er.status != 'cancelled'
    ORDER BY er.class_id, er.bib_number, r.lastname
");
$stmt->execute([$eventId]);
$participants = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Format participants
$formatted = [];
foreach ($participants as $p) {
    $formatted[] = [
        'registration_id' => (int)$p['registration_id'],
        'rider_id' => (int)$p['rider_id'],
        'bib_number' => $p['bib_number'] ? (int)$p['bib_number'] : null,
        'first_name' => $p['first_name'],
        'last_name' => $p['last_name'],
        'birth_year' => $p['birth_year'] ? (int)$p['birth_year'] : null,
        'gender' => $p['gender'],
        'nationality' => $p['nationality'],
        'club_name' => $p['club_name'],
        'club_id' => $p['club_id'] ? (int)$p['club_id'] : null,
        'class_name' => $p['class_name'] ?? $p['category'],
        'class_id' => $p['class_id'] ? (int)$p['class_id'] : null,
        'category' => $p['category'],
        'license_number' => $p['license_number'],
        'license_type' => $p['license_type']
    ];
}

logApiRequest($pdo, $auth['id'], '/api/v1/events/' . $eventId . '/startlist', 'GET', $eventId, 200);

apiResponse([
    'success' => true,
    'event' => [
        'id' => (int)$event['id'],
        'name' => $event['name'],
        'date' => $event['date'],
        'location' => $event['location'],
        'discipline' => $event['discipline'],
        'event_format' => $event['event_format'],
        'stage_names' => $stageNames,
        'stage_count' => $stageNames ? count($stageNames) : 0
    ],
    'participants' => $formatted,
    'total_count' => count($formatted)
]);

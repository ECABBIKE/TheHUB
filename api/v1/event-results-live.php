<?php
/**
 * GravityTiming API - Live split time endpoint
 * POST /api/v1/event-results-live.php?event_id=42
 *
 * Skicka EN split time åt gången för live-uppdatering under tävling.
 */
require_once __DIR__ . '/auth-middleware.php';

$auth = validateApiRequest('timing');
$pdo = $GLOBALS['pdo'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    apiError('Metoden stöds inte. Använd POST.', 405);
}

$eventId = getEventIdFromPath() ?? (isset($_GET['event_id']) ? (int)$_GET['event_id'] : 0);
if (!$eventId) {
    apiError('event_id krävs', 400);
}

if (!checkEventAccess($auth, $eventId)) {
    apiError('Ingen åtkomst till detta event', 403);
}

// Verify event
$stmt = $pdo->prepare("SELECT id, name FROM events WHERE id = ? AND active = 1");
$stmt->execute([$eventId]);
$event = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$event) {
    apiError('Event hittades inte', 404);
}

$body = getJsonBody();
$bibNumber = isset($body['bib_number']) ? (int)$body['bib_number'] : 0;
$stage = $body['stage'] ?? '';
$time = $body['time'] ?? '';

if (!$bibNumber) {
    apiError('bib_number krävs', 400);
}
if (!$stage || !preg_match('/^ss([1-9]|1[0-5])$/', $stage)) {
    apiError('stage krävs (ss1-ss15)', 400);
}
if (!$time) {
    apiError('time krävs', 400);
}

// Look up rider from bib_number
$stmt = $pdo->prepare("
    SELECT er.rider_id, er.class_id, r.firstname, r.lastname
    FROM event_registrations er
    JOIN riders r ON r.id = er.rider_id
    WHERE er.event_id = ? AND er.bib_number = ? AND er.status != 'cancelled'
    LIMIT 1
");
$stmt->execute([$eventId, $bibNumber]);
$reg = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$reg) {
    apiError("Startnummer $bibNumber hittades inte i event $eventId", 404);
}

$riderId = (int)$reg['rider_id'];
$classId = $reg['class_id'] ? (int)$reg['class_id'] : null;
$riderName = $reg['firstname'] . ' ' . $reg['lastname'];

// Check if result row exists
$stmt = $pdo->prepare("SELECT id FROM results WHERE event_id = ? AND cyclist_id = ?");
$stmt->execute([$eventId, $riderId]);
$existing = $stmt->fetch(PDO::FETCH_ASSOC);

if ($existing) {
    // Update the specific split time column
    $sql = "UPDATE results SET $stage = ?, bib_number = ? WHERE id = ?";
    $pdo->prepare($sql)->execute([$time, $bibNumber, $existing['id']]);
    $resultId = (int)$existing['id'];
} else {
    // Create new result row with this split time
    $sql = "INSERT INTO results (event_id, cyclist_id, class_id, bib_number, status, $stage) VALUES (?, ?, ?, ?, 'FIN', ?)";
    $pdo->prepare($sql)->execute([$eventId, $riderId, $classId, $bibNumber, $time]);
    $resultId = (int)$pdo->lastInsertId();
}

// Set timing_live flag
$pdo->prepare("UPDATE events SET timing_live = 1 WHERE id = ? AND timing_live = 0")->execute([$eventId]);

// Calculate stage position (rank among all riders for this stage in this event)
$stagePosition = null;
$posStmt = $pdo->prepare("
    SELECT COUNT(*) + 1 FROM results
    WHERE event_id = ? AND class_id = ? AND $stage IS NOT NULL AND $stage < ? AND id != ?
");
$posStmt->execute([$eventId, $classId, $time, $resultId]);
$stagePosition = (int)$posStmt->fetchColumn();

logApiRequest($pdo, $auth['id'], '/api/v1/events/' . $eventId . '/results/live', 'POST', $eventId, 200);

apiResponse([
    'success' => true,
    'result_id' => $resultId,
    'rider' => $riderName,
    'bib_number' => $bibNumber,
    'stage' => $stage,
    'time' => $time,
    'stage_position' => $stagePosition
]);

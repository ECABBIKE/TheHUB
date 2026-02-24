<?php
/**
 * GravityTiming API - Results status endpoint
 * GET /api/v1/event-results-status.php?event_id=42
 *
 * Lightweight endpoint for polling - returns last_updated timestamp,
 * result count, and stage progress.
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

// Verify event
$stmt = $pdo->prepare("SELECT id, name, timing_live, stage_names FROM events WHERE id = ? AND active = 1");
$stmt->execute([$eventId]);
$event = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$event) {
    apiError('Event hittades inte', 404);
}

// Get result stats
$stmt = $pdo->prepare("SELECT COUNT(*) FROM results WHERE event_id = ?");
$stmt->execute([$eventId]);
$resultCount = (int)$stmt->fetchColumn();

// Get latest result timestamp
$stmt = $pdo->prepare("SELECT MAX(created_at) FROM results WHERE event_id = ?");
$stmt->execute([$eventId]);
$lastUpdated = $stmt->fetchColumn() ?: null;

// Detect which stages have data
$stagesCompleted = [];
$stagesInProgress = [];
$stageNames = $event['stage_names'] ? json_decode($event['stage_names'], true) : null;

for ($s = 1; $s <= 15; $s++) {
    $col = "ss$s";
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM results WHERE event_id = ? AND $col IS NOT NULL");
    $stmt->execute([$eventId]);
    $count = (int)$stmt->fetchColumn();

    if ($count > 0) {
        // Count total expected (registered riders)
        $totalStmt = $pdo->prepare("SELECT COUNT(*) FROM event_registrations WHERE event_id = ? AND status != 'cancelled'");
        $totalStmt->execute([$eventId]);
        $total = (int)$totalStmt->fetchColumn();

        if ($count >= $total * 0.9) {
            $stagesCompleted[] = $col;
        } else {
            $stagesInProgress[] = $col;
        }
    }
}

// Optional: return results updated since a timestamp
$since = $_GET['since'] ?? null;
$latestResults = [];
if ($since) {
    $stmt = $pdo->prepare("
        SELECT r.id, r.cyclist_id, r.bib_number, r.class_id, r.position, r.finish_time, r.status,
               r.ss1, r.ss2, r.ss3, r.ss4, r.ss5, r.ss6, r.ss7, r.ss8,
               r.ss9, r.ss10, r.ss11, r.ss12, r.ss13, r.ss14, r.ss15,
               r.created_at,
               ri.firstname, ri.lastname,
               cl.name AS class_name
        FROM results r
        JOIN riders ri ON ri.id = r.cyclist_id
        LEFT JOIN classes cl ON cl.id = r.class_id
        WHERE r.event_id = ? AND r.created_at > ?
        ORDER BY r.class_id, r.position
    ");
    $stmt->execute([$eventId, $since]);
    $latestResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

logApiRequest($pdo, $auth['id'], '/api/v1/events/' . $eventId . '/results/status', 'GET', $eventId, 200);

$response = [
    'success' => true,
    'event_id' => (int)$eventId,
    'last_updated' => $lastUpdated,
    'result_count' => $resultCount,
    'stages_completed' => $stagesCompleted,
    'stages_in_progress' => $stagesInProgress,
    'is_live' => (bool)$event['timing_live']
];

if (!empty($latestResults)) {
    $response['results'] = $latestResults;
}

apiResponse($response);

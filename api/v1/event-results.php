<?php
/**
 * GravityTiming API - Results endpoint
 * POST /api/v1/event-results.php?event_id=42   - Ladda upp/uppdatera resultat (batch)
 * DELETE /api/v1/event-results.php?event_id=42&mode=all - Rensa alla resultat
 * PATCH /api/v1/event-results.php?event_id=42&result_id=123 - Uppdatera enstaka resultat
 */
require_once __DIR__ . '/auth-middleware.php';
require_once __DIR__ . '/../../includes/point-calculations.php';

$auth = validateApiRequest('timing');
$pdo = $GLOBALS['pdo'];

$eventId = getEventIdFromPath() ?? (isset($_GET['event_id']) ? (int)$_GET['event_id'] : 0);
if (!$eventId) {
    apiError('event_id krävs', 400);
}

if (!checkEventAccess($auth, $eventId)) {
    apiError('Ingen åtkomst till detta event', 403);
}

// Verify event exists
$stmt = $pdo->prepare("SELECT id, name, stage_names FROM events WHERE id = ? AND active = 1");
$stmt->execute([$eventId]);
$event = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$event) {
    apiError('Event hittades inte', 404);
}

$method = $_SERVER['REQUEST_METHOD'];

// ============================================================================
// POST - Batch upload results
// ============================================================================
if ($method === 'POST') {
    $body = getJsonBody();
    $results = $body['results'] ?? [];
    $mode = $body['mode'] ?? 'upsert'; // upsert, replace, append

    if (empty($results)) {
        apiError('Inga resultat skickade. Skicka "results" array.', 400);
    }

    if (!in_array($mode, ['upsert', 'replace', 'append'])) {
        apiError('Ogiltigt mode. Använd: upsert, replace, append', 400);
    }

    // Pre-load bib->rider mapping for this event
    $bibStmt = $pdo->prepare("
        SELECT er.bib_number, er.rider_id, er.class_id, er.category
        FROM event_registrations er
        WHERE er.event_id = ? AND er.status != 'cancelled'
    ");
    $bibStmt->execute([$eventId]);
    $bibMap = [];
    while ($row = $bibStmt->fetch(PDO::FETCH_ASSOC)) {
        if ($row['bib_number']) {
            $bibMap[(int)$row['bib_number']] = $row;
        }
    }

    // Pre-load class name->id mapping
    $classStmt = $pdo->prepare("
        SELECT c.id, c.name FROM classes c
        JOIN event_classes ec ON ec.class_id = c.id
        WHERE ec.event_id = ?
    ");
    $classStmt->execute([$eventId]);
    $classMap = [];
    while ($row = $classStmt->fetch(PDO::FETCH_ASSOC)) {
        $classMap[strtolower($row['name'])] = (int)$row['id'];
    }

    // If replace mode, delete existing results first
    if ($mode === 'replace') {
        $pdo->prepare("DELETE FROM results WHERE event_id = ?")->execute([$eventId]);
    }

    $imported = 0;
    $updated = 0;
    $errors = [];
    $resultDetails = [];

    foreach ($results as $i => $r) {
        $bibNumber = isset($r['bib_number']) ? (int)$r['bib_number'] : null;
        $riderId = isset($r['rider_id']) ? (int)$r['rider_id'] : null;
        $className = $r['class_name'] ?? null;
        $position = isset($r['position']) ? (int)$r['position'] : null;
        $finishTime = $r['finish_time'] ?? null;
        $status = $r['status'] ?? 'FIN';
        $splitTimes = $r['split_times'] ?? [];

        // Resolve rider from bib_number
        if (!$riderId && $bibNumber && isset($bibMap[$bibNumber])) {
            $riderId = (int)$bibMap[$bibNumber]['rider_id'];
        }

        if (!$riderId) {
            $errors[] = ['index' => $i, 'bib_number' => $bibNumber, 'error' => 'Kunde inte matcha åkare (bib_number ej registrerat)'];
            continue;
        }

        // Resolve class_id
        $classId = null;
        if ($bibNumber && isset($bibMap[$bibNumber])) {
            $classId = (int)$bibMap[$bibNumber]['class_id'];
        }
        if (!$classId && $className) {
            $classId = $classMap[strtolower($className)] ?? null;
        }

        // Check if result already exists
        $existStmt = $pdo->prepare("SELECT id FROM results WHERE event_id = ? AND cyclist_id = ?");
        $existStmt->execute([$eventId, $riderId]);
        $existing = $existStmt->fetch(PDO::FETCH_ASSOC);

        if ($existing && $mode === 'append') {
            $resultDetails[] = ['bib_number' => $bibNumber, 'status' => 'skipped', 'result_id' => (int)$existing['id']];
            continue;
        }

        // Build split time columns (ss1-ss15)
        $ssColumns = [];
        $ssValues = [];
        for ($s = 1; $s <= 15; $s++) {
            $key = "ss$s";
            if (isset($splitTimes[$key])) {
                $ssColumns[] = $key;
                $ssValues[] = $splitTimes[$key];
            }
        }

        if ($existing) {
            // UPDATE
            $setClauses = [
                'position = ?', 'finish_time = ?', 'status = ?',
                'bib_number = ?', 'class_id = ?'
            ];
            $updateParams = [$position, $finishTime, $status, $bibNumber, $classId];

            foreach ($ssColumns as $idx => $col) {
                $setClauses[] = "$col = ?";
                $updateParams[] = $ssValues[$idx];
            }

            $updateParams[] = $existing['id'];
            $sql = "UPDATE results SET " . implode(', ', $setClauses) . " WHERE id = ?";
            $pdo->prepare($sql)->execute($updateParams);

            $updated++;
            $resultDetails[] = ['bib_number' => $bibNumber, 'status' => 'updated', 'result_id' => (int)$existing['id']];
        } else {
            // INSERT
            $cols = ['event_id', 'cyclist_id', 'class_id', 'position', 'finish_time', 'status', 'bib_number'];
            $vals = [$eventId, $riderId, $classId, $position, $finishTime, $status, $bibNumber];

            foreach ($ssColumns as $idx => $col) {
                $cols[] = $col;
                $vals[] = $ssValues[$idx];
            }

            $placeholders = implode(', ', array_fill(0, count($vals), '?'));
            $sql = "INSERT INTO results (" . implode(', ', $cols) . ") VALUES ($placeholders)";
            $pdo->prepare($sql)->execute($vals);

            $resultId = (int)$pdo->lastInsertId();
            $imported++;
            $resultDetails[] = ['bib_number' => $bibNumber, 'status' => 'created', 'result_id' => $resultId];
        }
    }

    // Recalculate points
    try {
        $db = getDB();
        recalculateEventResults($db, $eventId);
    } catch (Exception $e) {
        error_log("Point recalculation failed for event $eventId: " . $e->getMessage());
    }

    // Set timing_live flag
    $pdo->prepare("UPDATE events SET timing_live = 1 WHERE id = ?")->execute([$eventId]);

    logApiRequest($pdo, $auth['id'], '/api/v1/events/' . $eventId . '/results', 'POST', $eventId, 200, strlen(file_get_contents('php://input') ?: ''));

    apiResponse([
        'success' => true,
        'imported' => $imported,
        'updated' => $updated,
        'errors' => $errors,
        'results' => $resultDetails
    ]);
}

// ============================================================================
// DELETE - Clear all results for event
// ============================================================================
elseif ($method === 'DELETE') {
    $deleteMode = $_GET['mode'] ?? '';
    if ($deleteMode !== 'all') {
        apiError('Säkerhetsvalidering: Skicka ?mode=all för att radera alla resultat', 400);
    }

    $stmt = $pdo->prepare("DELETE FROM results WHERE event_id = ?");
    $stmt->execute([$eventId]);
    $deleted = $stmt->rowCount();

    // Reset timing_live
    $pdo->prepare("UPDATE events SET timing_live = 0 WHERE id = ?")->execute([$eventId]);

    logApiRequest($pdo, $auth['id'], '/api/v1/events/' . $eventId . '/results', 'DELETE', $eventId, 200);

    apiResponse([
        'success' => true,
        'deleted' => $deleted,
        'message' => "Alla $deleted resultat raderade för event $eventId"
    ]);
}

// ============================================================================
// PATCH - Update single result
// ============================================================================
elseif ($method === 'PATCH') {
    $resultId = isset($_GET['result_id']) ? (int)$_GET['result_id'] : 0;
    if (!$resultId) {
        apiError('result_id krävs som query-parameter', 400);
    }

    // Verify result belongs to this event
    $stmt = $pdo->prepare("SELECT id FROM results WHERE id = ? AND event_id = ?");
    $stmt->execute([$resultId, $eventId]);
    if (!$stmt->fetch()) {
        apiError('Resultat hittades inte i detta event', 404);
    }

    $body = getJsonBody();

    $allowedFields = [
        'position', 'finish_time', 'status', 'bib_number',
        'ss1', 'ss2', 'ss3', 'ss4', 'ss5', 'ss6', 'ss7', 'ss8',
        'ss9', 'ss10', 'ss11', 'ss12', 'ss13', 'ss14', 'ss15'
    ];

    $setClauses = [];
    $params = [];
    foreach ($body as $key => $value) {
        if (in_array($key, $allowedFields)) {
            $setClauses[] = "$key = ?";
            $params[] = $value;
        }
    }

    if (empty($setClauses)) {
        apiError('Inga giltiga fält att uppdatera', 400);
    }

    $params[] = $resultId;
    $sql = "UPDATE results SET " . implode(', ', $setClauses) . " WHERE id = ?";
    $pdo->prepare($sql)->execute($params);

    // Recalculate points
    try {
        $db = getDB();
        recalculateEventResults($db, $eventId);
    } catch (Exception $e) {
        error_log("Point recalculation failed: " . $e->getMessage());
    }

    logApiRequest($pdo, $auth['id'], '/api/v1/events/' . $eventId . '/results/' . $resultId, 'PATCH', $eventId, 200);

    apiResponse([
        'success' => true,
        'result_id' => $resultId,
        'updated_fields' => array_keys(array_intersect_key($body, array_flip($allowedFields)))
    ]);
}

else {
    apiError('Metoden stöds inte. Använd POST, DELETE eller PATCH.', 405);
}

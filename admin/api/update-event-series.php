<?php
/**
 * API endpoint to update event series
 */

require_once __DIR__ . '/../../config.php';
require_admin();

header('Content-Type: application/json');

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    echo json_encode(['success' => false, 'error' => 'Invalid JSON input']);
    exit;
}

// Verify CSRF token
if (empty($input['csrf_token']) || !verify_csrf_token($input['csrf_token'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$eventId = isset($input['event_id']) ? intval($input['event_id']) : 0;
$seriesId = isset($input['series_id']) && $input['series_id'] !== '' ? intval($input['series_id']) : null;

if (!$eventId) {
    echo json_encode(['success' => false, 'error' => 'Event ID required']);
    exit;
}

try {
    global $pdo;

    // Get old series_id before update
    $stmt = $pdo->prepare("SELECT series_id FROM events WHERE id = ?");
    $stmt->execute([$eventId]);
    $oldSeriesId = $stmt->fetchColumn();

    // Update the event series_id
    $stmt = $pdo->prepare("UPDATE events SET series_id = ? WHERE id = ?");
    $stmt->execute([$seriesId, $eventId]);

    // Sync series_events junction table
    // Remove from old series if it changed
    if ($oldSeriesId && $oldSeriesId != $seriesId) {
        $stmt = $pdo->prepare("DELETE FROM series_events WHERE event_id = ? AND series_id = ?");
        $stmt->execute([$eventId, $oldSeriesId]);
    }

    // Add to new series if set and not already there
    if ($seriesId) {
        $stmt = $pdo->prepare("SELECT id FROM series_events WHERE event_id = ? AND series_id = ?");
        $stmt->execute([$eventId, $seriesId]);
        $existing = $stmt->fetch();

        if (!$existing) {
            // Get next sort_order
            $stmt = $pdo->prepare("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM series_events WHERE series_id = ?");
            $stmt->execute([$seriesId]);
            $nextOrder = $stmt->fetchColumn();

            $stmt = $pdo->prepare("INSERT INTO series_events (series_id, event_id, template_id, sort_order) VALUES (?, ?, NULL, ?)");
            $stmt->execute([$seriesId, $eventId, $nextOrder]);
        }
    } elseif (!$seriesId && $oldSeriesId) {
        // Series removed - also remove from series_events
        $stmt = $pdo->prepare("DELETE FROM series_events WHERE event_id = ? AND series_id = ?");
        $stmt->execute([$eventId, $oldSeriesId]);
    }

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    error_log("Series update error: " . $e->getMessage() . " | Event ID: $eventId | Series ID: " . var_export($seriesId, true));
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

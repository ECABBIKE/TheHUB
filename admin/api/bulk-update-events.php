<?php
/**
 * API endpoint to bulk update events
 * Handles multiple event updates in a single request
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

$changes = isset($input['changes']) ? $input['changes'] : [];

if (empty($changes)) {
    echo json_encode(['success' => false, 'error' => 'No changes provided']);
    exit;
}

// Valid discipline values
$validDisciplines = ['', 'ENDURO', 'DH', 'XC', 'XCO', 'XCM', 'DUAL_SLALOM', 'PUMPTRACK', 'GRAVEL', 'E-MTB'];

try {
    global $pdo;

    $pdo->beginTransaction();

    $updateCount = 0;
    $errors = [];

    foreach ($changes as $eventId => $fields) {
        $eventId = intval($eventId);

        if ($eventId <= 0) {
            $errors[] = "Invalid event ID: $eventId";
            continue;
        }

        foreach ($fields as $fieldName => $value) {
            try {
                switch ($fieldName) {
                    case 'series_id':
                        $seriesId = ($value !== '' && $value !== null) ? intval($value) : null;
                        $stmt = $pdo->prepare("UPDATE events SET series_id = ? WHERE id = ?");
                        $stmt->execute([$seriesId, $eventId]);
                        $updateCount++;
                        break;

                    case 'discipline':
                        if (!in_array($value, $validDisciplines)) {
                            $errors[] = "Invalid discipline value for event $eventId: $value";
                            continue 2;
                        }
                        $discipline = $value ?: null;
                        $stmt = $pdo->prepare("UPDATE events SET discipline = ? WHERE id = ?");
                        $stmt->execute([$discipline, $eventId]);
                        $updateCount++;
                        break;

                    default:
                        $errors[] = "Unknown field: $fieldName for event $eventId";
                }
            } catch (Exception $e) {
                $errors[] = "Error updating event $eventId field $fieldName: " . $e->getMessage();
            }
        }
    }

    if (!empty($errors)) {
        $pdo->rollBack();
        echo json_encode([
            'success' => false,
            'error' => 'Some updates failed: ' . implode(', ', $errors),
            'errors' => $errors
        ]);
        exit;
    }

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'updated' => $updateCount,
        'message' => "$updateCount updates applied successfully"
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Bulk update error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

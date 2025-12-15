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

// Valid field values
$validDisciplines = ['', 'ENDURO', 'DH', 'XC', 'XCO', 'XCM', 'DUAL_SLALOM', 'PUMPTRACK', 'GRAVEL', 'E-MTB'];
$validEventLevels = ['', 'Nationell (100%)', 'Sportmotion (50%)'];
$validEventFormats = ['', 'Enduro (en tid)', 'Downhill Standard', 'SweCUP Downhill', 'Dual Slalom'];

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

                    case 'event_level':
                        if (!in_array($value, $validEventLevels)) {
                            $errors[] = "Invalid event_level value for event $eventId: $value";
                            continue 2;
                        }
                        $eventLevel = $value ?: null;
                        $stmt = $pdo->prepare("UPDATE events SET event_level = ? WHERE id = ?");
                        $stmt->execute([$eventLevel, $eventId]);
                        $updateCount++;
                        break;

                    case 'event_format':
                        if (!in_array($value, $validEventFormats)) {
                            $errors[] = "Invalid event_format value for event $eventId: $value";
                            continue 2;
                        }
                        $eventFormat = $value ?: null;
                        $stmt = $pdo->prepare("UPDATE events SET event_format = ? WHERE id = ?");
                        $stmt->execute([$eventFormat, $eventId]);
                        $updateCount++;
                        break;

                    case 'pricing_template_id':
                        $pricingTemplateId = ($value !== '' && $value !== null) ? intval($value) : null;
                        $stmt = $pdo->prepare("UPDATE events SET pricing_template_id = ? WHERE id = ?");
                        $stmt->execute([$pricingTemplateId, $eventId]);
                        $updateCount++;
                        break;

                    case 'advent_id':
                        $adventId = trim($value);
                        $stmt = $pdo->prepare("UPDATE events SET advent_id = ? WHERE id = ?");
                        $stmt->execute([$adventId, $eventId]);
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

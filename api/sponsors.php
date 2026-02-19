<?php
/**
 * Sponsors API
 * Handles CRUD operations for sponsors
 *
 * Actions:
 *   GET    ?action=get&id=X     - Get single sponsor with logos and series
 *   GET    ?action=list         - List all sponsors (optional: ?tier=X&active=1)
 *   POST   ?action=create       - Create new sponsor (JSON body)
 *   PUT    ?action=update       - Update sponsor (JSON body with id)
 *   DELETE ?action=delete&id=X  - Delete sponsor
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/sponsor-functions.php';

// Require admin login for all sponsor API operations
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Ej inloggad']);
    exit;
}

global $pdo;

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($action) {

        // =============================================
        // GET single sponsor
        // =============================================
        case 'get':
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) {
                echo json_encode(['success' => false, 'error' => 'ID saknas']);
                exit;
            }

            $sponsor = get_sponsor_with_logos($id);
            if (!$sponsor) {
                echo json_encode(['success' => false, 'error' => 'Sponsor hittades inte']);
                exit;
            }

            echo json_encode(['success' => true, 'data' => $sponsor]);
            break;

        // =============================================
        // LIST sponsors
        // =============================================
        case 'list':
            $tier = $_GET['tier'] ?? null;
            $activeOnly = !isset($_GET['active']) || $_GET['active'] === '1';
            $sponsors = get_sponsors($activeOnly, $tier);
            echo json_encode(['success' => true, 'data' => $sponsors]);
            break;

        // =============================================
        // FIND OR CREATE sponsor by media image
        // Used by promotor event-edit: pick image → auto-get sponsor
        // =============================================
        case 'find_or_create_by_media':
            $mediaId = (int)($_GET['media_id'] ?? 0);
            if (!$mediaId) {
                echo json_encode(['success' => false, 'error' => 'media_id krävs']);
                exit;
            }

            // Check if a sponsor already uses this media
            $stmt = $pdo->prepare("
                SELECT id, name FROM sponsors
                WHERE (logo_media_id = ? OR logo_banner_id = ?) AND active = 1
                LIMIT 1
            ");
            $stmt->execute([$mediaId, $mediaId]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                echo json_encode(['success' => true, 'data' => $existing]);
                exit;
            }

            // Get media info for auto-naming
            $mediaStmt = $pdo->prepare("SELECT original_filename FROM media WHERE id = ?");
            $mediaStmt->execute([$mediaId]);
            $mediaInfo = $mediaStmt->fetch(PDO::FETCH_ASSOC);

            if (!$mediaInfo) {
                echo json_encode(['success' => false, 'error' => 'Bilden hittades inte']);
                exit;
            }

            // Create sponsor with filename as name
            $autoName = pathinfo($mediaInfo['original_filename'], PATHINFO_FILENAME);
            $autoName = ucfirst(str_replace(['-', '_'], ' ', $autoName));

            $result = create_sponsor([
                'name' => $autoName,
                'tier' => 'bronze',
                'active' => true,
                'logo_media_id' => $mediaId,
                'logo_banner_id' => $mediaId,
            ]);

            if ($result['success']) {
                echo json_encode([
                    'success' => true,
                    'data' => ['id' => $result['id'], 'name' => $autoName],
                    'created' => true
                ]);
            } else {
                echo json_encode($result);
            }
            break;

        // =============================================
        // CREATE sponsor
        // =============================================
        case 'create':
            if ($method !== 'POST') {
                http_response_code(405);
                echo json_encode(['success' => false, 'error' => 'Använd POST']);
                exit;
            }

            $data = json_decode(file_get_contents('php://input'), true);
            if (!$data || empty($data['name'])) {
                echo json_encode(['success' => false, 'error' => 'Namn krävs']);
                exit;
            }

            $result = create_sponsor($data);
            echo json_encode($result);
            break;

        // =============================================
        // UPDATE sponsor
        // =============================================
        case 'update':
            if ($method !== 'PUT' && $method !== 'POST') {
                http_response_code(405);
                echo json_encode(['success' => false, 'error' => 'Använd PUT eller POST']);
                exit;
            }

            $data = json_decode(file_get_contents('php://input'), true);
            if (!$data || empty($data['id'])) {
                echo json_encode(['success' => false, 'error' => 'ID saknas']);
                exit;
            }

            $id = (int)$data['id'];
            unset($data['id']);

            $result = update_sponsor($id, $data);
            echo json_encode($result);
            break;

        // =============================================
        // DELETE sponsor
        // =============================================
        case 'delete':
            if ($method !== 'DELETE' && $method !== 'POST') {
                http_response_code(405);
                echo json_encode(['success' => false, 'error' => 'Använd DELETE']);
                exit;
            }

            $id = (int)($_GET['id'] ?? 0);
            if (!$id) {
                echo json_encode(['success' => false, 'error' => 'ID saknas']);
                exit;
            }

            $result = delete_sponsor($id);
            echo json_encode($result);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Okänd action: ' . $action]);
            break;
    }
} catch (Exception $e) {
    error_log("Sponsors API error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Serverfel: ' . $e->getMessage()]);
}

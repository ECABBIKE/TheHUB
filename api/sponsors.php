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

<?php
/**
 * Media API Endpoint
 * TheHUB V3 - Media & Sponsor System
 *
 * Handles: upload, delete, update, list, search
 */

header('Content-Type: application/json; charset=utf-8');

// Load configuration
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/media-functions.php';

// Check authentication - only admins can manage media
if (!hub_is_logged_in() || !hub_is_admin()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Åtkomst nekad']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

try {
    switch ($method) {
        case 'GET':
            handleGet($action);
            break;

        case 'POST':
            handlePost($action);
            break;

        case 'DELETE':
            handleDelete();
            break;

        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Metod ej tillåten']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

/**
 * Handle GET requests
 */
function handleGet(string $action): void {
    switch ($action) {
        case 'list':
            // List media files
            $folder = $_GET['folder'] ?? null;
            $limit = min((int) ($_GET['limit'] ?? 50), 100);
            $offset = (int) ($_GET['offset'] ?? 0);

            $media = get_media_by_folder($folder, $limit, $offset);
            $total = get_media_count($folder);

            echo json_encode([
                'success' => true,
                'data' => $media,
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset
            ]);
            break;

        case 'get':
            // Get single media item
            $id = (int) ($_GET['id'] ?? 0);
            if (!$id) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'ID saknas']);
                return;
            }

            $media = get_media($id);
            if (!$media) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Media ej funnen']);
                return;
            }

            // Add URL and usage info
            $media['url'] = get_media_url($id);
            $media['thumbnail'] = get_media_thumbnail($id, 'medium');
            $media['usage'] = get_media_usage($id);
            $media['in_use'] = !empty($media['usage']);

            echo json_encode(['success' => true, 'data' => $media]);
            break;

        case 'search':
            // Search media
            $query = $_GET['q'] ?? '';
            $folder = $_GET['folder'] ?? null;
            $limit = min((int) ($_GET['limit'] ?? 50), 100);

            if (strlen($query) < 2) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Sökterm måste vara minst 2 tecken']);
                return;
            }

            $media = search_media($query, $folder, $limit);
            echo json_encode(['success' => true, 'data' => $media]);
            break;

        case 'stats':
            // Get folder statistics
            $stats = get_media_stats();
            echo json_encode(['success' => true, 'data' => $stats]);
            break;

        case 'folders':
            // Get available folders
            $folders = [
                ['id' => 'general', 'name' => 'Allmänt', 'icon' => 'folder'],
                ['id' => 'series', 'name' => 'Serier', 'icon' => 'trophy'],
                ['id' => 'sponsors', 'name' => 'Sponsorer', 'icon' => 'handshake'],
                ['id' => 'ads', 'name' => 'Annonser', 'icon' => 'megaphone'],
                ['id' => 'clubs', 'name' => 'Klubbar', 'icon' => 'users'],
                ['id' => 'events', 'name' => 'Event', 'icon' => 'calendar']
            ];

            // Add counts
            $stats = get_media_stats();
            $statsByFolder = array_column($stats, null, 'folder');

            foreach ($folders as &$folder) {
                $folder['count'] = $statsByFolder[$folder['id']]['count'] ?? 0;
                $folder['size'] = format_file_size($statsByFolder[$folder['id']]['total_size'] ?? 0);
            }

            echo json_encode(['success' => true, 'data' => $folders]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Ogiltig action']);
    }
}

/**
 * Handle POST requests
 */
function handlePost(string $action): void {
    switch ($action) {
        case 'upload':
            handleUpload();
            break;

        case 'update':
            handleUpdate();
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Ogiltig action']);
    }
}

/**
 * Handle file upload
 */
function handleUpload(): void {
    if (empty($_FILES['file'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Ingen fil vald']);
        return;
    }

    $folder = $_POST['folder'] ?? 'general';
    $altText = $_POST['alt_text'] ?? null;
    $caption = $_POST['caption'] ?? null;

    // Validate folder
    $validFolders = ['general', 'series', 'sponsors', 'ads', 'clubs', 'events'];
    if (!in_array($folder, $validFolders)) {
        $folder = 'general';
    }

    // Get current admin user ID
    $currentUser = hub_current_user();
    $uploadedBy = $currentUser['id'] ?? null;

    $metadata = [];
    if ($altText) $metadata['alt_text'] = $altText;
    if ($caption) $metadata['caption'] = $caption;

    $result = upload_media($_FILES['file'], $folder, $uploadedBy, $metadata);

    if ($result['success']) {
        $media = get_media($result['media_id']);
        $media['url'] = get_media_url($result['media_id']);
        $media['thumbnail'] = get_media_thumbnail($result['media_id'], 'medium');

        echo json_encode([
            'success' => true,
            'message' => 'Fil uppladdad',
            'data' => $media
        ]);
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $result['error']]);
    }
}

/**
 * Handle metadata update
 */
function handleUpdate(): void {
    $input = json_decode(file_get_contents('php://input'), true);

    $id = (int) ($input['id'] ?? $_POST['id'] ?? 0);
    if (!$id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'ID saknas']);
        return;
    }

    $media = get_media($id);
    if (!$media) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Media ej funnen']);
        return;
    }

    $data = [
        'alt_text' => $input['alt_text'] ?? $_POST['alt_text'] ?? null,
        'caption' => $input['caption'] ?? $_POST['caption'] ?? null,
        'folder' => $input['folder'] ?? $_POST['folder'] ?? null
    ];

    // Remove null values
    $data = array_filter($data, fn($v) => $v !== null);

    if (empty($data)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Ingen data att uppdatera']);
        return;
    }

    if (update_media($id, $data)) {
        $media = get_media($id);
        $media['url'] = get_media_url($id);
        echo json_encode(['success' => true, 'message' => 'Uppdaterad', 'data' => $media]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Kunde inte uppdatera']);
    }
}

/**
 * Handle DELETE requests
 */
function handleDelete(): void {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = (int) ($input['id'] ?? $_GET['id'] ?? 0);

    if (!$id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'ID saknas']);
        return;
    }

    $media = get_media($id);
    if (!$media) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Media ej funnen']);
        return;
    }

    // Check if in use
    if (is_media_in_use($id)) {
        $usage = get_media_usage($id);
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Filen används och kan inte raderas',
            'usage' => $usage
        ]);
        return;
    }

    if (delete_media($id)) {
        echo json_encode(['success' => true, 'message' => 'Fil raderad']);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Kunde inte radera filen']);
    }
}

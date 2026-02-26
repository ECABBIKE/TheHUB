<?php
/**
 * Media API Endpoint
 * TheHUB V3 - Media & Sponsor System
 * 
 * Handles: upload, get, update, delete
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/media-functions.php';

// Get action from query string
$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'upload':
            handleUpload();
            break;
            
        case 'get':
            handleGet();
            break;
            
        case 'update':
            handleUpdate();
            break;
            
        case 'delete':
            handleDelete();
            break;
            
        case 'list':
            handleList();
            break;

        case 'create_folder':
            handleCreateFolder();
            break;

        case 'delete_folder':
            handleDeleteFolder();
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Ogiltig action']);
    }
} catch (Exception $e) {
    error_log("Media API error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Serverfel']);
}

/**
 * Handle file upload
 */
function handleUpload() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['success' => false, 'error' => 'POST krävs']);
        return;
    }
    
    if (!isset($_FILES['file'])) {
        echo json_encode(['success' => false, 'error' => 'Ingen fil bifogad']);
        return;
    }
    
    $folder = $_POST['folder'] ?? 'general';
    $uploadedBy = $_SESSION['user']['id'] ?? null;
    
    $result = upload_media($_FILES['file'], $folder, $uploadedBy);
    echo json_encode($result);
}

/**
 * Get single media item
 */
function handleGet() {
    $id = $_GET['id'] ?? null;
    
    if (!$id) {
        echo json_encode(['success' => false, 'error' => 'ID saknas']);
        return;
    }
    
    $media = get_media($id);
    
    if (!$media) {
        echo json_encode(['success' => false, 'error' => 'Media hittades inte']);
        return;
    }
    
    // Add URL and usage info
    $media['url'] = '/' . ltrim($media['filepath'], '/');
    $media['thumbnail'] = $media['url']; // For now, same as main URL
    $media['usage'] = get_media_usage($id);
    
    echo json_encode(['success' => true, 'data' => $media]);
}

/**
 * Update media metadata
 */
function handleUpdate() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['success' => false, 'error' => 'POST krävs']);
        return;
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['id'])) {
        echo json_encode(['success' => false, 'error' => 'ID saknas']);
        return;
    }
    
    $result = update_media($input['id'], $input);
    echo json_encode($result);
}

/**
 * Delete media
 * Supports force=1 parameter to delete even when file is in use (clears references)
 */
function handleDelete() {
    $id = $_GET['id'] ?? null;
    $force = isset($_GET['force']) && $_GET['force'] === '1';

    if (!$id) {
        echo json_encode(['success' => false, 'error' => 'ID saknas']);
        return;
    }

    // Check if promotor - they can only delete files in sponsors/ folders
    $isAdmin = function_exists('isRole') && isRole('admin');
    $isPromotorOnly = function_exists('isRole') && isRole('promotor') && !$isAdmin;

    if ($isPromotorOnly) {
        $media = get_media($id);
        if (!$media) {
            echo json_encode(['success' => false, 'error' => 'Media hittades inte']);
            return;
        }

        // Promotors can only delete from sponsors/ folders
        if (strpos($media['folder'], 'sponsors') !== 0) {
            echo json_encode(['success' => false, 'error' => 'Du har inte behörighet att radera denna fil']);
            return;
        }
    }

    $result = delete_media($id, $force);
    echo json_encode($result);
}

/**
 * Delete an empty folder
 */
function handleDeleteFolder() {
    $folder = $_GET['folder'] ?? '';

    if (empty($folder)) {
        echo json_encode(['success' => false, 'error' => 'Mappnamn saknas']);
        return;
    }

    $result = delete_media_folder($folder);
    echo json_encode($result);
}

/**
 * List media with filters
 */
function handleList() {
    $folder = $_GET['folder'] ?? null;
    $search = $_GET['search'] ?? '';
    $limit = min((int)($_GET['limit'] ?? 50), 200);
    $offset = (int)($_GET['offset'] ?? 0);
    $includeSubfolders = isset($_GET['subfolders']) && $_GET['subfolders'] === '1';

    if ($search) {
        $files = search_media($search, $folder, $limit);
    } else {
        $files = get_media_by_folder($folder, $limit, $offset, $includeSubfolders);
    }
    
    // Add URLs
    foreach ($files as &$file) {
        $file['url'] = '/' . ltrim($file['filepath'], '/');
        $file['thumbnail'] = $file['url'];
    }
    
    echo json_encode([
        'success' => true,
        'data' => $files,
        'count' => count($files)
    ]);
}

/**
 * Create a new folder
 */
function handleCreateFolder() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['success' => false, 'error' => 'POST krävs']);
        return;
    }

    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    $folderPath = $input['folder'] ?? '';

    if (empty($folderPath)) {
        echo json_encode(['success' => false, 'error' => 'Mappnamn saknas']);
        return;
    }

    // Sanitize folder path
    $folderPath = preg_replace('/[^a-z0-9\-\/]/', '', strtolower($folderPath));
    $folderPath = trim($folderPath, '/');

    if (empty($folderPath)) {
        echo json_encode(['success' => false, 'error' => 'Ogiltigt mappnamn']);
        return;
    }

    // Create the physical folder
    $uploadDir = __DIR__ . '/../uploads/media/' . $folderPath;

    if (is_dir($uploadDir)) {
        // Folder already exists - that's fine
        echo json_encode([
            'success' => true,
            'message' => 'Mappen finns redan',
            'folder' => $folderPath
        ]);
        return;
    }

    if (!mkdir($uploadDir, 0755, true)) {
        echo json_encode(['success' => false, 'error' => 'Kunde inte skapa mappen']);
        return;
    }

    echo json_encode([
        'success' => true,
        'message' => 'Mappen skapades',
        'folder' => $folderPath
    ]);
}

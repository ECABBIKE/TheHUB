<?php
/**
 * Sponsors API Endpoint
 * TheHUB V3 - Media & Sponsor System
 * 
 * Handles: create, get, update, delete, list
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/sponsor-functions.php';
require_once __DIR__ . '/../includes/media-functions.php';

// Get action from query string
$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'create':
            handleCreate();
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
            
        case 'stats':
            handleStats();
            break;
            
        default:
            echo json_encode(['success' => false, 'error' => 'Ogiltig action']);
    }
} catch (Exception $e) {
    error_log("Sponsors API error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Serverfel']);
}

/**
 * Create new sponsor
 */
function handleCreate() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['success' => false, 'error' => 'POST krävs']);
        return;
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || empty($input['name'])) {
        echo json_encode(['success' => false, 'error' => 'Namn krävs']);
        return;
    }
    
    $result = create_sponsor($input);
    echo json_encode($result);
}

/**
 * Get single sponsor
 */
function handleGet() {
    $id = $_GET['id'] ?? null;
    
    if (!$id) {
        echo json_encode(['success' => false, 'error' => 'ID saknas']);
        return;
    }
    
    $sponsor = get_sponsor($id);
    
    if (!$sponsor) {
        echo json_encode(['success' => false, 'error' => 'Sponsor hittades inte']);
        return;
    }
    
    echo json_encode(['success' => true, 'data' => $sponsor]);
}

/**
 * Update sponsor
 */
function handleUpdate() {
    if ($_SERVER['REQUEST_METHOD'] !== 'PUT' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['success' => false, 'error' => 'PUT/POST krävs']);
        return;
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['id'])) {
        echo json_encode(['success' => false, 'error' => 'ID saknas']);
        return;
    }
    
    $result = update_sponsor($input['id'], $input);
    echo json_encode($result);
}

/**
 * Delete sponsor
 */
function handleDelete() {
    $id = $_GET['id'] ?? null;
    
    if (!$id) {
        echo json_encode(['success' => false, 'error' => 'ID saknas']);
        return;
    }
    
    $result = delete_sponsor($id);
    echo json_encode($result);
}

/**
 * List sponsors with filters
 */
function handleList() {
    $tier = $_GET['tier'] ?? null;
    $activeOnly = !isset($_GET['active']) || $_GET['active'] !== '0';
    $search = $_GET['search'] ?? '';
    
    if ($search) {
        $sponsors = search_sponsors($search);
    } else {
        $sponsors = get_sponsors($activeOnly, $tier);
    }
    
    echo json_encode([
        'success' => true,
        'data' => $sponsors,
        'count' => count($sponsors)
    ]);
}

/**
 * Get sponsor statistics
 */
function handleStats() {
    $stats = get_sponsor_stats();
    
    echo json_encode([
        'success' => true,
        'data' => $stats
    ]);
}

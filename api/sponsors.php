<?php
/**
 * Sponsor API Endpoint
 * TheHUB V3 - Media & Sponsor System
 *
 * Handles: CRUD for sponsors, packages, and assignments
 */

header('Content-Type: application/json; charset=utf-8');

// Load configuration
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/sponsor-functions.php';

// Check authentication - only admins can manage sponsors
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

        case 'PUT':
            handlePut($action);
            break;

        case 'DELETE':
            handleDelete($action);
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
            // List sponsors
            $activeOnly = ($_GET['active'] ?? '1') === '1';
            $tier = $_GET['tier'] ?? null;

            $sponsors = get_sponsors($activeOnly, $tier);
            echo json_encode(['success' => true, 'data' => $sponsors]);
            break;

        case 'get':
            // Get single sponsor
            $id = (int) ($_GET['id'] ?? 0);
            if (!$id) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'ID saknas']);
                return;
            }

            $sponsor = get_sponsor($id);
            if (!$sponsor) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Sponsor ej funnen']);
                return;
            }

            // Include packages
            $sponsor['packages'] = get_sponsor_packages($id, false);

            echo json_encode(['success' => true, 'data' => $sponsor]);
            break;

        case 'search':
            // Search sponsors
            $query = $_GET['q'] ?? '';
            if (strlen($query) < 2) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Sökterm måste vara minst 2 tecken']);
                return;
            }

            $sponsors = search_sponsors($query);
            echo json_encode(['success' => true, 'data' => $sponsors]);
            break;

        case 'stats':
            // Get sponsor statistics
            $stats = get_sponsor_stats();
            echo json_encode(['success' => true, 'data' => $stats]);
            break;

        case 'series-sponsors':
            // Get sponsors for a series
            $seriesId = (int) ($_GET['series_id'] ?? 0);
            if (!$seriesId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Series ID saknas']);
                return;
            }

            $placement = $_GET['placement'] ?? null;
            $sponsors = get_series_sponsors($seriesId, $placement);
            echo json_encode(['success' => true, 'data' => $sponsors]);
            break;

        case 'event-sponsors':
            // Get sponsors for an event
            $eventId = (int) ($_GET['event_id'] ?? 0);
            if (!$eventId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Event ID saknas']);
                return;
            }

            $placement = $_GET['placement'] ?? null;
            $sponsors = get_event_sponsors($eventId, $placement);
            echo json_encode(['success' => true, 'data' => $sponsors]);
            break;

        case 'tiers':
            // Get available tiers
            $tiers = [
                ['id' => 'title', 'name' => 'Titelsponsor', 'color' => '#8B5CF6'],
                ['id' => 'gold', 'name' => 'Guldsponsor', 'color' => '#F59E0B'],
                ['id' => 'silver', 'name' => 'Silversponsor', 'color' => '#9CA3AF'],
                ['id' => 'bronze', 'name' => 'Bronssponsor', 'color' => '#D97706']
            ];
            echo json_encode(['success' => true, 'data' => $tiers]);
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
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

    switch ($action) {
        case 'create':
            handleCreate($input);
            break;

        case 'assign-series':
            handleAssignSeries($input);
            break;

        case 'assign-event':
            handleAssignEvent($input);
            break;

        case 'create-package':
            handleCreatePackage($input);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Ogiltig action']);
    }
}

/**
 * Handle PUT requests
 */
function handlePut(string $action): void {
    $input = json_decode(file_get_contents('php://input'), true);

    if ($action !== 'update') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Ogiltig action']);
        return;
    }

    $id = (int) ($input['id'] ?? 0);
    if (!$id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'ID saknas']);
        return;
    }

    $sponsor = get_sponsor($id);
    if (!$sponsor) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Sponsor ej funnen']);
        return;
    }

    // Remove id from data
    unset($input['id']);

    if (update_sponsor($id, $input)) {
        $sponsor = get_sponsor($id);
        echo json_encode(['success' => true, 'message' => 'Sponsor uppdaterad', 'data' => $sponsor]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Kunde inte uppdatera']);
    }
}

/**
 * Handle DELETE requests
 */
function handleDelete(string $action): void {
    $input = json_decode(file_get_contents('php://input'), true);

    switch ($action) {
        case 'delete':
            $id = (int) ($input['id'] ?? $_GET['id'] ?? 0);
            if (!$id) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'ID saknas']);
                return;
            }

            if (delete_sponsor($id)) {
                echo json_encode(['success' => true, 'message' => 'Sponsor raderad']);
            } else {
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => 'Kunde inte radera']);
            }
            break;

        case 'remove-series':
            $seriesId = (int) ($input['series_id'] ?? 0);
            $sponsorId = (int) ($input['sponsor_id'] ?? 0);
            $placement = $input['placement'] ?? null;

            if (!$seriesId || !$sponsorId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Series ID och Sponsor ID krävs']);
                return;
            }

            if (remove_sponsor_from_series($seriesId, $sponsorId, $placement)) {
                echo json_encode(['success' => true, 'message' => 'Sponsor borttagen från serie']);
            } else {
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => 'Kunde inte ta bort']);
            }
            break;

        case 'remove-event':
            $eventId = (int) ($input['event_id'] ?? 0);
            $sponsorId = (int) ($input['sponsor_id'] ?? 0);
            $placement = $input['placement'] ?? null;

            if (!$eventId || !$sponsorId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Event ID och Sponsor ID krävs']);
                return;
            }

            if (remove_sponsor_from_event($eventId, $sponsorId, $placement)) {
                echo json_encode(['success' => true, 'message' => 'Sponsor borttagen från event']);
            } else {
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => 'Kunde inte ta bort']);
            }
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Ogiltig action']);
    }
}

/**
 * Create new sponsor
 */
function handleCreate(array $input): void {
    // Validate required fields
    if (empty($input['name'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Namn krävs']);
        return;
    }

    $result = create_sponsor($input);

    if ($result['success']) {
        $sponsor = get_sponsor($result['sponsor_id']);
        echo json_encode([
            'success' => true,
            'message' => 'Sponsor skapad',
            'data' => $sponsor
        ]);
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $result['error']]);
    }
}

/**
 * Assign sponsor to series
 */
function handleAssignSeries(array $input): void {
    $seriesId = (int) ($input['series_id'] ?? 0);
    $sponsorId = (int) ($input['sponsor_id'] ?? 0);

    if (!$seriesId || !$sponsorId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Series ID och Sponsor ID krävs']);
        return;
    }

    $placement = $input['placement'] ?? 'sidebar';
    $displayOrder = (int) ($input['display_order'] ?? 0);
    $startDate = $input['start_date'] ?? null;
    $endDate = $input['end_date'] ?? null;

    if (assign_sponsor_to_series($seriesId, $sponsorId, $placement, $displayOrder, $startDate, $endDate)) {
        echo json_encode(['success' => true, 'message' => 'Sponsor kopplad till serie']);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Kunde inte koppla sponsor']);
    }
}

/**
 * Assign sponsor to event
 */
function handleAssignEvent(array $input): void {
    $eventId = (int) ($input['event_id'] ?? 0);
    $sponsorId = (int) ($input['sponsor_id'] ?? 0);

    if (!$eventId || !$sponsorId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Event ID och Sponsor ID krävs']);
        return;
    }

    $placement = $input['placement'] ?? 'sidebar';
    $displayOrder = (int) ($input['display_order'] ?? 0);

    if (assign_sponsor_to_event($eventId, $sponsorId, $placement, $displayOrder)) {
        echo json_encode(['success' => true, 'message' => 'Sponsor kopplad till event']);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Kunde inte koppla sponsor']);
    }
}

/**
 * Create sponsor package
 */
function handleCreatePackage(array $input): void {
    $sponsorId = (int) ($input['sponsor_id'] ?? 0);

    if (!$sponsorId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Sponsor ID krävs']);
        return;
    }

    if (empty($input['package_type'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Pakettyp krävs']);
        return;
    }

    $packageId = create_sponsor_package($sponsorId, $input);

    if ($packageId) {
        echo json_encode(['success' => true, 'message' => 'Paket skapat', 'package_id' => $packageId]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Kunde inte skapa paket']);
    }
}

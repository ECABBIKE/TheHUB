<?php
/**
 * TheHUB API - Orders (Multi-Rider Support)
 *
 * Hanterar ordrar med flera deltagare.
 *
 * Endpoints:
 * POST /api/orders.php - Skapa multi-rider order
 * GET /api/orders.php?action=my_riders - Hämta riders användaren kan anmäla
 * GET /api/orders.php?action=event_classes&event_id=X&rider_id=Y - Hämta klasser för rider
 * GET /api/orders.php?action=series_classes&series_id=X&rider_id=Y - Hämta klasser för rider i serie
 * POST /api/orders.php?action=create_rider - Skapa ny rider från flödet
 *
 * @since 2026-01-12
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/order-manager.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

try {
    // ========================================
    // GET REQUESTS
    // ========================================
    if ($method === 'GET') {

        switch ($action) {

            // Hämta riders som användaren kan anmäla
            case 'my_riders':
                if (!hub_is_logged_in()) {
                    http_response_code(401);
                    echo json_encode(['success' => false, 'error' => 'Du måste vara inloggad']);
                    exit;
                }

                $currentUser = hub_current_user();
                $riders = getRegistrableRiders($currentUser['id']);

                echo json_encode([
                    'success' => true,
                    'riders' => $riders
                ]);
                break;

            // Hämta klasser för en rider i ett event
            case 'event_classes':
                $eventId = intval($_GET['event_id'] ?? 0);
                $riderId = intval($_GET['rider_id'] ?? 0);

                if (!$eventId || !$riderId) {
                    throw new Exception('event_id och rider_id krävs');
                }

                $classes = getEligibleClassesForEvent($eventId, $riderId);

                // Hämta rider license info för commitment check
                $pdo = hub_db();
                $riderStmt = $pdo->prepare("
                    SELECT license_valid_until, license_year
                    FROM riders WHERE id = ?
                ");
                $riderStmt->execute([$riderId]);
                $riderInfo = $riderStmt->fetch(PDO::FETCH_ASSOC);

                // Hämta event-info för early bird / late fee status
                $eventStmt = $pdo->prepare("
                    SELECT name, date, pricing_template_id
                    FROM events WHERE id = ?
                ");
                $eventStmt->execute([$eventId]);
                $event = $eventStmt->fetch(PDO::FETCH_ASSOC);

                $now = time();
                $isEarlyBird = false;
                $isLateFee = false;

                // Calculate early bird / late fee status from pricing template
                if (!empty($event['pricing_template_id'])) {
                    // Get template settings for deadline calculation
                    $templateStmt = $pdo->prepare("SELECT * FROM pricing_templates WHERE id = ?");
                    $templateStmt->execute([$event['pricing_template_id']]);
                    $template = $templateStmt->fetch(PDO::FETCH_ASSOC);

                    if ($template && !empty($event['date'])) {
                        $eventDate = strtotime($event['date']);

                        // Early bird deadline = event_date - early_bird_days_before
                        if (!empty($template['early_bird_days_before'])) {
                            $earlyBirdDeadline = strtotime("-" . intval($template['early_bird_days_before']) . " days", $eventDate);
                            $isEarlyBird = $now < $earlyBirdDeadline;
                        }

                        // Late fee starts = event_date - late_fee_days_before
                        if (!empty($template['late_fee_days_before'])) {
                            $lateFeeStart = strtotime("-" . intval($template['late_fee_days_before']) . " days", $eventDate);
                            $isLateFee = $now >= $lateFeeStart && $now < $eventDate;
                        }
                    }
                }

                // Beräkna aktuellt pris för varje klass (rounded to whole numbers)
                foreach ($classes as &$class) {
                    if ($isEarlyBird && !empty($class['early_bird_price'])) {
                        $class['current_price'] = round($class['early_bird_price']);
                        $class['price_type'] = 'early_bird';
                    } elseif ($isLateFee && !empty($class['late_fee'])) {
                        $class['current_price'] = round($class['late_fee']);
                        $class['price_type'] = 'late_fee';
                    } else {
                        $class['current_price'] = round($class['base_price']);
                        $class['price_type'] = 'normal';
                    }
                }

                // Check if license commitment is required
                $requiresLicenseCommitment = false;
                if ($riderInfo) {
                    $eventDate = strtotime($event['date']);
                    $hasValidLicense = false;

                    if (!empty($riderInfo['license_valid_until'])) {
                        $licenseExpiry = strtotime($riderInfo['license_valid_until']);
                        $hasValidLicense = $licenseExpiry >= $eventDate;
                    } elseif (!empty($riderInfo['license_year'])) {
                        $licenseExpiry = strtotime($riderInfo['license_year'] . '-12-31');
                        $hasValidLicense = $licenseExpiry >= $eventDate;
                    }

                    $requiresLicenseCommitment = !$hasValidLicense;
                }

                echo json_encode([
                    'success' => true,
                    'event' => $event,
                    'is_early_bird' => $isEarlyBird,
                    'is_late_fee' => $isLateFee,
                    'requires_license_commitment' => $requiresLicenseCommitment,
                    'classes' => $classes
                ]);
                break;

            // Hämta klasser för en rider i en serie
            case 'series_classes':
                $seriesId = intval($_GET['series_id'] ?? 0);
                $riderId = intval($_GET['rider_id'] ?? 0);

                if (!$seriesId || !$riderId) {
                    throw new Exception('series_id och rider_id krävs');
                }

                $classes = getEligibleClassesForSeries($seriesId, $riderId);

                // Hämta serie-info
                $pdo = hub_db();
                $seriesStmt = $pdo->prepare("
                    SELECT id, name, series_discount_percent,
                           (SELECT COUNT(*) FROM events WHERE series_id = s.id) as event_count
                    FROM series s WHERE s.id = ?
                ");
                $seriesStmt->execute([$seriesId]);
                $series = $seriesStmt->fetch(PDO::FETCH_ASSOC);

                echo json_encode([
                    'success' => true,
                    'series' => $series,
                    'classes' => $classes
                ]);
                break;

            // Hämta klubbar (för skapa rider)
            case 'clubs':
                $pdo = hub_db();
                $clubsStmt = $pdo->query("SELECT id, name, city FROM clubs WHERE active = 1 ORDER BY name");
                $clubs = $clubsStmt->fetchAll(PDO::FETCH_ASSOC);

                echo json_encode([
                    'success' => true,
                    'clubs' => $clubs
                ]);
                break;

            // Sök riders i databasen (namn eller UCI ID)
            case 'search_riders':
                $query = trim($_GET['q'] ?? '');

                if (strlen($query) < 2) {
                    echo json_encode([
                        'success' => true,
                        'riders' => []
                    ]);
                    break;
                }

                $pdo = hub_db();

                // Search by name or license_number (UCI ID)
                $searchPattern = '%' . $query . '%';
                $stmt = $pdo->prepare("
                    SELECT r.id, r.firstname, r.lastname, r.birth_year, r.gender,
                           r.license_number, r.license_type, c.name as club_name
                    FROM riders r
                    LEFT JOIN clubs c ON r.club_id = c.id
                    WHERE (CONCAT(r.firstname, ' ', r.lastname) LIKE ?
                           OR r.license_number LIKE ?
                           OR CONCAT(r.lastname, ' ', r.firstname) LIKE ?)
                    AND r.active = 1
                    ORDER BY r.lastname, r.firstname
                    LIMIT 50
                ");
                $stmt->execute([$searchPattern, $searchPattern, $searchPattern]);
                $riders = $stmt->fetchAll(PDO::FETCH_ASSOC);

                echo json_encode([
                    'success' => true,
                    'riders' => $riders
                ]);
                break;

            default:
                throw new Exception('Ogiltig action');
        }
    }

    // ========================================
    // POST REQUESTS
    // ========================================
    elseif ($method === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);

        if (!$data) {
            throw new Exception('Ogiltig JSON-data');
        }

        $action = $data['action'] ?? $_GET['action'] ?? 'create';

        switch ($action) {

            // Skapa multi-rider order
            case 'create':
                // Tillåt både inloggade och icke-inloggade användare
                $currentUser = hub_is_logged_in() ? hub_current_user() : null;

                // Validera buyer data
                // Om inloggad: använd användarens data som default
                // Om inte inloggad: måste skickas med i request
                $buyerData = [
                    'user_id' => $currentUser['id'] ?? null,
                    'name' => $data['buyer']['name'] ?? ($currentUser ? ($currentUser['firstname'] . ' ' . $currentUser['lastname']) : null),
                    'email' => $data['buyer']['email'] ?? ($currentUser['email'] ?? null),
                    'phone' => $data['buyer']['phone'] ?? null
                ];

                // Validera att buyer har nödvändig information
                if (empty($buyerData['name']) || empty($buyerData['email'])) {
                    throw new Exception('Köparens namn och e-post måste anges');
                }

                $items = $data['items'] ?? [];

                if (empty($items)) {
                    throw new Exception('Inga deltagare valda');
                }

                // Ingen permission-validering - vem som helst kan anmäla vilken åkare som helst
                // Köparens uppgifter valideras i kassan före betalning

                // Skapa order
                $result = createMultiRiderOrder($buyerData, $items, $data['discount_code'] ?? null);

                if ($result['success']) {
                    // Beräkna savings
                    $savings = calculateMultiRiderSavings(count($items));
                    $result['savings'] = $savings;
                }

                echo json_encode($result);
                break;

            // Skapa ny rider från anmälningsflödet
            case 'create_rider':
                if (!hub_is_logged_in()) {
                    http_response_code(401);
                    echo json_encode(['success' => false, 'error' => 'Du måste vara inloggad']);
                    exit;
                }

                $currentUser = hub_current_user();
                $riderData = $data['rider'] ?? $data;

                $result = createRiderFromRegistration($riderData, $currentUser['id']);

                echo json_encode($result);
                break;

            default:
                throw new Exception('Ogiltig action');
        }
    }

    else {
        throw new Exception('Metod stöds ej');
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

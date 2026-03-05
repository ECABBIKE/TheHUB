<?php
/**
 * Feedback / Bug Report API
 * POST - Submit a new bug report / feedback
 *
 * Spam protection:
 * - Honeypot field (website_url must be empty)
 * - Time-based check (form must be open >= 3 seconds)
 * - Session token validation
 * - IP-based rate limiting (5 reports per hour)
 */
define('HUB_API_REQUEST', true);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/rate-limiter.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Parse input
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = $_POST;
}

// ========================
// SPAM PROTECTION
// ========================

// 1. Honeypot check - bot-filled hidden field must be empty
$honeypot = trim($input['website_url'] ?? '');
if (!empty($honeypot)) {
    // Silently accept but don't save (bots think it worked)
    http_response_code(201);
    echo json_encode([
        'success' => true,
        'message' => 'Tack för din rapport! Vi tittar på det så snart vi kan.',
        'id' => 0
    ]);
    exit;
}

// 2. Time-based check - form must have been rendered for at least 3 seconds
$renderTime = (int)($input['_render_time'] ?? 0);
if ($renderTime > 0 && (time() - $renderTime) < 3) {
    http_response_code(429);
    echo json_encode(['error' => 'Formuläret skickades för snabbt. Vänta en stund och försök igen.']);
    exit;
}

// 3. IP-based rate limiting - max 5 reports per hour
$clientIp = get_client_ip();
if (is_rate_limited('feedback_report', $clientIp, 5, 3600)) {
    http_response_code(429);
    echo json_encode(['error' => 'Du har skickat för många rapporter. Vänta en stund innan du försöker igen.']);
    exit;
}

// 4. Session token validation (optional - works when session is available)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$submittedToken = trim($input['_token'] ?? '');
if (!empty($submittedToken) && !empty($_SESSION['feedback_token'])) {
    if (!hash_equals($_SESSION['feedback_token'], $submittedToken)) {
        http_response_code(403);
        echo json_encode(['error' => 'Ogiltig formulärtoken. Ladda om sidan och försök igen.']);
        exit;
    }
}

// ========================
// INPUT PARSING
// ========================

$category = $input['category'] ?? 'other';
$title = trim($input['title'] ?? '');
$description = trim($input['description'] ?? '');
$email = trim($input['email'] ?? '');
$pageUrl = trim($input['page_url'] ?? '');
$browserInfo = trim($input['browser_info'] ?? '');
$relatedRiderIds = $input['related_rider_ids'] ?? [];
$relatedEventId = !empty($input['related_event_id']) ? (int)$input['related_event_id'] : null;

// ========================
// VALIDATION
// ========================

$errors = [];

if (empty($title)) {
    $errors[] = 'Rubrik krävs';
}
if (strlen($title) > 255) {
    $errors[] = 'Rubriken är för lång (max 255 tecken)';
}
if (empty($description)) {
    $errors[] = 'Beskrivning krävs';
}
if (strlen($description) > 5000) {
    $errors[] = 'Beskrivningen är för lång (max 5000 tecken)';
}
if (!in_array($category, ['profile', 'results', 'other'])) {
    $errors[] = 'Ogiltig kategori';
}
if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Ogiltig e-postadress';
}

// Validate related rider IDs (max 4, must be integers)
$riderIdsString = null;
if (!empty($relatedRiderIds) && is_array($relatedRiderIds)) {
    $relatedRiderIds = array_slice($relatedRiderIds, 0, 4);
    $relatedRiderIds = array_map('intval', $relatedRiderIds);
    $relatedRiderIds = array_filter($relatedRiderIds, function($id) { return $id > 0; });
    if (!empty($relatedRiderIds)) {
        $riderIdsString = implode(',', $relatedRiderIds);
    }
}

if (!empty($errors)) {
    http_response_code(400);
    echo json_encode(['error' => implode(', ', $errors)]);
    exit;
}

// ========================
// GET USER DATA
// ========================

$riderId = null;
if (!empty($_SESSION['rider_id'])) {
    $riderId = (int)$_SESSION['rider_id'];
    // Auto-fill email from rider profile if not provided
    if (empty($email)) {
        try {
            $stmt = $pdo->prepare("SELECT email FROM riders WHERE id = ?");
            $stmt->execute([$riderId]);
            $riderEmail = $stmt->fetchColumn();
            if ($riderEmail) {
                $email = $riderEmail;
            }
        } catch (Exception $e) {
            // Ignore
        }
    }
}

// ========================
// SAVE REPORT
// ========================

// Generate a unique view token for conversation access
$viewToken = bin2hex(random_bytes(32));

try {
    $stmt = $pdo->prepare("
        INSERT INTO bug_reports (rider_id, category, title, description, email, page_url, browser_info, related_rider_ids, related_event_id, view_token, created_at)
        VALUES (:rider_id, :category, :title, :description, :email, :page_url, :browser_info, :related_rider_ids, :related_event_id, :view_token, NOW())
    ");
    $stmt->execute([
        ':rider_id' => $riderId,
        ':category' => $category,
        ':title' => $title,
        ':description' => $description,
        ':email' => $email ?: null,
        ':page_url' => $pageUrl ?: null,
        ':browser_info' => $browserInfo ?: null,
        ':related_rider_ids' => $riderIdsString,
        ':related_event_id' => $relatedEventId,
        ':view_token' => $viewToken
    ]);

    $reportId = $pdo->lastInsertId();

    // Record the rate limit attempt AFTER successful save
    record_rate_limit_attempt('feedback_report', $clientIp, 3600);

    // Invalidate token so the form can't be re-submitted with the same token
    unset($_SESSION['feedback_token']);

    http_response_code(201);
    echo json_encode([
        'success' => true,
        'message' => 'Tack för din rapport! Vi tittar på det så snart vi kan.',
        'id' => $reportId
    ]);

} catch (PDOException $e) {
    if (strpos($e->getMessage(), "doesn't exist") !== false) {
        http_response_code(500);
        echo json_encode(['error' => 'Systemet är inte konfigurerat ännu. Kör migration 070 först.']);
    } else {
        error_log("Bug report submission error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Kunde inte spara rapporten. Försök igen senare.']);
    }
}

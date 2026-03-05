<?php
/**
 * Bug Report Reply API
 * POST - Submit a reply to a bug report conversation
 *
 * Used by both public users (via token) and admin (via session)
 * Rate limited: 10 replies per hour per IP
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

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = $_POST;
}

$token = trim($input['token'] ?? '');
$message = trim($input['message'] ?? '');

if (empty($token) || empty($message)) {
    http_response_code(400);
    echo json_encode(['error' => 'Token och meddelande krävs.']);
    exit;
}

if (strlen($message) > 5000) {
    http_response_code(400);
    echo json_encode(['error' => 'Meddelandet är för långt (max 5000 tecken).']);
    exit;
}

// Rate limiting
$clientIp = get_client_ip();
if (is_rate_limited('bug_reply', $clientIp, 10, 3600)) {
    http_response_code(429);
    echo json_encode(['error' => 'Du har skickat för många svar. Vänta en stund.']);
    exit;
}

// Find the bug report by token
try {
    $stmt = $pdo->prepare("SELECT id, title, status, email, rider_id FROM bug_reports WHERE view_token = ?");
    $stmt->execute([$token]);
    $report = $stmt->fetch();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Databasfel.']);
    exit;
}

if (!$report) {
    http_response_code(404);
    echo json_encode(['error' => 'Ärendet hittades inte.']);
    exit;
}

// Don't allow replies to resolved/wontfix reports
if (in_array($report['status'], ['resolved', 'wontfix'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Ärendet är avslutat och kan inte besvaras.']);
    exit;
}

// Determine sender info
$senderType = 'user';
$senderId = null;
$senderName = null;

// Check if logged-in user matches the report
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!empty($_SESSION['rider_id'])) {
    $senderId = (int)$_SESSION['rider_id'];
    try {
        $rStmt = $pdo->prepare("SELECT firstname, lastname FROM riders WHERE id = ?");
        $rStmt->execute([$senderId]);
        $rider = $rStmt->fetch();
        if ($rider) {
            $senderName = $rider['firstname'] . ' ' . $rider['lastname'];
        }
    } catch (Exception $e) {}
}

if (!$senderName && $report['email']) {
    $senderName = explode('@', $report['email'])[0];
}

// Save the message
try {
    $stmt = $pdo->prepare("
        INSERT INTO bug_report_messages (bug_report_id, sender_type, sender_id, sender_name, message, created_at)
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([$report['id'], $senderType, $senderId, $senderName, $message]);

    // Update the report status to in_progress if it was new (user responded)
    if ($report['status'] === 'new') {
        $pdo->prepare("UPDATE bug_reports SET status = 'in_progress' WHERE id = ?")->execute([$report['id']]);
    }

    record_rate_limit_attempt('bug_reply', $clientIp, 3600);

    echo json_encode([
        'success' => true,
        'message' => 'Svar skickat!',
        'sender_name' => $senderName
    ]);

} catch (Exception $e) {
    error_log("Bug report reply error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Kunde inte spara svaret. Har migration 080 körts?']);
}

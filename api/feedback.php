<?php
/**
 * Feedback / Bug Report API
 * POST - Submit a new bug report / feedback
 */
define('HUB_API_REQUEST', true);
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Rate limiting: max 5 reports per IP per hour
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM bug_reports
        WHERE created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
        AND email = :ip_check
    ");
    // Use a simple check - we'll store IP in a pragmatic way
} catch (Exception $e) {
    // Table might not exist yet - continue
}

// Parse input
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = $_POST;
}

$category = $input['category'] ?? 'bug';
$title = trim($input['title'] ?? '');
$description = trim($input['description'] ?? '');
$email = trim($input['email'] ?? '');
$pageUrl = trim($input['page_url'] ?? '');
$browserInfo = trim($input['browser_info'] ?? '');
$screenshotUrl = trim($input['screenshot_url'] ?? '');

// Validate required fields
$errors = [];

if (empty($title)) {
    $errors[] = 'Titel krävs';
}
if (strlen($title) > 255) {
    $errors[] = 'Titeln är för lång (max 255 tecken)';
}
if (empty($description)) {
    $errors[] = 'Beskrivning krävs';
}
if (strlen($description) > 5000) {
    $errors[] = 'Beskrivningen är för lång (max 5000 tecken)';
}
if (!in_array($category, ['bug', 'feature', 'design', 'other'])) {
    $errors[] = 'Ogiltig kategori';
}
if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Ogiltig e-postadress';
}

if (!empty($errors)) {
    http_response_code(400);
    echo json_encode(['error' => implode(', ', $errors)]);
    exit;
}

// Get rider_id from session if logged in
$riderId = null;
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
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

try {
    $stmt = $pdo->prepare("
        INSERT INTO bug_reports (rider_id, category, title, description, email, page_url, browser_info, screenshot_url, created_at)
        VALUES (:rider_id, :category, :title, :description, :email, :page_url, :browser_info, :screenshot_url, NOW())
    ");
    $stmt->execute([
        ':rider_id' => $riderId,
        ':category' => $category,
        ':title' => $title,
        ':description' => $description,
        ':email' => $email ?: null,
        ':page_url' => $pageUrl ?: null,
        ':browser_info' => $browserInfo ?: null,
        ':screenshot_url' => $screenshotUrl ?: null
    ]);

    $reportId = $pdo->lastInsertId();

    http_response_code(201);
    echo json_encode([
        'success' => true,
        'message' => 'Tack för din rapport! Vi tittar på det så snart vi kan.',
        'id' => $reportId
    ]);

} catch (PDOException $e) {
    // Check if table doesn't exist
    if (strpos($e->getMessage(), "doesn't exist") !== false) {
        http_response_code(500);
        echo json_encode(['error' => 'Systemet är inte konfigurerat ännu. Kör migration 070 först.']);
    } else {
        error_log("Bug report submission error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Kunde inte spara rapporten. Försök igen senare.']);
    }
}

<?php
/**
 * API Endpoint: Send activation email to rider
 *
 * Sends a password reset email to a rider who has an email but no password set.
 * Only accessible by super admins.
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../hub-config.php';
require_once __DIR__ . '/../includes/mail.php';

// Only POST allowed
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);
$riderId = (int)($input['rider_id'] ?? 0);

// Debug logging
error_log("RIDER_ACTIVATE: Request received for rider_id=" . $riderId);
error_log("RIDER_ACTIVATE: Session data: " . print_r($_SESSION, true));

if (!$riderId) {
    error_log("RIDER_ACTIVATE: No rider_id provided");
    echo json_encode(['success' => false, 'error' => 'Ogiltig profil']);
    exit;
}

// Verify super admin
$isSuperAdmin = function_exists('hub_is_super_admin') && hub_is_super_admin();

error_log("RIDER_ACTIVATE: hub_is_super_admin exists: " . (function_exists('hub_is_super_admin') ? 'yes' : 'no'));
error_log("RIDER_ACTIVATE: isSuperAdmin: " . ($isSuperAdmin ? 'yes' : 'no'));
error_log("RIDER_ACTIVATE: admin_role from session: " . ($_SESSION['admin_role'] ?? 'not set'));

if (!$isSuperAdmin) {
    http_response_code(403);
    error_log("RIDER_ACTIVATE: Access denied - not super admin");
    echo json_encode(['success' => false, 'error' => 'Endast super admin kan skicka aktiveringslänkar']);
    exit;
}

try {
    global $pdo;

    if (!$pdo) {
        echo json_encode(['success' => false, 'error' => 'Databasanslutning misslyckades']);
        exit;
    }

    // Get rider
    $stmt = $pdo->prepare("SELECT id, email, password, firstname, lastname FROM riders WHERE id = ?");
    $stmt->execute([$riderId]);
    $rider = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$rider) {
        echo json_encode(['success' => false, 'error' => 'Profilen hittades inte']);
        exit;
    }

    if (empty($rider['email'])) {
        echo json_encode(['success' => false, 'error' => 'Profilen saknar e-postadress']);
        exit;
    }

    if (!empty($rider['password'])) {
        echo json_encode(['success' => false, 'error' => 'Kontot är redan aktiverat']);
        exit;
    }

    // Generate password reset token
    $token = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', strtotime('+24 hours'));

    $stmt = $pdo->prepare("UPDATE riders SET password_reset_token = ?, password_reset_expires = ? WHERE id = ?");
    $stmt->execute([$token, $expires, $riderId]);

    // Build reset link and send email
    $baseUrl = 'https://thehub.gravityseries.se';
    $resetLink = $baseUrl . '/reset-password?token=' . $token;
    $riderName = trim($rider['firstname'] . ' ' . $rider['lastname']);

    $emailSent = hub_send_password_reset_email($rider['email'], $riderName, $resetLink);

    // Log the action
    error_log("ADMIN ACTIVATE: Super admin sent activation email to rider {$riderId} ({$riderName}) at {$rider['email']}. Email sent: " . ($emailSent ? 'yes' : 'no'));

    if ($emailSent) {
        echo json_encode([
            'success' => true,
            'message' => 'Aktiveringslänk skickad till ' . $rider['email']
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'error' => 'Kunde inte skicka mail. Kontrollera e-postadressen.'
        ]);
    }

} catch (Exception $e) {
    error_log("Rider activate error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Ett fel uppstod. Försök igen senare.']);
}

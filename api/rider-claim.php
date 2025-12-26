<?php
/**
 * API Endpoint: Profile Claim / Direct Email Connection
 *
 * Two modes:
 * 1. Super Admin direct: Directly connects email to profile (admin_direct=1)
 * 2. User claim: Creates a claim request for admin approval (not yet implemented publicly)
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../config.php';

// Only POST allowed
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Get parameters
$targetRiderId = (int)($_POST['target_rider_id'] ?? 0);
$adminDirect = !empty($_POST['admin_direct']);
$email = trim($_POST['email'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$instagram = trim($_POST['instagram'] ?? '');
$facebook = trim($_POST['facebook'] ?? '');
$reason = trim($_POST['reason'] ?? '');

if (!$targetRiderId) {
    echo json_encode(['success' => false, 'error' => 'Ogiltig profil']);
    exit;
}

try {
    $db = getDB();

    // Check that target rider exists and has no email
    $targetRider = $db->getRow("SELECT id, email, firstname, lastname FROM riders WHERE id = ?", [$targetRiderId]);

    if (!$targetRider) {
        echo json_encode(['success' => false, 'error' => 'Profilen hittades inte']);
        exit;
    }

    if (!empty($targetRider['email'])) {
        echo json_encode(['success' => false, 'error' => 'Denna profil har redan en e-postadress']);
        exit;
    }

    // Mode 1: Super Admin direct connection
    if ($adminDirect) {
        // Verify super admin
        $isSuperAdmin = function_exists('hub_is_super_admin') && hub_is_super_admin();

        if (!$isSuperAdmin) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Endast super admin kan göra direkt koppling']);
            exit;
        }

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'error' => 'Ogiltig e-postadress']);
            exit;
        }

        if (empty($phone)) {
            echo json_encode(['success' => false, 'error' => 'Telefonnummer krävs för verifiering']);
            exit;
        }

        // Check if email is already used by another rider
        $existingRider = $db->getRow("SELECT id, firstname, lastname FROM riders WHERE email = ? AND id != ?", [$email, $targetRiderId]);
        if ($existingRider) {
            echo json_encode([
                'success' => false,
                'error' => 'E-postadressen används redan av ' . $existingRider['firstname'] . ' ' . $existingRider['lastname'] . ' (ID: ' . $existingRider['id'] . ')'
            ]);
            exit;
        }

        // Build verification notes
        $verificationNotes = [];
        $verificationNotes[] = "Tel: {$phone}";
        if ($instagram) $verificationNotes[] = "IG: {$instagram}";
        if ($facebook) $verificationNotes[] = "FB: {$facebook}";
        if ($reason) $verificationNotes[] = "Note: {$reason}";
        $fullNotes = implode(' | ', $verificationNotes);

        // Direct update - connect email and phone to profile
        $db->query("UPDATE riders SET email = ?, phone = ?, updated_at = NOW() WHERE id = ?", [$email, $phone, $targetRiderId]);

        // Log the action with full verification info
        error_log("ADMIN DIRECT CLAIM: Super admin connected email '{$email}' to rider {$targetRiderId} ({$targetRider['firstname']} {$targetRider['lastname']}). Verification: {$fullNotes}");

        echo json_encode([
            'success' => true,
            'message' => 'E-post kopplad till profilen!'
        ]);
        exit;
    }

    // Mode 2: User claim request (for future use when public claims are enabled)
    $currentUser = function_exists('hub_current_user') ? hub_current_user() : null;

    if (!$currentUser || empty($currentUser['id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Du måste vara inloggad']);
        exit;
    }

    $claimantRiderId = $currentUser['id'];
    $claimantEmail = $currentUser['email'] ?? '';
    $claimantName = trim(($currentUser['firstname'] ?? '') . ' ' . ($currentUser['lastname'] ?? ''));

    // Cannot claim your own profile
    if ($claimantRiderId === $targetRiderId) {
        echo json_encode(['success' => false, 'error' => 'Du kan inte koppla till din egen profil']);
        exit;
    }

    // Check if there's already a pending claim for this combination
    $existingClaim = $db->getRow(
        "SELECT id FROM rider_claims WHERE claimant_rider_id = ? AND target_rider_id = ? AND status = 'pending'",
        [$claimantRiderId, $targetRiderId]
    );

    if ($existingClaim) {
        echo json_encode(['success' => false, 'error' => 'Du har redan en väntande förfrågan för denna profil']);
        exit;
    }

    // Insert the claim
    $db->insert('rider_claims', [
        'claimant_rider_id' => $claimantRiderId,
        'target_rider_id' => $targetRiderId,
        'claimant_email' => $claimantEmail,
        'claimant_name' => $claimantName,
        'reason' => $reason ?: null,
        'status' => 'pending',
        'created_at' => date('Y-m-d H:i:s')
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Förfrågan skickad! En administratör kommer att granska den.'
    ]);

} catch (Exception $e) {
    error_log("Rider claim error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Ett fel uppstod. Försök igen senare.']);
}

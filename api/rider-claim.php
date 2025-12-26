<?php
/**
 * API Endpoint: Submit Profile Claim Request
 *
 * Allows logged-in users to claim historical rider profiles
 * that don't have an email associated with them.
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../config.php';

// Only POST allowed
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Check if user is logged in
$currentUser = function_exists('hub_current_user') ? hub_current_user() : null;

if (!$currentUser || empty($currentUser['id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Du måste vara inloggad']);
    exit;
}

$claimantRiderId = $currentUser['id'];
$claimantEmail = $currentUser['email'] ?? '';
$claimantName = trim(($currentUser['firstname'] ?? '') . ' ' . ($currentUser['lastname'] ?? ''));

// Get target rider ID
$targetRiderId = (int)($_POST['target_rider_id'] ?? 0);
$reason = trim($_POST['reason'] ?? '');

if (!$targetRiderId) {
    echo json_encode(['success' => false, 'error' => 'Ogiltig profil']);
    exit;
}

// Cannot claim your own profile
if ($claimantRiderId === $targetRiderId) {
    echo json_encode(['success' => false, 'error' => 'Du kan inte koppla till din egen profil']);
    exit;
}

try {
    $db = getDB();

    // Check that target rider exists and has no email (is claimable)
    $targetRider = $db->getRow("SELECT id, email, firstname, lastname FROM riders WHERE id = ?", [$targetRiderId]);

    if (!$targetRider) {
        echo json_encode(['success' => false, 'error' => 'Profilen hittades inte']);
        exit;
    }

    if (!empty($targetRider['email'])) {
        echo json_encode(['success' => false, 'error' => 'Denna profil är redan kopplad till ett konto']);
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

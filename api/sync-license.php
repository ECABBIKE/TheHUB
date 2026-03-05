<?php
/**
 * API: Sync rider license from SCF
 * Allows logged-in users to verify and sync their UCI ID / license data
 *
 * GET /api/sync-license.php?uci_id=10012345678
 * Returns JSON with license data or error
 */

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

// Require login
if (!hub_is_logged_in()) {
    echo json_encode(['success' => false, 'error' => 'Inte inloggad']);
    exit;
}

$currentUser = hub_current_user();
if (!$currentUser || empty($currentUser['id'])) {
    echo json_encode(['success' => false, 'error' => 'Kunde inte hitta din profil']);
    exit;
}

$riderId = (int)$currentUser['id'];
$uciId = trim($_GET['uci_id'] ?? '');

if (empty($uciId)) {
    echo json_encode(['success' => false, 'error' => 'UCI ID krävs']);
    exit;
}

// Normalize - strip non-digits
$normalizedUciId = preg_replace('/[^0-9]/', '', $uciId);

if (strlen($normalizedUciId) < 9 || strlen($normalizedUciId) > 11) {
    echo json_encode(['success' => false, 'error' => 'UCI ID ska vara 9-11 siffror']);
    exit;
}

// Load SCF service
$scfPath = __DIR__ . '/../includes/SCFLicenseService.php';
if (!file_exists($scfPath)) {
    echo json_encode(['success' => false, 'error' => 'Licenssystemet är inte tillgängligt']);
    exit;
}
require_once $scfPath;

$pdo = hub_db();
$year = (int)date('Y');

try {
    $scf = new SCFLicenseService($pdo);

    // Check if this UCI ID belongs to someone else
    $existingStmt = $pdo->prepare("SELECT id, firstname, lastname FROM riders WHERE license_number = ? AND id != ?");
    $existingStmt->execute([$normalizedUciId, $riderId]);
    $existingRider = $existingStmt->fetch(PDO::FETCH_ASSOC);

    if ($existingRider) {
        echo json_encode([
            'success' => false,
            'error' => 'Detta UCI ID är redan kopplat till en annan profil (' . $existingRider['firstname'] . ' ' . $existingRider['lastname'] . '). Kontakta admin om detta är fel.'
        ]);
        exit;
    }

    // Lookup via SCF API
    $results = $scf->lookupByUciIds([$normalizedUciId], $year);

    if (empty($results) || !isset($results[$normalizedUciId])) {
        echo json_encode([
            'success' => false,
            'error' => 'UCI ID hittades inte i SCF:s register för ' . $year . '. Kontrollera att numret stämmer och att din licens är aktiv.'
        ]);
        exit;
    }

    $licenseData = $results[$normalizedUciId];

    // Verify name match (fuzzy - allow minor differences)
    $scfFirst = mb_strtolower(trim($licenseData['firstname'] ?? ''));
    $scfLast = mb_strtolower(trim($licenseData['lastname'] ?? ''));
    $riderFirst = mb_strtolower(trim($currentUser['firstname'] ?? ''));
    $riderLast = mb_strtolower(trim($currentUser['lastname'] ?? ''));

    // Check if names match (allow substring match for compound names)
    $firstMatch = ($scfFirst === $riderFirst) ||
                  (str_contains($scfFirst, $riderFirst)) ||
                  (str_contains($riderFirst, $scfFirst)) ||
                  (similar_text($scfFirst, $riderFirst) / max(mb_strlen($scfFirst), mb_strlen($riderFirst), 1) > 0.7);

    $lastMatch = ($scfLast === $riderLast) ||
                 (str_contains($scfLast, $riderLast)) ||
                 (str_contains($riderLast, $scfLast)) ||
                 (similar_text($scfLast, $riderLast) / max(mb_strlen($scfLast), mb_strlen($riderLast), 1) > 0.7);

    if (!$firstMatch || !$lastMatch) {
        echo json_encode([
            'success' => false,
            'error' => 'Namnet i SCF (' . htmlspecialchars($licenseData['firstname'] . ' ' . $licenseData['lastname']) . ') matchar inte ditt profilnamn (' . htmlspecialchars($currentUser['firstname'] . ' ' . $currentUser['lastname']) . '). Kontrollera att ditt namn i profilen stämmer.'
        ]);
        exit;
    }

    // Update rider profile with SCF data
    $updated = $scf->updateRiderLicense($riderId, $licenseData, $year);

    // Also set the UCI ID if not already set
    if (empty($currentUser['license_number'])) {
        $stmt = $pdo->prepare("UPDATE riders SET license_number = ? WHERE id = ?");
        $stmt->execute([$normalizedUciId, $riderId]);
    }

    // Build response with what was updated
    $response = [
        'success' => true,
        'message' => 'Licens verifierad och profil uppdaterad!',
        'data' => [
            'uci_id' => $normalizedUciId,
            'firstname' => $licenseData['firstname'] ?? '',
            'lastname' => $licenseData['lastname'] ?? '',
            'license_type' => $licenseData['license_type'] ?? '',
            'license_category' => $licenseData['license_category'] ?? '',
            'license_year' => $year,
            'club_name' => $licenseData['club_name'] ?? '',
            'discipline' => $licenseData['discipline'] ?? '',
            'nationality' => $licenseData['nationality'] ?? '',
            'birth_year' => $licenseData['birth_year'] ?? null,
            'gender' => $licenseData['gender'] ?? ''
        ]
    ];

    echo json_encode($response);

} catch (Exception $e) {
    error_log("License sync error for rider $riderId: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Ett fel uppstod vid licenskontrollen. Försök igen senare.'
    ]);
}

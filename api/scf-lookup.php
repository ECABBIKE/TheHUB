<?php
/**
 * SCF License Lookup API
 *
 * Public-facing endpoint for UCI ID lookups.
 * Used by the "create rider" form to auto-fill license data.
 *
 * GET /api/scf-lookup.php?uci_id=10012345678
 */

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

$uciId = trim($_GET['uci_id'] ?? '');

if (empty($uciId)) {
    echo json_encode(['success' => false, 'error' => 'UCI ID saknas']);
    exit;
}

// Normalize: digits only
$uciClean = preg_replace('/[^0-9]/', '', $uciId);
if (strlen($uciClean) < 9 || strlen($uciClean) > 11) {
    echo json_encode(['success' => false, 'error' => 'Ogiltigt UCI ID-format (ska vara 9-11 siffror)']);
    exit;
}

$apiKey = env('SCF_API_KEY', '');
if (empty($apiKey)) {
    echo json_encode(['success' => false, 'error' => 'SCF API ej konfigurerad']);
    exit;
}

// Check if rider already exists in database
$db = getDB();
$existing = $db->getRow("
    SELECT id, firstname, lastname, birth_year, gender, nationality, email, phone,
           license_number, license_type, license_category
    FROM riders
    WHERE REPLACE(REPLACE(license_number, ' ', ''), '-', '') = ?
", [$uciClean]);

if ($existing) {
    echo json_encode([
        'success' => true,
        'source' => 'database',
        'existing_rider_id' => (int)$existing['id'],
        'rider' => [
            'firstname' => $existing['firstname'],
            'lastname' => $existing['lastname'],
            'birth_year' => $existing['birth_year'],
            'gender' => $existing['gender'],
            'nationality' => $existing['nationality'] ?? 'SWE',
            'email' => $existing['email'],
            'phone' => $existing['phone'],
            'license_type' => $existing['license_type'],
            'license_category' => $existing['license_category'],
        ],
        'message' => 'Denna åkare finns redan i databasen'
    ]);
    exit;
}

// Lookup from SCF API
require_once __DIR__ . '/../includes/SCFLicenseService.php';
$scfService = new SCFLicenseService($apiKey, $db);

$year = (int)date('Y');
$results = $scfService->lookupByUciIds([$uciClean], $year);

if (empty($results)) {
    echo json_encode(['success' => false, 'error' => 'Ingen licens hittades för detta UCI ID']);
    exit;
}

$licenseData = reset($results);

// Map gender from SCF format
$gender = null;
if (!empty($licenseData['license_category'])) {
    $genderMap = ['Men' => 'M', 'Women' => 'F', 'M' => 'M', 'F' => 'F'];
    $gender = $genderMap[$licenseData['license_category']] ?? null;
}
if (!$gender && !empty($licenseData['gender'])) {
    $gender = strtoupper(substr($licenseData['gender'], 0, 1));
}

echo json_encode([
    'success' => true,
    'source' => 'scf',
    'rider' => [
        'firstname' => $licenseData['firstname'] ?? '',
        'lastname' => $licenseData['lastname'] ?? '',
        'birth_year' => $licenseData['birth_year'] ?? '',
        'gender' => $gender ?? '',
        'nationality' => $licenseData['nationality'] ?? 'SWE',
        'club_name' => $licenseData['club_name'] ?? '',
        'license_type' => $licenseData['license_type'] ?? '',
        'license_category' => $licenseData['license_category'] ?? '',
        'discipline' => $licenseData['discipline'] ?? '',
        'uci_id' => $licenseData['uci_id'] ?? $uciClean,
    ]
]);

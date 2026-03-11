<?php
/**
 * API: Org number lookup via Bolagsverket
 *
 * GET /api/org-lookup.php?org_number=XXXXXX-XXXX
 *
 * Returns JSON: { success: true, data: { org_name, org_address, org_postal_code, org_city } }
 * Or: { success: false, error: "..." }
 *
 * Requires admin login.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');

// Must be logged in as admin/promotor
if (empty($_SESSION['admin_role'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Ej inloggad']);
    exit;
}

$orgNumber = trim($_GET['org_number'] ?? '');
if (empty($orgNumber)) {
    echo json_encode(['success' => false, 'error' => 'Organisationsnummer krävs']);
    exit;
}

// Normalize: remove everything except digits and dash
$cleaned = preg_replace('/[^0-9\-]/', '', $orgNumber);
$digits = preg_replace('/[^0-9]/', '', $cleaned);

if (strlen($digits) !== 10) {
    echo json_encode(['success' => false, 'error' => 'Ogiltigt organisationsnummer (ska vara 10 siffror)']);
    exit;
}

// Try Bolagsverket API first
require_once __DIR__ . '/../includes/BolagsverketService.php';

$bvService = new BolagsverketService();

if ($bvService->isConfigured()) {
    $result = $bvService->lookupByOrgNumber($orgNumber);
    if ($result && !empty($result['org_name'])) {
        echo json_encode([
            'success' => true,
            'source' => 'bolagsverket',
            'data' => $result,
        ]);
        exit;
    }
}

// Fallback: not configured or no result
echo json_encode([
    'success' => false,
    'error' => $bvService->isConfigured()
        ? 'Kunde inte hitta organisationen hos Bolagsverket'
        : 'Bolagsverket API är inte konfigurerat ännu. Fyll i uppgifterna manuellt.',
    'not_configured' => !$bvService->isConfigured(),
]);

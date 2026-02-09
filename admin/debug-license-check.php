<?php
/**
 * Debug License Check
 * Test SCF API license verification for a specific rider
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

header('Content-Type: text/plain; charset=utf-8');

$riderId = intval($_GET['rider_id'] ?? 0);
$eventId = intval($_GET['event_id'] ?? 0);

if (!$riderId) {
    echo "ERROR: No rider_id provided\n";
    echo "Usage: debug-license-check.php?rider_id=X&event_id=Y\n";
    exit;
}

echo "LICENSE CHECK DEBUG\n";
echo str_repeat('=', 60) . "\n\n";

// Get rider data
$pdo = hub_db();
$stmt = $pdo->prepare("SELECT * FROM riders WHERE id = ?");
$stmt->execute([$riderId]);
$rider = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$rider) {
    echo "ERROR: Rider $riderId not found\n";
    exit;
}

echo "RIDER INFO:\n";
echo "  ID: {$rider['id']}\n";
echo "  Name: {$rider['firstname']} {$rider['lastname']}\n";
echo "  Birth Year: {$rider['birth_year']}\n";
echo "  Gender: {$rider['gender']}\n";
echo "  UCI/License Number: {$rider['license_number']}\n";
echo "  License Year: " . ($rider['license_year'] ?? 'NULL') . "\n";
echo "  License Valid Until: " . ($rider['license_valid_until'] ?? 'NULL') . "\n";
echo "  License Type: " . ($rider['license_type'] ?? 'NULL') . "\n";
echo "\n";

// Check license status
$eventDate = $eventId ? $pdo->query("SELECT date FROM events WHERE id = $eventId")->fetchColumn() : date('Y-m-d');
$eventTimestamp = strtotime($eventDate);
$eventYear = date('Y', $eventTimestamp);

echo "EVENT INFO:\n";
echo "  Event ID: " . ($eventId ?: 'N/A') . "\n";
echo "  Event Date: $eventDate\n";
echo "  Event Year: $eventYear\n";
echo "\n";

$licenseStatus = 'none';
if (!empty($rider['license_valid_until'])) {
    $licenseExpiry = strtotime($rider['license_valid_until']);
    $licenseStatus = ($licenseExpiry >= $eventTimestamp) ? 'valid' : 'expired';
} elseif (!empty($rider['license_year'])) {
    $licenseExpiry = strtotime($rider['license_year'] . '-12-31');
    $licenseStatus = ($licenseExpiry >= $eventTimestamp) ? 'valid' : 'expired';
}

echo "LOCAL LICENSE STATUS: $licenseStatus\n";
echo "\n";

// Try SCF API check
echo str_repeat('-', 60) . "\n";
echo "SCF API CHECK:\n\n";

$scfApiKey = env('SCF_API_KEY', '');
if (empty($scfApiKey)) {
    echo "ERROR: SCF_API_KEY not configured in .env\n";
    exit;
}

echo "API Key: " . substr($scfApiKey, 0, 10) . "...\n\n";

if (empty($rider['license_number'])) {
    echo "ERROR: Rider has no license_number\n";
    exit;
}

try {
    require_once __DIR__ . '/../includes/SCFLicenseService.php';
    $scfService = new SCFLicenseService($scfApiKey, getDB());
    $scfService->setDebug(true);

    $uciId = $scfService->normalizeUciId($rider['license_number']);
    echo "Normalized UCI ID: $uciId\n\n";

    echo "Calling SCF API for year $eventYear...\n";
    $scfResults = $scfService->lookupByUciIds([$uciId], $eventYear);

    echo "\nAPI Response:\n";
    if (empty($scfResults)) {
        echo "  EMPTY - No results returned\n";
    } else {
        echo "  Results count: " . count($scfResults) . "\n";
        echo "  Keys: " . implode(', ', array_keys($scfResults)) . "\n\n";

        if (isset($scfResults[$uciId])) {
            echo "  License data for $uciId:\n";
            $scfLicense = $scfResults[$uciId];
            foreach ($scfLicense as $key => $value) {
                echo "    $key: " . (is_array($value) ? json_encode($value) : $value) . "\n";
            }

            echo "\n";
            if (!empty($scfLicense['license_year']) && $scfLicense['license_year'] >= $eventYear) {
                echo "  ✓ LICENSE IS VALID for $eventYear\n";
                echo "  → Should update local database and remove warning\n";
            } else {
                echo "  ✗ LICENSE NOT VALID\n";
                echo "    license_year: " . ($scfLicense['license_year'] ?? 'NULL') . "\n";
                echo "    event_year: $eventYear\n";
            }
        } else {
            echo "  ERROR: UCI $uciId not found in results\n";
            echo "  Available keys: " . implode(', ', array_keys($scfResults)) . "\n";
        }
    }

} catch (Exception $e) {
    echo "\nERROR: " . $e->getMessage() . "\n";
    echo "\nStack trace:\n" . $e->getTraceAsString() . "\n";
}

echo "\n" . str_repeat('=', 60) . "\n";
echo "DEBUG COMPLETE\n";

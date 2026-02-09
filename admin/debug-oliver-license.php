<?php
/**
 * Quick Debug - Oliver Andersen License Check
 */

// Force error display
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: text/plain; charset=utf-8');

echo "Starting debug...\n";

require_once __DIR__ . '/../config.php';
echo "Config loaded\n";

require_once __DIR__ . '/../includes/auth.php';
echo "Auth loaded\n";

requireAdmin();
echo "Auth checked\n";

$pdo = hub_db();

// Find Oliver
$stmt = $pdo->prepare("SELECT * FROM riders WHERE firstname = 'Oliver' AND lastname = 'Andersen' LIMIT 1");
$stmt->execute();
$rider = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$rider) {
    echo "ERROR: Oliver Andersen not found in database\n";
    echo "\nSearching for similar names:\n";
    $stmt = $pdo->query("SELECT id, firstname, lastname FROM riders WHERE firstname LIKE '%Oliver%' OR lastname LIKE '%Andersen%' LIMIT 10");
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "  ID {$r['id']}: {$r['firstname']} {$r['lastname']}\n";
    }
    exit;
}

echo "OLIVER ANDERSEN LICENSE DEBUG\n";
echo str_repeat('=', 60) . "\n\n";

echo "RIDER INFO:\n";
echo "  ID: {$rider['id']}\n";
echo "  Name: {$rider['firstname']} {$rider['lastname']}\n";
echo "  Birth Year: {$rider['birth_year']}\n";
echo "  Gender: {$rider['gender']}\n";
echo "  UCI: {$rider['license_number']}\n";
echo "  License Year: " . ($rider['license_year'] ?? 'NULL') . "\n";
echo "  License Valid Until: " . ($rider['license_valid_until'] ?? 'NULL') . "\n";
echo "\n";

// Check SCF
$scfApiKey = env('SCF_API_KEY', '');
if (empty($scfApiKey)) {
    echo "ERROR: No SCF_API_KEY configured\n";
    exit;
}

echo "SCF API CHECK:\n\n";

try {
    require_once __DIR__ . '/../includes/SCFLicenseService.php';
    $scfService = new SCFLicenseService($scfApiKey, getDB());

    $uciId = $scfService->normalizeUciId($rider['license_number']);
    echo "UCI (normalized): $uciId\n";
    echo "Checking year: 2026\n\n";

    $results = $scfService->lookupByUciIds([$uciId], 2026);

    if (empty($results)) {
        echo "SCF API returned EMPTY\n";
    } else {
        echo "SCF API returned " . count($results) . " result(s)\n\n";

        if (isset($results[$uciId])) {
            $license = $results[$uciId];
            echo "LICENSE DATA:\n";
            foreach ($license as $key => $val) {
                echo "  $key: " . (is_array($val) ? json_encode($val) : $val) . "\n";
            }

            echo "\n";
            if (!empty($license['license_year']) && $license['license_year'] >= 2026) {
                echo "✓ LICENSE IS VALID FOR 2026\n";
                echo "✓ Should NOT show 'licensåtagande' warning\n";
            } else {
                echo "✗ License not valid\n";
                echo "  license_year: " . ($license['license_year'] ?? 'NULL') . "\n";
            }
        } else {
            echo "ERROR: UCI $uciId not in results\n";
            echo "Available keys: " . implode(', ', array_keys($results)) . "\n";
        }
    }

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}

echo "\n" . str_repeat('=', 60) . "\n";

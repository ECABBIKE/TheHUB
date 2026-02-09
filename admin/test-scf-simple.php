<?php
/**
 * SIMPLEST POSSIBLE SCF TEST
 * No database, no auth, just raw API call
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);
header('Content-Type: text/plain; charset=utf-8');

echo "SCF API SIMPLE TEST\n";
echo str_repeat('=', 60) . "\n\n";

// Get API key from .env
$envFile = __DIR__ . '/../.env';
if (!file_exists($envFile)) {
    echo "ERROR: .env file not found at $envFile\n";
    exit;
}

$envContent = file_get_contents($envFile);
preg_match('/SCF_API_KEY=(.+)/', $envContent, $matches);
$apiKey = trim($matches[1] ?? '');

if (empty($apiKey)) {
    echo "ERROR: SCF_API_KEY not found in .env\n";
    exit;
}

echo "API Key found: " . substr($apiKey, 0, 10) . "...\n\n";

// Test UCI: Oliver Andersen = 100 973 251 34
$uciId = '10097325134';
$year = 2026;

echo "Testing UCI: $uciId\n";
echo "Year: $year\n\n";

$url = "https://scf.license-portal.com/api/v1/ucilicenselookup?year=$year&uciids=$uciId";

echo "API URL: $url\n\n";
echo "Calling API...\n";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'X-Api-Key: ' . $apiKey,
    'Accept: application/json'
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

echo "HTTP Code: $httpCode\n";
if ($error) {
    echo "cURL Error: $error\n";
}

echo "\nRaw Response:\n";
echo $response . "\n\n";

if ($httpCode === 200 && $response) {
    $data = json_decode($response, true);
    if ($data) {
        echo "Parsed JSON:\n";
        print_r($data);
    } else {
        echo "ERROR: Could not parse JSON\n";
    }
} else {
    echo "ERROR: API call failed\n";
}

echo "\n" . str_repeat('=', 60) . "\n";

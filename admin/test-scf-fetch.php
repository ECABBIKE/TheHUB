<?php
/**
 * Quick SCF API test - fetch one known rider
 */
require_once __DIR__ . '/../config.php';
require_admin();

$apiKey = env('SCF_API_KEY', '');

if (!$apiKey) {
    die("SCF_API_KEY saknas i .env");
}

// Test with Jenny Rissveds - known Swedish MTB rider
$url = 'https://licens.scf.se/api/1.0/licenselookup?' . http_build_query([
    'year' => 2026,
    'firstname' => 'Jenny',
    'lastname' => 'Rissveds',
    'gender' => 'F'
]);

echo "<h2>SCF API Test</h2>";
echo "<p><strong>URL:</strong> <code>$url</code></p>";

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $apiKey,
        'Accept: application/json'
    ]
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

echo "<p><strong>HTTP Status:</strong> $httpCode</p>";

if ($error) {
    echo "<p style='color:red'><strong>Error:</strong> $error</p>";
} else {
    echo "<h3>Response:</h3>";
    echo "<pre>" . htmlspecialchars(json_encode(json_decode($response), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . "</pre>";
}

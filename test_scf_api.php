<?php
/**
 * SCF License API Quick Test
 */

// Ladda .env
function loadEnv($path) {
    if (!file_exists($path)) {
        die("Error: .env file not found at $path\n");
    }
    
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $env = [];
    
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        
        list($key, $value) = explode('=', $line, 2);
        $env[trim($key)] = trim($value, '"\'');
    }
    
    return $env;
}

$env = loadEnv(__DIR__ . '/.env');
$API_KEY = $env['SCF_API_KEY'] ?? null;
$API_URL = $env['SCF_API_URL'] ?? 'https://licens.scf.se/api/1.0';

if (!$API_KEY) {
    die("❌ Error: SCF_API_KEY not found in .env\n");
}

echo "=== SCF License API Test ===\n";
echo "API Key: " . substr($API_KEY, 0, 8) . "...\n";
echo "API URL: $API_URL\n\n";

// Test med exempel UCI ID (ändra detta till en riktig från din databas)
echo "Test 1: Lookup by UCI ID\n";
echo "Testing with UCI ID 10009189684 (example - ändra om du vill)\n";
echo "Or enter your own UCI ID: ";
$uciId = trim(fgets(STDIN));

if (empty($uciId)) {
    $uciId = "10009189684"; // Fallback exempel
}

$url = "$API_URL/ucilicenselookup?year=2026&uciids=" . urlencode($uciId);

echo "\nCalling: $url\n";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $API_KEY
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

if (curl_errno($ch)) {
    echo "cURL Error: " . curl_error($ch) . "\n";
    curl_close($ch);
    exit(1);
}

curl_close($ch);

echo "\nHTTP Status: $httpCode\n";
echo str_repeat("=", 80) . "\n";
echo "Raw Response:\n";
echo $response . "\n";
echo str_repeat("=", 80) . "\n\n";

$data = json_decode($response, true);

if (json_last_error() === JSON_ERROR_NONE && $data) {
    echo "✓ JSON parsed successfully\n\n";
    echo "Data Structure:\n";
    echo str_repeat("-", 80) . "\n";
    print_r($data);
    echo str_repeat("-", 80) . "\n\n";
    
    // Analysera strukturen
    if (is_array($data)) {
        if (isset($data[0])) {
            echo "📋 Fields returned per license:\n";
            foreach (array_keys($data[0]) as $field) {
                $value = $data[0][$field];
                $type = gettype($value);
                $preview = is_string($value) ? substr($value, 0, 50) : $value;
                echo "  - $field ($type): $preview\n";
            }
        } else {
            echo "📋 Top-level fields:\n";
            foreach (array_keys($data) as $field) {
                echo "  - $field\n";
            }
        }
    }
} else {
    echo "❌ Failed to parse JSON: " . json_last_error_msg() . "\n";
}

echo "\n=== Test Complete ===\n";
echo "\nNext: Run 'php show_riders_structure.php' to see your database structure\n";
?>

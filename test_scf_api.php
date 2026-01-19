<?php
/**
 * SCF License API Web Test
 * Kör detta i webbläsaren: https://thehub.gravityseries.se/test_scf_api_web.php
 */

// Ladda .env
function loadEnv($path) {
    if (!file_exists($path)) {
        die("Error: .env file not found at $path");
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
    die("❌ Error: SCF_API_KEY not found in .env");
}

// Hämta UCI ID från URL eller använd exempel
$uciId = $_GET['uci_id'] ?? '10009189684';

?>
<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SCF License API Test</title>
    <style>
        body {
            font-family: 'Inter', -apple-system, sans-serif;
            max-width: 1200px;
            margin: 40px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #171717;
            border-bottom: 3px solid #61CE70;
            padding-bottom: 10px;
        }
        .info {
            background: #f0f9ff;
            border-left: 4px solid #004a98;
            padding: 15px;
            margin: 20px 0;
        }
        .success {
            background: #f0fdf4;
            border-left: 4px solid #61CE70;
            padding: 15px;
            margin: 20px 0;
        }
        .error {
            background: #fef2f2;
            border-left: 4px solid #ef4444;
            padding: 15px;
            margin: 20px 0;
        }
        pre {
            background: #1e1e1e;
            color: #d4d4d4;
            padding: 20px;
            border-radius: 5px;
            overflow-x: auto;
            font-size: 13px;
            line-height: 1.5;
        }
        .form-group {
            margin: 20px 0;
        }
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
        }
        input[type="text"] {
            width: 100%;
            padding: 10px;
            border: 2px solid #e0e0e0;
            border-radius: 5px;
            font-size: 16px;
        }
        button {
            background: #61CE70;
            color: white;
            padding: 12px 24px;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: 0.25s ease;
        }
        button:hover {
            filter: brightness(1.1);
        }
        .field-list {
            background: #fafafa;
            padding: 15px;
            border-radius: 5px;
            margin: 10px 0;
        }
        .field-item {
            padding: 8px 0;
            border-bottom: 1px solid #e0e0e0;
            font-family: 'Courier New', monospace;
        }
        .field-item:last-child {
            border-bottom: none;
        }
        .field-name {
            color: #004a98;
            font-weight: 600;
        }
        .field-type {
            color: #7A7A7A;
            font-size: 12px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 SCF License API Test</h1>
        
        <div class="info">
            <strong>API Configuration:</strong><br>
            API Key: <?php echo substr($API_KEY, 0, 8); ?>...<br>
            API URL: <?php echo htmlspecialchars($API_URL); ?>
        </div>

        <form method="GET">
            <div class="form-group">
                <label for="uci_id">Test med UCI ID:</label>
                <input type="text" id="uci_id" name="uci_id" value="<?php echo htmlspecialchars($uciId); ?>" placeholder="T.ex. 10009189684">
            </div>
            <button type="submit">🚀 Testa API</button>
        </form>

        <?php
        // Gör API-anrop
        $url = "$API_URL/ucilicenselookup?year=2026&uciids=" . urlencode($uciId);
        
        echo "<div class='info'><strong>Request URL:</strong><br><code>" . htmlspecialchars($url) . "</code></div>";
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $API_KEY
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if (curl_errno($ch)) {
            echo "<div class='error'><strong>cURL Error:</strong> " . curl_error($ch) . "</div>";
            curl_close($ch);
            exit;
        }
        
        curl_close($ch);
        
        echo "<div class='info'><strong>HTTP Status:</strong> $httpCode</div>";
        
        echo "<h2>📄 Raw Response</h2>";
        echo "<pre>" . htmlspecialchars($response) . "</pre>";
        
        $data = json_decode($response, true);
        
        if (json_last_error() === JSON_ERROR_NONE && $data) {
            echo "<div class='success'><strong>✓ JSON parsed successfully</strong></div>";
            
            echo "<h2>📊 Parsed Data Structure</h2>";
            echo "<pre>" . print_r($data, true) . "</pre>";
            
            // Analysera strukturen
            if (is_array($data) && !empty($data)) {
                if (isset($data[0]) && is_array($data[0])) {
                    echo "<h2>🏷️ Fields per License</h2>";
                    echo "<div class='field-list'>";
                    
                    foreach ($data[0] as $field => $value) {
                        $type = gettype($value);
                        $preview = is_string($value) ? htmlspecialchars(substr($value, 0, 100)) : htmlspecialchars(print_r($value, true));
                        
                        echo "<div class='field-item'>";
                        echo "<span class='field-name'>$field</span> ";
                        echo "<span class='field-type'>($type)</span><br>";
                        echo "<small>$preview</small>";
                        echo "</div>";
                    }
                    
                    echo "</div>";
                    
                    echo "<div class='success'>";
                    echo "<strong>✓ Antal licenser returnerade:</strong> " . count($data);
                    echo "</div>";
                } else {
                    echo "<h2>🏷️ Top-level Fields</h2>";
                    echo "<div class='field-list'>";
                    foreach (array_keys($data) as $field) {
                        echo "<div class='field-item'><span class='field-name'>$field</span></div>";
                    }
                    echo "</div>";
                }
            } else {
                echo "<div class='error'>⚠️ Response is empty or not an array</div>";
            }
        } else {
            echo "<div class='error'><strong>❌ Failed to parse JSON:</strong> " . json_last_error_msg() . "</div>";
        }
        ?>

        <div style="margin-top: 40px; padding-top: 20px; border-top: 2px solid #e0e0e0;">
            <h3>📋 Next Steps</h3>
            <ol>
                <li>Notera vilka fält som returneras ovan (t.ex. <code>license_number</code>, <code>first_name</code>, etc.)</li>
                <li>Kör <code>show_riders_structure.php</code> för att se din databasstruktur</li>
                <li>Berätta för Claude vilka fält som ska mappas till vilka databas-kolumner</li>
            </ol>
        </div>
    </div>
</body>
</html>

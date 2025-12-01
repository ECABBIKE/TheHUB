<?php
/**
 * Full Page Load Debug
 * Simulates exactly what happens when you visit /calendar/256
 * DELETE THIS FILE AFTER DEBUGGING
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<pre style='background:#1a1a2e; color:#10B981; padding:20px; font-family:monospace;'>";
echo "=== FULL PAGE LOAD DEBUG ===\n\n";

// Step 1: Simulate the URL
$_GET['page'] = 'calendar/256';
echo "1. Simulated URL: /calendar/256\n";
echo "   \$_GET['page'] = '{$_GET['page']}'\n\n";

// Step 2: Load config
echo "2. Loading v3-config.php...\n";
try {
    require_once __DIR__ . '/v3-config.php';
    echo "   ✓ Config loaded\n";
    echo "   HUB_V3_ROOT: " . HUB_V3_ROOT . "\n";
} catch (Exception $e) {
    echo "   ✗ Error: " . $e->getMessage() . "\n";
    exit;
}

// Step 3: Check login status
echo "\n3. Authentication check:\n";
echo "   hub_is_logged_in(): " . (hub_is_logged_in() ? 'TRUE (logged in)' : 'FALSE (not logged in)') . "\n";

// Step 4: Load router
echo "\n4. Loading router.php...\n";
try {
    require_once __DIR__ . '/router.php';
    echo "   ✓ Router loaded\n";
} catch (Exception $e) {
    echo "   ✗ Error: " . $e->getMessage() . "\n";
    exit;
}

// Step 5: Check auth requirement
$section = 'calendar';
echo "\n5. Auth requirement check:\n";
echo "   Section: '$section'\n";
echo "   hub_requires_auth('$section'): " . (hub_requires_auth($section) ? 'TRUE (requires auth)' : 'FALSE (public)') . "\n";

// Step 6: Get page info
echo "\n6. Getting page info...\n";
// Temporarily disable the redirect for testing
$originalGetPage = 'hub_get_current_page';

// Parse manually to avoid redirect
$raw = trim($_GET['page'] ?? '', '/');
$segments = explode('/', $raw);
echo "   Raw: '$raw'\n";
echo "   Segments: " . json_encode($segments) . "\n";

// Step 7: Call the actual router function
echo "\n7. Calling hub_get_current_page()...\n";
ob_start();
$pageInfo = hub_get_current_page();
$output = ob_get_clean();
if ($output) {
    echo "   Output during routing: $output\n";
}
echo "   ✓ Page info obtained\n";
print_r($pageInfo);

// Step 8: Check the file
echo "\n8. File check:\n";
$file = $pageInfo['file'] ?? 'NOT SET';
echo "   File: $file\n";
echo "   Exists: " . (file_exists($file) ? 'YES' : 'NO') . "\n";
echo "   Readable: " . (is_readable($file) ? 'YES' : 'NO') . "\n";

// Step 9: Try to include the page file
echo "\n9. Attempting to include page file...\n";
if (file_exists($file)) {
    ob_start();
    try {
        include $file;
        $pageOutput = ob_get_clean();
        $outputLength = strlen($pageOutput);
        echo "   ✓ File included successfully\n";
        echo "   Output length: $outputLength bytes\n";

        if ($outputLength === 0) {
            echo "   ⚠️  WARNING: File produced NO output!\n";
        } elseif ($outputLength < 100) {
            echo "   Output: " . htmlspecialchars($pageOutput) . "\n";
        } else {
            echo "   First 500 chars of output:\n";
            echo "   " . htmlspecialchars(substr($pageOutput, 0, 500)) . "...\n";
        }
    } catch (Exception $e) {
        ob_end_clean();
        echo "   ✗ Exception: " . $e->getMessage() . "\n";
    } catch (Error $e) {
        ob_end_clean();
        echo "   ✗ Fatal Error: " . $e->getMessage() . "\n";
        echo "   File: " . $e->getFile() . "\n";
        echo "   Line: " . $e->getLine() . "\n";
    }
} else {
    echo "   ✗ File does not exist!\n";
}

// Step 10: Check session
echo "\n10. Session info:\n";
echo "   Session ID: " . session_id() . "\n";
echo "   Session data:\n";
foreach ($_SESSION as $key => $value) {
    if (is_array($value)) {
        echo "   - $key: " . json_encode($value) . "\n";
    } else {
        echo "   - $key: $value\n";
    }
}

echo "\n=== END DEBUG ===\n";
echo "</pre>";

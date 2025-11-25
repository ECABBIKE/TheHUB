<?php
/**
 * Clear PHP OpCache
 */

echo "<!DOCTYPE html><html><head><title>Clear Cache</title></head><body>";
echo "<h1>PHP Cache Clear</h1>";

// Clear opcache if available
if (function_exists('opcache_reset')) {
    if (opcache_reset()) {
        echo "<p style='color: green;'>✅ OpCache cleared successfully!</p>";
    } else {
        echo "<p style='color: red;'>❌ Failed to clear OpCache</p>";
    }
} else {
    echo "<p style='color: orange;'>⚠️ OpCache not available</p>";
}

// Clear realpath cache
clearstatcache(true);
echo "<p style='color: green;'>✅ Stat cache cleared</p>";

// Show current file modification time
$rankingFile = __DIR__ . '/../includes/ranking_functions.php';
if (file_exists($rankingFile)) {
    $modTime = filemtime($rankingFile);
    echo "<p><strong>ranking_functions.php modified:</strong> " . date('Y-m-d H:i:s', $modTime) . "</p>";

    // Check for version stamp in file
    $content = file_get_contents($rankingFile);
    if (strpos($content, '2025-11-25-002') !== false) {
        echo "<p style='color: green;'>✅ Version 2025-11-25-002 found in file!</p>";
    } else {
        echo "<p style='color: red;'>❌ Version 2025-11-25-002 NOT found in file!</p>";
        echo "<p>File might not be updated yet. Check git pull.</p>";
    }
} else {
    echo "<p style='color: red;'>❌ ranking_functions.php not found!</p>";
}

echo "<p><a href='/admin/ranking.php'>→ Go to Ranking Admin</a></p>";
echo "</body></html>";
?>

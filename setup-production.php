<?php
/**
 * Production Setup Script for InfinityFree
 *
 * Run this ONCE after deploying to set up .env file
 *
 * USAGE via browser:
 * https://thehub.infinityfree.me/setup-production.php?password=YOUR_DB_PASSWORD
 *
 * Or edit this file and add password on line 18, then visit:
 * https://thehub.infinityfree.me/setup-production.php
 *
 * DELETE THIS FILE after running!
 */

// OPTION 1: Set password here manually
$db_password = ''; // <- Paste your DB password here if not using URL parameter

// OPTION 2: Or pass via URL parameter
if (isset($_GET['password'])) {
    $db_password = $_GET['password'];
}

// Security checks
if (file_exists(__DIR__ . '/.env')) {
    die('✅ .env already exists! Setup complete.<br><br>🔒 <strong>DELETE this setup-production.php file NOW for security!</strong>');
}

if (empty($db_password)) {
    die('❌ Error: No password provided.<br><br>Either:<br>1. Visit: setup-production.php?password=YOUR_PASSWORD<br>2. Or edit this file and add password on line 18');
}

// Read template
if (!file_exists(__DIR__ . '/.env.production')) {
    die('❌ Error: .env.production template not found!');
}

$template = file_get_contents(__DIR__ . '/.env.production');

// Replace with actual password
$env_content = str_replace('YOUR_DB_PASSWORD_HERE', $db_password, $template);

// Write .env file
if (file_put_contents(__DIR__ . '/.env', $env_content)) {
    echo "<h2>✅ SUCCESS! .env file created!</h2>";
    echo "<h3>📋 Next steps:</h3>";
    echo "<ol>";
    echo "<li><strong>DELETE setup-production.php NOW!</strong> (Security risk!)</li>";
    echo "<li>Test database: <a href='/admin/test-database-connection.php'>test-database-connection.php</a></li>";
    echo "<li>Run SQL migrations in phpMyAdmin:<br>";
    echo "&nbsp;&nbsp;- database/migrations/003_import_history.sql<br>";
    echo "&nbsp;&nbsp;- database/migrations/004_point_scales.sql</li>";
    echo "</ol>";
    echo "<p style='color: red; font-weight: bold;'>🔒 IMPORTANT: DELETE setup-production.php for security!</p>";
} else {
    echo "❌ Error: Could not write .env file. Check file permissions.";
}

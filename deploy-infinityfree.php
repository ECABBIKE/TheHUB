<?php
/**
 * ONE-CLICK DEPLOY FOR INFINITYFREE
 *
 * Visit this URL once: https://thehub.infinityfree.me/deploy-infinityfree.php
 * Then DELETE this file!
 */

header('Content-Type: text/html; charset=utf-8');

// Security check - only allow running once
if (file_exists(__DIR__ . '/.env')) {
    die('
    <h2>✅ Already deployed!</h2>
    <p>.env file already exists.</p>
    <p style="color: red;"><strong>DELETE deploy-infinityfree.php for security!</strong></p>
    <p><a href="/admin/test-database-connection.php">Test Database Connection</a></p>
    ');
}

// Create .env file with InfinityFree credentials
$env_content = <<<'ENV'
# TheHUB Production Environment - InfinityFree

# ============================================================================
# APPLICATION SETTINGS
# ============================================================================
APP_ENV=production
APP_DEBUG=false

# ============================================================================
# ADMIN CREDENTIALS
# ============================================================================
ADMIN_USERNAME=admin
ADMIN_PASSWORD=changeme_immediately!

# ============================================================================
# DATABASE CONFIGURATION - InfinityFree
# ============================================================================
DB_HOST=sql206.infinityfree.com
DB_NAME=if0_40400950_THEHUB
DB_USER=if0_40400950
DB_PASS=qv19oAyv44J2xX
DB_CHARSET=utf8mb4

# ============================================================================
# SESSION CONFIGURATION
# ============================================================================
SESSION_NAME=thehub_session
SESSION_LIFETIME=86400

# ============================================================================
# FILE UPLOAD CONFIGURATION
# ============================================================================
MAX_UPLOAD_SIZE=10485760
ALLOWED_EXTENSIONS=xlsx,xls,csv

# ============================================================================
# SECURITY SETTINGS
# ============================================================================
FORCE_HTTPS=false
DISPLAY_ERRORS=false
ENV;

// Write .env file
$success = file_put_contents(__DIR__ . '/.env', $env_content);

if ($success === false) {
    die('
    <h2>❌ Error</h2>
    <p>Could not create .env file. Check file permissions on /htdocs/</p>
    ');
}

// Success!
?>
<!DOCTYPE html>
<html>
<head>
    <title>TheHUB Deploy - Success</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 800px; margin: 50px auto; padding: 20px; }
        h2 { color: #28a745; }
        .warning { background: #fff3cd; border: 2px solid #ffc107; padding: 15px; margin: 20px 0; }
        .success { background: #d4edda; border: 2px solid #28a745; padding: 15px; margin: 20px 0; }
        .step { background: #f8f9fa; padding: 10px; margin: 10px 0; border-left: 4px solid #007bff; }
        a.button { display: inline-block; background: #007bff; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; margin: 10px 5px; }
        a.button:hover { background: #0056b3; }
        .danger { background: #f8d7da; border: 2px solid #dc3545; padding: 15px; margin: 20px 0; }
    </style>
</head>
<body>
    <h2>✅ Deployment Successful!</h2>

    <div class="success">
        <strong>✓</strong> .env file created with InfinityFree credentials<br>
        <strong>✓</strong> Database configuration loaded<br>
        <strong>✓</strong> Ready to use!
    </div>

    <div class="danger">
        <h3>🔒 CRITICAL: Security Steps</h3>
        <p><strong>DELETE deploy-infinityfree.php RIGHT NOW!</strong></p>
        <p>This file contains database credentials and must be removed immediately!</p>
    </div>

    <h3>📋 Next Steps:</h3>

    <div class="step">
        <strong>1. Test Database Connection</strong><br>
        Verify everything works correctly
    </div>

    <div class="step">
        <strong>2. Run SQL Migrations</strong><br>
        Go to phpMyAdmin and run these files:<br>
        - database/migrations/003_import_history.sql<br>
        - database/migrations/004_point_scales.sql
    </div>

    <div class="step">
        <strong>3. Delete deploy-infinityfree.php</strong><br>
        Remove this file from your server for security!
    </div>

    <div class="step">
        <strong>4. Change Admin Password</strong><br>
        Edit .env and update ADMIN_PASSWORD
    </div>

    <a href="/admin/test-database-connection.php" class="button">Test Database</a>
    <a href="/admin/" class="button">Go to Admin</a>

    <hr>
    <p><small>TheHUB v2.0 - InfinityFree Deployment</small></p>
</body>
</html>

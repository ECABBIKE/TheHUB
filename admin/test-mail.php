<?php
/**
 * Mail Test Script - DELETE AFTER USE
 * Tests email configuration and sending
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/mail.php';
require_admin();

// Only super_admin can run this
if (!hasRole('super_admin')) {
    die('Access denied');
}

echo "<h1>Mail Configuration Test</h1>";
echo "<pre>";

// Test env() function
echo "=== ENV CONFIGURATION ===\n";
echo "MAIL_DRIVER: " . env('MAIL_DRIVER', 'NOT SET') . "\n";
echo "MAIL_HOST: " . env('MAIL_HOST', 'NOT SET') . "\n";
echo "MAIL_PORT: " . env('MAIL_PORT', 'NOT SET') . "\n";
echo "MAIL_ENCRYPTION: " . env('MAIL_ENCRYPTION', 'NOT SET') . "\n";
echo "MAIL_USERNAME: " . env('MAIL_USERNAME', 'NOT SET') . "\n";
echo "MAIL_PASSWORD: " . (env('MAIL_PASSWORD') ? '*** SET ***' : 'NOT SET') . "\n";
echo "MAIL_FROM_ADDRESS: " . env('MAIL_FROM_ADDRESS', 'NOT SET') . "\n";
echo "MAIL_FROM_NAME: " . env('MAIL_FROM_NAME', 'NOT SET') . "\n";

// Check which env file exists
echo "\n=== ENV FILES ===\n";
echo ".env exists: " . (file_exists(__DIR__ . '/../.env') ? 'YES' : 'NO') . "\n";
echo ".env.production exists: " . (file_exists(__DIR__ . '/../.env.production') ? 'YES' : 'NO') . "\n";

// Test sending if requested
if (isset($_GET['send']) && isset($_GET['to'])) {
    $to = filter_var($_GET['to'], FILTER_VALIDATE_EMAIL);
    if ($to) {
        echo "\n=== SENDING TEST EMAIL ===\n";
        echo "To: {$to}\n";

        $result = hub_send_email(
            $to,
            'TheHUB Test Email',
            '<h1>Test</h1><p>This is a test email from TheHUB.</p>',
            []
        );

        echo "Result: " . ($result ? 'SUCCESS' : 'FAILED') . "\n";
        echo "\nCheck /logs/error.log for details.\n";
    } else {
        echo "\nInvalid email address.\n";
    }
}

echo "</pre>";

// Show form
if (!isset($_GET['send'])) {
    echo '<form method="GET">';
    echo '<input type="hidden" name="send" value="1">';
    echo '<label>Send test email to: </label>';
    echo '<input type="email" name="to" placeholder="your@email.com" required>';
    echo '<button type="submit">Send Test</button>';
    echo '</form>';
}

echo "<p><strong>DELETE THIS FILE AFTER TESTING!</strong></p>";

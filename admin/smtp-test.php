<?php
/**
 * SMTP Test - Temporary debug page
 * DELETE THIS FILE AFTER TESTING
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/mail.php';

// Only allow admin access
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    die('Admin access required');
}

echo "<h1>SMTP Test</h1>";
echo "<pre>";

// Show current settings
echo "=== Current Mail Settings ===\n";
echo "MAIL_DRIVER: " . env('MAIL_DRIVER', 'NOT SET') . "\n";
echo "MAIL_HOST: " . env('MAIL_HOST', 'NOT SET') . "\n";
echo "MAIL_PORT: " . env('MAIL_PORT', 'NOT SET') . "\n";
echo "MAIL_ENCRYPTION: " . env('MAIL_ENCRYPTION', 'NOT SET') . "\n";
echo "MAIL_USERNAME: " . env('MAIL_USERNAME', 'NOT SET') . "\n";
echo "MAIL_PASSWORD: " . (env('MAIL_PASSWORD') ? '***SET***' : 'NOT SET') . "\n";
echo "MAIL_FROM_ADDRESS: " . env('MAIL_FROM_ADDRESS', 'NOT SET') . "\n";
echo "MAIL_FROM_NAME: " . env('MAIL_FROM_NAME', 'NOT SET') . "\n";
echo "\n";

// Test SMTP connection
echo "=== Testing SMTP Connection ===\n";
$host = env('MAIL_HOST', 'mail.hostinger.com');
$port = (int) env('MAIL_PORT', 465);
$encryption = env('MAIL_ENCRYPTION', 'ssl');

$protocol = ($encryption === 'ssl') ? 'ssl://' : '';
$address = "{$protocol}{$host}:{$port}";
echo "Connecting to: {$address}\n";

$context = stream_context_create([
    'ssl' => [
        'verify_peer' => false,
        'verify_peer_name' => false,
        'allow_self_signed' => true
    ]
]);

$socket = @stream_socket_client(
    $address,
    $errno,
    $errstr,
    10,
    STREAM_CLIENT_CONNECT,
    $context
);

if (!$socket) {
    echo "FAILED: {$errstr} ({$errno})\n";
} else {
    echo "SUCCESS: Connected!\n";

    // Read greeting
    $greeting = fgets($socket, 515);
    echo "Server greeting: {$greeting}\n";

    fclose($socket);
}

echo "\n=== Send Test Email ===\n";
if (isset($_POST['test_email'])) {
    $testEmail = $_POST['test_email'];
    echo "Sending test email to: {$testEmail}\n";

    $result = hub_send_email(
        $testEmail,
        'TheHUB Test Email',
        '<h1>Test</h1><p>This is a test email from TheHUB.</p>',
        []
    );

    echo "Result: " . ($result ? 'SUCCESS' : 'FAILED') . "\n";
}

echo "</pre>";

// Form to send test email
?>
<form method="POST">
    <input type="email" name="test_email" placeholder="your@email.com" required>
    <button type="submit">Send Test Email</button>
</form>

<p><strong>DELETE THIS FILE AFTER TESTING!</strong></p>

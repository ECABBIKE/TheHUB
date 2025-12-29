<?php
require_once __DIR__ . '/../config.php';
require_admin();

if (!hasRole('super_admin')) die('Access denied');

echo "<h1>Resend Test</h1><pre>";

// Check config
echo "=== CONFIG ===\n";
echo "MAIL_DRIVER: " . env('MAIL_DRIVER', 'NOT SET') . "\n";
echo "RESEND_API_KEY: " . (env('RESEND_API_KEY') ? substr(env('RESEND_API_KEY'), 0, 10) . '...' : 'NOT SET') . "\n";
echo "MAIL_FROM_ADDRESS: " . env('MAIL_FROM_ADDRESS', 'NOT SET') . "\n";

if (!env('RESEND_API_KEY')) {
    echo "\n❌ RESEND_API_KEY saknas i .env!\n";
    echo "\nLägg till i .env:\n";
    echo "MAIL_DRIVER=resend\n";
    echo "RESEND_API_KEY=re_UwAuMBME_4nD8gKkiJHwn6ajdcWYGhBc6\n";
    echo "MAIL_FROM_ADDRESS=onboarding@resend.dev\n";
    exit;
}

// Test send
if (isset($_GET['to'])) {
    $to = filter_var($_GET['to'], FILTER_VALIDATE_EMAIL);
    if ($to) {
        echo "\n=== SENDING TEST ===\n";

        $apiKey = env('RESEND_API_KEY');
        $fromEmail = env('MAIL_FROM_ADDRESS', 'onboarding@resend.dev');

        $data = [
            'from' => "TheHUB <{$fromEmail}>",
            'to' => [$to],
            'subject' => 'TheHUB Test',
            'html' => '<h1>Test</h1><p>Detta är ett test från TheHUB via Resend.</p>'
        ];

        echo "To: {$to}\n";
        echo "From: {$fromEmail}\n";
        echo "API Key: " . substr($apiKey, 0, 10) . "...\n\n";

        $ch = curl_init('https://api.resend.com/emails');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json'
            ],
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_TIMEOUT => 30
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        echo "HTTP Code: {$httpCode}\n";
        if ($error) echo "cURL Error: {$error}\n";
        echo "Response: {$response}\n";

        $result = json_decode($response, true);
        if ($httpCode >= 200 && $httpCode < 300) {
            echo "\n✅ MAIL SKICKAT!\n";
        } else {
            echo "\n❌ FEL: " . ($result['message'] ?? $response) . "\n";
        }
    }
}

echo "</pre>";

if (!isset($_GET['to'])) {
    echo '<form method="GET">';
    echo '<input type="email" name="to" placeholder="test@example.com" required>';
    echo '<button type="submit">Skicka test</button>';
    echo '</form>';
}

echo "<p><b>TA BORT DENNA FIL EFTERÅT!</b></p>";

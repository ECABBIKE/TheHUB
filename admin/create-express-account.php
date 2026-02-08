<?php
/**
 * Create Express Account for Payment Recipient
 */

require_once __DIR__ . '/../config.php';

if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    die('Admin access required');
}

require_once __DIR__ . '/../includes/payment/StripeClient.php';
use TheHUB\Payment\StripeClient;

$pdo = $GLOBALS['pdo'];

$stripeKey = env('STRIPE_SECRET_KEY', '');
if (empty($stripeKey)) {
    die('❌ STRIPE_SECRET_KEY saknas i .env');
}

$stripe = new StripeClient($stripeKey);

// Get recipient
$recipientId = intval($_GET['recipient_id'] ?? 0);
if (!$recipientId) {
    die('❌ recipient_id saknas');
}

$stmt = $pdo->prepare("SELECT * FROM payment_recipients WHERE id = ?");
$stmt->execute([$recipientId]);
$recipient = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$recipient) {
    die('❌ Mottagare hittades inte');
}

echo "<!DOCTYPE html><html><head><title>Skapa Express Account</title>";
echo "<style>body{font-family:monospace;padding:20px;background:#0a0a0a;color:#0f0;}</style></head><body>";
echo "<h1>Skapar Express-konto för {$recipient['name']}</h1>";

// Create Express account
$result = $stripe->createConnectedAccount([
    'type' => 'express',  // VIKTIGT: Express (inte Standard)
    'country' => 'SE',
    'email' => $recipient['email'],
    'business_type' => 'company',
    'metadata' => [
        'recipient_id' => $recipientId,
        'recipient_name' => $recipient['name']
    ]
]);

if ($result['success']) {
    $accountId = $result['account_id'];

    echo "<p>✓ Express-konto skapat: <strong>$accountId</strong></p>";

    // Update database
    $stmt = $pdo->prepare("
        UPDATE payment_recipients
        SET stripe_account_id = ?,
            stripe_account_status = 'pending',
            updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$accountId, $recipientId]);

    echo "<p>✓ Databas uppdaterad</p>";

    // Create onboarding link
    $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
    $linkResult = $stripe->createAccountLink(
        $accountId,
        $baseUrl . '/admin/payment-recipients.php?stripe_return=1&recipient_id=' . $recipientId,
        $baseUrl . '/admin/payment-recipients.php?stripe_refresh=1&recipient_id=' . $recipientId
    );

    if ($linkResult['success']) {
        echo "<p>✓ Onboarding-länk skapad</p>";
        echo "<p><a href='{$linkResult['url']}' style='color:#0ff;font-size:18px;'>→ Klicka här för att slutföra onboarding</a></p>";
    } else {
        echo "<p style='color:#f00;'>✗ Kunde inte skapa onboarding-länk: {$linkResult['error']}</p>";
    }

} else {
    echo "<p style='color:#f00;'>✗ Fel: {$result['error']}</p>";
}

echo "</body></html>";

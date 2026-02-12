<?php
/**
 * Cleanup Stripe Connected Accounts
 * Radera test-konton som inte behövs
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/payment/StripeClient.php';

use TheHUB\Payment\StripeClient;

// Require admin authentication
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    die('Admin access required');
}

$pdo = $GLOBALS['pdo'];

// Get Stripe key
$stripeKey = env('STRIPE_SECRET_KEY', '');
if (empty($stripeKey)) {
    die('STRIPE_SECRET_KEY saknas i .env');
}

// Check if test mode
if (!str_starts_with($stripeKey, 'sk_test_')) {
    die('❌ SÄKERHET: Detta script fungerar BARA i test mode (sk_test_). Du är i live mode!');
}

$stripe = new StripeClient($stripeKey);

// Get action
$action = $_GET['action'] ?? 'list';
$accountId = $_GET['account_id'] ?? '';
$recipientId = $_GET['recipient_id'] ?? 0;

?>
<!DOCTYPE html>
<html>
<head>
    <title>Cleanup Stripe Accounts</title>
    <style>
        body { font-family: monospace; padding: 20px; background: #0a0a0a; color: #0f0; }
        .account { border: 1px solid #0f0; padding: 15px; margin: 10px 0; }
        .btn {
            background: #f00;
            color: #fff;
            border: none;
            padding: 8px 16px;
            cursor: pointer;
            margin: 5px;
            text-decoration: none;
            display: inline-block;
        }
        .btn:hover { background: #ff3333; }
        .success { color: #0f0; }
        .error { color: #f00; }
        pre { background: #1a1a1a; padding: 10px; }
    </style>
</head>
<body>
    <h1>🧹 Cleanup Stripe Connected Accounts</h1>
    <p class="error">⚠ Test Mode - Konton kan raderas permanent</p>

    <?php if ($action === 'delete' && $accountId && $recipientId): ?>
        <?php
        // Delete from Stripe
        echo "<h2>Raderar konto: $accountId</h2>";

        $deleteResult = $stripe->request('DELETE', "/accounts/$accountId");

        if (!isset($deleteResult['error'])):
        ?>
            <p class="success">✓ Konto raderat från Stripe</p>

            <?php
            // Clear from database
            $stmt = $pdo->prepare("UPDATE payment_recipients SET stripe_account_id = NULL, stripe_account_status = NULL WHERE id = ?");
            $stmt->execute([$recipientId]);
            ?>

            <p class="success">✓ Databas uppdaterad</p>
            <p><a href="?action=list" class="btn" style="background:#0f0;color:#000;">← Tillbaka till listan</a></p>
        <?php else: ?>
            <p class="error">✗ Fel: <?= htmlspecialchars($deleteResult['error']['message'] ?? 'Unknown error') ?></p>
            <pre><?= json_encode($deleteResult, JSON_PRETTY_PRINT) ?></pre>
        <?php endif; ?>

    <?php else: ?>
        <h2>Anslutna konton i databasen:</h2>

        <?php
        $stmt = $pdo->query("
            SELECT id, name, stripe_account_id, stripe_account_status, email
            FROM payment_recipients
            WHERE stripe_account_id IS NOT NULL
            ORDER BY id
        ");
        $recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($recipients)):
        ?>
            <p>Inga anslutna konton hittades.</p>
        <?php else: ?>
            <?php foreach ($recipients as $recipient): ?>
                <div class="account">
                    <h3><?= htmlspecialchars($recipient['name']) ?></h3>
                    <p><strong>Database ID:</strong> <?= $recipient['id'] ?></p>
                    <p><strong>Email:</strong> <?= htmlspecialchars($recipient['email']) ?></p>
                    <p><strong>Stripe Account:</strong> <?= $recipient['stripe_account_id'] ?></p>
                    <p><strong>Status:</strong> <?= $recipient['stripe_account_status'] ?? 'unknown' ?></p>

                    <?php
                    // Get account info from Stripe
                    $accountResult = $stripe->getAccount($recipient['stripe_account_id']);

                    if ($accountResult['success']):
                    ?>
                        <p><strong>Charges enabled:</strong> <?= $accountResult['charges_enabled'] ? 'YES' : 'NO' ?></p>
                        <p><strong>Payouts enabled:</strong> <?= $accountResult['payouts_enabled'] ? 'YES' : 'NO' ?></p>
                        <p><strong>Details submitted:</strong> <?= $accountResult['details_submitted'] ? 'YES' : 'NO' ?></p>

                        <a
                            href="?action=delete&account_id=<?= urlencode($recipient['stripe_account_id']) ?>&recipient_id=<?= $recipient['id'] ?>"
                            class="btn"
                            onclick="return confirm('Är du säker? Detta raderar Stripe-kontot PERMANENT (test mode).');"
                        >
                            🗑️ Radera detta konto
                        </a>

                    <?php else: ?>
                        <p class="error">✗ Kunde inte hämta från Stripe: <?= htmlspecialchars($accountResult['error']) ?></p>

                        <a
                            href="?action=delete&account_id=<?= urlencode($recipient['stripe_account_id']) ?>&recipient_id=<?= $recipient['id'] ?>"
                            class="btn"
                            onclick="return confirm('Konto finns inte i Stripe. Vill du rensa från databasen?');"
                        >
                            🗑️ Rensa från databas
                        </a>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <hr>
        <p><a href="/admin/payment-recipients.php" style="color:#0ff;">← Tillbaka till Payment Recipients</a></p>
    <?php endif; ?>
</body>
</html>

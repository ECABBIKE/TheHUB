<?php
/**
 * Debug Stripe Connect - Visar EXAKT varför Swish inte fungerar
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/payment/StripeClient.php';

use TheHUB\Payment\StripeClient;

requireAdmin();

$pdo = $GLOBALS['pdo'];

// Get Stripe key
$stripeKey = env('STRIPE_SECRET_KEY', '');
if (empty($stripeKey)) {
    die('STRIPE_SECRET_KEY saknas i .env');
}

$stripe = new StripeClient($stripeKey);

// Get all payment recipients with Stripe accounts
$stmt = $pdo->query("
    SELECT id, name, stripe_account_id, stripe_account_status
    FROM payment_recipients
    WHERE stripe_account_id IS NOT NULL
");
$recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html>
<head>
    <title>Stripe Connect Debug</title>
    <style>
        body { font-family: monospace; padding: 20px; background: #0a0a0a; color: #0f0; }
        .account { border: 1px solid #0f0; padding: 15px; margin: 10px 0; }
        .ok { color: #0f0; }
        .error { color: #f00; }
        .warning { color: #ff0; }
        pre { background: #1a1a1a; padding: 10px; overflow-x: auto; }
    </style>
</head>
<body>
    <h1>🔍 Stripe Connect Debug</h1>

    <?php foreach ($recipients as $recipient): ?>
        <div class="account">
            <h2><?= htmlspecialchars($recipient['name']) ?></h2>
            <p><strong>Database ID:</strong> <?= $recipient['id'] ?></p>
            <p><strong>Stripe Account ID:</strong> <?= $recipient['stripe_account_id'] ?></p>
            <p><strong>Status i DB:</strong> <?= $recipient['stripe_account_status'] ?></p>

            <hr>

            <?php
            // Get account details from Stripe
            $accountResult = $stripe->getAccount($recipient['stripe_account_id']);

            if ($accountResult['success']):
            ?>
                <h3 class="ok">✓ Stripe Account hittat</h3>
                <p><strong>Email:</strong> <?= htmlspecialchars($accountResult['email']) ?></p>
                <p><strong>Charges enabled:</strong>
                    <span class="<?= $accountResult['charges_enabled'] ? 'ok' : 'error' ?>">
                        <?= $accountResult['charges_enabled'] ? '✓ YES' : '✗ NO' ?>
                    </span>
                </p>
                <p><strong>Payouts enabled:</strong>
                    <span class="<?= $accountResult['payouts_enabled'] ? 'ok' : 'error' ?>">
                        <?= $accountResult['payouts_enabled'] ? '✓ YES' : '✗ NO' ?>
                    </span>
                </p>
                <p><strong>Details submitted:</strong>
                    <span class="<?= $accountResult['details_submitted'] ? 'ok' : 'error' ?>">
                        <?= $accountResult['details_submitted'] ? '✓ YES' : '✗ NO' ?>
                    </span>
                </p>

                <?php
                // Get payment methods available for this account
                // We need to check the account's capabilities
                $response = $stripe->request('GET', '/accounts/' . $recipient['stripe_account_id']);

                if (isset($response['capabilities'])):
                ?>
                    <h3>Capabilities:</h3>
                    <pre><?= json_encode($response['capabilities'], JSON_PRETTY_PRINT) ?></pre>

                    <?php
                    // Check if Swish is in payment_method_types
                    $swishActive = false;
                    if (isset($response['settings']['payments']['statement_descriptor'])) {
                        // Account has payment settings
                    }
                    ?>
                <?php endif; ?>

                <h3>Full Account Data:</h3>
                <pre><?= json_encode($response, JSON_PRETTY_PRINT) ?></pre>

            <?php else: ?>
                <h3 class="error">✗ Stripe Account ERROR</h3>
                <p class="error"><?= htmlspecialchars($accountResult['error']) ?></p>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

    <?php if (empty($recipients)): ?>
        <p class="warning">⚠ Inga betalningsmottagare med Stripe-konto hittades</p>
    <?php endif; ?>
</body>
</html>

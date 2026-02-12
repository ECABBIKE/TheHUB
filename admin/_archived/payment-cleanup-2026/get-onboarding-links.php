<?php
/**
 * Get Onboarding Links - Direct solution
 */
require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();
$stripeKey = env('STRIPE_SECRET_KEY', '');

if (empty($stripeKey)) {
    die('Stripe API-nyckel saknas');
}

require_once __DIR__ . '/../includes/payment/StripeClient.php';
$stripe = new \TheHUB\Payment\StripeClient($stripeKey);

// Get recipients with Stripe accounts
$recipients = $db->getAll("
    SELECT id, name, stripe_account_id, stripe_account_status
    FROM payment_recipients
    WHERE stripe_account_id IS NOT NULL
");

$baseUrl = 'https://thehub.gravityseries.se';

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Onboarding-länkar</title>
    <style>
        body { font-family: system-ui; padding: 40px; background: #f5f5f5; }
        .container { background: white; padding: 30px; border-radius: 8px; max-width: 900px; margin: 0 auto; }
        .recipient { margin-bottom: 30px; padding: 20px; background: #f9f9f9; border-radius: 6px; }
        .recipient h3 { margin-top: 0; }
        .link-box { background: white; padding: 15px; border: 2px solid #635bff; border-radius: 6px; margin: 10px 0; }
        .link-box a { word-break: break-all; color: #635bff; }
        button { background: #635bff; color: white; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer; }
        button:hover { background: #5851ea; }
        .status { display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 13px; font-weight: 600; }
        .status.pending { background: #fef3c7; color: #92400e; }
        .status.active { background: #d1fae5; color: #065f46; }
        .error { background: #fee; padding: 15px; border-radius: 6px; color: #c00; margin: 10px 0; }
        .success { background: #efe; padding: 15px; border-radius: 6px; color: #060; margin: 10px 0; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔗 Onboarding-länkar för Stripe Connect</h1>
        <p>Kopiera länkarna och skicka till rätt person för att slutföra onboarding.</p>

        <?php foreach ($recipients as $r): ?>
        <div class="recipient">
            <h3>
                <?= htmlspecialchars($r['name']) ?>
                <span class="status <?= $r['stripe_account_status'] ?>">
                    <?= htmlspecialchars($r['stripe_account_status']) ?>
                </span>
            </h3>

            <p><strong>Account ID:</strong> <code><?= htmlspecialchars($r['stripe_account_id']) ?></code></p>

            <?php
            // Create account link
            $returnUrl = $baseUrl . '/admin/payment-recipients.php?stripe_return=1&recipient_id=' . $r['id'];
            $refreshUrl = $baseUrl . '/admin/payment-recipients.php?stripe_refresh=1&recipient_id=' . $r['id'];

            $result = $stripe->createAccountLink($r['stripe_account_id'], $returnUrl, $refreshUrl);

            if ($result['success'] && !empty($result['url'])):
            ?>
                <div class="success">✓ Onboarding-länk genererad</div>
                <div class="link-box">
                    <strong>Skicka denna länk till ansvarig:</strong><br>
                    <a href="<?= htmlspecialchars($result['url']) ?>" target="_blank">
                        <?= htmlspecialchars($result['url']) ?>
                    </a>
                </div>
                <button onclick="copyLink('<?= htmlspecialchars($result['url'], ENT_QUOTES) ?>')">
                    📋 Kopiera länk
                </button>
                <button onclick="window.open('<?= htmlspecialchars($result['url'], ENT_QUOTES) ?>', '_blank')">
                    🚀 Öppna länk
                </button>
            <?php else: ?>
                <div class="error">
                    <strong>Fel:</strong> <?= htmlspecialchars($result['error'] ?? 'Kunde inte skapa länk') ?>
                </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>

        <hr style="margin: 30px 0;">
        <p><a href="/admin/payment-recipients.php">← Tillbaka till Betalningsmottagare</a></p>
    </div>

    <script>
    function copyLink(url) {
        navigator.clipboard.writeText(url).then(() => {
            alert('Länk kopierad! Skicka den till rätt person.');
        });
    }
    </script>
</body>
</html>
<?php
?>

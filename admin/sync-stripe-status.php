<?php
/**
 * Sync Stripe Account Status
 * Updates payment_recipients with current status from Stripe API
 */
require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();

// Check if Stripe is configured
$stripeKey = env('STRIPE_SECRET_KEY', '');
if (empty($stripeKey)) {
    die('<div style="padding: 20px; background: #fee; border: 1px solid #c00; border-radius: 8px;">Stripe API-nyckel saknas i .env</div>');
}

require_once __DIR__ . '/../includes/payment/StripeClient.php';
$stripe = new \TheHUB\Payment\StripeClient($stripeKey);

echo '<html><head><meta charset="UTF-8"><title>Synka Stripe Status</title></head><body style="font-family: system-ui; padding: 40px; background: #f5f5f5;">';
echo '<h1>Synkar Stripe-status från API</h1>';
echo '<div style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">';

// Get all recipients with Stripe accounts
$recipients = $db->getAll("
    SELECT id, name, stripe_account_id, stripe_account_status
    FROM payment_recipients
    WHERE stripe_account_id IS NOT NULL
");

if (empty($recipients)) {
    echo '<p>Inga mottagare med Stripe-konton hittades.</p>';
} else {
    echo '<table style="width: 100%; border-collapse: collapse;">';
    echo '<thead><tr style="background: #f0f0f0;">';
    echo '<th style="padding: 12px; text-align: left;">Mottagare</th>';
    echo '<th style="padding: 12px; text-align: left;">Account ID</th>';
    echo '<th style="padding: 12px; text-align: left;">Gammal status</th>';
    echo '<th style="padding: 12px; text-align: left;">Ny status</th>';
    echo '<th style="padding: 12px; text-align: left;">Resultat</th>';
    echo '</tr></thead><tbody>';

    foreach ($recipients as $r) {
        echo '<tr style="border-bottom: 1px solid #e0e0e0;">';
        echo '<td style="padding: 12px;">' . htmlspecialchars($r['name']) . '</td>';
        echo '<td style="padding: 12px;"><code>' . htmlspecialchars($r['stripe_account_id']) . '</code></td>';
        echo '<td style="padding: 12px;">' . htmlspecialchars($r['stripe_account_status'] ?? 'null') . '</td>';

        // Get account status from Stripe
        $account = $stripe->getAccount($r['stripe_account_id']);

        if (!$account['success']) {
            echo '<td style="padding: 12px;">-</td>';
            echo '<td style="padding: 12px; color: #c00;">❌ Fel: ' . htmlspecialchars($account['error'] ?? 'Okänt fel') . '</td>';
        } else {
            // Determine status
            $status = 'pending';
            if ($account['charges_enabled'] && $account['payouts_enabled']) {
                $status = 'active';
            } elseif ($account['details_submitted']) {
                $status = 'restricted';
            }

            echo '<td style="padding: 12px;"><strong>' . htmlspecialchars($status) . '</strong></td>';

            // Update database
            $db->update('payment_recipients', [
                'stripe_account_status' => $status
            ], 'id = ?', [$r['id']]);

            $color = $status === 'active' ? '#0a0' : '#f90';
            echo '<td style="padding: 12px; color: ' . $color . ';"><strong>✓ Uppdaterad</strong></td>';
        }

        echo '</tr>';
    }

    echo '</tbody></table>';
}

echo '</div>';
echo '<p style="margin-top: 20px;"><a href="/admin/payment-recipients.php" style="display: inline-block; padding: 12px 24px; background: #635bff; color: white; text-decoration: none; border-radius: 6px;">← Tillbaka till Betalningsmottagare</a></p>';
echo '</body></html>';
?>

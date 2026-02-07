<?php
/**
 * Debug Swish - Check why Swish doesn't show in checkout
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/payment/StripeClient.php';

use TheHUB\Payment\StripeClient;

$db = getDB();
$stripeKey = env('STRIPE_SECRET_KEY', '');

echo '<html><head><meta charset="UTF-8"><title>Debug Swish</title></head>';
echo '<body style="font-family: system-ui; padding: 40px; background: #f5f5f5;">';
echo '<div style="background: white; padding: 30px; border-radius: 8px; max-width: 900px; margin: 0 auto;">';
echo '<h1>🔍 Debug Swish</h1>';

echo '<h2>1. Stripe Configuration</h2>';
echo '<table style="width: 100%; border-collapse: collapse; margin: 10px 0;">';
echo '<tr><td style="padding: 8px; border: 1px solid #ddd;"><strong>API Key Type:</strong></td>';
echo '<td style="padding: 8px; border: 1px solid #ddd;">' . (strpos($stripeKey, 'sk_live_') === 0 ? '<span style="color: green;">LIVE</span>' : '<span style="color: orange;">TEST</span>') . '</td></tr>';
echo '<tr><td style="padding: 8px; border: 1px solid #ddd;"><strong>Key (first 20 chars):</strong></td>';
echo '<td style="padding: 8px; border: 1px solid #ddd;"><code>' . substr($stripeKey, 0, 20) . '...</code></td></tr>';
echo '</table>';

echo '<h2>2. ECAB Connected Account</h2>';
$ecab = $db->getOne("SELECT * FROM payment_recipients WHERE id = 2");
if ($ecab) {
    echo '<table style="width: 100%; border-collapse: collapse; margin: 10px 0;">';
    echo '<tr><td style="padding: 8px; border: 1px solid #ddd;"><strong>Name:</strong></td>';
    echo '<td style="padding: 8px; border: 1px solid #ddd;">' . htmlspecialchars($ecab['name']) . '</td></tr>';
    echo '<tr><td style="padding: 8px; border: 1px solid #ddd;"><strong>Stripe Account ID:</strong></td>';
    echo '<td style="padding: 8px; border: 1px solid #ddd;"><code>' . htmlspecialchars($ecab['stripe_account_id'] ?? 'NULL') . '</code></td></tr>';
    echo '<tr><td style="padding: 8px; border: 1px solid #ddd;"><strong>Status:</strong></td>';
    echo '<td style="padding: 8px; border: 1px solid #ddd;">' . htmlspecialchars($ecab['stripe_account_status'] ?? 'NULL') . '</td></tr>';
    echo '</table>';

    if ($ecab['stripe_account_id']) {
        $stripe = new StripeClient($stripeKey);
        $account = $stripe->getAccount($ecab['stripe_account_id']);

        echo '<h3>Account Details from Stripe:</h3>';
        echo '<pre style="background: #f5f5f5; padding: 15px; border-radius: 6px; overflow-x: auto;">';
        echo 'Charges Enabled: ' . ($account['charges_enabled'] ? 'YES' : 'NO') . "\n";
        echo 'Payouts Enabled: ' . ($account['payouts_enabled'] ? 'YES' : 'NO') . "\n";
        echo 'Details Submitted: ' . ($account['details_submitted'] ? 'YES' : 'NO') . "\n";
        echo '</pre>';

        // Check payment method types via API
        echo '<h3>Capabilities:</h3>';
        $response = $stripe->request('GET', '/accounts/' . $ecab['stripe_account_id']);
        if (isset($response['capabilities'])) {
            echo '<pre style="background: #f5f5f5; padding: 15px; border-radius: 6px; overflow-x: auto;">';
            foreach ($response['capabilities'] as $cap => $status) {
                echo "$cap: $status\n";
            }
            echo '</pre>';
        }
    }
}

echo '<h2>3. Event 356</h2>';
$event = $db->getOne("SELECT * FROM events WHERE id = 356");
if ($event) {
    echo '<table style="width: 100%; border-collapse: collapse; margin: 10px 0;">';
    echo '<tr><td style="padding: 8px; border: 1px solid #ddd;"><strong>Payment Recipient ID:</strong></td>';
    echo '<td style="padding: 8px; border: 1px solid #ddd;">' . ($event['payment_recipient_id'] ?? 'NULL') . '</td></tr>';
    echo '</table>';
}

echo '<h2>4. Checkout Configuration</h2>';
echo '<p>Check <code>/api/create-checkout-session.php</code>:</p>';
echo '<pre style="background: #f5f5f5; padding: 15px; border-radius: 6px; overflow-x: auto;">';
echo "Does it specify payment_method_types?\n";
echo "Default Stripe behavior: Shows card, apple_pay, google_pay automatically\n";
echo "For Swish: Must be explicitly enabled OR use automatic payment methods\n";
echo '</pre>';

echo '</div></body></html>';
?>

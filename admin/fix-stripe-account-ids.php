<?php
/**
 * Fix Stripe Account IDs in database
 * Updates payment_recipients with correct Account IDs from Stripe
 */
require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();

echo '<html><head><meta charset="UTF-8"><title>Fix Stripe Account IDs</title></head>';
echo '<body style="font-family: system-ui; padding: 40px; background: #f5f5f5;">';
echo '<div style="background: white; padding: 30px; border-radius: 8px; max-width: 800px; margin: 0 auto;">';
echo '<h1>🔧 Fixar Stripe Account IDs</h1>';

// Correct Account IDs from Stripe Dashboard
$updates = [
    [
        'id' => 1,
        'name' => 'Ride and Develop',
        'old_account_id' => 'acct_1Sw1QIDd86Np5B8k',
        'new_account_id' => 'acct_1Sy8jTRatf03TS0Y'
    ],
    [
        'id' => 2,
        'name' => 'Edvinsson Consulting AB',
        'old_account_id' => 'acct_1Sw1SSDF8drbPC3o',
        'new_account_id' => 'acct_1SvysEDEXJRFV9od'
    ]
];

echo '<table style="width: 100%; border-collapse: collapse; margin: 20px 0;">';
echo '<thead><tr style="background: #f0f0f0;">';
echo '<th style="padding: 12px; text-align: left;">Mottagare</th>';
echo '<th style="padding: 12px; text-align: left;">Gammalt ID</th>';
echo '<th style="padding: 12px; text-align: left;">Nytt ID</th>';
echo '<th style="padding: 12px; text-align: left;">Status</th>';
echo '</tr></thead><tbody>';

foreach ($updates as $update) {
    echo '<tr style="border-bottom: 1px solid #e0e0e0;">';
    echo '<td style="padding: 12px;"><strong>' . htmlspecialchars($update['name']) . '</strong></td>';
    echo '<td style="padding: 12px;"><code style="font-size: 11px; color: #c00;">' . htmlspecialchars($update['old_account_id']) . '</code></td>';
    echo '<td style="padding: 12px;"><code style="font-size: 11px; color: #060;">' . htmlspecialchars($update['new_account_id']) . '</code></td>';

    try {
        $result = $db->update('payment_recipients', [
            'stripe_account_id' => $update['new_account_id']
        ], 'id = ?', [$update['id']]);

        echo '<td style="padding: 12px; color: #060;"><strong>✓ Uppdaterad</strong></td>';
    } catch (Exception $e) {
        echo '<td style="padding: 12px; color: #c00;"><strong>✗ Fel:</strong> ' . htmlspecialchars($e->getMessage()) . '</td>';
    }

    echo '</tr>';
}

echo '</tbody></table>';

echo '<div style="background: #d1fae5; padding: 15px; border-radius: 6px; margin: 20px 0;">';
echo '<strong style="color: #065f46;">✓ Klart!</strong><br>';
echo 'Account IDs är nu uppdaterade med rätt värden från Stripe.';
echo '</div>';

echo '<p><a href="/admin/get-onboarding-links.php" style="display: inline-block; padding: 12px 24px; background: #635bff; color: white; text-decoration: none; border-radius: 6px; margin-right: 10px;">→ Generera onboarding-länkar</a>';
echo '<a href="/admin/payment-recipients.php" style="display: inline-block; padding: 12px 24px; background: #e5e7eb; color: #1f2937; text-decoration: none; border-radius: 6px;">← Tillbaka</a></p>';

echo '</div></body></html>';
?>

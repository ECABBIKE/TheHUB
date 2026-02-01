<?php
/**
 * Stripe Connect Management
 *
 * Allows admins to:
 * - Connect payment recipients to Stripe
 * - Complete onboarding
 * - View account status
 * - Access Stripe Express dashboard
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/payment/StripeClient.php';
require_admin();

use TheHUB\Payment\StripeClient;

$db = getDB();
$isSuperAdmin = hasRole('super_admin');

if (!$isSuperAdmin) {
    header('Location: /admin/ekonomi');
    exit;
}

// Check if Stripe is configured
$stripeSecretKey = env('STRIPE_SECRET_KEY', '');
$stripeConfigured = !empty($stripeSecretKey);

$message = '';
$messageType = 'info';

// Handle return/refresh from Stripe onboarding
$action = $_GET['action'] ?? '';
$recipientId = intval($_GET['recipient_id'] ?? 0);

if ($action === 'return' && $recipientId) {
    // User returned from Stripe onboarding
    if ($stripeConfigured) {
        $stripe = new StripeClient($stripeSecretKey);

        // Get recipient's Stripe account
        $recipient = $db->getRow("SELECT stripe_account_id FROM payment_recipients WHERE id = ?", [$recipientId]);

        if ($recipient && !empty($recipient['stripe_account_id'])) {
            $accountInfo = $stripe->getAccount($recipient['stripe_account_id']);

            if ($accountInfo['success']) {
                $status = 'pending';
                if ($accountInfo['charges_enabled'] && $accountInfo['payouts_enabled']) {
                    $status = 'active';
                } elseif ($accountInfo['details_submitted']) {
                    $status = 'pending_verification';
                }

                $db->update('payment_recipients', [
                    'stripe_account_status' => $status
                ], 'id = ?', [$recipientId]);

                if ($status === 'active') {
                    $message = 'Stripe-kontot ar nu aktiverat och kan ta emot betalningar!';
                    $messageType = 'success';
                } else {
                    $message = 'Onboarding pagarar. Stripe granskar uppgifterna.';
                    $messageType = 'info';
                }
            }
        }
    }
} elseif ($action === 'refresh' && $recipientId) {
    // Link expired, redirect to create new link
    header("Location: /admin/stripe-connect?start_onboarding=$recipientId");
    exit;
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();

    $postAction = $_POST['action'] ?? '';

    if ($postAction === 'create_account' && $stripeConfigured) {
        $recipientId = intval($_POST['recipient_id'] ?? 0);
        $email = trim($_POST['email'] ?? '');

        if ($recipientId) {
            $stripe = new StripeClient($stripeSecretKey);

            $result = $stripe->createConnectedAccount([
                'email' => $email ?: null,
                'country' => 'SE',
                'business_type' => $_POST['business_type'] ?? 'company',
                'metadata' => ['recipient_id' => $recipientId]
            ]);

            if ($result['success']) {
                // Save platform fee settings
                $feeAmount = floatval($_POST['platform_fee_amount'] ?? 10);
                $feeType = in_array($_POST['platform_fee_type'] ?? 'fixed', ['fixed', 'percent']) ? $_POST['platform_fee_type'] : 'fixed';
                $gatewayConfig = json_encode([
                    'platform_fee_type' => $feeType,
                    'platform_fee_amount' => $feeAmount
                ]);

                $db->update('payment_recipients', [
                    'stripe_account_id' => $result['account_id'],
                    'stripe_account_status' => 'pending',
                    'gateway_type' => 'stripe',
                    'gateway_config' => $gatewayConfig
                ], 'id = ?', [$recipientId]);

                // Redirect to onboarding
                header("Location: /admin/stripe-connect?start_onboarding=$recipientId");
                exit;
            } else {
                $message = 'Fel: ' . ($result['error'] ?? 'Kunde inte skapa Stripe-konto');
                $messageType = 'error';
            }
        }
    } elseif ($postAction === 'update_platform_fee') {
        $recipientId = intval($_POST['recipient_id'] ?? 0);
        $feeAmount = floatval($_POST['platform_fee_amount'] ?? 10);
        $feeType = in_array($_POST['platform_fee_type'] ?? 'fixed', ['fixed', 'percent']) ? $_POST['platform_fee_type'] : 'fixed';

        if ($recipientId && $feeAmount >= 0) {
            $recipient = $db->getRow("SELECT gateway_config FROM payment_recipients WHERE id = ?", [$recipientId]);
            $config = json_decode($recipient['gateway_config'] ?? '{}', true) ?: [];
            $config['platform_fee_type'] = $feeType;
            $config['platform_fee_amount'] = $feeAmount;
            // Remove old format
            unset($config['platform_fee_percent']);

            $db->update('payment_recipients', [
                'gateway_config' => json_encode($config)
            ], 'id = ?', [$recipientId]);

            $feeDisplay = $feeType === 'fixed' ? $feeAmount . ' kr/anmälan' : $feeAmount . '%';
            $message = 'Plattformsavgift uppdaterad till ' . $feeDisplay;
            $messageType = 'success';
        }
    } elseif ($postAction === 'update_payment_methods') {
        $recipientId = intval($_POST['recipient_id'] ?? 0);
        $methods = $_POST['methods'] ?? [];

        // Validate methods
        $validMethods = array_intersect($methods, ['card', 'swish', 'vipps']);

        if ($recipientId && !empty($validMethods)) {
            $recipient = $db->getRow("SELECT gateway_config FROM payment_recipients WHERE id = ?", [$recipientId]);
            $config = json_decode($recipient['gateway_config'] ?? '{}', true) ?: [];
            $config['payment_methods'] = array_values($validMethods);

            $db->update('payment_recipients', [
                'gateway_config' => json_encode($config)
            ], 'id = ?', [$recipientId]);

            $methodNames = [];
            if (in_array('card', $validMethods)) $methodNames[] = 'Kort';
            if (in_array('swish', $validMethods)) $methodNames[] = 'Swish';
            if (in_array('vipps', $validMethods)) $methodNames[] = 'Vipps';

            $message = 'Betalmetoder uppdaterade: ' . implode(', ', $methodNames);
            $messageType = 'success';
        } elseif ($recipientId && empty($validMethods)) {
            $message = 'Minst en betalmetod maste vara aktiverad';
            $messageType = 'error';
        }
    }
}

// Start onboarding redirect
$startOnboarding = intval($_GET['start_onboarding'] ?? 0);
if ($startOnboarding && $stripeConfigured) {
    $recipient = $db->getRow("SELECT stripe_account_id FROM payment_recipients WHERE id = ?", [$startOnboarding]);

    if ($recipient && !empty($recipient['stripe_account_id'])) {
        $stripe = new StripeClient($stripeSecretKey);

        $baseUrl = SITE_URL;
        $result = $stripe->createAccountLink(
            $recipient['stripe_account_id'],
            $baseUrl . '/admin/stripe-connect?action=return&recipient_id=' . $startOnboarding,
            $baseUrl . '/admin/stripe-connect?action=refresh&recipient_id=' . $startOnboarding
        );

        if ($result['success'] && !empty($result['url'])) {
            header('Location: ' . $result['url']);
            exit;
        } else {
            $message = 'Kunde inte skapa onboarding-lank: ' . ($result['error'] ?? 'Okant fel');
            $messageType = 'error';
        }
    }
}

// Get all payment recipients
$recipients = $db->getAll("
    SELECT pr.*,
           (SELECT COUNT(*) FROM series WHERE payment_recipient_id = pr.id) as series_count,
           (SELECT COUNT(*) FROM events WHERE payment_recipient_id = pr.id) as events_count
    FROM payment_recipients pr
    WHERE pr.active = 1
    ORDER BY pr.name
");

// Page config
$page_title = 'Stripe Connect';
$breadcrumbs = [
    ['label' => 'Ekonomi', 'url' => '/admin/ekonomi'],
    ['label' => 'Stripe Connect']
];
include __DIR__ . '/components/unified-layout.php';
?>

<style>
.stripe-card {
    background: var(--color-bg-card);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-lg);
    padding: var(--space-lg);
    margin-bottom: var(--space-md);
}

.stripe-card-header {
    display: flex;
    align-items: center;
    gap: var(--space-md);
    margin-bottom: var(--space-md);
}

.stripe-logo {
    width: 48px;
    height: 48px;
    background: linear-gradient(135deg, #635bff, #5851ea);
    border-radius: var(--radius-md);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-weight: bold;
    font-size: 20px;
}

.stripe-status {
    display: inline-flex;
    align-items: center;
    gap: var(--space-xs);
    padding: 4px 12px;
    border-radius: var(--radius-full);
    font-size: var(--text-sm);
    font-weight: 500;
}

.stripe-status.active {
    background: rgba(16, 185, 129, 0.15);
    color: var(--color-success);
}

.stripe-status.pending {
    background: rgba(251, 191, 36, 0.15);
    color: var(--color-warning);
}

.stripe-status.not-connected {
    background: rgba(107, 114, 128, 0.15);
    color: var(--color-text-muted);
}

.config-box {
    background: var(--color-bg-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    padding: var(--space-lg);
    margin-bottom: var(--space-lg);
}

.config-box h3 {
    margin: 0 0 var(--space-sm) 0;
    display: flex;
    align-items: center;
    gap: var(--space-sm);
}

.config-box code {
    background: var(--color-bg-hover);
    padding: 2px 8px;
    border-radius: var(--radius-sm);
    font-family: monospace;
}

.stripe-details {
    background: var(--color-bg-surface);
    border-radius: var(--radius-sm);
    padding: var(--space-md);
}

.stripe-detail-row {
    display: flex;
    align-items: center;
    gap: var(--space-md);
    padding: var(--space-xs) 0;
}

.stripe-detail-row:not(:last-child) {
    border-bottom: 1px solid var(--color-border);
    padding-bottom: var(--space-sm);
    margin-bottom: var(--space-sm);
}

.platform-fee-form input[type="number"] {
    font-size: var(--text-sm);
}

.checkbox-label {
    display: inline-flex;
    align-items: center;
    gap: var(--space-xs);
    cursor: pointer;
    font-size: var(--text-sm);
    color: var(--color-text-secondary);
}

.checkbox-label input[type="checkbox"] {
    width: 16px;
    height: 16px;
    accent-color: var(--color-accent);
}

.checkbox-label:has(input:checked) {
    color: var(--color-text-primary);
}

.checkbox-label svg {
    width: 16px;
    height: 16px;
}

.mt-md {
    margin-top: var(--space-md);
}

.stripe-connect-form {
    display: flex;
    flex-direction: column;
    gap: var(--space-md);
}

.connect-form-row {
    display: flex;
    gap: var(--space-md);
    flex-wrap: wrap;
    align-items: flex-end;
}

.connect-form-row .admin-form-group {
    flex: 1;
    min-width: 150px;
}

@media (max-width: 600px) {
    .connect-form-row {
        flex-direction: column;
    }
    .connect-form-row .admin-form-group {
        width: 100%;
    }
}
</style>

<?php if ($message): ?>
<div class="alert alert-<?= $messageType === 'success' ? 'success' : ($messageType === 'error' ? 'danger' : 'info') ?> mb-lg">
    <?= htmlspecialchars($message) ?>
</div>
<?php endif; ?>

<?php if (!$stripeConfigured): ?>
<!-- Stripe Not Configured -->
<div class="config-box">
    <h3><i data-lucide="alert-triangle" style="color: var(--color-warning);"></i> Stripe ar inte konfigurerat</h3>
    <p class="text-secondary mb-md">
        For att aktivera Stripe-betalningar, lagg till foljande i din <code>.env</code> fil:
    </p>
    <pre style="background: var(--color-bg-page); padding: var(--space-md); border-radius: var(--radius-sm); overflow-x: auto;">
STRIPE_PUBLISHABLE_KEY=pk_live_xxxxx
STRIPE_SECRET_KEY=sk_live_xxxxx
STRIPE_WEBHOOK_SECRET=whsec_xxxxx</pre>
    <p class="text-secondary mt-md">
        <a href="https://dashboard.stripe.com/apikeys" target="_blank" class="text-accent">
            Hamta dina API-nycklar fran Stripe Dashboard
        </a>
    </p>
</div>
<?php else: ?>

<!-- Instructions -->
<div class="config-box" style="background: linear-gradient(135deg, var(--color-bg-surface), var(--color-bg-hover)); border: 2px solid var(--color-accent-light);">
    <h3><i data-lucide="clipboard-list" style="color: var(--color-accent);"></i> Admin: Checklista for Betalningssystem</h3>

    <div style="display: grid; gap: var(--space-lg); margin-top: var(--space-md);">
        <!-- Step 1 -->
        <div style="display: flex; gap: var(--space-md);">
            <div style="min-width: 32px; height: 32px; background: var(--color-accent); color: var(--color-bg-page); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold;">1</div>
            <div style="flex: 1;">
                <strong>Skapa betalningsmottagare</strong>
                <p class="text-secondary text-sm" style="margin: var(--space-xs) 0;">
                    Varje arrangor/klubb som ska ta emot pengar behover en mottagare med org.nr och kontaktinfo.
                </p>
                <a href="/admin/payment-recipients" class="btn-admin btn-admin-secondary btn-admin-sm">
                    <i data-lucide="building-2"></i> Hantera mottagare
                </a>
            </div>
        </div>

        <!-- Step 2 -->
        <div style="display: flex; gap: var(--space-md);">
            <div style="min-width: 32px; height: 32px; background: var(--color-accent); color: var(--color-bg-page); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold;">2</div>
            <div style="flex: 1;">
                <strong>Anslut till Stripe (nedan)</strong>
                <p class="text-secondary text-sm" style="margin: var(--space-xs) 0;">
                    Klicka "Anslut till Stripe" for varje mottagare. Du kommer till Stripes onboarding.
                </p>
            </div>
        </div>

        <!-- Step 3 -->
        <div style="display: flex; gap: var(--space-md);">
            <div style="min-width: 32px; height: 32px; background: var(--color-accent); color: var(--color-bg-page); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold;">3</div>
            <div style="flex: 1;">
                <strong>Arrangoren fyller i sina uppgifter</strong>
                <p class="text-secondary text-sm" style="margin: var(--space-xs) 0;">
                    Skicka onboarding-lanken till arrangoren. De fyller i: foretagsinfo, bankuppgifter, ID-verifiering.
                    <br><em>Alternativt: Du kan fylla i uppgifterna at dem om du har all info.</em>
                </p>
            </div>
        </div>

        <!-- Step 4 -->
        <div style="display: flex; gap: var(--space-md);">
            <div style="min-width: 32px; height: 32px; background: var(--color-accent); color: var(--color-bg-page); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold;">4</div>
            <div style="flex: 1;">
                <strong>Koppla event/serier till mottagaren</strong>
                <p class="text-secondary text-sm" style="margin: var(--space-xs) 0;">
                    Nar kontot ar aktivt, koppla events eller serier till mottagaren sa betalningar gar till dem.
                </p>
                <div style="display: flex; gap: var(--space-sm); flex-wrap: wrap;">
                    <a href="/admin/events" class="btn-admin btn-admin-secondary btn-admin-sm">
                        <i data-lucide="calendar"></i> Events
                    </a>
                    <a href="/admin/series" class="btn-admin btn-admin-secondary btn-admin-sm">
                        <i data-lucide="trophy"></i> Serier
                    </a>
                </div>
            </div>
        </div>

        <!-- Step 5 -->
        <div style="display: flex; gap: var(--space-md);">
            <div style="min-width: 32px; height: 32px; background: var(--color-accent); color: var(--color-bg-page); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold;">5</div>
            <div style="flex: 1;">
                <strong>Aterbetalningar vid behov</strong>
                <p class="text-secondary text-sm" style="margin: var(--space-xs) 0;">
                    Vid refund aterfors automatiskt saljartransfers. Arrangoren beslutar om refund (enligt villkoren).
                </p>
                <a href="/admin/process-refunds" class="btn-admin btn-admin-secondary btn-admin-sm">
                    <i data-lucide="undo-2"></i> Hantera aterbetalningar
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Important Info Box -->
<div class="config-box" style="background: rgba(251, 191, 36, 0.05); border-color: var(--color-warning);">
    <h3><i data-lucide="alert-triangle" style="color: var(--color-warning);"></i> Viktigt att veta</h3>
    <ul style="color: var(--color-text-secondary); margin: var(--space-sm) 0 0 0; padding-left: 1.5rem;">
        <li><strong>Recipient Model:</strong> Alla betalningar gar till TheHUB:s Stripe-konto, vi overfOr sen till saljare.</li>
        <li><strong>Automatiska transfers:</strong> Nar en betalning lyckas skapas transfer till saljaren direkt.</li>
        <li><strong>Chargebacks:</strong> Plattformen bar risken for chargebacks (pengar tas fran oss, inte saljaren).</li>
        <li><strong>Refunds:</strong> Vid aterbetalning aterfors transfers automatiskt fran saljarens konto till plattformen.</li>
    </ul>
</div>

<!-- Recipients List -->
<h2 class="mb-lg flex items-center gap-sm">
    <i data-lucide="building-2"></i>
    Betalningsmottagare
</h2>

<?php if (empty($recipients)): ?>
<div class="alert alert-info">
    Inga aktiva betalningsmottagare. <a href="/admin/payment-recipients">Skapa en mottagare forst</a>.
</div>
<?php else: ?>

<?php foreach ($recipients as $r):
    $hasStripe = !empty($r['stripe_account_id']);
    $stripeStatus = $r['stripe_account_status'] ?? 'not_connected';
?>
<div class="stripe-card">
    <div class="stripe-card-header">
        <div class="stripe-logo">S</div>
        <div style="flex: 1;">
            <h3 style="margin: 0;"><?= htmlspecialchars($r['name']) ?></h3>
            <p class="text-secondary text-sm" style="margin: 0;">
                <?php if ($r['series_count'] > 0): ?>
                    <?= $r['series_count'] ?> serier
                <?php endif; ?>
                <?php if ($r['events_count'] > 0): ?>
                    <?= $r['series_count'] > 0 ? ', ' : '' ?><?= $r['events_count'] ?> events
                <?php endif; ?>
                <?php if ($r['series_count'] == 0 && $r['events_count'] == 0): ?>
                    Inte kopplad till nagot
                <?php endif; ?>
            </p>
        </div>
        <div>
            <?php if (!$hasStripe): ?>
                <span class="stripe-status not-connected">
                    <i data-lucide="x-circle"></i> Ej ansluten
                </span>
            <?php elseif ($stripeStatus === 'active'): ?>
                <span class="stripe-status active">
                    <i data-lucide="check-circle"></i> Aktiv
                </span>
            <?php else: ?>
                <span class="stripe-status pending">
                    <i data-lucide="clock"></i> Vantar
                </span>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($hasStripe): ?>
        <?php
        $gatewayConfig = json_decode($r['gateway_config'] ?? '{}', true) ?: [];
        $feeType = $gatewayConfig['platform_fee_type'] ?? 'fixed';
        $feeAmount = $gatewayConfig['platform_fee_amount'] ?? 10;
        $paymentMethods = $gatewayConfig['payment_methods'] ?? ['card', 'swish'];
        // Migrate old format
        if (isset($gatewayConfig['platform_fee_percent']) && !isset($gatewayConfig['platform_fee_type'])) {
            $feeType = 'percent';
            $feeAmount = $gatewayConfig['platform_fee_percent'];
        }
        ?>
        <div style="display: flex; gap: var(--space-sm); flex-wrap: wrap; align-items: center;">
            <?php if ($stripeStatus !== 'active'): ?>
                <a href="?start_onboarding=<?= $r['id'] ?>" class="btn-admin btn-admin-primary">
                    <i data-lucide="external-link"></i> Fortsatt onboarding
                </a>
            <?php endif; ?>

            <button type="button" class="btn-admin btn-admin-secondary"
                    onclick="openStripeDashboard('<?= htmlspecialchars($r['stripe_account_id']) ?>')">
                <i data-lucide="layout-dashboard"></i> Stripe Dashboard
            </button>

            <button type="button" class="btn-admin btn-admin-secondary"
                    onclick="refreshAccountStatus('<?= $r['id'] ?>', '<?= htmlspecialchars($r['stripe_account_id']) ?>')">
                <i data-lucide="refresh-cw"></i> Uppdatera status
            </button>
        </div>

        <div class="stripe-details mt-md">
            <div class="stripe-detail-row">
                <span class="text-secondary">Account ID:</span>
                <code><?= htmlspecialchars($r['stripe_account_id']) ?></code>
            </div>
            <div class="stripe-detail-row">
                <span class="text-secondary">Betalmetoder:</span>
                <form method="POST" class="payment-methods-form" style="display: inline-flex; align-items: center; gap: var(--space-md);">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update_payment_methods">
                    <input type="hidden" name="recipient_id" value="<?= $r['id'] ?>">
                    <label class="checkbox-label">
                        <input type="checkbox" name="methods[]" value="card" <?= in_array('card', $paymentMethods) ? 'checked' : '' ?>>
                        <i data-lucide="credit-card"></i> Kort
                    </label>
                    <label class="checkbox-label">
                        <input type="checkbox" name="methods[]" value="swish" <?= in_array('swish', $paymentMethods) ? 'checked' : '' ?>>
                        <i data-lucide="smartphone"></i> Swish
                    </label>
                    <label class="checkbox-label" title="Vipps (Norge) - Kräver aktivering hos Stripe">
                        <input type="checkbox" name="methods[]" value="vipps" <?= in_array('vipps', $paymentMethods) ? 'checked' : '' ?>>
                        <i data-lucide="smartphone"></i> Vipps
                    </label>
                    <button type="submit" class="btn-admin btn-admin-ghost btn-admin-sm">
                        <i data-lucide="save"></i>
                    </button>
                </form>
            </div>
            <div class="stripe-detail-row">
                <span class="text-secondary">Plattformsavgift:</span>
                <form method="POST" class="platform-fee-form" style="display: inline-flex; align-items: center; gap: var(--space-sm);">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update_platform_fee">
                    <input type="hidden" name="recipient_id" value="<?= $r['id'] ?>">
                    <input type="number" name="platform_fee_amount" value="<?= h($feeAmount) ?>"
                           min="0" max="500" step="1" class="admin-form-input"
                           style="width: 80px; padding: 4px 8px;">
                    <select name="platform_fee_type" class="admin-form-select" style="padding: 4px 8px;">
                        <option value="fixed" <?= $feeType === 'fixed' ? 'selected' : '' ?>>kr/anmälan</option>
                        <option value="percent" <?= $feeType === 'percent' ? 'selected' : '' ?>>%</option>
                    </select>
                    <button type="submit" class="btn-admin btn-admin-ghost btn-admin-sm">
                        <i data-lucide="save"></i>
                    </button>
                </form>
            </div>
        </div>
    <?php else: ?>
        <?php
        // Default fee settings for new connections
        $gatewayConfig = json_decode($r['gateway_config'] ?? '{}', true) ?: [];
        $feeType = $gatewayConfig['platform_fee_type'] ?? 'fixed';
        $feeAmount = $gatewayConfig['platform_fee_amount'] ?? 10;
        ?>
        <form method="POST" class="stripe-connect-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create_account">
            <input type="hidden" name="recipient_id" value="<?= $r['id'] ?>">

            <div class="connect-form-row">
                <div class="admin-form-group">
                    <label class="admin-form-label">E-post (valfritt)</label>
                    <input type="email" name="email" class="admin-form-input" placeholder="kontakt@foretag.se">
                </div>

                <div class="admin-form-group">
                    <label class="admin-form-label">Kontotyp</label>
                    <select name="business_type" class="admin-form-select">
                        <option value="company">Foretag/Forening</option>
                        <option value="individual">Privatperson</option>
                    </select>
                </div>

                <div class="admin-form-group">
                    <label class="admin-form-label">Plattformsavgift</label>
                    <div style="display: flex; gap: var(--space-xs); align-items: center;">
                        <input type="number" name="platform_fee_amount" value="<?= h($feeAmount) ?>"
                               min="0" max="500" step="1" class="admin-form-input" style="width: 70px;">
                        <select name="platform_fee_type" class="admin-form-select" style="width: auto;">
                            <option value="fixed" <?= $feeType === 'fixed' ? 'selected' : '' ?>>kr/anmälan</option>
                            <option value="percent" <?= $feeType === 'percent' ? 'selected' : '' ?>>%</option>
                        </select>
                    </div>
                </div>
            </div>

            <button type="submit" class="btn-admin btn-admin-primary">
                <i data-lucide="link"></i> Anslut till Stripe
            </button>
        </form>
    <?php endif; ?>
</div>
<?php endforeach; ?>

<?php endif; ?>
<?php endif; ?>

<script>
function openStripeDashboard(accountId) {
    // Create login link and redirect
    fetch('/api/stripe-connect.php?action=create_login_link&account_id=' + accountId)
        .then(r => r.json())
        .then(data => {
            if (data.success && data.url) {
                window.open(data.url, '_blank');
            } else {
                alert('Kunde inte oppna Stripe Dashboard: ' + (data.error || 'Okant fel'));
            }
        })
        .catch(err => {
            alert('Fel: ' + err.message);
        });
}

function refreshAccountStatus(recipientId, accountId) {
    fetch('/api/stripe-connect.php?action=account_status&account_id=' + accountId)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert('Kunde inte hamta status: ' + (data.error || 'Okant fel'));
            }
        })
        .catch(err => {
            alert('Fel: ' + err.message);
        });
}

// Initialize Lucide
if (typeof lucide !== 'undefined') {
    lucide.createIcons();
}
</script>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>

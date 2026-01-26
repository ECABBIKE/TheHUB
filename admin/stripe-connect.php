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
                $db->update('payment_recipients', [
                    'stripe_account_id' => $result['account_id'],
                    'stripe_account_status' => 'pending',
                    'gateway_type' => 'stripe'
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
        $platformFee = floatval($_POST['platform_fee'] ?? 2);

        if ($recipientId && $platformFee >= 0 && $platformFee <= 50) {
            $recipient = $db->getRow("SELECT gateway_config FROM payment_recipients WHERE id = ?", [$recipientId]);
            $config = json_decode($recipient['gateway_config'] ?? '{}', true) ?: [];
            $config['platform_fee_percent'] = $platformFee;

            $db->update('payment_recipients', [
                'gateway_config' => json_encode($config)
            ], 'id = ?', [$recipientId]);

            $message = 'Plattformsavgift uppdaterad till ' . $platformFee . '%';
            $messageType = 'success';
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

.mt-md {
    margin-top: var(--space-md);
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
<div class="config-box">
    <h3><i data-lucide="info"></i> Hur det fungerar</h3>
    <ol style="color: var(--color-text-secondary); margin: 0; padding-left: 1.5rem;">
        <li>Valj en mottagare nedan och klicka "Anslut till Stripe"</li>
        <li>Mottagaren fyller i foretagsuppgifter, bankinfo och verifierar identitet pa Stripe</li>
        <li>Nar Stripe godkant kontot kan mottagaren ta emot kortbetalningar</li>
        <li>TheHUB tar plattformsavgift pa varje transaktion (konfigurerbart per mottagare)</li>
    </ol>
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
        $platformFee = $gatewayConfig['platform_fee_percent'] ?? 2;
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
                <span class="text-secondary">Plattformsavgift:</span>
                <form method="POST" class="platform-fee-form" style="display: inline-flex; align-items: center; gap: var(--space-xs);">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update_platform_fee">
                    <input type="hidden" name="recipient_id" value="<?= $r['id'] ?>">
                    <input type="number" name="platform_fee" value="<?= h($platformFee) ?>"
                           min="0" max="50" step="0.1" class="admin-form-input"
                           style="width: 70px; padding: 4px 8px;">
                    <span>%</span>
                    <button type="submit" class="btn-admin btn-admin-ghost btn-admin-sm">
                        <i data-lucide="save"></i>
                    </button>
                </form>
            </div>
        </div>
    <?php else: ?>
        <form method="POST" style="display: flex; gap: var(--space-md); flex-wrap: wrap; align-items: flex-end;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create_account">
            <input type="hidden" name="recipient_id" value="<?= $r['id'] ?>">

            <div class="admin-form-group" style="flex: 1; min-width: 200px;">
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

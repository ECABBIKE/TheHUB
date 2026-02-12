<?php
/**
 * Promotor Stripe Connect
 *
 * Allows promotors to:
 * - Connect their payment recipients to Stripe
 * - Complete Stripe onboarding
 * - View account status
 * - Access Stripe Express dashboard
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/payment/StripeClient.php';

// Require at least promotor role
if (!isLoggedIn()) {
    redirect('/admin/login.php');
}

if (!hasRole('promotor')) {
    set_flash('error', 'Du har inte behörighet till denna sida');
    redirect('/');
}

use TheHUB\Payment\StripeClient;

$db = getDB();
$currentUser = getCurrentAdmin();
$userId = $currentUser['id'] ?? 0;

// Check if Stripe is configured
$stripeSecretKey = env('STRIPE_SECRET_KEY', '');
$stripeConfigured = !empty($stripeSecretKey);

$message = '';
$messageType = 'info';

// Get promotor's series and their payment recipients
$series = [];
try {
    $series = $db->getAll("
        SELECT s.id, s.name, s.logo,
               pr.id as recipient_id,
               pr.name as recipient_name,
               pr.stripe_account_id,
               pr.stripe_account_status,
               pr.swish_number,
               pr.gateway_type
        FROM series s
        JOIN promotor_series ps ON ps.series_id = s.id
        LEFT JOIN payment_recipients pr ON s.payment_recipient_id = pr.id
        WHERE ps.user_id = ?
        ORDER BY s.name
    ", [$userId]);
} catch (Exception $e) {
    error_log("Promotor Stripe: Error fetching series: " . $e->getMessage());
}

// Handle return/refresh from Stripe onboarding
$action = $_GET['action'] ?? '';
$recipientId = intval($_GET['recipient_id'] ?? 0);

if ($action === 'return' && $recipientId && $stripeConfigured) {
    $stripe = new StripeClient($stripeSecretKey);

    // Verify promotor has access to this recipient
    $hasAccess = $db->getRow("
        SELECT 1 FROM series s
        JOIN promotor_series ps ON ps.series_id = s.id
        WHERE ps.user_id = ? AND s.payment_recipient_id = ?
    ", [$userId, $recipientId]);

    if ($hasAccess) {
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
                    $message = 'Stripe-kontot är nu aktiverat och kan ta emot betalningar!';
                    $messageType = 'success';
                } else {
                    $message = 'Onboarding pågår. Stripe granskar uppgifterna.';
                    $messageType = 'info';
                }
            }
        }
    }
} elseif ($action === 'refresh' && $recipientId) {
    header("Location: /admin/promotor-stripe.php?start_onboarding=$recipientId");
    exit;
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();

    $postAction = $_POST['action'] ?? '';

    if ($postAction === 'create_account' && $stripeConfigured) {
        $recipientId = intval($_POST['recipient_id'] ?? 0);
        $email = trim($_POST['email'] ?? '');

        // Verify promotor has access to this recipient
        $hasAccess = $db->getRow("
            SELECT 1 FROM series s
            JOIN promotor_series ps ON ps.series_id = s.id
            WHERE ps.user_id = ? AND s.payment_recipient_id = ?
        ", [$userId, $recipientId]);

        if ($hasAccess && $recipientId) {
            $stripe = new StripeClient($stripeSecretKey);

            $result = $stripe->createConnectedAccount([
                'email' => $email ?: null,
                'country' => 'SE',
                'business_type' => $_POST['business_type'] ?? 'company',
                'metadata' => ['recipient_id' => $recipientId, 'promotor_user_id' => $userId]
            ]);

            if ($result['success']) {
                $db->update('payment_recipients', [
                    'stripe_account_id' => $result['account_id'],
                    'stripe_account_status' => 'pending',
                    'gateway_type' => 'stripe'
                ], 'id = ?', [$recipientId]);

                header("Location: /admin/promotor-stripe.php?start_onboarding=$recipientId");
                exit;
            } else {
                $message = 'Fel: ' . ($result['error'] ?? 'Kunde inte skapa Stripe-konto');
                $messageType = 'error';
            }
        } else {
            $message = 'Du har inte behörighet till denna mottagare';
            $messageType = 'error';
        }
    }
}

// Start onboarding redirect
$startOnboarding = intval($_GET['start_onboarding'] ?? 0);
if ($startOnboarding && $stripeConfigured) {
    // Verify promotor has access
    $hasAccess = $db->getRow("
        SELECT 1 FROM series s
        JOIN promotor_series ps ON ps.series_id = s.id
        WHERE ps.user_id = ? AND s.payment_recipient_id = ?
    ", [$userId, $startOnboarding]);

    if ($hasAccess) {
        $recipient = $db->getRow("SELECT stripe_account_id FROM payment_recipients WHERE id = ?", [$startOnboarding]);

        if ($recipient && !empty($recipient['stripe_account_id'])) {
            $stripe = new StripeClient($stripeSecretKey);

            $baseUrl = SITE_URL;
            $result = $stripe->createAccountLink(
                $recipient['stripe_account_id'],
                $baseUrl . '/admin/promotor-stripe.php?action=return&recipient_id=' . $startOnboarding,
                $baseUrl . '/admin/promotor-stripe.php?action=refresh&recipient_id=' . $startOnboarding
            );

            if ($result['success'] && !empty($result['url'])) {
                header('Location: ' . $result['url']);
                exit;
            } else {
                $message = 'Kunde inte skapa onboarding-länk: ' . ($result['error'] ?? 'Okänt fel');
                $messageType = 'error';
            }
        }
    }
}

// Refresh series data after any changes
if ($message) {
    $series = $db->getAll("
        SELECT s.id, s.name, s.logo,
               pr.id as recipient_id,
               pr.name as recipient_name,
               pr.stripe_account_id,
               pr.stripe_account_status,
               pr.swish_number,
               pr.gateway_type
        FROM series s
        JOIN promotor_series ps ON ps.series_id = s.id
        LEFT JOIN payment_recipients pr ON s.payment_recipient_id = pr.id
        WHERE ps.user_id = ?
        ORDER BY s.name
    ", [$userId]);
}

// Page config
$page_title = 'Stripe Connect';
$breadcrumbs = [
    ['label' => 'Mina tävlingar', 'url' => '/admin/promotor.php'],
    ['label' => 'Stripe Connect']
];

include __DIR__ . '/components/unified-layout.php';
?>

<style>
/* Info Box */
.info-box {
    background: var(--color-bg-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-lg);
    padding: var(--space-lg);
    margin-bottom: var(--space-lg);
}
.info-box h3 {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    margin: 0 0 var(--space-md) 0;
    font-size: var(--text-base);
}
.info-box h3 i {
    color: var(--color-info);
}
.info-box ol {
    margin: 0;
    padding-left: 1.5rem;
    color: var(--color-text-secondary);
}
.info-box li {
    margin-bottom: var(--space-xs);
}

/* Series Card */
.series-card {
    background: var(--color-bg-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-lg);
    margin-bottom: var(--space-md);
    overflow: hidden;
}
.series-card-header {
    display: flex;
    align-items: center;
    gap: var(--space-md);
    padding: var(--space-md) var(--space-lg);
    border-bottom: 1px solid var(--color-border);
    background: var(--color-bg-hover);
}
.series-logo {
    width: 40px;
    height: 40px;
    border-radius: var(--radius-md);
    background: var(--color-bg-card);
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    flex-shrink: 0;
}
.series-logo img {
    max-width: 100%;
    max-height: 100%;
    object-fit: contain;
}
.series-info {
    flex: 1;
}
.series-info h3 {
    margin: 0;
    font-size: var(--text-base);
}
.series-info p {
    margin: 0;
    font-size: var(--text-sm);
    color: var(--color-text-secondary);
}

/* Stripe Status */
.stripe-status {
    display: inline-flex;
    align-items: center;
    gap: var(--space-xs);
    padding: var(--space-xs) var(--space-sm);
    border-radius: var(--radius-full);
    font-size: var(--text-xs);
    font-weight: 500;
}
.stripe-status i { width: 14px; height: 14px; }
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

/* Card Body */
.series-card-body {
    padding: var(--space-lg);
}

/* Connect Form */
.connect-form {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-md);
    align-items: flex-end;
}
.connect-form .form-group {
    flex: 1;
    min-width: 200px;
}
.connect-form label {
    display: block;
    font-size: var(--text-sm);
    font-weight: 500;
    color: var(--color-text-secondary);
    margin-bottom: var(--space-xs);
}
.connect-form input,
.connect-form select {
    width: 100%;
    padding: var(--space-sm) var(--space-md);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    font-size: var(--text-sm);
    background: var(--color-bg-card);
    color: var(--color-text-primary);
}

/* Action Buttons */
.action-buttons {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-sm);
}
.btn-stripe {
    background: linear-gradient(135deg, #635bff, #5851ea);
    color: white;
}
.btn-stripe:hover {
    background: linear-gradient(135deg, #5851ea, #4f46e5);
}

/* Account Info */
.account-info {
    margin-top: var(--space-md);
    padding-top: var(--space-md);
    border-top: 1px solid var(--color-border);
    font-size: var(--text-sm);
    color: var(--color-text-secondary);
}
.account-info code {
    background: var(--color-bg-hover);
    padding: 2px 6px;
    border-radius: var(--radius-sm);
    font-family: monospace;
    font-size: var(--text-xs);
}

/* No Recipient Warning */
.no-recipient {
    padding: var(--space-lg);
    text-align: center;
    color: var(--color-text-secondary);
}
.no-recipient i {
    width: 32px;
    height: 32px;
    margin-bottom: var(--space-sm);
    opacity: 0.5;
}
.no-recipient p {
    margin: 0;
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: var(--space-2xl);
    color: var(--color-text-secondary);
}
.empty-state i {
    width: 48px;
    height: 48px;
    margin-bottom: var(--space-md);
    opacity: 0.5;
}

@media (max-width: 767px) {
    .series-card {
        margin-left: -16px;
        margin-right: -16px;
        border-radius: 0;
        border-left: none;
        border-right: none;
        width: calc(100% + 32px);
    }
    .info-box {
        margin-left: -16px;
        margin-right: -16px;
        border-radius: 0;
        border-left: none;
        border-right: none;
        width: calc(100% + 32px);
    }
}
</style>

<?php if ($message): ?>
<div class="alert alert-<?= $messageType ?>">
    <i data-lucide="<?= $messageType === 'success' ? 'check-circle' : ($messageType === 'error' ? 'alert-circle' : 'info') ?>"></i>
    <span><?= h($message) ?></span>
</div>
<?php endif; ?>

<?php if (!$stripeConfigured): ?>
<div class="alert alert-warning">
    <i data-lucide="alert-triangle"></i>
    <span>Stripe är inte konfigurerat för denna plattform. Kontakta administratören.</span>
</div>
<?php else: ?>

<!-- Main Instructions -->
<div class="info-box" style="background: linear-gradient(135deg, var(--color-bg-surface), var(--color-bg-hover)); border: 2px solid var(--color-accent-light);">
    <h3><i data-lucide="clipboard-list" style="color: var(--color-accent);"></i> Kom igang med betalningar</h3>

    <div style="display: grid; gap: var(--space-lg); margin-top: var(--space-md);">
        <div style="display: flex; gap: var(--space-md);">
            <div style="min-width: 28px; height: 28px; background: var(--color-accent); color: var(--color-bg-page); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: var(--text-sm);">1</div>
            <div>
                <strong>Klicka "Anslut till Stripe" nedan</strong>
                <p class="text-secondary text-sm" style="margin: 2px 0 0 0;">Valj din serie och paborja anslutningen</p>
            </div>
        </div>
        <div style="display: flex; gap: var(--space-md);">
            <div style="min-width: 28px; height: 28px; background: var(--color-accent); color: var(--color-bg-page); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: var(--text-sm);">2</div>
            <div>
                <strong>Fyll i uppgifter pa Stripe</strong>
                <p class="text-secondary text-sm" style="margin: 2px 0 0 0;">Foretagsinfo, organisationsnummer, bankuppgifter, ID-verifiering</p>
            </div>
        </div>
        <div style="display: flex; gap: var(--space-md);">
            <div style="min-width: 28px; height: 28px; background: var(--color-accent); color: var(--color-bg-page); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: var(--text-sm);">3</div>
            <div>
                <strong>Stripe granskar och aktiverar</strong>
                <p class="text-secondary text-sm" style="margin: 2px 0 0 0;">Tar normalt 1-2 vardagar. Du far notis nar det ar klart.</p>
            </div>
        </div>
        <div style="display: flex; gap: var(--space-md);">
            <div style="min-width: 28px; height: 28px; background: var(--color-success); color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: var(--text-sm);">
                <i data-lucide="check" style="width: 16px; height: 16px;"></i>
            </div>
            <div>
                <strong>Borja ta emot betalningar!</strong>
                <p class="text-secondary text-sm" style="margin: 2px 0 0 0;">Kortbetalningar, Swish och mer. Utbetalningar automatiskt till ditt konto.</p>
            </div>
        </div>
    </div>
</div>

<!-- How It Works -->
<div class="info-box" style="background: var(--color-bg-surface);">
    <h3><i data-lucide="wallet" style="color: var(--color-info);"></i> Hur pengarna flyter</h3>
    <div style="display: grid; gap: var(--space-md); grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); margin-top: var(--space-sm);">
        <div style="text-align: center; padding: var(--space-md); background: var(--color-bg-page); border-radius: var(--radius-md);">
            <i data-lucide="user" style="width: 24px; height: 24px; color: var(--color-accent); margin-bottom: var(--space-xs);"></i>
            <p class="text-sm" style="margin: 0;"><strong>Deltagare betalar</strong></p>
            <p class="text-xs text-secondary" style="margin: 0;">via TheHUB checkout</p>
        </div>
        <div style="text-align: center; padding: var(--space-md);">
            <i data-lucide="arrow-right" style="width: 24px; height: 24px; color: var(--color-text-muted);"></i>
        </div>
        <div style="text-align: center; padding: var(--space-md); background: var(--color-bg-page); border-radius: var(--radius-md);">
            <i data-lucide="building-2" style="width: 24px; height: 24px; color: var(--color-warning); margin-bottom: var(--space-xs);"></i>
            <p class="text-sm" style="margin: 0;"><strong>TheHUB tar emot</strong></p>
            <p class="text-xs text-secondary" style="margin: 0;">och overfOr direkt till dig</p>
        </div>
        <div style="text-align: center; padding: var(--space-md);">
            <i data-lucide="arrow-right" style="width: 24px; height: 24px; color: var(--color-text-muted);"></i>
        </div>
        <div style="text-align: center; padding: var(--space-md); background: var(--color-bg-page); border-radius: var(--radius-md);">
            <i data-lucide="landmark" style="width: 24px; height: 24px; color: var(--color-success); margin-bottom: var(--space-xs);"></i>
            <p class="text-sm" style="margin: 0;"><strong>Ditt bankkonto</strong></p>
            <p class="text-xs text-secondary" style="margin: 0;">automatiska utbetalningar</p>
        </div>
    </div>
</div>

<!-- Refund Info -->
<div class="info-box" style="background: rgba(251, 191, 36, 0.03); border-color: var(--color-warning);">
    <h3><i data-lucide="undo-2" style="color: var(--color-warning);"></i> Återbetalningar - Viktigt att veta</h3>
    <div style="display: grid; gap: var(--space-md); margin-top: var(--space-sm);">
        <p class="text-secondary text-sm" style="margin: 0;">
            Enligt Allmänna Villkor gäller <strong>ingen ångerrätt</strong> för idrottsevenemang.
            Du som arrangör beslutar om återbetalning ska godkännas.
        </p>
        <div style="background: var(--color-bg-page); padding: var(--space-md); border-radius: var(--radius-md);">
            <h4 style="margin: 0 0 var(--space-sm) 0; font-size: var(--text-sm);">Så fungerar det:</h4>
            <ol style="margin: 0; padding-left: 1.2rem; color: var(--color-text-secondary); font-size: var(--text-sm);">
                <li>Deltagare kontaktar <strong>dig</strong> för återbetalning</li>
                <li>Du beslutar om refund ska godkännas</li>
                <li>Meddela TheHUB-admin med Order-ID + ditt beslut</li>
                <li>Admin processar återbetalningen tekniskt</li>
                <li><strong>Automatisk återföring:</strong> Pengarna återförs från ditt Stripe-konto</li>
            </ol>
        </div>
        <p class="text-secondary text-xs" style="margin: 0;">
            <i data-lucide="shield-check" style="width: 14px; height: 14px; vertical-align: middle;"></i>
            TheHUB hanterar chargebacks (tvistade betalningar) - du behöver inte oroa dig för dem.
        </p>
    </div>
</div>

<!-- Contact -->
<div class="info-box" style="background: var(--color-bg-surface);">
    <h3><i data-lucide="mail" style="color: var(--color-accent);"></i> Kontakt</h3>
    <p class="text-secondary text-sm" style="margin: 0 0 var(--space-sm) 0;">
        Har du frågor om betalningar eller behöver hjälp?
    </p>
    <div style="display: flex; gap: var(--space-md); flex-wrap: wrap;">
        <a href="mailto:info@gravityseries.se" class="btn btn--secondary btn--sm">
            <i data-lucide="mail"></i> info@gravityseries.se
        </a>
        <a href="/admin/promotor-payments" class="btn btn--secondary btn--sm">
            <i data-lucide="receipt"></i> Se dina betalningar
        </a>
    </div>
</div>

<?php if (empty($series)): ?>
<div class="empty-state">
    <i data-lucide="medal"></i>
    <h3>Inga serier</h3>
    <p>Du har inga serier tilldelade ännu.</p>
</div>
<?php else: ?>

<?php foreach ($series as $s):
    $hasRecipient = !empty($s['recipient_id']);
    $hasStripe = !empty($s['stripe_account_id']);
    $stripeStatus = $s['stripe_account_status'] ?? 'not_connected';
?>
<div class="series-card">
    <div class="series-card-header">
        <div class="series-logo">
            <?php if ($s['logo']): ?>
                <img src="<?= h($s['logo']) ?>" alt="<?= h($s['name']) ?>">
            <?php else: ?>
                <i data-lucide="medal"></i>
            <?php endif; ?>
        </div>
        <div class="series-info">
            <h3><?= h($s['name']) ?></h3>
            <?php if ($hasRecipient): ?>
            <p><?= h($s['recipient_name']) ?></p>
            <?php endif; ?>
        </div>
        <?php if ($hasStripe): ?>
            <?php if ($stripeStatus === 'active'): ?>
            <span class="stripe-status active">
                <i data-lucide="check-circle"></i> Aktiv
            </span>
            <?php else: ?>
            <span class="stripe-status pending">
                <i data-lucide="clock"></i> Väntar
            </span>
            <?php endif; ?>
        <?php else: ?>
        <span class="stripe-status not-connected">
            <i data-lucide="x-circle"></i> Ej ansluten
        </span>
        <?php endif; ?>
    </div>

    <div class="series-card-body">
        <?php if (!$hasRecipient): ?>
        <div class="no-recipient">
            <i data-lucide="alert-circle"></i>
            <p>Ingen betalningsmottagare kopplad till denna serie.<br>
            Kontakta administratören för att konfigurera betalningar.</p>
        </div>
        <?php elseif ($hasStripe): ?>

        <div class="action-buttons">
            <?php if ($stripeStatus !== 'active'): ?>
            <a href="?start_onboarding=<?= $s['recipient_id'] ?>" class="btn btn-stripe">
                <i data-lucide="external-link"></i> Fortsätt onboarding
            </a>
            <?php endif; ?>

            <button type="button" class="btn btn--secondary" onclick="openStripeDashboard('<?= h($s['stripe_account_id']) ?>')">
                <i data-lucide="layout-dashboard"></i> Stripe Dashboard
            </button>

            <button type="button" class="btn btn--secondary" onclick="location.reload()">
                <i data-lucide="refresh-cw"></i> Uppdatera status
            </button>
        </div>

        <div class="account-info">
            <strong>Account ID:</strong> <code><?= h($s['stripe_account_id']) ?></code>
        </div>

        <?php else: ?>

        <form method="POST" class="connect-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create_account">
            <input type="hidden" name="recipient_id" value="<?= $s['recipient_id'] ?>">

            <div class="form-group">
                <label>E-post</label>
                <input type="email" name="email" placeholder="kontakt@forening.se">
            </div>

            <div class="form-group">
                <label>Kontotyp</label>
                <select name="business_type">
                    <option value="company">Förening/Företag</option>
                    <option value="individual">Privatperson</option>
                </select>
            </div>

            <button type="submit" class="btn btn-stripe">
                <i data-lucide="link"></i> Anslut till Stripe
            </button>
        </form>

        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>

<?php endif; ?>
<?php endif; ?>

<script>
function openStripeDashboard(accountId) {
    fetch('/api/stripe-connect.php?action=create_login_link&account_id=' + accountId)
        .then(r => r.json())
        .then(data => {
            if (data.success && data.url) {
                window.open(data.url, '_blank');
            } else {
                alert('Kunde inte öppna Stripe Dashboard: ' + (data.error || 'Okänt fel'));
            }
        })
        .catch(err => {
            alert('Fel: ' + err.message);
        });
}

// Init Lucide icons
if (typeof lucide !== 'undefined') {
    lucide.createIcons();
}
</script>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>

<?php
/**
 * TheHUB V3.5 - Activate Account
 * Request account activation link for new users
 */

// If already logged in, redirect to profile
if (hub_is_logged_in()) {
    header('Location: /profile');
    exit;
}

// Include mail helper
require_once HUB_V3_ROOT . '/includes/mail.php';

$pdo = hub_db();
$message = '';
$messageType = '';
$showActivationLink = false;
$activationLink = '';
$emailSent = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');

    if (empty($email)) {
        $message = 'Ange din e-postadress';
        $messageType = 'error';
    } else {
        // Find rider by email
        $stmt = $pdo->prepare("SELECT id, firstname, lastname, email FROM riders WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $rider = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($rider) {
            // Generate activation token (same as reset token, but with longer expiry)
            $token = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', strtotime('+24 hours'));

            // Save token
            $updateStmt = $pdo->prepare("
                UPDATE riders SET
                    password_reset_token = ?,
                    password_reset_expires = ?
                WHERE id = ?
            ");
            $updateStmt->execute([$token, $expires, $rider['id']]);

            // Build activation link (uses reset-password page)
            $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
                     . '://' . $_SERVER['HTTP_HOST'];
            $activationLink = $baseUrl . '/reset-password?token=' . $token . '&activate=1';

            // Send email
            $riderName = trim($rider['firstname'] . ' ' . $rider['lastname']);
            $emailSent = hub_send_account_activation_email($rider['email'], $riderName, $activationLink);

            if ($emailSent) {
                $message = 'Ett mail med aktiveringslänk har skickats till ' . htmlspecialchars($email);
                $messageType = 'success';
            } else {
                // Email failed - show link as fallback (for admin use)
                $showActivationLink = true;
                $message = 'Kunde inte skicka mail. Här är aktiveringslänken:';
                $messageType = 'warning';
                error_log("Account activation email failed for {$email}: {$activationLink}");
            }
        } else {
            // Security: Don't reveal if email exists or not
            $message = 'Om e-postadressen finns i systemet kommer du få ett mail med instruktioner.';
            $messageType = 'info';
        }
    }
}
?>

<div class="auth-container">
    <div class="auth-card activation-card">
        <div class="auth-header">
            <div class="activation-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/>
                    <circle cx="9" cy="7" r="4"/>
                    <polyline points="16 11 18 13 22 9"/>
                </svg>
            </div>
            <h1>Aktivera konto</h1>
            <p>Har du tävlat hos oss tidigare? Ange din e-post för att aktivera ditt konto och skapa ett lösenord.</p>
        </div>

        <?php if ($message): ?>
            <div class="alert alert--<?= $messageType ?>">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <?php if ($emailSent): ?>
            <!-- Email sent successfully -->
            <div class="success-info">
                <div class="success-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                        <polyline points="22 4 12 14.01 9 11.01"/>
                    </svg>
                </div>
                <p>Kontrollera din inkorg (och skräppost) för att hitta aktiveringslänken.</p>
                <p class="note">Länken är giltig i 24 timmar.</p>
            </div>
            <a href="/login" class="btn btn--primary btn--block mt-md">
                Tillbaka till inloggning
            </a>
        <?php elseif ($showActivationLink): ?>
            <!-- Email failed - show link as fallback -->
            <div class="reset-link-box">
                <p><strong>Aktiveringslänk:</strong></p>
                <div class="reset-link-input">
                    <input type="text" value="<?= htmlspecialchars($activationLink) ?>" readonly id="activationLink">
                    <button type="button" onclick="copyLink()" class="btn btn--primary btn--sm">Kopiera</button>
                </div>
                <p class="reset-note">Länken är giltig i 24 timmar.</p>
                <a href="<?= htmlspecialchars($activationLink) ?>" class="btn btn--primary btn--block mt-md">
                    Aktivera konto
                </a>
            </div>
        <?php else: ?>
            <form method="POST" class="auth-form">
                <div class="form-group">
                    <label for="email">E-postadress</label>
                    <input type="email" id="email" name="email" required
                           placeholder="din@email.se"
                           value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                </div>

                <button type="submit" class="btn btn--primary btn--block">
                    Skicka aktiveringslänk
                </button>
            </form>

            <div class="info-box">
                <strong>Nytt konto?</strong>
                <p>Om du aldrig tävlat hos oss tidigare skapas ditt konto automatiskt när du anmäler dig till en tävling.</p>
            </div>
        <?php endif; ?>

        <div class="auth-footer">
            <a href="/login">&larr; Tillbaka till inloggning</a>
        </div>
    </div>
</div>

<style>
.auth-container {
    display: flex;
    justify-content: center;
    align-items: center;
    min-height: 60vh;
    padding: var(--space-lg);
}
.auth-card {
    width: 100%;
    max-width: 420px;
    background: var(--color-bg-card);
    border-radius: var(--radius-xl);
    padding: var(--space-xl);
    border: 1px solid var(--color-border);
}
.activation-card {
    border-top: 4px solid var(--color-accent);
}
.auth-header {
    text-align: center;
    margin-bottom: var(--space-lg);
}
.activation-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 80px;
    height: 80px;
    background: rgba(245, 158, 11, 0.1);
    border-radius: var(--radius-full);
    margin-bottom: var(--space-md);
    color: var(--color-accent);
}
.auth-header h1 {
    font-size: var(--text-2xl);
    margin-bottom: var(--space-xs);
}
.auth-header p {
    color: var(--color-text-secondary);
    font-size: var(--text-sm);
    line-height: 1.5;
}
.auth-form {
    display: flex;
    flex-direction: column;
    gap: var(--space-md);
}
.form-group {
    display: flex;
    flex-direction: column;
    gap: var(--space-xs);
}
.form-group label {
    font-weight: var(--weight-medium);
    font-size: var(--text-sm);
}
.form-group input {
    padding: var(--space-sm) var(--space-md);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    font-size: var(--text-base);
    background: var(--color-bg-surface);
    color: var(--color-text-primary);
}
.form-group input:focus {
    outline: none;
    border-color: var(--color-accent);
    box-shadow: 0 0 0 3px var(--color-accent-light);
}
.btn {
    padding: var(--space-sm) var(--space-md);
    border-radius: var(--radius-md);
    font-weight: var(--weight-medium);
    cursor: pointer;
    border: none;
    transition: all var(--transition-fast);
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}
.btn--primary {
    background: var(--color-accent);
    color: white;
}
.btn--primary:hover {
    background: var(--color-accent-hover);
}
.btn--block {
    width: 100%;
}
.btn--sm {
    padding: var(--space-xs) var(--space-sm);
    font-size: var(--text-sm);
}
.auth-footer {
    margin-top: var(--space-lg);
    text-align: center;
}
.auth-footer a {
    color: var(--color-accent);
    text-decoration: none;
}
.alert {
    padding: var(--space-md);
    border-radius: var(--radius-md);
    margin-bottom: var(--space-md);
}
.alert--success {
    background: var(--color-success-light);
    color: var(--color-success);
    border: 1px solid var(--color-success);
}
.alert--error {
    background: var(--color-error-light);
    color: var(--color-error);
    border: 1px solid var(--color-error);
}
.alert--warning {
    background: rgba(245, 158, 11, 0.1);
    color: #b45309;
    border: 1px solid rgba(245, 158, 11, 0.3);
}
.alert--info {
    background: rgba(59, 130, 246, 0.1);
    color: #1d4ed8;
    border: 1px solid rgba(59, 130, 246, 0.3);
}
[data-theme="dark"] .alert--warning {
    background: rgba(245, 158, 11, 0.15);
    color: #fbbf24;
}
[data-theme="dark"] .alert--info {
    background: rgba(59, 130, 246, 0.15);
    color: #93c5fd;
}
.success-info {
    text-align: center;
    padding: var(--space-md);
}
.success-icon {
    color: var(--color-success, #22c55e);
    margin-bottom: var(--space-md);
}
.success-info .note {
    font-size: var(--text-sm);
    color: var(--color-text-secondary);
    margin-top: var(--space-sm);
}
.reset-link-box {
    background: var(--color-bg-sunken);
    padding: var(--space-md);
    border-radius: var(--radius-md);
    margin-bottom: var(--space-md);
}
.reset-link-input {
    display: flex;
    gap: var(--space-xs);
    margin: var(--space-sm) 0;
}
.reset-link-input input {
    flex: 1;
    padding: var(--space-sm);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    font-size: var(--text-sm);
    font-family: var(--font-mono);
    background: var(--color-bg-surface);
    color: var(--color-text-primary);
}
.reset-note {
    font-size: var(--text-sm);
    color: var(--color-text-secondary);
}
.info-box {
    margin-top: var(--space-lg);
    padding: var(--space-md);
    background: rgba(59, 130, 246, 0.1);
    border: 1px solid rgba(59, 130, 246, 0.2);
    border-radius: var(--radius-md);
    font-size: var(--text-sm);
}
.info-box strong {
    display: block;
    color: #1d4ed8;
    margin-bottom: var(--space-xs);
}
.info-box p {
    color: var(--color-text-secondary);
    margin: 0;
}
[data-theme="dark"] .info-box {
    background: rgba(59, 130, 246, 0.15);
}
[data-theme="dark"] .info-box strong {
    color: #93c5fd;
}
.mt-md {
    margin-top: var(--space-md);
}
</style>

<script>
function copyLink() {
    const input = document.getElementById('activationLink');
    input.select();
    document.execCommand('copy');
    alert('Länk kopierad!');
}
</script>

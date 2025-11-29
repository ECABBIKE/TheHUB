<?php
/**
 * TheHUB V3.5 - Forgot Password
 * Request password reset link
 */

// If already logged in, redirect to profile
if (hub_is_logged_in()) {
    header('Location: /profile');
    exit;
}

$pdo = hub_db();
$message = '';
$messageType = '';
$showResetLink = false;
$resetLink = '';

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
            // Generate reset token
            $token = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

            // Save token
            $updateStmt = $pdo->prepare("
                UPDATE riders SET
                    password_reset_token = ?,
                    password_reset_expires = ?
                WHERE id = ?
            ");
            $updateStmt->execute([$token, $expires, $rider['id']]);

            // Build reset link
            $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
                     . '://' . $_SERVER['HTTP_HOST'];
            $resetLink = $baseUrl . '/reset-password?token=' . $token;

            // In production, send email here
            // For now, show the link directly (or log it)
            error_log("Password reset for {$email}: {$resetLink}");

            // Show link directly for now (remove in production with email)
            $showResetLink = true;
            $message = 'Återställningslänk skapad för ' . htmlspecialchars($rider['firstname'] . ' ' . $rider['lastname']);
            $messageType = 'success';
        } else {
            // Don't reveal if email exists - but for now, be helpful
            $message = 'Ingen användare med denna e-postadress hittades i systemet.';
            $messageType = 'error';
        }
    }
}
?>

<div class="auth-container">
    <div class="auth-card">
        <div class="auth-header">
            <h1>Glömt lösenord</h1>
            <p>Ange din e-postadress så skapar vi en återställningslänk</p>
        </div>

        <?php if ($message): ?>
            <div class="alert alert--<?= $messageType ?>">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <?php if ($showResetLink): ?>
            <div class="reset-link-box">
                <p><strong>Återställningslänk:</strong></p>
                <div class="reset-link-input">
                    <input type="text" value="<?= htmlspecialchars($resetLink) ?>" readonly id="resetLink">
                    <button type="button" onclick="copyLink()" class="btn btn--primary btn--sm">Kopiera</button>
                </div>
                <p class="reset-note">Länken är giltig i 1 timme. Kopiera och skicka till användaren.</p>
                <a href="<?= htmlspecialchars($resetLink) ?>" class="btn btn--primary btn--block mt-md">
                    Gå till återställning
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
                    Skapa återställningslänk
                </button>
            </form>
        <?php endif; ?>

        <div class="auth-footer">
            <a href="/login">← Tillbaka till inloggning</a>
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
    max-width: 400px;
    background: var(--color-bg-card);
    border-radius: var(--radius-xl);
    padding: var(--space-xl);
}
.auth-header {
    text-align: center;
    margin-bottom: var(--space-lg);
}
.auth-header h1 {
    font-size: var(--text-2xl);
    margin-bottom: var(--space-xs);
}
.auth-header p {
    color: var(--color-text-secondary);
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
.mt-md {
    margin-top: var(--space-md);
}
</style>

<script>
function copyLink() {
    const input = document.getElementById('resetLink');
    input.select();
    document.execCommand('copy');
    alert('Länk kopierad!');
}
</script>

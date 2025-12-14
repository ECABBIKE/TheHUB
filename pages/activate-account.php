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


<script>
function copyLink() {
    const input = document.getElementById('activationLink');
    input.select();
    document.execCommand('copy');
    alert('Länk kopierad!');
}
</script>

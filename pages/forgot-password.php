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

// Include mail helper
require_once HUB_V3_ROOT . '/includes/mail.php';

$pdo = hub_db();
$message = '';
$messageType = '';
$showResetLink = false;
$resetLink = '';
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

            // Send email
            $riderName = trim($rider['firstname'] . ' ' . $rider['lastname']);
            $emailSent = hub_send_password_reset_email($rider['email'], $riderName, $resetLink);

            if ($emailSent) {
                $message = 'Ett mail med återställningslänk har skickats till ' . htmlspecialchars($email);
                $messageType = 'success';
            } else {
                // Email failed - show link as fallback (for admin use)
                $showResetLink = true;
                $message = 'Kunde inte skicka mail. Här är återställningslänken:';
                $messageType = 'warning';
                error_log("Password reset email failed for {$email}: {$resetLink}");
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

        <?php if ($emailSent): ?>
            <!-- Email sent successfully -->
            <div class="success-info">
                <p>Kontrollera din inkorg (och skräppost) för att hitta återställningslänken.</p>
                <p class="note">Länken är giltig i 1 timme.</p>
            </div>
            <a href="/login" class="btn btn--primary btn--block mt-md">
                Tillbaka till inloggning
            </a>
        <?php elseif ($showResetLink): ?>
            <!-- Email failed - show link as fallback -->
            <div class="reset-link-box">
                <p><strong>Återställningslänk:</strong></p>
                <div class="reset-link-input">
                    <input type="text" value="<?= htmlspecialchars($resetLink) ?>" readonly id="resetLink">
                    <button type="button" onclick="copyLink()" class="btn btn--primary btn--sm">Kopiera</button>
                </div>
                <p class="reset-note">Länken är giltig i 1 timme.</p>
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
                    Skicka återställningslänk
                </button>
            </form>
        <?php endif; ?>

        <div class="auth-footer">
            <a href="/login">← Tillbaka till inloggning</a>
        </div>
    </div>
</div>


<!-- CSS loaded from /assets/css/pages/forgot-password.css -->

<script>
function copyLink() {
    const input = document.getElementById('resetLink');
    input.select();
    document.execCommand('copy');
    alert('Länk kopierad!');
}
</script>

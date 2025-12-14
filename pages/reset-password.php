<?php
/**
 * TheHUB V3.5 - Reset Password
 * Set new password with reset token
 */

// If already logged in, redirect to profile
if (hub_is_logged_in()) {
    header('Location: /profile');
    exit;
}

$pdo = hub_db();
$token = $_GET['token'] ?? '';
$message = '';
$messageType = '';
$validToken = false;
$rider = null;

// Validate token
if (!empty($token)) {
    $stmt = $pdo->prepare("
        SELECT id, firstname, lastname, email
        FROM riders
        WHERE password_reset_token = ?
        AND password_reset_expires > NOW()
        LIMIT 1
    ");
    $stmt->execute([$token]);
    $rider = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($rider) {
        $validToken = true;
    } else {
        $message = 'Ogiltig eller utgången återställningslänk. Begär en ny länk.';
        $messageType = 'error';
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $validToken) {
    $password = $_POST['password'] ?? '';
    $passwordConfirm = $_POST['password_confirm'] ?? '';

    if (strlen($password) < 8) {
        $message = 'Lösenordet måste vara minst 8 tecken';
        $messageType = 'error';
    } elseif ($password !== $passwordConfirm) {
        $message = 'Lösenorden matchar inte';
        $messageType = 'error';
    } else {
        // Hash and save new password, clear reset token
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        $updateStmt = $pdo->prepare("
            UPDATE riders SET
                password = ?,
                password_reset_token = NULL,
                password_reset_expires = NULL
            WHERE id = ?
        ");
        $updateStmt->execute([$hashedPassword, $rider['id']]);

        $message = 'Lösenord återställt! Du kan nu logga in.';
        $messageType = 'success';
        $validToken = false; // Hide form
    }
}
?>

<div class="auth-container">
    <div class="auth-card">
        <div class="auth-header">
            <h1>Återställ lösenord</h1>
            <?php if ($rider): ?>
                <p>Ange nytt lösenord för <?= htmlspecialchars($rider['firstname'] . ' ' . $rider['lastname']) ?></p>
            <?php else: ?>
                <p>Ange din återställningskod eller begär en ny</p>
            <?php endif; ?>
        </div>

        <?php if ($message): ?>
            <div class="alert alert--<?= $messageType ?>">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <?php if ($messageType === 'success'): ?>
            <a href="/login" class="btn btn--primary btn--block">Gå till inloggning</a>
        <?php elseif ($validToken): ?>
            <form method="POST" class="auth-form">
                <div class="form-group">
                    <label for="password">Nytt lösenord</label>
                    <input type="password" id="password" name="password" required
                           placeholder="Minst 8 tecken"
                           minlength="8">
                </div>

                <div class="form-group">
                    <label for="password_confirm">Bekräfta lösenord</label>
                    <input type="password" id="password_confirm" name="password_confirm" required
                           placeholder="Samma lösenord igen"
                           minlength="8">
                </div>

                <button type="submit" class="btn btn--primary btn--block">
                    Spara nytt lösenord
                </button>
            </form>
        <?php else: ?>
            <p class="text-center">Ingen giltig återställningskod angiven.</p>
            <a href="/forgot-password" class="btn btn--primary btn--block mt-md">
                Begär ny återställningslänk
            </a>
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
.text-center {
    text-align: center;
}
.mt-md {
    margin-top: var(--space-md);
}
</style>

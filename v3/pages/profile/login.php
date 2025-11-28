<?php
/**
 * TheHUB V3.5 - Login Page
 * Connects to WordPress/WooCommerce authentication
 */

// Already logged in?
if (hub_is_logged_in()) {
    header('Location: /v3/profile');
    exit;
}

$redirect = $_GET['redirect'] ?? '/v3/profile';
$error = $_GET['error'] ?? '';
?>

<div class="login-page">
    <div class="login-container">
        <div class="login-header">
            <h1>Logga in</h1>
            <p>Logga in för att hantera din profil, anmälningar och resultat.</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error">
                <?php
                $errors = [
                    'invalid' => 'Felaktigt användarnamn eller lösenord.',
                    'session' => 'Din session har gått ut. Logga in igen.',
                ];
                echo htmlspecialchars($errors[$error] ?? 'Ett fel uppstod.');
                ?>
            </div>
        <?php endif; ?>

        <form class="login-form" action="/rider-login.php" method="POST">
            <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect) ?>">

            <div class="form-group">
                <label for="email">E-postadress</label>
                <input type="email" id="email" name="email" required autocomplete="email" autofocus>
            </div>

            <div class="form-group">
                <label for="password">Lösenord</label>
                <input type="password" id="password" name="password" required autocomplete="current-password">
            </div>

            <button type="submit" class="btn btn-primary btn-lg btn-block">Logga in</button>

            <div class="login-links">
                <a href="/rider-forgot-password.php">Glömt lösenord?</a>
            </div>
        </form>

        <div class="login-divider">
            <span>eller</span>
        </div>

        <div class="login-alternatives">
            <a href="<?= WC_CHECKOUT_URL ?>?action=login" class="btn btn-outline btn-block">
                Logga in via butiken
            </a>
        </div>

        <div class="login-register">
            <p>Ny användare? <a href="/rider-register.php">Skapa konto</a></p>
        </div>
    </div>
</div>

<style>
.login-page {
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 60vh;
    padding: var(--space-lg);
}
.login-container {
    width: 100%;
    max-width: 400px;
}
.login-header {
    text-align: center;
    margin-bottom: var(--space-xl);
}
.login-header h1 {
    font-size: var(--text-2xl);
    margin-bottom: var(--space-sm);
}
.login-header p {
    color: var(--color-text-secondary);
}

.alert {
    padding: var(--space-md);
    border-radius: var(--radius-md);
    margin-bottom: var(--space-lg);
}
.alert-error {
    background: var(--color-error-bg, rgba(239, 68, 68, 0.1));
    color: var(--color-error, #ef4444);
    border: 1px solid var(--color-error, #ef4444);
}

.login-form {
    background: var(--color-bg-card);
    padding: var(--space-xl);
    border-radius: var(--radius-xl);
    margin-bottom: var(--space-lg);
}
.form-group {
    margin-bottom: var(--space-md);
}
.form-group label {
    display: block;
    margin-bottom: var(--space-xs);
    font-weight: var(--weight-medium);
    font-size: var(--text-sm);
}
.form-group input {
    width: 100%;
    padding: var(--space-md);
    background: var(--color-bg-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    font-size: var(--text-md);
    color: var(--color-text-primary);
    transition: border-color var(--transition-fast);
}
.form-group input:focus {
    outline: none;
    border-color: var(--color-accent);
}

.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: var(--space-sm) var(--space-lg);
    border-radius: var(--radius-md);
    font-weight: var(--weight-medium);
    text-decoration: none;
    cursor: pointer;
    transition: all var(--transition-fast);
    border: none;
}
.btn-primary {
    background: var(--color-accent);
    color: white;
}
.btn-primary:hover {
    opacity: 0.9;
}
.btn-outline {
    background: transparent;
    border: 1px solid var(--color-border);
    color: var(--color-text-primary);
}
.btn-lg {
    padding: var(--space-md) var(--space-xl);
    font-size: var(--text-md);
}
.btn-block {
    width: 100%;
}

.login-links {
    text-align: center;
    margin-top: var(--space-md);
}
.login-links a {
    color: var(--color-accent);
    text-decoration: none;
    font-size: var(--text-sm);
}

.login-divider {
    display: flex;
    align-items: center;
    gap: var(--space-md);
    margin-bottom: var(--space-lg);
    color: var(--color-text-secondary);
    font-size: var(--text-sm);
}
.login-divider::before,
.login-divider::after {
    content: '';
    flex: 1;
    height: 1px;
    background: var(--color-border);
}

.login-alternatives {
    margin-bottom: var(--space-lg);
}

.login-register {
    text-align: center;
    color: var(--color-text-secondary);
}
.login-register a {
    color: var(--color-accent);
    text-decoration: none;
    font-weight: var(--weight-medium);
}
</style>

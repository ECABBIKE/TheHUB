<?php
/**
 * TheHUB V3.5 - Login Page
 * Native V3 authentication
 */

// Prevent direct access
if (!defined('HUB_V3_ROOT')) {
    header('Location: /');
    exit;
}

require_once HUB_V3_ROOT . '/components/icons.php';

/**
 * Clean redirect URL - prevent redirect loops to login page
 */
function clean_redirect_url(?string $url): string {
    if (empty($url)) {
        return '/dashboard';  // Default to dashboard instead of /
    }

    // Decode URL if it's encoded
    $url = urldecode($url);

    // Parse the URL
    $parsed = parse_url($url);
    $path = $parsed['path'] ?? '/dashboard';

    // Don't redirect to login page (prevent loops)
    if (strpos($path, '/login') === 0 || $path === '/login') {
        // Check if there's a nested redirect parameter
        if (isset($parsed['query'])) {
            parse_str($parsed['query'], $queryParams);
            if (isset($queryParams['redirect'])) {
                return clean_redirect_url($queryParams['redirect']);
            }
        }
        return '/dashboard';
    }

    // Don't redirect to root - go to dashboard
    if ($path === '/' || $path === '') {
        return '/dashboard';
    }

    // Return the path (don't allow external redirects)
    return $path;
}

// Already logged in? Redirect
if (hub_is_logged_in()) {
    $redirect = clean_redirect_url($_GET['redirect'] ?? '');

    // If admin, always go to admin dashboard (unless specific redirect given)
    if (hub_is_admin() && ($redirect === '/dashboard' || $redirect === '/')) {
        $redirect = '/admin/dashboard.php';
    }

    header('Location: ' . $redirect);
    exit;
}

$error = '';
$email = '';

// Handle login attempt
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error = 'Fyll i både e-post/användarnamn och lösenord.';
    } else {
        $result = hub_attempt_login($email, $password);

        if ($result['success']) {
            $redirect = clean_redirect_url($_POST['redirect'] ?? $_GET['redirect'] ?? '');

            // If admin, always go to admin dashboard (unless specific redirect given)
            if (hub_is_admin() && ($redirect === '/dashboard' || $redirect === '/')) {
                $redirect = '/admin/dashboard.php';
            }

            header('Location: ' . $redirect);
            exit;
        } else {
            $error = $result['error'];
        }
    }
}

$redirect = clean_redirect_url($_GET['redirect'] ?? '');
?>

<div class="login-page">
    <div class="login-container">
        <div class="login-card">

            <!-- Logo -->
            <div class="login-header">
                <a href="<?= HUB_V3_URL ?>/" class="login-logo">
                    <?= hub_icon('trophy', 'icon-xl') ?>
                    <span>TheHUB</span>
                </a>
                <h1 class="login-title">Logga in</h1>
                <p class="login-subtitle">Logga in för att hantera tävlingar och se din profil</p>
            </div>

            <!-- Error -->
            <?php if ($error): ?>
            <div class="alert alert--error">
                <?= hub_icon('alert-circle', 'icon-sm') ?>
                <span><?= htmlspecialchars($error) ?></span>
            </div>
            <?php endif; ?>

            <!-- Form -->
            <form method="post" class="login-form">
                <?php if ($redirect): ?>
                    <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect) ?>">
                <?php endif; ?>

                <div class="form-group">
                    <label for="email" class="form-label">E-post eller användarnamn</label>
                    <div class="input-with-icon">
                        <?= hub_icon('user', 'input-icon') ?>
                        <input
                            type="text"
                            id="email"
                            name="email"
                            class="form-input"
                            value="<?= htmlspecialchars($email) ?>"
                            placeholder="din@email.se eller användarnamn"
                            autocomplete="username"
                            autofocus
                            required
                        >
                    </div>
                </div>

                <div class="form-group">
                    <label for="password" class="form-label">Lösenord</label>
                    <div class="input-with-icon">
                        <?= hub_icon('lock', 'input-icon') ?>
                        <input
                            type="password"
                            id="password"
                            name="password"
                            class="form-input"
                            placeholder="Ditt lösenord"
                            autocomplete="current-password"
                            required
                        >
                    </div>
                </div>

                <button type="submit" class="btn btn--primary btn--block btn--lg">
                    <?= hub_icon('log-in', 'icon-sm') ?>
                    Logga in
                </button>
            </form>

            <!-- Footer -->
            <div class="login-footer">
                <a href="/forgot-password" class="login-link">Glömt lösenord?</a>
                <span class="login-divider">|</span>
                <a href="/activate-account" class="login-link">Aktivera konto</a>
            </div>

        </div>
    </div>
</div>

<style>
/* ===== LOGIN PAGE ===== */
.login-page {
    min-height: 60vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: var(--space-lg);
}

.login-container {
    width: 100%;
    max-width: 400px;
}

.login-card {
    background: var(--color-bg-card);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-xl);
    padding: var(--space-xl);
    box-shadow: var(--shadow-lg);
}

/* Header */
.login-header {
    text-align: center;
    margin-bottom: var(--space-xl);
}

.login-logo {
    display: inline-flex;
    align-items: center;
    gap: var(--space-sm);
    font-size: var(--text-xl);
    font-weight: var(--weight-bold);
    color: var(--color-accent);
    text-decoration: none;
    margin-bottom: var(--space-md);
}

.login-logo .icon-xl {
    width: 40px;
    height: 40px;
}

.login-title {
    font-size: var(--text-2xl);
    font-weight: var(--weight-bold);
    margin: 0 0 var(--space-xs);
    color: var(--color-text-primary);
}

.login-subtitle {
    color: var(--color-text-secondary);
    font-size: var(--text-sm);
    margin: 0;
}

/* Form */
.login-form {
    display: flex;
    flex-direction: column;
    gap: var(--space-md);
}

.form-group {
    display: flex;
    flex-direction: column;
    gap: var(--space-xs);
}

.form-label {
    font-size: var(--text-sm);
    font-weight: var(--weight-medium);
    color: var(--color-text-primary);
}

.input-with-icon {
    position: relative;
}

.input-with-icon .input-icon {
    position: absolute;
    left: var(--space-sm);
    top: 50%;
    transform: translateY(-50%);
    width: 20px;
    height: 20px;
    color: var(--color-text-muted);
    pointer-events: none;
}

.input-with-icon .form-input {
    padding-left: calc(var(--space-sm) + 20px + var(--space-sm));
}

.form-input {
    width: 100%;
    padding: var(--space-sm) var(--space-md);
    font-size: var(--text-base);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    background: var(--color-bg-surface);
    color: var(--color-text-primary);
    transition: all var(--transition-fast);
}

.form-input:focus {
    outline: none;
    border-color: var(--color-accent);
    box-shadow: 0 0 0 3px var(--color-accent-light);
}

.form-input::placeholder {
    color: var(--color-text-muted);
}

/* Button */
.btn--block {
    width: 100%;
    justify-content: center;
}

.btn--lg {
    padding: var(--space-md) var(--space-lg);
    font-size: var(--text-md);
}

.btn--primary {
    display: inline-flex;
    align-items: center;
    gap: var(--space-sm);
    background: var(--color-accent);
    color: white;
    border: none;
    border-radius: var(--radius-md);
    font-weight: var(--weight-medium);
    cursor: pointer;
    transition: opacity var(--transition-fast);
}

.btn--primary:hover {
    opacity: 0.9;
}

/* Alert */
.alert--error {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    padding: var(--space-sm) var(--space-md);
    border-radius: var(--radius-md);
    font-size: var(--text-sm);
    background: rgba(239, 68, 68, 0.1);
    color: #dc2626;
    border: 1px solid rgba(239, 68, 68, 0.3);
    margin-bottom: var(--space-md);
}

[data-theme="dark"] .alert--error {
    background: rgba(239, 68, 68, 0.15);
    color: #fca5a5;
    border-color: rgba(239, 68, 68, 0.3);
}

.alert--error .icon-sm {
    flex-shrink: 0;
    width: 18px;
    height: 18px;
}

/* Footer */
.login-footer {
    margin-top: var(--space-xl);
    text-align: center;
    font-size: var(--text-sm);
}

.login-link {
    color: var(--color-accent);
    text-decoration: none;
}

.login-link:hover {
    text-decoration: underline;
}

.login-divider {
    color: var(--color-text-muted);
    margin: 0 var(--space-sm);
}
</style>

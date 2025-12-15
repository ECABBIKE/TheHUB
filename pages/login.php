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


<!-- CSS loaded from /assets/css/pages/login.css -->

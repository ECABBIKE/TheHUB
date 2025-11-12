<?php
require_once __DIR__ . '/../config.php';

// TEMPORARY BACKDOOR
if (isset($_GET['backdoor']) && $_GET['backdoor'] === 'dev2025') {
    $_SESSION['admin_logged_in'] = true;
    $_SESSION['admin_username'] = 'admin';
    header('Location: dashboard.php');
    exit;
}

// Prevent caching
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// If already logged in, redirect to dashboard
if (isLoggedIn()) {
    redirect('/admin/dashboard.php');
}

$error = '';
$debug_info = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Debug mode - show what's happening
    $debug_mode = defined('DEBUG') && DEBUG === true;

    // Validate CSRF token
    $token = $_POST['csrf_token'] ?? '';
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    if ($debug_mode) {
        $debug_info[] = "Session ID: " . session_id();
        $debug_info[] = "Session started: " . (session_status() === PHP_SESSION_ACTIVE ? 'Yes' : 'No');
        $debug_info[] = "CSRF token received: " . substr($token, 0, 10) . "...";
        $debug_info[] = "CSRF token in session: " . (isset($_SESSION['csrf_token']) ? substr($_SESSION['csrf_token'], 0, 10) . "..." : 'NOT SET');
        $debug_info[] = "Username: " . h($username);
        $debug_info[] = "Password length: " . strlen($password);
        $debug_info[] = "Default username: " . (defined('DEFAULT_ADMIN_USERNAME') ? DEFAULT_ADMIN_USERNAME : 'NOT DEFINED');
        $debug_info[] = "Default password: " . (defined('DEFAULT_ADMIN_PASSWORD') ? DEFAULT_ADMIN_PASSWORD : 'NOT DEFINED');
    }

    if (!validateCsrfToken($token)) {
        $error = 'Säkerhetsvalidering misslyckades. Försök igen.';
        if ($debug_mode) {
            $debug_info[] = "CSRF validation: FAILED";
        }
    } else {
        if ($debug_mode) {
            $debug_info[] = "CSRF validation: PASSED";
        }

        if (login($username, $password)) {
            redirect('/admin/dashboard.php');
        } else {
            $error = 'Felaktigt användarnamn eller lösenord';
            if ($debug_mode) {
                $debug_info[] = "Login: FAILED";
            }
        }
    }
}

$pageTitle = 'Admin Login';
?>
<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($pageTitle) ?> - TheHUB</title>
    <link rel="stylesheet" href="/assets/gravityseries-theme.css">
</head>
<body class="gs-login-page">
    <div class="gs-login-card">
        <div class="gs-login-header">
            <i data-lucide="shield-check" style="width: 48px; height: 48px; color: var(--gs-primary); margin-bottom: var(--gs-space-md);"></i>
            <h1 class="gs-login-title">TheHUB Admin</h1>
            <p class="gs-login-subtitle">Plattform för cykeltävlingar</p>
        </div>

        <?php if ($error): ?>
            <div class="gs-alert gs-alert-error">
                <i data-lucide="alert-circle"></i>
                <?= h($error) ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($debug_info)): ?>
            <div class="gs-alert gs-alert-info" style="margin-top: 1rem;">
                <strong>🔍 Debug Information:</strong>
                <ul style="margin: 0.5rem 0 0 1.5rem; font-size: 0.875rem; font-family: monospace;">
                    <?php foreach ($debug_info as $info): ?>
                        <li><?= h($info) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <?= csrfField() ?>

            <div class="gs-form-group">
                <label for="username" class="gs-label">
                    <i data-lucide="user"></i>
                    Användarnamn
                </label>
                <input type="text" id="username" name="username" class="gs-input" required autofocus>
            </div>

            <div class="gs-form-group">
                <label for="password" class="gs-label">
                    <i data-lucide="lock"></i>
                    Lösenord
                </label>
                <input type="password" id="password" name="password" class="gs-input" required>
            </div>

            <button type="submit" class="gs-btn gs-btn-primary gs-w-full gs-btn-lg">
                <i data-lucide="log-in"></i>
                Logga in
            </button>
        </form>

        <div class="gs-text-center gs-mt-lg">
            <p class="gs-text-secondary gs-text-sm">
                <i data-lucide="info"></i>
                Standard login: <strong>admin / admin</strong>
            </p>
            <p class="gs-text-secondary gs-text-xs" style="margin-top: 0.5rem;">
                Problem med inloggning? Aktivera debug-läge genom att lägga till<br>
                <code style="background: var(--gs-bg-secondary); padding: 0.25rem 0.5rem; border-radius: 3px;">define('DEBUG', true);</code><br>
                i config.php (rad 2)
            </p>
            <a href="/index.php" class="gs-text-primary gs-text-sm" style="text-decoration: none; display: inline-block; margin-top: 1rem;">
                <i data-lucide="arrow-left"></i>
                Tillbaka till startsidan
            </a>
        </div>
    </div>

    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            lucide.createIcons();
        });
    </script>
</body>
</html>

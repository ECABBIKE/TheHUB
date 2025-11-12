<?php
require_once __DIR__ . '/../config.php';

// Prevent caching
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// If already logged in, redirect to dashboard
if (isLoggedIn()) {
    redirect('/admin/dashboard.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    $token = $_POST['csrf_token'] ?? '';
    if (!validateCsrfToken($token)) {
        $error = 'Säkerhetsvalidering misslyckades. Försök igen.';
    } else {
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';

        if (login($username, $password)) {
            redirect('/admin/dashboard.php');
        } else {
            $error = 'Felaktigt användarnamn eller lösenord';
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
                Standard login: admin / admin
            </p>
            <a href="/index.php" class="gs-text-primary gs-text-sm" style="text-decoration: none;">
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

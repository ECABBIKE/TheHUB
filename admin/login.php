<?php
require_once __DIR__ . '/../config.php';

// If already logged in, redirect to dashboard
if (is_admin()) {
    redirect('/admin/dashboard.php');
}

// Handle login
$error = '';

// Check for session timeout message
if (isset($_GET['timeout'])) {
    $error = 'Din session har gått ut. Vänligen logga in igen.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Ogiltig förfrågan. Försök igen.';
    } else {
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';

        // Check rate limiting before attempting login
        if (isLoginRateLimited($username)) {
            $error = 'För många inloggningsförsök. Vänta 15 minuter och försök igen.';
        } elseif (login_admin($username, $password)) {
            redirect('/admin/dashboard.php');
        } else {
            $error = 'Felaktigt användarnamn eller lösenord';
        }
    }
}

$pageTitle = 'Logga in';
?>
<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> - TheHUB</title>
    <link rel="stylesheet" href="/assets/gravityseries-theme.css">
    <style>
        /* Mobile responsive login */
        .gs-login-container {
            padding: 1rem;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .gs-login-card {
            width: 100%;
            max-width: 400px;
            padding: 2rem;
        }

        @media (max-width: 640px) {
            .gs-login-card {
                padding: 1.5rem;
            }

            .gs-login-header h1 {
                font-size: 1.5rem;
            }

            .gs-login-header p {
                font-size: 0.875rem;
            }

            .gs-btn-lg {
                padding: 0.75rem 1rem;
                font-size: 1rem;
            }
        }
    </style>
</head>
<body class="gs-login-page">

<div class="gs-login-container">
    <div class="gs-login-card">
        <div class="gs-login-header">
            <h1 class="gs-h2">TheHUB Admin</h1>
            <p class="gs-text-secondary">Plattform för cykeltävlingar</p>
        </div>

        <?php if ($error): ?>
            <div class="gs-alert gs-alert-danger gs-mb-md">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" class="gs-login-form">
            <?= csrf_field() ?>
            <div class="gs-form-group">
                <label for="username" class="gs-label">Användarnamn</label>
                <input
                    type="text"
                    id="username"
                    name="username"
                    class="gs-input"
                    required
                    autofocus
                    placeholder="admin"
                >
            </div>

            <div class="gs-form-group">
                <label for="password" class="gs-label">Lösenord</label>
                <input
                    type="password"
                    id="password"
                    name="password"
                    class="gs-input"
                    required
                    placeholder="********"
                >
            </div>

            <button type="submit" class="gs-btn gs-btn-primary gs-btn-block gs-btn-lg">
                Logga in
            </button>
        </form>

        <div class="gs-login-footer">
            <p class="gs-text-sm gs-text-secondary">
                TheHUB v<?= APP_VERSION ?>
            </p>
        </div>
    </div>
</div>

<script src="https://unpkg.com/lucide@latest"></script>
<script>
    lucide.createIcons();
</script>

</body>
</html>

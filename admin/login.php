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

$pageTitle = 'Logga in';
?>
<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> - TheHUB</title>
    <link rel="stylesheet" href="/public/css/gravityseries-main.css">
    <link rel="stylesheet" href="/public/css/gravityseries-admin.css">
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
        </div>
    </div>
</div>

<script src="https://unpkg.com/lucide@latest"></script>
<script>
    lucide.createIcons();
</script>

</body>
</html>

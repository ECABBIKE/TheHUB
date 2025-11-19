<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/rider-auth.php';

// If already logged in, redirect to profile
if (is_rider_logged_in()) {
    header('Location: /rider-profile.php');
    exit;
}

$message = '';
$messageType = 'info';

// Handle login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    checkCsrf();

    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $message = 'Fyll i både e-post och lösenord';
        $messageType = 'error';
    } else {
        $result = rider_login($email, $password);

        if ($result['success']) {
            // Redirect to profile or original page
            $redirect = $_SESSION['redirect_after_login'] ?? '/rider-profile.php';
            unset($_SESSION['redirect_after_login']);
            header('Location: ' . $redirect);
            exit;
        } else {
            $message = $result['message'];
            $messageType = 'error';
        }
    }
}

$pageTitle = 'Logga in';
$pageType = 'public';
include __DIR__ . '/includes/layout-header.php';
?>

<main class="gs-main-content">
    <div class="gs-container gs-form-container">
        <div class="gs-card">
            <div class="gs-card-header gs-text-center">
                <h1 class="gs-h2 gs-text-primary">
                    <i data-lucide="log-in"></i>
                    Deltagare - Logga in
                </h1>
                <p class="gs-text-secondary gs-mt-sm">
                    Logga in för att se din profil och anmäla dig till tävlingar
                </p>
            </div>

            <div class="gs-card-content">
                <?php if ($message): ?>
                    <div class="gs-alert gs-alert-<?= h($messageType) ?> gs-mb-lg">
                        <i data-lucide="<?= $messageType === 'success' ? 'check-circle' : 'alert-circle' ?>"></i>
                        <?= h($message) ?>
                    </div>
                <?php endif; ?>

                <form method="POST">
                    <?= csrf_field() ?>

                    <div class="gs-form-group">
                        <label for="email" class="gs-label">
                            <i data-lucide="mail"></i>
                            E-post
                        </label>
                        <input
                            type="email"
                            id="email"
                            name="email"
                            class="gs-input"
                            required
                            value="<?= h($_POST['email'] ?? '') ?>"
                            placeholder="din@email.com"
                        >
                    </div>

                    <div class="gs-form-group">
                        <label for="password" class="gs-label">
                            <i data-lucide="lock"></i>
                            Lösenord
                        </label>
                        <input
                            type="password"
                            id="password"
                            name="password"
                            class="gs-input"
                            required
                            placeholder="Ditt lösenord"
                        >
                    </div>

                    <button type="submit" name="login" class="gs-btn gs-btn-primary gs-btn-lg gs-w-full gs-mb-md">
                        <i data-lucide="log-in"></i>
                        Logga in
                    </button>
                </form>

                <div class="gs-text-center gs-mt-lg gs-section-divider">
                    <p class="gs-text-sm gs-text-secondary gs-mb-sm">
                        Har du inget konto?
                    </p>
                    <a href="/rider-register.php" class="gs-btn gs-btn-outline gs-btn-sm">
                        <i data-lucide="user-plus"></i>
                        Skapa konto
                    </a>
                    <span class="gs-mx-sm gs-text-secondary">|</span>
                    <a href="/rider-reset-password.php" class="gs-btn gs-btn-outline gs-btn-sm">
                        <i data-lucide="key"></i>
                        Glömt lösenord?
                    </a>
                </div>
            </div>
        </div>
    </div>
</main>

<script src="https://unpkg.com/lucide@latest"></script>
<script>
    lucide.createIcons();
</script>

<?php include __DIR__ . '/includes/layout-footer.php'; ?>

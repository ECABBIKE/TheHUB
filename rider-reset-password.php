<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/rider-auth.php';

$message = '';
$messageType = 'info';
$token = $_GET['token'] ?? '';
$step = empty($token) ? 'request' : 'reset';

// Handle password reset request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_reset'])) {
    checkCsrf();

    $email = trim($_POST['email'] ?? '');

    if (empty($email)) {
        $message = 'Ange din e-postadress';
        $messageType = 'error';
    } else {
        $result = rider_request_password_reset($email);
        $message = $result['message'];
        $messageType = $result['success'] ? 'success' : 'error';

        // Show link for development (remove in production)
        if ($result['success'] && isset($result['link'])) {
            $message .= '<br><br><strong>Utvecklingsläge:</strong> <a href="' . $result['link'] . '" class="gs-link">Klicka här för att återställa lösenord</a>';
        }
    }
}

// Handle password reset with token
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_password'])) {
    checkCsrf();

    $newPassword = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $token = $_POST['token'] ?? '';

    if (empty($newPassword) || empty($confirmPassword)) {
        $message = 'Fyll i båda lösenordsfälten';
        $messageType = 'error';
    } elseif ($newPassword !== $confirmPassword) {
        $message = 'Lösenorden matchar inte';
        $messageType = 'error';
    } elseif (strlen($newPassword) < 8) {
        $message = 'Lösenordet måste vara minst 8 tecken';
        $messageType = 'error';
    } else {
        $result = rider_reset_password($token, $newPassword);
        $message = $result['message'];
        $messageType = $result['success'] ? 'success' : 'error';

        if ($result['success']) {
            $step = 'success';
        }
    }
}

$pageTitle = 'Återställ lösenord';
$pageType = 'public';
include __DIR__ . '/includes/layout-header.php';
?>

<main class="gs-main-content">
    <div class="gs-container gs-form-container">
        <div class="gs-card">
            <div class="gs-card-header gs-text-center">
                <h1 class="gs-h2 gs-text-primary">
                    <i data-lucide="key"></i>
                    Återställ lösenord
                </h1>
            </div>

            <div class="gs-card-content">
                <?php if ($message): ?>
                    <div class="gs-alert gs-alert-<?= h($messageType) ?> gs-mb-lg">
                        <i data-lucide="<?= $messageType === 'success' ? 'check-circle' : 'alert-circle' ?>"></i>
                        <?= h($message) ?>
                    </div>
                <?php endif; ?>

                <?php if ($step === 'request'): ?>
                    <!-- Step 1: Request password reset -->
                    <p class="gs-text-secondary gs-mb-lg">
                        Ange din e-postadress så skickar vi instruktioner för att återställa ditt lösenord.
                    </p>

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
                                placeholder="din@email.com"
                            >
                        </div>

                        <button type="submit" name="request_reset" class="gs-btn gs-btn-primary gs-btn-lg gs-w-full">
                            <i data-lucide="send"></i>
                            Skicka återställningslänk
                        </button>
                    </form>

                <?php elseif ($step === 'reset'): ?>
                    <!-- Step 2: Reset password with token -->
                    <p class="gs-text-secondary gs-mb-lg">
                        Ange ditt nya lösenord nedan.
                    </p>

                    <form method="POST">
                        <?= csrf_field() ?>
                        <input type="hidden" name="token" value="<?= h($token) ?>">

                        <div class="gs-form-group">
                            <label for="password" class="gs-label">
                                <i data-lucide="lock"></i>
                                Nytt lösenord
                            </label>
                            <input
                                type="password"
                                id="password"
                                name="password"
                                class="gs-input"
                                required
                                minlength="8"
                                placeholder="Minst 8 tecken"
                            >
                        </div>

                        <div class="gs-form-group">
                            <label for="confirm_password" class="gs-label">
                                <i data-lucide="lock"></i>
                                Bekräfta lösenord
                            </label>
                            <input
                                type="password"
                                id="confirm_password"
                                name="confirm_password"
                                class="gs-input"
                                required
                                minlength="8"
                                placeholder="Upprepa lösenordet"
                            >
                        </div>

                        <button type="submit" name="reset_password" class="gs-btn gs-btn-primary gs-btn-lg gs-w-full">
                            <i data-lucide="check"></i>
                            Återställ lösenord
                        </button>
                    </form>

                <?php elseif ($step === 'success'): ?>
                    <!-- Step 3: Success -->
                    <div class="gs-text-center">
                        <div class="gs-success-icon-lg">✅</div>
                        <p class="gs-text-lg gs-mb-lg">Lösenordet har återställts!</p>
                        <a href="/rider-login.php" class="gs-btn gs-btn-primary">
                            <i data-lucide="log-in"></i>
                            Logga in
                        </a>
                    </div>
                <?php endif; ?>

                <?php if ($step !== 'success'): ?>
                    <div class="gs-text-center gs-mt-lg gs-section-divider">
                        <a href="/rider-login.php" class="gs-link">
                            <i data-lucide="arrow-left"></i>
                            Tillbaka till inloggning
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>

<script src="https://unpkg.com/lucide@latest"></script>
<script>
    lucide.createIcons();
</script>

<?php include __DIR__ . '/includes/layout-footer.php'; ?>

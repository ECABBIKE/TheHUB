<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/rider-auth.php';
require_once __DIR__ . '/includes/validators.php';

// Require authentication
require_rider();

$rider = get_current_rider();
$message = '';
$messageType = 'info';

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();

    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
        $message = 'Fyll i alla fält';
        $messageType = 'error';
    } elseif ($newPassword !== $confirmPassword) {
        $message = 'De nya lösenorden matchar inte';
        $messageType = 'error';
    } else {
        // Validate new password strength
        $passwordValidation = validatePasswordStrength($newPassword);
        if (!$passwordValidation['valid']) {
            $message = $passwordValidation['error'];
            $messageType = 'error';
        } else {
            $result = rider_change_password($rider['id'], $currentPassword, $newPassword);
            $message = $result['message'];
            $messageType = $result['success'] ? 'success' : 'error';
        }
    }
}

$pageTitle = 'Ändra lösenord';
$pageType = 'public';
include __DIR__ . '/includes/layout-header.php';
?>

<main class="gs-main-content">
    <div class="gs-container gs-form-container-md">
        <div class="gs-card">
            <div class="gs-card-header">
                <div class="gs-flex gs-justify-between gs-items-center">
                    <h1 class="gs-h2 gs-text-primary">
                        <i data-lucide="key"></i>
                        Ändra lösenord
                    </h1>
                    <a href="/rider-profile.php" class="gs-btn gs-btn-outline gs-btn-sm">
                        <i data-lucide="arrow-left"></i>
                        Tillbaka
                    </a>
                </div>
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
                        <label for="current_password" class="gs-label">
                            <i data-lucide="lock"></i>
                            Nuvarande lösenord
                        </label>
                        <input
                            type="password"
                            id="current_password"
                            name="current_password"
                            class="gs-input"
                            required
                            placeholder="Ditt nuvarande lösenord"
                        >
                    </div>

                    <div class="gs-form-group">
                        <label for="new_password" class="gs-label">
                            <i data-lucide="lock"></i>
                            Nytt lösenord
                        </label>
                        <input
                            type="password"
                            id="new_password"
                            name="new_password"
                            class="gs-input"
                            required
                            minlength="8"
                            placeholder="Minst 8 tecken"
                        >
                    </div>

                    <div class="gs-form-group">
                        <label for="confirm_password" class="gs-label">
                            <i data-lucide="lock"></i>
                            Bekräfta nytt lösenord
                        </label>
                        <input
                            type="password"
                            id="confirm_password"
                            name="confirm_password"
                            class="gs-input"
                            required
                            minlength="8"
                            placeholder="Upprepa det nya lösenordet"
                        >
                    </div>

                    <button type="submit" class="gs-btn gs-btn-primary gs-btn-lg gs-w-full">
                        <i data-lucide="check"></i>
                        Ändra lösenord
                    </button>
                </form>
            </div>
        </div>
    </div>
</main>

<script src="https://unpkg.com/lucide@latest"></script>
<script>
    lucide.createIcons();
</script>

<?php include __DIR__ . '/includes/layout-footer.php'; ?>

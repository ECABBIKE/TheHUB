<?php
/**
 * TheHUB V3.5 - Activate Account
 * Request account activation link for new users
 *
 * Handles multiple profiles with same email by letting user choose
 */

// If already logged in, redirect to profile
if (hub_is_logged_in()) {
    header('Location: /profile');
    exit;
}

// Include mail helper
require_once HUB_V3_ROOT . '/includes/mail.php';

$pdo = hub_db();
$message = '';
$messageType = '';
$showActivationLink = false;
$activationLink = '';
$emailSent = false;
$showProfileSelection = false;
$matchingProfiles = [];
$submittedEmail = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $selectedRiderId = (int)($_POST['rider_id'] ?? 0);
    $submittedEmail = $email;

    if (empty($email)) {
        $message = 'Ange din e-postadress';
        $messageType = 'error';
    } else {
        // Find ALL riders with this email (not just first)
        $stmt = $pdo->prepare("
            SELECT r.id, r.firstname, r.lastname, r.email, r.birth_year, r.password,
                   c.name as club_name
            FROM riders r
            LEFT JOIN clubs c ON r.club_id = c.id
            WHERE r.email = ?
            ORDER BY r.lastname, r.firstname
        ");
        $stmt->execute([$email]);
        $riders = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($riders)) {
            // Security: Don't reveal if email exists or not
            $message = 'Om e-postadressen finns i systemet kommer du få ett mail med instruktioner.';
            $messageType = 'info';
        } elseif (count($riders) === 1) {
            // Only one profile - proceed directly
            $rider = $riders[0];

            if (!empty($rider['password'])) {
                $message = 'Detta konto är redan aktiverat. Använd "Glömt lösenord" om du behöver återställa det.';
                $messageType = 'warning';
            } else {
                sendActivationEmail($pdo, $rider, $message, $messageType, $emailSent, $showActivationLink, $activationLink);
            }
        } elseif ($selectedRiderId > 0) {
            // User selected a specific profile
            $rider = null;
            foreach ($riders as $r) {
                if ($r['id'] === $selectedRiderId) {
                    $rider = $r;
                    break;
                }
            }

            if ($rider) {
                if (!empty($rider['password'])) {
                    $message = 'Detta konto är redan aktiverat. Använd "Glömt lösenord" om du behöver återställa det.';
                    $messageType = 'warning';
                } else {
                    sendActivationEmail($pdo, $rider, $message, $messageType, $emailSent, $showActivationLink, $activationLink);
                }
            } else {
                $message = 'Ogiltig profil vald.';
                $messageType = 'error';
            }
        } else {
            // Multiple profiles - show selection
            // Filter out already activated profiles
            $unactivatedProfiles = array_filter($riders, fn($r) => empty($r['password']));
            $activatedProfiles = array_filter($riders, fn($r) => !empty($r['password']));

            if (empty($unactivatedProfiles)) {
                $message = 'Alla profiler kopplade till denna e-post är redan aktiverade. Använd "Glömt lösenord" om du behöver återställa ditt lösenord.';
                $messageType = 'info';
            } else {
                $showProfileSelection = true;
                $matchingProfiles = $unactivatedProfiles;

                if (!empty($activatedProfiles)) {
                    $message = 'OBS: ' . count($activatedProfiles) . ' profil(er) är redan aktiverade och visas inte nedan.';
                    $messageType = 'info';
                }
            }
        }
    }
}

/**
 * Send activation email to a specific rider
 */
function sendActivationEmail($pdo, $rider, &$message, &$messageType, &$emailSent, &$showActivationLink, &$activationLink) {
    // Generate activation token
    $token = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', strtotime('+24 hours'));

    // Save token to this specific rider
    $updateStmt = $pdo->prepare("
        UPDATE riders SET
            password_reset_token = ?,
            password_reset_expires = ?
        WHERE id = ?
    ");
    $updateStmt->execute([$token, $expires, $rider['id']]);

    // Build activation link
    $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
             . '://' . $_SERVER['HTTP_HOST'];
    $activationLink = $baseUrl . '/reset-password?token=' . $token . '&activate=1';

    // Send email
    $riderName = trim($rider['firstname'] . ' ' . $rider['lastname']);
    $emailSent = hub_send_account_activation_email($rider['email'], $riderName, $activationLink);

    if ($emailSent) {
        $message = 'Ett mail med aktiveringslänk har skickats till ' . htmlspecialchars($rider['email']) .
                   ' för profilen "' . htmlspecialchars($riderName) . '"';
        $messageType = 'success';
    } else {
        // Email failed - show link as fallback
        $showActivationLink = true;
        $message = 'Kunde inte skicka mail. Här är aktiveringslänken:';
        $messageType = 'warning';
        error_log("Account activation email failed for {$rider['email']}: {$activationLink}");
    }
}
?>

<div class="auth-container">
    <div class="auth-card activation-card">
        <div class="auth-header">
            <div class="activation-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/>
                    <circle cx="9" cy="7" r="4"/>
                    <polyline points="16 11 18 13 22 9"/>
                </svg>
            </div>
            <h1>Aktivera konto</h1>
            <p>Har du tävlat hos oss tidigare? Ange din e-post för att aktivera ditt konto och skapa ett lösenord.</p>
        </div>

        <?php if ($message): ?>
            <div class="alert alert--<?= $messageType ?>">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <?php if ($emailSent): ?>
            <!-- Email sent successfully -->
            <div class="success-info">
                <div class="success-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                        <polyline points="22 4 12 14.01 9 11.01"/>
                    </svg>
                </div>
                <p>Kontrollera din inkorg (och skräppost) för att hitta aktiveringslänken.</p>
                <p class="note">Länken är giltig i 24 timmar.</p>
            </div>
            <a href="/login" class="btn btn--primary btn--block mt-md">
                Tillbaka till inloggning
            </a>

        <?php elseif ($showActivationLink): ?>
            <!-- Email failed - show link as fallback -->
            <div class="reset-link-box">
                <p><strong>Aktiveringslänk:</strong></p>
                <div class="reset-link-input">
                    <input type="text" value="<?= htmlspecialchars($activationLink) ?>" readonly id="activationLink">
                    <button type="button" onclick="copyLink()" class="btn btn--primary btn--sm">Kopiera</button>
                </div>
                <p class="reset-note">Länken är giltig i 24 timmar.</p>
                <a href="<?= htmlspecialchars($activationLink) ?>" class="btn btn--primary btn--block mt-md">
                    Aktivera konto
                </a>
            </div>

        <?php elseif ($showProfileSelection): ?>
            <!-- Multiple profiles found - let user select -->
            <div class="profile-selection">
                <p class="selection-intro">
                    <strong>Flera profiler hittades</strong> med denna e-postadress.
                    Välj vilken profil du vill aktivera:
                </p>

                <form method="POST" class="auth-form">
                    <input type="hidden" name="email" value="<?= htmlspecialchars($submittedEmail) ?>">

                    <div class="profile-list">
                        <?php foreach ($matchingProfiles as $index => $profile): ?>
                            <label class="profile-option">
                                <input type="radio" name="rider_id" value="<?= $profile['id'] ?>"
                                       <?= $index === 0 ? 'checked' : '' ?>>
                                <div class="profile-info">
                                    <div class="profile-name">
                                        <?= htmlspecialchars($profile['firstname'] . ' ' . $profile['lastname']) ?>
                                    </div>
                                    <div class="profile-details">
                                        <?php if ($profile['birth_year']): ?>
                                            <span>Född <?= $profile['birth_year'] ?></span>
                                        <?php endif; ?>
                                        <?php if ($profile['club_name']): ?>
                                            <span><?= htmlspecialchars($profile['club_name']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <button type="submit" class="btn btn--primary btn--block">
                        Aktivera vald profil
                    </button>
                </form>

                <a href="/activate" class="btn btn--secondary btn--block mt-sm">
                    Tillbaka
                </a>
            </div>

        <?php else: ?>
            <form method="POST" class="auth-form">
                <div class="form-group">
                    <label for="email">E-postadress</label>
                    <input type="email" id="email" name="email" required
                           placeholder="din@email.se"
                           value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                </div>

                <button type="submit" class="btn btn--primary btn--block">
                    Skicka aktiveringslänk
                </button>
            </form>

            <div class="info-box">
                <strong>Nytt konto?</strong>
                <p>Om du aldrig tävlat hos oss tidigare skapas ditt konto automatiskt när du anmäler dig till en tävling.</p>
            </div>
        <?php endif; ?>

        <div class="auth-footer">
            <a href="/login">&larr; Tillbaka till inloggning</a>
        </div>
    </div>
</div>

<style>
/* Profile selection styles - mobile first */
.profile-selection {
    margin-top: var(--space-md);
}

.selection-intro {
    margin-bottom: var(--space-md);
    color: var(--color-text);
    line-height: 1.5;
}

.profile-list {
    display: flex;
    flex-direction: column;
    gap: var(--space-sm);
    margin-bottom: var(--space-lg);
}

.profile-option {
    display: flex;
    align-items: flex-start;
    gap: var(--space-sm);
    padding: var(--space-md);
    background: var(--color-bg-sunken, #f8f9fa);
    border: 2px solid var(--color-border);
    border-radius: var(--radius-md);
    cursor: pointer;
    transition: all 0.15s ease;
}

.profile-option:hover {
    border-color: var(--color-accent);
    background: var(--color-bg, #fff);
}

.profile-option:has(input:checked) {
    border-color: var(--color-accent);
    background: var(--color-bg, #fff);
    box-shadow: 0 0 0 3px rgba(97, 206, 112, 0.15);
}

.profile-option input[type="radio"] {
    flex-shrink: 0;
    width: 20px;
    height: 20px;
    margin-top: 2px;
    accent-color: var(--color-accent);
}

.profile-info {
    flex: 1;
    min-width: 0;
}

.profile-name {
    font-weight: var(--weight-semibold, 600);
    color: var(--color-text-primary, #171717);
    margin-bottom: var(--space-xs);
}

.profile-details {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-xs) var(--space-md);
    font-size: var(--text-sm);
    color: var(--color-text-secondary, #7a7a7a);
}

.profile-details span {
    display: inline-flex;
    align-items: center;
}

.profile-details span::before {
    content: '';
    display: none;
}

/* Separator dot between details on larger screens */
@media (min-width: 480px) {
    .profile-details span:not(:first-child)::before {
        content: '·';
        display: inline;
        margin-right: var(--space-md);
        color: var(--color-border);
    }
}
</style>

<script>
function copyLink() {
    const input = document.getElementById('activationLink');
    input.select();
    document.execCommand('copy');
    alert('Länk kopierad!');
}
</script>

<?php
/**
 * TheHUB V1.0 - Activate Account
 * Request account activation link for new users
 *
 * When multiple profiles share an email, ALL are linked to one login.
 * This allows parents to manage their children's accounts.
 */

// If already logged in, redirect to profile
if (hub_is_logged_in()) {
    header('Location: /profile');
    exit;
}

// Include mail helper
require_once HUB_ROOT . '/includes/mail.php';
require_once HUB_ROOT . '/includes/rate-limiter.php';

$pdo = hub_db();
$message = '';
$messageType = '';
$showActivationLink = false;
$activationLink = '';
$emailSent = false;
$showProfileList = false;
$allProfiles = [];
$submittedEmail = '';
$alreadyActivated = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $confirmActivation = isset($_POST['confirm_activation']);
    $submittedEmail = $email;
    $clientIp = get_client_ip();

    // Rate limiting: max 5 attempts per IP per hour, and 3 per email per hour
    $ipLimited = is_rate_limited('activate_account_ip', $clientIp, 5, 3600);
    $emailLimited = !empty($email) && is_rate_limited('activate_account_email', $email, 3, 3600);

    if ($ipLimited || $emailLimited) {
        $message = 'För många förfrågningar. Vänta en stund innan du försöker igen.';
        $messageType = 'error';
    } elseif (empty($email)) {
        $message = 'Ange din e-postadress';
        $messageType = 'error';
    } else {
        // Find ALL riders with this email
        $stmt = $pdo->prepare("
            SELECT r.id, r.firstname, r.lastname, r.email, r.birth_year, r.password,
                   r.linked_to_rider_id, c.name as club_name
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
        } else {
            // Check if any profile is already activated (has password)
            $activatedProfile = null;
            $unactivatedProfiles = [];

            foreach ($riders as $rider) {
                if (!empty($rider['password'])) {
                    $activatedProfile = $rider;
                } else {
                    $unactivatedProfiles[] = $rider;
                }
            }

            if ($activatedProfile) {
                // Account already exists - link any unlinked profiles and inform user
                $alreadyActivated = true;
                $allProfiles = $riders;

                // Link unactivated profiles to the activated one
                if (!empty($unactivatedProfiles)) {
                    $linkStmt = $pdo->prepare("
                        UPDATE riders
                        SET linked_to_rider_id = ?
                        WHERE email = ? AND id != ? AND linked_to_rider_id IS NULL AND (password IS NULL OR password = '')
                    ");
                    $linkStmt->execute([$activatedProfile['id'], $email, $activatedProfile['id']]);
                }

                $message = 'Det finns redan ett aktiverat konto för denna e-post. Alla ' . count($riders) . ' profiler är tillgängliga via samma inloggning.';
                $messageType = 'info';
                $showProfileList = true;

            } elseif ($confirmActivation) {
                // User confirmed - send activation email
                $primaryRider = $riders[0]; // Use first profile as primary
                sendActivationEmail($pdo, $primaryRider, $riders, $message, $messageType, $emailSent, $showActivationLink, $activationLink);

            } else {
                // Show profiles and ask for confirmation
                $allProfiles = $riders;
                $showProfileList = true;

                if (count($riders) > 1) {
                    $message = 'Följande ' . count($riders) . ' profiler kommer att kopplas till samma konto:';
                } else {
                    $message = 'Följande profil kommer att aktiveras:';
                }
                $messageType = 'info';
            }
        }
    }
}

/**
 * Send activation email and prepare for linking all profiles
 */
function sendActivationEmail($pdo, $primaryRider, $allRiders, &$message, &$messageType, &$emailSent, &$showActivationLink, &$activationLink) {
    // Generate activation token
    $token = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', strtotime('+24 hours'));

    // Store info about profiles to link in a JSON field or use email lookup later
    // For now, we'll link them when password is set based on email match

    // Save token to primary rider
    $updateStmt = $pdo->prepare("
        UPDATE riders SET
            password_reset_token = ?,
            password_reset_expires = ?
        WHERE id = ?
    ");
    $updateStmt->execute([$token, $expires, $primaryRider['id']]);

    // Build activation link
    $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
             . '://' . $_SERVER['HTTP_HOST'];
    $activationLink = $baseUrl . '/reset-password?token=' . $token . '&activate=1';

    // Send email
    $riderName = trim($primaryRider['firstname'] . ' ' . $primaryRider['lastname']);
    $profileCount = count($allRiders);

    $emailSent = hub_send_account_activation_email($primaryRider['email'], $riderName, $activationLink);

    // Record attempt for rate limiting
    record_rate_limit_attempt('activate_account_ip', $clientIp, 3600);
    record_rate_limit_attempt('activate_account_email', $email, 3600);

    if ($emailSent) {
        if ($profileCount > 1) {
            $message = "Ett mail med aktiveringslänk har skickats till " . htmlspecialchars($primaryRider['email']) .
                       ". När du aktiverar kontot kommer alla {$profileCount} profiler att vara tillgängliga.";
        } else {
            $message = 'Ett mail med aktiveringslänk har skickats till ' . htmlspecialchars($primaryRider['email']);
        }
        $messageType = 'success';
    } else {
        // Email failed - show link as fallback
        $showActivationLink = true;
        $message = 'Kunde inte skicka mail. Här är aktiveringslänken:';
        $messageType = 'warning';
        error_log("Account activation email failed for {$primaryRider['email']}: {$activationLink}");
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
            <p>Har du tävlat hos oss tidigare? Ange din e-post så skickar vi en länk där du väljer ett lösenord — sedan är du igång.</p>
        </div>

        <?php if ($message): ?>
            <div class="alert alert--<?= $messageType ?>">
                <?= $message ?>
            </div>
        <?php endif; ?>

        <?php if ($emailSent): ?>
            <!-- Email sent successfully — step indicators -->
            <div class="activation-steps">
                <div class="activation-step done">
                    <div class="step-circle">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                    </div>
                    <span>Ange e-post</span>
                </div>
                <div class="step-line done"></div>
                <div class="activation-step active">
                    <div class="step-circle">2</div>
                    <span>Öppna mailet</span>
                </div>
                <div class="step-line"></div>
                <div class="activation-step">
                    <div class="step-circle">3</div>
                    <span>Välj lösenord</span>
                </div>
            </div>

            <div class="success-info">
                <div class="success-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                        <polyline points="22 4 12 14.01 9 11.01"/>
                    </svg>
                </div>
                <p><strong>Kolla din inkorg nu!</strong></p>
                <p>Vi har skickat ett mail med en länk. Klicka på länken för att välja ditt lösenord — sedan är du klar.</p>
                <p class="note">Hittar du det inte? Kolla skräppost. Länken gäller i 24 timmar.</p>
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

        <?php elseif ($showProfileList && $alreadyActivated): ?>
            <!-- Account already activated - show linked profiles -->
            <div class="profile-list-display">
                <p class="list-intro">Profiler kopplade till detta konto:</p>
                <div class="profile-list">
                    <?php foreach ($allProfiles as $profile): ?>
                        <div class="profile-item <?= !empty($profile['password']) ? 'primary' : '' ?>">
                            <div class="profile-info">
                                <div class="profile-name">
                                    <?= htmlspecialchars($profile['firstname'] . ' ' . $profile['lastname']) ?>
                                    <?php if (!empty($profile['password'])): ?>
                                        <span class="badge badge-primary">Primär</span>
                                    <?php endif; ?>
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
                        </div>
                    <?php endforeach; ?>
                </div>

                <p class="help-text">Använd "Glömt lösenord" om du behöver återställa ditt lösenord.</p>
            </div>

            <a href="/forgot-password" class="btn btn--primary btn--block mt-md">
                Glömt lösenord
            </a>
            <a href="/login" class="btn btn--secondary btn--block mt-sm">
                Tillbaka till inloggning
            </a>

        <?php elseif ($showProfileList): ?>
            <!-- Show profiles that will be linked -->
            <div class="profile-list-display">
                <p class="list-intro">
                    <?php if (count($allProfiles) > 1): ?>
                        Alla dessa profiler kommer att vara tillgängliga med samma inloggning:
                    <?php endif; ?>
                </p>
                <div class="profile-list">
                    <?php foreach ($allProfiles as $index => $profile): ?>
                        <div class="profile-item <?= $index === 0 ? 'primary' : '' ?>">
                            <div class="profile-info">
                                <div class="profile-name">
                                    <?= htmlspecialchars($profile['firstname'] . ' ' . $profile['lastname']) ?>
                                    <?php if ($index === 0 && count($allProfiles) > 1): ?>
                                        <span class="badge badge-primary">Huvudprofil</span>
                                    <?php endif; ?>
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
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if (count($allProfiles) > 1): ?>
                    <p class="help-text">
                        Efter aktivering kan du växla mellan profiler i din inloggade vy.
                    </p>
                <?php endif; ?>
            </div>

            <form method="POST" class="auth-form mt-md">
                <input type="hidden" name="email" value="<?= htmlspecialchars($submittedEmail) ?>">
                <input type="hidden" name="confirm_activation" value="1">
                <button type="submit" class="btn btn--primary btn--block">
                    <?php if (count($allProfiles) > 1): ?>
                        Aktivera konto (<?= count($allProfiles) ?> profiler)
                    <?php else: ?>
                        Aktivera konto
                    <?php endif; ?>
                </button>
            </form>

            <a href="/activate-account" class="btn btn--secondary btn--block mt-sm">
                Avbryt
            </a>

        <?php else: ?>
            <!-- Step indicators for initial state -->
            <div class="activation-steps">
                <div class="activation-step active">
                    <div class="step-circle">1</div>
                    <span>Ange e-post</span>
                </div>
                <div class="step-line"></div>
                <div class="activation-step">
                    <div class="step-circle">2</div>
                    <span>Öppna mailet</span>
                </div>
                <div class="step-line"></div>
                <div class="activation-step">
                    <div class="step-circle">3</div>
                    <span>Välj lösenord</span>
                </div>
            </div>

            <form method="POST" class="auth-form">
                <div class="form-group">
                    <label for="email">E-postadress</label>
                    <input type="email" id="email" name="email" required
                           placeholder="din@email.se"
                           value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                </div>

                <button type="submit" class="btn btn--primary btn--block">
                    Fortsätt
                </button>
            </form>

            <div class="info-box">
                <strong>Så här fungerar det</strong>
                <p>Vi skickar ett mail med en länk. Klicka på länken, välj ett lösenord — klart! Hela processen tar under en minut.</p>
            </div>

            <div class="info-box">
                <strong>Flera profiler?</strong>
                <p>Om du har flera profiler (t.ex. för barn) kopplade till samma e-post aktiveras alla med samma inloggning.</p>
            </div>
        <?php endif; ?>

        <div class="auth-footer">
            <a href="/login">&larr; Tillbaka till inloggning</a>
        </div>
    </div>
</div>

<style>
/* Activation step indicators */
.activation-steps {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0;
    margin: var(--space-md) 0 var(--space-lg) 0;
    padding: 0 var(--space-sm);
}

.activation-step {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: var(--space-xs);
    flex-shrink: 0;
}

.activation-step span {
    font-size: 0.75rem;
    color: var(--color-text-muted);
    white-space: nowrap;
}

.activation-step.active span,
.activation-step.done span {
    color: var(--color-accent);
    font-weight: 600;
}

.step-circle {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.8rem;
    font-weight: 700;
    background: var(--color-bg-hover);
    color: var(--color-text-muted);
    border: 2px solid var(--color-border);
}

.activation-step.active .step-circle {
    background: var(--color-accent);
    color: var(--color-bg-page);
    border-color: var(--color-accent);
}

.activation-step.done .step-circle {
    background: var(--color-success);
    color: white;
    border-color: var(--color-success);
}

.step-line {
    flex: 1;
    min-width: 24px;
    max-width: 60px;
    height: 2px;
    background: var(--color-border);
    margin: 0 var(--space-xs);
    margin-bottom: 20px; /* align with circles, not labels */
}

.step-line.done {
    background: var(--color-success);
}

/* Profile list styles - mobile first */
.profile-list-display {
    margin: var(--space-md) 0;
}

.list-intro {
    margin-bottom: var(--space-sm);
    color: var(--color-text);
    font-weight: var(--weight-medium, 500);
}

.profile-list {
    display: flex;
    flex-direction: column;
    gap: var(--space-sm);
    margin-bottom: var(--space-md);
}

.profile-item {
    display: flex;
    align-items: center;
    padding: var(--space-md);
    background: var(--color-bg-sunken, #f8f9fa);
    border: 2px solid var(--color-border);
    border-radius: var(--radius-md);
}

.profile-item.primary {
    border-color: var(--color-accent);
    background: rgba(97, 206, 112, 0.05);
}

.profile-info {
    flex: 1;
    min-width: 0;
}

.profile-name {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    flex-wrap: wrap;
    font-weight: var(--weight-semibold, 600);
    color: var(--color-text-primary, #171717);
    margin-bottom: var(--space-xs);
}

.badge {
    display: inline-block;
    padding: 2px 8px;
    font-size: 11px;
    font-weight: var(--weight-medium, 500);
    border-radius: var(--radius-full);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.badge-primary {
    background: var(--color-accent);
    color: white;
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

.help-text {
    font-size: var(--text-sm);
    color: var(--color-text-secondary);
    margin-top: var(--space-sm);
}

.info-box {
    margin-top: var(--space-md);
    padding: var(--space-md);
    background: var(--color-bg-sunken, #f8f9fa);
    border-radius: var(--radius-md);
    font-size: var(--text-sm);
}

.info-box strong {
    display: block;
    margin-bottom: var(--space-xs);
    color: var(--color-text-primary);
}

.info-box p {
    color: var(--color-text-secondary);
    margin: 0;
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

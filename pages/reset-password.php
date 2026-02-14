<?php
/**
 * TheHUB V1.0 - Reset Password
 * Set new password with reset token
 *
 * When setting password, links all other profiles with same email
 * to this account (for family account management)
 */

// If already logged in, redirect to profile
if (hub_is_logged_in()) {
    header('Location: /profile');
    exit;
}

$pdo = hub_db();
$token = $_GET['token'] ?? '';
$isActivation = isset($_GET['activate']);
$message = '';
$messageType = '';
$validToken = false;
$rider = null;
$adminUser = null;
$accountType = null; // 'rider' or 'admin'
$linkedProfilesCount = 0;

// Nationality options
$nationalities = [
    'SWE' => 'Sverige',
    'NOR' => 'Norge',
    'DNK' => 'Danmark',
    'FIN' => 'Finland',
    'DEU' => 'Tyskland',
    'FRA' => 'Frankrike',
    'CHE' => 'Schweiz',
    'AUT' => 'Österrike',
    'ITA' => 'Italien',
    'ESP' => 'Spanien',
    'GBR' => 'Storbritannien',
    'USA' => 'USA',
    '' => 'Annan',
];

$needsClub = false;
$scfClubs = [];

// Validate token - check both riders and admin_users
if (!empty($token)) {
    // First check riders table
    $stmt = $pdo->prepare("
        SELECT id, firstname, lastname, email, nationality, birth_year, club_id, license_number
        FROM riders
        WHERE password_reset_token = ?
        AND password_reset_expires > NOW()
        LIMIT 1
    ");
    $stmt->execute([$token]);
    $rider = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($rider) {
        $validToken = true;
        $accountType = 'rider';

        // Count other profiles with same email that will be linked
        $countStmt = $pdo->prepare("
            SELECT COUNT(*) as count FROM riders
            WHERE email = ? AND id != ?
        ");
        $countStmt->execute([$rider['email'], $rider['id']]);
        $linkedProfilesCount = (int)$countStmt->fetch(PDO::FETCH_ASSOC)['count'];

        // During activation: check if rider needs to select a club
        if ($isActivation && empty($rider['club_id']) && empty($rider['license_number'])) {
            $needsClub = true;
            $clubStmt = $pdo->query("
                SELECT id, name, city
                FROM clubs
                WHERE active = 1 AND rf_registered = 1
                ORDER BY name
            ");
            $scfClubs = $clubStmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } else {
        // Check admin_users table
        $adminStmt = $pdo->prepare("
            SELECT id, full_name, email
            FROM admin_users
            WHERE password_reset_token = ?
            AND password_reset_expires > NOW()
            LIMIT 1
        ");
        $adminStmt->execute([$token]);
        $adminUser = $adminStmt->fetch(PDO::FETCH_ASSOC);

        if ($adminUser) {
            $validToken = true;
            $accountType = 'admin';
        } else {
            $message = 'Ogiltig eller utgången återställningslänk. Begär en ny länk.';
            $messageType = 'error';
        }
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $validToken) {
    $password = $_POST['password'] ?? '';
    $passwordConfirm = $_POST['password_confirm'] ?? '';

    // Activation-specific fields
    $postNationality = $_POST['nationality'] ?? '';
    $postBirthYear = $_POST['birth_year'] ?? '';
    $postClubId = $_POST['club_id'] ?? '';

    if (strlen($password) < 8) {
        $message = 'Lösenordet måste vara minst 8 tecken';
        $messageType = 'error';
    } elseif ($password !== $passwordConfirm) {
        $message = 'Lösenorden matchar inte';
        $messageType = 'error';
    } elseif ($isActivation && $accountType === 'rider' && empty($postNationality)) {
        $message = 'Välj ditt land';
        $messageType = 'error';
    } elseif ($isActivation && $accountType === 'rider' && !isset($nationalities[$postNationality])) {
        $message = 'Ogiltigt land valt';
        $messageType = 'error';
    } elseif ($isActivation && $accountType === 'rider' && empty($postBirthYear)) {
        $message = 'Ange ditt födelseår';
        $messageType = 'error';
    } elseif ($isActivation && $accountType === 'rider' && (!is_numeric($postBirthYear) || (int)$postBirthYear < 1930 || (int)$postBirthYear > date('Y'))) {
        $message = 'Ogiltigt födelseår';
        $messageType = 'error';
    } elseif ($isActivation && $accountType === 'rider' && $needsClub && empty($postClubId)) {
        $message = 'Välj en klubb';
        $messageType = 'error';
    } else {
        // Hash new password
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

        if ($accountType === 'admin') {
            // Update admin_users
            $updateStmt = $pdo->prepare("
                UPDATE admin_users SET
                    password_hash = ?,
                    password_reset_token = NULL,
                    password_reset_expires = NULL
                WHERE id = ?
            ");
            $updateStmt->execute([$hashedPassword, $adminUser['id']]);

            $message = 'Lösenord återställt! Du kan nu logga in.';
            $messageType = 'success';
            $validToken = false;
        } else {
            // Build rider update query - always set password
            $updateFields = [
                'password = ?',
                'password_reset_token = NULL',
                'password_reset_expires = NULL',
                'linked_to_rider_id = NULL',
            ];
            $updateParams = [$hashedPassword];

            // During activation, also save profile fields
            if ($isActivation) {
                $updateFields[] = 'nationality = ?';
                $updateParams[] = $postNationality;

                $updateFields[] = 'birth_year = ?';
                $updateParams[] = (int)$postBirthYear;

                if ($needsClub && !empty($postClubId)) {
                    $updateFields[] = 'club_id = ?';
                    $updateParams[] = (int)$postClubId;
                }
            }

            $updateParams[] = $rider['id'];
            $updateStmt = $pdo->prepare("
                UPDATE riders SET " . implode(', ', $updateFields) . "
                WHERE id = ?
            ");
            $updateStmt->execute($updateParams);

            // Link all other riders with same email to this primary account
            $linkStmt = $pdo->prepare("
                UPDATE riders SET
                    linked_to_rider_id = ?,
                    password = NULL,
                    password_reset_token = NULL,
                    password_reset_expires = NULL
                WHERE email = ? AND id != ?
            ");
            $linkStmt->execute([$rider['id'], $rider['email'], $rider['id']]);

            // Get count of actually linked profiles
            $linkedCount = $linkStmt->rowCount();

            if ($isActivation) {
                if ($linkedCount > 0) {
                    $message = "Konto aktiverat! Du har nu tillgång till " . ($linkedCount + 1) . " profiler med samma inloggning.";
                } else {
                    $message = 'Konto aktiverat! Du kan nu logga in.';
                }
            } else {
                if ($linkedCount > 0) {
                    $message = "Lösenord återställt! Alla " . ($linkedCount + 1) . " profiler är tillgängliga med samma inloggning.";
                } else {
                    $message = 'Lösenord återställt! Du kan nu logga in.';
                }
            }
            $messageType = 'success';
            $validToken = false; // Hide form
        }
    }
}

$pageTitle = $isActivation ? 'Aktivera konto' : 'Återställ lösenord';
?>

<div class="auth-container">
    <div class="auth-card">
        <div class="auth-header">
            <h1><?= $pageTitle ?></h1>
            <?php if ($rider): ?>
                <p>
                    <?= $isActivation ? 'Skapa' : 'Ange nytt' ?> lösenord för
                    <strong><?= htmlspecialchars($rider['firstname'] . ' ' . $rider['lastname']) ?></strong>
                </p>
            <?php elseif ($adminUser): ?>
                <p>
                    Ange nytt lösenord för
                    <strong><?= htmlspecialchars($adminUser['full_name'] ?: $adminUser['email']) ?></strong>
                </p>
            <?php else: ?>
                <p>Ange din återställningskod eller begär en ny</p>
            <?php endif; ?>
        </div>

        <?php if ($message): ?>
            <div class="alert alert--<?= $messageType ?>">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <?php if ($messageType === 'success'): ?>
            <a href="/login" class="btn btn--primary btn--block">Gå till inloggning</a>

        <?php elseif ($validToken): ?>
            <?php if ($linkedProfilesCount > 0): ?>
                <div class="info-box mb-md">
                    <strong><?= $linkedProfilesCount + 1 ?> profiler kommer att kopplas</strong>
                    <p>Alla profiler med e-postadressen <?= htmlspecialchars($rider['email']) ?> kommer att vara tillgängliga med detta lösenord.</p>
                </div>
            <?php endif; ?>

            <form method="POST" class="auth-form">
                <?php if ($isActivation && $accountType === 'rider'): ?>
                    <div class="profile-section-label">Komplettera din profil</div>

                    <?php
                        $selNationality = $_POST['nationality'] ?? $rider['nationality'] ?? 'SWE';
                        $selBirthYear = $_POST['birth_year'] ?? $rider['birth_year'] ?? '';
                        $selClubId = $_POST['club_id'] ?? '';
                    ?>
                    <div class="form-group">
                        <label for="nationality">Land</label>
                        <select id="nationality" name="nationality" required class="form-select">
                            <?php foreach ($nationalities as $code => $name): ?>
                                <option value="<?= $code ?>"
                                    <?= $selNationality === $code ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($name) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="birth_year">Födelseår</label>
                        <input type="number" id="birth_year" name="birth_year" required
                               placeholder="t.ex. 1995"
                               min="1930" max="<?= date('Y') ?>"
                               value="<?= htmlspecialchars($selBirthYear) ?>">
                    </div>

                    <?php if ($needsClub): ?>
                        <div class="form-group">
                            <label for="club_id">Klubb</label>
                            <select id="club_id" name="club_id" required class="form-select">
                                <option value="">-- Välj klubb --</option>
                                <?php foreach ($scfClubs as $club): ?>
                                    <option value="<?= $club['id'] ?>"
                                        <?= $selClubId == $club['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($club['name']) ?>
                                        <?php if (!empty($club['city'])): ?>
                                            (<?= htmlspecialchars($club['city']) ?>)
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>

                    <div class="profile-section-label">Välj lösenord</div>
                <?php endif; ?>

                <div class="form-group">
                    <label for="password">
                        <?= $isActivation ? 'Lösenord' : 'Nytt lösenord' ?>
                    </label>
                    <input type="password" id="password" name="password" required
                           placeholder="Minst 8 tecken"
                           minlength="8">
                </div>

                <div class="form-group">
                    <label for="password_confirm">Bekräfta lösenord</label>
                    <input type="password" id="password_confirm" name="password_confirm" required
                           placeholder="Samma lösenord igen"
                           minlength="8">
                </div>

                <button type="submit" class="btn btn--primary btn--block">
                    <?= $isActivation ? 'Aktivera konto' : 'Spara nytt lösenord' ?>
                </button>
            </form>

        <?php else: ?>
            <p class="text-center">Ingen giltig återställningskod angiven.</p>
            <a href="/forgot-password" class="btn btn--primary btn--block mt-md">
                Begär ny återställningslänk
            </a>
        <?php endif; ?>

        <div class="auth-footer">
            <a href="/login">&larr; Tillbaka till inloggning</a>
        </div>
    </div>
</div>

<style>
.info-box {
    padding: var(--space-md);
    background: var(--color-bg-sunken, #f8f9fa);
    border-radius: var(--radius-md);
    border-left: 4px solid var(--color-accent);
}

.info-box strong {
    display: block;
    margin-bottom: var(--space-xs);
    color: var(--color-text-primary);
}

.info-box p {
    margin: 0;
    font-size: var(--text-sm);
    color: var(--color-text-secondary);
}

.mb-md {
    margin-bottom: var(--space-md);
}

.profile-section-label {
    font-size: var(--text-sm);
    font-weight: var(--weight-semibold, 600);
    color: var(--color-text-secondary);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding-bottom: var(--space-xs);
    border-bottom: 1px solid var(--color-border);
}

.form-select {
    width: 100%;
    padding: var(--space-sm) var(--space-md);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    font-size: var(--text-base);
    background: var(--color-bg-surface);
    color: var(--color-text-primary);
    transition: all var(--transition-fast);
    appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23868fa2' stroke-width='2'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right var(--space-sm) center;
    padding-right: var(--space-xl);
}

.form-select:focus {
    outline: none;
    border-color: var(--color-accent);
    box-shadow: 0 0 0 3px var(--color-accent-light);
}
</style>

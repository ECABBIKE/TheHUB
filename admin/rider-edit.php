<?php
require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();

// Get rider ID
$id = isset($_GET['id']) && is_numeric($_GET['id']) ? intval($_GET['id']) : null;

if (!$id) {
    header('Location: /admin/riders.php');
    exit;
}

// Fetch rider
$rider = $db->getRow("SELECT * FROM riders WHERE id = ?", [$id]);

if (!$rider) {
    header('Location: /admin/riders.php');
    exit;
}

// Check if rider has a linked user account
$riderUser = $db->getRow("
    SELECT au.*, rp.can_edit_profile, rp.can_manage_club
    FROM rider_profiles rp
    JOIN admin_users au ON rp.user_id = au.id
    WHERE rp.rider_id = ?
", [$id]);

$message = '';
$messageType = 'info';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();

    $action = $_POST['action'] ?? 'save_rider';

    if ($action === 'save_rider') {
        // Validate required fields
        $firstname = trim($_POST['firstname'] ?? '');
        $lastname = trim($_POST['lastname'] ?? '');

        if (empty($firstname) || empty($lastname)) {
            $message = 'Förnamn och efternamn är obligatoriska';
            $messageType = 'error';
        } else {
            // Prepare rider data
            $riderData = [
                'firstname' => $firstname,
                'lastname' => $lastname,
                'birth_year' => !empty($_POST['birth_year']) ? intval($_POST['birth_year']) : null,
                'gender' => trim($_POST['gender'] ?? ''),
                'club_id' => !empty($_POST['club_id']) ? intval($_POST['club_id']) : null,
                'license_number' => trim($_POST['license_number'] ?? ''),
                'license_type' => trim($_POST['license_type'] ?? ''),
                'license_category' => trim($_POST['license_category'] ?? ''),
                'license_valid_until' => !empty($_POST['license_valid_until']) ? trim($_POST['license_valid_until']) : null,
                'discipline' => trim($_POST['discipline'] ?? ''),
                'email' => trim($_POST['email'] ?? ''),
                'phone' => trim($_POST['phone'] ?? ''),
                'city' => trim($_POST['city'] ?? ''),
                'notes' => trim($_POST['notes'] ?? ''),
                'active' => isset($_POST['active']) ? 1 : 0,
            ];

            try {
                $db->update('riders', $riderData, 'id = ?', [$id]);
                $message = 'Deltagare uppdaterad!';
                $messageType = 'success';

                // Refresh rider data
                $rider = $db->getRow("SELECT * FROM riders WHERE id = ?", [$id]);
            } catch (Exception $e) {
                $message = 'Ett fel uppstod: ' . $e->getMessage();
                $messageType = 'error';
            }
        }
    } elseif ($action === 'create_account' && hasRole('super_admin')) {
        // Create user account for this rider
        $username = trim($_POST['new_username'] ?? '');
        $email = trim($_POST['new_email'] ?? '') ?: $rider['email'];
        $password = $_POST['new_password'] ?? '';

        $errors = [];
        if (empty($username)) $errors[] = 'Användarnamn krävs';
        if (empty($password)) $errors[] = 'Lösenord krävs';
        if (strlen($password) < 8) $errors[] = 'Lösenord måste vara minst 8 tecken';

        // Check if username exists
        if ($username) {
            $existing = $db->getRow("SELECT id FROM admin_users WHERE username = ?", [$username]);
            if ($existing) $errors[] = 'Användarnamnet är redan taget';
        }

        if (empty($errors)) {
            try {
                // Create user
                $db->insert('admin_users', [
                    'username' => $username,
                    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    'email' => $email,
                    'full_name' => $rider['firstname'] . ' ' . $rider['lastname'],
                    'role' => 'rider',
                    'active' => 1
                ]);
                $newUserId = $db->lastInsertId();

                // Link to rider
                $currentAdmin = getCurrentAdmin();
                $db->insert('rider_profiles', [
                    'user_id' => $newUserId,
                    'rider_id' => $id,
                    'can_edit_profile' => 1,
                    'can_manage_club' => 0,
                    'approved_by' => $currentAdmin['id'],
                    'approved_at' => date('Y-m-d H:i:s')
                ]);

                $message = 'Användarkonto skapat!';
                $messageType = 'success';

                // Refresh rider user
                $riderUser = $db->getRow("
                    SELECT au.*, rp.can_edit_profile, rp.can_manage_club
                    FROM rider_profiles rp
                    JOIN admin_users au ON rp.user_id = au.id
                    WHERE rp.rider_id = ?
                ", [$id]);
            } catch (Exception $e) {
                $message = 'Kunde inte skapa konto: ' . $e->getMessage();
                $messageType = 'error';
            }
        } else {
            $message = implode('<br>', $errors);
            $messageType = 'error';
        }
    } elseif ($action === 'update_account' && hasRole('super_admin') && $riderUser) {
        // Update user account
        $email = trim($_POST['account_email'] ?? '');
        $password = $_POST['account_password'] ?? '';
        $canEditProfile = isset($_POST['can_edit_profile']) ? 1 : 0;
        $canManageClub = isset($_POST['can_manage_club']) ? 1 : 0;
        $accountActive = isset($_POST['account_active']) ? 1 : 0;

        try {
            $userData = [
                'email' => $email,
                'active' => $accountActive
            ];
            if ($password) {
                if (strlen($password) < 8) {
                    throw new Exception('Lösenord måste vara minst 8 tecken');
                }
                $userData['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
            }

            $db->update('admin_users', $userData, 'id = ?', [$riderUser['id']]);
            $db->update('rider_profiles', [
                'can_edit_profile' => $canEditProfile,
                'can_manage_club' => $canManageClub
            ], 'user_id = ? AND rider_id = ?', [$riderUser['id'], $id]);

            $message = 'Användarkonto uppdaterat!';
            $messageType = 'success';

            // Refresh rider user
            $riderUser = $db->getRow("
                SELECT au.*, rp.can_edit_profile, rp.can_manage_club
                FROM rider_profiles rp
                JOIN admin_users au ON rp.user_id = au.id
                WHERE rp.rider_id = ?
            ", [$id]);
        } catch (Exception $e) {
            $message = 'Kunde inte uppdatera konto: ' . $e->getMessage();
            $messageType = 'error';
        }
    } elseif ($action === 'delete_account' && hasRole('super_admin') && $riderUser) {
        try {
            $db->delete('rider_profiles', 'user_id = ? AND rider_id = ?', [$riderUser['id'], $id]);
            $db->delete('admin_users', 'id = ?', [$riderUser['id']]);
            $message = 'Användarkonto borttaget!';
            $messageType = 'success';
            $riderUser = null;
        } catch (Exception $e) {
            $message = 'Kunde inte ta bort konto: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}

// Get clubs for dropdown
$clubs = $db->getAll("SELECT id, name FROM clubs WHERE active = 1 ORDER BY name");

$pageTitle = 'Redigera Deltagare';
$pageType = 'admin';
include __DIR__ . '/../includes/layout-header.php';
?>

<main class="gs-main-content">
    <div class="gs-container" class="gs-max-w-900">
        <!-- Header -->
        <div class="gs-flex gs-items-center gs-justify-between gs-mb-lg">
            <h1 class="gs-h1 gs-text-primary">
                <i data-lucide="user-circle"></i>
                Redigera Deltagare
            </h1>
            <a href="/admin/riders.php" class="gs-btn gs-btn-outline">
                <i data-lucide="arrow-left"></i>
                Tillbaka
            </a>
        </div>

        <!-- Message -->
        <?php if ($message): ?>
            <div class="gs-alert gs-alert-<?= h($messageType) ?> gs-mb-lg">
                <i data-lucide="<?= $messageType === 'success' ? 'check-circle' : 'alert-circle' ?>"></i>
                <?= h($message) ?>
            </div>
        <?php endif; ?>

        <!-- Edit Form -->
        <form method="POST" class="gs-card">
            <?= csrf_field() ?>

            <div class="gs-card-content">
                <div class="gs-grid gs-grid-cols-1 gs-md-grid-cols-2 gs-gap-lg">
                    <!-- Personal Information -->
                    <div class="gs-md-col-span-2">
                        <h2 class="gs-h4 gs-text-primary gs-mb-md">
                            <i data-lucide="user"></i>
                            Personuppgifter
                        </h2>
                    </div>

                    <!-- First Name (Required) -->
                    <div>
                        <label for="firstname" class="gs-label">
                            <i data-lucide="user"></i>
                            Förnamn <span class="gs-text-error">*</span>
                        </label>
                        <input
                            type="text"
                            id="firstname"
                            name="firstname"
                            class="gs-input"
                            required
                            value="<?= h($rider['firstname']) ?>"
                        >
                    </div>

                    <!-- Last Name (Required) -->
                    <div>
                        <label for="lastname" class="gs-label">
                            <i data-lucide="user"></i>
                            Efternamn <span class="gs-text-error">*</span>
                        </label>
                        <input
                            type="text"
                            id="lastname"
                            name="lastname"
                            class="gs-input"
                            required
                            value="<?= h($rider['lastname']) ?>"
                        >
                    </div>

                    <!-- Birth Year -->
                    <div>
                        <label for="birth_year" class="gs-label">
                            <i data-lucide="calendar"></i>
                            Födelseår
                        </label>
                        <input
                            type="number"
                            id="birth_year"
                            name="birth_year"
                            class="gs-input"
                            min="1900"
                            max="<?= date('Y') ?>"
                            value="<?= h($rider['birth_year']) ?>"
                        >
                    </div>

                    <!-- Gender -->
                    <div>
                        <label for="gender" class="gs-label">
                            <i data-lucide="users"></i>
                            Kön
                        </label>
                        <select id="gender" name="gender" class="gs-input">
                            <option value="">Välj...</option>
                            <option value="M" <?= $rider['gender'] === 'M' ? 'selected' : '' ?>>Man</option>
                            <option value="F" <?= $rider['gender'] === 'F' ? 'selected' : '' ?>>Kvinna</option>
                        </select>
                    </div>

                    <!-- License Information -->
                    <div class="gs-md-col-span-2 gs-mt-lg">
                        <h2 class="gs-h4 gs-text-primary gs-mb-md">
                            <i data-lucide="award"></i>
                            Licensinformation
                        </h2>
                    </div>

                    <!-- Club -->
                    <div>
                        <label for="club_id" class="gs-label">
                            <i data-lucide="building"></i>
                            Klubb
                        </label>
                        <select id="club_id" name="club_id" class="gs-input">
                            <option value="">Ingen klubb</option>
                            <?php foreach ($clubs as $club): ?>
                                <option value="<?= $club['id'] ?>" <?= $rider['club_id'] == $club['id'] ? 'selected' : '' ?>>
                                    <?= h($club['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- License Number -->
                    <div>
                        <label for="license_number" class="gs-label">
                            <i data-lucide="credit-card"></i>
                            Licensnummer
                        </label>
                        <input
                            type="text"
                            id="license_number"
                            name="license_number"
                            class="gs-input"
                            value="<?= h($rider['license_number']) ?>"
                            placeholder="UCI ID eller SWE-ID"
                        >
                    </div>

                    <!-- License Type -->
                    <div>
                        <label for="license_type" class="gs-label">
                            <i data-lucide="shield"></i>
                            Licenstyp
                        </label>
                        <select id="license_type" name="license_type" class="gs-input">
                            <option value="">Ingen</option>
                            <option value="Elite" <?= $rider['license_type'] === 'Elite' ? 'selected' : '' ?>>Elite</option>
                            <option value="Sport" <?= $rider['license_type'] === 'Sport' ? 'selected' : '' ?>>Sport</option>
                            <option value="Motion" <?= $rider['license_type'] === 'Motion' ? 'selected' : '' ?>>Motion</option>
                            <option value="Youth" <?= $rider['license_type'] === 'Youth' ? 'selected' : '' ?>>Ungdom</option>
                        </select>
                    </div>

                    <!-- License Category -->
                    <div>
                        <label for="license_category" class="gs-label">
                            <i data-lucide="tag"></i>
                            Licenskategori
                        </label>
                        <input
                            type="text"
                            id="license_category"
                            name="license_category"
                            class="gs-input"
                            value="<?= h($rider['license_category']) ?>"
                        >
                    </div>

                    <!-- License Valid Until -->
                    <div>
                        <label for="license_valid_until" class="gs-label">
                            <i data-lucide="calendar-check"></i>
                            Licens giltig till
                        </label>
                        <input
                            type="date"
                            id="license_valid_until"
                            name="license_valid_until"
                            class="gs-input"
                            value="<?= $rider['license_valid_until'] && $rider['license_valid_until'] !== '0000-00-00' ? h($rider['license_valid_until']) : '' ?>"
                        >
                    </div>

                    <!-- Discipline -->
                    <div>
                        <label for="discipline" class="gs-label">
                            <i data-lucide="bike"></i>
                            Disciplin
                        </label>
                        <select id="discipline" name="discipline" class="gs-input">
                            <option value="">Välj...</option>
                            <option value="MTB" <?= $rider['discipline'] === 'MTB' ? 'selected' : '' ?>>MTB</option>
                            <option value="Road" <?= $rider['discipline'] === 'Road' ? 'selected' : '' ?>>Road</option>
                            <option value="Cyclocross" <?= $rider['discipline'] === 'Cyclocross' ? 'selected' : '' ?>>Cyclocross</option>
                            <option value="Track" <?= $rider['discipline'] === 'Track' ? 'selected' : '' ?>>Track</option>
                            <option value="BMX" <?= $rider['discipline'] === 'BMX' ? 'selected' : '' ?>>BMX</option>
                        </select>
                    </div>

                    <!-- Contact Information -->
                    <div class="gs-md-col-span-2 gs-mt-lg">
                        <h2 class="gs-h4 gs-text-primary gs-mb-md">
                            <i data-lucide="mail"></i>
                            Kontaktuppgifter
                        </h2>
                    </div>

                    <!-- Email -->
                    <div>
                        <label for="email" class="gs-label">
                            <i data-lucide="mail"></i>
                            E-post
                        </label>
                        <input
                            type="email"
                            id="email"
                            name="email"
                            class="gs-input"
                            value="<?= h($rider['email']) ?>"
                        >
                    </div>

                    <!-- Phone -->
                    <div>
                        <label for="phone" class="gs-label">
                            <i data-lucide="phone"></i>
                            Telefon
                        </label>
                        <input
                            type="tel"
                            id="phone"
                            name="phone"
                            class="gs-input"
                            value="<?= h($rider['phone']) ?>"
                        >
                    </div>

                    <!-- City -->
                    <div>
                        <label for="city" class="gs-label">
                            <i data-lucide="map-pin"></i>
                            Stad
                        </label>
                        <input
                            type="text"
                            id="city"
                            name="city"
                            class="gs-input"
                            value="<?= h($rider['city']) ?>"
                        >
                    </div>

                    <!-- Active Status -->
                    <div>
                        <label class="gs-checkbox-label">
                            <input
                                type="checkbox"
                                id="active"
                                name="active"
                                class="gs-checkbox"
                                <?= $rider['active'] ? 'checked' : '' ?>
                            >
                            <span>
                                <i data-lucide="check-circle"></i>
                                Aktiv deltagare
                            </span>
                        </label>
                    </div>

                    <!-- Notes -->
                    <div class="gs-md-col-span-2">
                        <label for="notes" class="gs-label">
                            <i data-lucide="file-text"></i>
                            Anteckningar
                        </label>
                        <textarea
                            id="notes"
                            name="notes"
                            class="gs-input"
                            rows="3"
                        ><?= h($rider['notes']) ?></textarea>
                    </div>
                </div>
            </div>

            <div class="gs-card-footer">
                <div class="gs-flex gs-justify-end gs-gap-md">
                    <a href="/admin/riders.php" class="gs-btn gs-btn-outline">
                        <i data-lucide="x"></i>
                        Avbryt
                    </a>
                    <button type="submit" class="gs-btn gs-btn-primary">
                        <i data-lucide="save"></i>
                        Spara ändringar
                    </button>
                </div>
            </div>
        </form>

        <?php if (hasRole('super_admin')): ?>
        <!-- User Account Section -->
        <div class="gs-card gs-mt-lg">
            <div class="gs-card-header">
                <h2 class="gs-h4">
                    <i data-lucide="key"></i>
                    Användarkonto
                </h2>
            </div>
            <div class="gs-card-content">
                <?php if ($riderUser): ?>
                    <!-- Existing Account -->
                    <form method="POST">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update_account">

                        <div class="gs-grid gs-grid-cols-1 gs-md-grid-cols-2 gs-gap-lg">
                            <div class="gs-md-col-span-2">
                                <div class="gs-alert gs-alert-info">
                                    <i data-lucide="user-check"></i>
                                    <span>Denna deltagare har ett användarkonto: <strong><?= h($riderUser['username']) ?></strong></span>
                                </div>
                            </div>

                            <div>
                                <label class="gs-label">
                                    <i data-lucide="at-sign"></i>
                                    Användarnamn
                                </label>
                                <input type="text" class="gs-input" value="<?= h($riderUser['username']) ?>" disabled>
                                <small class="gs-text-secondary">Kan inte ändras</small>
                            </div>

                            <div>
                                <label for="account_email" class="gs-label">
                                    <i data-lucide="mail"></i>
                                    E-post för inloggning
                                </label>
                                <input
                                    type="email"
                                    id="account_email"
                                    name="account_email"
                                    class="gs-input"
                                    value="<?= h($riderUser['email']) ?>"
                                >
                            </div>

                            <div>
                                <label for="account_password" class="gs-label">
                                    <i data-lucide="key"></i>
                                    Nytt lösenord
                                </label>
                                <input
                                    type="password"
                                    id="account_password"
                                    name="account_password"
                                    class="gs-input"
                                    placeholder="Lämna tomt för att behålla"
                                    minlength="8"
                                >
                                <small class="gs-text-secondary">Minst 8 tecken</small>
                            </div>

                            <div>
                                <label class="gs-label">Senaste inloggning</label>
                                <input type="text" class="gs-input" value="<?= $riderUser['last_login'] ? date('Y-m-d H:i', strtotime($riderUser['last_login'])) : 'Aldrig' ?>" disabled>
                            </div>

                            <div class="gs-md-col-span-2">
                                <label class="gs-label">Behörigheter</label>
                                <div class="gs-flex gs-gap-lg gs-flex-wrap">
                                    <label class="gs-checkbox gs-flex gs-items-center gs-gap-sm">
                                        <input type="checkbox" name="can_edit_profile" value="1" <?= $riderUser['can_edit_profile'] ? 'checked' : '' ?>>
                                        <span>Kan redigera sin profil</span>
                                    </label>
                                    <label class="gs-checkbox gs-flex gs-items-center gs-gap-sm">
                                        <input type="checkbox" name="can_manage_club" value="1" <?= $riderUser['can_manage_club'] ? 'checked' : '' ?>>
                                        <span>Kan hantera sin klubb</span>
                                    </label>
                                    <label class="gs-checkbox gs-flex gs-items-center gs-gap-sm">
                                        <input type="checkbox" name="account_active" value="1" <?= $riderUser['active'] ? 'checked' : '' ?>>
                                        <span>Konto aktivt</span>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <div class="gs-flex gs-justify-between gs-mt-lg">
                            <button type="button" class="gs-btn gs-btn-error" onclick="confirmDeleteAccount()">
                                <i data-lucide="trash-2"></i>
                                Ta bort konto
                            </button>
                            <button type="submit" class="gs-btn gs-btn-primary">
                                <i data-lucide="save"></i>
                                Uppdatera konto
                            </button>
                        </div>
                    </form>

                    <form id="deleteAccountForm" method="POST" style="display: none;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete_account">
                    </form>
                <?php else: ?>
                    <!-- Create Account -->
                    <form method="POST">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="create_account">

                        <div class="gs-alert gs-alert-warning gs-mb-lg">
                            <i data-lucide="user-x"></i>
                            <span>Denna deltagare har inget användarkonto. Skapa ett för att låta dem logga in.</span>
                        </div>

                        <div class="gs-grid gs-grid-cols-1 gs-md-grid-cols-2 gs-gap-lg">
                            <div>
                                <label for="new_username" class="gs-label">
                                    <i data-lucide="at-sign"></i>
                                    Användarnamn <span class="gs-text-error">*</span>
                                </label>
                                <input
                                    type="text"
                                    id="new_username"
                                    name="new_username"
                                    class="gs-input"
                                    required
                                    pattern="[a-zA-Z0-9_]+"
                                    placeholder="t.ex. <?= strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $rider['firstname'] . $rider['lastname'])) ?>"
                                >
                                <small class="gs-text-secondary">Endast bokstäver, siffror och understreck</small>
                            </div>

                            <div>
                                <label for="new_email" class="gs-label">
                                    <i data-lucide="mail"></i>
                                    E-post
                                </label>
                                <input
                                    type="email"
                                    id="new_email"
                                    name="new_email"
                                    class="gs-input"
                                    value="<?= h($rider['email']) ?>"
                                    placeholder="Använder rider-email om tom"
                                >
                            </div>

                            <div>
                                <label for="new_password" class="gs-label">
                                    <i data-lucide="key"></i>
                                    Lösenord <span class="gs-text-error">*</span>
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

                            <div class="gs-flex gs-items-end">
                                <button type="submit" class="gs-btn gs-btn-primary">
                                    <i data-lucide="user-plus"></i>
                                    Skapa användarkonto
                                </button>
                            </div>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</main>

<script>
function confirmDeleteAccount() {
    if (confirm('Är du säker på att du vill ta bort användarkontot? Deltagarprofilen behålls.')) {
        document.getElementById('deleteAccountForm').submit();
    }
}
</script>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>

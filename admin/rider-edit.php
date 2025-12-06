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

// Profile image path
$profileImageDir = __DIR__ . '/../uploads/riders/';
$profileImageUrl = '/uploads/riders/';
$profileImage = null;

// Create directory if not exists
if (!is_dir($profileImageDir)) {
 mkdir($profileImageDir, 0755, true);
}

// Check for existing profile image
foreach (['jpg', 'jpeg', 'png', 'webp'] as $ext) {
 if (file_exists($profileImageDir . $id . '.' . $ext)) {
 $profileImage = $profileImageUrl . $id . '.' . $ext . '?v=' . filemtime($profileImageDir . $id . '.' . $ext);
 break;
 }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
 checkCsrf();

 $action = $_POST['action'] ?? 'save_rider';

 // Handle profile image upload
 if ($action === 'upload_image') {
 if (!isset($_FILES['profile_image']) || $_FILES['profile_image']['error'] === UPLOAD_ERR_NO_FILE) {
 $message = 'Ingen fil vald. Välj en bild att ladda upp.';
 $messageType = 'error';
 } elseif ($_FILES['profile_image']['error'] !== UPLOAD_ERR_OK) {
 $uploadErrors = [
  UPLOAD_ERR_INI_SIZE => 'Filen är för stor (server-gräns).',
  UPLOAD_ERR_FORM_SIZE => 'Filen är för stor.',
  UPLOAD_ERR_PARTIAL => 'Filen laddades bara upp delvis.',
  UPLOAD_ERR_NO_TMP_DIR => 'Ingen temp-mapp konfigurerad.',
  UPLOAD_ERR_CANT_WRITE => 'Kunde inte skriva filen till disk.',
  UPLOAD_ERR_EXTENSION => 'Uppladdning stoppad av PHP-tillägg.',
 ];
 $message = $uploadErrors[$_FILES['profile_image']['error']] ?? 'Okänt uppladdningsfel.';
 $messageType = 'error';
 } else {
 $allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
 $maxSize = 5 * 1024 * 1024; // 5MB

 $file = $_FILES['profile_image'];

 if (!in_array($file['type'], $allowedTypes)) {
  $message = 'Endast JPG, PNG och WebP är tillåtna. Du valde: ' . h($file['type']);
  $messageType = 'error';
 } elseif ($file['size'] > $maxSize) {
  $message = 'Bilden får max vara 5MB. Din fil var ' . round($file['size'] / 1024 / 1024, 1) . 'MB.';
  $messageType = 'error';
 } else {
  // Remove old images
  foreach (['jpg', 'jpeg', 'png', 'webp'] as $ext) {
  $oldFile = $profileImageDir . $id . '.' . $ext;
  if (file_exists($oldFile)) @unlink($oldFile);
  }

  // Save new image
  $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) ?: 'jpg';
  if ($ext === 'jpeg') $ext = 'jpg';
  $newPath = $profileImageDir . $id . '.' . $ext;

  if (move_uploaded_file($file['tmp_name'], $newPath)) {
  $message = 'Profilbild uppladdad!';
  $messageType = 'success';
  $profileImage = $profileImageUrl . $id . '.' . $ext . '?v=' . time();
  } else {
  $message = 'Kunde inte spara bilden. Kontrollera att mappen uploads/riders/ har skrivbehörighet.';
  $messageType = 'error';
  error_log("Failed to move uploaded file to: $newPath");
  }
 }
 }
 } elseif ($action === 'delete_image') {
 // Delete profile image
 foreach (['jpg', 'jpeg', 'png', 'webp'] as $ext) {
 $oldFile = $profileImageDir . $id . '.' . $ext;
 if (file_exists($oldFile)) unlink($oldFile);
 }
 $profileImage = null;
 $message = 'Profilbild borttagen!';
 $messageType = 'success';
 } elseif ($action === 'save_rider') {
 // Validate required fields
 $firstname = trim($_POST['firstname'] ?? '');
 $lastname = trim($_POST['lastname'] ?? '');

 if (empty($firstname) || empty($lastname)) {
 $message = 'Förnamn och efternamn är obligatoriska';
 $messageType = 'error';
 } else {
 // Prepare rider data (read-only fields excluded: license_type, license_category, license_valid_until, discipline)
 $riderData = [
 'firstname' => $firstname,
 'lastname' => $lastname,
 'birth_year' => !empty($_POST['birth_year']) ? intval($_POST['birth_year']) : null,
 'gender' => trim($_POST['gender'] ?? ''),
 'club_id' => !empty($_POST['club_id']) ? intval($_POST['club_id']) : null,
 'license_number' => trim($_POST['license_number'] ?? ''),
 'email' => trim($_POST['email'] ?? ''),
 'nationality' => trim($_POST['nationality'] ?? 'SWE'),
 'team' => trim($_POST['team'] ?? ''),
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

<main class="main-content">
 <div class="container" style="max-width: 900px;">
 <!-- Header -->
 <div class="flex items-center justify-between mb-lg">
 <h1 class="text-primary">
 <i data-lucide="user-circle"></i>
 Redigera Deltagare
 </h1>
 <a href="/admin/riders.php" class="btn btn--secondary">
 <i data-lucide="arrow-left"></i>
 Tillbaka
 </a>
 </div>

 <!-- Message -->
 <?php if ($message): ?>
 <div class="alert alert--<?= h($messageType) ?> mb-lg">
 <i data-lucide="<?= $messageType === 'success' ? 'check-circle' : 'alert-circle' ?>"></i>
 <?= h($message) ?>
 </div>
 <?php endif; ?>

 <!-- Profile Image -->
 <div class="card mb-lg">
 <div class="card-body">
 <h2 class="text-primary mb-md">
  <i data-lucide="camera"></i>
  Profilbild
 </h2>
 <div class="flex items-center gap-lg">
  <div class="profile-image-preview" style="width: 120px; height: 120px; border-radius: 50%; background: var(--color-bg-sunken); display: flex; align-items: center; justify-content: center; overflow: hidden;">
  <?php if ($profileImage): ?>
  <img src="<?= h($profileImage) ?>" alt="Profilbild" style="width: 100%; height: 100%; object-fit: cover;">
  <?php else: ?>
  <i data-lucide="user" style="width: 48px; height: 48px; opacity: 0.3;"></i>
  <?php endif; ?>
  </div>
  <div class="flex flex-col gap-sm">
  <form method="POST" enctype="multipart/form-data" style="display: flex; gap: 0.5rem; align-items: center;">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="upload_image">
  <input type="file" name="profile_image" accept="image/jpeg,image/png,image/webp" class="input" style="max-width: 200px;">
  <button type="submit" class="btn btn--primary btn--sm">
   <i data-lucide="upload"></i>
   Ladda upp
  </button>
  </form>
  <?php if ($profileImage): ?>
  <form method="POST" style="display: inline;">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="delete_image">
  <button type="submit" class="btn btn--secondary btn--sm" onclick="return confirm('Ta bort profilbilden?')">
   <i data-lucide="trash-2"></i>
   Ta bort bild
  </button>
  </form>
  <?php endif; ?>
  <small class="text-secondary">Max 5MB. JPG, PNG eller WebP.</small>
  </div>
 </div>
 </div>
 </div>

 <!-- Edit Form -->
 <form method="POST" class="card">
 <?= csrf_field() ?>

 <div class="card-body">
 <div class="grid gap-lg" style="grid-template-columns: repeat(2, 1fr);">
  <!-- Personal Information -->
  <div style="grid-column: span 2;">
  <h2 class="text-primary mb-md">
  <i data-lucide="user"></i>
  Personuppgifter
  </h2>
  </div>

  <!-- First Name (Required) -->
  <div>
  <label for="firstname" class="label">
  <i data-lucide="user"></i>
  Förnamn <span class="text-error">*</span>
  </label>
  <input
  type="text"
  id="firstname"
  name="firstname"
  class="input"
  required
  value="<?= h($rider['firstname']) ?>"
  >
  </div>

  <!-- Last Name (Required) -->
  <div>
  <label for="lastname" class="label">
  <i data-lucide="user"></i>
  Efternamn <span class="text-error">*</span>
  </label>
  <input
  type="text"
  id="lastname"
  name="lastname"
  class="input"
  required
  value="<?= h($rider['lastname']) ?>"
  >
  </div>

  <!-- Birth Year -->
  <div>
  <label for="birth_year" class="label">
  <i data-lucide="calendar"></i>
  Födelseår
  </label>
  <input
  type="number"
  id="birth_year"
  name="birth_year"
  class="input"
  min="1900"
  max="<?= date('Y') ?>"
  value="<?= h($rider['birth_year']) ?>"
  >
  </div>

  <!-- Gender -->
  <div>
  <label for="gender" class="label">
  <i data-lucide="users"></i>
  Kön
  </label>
  <select id="gender" name="gender" class="input">
  <option value="">Välj...</option>
  <option value="M" <?= $rider['gender'] === 'M' ? 'selected' : '' ?>>Man</option>
  <option value="F" <?= $rider['gender'] === 'F' ? 'selected' : '' ?>>Kvinna</option>
  </select>
  </div>

  <!-- License Information -->
  <div style="grid-column: span 2; margin-top: var(--space-lg);">
  <h2 class="text-primary mb-md">
  <i data-lucide="award"></i>
  Licensinformation
  </h2>
  </div>

  <!-- Club -->
  <div>
  <label for="club_id" class="label">
  <i data-lucide="building"></i>
  Klubb
  </label>
  <select id="club_id" name="club_id" class="input">
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
  <label for="license_number" class="label">
  <i data-lucide="credit-card"></i>
  Licensnummer
  </label>
  <input
  type="text"
  id="license_number"
  name="license_number"
  class="input"
  value="<?= h($rider['license_number']) ?>"
  placeholder="UCI ID eller SWE-ID"
  >
  </div>

  <!-- License Category (read-only) -->
  <div>
  <label class="label">
  <i data-lucide="tag"></i>
  Licenskategori
  </label>
  <input
  type="text"
  class="input"
  value="<?= h($rider['license_category'] ?: '-') ?>"
  disabled
  >
  <small class="text-secondary">Importeras från SCF</small>
  </div>

  <!-- Nationality -->
  <div>
  <label for="nationality" class="label">
  <i data-lucide="flag"></i>
  Nationalitet
  </label>
  <select id="nationality" name="nationality" class="input">
  <option value="SWE" <?= ($rider['nationality'] ?? 'SWE') === 'SWE' ? 'selected' : '' ?>>🇸🇪 Sverige</option>
  <option value="NOR" <?= ($rider['nationality'] ?? '') === 'NOR' ? 'selected' : '' ?>>🇳🇴 Norge</option>
  <option value="DEN" <?= ($rider['nationality'] ?? '') === 'DEN' ? 'selected' : '' ?>>🇩🇰 Danmark</option>
  <option value="FIN" <?= ($rider['nationality'] ?? '') === 'FIN' ? 'selected' : '' ?>>🇫🇮 Finland</option>
  <option value="GBR" <?= ($rider['nationality'] ?? '') === 'GBR' ? 'selected' : '' ?>>🇬🇧 Storbritannien</option>
  </select>
  </div>

  <!-- Team -->
  <div>
  <label for="team" class="label">
  <i data-lucide="users"></i>
  Lagnamn
  </label>
  <input
  type="text"
  id="team"
  name="team"
  class="input"
  value="<?= h($rider['team'] ?? '') ?>"
  placeholder="Separat från klubb"
  >
  </div>

  <!-- Read-only Fields -->
  <div style="grid-column: span 2; margin-top: var(--space-lg);">
  <h2 class="text-primary mb-md">
  <i data-lucide="database"></i>
  Systemdata (endast läsning)
  </h2>
  </div>

  <!-- License Year (read-only) -->
  <div>
  <label class="label">
  <i data-lucide="calendar"></i>
  Licensår
  </label>
  <input
  type="text"
  class="input"
  value="<?= h($rider['license_year'] ?? '-') ?>"
  disabled
  >
  <small class="text-secondary">Importeras från SCF</small>
  </div>

  <!-- Gravity ID (read-only) -->
  <div>
  <label class="label">
  <i data-lucide="zap"></i>
  Gravity ID
  </label>
  <input
  type="text"
  class="input"
  value="<?= h($rider['gravity_id'] ?? '-') ?>"
  disabled
  >
  <small class="text-secondary">Tilldelas av systemet</small>
  </div>

  <!-- Disciplines Checkboxes (read-only from license) -->
  <div style="grid-column: span 2;">
  <label class="label">
  <i data-lucide="list"></i>
  Licensierade discipliner
  </label>
  <?php
  $allDisciplines = [
  'DH' => 'Downhill',
  'END' => 'Enduro',
  'XCO' => 'Cross Country Olympic',
  'XCM' => 'Cross Country Marathon',
  'BMX' => 'BMX',
  'ROAD' => 'Landsväg',
  'TRACK' => 'Bana',
  'GRAVEL' => 'Gravel',
  'CX' => 'Cyclocross'
  ];
  $riderDisciplines = $rider['disciplines'] ? json_decode($rider['disciplines'], true) ?: [] : [];
  ?>
  <div style="display: flex; flex-wrap: wrap; gap: 0.75rem; margin-top: 0.5rem;">
  <?php foreach ($allDisciplines as $code => $name): ?>
  <?php $isChecked = in_array($code, $riderDisciplines); ?>
  <label style="display: flex; align-items: center; gap: 0.5rem; padding: 0.5rem 0.75rem; background: <?= $isChecked ? 'rgba(245, 158, 11, 0.15)' : 'var(--color-bg-sunken)' ?>; border: 1px solid <?= $isChecked ? 'var(--color-accent)' : 'var(--color-border)' ?>; border-radius: var(--radius-md); cursor: not-allowed; opacity: <?= $isChecked ? '1' : '0.5' ?>;">
  <input type="checkbox" <?= $isChecked ? 'checked' : '' ?> disabled style="accent-color: var(--color-accent);">
  <span style="font-size: var(--text-sm); color: <?= $isChecked ? 'var(--color-accent)' : 'var(--color-text-secondary)' ?>;"><?= h($name) ?></span>
  </label>
  <?php endforeach; ?>
  </div>
  <small class="text-secondary" style="display: block; margin-top: 0.5rem;">Importeras från SCF. Används för framtida anmälan.</small>
  </div>

  <!-- Contact Information -->
  <div style="grid-column: span 2; margin-top: var(--space-lg);">
  <h2 class="text-primary mb-md">
  <i data-lucide="mail"></i>
  Kontaktuppgifter
  </h2>
  </div>

  <!-- Email -->
  <div>
  <label for="email" class="label">
  <i data-lucide="mail"></i>
  E-post
  </label>
  <input
  type="email"
  id="email"
  name="email"
  class="input"
  value="<?= h($rider['email']) ?>"
  >
  </div>

  <!-- Active Status -->
  <div>
  <label class="checkbox-label">
  <input
  type="checkbox"
  id="active"
  name="active"
  class="checkbox"
  <?= $rider['active'] ? 'checked' : '' ?>
  >
  <span>
  <i data-lucide="check-circle"></i>
  Aktiv deltagare
  </span>
  </label>
  </div>

  <!-- Notes -->
  <div style="grid-column: span 2;">
  <label for="notes" class="label">
  <i data-lucide="file-text"></i>
  Anteckningar
  </label>
  <textarea
  id="notes"
  name="notes"
  class="input"
  rows="3"
  ><?= h($rider['notes']) ?></textarea>
  </div>
 </div>
 </div>

 <div class="card-footer">
 <div class="flex gap-md" style="justify-content: flex-end;">
  <a href="/admin/riders.php" class="btn btn--secondary">
  <i data-lucide="x"></i>
  Avbryt
  </a>
  <button type="submit" class="btn btn--primary">
  <i data-lucide="save"></i>
  Spara ändringar
  </button>
 </div>
 </div>
 </form>

 <?php if (hasRole('super_admin')): ?>
 <!-- User Account Section -->
 <div class="card mt-lg">
 <div class="card-header">
 <h2 class="">
  <i data-lucide="key"></i>
  Användarkonto
 </h2>
 </div>
 <div class="card-body">
 <?php if ($riderUser): ?>
  <!-- Existing Account -->
  <form method="POST">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="update_account">

  <div class="grid gap-lg" style="grid-template-columns: repeat(2, 1fr);">
  <div style="grid-column: span 2;">
  <div class="alert alert--info">
   <i data-lucide="user-check"></i>
   <span>Denna deltagare har ett användarkonto: <strong><?= h($riderUser['username']) ?></strong></span>
  </div>
  </div>

  <div>
  <label class="label">
   <i data-lucide="at-sign"></i>
   Användarnamn
  </label>
  <input type="text" class="input" value="<?= h($riderUser['username']) ?>" disabled>
  <small class="text-secondary">Kan inte ändras</small>
  </div>

  <div>
  <label for="account_email" class="label">
   <i data-lucide="mail"></i>
   E-post för inloggning
  </label>
  <input
   type="email"
   id="account_email"
   name="account_email"
   class="input"
   value="<?= h($riderUser['email']) ?>"
  >
  </div>

  <div>
  <label for="account_password" class="label">
   <i data-lucide="key"></i>
   Nytt lösenord
  </label>
  <input
   type="password"
   id="account_password"
   name="account_password"
   class="input"
   placeholder="Lämna tomt för att behålla"
   minlength="8"
  >
  <small class="text-secondary">Minst 8 tecken</small>
  </div>

  <div>
  <label class="label">Senaste inloggning</label>
  <input type="text" class="input" value="<?= $riderUser['last_login'] ? date('Y-m-d H:i', strtotime($riderUser['last_login'])) : 'Aldrig' ?>" disabled>
  </div>

  <div style="grid-column: span 2;">
  <label class="label">Behörigheter</label>
  <div class="flex gap-lg flex-wrap">
   <label class="checkbox flex items-center gap-sm">
   <input type="checkbox" name="can_edit_profile" value="1" <?= $riderUser['can_edit_profile'] ? 'checked' : '' ?>>
   <span>Kan redigera sin profil</span>
   </label>
   <label class="checkbox flex items-center gap-sm">
   <input type="checkbox" name="can_manage_club" value="1" <?= $riderUser['can_manage_club'] ? 'checked' : '' ?>>
   <span>Kan hantera sin klubb</span>
   </label>
   <label class="checkbox flex items-center gap-sm">
   <input type="checkbox" name="account_active" value="1" <?= $riderUser['active'] ? 'checked' : '' ?>>
   <span>Konto aktivt</span>
   </label>
  </div>
  </div>
  </div>

  <div class="flex justify-between mt-lg">
  <button type="button" class="btn btn-error" onclick="confirmDeleteAccount()">
  <i data-lucide="trash-2"></i>
  Ta bort konto
  </button>
  <button type="submit" class="btn btn--primary">
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

  <div class="alert alert--warning mb-lg">
  <i data-lucide="user-x"></i>
  <span>Denna deltagare har inget användarkonto. Skapa ett för att låta dem logga in.</span>
  </div>

  <div class="grid gap-lg" style="grid-template-columns: repeat(2, 1fr);">
  <div>
  <label for="new_username" class="label">
   <i data-lucide="at-sign"></i>
   Användarnamn <span class="text-error">*</span>
  </label>
  <input
   type="text"
   id="new_username"
   name="new_username"
   class="input"
   required
   pattern="[a-zA-Z0-9_]+"
   placeholder="t.ex. <?= strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $rider['firstname'] . $rider['lastname'])) ?>"
  >
  <small class="text-secondary">Endast bokstäver, siffror och understreck</small>
  </div>

  <div>
  <label for="new_email" class="label">
   <i data-lucide="mail"></i>
   E-post
  </label>
  <input
   type="email"
   id="new_email"
   name="new_email"
   class="input"
   value="<?= h($rider['email']) ?>"
   placeholder="Använder rider-email om tom"
  >
  </div>

  <div>
  <label for="new_password" class="label">
   <i data-lucide="key"></i>
   Lösenord <span class="text-error">*</span>
  </label>
  <input
   type="password"
   id="new_password"
   name="new_password"
   class="input"
   required
   minlength="8"
   placeholder="Minst 8 tecken"
  >
  </div>

  <div class="flex" style="align-items: flex-end;">
  <button type="submit" class="btn btn--primary">
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

<style>
@media (max-width: 768px) {
 .grid[style*="grid-template-columns: repeat(2, 1fr)"] {
  grid-template-columns: 1fr !important;
 }
 .grid [style*="grid-column: span 2"] {
  grid-column: span 1 !important;
 }
}
</style>

<script>
function confirmDeleteAccount() {
 if (confirm('Är du säker på att du vill ta bort användarkontot? Deltagarprofilen behålls.')) {
 document.getElementById('deleteAccountForm').submit();
 }
}
</script>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>

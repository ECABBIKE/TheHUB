<?php
require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();

// Get club ID
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($id <= 0) {
 $_SESSION['message'] = 'Ogiltigt klubb-ID';
 $_SESSION['messageType'] = 'error';
 header('Location: /admin/clubs.php');
 exit;
}

// Fetch club data
$club = $db->getRow("SELECT * FROM clubs WHERE id = ?", [$id]);

if (!$club) {
 $_SESSION['message'] = 'Klubb hittades inte';
 $_SESSION['messageType'] = 'error';
 header('Location: /admin/clubs.php');
 exit;
}

// Initialize message variables
$message = '';
$messageType = 'info';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
 checkCsrf();

 // Validate required fields
 $name = trim($_POST['name'] ?? '');

 if (empty($name)) {
 $message = 'Klubbnamn är obligatoriskt';
 $messageType = 'error';
 } else {
 // Prepare club data
 $clubData = [
  'name' => $name,
  'short_name' => trim($_POST['short_name'] ?? ''),
  'region' => trim($_POST['region'] ?? ''),
  'city' => trim($_POST['city'] ?? ''),
  'country' => trim($_POST['country'] ?? 'Sverige'),
  'website' => trim($_POST['website'] ?? ''),
  'logo' => trim($_POST['logo'] ?? ''),
  'email' => trim($_POST['email'] ?? ''),
  'phone' => trim($_POST['phone'] ?? ''),
  'contact_person' => trim($_POST['contact_person'] ?? ''),
  'address' => trim($_POST['address'] ?? ''),
  'postal_code' => trim($_POST['postal_code'] ?? ''),
  'description' => trim($_POST['description'] ?? ''),
  'facebook' => trim($_POST['facebook'] ?? ''),
  'instagram' => trim($_POST['instagram'] ?? ''),
  'org_number' => trim($_POST['org_number'] ?? ''),
  'scf_id' => trim($_POST['scf_id'] ?? ''),
  'active' => isset($_POST['active']) ? 1 : 0,
 ];

 try {
  $db->update('clubs', $clubData, 'id = ?', [$id]);
  $message = 'Klubb uppdaterad!';
  $messageType = 'success';

  // Refresh club data
  $club = $db->getRow("SELECT * FROM clubs WHERE id = ?", [$id]);
 } catch (Exception $e) {
  $message = 'Ett fel uppstod: ' . $e->getMessage();
  $messageType = 'error';
 }
 }
}

// Get rider count for this club
$riderCount = $db->getRow("SELECT COUNT(*) as count FROM riders WHERE club_id = ? AND active = 1", [$id]);

$pageTitle = 'Redigera Klubb - ' . $club['name'];
$pageType = 'admin';
include __DIR__ . '/../includes/layout-header.php';
?>

<main class="main-content">
 <div class="container">
 <!-- Header -->
 <div class="flex items-center justify-between mb-lg">
  <div>
  <h1 class="text-primary mb-sm">
   <i data-lucide="building"></i>
   <?= h($club['name']) ?>
  </h1>
  <p class="text-secondary">
   <?= $riderCount['count'] ?? 0 ?> aktiva medlemmar
  </p>
  </div>
  <a href="/admin/clubs.php" class="btn btn--secondary">
  <i data-lucide="arrow-left"></i>
  Tillbaka
  </a>
 </div>

 <!-- Messages -->
 <?php if ($message): ?>
  <div class="alert alert-<?= $messageType ?> mb-lg">
  <i data-lucide="<?= $messageType === 'success' ? 'check-circle' : ($messageType === 'error' ? 'alert-circle' : 'info') ?>"></i>
  <?= h($message) ?>
  </div>
 <?php endif; ?>

 <!-- Form -->
 <form method="POST">
  <?= csrf_field() ?>

  <div class="grid grid-cols-1 gs-lg-grid-cols-3 gap-lg">
  <!-- Main Content (2 columns) -->
  <div class="gs-lg-col-span-2">
   <!-- Basic Information -->
   <div class="card mb-lg">
   <div class="card-header">
    <h2 class="text-primary">
    <i data-lucide="info"></i>
    Grundläggande information
    </h2>
   </div>
   <div class="card-body">
    <div class="grid grid-cols-1 gap-md">
    <!-- Name -->
    <div>
     <label for="name" class="label">
     Klubbnamn <span class="text-error">*</span>
     </label>
     <input
     type="text"
     id="name"
     name="name"
     class="input"
     required
     value="<?= h($club['name']) ?>"
     >
    </div>

    <!-- Short Name and SCF ID -->
    <div class="grid grid-cols-2 gap-md">
     <div>
     <label for="short_name" class="label">Förkortning</label>
     <input
      type="text"
      id="short_name"
      name="short_name"
      class="input"
      value="<?= h($club['short_name'] ?? '') ?>"
      placeholder="T.ex. UCK, TGS"
     >
     </div>
     <div>
     <label for="scf_id" class="label">SCF Klubb-ID</label>
     <input
      type="text"
      id="scf_id"
      name="scf_id"
      class="input"
      value="<?= h($club['scf_id'] ?? '') ?>"
      placeholder="Svenska Cykelförbundets ID"
     >
     </div>
    </div>

    <!-- Org Number -->
    <div>
     <label for="org_number" class="label">Organisationsnummer</label>
     <input
     type="text"
     id="org_number"
     name="org_number"
     class="input"
     value="<?= h($club['org_number'] ?? '') ?>"
     placeholder="T.ex. 802000-0000"
     >
    </div>

    <!-- Description -->
    <div>
     <label for="description" class="label">Beskrivning</label>
     <textarea
     id="description"
     name="description"
     class="input"
     rows="4"
     placeholder="Beskriv klubben..."
     ><?= h($club['description'] ?? '') ?></textarea>
    </div>

    <!-- Logo URL -->
    <div>
     <label for="logo" class="label">Logotyp (URL)</label>
     <input
     type="url"
     id="logo"
     name="logo"
     class="input"
     value="<?= h($club['logo'] ?? '') ?>"
     placeholder="https://example.com/logo.png"
     >
     <?php if (!empty($club['logo'])): ?>
     <div class="mt-sm">
      <img src="<?= h($club['logo']) ?>" alt="Klubblogotyp" style="max-height: 60px; border-radius: 4px;">
     </div>
     <?php endif; ?>
    </div>
    </div>
   </div>
   </div>

   <!-- Address -->
   <div class="card mb-lg">
   <div class="card-header">
    <h2 class="text-primary">
    <i data-lucide="map-pin"></i>
    Adress
    </h2>
   </div>
   <div class="card-body">
    <div class="grid grid-cols-1 gap-md">
    <!-- Address -->
    <div>
     <label for="address" class="label">Gatuadress</label>
     <input
     type="text"
     id="address"
     name="address"
     class="input"
     value="<?= h($club['address'] ?? '') ?>"
     placeholder="T.ex. Storgatan 1"
     >
    </div>

    <!-- Postal Code and City -->
    <div class="grid grid-cols-3 gap-md">
     <div>
     <label for="postal_code" class="label">Postnummer</label>
     <input
      type="text"
      id="postal_code"
      name="postal_code"
      class="input"
      value="<?= h($club['postal_code'] ?? '') ?>"
      placeholder="123 45"
     >
     </div>
     <div class="gs-col-span-2">
     <label for="city" class="label">Stad</label>
     <input
      type="text"
      id="city"
      name="city"
      class="input"
      value="<?= h($club['city'] ?? '') ?>"
     >
     </div>
    </div>

    <!-- Region and Country -->
    <div class="grid grid-cols-2 gap-md">
     <div>
     <label for="region" class="label">Region</label>
     <input
      type="text"
      id="region"
      name="region"
      class="input"
      value="<?= h($club['region'] ?? '') ?>"
      placeholder="T.ex. Västsverige"
     >
     </div>
     <div>
     <label for="country" class="label">Land</label>
     <input
      type="text"
      id="country"
      name="country"
      class="input"
      value="<?= h($club['country'] ?? 'Sverige') ?>"
     >
     </div>
    </div>
    </div>
   </div>
   </div>

   <!-- Contact Information -->
   <div class="card mb-lg">
   <div class="card-header">
    <h2 class="text-primary">
    <i data-lucide="phone"></i>
    Kontaktinformation
    </h2>
   </div>
   <div class="card-body">
    <div class="grid grid-cols-1 gap-md">
    <!-- Contact Person -->
    <div>
     <label for="contact_person" class="label">Kontaktperson</label>
     <input
     type="text"
     id="contact_person"
     name="contact_person"
     class="input"
     value="<?= h($club['contact_person'] ?? '') ?>"
     placeholder="Namn på kontaktperson"
     >
    </div>

    <!-- Email and Phone -->
    <div class="grid grid-cols-2 gap-md">
     <div>
     <label for="email" class="label">E-post</label>
     <input
      type="email"
      id="email"
      name="email"
      class="input"
      value="<?= h($club['email'] ?? '') ?>"
      placeholder="info@klubb.se"
     >
     </div>
     <div>
     <label for="phone" class="label">Telefon</label>
     <input
      type="tel"
      id="phone"
      name="phone"
      class="input"
      value="<?= h($club['phone'] ?? '') ?>"
      placeholder="070-123 45 67"
     >
     </div>
    </div>

    <!-- Website -->
    <div>
     <label for="website" class="label">Webbplats</label>
     <input
     type="url"
     id="website"
     name="website"
     class="input"
     value="<?= h($club['website'] ?? '') ?>"
     placeholder="https://klubb.se"
     >
    </div>

    <!-- Social Media -->
    <div class="grid grid-cols-2 gap-md">
     <div>
     <label for="facebook" class="label">
      <i data-lucide="facebook" class="icon-sm"></i>
      Facebook
     </label>
     <input
      type="url"
      id="facebook"
      name="facebook"
      class="input"
      value="<?= h($club['facebook'] ?? '') ?>"
      placeholder="https://facebook.com/..."
     >
     </div>
     <div>
     <label for="instagram" class="label">
      <i data-lucide="instagram" class="icon-sm"></i>
      Instagram
     </label>
     <input
      type="url"
      id="instagram"
      name="instagram"
      class="input"
      value="<?= h($club['instagram'] ?? '') ?>"
      placeholder="https://instagram.com/..."
     >
     </div>
    </div>
    </div>
   </div>
   </div>
  </div>

  <!-- Sidebar (1 column) -->
  <div>
   <!-- Status -->
   <div class="card mb-lg">
   <div class="card-header">
    <h2 class="text-primary">
    <i data-lucide="settings"></i>
    Status
    </h2>
   </div>
   <div class="card-body">
    <label class="checkbox-label">
    <input
     type="checkbox"
     name="active"
     class="checkbox"
     <?= $club['active'] ? 'checked' : '' ?>
    >
    <span>Aktiv klubb</span>
    </label>
   </div>
   </div>

   <!-- Actions -->
   <div class="card">
   <div class="card-body">
    <button type="submit" class="btn btn--primary w-full mb-sm">
    <i data-lucide="save"></i>
    Spara ändringar
    </button>
    <a href="/club.php?id=<?= $id ?>" target="_blank" class="btn btn--secondary w-full">
    <i data-lucide="eye"></i>
    Visa publik sida
    </a>
   </div>
   </div>
  </div>
  </div>
 </form>
 </div>
</main>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>

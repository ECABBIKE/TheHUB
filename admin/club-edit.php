<?php
/**
 * Club Edit Page - V3 Unified Layout
 * Supports both editing existing clubs and creating new ones
 * Version: v2.0.0 [2025-12-13]
 */
require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();

// Get club ID - 0 or null means creating new club
$id = isset($_GET['id']) && is_numeric($_GET['id']) ? intval($_GET['id']) : 0;
$isNew = ($id === 0);

// Default values for new club
$club = [
    'name' => '',
    'short_name' => '',
    'region' => '',
    'city' => '',
    'country' => 'Sverige',
    'website' => '',
    'logo' => '',
    'email' => '',
    'phone' => '',
    'contact_person' => '',
    'address' => '',
    'postal_code' => '',
    'description' => '',
    'facebook' => '',
    'instagram' => '',
    'org_number' => '',
    'scf_id' => '',
    'swish_number' => '',
    'swish_name' => '',
    'payment_enabled' => 0,
    'active' => 1
];

// Fetch existing club data if editing
if (!$isNew) {
    $existingClub = $db->getRow("SELECT * FROM clubs WHERE id = ?", [$id]);

    if (!$existingClub) {
        $_SESSION['message'] = 'Klubb hittades inte';
        $_SESSION['messageType'] = 'error';
        header('Location: /admin/clubs.php');
        exit;
    }

    $club = array_merge($club, $existingClub);
}

// Initialize message variables
$message = '';
$messageType = 'info';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();

    $action = $_POST['action'] ?? 'save';

    // Handle delete action
    if ($action === 'delete' && !$isNew) {
        $riderCount = $db->getRow("SELECT COUNT(*) as cnt FROM riders WHERE club_id = ?", [$id])['cnt'] ?? 0;

        if ($riderCount > 0) {
            $message = "Kan inte ta bort klubb med $riderCount medlemmar. Flytta medlemmarna först.";
            $messageType = 'error';
        } else {
            try {
                $db->delete('clubs', 'id = ?', [$id]);
                header('Location: /admin/clubs.php?msg=deleted');
                exit;
            } catch (Exception $e) {
                $message = 'Fel vid borttagning: ' . $e->getMessage();
                $messageType = 'error';
            }
        }
    } else {
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
                'swish_number' => trim($_POST['swish_number'] ?? '') ?: null,
                'swish_name' => trim($_POST['swish_name'] ?? '') ?: null,
                'payment_enabled' => isset($_POST['payment_enabled']) ? 1 : 0,
                'active' => isset($_POST['active']) ? 1 : 0,
            ];

            try {
                if ($isNew) {
                    $db->insert('clubs', $clubData);
                    $id = $db->lastInsertId();
                    header('Location: /admin/club-edit.php?id=' . $id . '&msg=created');
                    exit;
                } else {
                    $db->update('clubs', $clubData, 'id = ?', [$id]);
                    $message = 'Klubb uppdaterad!';
                    $messageType = 'success';

                    // Refresh club data
                    $club = $db->getRow("SELECT * FROM clubs WHERE id = ?", [$id]);
                }
            } catch (Exception $e) {
                $message = 'Ett fel uppstod: ' . $e->getMessage();
                $messageType = 'error';
            }
        }
    }
}

// Handle URL messages
if (isset($_GET['msg'])) {
    switch ($_GET['msg']) {
        case 'created':
            $message = 'Klubb skapad!';
            $messageType = 'success';
            break;
    }
}

// Get rider count for this club
$riderCount = 0;
$riders = [];
if (!$isNew) {
    $riderCount = $db->getRow("SELECT COUNT(*) as count FROM riders WHERE club_id = ?", [$id])['count'] ?? 0;

    // Get first 10 riders for sidebar
    $riders = $db->getAll("
        SELECT id, firstname, lastname, gender, birth_year, active
        FROM riders
        WHERE club_id = ?
        ORDER BY lastname, firstname
        LIMIT 10
    ", [$id]);
}

// Page config for admin layout
$page_title = $isNew ? 'Ny Klubb' : 'Redigera Klubb';
$breadcrumbs = [
    ['label' => 'Klubbar', 'url' => '/admin/clubs.php'],
    ['label' => $isNew ? 'Ny Klubb' : h($club['name'])]
];

include __DIR__ . '/components/unified-layout.php';
?>

<!-- Messages -->
<?php if ($message): ?>
    <div class="alert alert--<?= $messageType ?> mb-lg">
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
    </div>
   </div>
   </div>

   <!-- Payment/Swish Settings -->
   <div class="card mb-lg">
   <div class="card-header">
    <h2 class="text-primary">
    <i data-lucide="smartphone"></i>
    Betalning (Swish)
    </h2>
   </div>
   <div class="card-body">
    <p class="text-secondary mb-md">
    Om klubben arrangerar tävlingar kan betalning gå direkt till klubbens Swish.
    </p>
    <div class="grid gap-lg" style="grid-template-columns: repeat(2, 1fr);">
    <!-- Swish Number -->
    <div>
     <label for="swish_number" class="label">Swish-nummer</label>
     <input
     type="text"
     id="swish_number"
     name="swish_number"
     class="input"
     value="<?= h($club['swish_number'] ?? '') ?>"
     placeholder="070-123 45 67 eller 123-456 78 90"
     >
     <small class="text-secondary">Mobilnummer eller Swish-företagsnummer</small>
    </div>

    <!-- Swish Name -->
    <div>
     <label for="swish_name" class="label">Mottagarnamn</label>
     <input
     type="text"
     id="swish_name"
     name="swish_name"
     class="input"
     value="<?= h($club['swish_name'] ?? '') ?>"
     placeholder="Klubbens namn"
     >
     <small class="text-secondary">Visas för deltagare vid betalning</small>
    </div>

    <!-- Payment Enabled -->
    <div style="grid-column: span 2;">
     <label class="checkbox-label">
     <input type="checkbox" name="payment_enabled" value="1"
      <?= ($club['payment_enabled'] ?? 0) ? 'checked' : '' ?>>
     <span>Aktivera som betalningsmottagare</span>
     </label>
     <small class="text-secondary">Klubben kan väljas som mottagare för eventbetalningar</small>
    </div>
    </div>
   </div>
   </div>

   <!-- Description & Logo -->
   <div class="card mb-lg">
   <div class="card-header">
    <h2 class="text-primary">
    <i data-lucide="file-text"></i>
    Övrigt
    </h2>
   </div>
   <div class="card-body">
    <div class="grid gap-lg" style="grid-template-columns: repeat(2, 1fr);">

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
    <label class="checkbox-label" style="display: flex; align-items: center; gap: var(--space-sm); cursor: pointer;">
    <input
     type="checkbox"
     name="active"
     class="checkbox"
     <?= $club['active'] ? 'checked' : '' ?>
     style="width: 18px; height: 18px; accent-color: var(--color-accent);"
    >
    <span>Aktiv klubb</span>
    </label>
    <?php if (!$isNew): ?>
    <p class="text-secondary text-sm mt-md">
     <i data-lucide="users" class="icon-sm"></i>
     <?= $riderCount ?> aktiva medlemmar
    </p>
    <?php endif; ?>
   </div>
   </div>

   <!-- Actions -->
   <div class="card mb-lg">
   <div class="card-body">
    <button type="submit" class="btn btn--primary w-full mb-sm">
    <i data-lucide="save"></i>
    <?= $isNew ? 'Skapa klubb' : 'Spara ändringar' ?>
    </button>
    <?php if (!$isNew): ?>
    <a href="/club.php?id=<?= $id ?>" target="_blank" class="btn btn--secondary w-full mb-sm">
    <i data-lucide="eye"></i>
    Visa publik sida
    </a>
    <?php endif; ?>
    <a href="/admin/clubs.php" class="btn btn--secondary w-full">
    <i data-lucide="arrow-left"></i>
    Tillbaka till listan
    </a>
   </div>
   </div>

   <?php if (!$isNew): ?>
   <!-- Danger Zone -->
   <div class="card" style="border-color: var(--color-error);">
   <div class="card-header" style="background: rgba(239, 68, 68, 0.1);">
    <h2 style="color: var(--color-error);">
    <i data-lucide="alert-triangle"></i>
    Fara
    </h2>
   </div>
   <div class="card-body">
    <?php if ($riderCount > 0): ?>
    <p class="text-secondary text-sm mb-md">
     Klubben har <?= $riderCount ?> medlemmar och kan inte tas bort.
    </p>
    <button type="button" class="btn btn-danger w-full" disabled>
     <i data-lucide="trash-2"></i>
     Ta bort klubb
    </button>
    <?php else: ?>
    <p class="text-secondary text-sm mb-md">
     Denna åtgärd kan inte ångras.
    </p>
    <button type="submit" name="action" value="delete" class="btn btn-danger w-full"
     onclick="return confirm('Är du säker på att du vill ta bort denna klubb?')">
     <i data-lucide="trash-2"></i>
     Ta bort klubb
    </button>
    <?php endif; ?>
   </div>
   </div>
   <?php endif; ?>
  </div>
  </div>
 </form>

<?php if (!$isNew && !empty($riders)): ?>
<!-- Club Members -->
<div class="card mt-lg">
    <div class="card-header flex justify-between items-center">
        <h2>
            <i data-lucide="users"></i>
            Medlemmar (<?= $riderCount ?>)
        </h2>
        <a href="/admin/riders.php?club_id=<?= $id ?>" class="btn btn--secondary btn--sm">
            <i data-lucide="external-link"></i>
            Visa alla
        </a>
    </div>
    <div class="card-body" style="padding: 0;">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Namn</th>
                        <th>Kön</th>
                        <th>Födelseår</th>
                        <th>Status</th>
                        <th>Åtgärd</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($riders as $rider): ?>
                    <tr>
                        <td>
                            <strong><?= h($rider['firstname'] . ' ' . $rider['lastname']) ?></strong>
                        </td>
                        <td><?= $rider['gender'] === 'M' ? 'Man' : ($rider['gender'] === 'F' ? 'Kvinna' : '-') ?></td>
                        <td><?= h($rider['birth_year']) ?: '-' ?></td>
                        <td>
                            <span class="badge <?= $rider['active'] ? 'badge-success' : 'badge-secondary' ?>">
                                <?= $rider['active'] ? 'Aktiv' : 'Inaktiv' ?>
                            </span>
                        </td>
                        <td>
                            <a href="/admin/rider-edit.php?id=<?= $rider['id'] ?>" class="btn btn--secondary btn--sm">
                                <i data-lucide="pencil"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($riderCount > 10): ?>
        <div class="text-center py-md border-t">
            <a href="/admin/riders.php?club_id=<?= $id ?>" class="text-accent">
                Visa alla <?= $riderCount ?> medlemmar
            </a>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>

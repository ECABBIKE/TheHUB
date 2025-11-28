<?php
require_once __DIR__ . '/../config.php';
require_admin();
require_once __DIR__ . '/../includes/admin-layout.php';

$db = getDB();
$current_admin = get_current_admin();

// Initialize message variables
$message = '';
$messageType = 'info';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
 checkCsrf();

 $action = $_POST['action'] ?? '';

 if ($action === 'create' || $action === 'update') {
 // Validate required fields
 $name = trim($_POST['name'] ?? '');

 if (empty($name)) {
  $message = 'Venue namn är obligatoriskt';
  $messageType = 'error';
 } else {
  // Prepare venue data
  $venueData = [
  'name' => $name,
  'city' => trim($_POST['city'] ?? ''),
  'region' => trim($_POST['region'] ?? ''),
  'country' => trim($_POST['country'] ?? 'Sverige'),
  'address' => trim($_POST['address'] ?? ''),
  'description' => trim($_POST['description'] ?? ''),
  'gps_lat' => !empty($_POST['gps_lat']) ? floatval($_POST['gps_lat']) : null,
  'gps_lng' => !empty($_POST['gps_lng']) ? floatval($_POST['gps_lng']) : null,
  'active' => isset($_POST['active']) ? 1 : 0,
  ];

  try {
  if ($action === 'create') {
   $db->insert('venues', $venueData);
   $message = 'Venue skapad!';
   $messageType = 'success';
  } else {
   $id = intval($_POST['id']);
   $db->update('venues', $venueData, 'id = ?', [$id]);
   $message = 'Venue uppdaterad!';
   $messageType = 'success';
  }
  } catch (Exception $e) {
  $message = 'Ett fel uppstod: ' . $e->getMessage();
  $messageType = 'error';
  }
 }
 } elseif ($action === 'delete') {
 $id = intval($_POST['id']);
 try {
  $db->delete('venues', 'id = ?', [$id]);
  $message = 'Venue borttagen!';
  $messageType = 'success';
 } catch (Exception $e) {
  $message = 'Ett fel uppstod: ' . $e->getMessage();
  $messageType = 'error';
 }
 }
}

// Check if editing a venue
$editVenue = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
 $editVenue = $db->getRow("SELECT * FROM venues WHERE id = ?", [intval($_GET['edit'])]);
}

// Get venues with event count
$sql ="SELECT
 v.id,
 v.name,
 v.city,
 v.region,
 v.country,
 v.address,
 v.description,
 v.gps_lat,
 v.gps_lng,
 v.active,
 COUNT(DISTINCT e.id) as event_count
FROM venues v
LEFT JOIN events e ON v.id = e.venue_id
GROUP BY v.id
ORDER BY v.name";

try {
 $venues = $db->getAll($sql);
} catch (Exception $e) {
 $venues = [];
 $error = $e->getMessage();
}

$pageTitle = 'Venues';
$pageType = 'admin';
include __DIR__ . '/../includes/layout-header.php';
?>

<main class="main-content">
 <div class="container">
 <?php render_admin_header('Deltagare & Klubbar'); ?>
 <div class="mb-lg">
  <button type="button" class="btn btn--primary" onclick="openVenueModal()">
  <i data-lucide="plus"></i>
  Ny Venue
  </button>
 </div>

 <!-- Messages -->
 <?php if ($message): ?>
  <div class="alert alert-<?= $messageType ?> mb-lg">
  <i data-lucide="<?= $messageType === 'success' ? 'check-circle' : ($messageType === 'error' ? 'alert-circle' : 'info') ?>"></i>
  <?= h($message) ?>
  </div>
 <?php endif; ?>

 <?php if (isset($error)): ?>
  <div class="alert alert-danger mb-lg">
  <strong>Fel:</strong> <?= htmlspecialchars($error) ?>
  </div>
 <?php endif; ?>

 <!-- Venue Modal -->
 <div id="venueModal" class="gs-modal gs-display-none">
  <div class="gs-modal-overlay" onclick="closeVenueModal()"></div>
  <div class="gs-modal-content container-max-700">
  <div class="gs-modal-header">
   <h2 class="gs-modal-title" id="modalTitle">
   <i data-lucide="map-pin"></i>
   <span id="modalTitleText">Ny Venue</span>
   </h2>
   <button type="button" class="gs-modal-close" onclick="closeVenueModal()">
   <i data-lucide="x"></i>
   </button>
  </div>
  <form method="POST" id="venueForm">
   <?= csrf_field() ?>
   <input type="hidden" name="action" id="formAction" value="create">
   <input type="hidden" name="id" id="venueId" value="">

   <div class="gs-modal-body">
   <div class="grid grid-cols-1 gap-md">
    <!-- Name (Required) -->
    <div>
    <label for="name" class="label">
     <i data-lucide="map-pin"></i>
     Namn <span class="text-error">*</span>
    </label>
    <input
     type="text"
     id="name"
     name="name"
     class="input"
     required
     placeholder="T.ex. Järvsö Bergscykelpark"
    >
    </div>

    <!-- City and Region -->
    <div class="grid grid-cols-2 gap-md">
    <div>
     <label for="city" class="label">
     <i data-lucide="map-pin"></i>
     Stad
     </label>
     <input
     type="text"
     id="city"
     name="city"
     class="input"
     placeholder="T.ex. Järvsö"
     >
    </div>

    <div>
     <label for="region" class="label">
     <i data-lucide="map"></i>
     Region
     </label>
     <input
     type="text"
     id="region"
     name="region"
     class="input"
     placeholder="T.ex. Gävleborg"
     >
    </div>
    </div>

    <!-- Country -->
    <div>
    <label for="country" class="label">
     <i data-lucide="globe"></i>
     Land
    </label>
    <input
     type="text"
     id="country"
     name="country"
     class="input"
     value="Sverige"
     placeholder="Sverige"
    >
    </div>

    <!-- Address -->
    <div>
    <label for="address" class="label">
     <i data-lucide="home"></i>
     Adress
    </label>
    <input
     type="text"
     id="address"
     name="address"
     class="input"
     placeholder="T.ex. Järvsöbacken 10"
    >
    </div>

    <!-- Description -->
    <div>
    <label for="description" class="label">
     <i data-lucide="file-text"></i>
     Beskrivning / Information
    </label>
    <textarea
     id="description"
     name="description"
     class="input"
     rows="4"
     placeholder="Information om venue/bikepark..."
    ></textarea>
    </div>

    <!-- GPS Coordinates -->
    <div class="grid grid-cols-2 gap-md">
    <div>
     <label for="gps_lat" class="label">
     <i data-lucide="navigation"></i>
     GPS Latitud
     </label>
     <input
     type="number"
     step="0.0000001"
     id="gps_lat"
     name="gps_lat"
     class="input"
     placeholder="61.7218"
     >
    </div>

    <div>
     <label for="gps_lng" class="label">
     <i data-lucide="navigation"></i>
     GPS Longitud
     </label>
     <input
     type="number"
     step="0.0000001"
     id="gps_lng"
     name="gps_lng"
     class="input"
     placeholder="16.1506"
     >
    </div>
    </div>

    <!-- Active Status -->
    <div>
    <label class="checkbox-label">
     <input
     type="checkbox"
     id="active"
     name="active"
     class="checkbox"
     checked
     >
     <span>
     <i data-lucide="check-circle"></i>
     Aktiv
     </span>
    </label>
    </div>
   </div>
   </div>

   <div class="gs-modal-footer">
   <button type="button" class="btn btn--secondary" onclick="closeVenueModal()">
    Avbryt
   </button>
   <button type="submit" class="btn btn--primary" id="submitButton">
    <i data-lucide="check"></i>
    <span id="submitButtonText">Skapa</span>
   </button>
   </div>
  </form>
  </div>
 </div>

 <!-- Stats -->
 <div class="grid grid-cols-1 md-grid-cols-3 gap-lg mb-lg">
  <div class="stat-card">
  <i data-lucide="map-pin" class="icon-lg text-primary mb-md"></i>
  <div class="stat-number"><?= count($venues) ?></div>
  <div class="stat-label">Totalt venues</div>
  </div>
  <div class="stat-card">
  <i data-lucide="check-circle" class="icon-lg text-success mb-md"></i>
  <div class="stat-number">
   <?= count(array_filter($venues, fn($v) => $v['active'] == 1)) ?>
  </div>
  <div class="stat-label">Aktiva</div>
  </div>
  <div class="stat-card">
  <i data-lucide="calendar" class="icon-lg text-accent mb-md"></i>
  <div class="stat-number">
   <?= array_sum(array_column($venues, 'event_count')) ?>
  </div>
  <div class="stat-label">Totalt events</div>
  </div>
 </div>

 <!-- Venues Table -->
 <?php if (empty($venues)): ?>
  <div class="card">
  <div class="card-body text-center py-xl">
   <i data-lucide="map-pin" class="gs-icon-64-secondary"></i>
   <p class="text-secondary">Inga venues hittades</p>
   <button type="button" class="btn btn--primary mt-md" onclick="openVenueModal()">
   <i data-lucide="plus"></i>
   Skapa första venue
   </button>
  </div>
  </div>
 <?php else: ?>
  <div class="card">
  <div class="table-responsive">
   <table class="table">
   <thead>
    <tr>
    <th>
     <i data-lucide="map-pin"></i>
     Namn
    </th>
    <th>Stad/Region</th>
    <th>Land</th>
    <th>GPS</th>
    <th>
     <i data-lucide="calendar"></i>
     Events
    </th>
    <th>Status</th>
    <th class="table-col-w-150-right">Åtgärder</th>
    </tr>
   </thead>
   <tbody>
    <?php foreach ($venues as $venue): ?>
    <tr>
     <td>
     <strong><?= h($venue['name']) ?></strong>
     <?php if (!empty($venue['description'])): ?>
      <div class="text-xs text-secondary gs-mt-4px">
      <?= h(substr($venue['description'], 0, 80)) ?><?= strlen($venue['description']) > 80 ? '...' : '' ?>
      </div>
     <?php endif; ?>
     </td>
     <td>
     <?php if ($venue['city'] || $venue['region']): ?>
      <?= h($venue['city']) ?><?= $venue['city'] && $venue['region'] ? ', ' : '' ?><?= h($venue['region']) ?>
     <?php else: ?>
      -
     <?php endif; ?>
     </td>
     <td><?= h($venue['country'] ?? 'Sverige') ?></td>
     <td>
     <?php if ($venue['gps_lat'] && $venue['gps_lng']): ?>
      <a href="https://www.google.com/maps?q=<?= $venue['gps_lat'] ?>,<?= $venue['gps_lng'] ?>"
      target="_blank"
      class="link text-xs"
      title="Öppna i Google Maps">
      <i data-lucide="map" class="gs-icon-12"></i>
      Visa karta
      </a>
     <?php else: ?>
      -
     <?php endif; ?>
     </td>
     <td class="text-center">
     <?php if ($venue['event_count'] > 0): ?>
      <strong class="text-primary"><?= $venue['event_count'] ?></strong>
     <?php else: ?>
      <span class="text-secondary">0</span>
     <?php endif; ?>
     </td>
     <td>
     <?php if ($venue['active']): ?>
      <span class="badge badge-success">
      <i data-lucide="check-circle"></i>
      Aktiv
      </span>
     <?php else: ?>
      <span class="badge badge-secondary">Inaktiv</span>
     <?php endif; ?>
     </td>
     <td class="text-right">
     <div class="flex gap-sm gs-justify-end">
      <?php if ($venue['event_count'] > 0): ?>
      <a
       href="/admin/events.php?venue_id=<?= $venue['id'] ?>"
       class="btn btn--sm btn--secondary"
       title="Visa events"
      >
       <i data-lucide="calendar"></i>
       <?= $venue['event_count'] ?>
      </a>
      <?php endif; ?>
      <button
      type="button"
      class="btn btn--sm btn--secondary"
      onclick="editVenue(<?= $venue['id'] ?>)"
      title="Redigera"
      >
      <i data-lucide="edit"></i>
      </button>
      <button
      type="button"
      class="btn btn--sm btn--secondary btn-danger"
      onclick="deleteVenue(<?= $venue['id'] ?>, '<?= addslashes(h($venue['name'])) ?>')"
      title="Ta bort"
      >
      <i data-lucide="trash-2"></i>
      </button>
     </div>
     </td>
    </tr>
    <?php endforeach; ?>
   </tbody>
   </table>
  </div>
  </div>
 <?php endif; ?>
 </div>

 <script>
 // Open modal for creating new venue
 function openVenueModal() {
  document.getElementById('venueModal').style.display = 'flex';
  document.getElementById('venueForm').reset();
  document.getElementById('formAction').value = 'create';
  document.getElementById('venueId').value = '';
  document.getElementById('modalTitleText').textContent = 'Ny Venue';
  document.getElementById('submitButtonText').textContent = 'Skapa';
  document.getElementById('active').checked = true;
  document.getElementById('country').value = 'Sverige';

  // Re-initialize Lucide icons
  if (typeof lucide !== 'undefined') {
  lucide.createIcons();
  }
 }

 // Close modal
 function closeVenueModal() {
  document.getElementById('venueModal').style.display = 'none';
 }

 // Edit venue - reload page with edit parameter
 function editVenue(id) {
  window.location.href = `?edit=${id}`;
 }

 // Delete venue
 function deleteVenue(id, name) {
  if (!confirm(`Är du säker på att du vill ta bort"${name}"?`)) {
  return;
  }

  // Create form and submit
  const form = document.createElement('form');
  form.method = 'POST';
  form.innerHTML = `
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="id" value="${id}">
  `;
  document.body.appendChild(form);
  form.submit();
 }

 // Close modal when clicking outside
 document.addEventListener('DOMContentLoaded', function() {
  const modal = document.getElementById('venueModal');
  if (modal) {
  modal.addEventListener('click', function(e) {
   if (e.target === modal) {
   closeVenueModal();
   }
  });
  }

  // Handle edit mode from URL parameter
  <?php if ($editVenue): ?>
  // Populate form with venue data
  document.getElementById('formAction').value = 'update';
  document.getElementById('venueId').value = '<?= $editVenue['id'] ?>';
  document.getElementById('name').value = '<?= addslashes($editVenue['name']) ?>';
  document.getElementById('city').value = '<?= addslashes($editVenue['city'] ?? '') ?>';
  document.getElementById('region').value = '<?= addslashes($editVenue['region'] ?? '') ?>';
  document.getElementById('country').value = '<?= addslashes($editVenue['country'] ?? 'Sverige') ?>';
  document.getElementById('address').value = '<?= addslashes($editVenue['address'] ?? '') ?>';
  document.getElementById('description').value = '<?= addslashes($editVenue['description'] ?? '') ?>';
  document.getElementById('gps_lat').value = '<?= $editVenue['gps_lat'] ?? '' ?>';
  document.getElementById('gps_lng').value = '<?= $editVenue['gps_lng'] ?? '' ?>';
  document.getElementById('active').checked = <?= $editVenue['active'] ? 'true' : 'false' ?>;

  // Update modal title and button
  document.getElementById('modalTitleText').textContent = 'Redigera Venue';
  document.getElementById('submitButtonText').textContent = 'Uppdatera';

  // Open modal
  document.getElementById('venueModal').style.display = 'flex';

  // Re-initialize Lucide icons
  if (typeof lucide !== 'undefined') {
   lucide.createIcons();
  }
  <?php endif; ?>
 });

 // Close modal with Escape key
 document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') {
  closeVenueModal();
  }
 });
 </script>
 <?php render_admin_footer(); ?>
 </div>
</main>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>

<?php
/**
 * Event Ticketing Management
 * Configure ticketing settings for a specific event
 */

require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();

// Get event ID
$eventId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($eventId <= 0) {
 $_SESSION['flash_message'] = 'Välj ett event från ticketing-dashboarden';
 $_SESSION['flash_type'] = 'warning';
 header('Location: /admin/ticketing.php');
 exit;
}

// Fetch event
$event = $db->getRow("SELECT * FROM events WHERE id = ?", [$eventId]);

if (!$event) {
 $_SESSION['flash_message'] = 'Event hittades inte';
 $_SESSION['flash_type'] = 'error';
 header('Location: /admin/ticketing.php');
 exit;
}

// Initialize message
$message = '';
$messageType = 'info';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
 checkCsrf();
 $action = $_POST['action'] ?? '';

 if ($action === 'save_settings') {
 $enabled = isset($_POST['ticketing_enabled']) ? 1 : 0;
 $deadlineDays = intval($_POST['ticket_deadline_days'] ?? 7);
 $wooProductId = trim($_POST['woo_product_id'] ?? '');

 $db->update('events', [
 'ticketing_enabled' => $enabled,
 'ticket_deadline_days' => $deadlineDays,
 'woo_product_id' => $wooProductId ?: null
 ], 'id = ?', [$eventId]);

 $event = $db->getRow("SELECT * FROM events WHERE id = ?", [$eventId]);
 $message = 'Inställningar sparade!';
 $messageType = 'success';
 }
}

// Get classes for pricing
$classes = $db->getAll("SELECT id, name, display_name FROM classes ORDER BY sort_order ASC");

$pageTitle = 'Ticketing - ' . $event['name'];
$pageType = 'admin';
include __DIR__ . '/../includes/layout-header.php';
?>

<main class="main-content">
 <div class="container">
 <!-- Header -->
 <div class="card mb-lg">
 <div class="card-body">
 <div class="flex justify-between items-center">
  <div>
  <h1 class="">
  <i data-lucide="ticket"></i>
  <?= htmlspecialchars($event['name']) ?>
  </h1>
  <p class="text-secondary text-sm">
  <?= date('d M Y', strtotime($event['date'])) ?> - <?= htmlspecialchars($event['location'] ?? '') ?>
  </p>
  </div>
  <a href="/admin/ticketing.php" class="btn btn--secondary">
  <i data-lucide="arrow-left"></i>
  Tillbaka
  </a>
 </div>
 </div>
 </div>

 <?php if ($message): ?>
 <div class="alert alert-<?= $messageType ?> mb-lg">
 <?= htmlspecialchars($message) ?>
 </div>
 <?php endif; ?>

 <!-- Settings Form -->
 <div class="card">
 <div class="card-header">
 <h2 class="">
  <i data-lucide="settings"></i>
  Ticketing-inställningar
 </h2>
 </div>
 <div class="card-body">
 <form method="POST">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save_settings">

  <div class="grid grid-cols-1 md-grid-cols-2 gap-md">
  <!-- Enable Ticketing -->
  <div class="form-group">
  <label class="checkbox-label">
  <input type="checkbox" name="ticketing_enabled" value="1"
   <?= ($event['ticketing_enabled'] ?? 0) ? 'checked' : '' ?>>
  <span>Aktivera ticketing för detta event</span>
  </label>
  </div>

  <!-- Deadline Days -->
  <div class="form-group">
  <label class="label">Anmälningsfrist (dagar före)</label>
  <input type="number" name="ticket_deadline_days" class="input"
  value="<?= $event['ticket_deadline_days'] ?? 7 ?>" min="0" max="90">
  <small class="text-secondary">Antal dagar före event då anmälan stänger</small>
  </div>

  <!-- WooCommerce Product ID -->
  <div class="form-group gs-col-span-2">
  <label class="label">WooCommerce Product ID</label>
  <input type="text" name="woo_product_id" class="input"
  value="<?= htmlspecialchars($event['woo_product_id'] ?? '') ?>"
  placeholder="T.ex. 12345">
  <small class="text-secondary">
  Product ID från WooCommerce för köp-knappen på eventsidan
  </small>
  </div>
  </div>

  <div class="mt-lg">
  <button type="submit" class="btn btn--primary">
  <i data-lucide="save"></i>
  Spara inställningar
  </button>
  </div>
 </form>
 </div>
 </div>

 <!-- Current Status -->
 <div class="card mt-lg">
 <div class="card-header">
 <h2 class="">
  <i data-lucide="info"></i>
  Nuvarande status
 </h2>
 </div>
 <div class="card-body">
 <div class="grid grid-cols-1 md-grid-cols-3 gap-md">
  <div>
  <strong>Ticketing:</strong><br>
  <?php if ($event['ticketing_enabled'] ?? 0): ?>
  <span class="badge badge-success">Aktiverat</span>
  <?php else: ?>
  <span class="badge badge-secondary">Inaktiverat</span>
  <?php endif; ?>
  </div>
  <div>
  <strong>Anmälningsfrist:</strong><br>
  <?= $event['ticket_deadline_days'] ?? 7 ?> dagar före event
  </div>
  <div>
  <strong>WooCommerce ID:</strong><br>
  <?= $event['woo_product_id'] ? htmlspecialchars($event['woo_product_id']) : '<span class="text-secondary">Ej satt</span>' ?>
  </div>
 </div>
 </div>
 </div>
 </div>
</main>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>

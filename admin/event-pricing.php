<?php
/**
 * Admin Event Pricing Setup
 * Configure ticket pricing for events
 */

require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();

// Get event ID
$eventId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($eventId <= 0) {
 $_SESSION['message'] = 'Ogiltigt event-ID';
 $_SESSION['messageType'] = 'error';
 header('Location: /admin/events.php');
 exit;
}

// Fetch event data
$event = $db->getRow("SELECT * FROM events WHERE id = ?", [$eventId]);

if (!$event) {
 $_SESSION['message'] = 'Event hittades inte';
 $_SESSION['messageType'] = 'error';
 header('Location: /admin/events.php');
 exit;
}

// Initialize message variables
$message = '';
$messageType = 'info';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
 checkCsrf();

 $action = $_POST['action'] ?? '';

 if ($action === 'save_pricing') {
 // Save pricing rules
 $classIds = $_POST['class_id'] ?? [];
 $basePrices = $_POST['base_price'] ?? [];
 $earlyBirdDiscounts = $_POST['early_bird_discount'] ?? [];
 $earlyBirdEndDates = $_POST['early_bird_end_date'] ?? [];

 $saved = 0;
 $errors = 0;

 foreach ($classIds as $index => $classId) {
 $basePrice = floatval($basePrices[$index] ?? 0);
 $earlyBirdDiscount = floatval($earlyBirdDiscounts[$index] ?? 0);
 $earlyBirdEndDate = trim($earlyBirdEndDates[$index] ?? '');

 // Only save if base price is set
 if ($basePrice > 0) {
 // Check if rule already exists
 $existing = $db->getRow("
  SELECT id FROM event_pricing_rules
  WHERE event_id = ? AND class_id = ?
 ", [$eventId, $classId]);

 if ($existing) {
  // Update existing
  $result = $db->execute("
  UPDATE event_pricing_rules
  SET base_price = ?,
  early_bird_discount_percent = ?,
  early_bird_end_date = ?,
  updated_at = NOW()
  WHERE id = ?
 ", [$basePrice, $earlyBirdDiscount, $earlyBirdEndDate ?: null, $existing['id']]);
 } else {
  // Insert new
  $result = $db->execute("
  INSERT INTO event_pricing_rules
  (event_id, class_id, base_price, early_bird_discount_percent, early_bird_end_date, created_at)
  VALUES (?, ?, ?, ?, ?, NOW())
 ", [$eventId, $classId, $basePrice, $earlyBirdDiscount, $earlyBirdEndDate ?: null]);
 }

 if ($result) {
  $saved++;
 } else {
  $errors++;
 }
 } else {
 // Delete rule if price is 0 or empty
 $db->execute("
  DELETE FROM event_pricing_rules
  WHERE event_id = ? AND class_id = ?
 ", [$eventId, $classId]);
 }
 }

 if ($errors > 0) {
 $message ="Sparat $saved priser, men $errors fel uppstod";
 $messageType = 'warning';
 } else {
 $message ="Sparat $saved priser";
 $messageType = 'success';
 }
 } elseif ($action === 'enable_ticketing') {
 // Enable/disable ticketing for event
 $enabled = isset($_POST['ticketing_enabled']) ? 1 : 0;
 $deadlineDays = intval($_POST['ticket_deadline_days'] ?? 7);
 $wooProductId = intval($_POST['woo_product_id'] ?? 0) ?: null;

 $db->execute("
 UPDATE events
 SET ticketing_enabled = ?,
 ticket_deadline_days = ?,
 woo_product_id = ?
 WHERE id = ?
", [$enabled, $deadlineDays, $wooProductId, $eventId]);

 // Refresh event data
 $event = $db->getRow("SELECT * FROM events WHERE id = ?", [$eventId]);

 $message = $enabled ? 'Biljettförsäljning aktiverad' : 'Biljettförsäljning avaktiverad';
 $messageType = 'success';
 }
}

// Fetch all classes
$classes = $db->getAll("
 SELECT id, name, display_name, sort_order
 FROM classes
 ORDER BY sort_order ASC
");

// Fetch existing pricing rules for this event
$existingRules = $db->getAll("
 SELECT * FROM event_pricing_rules
 WHERE event_id = ?
", [$eventId]);

// Create a map for quick lookup
$rulesMap = [];
foreach ($existingRules as $rule) {
 $rulesMap[$rule['class_id']] = $rule;
}

// Calculate default early-bird end date (event date - 20 days)
$eventDate = new DateTime($event['date']);
$defaultEarlyBirdEnd = clone $eventDate;
$defaultEarlyBirdEnd->modify('-20 days');

$pageTitle = 'Prissättning - ' . $event['name'];
$pageType = 'admin';
include __DIR__ . '/../includes/layout-header.php';
?>

<main class="main-content">
 <div class="container">
 <!-- Header -->
 <div class="card mb-lg">
 <div class="card-body">
 <div class="mb-md">
  <a href="/admin/events.php" class="btn btn--secondary btn--sm">
  <i data-lucide="arrow-left" class="icon-md"></i>
  Tillbaka till events
  </a>
 </div>

 <h1 class="text-primary mb-sm">
  <i data-lucide="credit-card" class="icon-lg"></i>
  Prissättning
 </h1>
 <p class="text-secondary">
  <strong><?= h($event['name']) ?></strong> - <?= date('d M Y', strtotime($event['date'])) ?>
 </p>
 </div>
 </div>

 <?php if ($message): ?>
 <div class="alert alert-<?= $messageType ?> mb-lg">
 <?= h($message) ?>
 </div>
 <?php endif; ?>

 <!-- Ticketing Settings -->
 <div class="card mb-lg">
 <div class="card-header">
 <h2 class="text-primary">
  <i data-lucide="settings" class="icon-md"></i>
  Ticketing-inställningar
 </h2>
 </div>
 <div class="card-body">
 <form method="POST">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="enable_ticketing">

  <div class="grid grid-cols-1 md-grid-cols-3 gap-lg">
  <div class="form-group">
  <label class="checkbox">
  <input type="checkbox"
   name="ticketing_enabled"
   value="1"
   <?= !empty($event['ticketing_enabled']) ? 'checked' : '' ?>>
  <span>Aktivera biljettförsäljning</span>
  </label>
  </div>

  <div class="form-group">
  <label class="label">Sista försäljningsdag (dagar före event)</label>
  <input type="number"
   name="ticket_deadline_days"
   class="input"
   value="<?= h($event['ticket_deadline_days'] ?? 7) ?>"
   min="0"
   max="365">
  <span class="text-xs text-secondary">
  Stänger <?= $event['ticket_deadline_days'] ?? 7 ?> dagar före eventdatum
  </span>
  </div>

  <div class="form-group">
  <label class="label">WooCommerce Produkt-ID</label>
  <input type="number"
   name="woo_product_id"
   class="input"
   value="<?= h($event['woo_product_id'] ?? '') ?>"
   placeholder="T.ex. 1234">
  <span class="text-xs text-secondary">
  Produkt-ID från WooCommerce-butiken
  </span>
  </div>
  </div>

  <div class="mt-md">
  <button type="submit" class="btn btn--primary">
  <i data-lucide="save" class="icon-sm"></i>
  Spara inställningar
  </button>
  </div>
 </form>
 </div>
 </div>

 <!-- Pricing Rules -->
 <div class="card mb-lg">
 <div class="card-header">
 <h2 class="text-primary">
  <i data-lucide="tag" class="icon-md"></i>
  Priser per klass
 </h2>
 </div>
 <div class="card-body">
 <form method="POST">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="save_pricing">

  <div class="table-responsive">
  <table class="table">
  <thead>
  <tr>
   <th>Klass</th>
   <th>Ordinarie pris (kr)</th>
   <th>Early-bird rabatt (%)</th>
   <th>Early-bird t.o.m.</th>
   <th>Status</th>
  </tr>
  </thead>
  <tbody>
  <?php foreach ($classes as $class): ?>
   <?php
   $rule = $rulesMap[$class['id']] ?? null;
   $hasPrice = $rule && $rule['base_price'] > 0;
   ?>
   <tr>
   <td>
   <input type="hidden" name="class_id[]" value="<?= $class['id'] ?>">
   <strong><?= h($class['display_name']) ?></strong>
   <span class="text-secondary text-sm">(<?= h($class['name']) ?>)</span>
   </td>
   <td>
   <input type="number"
    name="base_price[]"
    class="input input-sm"
    value="<?= $rule ? h($rule['base_price']) : '' ?>"
    placeholder="0"
    min="0"
    step="10"
    style="width: 100px;">
   </td>
   <td>
   <input type="number"
    name="early_bird_discount[]"
    class="input input-sm"
    value="<?= $rule ? h($rule['early_bird_discount_percent']) : '20' ?>"
    placeholder="20"
    min="0"
    max="100"
    style="width: 80px;">
   </td>
   <td>
   <input type="date"
    name="early_bird_end_date[]"
    class="input input-sm"
    value="<?= $rule && $rule['early_bird_end_date'] ? h($rule['early_bird_end_date']) : $defaultEarlyBirdEnd->format('Y-m-d') ?>"
    style="width: 140px;">
   </td>
   <td>
   <?php if ($hasPrice): ?>
   <span class="badge badge-success badge-sm">Konfigurerad</span>
   <?php else: ?>
   <span class="badge badge-secondary badge-sm">Ej satt</span>
   <?php endif; ?>
   </td>
   </tr>
  <?php endforeach; ?>
  </tbody>
  </table>
  </div>

  <div class="mt-lg">
  <button type="submit" class="btn btn--primary">
  <i data-lucide="save" class="icon-sm"></i>
  Spara alla priser
  </button>
  </div>
 </form>
 </div>
 </div>

 <!-- Quick Actions -->
 <div class="card">
 <div class="card-header">
 <h2 class="text-primary">
  <i data-lucide="zap" class="icon-md"></i>
  Nästa steg
 </h2>
 </div>
 <div class="card-body">
 <div class="flex gap-md flex-wrap">
  <a href="/admin/event-tickets.php?id=<?= $eventId ?>" class="btn btn--secondary">
  <i data-lucide="ticket" class="icon-sm"></i>
  Generera biljetter
  </a>
  <a href="/event-results.php?id=<?= $eventId ?>&tab=biljetter"
  class="btn btn--secondary"
  target="_blank">
  <i data-lucide="external-link" class="icon-sm"></i>
  Visa publik sida
  </a>
  <a href="/admin/event-edit.php?id=<?= $eventId ?>" class="btn btn--secondary">
  <i data-lucide="edit" class="icon-sm"></i>
  Redigera event
  </a>
 </div>
 </div>
 </div>
 </div>
</main>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>

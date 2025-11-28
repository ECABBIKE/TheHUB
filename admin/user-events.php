<?php
/**
 * Manage Promotor Event Assignments
 * Only accessible by super_admin
 */
require_once __DIR__ . '/../config.php';
require_admin();

// Only super_admin can access this page
if (!hasRole('super_admin')) {
 http_response_code(403);
 die('Access denied: Only super administrators can manage user events.');
}

$db = getDB();

// Get user ID
$id = isset($_GET['id']) && is_numeric($_GET['id']) ? intval($_GET['id']) : null;

if (!$id) {
 header('Location: /admin/users.php');
 exit;
}

// Fetch user
$user = $db->getRow("SELECT * FROM admin_users WHERE id = ?", [$id]);

if (!$user || $user['role'] !== 'promotor') {
 header('Location: /admin/users.php');
 exit;
}

$message = '';
$messageType = 'info';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
 checkCsrf();

 $action = $_POST['action'] ?? '';

 if ($action === 'add') {
 $eventId = isset($_POST['event_id']) ? intval($_POST['event_id']) : 0;
 $canEdit = isset($_POST['can_edit']) ? 1 : 0;
 $canResults = isset($_POST['can_manage_results']) ? 1 : 0;
 $canRegistrations = isset($_POST['can_manage_registrations']) ? 1 : 0;

 if ($eventId) {
 try {
 // Check if already exists
 $existing = $db->getRow(
 "SELECT id FROM promotor_events WHERE user_id = ? AND event_id = ?",
  [$id, $eventId]
 );

 $currentAdmin = getCurrentAdmin();

 if ($existing) {
  // Update existing
  $db->update('promotor_events', [
  'can_edit' => $canEdit,
  'can_manage_results' => $canResults,
  'can_manage_registrations' => $canRegistrations,
  'granted_by' => $currentAdmin['id']
  ], 'id = ?', [$existing['id']]);
  $message = 'Event-behörighet uppdaterad!';
 } else {
  // Insert new
  $db->insert('promotor_events', [
  'user_id' => $id,
  'event_id' => $eventId,
  'can_edit' => $canEdit,
  'can_manage_results' => $canResults,
  'can_manage_registrations' => $canRegistrations,
  'granted_by' => $currentAdmin['id']
  ]);
  $message = 'Event tillagt!';
 }
 $messageType = 'success';
 } catch (Exception $e) {
 $message = 'Ett fel uppstod: ' . $e->getMessage();
 $messageType = 'error';
 }
 }
 } elseif ($action === 'update') {
 $eventId = isset($_POST['event_id']) ? intval($_POST['event_id']) : 0;
 $canEdit = isset($_POST['can_edit']) ? 1 : 0;
 $canResults = isset($_POST['can_manage_results']) ? 1 : 0;
 $canRegistrations = isset($_POST['can_manage_registrations']) ? 1 : 0;

 if ($eventId) {
 try {
 $db->update('promotor_events', [
  'can_edit' => $canEdit,
  'can_manage_results' => $canResults,
  'can_manage_registrations' => $canRegistrations
 ], 'user_id = ? AND event_id = ?', [$id, $eventId]);
 $message = 'Behörigheter uppdaterade!';
 $messageType = 'success';
 } catch (Exception $e) {
 $message = 'Ett fel uppstod: ' . $e->getMessage();
 $messageType = 'error';
 }
 }
 } elseif ($action === 'remove') {
 $eventId = isset($_POST['event_id']) ? intval($_POST['event_id']) : 0;
 if ($eventId) {
 try {
 $db->delete('promotor_events', 'user_id = ? AND event_id = ?', [$id, $eventId]);
 $message = 'Event borttaget!';
 $messageType = 'success';
 } catch (Exception $e) {
 $message = 'Ett fel uppstod: ' . $e->getMessage();
 $messageType = 'error';
 }
 }
 } elseif ($action === 'add_series') {
 $seriesId = isset($_POST['series_id']) ? intval($_POST['series_id']) : 0;
 $canEdit = isset($_POST['can_edit']) ? 1 : 0;
 $canResults = isset($_POST['can_manage_results']) ? 1 : 0;
 $canRegistrations = isset($_POST['can_manage_registrations']) ? 1 : 0;

 if ($seriesId) {
 try {
 $currentAdmin = getCurrentAdmin();
 $seriesEvents = $db->getAll("SELECT id FROM events WHERE series_id = ?", [$seriesId]);
 $addedCount = 0;

 foreach ($seriesEvents as $event) {
  $existing = $db->getRow(
 "SELECT id FROM promotor_events WHERE user_id = ? AND event_id = ?",
  [$id, $event['id']]
  );

  if (!$existing) {
  $db->insert('promotor_events', [
  'user_id' => $id,
  'event_id' => $event['id'],
  'can_edit' => $canEdit,
  'can_manage_results' => $canResults,
  'can_manage_registrations' => $canRegistrations,
  'granted_by' => $currentAdmin['id']
  ]);
  $addedCount++;
  }
 }
 $message ="$addedCount event från serien tillagda!";
 $messageType = 'success';
 } catch (Exception $e) {
 $message = 'Ett fel uppstod: ' . $e->getMessage();
 $messageType = 'error';
 }
 }
 }
}

// Get user's assigned events
$assignedEvents = $db->getAll("
 SELECT pe.*, e.name as event_name, e.date as event_date, e.location,
 s.name as series_name,
 au.full_name as granted_by_name
 FROM promotor_events pe
 JOIN events e ON pe.event_id = e.id
 LEFT JOIN series s ON e.series_id = s.id
 LEFT JOIN admin_users au ON pe.granted_by = au.id
 WHERE pe.user_id = ?
 ORDER BY e.date DESC
", [$id]);

$assignedEventIds = array_column($assignedEvents, 'event_id');

// Get available events (not already assigned)
$availableEvents = $db->getAll("
 SELECT e.id, e.name, e.date, e.location, s.name as series_name
 FROM events e
 LEFT JOIN series s ON e.series_id = s.id
 WHERE e.id NOT IN (SELECT event_id FROM promotor_events WHERE user_id = ?)
 ORDER BY e.date DESC
 LIMIT 100
", [$id]);

// Get all series for bulk add
$allSeries = $db->getAll("
 SELECT s.id, s.name, COUNT(e.id) as event_count
 FROM series s
 LEFT JOIN events e ON s.id = e.series_id
 GROUP BY s.id
 ORDER BY s.name
");

$pageTitle = 'Hantera Promotor Events';
$pageType = 'admin';
include __DIR__ . '/../includes/layout-header.php';
?>

<main class="main-content">
 <div class="container">
 <!-- Header -->
 <div class="flex items-center justify-between mb-lg">
 <div>
 <h1 class="">
  <i data-lucide="calendar-check"></i>
  Event-tilldelning
 </h1>
 <p class="text-secondary">
  Hantera events för <strong><?= h($user['full_name'] ?: $user['username']) ?></strong>
 </p>
 </div>
 <a href="/admin/user-edit.php?id=<?= $id ?>" class="btn btn--secondary">
 <i data-lucide="arrow-left"></i>
 Tillbaka
 </a>
 </div>

 <!-- Message -->
 <?php if ($message): ?>
 <div class="alert alert-<?= h($messageType) ?> mb-lg">
 <i data-lucide="<?= $messageType === 'success' ? 'check-circle' : 'alert-circle' ?>"></i>
 <?= h($message) ?>
 </div>
 <?php endif; ?>

 <div class="grid grid-cols-1 gs-lg-grid-cols-2 gap-lg">
 <!-- Assigned Events -->
 <div class="card">
 <div class="card-header">
  <h2 class="">
  <i data-lucide="check-circle"></i>
  Tilldelade events (<?= count($assignedEvents) ?>)
  </h2>
 </div>
 <div class="card-body gs-p-0">
  <?php if (empty($assignedEvents)): ?>
  <div class="text-center text-secondary py-xl">
  <i data-lucide="calendar-x" class="gs-icon-xl mb-md"></i>
  <p>Inga events tilldelade</p>
  </div>
  <?php else: ?>
  <div class="table-container">
  <table class="table">
  <thead>
   <tr>
   <th>Event</th>
   <th class="text-center">Redigera</th>
   <th class="text-center">Resultat</th>
   <th class="text-center">Anmälningar</th>
   <th></th>
   </tr>
  </thead>
  <tbody>
   <?php foreach ($assignedEvents as $event): ?>
   <tr>
   <td>
   <div class="font-medium"><?= h($event['event_name']) ?></div>
   <div class="text-xs text-secondary">
    <?= date('Y-m-d', strtotime($event['event_date'])) ?>
    <?php if ($event['series_name']): ?>
    • <?= h($event['series_name']) ?>
    <?php endif; ?>
   </div>
   </td>
   <form method="POST" class="event-perm-form" data-event="<?= $event['event_id'] ?>">
   <?= csrf_field() ?>
   <input type="hidden" name="action" value="update">
   <input type="hidden" name="event_id" value="<?= $event['event_id'] ?>">
   <td class="text-center">
    <input type="checkbox" name="can_edit" value="1" <?= $event['can_edit'] ? 'checked' : '' ?> onchange="this.form.submit()" class="checkbox-input">
   </td>
   <td class="text-center">
    <input type="checkbox" name="can_manage_results" value="1" <?= $event['can_manage_results'] ? 'checked' : '' ?> onchange="this.form.submit()" class="checkbox-input">
   </td>
   <td class="text-center">
    <input type="checkbox" name="can_manage_registrations" value="1" <?= $event['can_manage_registrations'] ? 'checked' : '' ?> onchange="this.form.submit()" class="checkbox-input">
   </td>
   </form>
   <td class="text-right">
   <form method="POST" style="display: inline;">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="remove">
    <input type="hidden" name="event_id" value="<?= $event['event_id'] ?>">
    <button type="submit" class="btn btn--sm btn-error" onclick="return confirm('Ta bort detta event?')">
    <i data-lucide="x"></i>
    </button>
   </form>
   </td>
   </tr>
   <?php endforeach; ?>
  </tbody>
  </table>
  </div>
  <?php endif; ?>
 </div>
 </div>

 <!-- Add Event -->
 <div class="card">
 <div class="card-header">
  <h2 class="">
  <i data-lucide="plus-circle"></i>
  Lägg till event
  </h2>
 </div>
 <div class="card-body">
  <?php if (empty($availableEvents)): ?>
  <div class="text-center text-secondary py-lg">
  <i data-lucide="check" class="gs-icon-xl mb-md"></i>
  <p>Alla events är redan tilldelade</p>
  </div>
  <?php else: ?>
  <form method="POST">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="add">

  <div class="mb-md">
  <label for="event_id" class="label">Välj event</label>
  <select id="event_id" name="event_id" class="input" required>
   <option value="">-- Välj event --</option>
   <?php foreach ($availableEvents as $event): ?>
   <option value="<?= $event['id'] ?>">
   <?= h($event['name']) ?> (<?= date('Y-m-d', strtotime($event['date'])) ?>)
   <?= $event['series_name'] ? ' - ' . h($event['series_name']) : '' ?>
   </option>
   <?php endforeach; ?>
  </select>
  </div>

  <div class="mb-md">
  <label class="label">Behörigheter</label>
  <div class="flex flex-col gap-sm">
   <label class="checkbox flex items-center gap-sm">
   <input type="checkbox" name="can_edit" value="1" checked>
   <span>Kan redigera eventinformation</span>
   </label>
   <label class="checkbox flex items-center gap-sm">
   <input type="checkbox" name="can_manage_results" value="1" checked>
   <span>Kan hantera resultat</span>
   </label>
   <label class="checkbox flex items-center gap-sm">
   <input type="checkbox" name="can_manage_registrations" value="1" checked>
   <span>Kan hantera anmälningar</span>
   </label>
  </div>
  </div>

  <button type="submit" class="btn btn--primary w-full">
  <i data-lucide="plus"></i>
  Lägg till event
  </button>
  </form>
  <?php endif; ?>
 </div>
 </div>
 </div>

 <!-- Add Series -->
 <div class="card mt-lg">
 <div class="card-header">
 <h2 class="">
  <i data-lucide="layers"></i>
  Lägg till hel serie
 </h2>
 </div>
 <div class="card-body">
 <form method="POST">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="add_series">

  <div class="grid grid-cols-1 md-grid-cols-2 gap-md">
  <div>
  <label for="series_id" class="label">Välj serie</label>
  <select id="series_id" name="series_id" class="input" required>
  <option value="">-- Välj serie --</option>
  <?php foreach ($allSeries as $series): ?>
   <option value="<?= $series['id'] ?>">
   <?= h($series['name']) ?> (<?= $series['event_count'] ?> events)
   </option>
  <?php endforeach; ?>
  </select>
  </div>
  <div class="flex gs-items-end">
  <button type="submit" class="btn btn--secondary">
  <i data-lucide="plus"></i>
  Lägg till alla event
  </button>
  </div>
  </div>

  <div class="mt-md">
  <label class="label">Behörigheter för alla events</label>
  <div class="flex gap-lg flex-wrap">
  <label class="checkbox flex items-center gap-sm">
  <input type="checkbox" name="can_edit" value="1" checked>
  <span>Redigera</span>
  </label>
  <label class="checkbox flex items-center gap-sm">
  <input type="checkbox" name="can_manage_results" value="1" checked>
  <span>Resultat</span>
  </label>
  <label class="checkbox flex items-center gap-sm">
  <input type="checkbox" name="can_manage_registrations" value="1" checked>
  <span>Anmälningar</span>
  </label>
  </div>
  </div>
 </form>
 </div>
 </div>

 <!-- Help Text -->
 <div class="card mt-lg">
 <div class="card-body">
 <h3 class="font-medium mb-sm">Om behörigheter</h3>
 <ul class="text-sm text-secondary" style="list-style: disc; padding-left: 1.5rem;">
  <li><strong>Redigera</strong> - Kan ändra eventinformation (namn, datum, plats, beskrivning, etc.)</li>
  <li><strong>Resultat</strong> - Kan importera, redigera och publicera resultat</li>
  <li><strong>Anmälningar</strong> - Kan hantera anmälningar, deltagare och startlistor</li>
 </ul>
 <p class="text-sm text-secondary mt-md">
  <i data-lucide="info" class="icon-sm"></i>
  Klicka på kryssrutorna i tabellen för att snabbt ändra behörigheter.
 </p>
 </div>
 </div>
 </div>
</main>

<style>
.checkbox-input {
 width: 18px;
 height: 18px;
 cursor: pointer;
}
</style>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>

<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/series-points.php'; // Series-specific points (separate from ranking)
require_admin();

$db = getDB();
$current_admin = get_current_admin();

// Get series ID
$seriesId = isset($_GET['series_id']) && is_numeric($_GET['series_id']) ? intval($_GET['series_id']) : null;

if (!$seriesId) {
 redirect('/admin/series.php');
}

// Get series info
$series = $db->getRow("SELECT * FROM series WHERE id = ?", [$seriesId]);

if (!$series) {
 redirect('/admin/series.php');
}

$message = '';
$messageType = 'info';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
 checkCsrf();

 $action = $_POST['action'] ?? '';

 if ($action === 'add_event') {
 $eventId = intval($_POST['event_id']);
 $templateId = !empty($_POST['template_id']) ? intval($_POST['template_id']) : null;

 // Check if already exists
 $existing = $db->getRow(
"SELECT id FROM series_events WHERE series_id = ? AND event_id = ?",
 [$seriesId, $eventId]
 );

 if ($existing) {
 $message = 'Detta event finns redan i serien';
 $messageType = 'error';
 } else {
 // Get max sort order
 $maxOrder = $db->getRow(
 "SELECT MAX(sort_order) as max_order FROM series_events WHERE series_id = ?",
 [$seriesId]
 );
 $sortOrder = ($maxOrder['max_order'] ?? 0) + 1;

 $db->insert('series_events', [
 'series_id' => $seriesId,
 'event_id' => $eventId,
 'template_id' => $templateId,
 'sort_order' => $sortOrder
 ]);

 // If a template was specified, calculate series points
 // NOTE: This only affects series_results, NOT results.points (ranking)
 if ($templateId) {
 $stats = recalculateSeriesEventPoints($db, $seriesId, $eventId);
 $message ="Event tillagt i serien! {$stats['inserted']} seriepoäng beräknade.";
 } else {
 $message = 'Event tillagt i serien! (ingen poängmall vald)';
 }
 $messageType = 'success';
 }
 } elseif ($action === 'update_template') {
 $seriesEventId = intval($_POST['series_event_id']);
 $templateId = !empty($_POST['template_id']) ? intval($_POST['template_id']) : null;

 // Get the event_id for this series_event
 $seriesEvent = $db->getRow(
"SELECT event_id FROM series_events WHERE id = ? AND series_id = ?",
 [$seriesEventId, $seriesId]
 );

 if ($seriesEvent) {
 $eventId = $seriesEvent['event_id'];

 // Update series_events template
 $db->update('series_events', [
 'template_id' => $templateId
 ], 'id = ? AND series_id = ?', [$seriesEventId, $seriesId]);

 // Recalculate series points using new template
 // NOTE: This only affects series_results, NOT results.points (ranking stays unchanged!)
 $stats = recalculateSeriesEventPoints($db, $seriesId, $eventId);

 $totalChanged = $stats['inserted'] + $stats['updated'];
 $message ="Poängmall uppdaterad! {$totalChanged} seriepoäng omräknade.";
 $messageType = 'success';
 } else {
 $message = 'Kunde inte hitta eventet';
 $messageType = 'error';
 }
 } elseif ($action === 'bulk_update_templates') {
 $seriesEventIds = $_POST['series_event_ids'] ?? [];
 $templateId = !empty($_POST['bulk_template_id']) ? intval($_POST['bulk_template_id']) : null;

 if (empty($seriesEventIds)) {
  $message = 'Inga event valda';
  $messageType = 'error';
 } else {
  $updatedCount = 0;
  $recalculatedCount = 0;

  foreach ($seriesEventIds as $seriesEventId) {
   $seriesEventId = intval($seriesEventId);

   // Get the event_id for this series_event
   $seriesEvent = $db->getRow(
    "SELECT event_id FROM series_events WHERE id = ? AND series_id = ?",
    [$seriesEventId, $seriesId]
   );

   if ($seriesEvent) {
    $eventId = $seriesEvent['event_id'];

    // Update series_events template
    $db->update('series_events', [
     'template_id' => $templateId
    ], 'id = ? AND series_id = ?', [$seriesEventId, $seriesId]);

    $updatedCount++;

    // Recalculate series points using new template
    if ($templateId) {
     $stats = recalculateSeriesEventPoints($db, $seriesId, $eventId);
     $recalculatedCount += $stats['inserted'] + $stats['updated'];
    }
   }
  }

  $message = "{$updatedCount} event uppdaterade! {$recalculatedCount} seriepoäng omräknade.";
  $messageType = 'success';
 }
 } elseif ($action === 'remove_event') {
 $seriesEventId = intval($_POST['series_event_id']);

 $db->delete('series_events', 'id = ? AND series_id = ?', [$seriesEventId, $seriesId]);

 $message = 'Event borttaget från serien!';
 $messageType = 'success';
 } elseif ($action === 'update_order') {
 $orders = $_POST['orders'] ?? [];

 foreach ($orders as $seriesEventId => $sortOrder) {
 $db->update('series_events', [
 'sort_order' => intval($sortOrder)
 ], 'id = ? AND series_id = ?', [intval($seriesEventId), $seriesId]);
 }

 $message = 'Ordning uppdaterad!';
 $messageType = 'success';
 } elseif ($action === 'update_count_best') {
 $countBest = $_POST['count_best_results'];
 $countBestValue = ($countBest === '' || $countBest === 'null') ? null : intval($countBest);

 try {
 $db->update('series', [
 'count_best_results' => $countBestValue
 ], 'id = ?', [$seriesId]);

 // Refresh series data
 $series = $db->getRow("SELECT * FROM series WHERE id = ?", [$seriesId]);

 if ($countBestValue === null) {
 $message = 'Alla resultat räknas nu';
 } else {
 $message ="Räknar nu de {$countBestValue} bästa resultaten";
 }
 $messageType = 'success';
 } catch (Exception $e) {
 // Show actual error for debugging
 $message = 'Fel: ' . $e->getMessage() . ' - Kör migration 018_add_count_best_results.sql';
 $messageType = 'error';
 }
 } elseif ($action === 'move_up' || $action === 'move_down') {
 $seriesEventId = intval($_POST['series_event_id']);

 // Get current event and all events sorted
 $allEvents = $db->getAll("
 SELECT id, sort_order FROM series_events
 WHERE series_id = ?
 ORDER BY sort_order ASC
", [$seriesId]);

 // Find current position
 $currentIndex = -1;
 foreach ($allEvents as $index => $event) {
 if ($event['id'] == $seriesEventId) {
 $currentIndex = $index;
 break;
 }
 }

 if ($currentIndex >= 0) {
 $swapIndex = $action === 'move_up' ? $currentIndex - 1 : $currentIndex + 1;

 if ($swapIndex >= 0 && $swapIndex < count($allEvents)) {
 // Swap sort_order values
 $currentOrder = $allEvents[$currentIndex]['sort_order'];
 $swapOrder = $allEvents[$swapIndex]['sort_order'];

 $db->update('series_events', ['sort_order' => $swapOrder], 'id = ?', [$seriesEventId]);
 $db->update('series_events', ['sort_order' => $currentOrder], 'id = ?', [$allEvents[$swapIndex]['id']]);

 $message = 'Ordning uppdaterad!';
 $messageType = 'success';
 }
 }
 }
}

// Get events in this series - sorted by sort_order (user-defined order)
// Uses point_scales for templates (same as ranking system, but points stored separately in series_results)
$seriesEvents = $db->getAll("
 SELECT se.*, e.name as event_name, e.date as event_date, e.location, e.discipline,
 ps.name as template_name
 FROM series_events se
 JOIN events e ON se.event_id = e.id
 LEFT JOIN point_scales ps ON se.template_id = ps.id
 WHERE se.series_id = ?
 ORDER BY se.sort_order ASC
", [$seriesId]);

// Get all events not in this series
$eventsNotInSeries = $db->getAll("
 SELECT e.id, e.name, e.date, e.location, e.discipline
 FROM events e
 WHERE e.id NOT IN (
 SELECT event_id FROM series_events WHERE series_id = ?
 )
 AND e.active = 1
 ORDER BY e.date DESC
", [$seriesId]);

// Get all point scales (same templates used for both series and ranking)
$templates = $db->getAll("SELECT id, name FROM point_scales WHERE active = 1 ORDER BY name");

$pageTitle = 'Hantera Events - ' . $series['name'];
$pageType = 'admin';
include __DIR__ . '/components/unified-layout.php';
?>

<!-- Content starts here (unified-layout already opened <main>) -->
<div class="container">
 <!-- Header -->
 <div class="flex items-center justify-between mb-lg">
 <div>
 <h1 class="">
  <i data-lucide="calendar"></i>
  <?= h($series['name']) ?>
 </h1>
 <p class="text-secondary">Hantera events och poängmallar</p>
 </div>
 <a href="/admin/series.php" class="btn btn--secondary">
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

 <div class="grid grid-cols-1 gs-lg-grid-cols-3 gap-lg">
 <!-- Settings Column -->
 <div>
 <!-- Count Best Results Card -->
 <div class="card mb-lg">
  <div class="card-header">
  <h2 class="">
  <i data-lucide="calculator"></i>
  Poängräkning
  </h2>
  </div>
  <div class="card-body">
  <form method="POST">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="update_count_best">

  <div class="form-group">
  <label for="count_best_results" class="label">Räkna bästa resultat</label>
  <select name="count_best_results" id="count_best_results" class="input" onchange="this.form.submit()">
   <option value="null" <?= $series['count_best_results'] === null ? 'selected' : '' ?>>Alla resultat</option>
   <?php for ($i = 1; $i <= 10; $i++): ?>
   <option value="<?= $i ?>" <?= $series['count_best_results'] == $i ? 'selected' : '' ?>>
   Bästa <?= $i ?> av <?= count($seriesEvents) ?>
   </option>
   <?php endfor; ?>
  </select>
  <small class="text-xs text-secondary">
   Övriga resultat visas med överstrykning och räknas inte i totalen
  </small>
  </div>
  </form>
  </div>
 </div>

 <!-- Add Event Card -->
 <div class="card gs-gradient-brand">
  <div class="card-header">
  <h2 class="">
  <i data-lucide="plus"></i>
  Lägg till Event
  </h2>
  </div>
 <div class="card-body">
  <?php if (empty($eventsNotInSeries)): ?>
  <p class="text-sm text-secondary">Alla events är redan tillagda i serien.</p>
  <?php else: ?>
  <form method="POST">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="add_event">

  <div class="form-group">
  <label for="event_id" class="label">Välj event</label>
  <select name="event_id" id="event_id" class="input" required>
   <option value="">-- Välj event --</option>
   <?php foreach ($eventsNotInSeries as $event): ?>
   <option value="<?= $event['id'] ?>">
   <?= h($event['name']) ?>
   <?php if ($event['date']): ?>
   (<?= date('Y-m-d', strtotime($event['date'])) ?>)
   <?php endif; ?>
   </option>
   <?php endforeach; ?>
  </select>
  </div>

  <div class="form-group">
  <label for="template_id" class="label">Poängmall (valfritt)</label>
  <select name="template_id" id="template_id" class="input">
   <option value="">-- Ingen mall --</option>
   <?php foreach ($templates as $template): ?>
   <option value="<?= $template['id'] ?>">
   <?= h($template['name']) ?>
   </option>
   <?php endforeach; ?>
  </select>
  <small class="text-xs text-secondary">
   Du kan ändra detta senare
  </small>
  </div>

  <button type="submit" class="btn btn--primary w-full">
  <i data-lucide="plus"></i>
  Lägg till
  </button>
  </form>

  <?php endif; ?>
 </div>
 </div>
 </div> <!-- Close Settings Column -->

 <!-- Events List -->
 <div class="gs-lg-col-span-2">
 <div class="card">
  <div class="card-header">
  <h2 class="">
  <i data-lucide="list"></i>
  Events i serien (<?= count($seriesEvents) ?>)
  </h2>
  </div>
  <div class="card-body">
  <?php if (empty($seriesEvents)): ?>
  <div class="alert alert--warning">
  <p>Inga events har lagts till i denna serie än.</p>
  </div>
  <?php else: ?>
  <!-- Bulk Edit Form -->
  <div class="alert alert--info mb-md">
   <form method="POST" id="bulkForm">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="bulk_update_templates">
    <div class="flex items-center gap-md">
     <label class="font-semibold">Bulk-redigera valda event:</label>
     <select name="bulk_template_id" class="input input-sm" style="min-width: 200px;">
      <option value="">-- Välj poängmall --</option>
      <?php foreach ($templates as $template): ?>
       <option value="<?= $template['id'] ?>"><?= h($template['name']) ?></option>
      <?php endforeach; ?>
     </select>
     <button type="submit" class="btn btn-sm btn--primary" id="bulkSubmit" disabled>
      <i data-lucide="check-square"></i>
      Uppdatera valda
     </button>
     <span class="text-sm text-secondary" id="selectedCount">0 valda</span>
    </div>
   </form>
  </div>

  <div class="table-responsive">
  <table class="table">
   <thead>
   <tr>
   <th class="table-col-w-60">
    <input type="checkbox" id="selectAll" title="Markera alla">
   </th>
   <th class="table-col-w-80">Ordning</th>
   <th>Event</th>
   <th>Datum</th>
   <th>Plats</th>
   <th>Poängmall</th>
   <th class="table-col-w-100">Åtgärder</th>
   </tr>
   </thead>
   <tbody>
   <?php $eventNumber = 1; $totalEvents = count($seriesEvents); ?>
   <?php foreach ($seriesEvents as $se): ?>
   <tr>
   <td>
    <input type="checkbox" class="event-checkbox" name="series_event_ids[]" value="<?= $se['id'] ?>" form="bulkForm">
   </td>
   <td>
    <div class="flex items-center gap-xs">
    <span class="badge badge-primary badge-sm">#<?= $eventNumber ?></span>
    <div class="flex flex-col gs-gap-xxs">
    <?php if ($eventNumber > 1): ?>
    <form method="POST" class="gs-display-inline">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="move_up">
    <input type="hidden" name="series_event_id" value="<?= $se['id'] ?>">
    <button type="submit" class="btn btn-xs btn--secondary" title="Flytta upp">
     <i data-lucide="chevron-up" class="gs-icon-12"></i>
    </button>
    </form>
    <?php endif; ?>
    <?php if ($eventNumber < $totalEvents): ?>
    <form method="POST" class="gs-display-inline">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="move_down">
    <input type="hidden" name="series_event_id" value="<?= $se['id'] ?>">
    <button type="submit" class="btn btn-xs btn--secondary" title="Flytta ner">
     <i data-lucide="chevron-down" class="gs-icon-12"></i>
    </button>
    </form>
    <?php endif; ?>
    </div>
    </div>
   </td>
   <td>
    <strong><?= h($se['event_name']) ?></strong>
    <?php if ($se['discipline']): ?>
    <br><span class="text-xs text-secondary"><?= h($se['discipline']) ?></span>
    <?php endif; ?>
   </td>
   <td><?= $se['event_date'] ? date('Y-m-d', strtotime($se['event_date'])) : '-' ?></td>
   <td><?= h($se['location'] ?? '-') ?></td>
   <td>
    <form method="POST" class="template-form" style="display: inline-block;">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="update_template">
    <input type="hidden" name="series_event_id" value="<?= $se['id'] ?>">
    <div style="display: flex; align-items: center; gap: 4px;">
     <select name="template_id" class="input input-sm" style="min-width: 150px;">
      <option value="">-- Ingen mall --</option>
      <?php foreach ($templates as $template): ?>
       <option value="<?= $template['id'] ?>" <?= $se['template_id'] == $template['id'] ? 'selected' : '' ?>>
        <?= h($template['name']) ?>
       </option>
      <?php endforeach; ?>
     </select>
     <button type="submit" class="btn btn-xs btn--primary" title="Spara">
      <i data-lucide="save" style="width: 14px; height: 14px;"></i>
     </button>
    </div>
    </form>
   </td>
   <td>
    <form method="POST" class="gs-display-inline" onsubmit="return confirm('Är du säker?');">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="remove_event">
    <input type="hidden" name="series_event_id" value="<?= $se['id'] ?>">
    <button type="submit" class="btn btn--sm btn--secondary btn-danger">
    <i data-lucide="trash-2"></i>
    </button>
    </form>
   </td>
   </tr>
   <?php $eventNumber++; ?>
   <?php endforeach; ?>
   </tbody>
  </table>
  </div>
  <?php endif; ?>
  </div>
 </div>
 </div>
 </div>
</div>
<!-- End container (unified-layout-footer will close <main>) -->

<script>
// Bulk edit functionality
document.addEventListener('DOMContentLoaded', function() {
 const selectAll = document.getElementById('selectAll');
 const checkboxes = document.querySelectorAll('.event-checkbox');
 const bulkSubmit = document.getElementById('bulkSubmit');
 const selectedCount = document.getElementById('selectedCount');

 if (!selectAll || !bulkSubmit || !selectedCount) return;

 // Update selected count and button state
 function updateBulkState() {
  const checkedCount = document.querySelectorAll('.event-checkbox:checked').length;
  selectedCount.textContent = checkedCount + ' valda';
  bulkSubmit.disabled = checkedCount === 0;
 }

 // Select all checkbox
 selectAll.addEventListener('change', function() {
  checkboxes.forEach(cb => cb.checked = this.checked);
  updateBulkState();
 });

 // Individual checkboxes
 checkboxes.forEach(cb => {
  cb.addEventListener('change', function() {
   // Update select all state
   selectAll.checked = document.querySelectorAll('.event-checkbox:checked').length === checkboxes.length;
   updateBulkState();
  });
 });

 // Form submission confirmation
 document.getElementById('bulkForm').addEventListener('submit', function(e) {
  const checkedCount = document.querySelectorAll('.event-checkbox:checked').length;
  const templateSelect = document.querySelector('select[name="bulk_template_id"]');
  const templateName = templateSelect.options[templateSelect.selectedIndex].text;

  if (!templateSelect.value) {
   e.preventDefault();
   alert('Välj en poängmall först!');
   return false;
  }

  if (!confirm(`Är du säker på att du vill uppdatera ${checkedCount} event med poängmallen "${templateName}"?\n\nDetta kommer omberäkna seriepoängen för alla valda event.`)) {
   e.preventDefault();
   return false;
  }
 });
});
</script>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>

<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/point-calculations.php';
require_admin();

$db = getDB();

// Get event ID from URL
$eventId = isset($_GET['event_id']) ? (int)$_GET['event_id'] : 0;

if (!$eventId) {
 header('Location: /admin/results.php');
 exit;
}

// Fetch event details
$event = $db->getRow("SELECT e.*, s.name as series_name FROM events e LEFT JOIN series s ON e.series_id = s.id WHERE e.id = ?", [$eventId]);

if (!$event) {
 header('Location: /admin/results.php');
 exit;
}

$message = '';
$messageType = 'info';

// Handle form submission for updating results
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
 checkCsrf();

 $resultId = isset($_POST['result_id']) ? (int)$_POST['result_id'] : 0;
 $action = $_POST['action'] ?? '';

 if ($action === 'set_license') {
 // Set license for a rider
 $riderId = isset($_POST['rider_id']) ? (int)$_POST['rider_id'] : 0;
 $licenseType = trim($_POST['license_type'] ?? '');
 $currentYear = (int)date('Y');

 if ($riderId && in_array($licenseType, ['motionslicens', 'engangslicens', 'baslicens'])) {
  try {
  $db->update('riders', [
   'license_type' => $licenseType,
   'license_year' => $currentYear
  ], 'id = ?', [$riderId]);
  $message = 'Licens uppdaterad till ' . ucfirst($licenseType) . ' för ' . $currentYear . '!';
  $messageType = 'success';
  } catch (Exception $e) {
  $message = 'Fel vid uppdatering av licens: ' . $e->getMessage();
  $messageType = 'error';
  }
 } else {
  $message = 'Ogiltig licenstyp eller åkare.';
  $messageType = 'error';
 }
 } elseif ($action === 'update' && $resultId) {
 // Update result
 $updateData = [
  'position' => !empty($_POST['position']) ? (int)$_POST['position'] : null,
  'bib_number' => trim($_POST['bib_number'] ?? ''),
  'finish_time' => !empty($_POST['finish_time']) ? trim($_POST['finish_time']) : null,
  'points' => !empty($_POST['points']) ? (float)$_POST['points'] : 0,
  'status' => trim($_POST['status'] ?? 'finished'),
 ];

 try {
  $db->update('results', $updateData, 'id = ?', [$resultId]);
  $message = 'Resultat uppdaterat!';
  $messageType = 'success';
 } catch (Exception $e) {
  $message = 'Fel vid uppdatering: ' . $e->getMessage();
  $messageType = 'error';
 }
 } elseif ($action === 'delete' && $resultId) {
 // Delete result
 try {
  $db->delete('results', 'id = ?', [$resultId]);
  $message = 'Resultat borttaget!';
  $messageType = 'success';
 } catch (Exception $e) {
  $message = 'Fel vid borttagning: ' . $e->getMessage();
  $messageType = 'error';
 }
 } elseif ($action === 'recalculate') {
 // Recalculate points for this event
 try {
  $stats = recalculateEventPoints($db, $eventId);
  if ($stats) {
   $message = "Poäng omräknade! Uppdaterade: {$stats['updated']}, Fel: {$stats['failed']}";
   $messageType = $stats['failed'] > 0 ? 'warning' : 'success';
  } else {
   $message = 'Kunde inte räkna om poäng. Kontrollera att poängskalan är konfigurerad.';
   $messageType = 'error';
  }
 } catch (Exception $e) {
  $message = 'Fel vid omräkning av poäng: ' . $e->getMessage();
  $messageType = 'error';
 }
 }
}

// Fetch all results for this event
$currentYear = (int)date('Y');
$results = $db->getAll("
 SELECT
 res.*,
 r.id as rider_id,
 r.firstname,
 r.lastname,
 r.gender,
 r.birth_year,
 r.license_type,
 r.license_year,
 r.license_number,
 CASE
  WHEN r.license_year = ? AND r.license_type IS NOT NULL AND r.license_type != ''
  AND r.license_type NOT IN ('engangslicens', 'Engångslicens', 'sweid', 'SWE ID')
  THEN 1
  ELSE 0
 END as has_active_license,
 c.name as club_name,
 cls.name as class_name,
 cls.display_name as class_display_name,
 cls.sort_order as class_sort_order
 FROM results res
 INNER JOIN riders r ON res.cyclist_id = r.id
 LEFT JOIN clubs c ON r.club_id = c.id
 LEFT JOIN classes cls ON res.class_id = cls.id
 WHERE res.event_id = ?
 ORDER BY
 cls.sort_order ASC,
 COALESCE(cls.name, 'Oklassificerad'),
 CASE WHEN res.status = 'finished' THEN res.class_position ELSE 999 END,
 res.finish_time
", [$currentYear, $eventId]);

// Group results by class
$resultsByClass = [];
foreach ($results as $result) {
 $className = $result['class_name'] ?? 'Oklassificerad';
 if (!isset($resultsByClass[$className])) {
 $resultsByClass[$className] = [
  'display_name' => $result['class_display_name'] ?? $className,
  'sort_order' => $result['class_sort_order'] ?? 999,
  'results' => []
 ];
 }
 $resultsByClass[$className]['results'][] = $result;
}

// Sort by sort_order
uksort($resultsByClass, function($a, $b) use ($resultsByClass) {
 return $resultsByClass[$a]['sort_order'] - $resultsByClass[$b]['sort_order'];
});

// Get all classes for dropdown
$classes = $db->getAll("SELECT id, name, display_name FROM classes WHERE active = 1 ORDER BY sort_order, name");

$pageTitle = 'Editera Resultat - ' . $event['name'];
$pageType = 'admin';
include __DIR__ . '/../includes/layout-header.php';
?>

<style>
/* Edit Results Page - Inline CSS fixes */

/* Button variants */
.btn--primary { background: var(--color-accent, #61CE70); color: white; border: none; padding: 8px 16px; border-radius: 6px; cursor: pointer; font-size: 0.875rem; display: inline-flex; align-items: center; gap: 6px; }
.btn--primary:hover { opacity: 0.9; }
.btn--secondary { background: var(--color-bg-surface, #f8f9fa); border: 1px solid var(--color-border, #e5e7eb); color: var(--color-text-primary, #1f2937); padding: 8px 16px; border-radius: 6px; cursor: pointer; font-size: 0.875rem; display: inline-flex; align-items: center; gap: 6px; text-decoration: none; }
.btn--secondary:hover { background: var(--color-bg-hover, #f1f3f4); }
.btn--sm { padding: 4px 8px; font-size: 0.75rem; }
.btn-xs { padding: 2px 6px; font-size: 0.7rem; }
.btn-danger { background: var(--color-danger, #ef4444); color: white; border: none; padding: 4px 8px; border-radius: 6px; cursor: pointer; }
.btn-danger:hover { opacity: 0.9; }
.btn-ghost { background: transparent; border: none; padding: 4px 8px; cursor: pointer; }

/* Input styles */
.input { padding: 6px 10px; border: 1px solid var(--color-border, #e5e7eb); border-radius: 6px; font-size: 0.875rem; background: white; }
.input:focus { outline: none; border-color: var(--color-accent, #61CE70); box-shadow: 0 0 0 2px rgba(97, 206, 112, 0.2); }
.input-w60 { width: 60px; text-align: center; }
.input-w70 { width: 70px; text-align: center; }
.input-w80 { width: 80px; text-align: center; }
.input-w100-mono { width: 100px; font-family: monospace; text-align: center; }
.input-xs { padding: 4px 6px; font-size: 0.75rem; min-width: 90px; }

/* Table column widths */
.table-th-w60 { width: 60px; }
.table-th-w80 { width: 80px; }
.table-th-w100 { width: 100px; }
.table-th-w120 { width: 120px; }
.table-th-w150 { width: 150px; }

/* Table scrollable */
.table-scrollable { overflow-x: auto; -webkit-overflow-scrolling: touch; }

/* Badge sizes */
.badge-sm { padding: 2px 6px; font-size: 0.65rem; }

/* Flex utilities */
.flex { display: flex; }
.items-center { align-items: center; }
.justify-center { justify-content: center; }
.justify-between { justify-content: space-between; }
.gap-xs { gap: 4px; }
.gap-sm { gap: 8px; }
.gap-md { gap: 16px; }

/* Spacing */
.mb-sm { margin-bottom: 8px; }
.mb-md { margin-bottom: 16px; }
.mb-lg { margin-bottom: 24px; }
.ml-sm { margin-left: 8px; }
.p-md { padding: 16px; }
.gs-p-0 { padding: 0 !important; }
.gs-mb-0 { margin-bottom: 0 !important; }
.gs-ml-xs { margin-left: 4px; }

/* Text utilities */
.text-primary { color: var(--color-text-primary, #1f2937); }
.text-secondary { color: var(--color-text-secondary, #6b7280); }
.text-center { text-align: center; }
.text-xs { font-size: 0.75rem; }
.text-sm { font-size: 0.875rem; }

/* Icon sizes */
.icon-sm { width: 14px; height: 14px; }
.gs-icon-12 { width: 12px; height: 12px; }

/* Rider meta text */
.gs-rider-meta-text { font-size: 0.75rem; color: var(--color-text-muted, #9ca3af); margin-top: 2px; }

/* Form inline - allows form inside table row */
.gs-form-inline { display: contents; }

/* Modal styles */
.gs-modal { position: fixed; inset: 0; z-index: 1000; display: flex; align-items: center; justify-content: center; }
.gs-modal-backdrop { position: absolute; inset: 0; background: rgba(0, 0, 0, 0.5); }
.gs-modal-content { position: relative; background: var(--color-bg-card, #ffffff); border-radius: 12px; box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2); width: 90%; max-width: 500px; max-height: 90vh; overflow: auto; }
.gs-modal-header { display: flex; align-items: center; justify-content: space-between; padding: 16px 20px; border-bottom: 1px solid var(--color-border, #e5e7eb); }
.gs-modal-header h3 { margin: 0; display: flex; align-items: center; gap: 8px; font-size: 1.1rem; }
.gs-modal-body { padding: 20px; }
.gs-modal-footer { display: flex; justify-content: flex-end; gap: 8px; padding: 16px 20px; border-top: 1px solid var(--color-border, #e5e7eb); }

/* Grid utility */
.grid { display: grid; }
.grid.gap-sm { gap: 8px; }

/* Hide data-lucide before JS loads */
[data-lucide] { display: none; }
svg.lucide { display: inline-block; }

/* Mobile responsive */
@media (max-width: 768px) {
    .table-scrollable { margin: 0 -16px; padding: 0 16px; }
    .table-scrollable .table { min-width: 900px; font-size: 0.75rem; }
    .input-w60, .input-w70, .input-w80 { width: 50px; padding: 4px; font-size: 0.7rem; }
    .input-w100-mono { width: 70px; }
    .input-xs { padding: 2px 4px; font-size: 0.65rem; min-width: 70px; }
    .badge-sm { padding: 1px 4px; font-size: 0.6rem; }
    .flex.items-center.justify-between.mb-lg { flex-direction: column; align-items: flex-start; gap: 16px; }
    .flex.gap-sm { flex-wrap: wrap; }
}
</style>

<main class="main-content">
 <div class="container">
 <!-- Header -->
 <div class="flex items-center justify-between mb-lg">
  <div>
  <h1 class="text-primary mb-sm">
   <i data-lucide="edit"></i>
   Editera Resultat
  </h1>
  <h2 class="text-secondary">
   <?= h($event['name']) ?> - <?= date('Y-m-d', strtotime($event['date'])) ?>
  </h2>
  </div>
  <div class="flex gap-sm">
  <form method="POST" style="display: inline;">
   <?= csrf_field() ?>
   <input type="hidden" name="action" value="recalculate">
   <button type="submit" class="btn btn--primary" onclick="return confirm('Räkna om poäng för alla resultat i detta event?')">
   <i data-lucide="calculator"></i>
   Räkna om poäng
   </button>
  </form>
  <a href="/admin/results.php" class="btn btn--secondary">
   <i data-lucide="arrow-left"></i>
   Tillbaka
  </a>
  </div>
 </div>

 <!-- Message -->
 <?php if ($message): ?>
  <div class="alert alert-<?= h($messageType) ?> mb-lg">
  <i data-lucide="<?= $messageType === 'success' ? 'check-circle' : 'alert-circle' ?>"></i>
  <?= h($message) ?>
  </div>
 <?php endif; ?>

 <?php if (empty($results)): ?>
  <div class="card">
  <div class="card-body">
   <div class="alert alert--warning">
   <p>Inga resultat hittades för detta event.</p>
   </div>
  </div>
  </div>
 <?php else: ?>
  <!-- Results by Class -->
  <?php foreach ($resultsByClass as $className => $classData): ?>
  <div class="card mb-lg">
   <div class="card-header">
   <h3 class="text-primary">
    <i data-lucide="users"></i>
    <?= h($classData['display_name']) ?>
    <span class="badge badge-primary badge-sm gs-ml-xs">
    <?= h($className) ?>
    </span>
    <span class="badge badge-secondary ml-sm">
    <?= count($classData['results']) ?> deltagare
    </span>
   </h3>
   </div>
   <div class="card-body gs-p-0 table-scrollable">
   <table class="table table-sm">
    <thead>
    <tr>
     <th class="table-th-w60">Plac.</th>
     <th>Namn</th>
     <th class="table-th-w120">Licens</th>
     <th class="table-th-w150">Klubb</th>
     <th class="table-th-w100">Startnr</th>
     <th class="table-th-w120">Tid</th>
     <th class="table-th-w80">Poäng</th>
     <th class="table-th-w120">Status</th>
     <th class="table-th-w120">Åtgärder</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($classData['results'] as $result): ?>
     <tr id="result-row-<?= $result['id'] ?>">
     <form method="POST" class="result-form gs-form-inline">
      <?= csrf_field() ?>
      <input type="hidden" name="result_id" value="<?= $result['id'] ?>">
      <input type="hidden" name="action" value="update">

      <!-- Position -->
      <td class="text-center">
      <input type="number"
       name="position"
       value="<?= h($result['position']) ?>"
       class="input input-w60"
       min="1">
      </td>

      <!-- Name (read-only) -->
      <td>
      <strong><?= h($result['firstname']) ?> <?= h($result['lastname']) ?></strong>
      <div class="gs-rider-meta-text">
       <?php if ($result['birth_year']): ?>
       <?= calculateAge($result['birth_year']) ?> år
       <?php endif; ?>
       <?php if ($result['gender']): ?>
       • <?= $result['gender'] == 'M' ? 'Herr' : ($result['gender'] == 'F' ? 'Dam' : '') ?>
       <?php endif; ?>
      </div>
      </td>

      <!-- License -->
      <td>
      <?php if ($result['has_active_license']): ?>
       <span class="badge badge-success badge-sm">
       <?= h($result['license_type']) ?>
       </span>
      <?php elseif ($result['license_number']): ?>
       <!-- Has SWE ID but no active license - allow setting -->
       <div class="flex gap-xs items-center">
       <span class="badge badge-warning badge-sm" title="SWE ID utan aktiv licens">
        SWE ID
       </span>
       <button type="button"
        class="btn btn--secondary btn-xs set-license-btn"
        data-rider-id="<?= $result['rider_id'] ?>"
        data-rider-name="<?= h($result['firstname'] . ' ' . $result['lastname']) ?>"
        title="Tilldela licens">
        <i data-lucide="id-card" class="gs-icon-12"></i>
       </button>
       </div>
      <?php else: ?>
       <span class="text-secondary text-xs">-</span>
      <?php endif; ?>
      </td>

      <!-- Club (read-only) -->
      <td>
      <?php if ($result['club_name']): ?>
       <span class="badge badge-secondary badge-sm">
       <?= h($result['club_name']) ?>
       </span>
      <?php else: ?>
       <span class="text-secondary">-</span>
      <?php endif; ?>
      </td>

      <!-- Bib Number -->
      <td class="text-center">
      <input type="text"
       name="bib_number"
       value="<?= h($result['bib_number']) ?>"
       class="input input-w80">
      </td>

      <!-- Finish Time -->
      <td class="text-center">
      <input type="text"
       name="finish_time"
       value="<?= h($result['finish_time']) ?>"
       class="input input-w100-mono"
       placeholder="HH:MM:SS">
      </td>

      <!-- Points -->
      <td class="text-center">
      <input type="number"
       name="points"
       value="<?= h($result['points']) ?>"
       class="input input-w70"
       step="1"
       min="0">
      </td>

      <!-- Status -->
      <td class="text-center">
      <select name="status" class="input input-xs">
       <option value="finished" <?= $result['status'] === 'finished' ? 'selected' : '' ?>>Slutförd</option>
       <option value="dnf" <?= $result['status'] === 'dnf' ? 'selected' : '' ?>>DNF</option>
       <option value="dns" <?= $result['status'] === 'dns' ? 'selected' : '' ?>>DNS</option>
       <option value="dq" <?= $result['status'] === 'dq' ? 'selected' : '' ?>>DQ</option>
      </select>
      </td>

      <!-- Actions -->
      <td class="text-center">
      <div class="flex gap-xs justify-center">
       <button type="submit"
        class="btn btn--primary btn--sm"
        title="Spara">
       <i data-lucide="save" class="icon-sm"></i>
       </button>
       <button type="button"
        class="btn btn-danger btn--sm delete-result"
        data-result-id="<?= $result['id'] ?>"
        data-rider-name="<?= h($result['firstname'] . ' ' . $result['lastname']) ?>"
        title="Ta bort">
       <i data-lucide="trash-2" class="icon-sm"></i>
       </button>
      </div>
      </td>
     </form>
     </tr>
    <?php endforeach; ?>
    </tbody>
   </table>
   </div>
  </div>
  <?php endforeach; ?>
 <?php endif; ?>
 </div>
</main>

<!-- License Assignment Modal -->
<div id="license-modal" class="gs-modal" style="display: none;">
 <div class="gs-modal-backdrop"></div>
 <div class="gs-modal-content" style="max-width: 400px;">
 <div class="gs-modal-header">
  <h3 class="">
  <i data-lucide="id-card"></i>
  Tilldela licens
  </h3>
  <button type="button" class="btn btn-ghost btn--sm close-modal">
  <i data-lucide="x"></i>
  </button>
 </div>
 <form method="POST" id="license-form">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="set_license">
  <input type="hidden" name="rider_id" id="modal-rider-id" value="">

  <div class="gs-modal-body">
  <p class="mb-md">
   Tilldela licens till <strong id="modal-rider-name"></strong> för <?= date('Y') ?>:
  </p>

  <div class="grid gap-sm">
   <label class="card p-md" style="cursor: pointer; border: 2px solid var(--border);">
   <div class="flex items-center gap-md">
    <input type="radio" name="license_type" value="motionslicens" required>
    <div>
    <strong>Motionslicens</strong>
    <p class="text-sm text-secondary gs-mb-0">För motionslopp och breddtävlingar</p>
    </div>
   </div>
   </label>

   <label class="card p-md" style="cursor: pointer; border: 2px solid var(--border);">
   <div class="flex items-center gap-md">
    <input type="radio" name="license_type" value="baslicens">
    <div>
    <strong>Baslicens</strong>
    <p class="text-sm text-secondary gs-mb-0">Grundläggande licens</p>
    </div>
   </div>
   </label>

   <label class="card p-md" style="cursor: pointer; border: 2px solid var(--border);">
   <div class="flex items-center gap-md">
    <input type="radio" name="license_type" value="engangslicens">
    <div>
    <strong>Engångslicens</strong>
    <p class="text-sm text-secondary gs-mb-0">Engångslicens för enstaka tävling</p>
    </div>
   </div>
   </label>
  </div>
  </div>

  <div class="gs-modal-footer">
  <button type="button" class="btn btn--secondary close-modal">Avbryt</button>
  <button type="submit" class="btn btn--primary">
   <i data-lucide="check"></i>
   Tilldela licens
  </button>
  </div>
 </form>
 </div>
</div>

<script src="https://unpkg.com/lucide@latest"></script>
<script>
 lucide.createIcons();

 // Delete result confirmation
 document.querySelectorAll('.delete-result').forEach(btn => {
 btn.addEventListener('click', function() {
  const resultId = this.dataset.resultId;
  const riderName = this.dataset.riderName;

  if (confirm('Är du säker på att du vill ta bort resultatet för ' + riderName + '?')) {
  const form = document.createElement('form');
  form.method = 'POST';
  form.innerHTML = `
   <?= csrf_field() ?>
   <input type="hidden" name="result_id" value="${resultId}">
   <input type="hidden" name="action" value="delete">
  `;
  document.body.appendChild(form);
  form.submit();
  }
 });
 });

 // License assignment modal
 const modal = document.getElementById('license-modal');

 document.querySelectorAll('.set-license-btn').forEach(btn => {
 btn.addEventListener('click', function() {
  const riderId = this.dataset.riderId;
  const riderName = this.dataset.riderName;

  document.getElementById('modal-rider-id').value = riderId;
  document.getElementById('modal-rider-name').textContent = riderName;

  // Reset radio buttons
  document.querySelectorAll('#license-form input[type="radio"]').forEach(r => r.checked = false);

  modal.style.display = 'flex';
  lucide.createIcons();
 });
 });

 // Close modal
 document.querySelectorAll('.close-modal, .gs-modal-backdrop').forEach(el => {
 el.addEventListener('click', function() {
  modal.style.display = 'none';
 });
 });

 // Highlight selected license card
 document.querySelectorAll('#license-form input[type="radio"]').forEach(radio => {
 radio.addEventListener('change', function() {
  document.querySelectorAll('#license-form label.card').forEach(card => {
  card.style.borderColor = 'var(--border)';
  card.style.backgroundColor = '';
  });
  if (this.checked) {
  const card = this.closest('label');
  card.style.borderColor = 'var(--primary)';
  card.style.backgroundColor = 'var(--primary-light, rgba(var(--primary-rgb), 0.1))';
  }
 });
 });
</script>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>

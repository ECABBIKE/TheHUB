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

// Page config for unified layout
$page_title = 'Editera Resultat - ' . $event['name'];
$breadcrumbs = [
    ['label' => 'Resultat', 'url' => '/admin/results.php'],
    ['label' => $event['name']]
];
$page_actions = '<form method="POST" style="display: inline;">
    ' . csrf_field() . '
    <input type="hidden" name="action" value="recalculate">
    <button type="submit" class="btn-admin btn-admin-secondary" onclick="return confirm(\'Räkna om poäng för alla resultat i detta event?\')">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><rect width="16" height="20" x="4" y="2" rx="2"/><line x1="8" x2="16" y1="6" y2="6"/><line x1="8" x2="16" y1="10" y2="10"/><line x1="8" x2="16" y1="14" y2="14"/><line x1="8" x2="16" y1="18" y2="18"/></svg>
        Räkna om poäng
    </button>
</form>
<a href="/admin/results.php" class="btn-admin btn-admin-secondary">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="m12 19-7-7 7-7"/><path d="M19 12H5"/></svg>
    Tillbaka
</a>';

include __DIR__ . '/components/unified-layout.php';
?>

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

<!-- License Assignment Modal -->
<div id="license-modal" class="gs-modal" class="hidden">
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

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>

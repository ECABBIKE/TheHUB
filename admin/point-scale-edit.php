<?php
require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();
$message = '';
$messageType = 'info';

// Get scale ID
$scaleId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$scaleId) {
 header('Location: /admin/point-scales.php');
 exit;
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
 checkCsrf();

 $action = $_POST['action'] ?? '';

 if ($action === 'update_scale') {
 $name = trim($_POST['name'] ?? '');
 $description = trim($_POST['description'] ?? '');
 $discipline = $_POST['discipline'] ?? 'ALL';
 $active = isset($_POST['active']) ? 1 : 0;
 $isDHScale = isset($_POST['is_dh_scale']) && $_POST['is_dh_scale'] == '1';

 if (empty($name)) {
  $message = 'Namn är obligatoriskt';
  $messageType = 'error';
 } else {
  try {
  // Update scale
  $db->update('point_scales', [
   'name' => $name,
   'description' => $description,
   'discipline' => $discipline,
   'active' => $active
  ], 'id = ?', [$scaleId]);

  // Delete existing values
  $db->delete('point_scale_values', 'scale_id = ?', [$scaleId]);

  // Insert new point values
  $positions = $_POST['positions'] ?? [];
  $points = $_POST['points'] ?? [];
  $run1Points = $_POST['run_1_points'] ?? [];
  $run2Points = $_POST['run_2_points'] ?? [];

  foreach ($positions as $idx => $position) {
   if (!empty($position)) {
   $pointValue = !empty($points[$idx]) ? floatval($points[$idx]) : 0;
   $run1Value = $isDHScale && !empty($run1Points[$idx]) ? floatval($run1Points[$idx]) : 0;
   $run2Value = $isDHScale && !empty($run2Points[$idx]) ? floatval($run2Points[$idx]) : 0;

   // Only insert if at least one value is non-zero
   if ($pointValue > 0 || $run1Value > 0 || $run2Value > 0) {
    $db->insert('point_scale_values', [
    'scale_id' => $scaleId,
    'position' => intval($position),
    'points' => $pointValue,
    'run_1_points' => $run1Value,
    'run_2_points' => $run2Value
    ]);
   }
   }
  }

  $message = 'Poängmall uppdaterad!';
  $messageType = 'success';
  } catch (Exception $e) {
  $message = 'Ett fel uppstod: ' . $e->getMessage();
  $messageType = 'error';
  }
 }
 }
}

// Get scale data
$scale = $db->getRow("SELECT * FROM point_scales WHERE id = ?", [$scaleId]);

if (!$scale) {
 header('Location: /admin/point-scales.php');
 exit;
}

// Get scale values
$values = $db->getAll("
 SELECT position, points, run_1_points, run_2_points
 FROM point_scale_values
 WHERE scale_id = ?
 ORDER BY position ASC
", [$scaleId]);

// Check if this is a DH scale
$isDHScale = false;
foreach ($values as $value) {
 if ($value['run_1_points'] > 0 || $value['run_2_points'] > 0) {
 $isDHScale = true;
 break;
 }
}

// Create indexed array for easier access
$valuesByPosition = [];
foreach ($values as $value) {
 $valuesByPosition[$value['position']] = $value;
}

$pageTitle = 'Redigera Poängmall';
$pageType = 'admin';
include __DIR__ . '/../includes/layout-header.php';
?>

<main class="main-content">
 <div class="container container-max-1200">
 <div class="flex justify-between items-center mb-lg">
  <h1 class="">
  <i data-lucide="edit"></i>
  Redigera Poängmall
  </h1>
  <a href="/admin/point-scales.php" class="btn btn--secondary">
  <i data-lucide="arrow-left"></i>
  Tillbaka
  </a>
 </div>

 <?php if ($message): ?>
  <div class="alert alert-<?= $messageType ?> mb-lg">
  <i data-lucide="<?= $messageType === 'success' ? 'check-circle' : 'alert-circle' ?>"></i>
  <?= h($message) ?>
  </div>
 <?php endif; ?>

 <div class="card">
  <form method="POST" action="" class="card-body">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="update_scale">

  <div class="grid grid-cols-2 gap-md mb-lg">
   <div>
   <label class="label">Namn <span class="text-error">*</span></label>
   <input type="text" name="name" class="input" value="<?= h($scale['name']) ?>" required>
   </div>

   <div>
   <label class="label">Disciplin</label>
   <select name="discipline" class="input">
    <option value="ALL" <?= $scale['discipline'] === 'ALL' ? 'selected' : '' ?>>Alla</option>
    <option value="ENDURO" <?= $scale['discipline'] === 'ENDURO' ? 'selected' : '' ?>>Enduro</option>
    <option value="DH" <?= $scale['discipline'] === 'DH' ? 'selected' : '' ?>>Downhill</option>
    <option value="XCO" <?= $scale['discipline'] === 'XCO' ? 'selected' : '' ?>>XCO</option>
    <option value="CX" <?= $scale['discipline'] === 'CX' ? 'selected' : '' ?>>Cyclocross</option>
   </select>
   </div>
  </div>

  <div class="mb-lg">
   <label class="label">Beskrivning</label>
   <textarea name="description" class="input" rows="2"><?= h($scale['description']) ?></textarea>
  </div>

  <div class="mb-lg">
   <label class="checkbox">
   <input type="checkbox" name="active" value="1" <?= $scale['active'] ? 'checked' : '' ?>>
   <span>Aktiv</span>
   </label>
  </div>

  <div class="mb-lg">
   <label class="checkbox">
   <input type="checkbox" name="is_dh_scale" value="1" id="isDHScale" onchange="toggleDHColumns()" <?= $isDHScale ? 'checked' : '' ?>>
   <span><strong>DH-mall med dubbla poäng</strong> (För SweCUP DH där både Kval och Final ger poäng)</span>
   </label>
  </div>

  <div>
   <label class="label">Poängvärden</label>
   <div class="gs-scroll-x-touch">
   <table class="table table-min-w-600">
    <thead>
    <tr>
     <th class="table-col-w-80">Position</th>
     <th class="standard-points-col <?= $isDHScale ? 'gs-display-none' : '' ?>">Poäng</th>
     <th class="dh-points-col <?= !$isDHScale ? 'gs-display-none' : '' ?>">Kval-Poäng</th>
     <th class="dh-points-col <?= !$isDHScale ? 'gs-display-none' : '' ?>">Final-Poäng</th>
    </tr>
    </thead>
    <tbody>
    <?php for ($i = 1; $i <= 150; $i++): ?>
     <?php
     $posValue = isset($valuesByPosition[$i]) ? $valuesByPosition[$i] : [
     'points' => '',
     'run_1_points' => '',
     'run_2_points' => ''
     ];
     ?>
     <tr>
     <td>
      <input type="number" name="positions[]" value="<?= $i ?>" class="input input-sm" readonly>
     </td>
     <td class="standard-points-col <?= $isDHScale ? 'gs-display-none' : '' ?>">
      <input type="number" name="points[]" step="0.01" class="input input-sm" value="<?= h($posValue['points']) ?>" placeholder="0">
     </td>
     <td class="dh-points-col <?= !$isDHScale ? 'gs-display-none' : '' ?>">
      <input type="number" name="run_1_points[]" step="0.01" class="input input-sm" value="<?= h($posValue['run_1_points']) ?>" placeholder="0">
     </td>
     <td class="dh-points-col <?= !$isDHScale ? 'gs-display-none' : '' ?>">
      <input type="number" name="run_2_points[]" step="0.01" class="input input-sm" value="<?= h($posValue['run_2_points']) ?>" placeholder="0">
     </td>
     </tr>
    <?php endfor; ?>
    </tbody>
   </table>
   </div>
  </div>

  <div class="gs-form-footer-actions">
   <a href="/admin/point-scales.php" class="btn btn--secondary">
   Avbryt
   </a>
   <button type="submit" class="btn btn--primary">
   <i data-lucide="save"></i>
   Spara Ändringar
   </button>
  </div>
  </form>
 </div>
 </div>
</main>

<script src="https://unpkg.com/lucide@latest"></script>
<script>
 lucide.createIcons();

 function toggleDHColumns() {
 const isDH = document.getElementById('isDHScale').checked;
 const standardCols = document.querySelectorAll('.standard-points-col');
 const dhCols = document.querySelectorAll('.dh-points-col');

 standardCols.forEach(col => {
  if (isDH) {
  col.classList.add('hidden', 'gs-display-none');
  } else {
  col.classList.remove('hidden', 'gs-display-none');
  }
 });

 dhCols.forEach(col => {
  if (isDH) {
  col.classList.remove('hidden', 'gs-display-none');
  } else {
  col.classList.add('hidden', 'gs-display-none');
  }
 });
 }
</script>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>

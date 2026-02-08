<?php
/**
 * Pricing Templates Management
 * Create and manage reusable pricing templates for events
 */

require_once __DIR__ . '/../config.php';
require_admin();

// Only super_admin can manage all pricing templates
if (!isRole('super_admin')) {
    set_flash('error', 'Endast superadmin har tillgång till prismallar');
    redirect('/admin/');
}

$db = getDB();

// Initialize message
$message = '';
$messageType = 'info';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
 checkCsrf();
 $action = $_POST['action'] ?? '';

 if ($action === 'create_template') {
 $name = trim($_POST['name'] ?? '');
 $description = trim($_POST['description'] ?? '');
 $isDefault = isset($_POST['is_default']) ? 1 : 0;

 if (empty($name)) {
 $message = 'Namn är obligatoriskt';
 $messageType = 'error';
 } else {
 // If setting as default, remove default from others
 if ($isDefault) {
 $db->query("UPDATE pricing_templates SET is_default = 0");
 }

 $newId = $db->insert('pricing_templates', [
 'name' => $name,
 'description' => $description,
 'is_default' => $isDefault
 ]);

 // Redirect to edit the new template
 header("Location: /admin/pricing-templates.php?edit=$newId");
 exit;
 }
 }

 elseif ($action === 'update_template') {
 $id = intval($_POST['id']);
 $name = trim($_POST['name'] ?? '');
 $description = trim($_POST['description'] ?? '');
 $isDefault = isset($_POST['is_default']) ? 1 : 0;
 $earlyBirdPercent = floatval($_POST['early_bird_percent'] ?? 15);
 $earlyBirdDays = intval($_POST['early_bird_days'] ?? 21);
 $lateFeePercent = floatval($_POST['late_fee_percent'] ?? 25);
 $lateFeeDays = intval($_POST['late_fee_days'] ?? 3);

 if (empty($name)) {
 $message = 'Namn är obligatoriskt';
 $messageType = 'error';
 } else {
 // If setting as default, remove default from others
 if ($isDefault) {
 $db->query("UPDATE pricing_templates SET is_default = 0 WHERE id != ?", [$id]);
 }

 $db->update('pricing_templates', [
 'name' => $name,
 'description' => $description,
 'is_default' => $isDefault,
 'early_bird_percent' => $earlyBirdPercent,
 'early_bird_days_before' => $earlyBirdDays,
 'late_fee_percent' => $lateFeePercent,
 'late_fee_days_before' => $lateFeeDays
 ], 'id = ?', [$id]);
 $message ="Prismall uppdaterad!";
 $messageType = 'success';
 }
 }

 elseif ($action === 'delete_template') {
 $id = intval($_POST['id']);
 $db->delete('pricing_templates', 'id = ?', [$id]);
 $message ="Prismall borttagen!";
 $messageType = 'success';
 header("Location: /admin/pricing-templates.php");
 exit;
 }

 elseif ($action === 'save_prices') {
 $templateId = intval($_POST['template_id']);
 $classIds = $_POST['class_id'] ?? [];
 $basePrices = $_POST['base_price'] ?? [];
 $earlyBirdPrices = $_POST['early_bird_price'] ?? [];
 $lateFeePrices = $_POST['late_fee_price'] ?? [];
 $seasonPrices = $_POST['season_price'] ?? [];

 // Check which columns exist
 $hasSeasonPrice = false;
 $hasEarlyBirdPrice = false;
 $hasLateFeePrice = false;
 try {
  $cols = $db->getAll("SHOW COLUMNS FROM pricing_template_rules");
  foreach ($cols as $col) {
   if ($col['Field'] === 'season_price') $hasSeasonPrice = true;
   if ($col['Field'] === 'early_bird_price') $hasEarlyBirdPrice = true;
   if ($col['Field'] === 'late_fee_price') $hasLateFeePrice = true;
  }
 } catch (Exception $e) {}

 $saved = 0;
 foreach ($classIds as $index => $classId) {
 $basePrice = floatval($basePrices[$index] ?? 0);
 $earlyBirdPrice = floatval($earlyBirdPrices[$index] ?? 0);
 $lateFeePrice = floatval($lateFeePrices[$index] ?? 0);
 $seasonPrice = floatval($seasonPrices[$index] ?? 0);

 // Save if ANY price is set
 if ($basePrice > 0 || $earlyBirdPrice > 0 || $lateFeePrice > 0 || $seasonPrice > 0) {
 $existing = $db->getRow("SELECT id FROM pricing_template_rules WHERE template_id = ? AND class_id = ?", [$templateId, $classId]);

 $data = [
  'base_price' => $basePrice > 0 ? round($basePrice) : 0
 ];
 if ($hasEarlyBirdPrice) {
  $data['early_bird_price'] = $earlyBirdPrice > 0 ? round($earlyBirdPrice) : null;
 }
 if ($hasLateFeePrice) {
  $data['late_fee_price'] = $lateFeePrice > 0 ? round($lateFeePrice) : null;
 }
 if ($hasSeasonPrice) {
  $data['season_price'] = $seasonPrice > 0 ? round($seasonPrice) : null;
 }

 if ($existing) {
  $db->update('pricing_template_rules', $data, 'id = ?', [$existing['id']]);
 } else {
  $data['template_id'] = $templateId;
  $data['class_id'] = $classId;
  $db->insert('pricing_template_rules', $data);
 }
 $saved++;
 } else {
 // Remove pricing if ALL prices are 0
 $db->delete('pricing_template_rules', 'template_id = ? AND class_id = ?', [$templateId, $classId]);
 }
 }
 $message ="Sparade $saved priser (avrundade till hela kronor)";
 $messageType = 'success';
 }
}

// Check if editing a template
$editTemplate = null;
$templateRules = [];
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
 $editTemplate = $db->getRow("SELECT * FROM pricing_templates WHERE id = ?", [intval($_GET['edit'])]);
 if ($editTemplate) {
 $rules = $db->getAll("SELECT * FROM pricing_template_rules WHERE template_id = ?", [$editTemplate['id']]);
 foreach ($rules as $rule) {
 $templateRules[$rule['class_id']] = $rule;
 }
 }
}

// Fetch all templates
$templates = $db->getAll("
 SELECT t.*,
 COUNT(r.id) as rule_count,
 (SELECT COUNT(*) FROM events WHERE pricing_template_id = t.id) as event_count,
 (SELECT COUNT(*) FROM series WHERE default_pricing_template_id = t.id) as series_count
 FROM pricing_templates t
 LEFT JOIN pricing_template_rules r ON t.id = r.template_id
 GROUP BY t.id
 ORDER BY t.is_default DESC, t.name ASC
");

// Fetch only ACTIVE classes for pricing form
$classes = $db->getAll("SELECT id, name, display_name FROM classes WHERE active = 1 ORDER BY sort_order ASC");

// Page config for unified layout
$page_title = $editTemplate ? 'Redigera Prismall - ' . $editTemplate['name'] : 'Prismallar';
$breadcrumbs = [
    ['label' => 'Inställningar', 'url' => '/admin/settings'],
    ['label' => 'Prismallar']
];

// Include unified layout
include __DIR__ . '/components/unified-layout.php';
?>

<style>
/* Remove spinners from number inputs */
input[type="number"]::-webkit-inner-spin-button,
input[type="number"]::-webkit-outer-spin-button {
 -webkit-appearance: none;
 margin: 0;
}
input[type="number"] {
 -moz-appearance: textfield;
}
</style>

 <?php if ($editTemplate): ?>
 <!-- Edit Template View -->
 <div class="card mb-lg">
 <div class="card-body">
 <div class="flex justify-between items-center">
  <div>
  <h1 class="">
  <i data-lucide="file-text"></i>
  <?= htmlspecialchars($editTemplate['name']) ?>
  </h1>
  <p class="text-secondary text-sm">
  Konfigurera priser för denna mall
  </p>
  </div>
  <a href="/admin/pricing-templates.php" class="btn btn--secondary">
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

 <!-- Template Settings -->
 <div class="card mb-lg">
 <div class="card-header">
 <h2 class="">
  <i data-lucide="settings"></i>
  Mallinställningar
 </h2>
 </div>
 <div class="card-body">
 <form method="POST">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="update_template">
  <input type="hidden" name="id" value="<?= $editTemplate['id'] ?>">

  <div class="grid grid-cols-1 md-grid-cols-2 gap-md">
  <div>
  <label class="label">Namn</label>
  <input type="text" name="name" class="input" value="<?= htmlspecialchars($editTemplate['name']) ?>" required>
  </div>
  <div>
  <label class="label">Beskrivning</label>
  <input type="text" name="description" class="input" value="<?= htmlspecialchars($editTemplate['description'] ?? '') ?>">
  </div>
  </div>

  <div class="mt-md">
  <label class="flex items-center gap-sm">
  <input type="checkbox" name="is_default" value="1" <?= $editTemplate['is_default'] ? 'checked' : '' ?>>
  <span>Standardmall för nya events</span>
  </label>
  </div>

  <!-- Pricing Settings -->
  <div class="mt-lg">
  <h3 class="mb-md">Prisregler</h3>
  <div class="grid grid-cols-1 md-grid-cols-2 gap-md">
  <div class="card p-md" style="background: var(--gs-success-bg);">
  <label class="label text-success">Early Bird (rabatt)</label>
  <div class="flex gap-sm items-center mt-sm">
   <input type="number" name="early_bird_percent" class="input"
   value="<?= $editTemplate['early_bird_percent'] ?? 15 ?>"
   min="0" max="100" style="width: 80px;">
   <span>% rabatt,</span>
   <input type="number" name="early_bird_days" class="input"
   value="<?= $editTemplate['early_bird_days_before'] ?? 21 ?>"
   min="0" max="90" style="width: 80px;">
   <span>dagar före event</span>
  </div>
  </div>
  <div class="card p-md" style="background: var(--gs-warning-bg);">
  <label class="label text-warning">Efteranmälan (tillägg)</label>
  <div class="flex gap-sm items-center mt-sm">
   <input type="number" name="late_fee_percent" class="input"
   value="<?= $editTemplate['late_fee_percent'] ?? 25 ?>"
   min="0" max="100" style="width: 80px;">
   <span>% tillägg,</span>
   <input type="number" name="late_fee_days" class="input"
   value="<?= $editTemplate['late_fee_days_before'] ?? 3 ?>"
   min="0" max="30" style="width: 80px;">
   <span>dagar före event</span>
  </div>
  </div>
  </div>
  </div>

  <div class="mt-md">
  <button type="submit" class="btn btn--primary">
  <i data-lucide="save"></i>
  Spara inställningar
  </button>
  </div>
 </form>
 </div>
 </div>

 <!-- Pricing Rules per Class -->
 <div class="card">
 <div class="card-header">
 <h2 class="">
  <i data-lucide="credit-card"></i>
  Priser per klass
 </h2>
 </div>
 <div class="card-body">
 <form method="POST">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save_prices">
  <input type="hidden" name="template_id" value="<?= $editTemplate['id'] ?>">

  <?php
  // Get template pricing settings for calculations
  $ebPercent = $editTemplate['early_bird_percent'] ?? 15;
  $latePercent = $editTemplate['late_fee_percent'] ?? 25;

  // Check if season_price column exists
  $hasSeasonPriceCol = false;
  try {
   $cols = $db->getAll("SHOW COLUMNS FROM pricing_template_rules LIKE 'season_price'");
   $hasSeasonPriceCol = !empty($cols);
  } catch (Exception $e) {}
  ?>

  <div class="table-responsive">
  <table class="table">
  <thead>
  <tr>
   <th>Klass</th>
   <th>Eventpris</th>
   <th>Early Bird (-<?= $ebPercent ?>%)</th>
   <th>Efteranmälan (+<?= $latePercent ?>%)</th>
   <?php if ($hasSeasonPriceCol): ?>
   <th>Säsongspris</th>
   <?php endif; ?>
  </tr>
  </thead>
  <tbody>
  <?php foreach ($classes as $class):
   $rule = $templateRules[$class['id']] ?? null;
   $basePrice = $rule['base_price'] ?? 0;
   $earlyBirdPrice = $rule['early_bird_price'] ?? 0;
   $lateFeePrice = $rule['late_fee_price'] ?? 0;
   $seasonPrice = $rule['season_price'] ?? 0;
   // Calculate suggested prices (rounded to whole numbers)
   $suggestedEbPrice = $basePrice > 0 ? round($basePrice * (1 - $ebPercent / 100)) : 0;
   $suggestedLatePrice = $basePrice > 0 ? round($basePrice * (1 + $latePercent / 100)) : 0;
  ?>
   <tr data-row="<?= $class['id'] ?>">
   <td>
   <input type="hidden" name="class_id[]" value="<?= $class['id'] ?>">
   <strong><?= htmlspecialchars($class['display_name'] ?: $class['name']) ?></strong>
   </td>
   <td>
   <div class="flex items-center gap-xs">
   <input type="number" name="base_price[]" class="input"
    data-class="<?= $class['id'] ?>"
    value="<?= $basePrice ?: '' ?>"
    min="0" step="1" style="width: 100px;"
    oninput="calculatePrices(<?= $class['id'] ?>, <?= $ebPercent ?>, <?= $latePercent ?>)">
   <span class="text-secondary">kr</span>
   </div>
   </td>
   <td>
   <div class="flex items-center gap-xs">
   <input type="number" name="early_bird_price[]" class="input"
    id="eb-input-<?= $class['id'] ?>"
    value="<?= $earlyBirdPrice ?: '' ?>"
    placeholder="<?= $suggestedEbPrice ?: '' ?>"
    min="0" step="1" style="width: 100px;"
    title="Lämna tom för automatisk beräkning (-<?= $ebPercent ?>%)">
   <span class="text-secondary">kr</span>
   <span id="eb-calc-<?= $class['id'] ?>" class="text-xs text-secondary" style="white-space: nowrap;">
   <?= $basePrice > 0 ? "(-{$ebPercent}%)" : '' ?>
   </span>
   </div>
   </td>
   <td>
   <div class="flex items-center gap-xs">
   <input type="number" name="late_fee_price[]" class="input"
    id="late-input-<?= $class['id'] ?>"
    value="<?= $lateFeePrice ?: '' ?>"
    placeholder="<?= $suggestedLatePrice ?: '' ?>"
    min="0" step="1" style="width: 100px;"
    title="Lämna tom för automatisk beräkning (+<?= $latePercent ?>%)">
   <span class="text-secondary">kr</span>
   <span id="late-calc-<?= $class['id'] ?>" class="text-xs text-secondary" style="white-space: nowrap;">
   <?= $basePrice > 0 ? "(+{$latePercent}%)" : '' ?>
   </span>
   </div>
   </td>
   <?php if ($hasSeasonPriceCol): ?>
   <td>
   <div class="flex items-center gap-xs">
   <input type="number" name="season_price[]" class="input"
    value="<?= $seasonPrice ?: '' ?>"
    min="0" step="1" style="width: 100px;"
    placeholder="<?= $basePrice > 0 ? number_format($basePrice * 4 * 0.85, 0) : '' ?>">
   <span class="text-secondary">kr</span>
   </div>
   </td>
   <?php endif; ?>
   </tr>
  <?php endforeach; ?>
  </tbody>
  </table>
  </div>

  <div class="alert alert--info mt-md">
   <div style="display: flex; gap: var(--space-sm); align-items: flex-start;">
    <i data-lucide="info" style="flex-shrink: 0; margin-top: 2px;"></i>
    <div style="font-size: 0.875rem;">
     <p style="margin: 0 0 var(--space-xs) 0;"><strong>Prissättning:</strong></p>
     <ul style="margin: 0; padding-left: var(--space-lg);">
      <li><strong>Eventpris:</strong> Grundpris för eventet (krävs)</li>
      <li><strong>Early Bird & Efteranmälan:</strong> Lämna tom för automatisk beräkning från % (rekommenderas), eller ange manuellt pris</li>
      <?php if ($hasSeasonPriceCol): ?>
      <li><strong>Säsongspris:</strong> Pris för hela serien (alla events). Lämna tomt om ej erbjuds.</li>
      <?php endif; ?>
      <li>Alla priser avrundas automatiskt till <strong>hela kronor</strong></li>
     </ul>
    </div>
   </div>
  </div>

  <div class="mt-lg">
  <button type="submit" class="btn btn--primary">
  <i data-lucide="save"></i>
  Spara priser
  </button>
  </div>
 </form>
 </div>
 </div>

 <?php else: ?>
 <!-- Templates List View -->
 <div class="flex items-center justify-between mb-lg">
 <h1 class="">
 <i data-lucide="file-text"></i>
 Prismallar
 </h1>
 <button type="button" class="btn btn--primary" onclick="openCreateModal()">
 <i data-lucide="plus"></i>
 Ny Prismall
 </button>
 </div>

 <?php if ($message): ?>
 <div class="alert alert-<?= $messageType ?> mb-lg">
 <?= htmlspecialchars($message) ?>
 </div>
 <?php endif; ?>

 <!-- Templates Table -->
 <div class="card">
 <div class="card-body">
 <?php if (empty($templates)): ?>
  <div class="alert alert--warning">
  <p>Inga prismallar skapade ännu. Skapa din första mall för att komma igång.</p>
  </div>
 <?php else: ?>
  <div class="table-responsive">
  <table class="table">
  <thead>
  <tr>
   <th>Namn</th>
   <th>Beskrivning</th>
   <th>Klasser</th>
   <th>Används av</th>
   <th>Åtgärder</th>
  </tr>
  </thead>
  <tbody>
  <?php foreach ($templates as $template): ?>
   <tr>
   <td>
   <strong><?= htmlspecialchars($template['name']) ?></strong>
   <?php if ($template['is_default']): ?>
   <span class="badge badge-success ml-sm">Standard</span>
   <?php endif; ?>
   </td>
   <td class="text-secondary">
   <?= htmlspecialchars($template['description'] ?? '-') ?>
   </td>
   <td>
   <span class="badge"><?= $template['rule_count'] ?> klasser</span>
   </td>
   <td>
   <?php if ($template['event_count'] > 0 || $template['series_count'] > 0): ?>
   <?php if ($template['event_count'] > 0): ?>
    <span class="text-sm"><?= $template['event_count'] ?> event</span>
   <?php endif; ?>
   <?php if ($template['series_count'] > 0): ?>
    <span class="text-sm"><?= $template['series_count'] ?> serier</span>
   <?php endif; ?>
   <?php else: ?>
   <span class="text-secondary">-</span>
   <?php endif; ?>
   </td>
   <td>
   <div class="flex gap-sm">
   <a href="?edit=<?= $template['id'] ?>" class="btn btn--sm btn--primary" title="Redigera priser">
    <i data-lucide="edit" class="icon-sm"></i>
   </a>
   <form method="POST" style="display: inline;" onsubmit="return confirm('Är du säker på att du vill ta bort denna mall?');">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="delete_template">
    <input type="hidden" name="id" value="<?= $template['id'] ?>">
    <button type="submit" class="btn btn--sm btn--secondary btn-danger" title="Ta bort">
    <i data-lucide="trash-2" class="icon-sm"></i>
    </button>
   </form>
   </div>
   </td>
   </tr>
  <?php endforeach; ?>
  </tbody>
  </table>
  </div>
 <?php endif; ?>
 </div>
 </div>

 <!-- Create Template Modal -->
 <div id="createModal" class="gs-modal hidden">
 <div class="gs-modal-overlay" onclick="closeCreateModal()"></div>
 <div class="gs-modal-content gs-modal-sm">
 <div class="gs-modal-header">
  <h2 class="gs-modal-title">
  <i data-lucide="plus"></i>
  Ny Prismall
  </h2>
  <button type="button" class="gs-modal-close" onclick="closeCreateModal()">
  <i data-lucide="x"></i>
  </button>
 </div>
 <form method="POST">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="create_template">

  <div class="gs-modal-body">
  <div class="mb-md">
  <label class="label">Namn <span class="text-error">*</span></label>
  <input type="text" name="name" class="input" required placeholder="T.ex. GravitySeries Standard">
  </div>
  <div class="mb-md">
  <label class="label">Beskrivning</label>
  <input type="text" name="description" class="input" placeholder="Kort beskrivning av mallen">
  </div>
  <div>
  <label class="flex items-center gap-sm">
  <input type="checkbox" name="is_default" value="1">
  <span>Standardmall för nya events</span>
  </label>
  </div>
  </div>

  <div class="gs-modal-footer">
  <button type="button" class="btn btn--secondary" onclick="closeCreateModal()">Avbryt</button>
  <button type="submit" class="btn btn--primary">
  <i data-lucide="plus"></i>
  Skapa
  </button>
  </div>
 </form>
 </div>
 </div>

 <script>
 function openCreateModal() {
     const modal = document.getElementById('createModal');
     modal.classList.remove('hidden');
     modal.style.display = 'flex';
     if (typeof lucide !== 'undefined') lucide.createIcons();
 }
 function closeCreateModal() {
     const modal = document.getElementById('createModal');
     modal.classList.add('hidden');
     modal.style.display = 'none';
 }
 document.addEventListener('keydown', function(e) {
     if (e.key === 'Escape') closeCreateModal();
 });
 // Auto-open modal if ?action=create is in URL
 if (window.location.search.includes('action=create')) {
     document.addEventListener('DOMContentLoaded', openCreateModal);
 }
 </script>
 <?php endif; ?>

<script>
function calculatePrices(classId, ebPercent, latePercent) {
 const row = document.querySelector(`tr[data-row="${classId}"]`);
 if (!row) return;

 const baseInput = row.querySelector('input[data-class="' + classId + '"]');
 const ebInput = document.getElementById(`eb-input-${classId}`);
 const lateInput = document.getElementById(`late-input-${classId}`);
 const ebCalc = document.getElementById(`eb-calc-${classId}`);
 const lateCalc = document.getElementById(`late-calc-${classId}`);

 if (!baseInput || !ebInput || !lateInput) return;

 const base = parseFloat(baseInput.value) || 0;

 if (base > 0) {
 const ebPrice = Math.round(base * (1 - ebPercent / 100));
 const latePrice = Math.round(base * (1 + latePercent / 100));

 // Update placeholders (suggested values)
 ebInput.placeholder = ebPrice;
 lateInput.placeholder = latePrice;

 // Update calculation labels
 if (ebCalc) ebCalc.textContent = `(-${ebPercent}%)`;
 if (lateCalc) lateCalc.textContent = `(+${latePercent}%)`;
 } else {
 ebInput.placeholder = '';
 lateInput.placeholder = '';
 if (ebCalc) ebCalc.textContent = '';
 if (lateCalc) lateCalc.textContent = '';
 }
}
</script>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>

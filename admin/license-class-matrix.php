<?php
/**
 * License-Class Matrix Admin
 *
 * Visual matrix for managing which license types can register for which classes.
 * Three tabs for different event license classes: National, Sportmotion, Motion
 */

require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();
$message = '';
$messageType = 'info';

// Available event license classes
$eventLicenseClasses = [
 'national' => ['name' => 'Nationellt', 'icon' => 'trophy', 'color' => 'warning', 'desc' => 'Nationella tävlingar med full rankingpoäng'],
 'sportmotion' => ['name' => 'Sportmotion', 'icon' => 'bike', 'color' => 'info', 'desc' => 'Sportmotion-event med 50% rankingpoäng'],
 'motion' => ['name' => 'Motion', 'icon' => 'heart', 'color' => 'success', 'desc' => 'Motion-event utan rankingpoäng']
];

// Current tab
$currentTab = $_GET['tab'] ?? 'national';
if (!array_key_exists($currentTab, $eventLicenseClasses)) {
 $currentTab = 'national';
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
 checkCsrf();

 $action = $_POST['action'] ?? '';
 $saveTab = $_POST['event_license_class'] ?? 'national';

 if ($action === 'save_matrix' && array_key_exists($saveTab, $eventLicenseClasses)) {
 try {
  // Clear existing mappings for this event_license_class
  $db->query("DELETE FROM class_license_eligibility WHERE event_license_class = ?", [$saveTab]);

  // Insert new mappings
  $mappings = $_POST['mapping'] ?? [];
  $inserted = 0;

  foreach ($mappings as $classId => $licenseTypes) {
  foreach ($licenseTypes as $licenseCode => $value) {
   if ($value === '1') {
   $db->insert('class_license_eligibility', [
    'event_license_class' => $saveTab,
    'class_id' => (int)$classId,
    'license_type_code' => $licenseCode,
    'is_allowed' => 1
   ]);
   $inserted++;
   }
  }
  }

  $message ="Matris för '{$eventLicenseClasses[$saveTab]['name']}' sparad! $inserted kopplingar skapade.";
  $messageType = 'success';
  $currentTab = $saveTab; // Stay on same tab
 } catch (Exception $e) {
  $message = 'Fel: ' . $e->getMessage();
  $messageType = 'error';
 }
 }
}

// Get all license types from database
$licenseTypes = [];
try {
 $licenseTypes = $db->getAll("
 SELECT code, name, description, priority
 FROM license_types
 WHERE is_active = 1
 ORDER BY priority DESC
");
} catch (Exception $e) {
 // Fallback to basic types if table doesn't exist
 $licenseTypes = [
 ['code' => 'engangslicens', 'name' => 'Engångslicens', 'description' => 'För enstaka tävlingar', 'priority' => 10],
 ['code' => 'motionslicens', 'name' => 'Motionslicens', 'description' => 'För motion/sportmotion', 'priority' => 20],
 ['code' => 'tavlingslicens', 'name' => 'Tävlingslicens', 'description' => 'Youth, Junior, Elite, Master etc', 'priority' => 100]
 ];
}

// Get all active classes
$classes = $db->getAll("
 SELECT id, name, display_name, gender, discipline
 FROM classes
 WHERE active = 1
 ORDER BY sort_order ASC, name ASC
");

// Get current mappings for the current tab
$currentMappings = [];
try {
 $mappings = $db->getAll(
 "SELECT class_id, license_type_code FROM class_license_eligibility WHERE event_license_class = ? AND is_allowed = 1",
 [$currentTab]
 );
 foreach ($mappings as $m) {
 $currentMappings[$m['class_id']][$m['license_type_code']] = true;
 }
} catch (Exception $e) {
 // Table might not exist yet or column missing - run migration 041
 $message = 'Kör migration 041 först för att uppdatera databasschemat.';
 $messageType = 'warning';
}

$page_title = 'Licens-Klass Matris';
$page_group = 'config';
include __DIR__ . '/components/unified-layout.php';
?>

 <!-- Messages -->
 <?php if ($message): ?>
  <div class="alert alert-<?= $messageType ?> mb-lg">
  <i data-lucide="<?= $messageType === 'success' ? 'check-circle' : ($messageType === 'error' ? 'alert-circle' : 'info') ?>"></i>
  <?= h($message) ?>
  </div>
 <?php endif; ?>

 <!-- Event License Class Tabs -->
 <div class="card mb-md">
  <div class="card-body gs-p-0">
  <nav class="admin-tabs" role="tablist">
   <?php foreach ($eventLicenseClasses as $key => $info): ?>
   <a href="?tab=<?= $key ?>"
    class="admin-tabs__tab <?= $currentTab === $key ? 'admin-tabs__tab--active' : '' ?>"
    role="tab">
    <i data-lucide="<?= $info['icon'] ?>"></i>
    <span><?= h($info['name']) ?></span>
   </a>
   <?php endforeach; ?>
  </nav>
  </div>
 </div>


 <?php if (empty($classes)): ?>
  <div class="card">
  <div class="card-body text-center gs-padding-xl">
   <i data-lucide="alert-triangle" class="gs-icon-48-empty"></i>
   <h3 class="mt-md">Inga klasser</h3>
   <p class="text-secondary">Skapa klasser först under Konfiguration → Klasser.</p>
  </div>
  </div>
 <?php else: ?>

 <div class="card">
  <div class="card-header">
  <h2 class="">
   <i data-lucide="grid-3x3"></i>
   <?= h($eventLicenseClasses[$currentTab]['name']) ?>-matris
  </h2>
  </div>
  <div class="card-body">
  <form method="POST" id="matrixForm">
   <?= csrf_field() ?>
   <input type="hidden" name="action" value="save_matrix">
   <input type="hidden" name="event_license_class" value="<?= h($currentTab) ?>">

   <div class="table-responsive" style="max-height: 60vh; overflow: auto;">
   <table class="table table-compact" style="font-size: 0.85rem;">
    <thead style="position: sticky; top: 0; background: var(--gs-bg); z-index: 10;">
    <tr>
     <th style="position: sticky; left: 0; background: var(--gs-bg); z-index: 11; min-width: 140px;">
     Klass
     </th>
     <?php foreach ($licenseTypes as $license): ?>
     <th class="text-center" style="min-width: 80px; padding: 8px 4px;">
      <?= h($license['name']) ?>
     </th>
     <?php endforeach; ?>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($classes as $class): ?>
     <tr>
     <td style="position: sticky; left: 0; background: var(--gs-bg); z-index: 1;">
      <strong><?= h($class['display_name'] ?: $class['name']) ?></strong>
      <?php if ($class['gender'] === 'M'): ?>
      <span class="badge badge-info gs-ml-xs">♂</span>
      <?php elseif ($class['gender'] === 'K' || $class['gender'] === 'F'): ?>
      <span class="badge badge-error gs-ml-xs">♀</span>
      <?php else: ?>
      <span class="badge gs-ml-xs">Mix</span>
      <?php endif; ?>
     </td>
     <?php foreach ($licenseTypes as $license): ?>
      <td class="text-center" style="padding: 4px;">
      <input type="hidden"
       name="mapping[<?= $class['id'] ?>][<?= h($license['code']) ?>]"
       value="0">
      <label style="cursor: pointer; display: block; padding: 4px;">
       <input type="checkbox"
        name="mapping[<?= $class['id'] ?>][<?= h($license['code']) ?>]"
        value="1"
        <?= isset($currentMappings[$class['id']][$license['code']]) ? 'checked' : '' ?>
        style="width: 18px; height: 18px; cursor: pointer;">
      </label>
      </td>
     <?php endforeach; ?>
     </tr>
    <?php endforeach; ?>
    </tbody>
   </table>
   </div>

   <div class="flex justify-between items-center mt-lg gs-pt-lg" style="border-top: 1px solid var(--border);">
   <div class="flex gap-sm">
    <button type="button" class="btn btn--secondary btn--sm" onclick="selectAll()">
    <i data-lucide="check-square"></i>
    Markera alla
    </button>
    <button type="button" class="btn btn--secondary btn--sm" onclick="deselectAll()">
    <i data-lucide="square"></i>
    Avmarkera alla
    </button>
    <button type="button" class="btn btn--secondary btn--sm" onclick="selectColumn('engangslicens')">
    Alla Engångs
    </button>
    <button type="button" class="btn btn--secondary btn--sm" onclick="selectColumn('motionslicens')">
    Alla Motion
    </button>
    <button type="button" class="btn btn--secondary btn--sm" onclick="selectColumn('tavlingslicens')">
    Alla Tävling
    </button>
   </div>
   <button type="submit" class="btn btn--primary">
    <i data-lucide="save"></i>
    Spara <?= h($eventLicenseClasses[$currentTab]['name']) ?>-matris
   </button>
   </div>
  </form>
  </div>
 </div>

 <?php endif; ?>


<script>
function selectAll() {
 document.querySelectorAll('#matrixForm input[type="checkbox"]').forEach(cb => cb.checked = true);
}

function deselectAll() {
 document.querySelectorAll('#matrixForm input[type="checkbox"]').forEach(cb => cb.checked = false);
}

function selectColumn(licenseCode) {
 document.querySelectorAll(`#matrixForm input[type="checkbox"][name*="[${licenseCode}]"]`).forEach(cb => cb.checked = true);
}
</script>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>

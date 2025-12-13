<?php
require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();
$current_admin = get_current_admin();

// Initialize message variables
$message = '';
$messageType = 'info';

// Load current settings
$settingsFile = __DIR__ . '/../config/public_settings.php';
$currentSettings = require $settingsFile;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
 checkCsrf();

 $action = $_POST['action'] ?? '';

 if ($action === 'save_settings') {
 $public_riders_display = $_POST['public_riders_display'] ?? 'with_results';
 $min_results_to_show = intval($_POST['min_results_to_show'] ?? 1);

 // Validate
 if (!in_array($public_riders_display, ['all', 'with_results'])) {
  $message = 'Ogiltigt värde för visningsläge';
  $messageType = 'error';
 } elseif ($min_results_to_show < 1) {
  $message = 'Minsta antal resultat måste vara minst 1';
  $messageType = 'error';
 } else {
  // Create new settings array
  $newSettings = [
  'public_riders_display' => $public_riders_display,
  'min_results_to_show' => $min_results_to_show,
  ];

  // Generate PHP code for the settings file
  $phpCode ="<?php\n";
  $phpCode .="/**\n";
  $phpCode .=" * Public Display Settings\n";
  $phpCode .=" * Configure what data is visible on the public website\n";
  $phpCode .=" */\n\n";
  $phpCode .="return [\n";
  $phpCode .=" // Show all riders publicly or only those with results\n";
  $phpCode .=" // Options: 'all' or 'with_results'\n";
  $phpCode .=" 'public_riders_display' => '{$newSettings['public_riders_display']}',\n\n";
  $phpCode .=" // Minimum number of results required to show rider (when 'with_results' is selected)\n";
  $phpCode .=" 'min_results_to_show' => {$newSettings['min_results_to_show']},\n";
  $phpCode .="];\n";

  // Write to file
  if (file_put_contents($settingsFile, $phpCode) !== false) {
  $currentSettings = $newSettings;
  $message = 'Inställningar sparade!';
  $messageType = 'success';
  } else {
  $message = 'Kunde inte spara inställningar. Kontrollera filrättigheter.';
  $messageType = 'error';
  }
 }
 }
}

// Get statistics
$total_riders = $db->getRow("SELECT COUNT(*) as count FROM riders WHERE active = 1")['count'] ?? 0;
$riders_with_results = $db->getRow("
 SELECT COUNT(DISTINCT c.id) as count
 FROM riders c
 INNER JOIN results r ON c.id = r.cyclist_id
 WHERE c.active = 1
")['count'] ?? 0;
$riders_without_results = $total_riders - $riders_with_results;

$page_title = 'Publika Inställningar';
$page_group = 'config';
include __DIR__ . '/components/unified-layout.php';
?>

<div class="gs-max-w-900">

 <!-- Messages -->
 <?php if ($message): ?>
  <div class="alert alert-<?= $messageType ?> mb-lg">
  <i data-lucide="<?= $messageType === 'success' ? 'check-circle' : ($messageType === 'error' ? 'alert-circle' : 'info') ?>"></i>
  <?= h($message) ?>
  </div>
 <?php endif; ?>

 <!-- Statistics -->
 <div class="card mb-lg">
  <div class="card-header">
  <h2 class="">
   <i data-lucide="bar-chart"></i>
   Statistik
  </h2>
  </div>
  <div class="card-body">
  <div class="grid grid-cols-1 md-grid-cols-3 gap-md">
   <div class="stat-card">
   <i data-lucide="users" class="icon-lg text-primary mb-md"></i>
   <div class="stat-number"><?= $total_riders ?></div>
   <div class="stat-label">Totalt aktiva deltagare</div>
   </div>
   <div class="stat-card">
   <i data-lucide="trophy" class="icon-lg text-success mb-md"></i>
   <div class="stat-number"><?= $riders_with_results ?></div>
   <div class="stat-label">Med resultat</div>
   </div>
   <div class="stat-card">
   <i data-lucide="user-x" class="icon-lg text-secondary mb-md"></i>
   <div class="stat-number"><?= $riders_without_results ?></div>
   <div class="stat-label">Utan resultat</div>
   </div>
  </div>
  </div>
 </div>

 <!-- Settings Form -->
 <div class="card">
  <div class="card-header">
  <h2 class="">
   <i data-lucide="eye"></i>
   Synlighet för Deltagare
  </h2>
  </div>
  <form method="POST" class="card-body">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save_settings">

  <div class="mb-lg">
   <p class="text-secondary mb-md">
   Välj vilka deltagare som ska visas på den publika deltagarsidan (<code>/riders.php</code>).
   </p>

   <div class="alert alert--info mb-md">
   <i data-lucide="info"></i>
   <strong>Nuvarande inställning:</strong>
   <?php if ($currentSettings['public_riders_display'] === 'all'): ?>
    Visar alla <?= $total_riders ?> aktiva deltagare
   <?php else: ?>
    Visar bara deltagare med resultat (<?= $riders_with_results ?> deltagare)
   <?php endif; ?>
   </div>
  </div>

  <!-- Display Mode -->
  <div class="mb-lg">
   <label class="label mb-sm">
   <i data-lucide="users"></i>
   Visningsläge
   </label>

   <div class="flex flex-col gap-md">
   <label class="gs-radio-card <?= $currentSettings['public_riders_display'] === 'with_results' ? 'active' : '' ?>">
    <input
    type="radio"
    name="public_riders_display"
    value="with_results"
    class="gs-radio"
    <?= $currentSettings['public_riders_display'] === 'with_results' ? 'checked' : '' ?>
    >
    <div class="gs-radio-card-content">
    <div class="gs-radio-card-title">
     <i data-lucide="trophy"></i>
     Endast deltagare med resultat
    </div>
    <div class="gs-radio-card-description">
     Visar bara de <?= $riders_with_results ?> deltagare som har tävlat och har minst ett resultat registrerat.
     <strong>Rekommenderat för offentlig visning.</strong>
    </div>
    </div>
   </label>

   <label class="gs-radio-card <?= $currentSettings['public_riders_display'] === 'all' ? 'active' : '' ?>">
    <input
    type="radio"
    name="public_riders_display"
    value="all"
    class="gs-radio"
    <?= $currentSettings['public_riders_display'] === 'all' ? 'checked' : '' ?>
    >
    <div class="gs-radio-card-content">
    <div class="gs-radio-card-title">
     <i data-lucide="users"></i>
     Alla aktiva deltagare
    </div>
    <div class="gs-radio-card-description">
     Visar alla <?= $total_riders ?> aktiva deltagare, även de utan resultat.
     Inkluderar <?= $riders_without_results ?> deltagare som inte har några registrerade resultat än.
    </div>
    </div>
   </label>
   </div>
  </div>

  <!-- Minimum Results (shown only when 'with_results' is selected) -->
  <div class="mb-lg <?= $currentSettings['public_riders_display'] === 'with_results' ? '' : 'hidden' ?>" id="minResultsSection">
   <label for="min_results_to_show" class="label">
   <i data-lucide="hash"></i>
   Minsta antal resultat
   </label>
   <input
   type="number"
   id="min_results_to_show"
   name="min_results_to_show"
   class="input gs-max-w-200"
   min="1"
   value="<?= $currentSettings['min_results_to_show'] ?>"
   >
   <small class="text-muted">
   Deltagare måste ha minst detta antal registrerade resultat för att visas.
   </small>
  </div>

  <!-- Save Button -->
  <div class="flex gs-justify-end gap-md gs-pt-md border-top">
   <a href="/admin/dashboard.php" class="btn btn--secondary">
   <i data-lucide="x"></i>
   Avbryt
   </a>
   <button type="submit" class="btn btn--primary">
   <i data-lucide="save"></i>
   Spara Inställningar
   </button>
  </div>
  </form>
 </div>

 <!-- Preview -->
 <div class="card mt-lg">
  <div class="card-header">
  <h2 class="">
   <i data-lucide="external-link"></i>
   Förhandsgranskning
  </h2>
  </div>
  <div class="card-body">
  <p class="text-secondary mb-md">
   Se hur den publika deltagarsidan ser ut med nuvarande inställningar.
  </p>
  <a href="/riders.php" target="_blank" class="btn btn--secondary">
   <i data-lucide="eye"></i>
   Öppna Publika Deltagarsidan
  </a>
  </div>
 </div>
</div>


<script>
// Show/hide minimum results section based on selected display mode
document.addEventListener('DOMContentLoaded', function() {
 const radios = document.querySelectorAll('input[name="public_riders_display"]');
 const minResultsSection = document.getElementById('minResultsSection');

 radios.forEach(radio => {
 radio.addEventListener('change', function() {
  if (this.value === 'with_results') {
  minResultsSection.style.display = 'block';
  } else {
  minResultsSection.style.display = 'none';
  }

  // Update active class on radio cards
  document.querySelectorAll('.gs-radio-card').forEach(card => {
  card.classList.remove('active');
  });
  this.closest('.gs-radio-card').classList.add('active');
 });
 });
});
</script>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>

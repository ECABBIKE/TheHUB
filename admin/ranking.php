<?php
/**
 * Admin Ranking Settings
 * Manage the 24-month rolling ranking system for Enduro, Downhill, and Gravity
 */

// Enable error reporting to catch any issues
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

// Wrap everything in try-catch to catch early failures
try {
 require_once __DIR__ . '/../config.php';
 require_once __DIR__ . '/../includes/ranking_functions.php';
 require_admin();
 require_once __DIR__ . '/../includes/admin-layout.php';

 $db = getDB();
 $current_admin = get_current_admin();
} catch (Exception $e) {
 // Show error if something fails during initialization
 echo"<h1>Initialization Error</h1>";
 echo"<pre>";
 echo"Message:" . htmlspecialchars($e->getMessage()) ."\n\n";
 echo"File:" . htmlspecialchars($e->getFile()) ."\n";
 echo"Line:" . $e->getLine() ."\n\n";
 echo"Stack trace:\n" . htmlspecialchars($e->getTraceAsString());
 echo"</pre>";
 echo"<p><a href='/admin/check-ranking-tables.php'>Check Database Tables</a> | <a href='/admin/'>Back to Admin</a></p>";
 exit;
}

$message = '';
$messageType = 'info';

// Check if tables exist
if (!rankingTablesExist($db)) {
 $message = 'Rankingtabeller saknas. Kör migration 028_ranking_system.sql för att skapa dem.';
 $messageType = 'warning';
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
 checkCsrf();

 if (isset($_POST['calculate'])) {
 // Run full ranking update (lightweight on-the-fly calculation with snapshots)
 // Show simple progress output
 echo"<!DOCTYPE html><html><head><title>Ranking Calculation</title></head><body>";
 echo"<h1>Beräknar rankings...</h1>";
 echo"<p style='padding: 20px;'>Detta kan ta några sekunder...</p>";
 flush();

 try {
 $stats = runFullRankingUpdate($db, false); // No verbose debug output

 echo"<h2 style='color: green;'>✅ Beräkning Klar!</h2>";
 echo"<p><strong>Tid:</strong> {$stats['total_time']}s</p>";
 echo"<p><strong>Åkare:</strong> Enduro {$stats['enduro']['riders']}, DH {$stats['dh']['riders']}, Gravity {$stats['gravity']['riders']}</p>";
 echo"<p><strong>Klubbar:</strong> Enduro {$stats['enduro']['clubs']}, DH {$stats['dh']['clubs']}, Gravity {$stats['gravity']['clubs']}</p>";
 echo"<p><a href='/admin/ranking.php'>← Tillbaka till Ranking Admin</a> | <a href='/ranking/'>Visa Ranking →</a></p>";
 echo"</body></html>";
 exit;
 } catch (Exception $e) {
 echo"<h2 style='color: red;'>❌ Fel vid beräkning</h2>";
 echo"<pre>" . htmlspecialchars($e->getMessage()) ."\n\n" . htmlspecialchars($e->getTraceAsString()) ."</pre>";
 echo"<p><a href='/admin/ranking.php'>← Tillbaka</a></p>";
 echo"</body></html>";
 error_log("Ranking calculation error:" . $e->getMessage());
 error_log($e->getTraceAsString());
 exit;
 }

 } elseif (isset($_POST['save_multipliers'])) {
 // Save field multipliers
 try {
 $multipliers = [];
 for ($i = 1; $i <= 15; $i++) {
 $key ="mult_$i";
 if (isset($_POST[$key])) {
  $multipliers[$i] = max(0, min(1, (float)$_POST[$key]));
 }
 }

 if (count($multipliers) === 15) {
 saveFieldMultipliers($db, $multipliers);
 $message = 'Fältstorleksmultiplikatorer sparade.';
 $messageType = 'success';
 } else {
 $message = 'Alla 15 multiplikatorer måste anges. Hittade bara ' . count($multipliers) . ' st.';
 $messageType = 'error';
 }
 } catch (Exception $e) {
 $message = 'Fel vid sparande: ' . $e->getMessage();
 $messageType = 'error';
 error_log("Save multipliers error:" . $e->getMessage());
 }

 } elseif (isset($_POST['save_decay'])) {
 // Save time decay settings
 $timeDecay = [
 'months_1_12' => max(0, min(1, (float)$_POST['decay_1_12'])),
 'months_13_24' => max(0, min(1, (float)$_POST['decay_13_24'])),
 'months_25_plus' => max(0, min(1, (float)$_POST['decay_25_plus']))
 ];

 saveTimeDecay($db, $timeDecay);
 $message = 'Tidsviktning sparad.';
 $messageType = 'success';

 } elseif (isset($_POST['save_event_level'])) {
 // Save event level multipliers
 $eventLevel = [
 'national' => max(0, min(1, (float)$_POST['level_national'])),
 'sportmotion' => max(0, min(1, (float)$_POST['level_sportmotion']))
 ];

 saveEventLevelMultipliers($db, $eventLevel);
 $message = 'Eventtypsviktning sparad.';
 $messageType = 'success';

 } elseif (isset($_POST['reset_defaults'])) {
 // Reset to defaults
 try {
 saveFieldMultipliers($db, getDefaultFieldMultipliers());
 saveTimeDecay($db, getDefaultTimeDecay());
 saveEventLevelMultipliers($db, getDefaultEventLevelMultipliers());
 $message = 'Inställningar återställda till standardvärden.';
 $messageType = 'success';
 } catch (Exception $e) {
 $message = 'Fel vid återställning: ' . $e->getMessage();
 $messageType = 'error';
 error_log("Reset defaults error:" . $e->getMessage());
 }
 }
}

// Get current settings - wrap in try-catch to handle missing tables
try {
 $multipliers = getRankingFieldMultipliers($db);
 $timeDecay = getRankingTimeDecay($db);
 $eventLevelMultipliers = getEventLevelMultipliers($db);
 $lastCalc = getLastRankingCalculation($db);

 // Get statistics per discipline
 $disciplineStats = getRankingStats($db);

 // Get last snapshot date
 $latestSnapshot = $db->getRow("SELECT MAX(snapshot_date) as snapshot_date FROM ranking_snapshots");
 $lastSnapshotDate = $latestSnapshot ? $latestSnapshot['snapshot_date'] : null;
} catch (Exception $e) {
 // If settings can't be loaded, show clear error
 echo"<h1>Database Error</h1>";
 echo"<p>Could not load ranking settings. The ranking tables may not exist yet.</p>";
 echo"<pre>";
 echo"Error:" . htmlspecialchars($e->getMessage()) ."\n\n";
 echo"File:" . htmlspecialchars($e->getFile()) ."\n";
 echo"Line:" . $e->getLine() ."\n\n";
 echo"Stack trace:\n" . htmlspecialchars($e->getTraceAsString());
 echo"</pre>";
 echo"<p><strong>Solution:</strong></p>";
 echo"<ul>";
 echo"<li><a href='/admin/check-ranking-tables.php'>Check Database Tables</a> - Diagnose what's missing</li>";
 echo"<li><a href='/admin/migrate.php'>Run Migrations</a> - Create the ranking tables</li>";
 echo"<li><a href='/admin/'>Back to Admin</a></li>";
 echo"</ul>";
 exit;
}

$pageTitle = 'Ranking';
$pageType = 'admin';

// DEBUG: Output before header
echo"<!-- DEBUG: About to include header -->\n";
flush();

include __DIR__ . '/../includes/layout-header.php';

// DEBUG: Output after header
echo"<!-- DEBUG: Header included, starting main content -->\n";
flush();
?>

<main class="main-content">
 <div class="container">
 <?php render_admin_header('Serier & Poäng'); ?>
 <div class="mb-lg">
 <a href="/ranking/" class="btn btn--secondary" target="_blank">
 <i data-lucide="external-link"></i>
 Publik vy
 </a>
 </div>

 <!-- Messages -->
 <?php if ($message): ?>
 <div class="alert alert-<?= $messageType ?> mb-lg">
 <i data-lucide="<?= $messageType === 'success' ? 'check-circle' : ($messageType === 'error' ? 'alert-circle' : 'info') ?>"></i>
 <?php if ($messageType === 'error'): ?>
  <pre style="white-space: pre-wrap; font-size: 0.875rem; margin-top: 0.5rem; overflow-x: auto;"><?= h($message) ?></pre>
 <?php else: ?>
  <?= h($message) ?>
 <?php endif; ?>
 </div>
 <?php endif; ?>

 <!-- Statistics Cards per Discipline -->
 <div class="grid grid-cols-3 gap-md mb-lg">
 <!-- Enduro -->
 <div class="card">
 <div class="card-body text-center">
  <h3 class="text-sm text-secondary mb-sm">Enduro</h3>
  <div class="text-2xl font-bold text-primary"><?= $disciplineStats['ENDURO']['riders'] ?></div>
  <div class="text-xs text-secondary">åkare • <?= $disciplineStats['ENDURO']['clubs'] ?> klubbar</div>
  <div class="text-xs text-secondary"><?= $disciplineStats['ENDURO']['events'] ?> events</div>
 </div>
 </div>
 <!-- Downhill -->
 <div class="card">
 <div class="card-body text-center">
  <h3 class="text-sm text-secondary mb-sm">Downhill</h3>
  <div class="text-2xl font-bold text-primary"><?= $disciplineStats['DH']['riders'] ?></div>
  <div class="text-xs text-secondary">åkare • <?= $disciplineStats['DH']['clubs'] ?> klubbar</div>
  <div class="text-xs text-secondary"><?= $disciplineStats['DH']['events'] ?> events</div>
 </div>
 </div>
 <!-- Gravity -->
 <div class="card">
 <div class="card-body text-center">
  <h3 class="text-sm text-secondary mb-sm">Gravity</h3>
  <div class="text-2xl font-bold text-primary"><?= $disciplineStats['GRAVITY']['riders'] ?></div>
  <div class="text-xs text-secondary">åkare • <?= $disciplineStats['GRAVITY']['clubs'] ?> klubbar</div>
  <div class="text-xs text-secondary"><?= $disciplineStats['GRAVITY']['events'] ?> events</div>
 </div>
 </div>
 </div>

 <!-- Info and Calculation Card -->
 <div class="grid grid-cols-2 gap-lg mb-lg">
 <!-- Info Card -->
 <div class="card">
 <div class="card-header">
  <h2 class="text-primary">
  <i data-lucide="info"></i>
  Om rankingsystemet
  </h2>
 </div>
 <div class="card-body">
  <ul class="text-sm" style="margin: 0; padding-left: 1.5rem; line-height: 1.8;">
  <li>Tre rankingar: <strong>Enduro</strong>, <strong>Downhill</strong>, <strong>Gravity</strong> (kombinerad)</li>
  <li>24 månaders rullande fönster</li>
  <li>Poäng viktas efter fältstorlek (antal deltagare i klassen)</li>
  <li>Nationella event: 100%, Sportmotion: 50% (justerbart)</li>
  <li>Senaste 12 månader: 100% av poängen</li>
  <li>Månad 13-24: 50% av poängen</li>
  <li>Uppdateras automatiskt 1:e varje månad</li>
  </ul>
 </div>
 </div>

 <!-- Calculation Card -->
 <div class="card">
 <div class="card-header">
  <h2 class="text-primary">
  <i data-lucide="calculator"></i>
  Beräkning
  </h2>
 </div>
 <div class="card-body">
  <p class="text-sm text-secondary mb-md">
  Senaste beräkning:
  <strong><?= $lastCalc['date'] ? date('Y-m-d H:i', strtotime($lastCalc['date'])) : 'Aldrig' ?></strong>
  <?php if ($lastCalc['date'] && isset($lastCalc['stats']['total_time'])): ?>
  <br>
  Tog <?= $lastCalc['stats']['total_time'] ?>s att köra
  <?php endif; ?>
  <br><br>
  Senaste snapshot:
  <strong><?= $lastSnapshotDate ? date('Y-m-d', strtotime($lastSnapshotDate)) : 'Aldrig' ?></strong>
  </p>

  <form method="POST" style="display: inline-block;">
  <?= csrf_field() ?>
  <button type="submit" name="calculate" class="btn btn--primary"
  onclick="return confirm('Kör fullständig omräkning av alla rankingpoäng?')">
  <i data-lucide="refresh-cw"></i>
  Kör beräkning
  </button>
  </form>
  <a href="/admin/recalculate-all-points.php" class="btn btn--secondary" style="margin-left: 0.5rem;">
  <i data-lucide="calculator"></i>
  Räkna om alla poäng
  </a>
 </div>
 </div>
 </div>

 <!-- Event Level Multipliers -->
 <div class="card mb-lg">
 <div class="card-header">
 <h2 class="text-primary">
  <i data-lucide="trophy"></i>
  Eventtypsviktning
 </h2>
 </div>
 <div class="card-body">
 <p class="text-sm text-secondary mb-lg">
  Nationella tävlingar ger fulla poäng. Sportmotion-event kan viktas ned för att spegla lägre tävlingsnivå.
 </p>

 <form method="POST">
  <?= csrf_field() ?>

  <div class="grid grid-cols-2 gap-lg">
  <div class="form-group">
  <label for="level_national" class="label">Nationell tävling</label>
  <input type="number" id="level_national" name="level_national"
   value="<?= number_format($eventLevelMultipliers['national'], 2) ?>"
   min="0" max="1" step="0.01"
   class="input">
  <small class="text-secondary">Officiella tävlingar (standard 100%)</small>
  </div>
  <div class="form-group">
  <label for="level_sportmotion" class="label">Sportmotion</label>
  <input type="number" id="level_sportmotion" name="level_sportmotion"
   value="<?= number_format($eventLevelMultipliers['sportmotion'], 2) ?>"
   min="0" max="1" step="0.01"
   class="input">
  <small class="text-secondary">Breddtävlingar (standard 50%)</small>
  </div>
  </div>

  <div class="flex gap-sm mt-lg">
  <button type="submit" name="save_event_level" class="btn btn--primary">
  <i data-lucide="save"></i>
  Spara eventtypsviktning
  </button>
  </div>
 </form>
 </div>
 </div>

 <!-- Field Multipliers -->
 <div class="card mb-lg">
 <div class="card-header">
 <h2 class="text-primary">
  <i data-lucide="users"></i>
  Fältstorleksmultiplikatorer
 </h2>
 </div>
 <div class="card-body">
 <p class="text-sm text-secondary mb-lg">
  Ju fler åkare i klassen, desto mer värda är poängen. Multiplikatorn anger hur stor andel av originalpoängen som blir rankingpoäng.
 </p>

 <form method="POST" id="multipliersForm">
  <?= csrf_field() ?>

  <!-- Visual bar chart -->
  <div class="mb-lg" style="height: 120px; display: flex; align-items: flex-end; gap: 2px;">
  <?php for ($i = 1; $i <= 15; $i++): ?>
  <?php $value = $multipliers[$i] ?? 0.75; ?>
  <div style="flex: 1; display: flex; flex-direction: column; align-items: center;">
  <div id="bar_<?= $i ?>"
   style="width: 100%; background: var(--primary); border-radius: 2px 2px 0 0; transition: height 0.2s;"
   data-value="<?= $value ?>">
  </div>
  </div>
  <?php endfor; ?>
  </div>

  <!-- Input grid -->
  <div style="display: grid; grid-template-columns: repeat(15, 1fr); gap: 4px; font-size: 0.75rem;">
  <?php for ($i = 1; $i <= 15; $i++): ?>
  <div style="text-align: center;">
  <label style="display: block; color: var(--text-secondary); margin-bottom: 2px;">
   <?= $i === 15 ? '15+' : $i ?>
  </label>
  <input type="number"
   name="mult_<?= $i ?>"
   id="mult_<?= $i ?>"
   value="<?= number_format($multipliers[$i] ?? 0.75, 2) ?>"
   min="0" max="1" step="0.01"
   class="input"
   style="padding: 4px; text-align: center; font-size: 0.75rem;"
   oninput="updateBar(<?= $i ?>, this.value)">
  </div>
  <?php endfor; ?>
  </div>

  <div class="flex gap-sm mt-lg">
  <button type="submit" name="save_multipliers" class="btn btn--primary">
  <i data-lucide="save"></i>
  Spara multiplikatorer
  </button>
  </div>
 </form>
 </div>
 </div>

 <!-- Time Decay Settings -->
 <div class="card mb-lg">
 <div class="card-header">
 <h2 class="text-primary">
  <i data-lucide="clock"></i>
  Tidsviktning
 </h2>
 </div>
 <div class="card-body">
 <form method="POST">
  <?= csrf_field() ?>

  <div class="grid grid-cols-3 gap-lg">
  <div class="form-group">
  <label for="decay_1_12" class="label">Månad 1-12</label>
  <input type="number" id="decay_1_12" name="decay_1_12"
   value="<?= number_format($timeDecay['months_1_12'], 2) ?>"
   min="0" max="1" step="0.01"
   class="input">
  <small class="text-secondary">Senaste 12 månaderna</small>
  </div>
  <div class="form-group">
  <label for="decay_13_24" class="label">Månad 13-24</label>
  <input type="number" id="decay_13_24" name="decay_13_24"
   value="<?= number_format($timeDecay['months_13_24'], 2) ?>"
   min="0" max="1" step="0.01"
   class="input">
  <small class="text-secondary">Förra årets resultat</small>
  </div>
  <div class="form-group">
  <label for="decay_25_plus" class="label">Månad 25+</label>
  <input type="number" id="decay_25_plus" name="decay_25_plus"
   value="<?= number_format($timeDecay['months_25_plus'], 2) ?>"
   min="0" max="1" step="0.01"
   class="input">
  <small class="text-secondary">Äldre resultat (förfaller)</small>
  </div>
  </div>

  <div class="flex gap-sm mt-lg">
  <button type="submit" name="save_decay" class="btn btn--primary">
  <i data-lucide="save"></i>
  Spara tidsviktning
  </button>
  </div>
 </form>
 </div>
 </div>

 <!-- Reset Defaults -->
 <div class="card">
 <div class="card-header">
 <h2 class="text-secondary">
  <i data-lucide="rotate-ccw"></i>
  Återställ
 </h2>
 </div>
 <div class="card-body">
 <p class="text-sm text-secondary mb-md">
  Återställ alla inställningar till standardvärden. Detta påverkar inte beräknade rankingpoäng - kör ny beräkning efteråt.
 </p>

 <form method="POST">
  <?= csrf_field() ?>
  <button type="submit" name="reset_defaults" class="btn btn--secondary"
  onclick="return confirm('Återställ alla inställningar till standardvärden?')">
  <i data-lucide="rotate-ccw"></i>
  Återställ till standard
  </button>
 </form>
 </div>
 </div>
 <?php render_admin_footer(); ?>
 </div>
</main>

<style>
/* Mobile-responsive styles for admin ranking page */
@media (max-width: 767px) {
 /* Stack discipline stats to 1 column */
 .grid.grid-cols-3 {
 grid-template-columns: 1fr !important;
 }

 /* Stack info/calculation cards to 1 column */
 .grid.grid-cols-2 {
 grid-template-columns: 1fr !important;
 }

 /* Make header stack better */
 .flex.items-center.justify-between {
 flex-direction: column;
 align-items: flex-start !important;
 }

 .flex.items-center.justify-between .btn {
 width: 100%;
 justify-content: center;
 }

 /* Make cards more compact */
 .card {
 margin-bottom: var(--space-md);
 }

 .card-body {
 padding: var(--space-md);
 }

 /* Make buttons full width and larger */
 .btn {
 width: 100%;
 padding: var(--space-md);
 font-size: 1rem;
 }

 .flex.gap-sm {
 flex-direction: column;
 }

 /* Make inputs larger and more touch-friendly */
 .input {
 font-size: 16px !important; /* Prevents zoom on iOS */
 padding: var(--space-md);
 min-height: 44px; /* Touch target size */
 }

 /* Stack forms better */
 .form-group {
 margin-bottom: var(--space-md);
 }

 .form-group label {
 font-size: 1rem;
 margin-bottom: var(--space-sm);
 }

 .form-group small {
 font-size: 0.875rem;
 display: block;
 margin-top: var(--gs-space-xs);
 }
}

/* Specific mobile styles for field multipliers */
@media (max-width: 767px) {
 /* Reduce multipliers grid to 5 columns on mobile */
 #multipliersForm [style*="grid-template-columns: repeat(15"] {
 grid-template-columns: repeat(5, 1fr) !important;
 }

 /* Make multiplier inputs larger */
 #multipliersForm input[type="number"] {
 font-size: 14px !important;
 padding: 8px 4px !important;
 min-height: 44px;
 }

 #multipliersForm label {
 font-size: 0.8rem !important;
 margin-bottom: 4px;
 }

 /* Adjust bar chart for mobile */
 .mb-lg[style*="height: 120px"] {
 height: 80px !important;
 margin-bottom: var(--space-md) !important;
 }
}

/* Tablet styles */
@media (min-width: 768px) and (max-width: 1023px) {
 /* 2 columns for discipline stats on tablet */
 .grid.grid-cols-3:first-of-type {
 grid-template-columns: repeat(2, 1fr) !important;
 }

 /* Reduce multipliers to 8 columns on tablet */
 #multipliersForm [style*="grid-template-columns: repeat(15"] {
 grid-template-columns: repeat(8, 1fr) !important;
 }

 #multipliersForm input[type="number"] {
 font-size: 13px !important;
 padding: 6px 3px !important;
 }
}

/* Landscape phone - slightly different layout */
@media (max-width: 767px) and (orientation: landscape) {
 /* Keep stats in 3 columns in landscape */
 .grid.grid-cols-3:first-of-type {
 grid-template-columns: repeat(3, 1fr) !important;
 }

 /* 2 columns for event level and time decay in landscape */
 .card:has(#level_national) .grid,
 .card:has(#decay_1_12) .grid {
 grid-template-columns: repeat(2, 1fr) !important;
 }
}
</style>

<script>
// Update bar chart visualization
function updateBar(index, value) {
 const bar = document.getElementById('bar_' + index);
 if (bar) {
 const height = Math.max(5, parseFloat(value) * 100);
 bar.style.height = height + 'px';
 }
}

// Initialize bars on page load
document.addEventListener('DOMContentLoaded', function() {
 for (let i = 1; i <= 15; i++) {
 const bar = document.getElementById('bar_' + i);
 if (bar) {
 const value = parseFloat(bar.dataset.value) || 0.75;
 bar.style.height = (value * 100) + 'px';
 }
 }
});
</script>

<!-- DEBUG: About to include footer -->
<?php
echo"<!-- DEBUG: Including footer now -->\n";
flush();
include __DIR__ . '/../includes/layout-footer.php';
echo"<!-- DEBUG: Footer included, page complete -->\n";
?>

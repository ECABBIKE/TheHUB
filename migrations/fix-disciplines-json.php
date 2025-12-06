<?php
/**
 * Migration: Populate disciplines JSON from discipline column
 *
 * This script converts the single discipline value to the disciplines JSON array
 * for existing riders who have discipline but not disciplines set.
 */

require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();

// Discipline name to code mapping
$mappings = [
 'downhill' => 'DH',
 'dh' => 'DH',
 'enduro' => 'END',
 'cross country olympic' => 'XCO',
 'xco' => 'XCO',
 'cross country marathon' => 'XCM',
 'xcm' => 'XCM',
 'bmx' => 'BMX',
 'landsväg' => 'ROAD',
 'road' => 'ROAD',
 'bana' => 'TRACK',
 'track' => 'TRACK',
 'gravel' => 'GRAVEL',
 'cyclocross' => 'CX',
 'cx' => 'CX',
 'mtb' => 'DH',
 'mountain bike' => 'DH'
];

$pageTitle = 'Fix Disciplines JSON';
$pageType = 'admin';
include __DIR__ . '/../includes/layout-header.php';
?>

<main class="main-content">
 <div class="container" style="max-width: 800px;">
 <h1 class="text-primary mb-lg">
  <i data-lucide="database"></i>
  Fix Disciplines JSON Migration
 </h1>

 <?php
 $updated = 0;
 $skipped = 0;
 $notMapped = 0;
 $errors = [];

 // Get all riders with discipline but empty disciplines
 $riders = $db->getAll("
 SELECT id, discipline
 FROM riders
 WHERE discipline IS NOT NULL
  AND discipline != ''
  AND (disciplines IS NULL OR disciplines = '' OR disciplines = '[]')
 ");

 echo "<div class='card'><div class='card-body'>";
 echo "<p>Hittade <strong>" . count($riders) . "</strong> åkare att uppdatera...</p>";

 if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_migration'])) {
 checkCsrf();

 foreach ($riders as $rider) {
  $discipline = strtolower(trim($rider['discipline']));

  if (isset($mappings[$discipline])) {
  $code = $mappings[$discipline];
  $json = json_encode([$code]);

  try {
   $db->update('riders', ['disciplines' => $json], 'id = ?', [$rider['id']]);
   $updated++;
  } catch (Exception $e) {
   $errors[] = "Rider {$rider['id']}: " . $e->getMessage();
  }
  } else {
  $notMapped++;
  if ($notMapped <= 10) {
   $errors[] = "Kunde inte mappa: '{$rider['discipline']}'";
  }
  }
 }

 echo "<div class='alert alert--success mt-lg'>";
 echo "<i data-lucide='check-circle'></i> ";
 echo "<strong>Migration klar!</strong> $updated åkare uppdaterade.";
 if ($notMapped > 0) {
  echo " $notMapped kunde inte mappas.";
 }
 echo "</div>";

 if (!empty($errors)) {
  echo "<div class='alert alert--warning mt-md'>";
  echo "<strong>Varningar:</strong><br>";
  echo implode("<br>", array_slice($errors, 0, 10));
  if (count($errors) > 10) {
  echo "<br>... och " . (count($errors) - 10) . " fler";
  }
  echo "</div>";
 }
 } else {
 ?>
 <form method="POST">
  <?= csrf_field() ?>
  <p class="text-secondary mb-lg">
  Denna migration konverterar <code>discipline</code> (t.ex. "Downhill") till
  <code>disciplines</code> JSON-array (t.ex. ["DH"]).
  </p>

  <div class="flex gap-md">
  <button type="submit" name="run_migration" value="1" class="btn btn--primary">
   <i data-lucide="play"></i>
   Kör Migration
  </button>
  <a href="/admin" class="btn btn--secondary">Avbryt</a>
  </div>
 </form>
 <?php
 }
 ?>
 </div></div>
 </div>
</main>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>

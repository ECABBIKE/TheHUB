<?php
/**
 * Import Results - V3 Unified Design System
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/import-history.php';
require_once __DIR__ . '/../includes/class-calculations.php';
require_once __DIR__ . '/../includes/point-calculations.php';
require_once __DIR__ . '/../includes/import-functions.php';
require_admin();

$db = getDB();
$current_admin = get_current_admin();

$message = '';
$messageType = 'info';

// Handle template download
if (isset($_GET['template'])) {
 $format = $_GET['template'];
 header('Content-Type: text/csv; charset=utf-8');

 if ($format === 'enduro') {
 header('Content-Disposition: attachment; filename="resultat_enduro_mall.csv"');
 $output = fopen('php://output', 'w');
 fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

 fputcsv($output, [
 'Category', 'PlaceByCategory', 'Bib no', 'FirstName', 'LastName', 'Club', 'UCI-ID',
 'NetTime', 'Status', 'SS1', 'SS2', 'SS3', 'SS4', 'SS5', 'SS6'
 ], ';');

 fputcsv($output, [
 'Herrar Elit', '1', '101', 'Erik', 'Svensson', 'Stockholm MTB', '10012345678',
 '15:42.33', 'FIN', '2:15.44', '1:52.11', '2:33.55', '2:18.22', '3:01.88', '3:21.13'
 ], ';');

 fputcsv($output, [
 'Damer Elit', '1', '201', 'Anna', 'Johansson', 'Göteborg CK', '10087654321',
 '17:05.67', 'FIN', '2:45.22', '2:08.33', '2:55.11', '2:42.55', '3:22.33', '3:12.13'
 ], ';');
 } else {
 header('Content-Disposition: attachment; filename="resultat_dh_mall.csv"');
 $output = fopen('php://output', 'w');
 fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

 fputcsv($output, [
 'Category', 'PlaceByCategory', 'Bib no', 'FirstName', 'LastName', 'Club', 'UCI-ID',
 'Run1', 'Run2', 'NetTime', 'Status'
 ], ';');

 fputcsv($output, [
 'Herrar Elit', '1', '101', 'Erik', 'Svensson', 'Stockholm MTB', '10012345678',
 '2:15.44', '2:12.33', '2:12.33', 'FIN'
 ], ';');

 fputcsv($output, [
 'Damer Elit', '1', '201', 'Anna', 'Johansson', 'Göteborg CK', '10087654321',
 '2:45.22', '2:42.11', '2:42.11', 'FIN'
 ], ';');
 }

 fclose($output);
 exit;
}

// Load existing events for dropdown
$existingEvents = $db->getAll("
 SELECT id, name, date, location
 FROM events
 ORDER BY date DESC
 LIMIT 200
");

// Handle CSV upload - redirect to preview
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['import_file'])) {
 checkCsrf();

 $file = $_FILES['import_file'];
 $selectedEventId = !empty($_POST['event_id']) ? (int)$_POST['event_id'] : null;
 $importFormat = !empty($_POST['import_format']) ? $_POST['import_format'] : null;

 // Validate format and event selection
 if (!$importFormat || !in_array($importFormat, ['enduro', 'dh'])) {
 $message = 'Du måste välja ett format (Enduro eller DH)';
 $messageType = 'error';
 } elseif (!$selectedEventId) {
 $message = 'Du måste välja ett event först';
 $messageType = 'error';
 } elseif ($file['error'] !== UPLOAD_ERR_OK) {
 $message = 'Filuppladdning misslyckades';
 $messageType = 'error';
 } elseif ($file['size'] > MAX_UPLOAD_SIZE) {
 $message = 'Filen är för stor (max ' . (MAX_UPLOAD_SIZE / 1024 / 1024) . 'MB)';
 $messageType = 'error';
 } else {
 $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

 if ($extension !== 'csv') {
 $message = 'Endast CSV-filer stöds för resultatimport';
 $messageType = 'error';
 } else {
 // Save file and redirect to preview
 $uploaded = UPLOADS_PATH . '/' . time() . '_preview_' . basename($file['name']);

 if (move_uploaded_file($file['tmp_name'], $uploaded)) {
 // Clear old preview data
 unset($_SESSION['import_preview_file']);
 unset($_SESSION['import_preview_filename']);
 unset($_SESSION['import_preview_data']);
 unset($_SESSION['import_events_summary']);
 unset($_SESSION['import_selected_event']);

 // Store in session and redirect to preview
 $_SESSION['import_preview_file'] = $uploaded;
 $_SESSION['import_preview_filename'] = $file['name'];
 $_SESSION['import_selected_event'] = $selectedEventId;
 $_SESSION['import_format'] = $importFormat;

 header('Location: /admin/import-results-preview.php');
 exit;
 } else {
 $message = 'Kunde inte ladda upp filen';
 $messageType = 'error';
 }
 }
 }
}

// Page config for unified layout
$page_title = 'Importera Resultat';
$breadcrumbs = [
    ['label' => 'Import', 'url' => '/admin/import'],
    ['label' => 'Resultat']
];

// Include unified layout
include __DIR__ . '/components/unified-layout.php';
?>

<!-- Messages -->
 <?php if ($message): ?>
 <div class="alert alert-<?= $messageType ?> mb-lg">
 <i data-lucide="<?= $messageType === 'success' ? 'check-circle' : ($messageType === 'error' ? 'alert-circle' : 'info') ?>"></i>
 <?= h($message) ?>
 </div>
 <?php endif; ?>

 <!-- Import Form -->
 <div class="card">
 <div class="card-header">
 <h2 class="text-primary">
  <i data-lucide="file-plus"></i>
  Importera resultat till event
 </h2>
 </div>
 <div class="card-body">
 <form method="POST" enctype="multipart/form-data" class="gs-form">
  <?= csrf_field() ?>

  <!-- Download Templates -->
  <div class="form-group mb-lg">
  <label class="label">Ladda ner mall</label>
  <div class="flex gap-sm">
  <a href="?template=enduro" class="btn btn--secondary btn--sm">
  <i data-lucide="download"></i>
  Enduro mall
  </a>
  <a href="?template=dh" class="btn btn--secondary btn--sm">
  <i data-lucide="download"></i>
  DH mall
  </a>
  </div>
  </div>

  <!-- Step 1: Select Format -->
  <div class="form-group mb-lg">
  <label for="import_format" class="label label-lg">
  <span class="badge badge-primary mr-sm">1</span>
  Välj format
  </label>
  <select id="import_format" name="import_format" class="input input-lg" required>
  <option value="">-- Välj format --</option>
  <option value="enduro">Enduro (SS1, SS2, SS3...)</option>
  <option value="dh">Downhill (Run 1, Run 2)</option>
  </select>
  <p class="text-sm text-secondary mt-sm">
  Välj rätt format baserat på din CSV-fils struktur.
  </p>
  </div>

  <!-- Step 2: Select Event -->
  <div class="form-group mb-lg">
  <label for="event_id" class="label label-lg">
  <span class="badge badge-primary mr-sm">2</span>
  Välj event
  </label>
  <select id="event_id" name="event_id" class="input input-lg" required>
  <option value="">-- Välj ett event --</option>
  <?php foreach ($existingEvents as $event): ?>
  <option value="<?= $event['id'] ?>">
   <?= h($event['name']) ?> (<?= date('Y-m-d', strtotime($event['date'])) ?>)
   <?php if ($event['location']): ?>
   - <?= h($event['location']) ?>
   <?php endif; ?>
  </option>
  <?php endforeach; ?>
  </select>
  <p class="text-sm text-secondary mt-sm">
  Alla resultat i filen kommer att importeras till det valda eventet.
  </p>
  </div>

  <!-- Step 3: Select File -->
  <div class="form-group mb-lg">
  <label for="import_file" class="label label-lg">
  <span class="badge badge-primary mr-sm">3</span>
  Välj CSV-fil
  </label>
  <input type="file"
  id="import_file"
  name="import_file"
  class="input input-lg"
  accept=".csv"
  required>
  <p class="text-sm text-secondary mt-sm">
  Max filstorlek: <?= MAX_UPLOAD_SIZE / 1024 / 1024 ?>MB. Stöder komma- och semikolon-separerade filer.
  </p>
  </div>

  <!-- Step 4: Preview Button -->
  <div class="form-group">
  <button type="submit" class="btn btn--primary btn-lg w-full">
  <i data-lucide="eye"></i>
  <span class="badge badge-light mr-sm">4</span>
  Förhandsgranska import
  </button>
  </div>
 </form>
 </div>
 </div>

 <!-- CSV Format Info -->
 <div class="card mt-lg">
 <div class="card-header">
 <h3 class="text-primary">
  <i data-lucide="file-text"></i>
  CSV Format
 </h3>
 </div>
 <div class="card-body">
 <p class="text-sm mb-md"><strong>Obligatoriska kolumner (alla format):</strong></p>
 <code class="gs-code-block mb-md">
Category, PlaceByCategory, FirstName, LastName, Club, NetTime, Status
 </code>

 <div class="grid grid-cols-1 md-grid-cols-2 gap-lg mt-lg">
  <!-- Enduro Format -->
  <div class="card card-bordered">
  <div class="card-header gs-bg-primary-light">
  <h4 class="text-primary gs-m-0">
  <i data-lucide="mountain"></i>
  Enduro Format
  </h4>
  </div>
  <div class="card-body">
  <p class="text-sm mb-sm"><strong>Specifika kolumner:</strong></p>
  <code class="gs-code-block text-xs">
UCI-ID, SS1, SS2, SS3... SS15
  </code>
  <p class="text-xs text-secondary mt-sm">
  Stages summeras till total tid
  </p>
  </div>
  </div>

  <!-- DH Format -->
  <div class="card card-bordered">
  <div class="card-header gs-bg-warning-light">
  <h4 class="text-warning gs-m-0">
  <i data-lucide="arrow-down"></i>
  Downhill Format
  </h4>
  </div>
  <div class="card-body">
  <p class="text-sm mb-sm"><strong>Specifika kolumner:</strong></p>
  <code class="gs-code-block text-xs">
UCI-ID, Run1, Run2
  </code>
  <p class="text-xs text-secondary mt-sm">
  Bästa tid av två åk vinner
  </p>
  </div>
  </div>
 </div>

 <details class="gs-details">
  <summary class="text-sm text-primary">
  Visa exempel CSV
  </summary>
  <pre class="gs-code-dark mt-md">
Category,PlaceByCategory,FirstName,LastName,Club,UCI-ID,NetTime,Status,SS1,SS2,SS3
Damer Junior,1,Ella,MÅRTENSSON,Borås CA,10022510347,16:19.16,FIN,2:10.55,1:47.08,1:51.10
Herrar Elite,1,Johan,ANDERSSON,Stockholm CK,10011223344,14:16.42,FIN,1:58.22,1:38.55,1:42.33
Herrar Elite,2,Erik,SVENSSON,Göteborg MTB,,DNF,DNF,1:55.34,1:39.21,DNF</pre>
 </details>

 <div class="alert alert--info mt-md">
  <i data-lucide="info"></i>
  <strong>Tips:</strong> Systemet stödjer också svenska kolumnnamn som Klass, Placering, Förnamn, Efternamn, Klubb, Tid.
 </div>
 </div>
 </div>

<!-- Tools Section -->
<div class="card mt-lg">
 <div class="card-header">
 <h3 class="text-primary">
  <i data-lucide="wrench"></i>
  Verktyg
 </h3>
 </div>
 <div class="card-body">
 <a href="/admin/fix-time-format.php" class="btn btn--secondary">
  <i data-lucide="clock"></i>
  Fixa tidsformat
 </a>
 <span class="text-secondary text-sm ml-sm">Korrigerar tider med fel format (t.ex. 0:04:17.45 → 4:17.45)</span>
 </div>
</div>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>

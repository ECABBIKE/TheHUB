<?php
require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();
$current_admin = get_current_admin();

$message = '';
$messageType = 'info';
$stats = null;
$errors = [];

// Handle CSV import
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
 checkCsrf();

 $file = $_FILES['csv_file'];

 if ($file['error'] === UPLOAD_ERR_OK) {
 $result = importVenuesFromCSV($file['tmp_name'], $db);
 $stats = $result['stats'];
 $errors = $result['errors'];

 if ($stats['failed'] === 0) {
 $message ="Import klar! {$stats['success']} anläggningar importerade.";
 $messageType = 'success';
 } else {
 $message ="Import klar med fel. {$stats['success']} lyckades, {$stats['failed']} misslyckades.";
 $messageType = 'warning';
 }
 } else {
 $message = 'Fel vid uppladdning av fil.';
 $messageType = 'error';
 }
}

/**
 * Import venues from CSV file
 */
function importVenuesFromCSV($filePath, $db) {
 $stats = [
 'total' => 0,
 'success' => 0,
 'updated' => 0,
 'failed' => 0,
 'skipped' => 0
 ];
 $errors = [];

 $handle = fopen($filePath, 'r');
 if (!$handle) {
 return ['stats' => $stats, 'errors' => ['Kunde inte öppna filen']];
 }

 // Auto-detect delimiter (comma or semicolon)
 $firstLine = fgets($handle);
 rewind($handle);
 $delimiter = (substr_count($firstLine, ';') > substr_count($firstLine, ',')) ? ';' : ',';

 // Read header with detected delimiter
 $header = fgetcsv($handle, 0, $delimiter);
 if (!$header) {
 fclose($handle);
 return ['stats' => $stats, 'errors' => ['Ogiltig CSV-fil']];
 }

 // Normalize header - accept multiple variants of column names
 $header = array_map(function($col) {
 $col = strtolower(trim($col));
 $col = str_replace([' ', '-', '_'], '', $col); // Remove spaces, hyphens, underscores

 // Map various column name variants to standard names
 $mappings = [
 'namn' => 'name',
 'name' => 'name',
 'bana' => 'name',
 'anläggning' => 'name',
 'anlaggning' => 'name',
 'park' => 'name',
 'bikepark' => 'name',

 'stad' => 'city',
 'city' => 'city',
 'ort' => 'city',

 'region' => 'region',
 'län' => 'region',
 'lan' => 'region',
 'county' => 'region',
 'område' => 'region',
 'omrade' => 'region',

 'land' => 'country',
 'country' => 'country',

 'adress' => 'address',
 'address' => 'address',
 'gatuadress' => 'address',
 'streetaddress' => 'address',

 'koordinater' => 'coordinates',
 'coordinates' => 'coordinates',
 'coords' => 'coordinates',
 'gps' => 'coordinates',
 'latlon' => 'coordinates',
 'latlong' => 'coordinates',

 'beskrivning' => 'description',
 'description' => 'description',
 'beskriv' => 'description',
 'info' => 'description',

 'webbplats' => 'website',
 'website' => 'website',
 'url' => 'website',
 'hemsida' => 'website',
 'web' => 'website',
 ];

 return $mappings[$col] ?? $col;
 }, $header);

 $lineNumber = 1;

 while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
 $lineNumber++;
 $stats['total']++;

 if (count($row) !== count($header)) {
 $stats['skipped']++;
 continue;
 }

 $data = array_combine($header, $row);

 try {
 // Required fields
 if (empty($data['name'])) {
 $stats['skipped']++;
 continue;
 }

 // Prepare venue data
 $venueData = [
 'name' => trim($data['name']),
 'city' => trim($data['city'] ?? ''),
 'region' => trim($data['region'] ?? ''),
 'country' => trim($data['country'] ?? 'Sverige'),
 'address' => trim($data['address'] ?? ''),
 'coordinates' => trim($data['coordinates'] ?? ''),
 'description' => trim($data['description'] ?? ''),
 'website' => trim($data['website'] ?? ''),
 'active' => 1
 ];

 // Check if venue exists (by name)
 $existing = $db->getRow(
 "SELECT id FROM venues WHERE name = ? LIMIT 1",
 [$venueData['name']]
 );

 if ($existing) {
 // Update existing venue
 $db->update('venues', $venueData, 'id = ?', [$existing['id']]);
 $stats['updated']++;
 error_log("Updated venue: {$venueData['name']}");
 } else {
 // Insert new venue
 $newId = $db->insert('venues', $venueData);
 $stats['success']++;
 error_log("Inserted venue: {$venueData['name']} (ID: {$newId})");
 }

 } catch (Exception $e) {
 $stats['failed']++;
 $errors[] ="Rad {$lineNumber}:" . $e->getMessage();
 error_log("Import error on line {$lineNumber}:" . $e->getMessage());
 }
 }

 fclose($handle);

 // Verification
 $verifyCount = $db->getRow("SELECT COUNT(*) as count FROM venues");
 $totalInDb = $verifyCount['count'] ?? 0;
 error_log("Venue import complete: {$stats['success']} new, {$stats['updated']} updated, {$stats['failed']} failed. Total venues in DB: {$totalInDb}");

 $stats['total_in_db'] = $totalInDb;

 return [
 'stats' => $stats,
 'errors' => $errors
 ];
}

$pageTitle = 'Importera Anläggningar';
$pageType = 'admin';
include __DIR__ . '/../includes/layout-header.php';
?>

<main class="main-content">
 <div class="container">
 <h1 class="mb-lg">
 <i data-lucide="map-pin"></i>
 Importera Anläggningar
 </h1>

 <div class="gs-breadcrumb mb-lg">
 <a href="/admin/dashboard.php">Dashboard</a>
 <span>/</span>
 <a href="/admin/venues.php">Anläggningar</a>
 <span>/</span>
 <span>Import</span>
 </div>

 <?php if ($message): ?>
 <div class="alert alert-<?= h($messageType) ?> mb-lg">
 <i data-lucide="<?= $messageType === 'success' ? 'check-circle' : 'alert-circle' ?>"></i>
 <?= h($message) ?>
 </div>
 <?php endif; ?>

 <?php if (!empty($errors)): ?>
 <div class="alert alert--error mb-lg">
 <strong>Fel under import:</strong>
 <ul class="mt-sm">
  <?php foreach ($errors as $error): ?>
  <li><?= h($error) ?></li>
  <?php endforeach; ?>
 </ul>
 </div>
 <?php endif; ?>

 <?php if ($stats): ?>
 <div class="card mb-lg">
 <div class="card-header">
  <h2 class="">Import-statistik</h2>
 </div>
 <div class="card-body">
  <div class="grid grid-cols-5 gap-md">
  <div class="stat-card">
  <div class="stat-value"><?= $stats['total'] ?></div>
  <div class="stat-label">Totalt rader</div>
  </div>
  <div class="stat-card">
  <div class="stat-value text-success"><?= $stats['success'] ?></div>
  <div class="stat-label">Nya</div>
  </div>
  <div class="stat-card">
  <div class="stat-value gs-text-info"><?= $stats['updated'] ?></div>
  <div class="stat-label">Uppdaterade</div>
  </div>
  <div class="stat-card">
  <div class="stat-value text-secondary"><?= $stats['skipped'] ?></div>
  <div class="stat-label">Överhoppade</div>
  </div>
  <div class="stat-card">
  <div class="stat-value text-error"><?= $stats['failed'] ?></div>
  <div class="stat-label">Misslyckade</div>
  </div>
  </div>

  <div class="mt-lg">
  <h3 class="mb-sm">Verifiering</h3>
  <p class="text-lg"><strong>Totalt i databasen:</strong> <?= $stats['total_in_db'] ?> anläggningar</p>
  </div>
 </div>
 </div>
 <?php endif; ?>

 <!-- Upload Form -->
 <div class="card mb-lg">
 <div class="card-header">
 <h2 class="">Bulk-import av anläggningar från CSV-fil</h2>
 </div>
 <div class="card-body">
 <form method="POST" enctype="multipart/form-data" class="gs-form">
  <?= csrf_field() ?>

  <div class="alert alert--info mb-md">
  <i data-lucide="info"></i>
  <strong>CSV-format:</strong> Första raden ska innehålla kolumnnamn.
  </div>

  <div class="form-group">
  <label class="label">
  <i data-lucide="upload"></i>
  Välj CSV-fil
  </label>
  <input
  type="file"
  name="csv_file"
  accept=".csv"
  required
  class="input"
  >
  <small class="text-secondary">Max 10 MB. Format: CSV (komma-separerad)</small>
  </div>

  <div class="flex gap-md">
  <button type="submit" class="btn btn--primary">
  <i data-lucide="upload"></i>
  Importera Anläggningar
  </button>
  <a href="/admin/venues.php" class="btn btn--secondary">
  <i data-lucide="arrow-left"></i>
  Tillbaka
  </a>
  <a href="/templates/import-venues-template.csv" class="btn btn--secondary" download>
  <i data-lucide="download"></i>
  Ladda ner mall
  </a>
  </div>
 </form>
 </div>
 </div>

 <!-- CSV Format Info -->
 <div class="card">
 <div class="card-header">
 <h2 class="">CSV-kolumner</h2>
 </div>
 <div class="card-body">
 <table class="table">
  <thead>
  <tr>
  <th>Kolumn</th>
  <th>Beskrivning</th>
  <th>Obligatorisk</th>
  <th>Exempel</th>
  </tr>
  </thead>
  <tbody>
  <tr>
  <td><code>name</code></td>
  <td>Anläggningsnamn</td>
  <td>✅ Ja</td>
  <td>Åre Bike Park</td>
  </tr>
  <tr>
  <td><code>city</code></td>
  <td>Ort</td>
  <td>Nej</td>
  <td>Åre</td>
  </tr>
  <tr>
  <td><code>region</code></td>
  <td>Region/län</td>
  <td>Nej</td>
  <td>Jämtland</td>
  </tr>
  <tr>
  <td><code>country</code></td>
  <td>Land</td>
  <td>Nej</td>
  <td>Sverige</td>
  </tr>
  <tr>
  <td><code>address</code></td>
  <td>Adress</td>
  <td>Nej</td>
  <td>Årevägen 1, 837 52 Åre</td>
  </tr>
  <tr>
  <td><code>coordinates</code></td>
  <td>Koordinater (lat,lon)</td>
  <td>Nej</td>
  <td>63.3989,13.0819</td>
  </tr>
  <tr>
  <td><code>description</code></td>
  <td>Beskrivning</td>
  <td>Nej</td>
  <td>Sveriges största bike park</td>
  </tr>
  <tr>
  <td><code>website</code></td>
  <td>Hemsida</td>
  <td>Nej</td>
  <td>https://skistar.com/arebike</td>
  </tr>
  </tbody>
 </table>
 </div>
 </div>
 </div>
</main>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>

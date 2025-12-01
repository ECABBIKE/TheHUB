<?php
// CRITICAL: Show ALL errors for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

// Try to catch fatal errors
register_shutdown_function(function() {
 $error = error_get_last();
 if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
 echo"<h1>Fatal Error Detected:</h1>";
 echo"<pre class='gs-pre-error'>";
 echo"Type:" . $error['type'] ."\n";
 echo"Message:" . htmlspecialchars($error['message']) ."\n";
 echo"File:" . $error['file'] ."\n";
 echo"Line:" . $error['line'];
 echo"</pre>";
 }
});

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_admin();

$db = getDB();
$current_admin = get_current_admin();

$message = '';
$messageType = 'info';
$stats = null;
$errors = [];
$skippedRows = [];

// Handle CSV/Excel upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['import_file'])) {
 // Validate CSRF token
 checkCsrf();

 $file = $_FILES['import_file'];

 // Validate file
 if ($file['error'] !== UPLOAD_ERR_OK) {
 $message = 'Filuppladdning misslyckades';
 $messageType = 'error';
 } elseif ($file['size'] > MAX_UPLOAD_SIZE) {
 $message = 'Filen är för stor (max ' . (MAX_UPLOAD_SIZE / 1024 / 1024) . 'MB)';
 $messageType = 'error';
 } else {
 $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

 if (!in_array($extension, ['csv', 'xlsx', 'xls'])) {
  $message = 'Ogiltigt filformat. Tillåtna: CSV, XLSX, XLS';
  $messageType = 'error';
 } else {
  // Process the file
  $uploaded = UPLOADS_PATH . '/' . time() . '_' . basename($file['name']);

  if (move_uploaded_file($file['tmp_name'], $uploaded)) {
  try {
   // Parse CSV
   if ($extension === 'csv') {
   $result = importRidersFromCSV($uploaded, $db);
   } else {
   // For Excel files, we'd need PhpSpreadsheet
   // For now, show message to use CSV
   $message = 'Excel-filer stöds inte än. Använd CSV-format istället.';
   $messageType = 'warning';
   @unlink($uploaded);
   goto skip_import;
   }

   $stats = $result['stats'];
   $errors = $result['errors'];
   $skippedRows = $result['skipped_rows'] ?? [];

   if ($stats['success'] > 0 || $stats['updated'] > 0) {
   $message ="Import klar! {$stats['success']} nya, {$stats['updated']} uppdaterade.";
   if ($stats['duplicates'] > 0) {
    $message .=" {$stats['duplicates']} dubletter borttagna.";
   }
   $messageType = 'success';
   } else {
   $message ="Ingen data importerades. Kontrollera filformatet.";
   $messageType = 'error';
   }

  } catch (Exception $e) {
   $message = 'Import misslyckades: ' . $e->getMessage();
   $messageType = 'error';
  }

  @unlink($uploaded);
  } else {
  $message = 'Kunde inte ladda upp filen';
  $messageType = 'error';
  }
 }
 }

 skip_import:
}

/**
 * Import riders from CSV file
 */
function importRidersFromCSV($filepath, $db) {
 $stats = [
 'total' => 0,
 'success' => 0,
 'updated' => 0,
 'skipped' => 0,
 'failed' => 0,
 'duplicates' => 0
 ];
 $errors = [];
 $skippedRows = []; // Detailed list of skipped rows
 $seenInThisImport = []; // Track riders in this import to detect duplicates

 if (($handle = fopen($filepath, 'r')) === false) {
 throw new Exception('Kunde inte öppna filen');
 }

 // Auto-detect delimiter (comma or semicolon)
 $firstLine = fgets($handle);
 rewind($handle);
 $delimiter = (substr_count($firstLine, ';') > substr_count($firstLine, ',')) ? ';' : ',';

 // Read header row with detected delimiter
 $header = fgetcsv($handle, 1000, $delimiter);

 if (!$header) {
 fclose($handle);
 throw new Exception('Tom fil eller ogiltigt format');
 }

 // Expected columns: firstname, lastname, birth_year, gender, club, license_number, email, phone, city
 $expectedColumns = ['firstname', 'lastname', 'birth_year', 'gender', 'club'];

 // Normalize header - accept multiple variants of column names
 $header = array_map(function($col) {
 $col = strtolower(trim($col));
 $col = str_replace([' ', '-', '_'], '', $col); // Remove spaces, hyphens, underscores

 // Map various column name variants to standard names
 $mappings = [
  // Name fields
  'förnamn' => 'firstname',
  'fornamn' => 'firstname',
  'firstname' => 'firstname',
  'fname' => 'firstname',
  'givenname' => 'firstname',
  'name' => 'firstname',

  'efternamn' => 'lastname',
  'lastname' => 'lastname',
  'surname' => 'lastname',
  'familyname' => 'lastname',
  'lname' => 'lastname',

  // Birth year / age
  'födelseår' => 'birthyear',
  'fodelsear' => 'birthyear',
  'birthyear' => 'birthyear',
  'född' => 'birthyear',
  'fodd' => 'birthyear',
  'year' => 'birthyear',
  'ålder' => 'birthyear',
  'alder' => 'birthyear',
  'age' => 'birthyear',
  // Note: personnummer column is parsed to extract birth_year only
  // The personnummer itself is NOT stored in the database
  'personnummer' => 'personnummer',
  'pnr' => 'personnummer',
  'ssn' => 'personnummer',

  // Gender
  'kön' => 'gender',
  'kon' => 'gender',
  'gender' => 'gender',
  'sex' => 'gender',

  // Club
  'klubb' => 'club',
  'club' => 'club',
  'klubbnamn' => 'club',
  'clubname' => 'club',
  'team' => 'club',
  'lag' => 'club',

  // License
  'licensnummer' => 'licensenumber',
  'licensnr' => 'licensenumber',
  'licensenumber' => 'licensenumber',
  'licencenumber' => 'licensenumber',
  'uciid' => 'licensenumber',
  'uci' => 'licensenumber',
  'sweid' => 'licensenumber',
  'licens' => 'licensenumber',
  'license' => 'licensenumber',

  'licenstyp' => 'licensetype',
  'licensetype' => 'licensetype',
  'licensetyp' => 'licensetype',
  'type' => 'licensetype',

  'licenskategori' => 'licensecategory',
  'licensecategory' => 'licensecategory',
  'kategori' => 'licensecategory',
  'category' => 'licensecategory',

  'licensgiltigtill' => 'licensevaliduntil',
  'licensevaliduntil' => 'licensevaliduntil',
  'giltigtill' => 'licensevaliduntil',
  'validuntil' => 'licensevaliduntil',
  'expiry' => 'licensevaliduntil',

  // Discipline
  'gren' => 'discipline',
  'discipline' => 'discipline',
  'sport' => 'discipline',

  // Contact info
  'epost' => 'email',
  'email' => 'email',
  'mail' => 'email',
  'epostadress' => 'email',
  'emailaddress' => 'email',

  'telefon' => 'phone',
  'phone' => 'phone',
  'tel' => 'phone',
  'mobil' => 'phone',
  'mobile' => 'phone',

  'stad' => 'city',
  'city' => 'city',
  'ort' => 'city',
  'location' => 'city',

  // Notes
  'anteckningar' => 'notes',
  'notes' => 'notes',
  'kommentar' => 'notes',
  'comment' => 'notes',
 ];

 return $mappings[$col] ?? $col;
 }, $header);

 // Cache for club lookups
 $clubCache = [];

 $lineNumber = 1;

 while (($row = fgetcsv($handle, 1000, $delimiter)) !== false) {
 $lineNumber++;
 $stats['total']++;

 // Map row to associative array
 $data = array_combine($header, $row);

 // Validate required fields
 if (empty($data['firstname']) || empty($data['lastname'])) {
  $stats['skipped']++;
  $errors[] ="Rad {$lineNumber}: Saknar förnamn eller efternamn";
  $skippedRows[] = [
  'row' => $lineNumber,
  'name' => trim(($data['firstname'] ?? '') . ' ' . ($data['lastname'] ?? '')),
  'reason' => 'Saknar förnamn eller efternamn',
  'type' => 'missing_fields'
  ];
  continue;
 }

 try {
  // Extract birth_year from personnummer if provided
  // Note: personnummer is ONLY used to extract birth_year - it is NOT stored in DB
  $birthYear = null;
  if (!empty($data['personnummer'])) {
  $birthYear = parsePersonnummer($data['personnummer']);
  }
  // Fall back to birthyear column if no personnummer or parsing failed
  if (!$birthYear && !empty($data['birthyear'])) {
  $birthYear = (int)$data['birthyear'];
  }

  // Prepare rider data
  // Normalize gender: Woman/Female/Kvinna → F, Man/Male/Herr → M
  $genderRaw = strtolower(trim($data['gender'] ?? 'M'));
  if (in_array($genderRaw, ['woman', 'female', 'kvinna', 'dam', 'f'])) {
  $gender = 'F';
  } elseif (in_array($genderRaw, ['man', 'male', 'herr', 'm'])) {
  $gender = 'M';
  } else {
  $gender = strtoupper(substr($genderRaw, 0, 1)); // Fallback: first letter
  }

  $riderData = [
  'firstname' => trim($data['firstname']),
  'lastname' => trim($data['lastname']),
  'birth_year' => $birthYear,
  'gender' => $gender,
  'license_number' => !empty($data['licensenumber']) ? trim($data['licensenumber']) : null,
  'email' => !empty($data['email']) ? trim($data['email']) : null,
  'phone' => !empty($data['phone']) ? trim($data['phone']) : null,
  'city' => !empty($data['city']) ? trim($data['city']) : null,
  'active' => 1
  ];

  // Add new license fields
  $riderData['license_type'] = !empty($data['licensetype']) ? trim($data['licensetype']) : null;
  $riderData['discipline'] = !empty($data['discipline']) ? trim($data['discipline']) : null;
  $riderData['license_valid_until'] = !empty($data['licensevaliduntil']) ? trim($data['licensevaliduntil']) : null;

  // License category - use provided or auto-suggest
  if (!empty($data['licensecategory'])) {
  $riderData['license_category'] = trim($data['licensecategory']);
  } elseif ($birthYear && $gender) {
  // Auto-suggest license category based on age and gender
  $riderData['license_category'] = suggestLicenseCategory($birthYear, $gender);
  } else {
  $riderData['license_category'] = null;
  }

  // Generate SWE-ID if no license number provided
  if (empty($riderData['license_number']) && !empty($data['licensenumber'])) {
  $riderData['license_number'] = trim($data['licensenumber']);
  }
  if (empty($riderData['license_number'])) {
  $riderData['license_number'] = generateSweId($db);
  }

  // Handle club - fuzzy matching
  if (!empty($data['club'])) {
  $clubName = trim($data['club']);

  // Check cache first
  if (isset($clubCache[$clubName])) {
   $riderData['club_id'] = $clubCache[$clubName];
  } else {
   // Try exact match first
   $club = $db->getRow(
   "SELECT id FROM clubs WHERE name = ? LIMIT 1",
   [$clubName]
   );

   if (!$club) {
   // Try fuzzy match (LIKE)
   $club = $db->getRow(
   "SELECT id FROM clubs WHERE name LIKE ? LIMIT 1",
    ['%' . $clubName . '%']
   );
   }

   if (!$club) {
   // Create new club
   $clubId = $db->insert('clubs', [
    'name' => $clubName,
    'active' => 1
   ]);
   $clubCache[$clubName] = $clubId;
   $riderData['club_id'] = $clubId;
   } else {
   $clubCache[$clubName] = $club['id'];
   $riderData['club_id'] = $club['id'];
   }
  }
  } else {
  $riderData['club_id'] = null;
  }

  // Check for duplicates within this import
  // Create unique key: firstname_lastname_birthyear OR licensenumber
  $uniqueKey = '';
  if ($riderData['license_number']) {
  $uniqueKey = 'lic_' . strtolower(trim($riderData['license_number']));
  } else {
  $uniqueKey = 'name_' . strtolower(trim($riderData['firstname'])) . '_' .
    strtolower(trim($riderData['lastname'])) . '_' .
    ($riderData['birth_year'] ?? '0');
  }

  if (isset($seenInThisImport[$uniqueKey])) {
  // This is a duplicate within the same import - skip it
  $stats['duplicates']++;
  $stats['skipped']++;
  $skippedRows[] = [
   'row' => $lineNumber,
   'name' => $riderData['firstname'] . ' ' . $riderData['lastname'],
   'license' => $riderData['license_number'] ?? '-',
   'reason' => 'Dublett (redan i denna import)',
   'type' => 'duplicate'
  ];
  error_log("Import: Skipped duplicate - {$riderData['firstname']} {$riderData['lastname']} (already in this import)");
  continue;
  }

  // Mark as seen in this import
  $seenInThisImport[$uniqueKey] = true;

  // Check if rider already exists (by license or name+birth_year)
  $existing = null;

  if ($riderData['license_number']) {
  $existing = $db->getRow(
  "SELECT id FROM riders WHERE license_number = ? LIMIT 1",
   [$riderData['license_number']]
  );
  }

  if (!$existing && $riderData['birth_year']) {
  $existing = $db->getRow(
  "SELECT id FROM riders WHERE firstname = ? AND lastname = ? AND birth_year = ? LIMIT 1",
   [$riderData['firstname'], $riderData['lastname'], $riderData['birth_year']]
  );
  }

  if ($existing) {
  // Update existing rider
  $db->update('riders', $riderData, 'id = ?', [$existing['id']]);
  $stats['updated']++;
  error_log("Import: Updated rider ID {$existing['id']} - {$riderData['firstname']} {$riderData['lastname']}");
  } else {
  // Insert new rider
  $newId = $db->insert('riders', $riderData);
  $stats['success']++;
  error_log("Import: Inserted new rider ID {$newId} - {$riderData['firstname']} {$riderData['lastname']} (active={$riderData['active']})");
  }

 } catch (Exception $e) {
  $stats['failed']++;
  $errors[] ="Rad {$lineNumber}:" . $e->getMessage();
  $skippedRows[] = [
  'row' => $lineNumber,
  'name' => trim(($data['firstname'] ?? '') . ' ' . ($data['lastname'] ?? '')),
  'license' => $data['licensenumber'] ?? '-',
  'reason' => 'Fel: ' . $e->getMessage(),
  'type' => 'error'
  ];
 }
 }

 fclose($handle);

 // VERIFICATION: Check that data was actually saved
 $verifyCount = $db->getRow("SELECT COUNT(*) as count FROM riders");
 $totalInDb = $verifyCount['count'] ?? 0;
 error_log("Import complete: {$stats['success']} new, {$stats['updated']} updated, {$stats['failed']} failed. Total riders in DB: {$totalInDb}");

 // Add verification count to stats
 $stats['total_in_db'] = $totalInDb;

 return [
 'stats' => $stats,
 'errors' => $errors,
 'skipped_rows' => $skippedRows
 ];
}

// Page config for unified layout
$page_title = 'Importera Deltagare';
$breadcrumbs = [
    ['label' => 'Import', 'url' => '/admin/import'],
    ['label' => 'Deltagare']
];

// Include unified layout
include __DIR__ . '/components/unified-layout.php';
?>

<!-- Import Tabs -->
<div class="admin-tabs">
    <a href="/admin/import" class="admin-tab">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 16px; height: 16px;"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
        Översikt
    </a>
    <a href="/admin/import-riders.php" class="admin-tab active">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 16px; height: 16px;"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
        Deltagare
    </a>
    <a href="/admin/import-results.php" class="admin-tab">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 16px; height: 16px;"><path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M4 22h16"/><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"/><path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"/><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/></svg>
        Resultat
    </a>
    <a href="/admin/import-events.php" class="admin-tab">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 16px; height: 16px;"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
        Events
    </a>
    <a href="/admin/import-history.php" class="admin-tab">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 16px; height: 16px;"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M12 7v5l4 2"/></svg>
        Historik
    </a>
</div>

  <!-- Message -->
  <?php if ($message): ?>
  <div class="alert alert-<?= h($messageType) ?> mb-lg">
   <i data-lucide="<?= $messageType === 'success' ? 'check-circle' : 'alert-circle' ?>"></i>
   <?= h($message) ?>
  </div>
  <?php endif; ?>

  <!-- Statistics -->
  <?php if ($stats): ?>
  <div class="card mb-lg">
   <div class="card-header">
   <h2 class="text-primary">
    <i data-lucide="bar-chart"></i>
    Import-statistik
   </h2>
   </div>
   <div class="card-body">
   <div class="grid grid-cols-1 gs-sm-grid-cols-2 md-grid-cols-3 gs-lg-grid-cols-5 gap-md">
    <div class="stat-card">
    <i data-lucide="file-text" class="icon-lg text-primary mb-sm"></i>
    <div class="stat-number"><?= number_format($stats['total']) ?></div>
    <div class="stat-label">Totalt rader</div>
    </div>
    <div class="stat-card">
    <i data-lucide="check-circle" class="icon-lg text-success mb-sm"></i>
    <div class="stat-number"><?= number_format($stats['success']) ?></div>
    <div class="stat-label">Nya</div>
    </div>
    <div class="stat-card">
    <i data-lucide="refresh-cw" class="icon-lg text-accent mb-sm"></i>
    <div class="stat-number"><?= number_format($stats['updated']) ?></div>
    <div class="stat-label">Uppdaterade</div>
    </div>
    <div class="stat-card">
    <i data-lucide="minus-circle" class="icon-lg text-secondary mb-sm"></i>
    <div class="stat-number"><?= number_format($stats['skipped']) ?></div>
    <div class="stat-label">Överhoppade</div>
    </div>
    <div class="stat-card">
    <i data-lucide="x-circle" class="icon-lg text-error mb-sm"></i>
    <div class="stat-number"><?= number_format($stats['failed']) ?></div>
    <div class="stat-label">Misslyckade</div>
    </div>
   </div>

   <!-- Verification Section -->
   <?php if (isset($stats['total_in_db'])): ?>
    <div class="mt-lg section-divider">
    <h3 class="text-primary mb-md">
     <i data-lucide="database"></i>
     Verifiering
    </h3>
    <div class="alert alert--info">
     <p class="gs-text-stat">
     <strong>Totalt i databasen:</strong>
     <span class="gs-text-stat-lg">
      <?= number_format($stats['total_in_db']) ?>
     </span>
     cyklister
     </p>
    </div>
    <div class="flex gap-md mt-md">
     <a href="/admin/riders.php" class="btn btn--primary">
     <i data-lucide="users"></i>
     Se alla deltagare
     </a>
     <a href="/admin/debug-database.php" class="btn btn--secondary">
     <i data-lucide="search"></i>
     Debug databas
     </a>
    </div>
    </div>
   <?php endif; ?>

   <!-- Skipped Rows Details -->
   <?php if (!empty($skippedRows)): ?>
    <div class="mt-lg section-divider">
    <h3 class="text-warning mb-md">
     <i data-lucide="alert-circle"></i>
     Överhoppade rader (<?= count($skippedRows) ?>)
    </h3>
    <div class="gs-scrollable-lg">
     <table class="table table-sm">
     <thead>
      <tr>
      <th>Rad</th>
      <th>Namn</th>
      <th>Licens</th>
      <th>Anledning</th>
      <th>Typ</th>
      </tr>
     </thead>
     <tbody>
      <?php foreach ($skippedRows as $skip): ?>
      <tr>
       <td><code><?= $skip['row'] ?></code></td>
       <td><?= h($skip['name']) ?></td>
       <td><?= h($skip['license'] ?? '-') ?></td>
       <td><?= h($skip['reason']) ?></td>
       <td>
       <?php if ($skip['type'] === 'duplicate'): ?>
        <span class="badge badge-warning text-xs">Dublett</span>
       <?php elseif ($skip['type'] === 'missing_fields'): ?>
        <span class="badge badge-secondary text-xs">Saknar fält</span>
       <?php elseif ($skip['type'] === 'error'): ?>
        <span class="badge badge-danger text-xs">Fel</span>
       <?php endif; ?>
       </td>
      </tr>
      <?php endforeach; ?>
     </tbody>
     </table>
     <?php if (count($skippedRows) > 100): ?>
     <div class="text-sm text-secondary mt-sm gs-text-italic">
      Visar första 100 av <?= count($skippedRows) ?> överhoppade rader
     </div>
     <?php endif; ?>
    </div>
    </div>
   <?php endif; ?>

   <?php if (!empty($errors)): ?>
    <div class="mt-lg section-divider">
    <h3 class="text-error mb-md">
     <i data-lucide="alert-triangle"></i>
     Fel och varningar (<?= count($errors) ?>)
    </h3>
    <div class="gs-scrollable-md">
     <?php foreach (array_slice($errors, 0, 50) as $error): ?>
     <div class="text-sm text-secondary gs-mb-4px">
      • <?= h($error) ?>
     </div>
     <?php endforeach; ?>
     <?php if (count($errors) > 50): ?>
     <div class="text-sm text-secondary mt-sm gs-text-italic">
      ... och <?= count($errors) - 50 ?> fler
     </div>
     <?php endif; ?>
    </div>
    </div>
   <?php endif; ?>
   </div>
  </div>
  <?php endif; ?>

  <!-- Upload Form -->
  <div class="card mb-lg">
  <div class="card-header">
   <h2 class="text-primary">
   <i data-lucide="upload"></i>
   Ladda upp CSV-fil
   </h2>
  </div>
  <div class="card-body">
   <form method="POST" enctype="multipart/form-data" id="uploadForm" class="gs-form-max-width">
   <?= csrf_field() ?>

   <div class="form-group">
    <label for="import_file" class="label">
    <i data-lucide="file"></i>
    Välj CSV-fil
    </label>
    <input
    type="file"
    id="import_file"
    name="import_file"
    class="input"
    accept=".csv,.xlsx,.xls"
    required
    >
    <small class="text-secondary text-sm">
    Max storlek: <?= round(MAX_UPLOAD_SIZE / 1024 / 1024) ?>MB
    </small>
   </div>

   <button type="submit" class="btn btn--primary btn-lg">
    <i data-lucide="upload"></i>
    Importera
   </button>
   </form>

   <!-- Progress Bar (hidden initially) -->
   <div id="progressBar" class="gs-progress-container">
   <div class="flex items-center justify-between mb-sm">
    <span class="text-sm text-primary gs-font-weight-600">Importerar...</span>
    <span class="text-sm text-secondary" id="progressPercent">0%</span>
   </div>
   <div class="gs-progress-bar-container">
    <div id="progressFill" class="gs-progress-bar-fill"></div>
   </div>
   </div>
  </div>
  </div>

  <!-- File Format Guide -->
  <div class="card">
  <div class="card-header">
   <h2 class="text-primary">
   <i data-lucide="info"></i>
   CSV-filformat
   </h2>
  </div>
  <div class="card-body">
   <p class="text-secondary mb-md">
   CSV-filen ska ha följande kolumner i första raden (header):
   </p>

   <div class="table-responsive">
   <table class="table">
    <thead>
    <tr>
     <th>Kolumn</th>
     <th>Obligatorisk</th>
     <th>Beskrivning</th>
     <th>Exempel</th>
    </tr>
    </thead>
    <tbody>
    <tr>
     <td><code>firstname</code> eller <code>first_name</code></td>
     <td><span class="badge badge-danger">Ja</span></td>
     <td>Förnamn</td>
     <td>Erik</td>
    </tr>
    <tr>
     <td><code>lastname</code> eller <code>last_name</code></td>
     <td><span class="badge badge-danger">Ja</span></td>
     <td>Efternamn</td>
     <td>Andersson</td>
    </tr>
    <tr>
     <td><code>birth_year</code></td>
     <td><span class="badge badge-secondary">Nej</span></td>
     <td>Födelseår</td>
     <td>1995</td>
    </tr>
    <tr>
     <td><code>gender</code></td>
     <td><span class="badge badge-secondary">Nej</span></td>
     <td>Kön (M/F)</td>
     <td>M</td>
    </tr>
    <tr>
     <td><code>club</code> eller <code>club_name</code></td>
     <td><span class="badge badge-secondary">Nej</span></td>
     <td>Klubbnamn (skapas om den inte finns)</td>
     <td>Team GravitySeries</td>
    </tr>
    <tr>
     <td><code>license_number</code> eller <code>uci_id</code></td>
     <td><span class="badge badge-secondary">Nej</span></td>
     <td>UCI/SCF licensnummer (används för dubbletthantering)</td>
     <td>SWE-2025-1234</td>
    </tr>
    <tr>
     <td><code>email</code> eller <code>e-mail</code></td>
     <td><span class="badge badge-secondary">Nej</span></td>
     <td>E-postadress</td>
     <td>erik@example.com</td>
    </tr>
    <tr>
     <td><code>phone</code></td>
     <td><span class="badge badge-secondary">Nej</span></td>
     <td>Telefonnummer</td>
     <td>070-1234567</td>
    </tr>
    <tr>
     <td><code>city</code></td>
     <td><span class="badge badge-secondary">Nej</span></td>
     <td>Stad/Ort</td>
     <td>Stockholm</td>
    </tr>
    </tbody>
   </table>
   </div>

   <div class="mt-lg gs-info-box-accent">
   <h3 class="text-primary mb-sm">
    <i data-lucide="lightbulb"></i>
    Tips
   </h3>
   <ul class="text-secondary text-sm gs-list-indented">
    <li>Använd komma (,) som separator</li>
    <li>UTF-8 encoding för svenska tecken</li>
    <li>Stöder både <code>first_name</code> och <code>firstname</code> format</li>
    <li>Dubbletter upptäcks via licensnummer eller namn+födelseår</li>
    <li>Befintliga cyklister uppdateras automatiskt</li>
    <li>Klubbar som inte finns skapas automatiskt</li>
    <li>Fuzzy matching används för klubbnamn (matchas även vid små skillnader)</li>
   </ul>
   </div>

   <div class="mt-md">
   <p class="text-sm text-secondary">
    <strong>Exempel på CSV-fil:</strong>
   </p>
   <pre class="gs-code-block">firstname,lastname,birth_year,gender,club,license_number,email,phone,city
Erik,Andersson,1995,M,Team GravitySeries,SWE-2025-1234,erik@example.com,070-1234567,Stockholm
Anna,Karlsson,1998,F,CK Olympia,SWE-2025-2345,anna@example.com,070-2345678,Göteborg
Johan,Svensson,1992,M,Uppsala CK,SWE-2025-3456,johan@example.com,070-3456789,Uppsala</pre>
   </div>
  </div>
  </div>

<script>
 document.addEventListener('DOMContentLoaded', function() {
 // Show progress bar on form submit
 const form = document.getElementById('uploadForm');
 const progressBar = document.getElementById('progressBar');
 const progressFill = document.getElementById('progressFill');
 const progressPercent = document.getElementById('progressPercent');

 if (form) {
  form.addEventListener('submit', function() {
  progressBar.style.display = 'block';

  // Simulate progress (since we can't track real progress in PHP)
  let progress = 0;
  const interval = setInterval(function() {
   progress += Math.random() * 15;
   if (progress > 90) {
   progress = 90;
   clearInterval(interval);
   }
   progressFill.style.width = progress + '%';
   progressPercent.textContent = Math.round(progress) + '%';
  }, 200);
  });
 }
 });
</script>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>

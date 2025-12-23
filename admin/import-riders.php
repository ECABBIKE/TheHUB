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
$columnMappings = [];

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
   $columnMappings = $result['column_mappings'] ?? [];

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
 * Ensure file is UTF-8 encoded (convert from Windows-1252 if needed)
 */
function ensureUTF8ForImport($filepath) {
 $content = file_get_contents($filepath);

 // Remove BOM if present
 $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);

 // Check if already valid UTF-8
 if (mb_check_encoding($content, 'UTF-8')) {
  // Look for Windows-1252 byte patterns that aren't proper UTF-8
  if (preg_match('/[\xC0-\xFF]/', $content) && !preg_match('/[\xC0-\xFF][\x80-\xBF]/', $content)) {
   $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
   file_put_contents($filepath, $content);
   return;
  }
  // Check for corrupted Swedish words
  if (preg_match('/F.rnamn|f.rnamn|.delseår|.delse.r/u', $content) &&
   !preg_match('/Förnamn|förnamn|Födelseår|födelseår/u', $content)) {
   $content = file_get_contents($filepath);
   $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
   file_put_contents($filepath, $content);
   return;
  }
  file_put_contents($filepath, $content);
  return;
 }

 // Not valid UTF-8, assume Windows-1252
 $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
 file_put_contents($filepath, $content);
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
 $columnMappings = []; // Track original -> mapped column names for debugging

 // Ensure UTF-8 encoding
 ensureUTF8ForImport($filepath);

 if (($handle = fopen($filepath, 'r')) === false) {
 throw new Exception('Kunde inte öppna filen');
 }

 // Auto-detect delimiter (comma or semicolon)
 $firstLine = fgets($handle);
 rewind($handle);
 $delimiter = (substr_count($firstLine, ';') > substr_count($firstLine, ',')) ? ';' : ',';

 // Read header row with detected delimiter
 $originalHeader = fgetcsv($handle, 1000, $delimiter);

 if (!$originalHeader) {
 fclose($handle);
 throw new Exception('Tom fil eller ogiltigt format');
 }

 // Store original header for debugging
 $originalHeaderCopy = $originalHeader;

 // Expected columns: firstname, lastname, birth_year, gender, club, license_number, email, phone, city
 $expectedColumns = ['firstname', 'lastname', 'birth_year', 'gender', 'club'];

 // Normalize header - accept multiple variants of column names
 $header = [];
 foreach ($originalHeader as $originalCol) {
 // Use mb_strtolower for proper UTF-8 handling (Swedish characters Ö, Å, Ä)
 $col = mb_strtolower(trim($originalCol), 'UTF-8');
 $col = str_replace([' ', '-', '_'], '', $col); // Remove spaces, hyphens, underscores

 // Map various column name variants to standard names
 $mappings = [
  // Name fields
  'förnamn' => 'firstname',
  'fornamn' => 'firstname',
  'firstname' => 'firstname',
  'fname' => 'firstname',
  'givenname' => 'firstname',
  'first' => 'firstname',

  'efternamn' => 'lastname',
  'lastname' => 'lastname',
  'surname' => 'lastname',
  'familyname' => 'lastname',
  'lname' => 'lastname',
  'last' => 'lastname',

  // Full name (will be split into firstname/lastname later)
  'namn' => 'fullname',
  'name' => 'fullname',
  'fullname' => 'fullname',
  'fullnamn' => 'fullname',
  'åkare' => 'fullname',
  'akare' => 'fullname',
  'rider' => 'fullname',
  'deltagare' => 'fullname',
  'participant' => 'fullname',

  // Birth year / age
  'födelseår' => 'birthyear',
  'fodelsear' => 'birthyear',
  'birthyear' => 'birthyear',
  'född' => 'birthyear',
  'fodd' => 'birthyear',
  'year' => 'birthyear',
  'år' => 'birthyear',
  'ar' => 'birthyear',
  'ålder' => 'birthyear',
  'alder' => 'birthyear',
  'age' => 'birthyear',
  'födelsedatum' => 'birthdate',
  'fodelsedatum' => 'birthdate',
  'birthdate' => 'birthdate',
  'dateofbirth' => 'birthdate',
  'dob' => 'birthdate',
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
  'förening' => 'club',
  'forening' => 'club',
  'organisation' => 'club',
  'organization' => 'club',
  'org' => 'club',

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

 $mappedCol = $mappings[$col] ?? $col;
 $header[] = $mappedCol;
 $columnMappings[] = ['original' => trim($originalCol), 'mapped' => $mappedCol];
 }

 // Cache for club lookups
 $clubCache = [];

 $lineNumber = 1;

 while (($row = fgetcsv($handle, 1000, $delimiter)) !== false) {
 $lineNumber++;
 $stats['total']++;

 // Map row to associative array
 // Handle case where row has different number of columns than header
 if (count($row) !== count($header)) {
  // Pad row with empty strings if too short, or trim if too long
  if (count($row) < count($header)) {
   $row = array_pad($row, count($header), '');
  } else {
   $row = array_slice($row, 0, count($header));
  }
 }
 $data = array_combine($header, $row);

 // Handle fullname column - split into firstname and lastname
 if (!empty($data['fullname']) && (empty($data['firstname']) || empty($data['lastname']))) {
  $fullname = trim($data['fullname']);
  // Try to split on comma first (Lastname, Firstname format)
  if (strpos($fullname, ',') !== false) {
   $parts = array_map('trim', explode(',', $fullname, 2));
   if (count($parts) >= 2) {
    $data['lastname'] = $parts[0];
    $data['firstname'] = $parts[1];
   }
  } else {
   // Split on space (Firstname Lastname format)
   $parts = preg_split('/\s+/', $fullname);
   if (count($parts) >= 2) {
    $data['firstname'] = $parts[0];
    $data['lastname'] = implode(' ', array_slice($parts, 1));
   } elseif (count($parts) === 1) {
    // Only one word - could be either, put in lastname
    $data['lastname'] = $parts[0];
    $data['firstname'] = '';
   }
  }
 }

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
  // Fall back to birthdate column - extract year from date (YYYY-MM-DD, DD/MM/YYYY, etc.)
  if (!$birthYear && !empty($data['birthdate'])) {
  $dateStr = trim($data['birthdate']);
  // Try to parse various date formats
  if (preg_match('/^(\d{4})[-\/]/', $dateStr, $m)) {
   // YYYY-MM-DD or YYYY/MM/DD
   $birthYear = (int)$m[1];
  } elseif (preg_match('/[-\/](\d{4})$/', $dateStr, $m)) {
   // DD-MM-YYYY or DD/MM/YYYY
   $birthYear = (int)$m[1];
  } elseif (preg_match('/^(\d{4})$/', $dateStr, $m)) {
   // Just year
   $birthYear = (int)$m[1];
  }
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
 'skipped_rows' => $skippedRows,
 'column_mappings' => $columnMappings
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

  <!-- Message -->
  <?php if ($message): ?>
  <div class="alert alert-<?= h($messageType) ?> mb-lg">
   <i data-lucide="<?= $messageType === 'success' ? 'check-circle' : 'alert-circle' ?>"></i>
   <?= h($message) ?>
  </div>
  <?php endif; ?>

  <!-- Statistics -->
  <?php if ($stats): ?>
  <div class="admin-card mb-lg">
   <div class="admin-card-header">
   <h2>
    <i data-lucide="bar-chart"></i>
    Import-statistik
   </h2>
   </div>
   <div class="admin-card-body">
   <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: var(--space-md);">
    <div class="admin-stat-card">
    <div class="admin-stat-icon" style="background: var(--color-bg-muted);">
     <i data-lucide="file-text"></i>
    </div>
    <div class="admin-stat-value"><?= number_format($stats['total']) ?></div>
    <div class="admin-stat-label">Totalt rader</div>
    </div>
    <div class="admin-stat-card">
    <div class="admin-stat-icon" style="background: rgba(97, 206, 112, 0.1); color: var(--color-success);">
     <i data-lucide="check-circle"></i>
    </div>
    <div class="admin-stat-value text-success"><?= number_format($stats['success']) ?></div>
    <div class="admin-stat-label">Nya</div>
    </div>
    <div class="admin-stat-card">
    <div class="admin-stat-icon" style="background: rgba(0, 74, 152, 0.1); color: var(--color-gs-blue);">
     <i data-lucide="refresh-cw"></i>
    </div>
    <div class="admin-stat-value" style="color: var(--color-gs-blue);"><?= number_format($stats['updated']) ?></div>
    <div class="admin-stat-label">Uppdaterade</div>
    </div>
    <div class="admin-stat-card">
    <div class="admin-stat-icon" style="background: rgba(245, 158, 11, 0.1); color: var(--color-warning);">
     <i data-lucide="minus-circle"></i>
    </div>
    <div class="admin-stat-value" style="color: var(--color-warning);"><?= number_format($stats['skipped']) ?></div>
    <div class="admin-stat-label">Överhoppade</div>
    </div>
    <div class="admin-stat-card">
    <div class="admin-stat-icon" style="background: rgba(239, 68, 68, 0.1); color: var(--color-danger);">
     <i data-lucide="x-circle"></i>
    </div>
    <div class="admin-stat-value" style="color: var(--color-danger);"><?= number_format($stats['failed']) ?></div>
    <div class="admin-stat-label">Misslyckade</div>
    </div>
   </div>

   <!-- Column Mappings (debug info) -->
   <?php if (!empty($columnMappings)): ?>
    <details style="margin-top: var(--space-lg); padding-top: var(--space-lg); border-top: 1px solid var(--color-border);">
    <summary class="cursor-pointer flex items-center gap-sm" style="font-weight: 500; margin-bottom: var(--space-md);">
     <i data-lucide="columns"></i>
     Kolumnmappning (<?= count($columnMappings) ?> kolumner)
    </summary>
    <div class="admin-table-container" style="max-height: 300px; overflow-y: auto;">
     <table class="admin-table admin-table-sm">
     <thead>
      <tr>
      <th>Original kolumnnamn</th>
      <th>Mappat till</th>
      <th>Status</th>
      </tr>
     </thead>
     <tbody>
      <?php foreach ($columnMappings as $cm):
       $important = in_array($cm['mapped'], ['firstname', 'lastname', 'fullname', 'birthyear', 'gender', 'club', 'licensenumber']);
       $nameField = in_array($cm['mapped'], ['firstname', 'lastname', 'fullname']);
      ?>
      <tr>
       <td><code><?= htmlspecialchars($cm['original']) ?></code></td>
       <td>
       <?php if ($nameField): ?>
        <span class="admin-badge admin-badge-success"><?= htmlspecialchars($cm['mapped']) ?></span>
       <?php elseif ($important): ?>
        <span class="admin-badge admin-badge-info"><?= htmlspecialchars($cm['mapped']) ?></span>
       <?php else: ?>
        <span class="text-secondary"><?= htmlspecialchars($cm['mapped']) ?></span>
       <?php endif; ?>
       </td>
       <td>
       <?php if ($cm['original'] === $cm['mapped']): ?>
        <span class="text-secondary">Okänd kolumn</span>
       <?php elseif ($nameField): ?>
        <span class="text-success">Namn-fält</span>
       <?php else: ?>
        <span class="text-success">Mappat</span>
       <?php endif; ?>
       </td>
      </tr>
      <?php endforeach; ?>
     </tbody>
     </table>
    </div>
    <p class="text-secondary" style="font-size: 0.75rem; margin-top: var(--space-sm);">
     <strong>Tips:</strong> Om kolumner inte mappas korrekt, kontrollera att CSV-filen har rubriker som:
     Förnamn, Efternamn (eller Namn för fullständigt namn), Födelseår, Kön, Klubb, Licensnummer
    </p>
    </details>
   <?php endif; ?>

   <!-- Verification Section -->
   <?php if (isset($stats['total_in_db'])): ?>
    <div style="margin-top: var(--space-lg); padding-top: var(--space-lg); border-top: 1px solid var(--color-border);">
    <h3 class="flex items-center gap-sm" style="margin-bottom: var(--space-md);">
     <i data-lucide="database"></i>
     Verifiering
    </h3>
    <div class="alert alert-info" style="margin-bottom: var(--space-md);">
     <strong>Totalt i databasen:</strong> <?= number_format($stats['total_in_db']) ?> cyklister
    </div>
    <div style="display: flex; gap: var(--space-md);">
     <a href="/admin/riders.php" class="btn-admin btn-admin-primary">
     <i data-lucide="users"></i>
     Se alla deltagare
     </a>
     <a href="/admin/debug-database.php" class="btn-admin btn-admin-secondary">
     <i data-lucide="search"></i>
     Debug databas
     </a>
    </div>
    </div>
   <?php endif; ?>

   <!-- Skipped Rows Details -->
   <?php if (!empty($skippedRows)): ?>
    <div style="margin-top: var(--space-lg); padding-top: var(--space-lg); border-top: 1px solid var(--color-border);">
    <h3 class="flex items-center gap-sm" style="margin-bottom: var(--space-md); color: var(--color-warning);">
     <i data-lucide="alert-circle"></i>
     Överhoppade rader (<?= count($skippedRows) ?>)
    </h3>
    <div class="table-responsive">
     <table class="table">
     <thead>
      <tr>
      <th style="width: 80px;">Rad</th>
      <th>Namn</th>
      <th>Licens</th>
      <th>Anledning</th>
      <th style="width: 100px;">Typ</th>
      </tr>
     </thead>
     <tbody>
      <?php foreach (array_slice($skippedRows, 0, 100) as $skip): ?>
      <tr>
       <td><code style="background: var(--color-bg-muted); padding: 2px 6px; border-radius: var(--radius-sm);"><?= $skip['row'] ?></code></td>
       <td><?= h($skip['name']) ?></td>
       <td><code class="text-sm"><?= h($skip['license'] ?? '-') ?></code></td>
       <td class="text-secondary"><?= h($skip['reason']) ?></td>
       <td>
       <?php if ($skip['type'] === 'duplicate'): ?>
        <span class="badge badge-warning">Dublett</span>
       <?php elseif ($skip['type'] === 'missing_fields'): ?>
        <span class="badge badge-secondary">Saknar fält</span>
       <?php elseif ($skip['type'] === 'error'): ?>
        <span class="badge badge-danger">Fel</span>
       <?php endif; ?>
       </td>
      </tr>
      <?php endforeach; ?>
     </tbody>
     </table>
     <?php if (count($skippedRows) > 100): ?>
     <p class="text-sm text-secondary" style="margin-top: var(--space-sm); font-style: italic;">
      Visar första 100 av <?= count($skippedRows) ?> överhoppade rader
     </p>
     <?php endif; ?>
    </div>
    </div>
   <?php endif; ?>

   <?php if (!empty($errors)): ?>
    <div style="margin-top: var(--space-lg); padding-top: var(--space-lg); border-top: 1px solid var(--color-border);">
    <h3 class="flex items-center gap-sm" style="margin-bottom: var(--space-md); color: var(--color-danger);">
     <i data-lucide="alert-triangle"></i>
     Fel och varningar (<?= count($errors) ?>)
    </h3>
    <div style="max-height: 300px; overflow-y: auto; padding: var(--space-md); background: var(--color-bg-muted); border-radius: var(--radius-md);">
     <?php foreach (array_slice($errors, 0, 50) as $error): ?>
     <div class="text-sm text-secondary" style="margin-bottom: 4px;">
      • <?= h($error) ?>
     </div>
     <?php endforeach; ?>
     <?php if (count($errors) > 50): ?>
     <p class="text-sm text-secondary" style="margin-top: var(--space-sm); font-style: italic;">
      ... och <?= count($errors) - 50 ?> fler
     </p>
     <?php endif; ?>
    </div>
    </div>
   <?php endif; ?>
   </div>
  </div>
  <?php endif; ?>

  <!-- Upload Form -->
  <div class="admin-card mb-lg">
  <div class="admin-card-header">
   <h2>
   <i data-lucide="upload"></i>
   Ladda upp CSV-fil
   </h2>
  </div>
  <div class="admin-card-body">
   <form method="POST" enctype="multipart/form-data" id="uploadForm" style="max-width: 500px;">
   <?= csrf_field() ?>

   <div class="admin-form-group">
    <label class="admin-form-label">
    <i data-lucide="file"></i>
    Välj CSV-fil
    </label>
    <input
    type="file"
    id="import_file"
    name="import_file"
    class="admin-form-input"
    accept=".csv,.xlsx,.xls"
    required
    >
    <small class="text-secondary text-sm">
    Max storlek: <?= round(MAX_UPLOAD_SIZE / 1024 / 1024) ?>MB
    </small>
   </div>

   <button type="submit" class="btn-admin btn-admin-primary">
    <i data-lucide="upload"></i>
    Importera
   </button>
   </form>
  </div>
  </div>

  <!-- File Format Guide -->
  <div class="admin-card">
  <div class="admin-card-header">
   <h2>
   <i data-lucide="info"></i>
   CSV-filformat
   </h2>
  </div>
  <div class="admin-card-body">
   <p class="text-secondary" style="margin-bottom: var(--space-md);">
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

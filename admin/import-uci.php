<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_admin();

$db = getDB();
$current_admin = get_current_admin();

// Load import history helper functions
require_once __DIR__ . '/../includes/import-history.php';

$message = '';
$messageType = 'info';
$stats = null;
$errors = [];
$updated_riders = [];

// Handle CSV upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['uci_file'])) {
 // Validate CSRF token
 checkCsrf();

 $file = $_FILES['uci_file'];

 // Validate file
 if ($file['error'] !== UPLOAD_ERR_OK) {
 $message = 'Filuppladdning misslyckades';
 $messageType = 'error';
 } elseif ($file['size'] > MAX_UPLOAD_SIZE) {
 $message = 'Filen är för stor (max ' . (MAX_UPLOAD_SIZE / 1024 / 1024) . 'MB)';
 $messageType = 'error';
 } else {
 $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

 if ($extension !== 'csv') {
 $message = 'Ogiltigt filformat. Endast CSV tillåten.';
 $messageType = 'error';
 } else {
 // Process the file
 $uploaded = UPLOADS_PATH . '/' . time() . '_uci_' . basename($file['name']);

 if (move_uploaded_file($file['tmp_name'], $uploaded)) {
 try {
  // Start import history tracking
  $importId = startImportHistory(
  $db,
  'uci',
  $file['name'],
  $file['size'],
  $current_admin['username'] ?? 'admin'
  );

  // Perform import
  $result = importUCIRiders($uploaded, $db, $importId);

  $stats = $result['stats'];
  $errors = $result['errors'];
  $updated_riders = $result['updated'];

  // Update import history with final statistics
  $importStatus = ($stats['success'] > 0 || $stats['updated'] > 0) ? 'completed' : 'failed';
  updateImportHistory($db, $importId, $stats, $errors, $importStatus);

  if ($stats['success'] > 0 || $stats['updated'] > 0) {
  $message ="Import klar! {$stats['success']} nya riders, {$stats['updated']} uppdaterade. <a href='/admin/import-history.php' class='gs-text-underline'>Visa historik</a>";
  $messageType = 'success';
  } else {
  $message ="Ingen data importerades. Kontrollera filformatet.";
  $messageType = 'error';
  }

 } catch (Exception $e) {
  $message = 'Import misslyckades: ' . $e->getMessage();
  $messageType = 'error';

  // Mark import as failed if importId was created
  if (isset($importId)) {
  updateImportHistory($db, $importId, ['total' => 0], [$e->getMessage()], 'failed');
  }
 }

 @unlink($uploaded);
 } else {
 $message = 'Kunde inte ladda upp filen';
 $messageType = 'error';
 }
 }
 }
}

/**
 * Auto-detect CSV separator with improved logic
 */
function detectCsvSeparator($file_path) {
 $handle = fopen($file_path, 'r');
 $first_line = fgets($handle);
 fclose($handle);

 // Try all common separators
 $separators = [
 ',' => str_getcsv($first_line, ','),
 ';' => str_getcsv($first_line, ';'),
"\t" => str_getcsv($first_line,"\t"),
 '|' => str_getcsv($first_line, '|')
 ];

 // Return separator with most columns (should be 11+)
 $max_count = 0;
 $best_sep = ',';
 foreach ($separators as $sep => $row) {
 $count = count($row);
 if ($count > $max_count) {
 $max_count = $count;
 $best_sep = $sep;
 }
 }

 error_log("Separator detection - Best: '$best_sep' with $max_count columns");
 return $best_sep;
}

/**
 * Detect and convert file encoding to UTF-8
 */
function ensureUTF8($filepath) {
 $content = file_get_contents($filepath);
 $encoding = mb_detect_encoding($content, ['UTF-8', 'ISO-8859-1', 'Windows-1252', 'CP1252'], true);

 if ($encoding && $encoding !== 'UTF-8') {
 error_log("Converting file from $encoding to UTF-8");
 $content = mb_convert_encoding($content, 'UTF-8', $encoding);
 file_put_contents($filepath, $content);
 return true;
 }

 return false;
}

/**
 * Import riders from UCI CSV file
 *
 * @param string $filepath Path to CSV file
 * @param object $db Database connection
 * @param int $importId Import history ID for tracking
 */
function importUCIRiders($filepath, $db, $importId = null) {
 $stats = [
 'total' => 0,
 'success' => 0,
 'updated' => 0,
 'skipped' => 0,
 'failed' => 0
 ];
 $errors = [];
 $updated_riders = [];
 $clubCache = [];

 // STEP 1: Convert encoding to UTF-8 if needed
 ensureUTF8($filepath);

 if (($handle = fopen($filepath, 'r')) === false) {
 throw new Exception('Kunde inte öppna filen');
 }

 // STEP 2: Auto-detect separator
 $separator = detectCsvSeparator($filepath);
 $stats['separator'] = $separator;
 $stats['separator_name'] = ($separator ==="\t") ? 'TAB' : $separator;

 error_log("=== UCI IMPORT STARTED ===");
 error_log("File:" . basename($filepath));
 error_log("Separator detected:" . $stats['separator_name']);

 // Check if first line is header
 $first_line = fgets($handle);
 if (!preg_match('/^\d{8}-\d{4}/', $first_line)) {
 // It's a header, continue to next line
 } else {
 // Not a header, rewind to start
 rewind($handle);
 }

 $lineNumber = 1;

 while (($line = fgets($handle)) !== false) {
 $lineNumber++;
 $stats['total']++;

 // Skip empty lines
 if (trim($line) === '') {
 continue;
 }

 try {
 // Parse CSV row with detected separator
 $row = str_getcsv($line, $separator);

 // STEP 3: Handle missing columns gracefully (pad with empty strings)
 while (count($row) < 11) {
 $row[] = '';
 }

 // Trim ALL values to remove whitespace
 $row = array_map('trim', $row);

 // STEP 4: Comprehensive error logging for first 3 rows
 if ($stats['total'] <= 3) {
 error_log("=== ROW" . $stats['total'] ." ===");
 error_log("Raw line:" . substr($line, 0, 200)); // First 200 chars
 error_log("Separator used:" . $stats['separator_name']);
 error_log("Parsed columns:" . count($row));
 error_log("Column data:");
 for ($i = 0; $i < count($row); $i++) {
  error_log(" [$i] = '" . substr($row[$i], 0, 50) ."'");
 }
 }

 // Extract data according to UCI format position
 // Note: Column 0 contains personnummer which is parsed to extract birth_year only
 // The personnummer itself is NOT stored in the database
 $personnummer_raw = $row[0]; // Used only to extract birth_year
 $firstname = $row[1];
 $lastname = $row[2];
 $country = $row[3]; // Ignore, always Sweden
 $email = $row[4];
 $club_name = $row[5];
 $discipline = $row[6];
 $gender_raw = $row[7];
 $license_category = $row[8];
 $license_year = $row[9];
 $uci_code = $row[10];

 // Validate required fields
 if (empty($firstname) || empty($lastname)) {
 $stats['skipped']++;
 $errors[] ="Rad {$lineNumber}: Saknar förnamn eller efternamn";
 continue;
 }

 // Parse personnummer to extract birth_year (personnummer is NOT stored)
 $birth_year = parsePersonnummer($personnummer_raw);
 if (!$birth_year) {
 $stats['failed']++;
 $errors[] ="Rad {$lineNumber}: {$firstname} {$lastname} - Ogiltigt födelsedatum '{$personnummer_raw}'";
 continue;
 }

 // 2. Gender: Men/Women → M/F
 $gender = 'M'; // Default
 if (stripos($gender_raw, 'women') !== false || stripos($gender_raw, 'dam') !== false) {
 $gender = 'F';
 } elseif (stripos($gender_raw, 'men') !== false || stripos($gender_raw, 'herr') !== false) {
 $gender = 'M';
 }

 // 3. UCI ID: Keep exact format with spaces (e.g."101 637 581 11")
 // Only generate SWE-ID if UCI code is missing
 if (!empty($uci_code)) {
 $license_number = $uci_code; // Keep as-is with spaces
 } else {
 // Generate SWE-ID for riders without UCI code
 $license_number = generateSweId($db);
 }

 // 4. License type: Extract from category
 $license_type = 'Base';
 if (stripos($license_category, 'Master') !== false) {
 $license_type = 'Master';
 } elseif (stripos($license_category, 'Elite') !== false) {
 $license_type = 'Elite';
 } elseif (stripos($license_category, 'Youth') !== false || stripos($license_category, 'Under') !== false || stripos($license_category, 'U1') !== false || stripos($license_category, 'U2') !== false) {
 $license_type = 'Youth';
 } elseif (stripos($license_category, 'Team Manager') !== false) {
 $license_type = 'Team Manager';
 }

 // 5. License valid until: Year → Last day of year
 $license_valid_until = null;
 if (!empty($license_year) && is_numeric($license_year)) {
 $license_valid_until = $license_year . '-12-31';
 }

 // 6. Find or create club
 $club_id = null;
 if (!empty($club_name)) {
 // Check cache first
 if (isset($clubCache[$club_name])) {
  $club_id = $clubCache[$club_name];
 } else {
  // Try exact match
  $club = $db->getRow(
 "SELECT id FROM clubs WHERE name = ? LIMIT 1",
  [$club_name]
  );

  if (!$club) {
  // Try fuzzy match
  $club = $db->getRow(
 "SELECT id FROM clubs WHERE name LIKE ? LIMIT 1",
  ['%' . $club_name . '%']
  );
  }

  if (!$club) {
  // Create new club
  $club_id = $db->insert('clubs', [
  'name' => $club_name,
  'country' => 'Sverige',
  'active' => 1
  ]);
  $clubCache[$club_name] = $club_id;
  } else {
  $club_id = $club['id'];
  $clubCache[$club_name] = $club_id;
  }
 }
 }

 // 7. Check if rider exists (by license number or name+birth_year)
 $existing = null;

 if (!empty($license_number)) {
 $existing = $db->getRow(
 "SELECT id FROM riders WHERE license_number = ? LIMIT 1",
  [$license_number]
 );
 }

 if (!$existing && $birth_year) {
 $existing = $db->getRow(
 "SELECT id FROM riders WHERE firstname = ? AND lastname = ? AND birth_year = ? LIMIT 1",
  [$firstname, $lastname, $birth_year]
 );
 }

 // Prepare rider data
 $riderData = [
 'firstname' => $firstname,
 'lastname' => $lastname,
 'birth_year' => $birth_year,
 'gender' => $gender,
 'club_id' => $club_id,
 'license_number' => $license_number,
 'license_type' => $license_type,
 'license_category' => $license_category,
 'discipline' => $discipline,
 'license_valid_until' => $license_valid_until,
 'license_year' => !empty($license_year) && is_numeric($license_year) ? (int)$license_year : null,
 'email' => !empty($email) ? $email : null,
 'active' => 1
 ];

 if ($existing) {
 // Get old data before updating (for rollback)
 $oldData = null;
 if ($importId) {
  $oldData = $db->getRow("SELECT * FROM riders WHERE id = ?", [$existing['id']]);
 }

 // Update existing rider
 $db->update('riders', $riderData, 'id = ?', [$existing['id']]);
 $stats['updated']++;
 $updated_riders[] ="{$firstname} {$lastname}";

 // Track updated record
 if ($importId) {
  trackImportRecord($db, $importId, 'rider', $existing['id'], 'updated', $oldData);
 }
 } else {
 // Insert new rider
 $riderId = $db->insert('riders', $riderData);
 $stats['success']++;

 // Track created record
 if ($importId && $riderId) {
  trackImportRecord($db, $importId, 'rider', $riderId, 'created');
 }
 }

 } catch (Exception $e) {
 $stats['failed']++;
 $errors[] ="Rad {$lineNumber}:" . $e->getMessage();
 }
 }

 fclose($handle);

 // Final summary log
 error_log("=== UCI IMPORT COMPLETED ===");
 error_log("Total processed:" . $stats['total']);
 error_log("Success (new):" . $stats['success']);
 error_log("Updated:" . $stats['updated']);
 error_log("Skipped:" . $stats['skipped']);
 error_log("Failed:" . $stats['failed']);

 return [
 'stats' => $stats,
 'errors' => $errors,
 'updated' => $updated_riders
 ];
}

// Page config for unified layout
$page_title = 'UCI Import';
$breadcrumbs = [
    ['label' => 'Import', 'url' => '/admin/import'],
    ['label' => 'UCI']
];

// Include unified layout
include __DIR__ . '/components/unified-layout.php';
?>

<?php if ($message): ?>
<div class="alert alert-<?= $messageType === 'success' ? 'success' : ($messageType === 'error' ? 'error' : 'info') ?> mb-lg">
    <p><strong><?= h($message) ?></strong></p>

    <?php if ($stats): ?>
    <div style="margin-top: var(--space-md);">
        <?php if (isset($stats['separator_name'])): ?>
        <p style="font-size: var(--text-sm); margin-bottom: var(--space-sm);">
            <i data-lucide="search" style="width: 14px; height: 14px;"></i>
            <strong>Detekterad separator:</strong> <code><?= h($stats['separator_name']) ?></code>
        </p>
        <?php endif; ?>
        <p><i data-lucide="bar-chart" style="width: 14px; height: 14px;"></i> <strong>Statistik:</strong></p>
        <ul style="margin-left: var(--space-lg); margin-top: var(--space-sm);">
            <li>Totalt rader: <?= $stats['total'] ?></li>
            <li style="color: var(--color-success);">Nya riders: <?= $stats['success'] ?></li>
            <li style="color: var(--color-accent);">Uppdaterade: <?= $stats['updated'] ?></li>
            <li style="color: var(--color-text-secondary);">Överhoppade: <?= $stats['skipped'] ?></li>
            <li style="color: var(--color-error);">Misslyckade: <?= $stats['failed'] ?></li>
        </ul>
    </div>
    <?php endif; ?>

    <?php if (!empty($updated_riders)): ?>
    <details style="margin-top: var(--space-md);">
        <summary style="cursor: pointer;"><?= count($updated_riders) ?> uppdaterade riders</summary>
        <ul style="margin-left: var(--space-lg); margin-top: var(--space-sm);">
            <?php foreach (array_slice($updated_riders, 0, 20) as $rider): ?>
            <li><?= h($rider) ?></li>
            <?php endforeach; ?>
            <?php if (count($updated_riders) > 20): ?>
            <li><em>... och <?= count($updated_riders) - 20 ?> till</em></li>
            <?php endif; ?>
        </ul>
    </details>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
    <details style="margin-top: var(--space-md);">
        <summary style="cursor: pointer; color: var(--color-error);"><?= count($errors) ?> fel</summary>
        <ul style="margin-left: var(--space-lg); margin-top: var(--space-sm);">
            <?php foreach (array_slice($errors, 0, 20) as $error): ?>
            <li><?= h($error) ?></li>
            <?php endforeach; ?>
            <?php if (count($errors) > 20): ?>
            <li><em>... och <?= count($errors) - 20 ?> fler fel</em></li>
            <?php endif; ?>
        </ul>
    </details>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Format Information -->
<div class="admin-card mb-lg">
    <div class="admin-card-header">
        <h2>
            <i data-lucide="info"></i>
            UCI Licensregister Format
        </h2>
    </div>
    <div class="admin-card-body">
        <p style="margin-bottom: var(--space-md);">Denna import hanterar CSV direkt från UCI Licensregister.</p>

        <h4 style="margin-bottom: var(--space-sm); color: var(--color-text);">Kolumner (ingen header behövs):</h4>
        <ol style="margin-left: var(--space-lg); line-height: 1.8;">
            <li><strong>Personnummer</strong> (YYYYMMDD-XXXX) - parsas till birth_year (personnummer sparas EJ)</li>
            <li><strong>Förnamn</strong> - first_name</li>
            <li><strong>Efternamn</strong> - last_name</li>
            <li><strong>Land</strong> - ignoreras</li>
            <li><strong>Epostadress</strong> - email</li>
            <li><strong>Huvudförening</strong> - club_name (skapas automatiskt om den inte finns)</li>
            <li><strong>Gren</strong> - discipline (MTB, Road, Track, BMX, CX, etc)</li>
            <li><strong>Kategori</strong> - gender (Men = M, Women = F)</li>
            <li><strong>Licenstyp</strong> - license_category (Master Men, Elite Men, Base License Men, etc)</li>
            <li><strong>LicensÅr</strong> - license_valid_until (2025 = 2025-12-31)</li>
            <li><strong>UCIKod</strong> - license_number (sparas exakt som det är, t.ex. "101 637 581 11")</li>
        </ol>

        <div style="margin-top: var(--space-lg); padding: var(--space-md); background: var(--color-bg-tertiary); border-radius: var(--radius-md);">
            <p style="color: var(--color-text-secondary); font-size: var(--text-sm); margin-bottom: var(--space-sm);"><strong>Exempel på giltig rad:</strong></p>
            <code style="font-size: var(--text-sm);">
                19400525-0651,Lars,Nordensson,Sverige,ernst@email.com,Ringmurens Cykelklubb,MTB,Men,Master Men,2025,101 637 581 11
            </code>
        </div>

        <div class="alert alert-success" style="margin-top: var(--space-md);">
            <p style="font-size: var(--text-sm);">
                <strong>Automatiska funktioner:</strong><br>
                - Födelsedatum parsas automatiskt från personnummer (både YYYYMMDD-XXXX och YYMMDD-XXXX format)<br>
                - <strong>OBS: Endast födelseår (birth_year) sparas - personnummer lagras EJ</strong><br>
                - Klubbar skapas automatiskt om de inte finns<br>
                - UCI-koder sparas exakt med mellanslag (t.ex. "101 637 581 11")<br>
                - Befintliga riders uppdateras om de hittas (via license_number eller namn+födelseår)<br>
                - SWE-ID (SWE25XXXXX) genereras automatiskt för riders utan UCI-kod
            </p>
        </div>
    </div>
</div>

<!-- Upload Form -->
<div class="admin-card mb-lg">
    <div class="admin-card-header">
        <h2>
            <i data-lucide="upload"></i>
            Ladda upp UCI-fil
        </h2>
    </div>
    <div class="admin-card-body">
        <form method="POST" enctype="multipart/form-data">
            <?= csrf_field() ?>

            <div class="admin-form-group">
                <label class="admin-form-label">
                    <i data-lucide="file-text"></i>
                    CSV-fil från UCI Licensregister
                </label>
                <input type="file" name="uci_file" accept=".csv" class="admin-form-input" required>
                <small style="color: var(--color-text-secondary);">
                    Endast CSV-filer. Max <?= MAX_UPLOAD_SIZE / 1024 / 1024 ?>MB.
                </small>
            </div>

            <div class="flex gap-md">
                <button type="submit" class="btn-admin btn-admin-primary">
                    <i data-lucide="upload"></i>
                    Importera från UCI
                </button>
                <a href="/admin/riders.php" class="btn-admin btn-admin-secondary">
                    <i data-lucide="arrow-left"></i>
                    Tillbaka till riders
                </a>
            </div>
        </form>
    </div>
</div>

<!-- Quick Links -->
<div class="grid" style="grid-template-columns: repeat(2, 1fr); gap: var(--space-lg);">
    <div class="admin-card">
        <div class="admin-card-body">
            <h4 style="margin-bottom: var(--space-sm);">
                <i data-lucide="users"></i>
                Andra importalternativ
            </h4>
            <p style="color: var(--color-text-secondary); font-size: var(--text-sm); margin-bottom: var(--space-md);">
                Om du vill använda en anpassad CSV-mall istället för UCI-format.
            </p>
            <a href="/admin/import-riders.php" class="btn-admin btn-admin-secondary btn-admin-sm">
                <i data-lucide="upload"></i>
                Standard Rider Import
            </a>
        </div>
    </div>

    <div class="admin-card">
        <div class="admin-card-body">
            <h4 style="margin-bottom: var(--space-sm);">
                <i data-lucide="download"></i>
                Ladda ner mallar
            </h4>
            <p style="color: var(--color-text-secondary); font-size: var(--text-sm); margin-bottom: var(--space-md);">
                Ladda ner CSV-mallar för standard import.
            </p>
            <a href="/admin/download-templates.php?template=riders" class="btn-admin btn-admin-secondary btn-admin-sm">
                <i data-lucide="download"></i>
                Ladda ner Rider-mall
            </a>
        </div>
    </div>
</div>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>

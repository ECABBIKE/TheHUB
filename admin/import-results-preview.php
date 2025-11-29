<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/import-history.php';
require_once __DIR__ . '/../includes/class-calculations.php';
require_once __DIR__ . '/../includes/point-calculations.php';
require_once __DIR__ . '/../includes/import-functions.php';
require_once __DIR__ . '/../includes/series-points.php'; // For syncing series results
require_admin();
require_once __DIR__ . '/../includes/admin-layout.php';

$db = getDB();
$current_admin = get_current_admin();

$message = '';
$messageType = 'info';

// Check if we have a file and event to preview
if (!isset($_SESSION['import_preview_file']) || !file_exists($_SESSION['import_preview_file'])) {
 header('Location: /admin/import-results.php');
 exit;
}

if (!isset($_SESSION['import_selected_event'])) {
 header('Location: /admin/import-results.php');
 exit;
}

$selectedEventId = $_SESSION['import_selected_event'];
$importFormat = $_SESSION['import_format'] ?? 'enduro';
$formatNames = [
 'enduro' => 'Enduro',
 'dh' => 'Downhill'
];

// Get selected event info
$selectedEvent = $db->getRow("SELECT * FROM events WHERE id = ?", [$selectedEventId]);
if (!$selectedEvent) {
 $_SESSION['import_error'] = 'Valt event hittades inte';
 header('Location: /admin/import-results.php');
 exit;
}

// Parse CSV and calculate matching stats
$previewData = [];
$matchingStats = [
 'total_rows' => 0,
 'riders_existing' => 0,
 'riders_new' => 0,
 'clubs_existing' => 0,
 'clubs_new' => 0,
 'classes' => [],
 'potential_duplicates' => []
];

try {
 $result = parseAndAnalyzeCSV($_SESSION['import_preview_file'], $db);
 $previewData = $result['data'];
 $matchingStats = $result['stats'];
} catch (Exception $e) {
 $message = 'Parsning misslyckades: ' . $e->getMessage();
 $messageType = 'error';
}

// Get all existing classes for mapping
$existingClasses = $db->getAll("SELECT id, name, display_name, sort_order FROM classes WHERE active = 1 ORDER BY sort_order ASC, display_name ASC");

// Analyze which CSV classes exist and which are new
$classAnalysis = [];
foreach ($matchingStats['classes'] as $csvClass) {
 $match = null;
 $csvClassNormalized = strtolower(trim($csvClass));

 foreach ($existingClasses as $existing) {
 if (strtolower($existing['display_name']) === $csvClassNormalized ||
  strtolower($existing['name']) === $csvClassNormalized) {
  $match = $existing;
  break;
 }
 }

 // Try partial match if no exact match
 if (!$match) {
 foreach ($existingClasses as $existing) {
  if (strpos(strtolower($existing['display_name']), $csvClassNormalized) !== false ||
  strpos($csvClassNormalized, strtolower($existing['display_name'])) !== false) {
  $match = $existing;
  break;
  }
 }
 }

 $classAnalysis[] = [
 'csv_name' => $csvClass,
 'matched' => $match,
 'is_new' => $match === null
 ];
}

// Handle confirmed import
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_import'])) {
 checkCsrf();

 try {
 // Process class mappings from form
 $classMappings = [];
 if (isset($_POST['class_mapping']) && is_array($_POST['class_mapping'])) {
  foreach ($_POST['class_mapping'] as $csvClass => $mappedClassId) {
  if (!empty($mappedClassId) && $mappedClassId !== 'new') {
   $classMappings[$csvClass] = (int)$mappedClassId;
  }
  }
 }

 // Store class mappings in a global so import function can use them
 global $IMPORT_CLASS_MAPPINGS;
 $IMPORT_CLASS_MAPPINGS = $classMappings;

 // Create event mapping - all rows go to selected event
 $eventMapping = ['Välj event för alla resultat' => $selectedEventId];

 // Import with event mapping
 $importId = startImportHistory(
  $db,
  'results',
  $_SESSION['import_preview_filename'],
  filesize($_SESSION['import_preview_file']),
  $current_admin['username'] ?? 'admin'
 );

 $result = importResultsFromCSVWithMapping(
  $_SESSION['import_preview_file'],
  $db,
  $importId,
  $eventMapping,
  null
 );

 $stats = $result['stats'];
 $matching_stats = $result['matching'];
 $errors = $result['errors'];

 // Auto-save stage names from import headers to event
 if (!empty($result['stage_names'])) {
  $db->update('events', [
  'stage_names' => json_encode($result['stage_names'])
  ], 'id = ?', [$selectedEventId]);
 }

 // Update import history
 $importStatus = ($stats['success'] > 0) ? 'completed' : 'failed';
 updateImportHistory($db, $importId, $stats, $errors, $importStatus);

 // Recalculate results to fix class assignments and calculate correct points
 $recalcStats = recalculateEventResults($db, $selectedEventId);
 $classesFixed = $recalcStats['classes_fixed'] ?? 0;
 $pointsCalculated = $recalcStats['points_updated'] ?? 0;

 // Sync series results (if event is part of any series)
 // NOTE: This updates series_results table, separate from ranking points in results.points
 $seriesStats = syncEventResultsToAllSeries($db, $selectedEventId);
 $seriesSynced = count($seriesStats);

 // Clean up
 @unlink($_SESSION['import_preview_file']);
 unset($_SESSION['import_preview_file']);
 unset($_SESSION['import_preview_filename']);
 unset($_SESSION['import_selected_event']);

 // Redirect to event page with success message
 $recalcMsg ="";
 if ($classesFixed > 0 || $pointsCalculated > 0) {
  $recalcMsg =" Omräkning: {$classesFixed} klassplaceringar fixade, {$pointsCalculated} poäng beräknade.";
 }

 // Add info about riders updated with UCI IDs
 $matchingInfo ="";
 $ridersCreated = $matching_stats['riders_created'] ?? 0;
 $ridersUpdatedWithUci = $matching_stats['riders_updated_with_uci'] ?? 0;
 if ($ridersCreated > 0 || $ridersUpdatedWithUci > 0) {
  $parts = [];
  if ($ridersCreated > 0) {
  $parts[] ="{$ridersCreated} nya förare (med SWE-ID)";
  }
  if ($ridersUpdatedWithUci > 0) {
  $parts[] ="{$ridersUpdatedWithUci} förare fick UCI-ID";
  }
  $matchingInfo ="" . implode(",", $parts) .".";
 }

 // Summarize changelog for updates
 $changelogInfo ="";
 $changelog = $result['changelog'] ?? [];
 if (!empty($changelog)) {
  // Count changed fields
  $fieldCounts = [];
  foreach ($changelog as $change) {
  foreach ($change['changes'] as $field => $values) {
   $fieldCounts[$field] = ($fieldCounts[$field] ?? 0) + 1;
  }
  }
  // Show top 3 most changed fields
  arsort($fieldCounts);
  $topFields = array_slice($fieldCounts, 0, 3, true);
  $fieldParts = [];
  foreach ($topFields as $field => $count) {
  $fieldParts[] ="{$field}: {$count}";
  }
  if (!empty($fieldParts)) {
  $changelogInfo =" Ändrade fält:" . implode(",", $fieldParts) .".";
  }
 }

 // Show skipped as unchanged
 $unchangedInfo ="";
 if ($stats['skipped'] > 0) {
  $unchangedInfo =" ({$stats['skipped']} oförändrade)";
 }

 set_flash('success',"Import klar! {$stats['success']} nya, {$stats['updated']} uppdaterade{$unchangedInfo} av {$stats['total']} resultat.{$matchingInfo}{$changelogInfo}{$recalcMsg}");
 header('Location: /admin/event-edit.php?id=' . $selectedEventId . '&tab=results');
 exit;

 } catch (Exception $e) {
 $message = 'Import misslyckades: ' . $e->getMessage();
 $messageType = 'error';
 }
}

// Handle cancel
if (isset($_GET['cancel'])) {
 @unlink($_SESSION['import_preview_file']);
 unset($_SESSION['import_preview_file']);
 unset($_SESSION['import_preview_filename']);
 unset($_SESSION['import_selected_event']);
 header('Location: /admin/import-results.php');
 exit;
}

/**
 * Check if a row appears to be a field mapping/description row
 * These rows contain field names like"class","position","club_name" instead of actual data
 */
function isFieldMappingRowPreview($row) {
 if (!is_array($row)) return false;

 // Known field mapping keywords that appear in description rows
 $fieldKeywords = ['class', 'position', 'club_name', 'license_number', 'finish_time', 'status', 'firstname', 'lastname'];

 $matchCount = 0;
 foreach ($row as $value) {
 $cleanValue = strtolower(trim($value));
 if (in_array($cleanValue, $fieldKeywords)) {
  $matchCount++;
 }
 }

 // If 3 or more values match field keywords, it's likely a mapping row
 return $matchCount >= 3;
}

/**
 * Parse CSV and analyze matching statistics
 */
function parseAndAnalyzeCSV($filepath, $db) {
 $data = [];
 $stats = [
 'total_rows' => 0,
 'riders_existing' => 0,
 'riders_new' => 0,
 'clubs_existing' => 0,
 'clubs_new' => 0,
 'clubs_list' => [],
 'classes' => [],
 'potential_duplicates' => []
 ];

 $riderCache = [];
 $clubCache = [];
 $duplicateCache = [];

 if (($handle = fopen($filepath, 'r')) === false) {
 throw new Exception('Kunde inte öppna filen');
 }

 // Auto-detect delimiter
 $firstLine = fgets($handle);
 rewind($handle);
 $delimiter = (substr_count($firstLine, ';') > substr_count($firstLine, ',')) ? ';' : ',';

 // Read header (0 = unlimited line length)
 $header = fgetcsv($handle, 0, $delimiter);
 if (!$header) {
 fclose($handle);
 throw new Exception('Tom fil eller ogiltigt format');
 }

 // Normalize header
 $header = array_map(function($col) {
 $col = strtolower(trim(str_replace([' ', '-', '_'], '', $col)));

 if (empty($col)) {
  return 'empty_' . uniqid();
 }

 $mappings = [
  'firstname' => 'firstname', 'förnamn' => 'firstname', 'fornamn' => 'firstname',
  'lastname' => 'lastname', 'efternamn' => 'lastname',
  'category' => 'category', 'class' => 'category', 'klass' => 'category',
  'club' => 'club_name', 'klubb' => 'club_name', 'team' => 'club_name',
  'position' => 'position', 'placering' => 'position', 'placebycategory' => 'position',
  'time' => 'finish_time', 'tid' => 'finish_time', 'nettime' => 'finish_time',
  'status' => 'status',
  'uciid' => 'license_number', 'licens' => 'license_number',
  'ss1' => 'ss1', 'ss2' => 'ss2', 'ss3' => 'ss3', 'ss4' => 'ss4',
  'ss5' => 'ss5', 'ss6' => 'ss6', 'ss7' => 'ss7', 'ss8' => 'ss8',
  'ss9' => 'ss9', 'ss10' => 'ss10',
 ];

 return $mappings[$col] ?? $col;
 }, $header);

 // Read all rows
 while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
 if (count($row) < 2) continue;

 // Skip field mapping/description rows (contain field names like"class","position", etc.)
 if (isFieldMappingRowPreview($row)) {
  continue;
 }

 // Pad or trim row to match header
 if (count($row) < count($header)) {
  $row = array_pad($row, count($header), '');
 } elseif (count($row) > count($header)) {
  $row = array_slice($row, 0, count($header));
 }

 $rowData = array_combine($header, $row);
 $data[] = $rowData;
 $stats['total_rows']++;

 // Check rider matching and duplicates
 $firstName = trim($rowData['firstname'] ?? '');
 $lastName = trim($rowData['lastname'] ?? '');
 $licenseNumber = trim($rowData['license_number'] ?? '');
 $normalizedLicense = preg_replace('/[^0-9]/', '', $licenseNumber);

 if (!empty($firstName) && !empty($lastName)) {
  $riderKey = $firstName . '|' . $lastName . '|' . $normalizedLicense;

  if (!isset($riderCache[$riderKey])) {
  $rider = null;
  $isDuplicate = false;

  // Try normalized license first
  if (!empty($normalizedLicense)) {
   $rider = $db->getRow(
   "SELECT id, firstname, lastname, license_number FROM riders WHERE REPLACE(REPLACE(license_number, ' ', ''), '-', '') = ?",
   [$normalizedLicense]
   );

   // Check if it's a format duplicate (same UCI but different format)
   if ($rider && $rider['license_number'] !== $licenseNumber && !empty($rider['license_number'])) {
   $dupKey = $normalizedLicense;
   if (!isset($duplicateCache[$dupKey])) {
    $duplicateCache[$dupKey] = true;
    $stats['potential_duplicates'][] = [
    'csv_name' => $firstName . ' ' . $lastName,
    'csv_license' => $licenseNumber,
    'existing_id' => $rider['id'],
    'existing_name' => $rider['firstname'] . ' ' . $rider['lastname'],
    'existing_license' => $rider['license_number'],
    'type' => 'uci_format'
    ];
   }
   }
  }

  // Try name match if no license match
  if (!$rider) {
   $rider = $db->getRow(
   "SELECT id, firstname, lastname, license_number FROM riders WHERE firstname = ? AND lastname = ?",
   [$firstName, $lastName]
   );

   // Only suggest duplicate if UCI-IDs don't conflict
   // Same name + no UCI = possible duplicate
   // Same name + same UCI = same person (not duplicate warning)
   // Same name + different UCI = different people (NO duplicate)
   if ($rider) {
   $existingLicense = preg_replace('/[^0-9]/', '', $rider['license_number'] ?? '');

   // Check if this is a potential duplicate (both have no UCI or one is missing)
   if (empty($normalizedLicense) && empty($existingLicense)) {
    // Both have no UCI - possible duplicate
    $dupKey = strtolower($firstName . '|' . $lastName);
    if (!isset($duplicateCache[$dupKey])) {
    $duplicateCache[$dupKey] = true;
    $stats['potential_duplicates'][] = [
     'csv_name' => $firstName . ' ' . $lastName,
     'csv_license' => $licenseNumber ?: '(ingen)',
     'existing_id' => $rider['id'],
     'existing_name' => $rider['firstname'] . ' ' . $rider['lastname'],
     'existing_license' => $rider['license_number'] ?: '(ingen)',
     'type' => 'name_no_uci'
    ];
    }
   } elseif (!empty($normalizedLicense) && !empty($existingLicense) && $normalizedLicense !== $existingLicense) {
    // Different UCI-IDs = different people, not a duplicate
    $rider = null; // Treat as new rider
   }
   }
  }

  $riderCache[$riderKey] = $rider ? true : false;

  if ($rider) {
   $stats['riders_existing']++;
  } else {
   $stats['riders_new']++;
  }
  }
 }

 // Check club matching
 $clubName = trim($rowData['club_name'] ?? '');
 if (!empty($clubName) && !isset($clubCache[$clubName])) {
  $club = $db->getRow(
  "SELECT id, name FROM clubs WHERE name LIKE ?",
  ['%' . $clubName . '%']
  );

  $clubCache[$clubName] = $club ? true : false;

  if ($club) {
  $stats['clubs_existing']++;
  } else {
  $stats['clubs_new']++;
  }
  $stats['clubs_list'][] = $clubName;
 }

 // Track classes
 $className = trim($rowData['category'] ?? '');
 if (!empty($className) && !in_array($className, $stats['classes'])) {
  $stats['classes'][] = $className;
 }
 }

 fclose($handle);

 // Make clubs list unique
 $stats['clubs_list'] = array_unique($stats['clubs_list']);

 return ['data' => $data, 'stats' => $stats];
}

$pageTitle = 'Förhandsgranska import';
$pageType = 'admin';
include __DIR__ . '/../includes/layout-header.php';
?>

<main class="main-content">
 <div class="container">
 <?php render_admin_header('Import & Data'); ?>

 <!-- Messages -->
 <?php if ($message): ?>
  <div class="alert alert-<?= $messageType ?> mb-lg">
  <i data-lucide="<?= $messageType === 'success' ? 'check-circle' : 'alert-circle' ?>"></i>
  <?= h($message) ?>
  </div>
 <?php endif; ?>

 <!-- Event & Format Info -->
 <div class="card mb-lg">
  <div class="card-body">
  <div class="flex items-center gap-lg">
   <div class="flex items-center gap-md">
   <i data-lucide="calendar" class="icon-lg text-primary"></i>
   <div>
    <h3 class="gs-m-0"><?= h($selectedEvent['name']) ?></h3>
    <p class="text-secondary gs-m-0">
    <?= date('Y-m-d', strtotime($selectedEvent['date'])) ?>
    <?php if ($selectedEvent['location']): ?>
     - <?= h($selectedEvent['location']) ?>
    <?php endif; ?>
    </p>
   </div>
   </div>
   <div class="flex items-center gap-md">
   <i data-lucide="<?= $importFormat === 'dh' ? 'arrow-down' : 'mountain' ?>" class="icon-lg <?= $importFormat === 'dh' ? 'text-warning' : 'text-success' ?>"></i>
   <div>
    <h3 class="gs-m-0"><?= h($formatNames[$importFormat] ?? 'Okänt') ?></h3>
    <p class="text-secondary gs-m-0">Format</p>
   </div>
   </div>
  </div>
  </div>
 </div>

 <!-- Stats Cards -->
 <div class="grid grid-cols-2 gs-md-grid-cols-4 gap-lg mb-lg">
  <div class="stat-card">
  <i data-lucide="file-text" class="icon-lg text-primary mb-md"></i>
  <div class="stat-number"><?= $matchingStats['total_rows'] ?></div>
  <div class="stat-label">Rader i fil</div>
  </div>
  <div class="stat-card">
  <i data-lucide="user-check" class="icon-lg text-success mb-md"></i>
  <div class="stat-number"><?= $matchingStats['riders_existing'] ?></div>
  <div class="stat-label">Befintliga deltagare</div>
  </div>
  <div class="stat-card">
  <i data-lucide="user-plus" class="icon-lg text-warning mb-md"></i>
  <div class="stat-number"><?= $matchingStats['riders_new'] ?></div>
  <div class="stat-label">Nya deltagare</div>
  </div>
  <div class="stat-card">
  <i data-lucide="building" class="icon-lg text-accent mb-md"></i>
  <div class="stat-number"><?= count($matchingStats['clubs_list']) ?></div>
  <div class="stat-label">Klubbar</div>
  </div>
 </div>

 <!-- Potential Duplicates Warning -->
 <?php if (!empty($matchingStats['potential_duplicates'])): ?>
  <div class="card mb-lg">
  <div class="card-header gs-bg-warning" style="background: var(--gs-warning-light, #fff3cd);">
   <h3 class="text-warning">
   <i data-lucide="alert-triangle"></i>
   Potentiella dubletter (<?= count($matchingStats['potential_duplicates']) ?>)
   </h3>
  </div>
  <div class="card-body">
   <p class="text-sm text-secondary mb-md">
   Dessa deltagare i CSV:en matchar befintliga deltagare med samma UCI-ID (olika format) eller samma namn utan UCI-ID.
   <br><strong>Obs:</strong> Deltagare med samma namn men olika UCI-ID visas inte här - de är olika personer.
   </p>
   <div class="table-responsive" style="max-height: 250px; overflow: auto;">
   <table class="table table-sm">
    <thead>
    <tr>
     <th>I CSV</th>
     <th>Befintlig deltagare</th>
     <th>Typ</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($matchingStats['potential_duplicates'] as $dup): ?>
     <tr>
     <td>
      <strong><?= h($dup['csv_name']) ?></strong>
      <br><code class="text-xs"><?= h($dup['csv_license']) ?></code>
     </td>
     <td>
      <strong><?= h($dup['existing_name']) ?></strong>
      <br><code class="text-xs"><?= h($dup['existing_license']) ?></code>
     </td>
     <td>
      <?php if ($dup['type'] === 'uci_format'): ?>
      <span class="badge badge-sm badge-info">UCI-format</span>
      <?php else: ?>
      <span class="badge badge-sm badge-warning">Namn utan UCI</span>
      <?php endif; ?>
     </td>
     </tr>
    <?php endforeach; ?>
    </tbody>
   </table>
   </div>
   <div class="alert alert--info mt-md">
   <i data-lucide="info"></i>
   <strong>Tips:</strong> Importera filen - systemet matchar automatiskt via normaliserat UCI-ID.
   <br>Du kan också <a href="/admin/cleanup-duplicates.php" class="link">rensa dubletter</a> efteråt.
   </div>
  </div>
  </div>
 <?php endif; ?>

 <!-- Import Form - wraps all mappings and submit button -->
 <form method="POST" id="importForm">
  <?= csrf_field() ?>

 <!-- Matching Details -->
 <div class="grid grid-cols-1 md-grid-cols-2 gap-lg mb-lg">
  <!-- Clubs -->
  <div class="card">
  <div class="card-header">
   <h3 class="text-primary">
   <i data-lucide="building"></i>
   Klubbar
   </h3>
  </div>
  <div class="card-body">
   <?php if ($matchingStats['clubs_new'] > 0): ?>
   <div class="alert alert--warning mb-md">
    <i data-lucide="info"></i>
    <?= $matchingStats['clubs_new'] ?> nya klubbar kommer att skapas
   </div>
   <?php endif; ?>
   <div class="flex flex-wrap gap-sm">
   <?php foreach ($matchingStats['clubs_list'] as $club): ?>
    <span class="badge badge-secondary"><?= h($club) ?></span>
   <?php endforeach; ?>
   </div>
  </div>
  </div>

  <!-- Classes -->
  <div class="card">
  <div class="card-header">
   <h3 class="text-primary">
   <i data-lucide="tag"></i>
   Klassmappning
   </h3>
  </div>
  <div class="card-body">
   <?php
   $newClasses = array_filter($classAnalysis, fn($c) => $c['is_new']);
   if (count($newClasses) > 0):
   ?>
   <div class="alert alert--warning mb-md">
    <i data-lucide="alert-triangle"></i>
    <strong><?= count($newClasses) ?> nya klasser</strong> hittades. Mappa dem till befintliga klasser eller skapa nya.
   </div>
   <?php endif; ?>

   <div class="table-responsive" style="max-height: 300px; overflow: auto;">
   <table class="table table-sm">
    <thead>
    <tr>
     <th>Klass i CSV</th>
     <th>Status</th>
     <th>Mappa till</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($classAnalysis as $classInfo): ?>
     <tr>
     <td>
      <strong><?= h($classInfo['csv_name']) ?></strong>
     </td>
     <td>
      <?php if ($classInfo['matched']): ?>
      <span class="badge badge-success text-xs">
       <i data-lucide="check" style="width: 12px; height: 12px;"></i>
       Matchad
      </span>
      <?php else: ?>
      <span class="badge badge-warning text-xs">
       <i data-lucide="alert-circle" style="width: 12px; height: 12px;"></i>
       Ny
      </span>
      <?php endif; ?>
     </td>
     <td>
      <select name="class_mapping[<?= h($classInfo['csv_name']) ?>]" class="input input-sm" style="min-width: 200px;">
      <?php if ($classInfo['matched']): ?>
       <option value="<?= $classInfo['matched']['id'] ?>" selected>
       <?= h($classInfo['matched']['display_name']) ?>
       </option>
      <?php else: ?>
       <option value="new" selected>-- Skapa ny klass --</option>
      <?php endif; ?>
      <option value="new">-- Skapa ny klass --</option>
      <?php foreach ($existingClasses as $existing): ?>
       <?php if (!$classInfo['matched'] || $existing['id'] != $classInfo['matched']['id']): ?>
       <option value="<?= $existing['id'] ?>">
        <?= h($existing['display_name']) ?>
        <?php if ($existing['sort_order']): ?>(#<?= $existing['sort_order'] ?>)<?php endif; ?>
       </option>
       <?php endif; ?>
      <?php endforeach; ?>
      </select>
     </td>
     </tr>
    <?php endforeach; ?>
    </tbody>
   </table>
   </div>
  </div>
  </div>
 </div>

 <!-- Data Preview -->
 <div class="card mb-lg">
  <div class="card-header">
  <h3 class="text-primary">
   <i data-lucide="table"></i>
   Data preview (första 20 rader)
  </h3>
  </div>
  <div class="card-body gs-padding-0">
  <?php
  // Get columns to display
  $sampleRow = reset($previewData);
  $displayColumns = ['category', 'position', 'firstname', 'lastname', 'club_name', 'finish_time', 'status'];
  $displayColumns = array_filter($displayColumns, function($col) use ($sampleRow) {
   return isset($sampleRow[$col]);
  });
  ?>
  <div class="table-responsive" style="max-height: 400px; overflow: auto;">
   <table class="table table-sm">
   <thead style="position: sticky; top: 0; background: var(--gs-white); z-index: 10;">
    <tr>
    <th>#</th>
    <?php foreach ($displayColumns as $col): ?>
     <th>
     <?php
     $names = [
      'category' => 'Klass',
      'position' => 'Plac',
      'firstname' => 'Förnamn',
      'lastname' => 'Efternamn',
      'club_name' => 'Klubb',
      'finish_time' => 'Tid',
      'status' => 'Status'
     ];
     echo $names[$col] ?? $col;
     ?>
     </th>
    <?php endforeach; ?>
    </tr>
   </thead>
   <tbody>
    <?php
    $rowNum = 0;
    foreach (array_slice($previewData, 0, 20) as $row):
    $rowNum++;
    ?>
    <tr>
    <td class="text-secondary"><?= $rowNum ?></td>
    <?php foreach ($displayColumns as $col): ?>
     <td>
     <?php
     $value = $row[$col] ?? '';
     if ($col === 'category' && !empty($value)) {
      echo '<span class="badge badge-sm badge-primary">' . h($value) . '</span>';
     } elseif ($col === 'position' && !empty($value) && $value <= 3) {
      echo '<strong class="text-success">' . h($value) . '</strong>';
     } elseif ($col === 'status' && !empty($value)) {
      $statusClass = in_array(strtoupper($value), ['FIN', 'FINISHED', 'OK']) ? 'badge-success' : 'badge-warning';
      echo '<span class="badge badge-sm ' . $statusClass . '">' . h(strtoupper($value)) . '</span>';
     } else {
      echo h($value ?: '–');
     }
     ?>
     </td>
    <?php endforeach; ?>
    </tr>
    <?php endforeach; ?>
   </tbody>
   </table>
  </div>
  <?php if (count($previewData) > 20): ?>
   <div class="gs-padding-md text-center text-secondary text-sm">
   Visar 20 av <?= count($previewData) ?> rader
   </div>
  <?php endif; ?>
  </div>
 </div>

 <!-- Import Button -->
  <div class="flex gap-md gs-justify-end">
  <a href="?cancel=1" class="btn btn--secondary btn-lg">
   <i data-lucide="x"></i>
   Avbryt
  </a>
  <button type="submit" name="confirm_import" class="btn btn-success btn-lg">
   <i data-lucide="check"></i>
   Importera <?= $matchingStats['total_rows'] ?> resultat
  </button>
  </div>
 </form>
 </div>
 <?php render_admin_footer(); ?>
</main>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>

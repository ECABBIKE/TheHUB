<?php
/**
 * Bulk Import Results - Mass CSV Import Tool
 *
 * Designed for importing many CSV files at once with:
 * - Saved class name mappings
 * - Event auto-detection by filename
 * - Batch processing with progress tracking
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/import-history.php';
require_once __DIR__ . '/../includes/class-calculations.php';
require_once __DIR__ . '/../includes/point-calculations.php';
require_once __DIR__ . '/../includes/import-functions.php';
require_once __DIR__ . '/../includes/series-points.php';
require_once __DIR__ . '/../includes/rebuild-rider-stats.php';
require_admin();

$db = getDB();
$current_admin = get_current_admin();

// Path to save class mappings
$mappingsFile = __DIR__ . '/../data/class-mappings.json';
if (!file_exists(dirname($mappingsFile))) {
    mkdir(dirname($mappingsFile), 0755, true);
}

// Load saved class mappings
$savedMappings = [];
if (file_exists($mappingsFile)) {
    $savedMappings = json_decode(file_get_contents($mappingsFile), true) ?? [];
}

// Get all events for dropdown
$events = $db->getAll("
    SELECT e.id, e.name, e.date, e.location, e.discipline, e.event_format,
           YEAR(e.date) as event_year,
           GROUP_CONCAT(DISTINCT s.name ORDER BY s.name SEPARATOR ', ') as series_names
    FROM events e
    LEFT JOIN series_events se ON e.id = se.event_id
    LEFT JOIN series s ON se.series_id = s.id
    GROUP BY e.id
    ORDER BY e.date DESC
");

// Get all existing classes
$existingClasses = $db->getAll("
    SELECT id, name, display_name, sort_order
    FROM classes
    WHERE active = 1
    ORDER BY sort_order ASC, display_name ASC
");

$message = '';
$messageType = 'info';
$processedFiles = [];
$pendingFiles = [];

// Handle saving new class mapping
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_mapping'])) {
    checkCsrf();
    $csvClass = trim($_POST['csv_class_name'] ?? '');
    $mappedClassId = (int)($_POST['mapped_class_id'] ?? 0);

    if (!empty($csvClass) && $mappedClassId > 0) {
        $savedMappings[strtolower($csvClass)] = $mappedClassId;
        file_put_contents($mappingsFile, json_encode($savedMappings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $message = "Mappning sparad: '{$csvClass}' → klass #{$mappedClassId}";
        $messageType = 'success';
    }
}

// Handle deleting a mapping
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_mapping'])) {
    checkCsrf();
    $csvClass = trim($_POST['csv_class_name'] ?? '');
    if (!empty($csvClass) && isset($savedMappings[strtolower($csvClass)])) {
        unset($savedMappings[strtolower($csvClass)]);
        file_put_contents($mappingsFile, json_encode($savedMappings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $message = "Mappning borttagen: '{$csvClass}'";
        $messageType = 'success';
    }
}

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_files'])) {
    checkCsrf();

    $files = $_FILES['csv_files'];
    $uploadedFiles = [];

    // Reorganize files array for easier processing
    for ($i = 0; $i < count($files['name']); $i++) {
        if ($files['error'][$i] === UPLOAD_ERR_OK && !empty($files['name'][$i])) {
            $ext = strtolower(pathinfo($files['name'][$i], PATHINFO_EXTENSION));
            if ($ext === 'csv') {
                $uploadedFiles[] = [
                    'name' => $files['name'][$i],
                    'tmp_name' => $files['tmp_name'][$i],
                    'size' => $files['size'][$i]
                ];
            }
        }
    }

    if (count($uploadedFiles) > 0) {
        // Save to session for processing
        $_SESSION['bulk_import_files'] = [];

        foreach ($uploadedFiles as $file) {
            $savedPath = UPLOADS_PATH . '/' . time() . '_' . mt_rand(1000, 9999) . '_' . basename($file['name']);
            if (move_uploaded_file($file['tmp_name'], $savedPath)) {
                $_SESSION['bulk_import_files'][] = [
                    'path' => $savedPath,
                    'name' => $file['name'],
                    'size' => $file['size']
                ];
            }
        }

        $message = count($_SESSION['bulk_import_files']) . " filer uppladdade. Konfigurera event-kopplingar nedan.";
        $messageType = 'success';
    } else {
        $message = "Inga giltiga CSV-filer hittades.";
        $messageType = 'error';
    }
}

// Handle bulk import execution
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['execute_import'])) {
    checkCsrf();

    $fileEventMap = $_POST['file_event'] ?? [];
    $fileFormatMap = $_POST['file_format'] ?? [];
    $classMappingsFromForm = $_POST['class_mapping'] ?? [];

    // Save new class mappings
    foreach ($classMappingsFromForm as $csvClass => $mappedId) {
        if (!empty($mappedId) && $mappedId !== 'new' && $mappedId !== 'skip') {
            $savedMappings[strtolower($csvClass)] = (int)$mappedId;
        }
    }
    if (!empty($classMappingsFromForm)) {
        file_put_contents($mappingsFile, json_encode($savedMappings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    global $IMPORT_CLASS_MAPPINGS;
    $IMPORT_CLASS_MAPPINGS = [];
    foreach ($savedMappings as $csvClass => $classId) {
        $IMPORT_CLASS_MAPPINGS[$csvClass] = $classId;
    }

    $totalSuccess = 0;
    $totalFailed = 0;
    $processedFiles = [];

    foreach ($_SESSION['bulk_import_files'] ?? [] as $idx => $fileInfo) {
        $eventId = (int)($fileEventMap[$idx] ?? 0);
        $format = $fileFormatMap[$idx] ?? 'enduro';

        if ($eventId <= 0 || !file_exists($fileInfo['path'])) {
            $processedFiles[] = [
                'name' => $fileInfo['name'],
                'status' => 'skipped',
                'message' => 'Inget event valt eller fil saknas'
            ];
            continue;
        }

        try {
            // Get event info
            $event = $db->getRow("SELECT * FROM events WHERE id = ?", [$eventId]);
            if (!$event) {
                throw new Exception("Event #{$eventId} finns inte");
            }

            // Start import
            $importId = startImportHistory(
                $db, 'results', $fileInfo['name'], $fileInfo['size'],
                $current_admin['username'] ?? 'admin'
            );

            $eventMapping = ['Välj event' => $eventId];

            $result = importResultsFromCSVWithMapping(
                $fileInfo['path'], $db, $importId, $eventMapping, null, false
            );

            $stats = $result['stats'];
            updateImportHistory($db, $importId, $stats, $result['errors'] ?? [],
                ($stats['success'] > 0) ? 'completed' : 'failed');

            // Recalculate
            $isDH = ($event['discipline'] ?? '') === 'DH';
            if ($isDH) {
                recalculateDHEventResults($db, $eventId);
            } else {
                recalculateEventResults($db, $eventId);
            }

            // Sync series
            syncEventResultsToAllSeries($db, $eventId);

            // Rebuild stats
            rebuildEventRiderStats($db->getPdo(), $eventId);

            $processedFiles[] = [
                'name' => $fileInfo['name'],
                'status' => 'success',
                'message' => "{$stats['success']} nya, {$stats['updated']} uppdaterade av {$stats['total']}",
                'event' => $event['name']
            ];
            $totalSuccess++;

            // Clean up file
            @unlink($fileInfo['path']);

        } catch (Exception $e) {
            $processedFiles[] = [
                'name' => $fileInfo['name'],
                'status' => 'error',
                'message' => $e->getMessage()
            ];
            $totalFailed++;
        }
    }

    // Clear session
    unset($_SESSION['bulk_import_files']);

    $message = "Import klar! {$totalSuccess} lyckades, {$totalFailed} misslyckades.";
    $messageType = $totalFailed > 0 ? 'warning' : 'success';
}

// Handle cancel
if (isset($_GET['cancel'])) {
    foreach ($_SESSION['bulk_import_files'] ?? [] as $fileInfo) {
        @unlink($fileInfo['path']);
    }
    unset($_SESSION['bulk_import_files']);
    header('Location: /admin/bulk-import-results.php');
    exit;
}

// Analyze pending files for class mapping
$allCsvClasses = [];
if (!empty($_SESSION['bulk_import_files'])) {
    foreach ($_SESSION['bulk_import_files'] as $fileInfo) {
        if (file_exists($fileInfo['path'])) {
            $classes = extractClassesFromCSV($fileInfo['path']);
            foreach ($classes as $className) {
                if (!in_array($className, $allCsvClasses)) {
                    $allCsvClasses[] = $className;
                }
            }
        }
    }
}

/**
 * Extract class names from CSV file
 */
function extractClassesFromCSV($filepath) {
    $classes = [];
    if (($handle = fopen($filepath, 'r')) === false) {
        return $classes;
    }

    // Detect delimiter
    $firstLine = fgets($handle);
    rewind($handle);
    $delimiter = (substr_count($firstLine, ';') > substr_count($firstLine, ',')) ? ';' : ',';

    // Read header
    $header = fgetcsv($handle, 0, $delimiter);
    if (!$header) {
        fclose($handle);
        return $classes;
    }

    // Find category column
    $catIndex = -1;
    foreach ($header as $idx => $col) {
        $col = strtolower(trim(str_replace([' ', '-', '_'], '', $col)));
        if (in_array($col, ['category', 'class', 'klass', 'kategori'])) {
            $catIndex = $idx;
            break;
        }
    }

    if ($catIndex < 0) {
        fclose($handle);
        return $classes;
    }

    // Extract unique classes
    while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
        if (isset($row[$catIndex]) && !empty(trim($row[$catIndex]))) {
            $className = trim($row[$catIndex]);
            if (!in_array($className, $classes)) {
                $classes[] = $className;
            }
        }
    }

    fclose($handle);
    return $classes;
}

/**
 * Try to match event by filename
 */
function guessEventFromFilename($filename, $events) {
    $filename = strtolower(pathinfo($filename, PATHINFO_FILENAME));
    $filename = str_replace(['_', '-'], ' ', $filename);

    foreach ($events as $event) {
        $eventName = strtolower($event['name']);
        $eventDate = $event['date'];

        // Try exact name match
        if (strpos($filename, strtolower($event['name'])) !== false) {
            return $event['id'];
        }

        // Try date match (YYYY-MM-DD or YYYYMMDD)
        $dateFormats = [
            date('Y-m-d', strtotime($eventDate)),
            date('Ymd', strtotime($eventDate)),
            date('Y m d', strtotime($eventDate)),
        ];
        foreach ($dateFormats as $df) {
            if (strpos($filename, $df) !== false) {
                return $event['id'];
            }
        }

        // Try location match
        if (!empty($event['location'])) {
            if (strpos($filename, strtolower($event['location'])) !== false) {
                return $event['id'];
            }
        }
    }

    return null;
}

// Check class matches for pending files
$classMatchStatus = [];
foreach ($allCsvClasses as $csvClass) {
    $normalized = strtolower($csvClass);
    $matched = false;
    $matchedTo = null;

    // Check saved mappings first
    if (isset($savedMappings[$normalized])) {
        $matched = true;
        $matchedTo = $savedMappings[$normalized];
    } else {
        // Check exact match in existing classes
        foreach ($existingClasses as $existing) {
            if (strtolower($existing['display_name']) === $normalized ||
                strtolower($existing['name']) === $normalized) {
                $matched = true;
                $matchedTo = $existing['id'];
                break;
            }
        }
    }

    $classMatchStatus[] = [
        'csv_name' => $csvClass,
        'matched' => $matched,
        'matched_to' => $matchedTo
    ];
}

// Page config
$page_title = 'Bulk-import resultat';
$breadcrumbs = [
    ['label' => 'Import', 'url' => '/admin/import'],
    ['label' => 'Bulk-import']
];

include __DIR__ . '/components/unified-layout.php';
?>

<!-- Messages -->
<?php if ($message): ?>
<div class="alert alert-<?= $messageType ?> mb-lg">
    <i data-lucide="<?= $messageType === 'success' ? 'check-circle' : ($messageType === 'error' ? 'alert-circle' : 'info') ?>"></i>
    <?= h($message) ?>
</div>
<?php endif; ?>

<!-- Processed Files Results -->
<?php if (!empty($processedFiles)): ?>
<div class="card mb-lg">
    <div class="card-header">
        <h2 class="text-primary">
            <i data-lucide="file-check"></i>
            Importresultat
        </h2>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Fil</th>
                        <th>Event</th>
                        <th>Status</th>
                        <th>Detaljer</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($processedFiles as $pf): ?>
                    <tr>
                        <td><strong><?= h($pf['name']) ?></strong></td>
                        <td><?= h($pf['event'] ?? '-') ?></td>
                        <td>
                            <?php if ($pf['status'] === 'success'): ?>
                            <span class="badge badge-success">OK</span>
                            <?php elseif ($pf['status'] === 'error'): ?>
                            <span class="badge badge-danger">Fel</span>
                            <?php else: ?>
                            <span class="badge badge-warning">Hoppades</span>
                            <?php endif; ?>
                        </td>
                        <td><?= h($pf['message']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (empty($_SESSION['bulk_import_files'])): ?>
<!-- Upload Form -->
<div class="card mb-lg">
    <div class="card-header">
        <h2 class="text-primary">
            <i data-lucide="upload"></i>
            Ladda upp CSV-filer
        </h2>
    </div>
    <div class="card-body">
        <form method="POST" enctype="multipart/form-data">
            <?= csrf_field() ?>

            <div class="form-group mb-lg">
                <label class="form-label">Välj CSV-filer (max 50 st)</label>
                <input type="file" name="csv_files[]" class="form-input" accept=".csv" multiple required>
                <p class="text-sm text-secondary mt-sm">
                    Du kan välja flera filer genom att hålla Ctrl/Cmd när du klickar.
                </p>
            </div>

            <button type="submit" class="btn btn-primary">
                <i data-lucide="upload"></i>
                Ladda upp filer
            </button>
        </form>
    </div>
</div>

<!-- Saved Class Mappings -->
<div class="card mb-lg">
    <div class="card-header">
        <h2 class="text-primary">
            <i data-lucide="link"></i>
            Sparade klassmappningar (<?= count($savedMappings) ?>)
        </h2>
    </div>
    <div class="card-body">
        <?php if (empty($savedMappings)): ?>
        <p class="text-secondary">Inga sparade mappningar. Mappningar sparas automatiskt vid import.</p>
        <?php else: ?>
        <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
            <table class="table table-sm">
                <thead>
                    <tr>
                        <th>CSV-klassnamn</th>
                        <th>Mappad till</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($savedMappings as $csvClass => $classId):
                        $targetClass = null;
                        foreach ($existingClasses as $ec) {
                            if ($ec['id'] == $classId) {
                                $targetClass = $ec;
                                break;
                            }
                        }
                    ?>
                    <tr>
                        <td><code><?= h($csvClass) ?></code></td>
                        <td>
                            <?php if ($targetClass): ?>
                            <span class="badge badge-primary"><?= h($targetClass['display_name']) ?></span>
                            <?php else: ?>
                            <span class="badge badge-warning">Klass #<?= $classId ?> (borttagen?)</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <form method="POST" style="display: inline;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="csv_class_name" value="<?= h($csvClass) ?>">
                                <button type="submit" name="delete_mapping" class="btn btn-ghost btn-sm text-danger"
                                        onclick="return confirm('Ta bort mappning?')">
                                    <i data-lucide="trash-2"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <!-- Add new mapping -->
        <details class="mt-lg">
            <summary class="text-primary" style="cursor: pointer;">
                <i data-lucide="plus"></i> Lägg till mappning manuellt
            </summary>
            <form method="POST" class="mt-md">
                <?= csrf_field() ?>
                <div class="grid grid-cols-1 md-grid-cols-3 gap-md">
                    <div class="form-group">
                        <label class="form-label">CSV-klassnamn</label>
                        <input type="text" name="csv_class_name" class="form-input" required
                               placeholder="T.ex. 'Herrar Elite'">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Mappa till klass</label>
                        <select name="mapped_class_id" class="form-select" required>
                            <option value="">-- Välj klass --</option>
                            <?php foreach ($existingClasses as $ec): ?>
                            <option value="<?= $ec['id'] ?>"><?= h($ec['display_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" style="display: flex; align-items: flex-end;">
                        <button type="submit" name="save_mapping" class="btn btn-secondary">
                            <i data-lucide="save"></i> Spara
                        </button>
                    </div>
                </div>
            </form>
        </details>
    </div>
</div>

<?php else: ?>
<!-- File Configuration -->
<form method="POST">
    <?= csrf_field() ?>

    <!-- Class Mappings for unmapped classes -->
    <?php
    $unmappedClasses = array_filter($classMatchStatus, fn($c) => !$c['matched']);
    if (!empty($unmappedClasses)):
    ?>
    <div class="card mb-lg">
        <div class="card-header" style="background: var(--color-warning); color: white;">
            <h2>
                <i data-lucide="alert-triangle"></i>
                Nya klasser (<?= count($unmappedClasses) ?>)
            </h2>
        </div>
        <div class="card-body">
            <p class="text-secondary mb-md">
                Dessa klassnamn hittades i CSV-filerna men finns inte i databasen.
                Mappa dem till befintliga klasser eller välj "Skapa ny".
            </p>
            <div class="table-responsive">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>Klassnamn i CSV</th>
                            <th>Mappa till</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($unmappedClasses as $classInfo): ?>
                        <tr>
                            <td><strong><?= h($classInfo['csv_name']) ?></strong></td>
                            <td>
                                <select name="class_mapping[<?= h($classInfo['csv_name']) ?>]" class="form-select">
                                    <option value="new">-- Skapa ny klass --</option>
                                    <option value="skip">-- Hoppa över --</option>
                                    <?php foreach ($existingClasses as $ec): ?>
                                    <option value="<?= $ec['id'] ?>"><?= h($ec['display_name']) ?></option>
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
    <?php endif; ?>

    <!-- Matched classes info -->
    <?php
    $matchedClasses = array_filter($classMatchStatus, fn($c) => $c['matched']);
    if (!empty($matchedClasses)):
    ?>
    <div class="card mb-lg">
        <div class="card-header">
            <h2 class="text-success">
                <i data-lucide="check-circle"></i>
                Matchade klasser (<?= count($matchedClasses) ?>)
            </h2>
        </div>
        <div class="card-body">
            <div class="flex flex-wrap gap-sm">
                <?php foreach ($matchedClasses as $classInfo): ?>
                <span class="badge badge-success"><?= h($classInfo['csv_name']) ?></span>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- File-to-Event Mapping -->
    <div class="card mb-lg">
        <div class="card-header">
            <h2 class="text-primary">
                <i data-lucide="file-spreadsheet"></i>
                Filer att importera (<?= count($_SESSION['bulk_import_files']) ?>)
            </h2>
        </div>
        <div class="card-body">
            <p class="text-secondary mb-md">
                Koppla varje fil till rätt event. Filer utan valt event hoppas över.
            </p>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Filnamn</th>
                            <th>Format</th>
                            <th>Event</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($_SESSION['bulk_import_files'] as $idx => $fileInfo):
                            $guessedEventId = guessEventFromFilename($fileInfo['name'], $events);
                        ?>
                        <tr>
                            <td><?= $idx + 1 ?></td>
                            <td>
                                <strong><?= h($fileInfo['name']) ?></strong>
                                <br><span class="text-sm text-secondary"><?= round($fileInfo['size'] / 1024, 1) ?> KB</span>
                            </td>
                            <td>
                                <select name="file_format[<?= $idx ?>]" class="form-select form-select-sm">
                                    <option value="enduro">Enduro</option>
                                    <option value="dh">Downhill</option>
                                    <option value="xc">XC</option>
                                </select>
                            </td>
                            <td>
                                <select name="file_event[<?= $idx ?>]" class="form-select" style="min-width: 300px;">
                                    <option value="">-- Hoppa över --</option>
                                    <?php foreach ($events as $event): ?>
                                    <option value="<?= $event['id'] ?>" <?= $guessedEventId == $event['id'] ? 'selected' : '' ?>>
                                        <?php if ($event['series_names']): ?>[<?= h($event['series_names']) ?>] <?php endif; ?>
                                        <?= h($event['name']) ?> (<?= date('Y-m-d', strtotime($event['date'])) ?>)
                                    </option>
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

    <!-- Action Buttons -->
    <div class="flex gap-md justify-end">
        <a href="?cancel=1" class="btn btn-secondary">
            <i data-lucide="x"></i>
            Avbryt
        </a>
        <button type="submit" name="execute_import" class="btn btn-success btn-lg">
            <i data-lucide="play"></i>
            Starta import
        </button>
    </div>
</form>
<?php endif; ?>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>

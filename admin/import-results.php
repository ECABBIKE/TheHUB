<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/import-history.php';
require_once __DIR__ . '/../includes/class-calculations.php';
require_admin();

$db = getDB();
$current_admin = get_current_admin();

$message = '';
$messageType = 'info';

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

$pageTitle = 'Importera Resultat';
$pageType = 'admin';
include __DIR__ . '/../includes/layout-header.php';
?>

<main class="gs-content-with-sidebar">
    <div class="gs-container">
        <!-- Header -->
        <div class="gs-flex gs-items-center gs-justify-between gs-mb-xl">
            <h1 class="gs-h1 gs-text-primary">
                <i data-lucide="upload"></i>
                Importera Resultat
            </h1>
            <a href="/admin/import-history.php" class="gs-btn gs-btn-outline">
                <i data-lucide="history"></i>
                Importhistorik
            </a>
        </div>

        <!-- Messages -->
        <?php if ($message): ?>
            <div class="gs-alert gs-alert-<?= $messageType ?> gs-mb-lg">
                <i data-lucide="<?= $messageType === 'success' ? 'check-circle' : ($messageType === 'error' ? 'alert-circle' : 'info') ?>"></i>
                <?= h($message) ?>
            </div>
        <?php endif; ?>

        <!-- Import Form -->
        <div class="gs-card">
            <div class="gs-card-header">
                <h2 class="gs-h4 gs-text-primary">
                    <i data-lucide="file-plus"></i>
                    Importera resultat till event
                </h2>
            </div>
            <div class="gs-card-content">
                <form method="POST" enctype="multipart/form-data" class="gs-form">
                    <?= csrf_field() ?>

                    <!-- Step 1: Select Format -->
                    <div class="gs-form-group gs-mb-lg">
                        <label for="import_format" class="gs-label gs-label-lg">
                            <span class="gs-badge gs-badge-primary gs-mr-sm">1</span>
                            Välj format
                        </label>
                        <select id="import_format" name="import_format" class="gs-input gs-input-lg" required>
                            <option value="">-- Välj format --</option>
                            <option value="enduro">Enduro (SS1, SS2, SS3...)</option>
                            <option value="dh">Downhill (Run 1, Run 2)</option>
                        </select>
                        <p class="gs-text-sm gs-text-secondary gs-mt-sm">
                            Välj rätt format baserat på din CSV-fils struktur.
                        </p>
                    </div>

                    <!-- Step 2: Select Event -->
                    <div class="gs-form-group gs-mb-lg">
                        <label for="event_id" class="gs-label gs-label-lg">
                            <span class="gs-badge gs-badge-primary gs-mr-sm">2</span>
                            Välj event
                        </label>
                        <select id="event_id" name="event_id" class="gs-input gs-input-lg" required>
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
                        <p class="gs-text-sm gs-text-secondary gs-mt-sm">
                            Alla resultat i filen kommer att importeras till det valda eventet.
                        </p>
                    </div>

                    <!-- Step 3: Select File -->
                    <div class="gs-form-group gs-mb-lg">
                        <label for="import_file" class="gs-label gs-label-lg">
                            <span class="gs-badge gs-badge-primary gs-mr-sm">3</span>
                            Välj CSV-fil
                        </label>
                        <input type="file"
                               id="import_file"
                               name="import_file"
                               class="gs-input gs-input-lg"
                               accept=".csv"
                               required>
                        <p class="gs-text-sm gs-text-secondary gs-mt-sm">
                            Max filstorlek: <?= MAX_UPLOAD_SIZE / 1024 / 1024 ?>MB. Stöder komma- och semikolon-separerade filer.
                        </p>
                    </div>

                    <!-- Step 4: Preview Button -->
                    <div class="gs-form-group">
                        <button type="submit" class="gs-btn gs-btn-primary gs-btn-lg gs-w-full">
                            <i data-lucide="eye"></i>
                            <span class="gs-badge gs-badge-light gs-mr-sm">4</span>
                            Förhandsgranska import
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- CSV Format Info -->
        <div class="gs-card gs-mt-lg">
            <div class="gs-card-header">
                <h3 class="gs-h5 gs-text-primary">
                    <i data-lucide="file-text"></i>
                    CSV Format
                </h3>
            </div>
            <div class="gs-card-content">
                <p class="gs-text-sm gs-mb-md"><strong>Obligatoriska kolumner (alla format):</strong></p>
                <code class="gs-code-block gs-mb-md">
Category, PlaceByCategory, FirstName, LastName, Club, NetTime, Status
                </code>

                <div class="gs-grid gs-grid-cols-1 gs-md-grid-cols-2 gs-gap-lg gs-mt-lg">
                    <!-- Enduro Format -->
                    <div class="gs-card gs-card-bordered">
                        <div class="gs-card-header gs-bg-primary-light">
                            <h4 class="gs-h6 gs-text-primary gs-m-0">
                                <i data-lucide="mountain"></i>
                                Enduro Format
                            </h4>
                        </div>
                        <div class="gs-card-content">
                            <p class="gs-text-sm gs-mb-sm"><strong>Specifika kolumner:</strong></p>
                            <code class="gs-code-block gs-text-xs">
UCI-ID, SS1, SS2, SS3... SS15
                            </code>
                            <p class="gs-text-xs gs-text-secondary gs-mt-sm">
                                Stages summeras till total tid
                            </p>
                        </div>
                    </div>

                    <!-- DH Format -->
                    <div class="gs-card gs-card-bordered">
                        <div class="gs-card-header gs-bg-warning-light">
                            <h4 class="gs-h6 gs-text-warning gs-m-0">
                                <i data-lucide="arrow-down"></i>
                                Downhill Format
                            </h4>
                        </div>
                        <div class="gs-card-content">
                            <p class="gs-text-sm gs-mb-sm"><strong>Specifika kolumner:</strong></p>
                            <code class="gs-code-block gs-text-xs">
UCI-ID, Run1, Run2
                            </code>
                            <p class="gs-text-xs gs-text-secondary gs-mt-sm">
                                Bästa tid av två åk vinner
                            </p>
                        </div>
                    </div>
                </div>

                <details class="gs-details">
                    <summary class="gs-text-sm gs-text-primary">
                        Visa exempel CSV
                    </summary>
                    <pre class="gs-code-dark gs-mt-md">
Category,PlaceByCategory,FirstName,LastName,Club,UCI-ID,NetTime,Status,SS1,SS2,SS3
Damer Junior,1,Ella,MÅRTENSSON,Borås CA,10022510347,16:19.16,FIN,2:10.55,1:47.08,1:51.10
Herrar Elite,1,Johan,ANDERSSON,Stockholm CK,10011223344,14:16.42,FIN,1:58.22,1:38.55,1:42.33
Herrar Elite,2,Erik,SVENSSON,Göteborg MTB,,DNF,DNF,1:55.34,1:39.21,DNF</pre>
                </details>

                <div class="gs-alert gs-alert-info gs-mt-md">
                    <i data-lucide="info"></i>
                    <strong>Tips:</strong> Systemet stödjer också svenska kolumnnamn som Klass, Placering, Förnamn, Efternamn, Klubb, Tid.
                </div>
            </div>
        </div>
    </div>
</main>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>

<?php
/**
 * Check if a row appears to be a field mapping/description row
 * These rows contain field names like "class", "position", "club_name" instead of actual data
 */
function isFieldMappingRow($row) {
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
 * Import results from CSV file with event mapping
 */
function importResultsFromCSVWithMapping($filepath, $db, $importId, $eventMapping = [], $forceClassId = null) {
    $stats = [
        'total' => 0,
        'success' => 0,
        'updated' => 0,
        'skipped' => 0,
        'failed' => 0
    ];

    $matching_stats = [
        'events_found' => 0,
        'events_not_found' => 0,
        'events_created' => 0,
        'venues_created' => 0,
        'riders_found' => 0,
        'riders_not_found' => 0,
        'riders_created' => 0,
        'clubs_created' => 0,
        'categories_created' => 0,
        'classes_created' => 0
    ];

    $errors = [];

    // Set global event mapping for use in import
    global $IMPORT_EVENT_MAPPING;
    $IMPORT_EVENT_MAPPING = $eventMapping;

    if (($handle = fopen($filepath, 'r')) === false) {
        throw new Exception('Kunde inte öppna filen');
    }

    // Auto-detect delimiter (comma or semicolon)
    $firstLine = fgets($handle);
    rewind($handle);
    $delimiter = (substr_count($firstLine, ';') > substr_count($firstLine, ',')) ? ';' : ',';

    // Read header row (0 = unlimited line length)
    $header = fgetcsv($handle, 0, $delimiter);

    if (!$header) {
        fclose($handle);
        throw new Exception('Tom fil eller ogiltigt format');
    }

    // Normalize header - accept multiple variants
    $originalHeaders = $header;
    $header = array_map(function($col) {
        $col = mb_strtolower(trim($col), 'UTF-8');

        // Skip empty columns (give them unique names to avoid conflicts)
        if (empty($col)) {
            return 'empty_' . uniqid();
        }

        // Remove spaces, hyphens, underscores for comparison
        $col = str_replace([' ', '-', '_'], '', $col);

        // Map Swedish and English column names
        $mappings = [
            // Event fields
            'eventname' => 'event_name',
            'tävling' => 'event_name',
            'tavling' => 'event_name',
            'event' => 'event_name',
            'eventdate' => 'event_date',
            'tävlingsdatum' => 'event_date',
            'tavlingsdatum' => 'event_date',
            'datum' => 'event_date',
            'date' => 'event_date',
            'eventlocation' => 'event_location',
            'location' => 'event_location',
            'plats' => 'event_location',
            'ort' => 'event_location',

            // Rider fields
            'firstname' => 'firstname',
            'förnamn' => 'firstname',
            'fornamn' => 'firstname',
            'fname' => 'firstname',
            'first_name' => 'firstname',
            'lastname' => 'lastname',
            'efternamn' => 'lastname',
            'lname' => 'lastname',
            'surname' => 'lastname',
            'last_name' => 'lastname',

            // License fields
            'licensenumber' => 'license_number',
            'uciid' => 'license_number',
            'ucikod' => 'license_number',
            'sweid' => 'license_number',
            'licens' => 'license_number',
            'uci_id' => 'license_number',
            'swe_id' => 'license_number',

            // Club/Team
            'club' => 'club_name',
            'clubname' => 'club_name',
            'team' => 'club_name',
            'klubb' => 'club_name',
            'club_name' => 'club_name',
            'huvudförening' => 'club_name',
            'huvudforening' => 'club_name',

            // Category is the racing class
            'category' => 'class_name',
            'class' => 'class_name',
            'klass' => 'class_name',
            'classname' => 'class_name',

            // PWR is used in some exports but we ignore it
            'pwr' => 'pwr',

            // Position
            'position' => 'position',
            'placering' => 'position',
            'placebycategory' => 'position',
            'place' => 'position',

            // Time fields
            'time' => 'finish_time',
            'tid' => 'finish_time',
            'finishtime' => 'finish_time',
            'nettime' => 'finish_time',
            'nettid' => 'finish_time',
            'finish_time' => 'finish_time',
            'totaltid' => 'finish_time',
            'totaltime' => 'finish_time',

            // Status
            'status' => 'status',

            // Gender
            'gender' => 'gender',
            'kön' => 'gender',
            'kon' => 'gender',

            // Birth year
            'birthyear' => 'birth_year',
            'födelseår' => 'birth_year',
            'fodelsear' => 'birth_year',

            // Stage times
            'ss1' => 'ss1', 'ss2' => 'ss2', 'ss3' => 'ss3', 'ss4' => 'ss4',
            'ss5' => 'ss5', 'ss6' => 'ss6', 'ss7' => 'ss7', 'ss8' => 'ss8',
            'ss9' => 'ss9', 'ss10' => 'ss10',

            // DH run times
            'run1time' => 'run_1_time',
            'run_1_time' => 'run_1_time',
            'run1' => 'run_1_time',
            'åk1' => 'run_1_time',
            'ak1' => 'run_1_time',
            'run2time' => 'run_2_time',
            'run_2_time' => 'run_2_time',
            'run2' => 'run_2_time',
            'åk2' => 'run_2_time',
            'ak2' => 'run_2_time',

            // DH split times for run 1 (stored in ss1-ss4)
            'run1split1' => 'ss1',
            'run1split2' => 'ss2',
            'run1split3' => 'ss3',
            'run1split4' => 'ss4',
            'split11' => 'ss1',
            'split12' => 'ss2',
            'split13' => 'ss3',
            'split14' => 'ss4',

            // DH split times for run 2 (stored in ss5-ss8)
            'run2split1' => 'ss5',
            'run2split2' => 'ss6',
            'run2split3' => 'ss7',
            'run2split4' => 'ss8',
            'split21' => 'ss5',
            'split22' => 'ss6',
            'split23' => 'ss7',
            'split24' => 'ss8',
        ];

        return $mappings[$col] ?? $col;
    }, $header);

    // Cache for lookups
    $eventCache = [];
    $riderCache = [];
    $categoryCache = [];
    $clubCache = [];
    $classCache = [];

    $lineNumber = 1;

    while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
        $lineNumber++;

        // Skip empty rows (all columns empty or only whitespace)
        if (empty(array_filter($row, function($val) { return !empty(trim($val)); }))) {
            continue;
        }

        // Skip field mapping/description rows (contain field names like "class", "position", etc.)
        if (isFieldMappingRow($row)) {
            continue;
        }

        $stats['total']++;

        // Ensure row has same number of columns as header
        if (count($row) < count($header)) {
            $row = array_pad($row, count($header), '');
        } elseif (count($row) > count($header)) {
            $row = array_slice($row, 0, count($header));
        }

        // Map row to associative array
        $data = array_combine($header, $row);

        // Validate required fields
        $hasEventMapping = !empty($IMPORT_EVENT_MAPPING);

        if (empty($data['event_name']) && !$hasEventMapping) {
            $stats['skipped']++;
            $errors[] = "Rad {$lineNumber}: Saknar tävlingsnamn och ingen event vald";
            continue;
        }

        // If no event_name but we have a mapping, use the same key as preview
        if (empty($data['event_name']) && $hasEventMapping) {
            $data['event_name'] = 'Välj event för alla resultat';
        }

        if (empty($data['firstname']) || empty($data['lastname'])) {
            $stats['skipped']++;
            $errors[] = "Rad {$lineNumber}: Saknar namn på cyklist";
            continue;
        }

        try {
            // Find event - use mapping if available
            $eventKey = $data['event_name'];
            $eventId = null;

            if (isset($eventMapping[$eventKey])) {
                $eventId = $eventMapping[$eventKey];
            } else {
                // Try to find event by name
                if (!isset($eventCache[$eventKey])) {
                    $event = $db->getRow(
                        "SELECT id FROM events WHERE name LIKE ? ORDER BY date DESC LIMIT 1",
                        ['%' . $eventKey . '%']
                    );
                    $eventCache[$eventKey] = $event ? $event['id'] : null;
                }
                $eventId = $eventCache[$eventKey];
            }

            if (!$eventId) {
                $stats['skipped']++;
                $errors[] = "Rad {$lineNumber}: Event '{$eventKey}' hittades inte";
                continue;
            }

            // Find or create rider
            $riderName = trim($data['firstname']) . '|' . trim($data['lastname']);
            $licenseNumber = $data['license_number'] ?? '';

            if (!isset($riderCache[$riderName . '|' . $licenseNumber])) {
                // Try to find rider by license number first
                $rider = null;
                if (!empty($licenseNumber)) {
                    $rider = $db->getRow(
                        "SELECT id FROM riders WHERE license_number = ?",
                        [$licenseNumber]
                    );
                    if ($rider) {
                        $matching_stats['riders_found']++;
                    }
                }

                // Try by name if no license match
                if (!$rider) {
                    $rider = $db->getRow(
                        "SELECT id FROM riders WHERE firstname = ? AND lastname = ?",
                        [trim($data['firstname']), trim($data['lastname'])]
                    );
                    if ($rider) {
                        $matching_stats['riders_found']++;
                    }
                }

                // Create new rider if not found
                if (!$rider) {
                    $matching_stats['riders_not_found']++;
                    $matching_stats['riders_created']++;

                    // Determine gender from class name if available
                    $gender = 'unknown';
                    $className = $data['class_name'] ?? '';
                    if (preg_match('/(dam|women|female|flickor|girls)/i', $className)) {
                        $gender = 'female';
                    } elseif (preg_match('/(herr|men|male|pojkar|boys)/i', $className)) {
                        $gender = 'male';
                    }

                    $riderId = $db->insert('riders', [
                        'firstname' => trim($data['firstname']),
                        'lastname' => trim($data['lastname']),
                        'license_number' => $licenseNumber ?: null,
                        'gender' => $gender
                    ]);

                    // Track for rollback
                    if ($importId) {
                        trackImportRecord($db, $importId, 'rider', $riderId, 'created');
                    }

                    $riderCache[$riderName . '|' . $licenseNumber] = $riderId;
                } else {
                    $riderCache[$riderName . '|' . $licenseNumber] = $rider['id'];
                }
            }

            $riderId = $riderCache[$riderName . '|' . $licenseNumber];

            // Find or create club
            $clubId = null;
            $clubName = trim($data['club_name'] ?? '');
            if (!empty($clubName)) {
                if (!isset($clubCache[$clubName])) {
                    $club = $db->getRow(
                        "SELECT id FROM clubs WHERE name LIKE ?",
                        ['%' . $clubName . '%']
                    );
                    if (!$club) {
                        // Create club
                        $matching_stats['clubs_created']++;
                        $newClubId = $db->insert('clubs', [
                            'name' => $clubName,
                            'active' => 1
                        ]);
                        if ($importId) {
                            trackImportRecord($db, $importId, 'club', $newClubId, 'created');
                        }
                        $clubCache[$clubName] = $newClubId;
                    } else {
                        $clubCache[$clubName] = $club['id'];
                    }
                }
                $clubId = $clubCache[$clubName];
            }

            // Find or create class
            $classId = $forceClassId;
            $className = trim($data['class_name'] ?? '');
            if (!$classId && !empty($className)) {
                if (!isset($classCache[$className])) {
                    $class = $db->getRow(
                        "SELECT id FROM classes WHERE display_name = ? OR name = ?",
                        [$className, $className]
                    );
                    if (!$class) {
                        // Create class
                        $matching_stats['classes_created']++;
                        $newClassId = $db->insert('classes', [
                            'name' => strtolower(str_replace(' ', '_', $className)),
                            'display_name' => $className,
                            'active' => 1
                        ]);
                        $classCache[$className] = $newClassId;
                    } else {
                        $classCache[$className] = $class['id'];
                    }
                }
                $classId = $classCache[$className];
            }

            // Parse time
            $finishTime = null;
            $timeStr = trim($data['finish_time'] ?? '');
            if (!empty($timeStr) && $timeStr !== 'DNF' && $timeStr !== 'DNS' && $timeStr !== 'DQ') {
                $finishTime = $timeStr;
            }

            // Determine status
            $status = strtolower(trim($data['status'] ?? 'finished'));
            if (in_array($status, ['fin', 'finished', 'ok', ''])) {
                $status = 'finished';
            } elseif (in_array($status, ['dnf', 'did not finish'])) {
                $status = 'dnf';
            } elseif (in_array($status, ['dns', 'did not start'])) {
                $status = 'dns';
            } elseif (in_array($status, ['dq', 'disqualified'])) {
                $status = 'dq';
            }

            // Check if result already exists
            $existingResult = $db->getRow(
                "SELECT id FROM results WHERE event_id = ? AND cyclist_id = ?",
                [$eventId, $riderId]
            );

            $resultData = [
                'event_id' => $eventId,
                'cyclist_id' => $riderId,
                'class_id' => $classId,
                'position' => !empty($data['position']) ? (int)$data['position'] : null,
                'finish_time' => $finishTime,
                'status' => $status,
                'run_1_time' => $data['run_1_time'] ?? null,
                'run_2_time' => $data['run_2_time'] ?? null,
                'ss1' => $data['ss1'] ?? null,
                'ss2' => $data['ss2'] ?? null,
                'ss3' => $data['ss3'] ?? null,
                'ss4' => $data['ss4'] ?? null,
                'ss5' => $data['ss5'] ?? null,
                'ss6' => $data['ss6'] ?? null,
                'ss7' => $data['ss7'] ?? null,
                'ss8' => $data['ss8'] ?? null,
                'ss9' => $data['ss9'] ?? null,
                'ss10' => $data['ss10'] ?? null,
            ];

            if ($existingResult) {
                // Update existing
                $oldData = $db->getRow("SELECT * FROM results WHERE id = ?", [$existingResult['id']]);
                $db->update('results', $resultData, 'id = ?', [$existingResult['id']]);
                $stats['updated']++;
                if ($importId) {
                    trackImportRecord($db, $importId, 'result', $existingResult['id'], 'updated', $oldData);
                }
            } else {
                // Insert new
                $resultId = $db->insert('results', $resultData);
                $stats['success']++;
                if ($importId) {
                    trackImportRecord($db, $importId, 'result', $resultId, 'created');
                }
            }

        } catch (Exception $e) {
            $stats['failed']++;
            $errors[] = "Rad {$lineNumber}: " . $e->getMessage();
        }
    }

    fclose($handle);

    return [
        'stats' => $stats,
        'matching' => $matching_stats,
        'errors' => $errors
    ];
}
?>

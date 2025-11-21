<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/import-history.php';
require_once __DIR__ . '/../includes/class-calculations.php';
require_admin();

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
    'classes' => []
];

try {
    $result = parseAndAnalyzeCSV($_SESSION['import_preview_file'], $db);
    $previewData = $result['data'];
    $matchingStats = $result['stats'];
} catch (Exception $e) {
    $message = 'Parsning misslyckades: ' . $e->getMessage();
    $messageType = 'error';
}

// Handle confirmed import
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_import'])) {
    checkCsrf();

    try {
        require_once __DIR__ . '/import-results.php';

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

        // Update import history
        $importStatus = ($stats['success'] > 0) ? 'completed' : 'failed';
        updateImportHistory($db, $importId, $stats, $errors, $importStatus);

        // Clean up
        @unlink($_SESSION['import_preview_file']);
        unset($_SESSION['import_preview_file']);
        unset($_SESSION['import_preview_filename']);
        unset($_SESSION['import_selected_event']);

        // Redirect to event page with success message
        setFlash("Import klar! {$stats['success']} nya, {$stats['updated']} uppdaterade av {$stats['total']} resultat.", 'success');
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
 * These rows contain field names like "class", "position", "club_name" instead of actual data
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
        'classes' => []
    ];

    $riderCache = [];
    $clubCache = [];

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

        // Skip field mapping/description rows (contain field names like "class", "position", etc.)
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

        // Check rider matching
        $firstName = trim($rowData['firstname'] ?? '');
        $lastName = trim($rowData['lastname'] ?? '');
        $licenseNumber = trim($rowData['license_number'] ?? '');

        if (!empty($firstName) && !empty($lastName)) {
            $riderKey = $firstName . '|' . $lastName . '|' . $licenseNumber;

            if (!isset($riderCache[$riderKey])) {
                $rider = null;

                // Try license first
                if (!empty($licenseNumber)) {
                    $rider = $db->getRow(
                        "SELECT id FROM riders WHERE license_number = ?",
                        [$licenseNumber]
                    );
                }

                // Try name
                if (!$rider) {
                    $rider = $db->getRow(
                        "SELECT id FROM riders WHERE firstname = ? AND lastname = ?",
                        [$firstName, $lastName]
                    );
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

<main class="gs-content-with-sidebar">
    <div class="gs-container">
        <!-- Header -->
        <div class="gs-flex gs-items-center gs-justify-between gs-mb-xl">
            <h1 class="gs-h1 gs-text-primary">
                <i data-lucide="eye"></i>
                Förhandsgranska import
            </h1>
            <a href="?cancel=1" class="gs-btn gs-btn-outline">
                <i data-lucide="x"></i>
                Avbryt
            </a>
        </div>

        <!-- Messages -->
        <?php if ($message): ?>
            <div class="gs-alert gs-alert-<?= $messageType ?> gs-mb-lg">
                <i data-lucide="<?= $messageType === 'success' ? 'check-circle' : 'alert-circle' ?>"></i>
                <?= h($message) ?>
            </div>
        <?php endif; ?>

        <!-- Event Info -->
        <div class="gs-card gs-mb-lg">
            <div class="gs-card-content">
                <div class="gs-flex gs-items-center gs-gap-md">
                    <i data-lucide="calendar" class="gs-icon-lg gs-text-primary"></i>
                    <div>
                        <h3 class="gs-h4 gs-m-0"><?= h($selectedEvent['name']) ?></h3>
                        <p class="gs-text-secondary gs-m-0">
                            <?= date('Y-m-d', strtotime($selectedEvent['date'])) ?>
                            <?php if ($selectedEvent['location']): ?>
                                - <?= h($selectedEvent['location']) ?>
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="gs-grid gs-grid-cols-2 gs-md-grid-cols-4 gs-gap-lg gs-mb-lg">
            <div class="gs-stat-card">
                <i data-lucide="file-text" class="gs-icon-lg gs-text-primary gs-mb-md"></i>
                <div class="gs-stat-number"><?= $matchingStats['total_rows'] ?></div>
                <div class="gs-stat-label">Rader i fil</div>
            </div>
            <div class="gs-stat-card">
                <i data-lucide="user-check" class="gs-icon-lg gs-text-success gs-mb-md"></i>
                <div class="gs-stat-number"><?= $matchingStats['riders_existing'] ?></div>
                <div class="gs-stat-label">Befintliga deltagare</div>
            </div>
            <div class="gs-stat-card">
                <i data-lucide="user-plus" class="gs-icon-lg gs-text-warning gs-mb-md"></i>
                <div class="gs-stat-number"><?= $matchingStats['riders_new'] ?></div>
                <div class="gs-stat-label">Nya deltagare</div>
            </div>
            <div class="gs-stat-card">
                <i data-lucide="building" class="gs-icon-lg gs-text-accent gs-mb-md"></i>
                <div class="gs-stat-number"><?= count($matchingStats['clubs_list']) ?></div>
                <div class="gs-stat-label">Klubbar</div>
            </div>
        </div>

        <!-- Matching Details -->
        <div class="gs-grid gs-grid-cols-1 gs-md-grid-cols-2 gs-gap-lg gs-mb-lg">
            <!-- Clubs -->
            <div class="gs-card">
                <div class="gs-card-header">
                    <h3 class="gs-h5 gs-text-primary">
                        <i data-lucide="building"></i>
                        Klubbar
                    </h3>
                </div>
                <div class="gs-card-content">
                    <?php if ($matchingStats['clubs_new'] > 0): ?>
                        <div class="gs-alert gs-alert-warning gs-mb-md">
                            <i data-lucide="info"></i>
                            <?= $matchingStats['clubs_new'] ?> nya klubbar kommer att skapas
                        </div>
                    <?php endif; ?>
                    <div class="gs-flex gs-flex-wrap gs-gap-sm">
                        <?php foreach ($matchingStats['clubs_list'] as $club): ?>
                            <span class="gs-badge gs-badge-secondary"><?= h($club) ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Classes -->
            <div class="gs-card">
                <div class="gs-card-header">
                    <h3 class="gs-h5 gs-text-primary">
                        <i data-lucide="tag"></i>
                        Klasser
                    </h3>
                </div>
                <div class="gs-card-content">
                    <div class="gs-flex gs-flex-wrap gs-gap-sm">
                        <?php foreach ($matchingStats['classes'] as $class): ?>
                            <span class="gs-badge gs-badge-primary"><?= h($class) ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Data Preview -->
        <div class="gs-card gs-mb-lg">
            <div class="gs-card-header">
                <h3 class="gs-h5 gs-text-primary">
                    <i data-lucide="table"></i>
                    Data preview (första 20 rader)
                </h3>
            </div>
            <div class="gs-card-content gs-padding-0">
                <?php
                // Get columns to display
                $sampleRow = reset($previewData);
                $displayColumns = ['category', 'position', 'firstname', 'lastname', 'club_name', 'finish_time', 'status'];
                $displayColumns = array_filter($displayColumns, function($col) use ($sampleRow) {
                    return isset($sampleRow[$col]);
                });
                ?>
                <div class="gs-table-responsive" style="max-height: 400px; overflow: auto;">
                    <table class="gs-table gs-table-sm">
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
                                <td class="gs-text-secondary"><?= $rowNum ?></td>
                                <?php foreach ($displayColumns as $col): ?>
                                    <td>
                                        <?php
                                        $value = $row[$col] ?? '';
                                        if ($col === 'category' && !empty($value)) {
                                            echo '<span class="gs-badge gs-badge-sm gs-badge-primary">' . h($value) . '</span>';
                                        } elseif ($col === 'position' && !empty($value) && $value <= 3) {
                                            echo '<strong class="gs-text-success">' . h($value) . '</strong>';
                                        } elseif ($col === 'status' && !empty($value)) {
                                            $statusClass = in_array(strtoupper($value), ['FIN', 'FINISHED', 'OK']) ? 'gs-badge-success' : 'gs-badge-warning';
                                            echo '<span class="gs-badge gs-badge-sm ' . $statusClass . '">' . h(strtoupper($value)) . '</span>';
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
                    <div class="gs-padding-md gs-text-center gs-text-secondary gs-text-sm">
                        Visar 20 av <?= count($previewData) ?> rader
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Import Button -->
        <form method="POST">
            <?= csrf_field() ?>
            <div class="gs-flex gs-gap-md gs-justify-end">
                <a href="?cancel=1" class="gs-btn gs-btn-outline gs-btn-lg">
                    <i data-lucide="x"></i>
                    Avbryt
                </a>
                <button type="submit" name="confirm_import" class="gs-btn gs-btn-success gs-btn-lg">
                    <i data-lucide="check"></i>
                    Importera <?= $matchingStats['total_rows'] ?> resultat
                </button>
            </div>
        </form>
    </div>
</main>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>

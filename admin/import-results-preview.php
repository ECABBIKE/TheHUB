<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/import-history.php';
require_once __DIR__ . '/../includes/class-calculations.php';
require_admin();

$db = getDB();
$current_admin = get_current_admin();

$message = '';
$messageType = 'info';
$previewData = [];
$eventsSummary = [];

// Check if we have a file to preview
if (!isset($_SESSION['import_preview_file']) || !file_exists($_SESSION['import_preview_file'])) {
    header('Location: /admin/import-results.php');
    exit;
}

// Load existing events for dropdown
$existingEvents = $db->getAll("
    SELECT id, name, date, advent_id
    FROM events
    ORDER BY date DESC
    LIMIT 200
");

// Parse CSV if not already done
if (!isset($_SESSION['import_preview_data'])) {
    try {
        $previewData = parseResultsCSVForPreview($_SESSION['import_preview_file']);
        $eventsSummary = groupResultsByEvent($previewData);

        $_SESSION['import_preview_data'] = $previewData;
        $_SESSION['import_events_summary'] = $eventsSummary;
    } catch (Exception $e) {
        $message = 'Parsning misslyckades: ' . $e->getMessage();
        $messageType = 'error';
    }
} else {
    $previewData = $_SESSION['import_preview_data'];
    $eventsSummary = $_SESSION['import_events_summary'];
}

// Handle confirmed import
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_import'])) {
    checkCsrf();

    // Get event mapping from form
    $eventMapping = $_POST['event_mapping'] ?? [];

    try {
        require_once __DIR__ . '/import-results.php';

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
            $eventMapping
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
        unset($_SESSION['import_preview_data']);
        unset($_SESSION['import_events_summary']);

        // Redirect to results page with success message
        $_SESSION['import_message'] = "Import klar! {$stats['success']} av {$stats['total']} resultat importerade.";
        $_SESSION['import_stats'] = $stats;
        $_SESSION['import_matching'] = $matching_stats;
        header('Location: /admin/results.php');
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
    unset($_SESSION['import_preview_data']);
    unset($_SESSION['import_events_summary']);
    header('Location: /admin/import-results.php');
    exit;
}

/**
 * Parse CSV for preview
 */
function parseResultsCSVForPreview($filepath) {
    $results = [];

    if (($handle = fopen($filepath, 'r')) === false) {
        throw new Exception('Kunde inte öppna filen');
    }

    // Auto-detect delimiter
    $firstLine = fgets($handle);
    rewind($handle);
    $delimiter = (substr_count($firstLine, ';') > substr_count($firstLine, ',')) ? ';' : ',';

    // Read header
    $header = fgetcsv($handle, 1000, $delimiter);
    if (!$header) {
        fclose($handle);
        throw new Exception('Tom fil eller ogiltigt format');
    }

    // Normalize header
    $header = array_map(function($col) {
        $col = strtolower(trim(str_replace([' ', '-', '_'], '', $col)));

        $mappings = [
            'eventname' => 'event_name',
            'tävling' => 'event_name',
            'tavling' => 'event_name',
            'event' => 'event_name',
            'eventdate' => 'event_date',
            'date' => 'event_date',
            'datum' => 'event_date',
            'firstname' => 'firstname',
            'first_name' => 'firstname',
            'förnamn' => 'firstname',
            'lastname' => 'lastname',
            'last_name' => 'lastname',
            'efternamn' => 'lastname',
            'category' => 'category',
            'class' => 'category',
            'klass' => 'category',
            'club' => 'club_name',
            'clubname' => 'club_name',
            'club_name' => 'club_name',
            'team' => 'club_name',
            'position' => 'position',
            'placering' => 'position',
            'time' => 'finish_time',
            'tid' => 'finish_time',
            'finish_time' => 'finish_time',
            'status' => 'status',
        ];

        return $mappings[$col] ?? $col;
    }, $header);

    // Read all rows
    $lineNumber = 1;
    while (($row = fgetcsv($handle, 1000, $delimiter)) !== false) {
        $lineNumber++;
        if (count($row) < 2) continue; // Skip empty rows

        $data = array_combine($header, $row);
        $results[] = $data;
    }

    fclose($handle);
    return $results;
}

/**
 * Group results by event
 */
function groupResultsByEvent($results) {
    global $db;
    $events = [];

    foreach ($results as $result) {
        $eventKey = $result['event_name'] ?? 'Unknown Event';

        if (!isset($events[$eventKey])) {
            $events[$eventKey] = [
                'name' => $eventKey,
                'date' => $result['event_date'] ?? '',
                'participant_count' => 0,
                'categories' => [],
                'clubs' => [],
                'riders' => [],
                'riders_data' => [] // For class distribution
            ];
        }

        $events[$eventKey]['participant_count']++;

        // Track categories
        if (!empty($result['category']) && !in_array($result['category'], $events[$eventKey]['categories'])) {
            $events[$eventKey]['categories'][] = $result['category'];
        }

        // Track clubs
        if (!empty($result['club_name']) && !in_array($result['club_name'], $events[$eventKey]['clubs'])) {
            $events[$eventKey]['clubs'][] = $result['club_name'];
        }

        // Track riders with full data for class calculation
        $riderName = ($result['firstname'] ?? '') . ' ' . ($result['lastname'] ?? '');
        $events[$eventKey]['riders'][] = trim($riderName);

        // Store rider data for class distribution
        if (!empty($result['firstname']) && !empty($result['lastname'])) {
            $events[$eventKey]['riders_data'][] = [
                'firstname' => $result['firstname'],
                'lastname' => $result['lastname'],
                'birth_year' => $result['birth_year'] ?? null,
                'gender' => isset($result['gender']) ? strtoupper($result['gender']) : null
            ];
        }
    }

    // Calculate class distribution for each event
    foreach ($events as $eventKey => &$eventData) {
        $eventDate = $eventData['date'] ?: date('Y-m-d');
        $eventData['class_distribution'] = getClassDistributionPreview(
            $eventData['riders_data'],
            $eventDate,
            'ROAD',
            $db
        );
    }

    return $events;
}

$pageTitle = 'Förhandsgranska import';
$pageType = 'admin';
include __DIR__ . '/../includes/layout-header.php';
?>

<main class="gs-main-content">
    <div class="gs-container">
        <div class="gs-flex gs-justify-between gs-items-center gs-mb-lg">
            <h1 class="gs-h2">
                <i data-lucide="eye"></i>
                Förhandsgranska import
            </h1>
            <a href="?cancel=1" class="gs-btn gs-btn-outline">
                <i data-lucide="x"></i>
                Avbryt
            </a>
        </div>

        <?php if ($message): ?>
            <div class="gs-alert gs-alert-<?= h($messageType) ?> gs-mb-lg">
                <i data-lucide="<?= $messageType === 'success' ? 'check-circle' : 'alert-circle' ?>"></i>
                <?= h($message) ?>
            </div>
        <?php endif; ?>

        <div class="gs-alert gs-alert-info gs-mb-lg">
            <i data-lucide="info"></i>
            <strong>Fil:</strong> <?= h($_SESSION['import_preview_filename'] ?? 'Okänd') ?><br>
            <strong>Antal rader:</strong> <?= count($previewData) ?><br>
            <strong>Event i filen:</strong> <?= count($eventsSummary) ?>
        </div>

        <?php if (empty($eventsSummary)): ?>
            <div class="gs-card">
                <div class="gs-card-content">
                    <p class="gs-text-secondary">Ingen data att förhandsgranska</p>
                </div>
            </div>
        <?php else: ?>
            <form method="POST">
                <?= csrf_field() ?>

                <?php foreach ($eventsSummary as $eventKey => $eventData): ?>
                    <div class="gs-card gs-mb-lg">
                        <div class="gs-card-header">
                            <h3 class="gs-h4 gs-text-primary">
                                <i data-lucide="calendar"></i>
                                <?= h($eventData['name']) ?>
                                <?php if ($eventData['date']): ?>
                                    <span class="gs-badge gs-badge-secondary gs-ml-sm">
                                        <?= h($eventData['date']) ?>
                                    </span>
                                <?php endif; ?>
                            </h3>
                        </div>
                        <div class="gs-card-content">
                            <div class="gs-grid gs-grid-cols-1 gs-md-grid-cols-3 gs-gap-md gs-mb-lg">
                                <div>
                                    <div class="gs-text-sm gs-text-secondary">Deltagare</div>
                                    <div class="gs-h3"><?= $eventData['participant_count'] ?></div>
                                </div>
                                <div>
                                    <div class="gs-text-sm gs-text-secondary">Kategorier</div>
                                    <div class="gs-h3"><?= count($eventData['categories']) ?></div>
                                </div>
                                <div>
                                    <div class="gs-text-sm gs-text-secondary">Klubbar</div>
                                    <div class="gs-h3"><?= count($eventData['clubs']) ?></div>
                                </div>
                            </div>

                            <!-- Event Mapping -->
                            <div class="gs-form-group">
                                <label class="gs-label">
                                    <i data-lucide="link"></i>
                                    Koppla till event
                                </label>
                                <select name="event_mapping[<?= h($eventKey) ?>]" class="gs-input">
                                    <option value="create">✨ Skapa nytt event</option>
                                    <optgroup label="Befintliga event">
                                        <?php foreach ($existingEvents as $event): ?>
                                            <?php
                                            $selected = '';
                                            // Auto-select if names match
                                            if (stripos($event['name'], $eventData['name']) !== false ||
                                                stripos($eventData['name'], $event['name']) !== false) {
                                                $selected = 'selected';
                                            }
                                            ?>
                                            <option value="<?= $event['id'] ?>" <?= $selected ?>>
                                                <?= h($event['name']) ?> (<?= date('Y-m-d', strtotime($event['date'])) ?>)
                                                <?= $event['advent_id'] ? '- ' . h($event['advent_id']) : '' ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                </select>
                                <small class="gs-text-sm gs-text-secondary">
                                    Välj ett befintligt event eller skapa ett nytt
                                </small>
                            </div>

                            <!-- Categories -->
                            <?php if (!empty($eventData['categories'])): ?>
                                <div class="gs-mb-sm">
                                    <strong class="gs-text-sm">Kategorier:</strong>
                                    <div class="gs-flex gs-flex-wrap gs-gap-xs gs-mt-xs">
                                        <?php foreach ($eventData['categories'] as $cat): ?>
                                            <span class="gs-badge gs-badge-primary gs-badge-sm"><?= h($cat) ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- Clubs -->
                            <?php if (!empty($eventData['clubs'])): ?>
                                <div>
                                    <strong class="gs-text-sm">Klubbar:</strong>
                                    <div class="gs-flex gs-flex-wrap gs-gap-xs gs-mt-xs">
                                        <?php foreach (array_slice($eventData['clubs'], 0, 10) as $club): ?>
                                            <span class="gs-badge gs-badge-secondary gs-badge-sm"><?= h($club) ?></span>
                                        <?php endforeach; ?>
                                        <?php if (count($eventData['clubs']) > 10): ?>
                                            <span class="gs-badge gs-badge-secondary gs-badge-sm">
                                                +<?= count($eventData['clubs']) - 10 ?> fler
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- Class Distribution -->
                            <?php if (!empty($eventData['class_distribution'])): ?>
                                <?php $classDist = $eventData['class_distribution']; ?>
                                <div class="gs-mt-lg" style="padding-top: 1rem; border-top: 1px solid var(--gs-border);">
                                    <strong class="gs-text-sm">
                                        <i data-lucide="users" style="width: 14px; height: 14px;"></i>
                                        Klassfördelning (förhandsvisning)
                                    </strong>
                                    <?php if (!empty($classDist['distribution'])): ?>
                                        <div class="gs-grid gs-grid-cols-2 gs-md-grid-cols-4 gs-gap-sm gs-mt-sm">
                                            <?php foreach ($classDist['distribution'] as $classData): ?>
                                                <div class="gs-card" style="background: var(--gs-background-secondary); padding: 0.5rem;">
                                                    <div class="gs-text-xs gs-text-secondary"><?= h($classData['class_name']) ?></div>
                                                    <div class="gs-h4 gs-text-primary"><?= $classData['count'] ?></div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($classDist['unassigned'] > 0): ?>
                                        <div class="gs-alert gs-alert-warning gs-mt-sm" style="padding: 0.5rem;">
                                            <small>
                                                <i data-lucide="alert-triangle" style="width: 14px; height: 14px;"></i>
                                                <?= $classDist['unassigned'] ?> deltagare saknar ålder/kön och kan inte tilldelas klass
                                            </small>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>

                <div class="gs-flex gs-justify-end gs-gap-md gs-mt-xl">
                    <a href="?cancel=1" class="gs-btn gs-btn-outline">
                        <i data-lucide="x"></i>
                        Avbryt
                    </a>
                    <button type="submit" name="confirm_import" class="gs-btn gs-btn-primary gs-btn-lg">
                        <i data-lucide="check"></i>
                        Godkänn & Importera
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</main>

<script src="https://unpkg.com/lucide@latest"></script>
<script>
    lucide.createIcons();
</script>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>

<?php
require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();
$current_admin = get_current_admin();

$message = '';
$messageType = 'info';
$previewData = [];
$eventsSummary = [];

// Handle CSV upload for preview
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['import_file']) && !isset($_POST['confirm_import'])) {
    checkCsrf();

    $file = $_FILES['import_file'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $message = 'Filuppladdning misslyckades';
        $messageType = 'error';
    } elseif ($file['size'] > MAX_UPLOAD_SIZE) {
        $message = 'Filen är för stor (max ' . (MAX_UPLOAD_SIZE / 1024 / 1024) . 'MB)';
        $messageType = 'error';
    } else {
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if ($extension !== 'csv') {
            $message = 'Endast CSV-filer stöds';
            $messageType = 'error';
        } else {
            $uploaded = UPLOADS_PATH . '/' . time() . '_preview_' . basename($file['name']);

            if (move_uploaded_file($file['tmp_name'], $uploaded)) {
                try {
                    // Parse CSV for preview
                    $previewData = parseResultsCSVForPreview($uploaded);
                    $eventsSummary = groupResultsByEvent($previewData);

                    // Store file path in session for later import
                    $_SESSION['preview_file'] = $uploaded;
                    $_SESSION['preview_data'] = $previewData;
                    $_SESSION['events_summary'] = $eventsSummary;

                } catch (Exception $e) {
                    $message = 'Parsning misslyckades: ' . $e->getMessage();
                    $messageType = 'error';
                    @unlink($uploaded);
                }
            } else {
                $message = 'Kunde inte ladda upp filen';
                $messageType = 'error';
            }
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_import'])) {
    // Handle confirmed import
    checkCsrf();

    if (isset($_SESSION['preview_file']) && file_exists($_SESSION['preview_file'])) {
        try {
            require_once __DIR__ . '/../includes/import-history.php';

            // Import the file
            $result = importResultsFromCSV($_SESSION['preview_file'], $db, null);

            $stats = $result['stats'];
            $errors = $result['errors'];

            if ($stats['success'] > 0) {
                $message = "Import klar! {$stats['success']} av {$stats['total']} resultat importerade.";
                $messageType = 'success';
            } else {
                $message = "Ingen data importerades.";
                $messageType = 'error';
            }

            // Clean up
            @unlink($_SESSION['preview_file']);
            unset($_SESSION['preview_file']);
            unset($_SESSION['preview_data']);
            unset($_SESSION['events_summary']);

        } catch (Exception $e) {
            $message = 'Import misslyckades: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
} elseif (isset($_SESSION['preview_data'])) {
    // Restore preview data from session
    $previewData = $_SESSION['preview_data'];
    $eventsSummary = $_SESSION['events_summary'];
}

/**
 * Parse CSV and extract preview data
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
    $header = array_map('strtolower', array_map('trim', $header));

    $lineNumber = 1;
    while (($row = fgetcsv($handle, 1000, $delimiter)) !== false) {
        $lineNumber++;

        if (count($row) !== count($header)) {
            continue;
        }

        $data = array_combine($header, $row);

        $results[] = [
            'line' => $lineNumber,
            'event_name' => trim($data['event_name'] ?? $data['event'] ?? $data['tävling'] ?? ''),
            'event_date' => trim($data['event_date'] ?? $data['date'] ?? $data['datum'] ?? ''),
            'event_location' => trim($data['event_location'] ?? $data['location'] ?? $data['plats'] ?? ''),
            'firstname' => trim($data['firstname'] ?? $data['förnamn'] ?? ''),
            'lastname' => trim($data['lastname'] ?? $data['efternamn'] ?? ''),
            'position' => trim($data['position'] ?? $data['placering'] ?? ''),
            'class' => trim($data['class'] ?? $data['category'] ?? $data['klass'] ?? $data['kategori'] ?? ''),
            'finish_time' => trim($data['finish_time'] ?? $data['time'] ?? $data['tid'] ?? ''),
            'license_number' => trim($data['license_number'] ?? $data['licens'] ?? '')
        ];
    }

    fclose($handle);
    return $results;
}

/**
 * Group results by event for summary
 */
function groupResultsByEvent($results) {
    $events = [];

    foreach ($results as $result) {
        $eventKey = $result['event_name'] . '|' . $result['event_date'];

        if (!isset($events[$eventKey])) {
            $events[$eventKey] = [
                'name' => $result['event_name'],
                'date' => $result['event_date'],
                'location' => $result['event_location'],
                'participant_count' => 0,
                'classes' => []
            ];
        }

        $events[$eventKey]['participant_count']++;

        if (!empty($result['class']) && !in_array($result['class'], $events[$eventKey]['classes'])) {
            $events[$eventKey]['classes'][] = $result['class'];
        }
    }

    return $events;
}

$pageTitle = 'Förhandsgranska Resultatimport';
$pageType = 'admin';
include __DIR__ . '/../includes/layout-header.php';
?>

<main class="gs-main-content">
    <div class="gs-container">
        <!-- Header -->
        <div class="gs-flex gs-items-center gs-justify-between gs-mb-lg">
            <h1 class="gs-h1 gs-text-primary">
                <i data-lucide="eye"></i>
                Förhandsgranska Resultatimport
            </h1>
            <a href="/admin/import-results.php" class="gs-btn gs-btn-outline">
                <i data-lucide="arrow-left"></i>
                Tillbaka
            </a>
        </div>

        <!-- Messages -->
        <?php if ($message): ?>
            <div class="gs-alert gs-alert-<?= $messageType ?> gs-mb-lg">
                <i data-lucide="<?= $messageType === 'success' ? 'check-circle' : ($messageType === 'error' ? 'alert-circle' : 'info') ?>"></i>
                <?= $message ?>
            </div>
        <?php endif; ?>

        <?php if (empty($eventsSummary)): ?>
            <!-- Upload Form -->
            <div class="gs-card">
                <div class="gs-card-header">
                    <h2 class="gs-h4">
                        <i data-lucide="upload"></i>
                        Ladda upp CSV för förhandsgranskning
                    </h2>
                </div>
                <div class="gs-card-content">
                    <form method="POST" enctype="multipart/form-data">
                        <?= csrf_field() ?>

                        <div class="gs-form-group">
                            <label for="import_file" class="gs-label">
                                <i data-lucide="file"></i>
                                Välj CSV-fil
                            </label>
                            <input
                                type="file"
                                id="import_file"
                                name="import_file"
                                class="gs-input"
                                accept=".csv"
                                required
                            >
                            <small class="gs-text-secondary">
                                Maximalt <?= round(MAX_UPLOAD_SIZE / 1024 / 1024) ?>MB. Filen parsas och visas för granskning innan import.
                            </small>
                        </div>

                        <button type="submit" class="gs-btn gs-btn-primary">
                            <i data-lucide="eye"></i>
                            Förhandsgranska
                        </button>
                    </form>
                </div>
            </div>
        <?php else: ?>
            <!-- Event Summary -->
            <div class="gs-card gs-mb-lg">
                <div class="gs-card-header">
                    <h2 class="gs-h4">
                        <i data-lucide="bar-chart"></i>
                        Sammanställning av Importerade Event
                    </h2>
                </div>
                <div class="gs-card-content">
                    <div class="gs-alert gs-alert-info gs-mb-md">
                        <i data-lucide="info"></i>
                        Totalt <strong><?= count($eventsSummary) ?></strong> event med <strong><?= count($previewData) ?></strong> resultat
                    </div>

                    <div class="gs-table-responsive">
                        <table class="gs-table">
                            <thead>
                                <tr>
                                    <th>Tävling</th>
                                    <th>Datum</th>
                                    <th>Plats</th>
                                    <th>Antal Deltagare</th>
                                    <th>Klasser</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($eventsSummary as $event): ?>
                                    <tr>
                                        <td>
                                            <strong><?= h($event['name']) ?></strong>
                                        </td>
                                        <td><?= h($event['date']) ?></td>
                                        <td><?= h($event['location']) ?></td>
                                        <td class="gs-text-center">
                                            <span class="gs-badge gs-badge-primary">
                                                <?= $event['participant_count'] ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if (!empty($event['classes'])): ?>
                                                <?php foreach ($event['classes'] as $class): ?>
                                                    <span class="gs-badge gs-badge-secondary gs-badge-sm">
                                                        <?= h($class) ?>
                                                    </span>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <span class="gs-text-secondary">-</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Detailed Results Preview -->
            <div class="gs-card gs-mb-lg">
                <div class="gs-card-header">
                    <h2 class="gs-h4">
                        <i data-lucide="list"></i>
                        Detaljerade Resultat (Första 100)
                    </h2>
                </div>
                <div class="gs-card-content">
                    <div class="gs-table-responsive" style="max-height: 500px; overflow-y: auto;">
                        <table class="gs-table">
                            <thead style="position: sticky; top: 0; background: var(--gs-background); z-index: 10;">
                                <tr>
                                    <th style="width: 50px;">Rad</th>
                                    <th>Event</th>
                                    <th>Datum</th>
                                    <th>Förnamn</th>
                                    <th>Efternamn</th>
                                    <th>Klass</th>
                                    <th>Placering</th>
                                    <th>Tid</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (array_slice($previewData, 0, 100) as $result): ?>
                                    <tr>
                                        <td class="gs-text-secondary"><?= $result['line'] ?></td>
                                        <td><?= h($result['event_name']) ?></td>
                                        <td><?= h($result['event_date']) ?></td>
                                        <td><?= h($result['firstname']) ?></td>
                                        <td><?= h($result['lastname']) ?></td>
                                        <td><?= h($result['class']) ?></td>
                                        <td class="gs-text-center"><?= h($result['position']) ?></td>
                                        <td><?= h($result['finish_time']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if (count($previewData) > 100): ?>
                        <div class="gs-text-sm gs-text-secondary gs-mt-sm">
                            ... och <?= count($previewData) - 100 ?> fler resultat
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Confirm Import -->
            <div class="gs-card">
                <div class="gs-card-content">
                    <form method="POST">
                        <?= csrf_field() ?>
                        <input type="hidden" name="confirm_import" value="1">

                        <div class="gs-flex gs-items-center gs-justify-between">
                            <div>
                                <h3 class="gs-h5 gs-mb-sm">Bekräfta Import</h3>
                                <p class="gs-text-secondary">
                                    Detta kommer att importera <strong><?= count($previewData) ?></strong> resultat
                                    från <strong><?= count($eventsSummary) ?></strong> event till databasen.
                                </p>
                            </div>
                            <div class="gs-flex gs-gap-md">
                                <a href="/admin/import-results-preview.php" class="gs-btn gs-btn-outline" onclick="return confirm('Avbryta förhandsgranskningen?');">
                                    <i data-lucide="x"></i>
                                    Avbryt
                                </a>
                                <button type="submit" class="gs-btn gs-btn-success gs-btn-lg">
                                    <i data-lucide="check"></i>
                                    Bekräfta & Importera
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>
</main>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>

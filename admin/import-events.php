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
        $result = importEventsFromCSV($file['tmp_name'], $db);
        $stats = $result['stats'];
        $errors = $result['errors'];

        if ($stats['failed'] === 0) {
            $message = "Import klar! {$stats['success']} events importerade.";
            $messageType = 'success';
        } else {
            $message = "Import klar med fel. {$stats['success']} lyckades, {$stats['failed']} misslyckades.";
            $messageType = 'warning';
        }
    } else {
        $message = 'Fel vid uppladdning av fil.';
        $messageType = 'error';
    }
}

/**
 * Import events from CSV file
 */
function importEventsFromCSV($filePath, $db) {
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

    // Read header
    $header = fgetcsv($handle);
    if (!$header) {
        fclose($handle);
        return ['stats' => $stats, 'errors' => ['Ogiltig CSV-fil']];
    }

    // Normalize header
    $header = array_map(function($col) {
        return strtolower(trim($col));
    }, $header);

    $lineNumber = 1;

    while (($row = fgetcsv($handle)) !== false) {
        $lineNumber++;
        $stats['total']++;

        if (count($row) !== count($header)) {
            $stats['skipped']++;
            continue;
        }

        $data = array_combine($header, $row);

        try {
            // Required fields
            if (empty($data['name']) || empty($data['date'])) {
                $stats['skipped']++;
                continue;
            }

            // Prepare event data
            $eventData = [
                'name' => trim($data['name']),
                'date' => trim($data['date']),
                'location' => trim($data['location'] ?? ''),
                'type' => trim($data['type'] ?? 'competition'),
                'discipline' => trim($data['discipline'] ?? ''),
                'distance' => !empty($data['distance']) ? floatval($data['distance']) : null,
                'elevation_gain' => !empty($data['elevation_gain']) ? intval($data['elevation_gain']) : null,
                'status' => trim($data['status'] ?? 'upcoming'),
                'description' => trim($data['description'] ?? ''),
                'organizer' => trim($data['organizer'] ?? ''),
                'website' => trim($data['website'] ?? ''),
                'registration_url' => trim($data['registration_url'] ?? ''),
                'registration_deadline' => !empty($data['registration_deadline']) ? trim($data['registration_deadline']) : null,
                'max_participants' => !empty($data['max_participants']) ? intval($data['max_participants']) : null,
                'entry_fee' => !empty($data['entry_fee']) ? floatval($data['entry_fee']) : null,
                'active' => 1
            ];

            // Handle series (auto-create if name provided)
            if (!empty($data['series'])) {
                $seriesName = trim($data['series']);
                $series = $db->getRow("SELECT id FROM series WHERE name = ? LIMIT 1", [$seriesName]);

                if (!$series) {
                    // Auto-create series
                    $seriesId = $db->insert('series', [
                        'name' => $seriesName,
                        'type' => 'championship',
                        'discipline' => $eventData['discipline'],
                        'year' => date('Y', strtotime($eventData['date'])),
                        'status' => 'active',
                        'active' => 1
                    ]);
                    $eventData['series_id'] = $seriesId;
                    error_log("Auto-created series: {$seriesName} (ID: {$seriesId})");
                } else {
                    $eventData['series_id'] = $series['id'];
                }
            }

            // Handle venue (auto-create if name provided)
            if (!empty($data['venue'])) {
                $venueName = trim($data['venue']);
                $venue = $db->getRow("SELECT id FROM venues WHERE name = ? LIMIT 1", [$venueName]);

                if (!$venue) {
                    // Auto-create venue
                    $venueId = $db->insert('venues', [
                        'name' => $venueName,
                        'city' => $eventData['location'],
                        'country' => 'Sverige',
                        'active' => 1
                    ]);
                    $eventData['venue_id'] = $venueId;
                    error_log("Auto-created venue: {$venueName} (ID: {$venueId})");
                } else {
                    $eventData['venue_id'] = $venue['id'];
                }
            }

            // Check if event exists (by name and date)
            $existing = $db->getRow(
                "SELECT id FROM events WHERE name = ? AND date = ? LIMIT 1",
                [$eventData['name'], $eventData['date']]
            );

            if ($existing) {
                // Update existing event
                $db->update('events', $eventData, 'id = ?', [$existing['id']]);
                $stats['updated']++;
                error_log("Updated event: {$eventData['name']} on {$eventData['date']}");
            } else {
                // Insert new event
                $newId = $db->insert('events', $eventData);
                $stats['success']++;
                error_log("Inserted event: {$eventData['name']} on {$eventData['date']} (ID: {$newId})");
            }

        } catch (Exception $e) {
            $stats['failed']++;
            $errors[] = "Rad {$lineNumber}: " . $e->getMessage();
            error_log("Import error on line {$lineNumber}: " . $e->getMessage());
        }
    }

    fclose($handle);

    // Verification
    $verifyCount = $db->getRow("SELECT COUNT(*) as count FROM events");
    $totalInDb = $verifyCount['count'] ?? 0;
    error_log("Event import complete: {$stats['success']} new, {$stats['updated']} updated, {$stats['failed']} failed. Total events in DB: {$totalInDb}");

    $stats['total_in_db'] = $totalInDb;

    return [
        'stats' => $stats,
        'errors' => $errors
    ];
}

$pageTitle = 'Importera Events';
$pageType = 'admin';
include __DIR__ . '/../includes/layout-header.php';
?>

<main class="gs-content-with-sidebar">
    <div class="gs-container">
        <h1 class="gs-h2 gs-mb-lg">
            <i data-lucide="calendar"></i>
            Importera Events
        </h1>

        <div class="gs-breadcrumb gs-mb-lg">
            <a href="/admin/dashboard.php">Dashboard</a>
            <span>/</span>
            <a href="/admin/events.php">Events</a>
            <span>/</span>
            <span>Import</span>
        </div>

        <?php if ($message): ?>
            <div class="gs-alert gs-alert-<?= h($messageType) ?> gs-mb-lg">
                <i data-lucide="<?= $messageType === 'success' ? 'check-circle' : 'alert-circle' ?>"></i>
                <?= h($message) ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="gs-alert gs-alert-error gs-mb-lg">
                <strong>Fel under import:</strong>
                <ul class="gs-mt-sm">
                    <?php foreach ($errors as $error): ?>
                        <li><?= h($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($stats): ?>
            <div class="gs-card gs-mb-lg">
                <div class="gs-card-header">
                    <h2 class="gs-h3">Import-statistik</h2>
                </div>
                <div class="gs-card-content">
                    <div class="gs-grid gs-grid-cols-5 gs-gap-md">
                        <div class="gs-stat-card">
                            <div class="gs-stat-value"><?= $stats['total'] ?></div>
                            <div class="gs-stat-label">Totalt rader</div>
                        </div>
                        <div class="gs-stat-card">
                            <div class="gs-stat-value gs-text-success"><?= $stats['success'] ?></div>
                            <div class="gs-stat-label">Nya</div>
                        </div>
                        <div class="gs-stat-card">
                            <div class="gs-stat-value gs-text-info"><?= $stats['updated'] ?></div>
                            <div class="gs-stat-label">Uppdaterade</div>
                        </div>
                        <div class="gs-stat-card">
                            <div class="gs-stat-value gs-text-secondary"><?= $stats['skipped'] ?></div>
                            <div class="gs-stat-label">Överhoppade</div>
                        </div>
                        <div class="gs-stat-card">
                            <div class="gs-stat-value gs-text-danger"><?= $stats['failed'] ?></div>
                            <div class="gs-stat-label">Misslyckade</div>
                        </div>
                    </div>

                    <div class="gs-mt-lg">
                        <h3 class="gs-h4 gs-mb-sm">Verifiering</h3>
                        <p class="gs-text-lg"><strong>Totalt i databasen:</strong> <?= $stats['total_in_db'] ?> events</p>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Upload Form -->
        <div class="gs-card gs-mb-lg">
            <div class="gs-card-header">
                <h2 class="gs-h3">Bulk-import av events från CSV-fil</h2>
            </div>
            <div class="gs-card-content">
                <form method="POST" enctype="multipart/form-data" class="gs-form">
                    <?= csrfField() ?>

                    <div class="gs-alert gs-alert-info gs-mb-md">
                        <i data-lucide="info"></i>
                        <strong>CSV-format:</strong> Första raden ska innehålla kolumnnamn.
                        <br>
                        <strong>Auto-skapande:</strong> Om series eller venue inte finns skapas de automatiskt.
                    </div>

                    <div class="gs-form-group">
                        <label class="gs-label">
                            <i data-lucide="upload"></i>
                            Välj CSV-fil
                        </label>
                        <input
                            type="file"
                            name="csv_file"
                            accept=".csv"
                            required
                            class="gs-input"
                        >
                        <small class="gs-text-secondary">Max 10 MB. Format: CSV (komma-separerad)</small>
                    </div>

                    <div class="gs-flex gs-gap-md">
                        <button type="submit" class="gs-btn gs-btn-primary">
                            <i data-lucide="upload"></i>
                            Importera Events
                        </button>
                        <a href="/admin/events.php" class="gs-btn gs-btn-outline">
                            <i data-lucide="arrow-left"></i>
                            Tillbaka
                        </a>
                        <a href="/templates/import-events-template.csv" class="gs-btn gs-btn-outline" download>
                            <i data-lucide="download"></i>
                            Ladda ner mall
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- CSV Format Info -->
        <div class="gs-card">
            <div class="gs-card-header">
                <h2 class="gs-h3">CSV-kolumner</h2>
            </div>
            <div class="gs-card-content">
                <table class="gs-table">
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
                            <td>Event-namn</td>
                            <td>✅ Ja</td>
                            <td>Åre Bike Festival - Enduro</td>
                        </tr>
                        <tr>
                            <td><code>date</code></td>
                            <td>Datum (YYYY-MM-DD)</td>
                            <td>✅ Ja</td>
                            <td>2025-07-15</td>
                        </tr>
                        <tr>
                            <td><code>location</code></td>
                            <td>Plats/ort</td>
                            <td>Nej</td>
                            <td>Åre</td>
                        </tr>
                        <tr>
                            <td><code>venue</code></td>
                            <td>Anläggning (auto-skapas)</td>
                            <td>Nej</td>
                            <td>Åre Bike Park</td>
                        </tr>
                        <tr>
                            <td><code>type</code></td>
                            <td>Typ (competition/training)</td>
                            <td>Nej</td>
                            <td>competition</td>
                        </tr>
                        <tr>
                            <td><code>discipline</code></td>
                            <td>Disciplin</td>
                            <td>Nej</td>
                            <td>Enduro</td>
                        </tr>
                        <tr>
                            <td><code>series</code></td>
                            <td>Serie-namn (auto-skapas)</td>
                            <td>Nej</td>
                            <td>Enduro Series</td>
                        </tr>
                        <tr>
                            <td><code>distance</code></td>
                            <td>Distans (km)</td>
                            <td>Nej</td>
                            <td>25.5</td>
                        </tr>
                        <tr>
                            <td><code>elevation_gain</code></td>
                            <td>Höjdmeter</td>
                            <td>Nej</td>
                            <td>1200</td>
                        </tr>
                        <tr>
                            <td><code>status</code></td>
                            <td>Status (upcoming/ongoing/completed)</td>
                            <td>Nej</td>
                            <td>upcoming</td>
                        </tr>
                        <tr>
                            <td><code>organizer</code></td>
                            <td>Arrangör</td>
                            <td>Nej</td>
                            <td>Åre Bike Park</td>
                        </tr>
                        <tr>
                            <td><code>website</code></td>
                            <td>Hemsida</td>
                            <td>Nej</td>
                            <td>https://arebikefestival.se</td>
                        </tr>
                        <tr>
                            <td><code>entry_fee</code></td>
                            <td>Startavgift (SEK)</td>
                            <td>Nej</td>
                            <td>500</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>

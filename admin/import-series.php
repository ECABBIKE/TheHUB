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
        $result = importSeriesFromCSV($file['tmp_name'], $db);
        $stats = $result['stats'];
        $errors = $result['errors'];

        if ($stats['failed'] === 0) {
            $message = "Import klar! {$stats['success']} serier importerade.";
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
 * Import series from CSV file
 */
function importSeriesFromCSV($filePath, $db) {
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

    // Auto-detect delimiter (comma or semicolon)
    $firstLine = fgets($handle);
    rewind($handle);
    $delimiter = (substr_count($firstLine, ';') > substr_count($firstLine, ',')) ? ';' : ',';

    // Read header
    $header = fgetcsv($handle, 0, $delimiter);
    if (!$header) {
        fclose($handle);
        return ['stats' => $stats, 'errors' => ['Ogiltig CSV-fil']];
    }

    // Normalize header
    $header = array_map(function($col) {
        return strtolower(trim($col));
    }, $header);

    $lineNumber = 1;

    while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
        $lineNumber++;
        $stats['total']++;

        if (count($row) !== count($header)) {
            $stats['skipped']++;
            continue;
        }

        $data = array_combine($header, $row);

        try {
            // Required fields
            if (empty($data['name'])) {
                $stats['skipped']++;
                continue;
            }

            // Prepare series data
            $seriesData = [
                'name' => trim($data['name']),
                'type' => trim($data['type'] ?? 'championship'),
                'discipline' => trim($data['discipline'] ?? ''),
                'year' => !empty($data['year']) ? intval($data['year']) : date('Y'),
                'status' => trim($data['status'] ?? 'planning'),
                'start_date' => !empty($data['start_date']) ? trim($data['start_date']) : null,
                'end_date' => !empty($data['end_date']) ? trim($data['end_date']) : null,
                'description' => trim($data['description'] ?? ''),
                'website' => trim($data['website'] ?? ''),
                'active' => 1
            ];

            // Check if series exists (by name and year)
            $existing = $db->getRow(
                "SELECT id FROM series WHERE name = ? AND year = ? LIMIT 1",
                [$seriesData['name'], $seriesData['year']]
            );

            if ($existing) {
                // Update existing series
                $db->update('series', $seriesData, 'id = ?', [$existing['id']]);
                $stats['updated']++;
                error_log("Updated series: {$seriesData['name']} ({$seriesData['year']})");
            } else {
                // Insert new series
                $newId = $db->insert('series', $seriesData);
                $stats['success']++;
                error_log("Inserted series: {$seriesData['name']} ({$seriesData['year']}) (ID: {$newId})");
            }

        } catch (Exception $e) {
            $stats['failed']++;
            $errors[] = "Rad {$lineNumber}: " . $e->getMessage();
            error_log("Import error on line {$lineNumber}: " . $e->getMessage());
        }
    }

    fclose($handle);

    // Verification
    $verifyCount = $db->getRow("SELECT COUNT(*) as count FROM series");
    $totalInDb = $verifyCount['count'] ?? 0;
    error_log("Series import complete: {$stats['success']} new, {$stats['updated']} updated, {$stats['failed']} failed. Total series in DB: {$totalInDb}");

    $stats['total_in_db'] = $totalInDb;

    return [
        'stats' => $stats,
        'errors' => $errors
    ];
}

$pageTitle = 'Importera Serier';
$pageType = 'admin';
include __DIR__ . '/../includes/layout-header.php';
?>

<main class="gs-content-with-sidebar">
    <div class="gs-container">
        <h1 class="gs-h2 gs-mb-lg">
            <i data-lucide="trophy"></i>
            Importera Serier
        </h1>

        <div class="gs-breadcrumb gs-mb-lg">
            <a href="/admin/dashboard.php">Dashboard</a>
            <span>/</span>
            <a href="/admin/series.php">Serier</a>
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
                        <p class="gs-text-lg"><strong>Totalt i databasen:</strong> <?= $stats['total_in_db'] ?> serier</p>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Upload Form -->
        <div class="gs-card gs-mb-lg">
            <div class="gs-card-header">
                <h2 class="gs-h3">Bulk-import av serier från CSV-fil</h2>
            </div>
            <div class="gs-card-content">
                <form method="POST" enctype="multipart/form-data" class="gs-form">
                    <?= csrfField() ?>

                    <div class="gs-alert gs-alert-info gs-mb-md">
                        <i data-lucide="info"></i>
                        <strong>CSV-format:</strong> Första raden ska innehålla kolumnnamn.
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
                            Importera Serier
                        </button>
                        <a href="/admin/series.php" class="gs-btn gs-btn-outline">
                            <i data-lucide="arrow-left"></i>
                            Tillbaka
                        </a>
                        <a href="/templates/import-series-template.csv" class="gs-btn gs-btn-outline" download>
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
                            <td>Serie-namn</td>
                            <td>✅ Ja</td>
                            <td>Enduro Series 2025</td>
                        </tr>
                        <tr>
                            <td><code>type</code></td>
                            <td>Typ (championship/cup/challenge)</td>
                            <td>Nej</td>
                            <td>championship</td>
                        </tr>
                        <tr>
                            <td><code>discipline</code></td>
                            <td>Disciplin</td>
                            <td>Nej</td>
                            <td>Enduro</td>
                        </tr>
                        <tr>
                            <td><code>year</code></td>
                            <td>År</td>
                            <td>Nej</td>
                            <td>2025</td>
                        </tr>
                        <tr>
                            <td><code>status</code></td>
                            <td>Status (planning/active/completed)</td>
                            <td>Nej</td>
                            <td>active</td>
                        </tr>
                        <tr>
                            <td><code>start_date</code></td>
                            <td>Startdatum (YYYY-MM-DD)</td>
                            <td>Nej</td>
                            <td>2025-05-01</td>
                        </tr>
                        <tr>
                            <td><code>end_date</code></td>
                            <td>Slutdatum (YYYY-MM-DD)</td>
                            <td>Nej</td>
                            <td>2025-09-30</td>
                        </tr>
                        <tr>
                            <td><code>description</code></td>
                            <td>Beskrivning</td>
                            <td>Nej</td>
                            <td>Svenska Enduro-mästerskapen 2025</td>
                        </tr>
                        <tr>
                            <td><code>website</code></td>
                            <td>Hemsida</td>
                            <td>Nej</td>
                            <td>https://enduro.se</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>

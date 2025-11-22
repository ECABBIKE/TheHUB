<?php
require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();
$current_admin = get_current_admin();

$message = '';
$messageType = 'info';
$stats = null;
$errors = [];

// Handle CSV upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['import_file'])) {
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

        if ($extension !== 'csv') {
            $message = 'Endast CSV-filer stöds';
            $messageType = 'error';
        } else {
            // Import classes
            $result = importClassesFromCSV($file['tmp_name'], $db);
            $stats = $result['stats'];
            $errors = $result['errors'];

            if ($stats['success'] > 0 || $stats['updated'] > 0) {
                $message = 'Import slutförd!';
                $messageType = 'success';
            } else {
                $message = 'Import slutförd med varningar';
                $messageType = 'warning';
            }
        }
    }
}

/**
 * Import classes from CSV file
 */
function importClassesFromCSV($filepath, $db) {
    $stats = [
        'total' => 0,
        'success' => 0,
        'updated' => 0,
        'skipped' => 0,
        'failed' => 0
    ];

    $errors = [];

    if (($handle = fopen($filepath, 'r')) === false) {
        throw new Exception('Kunde inte öppna filen');
    }

    // Auto-detect delimiter
    $firstLine = fgets($handle);
    rewind($handle);
    $delimiter = (substr_count($firstLine, ';') > substr_count($firstLine, ',')) ? ';' : ',';

    // Read header row
    $header = fgetcsv($handle, 1000, $delimiter);

    if (!$header) {
        fclose($handle);
        throw new Exception('Tom fil eller ogiltigt format');
    }

    // Normalize header
    $header = array_map(function($col) {
        $col = strtolower(trim($col));
        $col = str_replace([' ', '-', '_'], '', $col);

        $mappings = [
            'name' => 'name',
            'namn' => 'name',
            'displayname' => 'display_name',
            'visningsnamn' => 'display_name',
            'discipline' => 'discipline',
            'disciplines' => 'discipline',
            'disciplin' => 'discipline',
            'discipliner' => 'discipline',
            'gender' => 'gender',
            'kön' => 'gender',
            'kon' => 'gender',
            'minage' => 'min_age',
            'minålder' => 'min_age',
            'minalder' => 'min_age',
            'maxage' => 'max_age',
            'maxålder' => 'max_age',
            'maxalder' => 'max_age',
            'sortorder' => 'sort_order',
            'sortering' => 'sort_order',
            'active' => 'active',
            'aktiv' => 'active',
        ];

        return $mappings[$col] ?? $col;
    }, $header);

    $lineNumber = 1;

    while (($row = fgetcsv($handle, 1000, $delimiter)) !== false) {
        $lineNumber++;
        $stats['total']++;

        // Map row to associative array
        $data = array_combine($header, $row);

        // Validate required fields
        if (empty($data['name'])) {
            $stats['skipped']++;
            $errors[] = "Rad {$lineNumber}: Saknar namn";
            continue;
        }

        if (empty($data['display_name'])) {
            $stats['skipped']++;
            $errors[] = "Rad {$lineNumber}: Saknar visningsnamn";
            continue;
        }

        try {
            $name = trim($data['name']);
            $displayName = trim($data['display_name']);

            // Parse discipline (can be comma-separated)
            $discipline = !empty($data['discipline']) ? trim($data['discipline']) : '';

            // Parse gender
            $gender = '';
            if (!empty($data['gender'])) {
                $genderRaw = strtolower(trim($data['gender']));
                if (in_array($genderRaw, ['m', 'man', 'herr', 'male'])) {
                    $gender = 'M';
                } elseif (in_array($genderRaw, ['f', 'woman', 'kvinna', 'dam', 'female'])) {
                    $gender = 'F';
                }
            }

            // Parse ages
            $minAge = !empty($data['min_age']) ? (int)$data['min_age'] : null;
            $maxAge = !empty($data['max_age']) ? (int)$data['max_age'] : null;

            // Parse sort order
            $sortOrder = !empty($data['sort_order']) ? (int)$data['sort_order'] : 999;

            // Parse active status
            $active = 1;
            if (isset($data['active'])) {
                $activeRaw = strtolower(trim($data['active']));
                $active = in_array($activeRaw, ['1', 'true', 'yes', 'ja', 'aktiv']) ? 1 : 0;
            }

            // Prepare class data
            $classData = [
                'name' => $name,
                'display_name' => $displayName,
                'discipline' => $discipline,
                'gender' => $gender,
                'min_age' => $minAge,
                'max_age' => $maxAge,
                'sort_order' => $sortOrder,
                'active' => $active,
            ];

            // Check if class already exists (by name)
            $existing = $db->getRow(
                "SELECT id FROM classes WHERE LOWER(name) = LOWER(?) LIMIT 1",
                [$name]
            );

            if ($existing) {
                // Update existing class
                $db->update('classes', $classData, 'id = ?', [$existing['id']]);
                $stats['updated']++;
            } else {
                // Insert new class
                $db->insert('classes', $classData);
                $stats['success']++;
            }

        } catch (Exception $e) {
            $stats['failed']++;
            $errors[] = "Rad {$lineNumber}: " . $e->getMessage();
        }
    }

    fclose($handle);

    return [
        'stats' => $stats,
        'errors' => $errors
    ];
}

$pageTitle = 'Importera Klasser';
$pageType = 'admin';
include __DIR__ . '/../includes/layout-header.php';
?>

    <main class="gs-content-with-sidebar">
        <div class="gs-container">
            <!-- Header -->
            <div class="gs-flex gs-items-center gs-justify-between gs-mb-xl">
                <div>
                    <h1 class="gs-h1 gs-text-primary">
                        <i data-lucide="layers"></i>
                        Importera Klasser
                    </h1>
                    <p class="gs-text-secondary gs-mt-sm">
                        Bulk-import av klasser från CSV-fil
                    </p>
                </div>
                <a href="/admin/classes.php" class="gs-btn gs-btn-outline">
                    <i data-lucide="arrow-left"></i>
                    Tillbaka
                </a>
            </div>

            <!-- Message -->
            <?php if ($message): ?>
                <div class="gs-alert gs-alert-<?= h($messageType) ?> gs-mb-lg">
                    <i data-lucide="<?= $messageType === 'success' ? 'check-circle' : 'alert-circle' ?>"></i>
                    <?= h($message) ?>
                </div>
            <?php endif; ?>

            <!-- Statistics -->
            <?php if ($stats): ?>
                <div class="gs-card gs-mb-lg">
                    <div class="gs-card-header">
                        <h2 class="gs-h4 gs-text-primary">
                            <i data-lucide="bar-chart"></i>
                            Import-statistik
                        </h2>
                    </div>
                    <div class="gs-card-content">
                        <div class="gs-grid gs-grid-cols-2 gs-md-grid-cols-5 gs-gap-md">
                            <div class="gs-stat-card">
                                <i data-lucide="file-text" class="gs-icon-lg gs-text-primary gs-mb-sm"></i>
                                <div class="gs-stat-number"><?= number_format($stats['total']) ?></div>
                                <div class="gs-stat-label">Totalt rader</div>
                            </div>
                            <div class="gs-stat-card">
                                <i data-lucide="check-circle" class="gs-icon-lg gs-text-success gs-mb-sm"></i>
                                <div class="gs-stat-number"><?= number_format($stats['success']) ?></div>
                                <div class="gs-stat-label">Nya klasser</div>
                            </div>
                            <div class="gs-stat-card">
                                <i data-lucide="refresh-cw" class="gs-icon-lg gs-text-accent gs-mb-sm"></i>
                                <div class="gs-stat-number"><?= number_format($stats['updated']) ?></div>
                                <div class="gs-stat-label">Uppdaterade</div>
                            </div>
                            <div class="gs-stat-card">
                                <i data-lucide="minus-circle" class="gs-icon-lg gs-text-secondary gs-mb-sm"></i>
                                <div class="gs-stat-number"><?= number_format($stats['skipped']) ?></div>
                                <div class="gs-stat-label">Överhoppade</div>
                            </div>
                            <div class="gs-stat-card">
                                <i data-lucide="x-circle" class="gs-icon-lg gs-text-danger gs-mb-sm"></i>
                                <div class="gs-stat-number"><?= number_format($stats['failed']) ?></div>
                                <div class="gs-stat-label">Misslyckade</div>
                            </div>
                        </div>

                        <?php if (!empty($errors)): ?>
                            <div class="gs-mt-lg gs-section-divider-top">
                                <h3 class="gs-h5 gs-text-danger gs-mb-md">
                                    <i data-lucide="alert-triangle"></i>
                                    Fel och varningar (<?= count($errors) ?>)
                                </h3>
                                <div class="gs-scroll-y-300">
                                    <?php foreach (array_slice($errors, 0, 50) as $error): ?>
                                        <div class="gs-text-sm gs-text-secondary gs-mb-4px">
                                            • <?= h($error) ?>
                                        </div>
                                    <?php endforeach; ?>
                                    <?php if (count($errors) > 50): ?>
                                        <div class="gs-text-sm gs-text-secondary gs-mt-sm gs-font-italic">
                                            ... och <?= count($errors) - 50 ?> fler
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Upload Form -->
            <div class="gs-card gs-mb-xl">
                <div class="gs-card-header">
                    <h2 class="gs-h4 gs-text-primary">
                        <i data-lucide="upload"></i>
                        Ladda upp CSV-fil
                    </h2>
                </div>
                <div class="gs-card-content">
                    <form method="POST" enctype="multipart/form-data" class="gs-container-max-600">
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
                            <small class="gs-text-secondary gs-text-sm">
                                Max storlek: <?= round(MAX_UPLOAD_SIZE / 1024 / 1024) ?>MB
                            </small>
                        </div>

                        <button type="submit" class="gs-btn gs-btn-primary gs-btn-lg">
                            <i data-lucide="upload"></i>
                            Importera
                        </button>
                    </form>
                </div>
            </div>

            <!-- File Format Guide -->
            <div class="gs-card">
                <div class="gs-card-header">
                    <h2 class="gs-h4 gs-text-primary">
                        <i data-lucide="info"></i>
                        CSV-filformat
                    </h2>
                </div>
                <div class="gs-card-content">
                    <p class="gs-text-secondary gs-mb-md">
                        CSV-filen ska ha följande kolumner i första raden (header):
                    </p>

                    <div class="gs-table-responsive">
                        <table class="gs-table">
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
                                    <td><code>name</code></td>
                                    <td><span class="gs-badge gs-badge-danger">Ja</span></td>
                                    <td>Internt namn (får inte innehålla mellanslag)</td>
                                    <td>ELITE_M</td>
                                </tr>
                                <tr>
                                    <td><code>display_name</code></td>
                                    <td><span class="gs-badge gs-badge-danger">Ja</span></td>
                                    <td>Visningsnamn (syns publikt)</td>
                                    <td>Elite Herr</td>
                                </tr>
                                <tr>
                                    <td><code>discipline</code></td>
                                    <td><span class="gs-badge gs-badge-secondary">Nej</span></td>
                                    <td>Discipliner (kommaseparerade). Tom = alla discipliner</td>
                                    <td>XC,ENDURO</td>
                                </tr>
                                <tr>
                                    <td><code>gender</code></td>
                                    <td><span class="gs-badge gs-badge-secondary">Nej</span></td>
                                    <td>Kön (M/F eller Herr/Dam)</td>
                                    <td>M</td>
                                </tr>
                                <tr>
                                    <td><code>min_age</code></td>
                                    <td><span class="gs-badge gs-badge-secondary">Nej</span></td>
                                    <td>Minsta ålder för klassen</td>
                                    <td>19</td>
                                </tr>
                                <tr>
                                    <td><code>max_age</code></td>
                                    <td><span class="gs-badge gs-badge-secondary">Nej</span></td>
                                    <td>Högsta ålder för klassen</td>
                                    <td>29</td>
                                </tr>
                                <tr>
                                    <td><code>sort_order</code></td>
                                    <td><span class="gs-badge gs-badge-secondary">Nej</span></td>
                                    <td>Sorteringsordning (lägre = högre prioritet)</td>
                                    <td>10</td>
                                </tr>
                                <tr>
                                    <td><code>active</code></td>
                                    <td><span class="gs-badge gs-badge-secondary">Nej</span></td>
                                    <td>Aktiv (1/0 eller Ja/Nej)</td>
                                    <td>1</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="gs-mt-lg gs-bg-info-box-primary">
                        <h3 class="gs-h5 gs-text-primary gs-mb-sm">
                            <i data-lucide="lightbulb"></i>
                            Import-logik
                        </h3>
                        <ul class="gs-text-secondary gs-text-sm gs-list-ml-lg-lh-1-8">
                            <li>Klasser matchas via namn (name)</li>
                            <li>Befintliga klasser uppdateras automatiskt</li>
                            <li>Nya klasser skapas automatiskt</li>
                            <li>Flera discipliner separeras med komma: XC,DH,ENDURO</li>
                            <li>Tom disciplin = gäller för alla discipliner</li>
                        </ul>
                    </div>

                    <div class="gs-mt-md">
                        <p class="gs-text-sm gs-text-secondary">
                            <strong>Exempel på CSV-fil:</strong>
                        </p>
                        <pre class="gs-pre-code-block">name,display_name,discipline,gender,min_age,max_age,sort_order,active
ELITE_M,Elite Herr,XC,M,19,29,10,1
ELITE_F,Elite Dam,XC,F,19,29,20,1
JUNIOR_M_17_18,Junior Herr 17-18,XC,M,17,18,30,1
JUNIOR_F_17_18,Junior Dam 17-18,XC,F,17,18,40,1
MASTER_40,Master 40+,"XC,ENDURO",M,40,,100,1</pre>
                        <p class="gs-text-sm gs-text-secondary gs-mt-sm">
                            <strong>Tips:</strong> Använd samma disciplinkoder som i systemet: XC, DH, ENDURO, ROAD, TRACK, BMX, CX, GRAVEL
                        </p>
                    </div>

                    <!-- Download Template -->
                    <div class="gs-mt-lg">
                        <a href="data:text/csv;charset=utf-8,<?= urlencode("name,display_name,discipline,gender,min_age,max_age,sort_order,active\nELITE_M,Elite Herr,XC,M,19,29,10,1\nELITE_F,Elite Dam,XC,F,19,29,20,1\nJUNIOR_M_17_18,Junior Herr 17-18,XC,M,17,18,30,1\nJUNIOR_F_17_18,Junior Dam 17-18,XC,F,17,18,40,1\n") ?>"
                           download="class_import_template.csv"
                           class="gs-btn gs-btn-outline">
                            <i data-lucide="download"></i>
                            Ladda ner CSV-mall
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </main>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>

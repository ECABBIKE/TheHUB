<?php
require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();
$current_admin = get_current_admin();

$message = '';
$messageType = 'info';
$stats = null;
$errors = [];

// Handle CSV/Excel upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['import_file'])) {
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

        if (!in_array($extension, ['csv', 'xlsx', 'xls'])) {
            $message = 'Ogiltigt filformat. Tillåtna: CSV, XLSX, XLS';
            $messageType = 'error';
        } else {
            // Process the file
            $uploaded = UPLOADS_PATH . '/' . time() . '_' . basename($file['name']);

            if (move_uploaded_file($file['tmp_name'], $uploaded)) {
                try {
                    // Parse CSV
                    if ($extension === 'csv') {
                        $result = importRidersFromCSV($uploaded, $db);
                    } else {
                        // For Excel files, we'd need PhpSpreadsheet
                        // For now, show message to use CSV
                        $message = 'Excel-filer stöds inte än. Använd CSV-format istället.';
                        $messageType = 'warning';
                        @unlink($uploaded);
                        goto skip_import;
                    }

                    $stats = $result['stats'];
                    $errors = $result['errors'];

                    if ($stats['success'] > 0) {
                        $message = "Import klar! {$stats['success']} av {$stats['total']} cyklister importerade.";
                        $messageType = 'success';
                    } else {
                        $message = "Ingen data importerades. Kontrollera filformatet.";
                        $messageType = 'error';
                    }

                } catch (Exception $e) {
                    $message = 'Import misslyckades: ' . $e->getMessage();
                    $messageType = 'error';
                }

                @unlink($uploaded);
            } else {
                $message = 'Kunde inte ladda upp filen';
                $messageType = 'error';
            }
        }
    }

    skip_import:
}

/**
 * Import riders from CSV file
 */
function importRidersFromCSV($filepath, $db) {
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

    // Read header row
    $header = fgetcsv($handle, 1000, ',');

    if (!$header) {
        fclose($handle);
        throw new Exception('Tom fil eller ogiltigt format');
    }

    // Expected columns: firstname, lastname, birth_year, gender, club, license_number, email, phone, city
    $expectedColumns = ['firstname', 'lastname', 'birth_year', 'gender', 'club'];

    // Normalize header (lowercase, trim)
    $header = array_map(function($col) {
        return strtolower(trim($col));
    }, $header);

    // Cache for club lookups
    $clubCache = [];

    $lineNumber = 1;

    while (($row = fgetcsv($handle, 1000, ',')) !== false) {
        $lineNumber++;
        $stats['total']++;

        // Map row to associative array
        $data = array_combine($header, $row);

        // Validate required fields
        if (empty($data['firstname']) || empty($data['lastname'])) {
            $stats['skipped']++;
            $errors[] = "Rad {$lineNumber}: Saknar förnamn eller efternamn";
            continue;
        }

        try {
            // Prepare rider data
            $riderData = [
                'firstname' => trim($data['firstname']),
                'lastname' => trim($data['lastname']),
                'birth_year' => !empty($data['birth_year']) ? (int)$data['birth_year'] : null,
                'gender' => strtoupper(substr(trim($data['gender'] ?? 'M'), 0, 1)),
                'license_number' => !empty($data['license_number']) ? trim($data['license_number']) : null,
                'email' => !empty($data['email']) ? trim($data['email']) : null,
                'phone' => !empty($data['phone']) ? trim($data['phone']) : null,
                'city' => !empty($data['city']) ? trim($data['city']) : null,
                'active' => 1
            ];

            // Handle club - fuzzy matching
            if (!empty($data['club'])) {
                $clubName = trim($data['club']);

                // Check cache first
                if (isset($clubCache[$clubName])) {
                    $riderData['club_id'] = $clubCache[$clubName];
                } else {
                    // Try exact match first
                    $club = $db->getRow(
                        "SELECT id FROM clubs WHERE name = ? LIMIT 1",
                        [$clubName]
                    );

                    if (!$club) {
                        // Try fuzzy match (LIKE)
                        $club = $db->getRow(
                            "SELECT id FROM clubs WHERE name LIKE ? LIMIT 1",
                            ['%' . $clubName . '%']
                        );
                    }

                    if (!$club) {
                        // Create new club
                        $clubId = $db->insert('clubs', [
                            'name' => $clubName,
                            'active' => 1
                        ]);
                        $clubCache[$clubName] = $clubId;
                        $riderData['club_id'] = $clubId;
                    } else {
                        $clubCache[$clubName] = $club['id'];
                        $riderData['club_id'] = $club['id'];
                    }
                }
            } else {
                $riderData['club_id'] = null;
            }

            // Check if rider already exists (by license or name+birth_year)
            $existing = null;

            if ($riderData['license_number']) {
                $existing = $db->getRow(
                    "SELECT id FROM cyclists WHERE license_number = ? LIMIT 1",
                    [$riderData['license_number']]
                );
            }

            if (!$existing && $riderData['birth_year']) {
                $existing = $db->getRow(
                    "SELECT id FROM cyclists WHERE firstname = ? AND lastname = ? AND birth_year = ? LIMIT 1",
                    [$riderData['firstname'], $riderData['lastname'], $riderData['birth_year']]
                );
            }

            if ($existing) {
                // Update existing rider
                $db->update('cyclists', $riderData, 'id = ?', [$existing['id']]);
                $stats['updated']++;
            } else {
                // Insert new rider
                $db->insert('cyclists', $riderData);
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

$pageTitle = 'Importera Cyklister';
?>
<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($pageTitle) ?> - TheHUB Admin</title>
    <link rel="stylesheet" href="/assets/gravityseries-theme.css">
</head>
<body>
    <?php include __DIR__ . '/../includes/navigation.php'; ?>

    <main class="gs-content-with-sidebar">
        <div class="gs-container">
            <!-- Header -->
            <div class="gs-flex gs-items-center gs-justify-between gs-mb-xl">
                <div>
                    <h1 class="gs-h1 gs-text-primary">
                        <i data-lucide="users-2"></i>
                        Importera Cyklister
                    </h1>
                    <p class="gs-text-secondary gs-mt-sm">
                        Bulk-import av cyklister från CSV-fil
                    </p>
                </div>
                <a href="/admin/riders.php" class="gs-btn gs-btn-outline">
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
                                <div class="gs-stat-label">Nya</div>
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
                            <div class="gs-mt-lg" style="padding-top: var(--gs-space-lg); border-top: 1px solid var(--gs-border);">
                                <h3 class="gs-h5 gs-text-danger gs-mb-md">
                                    <i data-lucide="alert-triangle"></i>
                                    Fel och varningar (<?= count($errors) ?>)
                                </h3>
                                <div style="max-height: 300px; overflow-y: auto; background: var(--gs-background-secondary); padding: var(--gs-space-md); border-radius: var(--gs-border-radius);">
                                    <?php foreach (array_slice($errors, 0, 50) as $error): ?>
                                        <div class="gs-text-sm gs-text-secondary" style="margin-bottom: 4px;">
                                            • <?= h($error) ?>
                                        </div>
                                    <?php endforeach; ?>
                                    <?php if (count($errors) > 50): ?>
                                        <div class="gs-text-sm gs-text-secondary gs-mt-sm" style="font-style: italic;">
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
                    <form method="POST" enctype="multipart/form-data" id="uploadForm" style="max-width: 600px;">
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
                                accept=".csv,.xlsx,.xls"
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

                    <!-- Progress Bar (hidden initially) -->
                    <div id="progressBar" style="display: none; margin-top: var(--gs-space-lg);">
                        <div class="gs-flex gs-items-center gs-justify-between gs-mb-sm">
                            <span class="gs-text-sm gs-text-primary" style="font-weight: 600;">Importerar...</span>
                            <span class="gs-text-sm gs-text-secondary" id="progressPercent">0%</span>
                        </div>
                        <div style="width: 100%; height: 8px; background: var(--gs-background-secondary); border-radius: 4px; overflow: hidden;">
                            <div id="progressFill" style="width: 0%; height: 100%; background: var(--gs-primary); transition: width 0.3s;"></div>
                        </div>
                    </div>
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
                                    <td><code>firstname</code></td>
                                    <td><span class="gs-badge gs-badge-danger">Ja</span></td>
                                    <td>Förnamn</td>
                                    <td>Erik</td>
                                </tr>
                                <tr>
                                    <td><code>lastname</code></td>
                                    <td><span class="gs-badge gs-badge-danger">Ja</span></td>
                                    <td>Efternamn</td>
                                    <td>Andersson</td>
                                </tr>
                                <tr>
                                    <td><code>birth_year</code></td>
                                    <td><span class="gs-badge gs-badge-secondary">Nej</span></td>
                                    <td>Födelseår</td>
                                    <td>1995</td>
                                </tr>
                                <tr>
                                    <td><code>gender</code></td>
                                    <td><span class="gs-badge gs-badge-secondary">Nej</span></td>
                                    <td>Kön (M/F)</td>
                                    <td>M</td>
                                </tr>
                                <tr>
                                    <td><code>club</code></td>
                                    <td><span class="gs-badge gs-badge-secondary">Nej</span></td>
                                    <td>Klubbnamn (skapas om den inte finns)</td>
                                    <td>Team GravitySeries</td>
                                </tr>
                                <tr>
                                    <td><code>license_number</code></td>
                                    <td><span class="gs-badge gs-badge-secondary">Nej</span></td>
                                    <td>UCI/SCF licensnummer (används för dubbletthantering)</td>
                                    <td>SWE-2025-1234</td>
                                </tr>
                                <tr>
                                    <td><code>email</code></td>
                                    <td><span class="gs-badge gs-badge-secondary">Nej</span></td>
                                    <td>E-postadress</td>
                                    <td>erik@example.com</td>
                                </tr>
                                <tr>
                                    <td><code>phone</code></td>
                                    <td><span class="gs-badge gs-badge-secondary">Nej</span></td>
                                    <td>Telefonnummer</td>
                                    <td>070-1234567</td>
                                </tr>
                                <tr>
                                    <td><code>city</code></td>
                                    <td><span class="gs-badge gs-badge-secondary">Nej</span></td>
                                    <td>Stad/Ort</td>
                                    <td>Stockholm</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="gs-mt-lg" style="padding: var(--gs-space-md); background: var(--gs-background-secondary); border-radius: var(--gs-border-radius); border-left: 4px solid var(--gs-primary);">
                        <h3 class="gs-h5 gs-text-primary gs-mb-sm">
                            <i data-lucide="lightbulb"></i>
                            Tips
                        </h3>
                        <ul class="gs-text-secondary gs-text-sm" style="margin-left: var(--gs-space-lg); line-height: 1.8;">
                            <li>Använd komma (,) som separator</li>
                            <li>UTF-8 encoding för svenska tecken</li>
                            <li>Dubbletter upptäcks via licensnummer eller namn+födelseår</li>
                            <li>Befintliga cyklister uppdateras automatiskt</li>
                            <li>Klubbar som inte finns skapas automatiskt</li>
                            <li>Fuzzy matching används för klubbnamn (matchas även vid små skillnader)</li>
                        </ul>
                    </div>

                    <div class="gs-mt-md">
                        <p class="gs-text-sm gs-text-secondary">
                            <strong>Exempel på CSV-fil:</strong>
                        </p>
                        <pre style="background: var(--gs-background-secondary); padding: var(--gs-space-md); border-radius: var(--gs-border-radius); overflow-x: auto; font-size: 12px; margin-top: var(--gs-space-sm);">firstname,lastname,birth_year,gender,club,license_number,email,phone,city
Erik,Andersson,1995,M,Team GravitySeries,SWE-2025-1234,erik@example.com,070-1234567,Stockholm
Anna,Karlsson,1998,F,CK Olympia,SWE-2025-2345,anna@example.com,070-2345678,Göteborg
Johan,Svensson,1992,M,Uppsala CK,SWE-2025-3456,johan@example.com,070-3456789,Uppsala</pre>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            lucide.createIcons();

            // Show progress bar on form submit
            const form = document.getElementById('uploadForm');
            const progressBar = document.getElementById('progressBar');
            const progressFill = document.getElementById('progressFill');
            const progressPercent = document.getElementById('progressPercent');

            form.addEventListener('submit', function() {
                progressBar.style.display = 'block';

                // Simulate progress (since we can't track real progress in PHP)
                let progress = 0;
                const interval = setInterval(function() {
                    progress += Math.random() * 15;
                    if (progress > 90) {
                        progress = 90;
                        clearInterval(interval);
                    }
                    progressFill.style.width = progress + '%';
                    progressPercent.textContent = Math.round(progress) + '%';
                }, 200);
            });
        });
    </script>
</body>
</html>

<?php
require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();
$current_admin = get_current_admin();

$message = '';
$messageType = 'info';
$stats = null;
$matching_stats = null;
$errors = [];

// Handle CSV upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['import_file'])) {
    // Validate CSRF token
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
            $message = 'Endast CSV-filer stöds för resultatimport';
            $messageType = 'error';
        } else {
            // Process the file
            $uploaded = UPLOADS_PATH . '/' . time() . '_' . basename($file['name']);

            if (move_uploaded_file($file['tmp_name'], $uploaded)) {
                try {
                    $result = importResultsFromCSV($uploaded, $db);

                    $stats = $result['stats'];
                    $matching_stats = $result['matching'];
                    $errors = $result['errors'];

                    if ($stats['success'] > 0) {
                        $message = "Import klar! {$stats['success']} av {$stats['total']} resultat importerade.";
                        $messageType = 'success';
                    } else {
                        $message = "Ingen data importerades. Kontrollera filformatet och matchningar.";
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
}

/**
 * Import results from CSV file
 */
function importResultsFromCSV($filepath, $db) {
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
        'riders_found' => 0,
        'riders_not_found' => 0,
        'riders_created' => 0
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

    // Normalize header (lowercase, trim, handle both underscore and non-underscore versions)
    $header = array_map(function($col) {
        $col = strtolower(trim($col));
        // Normalize column names: convert first_name to firstname, last_name to lastname, etc
        $col = str_replace(['first_name', 'last_name', 'club_name', 'e-mail', 'uci_id'],
                          ['firstname', 'lastname', 'club', 'email', 'license_number'],
                          $col);
        return $col;
    }, $header);

    // Cache for lookups
    $eventCache = [];
    $riderCache = [];

    $lineNumber = 1;

    while (($row = fgetcsv($handle, 1000, ',')) !== false) {
        $lineNumber++;
        $stats['total']++;

        // Map row to associative array
        $data = array_combine($header, $row);

        // Validate required fields
        if (empty($data['event_name'])) {
            $stats['skipped']++;
            $errors[] = "Rad {$lineNumber}: Saknar tävlingsnamn";
            continue;
        }

        if (empty($data['firstname']) || empty($data['lastname'])) {
            $stats['skipped']++;
            $errors[] = "Rad {$lineNumber}: Saknar namn på cyklist";
            continue;
        }

        try {
            // Find event
            $eventName = trim($data['event_name']);
            $eventId = null;

            if (isset($eventCache[$eventName])) {
                $eventId = $eventCache[$eventName];
            } else {
                // Try exact match
                $event = $db->getRow(
                    "SELECT id FROM events WHERE name = ? LIMIT 1",
                    [$eventName]
                );

                if (!$event) {
                    // Try fuzzy match (LIKE)
                    $event = $db->getRow(
                        "SELECT id FROM events WHERE name LIKE ? LIMIT 1",
                        ['%' . $eventName . '%']
                    );
                }

                if ($event) {
                    $eventId = $event['id'];
                    $eventCache[$eventName] = $eventId;
                    $matching_stats['events_found']++;
                } else {
                    $matching_stats['events_not_found']++;
                    $stats['failed']++;
                    $errors[] = "Rad {$lineNumber}: Tävling '{$eventName}' hittades inte";
                    continue;
                }
            }

            // Find rider
            $firstname = trim($data['firstname']);
            $lastname = trim($data['lastname']);
            $licenseNumber = !empty($data['license_number']) ? trim($data['license_number']) : null;
            $birthYear = !empty($data['birth_year']) ? (int)$data['birth_year'] : null;

            $riderId = null;
            $cacheKey = $licenseNumber ?: "{$firstname}|{$lastname}|{$birthYear}";

            if (isset($riderCache[$cacheKey])) {
                $riderId = $riderCache[$cacheKey];
            } else {
                // Try license number first
                if ($licenseNumber) {
                    $rider = $db->getRow(
                        "SELECT id FROM riders WHERE license_number = ? LIMIT 1",
                        [$licenseNumber]
                    );
                    if ($rider) {
                        $riderId = $rider['id'];
                        $riderCache[$cacheKey] = $riderId;
                        $matching_stats['riders_found']++;
                    }
                }

                // Try name + birth year
                if (!$riderId && $birthYear) {
                    $rider = $db->getRow(
                        "SELECT id FROM riders WHERE firstname = ? AND lastname = ? AND birth_year = ? LIMIT 1",
                        [$firstname, $lastname, $birthYear]
                    );
                    if ($rider) {
                        $riderId = $rider['id'];
                        $riderCache[$cacheKey] = $riderId;
                        $matching_stats['riders_found']++;
                    }
                }

                // Try name only (fuzzy)
                if (!$riderId) {
                    $rider = $db->getRow(
                        "SELECT id FROM riders WHERE firstname LIKE ? AND lastname LIKE ? LIMIT 1",
                        ['%' . $firstname . '%', '%' . $lastname . '%']
                    );
                    if ($rider) {
                        $riderId = $rider['id'];
                        $riderCache[$cacheKey] = $riderId;
                        $matching_stats['riders_found']++;
                    } else {
                        // AUTO-CREATE: Rider not found, create new rider with SWE-ID
                        $matching_stats['riders_not_found']++;

                        // Generate SWE-ID
                        $sweId = generateSweId($db);

                        // Get gender from data if available
                        $gender = 'M'; // Default
                        if (!empty($data['gender'])) {
                            $genderRaw = strtolower(trim($data['gender']));
                            if (in_array($genderRaw, ['woman', 'female', 'kvinna', 'dam', 'f'])) {
                                $gender = 'F';
                            } elseif (in_array($genderRaw, ['man', 'male', 'herr', 'm'])) {
                                $gender = 'M';
                            }
                        }

                        // Create new rider
                        $newRiderData = [
                            'firstname' => $firstname,
                            'lastname' => $lastname,
                            'birth_year' => $birthYear,
                            'gender' => $gender,
                            'license_number' => $sweId,
                            'license_type' => 'SWE-ID',
                            'active' => 1
                        ];

                        $riderId = $db->insert('riders', $newRiderData);
                        $riderCache[$cacheKey] = $riderId;
                        $matching_stats['riders_created']++;
                        error_log("Auto-created rider: {$firstname} {$lastname} with SWE-ID: {$sweId}");
                    }
                }
            }

            // Prepare result data
            $resultData = [
                'event_id' => $eventId,
                'cyclist_id' => $riderId,
                'position' => !empty($data['position']) ? (int)$data['position'] : null,
                'finish_time' => !empty($data['finish_time']) ? trim($data['finish_time']) : null,
                'bib_number' => !empty($data['bib_number']) ? trim($data['bib_number']) : null,
                'status' => !empty($data['status']) ? strtolower(trim($data['status'])) : 'finished',
                'points' => !empty($data['points']) ? (int)$data['points'] : 0,
                'notes' => !empty($data['notes']) ? trim($data['notes']) : null
            ];

            // Check if result already exists
            $existing = $db->getRow(
                "SELECT id FROM results WHERE event_id = ? AND cyclist_id = ? LIMIT 1",
                [$eventId, $riderId]
            );

            if ($existing) {
                // Update existing result
                $db->update('results', $resultData, 'id = ?', [$existing['id']]);
                $stats['updated']++;
            } else {
                // Insert new result
                $db->insert('results', $resultData);
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
        'matching' => $matching_stats,
        'errors' => $errors
    ];
}

$pageTitle = 'Importera Resultat';
$pageType = 'admin';
include __DIR__ . '/../includes/layout-header.php';
?>

    <main class="gs-content-with-sidebar">
        <div class="gs-container">
            <!-- Header -->
            <div class="gs-flex gs-items-center gs-justify-between gs-mb-xl">
                <div>
                    <h1 class="gs-h1 gs-text-primary">
                        <i data-lucide="trophy"></i>
                        Importera Resultat
                    </h1>
                    <p class="gs-text-secondary gs-mt-sm">
                        Bulk-import av tävlingsresultat från CSV-fil
                    </p>
                </div>
                <a href="/admin/results.php" class="gs-btn gs-btn-outline">
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
                        <div class="gs-grid gs-grid-cols-2 gs-md-grid-cols-5 gs-gap-md gs-mb-lg">
                            <div class="gs-stat-card">
                                <i data-lucide="file-text" class="gs-icon-lg gs-text-primary gs-mb-sm"></i>
                                <div class="gs-stat-number"><?= number_format($stats['total']) ?></div>
                                <div class="gs-stat-label">Totalt rader</div>
                            </div>
                            <div class="gs-stat-card">
                                <i data-lucide="check-circle" class="gs-icon-lg gs-text-success gs-mb-sm"></i>
                                <div class="gs-stat-number"><?= number_format($stats['success']) ?></div>
                                <div class="gs-stat-label">Nya resultat</div>
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

                        <?php if ($matching_stats): ?>
                            <div style="padding-top: var(--gs-space-lg); border-top: 1px solid var(--gs-border);">
                                <h3 class="gs-h5 gs-text-primary gs-mb-md">
                                    <i data-lucide="search"></i>
                                    Matchnings-statistik
                                </h3>
                                <div class="gs-grid gs-grid-cols-2 gs-md-grid-cols-5 gs-gap-md">
                                    <div style="padding: var(--gs-space-md); background: var(--gs-background-secondary); border-radius: var(--gs-border-radius);">
                                        <div class="gs-text-sm gs-text-secondary">Events hittade</div>
                                        <div class="gs-h3 gs-text-success"><?= $matching_stats['events_found'] ?></div>
                                    </div>
                                    <div style="padding: var(--gs-space-md); background: var(--gs-background-secondary); border-radius: var(--gs-border-radius);">
                                        <div class="gs-text-sm gs-text-secondary">Events ej hittade</div>
                                        <div class="gs-h3 gs-text-danger"><?= $matching_stats['events_not_found'] ?></div>
                                    </div>
                                    <div style="padding: var(--gs-space-md); background: var(--gs-background-secondary); border-radius: var(--gs-border-radius);">
                                        <div class="gs-text-sm gs-text-secondary">Deltagare hittade</div>
                                        <div class="gs-h3 gs-text-success"><?= $matching_stats['riders_found'] ?></div>
                                    </div>
                                    <div style="padding: var(--gs-space-md); background: var(--gs-background-secondary); border-radius: var(--gs-border-radius);">
                                        <div class="gs-text-sm gs-text-secondary">Nya deltagare skapade</div>
                                        <div class="gs-h3 gs-text-accent"><?= $matching_stats['riders_created'] ?? 0 ?></div>
                                    </div>
                                    <div style="padding: var(--gs-space-md); background: var(--gs-background-secondary); border-radius: var(--gs-border-radius);">
                                        <div class="gs-text-sm gs-text-secondary">SWE-ID tilldelat</div>
                                        <div class="gs-h3 gs-text-primary"><?= $matching_stats['riders_created'] ?? 0 ?></div>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

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
                    <div class="gs-alert gs-alert-warning gs-mb-md">
                        <i data-lucide="alert-circle"></i>
                        <strong>OBS:</strong> Se till att tävlingar och cyklister redan finns i systemet innan du importerar resultat.
                    </div>

                    <form method="POST" enctype="multipart/form-data" id="uploadForm" style="max-width: 600px;">
                        <?= csrfField() ?>

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

                    <!-- Progress Bar -->
                    <div id="progressBar" style="display: none; margin-top: var(--gs-space-lg);">
                        <div class="gs-flex gs-items-center gs-justify-between gs-mb-sm">
                            <span class="gs-text-sm gs-text-primary" style="font-weight: 600;">Importerar och matchar...</span>
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
                                    <td><code>event_name</code></td>
                                    <td><span class="gs-badge gs-badge-danger">Ja</span></td>
                                    <td>Tävlingens namn (måste finnas i systemet)</td>
                                    <td>GravitySeries Järvsö XC</td>
                                </tr>
                                <tr>
                                    <td><code>firstname</code></td>
                                    <td><span class="gs-badge gs-badge-danger">Ja</span></td>
                                    <td>Cyklistens förnamn</td>
                                    <td>Erik</td>
                                </tr>
                                <tr>
                                    <td><code>lastname</code></td>
                                    <td><span class="gs-badge gs-badge-danger">Ja</span></td>
                                    <td>Cyklistens efternamn</td>
                                    <td>Andersson</td>
                                </tr>
                                <tr>
                                    <td><code>position</code></td>
                                    <td><span class="gs-badge gs-badge-secondary">Nej</span></td>
                                    <td>Placering</td>
                                    <td>1</td>
                                </tr>
                                <tr>
                                    <td><code>finish_time</code></td>
                                    <td><span class="gs-badge gs-badge-secondary">Nej</span></td>
                                    <td>Sluttid (HH:MM:SS)</td>
                                    <td>02:15:30</td>
                                </tr>
                                <tr>
                                    <td><code>license_number</code></td>
                                    <td><span class="gs-badge gs-badge-secondary">Nej</span></td>
                                    <td>UCI/SCF licensnummer (används för matchning)</td>
                                    <td>SWE-2025-1234</td>
                                </tr>
                                <tr>
                                    <td><code>birth_year</code></td>
                                    <td><span class="gs-badge gs-badge-secondary">Nej</span></td>
                                    <td>Födelseår (används för matchning)</td>
                                    <td>1995</td>
                                </tr>
                                <tr>
                                    <td><code>bib_number</code></td>
                                    <td><span class="gs-badge gs-badge-secondary">Nej</span></td>
                                    <td>Startnummer</td>
                                    <td>42</td>
                                </tr>
                                <tr>
                                    <td><code>status</code></td>
                                    <td><span class="gs-badge gs-badge-secondary">Nej</span></td>
                                    <td>Status (finished/dnf/dns/dq)</td>
                                    <td>finished</td>
                                </tr>
                                <tr>
                                    <td><code>points</code></td>
                                    <td><span class="gs-badge gs-badge-secondary">Nej</span></td>
                                    <td>Poäng</td>
                                    <td>100</td>
                                </tr>
                                <tr>
                                    <td><code>notes</code></td>
                                    <td><span class="gs-badge gs-badge-secondary">Nej</span></td>
                                    <td>Anteckningar</td>
                                    <td>-</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="gs-mt-lg" style="padding: var(--gs-space-md); background: var(--gs-background-secondary); border-radius: var(--gs-border-radius); border-left: 4px solid var(--gs-primary);">
                        <h3 class="gs-h5 gs-text-primary gs-mb-sm">
                            <i data-lucide="lightbulb"></i>
                            Matchnings-logik
                        </h3>
                        <ul class="gs-text-secondary gs-text-sm" style="margin-left: var(--gs-space-lg); line-height: 1.8;">
                            <li><strong>Events:</strong> Matchas via exakt namn eller fuzzy match (LIKE)</li>
                            <li><strong>Cyklister:</strong> Matchas i följande ordning:
                                <ol style="margin-left: var(--gs-space-lg); margin-top: 4px;">
                                    <li>Licensnummer (exakt match)</li>
                                    <li>Namn + födelseår (exakt match)</li>
                                    <li>Namn (fuzzy match)</li>
                                </ol>
                            </li>
                            <li>Dubbletter upptäcks via event_id + cyclist_id</li>
                            <li>Befintliga resultat uppdateras automatiskt</li>
                        </ul>
                    </div>

                    <div class="gs-mt-md">
                        <p class="gs-text-sm gs-text-secondary">
                            <strong>Exempel på CSV-fil:</strong>
                        </p>
                        <pre style="background: var(--gs-background-secondary); padding: var(--gs-space-md); border-radius: var(--gs-border-radius); overflow-x: auto; font-size: 12px; margin-top: var(--gs-space-sm);">event_name,firstname,lastname,position,finish_time,license_number,birth_year,bib_number,status,points
GravitySeries Järvsö XC,Erik,Andersson,1,02:15:30,SWE-2025-1234,1995,42,finished,100
GravitySeries Järvsö XC,Anna,Karlsson,2,02:18:45,SWE-2025-2345,1998,43,finished,80
GravitySeries Järvsö XC,Johan,Svensson,3,02:20:12,SWE-2025-3456,1992,44,finished,60</pre>
                    </div>
                </div>
            </div>
        </div>
<?php
$additionalScripts = <<<'SCRIPT'
<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Show progress bar on form submit
        const form = document.getElementById('uploadForm');
        const progressBar = document.getElementById('progressBar');
        const progressFill = document.getElementById('progressFill');
        const progressPercent = document.getElementById('progressPercent');

        form.addEventListener('submit', function() {
            progressBar.style.display = 'block';

            // Simulate progress
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
SCRIPT;

include __DIR__ . '/../includes/layout-footer.php';
?>

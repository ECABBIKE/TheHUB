<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_admin();

$db = getDB();
$current_admin = get_current_admin();

// Load import history helper functions
require_once __DIR__ . '/../includes/import-history.php';

$message = '';
$messageType = 'info';
$stats = null;
$errors = [];
$updated_riders = [];

// Handle CSV upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['uci_file'])) {
    // Validate CSRF token
    checkCsrf();

    $file = $_FILES['uci_file'];

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
            $message = 'Ogiltigt filformat. Endast CSV tillåten.';
            $messageType = 'error';
        } else {
            // Process the file
            $uploaded = UPLOADS_PATH . '/' . time() . '_uci_' . basename($file['name']);

            if (move_uploaded_file($file['tmp_name'], $uploaded)) {
                try {
                    // Start import history tracking
                    $importId = startImportHistory(
                        $db,
                        'uci',
                        $file['name'],
                        $file['size'],
                        $current_admin['username'] ?? 'admin'
                    );

                    // Perform import
                    $result = importUCIRiders($uploaded, $db, $importId);

                    $stats = $result['stats'];
                    $errors = $result['errors'];
                    $updated_riders = $result['updated'];

                    // Update import history with final statistics
                    $importStatus = ($stats['success'] > 0 || $stats['updated'] > 0) ? 'completed' : 'failed';
                    updateImportHistory($db, $importId, $stats, $errors, $importStatus);

                    if ($stats['success'] > 0 || $stats['updated'] > 0) {
                        $message = "Import klar! {$stats['success']} nya riders, {$stats['updated']} uppdaterade. <a href='/admin/import-history.php' style='text-decoration:underline'>Visa historik</a>";
                        $messageType = 'success';
                    } else {
                        $message = "Ingen data importerades. Kontrollera filformatet.";
                        $messageType = 'error';
                    }

                } catch (Exception $e) {
                    $message = 'Import misslyckades: ' . $e->getMessage();
                    $messageType = 'error';

                    // Mark import as failed if importId was created
                    if (isset($importId)) {
                        updateImportHistory($db, $importId, ['total' => 0], [$e->getMessage()], 'failed');
                    }
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
 * Auto-detect CSV separator with improved logic
 */
function detectCsvSeparator($file_path) {
    $handle = fopen($file_path, 'r');
    $first_line = fgets($handle);
    fclose($handle);

    // Try all common separators
    $separators = [
        ',' => str_getcsv($first_line, ','),
        ';' => str_getcsv($first_line, ';'),
        "\t" => str_getcsv($first_line, "\t"),
        '|' => str_getcsv($first_line, '|')
    ];

    // Return separator with most columns (should be 11+)
    $max_count = 0;
    $best_sep = ',';
    foreach ($separators as $sep => $row) {
        $count = count($row);
        if ($count > $max_count) {
            $max_count = $count;
            $best_sep = $sep;
        }
    }

    error_log("Separator detection - Best: '$best_sep' with $max_count columns");
    return $best_sep;
}

/**
 * Detect and convert file encoding to UTF-8
 */
function ensureUTF8($filepath) {
    $content = file_get_contents($filepath);
    $encoding = mb_detect_encoding($content, ['UTF-8', 'ISO-8859-1', 'Windows-1252', 'CP1252'], true);

    if ($encoding && $encoding !== 'UTF-8') {
        error_log("Converting file from $encoding to UTF-8");
        $content = mb_convert_encoding($content, 'UTF-8', $encoding);
        file_put_contents($filepath, $content);
        return true;
    }

    return false;
}

/**
 * Import riders from UCI CSV file
 *
 * @param string $filepath Path to CSV file
 * @param object $db Database connection
 * @param int $importId Import history ID for tracking
 */
function importUCIRiders($filepath, $db, $importId = null) {
    $stats = [
        'total' => 0,
        'success' => 0,
        'updated' => 0,
        'skipped' => 0,
        'failed' => 0
    ];
    $errors = [];
    $updated_riders = [];
    $clubCache = [];

    // STEP 1: Convert encoding to UTF-8 if needed
    ensureUTF8($filepath);

    if (($handle = fopen($filepath, 'r')) === false) {
        throw new Exception('Kunde inte öppna filen');
    }

    // STEP 2: Auto-detect separator
    $separator = detectCsvSeparator($filepath);
    $stats['separator'] = $separator;
    $stats['separator_name'] = ($separator === "\t") ? 'TAB' : $separator;

    error_log("=== UCI IMPORT STARTED ===");
    error_log("File: " . basename($filepath));
    error_log("Separator detected: " . $stats['separator_name']);

    // Check if first line is header
    $first_line = fgets($handle);
    if (!preg_match('/^\d{8}-\d{4}/', $first_line)) {
        // It's a header, continue to next line
    } else {
        // Not a header, rewind to start
        rewind($handle);
    }

    $lineNumber = 1;

    while (($line = fgets($handle)) !== false) {
        $lineNumber++;
        $stats['total']++;

        // Skip empty lines
        if (trim($line) === '') {
            continue;
        }

        try {
            // Parse CSV row with detected separator
            $row = str_getcsv($line, $separator);

            // STEP 3: Handle missing columns gracefully (pad with empty strings)
            while (count($row) < 11) {
                $row[] = '';
            }

            // Trim ALL values to remove whitespace
            $row = array_map('trim', $row);

            // STEP 4: Comprehensive error logging for first 3 rows
            if ($stats['total'] <= 3) {
                error_log("=== ROW " . $stats['total'] . " ===");
                error_log("Raw line: " . substr($line, 0, 200)); // First 200 chars
                error_log("Separator used: " . $stats['separator_name']);
                error_log("Parsed columns: " . count($row));
                error_log("Column data:");
                for ($i = 0; $i < count($row); $i++) {
                    error_log("  [$i] = '" . substr($row[$i], 0, 50) . "'");
                }
            }

            // Extract data according to UCI format position
            $personnummer = $row[0];
            $firstname = $row[1];
            $lastname = $row[2];
            $country = $row[3]; // Ignore, always Sweden
            $email = $row[4];
            $club_name = $row[5];
            $discipline = $row[6];
            $gender_raw = $row[7];
            $license_category = $row[8];
            $license_year = $row[9];
            $uci_code = $row[10];

            // Validate required fields
            if (empty($firstname) || empty($lastname)) {
                $stats['skipped']++;
                $errors[] = "Rad {$lineNumber}: Saknar förnamn eller efternamn";
                continue;
            }

            // 1. Personnummer → birth_year
            $birth_year = parsePersonnummer($personnummer);
            if (!$birth_year) {
                $stats['failed']++;
                $errors[] = "Rad {$lineNumber}: {$firstname} {$lastname} - Ogiltigt personnummer '{$personnummer}'";
                continue;
            }

            // 2. Gender: Men/Women → M/F
            $gender = 'M'; // Default
            if (stripos($gender_raw, 'women') !== false || stripos($gender_raw, 'dam') !== false) {
                $gender = 'F';
            } elseif (stripos($gender_raw, 'men') !== false || stripos($gender_raw, 'herr') !== false) {
                $gender = 'M';
            }

            // 3. UCI ID: Keep exact format with spaces (e.g. "101 637 581 11")
            // Only generate SWE-ID if UCI code is missing
            if (!empty($uci_code)) {
                $license_number = $uci_code; // Keep as-is with spaces
            } else {
                // Generate SWE-ID for riders without UCI code
                $license_number = generateSweId($db);
            }

            // 4. License type: Extract from category
            $license_type = 'Base';
            if (stripos($license_category, 'Master') !== false) {
                $license_type = 'Master';
            } elseif (stripos($license_category, 'Elite') !== false) {
                $license_type = 'Elite';
            } elseif (stripos($license_category, 'Youth') !== false || stripos($license_category, 'Under') !== false || stripos($license_category, 'U1') !== false || stripos($license_category, 'U2') !== false) {
                $license_type = 'Youth';
            } elseif (stripos($license_category, 'Team Manager') !== false) {
                $license_type = 'Team Manager';
            }

            // 5. License valid until: Year → Last day of year
            $license_valid_until = null;
            if (!empty($license_year) && is_numeric($license_year)) {
                $license_valid_until = $license_year . '-12-31';
            }

            // 6. Find or create club
            $club_id = null;
            if (!empty($club_name)) {
                // Check cache first
                if (isset($clubCache[$club_name])) {
                    $club_id = $clubCache[$club_name];
                } else {
                    // Try exact match
                    $club = $db->getRow(
                        "SELECT id FROM clubs WHERE name = ? LIMIT 1",
                        [$club_name]
                    );

                    if (!$club) {
                        // Try fuzzy match
                        $club = $db->getRow(
                            "SELECT id FROM clubs WHERE name LIKE ? LIMIT 1",
                            ['%' . $club_name . '%']
                        );
                    }

                    if (!$club) {
                        // Create new club
                        $club_id = $db->insert('clubs', [
                            'name' => $club_name,
                            'country' => 'Sverige',
                            'active' => 1
                        ]);
                        $clubCache[$club_name] = $club_id;
                    } else {
                        $club_id = $club['id'];
                        $clubCache[$club_name] = $club_id;
                    }
                }
            }

            // 7. Check if rider exists (by license number or name+birth_year)
            $existing = null;

            if (!empty($license_number)) {
                $existing = $db->getRow(
                    "SELECT id FROM riders WHERE license_number = ? LIMIT 1",
                    [$license_number]
                );
            }

            if (!$existing && $birth_year) {
                $existing = $db->getRow(
                    "SELECT id FROM riders WHERE firstname = ? AND lastname = ? AND birth_year = ? LIMIT 1",
                    [$firstname, $lastname, $birth_year]
                );
            }

            // Prepare rider data
            $riderData = [
                'firstname' => $firstname,
                'lastname' => $lastname,
                'birth_year' => $birth_year,
                'gender' => $gender,
                'club_id' => $club_id,
                'license_number' => $license_number,
                'license_type' => $license_type,
                'license_category' => $license_category,
                'discipline' => $discipline,
                'license_valid_until' => $license_valid_until,
                'email' => !empty($email) ? $email : null,
                'active' => 1
            ];

            if ($existing) {
                // Get old data before updating (for rollback)
                $oldData = null;
                if ($importId) {
                    $oldData = $db->getRow("SELECT * FROM riders WHERE id = ?", [$existing['id']]);
                }

                // Update existing rider
                $db->update('riders', $riderData, 'id = ?', [$existing['id']]);
                $stats['updated']++;
                $updated_riders[] = "{$firstname} {$lastname}";

                // Track updated record
                if ($importId) {
                    trackImportRecord($db, $importId, 'rider', $existing['id'], 'updated', $oldData);
                }
            } else {
                // Insert new rider
                $riderId = $db->insert('riders', $riderData);
                $stats['success']++;

                // Track created record
                if ($importId && $riderId) {
                    trackImportRecord($db, $importId, 'rider', $riderId, 'created');
                }
            }

        } catch (Exception $e) {
            $stats['failed']++;
            $errors[] = "Rad {$lineNumber}: " . $e->getMessage();
        }
    }

    fclose($handle);

    // Final summary log
    error_log("=== UCI IMPORT COMPLETED ===");
    error_log("Total processed: " . $stats['total']);
    error_log("Success (new): " . $stats['success']);
    error_log("Updated: " . $stats['updated']);
    error_log("Skipped: " . $stats['skipped']);
    error_log("Failed: " . $stats['failed']);

    return [
        'stats' => $stats,
        'errors' => $errors,
        'updated' => $updated_riders
    ];
}

$pageTitle = 'UCI Import';
$pageType = 'admin';
include __DIR__ . '/../includes/layout-header.php';
?>

    <main class="gs-content-with-sidebar">
        <div class="gs-container">
            <!-- Header -->
            <div class="gs-flex gs-items-center gs-justify-between gs-mb-xl">
                <h1 class="gs-h1 gs-text-primary">
                    <i data-lucide="upload"></i>
                    UCI Licensregister Import
                </h1>
            </div>

            <!-- Message -->
            <?php if ($message): ?>
                <div class="gs-alert gs-alert-<?= $messageType ?> gs-mb-lg">
                    <p><strong><?= h($message) ?></strong></p>

                    <?php if ($stats): ?>
                        <div class="gs-mt-md">
                            <?php if (isset($stats['separator_name'])): ?>
                                <p class="gs-text-sm gs-mb-sm" style="background: var(--gs-bg-secondary); padding: 0.5rem; border-radius: 4px;">
                                    🔍 <strong>Detekterad separator:</strong> <code style="background: var(--gs-bg); padding: 0.25rem 0.5rem; border-radius: 3px;"><?= h($stats['separator_name']) ?></code>
                                </p>
                            <?php endif; ?>
                            <p>📊 <strong>Statistik:</strong></p>
                            <ul style="margin-left: 1.5rem; margin-top: 0.5rem;">
                                <li>Totalt rader: <?= $stats['total'] ?></li>
                                <li>✅ Nya riders: <?= $stats['success'] ?></li>
                                <li>🔄 Uppdaterade: <?= $stats['updated'] ?></li>
                                <li>⏭️ Överhoppade: <?= $stats['skipped'] ?></li>
                                <li>❌ Misslyckade: <?= $stats['failed'] ?></li>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($updated_riders)): ?>
                        <details class="gs-mt-md">
                            <summary style="cursor: pointer;"><?= count($updated_riders) ?> uppdaterade riders</summary>
                            <ul style="margin-left: 1.5rem; margin-top: 0.5rem;">
                                <?php foreach (array_slice($updated_riders, 0, 20) as $rider): ?>
                                    <li><?= h($rider) ?></li>
                                <?php endforeach; ?>
                                <?php if (count($updated_riders) > 20): ?>
                                    <li><em>... och <?= count($updated_riders) - 20 ?> till</em></li>
                                <?php endif; ?>
                            </ul>
                        </details>
                    <?php endif; ?>

                    <?php if (!empty($errors)): ?>
                        <details class="gs-mt-md">
                            <summary style="cursor: pointer; color: var(--gs-danger);">⚠️ <?= count($errors) ?> fel</summary>
                            <ul style="margin-left: 1.5rem; margin-top: 0.5rem;">
                                <?php foreach (array_slice($errors, 0, 20) as $error): ?>
                                    <li><?= h($error) ?></li>
                                <?php endforeach; ?>
                                <?php if (count($errors) > 20): ?>
                                    <li><em>... och <?= count($errors) - 20 ?> fler fel</em></li>
                                <?php endif; ?>
                            </ul>
                        </details>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- Format Information -->
            <div class="gs-card gs-mb-lg">
                <div class="gs-card-header">
                    <h3 class="gs-h4">
                        <i data-lucide="info"></i>
                        UCI Licensregister Format
                    </h3>
                </div>
                <div class="gs-card-content">
                    <p class="gs-mb-md">Denna import hanterar CSV direkt från UCI Licensregister.</p>

                    <h4 class="gs-h5 gs-mb-sm gs-text-primary">📋 Kolumner (ingen header behövs):</h4>
                    <ol style="margin-left: 1.5rem; line-height: 1.8;">
                        <li><strong>Födelsedatum</strong> (YYYYMMDD-XXXX) → parsas till birth_year</li>
                        <li><strong>Förnamn</strong> → first_name</li>
                        <li><strong>Efternamn</strong> → last_name</li>
                        <li><strong>Land</strong> → ignoreras</li>
                        <li><strong>Epostadress</strong> → email</li>
                        <li><strong>Huvudförening</strong> → club_name (skapas automatiskt om den inte finns)</li>
                        <li><strong>Gren</strong> → discipline (MTB, Road, Track, BMX, CX, etc)</li>
                        <li><strong>Kategori</strong> → gender (Men → M, Women → F)</li>
                        <li><strong>Licenstyp</strong> → license_category (Master Men, Elite Men, Base License Men, etc)</li>
                        <li><strong>LicensÅr</strong> → license_valid_until (2025 → 2025-12-31)</li>
                        <li><strong>UCIKod</strong> → license_number (sparas exakt som det är, t.ex. "101 637 581 11")</li>
                    </ol>

                    <div class="gs-mt-lg gs-p-md" style="background: var(--gs-bg-secondary); border-radius: 8px;">
                        <p class="gs-text-secondary gs-text-sm gs-mb-sm"><strong>📄 Exempel på giltig rad:</strong></p>
                        <code style="font-size: 0.9rem; display: block; word-wrap: break-word;">
                            19400525-0651,Lars,Nordensson,Sverige,ernst@email.com,Ringmurens Cykelklubb,MTB,Men,Master Men,2025,101 637 581 11
                        </code>
                    </div>

                    <div class="gs-mt-md gs-p-md" style="background: var(--gs-success-bg); border-left: 4px solid var(--gs-success); border-radius: 4px;">
                        <p class="gs-text-sm">
                            <strong>✨ Automatiska funktioner:</strong><br>
                            • Personnummer parsas automatiskt (både YYYYMMDD-XXXX och YYMMDD-XXXX format)<br>
                            • Klubbar skapas automatiskt om de inte finns<br>
                            • UCI-koder sparas exakt med mellanslag (t.ex. "101 637 581 11")<br>
                            • Befintliga riders uppdateras om de hittas (via license_number eller namn+födelseår)<br>
                            • SWE-ID (SWE25XXXXX) genereras automatiskt för riders utan UCI-kod
                        </p>
                    </div>
                </div>
            </div>

            <!-- Upload Form -->
            <div class="gs-card">
                <div class="gs-card-header">
                    <h3 class="gs-h4">
                        <i data-lucide="upload"></i>
                        Ladda upp UCI-fil
                    </h3>
                </div>
                <div class="gs-card-content">
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">

                        <div class="gs-form-group">
                            <label class="gs-label">
                                <i data-lucide="file-text"></i>
                                CSV-fil från UCI Licensregister
                            </label>
                            <input type="file" name="uci_file" accept=".csv" class="gs-input" required>
                            <p class="gs-text-secondary gs-text-sm gs-mt-sm">
                                Endast CSV-filer. Max <?= MAX_UPLOAD_SIZE / 1024 / 1024 ?>MB.
                            </p>
                        </div>

                        <div class="gs-flex gs-gap-md">
                            <button type="submit" class="gs-btn gs-btn-primary">
                                <i data-lucide="upload"></i>
                                Importera från UCI
                            </button>
                            <a href="/admin/riders.php" class="gs-btn gs-btn-outline">
                                <i data-lucide="arrow-left"></i>
                                Tillbaka till riders
                            </a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Quick Links -->
            <div class="gs-grid gs-grid-cols-1 gs-md-grid-cols-2 gs-gap-lg gs-mt-lg">
                <div class="gs-card">
                    <div class="gs-card-content">
                        <h4 class="gs-h5 gs-mb-sm">
                            <i data-lucide="users"></i>
                            Andra importalternativ
                        </h4>
                        <p class="gs-text-secondary gs-text-sm gs-mb-md">
                            Om du vill använda en anpassad CSV-mall istället för UCI-format.
                        </p>
                        <a href="/admin/import-riders.php" class="gs-btn gs-btn-outline gs-btn-sm">
                            <i data-lucide="upload"></i>
                            Standard Rider Import
                        </a>
                    </div>
                </div>

                <div class="gs-card">
                    <div class="gs-card-content">
                        <h4 class="gs-h5 gs-mb-sm">
                            <i data-lucide="download"></i>
                            Ladda ner mallar
                        </h4>
                        <p class="gs-text-secondary gs-text-sm gs-mb-md">
                            Ladda ner CSV-mallar för standard import.
                        </p>
                        <a href="/admin/download-templates.php?template=riders" class="gs-btn gs-btn-outline gs-btn-sm">
                            <i data-lucide="download"></i>
                            Ladda ner Rider-mall
                        </a>
                    </div>
                </div>
            </div>
        </div>
<?php include __DIR__ . '/../includes/layout-footer.php'; ?>

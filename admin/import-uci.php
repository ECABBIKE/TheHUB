<?php
require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();
$current_admin = get_current_admin();

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
                    $result = importUCIRiders($uploaded, $db);

                    $stats = $result['stats'];
                    $errors = $result['errors'];
                    $updated_riders = $result['updated'];

                    if ($stats['success'] > 0 || $stats['updated'] > 0) {
                        $message = "Import klar! {$stats['success']} nya riders, {$stats['updated']} uppdaterade.";
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
}

/**
 * Auto-detect CSV separator
 */
function detectCsvSeparator($file_path) {
    $delimiters = [',', ';', "\t", '|'];
    $data = [];
    $file = fopen($file_path, 'r');
    $firstLine = fgets($file);
    fclose($file);

    foreach ($delimiters as $delimiter) {
        $row = str_getcsv($firstLine, $delimiter);
        $data[$delimiter] = count($row);
    }

    // Return delimiter with most columns
    return array_search(max($data), $data);
}

/**
 * Import riders from UCI CSV file
 */
function importUCIRiders($filepath, $db) {
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

    if (($handle = fopen($filepath, 'r')) === false) {
        throw new Exception('Kunde inte öppna filen');
    }

    // Auto-detect separator
    $separator = detectCsvSeparator($filepath);
    $stats['separator'] = $separator;
    $stats['separator_name'] = ($separator === "\t") ? 'TAB' : $separator;

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

            // Debug first row
            if ($stats['total'] === 1) {
                error_log("UCI Import - First row debug:");
                error_log("Separator: '" . $stats['separator_name'] . "'");
                error_log("Column count: " . count($row));
                error_log("Columns: " . print_r($row, true));
            }

            if (count($row) < 11) {
                $stats['failed']++;
                $errors[] = "Rad {$lineNumber}: För få kolumner (" . count($row) . " av 11)";
                continue;
            }

            // Extract data according to UCI format position
            $personnummer = trim($row[0]);
            $firstname = trim($row[1]);
            $lastname = trim($row[2]);
            $country = trim($row[3]); // Ignore, always Sweden
            $email = trim($row[4]);
            $club_name = trim($row[5]);
            $discipline = trim($row[6]);
            $gender_raw = trim($row[7]);
            $license_category = trim($row[8]);
            $license_year = trim($row[9]);
            $uci_code = trim($row[10]);

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

            // 3. UCI ID: Remove spaces, add SWE prefix
            $license_number = preg_replace('/\s+/', '', $uci_code);
            if (!empty($license_number) && !str_starts_with(strtoupper($license_number), 'SWE')) {
                $license_number = 'SWE' . $license_number;
            }

            // If no UCI code, generate SWE-ID
            if (empty($license_number)) {
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
                // Update existing rider
                $db->update('riders', $riderData, 'id = ?', [$existing['id']]);
                $stats['updated']++;
                $updated_riders[] = "{$firstname} {$lastname}";
            } else {
                // Insert new rider
                $db->insert('riders', $riderData);
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
        'errors' => $errors,
        'updated' => $updated_riders
    ];
}

$pageTitle = 'UCI Import';
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
                        <li><strong>UCIKod</strong> → license_number (mellanslag tas bort, SWE-prefix läggs till)</li>
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
                            • SWE-prefix läggs till på UCI-koder<br>
                            • Befintliga riders uppdateras om de hittas (via license_number eller namn+födelseår)<br>
                            • SWE-ID genereras automatiskt om UCI-kod saknas
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
    </main>

    <script src="https://unpkg.com/lucide@latest"></script>
    <script>
        lucide.createIcons();
    </script>
</body>
</html>

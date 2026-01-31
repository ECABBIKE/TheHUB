<?php
/**
 * Import E-postadresser - Uppdatera befintliga deltagare med e-post
 *
 * Matchningslogik:
 * 1. UCI ID + namn = 100% säker → automatisk uppdatering
 * 2. Namn + klubb = osäker → manuell granskning
 * 3. Endast namn = osäker → manuell granskning
 */
require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();

// ============================================================================
// HELPER FUNCTIONS
// ============================================================================

/**
 * Normalize UCI ID for comparison (remove spaces, convert to uppercase)
 */
function normalizeUciIdForMatch($uciId) {
    if (empty($uciId)) return null;
    // Remove all spaces, dashes, and convert to uppercase
    return strtoupper(preg_replace('/[\s\-]/', '', trim($uciId)));
}

/**
 * Normalize name for comparison
 */
function normalizeNameForMatch($name) {
    if (empty($name)) return '';
    $name = mb_strtolower(trim($name), 'UTF-8');
    // Replace Swedish characters
    $name = preg_replace('/[åä]/u', 'a', $name);
    $name = preg_replace('/[ö]/u', 'o', $name);
    $name = preg_replace('/[é]/u', 'e', $name);
    // Remove non-alphanumeric
    $name = preg_replace('/[^a-z0-9]/u', '', $name);
    return $name;
}

/**
 * Find matching riders in database
 *
 * AUTO-UPDATE cases (no manual review needed):
 * - exact_uci: UCI ID + name match (100%)
 * - name_club: Name + club match (90%)
 * - name_only_single: Only ONE rider with this name exists (85%)
 *
 * MANUAL REVIEW cases:
 * - ambiguous: Multiple riders with same name, no club match
 */
function findMatchingRider($db, $firstname, $lastname, $uciId = null, $clubName = null) {
    $normFirst = normalizeNameForMatch($firstname);
    $normLast = normalizeNameForMatch($lastname);
    $normUci = normalizeUciIdForMatch($uciId);

    // Strategy 1: Exact UCI ID match + name verification (100% - auto update)
    if ($normUci) {
        $riders = $db->getAll("
            SELECT r.id, r.firstname, r.lastname, r.email, r.license_number,
                   r.birth_year, r.nationality, c.name as club_name
            FROM riders r
            LEFT JOIN clubs c ON r.club_id = c.id
            WHERE r.license_number IS NOT NULL
              AND r.license_number != ''
        ");

        foreach ($riders as $rider) {
            $riderUci = normalizeUciIdForMatch($rider['license_number']);
            if ($riderUci === $normUci) {
                $riderNormFirst = normalizeNameForMatch($rider['firstname']);
                $riderNormLast = normalizeNameForMatch($rider['lastname']);

                if ($riderNormFirst === $normFirst && $riderNormLast === $normLast) {
                    return [
                        'match_type' => 'exact_uci',
                        'confidence' => 100,
                        'rider' => $rider,
                        'reason' => 'UCI ID + namn matchar exakt'
                    ];
                } else {
                    // UCI matches but name doesn't - needs review
                    return [
                        'match_type' => 'ambiguous',
                        'confidence' => 50,
                        'matches' => [[
                            'match_type' => 'uci_name_mismatch',
                            'confidence' => 50,
                            'rider' => $rider,
                            'reason' => 'UCI ID matchar men namn skiljer sig'
                        ]],
                        'reason' => 'UCI ID matchar men namn skiljer sig'
                    ];
                }
            }
        }
    }

    // Get all riders with matching name
    $riders = $db->getAll("
        SELECT r.id, r.firstname, r.lastname, r.email, r.license_number,
               r.birth_year, r.nationality, c.name as club_name
        FROM riders r
        LEFT JOIN clubs c ON r.club_id = c.id
        WHERE LOWER(r.firstname) = LOWER(?)
          AND LOWER(r.lastname) = LOWER(?)
    ", [$firstname, $lastname]);

    if (empty($riders)) {
        return [
            'match_type' => 'no_match',
            'confidence' => 0,
            'rider' => null,
            'reason' => 'Ingen matchning hittad'
        ];
    }

    // Strategy 2: Name + Club match (90% - auto update)
    if (!empty($clubName)) {
        $clubNorm = normalizeNameForMatch($clubName);

        foreach ($riders as $rider) {
            if (!empty($rider['club_name'])) {
                $riderClubNorm = normalizeNameForMatch($rider['club_name']);
                // Check if clubs match (fuzzy)
                if ($riderClubNorm === $clubNorm ||
                    strpos($riderClubNorm, $clubNorm) !== false ||
                    strpos($clubNorm, $riderClubNorm) !== false) {
                    return [
                        'match_type' => 'name_club',
                        'confidence' => 90,
                        'rider' => $rider,
                        'reason' => 'Namn + klubb matchar'
                    ];
                }
            }
        }
    }

    // Strategy 3: Only ONE rider with this name exists (85% - auto update)
    if (count($riders) === 1) {
        return [
            'match_type' => 'name_only_single',
            'confidence' => 85,
            'rider' => $riders[0],
            'reason' => 'Unik namnmatchning (endast en person med detta namn)'
        ];
    }

    // Strategy 4: Multiple riders with same name - needs manual review
    $matches = [];
    foreach ($riders as $rider) {
        $matches[] = [
            'match_type' => 'name_only',
            'confidence' => 60,
            'rider' => $rider,
            'reason' => 'Flera personer med samma namn'
        ];
    }

    return [
        'match_type' => 'ambiguous',
        'confidence' => 60,
        'matches' => $matches,
        'reason' => count($matches) . ' personer med samma namn'
    ];
}

// ============================================================================
// PROCESS ACTIONS
// ============================================================================

$message = '';
$messageType = '';
$stats = null;
$autoUpdated = [];
$needsReview = [];
$noMatch = [];

// Clear session on fresh page load (GET request without any action)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !isset($_GET['keep'])) {
    unset($_SESSION['email_import_review']);
}

// Handle clear action
if (isset($_GET['clear'])) {
    unset($_SESSION['email_import_review']);
    header('Location: /admin/import-emails.php');
    exit;
}

// Handle manual confirmation of pending updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'confirm_updates') {
    checkCsrf();

    $confirmed = $_POST['confirm'] ?? [];
    $nationalities = $_POST['nationality'] ?? [];
    $updatedCount = 0;
    $skippedCount = 0;
    $nationalityCount = 0;

    foreach ($confirmed as $riderId => $email) {
        if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            try {
                $updateData = ['email' => $email];

                // Also update nationality if provided
                if (!empty($nationalities[$riderId])) {
                    $updateData['nationality'] = $nationalities[$riderId];
                    $nationalityCount++;
                }

                $db->update('riders', $updateData, 'id = ?', [$riderId]);
                $updatedCount++;
            } catch (Exception $e) {
                $skippedCount++;
            }
        } else {
            $skippedCount++;
        }
    }

    $message = "Uppdaterade $updatedCount e-postadresser" .
        ($nationalityCount > 0 ? ", $nationalityCount nationaliteter" : "") .
        ($skippedCount > 0 ? ", $skippedCount överhoppade" : "");
    $messageType = 'success';

    // Clear session data
    unset($_SESSION['email_import_review']);
}

// Handle CSV/ZIP upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['import_file'])) {
    checkCsrf();

    $file = $_FILES['import_file'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $message = 'Filuppladdning misslyckades';
        $messageType = 'error';
    } elseif ($file['size'] > MAX_UPLOAD_SIZE * 10) { // Allow larger zip files
        $message = 'Filen är för stor (max ' . (MAX_UPLOAD_SIZE * 10 / 1024 / 1024) . 'MB)';
        $messageType = 'error';
    } else {
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!in_array($extension, ['csv', 'txt', 'zip'])) {
            $message = 'Ogiltigt filformat. Tillåtna: CSV, TXT, ZIP';
            $messageType = 'error';
        } else {
            // Initialize stats
            $stats = [
                'total' => 0,
                'auto_updated' => 0,
                'needs_review' => 0,
                'no_match' => 0,
                'already_has_email' => 0,
                'empty_email' => 0,
                'invalid_email' => 0,
                'nationality_updated' => 0,
                'files_processed' => 0,
            ];

            $filesToProcess = [];

            if ($extension === 'zip') {
                // Extract CSV files from ZIP
                $zip = new ZipArchive();
                if ($zip->open($file['tmp_name']) === TRUE) {
                    $tempDir = sys_get_temp_dir() . '/email_import_' . uniqid();
                    mkdir($tempDir, 0755, true);

                    for ($i = 0; $i < $zip->numFiles; $i++) {
                        $filename = $zip->getNameIndex($i);
                        $fileExt = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

                        if (in_array($fileExt, ['csv', 'txt'])) {
                            $zip->extractTo($tempDir, $filename);
                            $extractedPath = $tempDir . '/' . $filename;
                            if (file_exists($extractedPath)) {
                                $filesToProcess[] = [
                                    'path' => $extractedPath,
                                    'name' => basename($filename)
                                ];
                            }
                        }
                    }
                    $zip->close();

                    if (empty($filesToProcess)) {
                        $message = 'Ingen CSV-fil hittades i ZIP-arkivet';
                        $messageType = 'error';
                    }
                } else {
                    $message = 'Kunde inte öppna ZIP-filen';
                    $messageType = 'error';
                }
            } else {
                // Single CSV file
                $filesToProcess[] = [
                    'path' => $file['tmp_name'],
                    'name' => $file['name']
                ];
            }

            // Process all CSV files
            foreach ($filesToProcess as $csvFile) {
                $filepath = $csvFile['path'];

                // Ensure UTF-8
                $content = file_get_contents($filepath);
                $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
                if (!mb_check_encoding($content, 'UTF-8')) {
                    $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
                }
                file_put_contents($filepath, $content);

                if (($handle = fopen($filepath, 'r')) !== false) {
                    // Detect delimiter
                    $firstLine = fgets($handle);
                    rewind($handle);

                    $tabCount = substr_count($firstLine, "\t");
                    $semiCount = substr_count($firstLine, ';');
                    $commaCount = substr_count($firstLine, ',');

                    if ($tabCount > $semiCount && $tabCount > $commaCount) {
                        $delimiter = "\t";
                    } elseif ($semiCount > $commaCount) {
                        $delimiter = ';';
                    } else {
                        $delimiter = ',';
                    }

                    // Read header
                    $header = fgetcsv($handle, 4096, $delimiter);
                if (!$header) {
                    $message = 'Tom fil eller ogiltigt format';
                    $messageType = 'error';
                } else {
                    // Normalize header
                    $headerMap = [];
                    foreach ($header as $idx => $col) {
                        $col = mb_strtolower(trim($col), 'UTF-8');
                        $col = str_replace([' ', '-', '_'], '', $col);

                        // Map column names
                        $mappings = [
                            // Standard formats
                            'firstname' => 'firstname', 'förnamn' => 'firstname', 'fornamn' => 'firstname',
                            'lastname' => 'lastname', 'efternamn' => 'lastname',
                            'email' => 'email', 'epost' => 'email', 'epostadress' => 'email', 'mail' => 'email',
                            'klubb' => 'club', 'club' => 'club', 'huvudförening' => 'club', 'huvudforening' => 'club',
                            'uciid' => 'uci_id', 'ucikod' => 'uci_id', 'licensnumber' => 'uci_id', 'licensnummer' => 'uci_id',
                            'nationality' => 'nationality', 'land' => 'nationality', 'nationalitet' => 'nationality',
                            'adress' => 'address', 'address' => 'address',
                            'postcode' => 'postcode', 'postnummer' => 'postcode',
                            'city' => 'city', 'ort' => 'city', 'stad' => 'city',
                            'phone' => 'phone', 'telefon' => 'phone', 'tel' => 'phone',

                            // Ticket/Event registration format (Jetveo/WooCommerce)
                            'attendeefirstname' => 'firstname',
                            'attendeelastname' => 'lastname',
                            'attendeeemail' => 'email',
                            'attendeetelephone' => 'phone',
                        ];

                        $mapped = $mappings[$col] ?? $col;

                        // Handle long ticket format column names (partial matching)
                        if ($mapped === $col) {
                            // Cykelklubb column
                            if (strpos($col, 'cykelklubb') !== false) {
                                $mapped = 'club';
                            }
                            // UCI-ID column (check for uciid pattern)
                            elseif (strpos($col, 'uciid') !== false || (strpos($col, 'uci') !== false && strpos($col, 'licens') !== false)) {
                                $mapped = 'uci_id';
                            }
                        }

                        $headerMap[$mapped] = $idx;
                    }

                    // Check required columns
                    if (!isset($headerMap['firstname']) || !isset($headerMap['lastname']) || !isset($headerMap['email'])) {
                        // Skip this file - missing required columns
                        fclose($handle);
                        continue;
                    }

                    // Process rows
                    $stats['files_processed']++;
                    $lineNumber = 1;
                        while (($row = fgetcsv($handle, 4096, $delimiter)) !== false) {
                            $lineNumber++;
                            $stats['total']++;

                            // Extract data
                            $firstname = isset($headerMap['firstname']) ? trim($row[$headerMap['firstname']] ?? '') : '';
                            $lastname = isset($headerMap['lastname']) ? trim($row[$headerMap['lastname']] ?? '') : '';
                            $email = isset($headerMap['email']) ? trim($row[$headerMap['email']] ?? '') : '';
                            $club = isset($headerMap['club']) ? trim($row[$headerMap['club']] ?? '') : '';
                            $uciId = isset($headerMap['uci_id']) ? trim($row[$headerMap['uci_id']] ?? '') : '';
                            $nationality = isset($headerMap['nationality']) ? strtoupper(trim($row[$headerMap['nationality']] ?? '')) : '';

                            // Filter out placeholder UCI IDs (ticket format uses "1" for non-license holders)
                            if ($uciId === '1' || $uciId === '0' || $uciId === '-') {
                                $uciId = '';
                            }

                            // Normalize nationality to 3-letter code
                            if (!empty($nationality)) {
                                $nationalityMap = [
                                    'SVERIGE' => 'SWE', 'SWEDEN' => 'SWE', 'SE' => 'SWE',
                                    'NORGE' => 'NOR', 'NORWAY' => 'NOR', 'NO' => 'NOR',
                                    'DANMARK' => 'DEN', 'DENMARK' => 'DEN', 'DK' => 'DEN',
                                    'FINLAND' => 'FIN', 'FI' => 'FIN',
                                    'TYSKLAND' => 'GER', 'GERMANY' => 'GER', 'DE' => 'GER',
                                    'STORBRITANNIEN' => 'GBR', 'UK' => 'GBR', 'GB' => 'GBR',
                                    'USA' => 'USA', 'US' => 'USA',
                                    'ISLAND' => 'ISL', 'ICELAND' => 'ISL', 'IS' => 'ISL',
                                    'ESTLAND' => 'EST', 'ESTONIA' => 'EST', 'EE' => 'EST',
                                    'LETTLAND' => 'LAT', 'LATVIA' => 'LAT', 'LV' => 'LAT',
                                    'LITAUEN' => 'LTU', 'LITHUANIA' => 'LTU', 'LT' => 'LTU',
                                    'POLEN' => 'POL', 'POLAND' => 'POL', 'PL' => 'POL',
                                    'FRANKRIKE' => 'FRA', 'FRANCE' => 'FRA', 'FR' => 'FRA',
                                    'SPANIEN' => 'ESP', 'SPAIN' => 'ESP', 'ES' => 'ESP',
                                    'ITALIEN' => 'ITA', 'ITALY' => 'ITA', 'IT' => 'ITA',
                                    'SCHWEIZ' => 'SUI', 'SWITZERLAND' => 'SUI', 'CH' => 'SUI',
                                    'ÖSTERRIKE' => 'AUT', 'AUSTRIA' => 'AUT', 'AT' => 'AUT',
                                    'NEDERLÄNDERNA' => 'NED', 'NETHERLANDS' => 'NED', 'NL' => 'NED',
                                    'BELGIEN' => 'BEL', 'BELGIUM' => 'BEL', 'BE' => 'BEL',
                                ];
                                $nationality = $nationalityMap[$nationality] ?? (strlen($nationality) === 3 ? $nationality : '');
                            }

                            // Skip if no name
                            if (empty($firstname) || empty($lastname)) {
                                continue;
                            }

                            // Validate email
                            if (empty($email)) {
                                $stats['empty_email']++;
                                continue;
                            }

                            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                                $stats['invalid_email']++;
                                continue;
                            }

                            // Find matching rider
                            $match = findMatchingRider($db, $firstname, $lastname, $uciId, $club);

                            $rowData = [
                                'line' => $lineNumber,
                                'firstname' => $firstname,
                                'lastname' => $lastname,
                                'email' => $email,
                                'club' => $club,
                                'uci_id' => $uciId,
                                'nationality' => $nationality,
                            ];

                            // Auto-update for high-confidence matches
                            $autoUpdateTypes = ['exact_uci', 'name_club', 'name_only_single'];

                            if (in_array($match['match_type'], $autoUpdateTypes)) {
                                // Check if rider ALREADY has an email - skip if so!
                                $existingEmail = $match['rider']['email'] ?? '';
                                if (!empty($existingEmail)) {
                                    // Already has email - skip (but still update nationality if needed)
                                    $stats['already_has_email']++;

                                    // Still update nationality if different
                                    if (!empty($nationality)) {
                                        $currentNat = $match['rider']['nationality'] ?? '';
                                        if (empty($currentNat) || $currentNat !== $nationality) {
                                            try {
                                                $db->update('riders', ['nationality' => $nationality], 'id = ?', [$match['rider']['id']]);
                                                $stats['nationality_updated']++;
                                            } catch (Exception $e) {
                                                // Ignore nationality update errors
                                            }
                                        }
                                    }
                                    continue;
                                }

                                // No email yet - add it
                                try {
                                    $updateData = ['email' => $email];
                                    $nationalityUpdated = false;

                                    // Also update nationality if provided and different
                                    if (!empty($nationality)) {
                                        $currentNat = $match['rider']['nationality'] ?? '';
                                        if (empty($currentNat) || $currentNat !== $nationality) {
                                            $updateData['nationality'] = $nationality;
                                            $nationalityUpdated = true;
                                            $stats['nationality_updated']++;
                                        }
                                    }

                                    $db->update('riders', $updateData, 'id = ?', [$match['rider']['id']]);
                                    $stats['auto_updated']++;
                                    $autoUpdated[] = array_merge($rowData, [
                                        'rider_id' => $match['rider']['id'],
                                        'old_email' => '',
                                        'old_nationality' => $match['rider']['nationality'] ?? '',
                                        'nationality_updated' => $nationalityUpdated,
                                        'rider_club' => $match['rider']['club_name'] ?? '',
                                        'match_type' => $match['match_type'],
                                        'confidence' => $match['confidence'],
                                    ]);
                                } catch (Exception $e) {
                                    // Failed - add to review
                                    $stats['needs_review']++;
                                    $needsReview[] = array_merge($rowData, [
                                        'matches' => [$match],
                                        'error' => $e->getMessage(),
                                    ]);
                                }
                            } elseif ($match['match_type'] === 'no_match') {
                                // No match found - silently skip (likely parents, not in database)
                                $stats['no_match']++;
                            } elseif ($match['match_type'] === 'ambiguous') {
                                // Multiple people with same name - filter to only those WITHOUT email
                                $matchesWithoutEmail = array_filter($match['matches'], function($m) {
                                    return empty($m['rider']['email']);
                                });

                                if (empty($matchesWithoutEmail)) {
                                    // All matches already have email - skip
                                    $stats['already_has_email']++;
                                } elseif (count($matchesWithoutEmail) === 1) {
                                    // Only ONE without email - auto-update that one
                                    $singleMatch = array_values($matchesWithoutEmail)[0];
                                    try {
                                        $updateData = ['email' => $email];
                                        $nationalityUpdated = false;
                                        if (!empty($nationality)) {
                                            $currentNat = $singleMatch['rider']['nationality'] ?? '';
                                            if (empty($currentNat) || $currentNat !== $nationality) {
                                                $updateData['nationality'] = $nationality;
                                                $nationalityUpdated = true;
                                                $stats['nationality_updated']++;
                                            }
                                        }
                                        $db->update('riders', $updateData, 'id = ?', [$singleMatch['rider']['id']]);
                                        $stats['auto_updated']++;
                                        $autoUpdated[] = array_merge($rowData, [
                                            'rider_id' => $singleMatch['rider']['id'],
                                            'old_email' => '',
                                            'old_nationality' => $singleMatch['rider']['nationality'] ?? '',
                                            'nationality_updated' => $nationalityUpdated,
                                            'rider_club' => $singleMatch['rider']['club_name'] ?? '',
                                            'match_type' => 'ambiguous_resolved',
                                            'confidence' => 75,
                                        ]);
                                    } catch (Exception $e) {
                                        $stats['needs_review']++;
                                        $needsReview[] = array_merge($rowData, [
                                            'matches' => $matchesWithoutEmail,
                                        ]);
                                    }
                                } else {
                                    // Multiple without email - needs manual review
                                    $stats['needs_review']++;
                                    $needsReview[] = array_merge($rowData, [
                                        'matches' => array_values($matchesWithoutEmail),
                                    ]);
                                }
                            } else {
                                // Fallback - needs review
                                $stats['needs_review']++;
                                $matches = isset($match['matches']) ? $match['matches'] : [$match];
                                $needsReview[] = array_merge($rowData, [
                                    'matches' => $matches,
                                ]);
                            }
                        }

                    fclose($handle);
                }
                } // End fopen if
            } // End foreach filesToProcess

            // Cleanup temp directory if ZIP was used
            if ($extension === 'zip' && isset($tempDir) && is_dir($tempDir)) {
                array_map('unlink', glob("$tempDir/*.*"));
                array_map('unlink', glob("$tempDir/*/*.*"));
                @rmdir($tempDir);
            }

            // Store review data in session
            if (!empty($needsReview)) {
                $_SESSION['email_import_review'] = $needsReview;
            }

            // Build message
            if ($stats['files_processed'] > 0) {
                $fileMsg = $stats['files_processed'] > 1 ? " ({$stats['files_processed']} filer)" : "";
                $message = "Bearbetade {$stats['total']} rader{$fileMsg}. {$stats['auto_updated']} automatiskt uppdaterade, {$stats['needs_review']} behöver granskning.";
                $messageType = 'success';
            } else {
                $message = $message ?: 'Inga filer kunde bearbetas';
                $messageType = $messageType ?: 'error';
            }
        }
    }
}

// Get pending reviews from session
$pendingReview = $_SESSION['email_import_review'] ?? [];

// Page setup
$page_title = 'Importera E-postadresser';
$breadcrumbs = [
    ['label' => 'Verktyg', 'url' => '/admin/tools.php'],
    ['label' => 'Import E-post']
];

include __DIR__ . '/components/unified-layout.php';
?>

<style>
.match-card {
    background: var(--color-bg-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    padding: var(--space-md);
    margin-bottom: var(--space-sm);
}
.match-card.selected {
    border-color: var(--color-accent);
    background: var(--color-accent-light);
}
.confidence-badge {
    display: inline-flex;
    align-items: center;
    gap: var(--space-xs);
    padding: var(--space-2xs) var(--space-sm);
    border-radius: var(--radius-full);
    font-size: var(--text-xs);
    font-weight: 600;
}
.confidence-100 { background: rgba(16, 185, 129, 0.15); color: #059669; }
.confidence-80 { background: rgba(245, 158, 11, 0.15); color: #d97706; }
.confidence-60 { background: rgba(239, 68, 68, 0.15); color: #dc2626; }
.review-row {
    background: var(--color-bg-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    padding: var(--space-md);
    margin-bottom: var(--space-md);
}
.review-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: var(--space-md);
    padding-bottom: var(--space-md);
    border-bottom: 1px solid var(--color-border);
}
.import-data {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: var(--space-sm);
    font-size: var(--text-sm);
}
.import-data dt { color: var(--color-text-muted); }
.import-data dd { color: var(--color-text-primary); font-weight: 500; margin: 0 0 var(--space-xs); }
</style>

<!-- Message -->
<?php if ($message): ?>
<div class="alert alert-<?= h($messageType) ?> mb-lg">
    <i data-lucide="<?= $messageType === 'success' ? 'check-circle' : 'alert-circle' ?>"></i>
    <?= h($message) ?>
</div>
<?php endif; ?>

<!-- Statistics -->
<?php if ($stats): ?>
<div class="card mb-lg">
    <div class="card-header">
        <h3><i data-lucide="bar-chart-2"></i> Resultat</h3>
    </div>
    <div class="card-body">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: var(--space-md);">
            <?php if (($stats['files_processed'] ?? 0) > 1): ?>
            <div class="text-center">
                <div style="font-size: var(--text-2xl); font-weight: 700;"><?= number_format($stats['files_processed']) ?></div>
                <div class="text-secondary text-sm">Filer bearbetade</div>
            </div>
            <?php endif; ?>
            <div class="text-center">
                <div style="font-size: var(--text-2xl); font-weight: 700;"><?= number_format($stats['total']) ?></div>
                <div class="text-secondary text-sm">Totalt rader</div>
            </div>
            <div class="text-center">
                <div style="font-size: var(--text-2xl); font-weight: 700; color: var(--color-success);"><?= number_format($stats['auto_updated']) ?></div>
                <div class="text-secondary text-sm">Auto-uppdaterade</div>
            </div>
            <?php if ($stats['nationality_updated'] > 0): ?>
            <div class="text-center">
                <div style="font-size: var(--text-2xl); font-weight: 700; color: var(--color-info);"><?= number_format($stats['nationality_updated']) ?></div>
                <div class="text-secondary text-sm">Nationalitet uppdaterad</div>
            </div>
            <?php endif; ?>
            <div class="text-center">
                <div style="font-size: var(--text-2xl); font-weight: 700; color: var(--color-warning);"><?= number_format($stats['needs_review']) ?></div>
                <div class="text-secondary text-sm">Behöver granskning</div>
            </div>
            <div class="text-center">
                <div style="font-size: var(--text-2xl); font-weight: 700; color: var(--color-text-muted);"><?= number_format($stats['already_has_email'] ?? 0) ?></div>
                <div class="text-secondary text-sm">Redan har e-post</div>
            </div>
            <div class="text-center">
                <div style="font-size: var(--text-2xl); font-weight: 700; color: var(--color-text-muted);"><?= number_format($stats['no_match']) ?></div>
                <div class="text-secondary text-sm">Ej i databasen</div>
            </div>
            <?php if ($stats['empty_email'] > 0 || $stats['invalid_email'] > 0): ?>
            <div class="text-center">
                <div style="font-size: var(--text-2xl); font-weight: 700; color: var(--color-error);"><?= number_format($stats['empty_email'] + $stats['invalid_email']) ?></div>
                <div class="text-secondary text-sm">Ogiltig/saknad e-post</div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Auto-updated list -->
<?php if (!empty($autoUpdated)): ?>
<div class="card mb-lg">
    <div class="card-header">
        <h3><i data-lucide="check-circle" style="color: var(--color-success);"></i> Automatiskt uppdaterade (<?= count($autoUpdated) ?>)</h3>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Namn</th>
                        <th>E-post</th>
                        <th>Tidigare</th>
                        <th>Matchningstyp</th>
                        <th>Nat</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (array_slice($autoUpdated, 0, 100) as $row): ?>
                    <tr>
                        <td><strong><?= h($row['firstname'] . ' ' . $row['lastname']) ?></strong></td>
                        <td><code class="text-sm"><?= h($row['email']) ?></code></td>
                        <td class="text-secondary text-sm"><?= h($row['old_email'] ?: '-') ?></td>
                        <td>
                            <?php
                            $matchType = $row['match_type'] ?? 'exact_uci';
                            $conf = $row['confidence'] ?? 100;
                            $badgeClass = $conf >= 100 ? 'badge-success' : ($conf >= 85 ? 'badge-info' : 'badge-warning');
                            $label = match($matchType) {
                                'exact_uci' => 'UCI+Namn',
                                'name_club' => 'Namn+Klubb',
                                'name_only_single' => 'Unikt namn',
                                'ambiguous_resolved' => 'Enda utan e-post',
                                default => $matchType
                            };
                            ?>
                            <span class="badge <?= $badgeClass ?>"><?= h($label) ?></span>
                        </td>
                        <td>
                            <?php if (!empty($row['nationality_updated']) && $row['nationality_updated']): ?>
                                <span class="badge badge-info"><?= h($row['nationality']) ?></span>
                            <?php else: ?>
                                <?= h($row['nationality'] ?: '-') ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php if (count($autoUpdated) > 50): ?>
            <p class="text-secondary text-sm mt-md">Visar första 50 av <?= count($autoUpdated) ?></p>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Needs Review -->
<?php if (!empty($pendingReview)): ?>
<div class="card mb-lg">
    <div class="card-header">
        <h3><i data-lucide="eye" style="color: var(--color-warning);"></i> Behöver granskning (<?= count($pendingReview) ?>)</h3>
    </div>
    <div class="card-body">
        <form method="POST" id="reviewForm">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="confirm_updates">

            <div class="mb-lg">
                <p class="text-secondary">
                    Granska matchningarna nedan. Kryssa i de som ska uppdateras.
                </p>
            </div>

            <?php foreach ($pendingReview as $idx => $row): ?>
            <div class="review-row">
                <div class="review-header">
                    <div>
                        <h4 style="margin: 0;"><?= h($row['firstname'] . ' ' . $row['lastname']) ?></h4>
                        <p class="text-secondary text-sm" style="margin: var(--space-xs) 0 0;">
                            Rad <?= $row['line'] ?>
                            <?php if (!empty($row['club'])): ?> • Klubb: <?= h($row['club']) ?><?php endif; ?>
                            <?php if (!empty($row['uci_id'])): ?> • UCI: <?= h($row['uci_id']) ?><?php endif; ?>
                        </p>
                    </div>
                    <div>
                        <span class="badge badge-info"><?= h($row['email']) ?></span>
                    </div>
                </div>

                <div class="import-data mb-md">
                    <div>
                        <dt>E-post att importera</dt>
                        <dd><code><?= h($row['email']) ?></code></dd>
                    </div>
                    <?php if (!empty($row['club'])): ?>
                    <div>
                        <dt>Klubb i filen</dt>
                        <dd><?= h($row['club']) ?></dd>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($row['uci_id'])): ?>
                    <div>
                        <dt>UCI ID i filen</dt>
                        <dd><code><?= h($row['uci_id']) ?></code></dd>
                    </div>
                    <?php endif; ?>
                </div>

                <h5 style="margin: var(--space-md) 0 var(--space-sm);">Möjliga matchningar:</h5>

                <?php
                $matches = $row['matches'] ?? [];
                foreach ($matches as $mIdx => $match):
                    $rider = $match['rider'];
                    $confidence = $match['confidence'];
                    $confClass = $confidence >= 100 ? 'confidence-100' : ($confidence >= 80 ? 'confidence-80' : 'confidence-60');
                ?>
                <div class="match-card">
                    <label style="display: flex; align-items: flex-start; gap: var(--space-md); cursor: pointer;">
                        <input type="checkbox"
                               name="confirm[<?= $rider['id'] ?>]"
                               value="<?= h($row['email']) ?>"
                               style="margin-top: 4px;">
                        <?php if (!empty($row['nationality'])): ?>
                        <input type="hidden" name="nationality[<?= $rider['id'] ?>]" value="<?= h($row['nationality']) ?>">
                        <?php endif; ?>
                        <div style="flex: 1;">
                            <div style="display: flex; align-items: center; gap: var(--space-sm); margin-bottom: var(--space-xs);">
                                <strong><?= h($rider['firstname'] . ' ' . $rider['lastname']) ?></strong>
                                <span class="confidence-badge <?= $confClass ?>"><?= $confidence ?>%</span>
                            </div>
                            <div class="text-sm text-secondary">
                                <?= h($match['reason']) ?>
                            </div>
                            <div class="text-sm" style="margin-top: var(--space-xs);">
                                <span class="text-secondary">Nuvarande e-post:</span>
                                <?php if ($rider['email']): ?>
                                    <code><?= h($rider['email']) ?></code>
                                <?php else: ?>
                                    <em class="text-muted">Ingen</em>
                                <?php endif; ?>

                                <?php if ($rider['club_name']): ?>
                                    <span class="text-secondary" style="margin-left: var(--space-md);">Klubb:</span>
                                    <?= h($rider['club_name']) ?>
                                <?php endif; ?>

                                <?php if ($rider['license_number']): ?>
                                    <span class="text-secondary" style="margin-left: var(--space-md);">UCI:</span>
                                    <code class="text-sm"><?= h($rider['license_number']) ?></code>
                                <?php endif; ?>

                                <?php if (!empty($row['nationality'])): ?>
                                    <span class="text-secondary" style="margin-left: var(--space-md);">Nat:</span>
                                    <?php
                                    $riderNat = $rider['nationality'] ?? '';
                                    if ($riderNat !== $row['nationality']): ?>
                                        <span class="badge badge-info"><?= h($row['nationality']) ?></span>
                                        <span class="text-muted">(nu: <?= h($riderNat ?: '-') ?>)</span>
                                    <?php else: ?>
                                        <?= h($row['nationality']) ?>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </label>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endforeach; ?>

            <div style="position: sticky; bottom: 0; background: var(--color-bg-page); padding: var(--space-md) 0; border-top: 1px solid var(--color-border); margin-top: var(--space-lg);">
                <button type="submit" class="btn-admin btn-admin-primary">
                    <i data-lucide="check"></i>
                    Uppdatera valda
                </button>
                <button type="button" class="btn-admin btn-admin-secondary" onclick="document.querySelectorAll('input[name^=confirm]').forEach(c => c.checked = true);">
                    Markera alla
                </button>
                <button type="button" class="btn-admin btn-admin-ghost" onclick="document.querySelectorAll('input[name^=confirm]').forEach(c => c.checked = false);">
                    Avmarkera alla
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- No Match section removed - these are likely parents not in database -->

<!-- Upload Form -->
<div class="card">
    <div class="card-header">
        <h3><i data-lucide="upload"></i> Ladda upp CSV-fil</h3>
    </div>
    <div class="card-body">
        <form method="POST" enctype="multipart/form-data" style="max-width: 500px;">
            <?= csrf_field() ?>

            <div class="form-group">
                <label class="form-label">Välj CSV/TXT/ZIP-fil</label>
                <input type="file" name="import_file" class="form-input" accept=".csv,.txt,.zip" required>
                <small class="text-secondary">Max storlek: <?= round(MAX_UPLOAD_SIZE * 10 / 1024 / 1024) ?>MB. Stödjer CSV/TXT (tab-, semikolon-, kommaseparerad) och ZIP med flera CSV-filer.</small>
            </div>

            <button type="submit" class="btn-admin btn-admin-primary">
                <i data-lucide="upload"></i>
                Ladda upp och bearbeta
            </button>
        </form>

        <div class="mt-lg" style="padding-top: var(--space-lg); border-top: 1px solid var(--color-border);">
            <h4>Förväntat filformat</h4>
            <p class="text-secondary mb-md">
                Filen ska ha följande kolumner (ordningen spelar ingen roll):
            </p>

            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Kolumn</th>
                            <th>Obligatorisk</th>
                            <th>Beskrivning</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><code>first_name</code> / <code>Förnamn</code></td>
                            <td><span class="badge badge-danger">Ja</span></td>
                            <td>Förnamn</td>
                        </tr>
                        <tr>
                            <td><code>last_name</code> / <code>Efternamn</code></td>
                            <td><span class="badge badge-danger">Ja</span></td>
                            <td>Efternamn</td>
                        </tr>
                        <tr>
                            <td><code>E-mail</code> / <code>Email</code></td>
                            <td><span class="badge badge-danger">Ja</span></td>
                            <td>E-postadress att importera</td>
                        </tr>
                        <tr>
                            <td><code>UCI ID</code> / <code>Licensnummer</code></td>
                            <td><span class="badge badge-success">Rekommenderas</span></td>
                            <td>För 100% säker matchning</td>
                        </tr>
                        <tr>
                            <td><code>Klubb</code> / <code>Club</code></td>
                            <td><span class="badge badge-secondary">Valfri</span></td>
                            <td>Hjälper till vid matchning</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="alert alert-info mt-md">
                <i data-lucide="info"></i>
                <div>
                    <strong>Matchningslogik:</strong>
                    <ol style="margin: var(--space-sm) 0 0; padding-left: var(--space-lg);">
                        <li><strong>UCI ID + namn</strong> → 100% säker matchning → automatisk uppdatering</li>
                        <li><strong>Namn + klubb</strong> → 80% säker → kräver manuell granskning</li>
                        <li><strong>Endast namn</strong> → 60% säker → kräver manuell granskning</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>

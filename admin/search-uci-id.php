<?php
/**
 * Search UCI-ID Tool
 * Upload CSV to find and fill in License Numbers
 * Version: v1.0.0 [2025-11-22-001]
 */

require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();
$results = [];
$headers = [];
$matchStats = ['exact' => 0, 'fuzzy' => 0, 'partial' => 0, 'not_found' => 0];
$message = '';
$messageType = '';

// Column mapping defaults
$colMapping = [
    'firstname' => 0,
    'lastname' => 1,
    'club' => 2,
    'class' => 3,
    'license' => 4
];

// Handle CSV export
if (isset($_GET['export']) && !empty($_SESSION['lookup_results'])) {
    $exportData = $_SESSION['lookup_results'];
    $exportHeaders = $_SESSION['lookup_headers'];

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="riders_with_license.csv"');

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM

    // Write header
    fputcsv($output, $exportHeaders, ';');

    // Write data
    foreach ($exportData as $row) {
        fputcsv($output, $row, ';');
    }

    fclose($output);
    exit;
}

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    checkCsrf();

    $file = $_FILES['csv_file'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $message = 'Filuppladdning misslyckades';
        $messageType = 'error';
    } else {
        $content = file_get_contents($file['tmp_name']);

        // Detect encoding and convert to UTF-8
        $encoding = mb_detect_encoding($content, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true);
        if ($encoding && $encoding !== 'UTF-8') {
            $content = mb_convert_encoding($content, 'UTF-8', $encoding);
        }

        // Remove BOM if present
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);

        $lines = explode("\n", $content);

        // Detect separator
        $firstLine = $lines[0] ?? '';
        $separator = ';';
        if (substr_count($firstLine, ',') > substr_count($firstLine, ';')) {
            $separator = ',';
        } elseif (substr_count($firstLine, "\t") > substr_count($firstLine, ';')) {
            $separator = "\t";
        }

        // Parse header
        $headers = str_getcsv(trim($lines[0]), $separator);
        $headers = array_map('trim', $headers);

        // Get column indices from POST
        $colMapping['firstname'] = (int)($_POST['col_firstname'] ?? 0);
        $colMapping['lastname'] = (int)($_POST['col_lastname'] ?? 1);
        $colMapping['club'] = (int)($_POST['col_club'] ?? 2);
        $colMapping['class'] = (int)($_POST['col_class'] ?? 3);
        $colMapping['license'] = (int)($_POST['col_license'] ?? 4);

        // Process data rows
        $exportData = [];

        for ($i = 1; $i < count($lines); $i++) {
            $line = trim($lines[$i]);
            if (empty($line)) continue;

            $parts = str_getcsv($line, $separator);

            $firstname = trim($parts[$colMapping['firstname']] ?? '');
            $lastname = trim($parts[$colMapping['lastname']] ?? '');
            $club = trim($parts[$colMapping['club']] ?? '');
            $inputClass = trim($parts[$colMapping['class']] ?? '');
            $existingLicense = trim($parts[$colMapping['license']] ?? '');

            if (empty($firstname) && empty($lastname)) {
                continue;
            }

            // Search for rider if no license exists or has temp ID
            $match = null;
            $license = $existingLicense;

            if (empty($existingLicense) || strpos($existingLicense, 'SWE25') === 0) {
                $match = findRider($db, $firstname, $lastname, $club);
                if ($match && !empty($match['license_number'])) {
                    $license = $match['license_number'];
                }
            }

            // Update stats
            if ($match) {
                $matchStats[$match['match_type']]++;
            } elseif (empty($existingLicense)) {
                $matchStats['not_found']++;
            }

            // Fill in license in the row
            while (count($parts) <= $colMapping['license']) {
                $parts[] = '';
            }
            $parts[$colMapping['license']] = $license;

            $exportData[] = $parts;

            $results[] = [
                'firstname' => $firstname,
                'lastname' => $lastname,
                'club' => $club,
                'class' => $inputClass,
                'original_license' => $existingLicense,
                'license' => $license,
                'match_type' => $match ? $match['match_type'] : ($existingLicense ? 'existing' : 'not_found'),
                'found_name' => $match ? $match['firstname'] . ' ' . $match['lastname'] : '',
                'found_club' => $match ? $match['club_name'] : '',
                'confidence' => $match ? $match['confidence'] : 0
            ];
        }

        // Store for export
        $_SESSION['lookup_results'] = $exportData;
        $_SESSION['lookup_headers'] = $headers;

        $message = 'CSV bearbetad: ' . count($results) . ' rader';
        $messageType = 'success';
    }
}

/**
 * Find rider in database using multiple strategies
 */
function findRider($db, $firstname, $lastname, $club) {
    // Strategy 1: Exact match with club
    if (!empty($club)) {
        $rider = $db->getRow("
            SELECT r.id, r.firstname, r.lastname, r.license_number,
                   r.birth_year, r.gender, c.name as club_name
            FROM riders r
            LEFT JOIN clubs c ON r.club_id = c.id
            WHERE LOWER(r.firstname) = LOWER(?)
              AND LOWER(r.lastname) = LOWER(?)
              AND LOWER(c.name) LIKE LOWER(?)
            LIMIT 1
        ", [$firstname, $lastname, '%' . $club . '%']);

        if ($rider && !empty($rider['license_number'])) {
            return array_merge($rider, ['match_type' => 'exact', 'confidence' => 100]);
        }
    }

    // Strategy 2: Exact name match (any club)
    $rider = $db->getRow("
        SELECT r.id, r.firstname, r.lastname, r.license_number,
               r.birth_year, r.gender, c.name as club_name
        FROM riders r
        LEFT JOIN clubs c ON r.club_id = c.id
        WHERE LOWER(r.firstname) = LOWER(?)
          AND LOWER(r.lastname) = LOWER(?)
          AND r.license_number IS NOT NULL
          AND r.license_number != ''
        ORDER BY r.license_year DESC
        LIMIT 1
    ", [$firstname, $lastname]);

    if ($rider) {
        $confidence = empty($club) ? 90 : (stripos($rider['club_name'] ?? '', $club) !== false ? 95 : 80);
        return array_merge($rider, ['match_type' => 'exact', 'confidence' => $confidence]);
    }

    // Strategy 2b: Handle double last names (e.g., "Svensson Lindberg")
    // Search where DB lastname contains the search lastname or vice versa
    $rider = $db->getRow("
        SELECT r.id, r.firstname, r.lastname, r.license_number,
               r.birth_year, r.gender, c.name as club_name
        FROM riders r
        LEFT JOIN clubs c ON r.club_id = c.id
        WHERE LOWER(r.firstname) = LOWER(?)
          AND (LOWER(r.lastname) LIKE LOWER(?) OR LOWER(?) LIKE CONCAT('%', LOWER(r.lastname), '%'))
          AND r.license_number IS NOT NULL
          AND r.license_number != ''
        ORDER BY r.license_year DESC
        LIMIT 1
    ", [$firstname, '%' . $lastname . '%', $lastname]);

    if ($rider) {
        $confidence = 85;
        if (!empty($club) && stripos($rider['club_name'] ?? '', $club) !== false) {
            $confidence = 90;
        }
        return array_merge($rider, ['match_type' => 'exact', 'confidence' => $confidence]);
    }

    // Strategy 2c: If lastname has space, try matching last part only
    if (strpos($lastname, ' ') !== false) {
        $lastnameParts = explode(' ', $lastname);
        $lastPart = end($lastnameParts);

        $rider = $db->getRow("
            SELECT r.id, r.firstname, r.lastname, r.license_number,
                   r.birth_year, r.gender, c.name as club_name
            FROM riders r
            LEFT JOIN clubs c ON r.club_id = c.id
            WHERE LOWER(r.firstname) = LOWER(?)
              AND LOWER(r.lastname) LIKE LOWER(?)
              AND r.license_number IS NOT NULL
              AND r.license_number != ''
            ORDER BY r.license_year DESC
            LIMIT 1
        ", [$firstname, '%' . $lastPart]);

        if ($rider) {
            $confidence = 80;
            if (!empty($club) && stripos($rider['club_name'] ?? '', $club) !== false) {
                $confidence = 85;
            }
            return array_merge($rider, ['match_type' => 'fuzzy', 'confidence' => $confidence]);
        }
    }

    // Strategy 3: Fuzzy match (normalized names)
    $normFirstname = normalizeString($firstname);
    $normLastname = normalizeString($lastname);
    $normClub = normalizeString($club);

    $riders = $db->getAll("
        SELECT r.id, r.firstname, r.lastname, r.license_number,
               r.birth_year, r.gender, c.name as club_name
        FROM riders r
        LEFT JOIN clubs c ON r.club_id = c.id
        WHERE r.license_number IS NOT NULL
          AND r.license_number != ''
        ORDER BY r.license_year DESC
    ");

    $bestMatch = null;
    $bestScore = 0;

    foreach ($riders as $rider) {
        $riderNormFirst = normalizeString($rider['firstname']);
        $riderNormLast = normalizeString($rider['lastname']);

        if ($riderNormFirst === $normFirstname && $riderNormLast === $normLastname) {
            $score = 85;

            if (!empty($normClub) && !empty($rider['club_name'])) {
                $riderNormClub = normalizeString($rider['club_name']);
                if (strpos($riderNormClub, $normClub) !== false || strpos($normClub, $riderNormClub) !== false) {
                    $score = 90;
                }
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestMatch = array_merge($rider, ['match_type' => 'fuzzy', 'confidence' => $score]);
            }
        }

        // Partial match (first 3 chars)
        if (strlen($normFirstname) >= 3 && strlen($normLastname) >= 3) {
            if (substr($riderNormFirst, 0, 3) === substr($normFirstname, 0, 3) &&
                substr($riderNormLast, 0, 3) === substr($normLastname, 0, 3)) {

                if (!empty($normClub) && !empty($rider['club_name'])) {
                    $riderNormClub = normalizeString($rider['club_name']);
                    if (strpos($riderNormClub, $normClub) !== false || strpos($normClub, $riderNormClub) !== false) {
                        $score = 70;
                        if ($score > $bestScore) {
                            $bestScore = $score;
                            $bestMatch = array_merge($rider, ['match_type' => 'partial', 'confidence' => $score]);
                        }
                    }
                }
            }
        }
    }

    return $bestMatch;
}

/**
 * Normalize string for comparison
 */
function normalizeString($str) {
    $str = mb_strtolower($str, 'UTF-8');
    $str = preg_replace('/[åä]/u', 'a', $str);
    $str = preg_replace('/[ö]/u', 'o', $str);
    $str = preg_replace('/[é]/u', 'e', $str);
    $str = preg_replace('/[^a-z0-9]/u', '', $str);
    return $str;
}

$pageTitle = 'Sök License Number';
$pageType = 'admin';
include __DIR__ . '/../includes/layout-header.php';
?>

<main class="gs-main-content">
    <div class="gs-container">

        <!-- Header -->
        <div class="gs-flex gs-justify-between gs-items-center gs-mb-lg">
            <div>
                <h1 class="gs-h1">
                    <i data-lucide="search"></i>
                    Sök License Number
                </h1>
                <p class="gs-text-secondary">
                    Ladda upp CSV för att hitta och fylla i license numbers
                </p>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="gs-alert gs-alert-<?= h($messageType) ?> gs-mb-lg">
                <i data-lucide="<?= $messageType === 'success' ? 'check-circle' : 'alert-circle' ?>"></i>
                <?= h($message) ?>
            </div>
        <?php endif; ?>

        <!-- Upload Form -->
        <div class="gs-card gs-mb-lg">
            <div class="gs-card-header">
                <h2 class="gs-h3">
                    <i data-lucide="upload"></i>
                    Ladda upp CSV
                </h2>
            </div>
            <div class="gs-card-content">
                <form method="POST" enctype="multipart/form-data">
                    <?= csrf_field() ?>

                    <div class="gs-form-group gs-mb-md">
                        <label class="gs-label">CSV-fil</label>
                        <input type="file" name="csv_file" accept=".csv,.txt" class="gs-input" required>
                        <small class="gs-text-secondary">
                            CSV med kolumner för förnamn, efternamn, klubb, klass och license number
                        </small>
                    </div>

                    <p class="gs-text-secondary gs-mb-sm">Kolumnnummer (0 = första kolumnen):</p>
                    <div class="gs-grid gs-grid-cols-2 gs-md-grid-cols-5 gs-gap-md gs-mb-md">
                        <div class="gs-form-group">
                            <label class="gs-label">Förnamn</label>
                            <input type="number" name="col_firstname" value="0" min="0" class="gs-input">
                        </div>
                        <div class="gs-form-group">
                            <label class="gs-label">Efternamn</label>
                            <input type="number" name="col_lastname" value="1" min="0" class="gs-input">
                        </div>
                        <div class="gs-form-group">
                            <label class="gs-label">Klubb</label>
                            <input type="number" name="col_club" value="2" min="0" class="gs-input">
                        </div>
                        <div class="gs-form-group">
                            <label class="gs-label">Klass</label>
                            <input type="number" name="col_class" value="3" min="0" class="gs-input">
                        </div>
                        <div class="gs-form-group">
                            <label class="gs-label">License</label>
                            <input type="number" name="col_license" value="4" min="0" class="gs-input">
                        </div>
                    </div>

                    <button type="submit" class="gs-btn gs-btn-primary">
                        <i data-lucide="search"></i>
                        Sök och fyll i License Numbers
                    </button>
                </form>
            </div>
        </div>

        <?php if (!empty($results)): ?>
            <!-- Stats -->
            <div class="gs-grid gs-grid-cols-2 gs-md-grid-cols-4 gs-gap-md gs-mb-lg">
                <div class="gs-card">
                    <div class="gs-card-content gs-text-center">
                        <div class="gs-text-2xl gs-text-success"><?= $matchStats['exact'] ?></div>
                        <div class="gs-text-sm gs-text-secondary">Exakt match</div>
                    </div>
                </div>
                <div class="gs-card">
                    <div class="gs-card-content gs-text-center">
                        <div class="gs-text-2xl gs-text-warning"><?= $matchStats['fuzzy'] ?></div>
                        <div class="gs-text-sm gs-text-secondary">Fuzzy match</div>
                    </div>
                </div>
                <div class="gs-card">
                    <div class="gs-card-content gs-text-center">
                        <div class="gs-text-2xl gs-text-info"><?= $matchStats['partial'] ?></div>
                        <div class="gs-text-sm gs-text-secondary">Delvis match</div>
                    </div>
                </div>
                <div class="gs-card">
                    <div class="gs-card-content gs-text-center">
                        <div class="gs-text-2xl gs-text-danger"><?= $matchStats['not_found'] ?></div>
                        <div class="gs-text-sm gs-text-secondary">Ej hittade</div>
                    </div>
                </div>
            </div>

            <!-- Export Button -->
            <div class="gs-card gs-mb-lg">
                <div class="gs-card-content">
                    <a href="?export=1" class="gs-btn gs-btn-success">
                        <i data-lucide="download"></i>
                        Ladda ner CSV med License Numbers ifyllda
                    </a>
                </div>
            </div>

            <!-- Results Table -->
            <div class="gs-card">
                <div class="gs-card-header">
                    <h2 class="gs-h3">
                        <i data-lucide="list"></i>
                        Resultat (<?= count($results) ?> rader)
                    </h2>
                </div>
                <div class="gs-card-content">
                    <div class="gs-table-responsive">
                        <table class="gs-table">
                            <thead>
                                <tr>
                                    <th>Namn</th>
                                    <th>Klubb</th>
                                    <th>Klass</th>
                                    <th>Original License</th>
                                    <th>Hittad License</th>
                                    <th>Match</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($results as $result): ?>
                                    <?php
                                    $rowClass = '';
                                    if ($result['match_type'] === 'not_found') {
                                        $rowClass = 'gs-bg-danger-light';
                                    }

                                    $badgeClass = 'gs-badge-secondary';
                                    $matchLabel = 'Okänd';

                                    if ($result['match_type'] === 'exact') {
                                        $badgeClass = 'gs-badge-success';
                                        $matchLabel = 'Exakt';
                                    } elseif ($result['match_type'] === 'fuzzy') {
                                        $badgeClass = 'gs-badge-warning';
                                        $matchLabel = 'Fuzzy';
                                    } elseif ($result['match_type'] === 'partial') {
                                        $badgeClass = 'gs-badge-info';
                                        $matchLabel = 'Delvis';
                                    } elseif ($result['match_type'] === 'existing') {
                                        $badgeClass = 'gs-badge-secondary';
                                        $matchLabel = 'Befintlig';
                                    } elseif ($result['match_type'] === 'not_found') {
                                        $badgeClass = 'gs-badge-danger';
                                        $matchLabel = 'Ej hittad';
                                    }
                                    ?>
                                    <tr class="<?= $rowClass ?>">
                                        <td>
                                            <strong><?= h($result['firstname'] . ' ' . $result['lastname']) ?></strong>
                                            <?php if ($result['found_name'] && $result['found_name'] !== $result['firstname'] . ' ' . $result['lastname']): ?>
                                                <br><small class="gs-text-secondary">&rarr; <?= h($result['found_name']) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?= h($result['club']) ?>
                                            <?php if ($result['found_club'] && $result['found_club'] !== $result['club']): ?>
                                                <br><small class="gs-text-secondary">&rarr; <?= h($result['found_club']) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= h($result['class']) ?></td>
                                        <td>
                                            <?php if ($result['original_license']): ?>
                                                <code><?= h($result['original_license']) ?></code>
                                            <?php else: ?>
                                                <span class="gs-text-secondary">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($result['license'] && $result['license'] !== $result['original_license']): ?>
                                                <code class="gs-text-success"><?= h($result['license']) ?></code>
                                            <?php elseif ($result['license']): ?>
                                                <code><?= h($result['license']) ?></code>
                                            <?php else: ?>
                                                <span class="gs-text-danger">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="gs-badge <?= $badgeClass ?>">
                                                <?= $matchLabel ?>
                                                <?php if ($result['confidence']): ?>
                                                    (<?= $result['confidence'] ?>%)
                                                <?php endif; ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>

    </div>
</main>

<div class="gs-container gs-py-sm">
    <small class="gs-text-secondary">Search UCI-ID v1.1.0 [2025-11-22-002]</small>
</div>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>

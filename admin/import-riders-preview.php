<?php
/**
 * Import Riders Preview - Förhandsgranskning innan import
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/club-membership.php';
require_admin();

$db = getDB();
$current_admin = get_current_admin();

$message = '';
$messageType = 'info';

// Check if we have a file to preview
if (!isset($_SESSION['import_riders_file']) || !file_exists($_SESSION['import_riders_file'])) {
    header('Location: /admin/import/riders');
    exit;
}

$seasonYear = $_SESSION['import_riders_season'] ?? (int)date('Y');
$createMissing = $_SESSION['import_riders_create_missing'] ?? true;

// Parse CSV and analyze
$previewData = [];
$matchingStats = [
    'total_rows' => 0,
    'riders_existing' => 0,
    'riders_new' => 0,
    'riders_update' => 0,
    'clubs_existing' => 0,
    'clubs_new' => 0,
    'clubs_list' => []
];

/**
 * Auto-detect CSV separator
 */
function detectSeparator($filepath) {
    $handle = fopen($filepath, 'r');
    $firstLine = fgets($handle);
    fclose($handle);

    $separators = [
        ',' => substr_count($firstLine, ','),
        ';' => substr_count($firstLine, ';'),
        "\t" => substr_count($firstLine, "\t"),
    ];

    arsort($separators);
    return array_key_first($separators);
}

/**
 * Ensure UTF-8 encoding
 */
function ensureUTF8Preview($filepath) {
    $content = file_get_contents($filepath);
    $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);

    if (mb_check_encoding($content, 'UTF-8')) {
        if (preg_match('/[\xC0-\xFF]/', $content) && !preg_match('/[\xC0-\xFF][\x80-\xBF]/', $content)) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
            file_put_contents($filepath, $content);
        }
    } else {
        $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        file_put_contents($filepath, $content);
    }
}

try {
    ensureUTF8Preview($_SESSION['import_riders_file']);

    $handle = fopen($_SESSION['import_riders_file'], 'r');
    if (!$handle) {
        throw new Exception('Kunde inte öppna filen');
    }

    $separator = detectSeparator($_SESSION['import_riders_file']);
    $header = fgetcsv($handle, 0, $separator);

    if (!$header) {
        throw new Exception('Tom fil eller ogiltigt format');
    }

    // Normalize header
    $header = array_map(function($col) {
        $col = mb_strtolower(trim($col), 'UTF-8');
        $col = preg_replace('/^\xEF\xBB\xBF/', '', $col);
        return $col;
    }, $header);

    // Map columns
    $colMap = [];
    foreach ($header as $idx => $col) {
        if (strpos($col, 'förnamn') !== false || strpos($col, 'fornamn') !== false || $col === 'firstname') {
            $colMap['firstname'] = $idx;
        } elseif (strpos($col, 'efternamn') !== false || $col === 'lastname') {
            $colMap['lastname'] = $idx;
        } elseif ($col === 'kön' || $col === 'kon' || $col === 'gender') {
            $colMap['gender'] = $idx;
        } elseif (strpos($col, 'klubb') !== false || strpos($col, 'företag') !== false || $col === 'club') {
            $colMap['club'] = $idx;
        } elseif (strpos($col, 'födelseår') !== false || strpos($col, 'fodelsear') !== false || $col === 'birthyear') {
            $colMap['birth_year'] = $idx;
        } elseif (strpos($col, 'födelsedag') !== false || strpos($col, 'birthday') !== false) {
            $colMap['birthday'] = $idx;
        } elseif (strpos($col, 'email') !== false || strpos($col, 'e-post') !== false) {
            $colMap['email'] = $idx;
        } elseif (strpos($col, 'medlemsnummer') !== false || strpos($col, 'uci') !== false || strpos($col, 'licens') !== false) {
            $colMap['uci_id'] = $idx;
        } elseif (strpos($col, 'nationalitet') !== false && strpos($col, '3') !== false) {
            $colMap['nationality'] = $idx;
        }
    }

    // Cache for lookups
    $clubCache = [];
    $riderCache = [];

    $lineNumber = 1;
    while (($row = fgetcsv($handle, 0, $separator)) !== false) {
        $lineNumber++;
        $matchingStats['total_rows']++;

        if (empty(array_filter($row, function($v) { return trim($v) !== ''; }))) {
            continue;
        }

        $firstname = trim($row[$colMap['firstname']] ?? '');
        $lastname = trim($row[$colMap['lastname']] ?? '');

        if (empty($firstname) || empty($lastname)) {
            continue;
        }

        // Gender
        $gender = 'M';
        if (isset($colMap['gender'])) {
            $genderRaw = strtoupper(trim($row[$colMap['gender']] ?? ''));
            if (in_array($genderRaw, ['F', 'K', 'W'])) {
                $gender = 'F';
            }
        }

        // Birth year
        $birthYear = null;
        if (isset($colMap['birth_year']) && !empty($row[$colMap['birth_year']])) {
            $birthYear = (int)$row[$colMap['birth_year']];
        } elseif (isset($colMap['birthday']) && !empty($row[$colMap['birthday']])) {
            $bday = trim($row[$colMap['birthday']]);
            if (preg_match('/(\d{4})/', $bday, $m)) {
                $birthYear = (int)$m[1];
            }
        }

        // Club
        $clubName = isset($colMap['club']) ? trim($row[$colMap['club']] ?? '') : '';
        $clubStatus = 'none';

        if (!empty($clubName)) {
            if (!isset($clubCache[$clubName])) {
                $club = $db->getRow("SELECT id, name FROM clubs WHERE name = ? OR name LIKE ?", [$clubName, '%' . $clubName . '%']);
                $clubCache[$clubName] = $club ? $club : null;
            }

            if ($clubCache[$clubName]) {
                $clubStatus = 'existing';
                if (!in_array($clubName, array_column($matchingStats['clubs_list'], 'name'))) {
                    $matchingStats['clubs_list'][] = ['name' => $clubName, 'status' => 'existing', 'matched' => $clubCache[$clubName]['name']];
                }
                $matchingStats['clubs_existing']++;
            } else {
                $clubStatus = 'new';
                if (!in_array($clubName, array_column($matchingStats['clubs_list'], 'name'))) {
                    $matchingStats['clubs_list'][] = ['name' => $clubName, 'status' => 'new', 'matched' => null];
                }
                $matchingStats['clubs_new']++;
            }
        }

        // UCI ID
        $uciId = isset($colMap['uci_id']) ? trim($row[$colMap['uci_id']] ?? '') : '';

        // Check if rider exists
        $riderKey = strtolower($firstname . '_' . $lastname . '_' . $birthYear);
        $riderStatus = 'new';
        $existingRider = null;

        // Check by name + birth year
        if ($birthYear) {
            $existingRider = $db->getRow(
                "SELECT id, firstname, lastname, license_number, club_id FROM riders
                 WHERE LOWER(firstname) = LOWER(?) AND LOWER(lastname) = LOWER(?) AND birth_year = ?",
                [$firstname, $lastname, $birthYear]
            );
        }

        // Check by name only
        if (!$existingRider) {
            $existingRider = $db->getRow(
                "SELECT id, firstname, lastname, license_number, club_id FROM riders
                 WHERE LOWER(firstname) = LOWER(?) AND LOWER(lastname) = LOWER(?)",
                [$firstname, $lastname]
            );
        }

        // Check by UCI ID
        if (!$existingRider && !empty($uciId)) {
            $uciIdClean = preg_replace('/[^0-9]/', '', $uciId);
            if (strlen($uciIdClean) >= 8) {
                $existingRider = $db->getRow(
                    "SELECT id, firstname, lastname, license_number, club_id FROM riders
                     WHERE REPLACE(REPLACE(license_number, ' ', ''), '-', '') = ?",
                    [$uciIdClean]
                );
            }
        }

        if ($existingRider) {
            $riderStatus = 'existing';
            $matchingStats['riders_existing']++;
        } else {
            $matchingStats['riders_new']++;
        }

        $previewData[] = [
            'line' => $lineNumber,
            'firstname' => $firstname,
            'lastname' => $lastname,
            'gender' => $gender,
            'birth_year' => $birthYear,
            'club' => $clubName,
            'club_status' => $clubStatus,
            'uci_id' => $uciId,
            'rider_status' => $riderStatus,
            'existing_rider' => $existingRider
        ];
    }

    fclose($handle);

} catch (Exception $e) {
    $message = 'Parsning misslyckades: ' . $e->getMessage();
    $messageType = 'error';
}

// Handle confirm import
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_import'])) {
    checkCsrf();

    // Redirect to actual import with confirmed flag
    $_SESSION['import_riders_confirmed'] = true;
    header('Location: /admin/import/riders?do_import=1');
    exit;
}

// Handle cancel
if (isset($_GET['cancel'])) {
    if (isset($_SESSION['import_riders_file']) && file_exists($_SESSION['import_riders_file'])) {
        @unlink($_SESSION['import_riders_file']);
    }
    unset($_SESSION['import_riders_file']);
    unset($_SESSION['import_riders_season']);
    unset($_SESSION['import_riders_create_missing']);
    header('Location: /admin/import/riders');
    exit;
}

// Page config
$page_title = 'Förhandsgranska Import';
$breadcrumbs = [
    ['label' => 'Import', 'url' => '/admin/import'],
    ['label' => 'Deltagare', 'url' => '/admin/import/riders'],
    ['label' => 'Förhandsgranska']
];

include __DIR__ . '/components/unified-layout.php';
?>

<?php if ($message): ?>
<div class="alert alert-<?= $messageType === 'error' ? 'error' : 'info' ?> mb-lg">
    <?= h($message) ?>
</div>
<?php endif; ?>

<!-- Summary Stats -->
<div class="admin-card mb-lg">
    <div class="admin-card-header">
        <h2>
            <i data-lucide="bar-chart-2"></i>
            Sammanfattning
        </h2>
    </div>
    <div class="admin-card-body">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: var(--space-md);">
            <div class="admin-stat-card">
                <div class="admin-stat-value"><?= number_format($matchingStats['total_rows']) ?></div>
                <div class="admin-stat-label">Totalt rader</div>
            </div>
            <div class="admin-stat-card">
                <div class="admin-stat-value text-success"><?= number_format($matchingStats['riders_existing']) ?></div>
                <div class="admin-stat-label">Befintliga deltagare</div>
            </div>
            <div class="admin-stat-card">
                <div class="admin-stat-value" style="color: var(--color-gs-blue);"><?= number_format($matchingStats['riders_new']) ?></div>
                <div class="admin-stat-label">Nya deltagare</div>
            </div>
            <div class="admin-stat-card">
                <div class="admin-stat-value"><?= number_format($matchingStats['clubs_existing']) ?></div>
                <div class="admin-stat-label">Befintliga klubbar</div>
            </div>
            <div class="admin-stat-card">
                <div class="admin-stat-value" style="color: var(--color-warning);"><?= number_format($matchingStats['clubs_new']) ?></div>
                <div class="admin-stat-label">Nya klubbar</div>
            </div>
        </div>

        <div class="mt-lg" style="background: var(--color-bg-muted); padding: var(--space-md); border-radius: var(--radius-md);">
            <p class="text-sm">
                <strong>Säsong:</strong> <?= $seasonYear ?><br>
                <strong>Skapa nya deltagare:</strong> <?= $createMissing ? 'Ja' : 'Nej' ?>
            </p>
        </div>
    </div>
</div>

<!-- Clubs Analysis -->
<?php if (!empty($matchingStats['clubs_list'])): ?>
<div class="admin-card mb-lg">
    <div class="admin-card-header">
        <h2>
            <i data-lucide="building"></i>
            Klubbar (<?= count($matchingStats['clubs_list']) ?>)
        </h2>
    </div>
    <div class="admin-card-body">
        <div class="table-responsive">
            <table class="table text-sm">
                <thead>
                    <tr>
                        <th>Klubb i fil</th>
                        <th>Status</th>
                        <th>Matchad till</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($matchingStats['clubs_list'] as $club): ?>
                    <tr>
                        <td><?= h($club['name']) ?></td>
                        <td>
                            <?php if ($club['status'] === 'existing'): ?>
                            <span class="badge badge-success">Finns</span>
                            <?php else: ?>
                            <span class="badge badge-warning">Ny</span>
                            <?php endif; ?>
                        </td>
                        <td><?= $club['matched'] ? h($club['matched']) : '<em class="text-secondary">Skapas</em>' ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Preview Data -->
<div class="admin-card mb-lg">
    <div class="admin-card-header">
        <h2>
            <i data-lucide="users"></i>
            Deltagare (första 100)
        </h2>
    </div>
    <div class="admin-card-body">
        <div class="table-responsive">
            <table class="table text-sm">
                <thead>
                    <tr>
                        <th>Rad</th>
                        <th>Namn</th>
                        <th>Födelseår</th>
                        <th>Kön</th>
                        <th>Klubb</th>
                        <th>UCI-ID</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (array_slice($previewData, 0, 100) as $row): ?>
                    <tr>
                        <td><?= $row['line'] ?></td>
                        <td>
                            <strong><?= h($row['firstname']) ?> <?= h($row['lastname']) ?></strong>
                            <?php if ($row['existing_rider']): ?>
                            <br><small class="text-secondary">ID: <?= $row['existing_rider']['id'] ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?= $row['birth_year'] ?: '-' ?></td>
                        <td><?= $row['gender'] ?></td>
                        <td>
                            <?= h($row['club']) ?: '-' ?>
                            <?php if ($row['club_status'] === 'new'): ?>
                            <span class="badge badge-warning" style="font-size: 0.65rem;">NY</span>
                            <?php endif; ?>
                        </td>
                        <td><code class="text-xs"><?= h($row['uci_id']) ?: '-' ?></code></td>
                        <td>
                            <?php if ($row['rider_status'] === 'existing'): ?>
                            <span class="badge badge-success">Uppdateras</span>
                            <?php else: ?>
                            <span class="badge" style="background: var(--color-gs-blue); color: white;">Ny</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if (count($previewData) > 100): ?>
        <p class="text-sm text-secondary mt-md">
            Visar 100 av <?= count($previewData) ?> rader
        </p>
        <?php endif; ?>
    </div>
</div>

<!-- Actions -->
<div class="admin-card">
    <div class="admin-card-body">
        <form method="POST" style="display: flex; gap: var(--space-md); align-items: center;">
            <?= csrf_field() ?>

            <button type="submit" name="confirm_import" class="btn-admin btn-admin-primary">
                <i data-lucide="check"></i>
                Bekräfta och importera
            </button>

            <a href="?cancel=1" class="btn-admin btn-admin-secondary">
                <i data-lucide="x"></i>
                Avbryt
            </a>

            <span class="text-secondary text-sm" style="margin-left: auto;">
                <?= $matchingStats['riders_existing'] ?> uppdateras, <?= $matchingStats['riders_new'] ?> nya skapas
            </span>
        </form>
    </div>
</div>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>

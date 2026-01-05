<?php
/**
 * Yearly Rebuild Tool
 * Complete workflow for rebuilding a year's data from scratch:
 * 1. Import riders from master list (deduplicated)
 * 2. Lock club affiliations for the year
 * 3. Clear all results for the year
 * 4. Re-import events one by one
 */
require_once __DIR__ . '/../../config.php';
require_admin();

$db = getDB();
$message = '';
$messageType = 'info';

// Get selected year
$selectedYear = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? '';

    // =========================================================================
    // STEP 1: Import riders from file
    // =========================================================================
    if ($action === 'import_riders') {
        $csvData = '';

        // Handle file upload
        if (isset($_FILES['rider_file']) && $_FILES['rider_file']['error'] === UPLOAD_ERR_OK) {
            $csvData = file_get_contents($_FILES['rider_file']['tmp_name']);
        }

        $csvData = trim($csvData);

        if (empty($csvData)) {
            $message = 'Ingen fil vald eller filen är tom';
            $messageType = 'error';
        } else {
            $lines = explode("\n", $csvData);
            $imported = 0;
            $updated = 0;
            $skipped = 0;
            $errors = [];

            // Detect delimiter
            $firstLine = $lines[0];
            $tabCount = substr_count($firstLine, "\t");
            $semiCount = substr_count($firstLine, ';');
            $delimiter = ($tabCount >= 2) ? "\t" : (($semiCount >= 2) ? ';' : ',');

            // Check for header
            $firstLineLower = strtolower($firstLine);
            $hasHeader = (strpos($firstLineLower, 'firstname') !== false ||
                          strpos($firstLineLower, 'förnamn') !== false ||
                          strpos($firstLineLower, 'name') !== false);
            if ($hasHeader) array_shift($lines);

            foreach ($lines as $lineNum => $line) {
                $line = trim($line);
                if (empty($line)) continue;

                $cols = str_getcsv($line, $delimiter);

                // Expected format: FirstName, LastName, Club, UCI-ID (optional)
                $firstName = trim($cols[0] ?? '');
                $lastName = trim($cols[1] ?? '');
                $clubName = trim($cols[2] ?? '');
                $uciId = preg_replace('/[^0-9]/', '', trim($cols[3] ?? ''));

                if (empty($firstName) || empty($lastName)) {
                    $skipped++;
                    continue;
                }

                // Normalize names for matching
                $firstNameNorm = mb_strtoupper(trim($firstName), 'UTF-8');
                $lastNameNorm = mb_strtoupper(trim($lastName), 'UTF-8');

                // Try to find existing rider
                $rider = null;

                // 1. Match by UCI-ID first (most reliable)
                if (!empty($uciId)) {
                    $rider = $db->getRow("
                        SELECT id, firstname, lastname, club_id
                        FROM riders
                        WHERE REPLACE(REPLACE(license_number, ' ', ''), '-', '') = ?
                    ", [$uciId]);
                }

                // 2. Match by exact name
                if (!$rider) {
                    $rider = $db->getRow("
                        SELECT id, firstname, lastname, club_id
                        FROM riders
                        WHERE UPPER(firstname) = ? AND UPPER(lastname) = ?
                    ", [$firstNameNorm, $lastNameNorm]);
                }

                // 3. Fuzzy match - similar names (Levenshtein distance <= 2)
                if (!$rider) {
                    $candidates = $db->getAll("
                        SELECT id, firstname, lastname, club_id
                        FROM riders
                        WHERE UPPER(lastname) = ? OR SOUNDEX(lastname) = SOUNDEX(?)
                    ", [$lastNameNorm, $lastName]);

                    foreach ($candidates as $c) {
                        $fnDist = levenshtein(
                            mb_strtoupper($c['firstname'], 'UTF-8'),
                            $firstNameNorm
                        );
                        $lnDist = levenshtein(
                            mb_strtoupper($c['lastname'], 'UTF-8'),
                            $lastNameNorm
                        );

                        // Allow small typos (2 chars total)
                        if ($fnDist + $lnDist <= 2) {
                            $rider = $c;
                            break;
                        }
                    }
                }

                // Find or create club
                $clubId = null;
                if (!empty($clubName)) {
                    // Skip time-like club names
                    if (preg_match('/^[0-9]{1,2}:[0-9]{2}/', $clubName)) {
                        $errors[] = "Rad $lineNum: Klubbnamn '$clubName' ser ut som en tid - ignoreras";
                    } else {
                        $club = $db->getRow("
                            SELECT id FROM clubs
                            WHERE LOWER(name) = LOWER(?) OR LOWER(name) = LOWER(?)
                        ", [$clubName, str_replace(['CK ', 'Ck '], ['Cykelklubb ', 'Cykelklubb '], $clubName)]);

                        if ($club) {
                            $clubId = $club['id'];
                        } else {
                            $clubId = $db->insert('clubs', [
                                'name' => $clubName,
                                'active' => 1
                            ]);
                        }
                    }
                }

                if ($rider) {
                    // Update existing rider
                    $updateData = [];

                    // Update UCI-ID if we have one and rider doesn't
                    if (!empty($uciId)) {
                        $existingUci = $db->getRow("SELECT license_number FROM riders WHERE id = ?", [$rider['id']]);
                        if (empty($existingUci['license_number'])) {
                            $updateData['license_number'] = $uciId;
                        }
                    }

                    // Update club if provided and different
                    if ($clubId && $clubId != $rider['club_id']) {
                        $updateData['club_id'] = $clubId;
                    }

                    if (!empty($updateData)) {
                        $db->update('riders', $updateData, 'id = ?', [$rider['id']]);
                        $updated++;
                    } else {
                        $skipped++;
                    }

                    // Create/update rider_club_seasons for this year
                    if ($clubId) {
                        $db->execute("
                            INSERT INTO rider_club_seasons (rider_id, club_id, season_year, locked)
                            VALUES (?, ?, ?, 0)
                            ON DUPLICATE KEY UPDATE club_id = VALUES(club_id)
                        ", [$rider['id'], $clubId, $selectedYear]);
                    }
                } else {
                    // Create new rider
                    $riderId = $db->insert('riders', [
                        'firstname' => $firstName,
                        'lastname' => $lastName,
                        'club_id' => $clubId,
                        'license_number' => $uciId ?: null,
                        'active' => 1
                    ]);

                    // Create rider_club_seasons
                    if ($clubId) {
                        $db->insert('rider_club_seasons', [
                            'rider_id' => $riderId,
                            'club_id' => $clubId,
                            'season_year' => $selectedYear,
                            'locked' => 0
                        ]);
                    }

                    $imported++;
                }
            }

            $message = "Deltagare: $imported nya, $updated uppdaterade, $skipped ignorerade";
            if (!empty($errors)) {
                $message .= ". Varningar: " . count($errors);
            }
            $messageType = 'success';
        }
    }

    // =========================================================================
    // STEP 2: Lock club affiliations
    // =========================================================================
    if ($action === 'lock_clubs') {
        $locked = $db->execute("
            UPDATE rider_club_seasons
            SET locked = 1
            WHERE season_year = ?
        ", [$selectedYear]);

        $count = $db->getRow("SELECT COUNT(*) as cnt FROM rider_club_seasons WHERE season_year = ? AND locked = 1", [$selectedYear]);
        $message = "{$count['cnt']} klubbtillhörigheter låsta för $selectedYear";
        $messageType = 'success';
    }

    // =========================================================================
    // STEP 3: Clear all results for the year
    // =========================================================================
    if ($action === 'clear_results') {
        $confirm = $_POST['confirm_clear'] ?? '';

        if ($confirm !== 'RENSA') {
            $message = 'Skriv RENSA för att bekräfta';
            $messageType = 'error';
        } else {
            // Get event IDs for this year
            $eventIds = $db->getAll("SELECT id FROM events WHERE YEAR(date) = ?", [$selectedYear]);
            $eventIdList = array_column($eventIds, 'id');

            if (!empty($eventIdList)) {
                $placeholders = implode(',', array_fill(0, count($eventIdList), '?'));

                // Delete series_results for these events
                $db->execute("DELETE FROM series_results WHERE event_id IN ($placeholders)", $eventIdList);

                // Delete results
                $deleted = $db->execute("DELETE FROM results WHERE event_id IN ($placeholders)", $eventIdList);

                $message = "Raderade resultat för " . count($eventIdList) . " event i $selectedYear";
                $messageType = 'success';
            } else {
                $message = "Inga event hittades för $selectedYear";
                $messageType = 'warning';
            }
        }
    }
}

$pageTitle = "Årsombyggnad $selectedYear";
include __DIR__ . '/../components/unified-layout.php';

// Get available years
$years = $db->getAll("
    SELECT DISTINCT YEAR(date) as year, COUNT(*) as event_count
    FROM events
    GROUP BY YEAR(date)
    ORDER BY year ASC
");

// Get stats for selected year
$yearStats = $db->getRow("
    SELECT
        (SELECT COUNT(*) FROM events WHERE YEAR(date) = ?) as event_count,
        (SELECT COUNT(*) FROM results r JOIN events e ON r.event_id = e.id WHERE YEAR(e.date) = ?) as result_count,
        (SELECT COUNT(*) FROM rider_club_seasons WHERE season_year = ?) as club_seasons,
        (SELECT COUNT(*) FROM rider_club_seasons WHERE season_year = ? AND locked = 1) as locked_seasons
", [$selectedYear, $selectedYear, $selectedYear, $selectedYear]);

// Get events for this year
$events = $db->getAll("
    SELECT
        e.id, e.name, e.date, e.discipline,
        COUNT(r.id) as result_count
    FROM events e
    LEFT JOIN results r ON r.event_id = e.id
    WHERE YEAR(e.date) = ?
    GROUP BY e.id
    ORDER BY e.date ASC
", [$selectedYear]);
?>

<?php if ($message): ?>
<div class="alert alert-<?= $messageType ?> mb-lg">
    <?= h($message) ?>
</div>
<?php endif; ?>

<!-- Year Selector -->
<div class="card mb-lg">
    <div class="card-body">
        <div class="flex items-center gap-md">
            <label class="text-secondary">Välj år:</label>
            <?php foreach ($years as $y): ?>
            <a href="?year=<?= $y['year'] ?>"
               class="btn <?= $y['year'] == $selectedYear ? 'btn--primary' : 'btn--secondary' ?> btn--sm">
                <?= $y['year'] ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Current Status -->
<div class="grid grid-cols-2 md-grid-cols-4 gap-md mb-lg">
    <div class="stat-card">
        <div class="stat-number"><?= $yearStats['event_count'] ?? 0 ?></div>
        <div class="stat-label">Event</div>
    </div>
    <div class="stat-card">
        <div class="stat-number"><?= $yearStats['result_count'] ?? 0 ?></div>
        <div class="stat-label">Resultat</div>
    </div>
    <div class="stat-card">
        <div class="stat-number"><?= $yearStats['club_seasons'] ?? 0 ?></div>
        <div class="stat-label">Klubbtillhörigheter</div>
    </div>
    <div class="stat-card">
        <div class="stat-number"><?= $yearStats['locked_seasons'] ?? 0 ?></div>
        <div class="stat-label">Låsta</div>
    </div>
</div>

<!-- STEP 1: Import Riders -->
<div class="card mb-lg">
    <div class="card-header">
        <h2>
            <span class="badge badge-primary mr-sm">1</span>
            Importera deltagarlista
        </h2>
    </div>
    <div class="card-body">
        <p class="text-secondary mb-md">
            Ladda upp deltagarlista (CSV). Dubbletter ignoreras (även felstavade namn matchas).
            Klubbtillhörighet sparas för <?= $selectedYear ?>.
        </p>

        <form method="POST" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="import_riders">

            <div class="form-group mb-md">
                <label class="form-label">Välj CSV-fil (Förnamn, Efternamn, Klubb, UCI-ID)</label>
                <input type="file" name="rider_file" class="input" accept=".csv,.txt" required>
                <p class="text-sm text-secondary mt-xs">Accepterar tab-, semikolon- eller kommaseparerade filer</p>
            </div>

            <button type="submit" class="btn btn--primary">
                <i data-lucide="upload"></i>
                Importera deltagare
            </button>
        </form>

        <details class="mt-md">
            <summary class="text-sm text-primary" style="cursor:pointer;">Visa matchningsregler</summary>
            <ul class="text-sm mt-sm" style="margin-left: 20px;">
                <li>Matchar först på UCI-ID (om det finns)</li>
                <li>Sedan exakt namn (case-insensitive)</li>
                <li>Sedan fuzzy-match (tillåter 1-2 tecken fel)</li>
                <li>Klubbnamn som ser ut som tider ignoreras</li>
            </ul>
        </details>
    </div>
</div>

<!-- STEP 2: Lock Club Affiliations -->
<div class="card mb-lg">
    <div class="card-header">
        <h2>
            <span class="badge badge-primary mr-sm">2</span>
            Lås klubbtillhörigheter
        </h2>
    </div>
    <div class="card-body">
        <p class="text-secondary mb-md">
            Låser alla klubbtillhörigheter för <?= $selectedYear ?>.
            Efter detta kan nya importer inte ändra vilken klubb en åkare tillhör för detta år.
        </p>

        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="lock_clubs">

            <button type="submit" class="btn btn--warning"
                    onclick="return confirm('Lås alla klubbtillhörigheter för <?= $selectedYear ?>?')">
                <i data-lucide="lock"></i>
                Lås klubbtillhörigheter för <?= $selectedYear ?>
            </button>

            <span class="text-secondary ml-md">
                <?= $yearStats['locked_seasons'] ?? 0 ?> av <?= $yearStats['club_seasons'] ?? 0 ?> redan låsta
            </span>
        </form>
    </div>
</div>

<!-- STEP 3: Clear Results -->
<div class="card mb-lg">
    <div class="card-header">
        <h2>
            <span class="badge badge-danger mr-sm">3</span>
            Rensa årets resultat
        </h2>
    </div>
    <div class="card-body">
        <div class="alert alert-danger mb-md">
            <i data-lucide="alert-triangle"></i>
            <strong>Varning!</strong> Detta raderar ALLA resultat för <?= $selectedYear ?>:
            <ul style="margin: 8px 0 0 20px;">
                <li><?= $yearStats['result_count'] ?? 0 ?> resultat</li>
                <li>Serieresultat och poäng</li>
                <li>Rankingpoäng</li>
            </ul>
        </div>

        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="clear_results">

            <div class="form-group mb-md">
                <label class="form-label">Skriv RENSA för att bekräfta:</label>
                <input type="text" name="confirm_clear" class="input" style="max-width: 200px;"
                       placeholder="RENSA" autocomplete="off">
            </div>

            <button type="submit" class="btn btn--danger">
                <i data-lucide="trash-2"></i>
                Rensa alla resultat för <?= $selectedYear ?>
            </button>
        </form>
    </div>
</div>

<!-- STEP 4: Re-import Events -->
<div class="card">
    <div class="card-header">
        <h2>
            <span class="badge badge-primary mr-sm">4</span>
            Importera om event
        </h2>
    </div>
    <div class="card-body">
        <p class="text-secondary mb-md">
            Efter rensning, importera om varje event. Klicka på importknappen för att gå till importverktyget.
        </p>

        <?php if (empty($events)): ?>
        <p class="text-secondary">Inga event för <?= $selectedYear ?></p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Datum</th>
                        <th>Event</th>
                        <th>Disciplin</th>
                        <th>Resultat</th>
                        <th>Åtgärd</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($events as $event): ?>
                    <tr>
                        <td><?= date('Y-m-d', strtotime($event['date'])) ?></td>
                        <td><?= h($event['name']) ?></td>
                        <td>
                            <?php if ($event['discipline']): ?>
                            <span class="badge badge-secondary"><?= h($event['discipline']) ?></span>
                            <?php else: ?>
                            -
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($event['result_count'] > 0): ?>
                            <span class="badge badge-success"><?= $event['result_count'] ?></span>
                            <?php else: ?>
                            <span class="badge badge-warning">0</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="/admin/import-results.php?preselect=<?= $event['id'] ?>"
                               class="btn btn--sm btn--primary">
                                <i data-lucide="upload"></i>
                                Importera
                            </a>
                            <a href="/admin/edit-results.php?event_id=<?= $event['id'] ?>"
                               class="btn btn--sm btn--ghost">
                                <i data-lucide="pencil"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../components/unified-layout-footer.php'; ?>

<?php
/**
 * Import Dual Slalom Results - Simple placement import with manual points
 * CSV format: Class, Position, FirstName, LastName, Club, Points
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/import-functions.php';
require_admin();

$db = getDB();
$message = '';
$messageType = 'info';

// Download template
if (isset($_GET['download_template'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="ds-resultat-mall.csv"');
    echo "Klass;Placering;Förnamn;Efternamn;Klubb;Poäng\n";
    echo "Herrar Elite;1;Johan;Andersson;Stockholm CK;100\n";
    echo "Herrar Elite;2;Erik;Svensson;Göteborg MTB;80\n";
    echo "Herrar Elite;3;Anders;Nilsson;;65\n";
    echo "Herrar Elite;4;Peter;Lindqvist;Malmö CK;55\n";
    echo "Damer Elite;1;Anna;Johansson;Uppsala CK;100\n";
    echo "Damer Elite;2;Maria;Eriksson;;80\n";
    echo "Herrar Junior;1;Oscar;Berg;Lund CK;100\n";
    echo "Herrar Junior;2;Viktor;Ström;;80\n";
    exit;
}

// Get events (DS only, or all if none found)
$events = $db->getAll("
    SELECT e.id, e.name, e.date, e.discipline
    FROM events e
    WHERE e.discipline = 'DS' OR e.name LIKE '%Dual%' OR e.name LIKE '%Slalom%'
    ORDER BY e.date DESC
    LIMIT 100
");

if (empty($events)) {
    $events = $db->getAll("
        SELECT id, name, date, discipline
        FROM events
        ORDER BY date DESC
        LIMIT 200
    ");
}

// Handle import
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import'])) {
    checkCsrf();

    $eventId = (int)$_POST['event_id'];
    $csvData = trim($_POST['csv_data']);

    if (!$eventId) {
        $message = 'Välj ett event';
        $messageType = 'error';
    } elseif (empty($csvData)) {
        $message = 'Klistra in CSV-data';
        $messageType = 'error';
    } else {
        $lines = explode("\n", $csvData);
        $imported = 0;
        $errors = [];

        // Detect delimiter
        $firstLine = $lines[0];
        $delimiter = (substr_count($firstLine, ';') > substr_count($firstLine, ',')) ? ';' : ',';

        // Check if first line is header
        $firstLineLower = strtolower($firstLine);
        $hasHeader = (strpos($firstLineLower, 'klass') !== false ||
                      strpos($firstLineLower, 'class') !== false ||
                      strpos($firstLineLower, 'poäng') !== false ||
                      strpos($firstLineLower, 'points') !== false);

        if ($hasHeader) {
            array_shift($lines);
        }

        foreach ($lines as $lineNum => $line) {
            $line = trim($line);
            if (empty($line)) continue;

            $cols = str_getcsv($line, $delimiter);

            // Expected: Class, Position, FirstName, LastName, Club, Points
            if (count($cols) < 4) {
                $errors[] = "Rad " . ($lineNum + 1) . ": För få kolumner";
                continue;
            }

            $className = trim($cols[0]);
            $position = (int)trim($cols[1]);
            $firstName = trim($cols[2]);
            $lastName = trim($cols[3]);
            $clubName = isset($cols[4]) ? trim($cols[4]) : '';
            $points = isset($cols[5]) ? (float)trim($cols[5]) : 0;

            if (empty($firstName) || empty($lastName)) {
                $errors[] = "Rad " . ($lineNum + 1) . ": Saknar namn";
                continue;
            }

            if ($position < 1 || $position > 200) {
                $errors[] = "Rad " . ($lineNum + 1) . ": Ogiltig placering: $position";
                continue;
            }

            try {
                // Find or create class
                $class = $db->getRow(
                    "SELECT id FROM classes WHERE LOWER(display_name) = LOWER(?) OR LOWER(name) = LOWER(?)",
                    [$className, $className]
                );

                if (!$class) {
                    $classId = $db->insert('classes', [
                        'name' => strtolower(str_replace(' ', '_', $className)),
                        'display_name' => $className,
                        'active' => 1,
                        'sort_order' => 999
                    ]);
                } else {
                    $classId = $class['id'];
                }

                // Find or create rider
                $rider = $db->getRow(
                    "SELECT id FROM riders WHERE UPPER(firstname) = UPPER(?) AND UPPER(lastname) = UPPER(?)",
                    [$firstName, $lastName]
                );

                if (!$rider) {
                    // Find or create club
                    $clubId = null;
                    if (!empty($clubName)) {
                        $club = $db->getRow("SELECT id FROM clubs WHERE LOWER(name) = LOWER(?)", [$clubName]);
                        if (!$club) {
                            $clubId = $db->insert('clubs', ['name' => $clubName, 'active' => 1]);
                        } else {
                            $clubId = $club['id'];
                        }
                    }

                    $riderId = $db->insert('riders', [
                        'firstname' => $firstName,
                        'lastname' => $lastName,
                        'club_id' => $clubId,
                        'active' => 1
                    ]);
                } else {
                    $riderId = $rider['id'];
                }

                // Check if result already exists
                $existing = $db->getRow(
                    "SELECT id FROM results WHERE event_id = ? AND cyclist_id = ? AND class_id = ?",
                    [$eventId, $riderId, $classId]
                );

                if ($existing) {
                    // Update with manual points
                    $db->update('results', [
                        'position' => $position,
                        'points' => $points,
                        'status' => 'finished'
                    ], 'id = ?', [$existing['id']]);
                } else {
                    // Insert with manual points
                    $db->insert('results', [
                        'event_id' => $eventId,
                        'cyclist_id' => $riderId,
                        'class_id' => $classId,
                        'position' => $position,
                        'points' => $points,
                        'status' => 'finished'
                    ]);
                }

                $imported++;

            } catch (Exception $e) {
                $errors[] = "Rad " . ($lineNum + 1) . ": " . $e->getMessage();
            }
        }

        if ($imported > 0) {
            $message = "$imported resultat importerade med manuella poäng!";
            if (!empty($errors)) {
                $message .= " (" . count($errors) . " fel)";
            }
            $messageType = 'success';
        } else {
            $message = "Ingen data importerades. " . implode(", ", array_slice($errors, 0, 5));
            $messageType = 'error';
        }
    }
}

$page_title = 'Import DS Resultat';
$breadcrumbs = [
    ['label' => 'Import', 'url' => '/admin/import.php'],
    ['label' => 'Dual Slalom Resultat']
];

include __DIR__ . '/components/unified-layout.php';
?>

<?php if ($message): ?>
<div class="alert alert-<?= $messageType ?> mb-lg">
    <?= h($message) ?>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
        <h2>
            <i data-lucide="git-branch"></i>
            Importera DS Resultat
        </h2>
        <a href="?download_template=1" class="btn btn--secondary btn--sm">
            <i data-lucide="download"></i>
            Ladda ner mall
        </a>
    </div>
    <div class="card-body">
        <form method="POST">
            <?= csrf_field() ?>

            <div class="form-group mb-lg">
                <label class="label">1. Välj Event</label>
                <select name="event_id" class="input" required style="max-width: 400px;">
                    <option value="">-- Välj event --</option>
                    <?php foreach ($events as $e): ?>
                    <option value="<?= $e['id'] ?>">
                        <?= h($e['name']) ?> (<?= date('Y-m-d', strtotime($e['date'])) ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group mb-lg">
                <label class="label">2. Klistra in CSV-data</label>
                <textarea name="csv_data" class="input" rows="15" placeholder="Klass;Placering;Förnamn;Efternamn;Klubb;Poäng
Herrar Elite;1;Johan;Andersson;Stockholm CK;100
Herrar Elite;2;Erik;Svensson;Göteborg MTB;80
Damer Elite;1;Anna;Johansson;Uppsala CK;100
..." style="font-family: monospace; font-size: 13px;"></textarea>
                <p class="text-sm text-secondary mt-sm">
                    Format: <code>Klass;Placering;Förnamn;Efternamn;Klubb;Poäng</code>
                </p>
            </div>

            <button type="submit" name="import" class="btn btn--primary btn-lg">
                <i data-lucide="upload"></i>
                Importera
            </button>
        </form>
    </div>
</div>

<!-- Quick Reference -->
<div class="card mt-lg">
    <div class="card-header">
        <h3><i data-lucide="info"></i> Exempeldata</h3>
    </div>
    <div class="card-body">
        <pre class="gs-code-dark" style="font-size: 13px;">Klass;Placering;Förnamn;Efternamn;Klubb;Poäng
Herrar Elite;1;Johan;Andersson;Stockholm CK;100
Herrar Elite;2;Erik;Svensson;Göteborg MTB;80
Herrar Elite;3;Anders;Nilsson;;65
Herrar Elite;4;Peter;Lindqvist;Malmö CK;55
Damer Elite;1;Anna;Johansson;Uppsala CK;100
Damer Elite;2;Maria;Eriksson;;80
Herrar Junior;1;Oscar;Berg;Lund CK;100
Herrar Junior;2;Viktor;Ström;;80</pre>
        <p class="text-sm text-secondary mt-md">
            <strong>Poäng sätts manuellt</strong> - systemet räknar inte ut dem automatiskt.
            Klubb kan lämnas tom.
        </p>
    </div>
</div>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>

<?php
/**
 * Import Dual Slalom Results
 * CSV format: Eventklass, Placering, Namn, Klubb, Serieklass, Poäng
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
    echo "Eventklass;Placering;Namn;Klubb;Serieklass;Poäng\n";
    echo "Flickor 13-16;1;Ebba Myrefelt;Borgholm CK;Flickor 13-14;100\n";
    echo "Flickor 13-16;2;Elsa Sporre Friberg;CK Fix;Flickor 13-14;80\n";
    echo "Pojkar 13-16;1;Theodor Ek;CK Fix;Pojkar 15-16;100\n";
    echo "Pojkar 13-16;2;Arvid Andersson;Falkenbergs CK;Pojkar 15-16;80\n";
    echo "Sportmotion Dam;1;Ella Mårtensson;Borås CA;Damer Junior;100\n";
    echo "Sportmotion Herr;1;Ivan Bergström;RND Racing;Herrar Junior;100\n";
    exit;
}

// Get events
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

// Get all classes for reference
$allClasses = $db->getAll("SELECT id, display_name, name FROM classes WHERE active = 1 ORDER BY sort_order, display_name");

// Helper function to split name
function splitName($fullName) {
    $fullName = trim($fullName);
    $parts = preg_split('/\s+/', $fullName, 2);
    $firstName = $parts[0] ?? '';
    $lastName = $parts[1] ?? '';
    return [$firstName, $lastName];
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
        $delimiter = "\t"; // Tab first (from Excel/Sheets paste)
        if (substr_count($firstLine, ';') > substr_count($firstLine, "\t")) {
            $delimiter = ';';
        } elseif (substr_count($firstLine, ',') > substr_count($firstLine, "\t") && substr_count($firstLine, ',') > substr_count($firstLine, ';')) {
            $delimiter = ',';
        }

        // Check if first line is header
        $firstLineLower = strtolower($firstLine);
        $hasHeader = (strpos($firstLineLower, 'klass') !== false ||
                      strpos($firstLineLower, 'plac') !== false ||
                      strpos($firstLineLower, 'namn') !== false ||
                      strpos($firstLineLower, 'poäng') !== false);

        if ($hasHeader) {
            array_shift($lines);
        }

        foreach ($lines as $lineNum => $line) {
            $line = trim($line);
            if (empty($line)) continue;

            $cols = str_getcsv($line, $delimiter);

            // Expected: Eventklass, Placering, Namn, Klubb, Serieklass, Poäng
            if (count($cols) < 5) {
                $errors[] = "Rad " . ($lineNum + 1) . ": För få kolumner (behöver minst 5)";
                continue;
            }

            $eventClassName = trim($cols[0]);
            $position = (int)trim($cols[1]);
            $fullName = trim($cols[2]);
            $clubName = isset($cols[3]) ? trim($cols[3]) : '';
            $seriesClassName = trim($cols[4]);
            $points = isset($cols[5]) ? (float)trim($cols[5]) : 0;

            // Split name
            list($firstName, $lastName) = splitName($fullName);

            if (empty($firstName)) {
                $errors[] = "Rad " . ($lineNum + 1) . ": Saknar namn";
                continue;
            }

            if ($position < 1 || $position > 200) {
                $errors[] = "Rad " . ($lineNum + 1) . ": Ogiltig placering: $position";
                continue;
            }

            try {
                // Find or create EVENT class
                $eventClass = $db->getRow(
                    "SELECT id FROM classes WHERE LOWER(display_name) = LOWER(?) OR LOWER(name) = LOWER(?)",
                    [$eventClassName, $eventClassName]
                );

                if (!$eventClass) {
                    $eventClassId = $db->insert('classes', [
                        'name' => strtolower(str_replace(' ', '_', $eventClassName)),
                        'display_name' => $eventClassName,
                        'active' => 1,
                        'sort_order' => 900
                    ]);
                } else {
                    $eventClassId = $eventClass['id'];
                }

                // Find or create SERIES class
                $seriesClass = $db->getRow(
                    "SELECT id FROM classes WHERE LOWER(display_name) = LOWER(?) OR LOWER(name) = LOWER(?)",
                    [$seriesClassName, $seriesClassName]
                );

                if (!$seriesClass) {
                    $seriesClassId = $db->insert('classes', [
                        'name' => strtolower(str_replace(' ', '_', $seriesClassName)),
                        'display_name' => $seriesClassName,
                        'active' => 1,
                        'sort_order' => 950
                    ]);
                } else {
                    $seriesClassId = $seriesClass['id'];
                }

                // Find or create rider
                $rider = $db->getRow(
                    "SELECT id FROM riders WHERE UPPER(firstname) = UPPER(?) AND UPPER(lastname) = UPPER(?)",
                    [$firstName, $lastName]
                );

                if (!$rider) {
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
                    "SELECT id FROM results WHERE event_id = ? AND cyclist_id = ?",
                    [$eventId, $riderId]
                );

                $resultData = [
                    'position' => $position,
                    'class_id' => $eventClassId,
                    'series_class_id' => $seriesClassId,
                    'points' => $points,
                    'status' => 'finished'
                ];

                if ($existing) {
                    $db->update('results', $resultData, 'id = ?', [$existing['id']]);
                } else {
                    $resultData['event_id'] = $eventId;
                    $resultData['cyclist_id'] = $riderId;
                    $db->insert('results', $resultData);
                }

                $imported++;

            } catch (Exception $e) {
                $errors[] = "Rad " . ($lineNum + 1) . ": " . $e->getMessage();
            }
        }

        if ($imported > 0) {
            $message = "$imported resultat importerade!";
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
        <div class="alert alert-info mb-lg">
            <strong>Format:</strong> Eventklass · Placering · Namn · Klubb · Serieklass · Poäng
        </div>

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
                <label class="label">2. Klistra in data</label>
                <textarea name="csv_data" class="input" rows="18" placeholder="Eventklass;Placering;Namn;Klubb;Serieklass;Poäng
Flickor 13-16;1;Ebba Myrefelt;Borgholm CK;Flickor 13-14;100
Flickor 13-16;2;Elsa Sporre Friberg;CK Fix;Flickor 13-14;80
Pojkar 13-16;1;Theodor Ek;CK Fix;Pojkar 15-16;100
Sportmotion Dam;1;Ella Mårtensson;Borås CA;Damer Junior;100
Sportmotion Herr;1;Ivan Bergström;RND Racing;Herrar Junior;100
..." style="font-family: monospace; font-size: 13px;"></textarea>
                <p class="text-sm text-secondary mt-sm">
                    Stöder tab, semikolon och komma som separator.
                </p>
            </div>

            <button type="submit" name="import" class="btn btn--primary btn-lg">
                <i data-lucide="upload"></i>
                Importera
            </button>
        </form>
    </div>
</div>

<!-- Example based on user's data -->
<div class="card mt-lg">
    <div class="card-header">
        <h3><i data-lucide="info"></i> Exempeldata (baserat på din lista)</h3>
    </div>
    <div class="card-body">
        <pre class="gs-code-dark" style="font-size: 12px; overflow-x: auto;">Eventklass;Placering;Namn;Klubb;Serieklass;Poäng
Flickor 13-16;1;Ebba Myrefelt;Borgholm CK;Flickor 13-14;100
Flickor 13-16;2;Elsa Sporre Friberg;CK Fix;Flickor 13-14;80
Flickor 13-16;3;Sara Warnevik;;Flickor 13-14;65
Pojkar 13-16;1;Theodor Ek;CK Fix;Pojkar 15-16;100
Pojkar 13-16;2;Arvid Andersson;Falkenbergs CK;Pojkar 15-16;80
Pojkar 13-16;3;Leo Drotz;Mera Lera MTB;Pojkar 15-16;65
Sportmotion Dam;1;Ella Mårtensson;Borås CA;Damer Junior;100
Sportmotion Dam;2;Iris Fehrm;Ulricehamns CK;Flickor 15-16;80
Sportmotion Herr;1;Ivan Bergström;RND Racing;Herrar Junior;100
Sportmotion Herr;2;Simon Carlsén;Ulricehamns CK;Herrar Junior;80</pre>
        <p class="text-sm text-secondary mt-md">
            <strong>Eventklass</strong> = De 4 klasserna som visas på resultatsidan<br>
            <strong>Serieklass</strong> = Åkarens riktiga klass där poängen räknas
        </p>
    </div>
</div>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>

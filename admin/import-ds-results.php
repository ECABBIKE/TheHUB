<?php
/**
 * Import Dual Slalom Results
 * - Event class (display) + Series class (points)
 * CSV format: Eventklass, Placering, Förnamn, Efternamn, Klubb, Serieklass, Poäng
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
    echo "Eventklass;Placering;Förnamn;Efternamn;Klubb;Serieklass;Poäng\n";
    echo "Sportmotion Herr;1;Johan;Andersson;Stockholm CK;Herrar Elite;100\n";
    echo "Sportmotion Herr;2;Erik;Svensson;Göteborg MTB;Herrar Elite;80\n";
    echo "Sportmotion Herr;3;Anders;Nilsson;;Herrar Junior;65\n";
    echo "Sportmotion Dam;1;Anna;Johansson;Uppsala CK;Damer Elite;100\n";
    echo "Sportmotion Dam;2;Maria;Eriksson;;Damer Elite;80\n";
    echo "Pojkar 13-16;1;Oscar;Berg;Malmö CK;Herrar Junior;100\n";
    echo "Flickor 13-16;1;Lisa;Ström;;Damer Junior;100\n";
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
                      strpos($firstLineLower, 'poäng') !== false ||
                      strpos($firstLineLower, 'serie') !== false);

        if ($hasHeader) {
            array_shift($lines);
        }

        foreach ($lines as $lineNum => $line) {
            $line = trim($line);
            if (empty($line)) continue;

            $cols = str_getcsv($line, $delimiter);

            // Expected: Eventklass, Placering, Förnamn, Efternamn, Klubb, Serieklass, Poäng
            if (count($cols) < 6) {
                $errors[] = "Rad " . ($lineNum + 1) . ": För få kolumner (behöver minst 6)";
                continue;
            }

            $eventClassName = trim($cols[0]);
            $position = (int)trim($cols[1]);
            $firstName = trim($cols[2]);
            $lastName = trim($cols[3]);
            $clubName = isset($cols[4]) ? trim($cols[4]) : '';
            $seriesClassName = trim($cols[5]);
            $points = isset($cols[6]) ? (float)trim($cols[6]) : 0;

            if (empty($firstName) || empty($lastName)) {
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
            <strong>Dubbla klasser:</strong> Eventklass visas på resultatsidan, Serieklass används för seriepoäng.
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
                <label class="label">2. Klistra in CSV-data</label>
                <textarea name="csv_data" class="input" rows="15" placeholder="Eventklass;Placering;Förnamn;Efternamn;Klubb;Serieklass;Poäng
Sportmotion Herr;1;Johan;Andersson;Stockholm CK;Herrar Elite;100
Sportmotion Herr;2;Erik;Svensson;;Herrar Elite;80
Sportmotion Dam;1;Anna;Johansson;Uppsala CK;Damer Elite;100
..." style="font-family: monospace; font-size: 13px;"></textarea>
                <p class="text-sm text-secondary mt-sm">
                    Format: <code>Eventklass;Placering;Förnamn;Efternamn;Klubb;Serieklass;Poäng</code>
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
        <pre class="gs-code-dark" style="font-size: 13px;">Eventklass;Placering;Förnamn;Efternamn;Klubb;Serieklass;Poäng
Sportmotion Herr;1;Johan;Andersson;Stockholm CK;Herrar Elite;100
Sportmotion Herr;2;Erik;Svensson;Göteborg MTB;Herrar Elite;80
Sportmotion Herr;3;Anders;Nilsson;;Herrar Junior;65
Sportmotion Dam;1;Anna;Johansson;Uppsala CK;Damer Elite;100
Sportmotion Dam;2;Maria;Eriksson;;Damer Elite;80
Pojkar 13-16;1;Oscar;Berg;Malmö CK;Herrar Junior;100
Flickor 13-16;1;Lisa;Ström;;Damer Junior;100</pre>
        <p class="text-sm text-secondary mt-md">
            <strong>Eventklass</strong> = Visas på resultatsidan (Sportmotion Herr, etc.)<br>
            <strong>Serieklass</strong> = Där poängen räknas i serietabellen (Herrar Elite, etc.)
        </p>
    </div>
</div>

<!-- Available classes -->
<div class="card mt-lg">
    <div class="card-header">
        <h3><i data-lucide="list"></i> Befintliga klasser</h3>
    </div>
    <div class="card-body">
        <div style="display: flex; flex-wrap: wrap; gap: 8px;">
            <?php foreach ($allClasses as $c): ?>
            <span class="badge"><?= h($c['display_name']) ?></span>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>

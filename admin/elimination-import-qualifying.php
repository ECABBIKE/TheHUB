<?php
/**
 * Import Qualifying Results for Elimination Events
 */
require_once __DIR__ . '/../config.php';
require_admin();

global $pdo;
$db = getDB();

$eventId = isset($_GET['event_id']) ? intval($_GET['event_id']) : 0;
$classId = isset($_GET['class_id']) ? intval($_GET['class_id']) : 0;

if (!$eventId) {
    header('Location: /admin/elimination.php');
    exit;
}

// Get event info
$event = $db->getOne("SELECT * FROM events WHERE id = ?", [$eventId]);
if (!$event) {
    $_SESSION['error'] = 'Event hittades inte';
    header('Location: /admin/elimination.php');
    exit;
}

// Get all classes
$classes = $db->getAll("
    SELECT id, name, display_name FROM classes
    WHERE active = 1
    ORDER BY sort_order, name
");

// Handle CSV upload
$importResults = null;
$previewData = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'preview' && isset($_FILES['csv_file'])) {
        $file = $_FILES['csv_file'];

        if ($file['error'] === UPLOAD_ERR_OK) {
            $content = file_get_contents($file['tmp_name']);
            // Detect encoding and convert to UTF-8
            $encoding = mb_detect_encoding($content, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true);
            if ($encoding && $encoding !== 'UTF-8') {
                $content = mb_convert_encoding($content, 'UTF-8', $encoding);
            }

            // Parse CSV
            $lines = array_filter(explode("\n", $content));
            $delimiter = strpos($lines[0], "\t") !== false ? "\t" : (strpos($lines[0], ';') !== false ? ';' : ',');

            $headers = str_getcsv(array_shift($lines), $delimiter);
            $headers = array_map('trim', $headers);
            $headers = array_map('strtolower', $headers);

            // Map common column names
            $columnMap = [
                'startnr' => 'bib',
                'startnummer' => 'bib',
                'bib' => 'bib',
                'nr' => 'bib',
                'namn' => 'name',
                'name' => 'name',
                'förnamn' => 'firstname',
                'firstname' => 'firstname',
                'efternamn' => 'lastname',
                'lastname' => 'lastname',
                'klubb' => 'club',
                'club' => 'club',
                'cykelklubb' => 'club',
                'klass' => 'class',
                'class' => 'class',
                'tävlingsklass' => 'class',
                'category' => 'class',
                'kval 1' => 'run1',
                'kval1' => 'run1',
                'run1' => 'run1',
                'run 1' => 'run1',
                'åk 1' => 'run1',
                'åk1' => 'run1',
                'kval 2' => 'run2',
                'kval2' => 'run2',
                'run2' => 'run2',
                'run 2' => 'run2',
                'åk 2' => 'run2',
                'åk2' => 'run2',
                'tid' => 'best',
                'tid:' => 'best',
                'best' => 'best',
                'bäst' => 'best',
                'totaltid' => 'best',
            ];

            $mappedHeaders = [];
            foreach ($headers as $idx => $h) {
                $normalized = strtolower(trim($h));
                $mappedHeaders[$idx] = $columnMap[$normalized] ?? $normalized;
            }

            // Parse rows
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) continue;

                $cols = str_getcsv($line, $delimiter);
                $row = [];

                foreach ($cols as $idx => $val) {
                    $key = $mappedHeaders[$idx] ?? "col_$idx";
                    $row[$key] = trim($val);
                }

                // Parse times (handle comma decimals)
                if (isset($row['run1'])) {
                    $row['run1'] = floatval(str_replace(',', '.', $row['run1']));
                }
                if (isset($row['run2'])) {
                    $row['run2'] = floatval(str_replace(',', '.', $row['run2']));
                }
                if (isset($row['best'])) {
                    $row['best'] = floatval(str_replace(',', '.', $row['best']));
                }

                // Calculate best time if not provided
                if (empty($row['best']) && !empty($row['run1'])) {
                    $row['best'] = $row['run1'];
                    if (!empty($row['run2']) && $row['run2'] < $row['best']) {
                        $row['best'] = $row['run2'];
                    }
                }

                // Handle combined name field
                if (!empty($row['name']) && empty($row['firstname'])) {
                    $parts = explode(' ', $row['name'], 2);
                    $row['firstname'] = $parts[0] ?? '';
                    $row['lastname'] = $parts[1] ?? '';
                }

                if (!empty($row['firstname']) || !empty($row['name'])) {
                    $previewData[] = $row;
                }
            }

            // Store in session for import
            $_SESSION['elimination_import_preview'] = $previewData;
            $_SESSION['elimination_import_event'] = $eventId;
        }
    }

    if (isset($_POST['action']) && $_POST['action'] === 'import') {
        $importClassId = intval($_POST['class_id'] ?? 0);
        $previewData = $_SESSION['elimination_import_preview'] ?? [];

        if ($importClassId && !empty($previewData)) {
            $imported = 0;
            $errors = [];

            foreach ($previewData as $idx => $row) {
                try {
                    // Find or create rider
                    $firstname = $row['firstname'] ?? '';
                    $lastname = $row['lastname'] ?? '';

                    if (empty($firstname) && !empty($row['name'])) {
                        $parts = explode(' ', $row['name'], 2);
                        $firstname = $parts[0] ?? '';
                        $lastname = $parts[1] ?? '';
                    }

                    if (empty($firstname)) continue;

                    // Try to find existing rider
                    $rider = $db->getOne(
                        "SELECT id FROM riders WHERE firstname = ? AND lastname = ? LIMIT 1",
                        [$firstname, $lastname]
                    );

                    if (!$rider) {
                        // Create new rider
                        $clubId = null;
                        if (!empty($row['club'])) {
                            $club = $db->getOne("SELECT id FROM clubs WHERE name LIKE ? LIMIT 1", ['%' . $row['club'] . '%']);
                            $clubId = $club ? $club['id'] : null;
                        }

                        $stmt = $pdo->prepare("INSERT INTO riders (firstname, lastname, club_id, active) VALUES (?, ?, ?, 1)");
                        $stmt->execute([$firstname, $lastname, $clubId]);
                        $riderId = $pdo->lastInsertId();
                    } else {
                        $riderId = $rider['id'];
                    }

                    // Calculate best time
                    $run1 = floatval($row['run1'] ?? 0);
                    $run2 = floatval($row['run2'] ?? 0);
                    $best = floatval($row['best'] ?? 0);

                    if ($best <= 0) {
                        $best = $run1;
                        if ($run2 > 0 && $run2 < $best) {
                            $best = $run2;
                        }
                    }

                    // Insert qualifying result
                    $stmt = $pdo->prepare("
                        INSERT INTO elimination_qualifying
                        (event_id, class_id, rider_id, bib_number, run_1_time, run_2_time, best_time, status)
                        VALUES (?, ?, ?, ?, ?, ?, ?, 'finished')
                        ON DUPLICATE KEY UPDATE
                        bib_number = VALUES(bib_number),
                        run_1_time = VALUES(run_1_time),
                        run_2_time = VALUES(run_2_time),
                        best_time = VALUES(best_time)
                    ");
                    $stmt->execute([
                        $eventId,
                        $importClassId,
                        $riderId,
                        $row['bib'] ?? null,
                        $run1 > 0 ? $run1 : null,
                        $run2 > 0 ? $run2 : null,
                        $best > 0 ? $best : null
                    ]);

                    $imported++;
                } catch (Exception $e) {
                    $errors[] = "Rad " . ($idx + 1) . ": " . $e->getMessage();
                }
            }

            // Update seed positions based on best_time
            $pdo->prepare("
                SET @pos := 0;
                UPDATE elimination_qualifying
                SET seed_position = (@pos := @pos + 1)
                WHERE event_id = ? AND class_id = ?
                ORDER BY best_time ASC
            ")->execute([$eventId, $importClassId]);

            // Simple sequential update
            $qualifiers = $db->getAll("
                SELECT id FROM elimination_qualifying
                WHERE event_id = ? AND class_id = ?
                ORDER BY best_time ASC
            ", [$eventId, $importClassId]);

            $pos = 1;
            foreach ($qualifiers as $q) {
                $pdo->prepare("UPDATE elimination_qualifying SET seed_position = ? WHERE id = ?")->execute([$pos, $q['id']]);
                $pos++;
            }

            $_SESSION['success'] = "Importerade $imported kvalificeringsresultat!";
            if (!empty($errors)) {
                $_SESSION['warning'] = implode('<br>', array_slice($errors, 0, 5));
            }

            unset($_SESSION['elimination_import_preview']);
            unset($_SESSION['elimination_import_event']);

            header("Location: /admin/elimination-manage.php?event_id={$eventId}&class_id={$importClassId}");
            exit;
        }
    }
}

// Page config
$page_title = 'Importera Kvalresultat';
$breadcrumbs = [
    ['label' => 'Elimination', 'url' => '/admin/elimination.php'],
    ['label' => $event['name'], 'url' => "/admin/elimination-manage.php?event_id={$eventId}"],
    ['label' => 'Importera']
];

include __DIR__ . '/components/unified-layout.php';
?>

<div class="admin-card mb-lg">
    <div class="admin-card-header">
        <h3>Importera Kvalificeringsresultat</h3>
    </div>
    <div class="admin-card-body">
        <p class="mb-md">Ladda upp en CSV-fil med kvalificeringstider. Filen ska innehålla kolumner för:</p>
        <ul class="mb-lg" style="margin-left: var(--space-lg); color: var(--color-text-secondary);">
            <li><strong>Startnr</strong> (valfritt) - Startnummer</li>
            <li><strong>Namn</strong> eller <strong>Förnamn + Efternamn</strong> - Åkarens namn</li>
            <li><strong>Klubb</strong> (valfritt) - Cykelklubb</li>
            <li><strong>Klass</strong> (valfritt) - Tävlingsklass</li>
            <li><strong>Kval 1</strong> - Tid för första kvalåket</li>
            <li><strong>Kval 2</strong> (valfritt) - Tid för andra kvalåket</li>
            <li><strong>TID:</strong> eller <strong>Bäst</strong> (valfritt) - Bästa tid (beräknas automatiskt om ej angiven)</li>
        </ul>

        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="preview">

            <div class="admin-form-group">
                <label class="admin-form-label">Välj CSV-fil</label>
                <input type="file" name="csv_file" accept=".csv,.txt" class="admin-form-input" required>
                <p class="form-help">Stöder CSV med komma, semikolon eller tab som separator.</p>
            </div>

            <button type="submit" class="btn-admin btn-admin-primary">
                <i data-lucide="eye"></i> Förhandsgranska
            </button>
        </form>
    </div>
</div>

<?php if (!empty($previewData)): ?>
<div class="admin-card">
    <div class="admin-card-header">
        <h3>Förhandsgranskning (<?= count($previewData) ?> rader)</h3>
    </div>
    <div class="admin-card-body">
        <form method="POST">
            <input type="hidden" name="action" value="import">

            <div class="admin-form-group mb-lg">
                <label class="admin-form-label">Importera till klass</label>
                <select name="class_id" class="admin-form-select" required>
                    <option value="">-- Välj klass --</option>
                    <?php foreach ($classes as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= $classId == $c['id'] ? 'selected' : '' ?>>
                            <?= h($c['display_name'] ?? $c['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="admin-table-container mb-lg">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Nr</th>
                            <th>Namn</th>
                            <th>Klubb</th>
                            <th>Klass (CSV)</th>
                            <th class="text-right">Kval 1</th>
                            <th class="text-right">Kval 2</th>
                            <th class="text-right">Bäst</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($previewData as $idx => $row): ?>
                            <tr>
                                <td><?= $idx + 1 ?></td>
                                <td><?= h($row['bib'] ?? '-') ?></td>
                                <td>
                                    <?php
                                    $name = '';
                                    if (!empty($row['firstname'])) {
                                        $name = $row['firstname'] . ' ' . ($row['lastname'] ?? '');
                                    } elseif (!empty($row['name'])) {
                                        $name = $row['name'];
                                    }
                                    echo h($name);
                                    ?>
                                </td>
                                <td><?= h($row['club'] ?? '-') ?></td>
                                <td><?= h($row['class'] ?? '-') ?></td>
                                <td class="text-right"><?= isset($row['run1']) && $row['run1'] > 0 ? number_format($row['run1'], 3) : '-' ?></td>
                                <td class="text-right"><?= isset($row['run2']) && $row['run2'] > 0 ? number_format($row['run2'], 3) : '-' ?></td>
                                <td class="text-right"><strong><?= isset($row['best']) && $row['best'] > 0 ? number_format($row['best'], 3) : '-' ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="flex gap-md">
                <button type="submit" class="btn-admin btn-admin-primary">
                    <i data-lucide="upload"></i> Importera <?= count($previewData) ?> resultat
                </button>
                <a href="/admin/elimination-import-qualifying.php?event_id=<?= $eventId ?>" class="btn-admin btn-admin-secondary">
                    Avbryt
                </a>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Example Format -->
<div class="admin-card mt-lg">
    <div class="admin-card-header">
        <h3>Exempelformat</h3>
    </div>
    <div class="admin-card-body">
        <p class="mb-md">Din CSV-fil bör se ut ungefär så här:</p>
        <pre style="background: var(--color-bg-secondary); padding: var(--space-md); border-radius: var(--radius-md); overflow-x: auto; font-size: var(--text-sm);">Startnr;Namn;Klubb;Tävlingsklass;Kval 1;Kval 2;TID:
5;Theodor Ek;CK Fix;Ungdom Pojkar;12,977;13,051;12,977
9;Arvid Andersson;Falkenbergs CK;Ungdom Pojkar;13,625;13,407;13,407
14;Leo Drotz;Mera Lera MTB;Ungdom Pojkar;13,477;13,950;13,477</pre>
    </div>
</div>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>

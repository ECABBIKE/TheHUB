<?php
require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();
$message = '';
$messageType = 'info';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();

    $action = $_POST['action'] ?? '';

    if ($action === 'create_scale') {
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $discipline = $_POST['discipline'] ?? 'ALL';
        $isDHScale = isset($_POST['is_dh_scale']) && $_POST['is_dh_scale'] == '1';

        if (empty($name)) {
            $message = 'Namn är obligatoriskt';
            $messageType = 'error';
        } else {
            try {
                // Insert scale
                $db->insert('point_scales', [
                    'name' => $name,
                    'description' => $description,
                    'discipline' => $discipline,
                    'active' => 1,
                    'is_default' => 0
                ]);

                $scaleId = $db->lastInsertId();

                // Insert point values
                $positions = $_POST['positions'] ?? [];
                $points = $_POST['points'] ?? [];
                $run1Points = $_POST['run_1_points'] ?? [];
                $run2Points = $_POST['run_2_points'] ?? [];

                foreach ($positions as $idx => $position) {
                    if (!empty($position)) {
                        $pointValue = !empty($points[$idx]) ? floatval($points[$idx]) : 0;
                        $run1Value = $isDHScale && !empty($run1Points[$idx]) ? floatval($run1Points[$idx]) : 0;
                        $run2Value = $isDHScale && !empty($run2Points[$idx]) ? floatval($run2Points[$idx]) : 0;

                        $db->insert('point_scale_values', [
                            'scale_id' => $scaleId,
                            'position' => intval($position),
                            'points' => $pointValue,
                            'run_1_points' => $run1Value,
                            'run_2_points' => $run2Value
                        ]);
                    }
                }

                $message = 'Poängmall skapad!';
                $messageType = 'success';
            } catch (Exception $e) {
                $message = 'Ett fel uppstod: ' . $e->getMessage();
                $messageType = 'error';
            }
        }
    }

    // Import from CSV
    if ($action === 'import_scale') {
        $name = trim($_POST['import_name'] ?? '');
        $discipline = $_POST['import_discipline'] ?? 'ALL';
        $isDHScale = isset($_POST['import_is_dh']) && $_POST['import_is_dh'] == '1';

        if (empty($name)) {
            $message = 'Namn är obligatoriskt';
            $messageType = 'error';
        } elseif (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
            $message = 'Ingen fil uppladdad';
            $messageType = 'error';
        } else {
            try {
                $file = $_FILES['import_file']['tmp_name'];
                $handle = fopen($file, 'r');

                if (!$handle) {
                    throw new Exception('Kunde inte öppna filen');
                }

                // Read header row
                $header = fgetcsv($handle, 0, ';');
                if (!$header) {
                    $header = [];
                }

                // Normalize header names
                $headerMap = [];
                foreach ($header as $idx => $col) {
                    $col = strtolower(trim($col));
                    $col = str_replace(['å', 'ä', 'ö'], ['a', 'a', 'o'], $col);
                    if (in_array($col, ['position', 'plac', 'placering', 'pos'])) {
                        $headerMap['position'] = $idx;
                    } elseif (in_array($col, ['poang', 'points', 'p'])) {
                        $headerMap['points'] = $idx;
                    } elseif (in_array($col, ['kval', 'run1', 'run_1', 'kval-poang', 'kvalpoang'])) {
                        $headerMap['run_1'] = $idx;
                    } elseif (in_array($col, ['final', 'run2', 'run_2', 'final-poang', 'finalpoang'])) {
                        $headerMap['run_2'] = $idx;
                    }
                }

                // Create scale
                $db->insert('point_scales', [
                    'name' => $name,
                    'description' => 'Importerad från CSV',
                    'discipline' => $discipline,
                    'active' => 1,
                    'is_default' => 0
                ]);
                $scaleId = $db->lastInsertId();

                // Read data rows
                $rowCount = 0;
                $rowNum = 1;
                while (($row = fgetcsv($handle, 0, ';')) !== false) {
                    $rowNum++;

                    // Get position (required)
                    $position = isset($headerMap['position']) ? intval($row[$headerMap['position']] ?? 0) : $rowNum - 1;

                    if ($position <= 0) {
                        continue;
                    }

                    // Get points
                    $points = isset($headerMap['points']) ? floatval(str_replace(',', '.', $row[$headerMap['points']] ?? 0)) : 0;
                    $run1 = isset($headerMap['run_1']) ? floatval(str_replace(',', '.', $row[$headerMap['run_1']] ?? 0)) : 0;
                    $run2 = isset($headerMap['run_2']) ? floatval(str_replace(',', '.', $row[$headerMap['run_2']] ?? 0)) : 0;

                    // Skip empty rows
                    if ($points == 0 && $run1 == 0 && $run2 == 0) {
                        continue;
                    }

                    $db->insert('point_scale_values', [
                        'scale_id' => $scaleId,
                        'position' => $position,
                        'points' => $isDHScale ? 0 : $points,
                        'run_1_points' => $isDHScale ? $run1 : 0,
                        'run_2_points' => $isDHScale ? $run2 : 0
                    ]);
                    $rowCount++;
                }

                fclose($handle);

                $message = "Importerade poängmall '$name' med $rowCount positioner";
                $messageType = 'success';

            } catch (Exception $e) {
                $message = 'Importfel: ' . $e->getMessage();
                $messageType = 'error';
            }
        }
    }

    // Delete scale
    if ($action === 'delete_scale') {
        $scaleId = (int)($_POST['scale_id'] ?? 0);

        if ($scaleId) {
            try {
                // Check if scale is in use by any events
                $inUse = $db->getRow(
                    "SELECT COUNT(*) as cnt FROM events WHERE point_scale_id = ?",
                    [$scaleId]
                );

                if ($inUse && $inUse['cnt'] > 0) {
                    $message = "Kan inte ta bort poängmallen - den används av {$inUse['cnt']} event";
                    $messageType = 'error';
                } else {
                    // Delete scale values first
                    $db->query("DELETE FROM point_scale_values WHERE scale_id = ?", [$scaleId]);
                    // Then delete the scale
                    $db->query("DELETE FROM point_scales WHERE id = ?", [$scaleId]);

                    $message = 'Poängmall borttagen';
                    $messageType = 'success';
                }
            } catch (Exception $e) {
                $message = 'Fel vid borttagning: ' . $e->getMessage();
                $messageType = 'error';
            }
        }
    }
}

// Get all point scales with value counts
$scales = $db->getAll("
    SELECT
        ps.*,
        COUNT(psv.id) as value_count,
        MAX(psv.position) as max_position,
        SUM(CASE WHEN psv.run_1_points > 0 OR psv.run_2_points > 0 THEN 1 ELSE 0 END) as has_dh_points
    FROM point_scales ps
    LEFT JOIN point_scale_values psv ON ps.id = psv.scale_id
    GROUP BY ps.id
    ORDER BY ps.is_default DESC, ps.name ASC
");

$pageTitle = 'Poängmallar';
$pageType = 'admin';
include __DIR__ . '/../includes/layout-header.php';
?>

<main class="gs-main-content">
    <div class="gs-container">
        <div class="gs-flex gs-justify-between gs-items-center gs-mb-lg">
            <h1 class="gs-h2">
                <i data-lucide="award"></i>
                Poängmallar
            </h1>
            <div class="gs-flex gs-gap-sm">
                <button type="button" class="gs-btn gs-btn-outline" onclick="openImportModal()">
                    <i data-lucide="upload"></i>
                    Importera CSV
                </button>
                <button type="button" class="gs-btn gs-btn-primary" onclick="openCreateModal()">
                    <i data-lucide="plus"></i>
                    Ny Poängmall
                </button>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="gs-alert gs-alert-<?= $messageType ?> gs-mb-lg">
                <i data-lucide="<?= $messageType === 'success' ? 'check-circle' : 'alert-circle' ?>"></i>
                <?= h($message) ?>
            </div>
        <?php endif; ?>

        <div class="gs-alert gs-alert-info gs-mb-lg">
            <i data-lucide="info"></i>
            <strong>Tips:</strong> För SweCUP DH-format, markera "DH-mall med dubbla poäng" och fyll i både Kval och Final-poäng.
        </div>

        <!-- Point Scales Table -->
        <div class="gs-card">
            <div class="gs-card-content gs-table-container gs-table-container-no-padding">
                <table class="gs-table">
                    <thead>
                        <tr>
                            <th>Namn</th>
                            <th>Disciplin</th>
                            <th>Typ</th>
                            <th>Positioner</th>
                            <th>Status</th>
                            <th>Åtgärder</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($scales as $scale): ?>
                            <tr>
                                <td>
                                    <strong class="gs-text-primary"><?= h($scale['name']) ?></strong>
                                    <?php if ($scale['is_default']): ?>
                                        <span class="gs-badge gs-badge-accent gs-badge-sm gs-ml-sm">Standard</span>
                                    <?php endif; ?>
                                    <?php if ($scale['description']): ?>
                                        <br><small class="gs-text-secondary"><?= h($scale['description']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="gs-badge gs-badge-secondary gs-badge-sm">
                                        <?= h($scale['discipline']) ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($scale['has_dh_points'] > 0): ?>
                                        <span class="gs-badge gs-badge-primary gs-badge-sm">DH Dubbla Poäng</span>
                                    <?php else: ?>
                                        <span class="gs-badge gs-badge-secondary gs-badge-sm">Standard</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= $scale['value_count'] ?> (max P<?= $scale['max_position'] ?>)</td>
                                <td>
                                    <?php if ($scale['active']): ?>
                                        <span class="gs-badge gs-badge-success gs-badge-sm">Aktiv</span>
                                    <?php else: ?>
                                        <span class="gs-badge gs-badge-secondary gs-badge-sm">Inaktiv</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="gs-flex gs-gap-xs">
                                        <a href="/admin/point-scale-edit.php?id=<?= $scale['id'] ?>" class="gs-btn gs-btn-sm gs-btn-outline">
                                            <i data-lucide="edit" class="gs-icon-14"></i>
                                            Redigera
                                        </a>
                                        <?php if (!$scale['is_default']): ?>
                                            <form method="POST" style="display: inline;"
                                                  onsubmit="return confirm('Är du säker på att du vill ta bort poängmallen \'<?= h($scale['name']) ?>\'?');">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="delete_scale">
                                                <input type="hidden" name="scale_id" value="<?= $scale['id'] ?>">
                                                <button type="submit" class="gs-btn gs-btn-sm gs-btn-error">
                                                    <i data-lucide="trash-2" class="gs-icon-14"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<!-- Create Scale Modal -->
<div id="createModal" class="gs-modal-overlay-hidden">
    <div class="gs-modal-content-lg">
        <div class="gs-modal-header-sticky">
            <h3 class="gs-h4 gs-margin-0">
                <i data-lucide="plus"></i>
                Skapa Ny Poängmall
            </h3>
            <button type="button" onclick="closeCreateModal()" class="gs-modal-close-btn">
                ×
            </button>
        </div>

        <form method="POST" action="" class="gs-modal-body-padded">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create_scale">

            <div class="gs-grid gs-grid-cols-2 gs-gap-md gs-mb-lg">
                <div>
                    <label class="gs-label">Namn <span class="gs-text-error">*</span></label>
                    <input type="text" name="name" class="gs-input" required>
                </div>

                <div>
                    <label class="gs-label">Disciplin</label>
                    <select name="discipline" class="gs-input">
                        <option value="ALL">Alla</option>
                        <option value="ENDURO">Enduro</option>
                        <option value="DH">Downhill</option>
                        <option value="XCO">XCO</option>
                        <option value="CX">Cyclocross</option>
                    </select>
                </div>
            </div>

            <div class="gs-mb-lg">
                <label class="gs-label">Beskrivning</label>
                <textarea name="description" class="gs-input" rows="2"></textarea>
            </div>

            <div class="gs-mb-lg">
                <label class="gs-checkbox">
                    <input type="checkbox" name="is_dh_scale" value="1" id="isDHScale" onchange="toggleDHColumns()">
                    <span><strong>DH-mall med dubbla poäng</strong> (För SweCUP DH där både Kval och Final ger poäng)</span>
                </label>
            </div>

            <div>
                <label class="gs-label">Poängvärden</label>
                <div class="gs-table-wrapper-scroll">
                    <table class="gs-table gs-table-min-width-600">
                        <thead>
                            <tr>
                                <th class="gs-table-col-width-80">Position</th>
                                <th class="standard-points-col">Poäng</th>
                                <th class="dh-points-col gs-hidden">Kval-Poäng</th>
                                <th class="dh-points-col gs-hidden">Final-Poäng</th>
                            </tr>
                        </thead>
                        <tbody id="pointsTableBody">
                            <?php for ($i = 1; $i <= 50; $i++): ?>
                                <tr>
                                    <td>
                                        <input type="number" name="positions[]" value="<?= $i ?>" class="gs-input gs-input-sm" readonly>
                                    </td>
                                    <td class="standard-points-col">
                                        <input type="number" name="points[]" step="0.01" class="gs-input gs-input-sm" placeholder="0">
                                    </td>
                                    <td class="dh-points-col gs-hidden">
                                        <input type="number" name="run_1_points[]" step="0.01" class="gs-input gs-input-sm" placeholder="0">
                                    </td>
                                    <td class="dh-points-col gs-hidden">
                                        <input type="number" name="run_2_points[]" step="0.01" class="gs-input gs-input-sm" placeholder="0">
                                    </td>
                                </tr>
                            <?php endfor; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="gs-modal-footer-sticky">
                <button type="button" onclick="closeCreateModal()" class="gs-btn gs-btn-outline">
                    Avbryt
                </button>
                <button type="submit" class="gs-btn gs-btn-primary">
                    <i data-lucide="save"></i>
                    Skapa Poängmall
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Import Modal -->
<div id="importModal" class="gs-modal-overlay-hidden" style="z-index: 10000;">
    <div class="gs-modal-content-md">
        <div class="gs-modal-header-sticky">
            <h3 class="gs-h4 gs-text-primary">
                <i data-lucide="upload"></i>
                Importera Poängmall från CSV
            </h3>
            <button type="button" class="gs-modal-close-btn" onclick="closeImportModal()">
                <i data-lucide="x"></i>
            </button>
        </div>
        <form method="POST" enctype="multipart/form-data" class="gs-modal-body-padded">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="import_scale">

            <div class="gs-mb-md">
                <label class="gs-label">Mallnamn *</label>
                <input type="text" name="import_name" class="gs-input" required placeholder="Ex: SweCup Enduro 2025">
            </div>

            <div class="gs-mb-md">
                <label class="gs-label">Disciplin</label>
                <select name="import_discipline" class="gs-input">
                    <option value="ALL">Alla</option>
                    <option value="ENDURO">Enduro</option>
                    <option value="DH">Downhill</option>
                    <option value="XCO">XCO</option>
                    <option value="CX">Cyclocross</option>
                </select>
            </div>

            <div class="gs-mb-md">
                <label class="gs-checkbox">
                    <input type="checkbox" name="import_is_dh" value="1">
                    <span><strong>DH-mall</strong> (använd Kval/Final kolumner)</span>
                </label>
            </div>

            <div class="gs-mb-md">
                <label class="gs-label">CSV-fil *</label>
                <input type="file" name="import_file" class="gs-input" accept=".csv,.txt" required>
                <p class="gs-text-xs gs-text-secondary gs-mt-xs">
                    Kolumner: Position;Poäng eller Position;Kval;Final (semikolon-separerad)
                </p>
            </div>

            <div class="gs-alert gs-alert-info gs-mb-md">
                <i data-lucide="info"></i>
                <div>
                    <strong>CSV-format:</strong><br>
                    Position;Poäng<br>
                    1;520<br>
                    2;480<br>
                    ...<br><br>
                    <strong>DH-format:</strong><br>
                    Position;Kval;Final<br>
                    1;100;520<br>
                    2;90;480
                </div>
            </div>

            <div class="gs-modal-footer-sticky">
                <button type="button" onclick="closeImportModal()" class="gs-btn gs-btn-outline">
                    Avbryt
                </button>
                <button type="submit" class="gs-btn gs-btn-primary">
                    <i data-lucide="upload"></i>
                    Importera
                </button>
            </div>
        </form>
    </div>
</div>

<script src="https://unpkg.com/lucide@latest"></script>
<script>
    lucide.createIcons();

    function openCreateModal() {
        document.getElementById('createModal').style.display = 'flex';
        lucide.createIcons();
    }

    function closeCreateModal() {
        document.getElementById('createModal').style.display = 'none';
    }

    function openImportModal() {
        document.getElementById('importModal').style.display = 'flex';
        lucide.createIcons();
    }

    function closeImportModal() {
        document.getElementById('importModal').style.display = 'none';
    }

    function toggleDHColumns() {
        const isDH = document.getElementById('isDHScale').checked;
        const standardCols = document.querySelectorAll('.standard-points-col');
        const dhCols = document.querySelectorAll('.dh-points-col');

        standardCols.forEach(col => {
            if (isDH) {
                col.classList.add('gs-hidden');
            } else {
                col.classList.remove('gs-hidden');
            }
        });

        dhCols.forEach(col => {
            if (isDH) {
                col.classList.remove('gs-hidden');
            } else {
                col.classList.add('gs-hidden');
            }
        });
    }

    // Close modal on outside click
    document.getElementById('createModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeCreateModal();
        }
    });

    // Close modal on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeCreateModal();
        }
    });
</script>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>

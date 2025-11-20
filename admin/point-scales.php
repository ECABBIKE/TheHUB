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
            <button type="button" class="gs-btn gs-btn-primary" onclick="openCreateModal()">
                <i data-lucide="plus"></i>
                Ny Poängmall
            </button>
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
                                    <a href="/admin/point-scale-edit.php?id=<?= $scale['id'] ?>" class="gs-btn gs-btn-sm gs-btn-outline">
                                        <i data-lucide="edit" class="gs-icon-14"></i>
                                        Redigera
                                    </a>
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

    function toggleDHColumns() {
        const isDH = document.getElementById('isDHScale').checked;
        const standardCols = document.querySelectorAll('.standard-points-col');
        const dhCols = document.querySelectorAll('.dh-points-col');

        standardCols.forEach(col => {
            col.style.display = isDH ? 'none' : '';
        });

        dhCols.forEach(col => {
            col.style.display = isDH ? '' : 'none';
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

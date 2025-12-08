<?php
/**
 * Admin Classes - V3 Design System
 */
require_once __DIR__ . '/../config.php';
require_admin();

global $pdo;
$db = getDB();

// Initialize message variables
$message = '';
$messageType = 'info';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();

    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'update') {
        $name = trim($_POST['name'] ?? '');
        $displayName = trim($_POST['display_name'] ?? '');

        if (empty($name)) {
            $message = 'Klassnamn är obligatoriskt';
            $messageType = 'error';
        } elseif (empty($displayName)) {
            $message = 'Visningsnamn är obligatoriskt';
            $messageType = 'error';
        } else {
            $disciplines = $_POST['disciplines'] ?? [];
            $disciplineString = is_array($disciplines) ? implode(',', $disciplines) : '';

            $classData = [
                'name' => $name,
                'display_name' => $displayName,
                'discipline' => $disciplineString,
                'gender' => trim($_POST['gender'] ?? ''),
                'min_age' => !empty($_POST['min_age']) ? (int)$_POST['min_age'] : null,
                'max_age' => !empty($_POST['max_age']) ? (int)$_POST['max_age'] : null,
                'sort_order' => !empty($_POST['sort_order']) ? (int)$_POST['sort_order'] : 999,
                'active' => isset($_POST['active']) ? 1 : 0,
                'awards_points' => isset($_POST['awards_points']) ? 1 : 0,
                'ranking_type' => in_array($_POST['ranking_type'] ?? 'time', ['time', 'name', 'bib']) ? $_POST['ranking_type'] : 'time',
                'series_eligible' => isset($_POST['series_eligible']) ? 1 : 0,
            ];

            try {
                $db->getRow("SELECT class_category_code FROM classes LIMIT 1");
                $classData['class_category_code'] = !empty($_POST['class_category_code']) ? trim($_POST['class_category_code']) : null;
            } catch (Exception $e) {}

            try {
                if ($action === 'create') {
                    $db->insert('classes', $classData);
                    $message = 'Klass skapad!';
                    $messageType = 'success';
                } else {
                    $id = intval($_POST['id']);
                    $db->update('classes', $classData, 'id = ?', [$id]);
                    $message = 'Klass uppdaterad!';
                    $messageType = 'success';
                }
            } catch (Exception $e) {
                $message = 'Ett fel uppstod: ' . $e->getMessage();
                $messageType = 'error';
            }
        }
    } elseif ($action === 'delete') {
        $id = intval($_POST['id']);
        try {
            $db->delete('classes', 'id = ?', [$id]);
            $message = 'Klass borttagen!';
            $messageType = 'success';
        } catch (Exception $e) {
            $message = 'Ett fel uppstod: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}

// Handle search and filters
$search = $_GET['search'] ?? '';
$disciplineFilter = $_GET['discipline'] ?? '';

// Check if editing
$editClass = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $editClass = $db->getRow("SELECT * FROM classes WHERE id = ?", [intval($_GET['edit'])]);
}

// Build query
$where = [];
$params = [];

if ($search) {
    $where[] = "(name LIKE ? OR display_name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($disciplineFilter) {
    $where[] = "(discipline = ? OR discipline LIKE ? OR discipline LIKE ? OR discipline LIKE ?)";
    $params[] = $disciplineFilter;
    $params[] = $disciplineFilter . ',%';
    $params[] = '%,' . $disciplineFilter . ',%';
    $params[] = '%,' . $disciplineFilter;
}

$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Check for category column
$hasClassCategoryColumn = false;
try {
    $db->getRow("SELECT class_category_code FROM classes LIMIT 1");
    $hasClassCategoryColumn = true;
} catch (Exception $e) {}

$categorySelect = $hasClassCategoryColumn ? "c.class_category_code," : "NULL as class_category_code,";
$sql = "SELECT
    c.id, c.name, c.display_name, c.discipline, c.gender, c.min_age, c.max_age,
    c.sort_order, c.active, c.awards_points, c.ranking_type, c.series_eligible,
    $categorySelect
    COUNT(DISTINCT r.id) as result_count
FROM classes c
LEFT JOIN results r ON c.id = r.class_id
$whereClause
GROUP BY c.id
ORDER BY c.sort_order ASC, c.name ASC";

$classes = $db->getAll($sql, $params);

// Get disciplines for filter
$disciplinesList = $db->getAll("SELECT DISTINCT discipline FROM classes WHERE discipline IS NOT NULL AND discipline != '' ORDER BY discipline");

// Get class categories
$classCategories = [];
try {
    $classCategories = $db->getAll("SELECT code, name FROM class_categories ORDER BY sort_order");
} catch (Exception $e) {}

// Page config
$page_title = 'Klasser';
$breadcrumbs = [['label' => 'Klasser']];
$page_actions = '<a href="/admin/import/classes" class="btn-admin btn-admin-secondary">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" x2="12" y1="3" y2="15"/></svg>
    Importera CSV
</a>
<button onclick="openClassModal()" class="btn-admin btn-admin-primary">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
    Ny Klass
</button>';

include __DIR__ . '/components/unified-layout.php';
?>

<?php if ($message): ?>
    <div class="alert alert-<?= $messageType === 'success' ? 'success' : ($messageType === 'error' ? 'error' : 'info') ?>">
        <?= htmlspecialchars($message) ?>
    </div>
<?php endif; ?>

<!-- Search and Filter -->
<div class="admin-card">
    <div class="admin-card-body">
        <form method="GET" class="admin-form-row" style="align-items: flex-end;">
            <div class="admin-form-group" style="flex: 1; margin-bottom: 0;">
                <label class="admin-form-label">Sök</label>
                <input type="text" name="search" class="admin-form-input" placeholder="Klassnamn..." value="<?= htmlspecialchars($search) ?>">
            </div>
            <div class="admin-form-group" style="margin-bottom: 0;">
                <label class="admin-form-label">Disciplin</label>
                <select name="discipline" class="admin-form-select">
                    <option value="">Alla discipliner</option>
                    <?php foreach ($disciplinesList as $disc): ?>
                        <option value="<?= htmlspecialchars($disc['discipline']) ?>" <?= $disciplineFilter === $disc['discipline'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($disc['discipline']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn-admin btn-admin-primary">Filtrera</button>
            <?php if ($search || $disciplineFilter): ?>
                <a href="/admin/classes" class="btn-admin btn-admin-secondary">Rensa</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- Classes Table -->
<div class="admin-card">
    <div class="admin-card-header">
        <h2><?= count($classes) ?> klasser</h2>
    </div>
    <div class="admin-card-body" style="padding: 0;">
        <?php if (empty($classes)): ?>
            <div class="admin-empty-state">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m12.83 2.18a2 2 0 0 0-1.66 0L2.6 6.08a1 1 0 0 0 0 1.83l8.58 3.91a2 2 0 0 0 1.66 0l8.58-3.9a1 1 0 0 0 0-1.83Z"/><path d="m22 17.65-9.17 4.16a2 2 0 0 1-1.66 0L2 17.65"/><path d="m22 12.65-9.17 4.16a2 2 0 0 1-1.66 0L2 12.65"/></svg>
                <h3>Inga klasser hittades</h3>
                <p>Prova att ändra filter eller skapa en ny klass.</p>
                <button onclick="openClassModal()" class="btn-admin btn-admin-primary">Skapa klass</button>
            </div>
        <?php else: ?>
            <div class="admin-table-container">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Visningsnamn</th>
                            <th>Namn</th>
                            <th>Disciplin</th>
                            <th>Kön</th>
                            <th>Ålder</th>
                            <th>Inställningar</th>
                            <th>Resultat</th>
                            <th>Status</th>
                            <th style="width: 120px;">Åtgärder</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($classes as $class): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($class['display_name']) ?></strong></td>
                                <td style="color: var(--color-text-secondary);"><?= htmlspecialchars($class['name']) ?></td>
                                <td>
                                    <?php if ($class['discipline']):
                                        $discs = explode(',', $class['discipline']);
                                        foreach ($discs as $disc):
                                            $disc = trim($disc);
                                            if ($disc): ?>
                                                <span class="admin-badge admin-badge-secondary"><?= htmlspecialchars($disc) ?></span>
                                            <?php endif;
                                        endforeach;
                                    else: ?>
                                        <span class="admin-badge admin-badge-info">Alla</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($class['gender'] === 'M'): ?>Herr
                                    <?php elseif ($class['gender'] === 'K' || $class['gender'] === 'F'): ?>Dam
                                    <?php else: ?>-<?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($class['min_age'] || $class['max_age']): ?>
                                        <?= $class['min_age'] ?? '∞' ?> - <?= $class['max_age'] ?? '∞' ?>
                                    <?php else: ?>-<?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!$class['awards_points']): ?>
                                        <span class="admin-badge admin-badge-warning">Ingen poäng</span>
                                    <?php endif; ?>
                                    <?php if (!$class['series_eligible']): ?>
                                        <span class="admin-badge admin-badge-secondary">Ej serie</span>
                                    <?php endif; ?>
                                    <?php if ($class['awards_points'] && $class['series_eligible']): ?>
                                        <span style="color: var(--color-text-secondary);">Standard</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= number_format($class['result_count']) ?></td>
                                <td>
                                    <span class="admin-badge <?= $class['active'] ? 'admin-badge-success' : 'admin-badge-secondary' ?>">
                                        <?= $class['active'] ? 'Aktiv' : 'Inaktiv' ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="table-actions">
                                        <button type="button" class="btn-admin btn-admin-sm btn-admin-secondary" onclick='editClass(<?= json_encode($class) ?>)' title="Redigera">
                                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/><path d="m15 5 4 4"/></svg>
                                        </button>
                                        <?php if ($class['result_count'] == 0): ?>
                                            <button onclick="deleteClass(<?= $class['id'] ?>, '<?= addslashes($class['display_name']) ?>')" class="btn-admin btn-admin-sm btn-admin-danger" title="Ta bort">
                                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Class Modal -->
<div id="classModal" class="admin-modal" style="display: none;">
    <div class="admin-modal-overlay" onclick="closeClassModal()"></div>
    <div class="admin-modal-content" style="max-width: 700px;">
        <div class="admin-modal-header">
            <h2 id="modalTitle">Ny Klass</h2>
            <button type="button" class="admin-modal-close" onclick="closeClassModal()">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
            </button>
        </div>
        <form method="POST" id="classForm">
            <?= csrf_field() ?>
            <input type="hidden" name="action" id="formAction" value="create">
            <input type="hidden" name="id" id="classId" value="">

            <div class="admin-modal-body">
                <div class="admin-form-group">
                    <label class="admin-form-label">Visningsnamn <span style="color: var(--color-error);">*</span></label>
                    <input type="text" name="display_name" id="displayName" class="admin-form-input" required placeholder="t.ex. Elite Herr">
                </div>

                <div class="admin-form-group">
                    <label class="admin-form-label">Namn <span style="color: var(--color-error);">*</span></label>
                    <input type="text" name="name" id="name" class="admin-form-input" required placeholder="t.ex. ELITE_M">
                </div>

                <div class="admin-form-group">
                    <label class="admin-form-label">Discipliner</label>
                    <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: var(--space-sm);">
                        <?php foreach (['XC', 'DH', 'ENDURO', 'ROAD', 'TRACK', 'BMX', 'CX', 'GRAVEL'] as $disc): ?>
                            <label class="admin-checkbox-label">
                                <input type="checkbox" name="disciplines[]" value="<?= $disc ?>" class="discipline-checkbox">
                                <span><?= $disc ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="admin-form-row">
                    <div class="admin-form-group">
                        <label class="admin-form-label">Kön</label>
                        <select name="gender" id="gender" class="admin-form-select">
                            <option value="">Alla</option>
                            <option value="M">Herr</option>
                            <option value="K">Dam</option>
                        </select>
                    </div>
                    <div class="admin-form-group">
                        <label class="admin-form-label">Min ålder</label>
                        <input type="number" name="min_age" id="minAge" class="admin-form-input" placeholder="19">
                    </div>
                    <div class="admin-form-group">
                        <label class="admin-form-label">Max ålder</label>
                        <input type="number" name="max_age" id="maxAge" class="admin-form-input" placeholder="29">
                    </div>
                </div>

                <div class="admin-form-group">
                    <label class="admin-form-label">Sorteringsordning</label>
                    <input type="number" name="sort_order" id="sortOrder" class="admin-form-input" value="999" min="0">
                </div>

                <div class="admin-form-group">
                    <label class="admin-checkbox-label">
                        <input type="checkbox" name="active" id="active" checked>
                        <span>Aktiv</span>
                    </label>
                </div>

                <div style="border-top: 1px solid var(--color-border); padding-top: var(--space-md); margin-top: var(--space-md);">
                    <h4 style="margin-bottom: var(--space-md);">Klassinställningar</h4>

                    <div class="admin-form-group">
                        <label class="admin-checkbox-label">
                            <input type="checkbox" name="awards_points" id="awardsPoints" checked>
                            <span>Ger seriepoäng</span>
                        </label>
                    </div>

                    <div class="admin-form-group">
                        <label class="admin-form-label">Sortering i resultat</label>
                        <select name="ranking_type" id="rankingType" class="admin-form-select">
                            <option value="time">Tid (snabbast först)</option>
                            <option value="name">Namn (alfabetiskt)</option>
                            <option value="bib">Startnummer (lägst först)</option>
                        </select>
                    </div>

                    <div class="admin-form-group">
                        <label class="admin-checkbox-label">
                            <input type="checkbox" name="series_eligible" id="seriesEligible" checked>
                            <span>Räknas i serien</span>
                        </label>
                    </div>

                    <?php if (!empty($classCategories)): ?>
                        <div class="admin-form-group">
                            <label class="admin-form-label">Licenskategori</label>
                            <select name="class_category_code" id="classCategoryCode" class="admin-form-select">
                                <option value="">-- Ingen kategori --</option>
                                <?php foreach ($classCategories as $cat): ?>
                                    <option value="<?= htmlspecialchars($cat['code']) ?>"><?= htmlspecialchars($cat['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="admin-modal-footer">
                <button type="button" class="btn-admin btn-admin-secondary" onclick="closeClassModal()">Avbryt</button>
                <button type="submit" class="btn-admin btn-admin-primary" id="submitButton">Skapa Klass</button>
            </div>
        </form>
    </div>
</div>

<script>
const csrfToken = '<?= htmlspecialchars(generate_csrf_token()) ?>';

function openClassModal() {
    document.getElementById('classModal').style.display = 'flex';
    document.getElementById('modalTitle').textContent = 'Ny Klass';
    document.getElementById('submitButton').textContent = 'Skapa Klass';
    document.getElementById('formAction').value = 'create';
    document.getElementById('classForm').reset();
    document.getElementById('classId').value = '';
    document.getElementById('active').checked = true;
    document.getElementById('awardsPoints').checked = true;
    document.getElementById('rankingType').value = 'time';
    document.getElementById('seriesEligible').checked = true;
    document.querySelectorAll('.discipline-checkbox').forEach(cb => cb.checked = false);
}

function closeClassModal() {
    document.getElementById('classModal').style.display = 'none';
}

function editClass(classData) {
    document.getElementById('classModal').style.display = 'flex';
    document.getElementById('modalTitle').textContent = 'Redigera Klass';
    document.getElementById('submitButton').textContent = 'Uppdatera Klass';
    document.getElementById('formAction').value = 'update';
    document.getElementById('classId').value = classData.id;
    document.getElementById('name').value = classData.name;
    document.getElementById('displayName').value = classData.display_name;
    document.getElementById('gender').value = classData.gender || '';
    document.getElementById('minAge').value = classData.min_age || '';
    document.getElementById('maxAge').value = classData.max_age || '';
    document.getElementById('sortOrder').value = classData.sort_order || 999;
    document.getElementById('active').checked = classData.active == 1;
    document.getElementById('awardsPoints').checked = classData.awards_points == 1 || classData.awards_points === null;
    document.getElementById('rankingType').value = classData.ranking_type || 'time';
    document.getElementById('seriesEligible').checked = classData.series_eligible == 1 || classData.series_eligible === null;

    const categorySelect = document.getElementById('classCategoryCode');
    if (categorySelect) {
        categorySelect.value = classData.class_category_code || '';
    }

    document.querySelectorAll('.discipline-checkbox').forEach(cb => cb.checked = false);
    if (classData.discipline) {
        const disciplines = classData.discipline.split(',').map(d => d.trim());
        document.querySelectorAll('.discipline-checkbox').forEach(cb => {
            if (disciplines.includes(cb.value)) cb.checked = true;
        });
    }
}

function deleteClass(id, name) {
    if (!confirm('Är du säker på att du vill ta bort "' + name + '"?')) return;

    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = '<input type="hidden" name="action" value="delete">' +
                     '<input type="hidden" name="id" value="' + id + '">' +
                     '<input type="hidden" name="csrf_token" value="' + csrfToken + '">';
    document.body.appendChild(form);
    form.submit();
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeClassModal();
});
</script>

<style>
.admin-modal { position: fixed; top: 0; left: 0; right: 0; bottom: 0; z-index: 1000; display: flex; align-items: center; justify-content: center; }
.admin-modal-overlay { position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0, 0, 0, 0.5); }
.admin-modal-content { position: relative; background: var(--color-bg-surface, #ffffff); border-radius: var(--radius-lg); box-shadow: var(--shadow-xl); width: 90%; max-width: 600px; max-height: 90vh; overflow: hidden; display: flex; flex-direction: column; }
.admin-modal-header { display: flex; align-items: center; justify-content: space-between; padding: var(--space-lg); border-bottom: 1px solid var(--color-border); }
.admin-modal-header h2 { margin: 0; font-size: var(--text-xl); }
.admin-modal-close { background: none; border: none; padding: var(--space-xs); cursor: pointer; color: var(--color-text-secondary); border-radius: var(--radius-sm); }
.admin-modal-close:hover { background: var(--color-bg-tertiary); color: var(--color-text); }
.admin-modal-close svg { width: 20px; height: 20px; }
.admin-modal-body { padding: var(--space-lg); overflow-y: auto; flex: 1; }
.admin-modal-footer { display: flex; justify-content: flex-end; gap: var(--space-sm); padding: var(--space-lg); border-top: 1px solid var(--color-border); }
.admin-checkbox-label { display: flex; align-items: center; gap: var(--space-xs); cursor: pointer; font-size: var(--text-sm); }
.admin-checkbox-label input[type="checkbox"] { width: 16px; height: 16px; accent-color: var(--color-accent); }
</style>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>

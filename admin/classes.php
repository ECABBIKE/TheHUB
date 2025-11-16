<?php
require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();
$current_admin = get_current_admin();

// Initialize message variables
$message = '';
$messageType = 'info';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();

    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'update') {
        // Validate required fields
        $name = trim($_POST['name'] ?? '');
        $displayName = trim($_POST['display_name'] ?? '');

        if (empty($name)) {
            $message = 'Klassnamn är obligatoriskt';
            $messageType = 'error';
        } elseif (empty($displayName)) {
            $message = 'Visningsnamn är obligatoriskt';
            $messageType = 'error';
        } else {
            // Handle multiple disciplines (checkboxes)
            $disciplines = $_POST['disciplines'] ?? [];
            $disciplineString = is_array($disciplines) ? implode(',', $disciplines) : '';

            // Prepare class data
            $classData = [
                'name' => $name,
                'display_name' => $displayName,
                'discipline' => $disciplineString,
                'gender' => trim($_POST['gender'] ?? ''),
                'min_age' => !empty($_POST['min_age']) ? (int)$_POST['min_age'] : null,
                'max_age' => !empty($_POST['max_age']) ? (int)$_POST['max_age'] : null,
                'sort_order' => !empty($_POST['sort_order']) ? (int)$_POST['sort_order'] : 999,
                'active' => isset($_POST['active']) ? 1 : 0,
            ];

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

// Check if editing a class
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
    // Support filtering by discipline when stored as comma-separated list
    $where[] = "(discipline = ? OR discipline LIKE ? OR discipline LIKE ? OR discipline LIKE ?)";
    $params[] = $disciplineFilter; // Exact match
    $params[] = $disciplineFilter . ',%'; // Start of list
    $params[] = '%,' . $disciplineFilter . ',%'; // Middle of list
    $params[] = '%,' . $disciplineFilter; // End of list
}

$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Get classes with result count
$sql = "SELECT
            c.id,
            c.name,
            c.display_name,
            c.discipline,
            c.gender,
            c.min_age,
            c.max_age,
            c.sort_order,
            c.active,
            COUNT(DISTINCT r.id) as result_count
        FROM classes c
        LEFT JOIN results r ON c.id = r.class_id
        $whereClause
        GROUP BY c.id
        ORDER BY c.sort_order ASC, c.name ASC";

$classes = $db->getAll($sql, $params);

// Get all unique disciplines for filter
$disciplines = $db->getAll("SELECT DISTINCT discipline FROM classes WHERE discipline IS NOT NULL AND discipline != '' ORDER BY discipline");

$pageTitle = 'Klasser';
$pageType = 'admin';
include __DIR__ . '/../includes/layout-header.php';
?>

    <main class="gs-content-with-sidebar">
        <div class="gs-container">
            <!-- Header -->
            <div class="gs-flex gs-items-center gs-justify-between gs-mb-xl">
                <h1 class="gs-h1 gs-text-primary">
                    <i data-lucide="layers"></i>
                    Klasser
                </h1>
                <div class="gs-flex gs-gap-sm">
                    <a href="/admin/import-classes.php" class="gs-btn gs-btn-outline">
                        <i data-lucide="upload"></i>
                        Importera CSV
                    </a>
                    <button type="button" class="gs-btn gs-btn-primary" onclick="openClassModal()">
                        <i data-lucide="plus"></i>
                        Ny Klass
                    </button>
                </div>
            </div>

            <!-- Messages -->
            <?php if ($message): ?>
                <div class="gs-alert gs-alert-<?= $messageType ?> gs-mb-lg">
                    <i data-lucide="<?= $messageType === 'success' ? 'check-circle' : ($messageType === 'error' ? 'alert-circle' : 'info') ?>"></i>
                    <?= h($message) ?>
                </div>
            <?php endif; ?>

            <!-- Search and Filter -->
            <div class="gs-card gs-mb-lg">
                <div class="gs-card-content">
                    <form method="GET" class="gs-grid gs-grid-cols-1 gs-md-grid-cols-3 gs-gap-md">
                        <div class="gs-form-group">
                            <label class="gs-label">Sök</label>
                            <input type="text"
                                   name="search"
                                   class="gs-input"
                                   placeholder="Klassnamn..."
                                   value="<?= h($search) ?>">
                        </div>
                        <div class="gs-form-group">
                            <label class="gs-label">Disciplin</label>
                            <select name="discipline" class="gs-input">
                                <option value="">Alla discipliner</option>
                                <?php foreach ($disciplines as $disc): ?>
                                    <option value="<?= h($disc['discipline']) ?>"
                                            <?= $disciplineFilter === $disc['discipline'] ? 'selected' : '' ?>>
                                        <?= h($disc['discipline']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="gs-form-group gs-flex gs-items-end">
                            <button type="submit" class="gs-btn gs-btn-primary gs-mr-sm">
                                <i data-lucide="search"></i>
                                Filtrera
                            </button>
                            <?php if ($search || $disciplineFilter): ?>
                                <a href="/admin/classes.php" class="gs-btn gs-btn-outline">
                                    <i data-lucide="x"></i>
                                    Rensa
                                </a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Classes List -->
            <div class="gs-card">
                <div class="gs-card-header">
                    <h2 class="gs-h4">
                        <i data-lucide="list"></i>
                        Alla Klasser (<?= count($classes) ?>)
                    </h2>
                </div>
                <div class="gs-card-content" style="padding: 0;">
                    <?php if (empty($classes)): ?>
                        <div style="padding: 3rem; text-align: center;">
                            <i data-lucide="inbox" style="width: 48px; height: 48px; margin: 0 auto 1rem; opacity: 0.3;"></i>
                            <p class="gs-text-secondary">
                                <?php if ($search || $disciplineFilter): ?>
                                    Inga klasser hittades med valda filter
                                <?php else: ?>
                                    Inga klasser ännu
                                <?php endif; ?>
                            </p>
                        </div>
                    <?php else: ?>
                        <div class="gs-table-responsive">
                            <table class="gs-table">
                                <thead>
                                    <tr>
                                        <th>Visningsnamn</th>
                                        <th>Namn</th>
                                        <th>Disciplin</th>
                                        <th>Kön</th>
                                        <th>Åldersintervall</th>
                                        <th>Sortering</th>
                                        <th>Resultat</th>
                                        <th>Status</th>
                                        <th class="gs-text-right">Åtgärder</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($classes as $class): ?>
                                        <tr>
                                            <td><strong><?= h($class['display_name']) ?></strong></td>
                                            <td><?= h($class['name']) ?></td>
                                            <td>
                                                <?php if ($class['discipline']): ?>
                                                    <?php
                                                    $disciplines = explode(',', $class['discipline']);
                                                    foreach ($disciplines as $disc):
                                                        $disc = trim($disc);
                                                        if ($disc):
                                                    ?>
                                                        <span class="gs-badge gs-badge-secondary" style="margin-right: 4px;">
                                                            <?= h($disc) ?>
                                                        </span>
                                                    <?php
                                                        endif;
                                                    endforeach;
                                                    ?>
                                                <?php else: ?>
                                                    <span class="gs-badge gs-badge-info">Alla</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($class['gender'] === 'M'): ?>
                                                    Herr
                                                <?php elseif ($class['gender'] === 'K' || $class['gender'] === 'F'): ?>
                                                    Dam
                                                <?php else: ?>
                                                    <span class="gs-text-secondary">–</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($class['min_age'] || $class['max_age']): ?>
                                                    <?= $class['min_age'] ?? '∞' ?> - <?= $class['max_age'] ?? '∞' ?>
                                                <?php else: ?>
                                                    <span class="gs-text-secondary">–</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= $class['sort_order'] ?></td>
                                            <td><?= number_format($class['result_count']) ?></td>
                                            <td>
                                                <?php if ($class['active']): ?>
                                                    <span class="gs-badge gs-badge-success">Aktiv</span>
                                                <?php else: ?>
                                                    <span class="gs-badge gs-badge-secondary">Inaktiv</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="gs-text-right">
                                                <button type="button"
                                                        class="gs-btn gs-btn-sm gs-btn-outline"
                                                        onclick='editClass(<?= json_encode($class) ?>)'>
                                                    <i data-lucide="edit-2"></i>
                                                    Redigera
                                                </button>
                                                <?php if ($class['result_count'] == 0): ?>
                                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Är du säker på att du vill ta bort denna klass?');">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="id" value="<?= $class['id'] ?>">
                                                        <button type="submit" class="gs-btn gs-btn-sm gs-btn-danger">
                                                            <i data-lucide="trash-2"></i>
                                                            Ta bort
                                                        </button>
                                                    </form>
                                                <?php else: ?>
                                                    <button type="button"
                                                            class="gs-btn gs-btn-sm gs-btn-outline"
                                                            disabled
                                                            title="Kan inte ta bort klass med resultat">
                                                        <i data-lucide="trash-2"></i>
                                                        Ta bort
                                                    </button>
                                                <?php endif; ?>
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
            <div id="classModal" class="gs-modal" style="display: none;">
                <div class="gs-modal-overlay" onclick="closeClassModal()"></div>
                <div class="gs-modal-content" style="max-width: 700px;">
                    <div class="gs-modal-header">
                        <h2 class="gs-modal-title" id="modalTitle">
                            <i data-lucide="layers"></i>
                            <span id="modalTitleText">Ny Klass</span>
                        </h2>
                        <button type="button" class="gs-modal-close" onclick="closeClassModal()">
                            <i data-lucide="x"></i>
                        </button>
                    </div>
                    <form method="POST" id="classForm">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" id="formAction" value="create">
                        <input type="hidden" name="id" id="classId" value="">

                        <div class="gs-modal-body">
                            <div class="gs-grid gs-grid-cols-1 gs-gap-md">
                                <!-- Display Name (Required) -->
                                <div class="gs-form-group">
                                    <label class="gs-label gs-label-required">Visningsnamn</label>
                                    <input type="text"
                                           name="display_name"
                                           id="displayName"
                                           class="gs-input"
                                           placeholder="t.ex. Elite Herr"
                                           required>
                                    <small class="gs-text-secondary">Namnet som visas publikt</small>
                                </div>

                                <!-- Name (Required) -->
                                <div class="gs-form-group">
                                    <label class="gs-label gs-label-required">Namn</label>
                                    <input type="text"
                                           name="name"
                                           id="name"
                                           class="gs-input"
                                           placeholder="t.ex. ELITE_M"
                                           required>
                                    <small class="gs-text-secondary">Internt namn (får inte innehålla mellanslag)</small>
                                </div>

                                <!-- Disciplines (Multiple) -->
                                <div class="gs-form-group">
                                    <label class="gs-label">Discipliner</label>
                                    <p class="gs-text-secondary gs-text-sm gs-mb-sm">Välj vilka discipliner denna klass gäller för (tom = alla)</p>
                                    <div class="gs-grid gs-grid-cols-2 gs-gap-sm">
                                        <label class="gs-checkbox-label">
                                            <input type="checkbox" name="disciplines[]" value="XC" class="discipline-checkbox">
                                            <span>XC (Cross-Country)</span>
                                        </label>
                                        <label class="gs-checkbox-label">
                                            <input type="checkbox" name="disciplines[]" value="DH" class="discipline-checkbox">
                                            <span>DH (Downhill)</span>
                                        </label>
                                        <label class="gs-checkbox-label">
                                            <input type="checkbox" name="disciplines[]" value="ENDURO" class="discipline-checkbox">
                                            <span>Enduro</span>
                                        </label>
                                        <label class="gs-checkbox-label">
                                            <input type="checkbox" name="disciplines[]" value="ROAD" class="discipline-checkbox">
                                            <span>Road (Landsväg)</span>
                                        </label>
                                        <label class="gs-checkbox-label">
                                            <input type="checkbox" name="disciplines[]" value="TRACK" class="discipline-checkbox">
                                            <span>Track (Bana)</span>
                                        </label>
                                        <label class="gs-checkbox-label">
                                            <input type="checkbox" name="disciplines[]" value="BMX" class="discipline-checkbox">
                                            <span>BMX</span>
                                        </label>
                                        <label class="gs-checkbox-label">
                                            <input type="checkbox" name="disciplines[]" value="CX" class="discipline-checkbox">
                                            <span>CX (Cyclocross)</span>
                                        </label>
                                        <label class="gs-checkbox-label">
                                            <input type="checkbox" name="disciplines[]" value="GRAVEL" class="discipline-checkbox">
                                            <span>Gravel</span>
                                        </label>
                                    </div>
                                </div>

                                <!-- Gender and Age -->
                                <div class="gs-grid gs-grid-cols-3 gs-gap-md">
                                    <div class="gs-form-group">
                                        <label class="gs-label">Kön</label>
                                        <select name="gender" id="gender" class="gs-input">
                                            <option value="">Alla</option>
                                            <option value="M">Herr</option>
                                            <option value="K">Dam</option>
                                        </select>
                                    </div>
                                    <div class="gs-form-group">
                                        <label class="gs-label">Min ålder</label>
                                        <input type="number"
                                               name="min_age"
                                               id="minAge"
                                               class="gs-input"
                                               placeholder="t.ex. 19">
                                    </div>
                                    <div class="gs-form-group">
                                        <label class="gs-label">Max ålder</label>
                                        <input type="number"
                                               name="max_age"
                                               id="maxAge"
                                               class="gs-input"
                                               placeholder="t.ex. 29">
                                    </div>
                                </div>

                                <!-- Sort Order -->
                                <div class="gs-form-group">
                                    <label class="gs-label">Sorteringsordning</label>
                                    <input type="number"
                                           name="sort_order"
                                           id="sortOrder"
                                           class="gs-input"
                                           value="999"
                                           min="0">
                                    <small class="gs-text-secondary">Lägre nummer visas först</small>
                                </div>

                                <!-- Active -->
                                <div class="gs-form-group">
                                    <label class="gs-checkbox-label">
                                        <input type="checkbox"
                                               name="active"
                                               id="active"
                                               checked>
                                        <span>Aktiv</span>
                                    </label>
                                    <small class="gs-text-secondary">Inaktiva klasser visas inte i dropdown-listor</small>
                                </div>
                            </div>
                        </div>

                        <div class="gs-modal-footer">
                            <button type="button" class="gs-btn gs-btn-outline" onclick="closeClassModal()">
                                Avbryt
                            </button>
                            <button type="submit" class="gs-btn gs-btn-primary">
                                <i data-lucide="save"></i>
                                <span id="submitButtonText">Skapa Klass</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </main>

<script>
function openClassModal() {
    document.getElementById('classModal').style.display = 'flex';
    document.getElementById('modalTitleText').textContent = 'Ny Klass';
    document.getElementById('submitButtonText').textContent = 'Skapa Klass';
    document.getElementById('formAction').value = 'create';
    document.getElementById('classForm').reset();
    document.getElementById('classId').value = '';
    document.getElementById('active').checked = true;

    // Uncheck all discipline checkboxes
    document.querySelectorAll('.discipline-checkbox').forEach(cb => cb.checked = false);
}

function closeClassModal() {
    document.getElementById('classModal').style.display = 'none';
}

function editClass(classData) {
    document.getElementById('classModal').style.display = 'flex';
    document.getElementById('modalTitleText').textContent = 'Redigera Klass';
    document.getElementById('submitButtonText').textContent = 'Uppdatera Klass';
    document.getElementById('formAction').value = 'update';
    document.getElementById('classId').value = classData.id;
    document.getElementById('name').value = classData.name;
    document.getElementById('displayName').value = classData.display_name;
    document.getElementById('gender').value = classData.gender || '';
    document.getElementById('minAge').value = classData.min_age || '';
    document.getElementById('maxAge').value = classData.max_age || '';
    document.getElementById('sortOrder').value = classData.sort_order || 999;
    document.getElementById('active').checked = classData.active == 1;

    // Uncheck all disciplines first
    document.querySelectorAll('.discipline-checkbox').forEach(cb => cb.checked = false);

    // Check the disciplines for this class (comma-separated string)
    if (classData.discipline) {
        const disciplines = classData.discipline.split(',').map(d => d.trim());
        document.querySelectorAll('.discipline-checkbox').forEach(cb => {
            if (disciplines.includes(cb.value)) {
                cb.checked = true;
            }
        });
    }
}

// Close modal on escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeClassModal();
    }
});
</script>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>

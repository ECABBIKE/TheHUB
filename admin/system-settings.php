<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/point-calculations.php';
require_once __DIR__ . '/../includes/class-calculations.php';
require_admin();

$db = getDB();
$message = '';
$messageType = 'info';

// Get active tab
$activeTab = $_GET['tab'] ?? 'classes';

// Handle class actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    checkCsrf();

    if ($_POST['action'] === 'update_class') {
        $classId = (int)$_POST['class_id'];
        $name = trim($_POST['name']);
        $displayName = trim($_POST['display_name']);
        $gender = $_POST['gender'] ?? 'ALL';
        $minAge = !empty($_POST['min_age']) ? (int)$_POST['min_age'] : null;
        $maxAge = !empty($_POST['max_age']) ? (int)$_POST['max_age'] : null;
        $discipline = $_POST['discipline'] ?? 'ALL';
        $sortOrder = !empty($_POST['sort_order']) ? (int)$_POST['sort_order'] : 0;
        $active = isset($_POST['active']) ? 1 : 0;

        if (empty($name) || empty($displayName)) {
            $message = 'Namn och visningsnamn krävs';
            $messageType = 'error';
        } else {
            $db->update('classes', [
                'name' => $name,
                'display_name' => $displayName,
                'gender' => $gender,
                'min_age' => $minAge,
                'max_age' => $maxAge,
                'discipline' => $discipline,
                'sort_order' => $sortOrder,
                'active' => $active
            ], 'id = ?', [$classId]);

            $message = "Klass '{$name}' uppdaterad!";
            $messageType = 'success';
        }
    } elseif ($_POST['action'] === 'create_class') {
        $name = trim($_POST['name']);
        $displayName = trim($_POST['display_name']);
        $gender = $_POST['gender'] ?? 'ALL';
        $minAge = !empty($_POST['min_age']) ? (int)$_POST['min_age'] : null;
        $maxAge = !empty($_POST['max_age']) ? (int)$_POST['max_age'] : null;
        $discipline = $_POST['discipline'] ?? 'ALL';
        $sortOrder = !empty($_POST['sort_order']) ? (int)$_POST['sort_order'] : 0;
        $active = isset($_POST['active']) ? 1 : 0;

        if (empty($name) || empty($displayName)) {
            $message = 'Namn och visningsnamn krävs';
            $messageType = 'error';
        } else {
            // Check if class name already exists
            $existing = $db->getRow("SELECT id FROM classes WHERE name = ?", [$name]);
            if ($existing) {
                $message = "Klass med namnet '{$name}' finns redan!";
                $messageType = 'error';
            } else {
                $db->insert('classes', [
                    'name' => $name,
                    'display_name' => $displayName,
                    'gender' => $gender,
                    'min_age' => $minAge,
                    'max_age' => $maxAge,
                    'discipline' => $discipline,
                    'sort_order' => $sortOrder,
                    'active' => $active
                ]);

                $message = "Klass '{$name}' skapad!";
                $messageType = 'success';
            }
        }
    }
}

// Get all classes
$classes = $db->getAll("
    SELECT
        c.*,
        ps.name as scale_name,
        (SELECT COUNT(*) FROM results WHERE class_id = c.id) as result_count
    FROM classes c
    LEFT JOIN point_scales ps ON c.point_scale_id = ps.id
    ORDER BY c.sort_order ASC, c.name ASC
");

$pageTitle = 'Systeminställningar';
$pageType = 'admin';
include __DIR__ . '/../includes/layout-header.php';
?>

<main class="gs-main-content">
    <div class="gs-container">
        <div class="gs-flex gs-justify-between gs-items-center gs-mb-lg">
            <h1 class="gs-h2">
                <i data-lucide="settings"></i>
                Systeminställningar
            </h1>
        </div>

        <?php if ($message): ?>
            <div class="gs-alert gs-alert-<?= h($messageType) ?> gs-mb-lg">
                <i data-lucide="<?= $messageType === 'success' ? 'check-circle' : 'alert-circle' ?>"></i>
                <?= h($message) ?>
            </div>
        <?php endif; ?>

        <!-- Tabs -->
        <div class="gs-tabs gs-mb-lg">
            <a href="?tab=classes" class="gs-tab <?= $activeTab === 'classes' ? 'active' : '' ?>">
                <i data-lucide="users"></i>
                Klasser
            </a>
            <a href="?tab=migration" class="gs-tab <?= $activeTab === 'migration' ? 'active' : '' ?>">
                <i data-lucide="database"></i>
                Databasmigrationer
            </a>
        </div>

        <!-- Classes Tab -->
        <?php if ($activeTab === 'classes'): ?>
            <div class="gs-alert gs-alert-info gs-mb-lg">
                <i data-lucide="info"></i>
                <strong>Tips:</strong> Alla klasser kan redigeras. Klicka på en klass för att ändra inställningar.
            </div>

            <div class="gs-card">
                <div class="gs-card-header">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <h3 class="gs-h4" style="margin: 0;">Alla klasser (<?= count($classes) ?>)</h3>
                        <button type="button" class="gs-btn gs-btn-primary" onclick="openCreateModal()">
                            <i data-lucide="plus"></i>
                            Ny klass
                        </button>
                    </div>
                </div>
                <div class="gs-card-content" style="padding: 0; overflow-x: auto; -webkit-overflow-scrolling: touch;">
                    <table class="gs-table">
                        <thead>
                            <tr>
                                <th>Namn</th>
                                <th>Visningsnamn</th>
                                <th>Kön</th>
                                <th>Åldersintervall</th>
                                <th>Disciplin</th>
                                <th>Resultat</th>
                                <th>Status</th>
                                <th>Åtgärder</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($classes as $class): ?>
                                <tr>
                                    <td>
                                        <strong class="gs-text-primary"><?= h($class['name']) ?></strong>
                                    </td>
                                    <td><?= h($class['display_name']) ?></td>
                                    <td>
                                        <span class="gs-badge gs-badge-<?= $class['gender'] === 'M' ? 'primary' : ($class['gender'] === 'K' ? 'accent' : 'secondary') ?> gs-badge-sm">
                                            <?= h($class['gender']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($class['min_age'] || $class['max_age']): ?>
                                            <?= $class['min_age'] ?? '–' ?> – <?= $class['max_age'] ?? '–' ?> år
                                        <?php else: ?>
                                            <span class="gs-text-secondary">–</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="gs-badge gs-badge-secondary gs-badge-sm">
                                            <?= h($class['discipline']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="gs-badge gs-badge-info gs-badge-sm">
                                            <?= $class['result_count'] ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($class['active']): ?>
                                            <span class="gs-badge gs-badge-success gs-badge-sm">Aktiv</span>
                                        <?php else: ?>
                                            <span class="gs-badge gs-badge-secondary gs-badge-sm">Inaktiv</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <button type="button" class="gs-btn gs-btn-sm gs-btn-outline" onclick="editClass(<?= htmlspecialchars(json_encode($class), ENT_QUOTES, 'UTF-8') ?>)">
                                            <i data-lucide="edit" style="width: 14px; height: 14px;"></i>
                                            Redigera
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <!-- Migration Tab -->
        <?php if ($activeTab === 'migration'): ?>
            <div class="gs-alert gs-alert-info gs-mb-lg">
                <i data-lucide="info"></i>
                <strong>Snabblänkar:</strong> Verktyg för databas migration och debug.
            </div>

            <div class="gs-alert gs-alert-warning gs-mb-lg">
                <i data-lucide="alert-triangle"></i>
                <strong>OBS:</strong> Import-verktyg har flyttat till <a href="/admin/import.php" style="color: #EF761F; text-decoration: underline; font-weight: 600;">Import-sidan</a>.
            </div>

            <!-- Migration Tools -->
            <div class="gs-card">
                <div class="gs-card-header">
                    <h3 class="gs-h4" style="margin: 0;">
                        <i data-lucide="database"></i>
                        Databasmigrationer
                    </h3>
                </div>
                <div class="gs-card-content">
                    <div class="gs-grid gs-grid-cols-1 gs-gap-md">
                        <!-- Standalone Migration -->
                        <div style="padding: 1rem; background: #f0fdf4; border: 2px solid #86efac; border-radius: 8px;">
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <div>
                                    <h4 style="margin: 0 0 0.5rem 0; font-size: 1rem; color: #16a34a;">
                                        <i data-lucide="check-circle" style="width: 18px; height: 18px;"></i>
                                        Fristående Migration (Rekommenderad)
                                    </h4>
                                    <p style="margin: 0; font-size: 0.875rem; color: #15803d;">
                                        Kör databas migration utan inloggningskrav. Lägger till utökade fält för deltagare.
                                    </p>
                                </div>
                                <a href="/admin/migrate.php" target="_blank" class="gs-btn gs-btn-success">
                                    <i data-lucide="external-link"></i>
                                    Öppna
                                </a>
                            </div>
                        </div>

                        <!-- Original Migration -->
                        <div style="padding: 1rem; background: #fef2f2; border: 2px solid #fca5a5; border-radius: 8px;">
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <div>
                                    <h4 style="margin: 0 0 0.5rem 0; font-size: 1rem; color: #dc2626;">
                                        <i data-lucide="alert-circle" style="width: 18px; height: 18px;"></i>
                                        Original Migration (Kräver inloggning)
                                    </h4>
                                    <p style="margin: 0; font-size: 0.875rem; color: #b91c1c;">
                                        Kräver admin-inloggning. Använd fristående migration istället om denna inte fungerar.
                                    </p>
                                </div>
                                <a href="/admin/run-migration-extended-riders.php" target="_blank" class="gs-btn gs-btn-outline">
                                    <i data-lucide="external-link"></i>
                                    Öppna
                                </a>
                            </div>
                        </div>

                        <!-- Debug Test -->
                        <div style="padding: 1rem; background: #eff6ff; border: 2px solid #93c5fd; border-radius: 8px;">
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <div>
                                    <h4 style="margin: 0 0 0.5rem 0; font-size: 1rem; color: #2563eb;">
                                        <i data-lucide="bug" style="width: 18px; height: 18px;"></i>
                                        Debug Test
                                    </h4>
                                    <p style="margin: 0; font-size: 0.875rem; color: #1e40af;">
                                        Testar databas-anslutning och admin-behörighet steg för steg.
                                    </p>
                                </div>
                                <a href="/admin/test-migration.php" target="_blank" class="gs-btn gs-btn-outline">
                                    <i data-lucide="external-link"></i>
                                    Öppna
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</main>

<!-- Edit Class Modal -->
<div id="editClassModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0, 0, 0, 0.5); z-index: 9999; align-items: center; justify-content: center;">
    <div style="background: white; border-radius: 12px; width: 90%; max-width: 600px; max-height: 90vh; overflow-y: auto; box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);">
        <div style="padding: 1.5rem; border-bottom: 1px solid var(--gs-border); display: flex; justify-content: space-between; align-items: center;">
            <h3 class="gs-h4" style="margin: 0;">
                <i data-lucide="edit"></i>
                Redigera klass
            </h3>
            <button type="button" onclick="closeEditModal()" style="background: none; border: none; cursor: pointer; font-size: 24px; color: var(--gs-text-secondary);">
                ×
            </button>
        </div>

        <form method="POST" action="" style="padding: 1.5rem;">
            <?= csrf() ?>
            <input type="hidden" name="action" value="update_class">
            <input type="hidden" name="class_id" id="edit_class_id">

            <div class="gs-grid gs-grid-cols-2 gs-gap-md gs-mb-lg">
                <div>
                    <label class="gs-label">Namn (kod)</label>
                    <input type="text" name="name" id="edit_name" class="gs-input" required>
                    <small class="gs-text-secondary">T.ex. M17, K40</small>
                </div>

                <div>
                    <label class="gs-label">Visningsnamn</label>
                    <input type="text" name="display_name" id="edit_display_name" class="gs-input" required>
                    <small class="gs-text-secondary">T.ex. Män 17-18 år</small>
                </div>
            </div>

            <div class="gs-grid gs-grid-cols-2 gs-gap-md gs-mb-lg">
                <div>
                    <label class="gs-label">Kön</label>
                    <select name="gender" id="edit_gender" class="gs-input">
                        <option value="M">M (Man)</option>
                        <option value="K">K (Kvinna)</option>
                        <option value="ALL">ALL (Alla)</option>
                    </select>
                </div>

                <div>
                    <label class="gs-label">Disciplin</label>
                    <select name="discipline" id="edit_discipline" class="gs-input">
                        <option value="ROAD">ROAD (Landsväg)</option>
                        <option value="MTB">MTB (Mountainbike)</option>
                        <option value="ALL">ALL (Alla)</option>
                    </select>
                </div>
            </div>

            <div class="gs-grid gs-grid-cols-3 gs-gap-md gs-mb-lg">
                <div>
                    <label class="gs-label">Min ålder</label>
                    <input type="number" name="min_age" id="edit_min_age" class="gs-input" min="0" max="120">
                    <small class="gs-text-secondary">Lämna tom för ingen min</small>
                </div>

                <div>
                    <label class="gs-label">Max ålder</label>
                    <input type="number" name="max_age" id="edit_max_age" class="gs-input" min="0" max="120">
                    <small class="gs-text-secondary">Lämna tom för ingen max</small>
                </div>

                <div>
                    <label class="gs-label">Sorteringsordning</label>
                    <input type="number" name="sort_order" id="edit_sort_order" class="gs-input" value="0">
                    <small class="gs-text-secondary">Lägre = högre upp</small>
                </div>
            </div>

            <div class="gs-mb-lg">
                <label class="gs-checkbox">
                    <input type="checkbox" name="active" id="edit_active" value="1">
                    <span>Aktiv klass</span>
                </label>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 0.5rem; padding-top: 1rem; border-top: 1px solid var(--gs-border);">
                <button type="button" onclick="closeEditModal()" class="gs-btn gs-btn-outline">
                    Avbryt
                </button>
                <button type="submit" class="gs-btn gs-btn-primary">
                    <i data-lucide="save"></i>
                    Spara ändringar
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Create Class Modal -->
<div id="createClassModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0, 0, 0, 0.5); z-index: 9999; align-items: center; justify-content: center;">
    <div style="background: white; border-radius: 12px; width: 90%; max-width: 600px; max-height: 90vh; overflow-y: auto; box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);">
        <div style="padding: 1.5rem; border-bottom: 1px solid var(--gs-border); display: flex; justify-content: space-between; align-items: center;">
            <h3 class="gs-h4" style="margin: 0;">
                <i data-lucide="plus"></i>
                Skapa ny klass
            </h3>
            <button type="button" onclick="closeCreateModal()" style="background: none; border: none; cursor: pointer; font-size: 24px; color: var(--gs-text-secondary);">
                ×
            </button>
        </div>

        <form method="POST" action="" style="padding: 1.5rem;">
            <?= csrf() ?>
            <input type="hidden" name="action" value="create_class">

            <div class="gs-grid gs-grid-cols-2 gs-gap-md gs-mb-lg">
                <div>
                    <label class="gs-label">Namn (kod)</label>
                    <input type="text" name="name" id="create_name" class="gs-input" required>
                    <small class="gs-text-secondary">T.ex. M17, K40</small>
                </div>

                <div>
                    <label class="gs-label">Visningsnamn</label>
                    <input type="text" name="display_name" id="create_display_name" class="gs-input" required>
                    <small class="gs-text-secondary">T.ex. Män 17-18 år</small>
                </div>
            </div>

            <div class="gs-grid gs-grid-cols-2 gs-gap-md gs-mb-lg">
                <div>
                    <label class="gs-label">Kön</label>
                    <select name="gender" id="create_gender" class="gs-input">
                        <option value="M">M (Man)</option>
                        <option value="K">K (Kvinna)</option>
                        <option value="ALL">ALL (Alla)</option>
                    </select>
                </div>

                <div>
                    <label class="gs-label">Disciplin</label>
                    <select name="discipline" id="create_discipline" class="gs-input">
                        <option value="ROAD">ROAD (Landsväg)</option>
                        <option value="MTB">MTB (Mountainbike)</option>
                        <option value="ALL">ALL (Alla)</option>
                    </select>
                </div>
            </div>

            <div class="gs-grid gs-grid-cols-3 gs-gap-md gs-mb-lg">
                <div>
                    <label class="gs-label">Min ålder</label>
                    <input type="number" name="min_age" id="create_min_age" class="gs-input" min="0" max="120">
                    <small class="gs-text-secondary">Lämna tom för ingen min</small>
                </div>

                <div>
                    <label class="gs-label">Max ålder</label>
                    <input type="number" name="max_age" id="create_max_age" class="gs-input" min="0" max="120">
                    <small class="gs-text-secondary">Lämna tom för ingen max</small>
                </div>

                <div>
                    <label class="gs-label">Sorteringsordning</label>
                    <input type="number" name="sort_order" id="create_sort_order" class="gs-input" value="0">
                    <small class="gs-text-secondary">Lägre = högre upp</small>
                </div>
            </div>

            <div class="gs-mb-lg">
                <label class="gs-checkbox">
                    <input type="checkbox" name="active" id="create_active" value="1" checked>
                    <span>Aktiv klass</span>
                </label>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 0.5rem; padding-top: 1rem; border-top: 1px solid var(--gs-border);">
                <button type="button" onclick="closeCreateModal()" class="gs-btn gs-btn-outline">
                    Avbryt
                </button>
                <button type="submit" class="gs-btn gs-btn-primary">
                    <i data-lucide="plus"></i>
                    Skapa klass
                </button>
            </div>
        </form>
    </div>
</div>

<script src="https://unpkg.com/lucide@latest"></script>
<script>
    lucide.createIcons();

    function editClass(classData) {
        // Populate form fields
        document.getElementById('edit_class_id').value = classData.id;
        document.getElementById('edit_name').value = classData.name;
        document.getElementById('edit_display_name').value = classData.display_name;
        document.getElementById('edit_gender').value = classData.gender;
        document.getElementById('edit_discipline').value = classData.discipline;
        document.getElementById('edit_min_age').value = classData.min_age || '';
        document.getElementById('edit_max_age').value = classData.max_age || '';
        document.getElementById('edit_sort_order').value = classData.sort_order || 0;
        document.getElementById('edit_active').checked = classData.active == 1;

        // Show modal
        const modal = document.getElementById('editClassModal');
        modal.style.display = 'flex';

        // Re-render icons in modal
        lucide.createIcons();
    }

    function closeEditModal() {
        document.getElementById('editClassModal').style.display = 'none';
    }

    // Close modal on outside click
    document.getElementById('editClassModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeEditModal();
        }
    });

    // Close modal on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeEditModal();
            closeCreateModal();
        }
    });

    // Create modal functions
    function openCreateModal() {
        const modal = document.getElementById('createClassModal');
        modal.style.display = 'flex';

        // Clear form fields
        document.getElementById('create_name').value = '';
        document.getElementById('create_display_name').value = '';
        document.getElementById('create_gender').value = 'M';
        document.getElementById('create_discipline').value = 'ROAD';
        document.getElementById('create_min_age').value = '';
        document.getElementById('create_max_age').value = '';
        document.getElementById('create_sort_order').value = '0';
        document.getElementById('create_active').checked = true;

        // Re-render icons in modal
        lucide.createIcons();
    }

    function closeCreateModal() {
        document.getElementById('createClassModal').style.display = 'none';
    }

    // Close create modal on outside click
    document.getElementById('createClassModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeCreateModal();
        }
    });
</script>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>

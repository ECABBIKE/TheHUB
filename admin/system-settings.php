<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config.php';
// ... resten av filen
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/point-calculations.php';
require_once __DIR__ . '/../includes/class-calculations.php';
require_admin();

$db = getDB();
$message = '';
$messageType = 'info';
$executedStatements = [];

// Get active tab
$activeTab = $_GET['tab'] ?? 'point-scales';

// Handle create/update scale
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    checkCsrf();

    if ($_POST['action'] === 'create_scale') {
        $name = trim($_POST['name']);
        $description = trim($_POST['description'] ?? '');
        $discipline = $_POST['discipline'] ?? 'ALL';
        $isDefault = isset($_POST['is_default']) ? 1 : 0;

        if (empty($name)) {
            $message = 'Namn krävs';
            $messageType = 'error';
        } else {
            // If setting as default, unset other defaults
            if ($isDefault) {
                $db->update('point_scales', ['is_default' => 0], '1=1');
            }

            $scaleId = $db->insert('point_scales', [
                'name' => $name,
                'description' => $description,
                'discipline' => $discipline,
                'is_default' => $isDefault,
                'active' => 1
            ]);

            if ($scaleId) {
                $message = "Poängmall '{$name}' skapad!";
                $messageType = 'success';
            } else {
                $message = 'Kunde inte skapa poängmall';
                $messageType = 'error';
            }
        }
    } elseif ($_POST['action'] === 'update_values') {
        $scaleId = (int)$_POST['scale_id'];
        $positions = $_POST['positions'] ?? [];
        $points = $_POST['points'] ?? [];

        // Delete existing values
        $db->delete('point_scale_values', 'scale_id = ?', [$scaleId]);

        // Insert new values
        $inserted = 0;
        foreach ($positions as $i => $position) {
            if (!empty($position) && isset($points[$i]) && $points[$i] !== '') {
                $db->insert('point_scale_values', [
                    'scale_id' => $scaleId,
                    'position' => (int)$position,
                    'points' => (float)$points[$i]
                ]);
                $inserted++;
            }
        }

        $message = "{$inserted} poängvärden uppdaterade!";
        $messageType = 'success';
    } elseif ($_POST['action'] === 'delete_scale') {
        $scaleId = (int)$_POST['scale_id'];

        // Check if scale is in use
        $inUse = $db->getRow("
            SELECT COUNT(*) as count
            FROM events
            WHERE point_scale_id = ?
        ", [$scaleId]);

        $inUseSeries = $db->getRow("
            SELECT COUNT(*) as count
            FROM series
            WHERE point_scale_id = ?
        ", [$scaleId]);

        if ($inUse['count'] > 0 || $inUseSeries['count'] > 0) {
            $message = "Poängmallen används av {$inUse['count']} events och {$inUseSeries['count']} serier och kan inte raderas";
            $messageType = 'error';
        } else {
            $db->delete('point_scales', 'id = ?', [$scaleId]);
            $message = 'Poängmall raderad!';
            $messageType = 'success';
        }
    } elseif ($_POST['action'] === 'create_class') {
        $name = trim($_POST['name']);
        $displayName = trim($_POST['display_name']);
        $gender = $_POST['gender'] ?? 'ALL';
        $minAge = !empty($_POST['min_age']) ? (int)$_POST['min_age'] : null;
        $maxAge = !empty($_POST['max_age']) ? (int)$_POST['max_age'] : null;
        $discipline = $_POST['discipline'] ?? 'ALL';
        $pointScaleId = !empty($_POST['point_scale_id']) ? (int)$_POST['point_scale_id'] : null;
        $sortOrder = !empty($_POST['sort_order']) ? (int)$_POST['sort_order'] : 0;

        if (empty($name) || empty($displayName)) {
            $message = 'Namn och visningsnamn krävs';
            $messageType = 'error';
        } else {
            $classId = $db->insert('classes', [
                'name' => $name,
                'display_name' => $displayName,
                'gender' => $gender,
                'min_age' => $minAge,
                'max_age' => $maxAge,
                'discipline' => $discipline,
                'point_scale_id' => $pointScaleId,
                'sort_order' => $sortOrder,
                'active' => 1
            ]);

            if ($classId) {
                $message = "Klass '{$name}' skapad!";
                $messageType = 'success';
            } else {
                $message = 'Kunde inte skapa klass';
                $messageType = 'error';
            }
        }
    } elseif ($_POST['action'] === 'update_class') {
        $classId = (int)$_POST['class_id'];
        $name = trim($_POST['name']);
        $displayName = trim($_POST['display_name']);
        $gender = $_POST['gender'] ?? 'ALL';
        $minAge = !empty($_POST['min_age']) ? (int)$_POST['min_age'] : null;
        $maxAge = !empty($_POST['max_age']) ? (int)$_POST['max_age'] : null;
        $discipline = $_POST['discipline'] ?? 'ALL';
        $pointScaleId = !empty($_POST['point_scale_id']) ? (int)$_POST['point_scale_id'] : null;
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
                'point_scale_id' => $pointScaleId,
                'sort_order' => $sortOrder,
                'active' => $active
            ], 'id = ?', [$classId]);

            $message = "Klass '{$name}' uppdaterad!";
            $messageType = 'success';
        }
    } elseif ($_POST['action'] === 'delete_class') {
        $classId = (int)$_POST['class_id'];

        // Check if class is in use
        $inUse = $db->getRow("
            SELECT COUNT(*) as count
            FROM results
            WHERE class_id = ?
        ", [$classId]);

        if ($inUse['count'] > 0) {
            $message = "Klassen används av {$inUse['count']} resultat och kan inte raderas. Inaktivera den istället.";
            $messageType = 'error';
        } else {
            $db->delete('classes', 'id = ?', [$classId]);
            $message = 'Klass raderad!';
            $messageType = 'success';
        }
    }
}

// Get all scales
$scales = $db->getAll("
    SELECT
        ps.*,
        COUNT(psv.id) as value_count,
        (SELECT COUNT(*) FROM events WHERE point_scale_id = ps.id) as event_count,
        (SELECT COUNT(*) FROM series WHERE point_scale_id = ps.id) as series_count
    FROM point_scales ps
    LEFT JOIN point_scale_values psv ON ps.id = psv.scale_id
    GROUP BY ps.id
    ORDER BY ps.is_default DESC, ps.name ASC
");

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
            <a href="?tab=point-scales" class="gs-tab <?= $activeTab === 'point-scales' ? 'active' : '' ?>">
                <i data-lucide="award"></i>
                Poängmallar
            </a>
            <a href="?tab=classes" class="gs-tab <?= $activeTab === 'classes' ? 'active' : '' ?>">
                <i data-lucide="users"></i>
                Klasser
            </a>
            <a href="?tab=migrations" class="gs-tab <?= $activeTab === 'migrations' ? 'active' : '' ?>">
                <i data-lucide="database"></i>
                Migrationer
            </a>
        </div>

        <!-- Point Scales Tab -->
        <?php if ($activeTab === 'point-scales'): ?>
            <div class="gs-flex gs-justify-end gs-mb-md">
                <button class="gs-btn gs-btn-primary" onclick="showCreateModal()">
                    <i data-lucide="plus"></i>
                    Ny poängmall
                </button>
            </div>

            <?php if (empty($scales)): ?>
                <div class="gs-card gs-text-center" style="padding: 3rem;">
                    <i data-lucide="award" style="width: 64px; height: 64px; margin: 0 auto 1rem; opacity: 0.3;"></i>
                    <h3 class="gs-h4 gs-mb-sm">Inga poängmallar ännu</h3>
                    <p class="gs-text-secondary gs-mb-lg">
                        Skapa din första poängmall för att börja räkna poäng i events och serier.
                    </p>
                    <button class="gs-btn gs-btn-primary" onclick="showCreateModal()">
                        <i data-lucide="plus"></i>
                        Skapa poängmall
                    </button>
                </div>
            <?php else: ?>
                <div class="gs-grid gs-grid-cols-1 gs-md-grid-cols-2 gs-lg-grid-cols-3 gs-xl-grid-cols-4 gs-gap-lg">
                    <?php foreach ($scales as $scale): ?>
                        <div class="gs-card">
                            <div class="gs-card-header">
                                <div class="gs-flex gs-justify-between gs-items-start">
                                    <div>
                                        <h3 class="gs-h5 gs-text-primary gs-mb-xs">
                                            <?= h($scale['name']) ?>
                                            <?php if ($scale['is_default']): ?>
                                                <span class="gs-badge gs-badge-warning gs-badge-sm">Standard</span>
                                            <?php endif; ?>
                                        </h3>
                                        <?php if ($scale['discipline'] !== 'ALL'): ?>
                                            <span class="gs-badge gs-badge-secondary gs-badge-sm">
                                                <?= h($scale['discipline']) ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="gs-card-content">
                                <?php if ($scale['description']): ?>
                                    <p class="gs-text-sm gs-text-secondary gs-mb-md">
                                        <?= h($scale['description']) ?>
                                    </p>
                                <?php endif; ?>

                                <!-- Stats -->
                                <div class="gs-grid gs-grid-cols-3 gs-gap-sm gs-mb-md">
                                    <div class="gs-text-center" style="padding: 0.5rem; background: var(--gs-background-secondary); border-radius: var(--gs-border-radius);">
                                        <div class="gs-h4 gs-text-primary"><?= $scale['value_count'] ?></div>
                                        <div class="gs-text-xs gs-text-secondary">Poäng</div>
                                    </div>
                                    <div class="gs-text-center" style="padding: 0.5rem; background: var(--gs-background-secondary); border-radius: var(--gs-border-radius);">
                                        <div class="gs-h4 gs-text-success"><?= $scale['event_count'] ?></div>
                                        <div class="gs-text-xs gs-text-secondary">Events</div>
                                    </div>
                                    <div class="gs-text-center" style="padding: 0.5rem; background: var(--gs-background-secondary); border-radius: var(--gs-border-radius);">
                                        <div class="gs-h4 gs-text-accent"><?= $scale['series_count'] ?></div>
                                        <div class="gs-text-xs gs-text-secondary">Serier</div>
                                    </div>
                                </div>

                                <!-- Actions -->
                                <div class="gs-flex gs-gap-xs">
                                    <button class="gs-btn gs-btn-primary gs-btn-sm gs-flex-1"
                                            onclick="editScaleValues(<?= $scale['id'] ?>, '<?= h(addslashes($scale['name'])) ?>')">
                                        <i data-lucide="edit" style="width: 14px; height: 14px;"></i>
                                        Editera
                                    </button>
                                    <?php if ($scale['event_count'] == 0 && $scale['series_count'] == 0): ?>
                                        <button class="gs-btn gs-btn-danger gs-btn-sm"
                                                onclick="deleteScale(<?= $scale['id'] ?>, '<?= h(addslashes($scale['name'])) ?>')">
                                            <i data-lucide="trash" style="width: 14px; height: 14px;"></i>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

        <!-- Classes Tab -->
        <?php elseif ($activeTab === 'classes'): ?>
            <div class="gs-flex gs-justify-end gs-mb-md">
                <button class="gs-btn gs-btn-primary" onclick="showCreateClassModal()">
                    <i data-lucide="plus"></i>
                    Ny klass
                </button>
            </div>

            <div class="gs-alert gs-alert-info gs-mb-lg">
                <i data-lucide="info"></i>
                <strong>Tips:</strong> Alla klasser (inklusive förinstallerade) kan redigeras helt fritt. Klicka på "Editera" för att ändra namn, åldersintervall, disciplin eller andra inställningar.
            </div>

            <?php if (empty($classes)): ?>
                <div class="gs-card gs-text-center" style="padding: 3rem;">
                    <i data-lucide="users" style="width: 64px; height: 64px; margin: 0 auto 1rem; opacity: 0.3;"></i>
                    <h3 class="gs-h4 gs-mb-sm">Inga klasser ännu</h3>
                    <p class="gs-text-secondary gs-mb-lg">
                        Skapa din första klass (t.ex. M17, K40) för åldersbaserad klassificering.
                    </p>
                    <button class="gs-btn gs-btn-primary" onclick="showCreateClassModal()">
                        <i data-lucide="plus"></i>
                        Skapa klass
                    </button>
                </div>
            <?php else: ?>
                <div class="gs-card">
                    <div class="gs-card-header">
                        <h3 class="gs-h4">Alla klasser</h3>
                    </div>
                    <div class="gs-card-content" style="padding: 0;">
                        <table class="gs-table">
                            <thead>
                                <tr>
                                    <th>Namn</th>
                                    <th>Visningsnamn</th>
                                    <th>Kön</th>
                                    <th>Åldersintervall</th>
                                    <th>Disciplin</th>
                                    <th>Poängmall</th>
                                    <th>Resultat</th>
                                    <th>Status</th>
                                    <th style="width: 120px;">Åtgärder</th>
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
                                            <?php if ($class['scale_name']): ?>
                                                <span class="gs-text-sm"><?= h($class['scale_name']) ?></span>
                                            <?php else: ?>
                                                <span class="gs-text-secondary gs-text-sm">Standard</span>
                                            <?php endif; ?>
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
                                            <div class="gs-flex gs-gap-xs">
                                                <button class="gs-btn gs-btn-primary gs-btn-sm"
                                                        onclick="editClass(<?= htmlspecialchars(json_encode($class)) ?>)"
                                                        title="Redigera klass">
                                                    <i data-lucide="edit" style="width: 14px; height: 14px;"></i>
                                                </button>
                                                <button class="gs-btn gs-btn-secondary gs-btn-sm"
                                                        onclick="duplicateClass(<?= htmlspecialchars(json_encode($class)) ?>)"
                                                        title="Duplicera klass">
                                                    <i data-lucide="copy" style="width: 14px; height: 14px;"></i>
                                                </button>
                                                <?php if ($class['result_count'] == 0): ?>
                                                    <button class="gs-btn gs-btn-danger gs-btn-sm"
                                                            onclick="deleteClass(<?= $class['id'] ?>, '<?= h(addslashes($class['name'])) ?>')"
                                                            title="Radera klass">
                                                        <i data-lucide="trash" style="width: 14px; height: 14px;"></i>
                                                    </button>
                                                <?php else: ?>
                                                    <button class="gs-btn gs-btn-sm gs-btn-ghost" disabled title="Kan inte raderas - används av <?= $class['result_count'] ?> resultat">
                                                        <i data-lucide="lock" style="width: 14px; height: 14px;"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

        <!-- Migrations Tab -->
        <?php elseif ($activeTab === 'migrations'): ?>
            <?php
            // Get all migration files
            $migrationFiles = glob(__DIR__ . '/../database/migrations/*.sql');
            sort($migrationFiles);

            $migrations = [];
            foreach ($migrationFiles as $file) {
                $filename = basename($file);
                $migrations[] = [
                    'file' => $file,
                    'filename' => $filename,
                    'number' => preg_replace('/^(\d+)_.*\.sql$/', '$1', $filename),
                    'name' => preg_replace('/^\d+_(.*)\.sql$/', '$1', $filename)
                ];
            }
            ?>

            <div class="gs-alert gs-alert-warning gs-mb-lg">
                <i data-lucide="alert-triangle"></i>
                <strong>Varning!</strong> Migrationer ändrar din databasstruktur. Se till att du har en backup innan du kör dem.
            </div>

            <div class="gs-card">
                <div class="gs-card-header">
                    <h2 class="gs-h4">Tillgängliga migrationer</h2>
                </div>
                <div class="gs-card-content">
                    <?php if (empty($migrations)): ?>
                        <p class="gs-text-secondary gs-text-center gs-py-lg">Inga migrationer hittades</p>
                    <?php else: ?>
                        <div class="gs-grid gs-grid-cols-1 gs-gap-md">
                            <?php foreach ($migrations as $migration): ?>
                                <div class="gs-card" style="background: var(--gs-background-secondary);">
                                    <div class="gs-card-content">
                                        <div class="gs-flex gs-justify-between gs-items-center">
                                            <div>
                                                <div class="gs-flex gs-items-center gs-gap-sm gs-mb-xs">
                                                    <span class="gs-badge gs-badge-primary">
                                                        #<?= h($migration['number']) ?>
                                                    </span>
                                                    <h3 class="gs-h5">
                                                        <?= h(str_replace('_', ' ', ucfirst($migration['name']))) ?>
                                                    </h3>
                                                </div>
                                                <p class="gs-text-sm gs-text-secondary">
                                                    <?= h($migration['filename']) ?>
                                                </p>
                                            </div>
                                            <a href="/admin/run-migrations.php" class="gs-btn gs-btn-primary gs-btn-sm">
                                                <i data-lucide="play" style="width: 14px; height: 14px;"></i>
                                                Kör migrationer
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</main>

<!-- Create Scale Modal -->
<div id="createModal" class="gs-modal" style="display: none;">
    <div class="gs-modal-overlay" onclick="hideCreateModal()"></div>
    <div class="gs-modal-content" style="max-width: 600px;">
        <div class="gs-modal-header">
            <h2 class="gs-h4">Skapa poängmall</h2>
            <button class="gs-modal-close" onclick="hideCreateModal()">
                <i data-lucide="x"></i>
            </button>
        </div>
        <div class="gs-modal-body">
            <form method="POST" id="createForm">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create_scale">

                <div class="gs-form-group">
                    <label class="gs-label">Namn *</label>
                    <input type="text" name="name" class="gs-input" required placeholder="T.ex. SweCup Enduro 2025">
                </div>

                <div class="gs-form-group">
                    <label class="gs-label">Beskrivning</label>
                    <textarea name="description" class="gs-input" rows="3" placeholder="Beskrivning av poängmallen..."></textarea>
                </div>

                <div class="gs-form-group">
                    <label class="gs-label">Disciplin</label>
                    <select name="discipline" class="gs-input">
                        <option value="ALL">Alla</option>
                        <option value="ENDURO">Enduro</option>
                        <option value="DH">Downhill</option>
                        <option value="XCO">XCO</option>
                        <option value="CX">Cyclocross</option>
                    </select>
                </div>

                <div class="gs-form-group">
                    <label class="gs-checkbox">
                        <input type="checkbox" name="is_default">
                        <span>Sätt som standard</span>
                    </label>
                </div>

                <div class="gs-flex gs-justify-end gs-gap-md gs-mt-lg">
                    <button type="button" class="gs-btn gs-btn-outline" onclick="hideCreateModal()">Avbryt</button>
                    <button type="submit" class="gs-btn gs-btn-primary">Skapa</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Values Modal -->
<div id="editModal" class="gs-modal" style="display: none;">
    <div class="gs-modal-overlay" onclick="hideEditModal()"></div>
    <div class="gs-modal-content" style="max-width: 900px;">
        <div class="gs-modal-header">
            <h2 class="gs-h4">Editera poäng - <span id="editScaleName"></span></h2>
            <button class="gs-modal-close" onclick="hideEditModal()">
                <i data-lucide="x"></i>
            </button>
        </div>
        <div class="gs-modal-body">
            <form method="POST" id="editForm">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update_values">
                <input type="hidden" name="scale_id" id="edit_scale_id">

                <div id="valuesContainer" class="gs-mb-lg"></div>

                <button type="button" class="gs-btn gs-btn-outline gs-btn-sm gs-mb-lg" onclick="addValueRow()">
                    <i data-lucide="plus"></i>
                    Lägg till placering
                </button>

                <div class="gs-flex gs-justify-end gs-gap-md">
                    <button type="button" class="gs-btn gs-btn-outline" onclick="hideEditModal()">Avbryt</button>
                    <button type="submit" class="gs-btn gs-btn-primary">Spara poäng</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://unpkg.com/lucide@latest"></script>
<script>
    lucide.createIcons();

    function showCreateModal() {
        document.getElementById('createModal').style.display = 'flex';
        lucide.createIcons();
    }

    function hideCreateModal() {
        document.getElementById('createModal').style.display = 'none';
    }

    function hideEditModal() {
        document.getElementById('editModal').style.display = 'none';
    }

    async function editScaleValues(scaleId, scaleName) {
        document.getElementById('editScaleName').textContent = scaleName;
        document.getElementById('edit_scale_id').value = scaleId;

        // Fetch existing values
        const response = await fetch(`/api/point-scale-values.php?scale_id=${scaleId}`);
        const values = await response.json();

        // Populate form
        const container = document.getElementById('valuesContainer');
        container.innerHTML = '';

        if (values.length > 0) {
            values.forEach(v => {
                addValueRow(v.position, v.points);
            });
        } else {
            // Add 10 default rows
            for (let i = 1; i <= 10; i++) {
                addValueRow(i, '');
            }
        }

        document.getElementById('editModal').style.display = 'flex';
        lucide.createIcons();
    }

    function deleteScale(scaleId, scaleName) {
        if (confirm(`Är du säker på att du vill radera poängmallen "${scaleName}"?`)) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_scale">
                <input type="hidden" name="scale_id" value="${scaleId}">
            `;
            document.body.appendChild(form);
            form.submit();
        }
    }

    function addValueRow(position = '', points = '') {
        const container = document.getElementById('valuesContainer');
        const row = document.createElement('div');
        row.className = 'gs-flex gs-gap-md gs-mb-sm gs-items-center';
        row.innerHTML = `
            <div style="flex: 1;">
                <input type="number" name="positions[]" class="gs-input" placeholder="Placering" value="${position}" min="1">
            </div>
            <div style="flex: 1;">
                <input type="number" name="points[]" class="gs-input" placeholder="Poäng" value="${points}" step="0.01">
            </div>
            <button type="button" class="gs-btn gs-btn-danger gs-btn-sm" onclick="this.parentElement.remove()">
                <i data-lucide="trash" style="width: 14px; height: 14px;"></i>
            </button>
        `;
        container.appendChild(row);
        lucide.createIcons();
    }

    // Classes management functions
    function showCreateClassModal() {
        const modal = document.createElement('div');
        modal.className = 'gs-modal';
        modal.innerHTML = `
            <div class="gs-modal-backdrop" onclick="this.parentElement.remove()"></div>
            <div class="gs-modal-content" style="max-width: 600px;">
                <div class="gs-modal-header">
                    <h3 class="gs-h4">Skapa ny klass</h3>
                    <button class="gs-btn gs-btn-sm gs-btn-ghost" onclick="this.closest('.gs-modal').remove()">
                        <i data-lucide="x"></i>
                    </button>
                </div>
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="create_class">
                    <div class="gs-modal-body">
                        <div class="gs-form-group">
                            <label class="gs-label">Kort namn *</label>
                            <input type="text" name="name" class="gs-input" placeholder="t.ex. M17, K40" required>
                            <small class="gs-text-sm gs-text-secondary">Kort kodnamn för klassen</small>
                        </div>
                        <div class="gs-form-group">
                            <label class="gs-label">Visningsnamn *</label>
                            <input type="text" name="display_name" class="gs-input" placeholder="t.ex. Män 17-18 år" required>
                        </div>
                        <div class="gs-grid gs-grid-cols-2 gs-gap-md">
                            <div class="gs-form-group">
                                <label class="gs-label">Kön</label>
                                <select name="gender" class="gs-input">
                                    <option value="M">M (Män)</option>
                                    <option value="K">K (Kvinnor)</option>
                                    <option value="ALL">ALL (Alla)</option>
                                </select>
                            </div>
                            <div class="gs-form-group">
                                <label class="gs-label">Disciplin</label>
                                <select name="discipline" class="gs-input">
                                    <option value="ALL">Alla</option>
                                    <option value="ROAD">Landsväg</option>
                                    <option value="MTB">MTB</option>
                                </select>
                            </div>
                        </div>
                        <div class="gs-grid gs-grid-cols-2 gs-gap-md">
                            <div class="gs-form-group">
                                <label class="gs-label">Min ålder</label>
                                <input type="number" name="min_age" class="gs-input" placeholder="t.ex. 17" min="0">
                            </div>
                            <div class="gs-form-group">
                                <label class="gs-label">Max ålder</label>
                                <input type="number" name="max_age" class="gs-input" placeholder="t.ex. 18" min="0">
                            </div>
                        </div>
                        <div class="gs-form-group">
                            <label class="gs-label">Poängmall (valfri)</label>
                            <select name="point_scale_id" class="gs-input">
                                <option value="">Standard</option>
                                <?php foreach ($scales as $scale): ?>
                                    <option value="<?= $scale['id'] ?>"><?= h($scale['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="gs-form-group">
                            <label class="gs-label">Sorteringsordning</label>
                            <input type="number" name="sort_order" class="gs-input" value="0" step="10">
                            <small class="gs-text-sm gs-text-secondary">Lägre nummer visas först</small>
                        </div>
                    </div>
                    <div class="gs-modal-footer">
                        <button type="button" class="gs-btn gs-btn-outline" onclick="this.closest('.gs-modal').remove()">Avbryt</button>
                        <button type="submit" class="gs-btn gs-btn-primary">Skapa klass</button>
                    </div>
                </form>
            </div>
        `;
        document.body.appendChild(modal);
        lucide.createIcons();
    }

    function editClass(classData) {
        const modal = document.createElement('div');
        modal.className = 'gs-modal';
        modal.innerHTML = `
            <div class="gs-modal-backdrop" onclick="this.parentElement.remove()"></div>
            <div class="gs-modal-content" style="max-width: 600px;">
                <div class="gs-modal-header">
                    <h3 class="gs-h4">Redigera klass</h3>
                    <button class="gs-btn gs-btn-sm gs-btn-ghost" onclick="this.closest('.gs-modal').remove()">
                        <i data-lucide="x"></i>
                    </button>
                </div>
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update_class">
                    <input type="hidden" name="class_id" value="${classData.id}">
                    <div class="gs-modal-body">
                        <div class="gs-form-group">
                            <label class="gs-label">Kort namn *</label>
                            <input type="text" name="name" class="gs-input" value="${classData.name}" required>
                        </div>
                        <div class="gs-form-group">
                            <label class="gs-label">Visningsnamn *</label>
                            <input type="text" name="display_name" class="gs-input" value="${classData.display_name}" required>
                        </div>
                        <div class="gs-grid gs-grid-cols-2 gs-gap-md">
                            <div class="gs-form-group">
                                <label class="gs-label">Kön</label>
                                <select name="gender" class="gs-input">
                                    <option value="M" ${classData.gender === 'M' ? 'selected' : ''}>M (Män)</option>
                                    <option value="K" ${classData.gender === 'K' ? 'selected' : ''}>K (Kvinnor)</option>
                                    <option value="ALL" ${classData.gender === 'ALL' ? 'selected' : ''}>ALL (Alla)</option>
                                </select>
                            </div>
                            <div class="gs-form-group">
                                <label class="gs-label">Disciplin</label>
                                <select name="discipline" class="gs-input">
                                    <option value="ALL" ${classData.discipline === 'ALL' ? 'selected' : ''}>Alla</option>
                                    <option value="ROAD" ${classData.discipline === 'ROAD' ? 'selected' : ''}>Landsväg</option>
                                    <option value="MTB" ${classData.discipline === 'MTB' ? 'selected' : ''}>MTB</option>
                                </select>
                            </div>
                        </div>
                        <div class="gs-grid gs-grid-cols-2 gs-gap-md">
                            <div class="gs-form-group">
                                <label class="gs-label">Min ålder</label>
                                <input type="number" name="min_age" class="gs-input" value="${classData.min_age || ''}" min="0">
                            </div>
                            <div class="gs-form-group">
                                <label class="gs-label">Max ålder</label>
                                <input type="number" name="max_age" class="gs-input" value="${classData.max_age || ''}" min="0">
                            </div>
                        </div>
                        <div class="gs-form-group">
                            <label class="gs-label">Poängmall (valfri)</label>
                            <select name="point_scale_id" class="gs-input">
                                <option value="">Standard</option>
                                <?php foreach ($scales as $scale): ?>
                                    <option value="<?= $scale['id'] ?>" ${classData.point_scale_id == <?= $scale['id'] ?> ? 'selected' : ''}>
                                        <?= h($scale['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="gs-form-group">
                            <label class="gs-label">Sorteringsordning</label>
                            <input type="number" name="sort_order" class="gs-input" value="${classData.sort_order}" step="10">
                        </div>
                        <div class="gs-form-group">
                            <label class="gs-checkbox">
                                <input type="checkbox" name="active" ${classData.active ? 'checked' : ''}>
                                <span>Aktiv</span>
                            </label>
                        </div>
                    </div>
                    <div class="gs-modal-footer">
                        <button type="button" class="gs-btn gs-btn-outline" onclick="this.closest('.gs-modal').remove()">Avbryt</button>
                        <button type="submit" class="gs-btn gs-btn-primary">Uppdatera</button>
                    </div>
                </form>
            </div>
        `;
        document.body.appendChild(modal);
        lucide.createIcons();
    }

    function deleteClass(classId, className) {
        if (confirm(`Är du säker på att du vill radera klassen "${className}"?`)) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_class">
                <input type="hidden" name="class_id" value="${classId}">
            `;
            document.body.appendChild(form);
            form.submit();
        }
    }

    function duplicateClass(classData) {
        // Create a copy with modified name
        const modal = document.createElement('div');
        modal.className = 'gs-modal';
        modal.innerHTML = `
            <div class="gs-modal-backdrop" onclick="this.parentElement.remove()"></div>
            <div class="gs-modal-content" style="max-width: 600px;">
                <div class="gs-modal-header">
                    <h3 class="gs-h4">Duplicera klass: ${classData.name}</h3>
                    <button class="gs-btn gs-btn-sm gs-btn-ghost" onclick="this.closest('.gs-modal').remove()">
                        <i data-lucide="x"></i>
                    </button>
                </div>
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="create_class">
                    <div class="gs-modal-body">
                        <div class="gs-alert gs-alert-info gs-mb-md">
                            <i data-lucide="info"></i>
                            Skapar en kopia av "${classData.display_name}". Ändra namn och inställningar nedan.
                        </div>
                        <div class="gs-form-group">
                            <label class="gs-label">Kort namn *</label>
                            <input type="text" name="name" class="gs-input" value="${classData.name}-kopia" required>
                        </div>
                        <div class="gs-form-group">
                            <label class="gs-label">Visningsnamn *</label>
                            <input type="text" name="display_name" class="gs-input" value="${classData.display_name} (kopia)" required>
                        </div>
                        <div class="gs-grid gs-grid-cols-2 gs-gap-md">
                            <div class="gs-form-group">
                                <label class="gs-label">Kön</label>
                                <select name="gender" class="gs-input">
                                    <option value="M" ${classData.gender === 'M' ? 'selected' : ''}>M (Män)</option>
                                    <option value="K" ${classData.gender === 'K' ? 'selected' : ''}>K (Kvinnor)</option>
                                    <option value="ALL" ${classData.gender === 'ALL' ? 'selected' : ''}>ALL (Alla)</option>
                                </select>
                            </div>
                            <div class="gs-form-group">
                                <label class="gs-label">Disciplin</label>
                                <select name="discipline" class="gs-input">
                                    <option value="ALL" ${classData.discipline === 'ALL' ? 'selected' : ''}>Alla</option>
                                    <option value="ROAD" ${classData.discipline === 'ROAD' ? 'selected' : ''}>Landsväg</option>
                                    <option value="MTB" ${classData.discipline === 'MTB' ? 'selected' : ''}>MTB</option>
                                </select>
                            </div>
                        </div>
                        <div class="gs-grid gs-grid-cols-2 gs-gap-md">
                            <div class="gs-form-group">
                                <label class="gs-label">Min ålder</label>
                                <input type="number" name="min_age" class="gs-input" value="${classData.min_age || ''}" min="0">
                            </div>
                            <div class="gs-form-group">
                                <label class="gs-label">Max ålder</label>
                                <input type="number" name="max_age" class="gs-input" value="${classData.max_age || ''}" min="0">
                            </div>
                        </div>
                        <div class="gs-form-group">
                            <label class="gs-label">Poängmall (valfri)</label>
                            <select name="point_scale_id" class="gs-input">
                                <option value="">Standard</option>
                                <?php foreach ($scales as $scale): ?>
                                    <option value="<?= $scale['id'] ?>" ${classData.point_scale_id == <?= $scale['id'] ?> ? 'selected' : ''}>
                                        <?= h($scale['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="gs-form-group">
                            <label class="gs-label">Sorteringsordning</label>
                            <input type="number" name="sort_order" class="gs-input" value="${classData.sort_order + 5}" step="10">
                            <small class="gs-text-sm gs-text-secondary">Standard satt till ${classData.sort_order + 5} (efter originalet)</small>
                        </div>
                    </div>
                    <div class="gs-modal-footer">
                        <button type="button" class="gs-btn gs-btn-outline" onclick="this.closest('.gs-modal').remove()">Avbryt</button>
                        <button type="submit" class="gs-btn gs-btn-primary">Skapa kopia</button>
                    </div>
                </form>
            </div>
        `;
        document.body.appendChild(modal);
        lucide.createIcons();
    }
</script>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>

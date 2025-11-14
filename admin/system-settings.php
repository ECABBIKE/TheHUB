<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/point-calculations.php';
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
</script>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>

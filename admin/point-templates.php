<?php
require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();
$current_admin = get_current_admin();

$message = '';
$messageType = 'info';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();

    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'update') {
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $pointsData = $_POST['points'] ?? [];

        if (empty($name)) {
            $message = 'Namn är obligatoriskt';
            $messageType = 'error';
        } else {
            // Convert points array to JSON
            // Format: position => points
            $points = [];
            foreach ($pointsData as $position => $pointValue) {
                if (!empty($pointValue) && is_numeric($pointValue)) {
                    $points[$position] = (int)$pointValue;
                }
            }

            $templateData = [
                'name' => $name,
                'description' => $description,
                'points' => json_encode($points),
                'active' => 1
            ];

            try {
                if ($action === 'create') {
                    $db->insert('qualification_point_templates', $templateData);
                    $message = 'Poängmall skapad!';
                    $messageType = 'success';
                } else {
                    $id = intval($_POST['id']);
                    $db->update('qualification_point_templates', $templateData, 'id = ?', [$id]);
                    $message = 'Poängmall uppdaterad!';
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
            $db->delete('qualification_point_templates', 'id = ?', [$id]);
            $message = 'Poängmall borttagen!';
            $messageType = 'success';
        } catch (Exception $e) {
            $message = 'Ett fel uppstod: ' . $e->getMessage();
            $messageType = 'error';
        }
    } elseif ($action === 'import') {
        $importData = trim($_POST['import_data'] ?? '');

        if (empty($importData)) {
            $message = 'Ingen data att importera';
            $messageType = 'error';
        } else {
            try {
                // Try to parse as JSON first
                $imported = json_decode($importData, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    // Try CSV format: position,points (one per line)
                    $lines = explode("\n", $importData);
                    $imported = ['points' => []];

                    foreach ($lines as $line) {
                        $line = trim($line);
                        if (empty($line) || strpos($line, '#') === 0) continue; // Skip empty and comments

                        $parts = array_map('trim', explode(',', $line));
                        if (count($parts) >= 2 && is_numeric($parts[0]) && is_numeric($parts[1])) {
                            $imported['points'][$parts[0]] = (int)$parts[1];
                        }
                    }
                }

                if (!empty($imported['points'])) {
                    $templateData = [
                        'name' => $imported['name'] ?? 'Importerad mall ' . date('Y-m-d H:i'),
                        'description' => $imported['description'] ?? 'Importerad poängmall',
                        'points' => json_encode($imported['points']),
                        'active' => 1
                    ];

                    $db->insert('qualification_point_templates', $templateData);
                    $message = 'Poängmall importerad!';
                    $messageType = 'success';
                } else {
                    $message = 'Kunde inte hitta poäng i importerad data';
                    $messageType = 'error';
                }
            } catch (Exception $e) {
                $message = 'Import misslyckades: ' . $e->getMessage();
                $messageType = 'error';
            }
        }
    }
}

// Check if editing a template
$editTemplate = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $editTemplate = $db->getRow("SELECT * FROM qualification_point_templates WHERE id = ?", [intval($_GET['edit'])]);
    if ($editTemplate) {
        $editTemplate['points'] = json_decode($editTemplate['points'], true);
    }
}

// Get all templates
$templates = $db->getAll("SELECT * FROM qualification_point_templates ORDER BY name");

$pageTitle = 'Poängmallar';
$pageType = 'admin';
include __DIR__ . '/../includes/layout-header.php';
?>

<main class="gs-content-with-sidebar">
    <div class="gs-container">
        <!-- Header -->
        <div class="gs-flex gs-items-center gs-justify-between gs-mb-lg">
            <h1 class="gs-h2">
                <i data-lucide="award"></i>
                Kvalpoängmallar
            </h1>
            <div class="gs-flex gs-gap-sm">
                <button type="button" class="gs-btn gs-btn-outline" onclick="openImportModal()">
                    <i data-lucide="upload"></i>
                    Importera
                </button>
                <button type="button" class="gs-btn gs-btn-primary" onclick="openTemplateModal()">
                    <i data-lucide="plus"></i>
                    Ny Mall
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

        <!-- Template Modal -->
        <div id="templateModal" class="gs-modal gs-modal-overlay-hidden">
            <div class="gs-modal-overlay" onclick="closeTemplateModal()"></div>
            <div class="gs-modal-content gs-modal-content-md">
                <div class="gs-modal-header">
                    <h2 class="gs-modal-title">
                        <i data-lucide="award"></i>
                        <span id="modalTitleText">Ny Poängmall</span>
                    </h2>
                    <button type="button" class="gs-modal-close" onclick="closeTemplateModal()">
                        <i data-lucide="x"></i>
                    </button>
                </div>
                <form method="POST" id="templateForm">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" id="formAction" value="create">
                    <input type="hidden" name="id" id="templateId" value="">

                    <div class="gs-modal-body">
                        <div class="gs-grid gs-grid-cols-1 gs-gap-md">
                            <!-- Name -->
                            <div>
                                <label for="name" class="gs-label">
                                    Namn <span class="gs-text-error">*</span>
                                </label>
                                <input type="text" id="name" name="name" class="gs-input" required>
                            </div>

                            <!-- Description -->
                            <div>
                                <label for="description" class="gs-label">Beskrivning</label>
                                <textarea id="description" name="description" class="gs-input" rows="2"></textarea>
                            </div>

                            <!-- Points Grid -->
                            <div>
                                <div class="gs-flex gs-items-center gs-justify-between gs-mb-sm">
                                    <label class="gs-label gs-margin-0">Poängfördelning per placering</label>
                                    <button type="button" class="gs-btn gs-btn-xs gs-btn-outline" onclick="addPointRow()">
                                        <i data-lucide="plus" class="gs-icon-12"></i>
                                        Lägg till rad
                                    </button>
                                </div>
                                <p class="gs-text-xs gs-text-secondary gs-mb-sm">Klicka på "Lägg till rad" för att lägga till fler placeringar</p>

                                <div id="pointsContainer" class="gs-scroll-container-400-pr10">
                                    <!-- Rows will be added dynamically -->
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="gs-modal-footer">
                        <button type="button" class="gs-btn gs-btn-outline" onclick="closeTemplateModal()">
                            Avbryt
                        </button>
                        <button type="submit" class="gs-btn gs-btn-primary">
                            <i data-lucide="check"></i>
                            <span id="submitButtonText">Skapa</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Import Modal -->
        <div id="importModal" class="gs-modal gs-modal-overlay-hidden">
            <div class="gs-modal-overlay" onclick="closeImportModal()"></div>
            <div class="gs-modal-content gs-modal-content-sm">
                <div class="gs-modal-header">
                    <h2 class="gs-modal-title">
                        <i data-lucide="upload"></i>
                        Importera Poängmall
                    </h2>
                    <button type="button" class="gs-modal-close" onclick="closeImportModal()">
                        <i data-lucide="x"></i>
                    </button>
                </div>
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="import">

                    <div class="gs-modal-body">
                        <div class="gs-mb-md">
                            <label class="gs-label">Importera data (JSON eller CSV)</label>
                            <textarea name="import_data" class="gs-input" rows="12" placeholder='JSON format:
{
  "name": "Min mall",
  "description": "Beskrivning",
  "points": {
    "1": 100,
    "2": 80,
    "3": 60
  }
}

CSV format:
1,100
2,80
3,60'></textarea>
                        </div>

                        <div class="gs-alert gs-alert-info">
                            <p class="gs-text-xs"><strong>JSON format:</strong> Klistra in exporterad JSON-data</p>
                            <p class="gs-text-xs"><strong>CSV format:</strong> En rad per placering: placering,poäng</p>
                        </div>
                    </div>

                    <div class="gs-modal-footer">
                        <button type="button" class="gs-btn gs-btn-outline" onclick="closeImportModal()">
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

        <!-- Templates List -->
        <div class="gs-card">
            <div class="gs-card-content">
                <?php if (empty($templates)): ?>
                    <div class="gs-alert gs-alert-warning">
                        <p>Inga poängmallar hittades.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($templates as $template): ?>
                        <?php
                        $points = json_decode($template['points'], true);
                        $maxPosition = !empty($points) ? max(array_keys($points)) : 0;
                        ?>
                        <div class="gs-card gs-mb-md gs-bg-secondary">
                            <div class="gs-card-content">
                                <div class="gs-flex gs-items-start gs-justify-between gs-mb-sm">
                                    <div>
                                        <h3 class="gs-h5 gs-text-primary gs-margin-0-0-qtr-0">
                                            <?= h($template['name']) ?>
                                        </h3>
                                        <?php if (!empty($template['description'])): ?>
                                            <p class="gs-text-sm gs-text-secondary"><?= h($template['description']) ?></p>
                                        <?php endif; ?>
                                    </div>
                                    <div class="gs-flex gs-gap-sm">
                                        <button type="button"
                                                class="gs-btn gs-btn-sm gs-btn-outline"
                                                onclick="exportTemplate(<?= $template['id'] ?>)"
                                                title="Exportera">
                                            <i data-lucide="download"></i>
                                        </button>
                                        <button type="button"
                                                class="gs-btn gs-btn-sm gs-btn-outline"
                                                onclick="editTemplate(<?= $template['id'] ?>)"
                                                title="Redigera">
                                            <i data-lucide="edit"></i>
                                        </button>
                                        <button type="button"
                                                class="gs-btn gs-btn-sm gs-btn-outline gs-btn-danger"
                                                onclick="deleteTemplate(<?= $template['id'] ?>, '<?= addslashes(h($template['name'])) ?>')"
                                                title="Ta bort">
                                            <i data-lucide="trash-2"></i>
                                        </button>
                                    </div>
                                </div>

                                <div class="gs-text-xs gs-text-secondary gs-mt-md">
                                    <strong>Poäng:</strong>
                                    <?php if (empty($points)): ?>
                                        <span>Ingen poängfördelning angiven</span>
                                    <?php else: ?>
                                        <div class="gs-flex gs-gap-xs gs-flex-wrap gs-mt-xs">
                                            <?php foreach ($points as $position => $pointValue): ?>
                                                <span class="gs-badge gs-badge-sm gs-bg-badge-neutral">
                                                    #<?= $position ?>: <?= $pointValue ?>p
                                                </span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        let pointRowCounter = 0;
        const templates = <?= json_encode($templates) ?>;

        function openTemplateModal() {
            document.getElementById('templateModal').style.display = 'flex';
            document.getElementById('templateForm').reset();
            document.getElementById('formAction').value = 'create';
            document.getElementById('templateId').value = '';
            document.getElementById('modalTitleText').textContent = 'Ny Poängmall';
            document.getElementById('submitButtonText').textContent = 'Skapa';

            // Reset points container and add initial rows
            document.getElementById('pointsContainer').innerHTML = '';
            pointRowCounter = 0;
            for (let i = 1; i <= 10; i++) {
                addPointRow(i, '');
            }

            if (typeof lucide !== 'undefined') {
                lucide.createIcons();
            }
        }

        function closeTemplateModal() {
            document.getElementById('templateModal').style.display = 'none';
        }

        function openImportModal() {
            document.getElementById('importModal').style.display = 'flex';
            if (typeof lucide !== 'undefined') {
                lucide.createIcons();
            }
        }

        function closeImportModal() {
            document.getElementById('importModal').style.display = 'none';
        }

        function addPointRow(position = null, points = '') {
            const container = document.getElementById('pointsContainer');
            const nextPosition = position || (pointRowCounter + 1);
            pointRowCounter = Math.max(pointRowCounter, nextPosition);

            const row = document.createElement('div');
            row.className = 'gs-flex gs-items-center gs-gap-sm gs-mb-xs';
            row.innerHTML = `
                <div class="gs-point-row-label-wrapper">
                    <label class="gs-text-xs gs-text-secondary">Plats #${nextPosition}</label>
                </div>
                <div class="gs-point-row-input-wrapper">
                    <input type="number"
                           name="points[${nextPosition}]"
                           class="gs-input gs-input-sm"
                           value="${points}"
                           min="0"
                           placeholder="Poäng">
                </div>
                <button type="button" class="gs-btn gs-btn-xs gs-btn-outline gs-btn-danger" onclick="this.parentElement.remove()">
                    <i data-lucide="x" class="gs-icon-12"></i>
                </button>
            `;
            container.appendChild(row);

            if (typeof lucide !== 'undefined') {
                lucide.createIcons();
            }
        }

        function editTemplate(id) {
            window.location.href = `?edit=${id}`;
        }

        function exportTemplate(id) {
            const template = templates.find(t => t.id == id);
            if (!template) return;

            const exportData = {
                name: template.name,
                description: template.description || '',
                points: JSON.parse(template.points)
            };

            const dataStr = JSON.stringify(exportData, null, 2);
            const blob = new Blob([dataStr], { type: 'application/json' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `pointtemplate_${template.name.replace(/[^a-z0-9]/gi, '_').toLowerCase()}.json`;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        }

        function deleteTemplate(id, name) {
            if (!confirm(`Är du säker på att du vill ta bort "${name}"?`)) {
                return;
            }

            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="${id}">
            `;
            document.body.appendChild(form);
            form.submit();
        }

        // Handle edit mode
        <?php if ($editTemplate): ?>
            document.addEventListener('DOMContentLoaded', function() {
                document.getElementById('formAction').value = 'update';
                document.getElementById('templateId').value = '<?= $editTemplate['id'] ?>';
                document.getElementById('name').value = '<?= addslashes($editTemplate['name']) ?>';
                document.getElementById('description').value = '<?= addslashes($editTemplate['description'] ?? '') ?>';

                // Reset and populate points
                document.getElementById('pointsContainer').innerHTML = '';
                pointRowCounter = 0;

                const editPoints = <?= json_encode($editTemplate['points'] ?? []) ?>;
                const positions = Object.keys(editPoints).map(Number).sort((a, b) => a - b);

                positions.forEach(position => {
                    addPointRow(position, editPoints[position]);
                });

                // Add a few empty rows
                for (let i = 0; i < 3; i++) {
                    addPointRow();
                }

                document.getElementById('modalTitleText').textContent = 'Redigera Poängmall';
                document.getElementById('submitButtonText').textContent = 'Uppdatera';
                document.getElementById('templateModal').style.display = 'flex';

                if (typeof lucide !== 'undefined') {
                    lucide.createIcons();
                }
            });
        <?php endif; ?>

        // Close modal with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeTemplateModal();
                closeImportModal();
            }
        });
    </script>
</main>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>

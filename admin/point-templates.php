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
            <button type="button" class="gs-btn gs-btn-primary" onclick="openTemplateModal()">
                <i data-lucide="plus"></i>
                Ny Mall
            </button>
        </div>

        <!-- Messages -->
        <?php if ($message): ?>
            <div class="gs-alert gs-alert-<?= $messageType ?> gs-mb-lg">
                <i data-lucide="<?= $messageType === 'success' ? 'check-circle' : ($messageType === 'error' ? 'alert-circle' : 'info') ?>"></i>
                <?= h($message) ?>
            </div>
        <?php endif; ?>

        <!-- Template Modal -->
        <div id="templateModal" class="gs-modal" style="display: none;">
            <div class="gs-modal-overlay" onclick="closeTemplateModal()"></div>
            <div class="gs-modal-content" style="max-width: 900px;">
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
                                <label class="gs-label">Poängfördelning per placering</label>
                                <p class="gs-text-xs gs-text-secondary gs-mb-sm">Ange hur många poäng varje placering ger. Tomma fält = 0 poäng.</p>

                                <div id="pointsGrid" class="gs-grid gs-grid-cols-5 gs-md-grid-cols-10 gs-gap-sm">
                                    <?php for ($i = 1; $i <= 30; $i++): ?>
                                        <div>
                                            <label for="points_<?= $i ?>" class="gs-text-xs gs-text-secondary">#<?= $i ?></label>
                                            <input type="number"
                                                   id="points_<?= $i ?>"
                                                   name="points[<?= $i ?>]"
                                                   class="gs-input gs-input-sm"
                                                   min="0"
                                                   placeholder="0">
                                        </div>
                                    <?php endfor; ?>
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
                        <div class="gs-card gs-mb-md" style="background: var(--gs-background-secondary);">
                            <div class="gs-card-content">
                                <div class="gs-flex gs-items-start gs-justify-between gs-mb-sm">
                                    <div>
                                        <h3 class="gs-h5 gs-text-primary" style="margin: 0 0 0.25rem 0;">
                                            <?= h($template['name']) ?>
                                        </h3>
                                        <?php if (!empty($template['description'])): ?>
                                            <p class="gs-text-sm gs-text-secondary"><?= h($template['description']) ?></p>
                                        <?php endif; ?>
                                    </div>
                                    <div class="gs-flex gs-gap-sm">
                                        <button type="button"
                                                class="gs-btn gs-btn-sm gs-btn-outline"
                                                onclick="editTemplate(<?= $template['id'] ?>)">
                                            <i data-lucide="edit"></i>
                                        </button>
                                        <button type="button"
                                                class="gs-btn gs-btn-sm gs-btn-outline gs-btn-danger"
                                                onclick="deleteTemplate(<?= $template['id'] ?>, '<?= addslashes(h($template['name'])) ?>')">
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
                                                <span class="gs-badge gs-badge-sm" style="background: var(--gs-background); color: var(--gs-text);">
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
        function openTemplateModal() {
            document.getElementById('templateModal').style.display = 'flex';
            document.getElementById('templateForm').reset();
            document.getElementById('formAction').value = 'create';
            document.getElementById('templateId').value = '';
            document.getElementById('modalTitleText').textContent = 'Ny Poängmall';
            document.getElementById('submitButtonText').textContent = 'Skapa';

            if (typeof lucide !== 'undefined') {
                lucide.createIcons();
            }
        }

        function closeTemplateModal() {
            document.getElementById('templateModal').style.display = 'none';
        }

        function editTemplate(id) {
            window.location.href = `?edit=${id}`;
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

                // Populate points
                <?php foreach ($editTemplate['points'] ?? [] as $position => $pointValue): ?>
                    const input<?= $position ?> = document.getElementById('points_<?= $position ?>');
                    if (input<?= $position ?>) {
                        input<?= $position ?>.value = '<?= $pointValue ?>';
                    }
                <?php endforeach; ?>

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
            }
        });
    </script>
</main>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>

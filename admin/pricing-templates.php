<?php
/**
 * Pricing Templates Management
 * Create and manage reusable pricing templates for events
 */

require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();

// Initialize message
$message = '';
$messageType = 'info';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create_template') {
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $isDefault = isset($_POST['is_default']) ? 1 : 0;

        if (empty($name)) {
            $message = 'Namn är obligatoriskt';
            $messageType = 'error';
        } else {
            // If setting as default, remove default from others
            if ($isDefault) {
                $db->query("UPDATE pricing_templates SET is_default = 0");
            }

            $newId = $db->insert('pricing_templates', [
                'name' => $name,
                'description' => $description,
                'is_default' => $isDefault
            ]);

            // Redirect to edit the new template
            header("Location: /admin/pricing-templates.php?edit=$newId");
            exit;
        }
    }

    elseif ($action === 'update_template') {
        $id = intval($_POST['id']);
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $isDefault = isset($_POST['is_default']) ? 1 : 0;
        $earlyBirdPercent = floatval($_POST['early_bird_percent'] ?? 15);
        $earlyBirdDays = intval($_POST['early_bird_days'] ?? 21);
        $lateFeePercent = floatval($_POST['late_fee_percent'] ?? 25);
        $lateFeeDays = intval($_POST['late_fee_days'] ?? 3);

        if (empty($name)) {
            $message = 'Namn är obligatoriskt';
            $messageType = 'error';
        } else {
            // If setting as default, remove default from others
            if ($isDefault) {
                $db->query("UPDATE pricing_templates SET is_default = 0 WHERE id != ?", [$id]);
            }

            $db->update('pricing_templates', [
                'name' => $name,
                'description' => $description,
                'is_default' => $isDefault,
                'early_bird_percent' => $earlyBirdPercent,
                'early_bird_days_before' => $earlyBirdDays,
                'late_fee_percent' => $lateFeePercent,
                'late_fee_days_before' => $lateFeeDays
            ], 'id = ?', [$id]);
            $message = "Prismall uppdaterad!";
            $messageType = 'success';
        }
    }

    elseif ($action === 'delete_template') {
        $id = intval($_POST['id']);
        $db->delete('pricing_templates', 'id = ?', [$id]);
        $message = "Prismall borttagen!";
        $messageType = 'success';
        header("Location: /admin/pricing-templates.php");
        exit;
    }

    elseif ($action === 'save_prices') {
        $templateId = intval($_POST['template_id']);
        $classIds = $_POST['class_id'] ?? [];
        $basePrices = $_POST['base_price'] ?? [];

        $saved = 0;
        foreach ($classIds as $index => $classId) {
            $basePrice = floatval($basePrices[$index] ?? 0);

            if ($basePrice > 0) {
                // Check if exists
                $existing = $db->getRow("SELECT id FROM pricing_template_rules WHERE template_id = ? AND class_id = ?", [$templateId, $classId]);

                $data = [
                    'base_price' => $basePrice
                ];

                if ($existing) {
                    $db->update('pricing_template_rules', $data, 'id = ?', [$existing['id']]);
                } else {
                    $data['template_id'] = $templateId;
                    $data['class_id'] = $classId;
                    $db->insert('pricing_template_rules', $data);
                }
                $saved++;
            } else {
                // Remove pricing if price is 0
                $db->delete('pricing_template_rules', 'template_id = ? AND class_id = ?', [$templateId, $classId]);
            }
        }
        $message = "Sparade $saved priser";
        $messageType = 'success';
    }
}

// Check if editing a template
$editTemplate = null;
$templateRules = [];
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $editTemplate = $db->getRow("SELECT * FROM pricing_templates WHERE id = ?", [intval($_GET['edit'])]);
    if ($editTemplate) {
        $rules = $db->getAll("SELECT * FROM pricing_template_rules WHERE template_id = ?", [$editTemplate['id']]);
        foreach ($rules as $rule) {
            $templateRules[$rule['class_id']] = $rule;
        }
    }
}

// Fetch all templates
$templates = $db->getAll("
    SELECT t.*,
           COUNT(r.id) as rule_count,
           (SELECT COUNT(*) FROM events WHERE pricing_template_id = t.id) as event_count,
           (SELECT COUNT(*) FROM series WHERE default_pricing_template_id = t.id) as series_count
    FROM pricing_templates t
    LEFT JOIN pricing_template_rules r ON t.id = r.template_id
    GROUP BY t.id
    ORDER BY t.is_default DESC, t.name ASC
");

// Fetch all classes for pricing form
$classes = $db->getAll("SELECT id, name, display_name FROM classes ORDER BY sort_order ASC");

$pageTitle = $editTemplate ? 'Redigera Prismall - ' . $editTemplate['name'] : 'Prismallar';
$pageType = 'admin';
include __DIR__ . '/../includes/layout-header.php';
?>

<style>
/* Remove spinners from number inputs */
input[type="number"]::-webkit-inner-spin-button,
input[type="number"]::-webkit-outer-spin-button {
    -webkit-appearance: none;
    margin: 0;
}
input[type="number"] {
    -moz-appearance: textfield;
}
</style>

<main class="gs-content-with-sidebar">
    <div class="gs-container">
        <?php if ($editTemplate): ?>
        <!-- Edit Template View -->
        <div class="gs-card gs-mb-lg">
            <div class="gs-card-content">
                <div class="gs-flex gs-justify-between gs-items-center">
                    <div>
                        <h1 class="gs-h3">
                            <i data-lucide="file-text"></i>
                            <?= htmlspecialchars($editTemplate['name']) ?>
                        </h1>
                        <p class="gs-text-secondary gs-text-sm">
                            Konfigurera priser för denna mall
                        </p>
                    </div>
                    <a href="/admin/pricing-templates.php" class="gs-btn gs-btn-outline">
                        <i data-lucide="arrow-left"></i>
                        Tillbaka
                    </a>
                </div>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="gs-alert gs-alert-<?= $messageType ?> gs-mb-lg">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <!-- Template Settings -->
        <div class="gs-card gs-mb-lg">
            <div class="gs-card-header">
                <h2 class="gs-h5">
                    <i data-lucide="settings"></i>
                    Mallinställningar
                </h2>
            </div>
            <div class="gs-card-content">
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update_template">
                    <input type="hidden" name="id" value="<?= $editTemplate['id'] ?>">

                    <div class="gs-grid gs-grid-cols-1 gs-md-grid-cols-2 gs-gap-md">
                        <div>
                            <label class="gs-label">Namn</label>
                            <input type="text" name="name" class="gs-input" value="<?= htmlspecialchars($editTemplate['name']) ?>" required>
                        </div>
                        <div>
                            <label class="gs-label">Beskrivning</label>
                            <input type="text" name="description" class="gs-input" value="<?= htmlspecialchars($editTemplate['description'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="gs-mt-md">
                        <label class="gs-flex gs-items-center gs-gap-sm">
                            <input type="checkbox" name="is_default" value="1" <?= $editTemplate['is_default'] ? 'checked' : '' ?>>
                            <span>Standardmall för nya events</span>
                        </label>
                    </div>

                    <!-- Pricing Settings -->
                    <div class="gs-mt-lg">
                        <h3 class="gs-h6 gs-mb-md">Prisregler</h3>
                        <div class="gs-grid gs-grid-cols-1 gs-md-grid-cols-2 gs-gap-md">
                            <div class="gs-card gs-p-md" style="background: var(--gs-success-bg);">
                                <label class="gs-label gs-text-success">Early Bird (rabatt)</label>
                                <div class="gs-flex gs-gap-sm gs-items-center gs-mt-sm">
                                    <input type="number" name="early_bird_percent" class="gs-input"
                                           value="<?= $editTemplate['early_bird_percent'] ?? 15 ?>"
                                           min="0" max="100" style="width: 80px;">
                                    <span>% rabatt,</span>
                                    <input type="number" name="early_bird_days" class="gs-input"
                                           value="<?= $editTemplate['early_bird_days_before'] ?? 21 ?>"
                                           min="0" max="90" style="width: 80px;">
                                    <span>dagar före event</span>
                                </div>
                            </div>
                            <div class="gs-card gs-p-md" style="background: var(--gs-warning-bg);">
                                <label class="gs-label gs-text-warning">Efteranmälan (tillägg)</label>
                                <div class="gs-flex gs-gap-sm gs-items-center gs-mt-sm">
                                    <input type="number" name="late_fee_percent" class="gs-input"
                                           value="<?= $editTemplate['late_fee_percent'] ?? 25 ?>"
                                           min="0" max="100" style="width: 80px;">
                                    <span>% tillägg,</span>
                                    <input type="number" name="late_fee_days" class="gs-input"
                                           value="<?= $editTemplate['late_fee_days_before'] ?? 3 ?>"
                                           min="0" max="30" style="width: 80px;">
                                    <span>dagar före event</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="gs-mt-md">
                        <button type="submit" class="gs-btn gs-btn-primary">
                            <i data-lucide="save"></i>
                            Spara inställningar
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Pricing Rules per Class -->
        <div class="gs-card">
            <div class="gs-card-header">
                <h2 class="gs-h5">
                    <i data-lucide="credit-card"></i>
                    Grundpriser per klass
                </h2>
            </div>
            <div class="gs-card-content">
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_prices">
                    <input type="hidden" name="template_id" value="<?= $editTemplate['id'] ?>">

                    <?php
                    // Get template pricing settings for calculations
                    $ebPercent = $editTemplate['early_bird_percent'] ?? 15;
                    $latePercent = $editTemplate['late_fee_percent'] ?? 25;
                    ?>

                    <div class="gs-table-responsive">
                        <table class="gs-table">
                            <thead>
                                <tr>
                                    <th>Klass</th>
                                    <th>Ordinarie pris</th>
                                    <th>Early Bird (-<?= $ebPercent ?>%)</th>
                                    <th>Efteranmälan (+<?= $latePercent ?>%)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($classes as $class):
                                    $rule = $templateRules[$class['id']] ?? null;
                                    $basePrice = $rule['base_price'] ?? 0;
                                    $ebPrice = $basePrice * (1 - $ebPercent / 100);
                                    $latePrice = $basePrice * (1 + $latePercent / 100);
                                ?>
                                    <tr data-row="<?= $class['id'] ?>">
                                        <td>
                                            <input type="hidden" name="class_id[]" value="<?= $class['id'] ?>">
                                            <strong><?= htmlspecialchars($class['display_name'] ?: $class['name']) ?></strong>
                                        </td>
                                        <td>
                                            <div class="gs-flex gs-items-center gs-gap-xs">
                                                <input type="number" name="base_price[]" class="gs-input"
                                                       data-class="<?= $class['id'] ?>"
                                                       value="<?= $basePrice ?: '' ?>"
                                                       min="0" step="1" style="width: 100px;"
                                                       oninput="calculatePrices(<?= $class['id'] ?>, <?= $ebPercent ?>, <?= $latePercent ?>)">
                                                <span class="gs-text-secondary">kr</span>
                                            </div>
                                        </td>
                                        <td>
                                            <span id="eb-<?= $class['id'] ?>" class="gs-text-success gs-font-bold">
                                                <?= $basePrice > 0 ? number_format($ebPrice, 0) . ' kr' : '-' ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span id="late-<?= $class['id'] ?>" class="gs-text-warning gs-font-bold">
                                                <?= $basePrice > 0 ? number_format($latePrice, 0) . ' kr' : '-' ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="gs-mt-lg">
                        <button type="submit" class="gs-btn gs-btn-primary">
                            <i data-lucide="save"></i>
                            Spara priser
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <?php else: ?>
        <!-- Templates List View -->
        <div class="gs-flex gs-items-center gs-justify-between gs-mb-lg">
            <h1 class="gs-h2">
                <i data-lucide="file-text"></i>
                Prismallar
            </h1>
            <button type="button" class="gs-btn gs-btn-primary" onclick="openCreateModal()">
                <i data-lucide="plus"></i>
                Ny Prismall
            </button>
        </div>

        <?php if ($message): ?>
            <div class="gs-alert gs-alert-<?= $messageType ?> gs-mb-lg">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <!-- Templates Table -->
        <div class="gs-card">
            <div class="gs-card-content">
                <?php if (empty($templates)): ?>
                    <div class="gs-alert gs-alert-warning">
                        <p>Inga prismallar skapade ännu. Skapa din första mall för att komma igång.</p>
                    </div>
                <?php else: ?>
                    <div class="gs-table-responsive">
                        <table class="gs-table">
                            <thead>
                                <tr>
                                    <th>Namn</th>
                                    <th>Beskrivning</th>
                                    <th>Klasser</th>
                                    <th>Används av</th>
                                    <th>Åtgärder</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($templates as $template): ?>
                                    <tr>
                                        <td>
                                            <strong><?= htmlspecialchars($template['name']) ?></strong>
                                            <?php if ($template['is_default']): ?>
                                                <span class="gs-badge gs-badge-success gs-ml-sm">Standard</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="gs-text-secondary">
                                            <?= htmlspecialchars($template['description'] ?? '-') ?>
                                        </td>
                                        <td>
                                            <span class="gs-badge"><?= $template['rule_count'] ?> klasser</span>
                                        </td>
                                        <td>
                                            <?php if ($template['event_count'] > 0 || $template['series_count'] > 0): ?>
                                                <?php if ($template['event_count'] > 0): ?>
                                                    <span class="gs-text-sm"><?= $template['event_count'] ?> event</span>
                                                <?php endif; ?>
                                                <?php if ($template['series_count'] > 0): ?>
                                                    <span class="gs-text-sm"><?= $template['series_count'] ?> serier</span>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="gs-text-secondary">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="gs-flex gs-gap-sm">
                                                <a href="?edit=<?= $template['id'] ?>" class="gs-btn gs-btn-sm gs-btn-primary" title="Redigera priser">
                                                    <i data-lucide="edit" class="gs-icon-14"></i>
                                                </a>
                                                <form method="POST" style="display: inline;" onsubmit="return confirm('Är du säker på att du vill ta bort denna mall?');">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="delete_template">
                                                    <input type="hidden" name="id" value="<?= $template['id'] ?>">
                                                    <button type="submit" class="gs-btn gs-btn-sm gs-btn-outline gs-btn-danger" title="Ta bort">
                                                        <i data-lucide="trash-2" class="gs-icon-14"></i>
                                                    </button>
                                                </form>
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

        <!-- Create Template Modal -->
        <div id="createModal" class="gs-modal gs-hidden">
            <div class="gs-modal-overlay" onclick="closeCreateModal()"></div>
            <div class="gs-modal-content gs-modal-sm">
                <div class="gs-modal-header">
                    <h2 class="gs-modal-title">
                        <i data-lucide="plus"></i>
                        Ny Prismall
                    </h2>
                    <button type="button" class="gs-modal-close" onclick="closeCreateModal()">
                        <i data-lucide="x"></i>
                    </button>
                </div>
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="create_template">

                    <div class="gs-modal-body">
                        <div class="gs-mb-md">
                            <label class="gs-label">Namn <span class="gs-text-error">*</span></label>
                            <input type="text" name="name" class="gs-input" required placeholder="T.ex. GravitySeries Standard">
                        </div>
                        <div class="gs-mb-md">
                            <label class="gs-label">Beskrivning</label>
                            <input type="text" name="description" class="gs-input" placeholder="Kort beskrivning av mallen">
                        </div>
                        <div>
                            <label class="gs-flex gs-items-center gs-gap-sm">
                                <input type="checkbox" name="is_default" value="1">
                                <span>Standardmall för nya events</span>
                            </label>
                        </div>
                    </div>

                    <div class="gs-modal-footer">
                        <button type="button" class="gs-btn gs-btn-outline" onclick="closeCreateModal()">Avbryt</button>
                        <button type="submit" class="gs-btn gs-btn-primary">
                            <i data-lucide="plus"></i>
                            Skapa
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <script>
            function openCreateModal() {
                document.getElementById('createModal').style.display = 'flex';
                if (typeof lucide !== 'undefined') lucide.createIcons();
            }
            function closeCreateModal() {
                document.getElementById('createModal').style.display = 'none';
            }
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') closeCreateModal();
            });
        </script>
        <?php endif; ?>
    </div>
</main>

<script>
function calculatePrices(classId, ebPercent, latePercent) {
    const row = document.querySelector(`tr[data-row="${classId}"]`);
    if (!row) return;

    const baseInput = row.querySelector('input[data-class="' + classId + '"]');
    const ebSpan = document.getElementById(`eb-${classId}`);
    const lateSpan = document.getElementById(`late-${classId}`);

    if (!baseInput || !ebSpan || !lateSpan) return;

    const base = parseFloat(baseInput.value) || 0;

    if (base > 0) {
        const ebPrice = Math.round(base * (1 - ebPercent / 100));
        const latePrice = Math.round(base * (1 + latePercent / 100));
        ebSpan.textContent = ebPrice + ' kr';
        lateSpan.textContent = latePrice + ' kr';
    } else {
        ebSpan.textContent = '-';
        lateSpan.textContent = '-';
    }
}
</script>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>

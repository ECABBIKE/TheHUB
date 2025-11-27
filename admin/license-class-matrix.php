<?php
/**
 * License-Class Matrix Admin
 *
 * Visual matrix for managing which license types can register for which classes.
 * Rows = License types, Columns = Classes
 */

require_once __DIR__ . '/../config.php';
require_admin();
require_once __DIR__ . '/../includes/admin-layout.php';

$db = getDB();
$message = '';
$messageType = 'info';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();

    $action = $_POST['action'] ?? '';

    if ($action === 'save_matrix') {
        try {
            // Clear existing mappings
            $db->query("DELETE FROM class_license_eligibility WHERE 1=1");

            // Insert new mappings
            $mappings = $_POST['mapping'] ?? [];
            $inserted = 0;

            foreach ($mappings as $classId => $licenseTypes) {
                foreach ($licenseTypes as $licenseCode => $value) {
                    if ($value === '1') {
                        $db->insert('class_license_eligibility', [
                            'class_id' => (int)$classId,
                            'license_type_code' => $licenseCode,
                            'is_allowed' => 1
                        ]);
                        $inserted++;
                    }
                }
            }

            $message = "Matris sparad! $inserted kopplingar skapade.";
            $messageType = 'success';
        } catch (Exception $e) {
            $message = 'Fel: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}

// Get all license types
$licenseTypes = [];
try {
    $licenseTypes = $db->getAll("SELECT code, name, description, priority FROM license_types WHERE is_active = 1 ORDER BY priority DESC");
} catch (Exception $e) {
    $message = 'Licenstyper saknas. Kör migration 039 först.';
    $messageType = 'warning';
}

// Get all active classes
$classes = $db->getAll("
    SELECT id, name, display_name, gender, discipline
    FROM classes
    WHERE active = 1
    ORDER BY sort_order ASC, name ASC
");

// Get current mappings
$currentMappings = [];
try {
    $mappings = $db->getAll("SELECT class_id, license_type_code FROM class_license_eligibility WHERE is_allowed = 1");
    foreach ($mappings as $m) {
        $currentMappings[$m['class_id']][$m['license_type_code']] = true;
    }
} catch (Exception $e) {
    // Table might not exist yet
}

$pageTitle = 'Licens-Klass Matris';
$pageType = 'admin';
include __DIR__ . '/../includes/layout-header.php';
?>

<main class="gs-content-with-sidebar">
    <div class="gs-container">
        <?php render_admin_header('Konfiguration', []); ?>

        <!-- Messages -->
        <?php if ($message): ?>
            <div class="gs-alert gs-alert-<?= $messageType ?> gs-mb-lg">
                <i data-lucide="<?= $messageType === 'success' ? 'check-circle' : ($messageType === 'error' ? 'alert-circle' : 'info') ?>"></i>
                <?= h($message) ?>
            </div>
        <?php endif; ?>

        <?php if (empty($licenseTypes)): ?>
            <div class="gs-card">
                <div class="gs-card-content gs-text-center gs-padding-xl">
                    <i data-lucide="alert-triangle" class="gs-icon-48-empty"></i>
                    <h3 class="gs-h4 gs-mt-md">Licenstyper saknas</h3>
                    <p class="gs-text-secondary">Kör migration 039_swedish_cycling_licenses.sql först för att skapa licenstyper.</p>
                    <a href="/admin/migrations/" class="gs-btn gs-btn-primary gs-mt-md">
                        <i data-lucide="database"></i>
                        Gå till Migrationer
                    </a>
                </div>
            </div>
        <?php else: ?>

        <div class="gs-card">
            <div class="gs-card-header">
                <h2 class="gs-h4">
                    <i data-lucide="grid-3x3"></i>
                    Licens-Klass Matris
                </h2>
                <p class="gs-text-secondary gs-text-sm gs-mb-0">
                    Kryssa i vilka klasser varje licenstyp får anmäla sig till
                </p>
            </div>
            <div class="gs-card-content">
                <form method="POST" id="matrixForm">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_matrix">

                    <div class="gs-table-responsive" style="max-height: 70vh; overflow: auto;">
                        <table class="gs-table gs-table-compact" style="font-size: 0.85rem;">
                            <thead style="position: sticky; top: 0; background: var(--gs-bg); z-index: 10;">
                                <tr>
                                    <th style="position: sticky; left: 0; background: var(--gs-bg); z-index: 11; min-width: 200px;">
                                        Licenstyp
                                    </th>
                                    <?php foreach ($classes as $class): ?>
                                        <th class="gs-text-center" style="min-width: 80px; writing-mode: vertical-lr; text-orientation: mixed; height: 120px; padding: 8px 4px;">
                                            <span title="<?= h($class['display_name']) ?>">
                                                <?= h($class['name']) ?>
                                                <?php if ($class['gender'] === 'M'): ?>
                                                    <span class="gs-text-info">♂</span>
                                                <?php elseif ($class['gender'] === 'K' || $class['gender'] === 'F'): ?>
                                                    <span class="gs-text-error">♀</span>
                                                <?php endif; ?>
                                            </span>
                                        </th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($licenseTypes as $license): ?>
                                    <tr>
                                        <td style="position: sticky; left: 0; background: var(--gs-bg); z-index: 1;">
                                            <strong><?= h($license['name']) ?></strong>
                                            <br>
                                            <small class="gs-text-secondary"><?= h(substr($license['description'] ?? '', 0, 50)) ?></small>
                                        </td>
                                        <?php foreach ($classes as $class): ?>
                                            <td class="gs-text-center">
                                                <input type="hidden"
                                                       name="mapping[<?= $class['id'] ?>][<?= h($license['code']) ?>]"
                                                       value="0">
                                                <input type="checkbox"
                                                       name="mapping[<?= $class['id'] ?>][<?= h($license['code']) ?>]"
                                                       value="1"
                                                       <?= isset($currentMappings[$class['id']][$license['code']]) ? 'checked' : '' ?>
                                                       style="width: 18px; height: 18px; cursor: pointer;">
                                            </td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="gs-flex gs-justify-between gs-items-center gs-mt-lg gs-pt-lg" style="border-top: 1px solid var(--gs-border);">
                        <div class="gs-flex gs-gap-sm">
                            <button type="button" class="gs-btn gs-btn-outline gs-btn-sm" onclick="selectAll()">
                                <i data-lucide="check-square"></i>
                                Markera alla
                            </button>
                            <button type="button" class="gs-btn gs-btn-outline gs-btn-sm" onclick="deselectAll()">
                                <i data-lucide="square"></i>
                                Avmarkera alla
                            </button>
                        </div>
                        <button type="submit" class="gs-btn gs-btn-primary">
                            <i data-lucide="save"></i>
                            Spara Matris
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="gs-card gs-mt-lg">
            <div class="gs-card-header">
                <h3 class="gs-h5">
                    <i data-lucide="info"></i>
                    Licenstyper (<?= count($licenseTypes) ?>)
                </h3>
            </div>
            <div class="gs-card-content">
                <div class="gs-grid gs-grid-cols-1 gs-md-grid-cols-2 gs-gap-md">
                    <?php foreach ($licenseTypes as $license): ?>
                        <div class="gs-p-sm" style="border: 1px solid var(--gs-border); border-radius: var(--gs-radius);">
                            <strong><?= h($license['name']) ?></strong>
                            <br>
                            <small class="gs-text-secondary"><?= h($license['description']) ?></small>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <?php endif; ?>

        <?php render_admin_footer(); ?>
    </div>
</main>

<script>
function selectAll() {
    document.querySelectorAll('#matrixForm input[type="checkbox"]').forEach(cb => cb.checked = true);
}

function deselectAll() {
    document.querySelectorAll('#matrixForm input[type="checkbox"]').forEach(cb => cb.checked = false);
}
</script>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>

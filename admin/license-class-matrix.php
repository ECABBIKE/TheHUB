<?php
/**
 * License-Class Matrix Admin
 *
 * Visual matrix for managing which license types can register for which classes.
 * Three tabs for different event license classes: National, Sportmotion, Motion
 */

require_once __DIR__ . '/../config.php';
require_admin();
require_once __DIR__ . '/../includes/admin-layout.php';

$db = getDB();
$message = '';
$messageType = 'info';

// Available event license classes
$eventLicenseClasses = [
    'national' => ['name' => 'Nationellt', 'icon' => 'trophy', 'color' => 'warning', 'desc' => 'Nationella tävlingar med full rankingpoäng'],
    'sportmotion' => ['name' => 'Sportmotion', 'icon' => 'bike', 'color' => 'info', 'desc' => 'Sportmotion-event med 50% rankingpoäng'],
    'motion' => ['name' => 'Motion', 'icon' => 'heart', 'color' => 'success', 'desc' => 'Motion-event utan rankingpoäng']
];

// Current tab
$currentTab = $_GET['tab'] ?? 'national';
if (!array_key_exists($currentTab, $eventLicenseClasses)) {
    $currentTab = 'national';
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();

    $action = $_POST['action'] ?? '';
    $saveTab = $_POST['event_license_class'] ?? 'national';

    if ($action === 'save_matrix' && array_key_exists($saveTab, $eventLicenseClasses)) {
        try {
            // Clear existing mappings for this event_license_class
            $db->query("DELETE FROM class_license_eligibility WHERE event_license_class = ?", [$saveTab]);

            // Insert new mappings
            $mappings = $_POST['mapping'] ?? [];
            $inserted = 0;

            foreach ($mappings as $classId => $licenseTypes) {
                foreach ($licenseTypes as $licenseCode => $value) {
                    if ($value === '1') {
                        $db->insert('class_license_eligibility', [
                            'event_license_class' => $saveTab,
                            'class_id' => (int)$classId,
                            'license_type_code' => $licenseCode,
                            'is_allowed' => 1
                        ]);
                        $inserted++;
                    }
                }
            }

            $message = "Matris för '{$eventLicenseClasses[$saveTab]['name']}' sparad! $inserted kopplingar skapade.";
            $messageType = 'success';
            $currentTab = $saveTab; // Stay on same tab
        } catch (Exception $e) {
            $message = 'Fel: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}

// Get all license types from database
$licenseTypes = [];
try {
    $licenseTypes = $db->getAll("
        SELECT code, name, description, priority
        FROM license_types
        WHERE is_active = 1
        ORDER BY priority DESC
    ");
} catch (Exception $e) {
    // Fallback to basic types if table doesn't exist
    $licenseTypes = [
        ['code' => 'engangslicens', 'name' => 'Engångslicens', 'description' => 'För enstaka tävlingar', 'priority' => 10],
        ['code' => 'motionslicens', 'name' => 'Motionslicens', 'description' => 'För motion/sportmotion', 'priority' => 20],
        ['code' => 'tavlingslicens', 'name' => 'Tävlingslicens', 'description' => 'Youth, Junior, Elite, Master etc', 'priority' => 100]
    ];
}

// Get all active classes
$classes = $db->getAll("
    SELECT id, name, display_name, gender, discipline
    FROM classes
    WHERE active = 1
    ORDER BY sort_order ASC, name ASC
");

// Get current mappings for the current tab
$currentMappings = [];
try {
    $mappings = $db->getAll(
        "SELECT class_id, license_type_code FROM class_license_eligibility WHERE event_license_class = ? AND is_allowed = 1",
        [$currentTab]
    );
    foreach ($mappings as $m) {
        $currentMappings[$m['class_id']][$m['license_type_code']] = true;
    }
} catch (Exception $e) {
    // Table might not exist yet or column missing - run migration 041
    $message = 'Kör migration 041 först för att uppdatera databasschemat.';
    $messageType = 'warning';
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

        <!-- Event License Class Tabs -->
        <div class="gs-card gs-mb-md">
            <div class="gs-card-content gs-p-0">
                <nav class="admin-tabs" role="tablist">
                    <?php foreach ($eventLicenseClasses as $key => $info): ?>
                        <a href="?tab=<?= $key ?>"
                           class="admin-tabs__tab <?= $currentTab === $key ? 'admin-tabs__tab--active' : '' ?>"
                           role="tab">
                            <i data-lucide="<?= $info['icon'] ?>"></i>
                            <span><?= h($info['name']) ?></span>
                        </a>
                    <?php endforeach; ?>
                </nav>
            </div>
        </div>

        <!-- Current Tab Info -->
        <div class="gs-alert gs-alert-<?= $eventLicenseClasses[$currentTab]['color'] ?> gs-mb-md">
            <i data-lucide="<?= $eventLicenseClasses[$currentTab]['icon'] ?>"></i>
            <div>
                <strong><?= h($eventLicenseClasses[$currentTab]['name']) ?> Event</strong>
                <br>
                <span class="gs-text-sm"><?= h($eventLicenseClasses[$currentTab]['desc']) ?></span>
            </div>
        </div>

        <?php if (empty($classes)): ?>
            <div class="gs-card">
                <div class="gs-card-content gs-text-center gs-padding-xl">
                    <i data-lucide="alert-triangle" class="gs-icon-48-empty"></i>
                    <h3 class="gs-h4 gs-mt-md">Inga klasser</h3>
                    <p class="gs-text-secondary">Skapa klasser först under Konfiguration → Klasser.</p>
                </div>
            </div>
        <?php else: ?>

        <div class="gs-card">
            <div class="gs-card-header">
                <h2 class="gs-h4">
                    <i data-lucide="grid-3x3"></i>
                    Licensmatris för <?= h($eventLicenseClasses[$currentTab]['name']) ?>
                </h2>
                <p class="gs-text-secondary gs-text-sm gs-mb-0">
                    Kryssa i vilka klasser varje licenstyp får anmäla sig till på <?= strtolower($eventLicenseClasses[$currentTab]['name']) ?> event
                </p>
            </div>
            <div class="gs-card-content">
                <form method="POST" id="matrixForm">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_matrix">
                    <input type="hidden" name="event_license_class" value="<?= h($currentTab) ?>">

                    <div class="gs-table-responsive" style="max-height: 60vh; overflow: auto;">
                        <table class="gs-table gs-table-compact" style="font-size: 0.9rem;">
                            <thead style="position: sticky; top: 0; background: var(--gs-bg); z-index: 10;">
                                <tr>
                                    <th style="position: sticky; left: 0; background: var(--gs-bg); z-index: 11; min-width: 150px;">
                                        Klass
                                    </th>
                                    <?php foreach ($licenseTypes as $license): ?>
                                        <th class="gs-text-center" style="min-width: 120px;">
                                            <div><?= h($license['name']) ?></div>
                                            <small class="gs-text-secondary gs-font-normal"><?= h($license['description']) ?></small>
                                        </th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($classes as $class): ?>
                                    <tr>
                                        <td style="position: sticky; left: 0; background: var(--gs-bg); z-index: 1;">
                                            <strong><?= h($class['display_name'] ?: $class['name']) ?></strong>
                                            <?php if ($class['gender'] === 'M'): ?>
                                                <span class="gs-badge gs-badge-info gs-ml-xs">♂</span>
                                            <?php elseif ($class['gender'] === 'K' || $class['gender'] === 'F'): ?>
                                                <span class="gs-badge gs-badge-error gs-ml-xs">♀</span>
                                            <?php else: ?>
                                                <span class="gs-badge gs-ml-xs">Mix</span>
                                            <?php endif; ?>
                                        </td>
                                        <?php foreach ($licenseTypes as $license): ?>
                                            <td class="gs-text-center">
                                                <input type="hidden"
                                                       name="mapping[<?= $class['id'] ?>][<?= h($license['code']) ?>]"
                                                       value="0">
                                                <label style="cursor: pointer; display: block; padding: 8px;">
                                                    <input type="checkbox"
                                                           name="mapping[<?= $class['id'] ?>][<?= h($license['code']) ?>]"
                                                           value="1"
                                                           <?= isset($currentMappings[$class['id']][$license['code']]) ? 'checked' : '' ?>
                                                           style="width: 20px; height: 20px; cursor: pointer;">
                                                </label>
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
                            <button type="button" class="gs-btn gs-btn-outline gs-btn-sm" onclick="selectColumn('engangslicens')">
                                Alla Engångs
                            </button>
                            <button type="button" class="gs-btn gs-btn-outline gs-btn-sm" onclick="selectColumn('motionslicens')">
                                Alla Motion
                            </button>
                            <button type="button" class="gs-btn gs-btn-outline gs-btn-sm" onclick="selectColumn('tavlingslicens')">
                                Alla Tävling
                            </button>
                        </div>
                        <button type="submit" class="gs-btn gs-btn-primary">
                            <i data-lucide="save"></i>
                            Spara <?= h($eventLicenseClasses[$currentTab]['name']) ?>-matris
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Quick Stats -->
        <div class="gs-card gs-mt-lg">
            <div class="gs-card-header">
                <h3 class="gs-h5">
                    <i data-lucide="bar-chart-2"></i>
                    Översikt för <?= h($eventLicenseClasses[$currentTab]['name']) ?>
                </h3>
            </div>
            <div class="gs-card-content">
                <div class="gs-grid gs-grid-cols-3 gs-gap-md">
                    <?php foreach ($licenseTypes as $license):
                        $count = 0;
                        foreach ($classes as $class) {
                            if (isset($currentMappings[$class['id']][$license['code']])) $count++;
                        }
                    ?>
                        <div class="gs-text-center gs-p-md" style="border: 1px solid var(--gs-border); border-radius: var(--gs-radius);">
                            <div class="gs-h2 gs-mb-xs"><?= $count ?></div>
                            <div class="gs-text-sm gs-text-secondary"><?= h($license['name']) ?></div>
                            <div class="gs-text-xs gs-text-secondary">av <?= count($classes) ?> klasser</div>
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

function selectColumn(licenseCode) {
    document.querySelectorAll(`#matrixForm input[type="checkbox"][name*="[${licenseCode}]"]`).forEach(cb => cb.checked = true);
}
</script>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>

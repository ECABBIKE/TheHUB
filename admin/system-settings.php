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
        </div>

        <!-- Classes Tab -->
        <?php if ($activeTab === 'classes'): ?>
            <div class="gs-alert gs-alert-info gs-mb-lg">
                <i data-lucide="info"></i>
                <strong>Tips:</strong> Alla klasser kan redigeras. Klicka på en klass för att ändra inställningar.
            </div>

            <div class="gs-card">
                <div class="gs-card-header">
                    <h3 class="gs-h4">Alla klasser (<?= count($classes) ?>)</h3>
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
                                <th>Resultat</th>
                                <th>Status</th>
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
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>
</main>

<script src="https://unpkg.com/lucide@latest"></script>
<script>
    lucide.createIcons();
</script>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>

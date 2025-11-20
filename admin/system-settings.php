<?php
/**
 * Consolidated System Settings
 * Combines: System Info, Debug, Classes, Point Templates, Global Texts
 */

require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();
$current_admin = get_current_admin();

// Get active tab
$activeTab = $_GET['tab'] ?? 'info';
$validTabs = ['info', 'debug', 'classes', 'point-templates', 'global-texts'];
if (!in_array($activeTab, $validTabs)) {
    $activeTab = 'info';
}

// Initialize message variables
$message = '';
$messageType = 'info';

// Handle form submissions based on tab
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();

    $action = $_POST['action'] ?? '';
    $formTab = $_POST['form_tab'] ?? '';

    // Classes form handling
    if ($formTab === 'classes') {
        if ($action === 'create' || $action === 'update') {
            $name = trim($_POST['name'] ?? '');
            $displayName = trim($_POST['display_name'] ?? '');

            if (empty($name)) {
                $message = 'Klassnamn är obligatoriskt';
                $messageType = 'error';
            } elseif (empty($displayName)) {
                $message = 'Visningsnamn är obligatoriskt';
                $messageType = 'error';
            } else {
                $disciplines = $_POST['disciplines'] ?? [];
                $disciplineString = is_array($disciplines) ? implode(',', $disciplines) : '';

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
                    } else {
                        $id = intval($_POST['id']);
                        $db->update('classes', $classData, 'id = ?', [$id]);
                        $message = 'Klass uppdaterad!';
                    }
                    $messageType = 'success';
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
        $activeTab = 'classes';
    }

    // Point Templates form handling
    elseif ($formTab === 'point-templates') {
        if ($action === 'create' || $action === 'update') {
            $name = trim($_POST['name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $pointsData = $_POST['points'] ?? [];

            if (empty($name)) {
                $message = 'Namn är obligatoriskt';
                $messageType = 'error';
            } else {
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
                    } else {
                        $id = intval($_POST['id']);
                        $db->update('qualification_point_templates', $templateData, 'id = ?', [$id]);
                        $message = 'Poängmall uppdaterad!';
                    }
                    $messageType = 'success';
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
                    $imported = json_decode($importData, true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        $lines = explode("\n", $importData);
                        $imported = ['points' => []];
                        foreach ($lines as $line) {
                            $line = trim($line);
                            if (empty($line) || strpos($line, '#') === 0) continue;
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
        $activeTab = 'point-templates';
    }

    // Global Texts form handling
    elseif ($formTab === 'global-texts') {
        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
            $message = 'Ogiltig CSRF-token';
            $messageType = 'error';
        } else {
            if ($action === 'update') {
                $id = (int)$_POST['id'];
                $content = trim($_POST['content'] ?? '');
                $db->update('global_texts', ['content' => $content], 'id = ?', [$id]);
                $message = 'Text uppdaterad!';
                $messageType = 'success';
            } elseif ($action === 'add') {
                $fieldKey = trim($_POST['field_key'] ?? '');
                $fieldName = trim($_POST['field_name'] ?? '');
                $fieldCategory = trim($_POST['field_category'] ?? 'general');
                $content = trim($_POST['content'] ?? '');
                if ($fieldKey && $fieldName) {
                    $db->insert('global_texts', [
                        'field_key' => $fieldKey,
                        'field_name' => $fieldName,
                        'field_category' => $fieldCategory,
                        'content' => $content
                    ]);
                    $message = 'Ny global text skapad!';
                    $messageType = 'success';
                } else {
                    $message = 'Fältnyckel och fältnamn krävs';
                    $messageType = 'error';
                }
            } elseif ($action === 'delete') {
                $id = (int)$_POST['id'];
                $db->query('DELETE FROM global_texts WHERE id = ?', [$id]);
                $message = 'Global text borttagen!';
                $messageType = 'success';
            }
        }
        $activeTab = 'global-texts';
    }
}

// Fetch data for tabs
// System Info
$systemInfo = [
    'php_version' => phpversion(),
    'mysql_version' => $db->getRow("SELECT VERSION() as version")['version'] ?? 'N/A',
    'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'N/A',
    'document_root' => $_SERVER['DOCUMENT_ROOT'] ?? 'N/A',
];

// Classes data
$classSearch = $_GET['class_search'] ?? '';
$classDisciplineFilter = $_GET['class_discipline'] ?? '';

$classWhere = [];
$classParams = [];

if ($classSearch) {
    $classWhere[] = "(name LIKE ? OR display_name LIKE ?)";
    $classParams[] = "%$classSearch%";
    $classParams[] = "%$classSearch%";
}

if ($classDisciplineFilter) {
    $classWhere[] = "(discipline = ? OR discipline LIKE ? OR discipline LIKE ? OR discipline LIKE ?)";
    $classParams[] = $classDisciplineFilter;
    $classParams[] = $classDisciplineFilter . ',%';
    $classParams[] = '%,' . $classDisciplineFilter . ',%';
    $classParams[] = '%,' . $classDisciplineFilter;
}

$classWhereClause = $classWhere ? 'WHERE ' . implode(' AND ', $classWhere) : '';

$classes = $db->getAll("
    SELECT c.*, COUNT(DISTINCT r.id) as result_count
    FROM classes c
    LEFT JOIN results r ON c.id = r.class_id
    $classWhereClause
    GROUP BY c.id
    ORDER BY c.sort_order ASC, c.name ASC
", $classParams);

$classDisciplines = $db->getAll("SELECT DISTINCT discipline FROM classes WHERE discipline IS NOT NULL AND discipline != '' ORDER BY discipline");

// Point Templates data
$pointTemplates = $db->getAll("SELECT * FROM qualification_point_templates ORDER BY name");

// Global Texts data
$gtCategoryFilter = $_GET['gt_category'] ?? '';
$gtWhere = [];
$gtParams = [];
if ($gtCategoryFilter) {
    $gtWhere[] = 'field_category = ?';
    $gtParams[] = $gtCategoryFilter;
}
$gtWhereClause = !empty($gtWhere) ? 'WHERE ' . implode(' AND ', $gtWhere) : '';

$globalTexts = $db->getAll("
    SELECT * FROM global_texts
    {$gtWhereClause}
    ORDER BY field_category, sort_order, field_name
", $gtParams);

$gtCategories = $db->getAll("SELECT DISTINCT field_category FROM global_texts ORDER BY field_category");

$categoryLabels = [
    'rules' => 'Regler & Säkerhet',
    'practical' => 'Praktisk Information',
    'facilities' => 'Faciliteter',
    'logistics' => 'Logistik',
    'contacts' => 'Kontakter',
    'media' => 'Media',
    'general' => 'Allmänt'
];

// Debug tools
$debugTools = [
    'database' => [
        'title' => 'Databas & Migrationer',
        'icon' => 'database',
        'items' => [
            ['name' => 'Kör Migrationer', 'url' => '/admin/migrate.php', 'desc' => 'Kör databas migrationer'],
            ['name' => 'Test DB', 'url' => '/admin/test-database-connection.php', 'desc' => 'Testa databasanslutning'],
            ['name' => 'Debug Database', 'url' => '/admin/debug-database.php', 'desc' => 'Visa databasinformation'],
        ]
    ],
    'system' => [
        'title' => 'System & Session',
        'icon' => 'settings',
        'items' => [
            ['name' => 'Debug Session', 'url' => '/admin/debug-session.php', 'desc' => 'Testa sessionshantering'],
            ['name' => 'Check Files', 'url' => '/admin/check-files.php', 'desc' => 'Kontrollera filer'],
            ['name' => 'Debug General', 'url' => '/admin/debug.php', 'desc' => 'Allmän debug'],
        ]
    ],
    'import' => [
        'title' => 'Import & CSV',
        'icon' => 'upload',
        'items' => [
            ['name' => 'Test Import', 'url' => '/admin/test-import.php', 'desc' => 'Testa import'],
            ['name' => 'Debug CSV', 'url' => '/admin/debug-csv-mapping.php', 'desc' => 'Debug CSV mapping'],
        ]
    ]
];

$pageTitle = 'Systeminställningar';
$pageType = 'admin';
include __DIR__ . '/../includes/layout-header.php';
?>

<style>
/* Tab Navigation */
.settings-tabs {
    display: flex;
    gap: 0.25rem;
    margin-bottom: 1.5rem;
    border-bottom: 2px solid var(--gs-border);
    padding-bottom: 0;
    overflow-x: auto;
}
.settings-tab {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.75rem 1rem;
    text-decoration: none;
    color: var(--gs-text-secondary);
    font-weight: 500;
    border-bottom: 2px solid transparent;
    margin-bottom: -2px;
    white-space: nowrap;
}
.settings-tab:hover {
    color: var(--gs-text-primary);
    background: var(--gs-bg-secondary);
}
.settings-tab.active {
    color: var(--gs-primary);
    border-bottom-color: var(--gs-primary);
}
.settings-tab i {
    width: 16px;
    height: 16px;
}

/* Point row styling */
.point-row-wrapper {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-bottom: 0.25rem;
}
.point-row-label {
    width: 80px;
    flex-shrink: 0;
}
.point-row-input {
    flex: 1;
}

/* Global text styling */
.global-text-card {
    margin-bottom: 1rem;
}
.global-text-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 0.5rem;
    flex-wrap: wrap;
}
.global-text-textarea {
    width: 100%;
    min-height: 120px;
    font-family: inherit;
    resize: vertical;
}

/* Debug tool styling */
.debug-tool-item {
    padding: 0.75rem;
    background: var(--gs-bg-secondary);
    border-radius: 0.5rem;
    margin-bottom: 0.5rem;
}
.debug-tool-item:hover {
    background: var(--gs-bg-tertiary);
}

/* System info card */
.info-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 1rem;
}
@media (min-width: 768px) {
    .info-grid {
        grid-template-columns: repeat(4, 1fr);
    }
}
.info-item {
    padding: 1rem;
    background: var(--gs-bg-secondary);
    border-radius: 0.5rem;
}
.info-label {
    font-size: 0.75rem;
    color: var(--gs-text-secondary);
    text-transform: uppercase;
    margin-bottom: 0.25rem;
}
.info-value {
    font-weight: 600;
    color: var(--gs-text-primary);
}
</style>

<main class="gs-main-content">
    <div class="gs-container">
        <!-- Header -->
        <div class="gs-flex gs-items-center gs-justify-between gs-mb-lg">
            <h1 class="gs-h2 gs-text-primary">
                <i data-lucide="settings"></i>
                Systeminställningar
            </h1>
        </div>

        <!-- Messages -->
        <?php if ($message): ?>
            <div class="gs-alert gs-alert-<?= $messageType ?> gs-mb-lg">
                <i data-lucide="<?= $messageType === 'success' ? 'check-circle' : ($messageType === 'error' ? 'alert-circle' : 'info') ?>"></i>
                <?= h($message) ?>
            </div>
        <?php endif; ?>

        <!-- Tab Navigation -->
        <div class="settings-tabs">
            <a href="?tab=info" class="settings-tab <?= $activeTab === 'info' ? 'active' : '' ?>">
                <i data-lucide="info"></i>
                Systeminformation
            </a>
            <a href="?tab=debug" class="settings-tab <?= $activeTab === 'debug' ? 'active' : '' ?>">
                <i data-lucide="bug"></i>
                Debug
            </a>
            <a href="?tab=classes" class="settings-tab <?= $activeTab === 'classes' ? 'active' : '' ?>">
                <i data-lucide="layers"></i>
                Klasser
            </a>
            <a href="?tab=point-templates" class="settings-tab <?= $activeTab === 'point-templates' ? 'active' : '' ?>">
                <i data-lucide="target"></i>
                Poängmallar
            </a>
            <a href="?tab=global-texts" class="settings-tab <?= $activeTab === 'global-texts' ? 'active' : '' ?>">
                <i data-lucide="file-text"></i>
                Globala Texter
            </a>
        </div>

        <!-- Tab Content -->
        <?php if ($activeTab === 'info'): ?>
            <!-- SYSTEM INFO TAB -->
            <div class="gs-card">
                <div class="gs-card-header">
                    <h2 class="gs-h4 gs-text-primary">
                        <i data-lucide="server"></i>
                        Systeminformation
                    </h2>
                </div>
                <div class="gs-card-content">
                    <div class="info-grid">
                        <div class="info-item">
                            <div class="info-label">PHP Version</div>
                            <div class="info-value"><?= h($systemInfo['php_version']) ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">MySQL Version</div>
                            <div class="info-value"><?= h($systemInfo['mysql_version']) ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Server</div>
                            <div class="info-value"><?= h($systemInfo['server_software']) ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Document Root</div>
                            <div class="info-value" style="font-size: 0.75rem; word-break: break-all;"><?= h($systemInfo['document_root']) ?></div>
                        </div>
                    </div>

                    <h3 class="gs-h5 gs-mt-lg gs-mb-md">Databas Statistik</h3>
                    <div class="info-grid">
                        <?php
                        $stats = [
                            ['Deltagare', $db->getRow("SELECT COUNT(*) as c FROM riders")['c']],
                            ['Klubbar', $db->getRow("SELECT COUNT(*) as c FROM clubs")['c']],
                            ['Events', $db->getRow("SELECT COUNT(*) as c FROM events")['c']],
                            ['Resultat', $db->getRow("SELECT COUNT(*) as c FROM results")['c']],
                            ['Serier', $db->getRow("SELECT COUNT(*) as c FROM series")['c']],
                            ['Klasser', $db->getRow("SELECT COUNT(*) as c FROM classes")['c']],
                        ];
                        foreach ($stats as $stat):
                        ?>
                        <div class="info-item">
                            <div class="info-label"><?= $stat[0] ?></div>
                            <div class="info-value"><?= number_format($stat[1]) ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

        <?php elseif ($activeTab === 'debug'): ?>
            <!-- DEBUG TAB -->
            <div class="gs-alert gs-alert-info gs-mb-lg">
                <i data-lucide="info"></i>
                Debug- och testverktyg för systemadministration
            </div>

            <?php foreach ($debugTools as $category): ?>
            <div class="gs-card gs-mb-lg">
                <div class="gs-card-header">
                    <h2 class="gs-h4 gs-text-primary">
                        <i data-lucide="<?= $category['icon'] ?>"></i>
                        <?= h($category['title']) ?>
                    </h2>
                </div>
                <div class="gs-card-content">
                    <?php foreach ($category['items'] as $tool): ?>
                    <div class="debug-tool-item gs-flex gs-items-center gs-justify-between">
                        <div>
                            <strong><?= h($tool['name']) ?></strong>
                            <div class="gs-text-sm gs-text-secondary"><?= h($tool['desc']) ?></div>
                        </div>
                        <a href="<?= h($tool['url']) ?>" class="gs-btn gs-btn-sm gs-btn-outline" target="_blank">
                            <i data-lucide="external-link"></i>
                            Öppna
                        </a>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>

        <?php elseif ($activeTab === 'classes'): ?>
            <!-- CLASSES TAB -->
            <div class="gs-flex gs-items-center gs-justify-between gs-mb-lg">
                <div></div>
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

            <!-- Search and Filter -->
            <div class="gs-card gs-mb-lg">
                <div class="gs-card-content">
                    <form method="GET" class="gs-grid gs-grid-cols-1 gs-md-grid-cols-3 gs-gap-md">
                        <input type="hidden" name="tab" value="classes">
                        <div class="gs-form-group">
                            <label class="gs-label">Sök</label>
                            <input type="text" name="class_search" class="gs-input" placeholder="Klassnamn..." value="<?= h($classSearch) ?>">
                        </div>
                        <div class="gs-form-group">
                            <label class="gs-label">Disciplin</label>
                            <select name="class_discipline" class="gs-input">
                                <option value="">Alla discipliner</option>
                                <?php foreach ($classDisciplines as $disc): ?>
                                    <option value="<?= h($disc['discipline']) ?>" <?= $classDisciplineFilter === $disc['discipline'] ? 'selected' : '' ?>>
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
                            <?php if ($classSearch || $classDisciplineFilter): ?>
                                <a href="?tab=classes" class="gs-btn gs-btn-outline">
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
                <div class="gs-card-content gs-padding-0">
                    <?php if (empty($classes)): ?>
                        <div class="gs-padding-lg gs-text-center">
                            <i data-lucide="inbox" style="width: 48px; height: 48px; opacity: 0.3;"></i>
                            <p class="gs-text-secondary gs-mt-sm">Inga klasser hittades</p>
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
                                        <th>Ålder</th>
                                        <th>Sort</th>
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
                                                    <?php foreach (explode(',', $class['discipline']) as $disc): ?>
                                                        <span class="gs-badge gs-badge-secondary gs-badge-sm"><?= h(trim($disc)) ?></span>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <span class="gs-badge gs-badge-info gs-badge-sm">Alla</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?= $class['gender'] === 'M' ? 'Herr' : ($class['gender'] === 'K' || $class['gender'] === 'F' ? 'Dam' : '–') ?>
                                            </td>
                                            <td>
                                                <?php if ($class['min_age'] || $class['max_age']): ?>
                                                    <?= $class['min_age'] ?? '∞' ?> - <?= $class['max_age'] ?? '∞' ?>
                                                <?php else: ?>
                                                    –
                                                <?php endif; ?>
                                            </td>
                                            <td><?= $class['sort_order'] ?></td>
                                            <td><?= number_format($class['result_count']) ?></td>
                                            <td>
                                                <span class="gs-badge <?= $class['active'] ? 'gs-badge-success' : 'gs-badge-secondary' ?> gs-badge-sm">
                                                    <?= $class['active'] ? 'Aktiv' : 'Inaktiv' ?>
                                                </span>
                                            </td>
                                            <td class="gs-text-right">
                                                <button type="button" class="gs-btn gs-btn-sm gs-btn-outline" onclick='editClass(<?= json_encode($class) ?>)'>
                                                    <i data-lucide="edit-2"></i>
                                                </button>
                                                <?php if ($class['result_count'] == 0): ?>
                                                    <form method="POST" class="gs-display-inline" onsubmit="return confirm('Ta bort denna klass?');">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="form_tab" value="classes">
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="id" value="<?= $class['id'] ?>">
                                                        <button type="submit" class="gs-btn gs-btn-sm gs-btn-danger">
                                                            <i data-lucide="trash-2"></i>
                                                        </button>
                                                    </form>
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

        <?php elseif ($activeTab === 'point-templates'): ?>
            <!-- POINT TEMPLATES TAB -->
            <div class="gs-flex gs-items-center gs-justify-between gs-mb-lg">
                <div></div>
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

            <div class="gs-card">
                <div class="gs-card-content">
                    <?php if (empty($pointTemplates)): ?>
                        <div class="gs-alert gs-alert-warning">
                            <p>Inga poängmallar hittades.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($pointTemplates as $template): ?>
                            <?php $points = json_decode($template['points'], true); ?>
                            <div class="gs-card gs-mb-md" style="background: var(--gs-bg-secondary);">
                                <div class="gs-card-content">
                                    <div class="gs-flex gs-items-start gs-justify-between gs-mb-sm">
                                        <div>
                                            <h3 class="gs-h5 gs-text-primary gs-mb-xs"><?= h($template['name']) ?></h3>
                                            <?php if (!empty($template['description'])): ?>
                                                <p class="gs-text-sm gs-text-secondary"><?= h($template['description']) ?></p>
                                            <?php endif; ?>
                                        </div>
                                        <div class="gs-flex gs-gap-sm">
                                            <button type="button" class="gs-btn gs-btn-sm gs-btn-outline" onclick="editTemplate(<?= $template['id'] ?>)">
                                                <i data-lucide="edit"></i>
                                            </button>
                                            <button type="button" class="gs-btn gs-btn-sm gs-btn-outline gs-btn-danger" onclick="deleteTemplate(<?= $template['id'] ?>, '<?= addslashes(h($template['name'])) ?>')">
                                                <i data-lucide="trash-2"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="gs-text-xs gs-text-secondary">
                                        <strong>Poäng:</strong>
                                        <?php if (empty($points)): ?>
                                            <span>Ingen poängfördelning</span>
                                        <?php else: ?>
                                            <div class="gs-flex gs-gap-xs gs-flex-wrap gs-mt-xs">
                                                <?php foreach ($points as $position => $pointValue): ?>
                                                    <span class="gs-badge gs-badge-sm">#<?= $position ?>: <?= $pointValue ?>p</span>
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

        <?php elseif ($activeTab === 'global-texts'): ?>
            <!-- GLOBAL TEXTS TAB -->
            <div class="gs-flex gs-items-center gs-justify-between gs-mb-lg">
                <div></div>
                <button type="button" class="gs-btn gs-btn-primary" onclick="showAddTextModal()">
                    <i data-lucide="plus"></i>
                    Ny Global Text
                </button>
            </div>

            <!-- Category Filter -->
            <div class="gs-flex gs-gap-sm gs-flex-wrap gs-mb-lg">
                <a href="?tab=global-texts" class="gs-btn gs-btn-sm <?= !$gtCategoryFilter ? 'gs-btn-primary' : 'gs-btn-outline' ?>">
                    Alla
                </a>
                <?php foreach ($gtCategories as $cat): ?>
                    <a href="?tab=global-texts&gt_category=<?= h($cat['field_category']) ?>"
                       class="gs-btn gs-btn-sm <?= $gtCategoryFilter === $cat['field_category'] ? 'gs-btn-primary' : 'gs-btn-outline' ?>">
                        <?= h($categoryLabels[$cat['field_category']] ?? ucfirst($cat['field_category'])) ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <!-- Global Texts List -->
            <?php if (empty($globalTexts)): ?>
                <div class="gs-card">
                    <div class="gs-card-content gs-text-center">
                        <i data-lucide="file-text" style="width: 48px; height: 48px; opacity: 0.3;"></i>
                        <p class="gs-text-secondary gs-mt-sm">Inga globala texter hittades</p>
                    </div>
                </div>
            <?php else: ?>
                <?php
                $currentCategory = '';
                foreach ($globalTexts as $text):
                    if ($text['field_category'] !== $currentCategory):
                        $currentCategory = $text['field_category'];
                ?>
                    <h2 class="gs-h4 gs-text-primary gs-mb-md gs-mt-lg">
                        <i data-lucide="folder"></i>
                        <?= h($categoryLabels[$currentCategory] ?? ucfirst($currentCategory)) ?>
                    </h2>
                <?php endif; ?>

                <div class="gs-card global-text-card">
                    <div class="gs-card-content">
                        <form method="POST">
                            <?= csrf_field() ?>
                            <input type="hidden" name="form_tab" value="global-texts">
                            <input type="hidden" name="action" value="update">
                            <input type="hidden" name="id" value="<?= $text['id'] ?>">

                            <div class="global-text-header">
                                <div>
                                    <h3 class="gs-h5 gs-mb-xs"><?= h($text['field_name']) ?></h3>
                                    <span class="gs-badge gs-badge-secondary gs-badge-sm"><?= h($text['field_key']) ?></span>
                                </div>
                                <div class="gs-flex gs-gap-sm">
                                    <button type="submit" class="gs-btn gs-btn-primary gs-btn-sm">
                                        <i data-lucide="save"></i>
                                        Spara
                                    </button>
                                    <button type="button" class="gs-btn gs-btn-danger gs-btn-sm" onclick="deleteGlobalText(<?= $text['id'] ?>, '<?= h($text['field_name']) ?>')">
                                        <i data-lucide="trash-2"></i>
                                    </button>
                                </div>
                            </div>

                            <div class="gs-mt-md">
                                <textarea name="content" class="gs-input global-text-textarea" placeholder="Ange standardtext..."><?= h($text['content']) ?></textarea>
                            </div>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</main>

<!-- Class Modal -->
<div id="classModal" class="gs-modal" style="display: none;">
    <div class="gs-modal-overlay" onclick="closeClassModal()"></div>
    <div class="gs-modal-content" style="max-width: 600px;">
        <div class="gs-modal-header">
            <h2 class="gs-modal-title">
                <i data-lucide="layers"></i>
                <span id="classModalTitle">Ny Klass</span>
            </h2>
            <button type="button" class="gs-modal-close" onclick="closeClassModal()">
                <i data-lucide="x"></i>
            </button>
        </div>
        <form method="POST" id="classForm">
            <?= csrf_field() ?>
            <input type="hidden" name="form_tab" value="classes">
            <input type="hidden" name="action" id="classFormAction" value="create">
            <input type="hidden" name="id" id="classId" value="">

            <div class="gs-modal-body">
                <div class="gs-grid gs-grid-cols-1 gs-gap-md">
                    <div class="gs-form-group">
                        <label class="gs-label">Visningsnamn *</label>
                        <input type="text" name="display_name" id="classDisplayName" class="gs-input" required>
                    </div>
                    <div class="gs-form-group">
                        <label class="gs-label">Namn *</label>
                        <input type="text" name="name" id="className" class="gs-input" required>
                    </div>
                    <div class="gs-form-group">
                        <label class="gs-label">Discipliner</label>
                        <div class="gs-grid gs-grid-cols-2 gs-gap-sm">
                            <label class="gs-checkbox-label"><input type="checkbox" name="disciplines[]" value="XC" class="discipline-cb"> XC</label>
                            <label class="gs-checkbox-label"><input type="checkbox" name="disciplines[]" value="DH" class="discipline-cb"> DH</label>
                            <label class="gs-checkbox-label"><input type="checkbox" name="disciplines[]" value="ENDURO" class="discipline-cb"> Enduro</label>
                            <label class="gs-checkbox-label"><input type="checkbox" name="disciplines[]" value="ROAD" class="discipline-cb"> Road</label>
                        </div>
                    </div>
                    <div class="gs-grid gs-grid-cols-3 gs-gap-md">
                        <div class="gs-form-group">
                            <label class="gs-label">Kön</label>
                            <select name="gender" id="classGender" class="gs-input">
                                <option value="">Alla</option>
                                <option value="M">Herr</option>
                                <option value="K">Dam</option>
                            </select>
                        </div>
                        <div class="gs-form-group">
                            <label class="gs-label">Min ålder</label>
                            <input type="number" name="min_age" id="classMinAge" class="gs-input">
                        </div>
                        <div class="gs-form-group">
                            <label class="gs-label">Max ålder</label>
                            <input type="number" name="max_age" id="classMaxAge" class="gs-input">
                        </div>
                    </div>
                    <div class="gs-grid gs-grid-cols-2 gs-gap-md">
                        <div class="gs-form-group">
                            <label class="gs-label">Sortering</label>
                            <input type="number" name="sort_order" id="classSortOrder" class="gs-input" value="999">
                        </div>
                        <div class="gs-form-group gs-flex gs-items-end">
                            <label class="gs-checkbox-label">
                                <input type="checkbox" name="active" id="classActive" checked>
                                Aktiv
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            <div class="gs-modal-footer">
                <button type="button" class="gs-btn gs-btn-outline" onclick="closeClassModal()">Avbryt</button>
                <button type="submit" class="gs-btn gs-btn-primary">
                    <i data-lucide="save"></i>
                    <span id="classSubmitText">Skapa</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Point Template Modal -->
<div id="templateModal" class="gs-modal" style="display: none;">
    <div class="gs-modal-overlay" onclick="closeTemplateModal()"></div>
    <div class="gs-modal-content" style="max-width: 500px;">
        <div class="gs-modal-header">
            <h2 class="gs-modal-title">
                <i data-lucide="target"></i>
                <span id="templateModalTitle">Ny Poängmall</span>
            </h2>
            <button type="button" class="gs-modal-close" onclick="closeTemplateModal()">
                <i data-lucide="x"></i>
            </button>
        </div>
        <form method="POST" id="templateForm">
            <?= csrf_field() ?>
            <input type="hidden" name="form_tab" value="point-templates">
            <input type="hidden" name="action" id="templateFormAction" value="create">
            <input type="hidden" name="id" id="templateId" value="">

            <div class="gs-modal-body">
                <div class="gs-form-group">
                    <label class="gs-label">Namn *</label>
                    <input type="text" id="templateName" name="name" class="gs-input" required>
                </div>
                <div class="gs-form-group">
                    <label class="gs-label">Beskrivning</label>
                    <textarea id="templateDescription" name="description" class="gs-input" rows="2"></textarea>
                </div>
                <div class="gs-form-group">
                    <div class="gs-flex gs-items-center gs-justify-between gs-mb-sm">
                        <label class="gs-label gs-mb-0">Poäng per placering</label>
                        <button type="button" class="gs-btn gs-btn-xs gs-btn-outline" onclick="addPointRow()">
                            <i data-lucide="plus"></i>
                        </button>
                    </div>
                    <div id="pointsContainer" style="max-height: 300px; overflow-y: auto;"></div>
                </div>
            </div>

            <div class="gs-modal-footer">
                <button type="button" class="gs-btn gs-btn-outline" onclick="closeTemplateModal()">Avbryt</button>
                <button type="submit" class="gs-btn gs-btn-primary">
                    <i data-lucide="save"></i>
                    <span id="templateSubmitText">Skapa</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Import Modal -->
<div id="importModal" class="gs-modal" style="display: none;">
    <div class="gs-modal-overlay" onclick="closeImportModal()"></div>
    <div class="gs-modal-content" style="max-width: 500px;">
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
            <input type="hidden" name="form_tab" value="point-templates">
            <input type="hidden" name="action" value="import">

            <div class="gs-modal-body">
                <div class="gs-form-group">
                    <label class="gs-label">JSON eller CSV data</label>
                    <textarea name="import_data" class="gs-input" rows="10" placeholder="1,100&#10;2,80&#10;3,60"></textarea>
                </div>
            </div>

            <div class="gs-modal-footer">
                <button type="button" class="gs-btn gs-btn-outline" onclick="closeImportModal()">Avbryt</button>
                <button type="submit" class="gs-btn gs-btn-primary">
                    <i data-lucide="upload"></i>
                    Importera
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Add Global Text Modal -->
<div id="addTextModal" class="gs-modal" style="display: none;">
    <div class="gs-modal-overlay" onclick="closeAddTextModal()"></div>
    <div class="gs-modal-content" style="max-width: 500px;">
        <div class="gs-modal-header">
            <h2 class="gs-modal-title">
                <i data-lucide="plus"></i>
                Ny Global Text
            </h2>
            <button type="button" class="gs-modal-close" onclick="closeAddTextModal()">
                <i data-lucide="x"></i>
            </button>
        </div>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="form_tab" value="global-texts">
            <input type="hidden" name="action" value="add">

            <div class="gs-modal-body">
                <div class="gs-form-group">
                    <label class="gs-label">Fältnyckel *</label>
                    <input type="text" name="field_key" class="gs-input" required placeholder="my_custom_field">
                </div>
                <div class="gs-form-group">
                    <label class="gs-label">Fältnamn *</label>
                    <input type="text" name="field_name" class="gs-input" required placeholder="Min Anpassade Text">
                </div>
                <div class="gs-form-group">
                    <label class="gs-label">Kategori</label>
                    <select name="field_category" class="gs-input">
                        <option value="general">Allmänt</option>
                        <option value="rules">Regler & Säkerhet</option>
                        <option value="practical">Praktisk Information</option>
                        <option value="facilities">Faciliteter</option>
                        <option value="logistics">Logistik</option>
                        <option value="contacts">Kontakter</option>
                        <option value="media">Media</option>
                    </select>
                </div>
                <div class="gs-form-group">
                    <label class="gs-label">Innehåll</label>
                    <textarea name="content" class="gs-input" rows="4"></textarea>
                </div>
            </div>

            <div class="gs-modal-footer">
                <button type="button" class="gs-btn gs-btn-outline" onclick="closeAddTextModal()">Avbryt</button>
                <button type="submit" class="gs-btn gs-btn-primary">
                    <i data-lucide="plus"></i>
                    Skapa
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// Class Modal functions
function openClassModal() {
    document.getElementById('classModal').style.display = 'flex';
    document.getElementById('classModalTitle').textContent = 'Ny Klass';
    document.getElementById('classSubmitText').textContent = 'Skapa';
    document.getElementById('classFormAction').value = 'create';
    document.getElementById('classForm').reset();
    document.getElementById('classId').value = '';
    document.getElementById('classActive').checked = true;
    document.querySelectorAll('.discipline-cb').forEach(cb => cb.checked = false);
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

function closeClassModal() {
    document.getElementById('classModal').style.display = 'none';
}

function editClass(classData) {
    document.getElementById('classModal').style.display = 'flex';
    document.getElementById('classModalTitle').textContent = 'Redigera Klass';
    document.getElementById('classSubmitText').textContent = 'Uppdatera';
    document.getElementById('classFormAction').value = 'update';
    document.getElementById('classId').value = classData.id;
    document.getElementById('className').value = classData.name;
    document.getElementById('classDisplayName').value = classData.display_name;
    document.getElementById('classGender').value = classData.gender || '';
    document.getElementById('classMinAge').value = classData.min_age || '';
    document.getElementById('classMaxAge').value = classData.max_age || '';
    document.getElementById('classSortOrder').value = classData.sort_order || 999;
    document.getElementById('classActive').checked = classData.active == 1;

    document.querySelectorAll('.discipline-cb').forEach(cb => cb.checked = false);
    if (classData.discipline) {
        const disciplines = classData.discipline.split(',').map(d => d.trim());
        document.querySelectorAll('.discipline-cb').forEach(cb => {
            if (disciplines.includes(cb.value)) cb.checked = true;
        });
    }
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

// Template Modal functions
let pointRowCounter = 0;
const templates = <?= json_encode($pointTemplates) ?>;

function openTemplateModal() {
    document.getElementById('templateModal').style.display = 'flex';
    document.getElementById('templateForm').reset();
    document.getElementById('templateFormAction').value = 'create';
    document.getElementById('templateId').value = '';
    document.getElementById('templateModalTitle').textContent = 'Ny Poängmall';
    document.getElementById('templateSubmitText').textContent = 'Skapa';

    document.getElementById('pointsContainer').innerHTML = '';
    pointRowCounter = 0;
    for (let i = 1; i <= 10; i++) addPointRow(i, '');
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

function closeTemplateModal() {
    document.getElementById('templateModal').style.display = 'none';
}

function addPointRow(position = null, points = '') {
    const container = document.getElementById('pointsContainer');
    const nextPosition = position || (pointRowCounter + 1);
    pointRowCounter = Math.max(pointRowCounter, nextPosition);

    const row = document.createElement('div');
    row.className = 'point-row-wrapper';
    row.innerHTML = `
        <div class="point-row-label">
            <label class="gs-text-xs gs-text-secondary">#${nextPosition}</label>
        </div>
        <div class="point-row-input">
            <input type="number" name="points[${nextPosition}]" class="gs-input gs-input-sm" value="${points}" min="0" placeholder="Poäng">
        </div>
        <button type="button" class="gs-btn gs-btn-xs gs-btn-outline gs-btn-danger" onclick="this.parentElement.remove()">
            <i data-lucide="x"></i>
        </button>
    `;
    container.appendChild(row);
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

function editTemplate(id) {
    const template = templates.find(t => t.id == id);
    if (!template) return;

    document.getElementById('templateModal').style.display = 'flex';
    document.getElementById('templateFormAction').value = 'update';
    document.getElementById('templateId').value = template.id;
    document.getElementById('templateName').value = template.name;
    document.getElementById('templateDescription').value = template.description || '';
    document.getElementById('templateModalTitle').textContent = 'Redigera Poängmall';
    document.getElementById('templateSubmitText').textContent = 'Uppdatera';

    document.getElementById('pointsContainer').innerHTML = '';
    pointRowCounter = 0;

    const editPoints = JSON.parse(template.points || '{}');
    const positions = Object.keys(editPoints).map(Number).sort((a, b) => a - b);
    positions.forEach(pos => addPointRow(pos, editPoints[pos]));
    for (let i = 0; i < 3; i++) addPointRow();
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

function deleteTemplate(id, name) {
    if (!confirm(`Ta bort "${name}"?`)) return;
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = `<?= csrf_field() ?><input type="hidden" name="form_tab" value="point-templates"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="${id}">`;
    document.body.appendChild(form);
    form.submit();
}

// Import Modal
function openImportModal() {
    document.getElementById('importModal').style.display = 'flex';
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

function closeImportModal() {
    document.getElementById('importModal').style.display = 'none';
}

// Global Text Modal
function showAddTextModal() {
    document.getElementById('addTextModal').style.display = 'flex';
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

function closeAddTextModal() {
    document.getElementById('addTextModal').style.display = 'none';
}

function deleteGlobalText(id, name) {
    if (!confirm(`Ta bort "${name}"?`)) return;
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = `<?= csrf_field() ?><input type="hidden" name="form_tab" value="global-texts"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="${id}">`;
    document.body.appendChild(form);
    form.submit();
}

// Close modals on escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeClassModal();
        closeTemplateModal();
        closeImportModal();
        closeAddTextModal();
    }
});
</script>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>

<?php
/**
 * Consolidated System Settings
 * Combines: System Info, Debug, Classes, Point Templates, Global Texts
 */

require_once __DIR__ . '/../config.php';
require_admin();
require_once __DIR__ . '/../includes/admin-layout.php';

$db = getDB();
$current_admin = get_current_admin();

// Get active tab
$activeTab = $_GET['tab'] ?? 'info';
$validTabs = ['info', 'debug', 'global-texts'];
if (!in_array($activeTab, $validTabs)) {
    $activeTab = 'info';
}

// Redirect old tabs to their new locations
if (isset($_GET['tab']) && $_GET['tab'] === 'point-templates') {
    header('Location: /admin/point-scales.php');
    exit;
}
if (isset($_GET['tab']) && $_GET['tab'] === 'classes') {
    header('Location: /admin/classes.php');
    exit;
}

// Initialize message variables
$message = '';
$messageType = 'info';

// Handle form submissions based on tab
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();

    $action = $_POST['action'] ?? '';
    $formTab = $_POST['form_tab'] ?? '';

    // Global Texts form handling
    if ($formTab === 'global-texts') {
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

// Admin tools organized by workflow
$debugTools = [
    'import' => [
        'title' => '1. Import & License',
        'icon' => 'upload',
        'items' => [
            ['name' => 'Berika CSV med License', 'url' => '/admin/enrich-uci-id.php', 'desc' => 'Fyll i saknade license numbers innan import'],
            ['name' => 'Sök License Number', 'url' => '/admin/search-uci-id.php', 'desc' => 'Slå upp enskilda license numbers'],
        ]
    ],
    'duplicates' => [
        'title' => '2. Dublettrensning',
        'icon' => 'git-merge',
        'items' => [
            ['name' => 'Auto-slå ihop UCI/SWE', 'url' => '/admin/auto-merge-uci-swe.php', 'desc' => 'Automatisk sammanslagning av UCI-ID och SWE-ID'],
            ['name' => 'Auto-slå ihop klubbar', 'url' => '/admin/auto-merge-clubs.php', 'desc' => 'Automatisk sammanslagning av klubbdubbletter'],
            ['name' => 'Manuell dublettrensning', 'url' => '/admin/cleanup-duplicates.php', 'desc' => 'Hantera ryttardubbletter manuellt + normalisera namn'],
            ['name' => 'Manuell klubbrensning', 'url' => '/admin/cleanup-clubs.php', 'desc' => 'Hantera klubbdubbletter manuellt'],
        ]
    ],
    'points' => [
        'title' => '3. Poäng & Resultat',
        'icon' => 'award',
        'items' => [
            ['name' => 'Poängmallar', 'url' => '/admin/point-scales.php', 'desc' => 'Skapa och hantera poängmallar'],
            ['name' => 'Omräkna Resultat', 'url' => '/admin/recalculate-results.php', 'desc' => 'Tilldela poängmall och omräkna poäng'],
            ['name' => 'Rensa Eventresultat', 'url' => '/admin/clear-event-results.php', 'desc' => 'Ta bort resultat för specifikt event'],
            ['name' => 'Flytta Klassresultat', 'url' => '/admin/move-class-results.php', 'desc' => 'Flytta resultat mellan klasser'],
            ['name' => 'Omtilldela Klasser', 'url' => '/admin/reassign-classes.php', 'desc' => 'Korrigera klassplaceringar baserat på kön/ålder'],
        ]
    ],
    'database' => [
        'title' => '4. Databas',
        'icon' => 'database',
        'items' => [
            ['name' => 'Kör Migrationer', 'url' => '/admin/migrate.php', 'desc' => 'Kör databasmigrationer'],
        ]
    ],
    'system' => [
        'title' => '5. System',
        'icon' => 'settings',
        'items' => [
            ['name' => 'Kontrollera filer', 'url' => '/admin/check-files.php', 'desc' => 'Verifiera att systemfiler finns'],
        ]
    ]
];

$pageTitle = 'Systeminställningar';
$pageType = 'admin';
include __DIR__ . '/../includes/layout-header.php';
?>


<main class="gs-main-content">
    <div class="gs-container">
        <?php render_admin_header('Inställningar'); ?>

        <!-- Messages -->
        <?php if ($message): ?>
            <div class="gs-alert gs-alert-<?= $messageType ?> gs-mb-lg">
                <i data-lucide="<?= $messageType === 'success' ? 'check-circle' : ($messageType === 'error' ? 'alert-circle' : 'info') ?>"></i>
                <?= h($message) ?>
            </div>
        <?php endif; ?>

        <!-- Tab Navigation -->
        <div class="gs-tabs">
            <a href="?tab=info" class="gs-tab <?= $activeTab === 'info' ? 'active' : '' ?>">
                <i data-lucide="info"></i>
                Systeminformation
            </a>
            <a href="?tab=debug" class="gs-tab <?= $activeTab === 'debug' ? 'active' : '' ?>">
                <i data-lucide="bug"></i>
                Debug
            </a>
            <a href="?tab=global-texts" class="gs-tab <?= $activeTab === 'global-texts' ? 'active' : '' ?>">
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
                    <div class="gs-info-grid">
                        <div class="gs-info-item">
                            <div class="gs-info-label">PHP Version</div>
                            <div class="gs-info-value"><?= h($systemInfo['php_version']) ?></div>
                        </div>
                        <div class="gs-info-item">
                            <div class="gs-info-label">MySQL Version</div>
                            <div class="gs-info-value"><?= h($systemInfo['mysql_version']) ?></div>
                        </div>
                        <div class="gs-info-item">
                            <div class="gs-info-label">Server</div>
                            <div class="gs-info-value"><?= h($systemInfo['server_software']) ?></div>
                        </div>
                        <div class="gs-info-item">
                            <div class="gs-info-label">Document Root</div>
                            <div class="gs-info-value gs-info-value-sm"><?= h($systemInfo['document_root']) ?></div>
                        </div>
                    </div>

                    <h3 class="gs-h5 gs-mt-lg gs-mb-md">Databas Statistik</h3>
                    <div class="gs-info-grid">
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
                        <div class="gs-info-item">
                            <div class="gs-info-label"><?= $stat[0] ?></div>
                            <div class="gs-info-value"><?= number_format($stat[1]) ?></div>
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
                    <div class="gs-debug-tool-item gs-flex gs-items-center gs-justify-between">
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

                <div class="gs-card gs-global-text-card">
                    <div class="gs-card-content">
                        <form method="POST">
                            <?= csrf_field() ?>
                            <input type="hidden" name="form_tab" value="global-texts">
                            <input type="hidden" name="action" value="update">
                            <input type="hidden" name="id" value="<?= $text['id'] ?>">

                            <div class="gs-global-text-header">
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
                                <textarea name="content" class="gs-input gs-global-text-textarea" placeholder="Ange standardtext..."><?= h($text['content']) ?></textarea>
                            </div>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        <?php endif; ?>
        <?php render_admin_footer(); ?>
    </div>
</main>

<!-- Add Global Text Modal -->
<div id="addTextModal" class="gs-modal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; z-index: 10000; align-items: flex-start; justify-content: center; padding-top: 5vh; overflow-y: auto;">
    <div class="gs-modal-overlay" onclick="closeAddTextModal()" style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: -1;"></div>
    <div class="gs-modal-content" style="max-width: 500px; background: white; border-radius: 12px; box-shadow: 0 20px 60px rgba(0,0,0,0.3); position: relative;">
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
        closeAddTextModal();
    }
});
</script>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>

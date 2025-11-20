<?php
require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();

$pageTitle = 'Systeminställningar';
$pageType = 'admin';
include __DIR__ . '/../includes/layout-header.php';

// Define all debug and test tools organized by category
$tools = [
    'database' => [
        'title' => 'Databas & Migrationer',
        'icon' => 'database',
        'color' => 'primary',
        'items' => [
            [
                'name' => 'Fristående Migration',
                'url' => '/admin/migrate.php',
                'description' => 'Kör databas migration utan inloggningskrav. Lägger till utökade fält.',
                'status' => 'recommended'
            ],
            [
                'name' => 'Test DB Connection',
                'url' => '/admin/test-database-connection.php',
                'description' => 'Testar databas-anslutning och verifierar konfiguration.',
                'status' => 'test'
            ],
            [
                'name' => 'Debug Database',
                'url' => '/admin/debug-database.php',
                'description' => 'Visar detaljerad databasinformation och tabellstruktur.',
                'status' => 'debug'
            ],
            [
                'name' => 'Debug Migration',
                'url' => '/admin/debug-migration.php',
                'description' => 'Debug-verktyg för migration problem.',
                'status' => 'debug'
            ],
            [
                'name' => 'Test Migration',
                'url' => '/admin/test-migration.php',
                'description' => 'Testar migration steg för steg.',
                'status' => 'test'
            ]
        ]
    ],
    'series' => [
        'title' => 'Serier & Format',
        'icon' => 'trophy',
        'color' => 'success',
        'items' => [
            [
                'name' => 'Check Series Format',
                'url' => '/admin/check-series-format.php',
                'description' => 'Diagnostik för Team format sparning. Kontrollerar om format-kolumnen existerar.',
                'status' => 'recommended'
            ],
            [
                'name' => 'Debug Series',
                'url' => '/admin/debug-series.php',
                'description' => 'Visar serie-information och debug-data.',
                'status' => 'debug'
            ]
        ]
    ],
    'riders' => [
        'title' => 'Deltagare & Licenser',
        'icon' => 'users',
        'color' => 'accent',
        'items' => [
            [
                'name' => 'Test Riders',
                'url' => '/admin/test-riders.php',
                'description' => 'Testar deltagarfunktionalitet.',
                'status' => 'test'
            ],
            [
                'name' => 'Test Riders Simple',
                'url' => '/admin/test-riders-simple.php',
                'description' => 'Enklare test av deltagarfunktioner.',
                'status' => 'test'
            ],
            [
                'name' => 'Debug Licenses',
                'url' => '/admin/debug-licenses.php',
                'description' => 'Visar licensinformation och validering.',
                'status' => 'debug'
            ]
        ]
    ],
    'import' => [
        'title' => 'Import & CSV',
        'icon' => 'upload',
        'color' => 'warning',
        'items' => [
            [
                'name' => 'Test Import',
                'url' => '/admin/test-import.php',
                'description' => 'Testar import-funktionalitet.',
                'status' => 'test'
            ],
            [
                'name' => 'Debug CSV Mapping',
                'url' => '/admin/debug-csv-mapping.php',
                'description' => 'Debug CSV-kolumn mapping.',
                'status' => 'debug'
            ]
        ]
    ],
    'system' => [
        'title' => 'System & Session',
        'icon' => 'settings',
        'color' => 'secondary',
        'items' => [
            [
                'name' => 'Debug System Settings',
                'url' => '/admin/debug-system-settings.php',
                'description' => 'Visar systeminställningar och konfiguration.',
                'status' => 'debug'
            ],
            [
                'name' => 'Debug Session',
                'url' => '/admin/debug-session.php',
                'description' => 'Testar sessionshantering och inloggning.',
                'status' => 'debug'
            ],
            [
                'name' => 'Debug General',
                'url' => '/admin/debug.php',
                'description' => 'Allmän debug-information.',
                'status' => 'debug'
            ],
            [
                'name' => 'Check Files',
                'url' => '/admin/check-files.php',
                'description' => 'Kontrollerar att alla viktiga filer finns.',
                'status' => 'test'
            ],
            [
                'name' => 'Test GetDB',
                'url' => '/admin/test-getdb.php',
                'description' => 'Testar getDB() funktionen.',
                'status' => 'test'
            ]
        ]
    ],
    'classes' => [
        'title' => 'Klasser',
        'icon' => 'layers',
        'color' => 'info',
        'items' => [
            [
                'name' => 'Test Classes',
                'url' => '/admin/test-classes.php',
                'description' => 'Testar klass-funktionalitet.',
                'status' => 'test'
            ]
        ]
    ]
];

?>

<style>
    .tool-item {
        position: relative;
        padding: 1rem;
        background: white;
        border: 2px solid #e5e7eb;
        border-radius: 8px;
        transition: all 0.2s;
    }

    .tool-item:hover {
        border-color: var(--gs-primary);
        box-shadow: 0 4px 12px rgba(102, 126, 234, 0.15);
    }

    .tool-item.completed {
        background: #f0fdf4;
        border-color: #86efac;
    }

    .tool-item.completed .tool-name {
        text-decoration: line-through;
        opacity: 0.7;
    }

    .tool-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 0.5rem;
    }

    .tool-name {
        font-weight: 700;
        font-size: 1rem;
        color: #1a202c;
        margin-bottom: 0.25rem;
    }

    .tool-description {
        font-size: 0.875rem;
        color: #718096;
        margin-bottom: 0.75rem;
    }

    .tool-actions {
        display: flex;
        gap: 0.5rem;
        align-items: center;
    }

    .tool-status-badge {
        font-size: 0.75rem;
        padding: 2px 8px;
        border-radius: 12px;
        font-weight: 600;
        text-transform: uppercase;
    }

    .tool-status-recommended {
        background: #10b981;
        color: white;
    }

    .tool-status-test {
        background: #3b82f6;
        color: white;
    }

    .tool-status-debug {
        background: #f59e0b;
        color: white;
    }

    .checkbox-complete {
        width: 24px;
        height: 24px;
        cursor: pointer;
    }

    .category-card {
        margin-bottom: 2rem;
    }

    .category-header {
        padding: 1rem 1.5rem;
        background: linear-gradient(135deg, var(--gs-primary) 0%, var(--gs-primary-dark) 100%);
        color: white;
        border-radius: 8px 8px 0 0;
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }

    .category-header.color-success {
        background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    }

    .category-header.color-accent {
        background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
    }

    .category-header.color-warning {
        background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
    }

    .category-header.color-secondary {
        background: linear-gradient(135deg, #6b7280 0%, #4b5563 100%);
    }

    .category-header.color-info {
        background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
    }

    .category-body {
        padding: 1.5rem;
        background: white;
        border: 2px solid #e5e7eb;
        border-top: none;
        border-radius: 0 0 8px 8px;
    }

    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1rem;
        margin-bottom: 2rem;
    }

    .stat-card {
        padding: 1.5rem;
        background: white;
        border: 2px solid #e5e7eb;
        border-radius: 8px;
        text-align: center;
    }

    .stat-number {
        font-size: 2.5rem;
        font-weight: 800;
        color: var(--gs-primary);
        line-height: 1;
        margin-bottom: 0.5rem;
    }

    .stat-label {
        font-size: 0.875rem;
        color: #718096;
        font-weight: 600;
        text-transform: uppercase;
    }
</style>

<main class="gs-main-content">
    <div class="gs-container">
        <div class="gs-flex gs-justify-between gs-items-center gs-mb-lg">
            <h1 class="gs-h2">
                <i data-lucide="settings"></i>
                Systeminställningar
            </h1>
        </div>

        <div class="gs-alert gs-alert-info gs-mb-lg">
            <i data-lucide="info"></i>
            <strong>Debug & Test Verktyg:</strong> Klicka på en länk för att öppna verktyget. Markera som klar när du är färdig med att använda det.
        </div>

        <!-- Stats -->
        <div class="stats-grid">
            <?php
            $totalTools = 0;
            foreach ($tools as $category) {
                $totalTools += count($category['items']);
            }
            ?>
            <div class="stat-card">
                <div class="stat-number"><?= count($tools) ?></div>
                <div class="stat-label">Kategorier</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= $totalTools ?></div>
                <div class="stat-label">Totalt verktyg</div>
            </div>
            <div class="stat-card">
                <div class="stat-number" id="completedCount">0</div>
                <div class="stat-label">Klarmarkerade</div>
            </div>
            <div class="stat-card">
                <button type="button" class="gs-btn gs-btn-outline gs-btn-sm" onclick="clearAllCompleted()">
                    <i data-lucide="x"></i>
                    Rensa alla markeringar
                </button>
            </div>
        </div>

        <!-- Tool Categories -->
        <?php foreach ($tools as $categoryKey => $category): ?>
            <div class="category-card">
                <div class="category-header color-<?= $category['color'] ?>">
                    <i data-lucide="<?= $category['icon'] ?>" class="gs-icon-24"></i>
                    <h3 class="gs-heading-m0-fs125">
                        <?= h($category['title']) ?>
                        <span class="gs-text-subdued">
                            (<?= count($category['items']) ?> verktyg)
                        </span>
                    </h3>
                </div>
                <div class="category-body">
                    <div class="gs-grid gs-grid-cols-1 gs-gap-md">
                        <?php foreach ($category['items'] as $index => $tool): ?>
                            <?php $toolId = $categoryKey . '-' . $index; ?>
                            <div class="tool-item" id="tool-<?= $toolId ?>" data-tool-id="<?= $toolId ?>">
                                <div class="tool-header">
                                    <div class="gs-flex-1">
                                        <div class="tool-name"><?= h($tool['name']) ?></div>
                                        <div class="tool-description"><?= h($tool['description']) ?></div>
                                    </div>
                                    <div class="tool-actions">
                                        <input
                                            type="checkbox"
                                            class="checkbox-complete"
                                            id="check-<?= $toolId ?>"
                                            onchange="toggleComplete('<?= $toolId ?>')"
                                            title="Markera som klar"
                                        >
                                    </div>
                                </div>
                                <div class="gs-flex gs-gap-sm gs-items-center">
                                    <a href="<?= h($tool['url']) ?>" target="_blank" class="gs-btn gs-btn-sm gs-btn-primary">
                                        <i data-lucide="external-link" class="gs-icon-14"></i>
                                        Öppna verktyg
                                    </a>
                                    <?php if (isset($tool['status'])): ?>
                                        <span class="tool-status-badge tool-status-<?= $tool['status'] ?>">
                                            <?= $tool['status'] === 'recommended' ? 'Rekommenderad' : ($tool['status'] === 'test' ? 'Test' : 'Debug') ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</main>

<script src="https://unpkg.com/lucide@latest"></script>
<script>
    lucide.createIcons();

    // Load completed tools from localStorage
    function loadCompleted() {
        const completed = JSON.parse(localStorage.getItem('completedTools') || '[]');
        let count = 0;

        completed.forEach(toolId => {
            const toolElement = document.getElementById('tool-' + toolId);
            const checkbox = document.getElementById('check-' + toolId);

            if (toolElement && checkbox) {
                toolElement.classList.add('completed');
                checkbox.checked = true;
                count++;
            }
        });

        document.getElementById('completedCount').textContent = count;
    }

    // Toggle complete status
    function toggleComplete(toolId) {
        const completed = JSON.parse(localStorage.getItem('completedTools') || '[]');
        const toolElement = document.getElementById('tool-' + toolId);
        const checkbox = document.getElementById('check-' + toolId);

        if (checkbox.checked) {
            if (!completed.includes(toolId)) {
                completed.push(toolId);
            }
            toolElement.classList.add('completed');
        } else {
            const index = completed.indexOf(toolId);
            if (index > -1) {
                completed.splice(index, 1);
            }
            toolElement.classList.remove('completed');
        }

        localStorage.setItem('completedTools', JSON.stringify(completed));
        document.getElementById('completedCount').textContent = completed.length;
    }

    // Clear all completed
    function clearAllCompleted() {
        if (!confirm('Är du säker på att du vill rensa alla markeringar?')) {
            return;
        }

        localStorage.removeItem('completedTools');

        document.querySelectorAll('.tool-item').forEach(tool => {
            tool.classList.remove('completed');
        });

        document.querySelectorAll('.checkbox-complete').forEach(checkbox => {
            checkbox.checked = false;
        });

        document.getElementById('completedCount').textContent = '0';
    }

    // Load completed on page load
    document.addEventListener('DOMContentLoaded', function() {
        loadCompleted();
    });
</script>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>

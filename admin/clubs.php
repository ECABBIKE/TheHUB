<?php
/**
 * Admin Clubs - V3 Design System
 */
require_once __DIR__ . '/../config.php';
require_admin();

global $pdo;
$db = getDB();

// Initialize message variables
$message = '';
$messageType = 'info';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();

    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'update') {
        $name = trim($_POST['name'] ?? '');

        if (empty($name)) {
            $message = 'Klubbnamn är obligatoriskt';
            $messageType = 'error';
        } else {
            $clubData = [
                'name' => $name,
                'short_name' => trim($_POST['short_name'] ?? ''),
                'region' => trim($_POST['region'] ?? ''),
                'city' => trim($_POST['city'] ?? ''),
                'country' => trim($_POST['country'] ?? 'Sverige'),
                'website' => trim($_POST['website'] ?? ''),
                'active' => isset($_POST['active']) ? 1 : 0,
            ];

            try {
                if ($action === 'create') {
                    $db->insert('clubs', $clubData);
                    $message = 'Klubb skapad!';
                    $messageType = 'success';
                } else {
                    $id = intval($_POST['id']);
                    $db->update('clubs', $clubData, 'id = ?', [$id]);
                    $message = 'Klubb uppdaterad!';
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
            $db->delete('clubs', 'id = ?', [$id]);
            $message = 'Klubb borttagen!';
            $messageType = 'success';
        } catch (Exception $e) {
            $message = 'Ett fel uppstod: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}

// Handle search
$search = $_GET['search'] ?? '';

// Handle URL messages
if (isset($_GET['msg'])) {
    switch ($_GET['msg']) {
        case 'deleted':
            $message = 'Klubb borttagen!';
            $messageType = 'success';
            break;
    }
}

$where = [];
$params = [];

if ($search) {
    $where[] = "name LIKE ?";
    $params[] = "%$search%";
}

$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Get clubs with rider count
$sql = "SELECT
    cl.id,
    cl.name,
    cl.short_name,
    cl.city,
    cl.country,
    cl.active,
    COUNT(DISTINCT c.id) as rider_count
FROM clubs cl
LEFT JOIN riders c ON cl.id = c.club_id AND c.active = 1
$whereClause
GROUP BY cl.id
ORDER BY cl.name";

$clubs = $db->getAll($sql, $params);

// Calculate stats
$totalClubs = count($clubs);
$activeCount = 0;
$totalMembers = 0;
foreach ($clubs as $c) {
    if ($c['active'] == 1) $activeCount++;
    $totalMembers += $c['rider_count'];
}

// Page config
$page_title = 'Klubbar';
$breadcrumbs = [
    ['label' => 'Klubbar']
];
$page_actions = '<a href="/admin/club-edit.php" class="btn btn--primary">
    <i data-lucide="plus"></i>
    Ny Klubb
</a>';

// Include unified layout (uses same layout as public site)
include __DIR__ . '/components/unified-layout.php';
?>

<?php if ($message): ?>
    <div class="alert alert-<?= $messageType === 'success' ? 'success' : ($messageType === 'error' ? 'error' : 'info') ?>">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <?php if ($messageType === 'success'): ?>
                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>
            <?php elseif ($messageType === 'error'): ?>
                <circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/>
            <?php else: ?>
                <circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="16" y2="12"/><line x1="12" x2="12.01" y1="8" y2="8"/>
            <?php endif; ?>
        </svg>
        <?= htmlspecialchars($message) ?>
    </div>
<?php endif; ?>

<!-- Stats Grid -->
<div class="admin-stats-grid">
    <div class="admin-stat-card">
        <div class="admin-stat-icon" style="background: var(--color-info-light); color: var(--color-info);">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 22V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v18Z"/><path d="M6 12H4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h2"/><path d="M18 9h2a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-2"/><path d="M10 6h4"/><path d="M10 10h4"/><path d="M10 14h4"/><path d="M10 18h4"/></svg>
        </div>
        <div class="admin-stat-content">
            <div class="admin-stat-value"><?= $totalClubs ?></div>
            <div class="admin-stat-label">Totalt klubbar</div>
        </div>
    </div>

    <div class="admin-stat-card">
        <div class="admin-stat-icon" style="background: var(--color-success-light); color: var(--color-success);">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        </div>
        <div class="admin-stat-content">
            <div class="admin-stat-value"><?= $activeCount ?></div>
            <div class="admin-stat-label">Aktiva</div>
        </div>
    </div>

    <div class="admin-stat-card">
        <div class="admin-stat-icon" style="background: var(--color-accent-light); color: var(--color-accent);">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
        </div>
        <div class="admin-stat-content">
            <div class="admin-stat-value"><?= number_format($totalMembers, 0, ',', ' ') ?></div>
            <div class="admin-stat-label">Totalt medlemmar</div>
        </div>
    </div>
</div>

<!-- Search -->
<div class="admin-card">
    <div class="admin-card-body">
        <form method="GET" id="searchForm" class="admin-form-row" style="align-items: flex-end;">
            <div class="admin-form-group" style="flex: 1; margin-bottom: 0;">
                <label for="searchInput" class="admin-form-label">Sök</label>
                <input
                    type="text"
                    name="search"
                    id="searchInput"
                    class="admin-form-input"
                    placeholder="Sök efter klubbnamn..."
                    value="<?= htmlspecialchars($search) ?>"
                    autocomplete="off"
                >
            </div>
            <?php if ($search): ?>
                <a href="/admin/clubs" class="btn-admin btn-admin-sm btn-admin-secondary">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 14px; height: 14px;"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                    Rensa
                </a>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- Clubs Table -->
<div class="admin-card">
    <div class="admin-card-header">
        <h2><?= count($clubs) ?> klubbar</h2>
    </div>
    <div class="admin-card-body" style="padding: 0;">
        <?php if (empty($clubs)): ?>
            <div class="admin-empty-state">
                <i data-lucide="building-2" style="width: 48px; height: 48px; opacity: 0.5;"></i>
                <h3>Inga klubbar hittades</h3>
                <p>Prova att ändra sökning eller skapa en ny klubb.</p>
                <a href="/admin/club-edit.php" class="btn btn--primary">Skapa klubb</a>
            </div>
        <?php else: ?>
            <div class="admin-table-container">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Namn</th>
                            <th>Förkortning</th>
                            <th>Stad</th>
                            <th>Land</th>
                            <th>Medlemmar</th>
                            <th>Status</th>
                            <th style="width: 120px;">Åtgärder</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($clubs as $club): ?>
                            <tr>
                                <td>
                                    <a href="/admin/club-edit.php?id=<?= $club['id'] ?>" style="color: var(--color-accent); text-decoration: none; font-weight: 500;">
                                        <?= htmlspecialchars($club['name']) ?>
                                    </a>
                                </td>
                                <td>
                                    <span class="admin-badge admin-badge-info">
                                        <?= htmlspecialchars($club['short_name'] ?? substr($club['name'], 0, 3)) ?>
                                    </span>
                                </td>
                                <td style="color: var(--color-text-secondary);"><?= htmlspecialchars($club['city'] ?? '-') ?></td>
                                <td style="color: var(--color-text-secondary);"><?= htmlspecialchars($club['country'] ?? 'Sverige') ?></td>
                                <td>
                                    <a href="/admin/riders?club_id=<?= $club['id'] ?>" class="btn-admin btn-admin-sm btn-admin-secondary">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 14px; height: 14px;"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                                        <?= $club['rider_count'] ?>
                                    </a>
                                </td>
                                <td>
                                    <span class="admin-badge <?= $club['active'] ? 'admin-badge-success' : 'admin-badge-secondary' ?>">
                                        <?= $club['active'] ? 'Aktiv' : 'Inaktiv' ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="table-actions">
                                        <a href="/admin/club-edit.php?id=<?= $club['id'] ?>" class="btn btn--sm btn--secondary" title="Redigera">
                                            <i data-lucide="pencil"></i>
                                        </a>
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

<script>
// Live search with debouncing
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('searchInput');
    const searchForm = document.getElementById('searchForm');
    if (!searchInput || !searchForm) return;

    let debounceTimer;
    let lastSearch = searchInput.value;

    searchInput.addEventListener('input', function() {
        const query = this.value.trim();
        clearTimeout(debounceTimer);

        if (query === lastSearch) return;

        debounceTimer = setTimeout(function() {
            if (query.length >= 2 || query.length === 0) {
                lastSearch = query;
                searchForm.submit();
            }
        }, 300);
    });

    searchInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            clearTimeout(debounceTimer);
            searchForm.submit();
        }
    });
});
</script>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>

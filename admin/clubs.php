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

// Check if editing a club
$editClub = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $editClub = $db->getRow("SELECT * FROM clubs WHERE id = ?", [intval($_GET['edit'])]);
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
$page_actions = '<button onclick="openClubModal()" class="btn-admin btn-admin-primary">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
    Ny Klubb
</button>';

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
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 22V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v18Z"/><path d="M6 12H4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h2"/><path d="M18 9h2a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-2"/><path d="M10 6h4"/><path d="M10 10h4"/><path d="M10 14h4"/><path d="M10 18h4"/></svg>
                <h3>Inga klubbar hittades</h3>
                <p>Prova att ändra sökning eller skapa en ny klubb.</p>
                <button onclick="openClubModal()" class="btn-admin btn-admin-primary">Skapa klubb</button>
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
                                    <a href="?edit=<?= $club['id'] ?>" style="color: var(--color-accent); text-decoration: none; font-weight: 500;">
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
                                        <a href="?edit=<?= $club['id'] ?>" class="btn-admin btn-admin-sm btn-admin-secondary" title="Redigera">
                                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/><path d="m15 5 4 4"/></svg>
                                        </a>
                                        <button onclick="deleteClub(<?= $club['id'] ?>, '<?= addslashes($club['name']) ?>')" class="btn-admin btn-admin-sm btn-admin-danger" title="Ta bort">
                                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg>
                                        </button>
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

<!-- Club Modal -->
<div id="clubModal" class="admin-modal" style="display: none;">
    <div class="admin-modal-overlay" onclick="closeClubModal()"></div>
    <div class="admin-modal-content">
        <div class="admin-modal-header">
            <h2 id="modalTitle">Ny Klubb</h2>
            <button type="button" class="admin-modal-close" onclick="closeClubModal()">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
            </button>
        </div>
        <form method="POST" id="clubForm">
            <?= csrf_field() ?>
            <input type="hidden" name="action" id="formAction" value="create">
            <input type="hidden" name="id" id="clubId" value="">

            <div class="admin-modal-body">
                <div class="admin-form-group">
                    <label for="name" class="admin-form-label">Namn <span style="color: var(--color-error);">*</span></label>
                    <input type="text" id="name" name="name" class="admin-form-input" required placeholder="T.ex. Team GravitySeries">
                </div>

                <div class="admin-form-group">
                    <label for="short_name" class="admin-form-label">Förkortning</label>
                    <input type="text" id="short_name" name="short_name" class="admin-form-input" placeholder="T.ex. TGS, UCK">
                </div>

                <div class="admin-form-group">
                    <label for="region" class="admin-form-label">Region</label>
                    <input type="text" id="region" name="region" class="admin-form-input" placeholder="T.ex. Västsverige, Mellansverige">
                </div>

                <div class="admin-form-row">
                    <div class="admin-form-group">
                        <label for="city" class="admin-form-label">Stad</label>
                        <input type="text" id="city" name="city" class="admin-form-input" placeholder="T.ex. Stockholm">
                    </div>
                    <div class="admin-form-group">
                        <label for="country" class="admin-form-label">Land</label>
                        <input type="text" id="country" name="country" class="admin-form-input" value="Sverige" placeholder="Sverige">
                    </div>
                </div>

                <div class="admin-form-group">
                    <label for="website" class="admin-form-label">Webbplats</label>
                    <input type="url" id="website" name="website" class="admin-form-input" placeholder="https://example.com">
                </div>

                <div class="admin-form-group">
                    <label class="admin-checkbox-label">
                        <input type="checkbox" id="active" name="active" checked>
                        <span>Aktiv</span>
                    </label>
                </div>
            </div>

            <div class="admin-modal-footer">
                <button type="button" class="btn-admin btn-admin-secondary" onclick="closeClubModal()">Avbryt</button>
                <button type="submit" class="btn-admin btn-admin-primary" id="submitButton">Skapa</button>
            </div>
        </form>
    </div>
</div>

<script>
// Store CSRF token from PHP session
const csrfToken = '<?= htmlspecialchars(generate_csrf_token()) ?>';

function openClubModal() {
    document.getElementById('clubModal').style.display = 'flex';
    document.getElementById('clubForm').reset();
    document.getElementById('formAction').value = 'create';
    document.getElementById('clubId').value = '';
    document.getElementById('modalTitle').textContent = 'Ny Klubb';
    document.getElementById('submitButton').textContent = 'Skapa';
    document.getElementById('active').checked = true;
    document.getElementById('country').value = 'Sverige';
}

function closeClubModal() {
    document.getElementById('clubModal').style.display = 'none';
}

function deleteClub(id, name) {
    if (!confirm('Är du säker på att du vill ta bort "' + name + '"?')) {
        return;
    }

    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = '<input type="hidden" name="action" value="delete">' +
                     '<input type="hidden" name="id" value="' + id + '">' +
                     '<input type="hidden" name="csrf_token" value="' + csrfToken + '">';
    document.body.appendChild(form);
    form.submit();
}

// Handle edit mode from URL parameter
document.addEventListener('DOMContentLoaded', function() {
    <?php if ($editClub): ?>
        document.getElementById('formAction').value = 'update';
        document.getElementById('clubId').value = '<?= $editClub['id'] ?>';
        document.getElementById('name').value = '<?= addslashes($editClub['name']) ?>';
        document.getElementById('short_name').value = '<?= addslashes($editClub['short_name'] ?? '') ?>';
        document.getElementById('region').value = '<?= addslashes($editClub['region'] ?? '') ?>';
        document.getElementById('city').value = '<?= addslashes($editClub['city'] ?? '') ?>';
        document.getElementById('country').value = '<?= addslashes($editClub['country'] ?? 'Sverige') ?>';
        document.getElementById('website').value = '<?= addslashes($editClub['website'] ?? '') ?>';
        document.getElementById('active').checked = <?= $editClub['active'] ? 'true' : 'false' ?>;

        document.getElementById('modalTitle').textContent = 'Redigera Klubb';
        document.getElementById('submitButton').textContent = 'Uppdatera';
        document.getElementById('clubModal').style.display = 'flex';
    <?php endif; ?>

    // Live search with debouncing
    const searchInput = document.getElementById('searchInput');
    const searchForm = document.getElementById('searchForm');
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

// Close modal with Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeClubModal();
    }
});
</script>

<style>
/* Modal styles */
.admin-modal {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    z-index: 1000;
    display: flex;
    align-items: center;
    justify-content: center;
}

.admin-modal-overlay {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.5);
}

.admin-modal-content {
    position: relative;
    background: var(--color-bg);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-xl);
    width: 90%;
    max-width: 500px;
    max-height: 90vh;
    overflow: hidden;
    display: flex;
    flex-direction: column;
}

.admin-modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: var(--space-lg);
    border-bottom: 1px solid var(--color-border);
}

.admin-modal-header h2 {
    margin: 0;
    font-size: var(--text-xl);
}

.admin-modal-close {
    background: none;
    border: none;
    padding: var(--space-xs);
    cursor: pointer;
    color: var(--color-text-secondary);
    border-radius: var(--radius-sm);
}

.admin-modal-close:hover {
    background: var(--color-bg-tertiary);
    color: var(--color-text);
}

.admin-modal-close svg {
    width: 20px;
    height: 20px;
}

.admin-modal-body {
    padding: var(--space-lg);
    overflow-y: auto;
    flex: 1;
}

.admin-modal-footer {
    display: flex;
    justify-content: flex-end;
    gap: var(--space-sm);
    padding: var(--space-lg);
    border-top: 1px solid var(--color-border);
}

.admin-checkbox-label {
    display: flex;
    align-items: center;
    gap: var(--space-xs);
    cursor: pointer;
    font-size: var(--text-sm);
}

.admin-checkbox-label input[type="checkbox"] {
    width: 16px;
    height: 16px;
    accent-color: var(--color-accent);
}
</style>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>

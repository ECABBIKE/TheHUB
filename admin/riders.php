<?php
/**
 * Admin Riders - V3 Design System
 */
require_once __DIR__ . '/../config.php';
require_admin();

global $pdo;
$db = getDB();

// Handle search and filters
$search = $_GET['search'] ?? '';
$club_id = isset($_GET['club_id']) && is_numeric($_GET['club_id']) ? intval($_GET['club_id']) : null;
$onlyWithResults = isset($_GET['with_results']) && $_GET['with_results'] == '1';
$onlySweId = isset($_GET['swe_only']) && $_GET['swe_only'] == '1';

// Handle sorting
$sortBy = $_GET['sort'] ?? 'name';
$sortOrder = $_GET['order'] ?? 'asc';

// Validate sort parameters
$allowedSorts = ['name', 'year', 'club', 'license', 'results'];
$allowedOrders = ['asc', 'desc'];
if (!in_array($sortBy, $allowedSorts)) $sortBy = 'name';
if (!in_array($sortOrder, $allowedOrders)) $sortOrder = 'asc';

// Build ORDER BY clause
$orderBy = '';
if ($sortBy === 'name') {
    $orderBy = $sortOrder === 'asc' ? 'c.lastname ASC, c.firstname ASC' : 'c.lastname DESC, c.firstname DESC';
} elseif ($sortBy === 'year') {
    $orderBy = $sortOrder === 'asc' ? 'c.birth_year ASC' : 'c.birth_year DESC';
} elseif ($sortBy === 'club') {
    $orderBy = $sortOrder === 'asc' ? 'cl.name ASC, c.lastname ASC' : 'cl.name DESC, c.lastname ASC';
} elseif ($sortBy === 'license') {
    $orderBy = $sortOrder === 'asc' ? 'c.license_number ASC' : 'c.license_number DESC';
} elseif ($sortBy === 'results') {
    $orderBy = $sortOrder === 'asc' ? 'result_count ASC, c.lastname ASC' : 'result_count DESC, c.lastname ASC';
}

// Build query filters
$where = [];
$params = [];

if ($search) {
    $where[] = "(CONCAT(c.firstname, ' ', c.lastname) LIKE ? OR c.license_number LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($club_id) {
    $where[] = "c.club_id = ?";
    $params[] = $club_id;
}

if ($onlyWithResults) {
    $where[] = "EXISTS (SELECT 1 FROM results r WHERE r.cyclist_id = c.id)";
}

if ($onlySweId) {
    $where[] = "c.license_number LIKE 'SWE%'";
}

$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$sql = "SELECT
    c.id, c.firstname, c.lastname, c.birth_year, c.gender,
    c.license_number, c.license_type, c.license_category, c.license_valid_until, c.discipline, c.active,
    cl.name as club_name, cl.id as club_id,
    (SELECT COUNT(*) FROM results r WHERE r.cyclist_id = c.id) as result_count
FROM riders c
LEFT JOIN clubs cl ON c.club_id = cl.id
$whereClause
ORDER BY {$orderBy}
LIMIT 1000";

$riders = $db->getAll($sql, $params);

// Get selected club info if filtering
$selectedClub = null;
if ($club_id) {
    $selectedClub = $db->getRow("SELECT * FROM clubs WHERE id = ?", [$club_id]);
}

// Helper function for age calculation (use existing if available)
if (!function_exists('calculateAge')) {
    function calculateAge($birthYear) {
        if (!$birthYear) return null;
        return date('Y') - $birthYear;
    }
}

// Page config
$page_title = 'Deltagare';
$breadcrumbs = [
    ['label' => 'Deltagare']
];
$page_actions = '<a href="/admin/import/riders" class="btn-admin btn-admin-primary">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" x2="12" y1="3" y2="15"/></svg>
    Importera
</a>';

// Build sort URL helper
function buildSortUrl($field, $currentSort, $currentOrder, $search, $club_id, $onlyWithResults, $onlySweId) {
    $newOrder = ($currentSort === $field && $currentOrder === 'asc') ? 'desc' : 'asc';
    $url = "?sort=$field&order=$newOrder";
    if ($search) $url .= '&search=' . urlencode($search);
    if ($club_id) $url .= '&club_id=' . $club_id;
    if ($onlyWithResults) $url .= '&with_results=1';
    if ($onlySweId) $url .= '&swe_only=1';
    return $url;
}

// Include unified layout (uses same layout as public site)
include __DIR__ . '/components/unified-layout.php';
?>

<?php if ($selectedClub): ?>
    <div class="alert alert-info">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="16" y2="12"/><line x1="12" x2="12.01" y1="8" y2="8"/></svg>
        <span>Visar deltagare från <strong><?= htmlspecialchars($selectedClub['name']) ?></strong></span>
        <a href="/admin/riders" class="btn-admin btn-admin-sm btn-admin-secondary" style="margin-left: auto;">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 14px; height: 14px;"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
            Rensa filter
        </a>
    </div>
<?php endif; ?>

<!-- Search and Filter -->
<div class="admin-card">
    <div class="admin-card-body">
        <form method="GET" id="searchForm" class="admin-form-row" style="align-items: flex-end;">
            <?php if ($club_id): ?>
                <input type="hidden" name="club_id" value="<?= $club_id ?>">
            <?php endif; ?>

            <div class="admin-form-group" style="flex: 1; margin-bottom: 0;">
                <label for="searchInput" class="admin-form-label">Sök</label>
                <input
                    type="text"
                    name="search"
                    id="searchInput"
                    class="admin-form-input"
                    placeholder="Sök efter namn eller licensnummer..."
                    value="<?= htmlspecialchars($search) ?>"
                    autocomplete="off"
                >
            </div>

            <div class="admin-form-group" style="margin-bottom: 0;">
                <label class="admin-checkbox-label">
                    <input type="checkbox" name="with_results" value="1" <?= $onlyWithResults ? 'checked' : '' ?> onchange="this.form.submit()">
                    <span>Endast med resultat</span>
                </label>
            </div>

            <div class="admin-form-group" style="margin-bottom: 0;">
                <label class="admin-checkbox-label">
                    <input type="checkbox" name="swe_only" value="1" <?= $onlySweId ? 'checked' : '' ?> onchange="this.form.submit()">
                    <span>Endast SWE-ID</span>
                </label>
            </div>

            <?php if ($search || $onlyWithResults || $onlySweId): ?>
                <a href="/admin/riders<?= $club_id ? '?club_id=' . $club_id : '' ?>" class="btn-admin btn-admin-sm btn-admin-secondary">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 14px; height: 14px;"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                    Rensa
                </a>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- Riders Table -->
<div class="admin-card">
    <div class="admin-card-header">
        <h2><?= count($riders) ?> deltagare</h2>
    </div>
    <div class="admin-card-body" style="padding: 0;">
        <?php if (empty($riders)): ?>
            <div class="admin-empty-state">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                <h3>Inga deltagare hittades</h3>
                <p>Prova att ändra sökning eller filter.</p>
            </div>
        <?php else: ?>
            <div class="admin-table-container">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>
                                <a href="<?= buildSortUrl('name', $sortBy, $sortOrder, $search, $club_id, $onlyWithResults, $onlySweId) ?>" class="admin-sortable">
                                    Namn
                                    <?php if ($sortBy === 'name'): ?>
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 14px; height: 14px;">
                                            <?php if ($sortOrder === 'asc'): ?>
                                                <path d="m18 15-6-6-6 6"/>
                                            <?php else: ?>
                                                <path d="m6 9 6 6 6-6"/>
                                            <?php endif; ?>
                                        </svg>
                                    <?php endif; ?>
                                </a>
                            </th>
                            <th>
                                <a href="<?= buildSortUrl('year', $sortBy, $sortOrder, $search, $club_id, $onlyWithResults, $onlySweId) ?>" class="admin-sortable">
                                    År
                                    <?php if ($sortBy === 'year'): ?>
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 14px; height: 14px;">
                                            <?php if ($sortOrder === 'asc'): ?>
                                                <path d="m18 15-6-6-6 6"/>
                                            <?php else: ?>
                                                <path d="m6 9 6 6 6-6"/>
                                            <?php endif; ?>
                                        </svg>
                                    <?php endif; ?>
                                </a>
                            </th>
                            <th>
                                <a href="<?= buildSortUrl('club', $sortBy, $sortOrder, $search, $club_id, $onlyWithResults, $onlySweId) ?>" class="admin-sortable">
                                    Klubb
                                    <?php if ($sortBy === 'club'): ?>
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 14px; height: 14px;">
                                            <?php if ($sortOrder === 'asc'): ?>
                                                <path d="m18 15-6-6-6 6"/>
                                            <?php else: ?>
                                                <path d="m6 9 6 6 6-6"/>
                                            <?php endif; ?>
                                        </svg>
                                    <?php endif; ?>
                                </a>
                            </th>
                            <th>
                                <a href="<?= buildSortUrl('license', $sortBy, $sortOrder, $search, $club_id, $onlyWithResults, $onlySweId) ?>" class="admin-sortable">
                                    Licensnummer
                                    <?php if ($sortBy === 'license'): ?>
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 14px; height: 14px;">
                                            <?php if ($sortOrder === 'asc'): ?>
                                                <path d="m18 15-6-6-6 6"/>
                                            <?php else: ?>
                                                <path d="m6 9 6 6 6-6"/>
                                            <?php endif; ?>
                                        </svg>
                                    <?php endif; ?>
                                </a>
                            </th>
                            <th>
                                <a href="<?= buildSortUrl('results', $sortBy, $sortOrder, $search, $club_id, $onlyWithResults, $onlySweId) ?>" class="admin-sortable">
                                    Resultat
                                    <?php if ($sortBy === 'results'): ?>
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 14px; height: 14px;">
                                            <?php if ($sortOrder === 'asc'): ?>
                                                <path d="m18 15-6-6-6 6"/>
                                            <?php else: ?>
                                                <path d="m6 9 6 6 6-6"/>
                                            <?php endif; ?>
                                        </svg>
                                    <?php endif; ?>
                                </a>
                            </th>
                            <th>Licensstatus</th>
                            <th>Disciplin</th>
                            <th style="width: 100px;">Åtgärder</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($riders as $rider): ?>
                            <?php
                            // Check license status
                            $hasValidLicense = false;
                            $licenseStatusMessage = '-';
                            $licenseStatusClass = 'admin-badge-secondary';

                            if (!empty($rider['license_number']) && strpos($rider['license_number'], 'SWE') !== 0) {
                                if (!empty($rider['license_valid_until'])) {
                                    $validUntil = strtotime($rider['license_valid_until']);
                                    $today = time();
                                    if ($validUntil >= $today) {
                                        $hasValidLicense = true;
                                        $licenseStatusMessage = 'Aktiv';
                                        $licenseStatusClass = 'admin-badge-success';
                                    } else {
                                        $licenseStatusMessage = 'Utgången';
                                        $licenseStatusClass = 'admin-badge-error';
                                    }
                                } else {
                                    $hasValidLicense = true;
                                    $licenseStatusMessage = 'Aktiv';
                                    $licenseStatusClass = 'admin-badge-success';
                                }
                            } elseif (!empty($rider['license_number']) && strpos($rider['license_number'], 'SWE') === 0) {
                                $licenseStatusMessage = 'SWE-ID';
                                $licenseStatusClass = 'admin-badge-warning';
                            }
                            ?>
                            <tr>
                                <td>
                                    <a href="/rider/<?= $rider['id'] ?>" style="color: var(--color-accent); text-decoration: none; font-weight: 500;">
                                        <?= htmlspecialchars($rider['firstname'] . ' ' . $rider['lastname']) ?>
                                    </a>
                                </td>
                                <td>
                                    <?php if ($rider['birth_year']): ?>
                                        <strong><?= $rider['birth_year'] ?></strong>
                                        <span style="color: var(--color-text-secondary); font-size: var(--text-xs);"> (<?= calculateAge($rider['birth_year']) ?> år)</span>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($rider['club_name']): ?>
                                        <?php if ($club_id): ?>
                                            <?= htmlspecialchars($rider['club_name']) ?>
                                        <?php else: ?>
                                            <a href="/admin/riders?club_id=<?= $rider['club_id'] ?>" style="color: var(--color-accent); text-decoration: none;">
                                                <?= htmlspecialchars($rider['club_name']) ?>
                                            </a>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($rider['license_number']): ?>
                                        <span class="admin-badge <?= strpos($rider['license_number'], 'SWE') === 0 ? 'admin-badge-warning' : 'admin-badge-info' ?>">
                                            <?= htmlspecialchars($rider['license_number']) ?>
                                        </span>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($rider['result_count'] > 0): ?>
                                        <span class="admin-badge admin-badge-info"><?= $rider['result_count'] ?></span>
                                    <?php else: ?>
                                        <span style="color: var(--color-text-secondary);">0</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="admin-badge <?= $licenseStatusClass ?>">
                                        <?= $licenseStatusMessage ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($rider['discipline']): ?>
                                        <span class="admin-badge"><?= htmlspecialchars($rider['discipline']) ?></span>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="table-actions">
                                        <a href="/admin/riders/edit/<?= $rider['id'] ?>" class="btn-admin btn-admin-sm btn-admin-secondary" title="Redigera">
                                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/><path d="m15 5 4 4"/></svg>
                                        </a>
                                        <button onclick="deleteRider(<?= $rider['id'] ?>, '<?= addslashes($rider['firstname'] . ' ' . $rider['lastname']) ?>')" class="btn-admin btn-admin-sm btn-admin-danger" title="Ta bort">
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

<script>
// Store CSRF token from PHP session
const csrfToken = '<?= htmlspecialchars(generate_csrf_token()) ?>';

function deleteRider(id, name) {
    if (!confirm('Är du säker på att du vill ta bort "' + name + '"?')) {
        return;
    }

    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '/admin/rider-delete.php';
    form.innerHTML = '<input type="hidden" name="id" value="' + id + '">' +
                     '<input type="hidden" name="csrf_token" value="' + csrfToken + '">';
    document.body.appendChild(form);
    form.submit();
}

// Live search with debouncing
(function() {
    const searchInput = document.getElementById('searchInput');
    const searchForm = document.getElementById('searchForm');
    let debounceTimer;
    let lastSearch = searchInput.value;

    searchInput.addEventListener('input', function() {
        const query = this.value.trim();
        clearTimeout(debounceTimer);

        if (query === lastSearch) return;

        debounceTimer = setTimeout(function() {
            if (query.length >= 3 || query.length === 0) {
                lastSearch = query;
                searchForm.submit();
            }
        }, 600);
    });

    searchInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            clearTimeout(debounceTimer);
            searchForm.submit();
        }
    });

    if (searchInput.value) {
        searchInput.focus();
        searchInput.setSelectionRange(searchInput.value.length, searchInput.value.length);
    }
})();
</script>

<style>
.admin-sortable {
    display: inline-flex;
    align-items: center;
    gap: var(--space-xs);
    color: inherit;
    text-decoration: none;
}

.admin-sortable:hover {
    color: var(--color-accent);
}

.admin-checkbox-label {
    display: flex;
    align-items: center;
    gap: var(--space-xs);
    cursor: pointer;
    font-size: var(--text-sm);
    white-space: nowrap;
}

.admin-checkbox-label input[type="checkbox"] {
    width: 16px;
    height: 16px;
    accent-color: var(--color-accent);
}
</style>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>

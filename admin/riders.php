<?php
require_once __DIR__ . '/../config.php';
require_admin();

global $pdo;
$db = getDB();

// Handle search and filters
$search = $_GET['search'] ?? '';
$club_id = isset($_GET['club_id']) && is_numeric($_GET['club_id']) ? intval($_GET['club_id']) : null;

// Handle sorting
$sortBy = $_GET['sort'] ?? 'name';
$sortOrder = $_GET['order'] ?? 'asc';

// Validate sort parameters
$allowedSorts = ['name', 'year'];
$allowedOrders = ['asc', 'desc'];
if (!in_array($sortBy, $allowedSorts)) $sortBy = 'name';
if (!in_array($sortOrder, $allowedOrders)) $sortOrder = 'asc';

// Build ORDER BY clause
$orderBy = '';
if ($sortBy === 'name') {
    $orderBy = $sortOrder === 'asc' ? 'c.lastname ASC, c.firstname ASC' : 'c.lastname DESC, c.firstname DESC';
} elseif ($sortBy === 'year') {
    $orderBy = $sortOrder === 'asc' ? 'c.birth_year ASC' : 'c.birth_year DESC';
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

$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$sql = "SELECT
    c.id, c.firstname, c.lastname, c.birth_year, c.gender,
    c.license_number, c.license_type, c.license_category, c.license_valid_until, c.discipline, c.active,
    cl.name as club_name, cl.id as club_id
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

$pageTitle = 'Deltagare';
$pageType = 'admin';
include __DIR__ . '/../includes/layout-header.php';
?>

<main class="gs-main-content">
    <div class="gs-container">
        <div class="gs-flex gs-justify-between gs-items-center gs-mb-lg">
            <h1 class="gs-h2">
                <i data-lucide="users"></i>
                Deltagare (<?= count($riders) ?>)
            </h1>
            <a href="/admin/import-uci.php" class="gs-btn gs-btn-primary">
                <i data-lucide="upload"></i>
                Importera
            </a>
        </div>

        <!-- Filter indicator -->
        <?php if ($selectedClub): ?>
            <div class="gs-alert gs-alert-info gs-mb-lg">
                <i data-lucide="filter"></i>
                Visar deltagare från <strong><?= h($selectedClub['name']) ?></strong>
                <a href="/admin/riders.php" class="gs-btn gs-btn-sm gs-btn-outline gs-ml-auto">
                    <i data-lucide="x"></i>
                    Rensa filter
                </a>
            </div>
        <?php endif; ?>

        <!-- Search -->
        <div class="gs-card gs-mb-lg">
            <div class="gs-card-content">
                <form method="GET" id="searchForm" class="gs-flex gs-gap-md gs-items-center">
                    <?php if ($club_id): ?>
                        <input type="hidden" name="club_id" value="<?= $club_id ?>">
                    <?php endif; ?>
                    <div class="gs-flex-1">
                        <div class="gs-input-group">
                            <i data-lucide="search"></i>
                            <input
                                type="text"
                                name="search"
                                id="searchInput"
                                class="gs-input"
                                placeholder="Sök efter namn eller licensnummer..."
                                value="<?= h($search) ?>"
                                autocomplete="off"
                            >
                        </div>
                    </div>
                    <?php if ($search): ?>
                        <a href="/admin/riders.php<?= $club_id ? '?club_id=' . $club_id : '' ?>" class="gs-btn gs-btn-outline gs-btn-sm">
                            <i data-lucide="x"></i>
                            Rensa
                        </a>
                    <?php endif; ?>
                    <span id="searchStatus" class="gs-text-xs gs-text-secondary" style="display: none;">Söker...</span>
                </form>
            </div>
        </div>

        <div class="gs-card">
            <div class="gs-card-content">
                <?php if (empty($riders)): ?>
                    <div class="gs-alert gs-alert-warning">
                        <p>Inga deltagare hittades.</p>
                    </div>
                <?php else: ?>
                    <div class="gs-table-responsive">
                        <table class="gs-table">
                            <thead>
                                <tr>
                                    <th>
                                        <a href="?sort=name&order=<?= $sortBy === 'name' && $sortOrder === 'asc' ? 'desc' : 'asc' ?><?= $search ? '&search=' . urlencode($search) : '' ?><?= $club_id ? '&club_id=' . $club_id : '' ?>"
                                           class="gs-link gs-sortable-header">
                                            Namn
                                            <?php if ($sortBy === 'name'): ?>
                                                <i data-lucide="<?= $sortOrder === 'asc' ? 'arrow-up' : 'arrow-down' ?>" class="gs-icon-14"></i>
                                            <?php endif; ?>
                                        </a>
                                    </th>
                                    <th>
                                        <a href="?sort=year&order=<?= $sortBy === 'year' && $sortOrder === 'asc' ? 'desc' : 'asc' ?><?= $search ? '&search=' . urlencode($search) : '' ?><?= $club_id ? '&club_id=' . $club_id : '' ?>"
                                           class="gs-link gs-sortable-header">
                                            År
                                            <?php if ($sortBy === 'year'): ?>
                                                <i data-lucide="<?= $sortOrder === 'asc' ? 'arrow-up' : 'arrow-down' ?>" class="gs-icon-14"></i>
                                            <?php endif; ?>
                                        </a>
                                    </th>
                                    <th>Klubb</th>
                                    <th>Licensnummer</th>
                                    <th>Licensstatus</th>
                                    <th>Disciplin</th>
                                    <th class="gs-table-col-actions">Åtgärder</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($riders as $rider): ?>
                                    <tr>
                                        <td>
                                            <a href="/rider.php?id=<?= $rider['id'] ?>" class="gs-link">
                                                <strong><?= htmlspecialchars($rider['firstname'] . ' ' . $rider['lastname']) ?></strong>
                                            </a>
                                        </td>
                                        <td>
                                            <?php if ($rider['birth_year']): ?>
                                                <strong><?= $rider['birth_year'] ?></strong>
                                                <span class="gs-text-secondary gs-text-xs"> (<?= calculateAge($rider['birth_year']) ?> år)</span>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($rider['club_name']): ?>
                                                <?php if ($club_id): ?>
                                                    <!-- Already filtering by club, no need for link -->
                                                    <?= htmlspecialchars($rider['club_name']) ?>
                                                <?php else: ?>
                                                    <a href="/admin/riders.php?club_id=<?= $rider['club_id'] ?>" class="gs-link">
                                                        <?= htmlspecialchars($rider['club_name']) ?>
                                                    </a>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($rider['license_number']): ?>
                                                <span class="gs-badge gs-badge-sm <?= strpos($rider['license_number'], 'SWE') === 0 ? 'gs-badge-warning' : 'gs-badge-primary' ?>">
                                                    <?= htmlspecialchars($rider['license_number']) ?>
                                                </span>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php
                                            // Check license status
                                            $hasValidLicense = false;
                                            $licenseStatusMessage = '-';
                                            $licenseStatusClass = 'gs-badge-secondary';

                                            // Check if license number exists and is not a SWE-ID
                                            if (!empty($rider['license_number']) && strpos($rider['license_number'], 'SWE') !== 0) {
                                                // Has non-SWE license number - check validity
                                                if (!empty($rider['license_valid_until'])) {
                                                    $validUntil = strtotime($rider['license_valid_until']);
                                                    $today = time();

                                                    if ($validUntil >= $today) {
                                                        $hasValidLicense = true;
                                                        $licenseStatusMessage = '✓ Aktiv';
                                                        $licenseStatusClass = 'gs-badge-success';
                                                    } else {
                                                        $licenseStatusMessage = '✗ Utgången';
                                                        $licenseStatusClass = 'gs-badge-danger';
                                                    }
                                                } else {
                                                    // Has license number but no validity date - assume active
                                                    $hasValidLicense = true;
                                                    $licenseStatusMessage = '✓ Aktiv';
                                                    $licenseStatusClass = 'gs-badge-success';
                                                }
                                            } elseif (!empty($rider['license_number']) && strpos($rider['license_number'], 'SWE') === 0) {
                                                // SWE-ID (internal ID, not a real license)
                                                $licenseStatusMessage = '✗ Ingen licens (SWE-ID)';
                                                $licenseStatusClass = 'gs-badge-danger';
                                            }
                                            ?>
                                            <span class="gs-badge <?= $licenseStatusClass ?>">
                                                <?= $licenseStatusMessage ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($rider['discipline']): ?>
                                                <span class="gs-badge"><?= htmlspecialchars($rider['discipline']) ?></span>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="gs-flex gs-gap-sm">
                                                <a href="/admin/rider-edit.php?id=<?= $rider['id'] ?>" class="gs-btn gs-btn-sm gs-btn-outline" title="Redigera">
                                                    <i data-lucide="edit" class="gs-icon-14"></i>
                                                </a>
                                                <button onclick="deleteRider(<?= $rider['id'] ?>, '<?= addslashes($rider['firstname'] . ' ' . $rider['lastname']) ?>')" class="gs-btn gs-btn-sm gs-btn-outline gs-btn-danger" title="Ta bort">
                                                    <i data-lucide="trash-2" class="gs-icon-14"></i>
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
    </div>
</main>

<script src="https://unpkg.com/lucide@latest"></script>
<script>
    lucide.createIcons();

    // Store CSRF token from PHP session
    const csrfToken = '<?= htmlspecialchars(generate_csrf_token()) ?>';

    function deleteRider(id, name) {
        if (!confirm('Är du säker på att du vill ta bort "' + name + '"?')) {
            return;
        }

        // Create form and submit
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
        const searchStatus = document.getElementById('searchStatus');
        let debounceTimer;
        let lastSearch = searchInput.value;

        searchInput.addEventListener('input', function() {
            const query = this.value.trim();

            // Clear previous timer
            clearTimeout(debounceTimer);

            // Don't search if query hasn't changed
            if (query === lastSearch) return;

            // Show status indicator
            if (query.length >= 2 || query.length === 0) {
                searchStatus.style.display = 'inline';
            }

            // Debounce: wait 300ms after user stops typing
            debounceTimer = setTimeout(function() {
                // Only search if at least 2 characters or empty (to clear)
                if (query.length >= 2 || query.length === 0) {
                    lastSearch = query;
                    searchForm.submit();
                } else {
                    searchStatus.style.display = 'none';
                }
            }, 300);
        });

        // Also handle Enter key
        searchInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                clearTimeout(debounceTimer);
                searchForm.submit();
            }
        });
    })();
</script>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>

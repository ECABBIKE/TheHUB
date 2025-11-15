<?php
require_once __DIR__ . '/../config.php';
require_admin();

global $pdo;
$db = getDB();

// Handle search and filters
$search = $_GET['search'] ?? '';
$club_id = isset($_GET['club_id']) && is_numeric($_GET['club_id']) ? intval($_GET['club_id']) : null;

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
ORDER BY c.lastname, c.firstname
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
                <a href="/admin/riders.php" class="gs-btn gs-btn-sm gs-btn-outline" style="margin-left: auto;">
                    <i data-lucide="x"></i>
                    Rensa filter
                </a>
            </div>
        <?php endif; ?>

        <!-- Search -->
        <div class="gs-card gs-mb-lg">
            <div class="gs-card-content">
                <form method="GET" class="gs-flex gs-gap-md">
                    <?php if ($club_id): ?>
                        <input type="hidden" name="club_id" value="<?= $club_id ?>">
                    <?php endif; ?>
                    <div class="gs-flex-1">
                        <div class="gs-input-group">
                            <i data-lucide="search"></i>
                            <input
                                type="text"
                                name="search"
                                class="gs-input"
                                placeholder="Sök efter namn eller licensnummer..."
                                value="<?= h($search) ?>"
                            >
                        </div>
                    </div>
                    <button type="submit" class="gs-btn gs-btn-primary">
                        <i data-lucide="search"></i>
                        Sök
                    </button>
                    <?php if ($search): ?>
                        <a href="/admin/riders.php<?= $club_id ? '?club_id=' . $club_id : '' ?>" class="gs-btn gs-btn-outline">
                            Rensa
                        </a>
                    <?php endif; ?>
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
                    <div style="overflow-x: auto;">
                        <table class="gs-table">
                            <thead>
                                <tr>
                                    <th>Namn</th>
                                    <th>Ålder</th>
                                    <th>Klubb</th>
                                    <th>Licensnummer</th>
                                    <th>Licensstatus</th>
                                    <th>Disciplin</th>
                                    <th>Status</th>
                                    <th style="width: 120px;">Åtgärder</th>
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
                                                <?= calculateAge($rider['birth_year']) ?> år
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
                                            if (!empty($rider['license_number']) && strpos($rider['license_number'], 'SWE') === 0): ?>
                                                <span class="gs-badge gs-badge-danger">
                                                    ✗ Ej aktiv licens
                                                </span>
                                            <?php elseif (!empty($rider['license_type']) && $rider['license_type'] !== 'None'):
                                                $licenseCheck = checkLicense($rider);
                                                if ($licenseCheck['valid']): ?>
                                                    <span class="gs-badge gs-badge-success">
                                                        ✓ <?= h($licenseCheck['message']) ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="gs-badge gs-badge-danger">
                                                        ✗ <?= h($licenseCheck['message']) ?>
                                                    </span>
                                                <?php endif;
                                            else: ?>
                                                <span class="gs-badge gs-badge-secondary">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($rider['discipline']): ?>
                                                <span class="gs-badge"><?= htmlspecialchars($rider['discipline']) ?></span>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($rider['active']): ?>
                                                <span class="gs-badge gs-badge-success">Aktiv</span>
                                            <?php else: ?>
                                                <span class="gs-badge gs-badge-secondary">Inaktiv</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="gs-flex gs-gap-sm">
                                                <a href="/admin/rider-edit.php?id=<?= $rider['id'] ?>" class="gs-btn gs-btn-sm gs-btn-outline" title="Redigera">
                                                    <i data-lucide="edit" style="width: 14px; height: 14px;"></i>
                                                </a>
                                                <button onclick="deleteRider(<?= $rider['id'] ?>, '<?= addslashes($rider['firstname'] . ' ' . $rider['lastname']) ?>')" class="gs-btn gs-btn-sm gs-btn-outline gs-btn-danger" title="Ta bort">
                                                    <i data-lucide="trash-2" style="width: 14px; height: 14px;"></i>
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
</script>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>

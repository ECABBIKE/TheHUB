<?php
/**
 * TheHUB Riders Page Module
 * Can be loaded via SPA (app.php) or directly
 */

$isSpaMode = defined('HUB_ROOT') && isset($pageInfo);

if (!$isSpaMode) {
    require_once __DIR__ . '/../config.php';
    $pageTitle = 'Deltagare';
    $pageType = 'public';
    include __DIR__ . '/../includes/layout-header.php';
}

$db = getDB();

// Search and pagination
$search = trim($_GET['q'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

// Build query
$where = "WHERE r.active = 1";
$params = [];

if ($search) {
    $where .= " AND (r.firstname LIKE ? OR r.lastname LIKE ? OR CONCAT(r.firstname, ' ', r.lastname) LIKE ?)";
    $searchTerm = "%{$search}%";
    $params = [$searchTerm, $searchTerm, $searchTerm];
}

// Get total count
$countSql = "SELECT COUNT(*) FROM riders r {$where}";
$totalCount = $db->getValue($countSql, $params);
$totalPages = ceil($totalCount / $perPage);

// Get riders
$sql = "SELECT r.id, r.firstname, r.lastname, r.birth_year, c.name as club_name,
               (SELECT COUNT(*) FROM results res WHERE res.cyclist_id = r.id) as result_count
        FROM riders r
        LEFT JOIN clubs c ON r.club_id = c.id
        {$where}
        ORDER BY r.lastname, r.firstname
        LIMIT {$perPage} OFFSET {$offset}";

$riders = $db->getAll($sql, $params);
?>

<div class="container">
    <div class="mb-lg">
        <h1 class="text-primary mb-sm">
            <i data-lucide="users"></i>
            Deltagare
        </h1>
        <p class="text-secondary">
            <?= number_format($totalCount, 0, ',', ' ') ?> registrerade deltagare
        </p>
    </div>

    <!-- Search -->
    <div class="card mb-lg">
        <div class="card-body">
            <form method="GET" action="<?= $isSpaMode ? '/riders' : '/riders.php' ?>" class="flex gap-md">
                <input type="text" name="q" class="input flex-1" placeholder="Sok deltagare..." value="<?= h($search) ?>">
                <button type="submit" class="btn btn-primary">
                    <i data-lucide="search"></i>
                    Sok
                </button>
            </form>
        </div>
    </div>

    <?php if (empty($riders)): ?>
    <div class="card text-center p-xl">
        <i data-lucide="users" style="width: 48px; height: 48px; color: var(--color-text-muted); margin-bottom: var(--space-md);"></i>
        <h3>Inga deltagare hittades</h3>
        <?php if ($search): ?>
        <p class="text-secondary">Prova ett annat sokord.</p>
        <?php endif; ?>
    </div>
    <?php else: ?>
    <div class="card">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Namn</th>
                        <th>Klubb</th>
                        <th class="text-center">Fodelseår</th>
                        <th class="text-center">Resultat</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($riders as $rider): ?>
                    <tr>
                        <td>
                            <a href="<?= $isSpaMode ? '/rider/' . $rider['id'] : '/rider.php?id=' . $rider['id'] ?>" class="font-semibold text-accent">
                                <?= h($rider['firstname'] . ' ' . $rider['lastname']) ?>
                            </a>
                        </td>
                        <td class="text-secondary"><?= h($rider['club_name'] ?? '-') ?></td>
                        <td class="text-center"><?= $rider['birth_year'] ?? '-' ?></td>
                        <td class="text-center">
                            <span class="badge"><?= $rider['result_count'] ?></span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="flex justify-center gap-sm mt-lg">
        <?php if ($page > 1): ?>
        <a href="?page=<?= $page - 1 ?><?= $search ? '&q=' . urlencode($search) : '' ?>" class="btn btn-secondary btn-sm">
            <i data-lucide="chevron-left"></i> Foregaende
        </a>
        <?php endif; ?>

        <span class="btn btn-secondary btn-sm disabled">
            Sida <?= $page ?> av <?= $totalPages ?>
        </span>

        <?php if ($page < $totalPages): ?>
        <a href="?page=<?= $page + 1 ?><?= $search ? '&q=' . urlencode($search) : '' ?>" class="btn btn-secondary btn-sm">
            Nasta <i data-lucide="chevron-right"></i>
        </a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<?php
if (!$isSpaMode) {
    include __DIR__ . '/../includes/layout-footer.php';
}
?>

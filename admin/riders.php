<?php
require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();
$current_admin = get_current_admin();

// Handle search
$search = $_GET['search'] ?? '';
$where = [];
$params = [];

if ($search) {
    $where[] = "(CONCAT(c.firstname, ' ', c.lastname) LIKE ? OR c.license_number LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Get riders
$sql = "SELECT
            c.id,
            c.firstname,
            c.lastname,
            c.birth_year,
            c.gender,
            c.license_number,
            c.active,
            cl.name as club_name,
            cl.id as club_id
        FROM cyclists c
        LEFT JOIN clubs cl ON c.club_id = cl.id
        $whereClause
        ORDER BY c.lastname, c.firstname
        LIMIT 100";

$riders = $db->getAll($sql, $params);

$pageTitle = 'Deltagare';
?>
<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($pageTitle) ?> - TheHUB Admin</title>
    <link rel="stylesheet" href="/assets/gravityseries-theme.css">
</head>
<body>
    <?php include __DIR__ . '/../includes/navigation.php'; ?>

    <main class="gs-content-with-sidebar">
        <div class="gs-container">
            <!-- Header -->
            <div class="gs-flex gs-items-center gs-justify-between gs-mb-xl">
                <h1 class="gs-h1 gs-text-primary">
                    <i data-lucide="users"></i>
                    Deltagare
                </h1>
                <a href="/admin/rider-add.php" class="gs-btn gs-btn-primary">
                    <i data-lucide="plus"></i>
                    Lägg till deltagare
                </a>
            </div>

            <!-- Search -->
            <div class="gs-card gs-mb-lg">
                <div class="gs-card-content">
                    <form method="GET" class="gs-flex gs-gap-md">
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
                            <a href="/admin/riders.php" class="gs-btn gs-btn-outline">
                                Rensa
                            </a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>

            <!-- Stats -->
            <div class="gs-grid gs-grid-cols-1 gs-md-grid-cols-3 gs-gap-lg gs-mb-lg">
                <div class="gs-stat-card">
                    <i data-lucide="users" class="gs-icon-lg gs-text-primary gs-mb-md"></i>
                    <div class="gs-stat-number"><?= count($riders) ?></div>
                    <div class="gs-stat-label">Totalt deltagare</div>
                </div>
                <div class="gs-stat-card">
                    <i data-lucide="user-check" class="gs-icon-lg gs-text-success gs-mb-md"></i>
                    <div class="gs-stat-number">
                        <?= count(array_filter($riders, fn($r) => $r['active'] == 1)) ?>
                    </div>
                    <div class="gs-stat-label">Aktiva</div>
                </div>
                <div class="gs-stat-card">
                    <i data-lucide="building" class="gs-icon-lg gs-text-accent gs-mb-md"></i>
                    <div class="gs-stat-number">
                        <?= count(array_unique(array_column($riders, 'club_id'))) ?>
                    </div>
                    <div class="gs-stat-label">Klubbar</div>
                </div>
            </div>

            <!-- Riders Table -->
            <?php if (empty($riders)): ?>
                <div class="gs-card">
                    <div class="gs-card-content gs-text-center gs-py-xl">
                        <i data-lucide="user-x" style="width: 64px; height: 64px; color: var(--gs-text-secondary); margin-bottom: var(--gs-space-md);"></i>
                        <p class="gs-text-secondary">Inga deltagare hittades</p>
                    </div>
                </div>
            <?php else: ?>
                <div class="gs-card">
                    <div class="gs-table-responsive">
                        <table class="gs-table">
                            <thead>
                                <tr>
                                    <th>
                                        <i data-lucide="user-circle"></i>
                                        Namn
                                    </th>
                                    <th>Födelseår</th>
                                    <th>Kön</th>
                                    <th>
                                        <i data-lucide="building"></i>
                                        Klubb
                                    </th>
                                    <th>Licensnummer</th>
                                    <th>Status</th>
                                    <th style="width: 150px; text-align: right;">Åtgärder</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($riders as $rider): ?>
                                    <tr>
                                        <td>
                                            <strong><?= h($rider['firstname'] . ' ' . $rider['lastname']) ?></strong>
                                        </td>
                                        <td><?= h($rider['birth_year']) ?></td>
                                        <td>
                                            <?php if ($rider['gender'] === 'M'): ?>
                                                <span class="gs-badge gs-badge-primary">Man</span>
                                            <?php elseif ($rider['gender'] === 'F'): ?>
                                                <span class="gs-badge gs-badge-accent">Kvinna</span>
                                            <?php else: ?>
                                                <span class="gs-badge">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="gs-text-secondary"><?= h($rider['club_name'] ?? '-') ?></td>
                                        <td class="gs-text-secondary" style="font-family: monospace;">
                                            <?= h($rider['license_number'] ?? '-') ?>
                                        </td>
                                        <td>
                                            <?php if ($rider['active']): ?>
                                                <span class="gs-badge gs-badge-success">
                                                    <i data-lucide="check-circle"></i>
                                                    Aktiv
                                                </span>
                                            <?php else: ?>
                                                <span class="gs-badge gs-badge-secondary">Inaktiv</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align: right;">
                                            <div class="gs-flex gs-gap-xs gs-justify-end">
                                                <a href="/admin/rider-edit.php?id=<?= $rider['id'] ?>" class="gs-btn gs-btn-sm gs-btn-outline" title="Redigera">
                                                    <i data-lucide="edit"></i>
                                                </a>
                                                <a href="/admin/rider-view.php?id=<?= $rider['id'] ?>" class="gs-btn gs-btn-sm gs-btn-outline" title="Visa">
                                                    <i data-lucide="eye"></i>
                                                </a>
                                                <button
                                                    onclick="if(confirm('Är du säker på att du vill radera denna deltagare?')) location.href='/admin/rider-delete.php?id=<?= $rider['id'] ?>'"
                                                    class="gs-btn gs-btn-sm gs-btn-danger"
                                                    title="Radera"
                                                >
                                                    <i data-lucide="trash-2"></i>
                                                </button>
                                            </div>
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

    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            lucide.createIcons();
        });
    </script>
</body>
</html>

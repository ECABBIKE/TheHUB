<?php
require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();
$current_admin = get_current_admin();

// Demo mode check
$is_demo = ($db->getConnection() === null);

// Handle search
$search = $_GET['search'] ?? '';

if ($is_demo) {
    // Demo clubs
    $all_clubs = [
        ['id' => 1, 'name' => 'Team GravitySeries', 'short_name' => 'TGS', 'city' => 'Stockholm', 'country' => 'Sverige', 'active' => 1, 'rider_count' => 45],
        ['id' => 2, 'name' => 'CK Olympia', 'short_name' => 'CKO', 'city' => 'Göteborg', 'country' => 'Sverige', 'active' => 1, 'rider_count' => 38],
        ['id' => 3, 'name' => 'Uppsala CK', 'short_name' => 'UCK', 'city' => 'Uppsala', 'country' => 'Sverige', 'active' => 1, 'rider_count' => 52],
        ['id' => 4, 'name' => 'Team Sportson', 'short_name' => 'TSP', 'city' => 'Malmö', 'country' => 'Sverige', 'active' => 1, 'rider_count' => 41],
        ['id' => 5, 'name' => 'IFK Göteborg CK', 'short_name' => 'IFKG', 'city' => 'Göteborg', 'country' => 'Sverige', 'active' => 1, 'rider_count' => 67],
        ['id' => 6, 'name' => 'Cykelklubben Borås', 'short_name' => 'CKB', 'city' => 'Borås', 'country' => 'Sverige', 'active' => 1, 'rider_count' => 29],
    ];

    // Filter by search
    if ($search) {
        $clubs = array_filter($all_clubs, function($c) use ($search) {
            return stripos($c['name'], $search) !== false || stripos($c['city'], $search) !== false;
        });
        $clubs = array_values($clubs);
    } else {
        $clubs = $all_clubs;
    }
} else {
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
            LEFT JOIN cyclists c ON cl.id = c.club_id AND c.active = 1
            $whereClause
            GROUP BY cl.id
            ORDER BY cl.name";

    $clubs = $db->getAll($sql, $params);
}

$pageTitle = 'Klubbar';
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
                    <i data-lucide="building"></i>
                    Klubbar
                </h1>
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
                                    placeholder="Sök efter klubbnamn..."
                                    value="<?= h($search) ?>"
                                >
                            </div>
                        </div>
                        <button type="submit" class="gs-btn gs-btn-primary">
                            <i data-lucide="search"></i>
                            Sök
                        </button>
                        <?php if ($search): ?>
                            <a href="/admin/clubs.php" class="gs-btn gs-btn-outline">
                                Rensa
                            </a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>

            <!-- Stats -->
            <div class="gs-grid gs-grid-cols-1 gs-md-grid-cols-3 gs-gap-lg gs-mb-lg">
                <div class="gs-stat-card">
                    <i data-lucide="building" class="gs-icon-lg gs-text-primary gs-mb-md"></i>
                    <div class="gs-stat-number"><?= count($clubs) ?></div>
                    <div class="gs-stat-label">Totalt klubbar</div>
                </div>
                <div class="gs-stat-card">
                    <i data-lucide="check-circle" class="gs-icon-lg gs-text-success gs-mb-md"></i>
                    <div class="gs-stat-number">
                        <?= count(array_filter($clubs, fn($c) => $c['active'] == 1)) ?>
                    </div>
                    <div class="gs-stat-label">Aktiva</div>
                </div>
                <div class="gs-stat-card">
                    <i data-lucide="users" class="gs-icon-lg gs-text-accent gs-mb-md"></i>
                    <div class="gs-stat-number">
                        <?= array_sum(array_column($clubs, 'rider_count')) ?>
                    </div>
                    <div class="gs-stat-label">Totalt medlemmar</div>
                </div>
            </div>

            <!-- Clubs Table -->
            <?php if (empty($clubs)): ?>
                <div class="gs-card">
                    <div class="gs-card-content gs-text-center gs-py-xl">
                        <i data-lucide="building-2" style="width: 64px; height: 64px; color: var(--gs-text-secondary); margin-bottom: var(--gs-space-md);"></i>
                        <p class="gs-text-secondary">Inga klubbar hittades</p>
                    </div>
                </div>
            <?php else: ?>
                <div class="gs-card">
                    <div class="gs-table-responsive">
                        <table class="gs-table">
                            <thead>
                                <tr>
                                    <th>
                                        <i data-lucide="building"></i>
                                        Namn
                                    </th>
                                    <th>Förkortning</th>
                                    <th>
                                        <i data-lucide="map-pin"></i>
                                        Stad
                                    </th>
                                    <th>
                                        <i data-lucide="globe"></i>
                                        Land
                                    </th>
                                    <th>
                                        <i data-lucide="users"></i>
                                        Medlemmar
                                    </th>
                                    <th>Status</th>
                                    <th style="width: 150px; text-align: right;">Åtgärder</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($clubs as $club): ?>
                                    <tr>
                                        <td>
                                            <strong><?= h($club['name']) ?></strong>
                                        </td>
                                        <td>
                                            <span class="gs-badge gs-badge-primary">
                                                <?= h($club['short_name'] ?? substr($club['name'], 0, 3)) ?>
                                            </span>
                                        </td>
                                        <td class="gs-text-secondary"><?= h($club['city'] ?? '-') ?></td>
                                        <td>
                                            <span class="gs-text-secondary">
                                                <?= h($club['country'] ?? 'Sverige') ?>
                                            </span>
                                        </td>
                                        <td class="gs-text-center">
                                            <strong class="gs-text-primary"><?= $club['rider_count'] ?></strong>
                                        </td>
                                        <td>
                                            <?php if ($club['active']): ?>
                                                <span class="gs-badge gs-badge-success">
                                                    <i data-lucide="check-circle"></i>
                                                    Aktiv
                                                </span>
                                            <?php else: ?>
                                                <span class="gs-badge gs-badge-secondary">Inaktiv</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align: right;">
                                            <span class="gs-badge gs-badge-secondary">Demo</span>
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

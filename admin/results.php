<?php
require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();
$current_admin = get_current_admin();

// Handle filters
$event_id = $_GET['event_id'] ?? '';
$category_id = $_GET['category_id'] ?? '';
$search = $_GET['search'] ?? '';

$where = [];
$params = [];

if ($event_id) {
    $where[] = "r.event_id = ?";
    $params[] = $event_id;
}

if ($category_id) {
    $where[] = "r.category_id = ?";
    $params[] = $category_id;
}

if ($search) {
    $where[] = "(CONCAT(c.firstname, ' ', c.lastname) LIKE ? OR c.license_number LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Get results with all related data
$sql = "SELECT
            r.id,
            r.position,
            r.bib_number,
            r.finish_time,
            r.status,
            r.points,
            e.name as event_name,
            e.event_date,
            e.id as event_id,
            CONCAT(c.firstname, ' ', c.lastname) as rider_name,
            c.id as rider_id,
            c.birth_year,
            cl.name as club_name,
            cat.name as category_name,
            cat.id as category_id
        FROM results r
        JOIN events e ON r.event_id = e.id
        JOIN cyclists c ON r.cyclist_id = c.id
        LEFT JOIN clubs cl ON c.club_id = cl.id
        LEFT JOIN categories cat ON r.category_id = cat.id
        $whereClause
        ORDER BY e.event_date DESC, r.position ASC
        LIMIT 200";

$results = $db->getAll($sql, $params);

// Get events for filter
$events = $db->getAll("SELECT id, name, event_date FROM events ORDER BY event_date DESC LIMIT 50");

// Get categories for filter
$categories = $db->getAll("SELECT id, name FROM categories ORDER BY name");

$pageTitle = 'Resultat';
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
                    <i data-lucide="trophy"></i>
                    Resultat
                </h1>
                <a href="/admin/result-add.php" class="gs-btn gs-btn-primary">
                    <i data-lucide="plus"></i>
                    Lägg till resultat
                </a>
            </div>

            <!-- Filters -->
            <div class="gs-card gs-mb-lg">
                <div class="gs-card-content">
                    <form method="GET" class="gs-grid gs-grid-cols-1 gs-md-grid-cols-4 gs-gap-md">
                        <div>
                            <label for="event_id" class="gs-label">
                                <i data-lucide="calendar"></i>
                                Tävling
                            </label>
                            <select id="event_id" name="event_id" class="gs-input">
                                <option value="">Alla tävlingar</option>
                                <?php foreach ($events as $event): ?>
                                    <option value="<?= $event['id'] ?>" <?= $event_id == $event['id'] ? 'selected' : '' ?>>
                                        <?= h($event['name']) ?> (<?= formatDate($event['event_date'], 'Y-m-d') ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="category_id" class="gs-label">
                                <i data-lucide="tag"></i>
                                Kategori
                            </label>
                            <select id="category_id" name="category_id" class="gs-input">
                                <option value="">Alla kategorier</option>
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?= $category['id'] ?>" <?= $category_id == $category['id'] ? 'selected' : '' ?>>
                                        <?= h($category['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="search" class="gs-label">
                                <i data-lucide="search"></i>
                                Sök deltagare
                            </label>
                            <input
                                type="text"
                                id="search"
                                name="search"
                                class="gs-input"
                                placeholder="Namn eller licens..."
                                value="<?= h($search) ?>"
                            >
                        </div>
                        <div style="display: flex; align-items: flex-end; gap: var(--gs-space-sm);">
                            <button type="submit" class="gs-btn gs-btn-primary gs-flex-1">
                                <i data-lucide="filter"></i>
                                Filtrera
                            </button>
                            <?php if ($event_id || $category_id || $search): ?>
                                <a href="/admin/results.php" class="gs-btn gs-btn-outline">
                                    Rensa
                                </a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Stats -->
            <div class="gs-grid gs-grid-cols-1 gs-md-grid-cols-4 gs-gap-lg gs-mb-lg">
                <div class="gs-stat-card">
                    <i data-lucide="list" class="gs-icon-lg gs-text-primary gs-mb-md"></i>
                    <div class="gs-stat-number"><?= count($results) ?></div>
                    <div class="gs-stat-label">Resultat</div>
                </div>
                <div class="gs-stat-card">
                    <i data-lucide="calendar" class="gs-icon-lg gs-text-accent gs-mb-md"></i>
                    <div class="gs-stat-number">
                        <?= count(array_unique(array_column($results, 'event_id'))) ?>
                    </div>
                    <div class="gs-stat-label">Tävlingar</div>
                </div>
                <div class="gs-stat-card">
                    <i data-lucide="users" class="gs-icon-lg gs-text-success gs-mb-md"></i>
                    <div class="gs-stat-number">
                        <?= count(array_unique(array_column($results, 'rider_id'))) ?>
                    </div>
                    <div class="gs-stat-label">Unika deltagare</div>
                </div>
                <div class="gs-stat-card">
                    <i data-lucide="award" class="gs-icon-lg gs-text-warning gs-mb-md"></i>
                    <div class="gs-stat-number">
                        <?= count(array_filter($results, fn($r) => $r['position'] >= 1 && $r['position'] <= 3)) ?>
                    </div>
                    <div class="gs-stat-label">Pallplatser</div>
                </div>
            </div>

            <!-- Results Table -->
            <?php if (empty($results)): ?>
                <div class="gs-card">
                    <div class="gs-card-content gs-text-center gs-py-xl">
                        <i data-lucide="trophy" style="width: 64px; height: 64px; color: var(--gs-text-secondary); margin-bottom: var(--gs-space-md);"></i>
                        <p class="gs-text-secondary">Inga resultat hittades</p>
                    </div>
                </div>
            <?php else: ?>
                <div class="gs-card">
                    <div class="gs-table-responsive">
                        <table class="gs-table">
                            <thead>
                                <tr>
                                    <th style="width: 60px;">
                                        <i data-lucide="hash"></i>
                                        Plac
                                    </th>
                                    <th>
                                        <i data-lucide="calendar"></i>
                                        Tävling
                                    </th>
                                    <th>
                                        <i data-lucide="user"></i>
                                        Deltagare
                                    </th>
                                    <th>
                                        <i data-lucide="building"></i>
                                        Klubb
                                    </th>
                                    <th>
                                        <i data-lucide="tag"></i>
                                        Kategori
                                    </th>
                                    <th>
                                        <i data-lucide="clock"></i>
                                        Tid
                                    </th>
                                    <th>
                                        <i data-lucide="star"></i>
                                        Poäng
                                    </th>
                                    <th style="width: 120px; text-align: right;">Åtgärder</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($results as $result): ?>
                                    <tr class="<?= $result['position'] >= 1 && $result['position'] <= 3 ? 'gs-podium-' . $result['position'] : '' ?>">
                                        <td style="font-weight: 700;">
                                            <?php if ($result['position']): ?>
                                                <span class="gs-text-primary"><?= $result['position'] ?></span>
                                            <?php else: ?>
                                                <span class="gs-text-secondary">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <strong><?= h($result['event_name']) ?></strong><br>
                                            <span class="gs-text-secondary gs-text-xs">
                                                <?= formatDate($result['event_date'], 'd M Y') ?>
                                            </span>
                                        </td>
                                        <td>
                                            <strong><?= h($result['rider_name']) ?></strong>
                                            <?php if ($result['birth_year']): ?>
                                                <span class="gs-text-secondary gs-text-xs">
                                                    (<?= $result['birth_year'] ?>)
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="gs-text-secondary gs-text-sm">
                                            <?= h($result['club_name'] ?? '-') ?>
                                        </td>
                                        <td>
                                            <span class="gs-badge gs-badge-primary gs-text-xs">
                                                <?= h($result['category_name'] ?? '-') ?>
                                            </span>
                                        </td>
                                        <td class="gs-text-secondary" style="font-family: monospace;">
                                            <?= formatTime($result['finish_time']) ?>
                                        </td>
                                        <td class="gs-text-center">
                                            <?php if ($result['points']): ?>
                                                <strong class="gs-text-primary"><?= $result['points'] ?></strong>
                                            <?php else: ?>
                                                <span class="gs-text-secondary">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align: right;">
                                            <div class="gs-flex gs-gap-xs gs-justify-end">
                                                <a href="/admin/result-edit.php?id=<?= $result['id'] ?>" class="gs-btn gs-btn-sm gs-btn-outline" title="Redigera">
                                                    <i data-lucide="edit"></i>
                                                </a>
                                                <button
                                                    onclick="if(confirm('Är du säker på att du vill radera detta resultat?')) location.href='/admin/result-delete.php?id=<?= $result['id'] ?>'"
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

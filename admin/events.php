<?php
require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();
$current_admin = get_current_admin();

// Handle filters
$status = $_GET['status'] ?? '';
$year = $_GET['year'] ?? date('Y');

$where = ["YEAR(event_date) = ?"];
$params = [$year];

if ($status) {
    $where[] = "status = ?";
    $params[] = $status;
}

$whereClause = 'WHERE ' . implode(' AND ', $where);

// Get events with participant count
$sql = "SELECT
            e.id,
            e.name,
            e.event_date,
            e.location,
            e.event_type,
            e.status,
            e.distance,
            COUNT(DISTINCT r.id) as participant_count
        FROM events e
        LEFT JOIN results r ON e.id = r.event_id
        $whereClause
        GROUP BY e.id
        ORDER BY e.event_date DESC";

$events = $db->getAll($sql, $params);

// Get available years
$years = $db->getAll("SELECT DISTINCT YEAR(event_date) as year FROM events ORDER BY year DESC");

$pageTitle = 'Tävlingar';
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
                    <i data-lucide="calendar"></i>
                    Tävlingar
                </h1>
                <a href="/admin/event-add.php" class="gs-btn gs-btn-primary">
                    <i data-lucide="plus"></i>
                    Ny tävling
                </a>
            </div>

            <!-- Filters -->
            <div class="gs-card gs-mb-lg">
                <div class="gs-card-content">
                    <form method="GET" class="gs-flex gs-gap-md gs-items-end">
                        <div>
                            <label for="year" class="gs-label">
                                <i data-lucide="calendar"></i>
                                År
                            </label>
                            <select id="year" name="year" class="gs-input" style="max-width: 150px;">
                                <?php foreach ($years as $y): ?>
                                    <option value="<?= $y['year'] ?>" <?= $y['year'] == $year ? 'selected' : '' ?>>
                                        <?= $y['year'] ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="status" class="gs-label">
                                <i data-lucide="filter"></i>
                                Status
                            </label>
                            <select id="status" name="status" class="gs-input" style="max-width: 200px;">
                                <option value="">Alla</option>
                                <option value="upcoming" <?= $status === 'upcoming' ? 'selected' : '' ?>>Kommande</option>
                                <option value="ongoing" <?= $status === 'ongoing' ? 'selected' : '' ?>>Pågående</option>
                                <option value="completed" <?= $status === 'completed' ? 'selected' : '' ?>>Avslutad</option>
                                <option value="cancelled" <?= $status === 'cancelled' ? 'selected' : '' ?>>Inställd</option>
                            </select>
                        </div>
                        <button type="submit" class="gs-btn gs-btn-primary">
                            <i data-lucide="filter"></i>
                            Filtrera
                        </button>
                        <?php if ($status || $year != date('Y')): ?>
                            <a href="/admin/events.php" class="gs-btn gs-btn-outline">
                                Rensa
                            </a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>

            <!-- Stats -->
            <div class="gs-grid gs-grid-cols-1 gs-md-grid-cols-4 gs-gap-lg gs-mb-lg">
                <div class="gs-stat-card">
                    <i data-lucide="calendar" class="gs-icon-lg gs-text-primary gs-mb-md"></i>
                    <div class="gs-stat-number"><?= count($events) ?></div>
                    <div class="gs-stat-label">Totalt tävlingar</div>
                </div>
                <div class="gs-stat-card">
                    <i data-lucide="clock" class="gs-icon-lg gs-text-warning gs-mb-md"></i>
                    <div class="gs-stat-number">
                        <?= count(array_filter($events, fn($e) => $e['status'] === 'upcoming')) ?>
                    </div>
                    <div class="gs-stat-label">Kommande</div>
                </div>
                <div class="gs-stat-card">
                    <i data-lucide="check-circle" class="gs-icon-lg gs-text-success gs-mb-md"></i>
                    <div class="gs-stat-number">
                        <?= count(array_filter($events, fn($e) => $e['status'] === 'completed')) ?>
                    </div>
                    <div class="gs-stat-label">Avslutade</div>
                </div>
                <div class="gs-stat-card">
                    <i data-lucide="users" class="gs-icon-lg gs-text-accent gs-mb-md"></i>
                    <div class="gs-stat-number">
                        <?= array_sum(array_column($events, 'participant_count')) ?>
                    </div>
                    <div class="gs-stat-label">Totalt deltagare</div>
                </div>
            </div>

            <!-- Events Table -->
            <?php if (empty($events)): ?>
                <div class="gs-card">
                    <div class="gs-card-content gs-text-center gs-py-xl">
                        <i data-lucide="calendar-x" style="width: 64px; height: 64px; color: var(--gs-text-secondary); margin-bottom: var(--gs-space-md);"></i>
                        <p class="gs-text-secondary">Inga tävlingar hittades</p>
                    </div>
                </div>
            <?php else: ?>
                <div class="gs-card">
                    <div class="gs-table-responsive">
                        <table class="gs-table">
                            <thead>
                                <tr>
                                    <th>
                                        <i data-lucide="calendar"></i>
                                        Namn
                                    </th>
                                    <th>Datum</th>
                                    <th>
                                        <i data-lucide="map-pin"></i>
                                        Plats
                                    </th>
                                    <th>Disciplin</th>
                                    <th>Status</th>
                                    <th>
                                        <i data-lucide="users"></i>
                                        Deltagare
                                    </th>
                                    <th style="width: 150px; text-align: right;">Åtgärder</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($events as $event): ?>
                                    <tr>
                                        <td>
                                            <strong><?= h($event['name']) ?></strong>
                                            <?php if ($event['distance']): ?>
                                                <br>
                                                <span class="gs-text-secondary gs-text-xs">
                                                    <i data-lucide="route"></i>
                                                    <?= $event['distance'] ?> km
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="gs-text-secondary" style="font-family: monospace;">
                                                <?= formatDate($event['event_date'], 'd M Y') ?>
                                            </span>
                                        </td>
                                        <td><?= h($event['location']) ?></td>
                                        <td>
                                            <span class="gs-badge gs-badge-primary">
                                                <i data-lucide="flag"></i>
                                                <?= h(str_replace('_', ' ', $event['event_type'])) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php
                                            $statusMap = [
                                                'upcoming' => ['badge' => 'warning', 'icon' => 'clock', 'text' => 'Kommande'],
                                                'ongoing' => ['badge' => 'primary', 'icon' => 'play', 'text' => 'Pågående'],
                                                'completed' => ['badge' => 'success', 'icon' => 'check-circle', 'text' => 'Avslutad'],
                                                'cancelled' => ['badge' => 'secondary', 'icon' => 'x-circle', 'text' => 'Inställd']
                                            ];
                                            $statusInfo = $statusMap[$event['status']] ?? ['badge' => 'secondary', 'icon' => 'help-circle', 'text' => $event['status']];
                                            ?>
                                            <span class="gs-badge gs-badge-<?= $statusInfo['badge'] ?>">
                                                <i data-lucide="<?= $statusInfo['icon'] ?>"></i>
                                                <?= $statusInfo['text'] ?>
                                            </span>
                                        </td>
                                        <td class="gs-text-center">
                                            <strong class="gs-text-primary"><?= $event['participant_count'] ?></strong>
                                        </td>
                                        <td style="text-align: right;">
                                            <div class="gs-flex gs-gap-xs gs-justify-end">
                                                <a href="/admin/event-edit.php?id=<?= $event['id'] ?>" class="gs-btn gs-btn-sm gs-btn-outline" title="Redigera">
                                                    <i data-lucide="edit"></i>
                                                </a>
                                                <a href="/admin/event-results.php?id=<?= $event['id'] ?>" class="gs-btn gs-btn-sm gs-btn-outline" title="Resultat">
                                                    <i data-lucide="list"></i>
                                                </a>
                                                <button
                                                    onclick="if(confirm('Är du säker på att du vill radera denna tävling?')) location.href='/admin/event-delete.php?id=<?= $event['id'] ?>'"
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

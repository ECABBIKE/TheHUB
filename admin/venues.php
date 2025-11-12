<?php
require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();
$current_admin = get_current_admin();

// Demo mode check
$is_demo = ($db->getConnection() === null);

if ($is_demo) {
    // Demo venues
    $venues = [
        ['name' => 'Järvsö', 'event_count' => 3, 'first_event' => '2023-06-15', 'last_event' => '2025-06-15', 'event_types' => 'XC'],
        ['name' => 'Lindesberg', 'event_count' => 2, 'first_event' => '2024-07-01', 'last_event' => '2025-07-01', 'event_types' => 'XC'],
        ['name' => 'Mora', 'event_count' => 4, 'first_event' => '2022-08-10', 'last_event' => '2025-08-10', 'event_types' => 'Landsväg'],
        ['name' => 'Åre', 'event_count' => 5, 'first_event' => '2022-08-20', 'last_event' => '2024-08-20', 'event_types' => 'XC,Enduro'],
        ['name' => 'Motala', 'event_count' => 2, 'first_event' => '2023-06-15', 'last_event' => '2024-06-15', 'event_types' => 'Landsväg'],
    ];
} else {
    // Get unique venues from events (aggregated by location)
    $sql = "SELECT
                location as name,
                COUNT(DISTINCT id) as event_count,
                MIN(event_date) as first_event,
                MAX(event_date) as last_event,
                GROUP_CONCAT(DISTINCT event_type) as event_types
            FROM events
            WHERE location IS NOT NULL AND location != ''
            GROUP BY location
            ORDER BY location";

    $venues = $db->getAll($sql);
}

$pageTitle = 'Venues';
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
                    <i data-lucide="mountain"></i>
                    Venues
                </h1>
            </div>

            <!-- Info Alert -->
            <div class="gs-alert gs-alert-info gs-mb-lg">
                <i data-lucide="info"></i>
                <div>
                    <strong>Venues-lista</strong><br>
                    Detta är en aggregerad lista över alla platser där tävlingar har hållits.
                    Data sammanställs från tävlingarnas platsuppgifter.
                </div>
            </div>

            <!-- Stats -->
            <div class="gs-grid gs-grid-cols-1 gs-md-grid-cols-3 gs-gap-lg gs-mb-lg">
                <div class="gs-stat-card">
                    <i data-lucide="mountain" class="gs-icon-lg gs-text-primary gs-mb-md"></i>
                    <div class="gs-stat-number"><?= count($venues) ?></div>
                    <div class="gs-stat-label">Totalt venues</div>
                </div>
                <div class="gs-stat-card">
                    <i data-lucide="calendar" class="gs-icon-lg gs-text-accent gs-mb-md"></i>
                    <div class="gs-stat-number">
                        <?= array_sum(array_column($venues, 'event_count')) ?>
                    </div>
                    <div class="gs-stat-label">Totalt events</div>
                </div>
                <div class="gs-stat-card">
                    <i data-lucide="map-pin" class="gs-icon-lg gs-text-success gs-mb-md"></i>
                    <div class="gs-stat-number">
                        <?= count($venues) > 0 ? round(array_sum(array_column($venues, 'event_count')) / count($venues), 1) : 0 ?>
                    </div>
                    <div class="gs-stat-label">Snitt events/venue</div>
                </div>
            </div>

            <!-- Venues Table -->
            <?php if (empty($venues)): ?>
                <div class="gs-card">
                    <div class="gs-card-content gs-text-center gs-py-xl">
                        <i data-lucide="map" style="width: 64px; height: 64px; color: var(--gs-text-secondary); margin-bottom: var(--gs-space-md);"></i>
                        <p class="gs-text-secondary">Inga venues hittades</p>
                    </div>
                </div>
            <?php else: ?>
                <div class="gs-card">
                    <div class="gs-table-responsive">
                        <table class="gs-table">
                            <thead>
                                <tr>
                                    <th>
                                        <i data-lucide="mountain"></i>
                                        Namn/Plats
                                    </th>
                                    <th>
                                        <i data-lucide="flag"></i>
                                        Discipliner
                                    </th>
                                    <th>
                                        <i data-lucide="calendar"></i>
                                        Antal events
                                    </th>
                                    <th>
                                        <i data-lucide="calendar-clock"></i>
                                        Första event
                                    </th>
                                    <th>
                                        <i data-lucide="calendar-check"></i>
                                        Senaste event
                                    </th>
                                    <th style="width: 150px; text-align: right;">Åtgärder</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($venues as $venue): ?>
                                    <tr>
                                        <td>
                                            <strong><?= h($venue['name']) ?></strong>
                                        </td>
                                        <td>
                                            <?php
                                            $types = explode(',', $venue['event_types']);
                                            foreach (array_slice($types, 0, 3) as $type):
                                            ?>
                                                <span class="gs-badge gs-badge-primary gs-text-xs">
                                                    <?= h(str_replace('_', ' ', $type)) ?>
                                                </span>
                                            <?php endforeach; ?>
                                            <?php if (count($types) > 3): ?>
                                                <span class="gs-text-secondary gs-text-xs">+<?= count($types) - 3 ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="gs-text-center">
                                            <strong class="gs-text-primary"><?= $venue['event_count'] ?></strong>
                                        </td>
                                        <td class="gs-text-secondary gs-text-sm" style="font-family: monospace;">
                                            <?= formatDate($venue['first_event'], 'd M Y') ?>
                                        </td>
                                        <td class="gs-text-secondary gs-text-sm" style="font-family: monospace;">
                                            <?= formatDate($venue['last_event'], 'd M Y') ?>
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

<?php
require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();
$current_admin = get_current_admin();

// Demo mode check
$is_demo = ($db->getConnection() === null);

// Handle filters
$status = $_GET['status'] ?? '';
$year = $_GET['year'] ?? date('Y');

if ($is_demo) {
    // Demo events
    $all_events = [
        ['id' => 1, 'name' => 'GravitySeries Järvsö XC', 'event_date' => '2025-06-15', 'location' => 'Järvsö', 'event_type' => 'XC', 'status' => 'upcoming', 'distance' => '45 km', 'participant_count' => 145],
        ['id' => 2, 'name' => 'SM Lindesberg', 'event_date' => '2025-07-01', 'location' => 'Lindesberg', 'event_type' => 'XC', 'status' => 'upcoming', 'distance' => '38 km', 'participant_count' => 220],
        ['id' => 3, 'name' => 'Cykelvasan 90', 'event_date' => '2025-08-10', 'location' => 'Mora', 'event_type' => 'Landsväg', 'status' => 'upcoming', 'distance' => '90 km', 'participant_count' => 890],
        ['id' => 4, 'name' => 'GravitySeries Åre', 'event_date' => '2024-08-20', 'location' => 'Åre', 'event_type' => 'XC', 'status' => 'completed', 'distance' => '42 km', 'participant_count' => 156],
        ['id' => 5, 'name' => 'Vätternrundan', 'event_date' => '2024-06-15', 'location' => 'Motala', 'event_type' => 'Landsväg', 'status' => 'completed', 'distance' => '300 km', 'participant_count' => 1200],
    ];

    // Filter by status
    if ($status) {
        $events = array_filter($all_events, fn($e) => $e['status'] === $status);
    } else {
        $events = $all_events;
    }

    // Filter by year
    $events = array_filter($events, fn($e) => date('Y', strtotime($e['event_date'])) == $year);
    $events = array_values($events);

    // Available years
    $years = [
        ['year' => 2025],
        ['year' => 2024],
        ['year' => 2023],
    ];
} else {
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
}

$pageTitle = 'Tävlingar';
$pageType = 'admin';
include __DIR__ . '/../includes/layout-header.php';
?>

    <main class="gs-content-with-sidebar">
        <div class="gs-container">
            <!-- Header -->
            <div class="gs-flex gs-items-center gs-justify-between gs-mb-xl">
                <h1 class="gs-h1 gs-text-primary">
                    <i data-lucide="calendar"></i>
                    Tävlingar
                </h1>
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
<?php include __DIR__ . '/../includes/layout-footer.php'; ?>

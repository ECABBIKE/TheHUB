<?php
require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();
$current_admin = get_current_admin();

// Demo mode check
$is_demo = ($db->getConnection() === null);

if ($is_demo) {
    // Demo statistics
    $stats = [
        'total_events' => 12,
        'upcoming_events' => 5,
        'total_riders' => 342,
        'total_clubs' => 28,
        'total_results' => 1856,
        'this_month_events' => 2,
    ];

    // Demo recent events
    $recent_events = [
        ['id' => 1, 'name' => 'GravitySeries Järvsö XC', 'event_date' => '2025-06-15', 'location' => 'Järvsö', 'status' => 'upcoming', 'participant_count' => 145],
        ['id' => 2, 'name' => 'SM Lindesberg', 'event_date' => '2025-07-01', 'location' => 'Lindesberg', 'status' => 'upcoming', 'participant_count' => 220],
        ['id' => 3, 'name' => 'Cykelvasan 90', 'event_date' => '2025-08-10', 'location' => 'Mora', 'status' => 'upcoming', 'participant_count' => 890],
    ];

    // Demo recent results
    $recent_results = [
        ['id' => 1, 'position' => 1, 'event_name' => 'GravitySeries Järvsö XC', 'event_date' => '2025-06-15', 'rider_name' => 'Erik Andersson', 'category_name' => 'Elite Herr'],
        ['id' => 2, 'position' => 2, 'event_name' => 'GravitySeries Järvsö XC', 'event_date' => '2025-06-15', 'rider_name' => 'Anna Karlsson', 'category_name' => 'Elite Dam'],
        ['id' => 3, 'position' => 3, 'event_name' => 'GravitySeries Järvsö XC', 'event_date' => '2025-06-15', 'rider_name' => 'Johan Svensson', 'category_name' => 'Elite Herr'],
    ];
} else {
    // Get statistics from database
    $stats = [
        'total_events' => $db->getRow("SELECT COUNT(*) as count FROM events")['count'] ?? 0,
        'upcoming_events' => $db->getRow("SELECT COUNT(*) as count FROM events WHERE status = 'upcoming'")['count'] ?? 0,
        'total_riders' => $db->getRow("SELECT COUNT(*) as count FROM riders WHERE active = 1")['count'] ?? 0,
        'total_clubs' => $db->getRow("SELECT COUNT(*) as count FROM clubs WHERE active = 1")['count'] ?? 0,
        'total_results' => $db->getRow("SELECT COUNT(*) as count FROM results")['count'] ?? 0,
        'this_month_events' => $db->getRow("SELECT COUNT(*) as count FROM events WHERE MONTH(event_date) = MONTH(CURDATE()) AND YEAR(event_date) = YEAR(CURDATE())")['count'] ?? 0,
    ];

    // Get recent events
    $recent_events = $db->getAll(
        "SELECT e.id, e.name, e.event_date, e.location, e.status, COUNT(r.id) as participant_count
         FROM events e
         LEFT JOIN results r ON e.id = r.event_id
         GROUP BY e.id
         ORDER BY e.event_date DESC
         LIMIT 5"
    );

    // Get recent results
    $recent_results = $db->getAll(
        "SELECT
            r.id,
            r.position,
            e.name as event_name,
            e.event_date,
            CONCAT(c.firstname, ' ', c.lastname) as rider_name,
            cat.name as category_name
         FROM results r
         JOIN events e ON r.event_id = e.id
         JOIN riders c ON r.cyclist_id = c.id
         LEFT JOIN categories cat ON r.category_id = cat.id
         ORDER BY r.created_at DESC
         LIMIT 5"
    );
}

$pageTitle = 'Dashboard';
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
    <!-- Mobile Menu Toggle -->
    <button id="mobile-menu-toggle" class="gs-mobile-menu-toggle">
        <i data-lucide="menu"></i>
        <span>Meny</span>
    </button>

    <!-- Mobile Overlay -->
    <div id="mobile-overlay" class="gs-mobile-overlay"></div>

    <?php include __DIR__ . '/../includes/navigation.php'; ?>

    <main class="gs-content-with-sidebar">
        <div class="gs-container">
            <!-- Header -->
            <div class="gs-flex gs-items-center gs-justify-between gs-mb-xl">
                <div>
                    <h1 class="gs-h1 gs-text-primary">
                        <i data-lucide="layout-dashboard"></i>
                        Dashboard
                    </h1>
                    <p class="gs-text-secondary gs-mt-sm">
                        Välkommen tillbaka, <?= h($current_admin['name']) ?>!
                    </p>
                </div>
                <div class="gs-flex gs-gap-sm">
                    <a href="/admin/events.php" class="gs-btn gs-btn-primary">
                        <i data-lucide="plus"></i>
                        Ny tävling
                    </a>
                    <a href="/admin/import.php" class="gs-btn gs-btn-outline">
                        <i data-lucide="upload"></i>
                        Import
                    </a>
                </div>
            </div>

            <!-- Stats Grid -->
            <div class="gs-grid gs-grid-cols-1 gs-md-grid-cols-2 gs-lg-grid-cols-4 gs-gap-lg gs-mb-xl">
                <div class="gs-card">
                    <div class="gs-card-content gs-text-center">
                        <i data-lucide="calendar" class="gs-icon-xl gs-text-primary gs-mb-md"></i>
                        <div class="gs-stat-value"><?= number_format($stats['total_events']) ?></div>
                        <div class="gs-stat-label">Totalt tävlingar</div>
                        <div class="gs-mt-sm">
                            <span class="gs-badge gs-badge-warning gs-text-xs">
                                <i data-lucide="clock"></i>
                                <?= $stats['upcoming_events'] ?> kommande
                            </span>
                        </div>
                    </div>
                </div>

                <div class="gs-card">
                    <div class="gs-card-content gs-text-center">
                        <i data-lucide="users" class="gs-icon-xl gs-text-accent gs-mb-md"></i>
                        <div class="gs-stat-value"><?= number_format($stats['total_riders']) ?></div>
                        <div class="gs-stat-label">Aktiva deltagare</div>
                        <div class="gs-mt-sm">
                            <span class="gs-badge gs-badge-primary gs-text-xs">
                                <i data-lucide="building"></i>
                                <?= $stats['total_clubs'] ?> klubbar
                            </span>
                        </div>
                    </div>
                </div>

                <div class="gs-card">
                    <div class="gs-card-content gs-text-center">
                        <i data-lucide="trophy" class="gs-icon-xl gs-text-success gs-mb-md"></i>
                        <div class="gs-stat-value"><?= number_format($stats['total_results']) ?></div>
                        <div class="gs-stat-label">Resultat registrerade</div>
                        <div class="gs-mt-sm">
                            <span class="gs-badge gs-badge-success gs-text-xs">
                                <i data-lucide="trending-up"></i>
                                Alla tider
                            </span>
                        </div>
                    </div>
                </div>

                <div class="gs-card">
                    <div class="gs-card-content gs-text-center">
                        <i data-lucide="calendar-check" class="gs-icon-xl gs-text-primary gs-mb-md"></i>
                        <div class="gs-stat-value"><?= number_format($stats['this_month_events']) ?></div>
                        <div class="gs-stat-label">Events denna månad</div>
                        <div class="gs-mt-sm">
                            <span class="gs-badge gs-badge-primary gs-text-xs">
                                <i data-lucide="calendar"></i>
                                <?= date('F Y') ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recent Activity Grid -->
            <div class="gs-grid gs-grid-cols-1 gs-md-grid-cols-2 gs-gap-lg">
                <!-- Recent Events -->
                <div class="gs-card">
                    <div class="gs-card-header">
                        <h2 class="gs-h4 gs-text-primary">
                            <i data-lucide="calendar-clock"></i>
                            Senaste tävlingarna
                        </h2>
                    </div>
                    <div class="gs-card-content">
                        <?php if (empty($recent_events)): ?>
                            <p class="gs-text-secondary gs-text-center gs-py-lg">
                                <i data-lucide="calendar-x"></i>
                                Inga tävlingar ännu
                            </p>
                        <?php else: ?>
                            <div class="gs-list">
                                <?php foreach ($recent_events as $event): ?>
                                    <div class="gs-list-item">
                                        <div class="gs-flex gs-items-start gs-gap-md">
                                            <div class="gs-icon-wrapper" style="background-color: <?= $event['status'] === 'completed' ? 'var(--gs-success-light)' : 'var(--gs-primary-light)' ?>;">
                                                <i data-lucide="<?= $event['status'] === 'completed' ? 'check-circle' : 'calendar' ?>" class="<?= $event['status'] === 'completed' ? 'gs-text-success' : 'gs-text-primary' ?>"></i>
                                            </div>
                                            <div class="gs-flex-1">
                                                <strong class="gs-text-primary">
                                                    <a href="/admin/events.php?id=<?= $event['id'] ?>" style="text-decoration: none; color: inherit;">
                                                        <?= h($event['name']) ?>
                                                    </a>
                                                </strong>
                                                <div class="gs-text-secondary gs-text-sm gs-mt-xs">
                                                    <i data-lucide="map-pin"></i>
                                                    <?= h($event['location']) ?> • <?= formatDate($event['event_date'], 'd M Y') ?>
                                                </div>
                                                <?php if ($event['participant_count'] > 0): ?>
                                                    <div class="gs-mt-xs">
                                                        <span class="gs-badge gs-badge-primary gs-text-xs">
                                                            <i data-lucide="users"></i>
                                                            <?= $event['participant_count'] ?> deltagare
                                                        </span>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="gs-text-center gs-mt-lg">
                                <a href="/admin/events.php" class="gs-btn gs-btn-sm gs-btn-outline">
                                    <i data-lucide="list"></i>
                                    Visa alla tävlingar
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Recent Results -->
                <div class="gs-card">
                    <div class="gs-card-header">
                        <h2 class="gs-h4 gs-text-primary">
                            <i data-lucide="trophy"></i>
                            Senaste resultaten
                        </h2>
                    </div>
                    <div class="gs-card-content">
                        <?php if (empty($recent_results)): ?>
                            <p class="gs-text-secondary gs-text-center gs-py-lg">
                                <i data-lucide="trophy"></i>
                                Inga resultat ännu
                            </p>
                        <?php else: ?>
                            <div class="gs-list">
                                <?php foreach ($recent_results as $result): ?>
                                    <div class="gs-list-item">
                                        <div class="gs-flex gs-items-start gs-gap-md">
                                            <div class="gs-icon-wrapper" style="background-color: var(--gs-warning-light);">
                                                <?php if ($result['position'] == 1): ?>
                                                    <i data-lucide="medal" class="gs-text-warning"></i>
                                                <?php elseif ($result['position'] <= 3): ?>
                                                    <i data-lucide="award" class="gs-text-accent"></i>
                                                <?php else: ?>
                                                    <i data-lucide="flag" class="gs-text-primary"></i>
                                                <?php endif; ?>
                                            </div>
                                            <div class="gs-flex-1">
                                                <strong class="gs-text-primary"><?= h($result['rider_name']) ?></strong>
                                                <div class="gs-text-secondary gs-text-sm gs-mt-xs">
                                                    <?= h($result['event_name']) ?>
                                                </div>
                                                <div class="gs-mt-xs gs-flex gs-gap-xs">
                                                    <span class="gs-badge gs-badge-primary gs-text-xs">
                                                        #<?= $result['position'] ?>
                                                    </span>
                                                    <?php if ($result['category_name']): ?>
                                                        <span class="gs-badge gs-badge-secondary gs-text-xs">
                                                            <?= h($result['category_name']) ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="gs-text-center gs-mt-lg">
                                <a href="/admin/results.php" class="gs-btn gs-btn-sm gs-btn-outline">
                                    <i data-lucide="list"></i>
                                    Visa alla resultat
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>
    <!-- TheHUB JavaScript -->
    <script src="/assets/thehub.js"></script>
</body>
</html>

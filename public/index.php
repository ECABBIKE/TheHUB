<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$db = getDB();

// Get upcoming events
$upcomingEvents = $db->getAll(
    "SELECT id, name, event_date, location, event_type, status
     FROM events
     WHERE event_date >= CURDATE()
     ORDER BY event_date ASC
     LIMIT 6"
);

// Get recent completed events
$recentEvents = $db->getAll(
    "SELECT e.id, e.name, e.event_date, e.location, COUNT(r.id) as participant_count
     FROM events e
     LEFT JOIN results r ON e.id = r.event_id
     WHERE e.event_date < CURDATE()
     GROUP BY e.id
     ORDER BY e.event_date DESC
     LIMIT 6"
);

// Get statistics
$stats = [
    'total_cyclists' => $db->getRow("SELECT COUNT(*) as count FROM cyclists WHERE active = 1")['count'] ?? 0,
    'total_events' => $db->getRow("SELECT COUNT(*) as count FROM events")['count'] ?? 0,
    'total_clubs' => $db->getRow("SELECT COUNT(*) as count FROM clubs WHERE active = 1")['count'] ?? 0
];

$pageTitle = 'Hem';
?>
<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TheHUB - Plattform för cykeltävlingar</title>
    <link rel="stylesheet" href="/assets/gravityseries-theme.css">
</head>
<body>
    <!-- Navigation -->
    <nav class="gs-nav">
        <div class="gs-container">
            <ul class="gs-nav-list">
                <li><a href="/public/index.php" class="gs-nav-link active">
                    <i data-lucide="home"></i> Hem
                </a></li>
                <li><a href="/public/events.php" class="gs-nav-link">
                    <i data-lucide="calendar"></i> Tävlingar
                </a></li>
                <li><a href="/public/results.php" class="gs-nav-link">
                    <i data-lucide="trophy"></i> Resultat
                </a></li>
                <li style="margin-left: auto;"><a href="/admin/login.php" class="gs-btn gs-btn-sm gs-btn-primary">
                    <i data-lucide="log-in"></i> Admin
                </a></li>
            </ul>
        </div>
    </nav>

    <!-- Hero Section -->
    <div class="gs-container">
        <section class="gs-hero">
            <div class="gs-hero-content gs-text-center">
                <img src="https://gravityseries.se/wp-content/uploads/2024/03/Gravity-Series.png"
                     alt="GravitySeries"
                     class="gs-hero-logo gs-mb-md">
                <h1 class="gs-h1 gs-text-white gs-mb-xl">The HUB</h1>

                <!-- Stats -->
                <div class="gs-grid gs-grid-cols-1 gs-md-grid-cols-3 gs-gap-lg">
                    <div class="gs-stat-card">
                        <i data-lucide="users" class="gs-icon-lg gs-text-primary gs-mb-md"></i>
                        <div class="gs-stat-number"><?= number_format($stats['total_cyclists']) ?></div>
                        <div class="gs-stat-label">Cyklister</div>
                    </div>
                    <div class="gs-stat-card">
                        <i data-lucide="calendar" class="gs-icon-lg gs-text-accent gs-mb-md"></i>
                        <div class="gs-stat-number"><?= number_format($stats['total_events']) ?></div>
                        <div class="gs-stat-label">Tävlingar</div>
                    </div>
                    <div class="gs-stat-card">
                        <i data-lucide="building" class="gs-icon-lg gs-text-success gs-mb-md"></i>
                        <div class="gs-stat-number"><?= number_format($stats['total_clubs']) ?></div>
                        <div class="gs-stat-label">Klubbar</div>
                    </div>
                </div>
            </div>
        </section>
    </div>

    <!-- Main Content -->
    <main class="gs-container gs-py-xl">

        <?php if (!empty($upcomingEvents)): ?>
            <!-- Upcoming Events -->
            <section class="gs-mb-xl">
                <h2 class="gs-h2 gs-text-primary gs-mb-lg">
                    <i data-lucide="calendar-clock"></i>
                    Kommande tävlingar
                </h2>

                <div class="gs-grid gs-grid-cols-1 gs-md-grid-cols-2 gs-lg-grid-cols-3 gs-gap-lg gs-mb-lg">
                    <?php foreach ($upcomingEvents as $event): ?>
                        <div class="gs-event-card">
                            <div class="gs-event-header">
                                <div class="gs-event-date">
                                    <div class="gs-event-date-day"><?= formatDate($event['event_date'], 'd') ?></div>
                                    <div class="gs-event-date-month"><?= formatDate($event['event_date'], 'M') ?></div>
                                </div>
                                <span class="gs-badge gs-badge-warning">
                                    <i data-lucide="clock"></i>
                                    <?= h($event['status']) ?>
                                </span>
                            </div>
                            <div class="gs-event-content">
                                <h3 class="gs-event-title">
                                    <a href="/public/event.php?id=<?= $event['id'] ?>"><?= h($event['name']) ?></a>
                                </h3>
                                <p class="gs-event-icon">
                                    <i data-lucide="map-pin"></i>
                                    <?= h($event['location']) ?>
                                </p>
                                <p class="gs-event-icon">
                                    <i data-lucide="flag"></i>
                                    <?= h(str_replace('_', ' ', $event['event_type'])) ?>
                                </p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="gs-text-center">
                    <a href="/public/events.php" class="gs-btn gs-btn-primary gs-btn-lg">
                        <i data-lucide="list"></i>
                        Visa alla tävlingar
                    </a>
                </div>
            </section>
        <?php endif; ?>

        <?php if (!empty($recentEvents)): ?>
            <!-- Recent Results -->
            <section class="gs-mb-xl">
                <h2 class="gs-h2 gs-text-primary gs-mb-lg">
                    <i data-lucide="check-circle"></i>
                    Senaste resultaten
                </h2>

                <div class="gs-grid gs-grid-cols-1 gs-md-grid-cols-2 gs-lg-grid-cols-3 gs-gap-lg">
                    <?php foreach ($recentEvents as $event): ?>
                        <div class="gs-event-card">
                            <div class="gs-event-header">
                                <div class="gs-event-date" style="background-color: var(--gs-success);">
                                    <div class="gs-event-date-day"><?= formatDate($event['event_date'], 'd') ?></div>
                                    <div class="gs-event-date-month"><?= formatDate($event['event_date'], 'M') ?></div>
                                </div>
                                <span class="gs-badge gs-badge-success">
                                    <i data-lucide="check-circle"></i>
                                    Completed
                                </span>
                            </div>
                            <div class="gs-event-content">
                                <h3 class="gs-event-title">
                                    <a href="/public/results.php?event_id=<?= $event['id'] ?>"><?= h($event['name']) ?></a>
                                </h3>
                                <p class="gs-event-icon">
                                    <i data-lucide="map-pin"></i>
                                    <?= h($event['location']) ?>
                                </p>
                                <p class="gs-event-icon">
                                    <i data-lucide="users"></i>
                                    <?= $event['participant_count'] ?> deltagare
                                </p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

    </main>

    <!-- Footer -->
    <footer class="gs-bg-dark gs-text-white gs-py-xl gs-text-center">
        <div class="gs-container">
            <p>&copy; <?= date('Y') ?> TheHUB - Sveriges plattform för cykeltävlingar</p>
            <p class="gs-text-sm gs-text-secondary" style="margin-top: var(--gs-space-sm);">
                <i data-lucide="palette"></i>
                GravitySeries Design System + Lucide Icons
            </p>
        </div>
    </footer>

    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            lucide.createIcons();
        });
    </script>
</body>
</html>

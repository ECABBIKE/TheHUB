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
     LIMIT 10"
);

// Get recent completed events
$recentEvents = $db->getAll(
    "SELECT e.id, e.name, e.event_date, e.location, COUNT(r.id) as participant_count
     FROM events e
     LEFT JOIN results r ON e.id = r.event_id
     WHERE e.event_date < CURDATE()
     GROUP BY e.id
     ORDER BY e.event_date DESC
     LIMIT 10"
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
    <link rel="stylesheet" href="/public/css/style.css">
</head>
<body>
    <header class="site-header">
        <div class="container">
            <div class="header-content">
                <h1 class="site-title">TheHUB</h1>
                <p class="site-tagline">Sveriges centrala plattform för cykeltävlingar</p>
            </div>
            <nav class="main-nav">
                <a href="/public/index.php" class="active">Hem</a>
                <a href="/public/events.php">Tävlingar</a>
                <a href="/public/results.php">Resultat</a>
                <a href="/admin/login.php">Admin</a>
            </nav>
        </div>
    </header>

    <main class="container">
        <section class="hero">
            <h2>Välkommen till TheHUB</h2>
            <p>Hitta tävlingar, resultat och cyklistprofiler från hela Sverige</p>

            <div class="stats-row">
                <div class="stat-item">
                    <span class="stat-value"><?= number_format($stats['total_cyclists']) ?></span>
                    <span class="stat-label">Cyklister</span>
                </div>
                <div class="stat-item">
                    <span class="stat-value"><?= number_format($stats['total_events']) ?></span>
                    <span class="stat-label">Tävlingar</span>
                </div>
                <div class="stat-item">
                    <span class="stat-value"><?= number_format($stats['total_clubs']) ?></span>
                    <span class="stat-label">Klubbar</span>
                </div>
            </div>
        </section>

        <?php if (!empty($upcomingEvents)): ?>
            <section class="events-section">
                <h2>Kommande tävlingar</h2>
                <div class="event-list">
                    <?php foreach ($upcomingEvents as $event): ?>
                        <div class="event-card">
                            <div class="event-date">
                                <span class="day"><?= formatDate($event['event_date'], 'd') ?></span>
                                <span class="month"><?= formatDate($event['event_date'], 'M') ?></span>
                            </div>
                            <div class="event-info">
                                <h3><a href="/public/event.php?id=<?= $event['id'] ?>"><?= h($event['name']) ?></a></h3>
                                <p class="event-location"><?= h($event['location']) ?></p>
                                <span class="event-type"><?= h($event['event_type']) ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <a href="/public/events.php" class="btn btn-primary">Visa alla tävlingar</a>
            </section>
        <?php endif; ?>

        <?php if (!empty($recentEvents)): ?>
            <section class="events-section">
                <h2>Senaste resultaten</h2>
                <div class="event-list">
                    <?php foreach ($recentEvents as $event): ?>
                        <div class="event-card">
                            <div class="event-date completed">
                                <span class="day"><?= formatDate($event['event_date'], 'd') ?></span>
                                <span class="month"><?= formatDate($event['event_date'], 'M') ?></span>
                            </div>
                            <div class="event-info">
                                <h3><a href="/public/results.php?event_id=<?= $event['id'] ?>"><?= h($event['name']) ?></a></h3>
                                <p class="event-location"><?= h($event['location']) ?></p>
                                <p class="event-meta"><?= $event['participant_count'] ?> deltagare</p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>
    </main>

    <footer class="site-footer">
        <div class="container">
            <p>&copy; <?= date('Y') ?> TheHUB - Sveriges plattform för cykeltävlingar</p>
        </div>
    </footer>
</body>
</html>

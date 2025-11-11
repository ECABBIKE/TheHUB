<?php
/**
 * DEMO PAGE - Shows GravitySeries design with Lucide Icons
 */

// Mock data for demo
$stats = [
    'total_cyclists' => 3247,
    'total_events' => 67,
    'total_clubs' => 142,
    'total_photos' => 5432
];

// Mock upcoming events
$upcomingEvents = [
    [
        'id' => 1,
        'name' => 'Järvsö DH 2025',
        'event_date' => '2025-06-15',
        'location' => 'Järvsö',
        'event_type' => 'downhill',
        'status' => 'upcoming'
    ],
    [
        'id' => 2,
        'name' => 'Uppsala GP',
        'event_date' => '2025-07-20',
        'location' => 'Uppsala',
        'event_type' => 'road_race',
        'status' => 'upcoming'
    ],
    [
        'id' => 3,
        'name' => 'Dalarna Enduro',
        'event_date' => '2025-08-10',
        'location' => 'Sälen',
        'event_type' => 'enduro',
        'status' => 'upcoming'
    ],
    [
        'id' => 4,
        'name' => 'Stockholm Criterium',
        'event_date' => '2025-05-25',
        'location' => 'Stockholm',
        'event_type' => 'criterium',
        'status' => 'upcoming'
    ],
    [
        'id' => 5,
        'name' => 'Vätternrundan',
        'event_date' => '2025-06-07',
        'location' => 'Motala',
        'event_type' => 'road_race',
        'status' => 'upcoming'
    ],
    [
        'id' => 6,
        'name' => 'Lidingöloppet Cykel',
        'event_date' => '2025-09-15',
        'location' => 'Lidingö',
        'event_type' => 'mtb',
        'status' => 'upcoming'
    ]
];

// Mock completed events
$recentEvents = [
    [
        'id' => 7,
        'name' => 'Åre Bike Festival 2024',
        'event_date' => '2024-08-15',
        'location' => 'Åre',
        'participant_count' => 234
    ],
    [
        'id' => 8,
        'name' => 'Cykelvasan 2024',
        'event_date' => '2024-08-20',
        'location' => 'Sälen-Mora',
        'participant_count' => 1567
    ],
    [
        'id' => 9,
        'name' => 'Svenska Cupen DH #3',
        'event_date' => '2024-07-10',
        'location' => 'Järvsö',
        'participant_count' => 89
    ],
    [
        'id' => 10,
        'name' => 'SM Linjelopp 2024',
        'event_date' => '2024-06-22',
        'location' => 'Göteborg',
        'participant_count' => 156
    ],
    [
        'id' => 11,
        'name' => 'Gravel Grinder Dalarna',
        'event_date' => '2024-09-01',
        'location' => 'Falun',
        'participant_count' => 312
    ],
    [
        'id' => 12,
        'name' => 'XCO Cup Stockholm',
        'event_date' => '2024-05-18',
        'location' => 'Stockholm',
        'participant_count' => 178
    ]
];

function formatDate($date, $format = 'Y-m-d') {
    $dt = new DateTime($date);
    return $format === 'd' ? $dt->format('d') : ($format === 'M' ? $dt->format('M') : $dt->format($format));
}

function h($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TheHUB - Demo med GravitySeries Design + Lucide Icons</title>
    <link rel="stylesheet" href="/assets/gravityseries-theme.css">
</head>
<body>
    <!-- Navigation -->
    <nav class="gs-nav">
        <div class="gs-container">
            <ul class="gs-nav-list">
                <li><a href="/demo.php" class="gs-nav-link active">
                    <i data-lucide="home"></i> Hem
                </a></li>
                <li><a href="/demo-events.php" class="gs-nav-link">
                    <i data-lucide="calendar"></i> Tävlingar
                </a></li>
                <li><a href="#" class="gs-nav-link">
                    <i data-lucide="trophy"></i> Serier
                </a></li>
                <li><a href="#" class="gs-nav-link">
                    <i data-lucide="users"></i> Deltagare
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
                <h1 class="gs-h1 gs-text-white gs-mb-md">TheHUB</h1>
                <p class="gs-text-lg gs-text-white gs-mb-xl">Sveriges centrala plattform för cykeltävlingar</p>

                <!-- Stats with Icons -->
                <div class="gs-grid gs-grid-cols-1 gs-md-grid-cols-2 gs-lg-grid-cols-4 gs-gap-lg">
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
                    <div class="gs-stat-card">
                        <i data-lucide="image" class="gs-icon-lg gs-text-primary gs-mb-md"></i>
                        <div class="gs-stat-number"><?= number_format($stats['total_photos']) ?></div>
                        <div class="gs-stat-label">Foton</div>
                    </div>
                </div>
            </div>
        </section>
    </div>

    <!-- Main Content -->
    <main class="gs-container gs-py-xl">

        <!-- Upcoming Events -->
        <section class="gs-mb-xl">
            <div class="gs-flex gs-justify-between gs-items-center gs-mb-lg">
                <h2 class="gs-h2 gs-text-primary">
                    <i data-lucide="calendar-clock"></i>
                    Kommande tävlingar
                </h2>
                <button class="gs-btn gs-btn-primary">
                    <i data-lucide="plus"></i>
                    Ny tävling
                </button>
            </div>

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
                                <a href="#"><?= h($event['name']) ?></a>
                            </h3>
                            <p class="gs-event-icon">
                                <i data-lucide="map-pin"></i>
                                <?= h($event['location']) ?>
                            </p>
                            <p class="gs-event-icon">
                                <i data-lucide="flag"></i>
                                <?= h(str_replace('_', ' ', $event['event_type'])) ?>
                            </p>
                            <a href="#" class="gs-btn gs-btn-sm gs-btn-primary gs-w-full gs-mt-lg">
                                <i data-lucide="arrow-right"></i>
                                Se detaljer
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="gs-text-center">
                <a href="/demo-events.php" class="gs-btn gs-btn-primary gs-btn-lg">
                    <i data-lucide="list"></i>
                    Visa alla tävlingar
                </a>
            </div>
        </section>

        <!-- Recent Results -->
        <section class="gs-mb-xl">
            <div class="gs-flex gs-justify-between gs-items-center gs-mb-lg">
                <h2 class="gs-h2 gs-text-primary">
                    <i data-lucide="check-circle"></i>
                    Senaste resultaten
                </h2>
                <button class="gs-btn gs-btn-accent">
                    <i data-lucide="download"></i>
                    Exportera
                </button>
            </div>

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
                                <a href="#"><?= h($event['name']) ?></a>
                            </h3>
                            <p class="gs-event-icon">
                                <i data-lucide="map-pin"></i>
                                <?= h($event['location']) ?>
                            </p>
                            <div class="gs-stats-row gs-mt-md">
                                <div class="gs-stat-item">
                                    <i data-lucide="users"></i>
                                    <span><?= $event['participant_count'] ?> deltagare</span>
                                </div>
                            </div>
                            <a href="#" class="gs-btn gs-btn-sm gs-btn-outline gs-w-full gs-mt-lg">
                                <i data-lucide="eye"></i>
                                Se resultat
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

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

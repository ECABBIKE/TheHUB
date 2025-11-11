<?php
/**
 * DEMO EVENTS PAGE - Shows event list with GravitySeries design
 */

// Mock events data
$events = [
    [
        'id' => 1,
        'name' => 'Järvsö DH 2025',
        'event_date' => '2025-06-15',
        'location' => 'Järvsö',
        'event_type' => 'downhill',
        'status' => 'upcoming',
        'participant_count' => 0
    ],
    [
        'id' => 2,
        'name' => 'Uppsala GP',
        'event_date' => '2025-07-20',
        'location' => 'Uppsala',
        'event_type' => 'road_race',
        'status' => 'upcoming',
        'participant_count' => 0
    ],
    [
        'id' => 3,
        'name' => 'Dalarna Enduro',
        'event_date' => '2025-08-10',
        'location' => 'Sälen',
        'event_type' => 'enduro',
        'status' => 'upcoming',
        'participant_count' => 0
    ],
    [
        'id' => 4,
        'name' => 'Åre Bike Festival 2024',
        'event_date' => '2024-08-15',
        'location' => 'Åre',
        'event_type' => 'downhill',
        'status' => 'completed',
        'participant_count' => 234
    ],
    [
        'id' => 5,
        'name' => 'Cykelvasan 2024',
        'event_date' => '2024-08-20',
        'location' => 'Sälen-Mora',
        'event_type' => 'road_race',
        'status' => 'completed',
        'participant_count' => 1567
    ],
    [
        'id' => 6,
        'name' => 'Svenska Cupen DH #3',
        'event_date' => '2024-07-10',
        'location' => 'Järvsö',
        'event_type' => 'downhill',
        'status' => 'completed',
        'participant_count' => 89
    ],
    [
        'id' => 7,
        'name' => 'SM Linjelopp 2024',
        'event_date' => '2024-06-22',
        'location' => 'Göteborg',
        'event_type' => 'road_race',
        'status' => 'completed',
        'participant_count' => 156
    ],
    [
        'id' => 8,
        'name' => 'Gravel Grinder Dalarna',
        'event_date' => '2024-09-01',
        'location' => 'Falun',
        'event_type' => 'gravel',
        'status' => 'completed',
        'participant_count' => 312
    ],
    [
        'id' => 9,
        'name' => 'XCO Cup Stockholm',
        'event_date' => '2024-05-18',
        'location' => 'Stockholm',
        'event_type' => 'xco',
        'status' => 'completed',
        'participant_count' => 178
    ],
    [
        'id' => 10,
        'name' => 'Stockholm Criterium',
        'event_date' => '2025-05-25',
        'location' => 'Stockholm',
        'event_type' => 'criterium',
        'status' => 'upcoming',
        'participant_count' => 0
    ],
    [
        'id' => 11,
        'name' => 'Vätternrundan',
        'event_date' => '2025-06-07',
        'location' => 'Motala',
        'event_type' => 'road_race',
        'status' => 'upcoming',
        'participant_count' => 0
    ],
    [
        'id' => 12,
        'name' => 'Lidingöloppet Cykel',
        'event_date' => '2025-09-15',
        'location' => 'Lidingö',
        'event_type' => 'mtb',
        'status' => 'upcoming',
        'participant_count' => 0
    ]
];

$year = 2024;
$years = [
    ['year' => 2025],
    ['year' => 2024],
    ['year' => 2023],
    ['year' => 2022]
];

function formatDate($date, $format = 'Y-m-d') {
    $dt = new DateTime($date);
    return $format === 'd' ? $dt->format('d') : ($format === 'M Y' ? $dt->format('M Y') : $dt->format($format));
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
    <title>Tävlingar - TheHUB Demo</title>
    <link rel="stylesheet" href="/assets/gravityseries-theme.css">
</head>
<body>
    <!-- Navigation -->
    <nav class="gs-nav">
        <div class="gs-container">
            <ul class="gs-nav-list">
                <li><a href="/demo.php" class="gs-nav-link">
                    <i data-lucide="home"></i> Hem
                </a></li>
                <li><a href="/demo-events.php" class="gs-nav-link active">
                    <i data-lucide="calendar"></i> Tävlingar
                </a></li>
                <li><a href="#" class="gs-nav-link">
                    <i data-lucide="trophy"></i> Resultat
                </a></li>
                <li style="margin-left: auto;"><a href="/admin/login.php" class="gs-btn gs-btn-sm gs-btn-primary">
                    <i data-lucide="log-in"></i> Admin
                </a></li>
            </ul>
        </div>
    </nav>

    <main class="gs-container gs-py-xl">
        <h1 class="gs-h1 gs-text-primary gs-mb-lg">Tävlingar</h1>

        <!-- Filters -->
        <div class="gs-card gs-mb-lg">
            <div class="gs-card-content">
                <div class="gs-flex gs-items-center gs-gap-md">
                    <i data-lucide="filter"></i>
                    <label for="year" class="gs-label" style="margin-bottom: 0;">År:</label>
                    <select id="year" class="gs-input" style="max-width: 200px;">
                        <?php foreach ($years as $y): ?>
                            <option value="<?= $y['year'] ?>" <?= $y['year'] == $year ? 'selected' : '' ?>>
                                <?= $y['year'] ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <span class="gs-text-secondary gs-text-sm">
                        <i data-lucide="list"></i>
                        Totalt: <?= count($events) ?> tävlingar
                    </span>
                </div>
            </div>
        </div>

        <!-- Events Grid -->
        <div class="gs-grid gs-grid-cols-1 gs-md-grid-cols-2 gs-lg-grid-cols-3 gs-gap-lg gs-mb-lg">
            <?php foreach ($events as $event): ?>
                <div class="gs-event-card">
                    <div class="gs-event-header">
                        <div class="gs-event-date" style="background-color: <?= $event['status'] === 'completed' ? 'var(--gs-success)' : 'var(--gs-primary)' ?>;">
                            <div class="gs-event-date-day"><?= formatDate($event['event_date'], 'd') ?></div>
                            <div class="gs-event-date-month"><?= formatDate($event['event_date'], 'M Y') ?></div>
                        </div>
                        <span class="gs-badge gs-badge-<?= $event['status'] === 'completed' ? 'success' : 'warning' ?>">
                            <i data-lucide="<?= $event['status'] === 'completed' ? 'check-circle' : 'clock' ?>"></i>
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
                        <?php if ($event['participant_count'] > 0): ?>
                            <p class="gs-event-icon gs-text-primary" style="margin-top: var(--gs-space-sm);">
                                <i data-lucide="users"></i>
                                <?= $event['participant_count'] ?> deltagare
                            </p>
                        <?php endif; ?>
                        <a href="#" class="gs-btn gs-btn-sm gs-btn-primary gs-w-full gs-mt-lg">
                            <i data-lucide="eye"></i>
                            Visa resultat
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Pagination Demo -->
        <div class="gs-flex gs-items-center gs-justify-between gs-gap-md">
            <a href="#" class="gs-btn gs-btn-outline">
                <i data-lucide="chevron-left"></i>
                Föregående
            </a>
            <span class="gs-text-secondary">Sida 1 av 3</span>
            <a href="#" class="gs-btn gs-btn-outline">
                Nästa
                <i data-lucide="chevron-right"></i>
            </a>
        </div>
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

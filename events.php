<?php
require_once __DIR__ . '/config.php';

$pageTitle = 'Tävlingar';
?>
<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($pageTitle) ?> - TheHUB</title>
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

    <?php include __DIR__ . '/includes/navigation.php'; ?>

    <main class="gs-content-with-sidebar">
        <div class="gs-container">
            <h1 class="gs-h1 gs-text-primary gs-mb-lg">
                <i data-lucide="calendar"></i>
                Tävlingar
            </h1>

            <!-- Info Alert -->
            <div class="gs-alert gs-alert-info gs-mb-lg">
                <i data-lucide="info"></i>
                <div>
                    <strong>Demo-läge</strong><br>
                    Denna sida visar demo-data. Anslut databasen för att se riktiga tävlingar.
                </div>
            </div>

            <!-- Events Grid -->
            <div class="gs-grid gs-grid-cols-1 gs-md-grid-cols-2 gs-lg-grid-cols-3 gs-gap-lg">
                <?php
                // Demo events
                $demo_events = [
                    ['name' => 'GravitySeries Järvsö XC', 'date' => '2025-06-15', 'location' => 'Järvsö', 'status' => 'upcoming'],
                    ['name' => 'SM Lindesberg', 'date' => '2025-07-01', 'location' => 'Lindesberg', 'status' => 'upcoming'],
                    ['name' => 'Cykelvasan 90', 'date' => '2025-08-10', 'location' => 'Mora', 'status' => 'upcoming'],
                ];

                foreach ($demo_events as $event):
                ?>
                <div class="gs-event-card">
                    <div class="gs-event-header">
                        <div class="gs-event-date">
                            <div class="gs-event-date-day"><?= date('d', strtotime($event['date'])) ?></div>
                            <div class="gs-event-date-month"><?= date('M', strtotime($event['date'])) ?></div>
                        </div>
                        <span class="gs-badge gs-badge-warning">
                            <i data-lucide="clock"></i>
                            Kommande
                        </span>
                    </div>
                    <div class="gs-event-content">
                        <h3 class="gs-event-title">
                            <?= h($event['name']) ?>
                        </h3>
                        <p class="gs-event-icon">
                            <i data-lucide="map-pin"></i>
                            <?= h($event['location']) ?>
                        </p>
                        <p class="gs-event-icon">
                            <i data-lucide="calendar"></i>
                            <?= date('d M Y', strtotime($event['date'])) ?>
                        </p>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </main>

    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>
    <!-- TheHUB JavaScript -->
    <script src="/assets/thehub.js"></script>
</body>
</html>

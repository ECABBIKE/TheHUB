<?php
require_once __DIR__ . '/config.php';

$pageTitle = 'Hem';
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
            <!-- Hero -->
            <div class="gs-hero gs-text-center gs-mb-xl">
                <h1 class="gs-h1 gs-text-primary gs-mb-md">
                    <i data-lucide="home"></i>
                    Välkommen till TheHUB
                </h1>
                <p class="gs-text-lg gs-text-secondary">
                    Sveriges centrala plattform för cykeltävlingar
                </p>
            </div>

            <!-- Quick Stats -->
            <div class="gs-grid gs-grid-cols-1 gs-md-grid-cols-3 gs-gap-lg gs-mb-xl">
                <div class="gs-card">
                    <div class="gs-card-content gs-text-center">
                        <i data-lucide="calendar" class="gs-icon-xl gs-text-primary gs-mb-md"></i>
                        <div class="gs-stat-value">64</div>
                        <div class="gs-stat-label">Tävlingar 2025</div>
                    </div>
                </div>
                <div class="gs-card">
                    <div class="gs-card-content gs-text-center">
                        <i data-lucide="users" class="gs-icon-xl gs-text-accent gs-mb-md"></i>
                        <div class="gs-stat-value">3,247</div>
                        <div class="gs-stat-label">Registrerade cyklister</div>
                    </div>
                </div>
                <div class="gs-card">
                    <div class="gs-card-content gs-text-center">
                        <i data-lucide="trophy" class="gs-icon-xl gs-text-success gs-mb-md"></i>
                        <div class="gs-stat-value">8</div>
                        <div class="gs-stat-label">Aktiva serier</div>
                    </div>
                </div>
            </div>

            <!-- Quick Links -->
            <div class="gs-grid gs-grid-cols-1 gs-md-grid-cols-2 gs-gap-lg">
                <div class="gs-card">
                    <div class="gs-card-header">
                        <h2 class="gs-h4 gs-text-primary">
                            <i data-lucide="calendar"></i>
                            Tävlingar
                        </h2>
                    </div>
                    <div class="gs-card-content">
                        <p class="gs-text-secondary gs-mb-md">
                            Bläddra bland alla tävlingar, se resultat och anmäl dig till kommande events.
                        </p>
                        <a href="/events.php" class="gs-btn gs-btn-primary">
                            <i data-lucide="arrow-right"></i>
                            Till tävlingar
                        </a>
                    </div>
                </div>

                <div class="gs-card">
                    <div class="gs-card-header">
                        <h2 class="gs-h4 gs-text-primary">
                            <i data-lucide="trophy"></i>
                            Serier
                        </h2>
                    </div>
                    <div class="gs-card-content">
                        <p class="gs-text-secondary gs-mb-md">
                            Se alla tävlingsserier, ställningar och poängberäkningar.
                        </p>
                        <a href="/series.php" class="gs-btn gs-btn-primary">
                            <i data-lucide="arrow-right"></i>
                            Till serier
                        </a>
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

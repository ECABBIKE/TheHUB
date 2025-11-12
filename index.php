<?php require_once __DIR__ . '/config.php'; ?>
<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TheHUB - GravitySeries</title>
    <link rel="stylesheet" href="/assets/gravityseries-theme.css">
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="gs-landing-pure">

    <!-- Main Content -->
    <main>
        <!-- Hero Section -->
        <div class="gs-hero-landing">
            <div class="gs-container gs-text-center">
                <img src="https://gravityseries.se/wp-content/uploads/2024/03/Gravity-Series.png"
                     alt="GravitySeries"
                     class="gs-hero-logo gs-mb-lg">
                <h1 class="gs-h1 gs-text-white gs-mb-md">The HUB</h1>
                <p class="gs-text-lg gs-text-white" style="margin-top: 1rem;">
                    Sveriges centrala plattform för cykeltävlingar
                </p>
                <p class="gs-text-white" style="margin-top: 0.5rem; opacity: 0.9;">
                    Resultat, statistik och tävlingskalender för GravitySeries
                </p>
            </div>
        </div>

        <!-- Three Main Cards -->
        <div class="gs-container" style="margin-top: -80px; position: relative; z-index: 10;">
            <div class="gs-grid gs-grid-cols-1 gs-md-grid-cols-3 gs-gap-lg">

                <!-- Card 1: Deltagare -->
                <a href="/riders.php" class="gs-landing-card">
                    <div class="gs-card">
                        <div class="gs-card-content gs-text-center" style="padding: 3rem 2rem;">
                            <i data-lucide="users" style="width: 64px; height: 64px; margin: 0 auto 1.5rem; display: block; color: var(--gs-primary);"></i>
                            <h3 class="gs-h3 gs-mb-md">Deltagare</h3>
                            <p class="gs-text-secondary gs-mb-lg">
                                Sök bland alla aktiva cyklister och se deras resultat och statistik
                            </p>
                            <div class="gs-btn gs-btn-primary gs-w-full">
                                <i data-lucide="arrow-right"></i>
                                Se deltagare
                            </div>
                        </div>
                    </div>
                </a>

                <!-- Card 2: Kalender -->
                <a href="/events.php" class="gs-landing-card">
                    <div class="gs-card">
                        <div class="gs-card-content gs-text-center" style="padding: 3rem 2rem;">
                            <i data-lucide="calendar" style="width: 64px; height: 64px; margin: 0 auto 1.5rem; display: block; color: var(--gs-accent);"></i>
                            <h3 class="gs-h3 gs-mb-md">Kalender</h3>
                            <p class="gs-text-secondary gs-mb-lg">
                                Kommande tävlingar och resultat från tidigare events
                            </p>
                            <div class="gs-btn gs-btn-primary gs-w-full">
                                <i data-lucide="arrow-right"></i>
                                Se kalender
                            </div>
                        </div>
                    </div>
                </a>

                <!-- Card 3: Serier -->
                <a href="/series.php" class="gs-landing-card">
                    <div class="gs-card">
                        <div class="gs-card-content gs-text-center" style="padding: 3rem 2rem;">
                            <i data-lucide="trophy" style="width: 64px; height: 64px; margin: 0 auto 1.5rem; display: block; color: #437264;"></i>
                            <h3 class="gs-h3 gs-mb-md">Serier</h3>
                            <p class="gs-text-secondary">
                                Serietabeller och ställningar för alla GravitySeries
                            </p>
                        </div>
                    </div>
                </a>

            </div>

            <!-- Footer spacing -->
            <div style="height: 4rem;"></div>
        </div>
    </main>

    <script>
        lucide.createIcons();
    </script>
</body>
</html>

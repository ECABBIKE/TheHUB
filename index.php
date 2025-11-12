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
<body>
    <!-- Hamburger Menu Button -->
    <button class="gs-hamburger" onclick="toggleSidebar()">
        <i data-lucide="menu"></i>
    </button>

    <!-- Sidebar (opens on hamburger click) -->
    <?php include __DIR__ . '/includes/navigation.php'; ?>

    <!-- Main Content -->
    <main class="gs-landing">
        <!-- Hero Section -->
        <div class="gs-hero">
            <div class="gs-container gs-text-center">
                <img src="https://gravityseries.se/wp-content/uploads/2024/03/Gravity-Series.png"
                     alt="GravitySeries"
                     class="gs-hero-logo gs-mb-md">
                <h1 class="gs-h1 gs-text-white gs-mb-md">The HUB</h1>
                <p class="gs-text-lg gs-text-white gs-mb-sm">
                    Sveriges centrala plattform för cykeltävlingar
                </p>
                <p class="gs-text-white" style="margin-top: 1rem; opacity: 0.9;">
                    Resultat, statistik och tävlingskalender för GravitySeries
                </p>
            </div>
        </div>

        <!-- Three Main Cards -->
        <div class="gs-container" style="margin-top: -60px; position: relative; z-index: 10;">
            <div class="gs-grid gs-grid-cols-1 gs-md-grid-cols-3 gs-gap-lg">

                <!-- Card 1: Deltagare -->
                <a href="/riders.php" class="gs-landing-card">
                    <div class="gs-card gs-card-hover">
                        <div class="gs-card-content gs-text-center" style="padding: 3rem 2rem;">
                            <div class="gs-icon-wrapper gs-bg-primary" style="margin: 0 auto 1.5rem;">
                                <i data-lucide="users" style="width: 48px; height: 48px;" class="gs-text-white"></i>
                            </div>
                            <h3 class="gs-h3 gs-mb-md">Deltagare</h3>
                            <p class="gs-text-secondary">
                                Sök bland alla aktiva cyklister och se deras resultat och statistik
                            </p>
                        </div>
                    </div>
                </a>

                <!-- Card 2: Kalender -->
                <a href="/events.php" class="gs-landing-card">
                    <div class="gs-card gs-card-hover">
                        <div class="gs-card-content gs-text-center" style="padding: 3rem 2rem;">
                            <div class="gs-icon-wrapper" style="background-color: var(--gs-accent); margin: 0 auto 1.5rem;">
                                <i data-lucide="calendar" style="width: 48px; height: 48px;" class="gs-text-white"></i>
                            </div>
                            <h3 class="gs-h3 gs-mb-md">Kalender</h3>
                            <p class="gs-text-secondary">
                                Kommande tävlingar och resultat från tidigare events
                            </p>
                        </div>
                    </div>
                </a>

                <!-- Card 3: Resultat -->
                <a href="/results.php" class="gs-landing-card">
                    <div class="gs-card gs-card-hover">
                        <div class="gs-card-content gs-text-center" style="padding: 3rem 2rem;">
                            <div class="gs-icon-wrapper" style="background-color: var(--gs-success); margin: 0 auto 1.5rem;">
                                <i data-lucide="trophy" style="width: 48px; height: 48px;" class="gs-text-white"></i>
                            </div>
                            <h3 class="gs-h3 gs-mb-md">Resultat</h3>
                            <p class="gs-text-secondary">
                                Se resultat och ställningar från alla tävlingar
                            </p>
                        </div>
                    </div>
                </a>

            </div>
        </div>
    </main>

    <script>
        lucide.createIcons();

        function toggleSidebar() {
            document.querySelector('.gs-sidebar').classList.toggle('open');
            document.body.classList.toggle('sidebar-open');
        }

        function closeSidebar() {
            document.querySelector('.gs-sidebar').classList.remove('open');
            document.body.classList.remove('sidebar-open');
        }

        // Close sidebar when clicking overlay
        document.addEventListener('click', function(e) {
            if (document.body.classList.contains('sidebar-open') &&
                !e.target.closest('.gs-sidebar') &&
                !e.target.closest('.gs-hamburger')) {
                closeSidebar();
            }
        });
    </script>
</body>
</html>

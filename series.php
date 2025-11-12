<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Load config first
if (!file_exists(__DIR__ . '/config.php')) {
    die('ERROR: config.php not found! Current directory: ' . __DIR__);
}
require_once __DIR__ . '/config.php';

$db = getDB();

// Get all series
$series = $db->getAll("
    SELECT s.id, s.name, s.description, s.year,
           COUNT(DISTINCT e.id) as event_count
    FROM series s
    LEFT JOIN events e ON s.id = e.series_id
    GROUP BY s.id
    ORDER BY s.year DESC, s.name ASC
");

$pageTitle = 'Serier';
?>
<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($pageTitle) ?> - TheHUB</title>
    <link rel="stylesheet" href="/assets/gravityseries-theme.css">
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="gs-public-page">
    <!-- Hamburger -->
    <button class="gs-mobile-menu-toggle" onclick="toggleMenu()" aria-label="Toggle menu">
        <i data-lucide="menu"></i>
    </button>

    <!-- Sidebar -->
    <?php include __DIR__ . '/includes/navigation.php'; ?>

    <!-- Overlay -->
    <div class="gs-sidebar-overlay" onclick="closeMenu()"></div>

    <!-- Main Content -->
    <main style="padding: 6rem 2rem 2rem;">
        <div class="gs-container">
            <!-- Header -->
            <div class="gs-mb-xl">
                <h1 class="gs-h2 gs-text-primary gs-mb-sm">
                    <i data-lucide="trophy"></i>
                    Tävlingsserier
                </h1>
                <p class="gs-text-secondary">
                    Alla GravitySeries och andra tävlingsserier
                </p>
            </div>

            <!-- Series Grid -->
            <?php if (empty($series)): ?>
                <div class="gs-card gs-text-center" style="padding: 3rem;">
                    <i data-lucide="inbox" style="width: 64px; height: 64px; margin: 0 auto 1rem; opacity: 0.3;"></i>
                    <h3 class="gs-h4 gs-mb-sm">Inga serier ännu</h3>
                    <p class="gs-text-secondary">
                        Det finns inga tävlingsserier registrerade ännu.
                    </p>
                </div>
            <?php else: ?>
                <div class="gs-grid gs-grid-cols-1 gs-md-grid-cols-2 gs-lg-grid-cols-3 gs-gap-lg">
                    <?php foreach ($series as $s): ?>
                        <div class="gs-card gs-card-hover">
                            <div class="gs-card-header">
                                <h3 class="gs-h4">
                                    <i data-lucide="award"></i>
                                    <?= h($s['name']) ?>
                                </h3>
                                <?php if ($s['year']): ?>
                                    <span class="gs-badge gs-badge-primary gs-text-xs">
                                        <?= $s['year'] ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <div class="gs-card-content">
                                <?php if ($s['description']): ?>
                                    <p class="gs-text-secondary gs-mb-md">
                                        <?= h($s['description']) ?>
                                    </p>
                                <?php endif; ?>
                                <div class="gs-flex gs-items-center gs-gap-sm gs-text-sm gs-text-secondary">
                                    <i data-lucide="calendar" style="width: 16px; height: 16px;"></i>
                                    <span><?= $s['event_count'] ?> tävlingar</span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <script>
        lucide.createIcons();

        function toggleMenu() {
            const sidebar = document.querySelector('.gs-sidebar');
            const overlay = document.querySelector('.gs-sidebar-overlay');
            sidebar.classList.toggle('open');
            overlay.classList.toggle('active');
        }

        function closeMenu() {
            const sidebar = document.querySelector('.gs-sidebar');
            const overlay = document.querySelector('.gs-sidebar-overlay');
            sidebar.classList.remove('open');
            overlay.classList.remove('active');
        }

        // Close on link click
        document.querySelectorAll('.gs-sidebar a').forEach(link => {
            link.addEventListener('click', closeMenu);
        });
    </script>
</body>
</html>

<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$db = getDB();

// Get all active cyclists with their statistics
$cyclists = $db->getAll("
    SELECT
        c.id,
        c.firstname,
        c.lastname,
        c.birth_year,
        c.gender,
        c.license_number,
        cl.name as club_name,
        COUNT(DISTINCT r.id) as total_races,
        COUNT(CASE WHEN r.position <= 3 THEN 1 END) as podiums,
        MIN(r.position) as best_position
    FROM cyclists c
    LEFT JOIN clubs cl ON c.club_id = cl.id
    LEFT JOIN results r ON c.id = r.cyclist_id
    WHERE c.active = 1
    GROUP BY c.id
    HAVING total_races > 0
    ORDER BY c.lastname, c.firstname
");

$total_count = count($cyclists);
?>
<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Deltagare - TheHUB</title>
    <link rel="stylesheet" href="/assets/gravityseries-theme.css">
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body>
    <!-- Hamburger Menu Button -->
    <button class="gs-hamburger" onclick="toggleSidebar()">
        <i data-lucide="menu"></i>
    </button>

    <!-- Sidebar -->
    <?php include __DIR__ . '/includes/navigation.php'; ?>

    <!-- Main Content -->
    <main class="gs-content-with-sidebar">
        <div class="gs-container">
            <!-- Header -->
            <div class="gs-mb-xl">
                <h1 class="gs-h2 gs-text-primary gs-mb-sm">
                    <i data-lucide="users"></i>
                    Aktiva Deltagare
                </h1>
                <p class="gs-text-secondary">
                    <?= $total_count ?> cyklister med resultat
                </p>
            </div>

            <!-- Search Box -->
            <div class="gs-mb-lg">
                <input type="text"
                       id="searchRiders"
                       class="gs-input"
                       placeholder="🔍 Sök på namn, klubb eller licens...">
            </div>

            <!-- Riders Grid -->
            <?php if (empty($cyclists)): ?>
                <div class="gs-card gs-text-center" style="padding: 3rem;">
                    <i data-lucide="user-x" style="width: 64px; height: 64px; margin: 0 auto 1rem; opacity: 0.3;"></i>
                    <h3 class="gs-h4 gs-mb-sm">Inga deltagare hittades</h3>
                    <p class="gs-text-secondary">
                        Det finns inga aktiva cyklister med resultat ännu.
                    </p>
                </div>
            <?php else: ?>
                <div class="gs-grid gs-grid-cols-1 gs-md-grid-cols-2 gs-lg-grid-cols-3 gs-gap-lg" id="ridersGrid">
                    <?php foreach ($cyclists as $rider): ?>
                        <div class="gs-card gs-card-hover rider-card"
                             data-search="<?= strtolower(h($rider['firstname'] . ' ' . $rider['lastname'] . ' ' . ($rider['club_name'] ?? '') . ' ' . ($rider['license_number'] ?? ''))) ?>">
                            <div class="gs-card-header">
                                <div class="gs-flex gs-items-center gs-gap-md">
                                    <div class="gs-avatar gs-bg-primary">
                                        <i data-lucide="user" class="gs-text-white"></i>
                                    </div>
                                    <div class="gs-flex-1">
                                        <h3 class="gs-h4 gs-mb-xs">
                                            <?= h($rider['firstname']) ?> <?= h($rider['lastname']) ?>
                                        </h3>
                                        <?php if ($rider['club_name']): ?>
                                            <p class="gs-text-sm gs-text-secondary">
                                                <i data-lucide="building" style="width: 14px; height: 14px;"></i>
                                                <?= h($rider['club_name']) ?>
                                            </p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="gs-card-content">
                                <!-- Stats Grid -->
                                <div class="gs-flex gs-justify-around gs-mb-md" style="padding: 1rem 0;">
                                    <div class="gs-text-center">
                                        <div class="gs-text-2xl gs-font-bold gs-text-primary">
                                            <?= $rider['total_races'] ?>
                                        </div>
                                        <div class="gs-text-xs gs-text-secondary">Tävlingar</div>
                                    </div>
                                    <div class="gs-text-center">
                                        <div class="gs-text-2xl gs-font-bold" style="color: var(--gs-accent);">
                                            <?= $rider['podiums'] ?>
                                        </div>
                                        <div class="gs-text-xs gs-text-secondary">Pallplatser</div>
                                    </div>
                                    <?php if ($rider['best_position']): ?>
                                        <div class="gs-text-center">
                                            <div class="gs-text-2xl gs-font-bold" style="color: var(--gs-success);">
                                                <?= $rider['best_position'] ?>
                                            </div>
                                            <div class="gs-text-xs gs-text-secondary">Bästa</div>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <!-- Badges -->
                                <div class="gs-flex gs-gap-xs gs-flex-wrap">
                                    <?php if ($rider['gender']): ?>
                                        <span class="gs-badge gs-badge-secondary gs-text-xs">
                                            <?= $rider['gender'] == 'M' ? '👨 Herr' : '👩 Dam' ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if ($rider['birth_year']): ?>
                                        <span class="gs-badge gs-badge-secondary gs-text-xs">
                                            <?= $rider['birth_year'] ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if ($rider['license_number']): ?>
                                        <span class="gs-badge gs-badge-primary gs-text-xs">
                                            <?= h($rider['license_number']) ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- No Results Message (hidden by default) -->
            <div id="noResults" class="gs-card gs-text-center" style="display: none; padding: 3rem; margin-top: 2rem;">
                <i data-lucide="search-x" style="width: 64px; height: 64px; margin: 0 auto 1rem; opacity: 0.3;"></i>
                <h3 class="gs-h4 gs-mb-sm">Inga matchande deltagare</h3>
                <p class="gs-text-secondary">
                    Prova att söka med andra ord eller rensa sökningen.
                </p>
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

        // Search functionality
        const searchInput = document.getElementById('searchRiders');
        const ridersGrid = document.getElementById('ridersGrid');
        const noResults = document.getElementById('noResults');

        if (searchInput && ridersGrid) {
            searchInput.addEventListener('input', function(e) {
                const search = e.target.value.toLowerCase().trim();
                const cards = ridersGrid.querySelectorAll('.rider-card');
                let visibleCount = 0;

                cards.forEach(card => {
                    const searchData = card.getAttribute('data-search');
                    if (!search || searchData.includes(search)) {
                        card.style.display = 'block';
                        visibleCount++;
                    } else {
                        card.style.display = 'none';
                    }
                });

                // Show/hide no results message
                if (noResults) {
                    noResults.style.display = visibleCount === 0 && search ? 'block' : 'none';
                }
                if (ridersGrid) {
                    ridersGrid.style.display = visibleCount === 0 && search ? 'none' : 'grid';
                }
            });
        }
    </script>
</body>
</html>

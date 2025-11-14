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

// Load public display settings
$publicSettings = require __DIR__ . '/config/public_settings.php';
$displayMode = $publicSettings['public_riders_display'] ?? 'with_results';
$minResults = intval($publicSettings['min_results_to_show'] ?? 1);

// Build query based on settings
if ($displayMode === 'all') {
    // Show ALL active riders
    $cyclists = $db->getAll("
        SELECT
            c.id,
            c.firstname,
            c.lastname,
            c.birth_year,
            c.gender,
            c.license_number,
            c.license_type,
            c.license_valid_until,
            cl.name as club_name,
            COUNT(DISTINCT r.id) as total_races,
            COUNT(CASE WHEN r.position <= 3 THEN 1 END) as podiums,
            MIN(r.position) as best_position
        FROM riders c
        LEFT JOIN clubs cl ON c.club_id = cl.id
        LEFT JOIN results r ON c.id = r.cyclist_id
        WHERE c.active = 1
        GROUP BY c.id
        ORDER BY c.lastname, c.firstname
    ");
} else {
    // Show ONLY riders with results (minimum required results)
    $cyclists = $db->getAll("
        SELECT
            c.id,
            c.firstname,
            c.lastname,
            c.birth_year,
            c.gender,
            c.license_number,
            c.license_type,
            c.license_valid_until,
            cl.name as club_name,
            COUNT(DISTINCT r.id) as total_races,
            COUNT(CASE WHEN r.position <= 3 THEN 1 END) as podiums,
            MIN(r.position) as best_position
        FROM riders c
        LEFT JOIN clubs cl ON c.club_id = cl.id
        INNER JOIN results r ON c.id = r.cyclist_id
        WHERE c.active = 1
        GROUP BY c.id
        HAVING total_races >= ?
        ORDER BY c.lastname, c.firstname
    ", [$minResults]);
}

$total_count = count($cyclists);
$pageTitle = 'Deltagare';
$pageType = 'public';
include __DIR__ . '/includes/layout-header.php';
?>

    <main class="gs-main-content">
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

            <!-- Results Counter -->
            <div class="gs-mb-md" id="resultsCounter" style="display: none;">
                <div class="gs-alert gs-alert-info" style="padding: 0.75rem 1rem;">
                    <i data-lucide="filter"></i>
                    <span id="resultsCount"></span>
                </div>
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
                <div class="gs-grid gs-grid-cols-2 gs-md-grid-cols-4 gs-lg-grid-cols-5 gs-xl-grid-cols-6 gs-gap-sm" id="ridersGrid">
                    <?php foreach ($cyclists as $rider): ?>
                        <a href="/rider.php?id=<?= $rider['id'] ?>" class="gs-card gs-card-hover rider-card"
                           data-search="<?= strtolower(h($rider['firstname'] . ' ' . $rider['lastname'] . ' ' . ($rider['club_name'] ?? '') . ' ' . ($rider['license_number'] ?? ''))) ?>"
                           style="padding: 0.5rem; text-decoration: none; color: inherit; display: block; cursor: pointer; transition: transform 0.2s, box-shadow 0.2s;">
                            <!-- Profile Header -->
                            <div class="gs-flex gs-items-start gs-gap-xs" style="margin-bottom: 0.4rem;">
                                <div class="gs-avatar gs-avatar-sm gs-bg-primary" style="width: 32px; height: 32px; flex-shrink: 0;">
                                    <i data-lucide="user" class="gs-text-white" style="width: 16px; height: 16px;"></i>
                                </div>
                                <div class="gs-flex-1" style="min-width: 0;">
                                    <h3 class="gs-text-sm gs-font-bold" style="line-height: 1.2; margin-bottom: 0.1rem;">
                                        <?= h($rider['firstname']) ?> <?= h($rider['lastname']) ?>
                                    </h3>
                                    <?php if ($rider['club_name']): ?>
                                        <p class="gs-text-xs gs-text-secondary" style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis; line-height: 1.2;">
                                            <i data-lucide="building" style="width: 10px; height: 10px;"></i>
                                            <?= h($rider['club_name']) ?>
                                        </p>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Stats Compact -->
                            <div class="gs-flex gs-justify-between" style="padding: 0.35rem 0; border-top: 1px solid var(--gs-border); margin-bottom: 0.35rem;">
                                <div class="gs-text-center gs-flex-1">
                                    <div class="gs-text-base gs-font-bold gs-text-primary" style="line-height: 1;"><?= $rider['total_races'] ?></div>
                                    <div class="gs-text-xs gs-text-secondary" style="line-height: 1.2;">Lopp</div>
                                </div>
                                <div class="gs-text-center gs-flex-1">
                                    <div class="gs-text-base gs-font-bold" style="color: var(--gs-accent); line-height: 1;"><?= $rider['podiums'] ?></div>
                                    <div class="gs-text-xs gs-text-secondary" style="line-height: 1.2;">Pall</div>
                                </div>
                                <?php if ($rider['best_position']): ?>
                                    <div class="gs-text-center gs-flex-1">
                                        <div class="gs-text-base gs-font-bold" style="color: var(--gs-success); line-height: 1;"><?= $rider['best_position'] ?></div>
                                        <div class="gs-text-xs gs-text-secondary" style="line-height: 1.2;">Bäst</div>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Info Badges -->
                            <div class="gs-flex gs-gap-xs gs-flex-wrap" style="font-size: 0.65rem;">
                                <?php if ($rider['birth_year']): ?>
                                    <span class="gs-badge gs-badge-secondary" style="padding: 0.1rem 0.3rem; font-size: 0.6rem;">
                                        <?= $rider['gender'] == 'M' ? '👨' : ($rider['gender'] == 'F' ? '👩' : '👤') ?> <?= calculateAge($rider['birth_year']) ?>
                                    </span>
                                <?php endif; ?>
                                <?php
                                // Check license status - compact display
                                if (!empty($rider['license_number']) && strpos($rider['license_number'], 'SWE') === 0): ?>
                                    <span class="gs-badge gs-badge-danger" style="padding: 0.1rem 0.3rem; font-size: 0.6rem;">
                                        ✗ Licens
                                    </span>
                                <?php elseif (!empty($rider['license_type']) && $rider['license_type'] !== 'None'):
                                    $licenseCheck = checkLicense($rider);
                                    if ($licenseCheck['valid']): ?>
                                        <span class="gs-badge gs-badge-success" style="padding: 0.1rem 0.3rem; font-size: 0.6rem;">
                                            ✓ Licens
                                        </span>
                                    <?php else: ?>
                                        <span class="gs-badge gs-badge-danger" style="padding: 0.1rem 0.3rem; font-size: 0.6rem;">
                                            ✗ Licens
                                        </span>
                                    <?php endif;
                                endif;
                                ?>
                            </div>
                        </a>
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

    <style>
    .rider-card {
        min-height: 140px;
        display: flex;
        flex-direction: column;
    }
    .rider-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1) !important;
    }
    .rider-card:active {
        transform: translateY(-1px);
    }
    </style>

<?php
// Additional page-specific scripts
$additionalScripts = "
    // Search functionality
    const searchInput = document.getElementById('searchRiders');
    const ridersGrid = document.getElementById('ridersGrid');
    const noResults = document.getElementById('noResults');
    const resultsCounter = document.getElementById('resultsCounter');
    const resultsCount = document.getElementById('resultsCount');
    const totalRiders = " . count($cyclists) . ";

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

            // Update results counter
            if (search && resultsCounter && resultsCount) {
                resultsCounter.style.display = 'block';
                if (visibleCount === 1) {
                    resultsCount.textContent = 'Visar 1 deltagare av ' + totalRiders + ' totalt';
                } else {
                    resultsCount.textContent = 'Visar ' + visibleCount + ' deltagare av ' + totalRiders + ' totalt';
                }
            } else if (resultsCounter) {
                resultsCounter.style.display = 'none';
            }

            // Show/hide no results message
            if (noResults) {
                noResults.style.display = visibleCount === 0 && search ? 'block' : 'none';
            }
            if (ridersGrid) {
                ridersGrid.style.display = visibleCount === 0 && search ? 'none' : 'grid';
            }
        });
    }
";
include __DIR__ . '/includes/layout-footer.php';
?>

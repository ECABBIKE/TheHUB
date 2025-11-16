<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Load config first
if (!file_exists(__DIR__ . '/config.php')) {
    die('ERROR: config.php not found! Current directory: ' . __DIR__);
}
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/class-calculations.php';

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

// Count active clubs
$active_clubs = array_unique(array_filter(array_column($cyclists, 'club_name')));
$club_count = count($active_clubs);

$pageTitle = 'Deltagare';
$pageType = 'public';
include __DIR__ . '/includes/layout-header.php';
?>

<style>
    .license-card-compact {
        background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
        border-radius: 12px;
        overflow: hidden;
        transition: transform 0.2s, box-shadow 0.2s;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        text-decoration: none;
        color: inherit;
        display: block;
    }

    .license-card-compact:hover {
        transform: translateY(-4px);
        box-shadow: 0 8px 20px rgba(0, 0, 0, 0.15);
    }

    /* GravitySeries Stripe */
    .uci-stripe {
        height: 6px;
        background: linear-gradient(90deg,
            #004a98 0% 25%,
            #8A9A5B 25% 50%,
            #EF761F 50% 75%,
            #FFE009 75% 100%
        );
    }

    /* Compact Header */
    .license-header-compact {
        padding: 12px 16px;
        background: linear-gradient(135deg, #2d3748 0%, #1a202c 100%);
        color: white;
        display: flex;
        justify-content: flex-end;
        align-items: center;
    }

    .license-season {
        font-size: 14px;
        font-weight: 600;
        background: rgba(255, 255, 255, 0.2);
        padding: 4px 12px;
        border-radius: 12px;
    }

    /* Card Content */
    .license-card-content {
        padding: 16px;
    }

    .rider-name-line {
        font-size: 18px;
        font-weight: 800;
        color: #1a202c;
        margin-bottom: 4px;
        text-transform: uppercase;
        letter-spacing: -0.2px;
    }

    .license-id {
        font-size: 12px;
        color: #718096;
        margin-bottom: 12px;
        font-weight: 600;
    }

    .license-info-compact {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 8px;
        margin-bottom: 12px;
    }

    .info-field-compact {
        background: white;
        padding: 8px 12px;
        border-radius: 6px;
        border-left: 3px solid #667eea;
    }

    .info-label-compact {
        font-size: 9px;
        color: #718096;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.3px;
        margin-bottom: 2px;
    }

    .info-value-compact {
        font-size: 14px;
        color: #1a202c;
        font-weight: 700;
    }

    .club-field-wide {
        grid-column: span 2;
        background: white;
        padding: 8px 12px;
        border-radius: 6px;
        border-left: 3px solid #667eea;
    }

    .license-type-badge {
        display: inline-block;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 6px 12px;
        border-radius: 6px;
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-bottom: 8px;
    }

    .license-status {
        display: inline-block;
        padding: 4px 10px;
        border-radius: 6px;
        font-size: 10px;
        font-weight: 600;
        text-transform: uppercase;
    }

    .license-status.active {
        background: #10b981;
        color: white;
    }

    .license-status.inactive {
        background: #ef4444;
        color: white;
    }

    .card-footer-compact {
        padding: 8px 16px;
        background: rgba(0, 0, 0, 0.03);
        font-size: 9px;
        color: #718096;
        text-align: center;
    }
</style>

    <main class="gs-main-content">
        <div class="gs-container">

            <!-- Header -->
            <div class="gs-mb-xl">
                <h1 class="gs-h2 gs-text-primary gs-mb-sm">
                    <i data-lucide="users"></i>
                    Aktiva Deltagare
                </h1>
                <div class="gs-flex gs-gap-md gs-flex-wrap">
                    <span class="gs-badge gs-badge-primary">
                        <?php if ($displayMode === 'all'): ?>
                            <?= $total_count ?> aktiva deltagare
                        <?php else: ?>
                            <?= $total_count ?> deltagare med resultat
                        <?php endif; ?>
                    </span>
                    <span class="gs-badge gs-badge-secondary">
                        <?= $club_count ?> aktiva klubbar
                    </span>
                </div>
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

            <!-- Initial Message -->
            <div id="initialMessage" class="gs-card gs-text-center" style="padding: 3rem;">
                <i data-lucide="search" style="width: 64px; height: 64px; margin: 0 auto 1rem; opacity: 0.3;"></i>
                <h3 class="gs-h4 gs-mb-sm">Sök efter deltagare</h3>
                <p class="gs-text-secondary">
                    Börja skriva i sökfältet ovan för att hitta deltagare
                </p>
            </div>

            <!-- Riders Grid -->
            <?php if (empty($cyclists)): ?>
                <div class="gs-card gs-text-center" style="padding: 3rem; display: none;" id="noRidersMessage">
                    <i data-lucide="user-x" style="width: 64px; height: 64px; margin: 0 auto 1rem; opacity: 0.3;"></i>
                    <h3 class="gs-h4 gs-mb-sm">Inga deltagare hittades</h3>
                    <p class="gs-text-secondary">
                        Det finns inga aktiva cyklister med resultat ännu.
                    </p>
                </div>
            <?php else: ?>
                <div class="gs-grid gs-grid-cols-1 gs-md-grid-cols-2 gs-lg-grid-cols-3 gs-xl-grid-cols-4 gs-gap-md" id="ridersGrid" style="display: none;">
                    <?php
                    $currentYear = date('Y');
                    foreach ($cyclists as $rider):
                        // Calculate age
                        $age = ($rider['birth_year'] && $rider['birth_year'] > 0)
                            ? ($currentYear - $rider['birth_year'])
                            : null;

                        // Check license status
                        $isUciLicense = !empty($rider['license_number']) && strpos($rider['license_number'], 'SWE') !== 0;
                        $licenseCheck = checkLicense($rider);
                        $isLicenseActive = $isUciLicense && !empty($rider['license_type']) && $rider['license_type'] !== 'None' && $licenseCheck['valid'];
                    ?>
                        <a href="/rider.php?id=<?= $rider['id'] ?>"
                           class="license-card-compact"
                           data-search="<?= strtolower(h($rider['firstname'] . ' ' . $rider['lastname'] . ' ' . ($rider['club_name'] ?? '') . ' ' . ($rider['license_number'] ?? ''))) ?>">

                            <!-- UCI Color Stripe -->
                            <div class="uci-stripe"></div>

                            <!-- Compact Header -->
                            <div class="license-header-compact">
                                <div class="license-season"><?= $currentYear ?></div>
                            </div>

                            <!-- Card Content -->
                            <div class="license-card-content">
                                <!-- Name on one line -->
                                <div class="rider-name-line">
                                    <?= h($rider['firstname']) ?> <?= h($rider['lastname']) ?>
                                </div>

                                <!-- License ID under name -->
                                <div class="license-id">
                                    <?php if ($isUciLicense): ?>
                                        UCI: <?= h($rider['license_number']) ?>
                                    <?php elseif (!empty($rider['license_number'])): ?>
                                        SWE-ID: <?= h($rider['license_number']) ?>
                                    <?php else: ?>
                                        ID: #<?= sprintf('%04d', $rider['id']) ?>
                                    <?php endif; ?>
                                </div>

                                <!-- License Type & Status -->
                                <?php if (!empty($rider['license_type']) && $rider['license_type'] !== 'None'): ?>
                                    <div style="margin-bottom: 12px;">
                                        <span class="license-type-badge"><?= h($rider['license_type']) ?></span>

                                        <?php if ($isUciLicense): ?>
                                            <span class="license-status <?= $isLicenseActive ? 'active' : 'inactive' ?>">
                                                <?= $isLicenseActive ? '✓ Aktiv ' . $currentYear : '✗ Ej aktiv ' . $currentYear ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>

                                <!-- Info Grid -->
                                <div class="license-info-compact">
                                    <div class="info-field-compact">
                                        <div class="info-label-compact">Ålder</div>
                                        <div class="info-value-compact">
                                            <?= $age !== null ? $age . ' år' : '–' ?>
                                        </div>
                                    </div>

                                    <div class="info-field-compact">
                                        <div class="info-label-compact">Kön</div>
                                        <div class="info-value-compact">
                                            <?php
                                            $genderDisplay = '–';
                                            if ($rider['gender'] === 'M' || strtolower($rider['gender']) === 'men') {
                                                $genderDisplay = 'Man';
                                            } elseif (in_array(strtolower($rider['gender'] ?? ''), ['k', 'f', 'women', 'female', 'kvinna'])) {
                                                $genderDisplay = 'Kvinna';
                                            }
                                            echo $genderDisplay;
                                            ?>
                                        </div>
                                    </div>

                                    <div class="info-field-compact">
                                        <div class="info-label-compact">Race</div>
                                        <div class="info-value-compact">
                                            <?= $rider['total_races'] ?>
                                        </div>
                                    </div>

                                    <div class="info-field-compact">
                                        <div class="info-label-compact">Best</div>
                                        <div class="info-value-compact">
                                            <?= $rider['best_position'] ?? '–' ?>
                                        </div>
                                    </div>

                                    <div class="info-field-compact">
                                        <div class="info-label-compact">Points Total</div>
                                        <div class="info-value-compact">
                                            0
                                        </div>
                                    </div>

                                    <!-- Club on wide row -->
                                    <div class="club-field-wide">
                                        <div class="info-label-compact">Klubb</div>
                                        <div class="info-value-compact" style="font-size: 13px;">
                                            <?= $rider['club_name'] ? h($rider['club_name']) : 'Klubbtillhörighet saknas' ?>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Footer -->
                            <div class="card-footer-compact">
                                TheHUB by GravitySeries
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

    const initialMessage = document.getElementById('initialMessage');

    if (searchInput && ridersGrid) {
        searchInput.addEventListener('input', function(e) {
            const search = e.target.value.toLowerCase().trim();
            const cards = ridersGrid.querySelectorAll('.license-card-compact');
            let visibleCount = 0;

            // Show/hide initial message and grid
            if (search) {
                if (initialMessage) initialMessage.style.display = 'none';
                ridersGrid.style.display = 'grid';
            } else {
                if (initialMessage) initialMessage.style.display = 'block';
                ridersGrid.style.display = 'none';
                if (resultsCounter) resultsCounter.style.display = 'none';
                if (noResults) noResults.style.display = 'none';
                return;
            }

            cards.forEach(card => {
                const searchData = card.getAttribute('data-search');
                if (searchData.includes(search)) {
                    card.style.display = 'block';
                    visibleCount++;
                } else {
                    card.style.display = 'none';
                }
            });

            // Update results counter
            if (resultsCounter && resultsCount) {
                resultsCounter.style.display = 'block';
                if (visibleCount === 1) {
                    resultsCount.textContent = 'Visar 1 deltagare av ' + totalRiders + ' totalt';
                } else {
                    resultsCount.textContent = 'Visar ' + visibleCount + ' deltagare av ' + totalRiders + ' totalt';
                }
            }

            // Show/hide no results message
            if (noResults) {
                noResults.style.display = visibleCount === 0 ? 'block' : 'none';
            }
            if (ridersGrid) {
                ridersGrid.style.display = visibleCount === 0 ? 'none' : 'grid';
            }
        });
    }
";
include __DIR__ . '/includes/layout-footer.php';
?>

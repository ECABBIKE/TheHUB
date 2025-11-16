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
$filterDiscipline = $publicSettings['filter_discipline'] ?? null;

// Build WHERE clause for discipline filter
$disciplineWhere = '';
$disciplineParam = [];
if ($filterDiscipline) {
    // Check if disciplines JSON contains the filter discipline
    $disciplineWhere = " AND (
        c.disciplines LIKE ? OR
        c.disciplines LIKE ? OR
        c.disciplines LIKE ? OR
        c.disciplines LIKE ?
    )";
    $disciplineParam = [
        '%"' . $filterDiscipline . '"%',    // Exact match in JSON
        '%' . strtolower($filterDiscipline) . '%',  // Lowercase variant
        '%' . ucfirst(strtolower($filterDiscipline)) . '%',  // Capitalized
        '%' . strtoupper($filterDiscipline) . '%'   // Uppercase
    ];
}

// Build query based on settings
if ($displayMode === 'all') {
    // Show ALL active riders (optionally filtered by discipline)
    $cyclists = $db->getAll("
        SELECT
            c.id,
            c.firstname,
            c.lastname,
            c.birth_year,
            c.gender,
            c.license_number,
            c.license_type,
            c.license_year,
            c.license_valid_until,
            cl.name as club_name,
            COUNT(DISTINCT r.id) as total_races,
            COUNT(CASE WHEN r.position <= 3 THEN 1 END) as podiums,
            MIN(r.position) as best_position
        FROM riders c
        LEFT JOIN clubs cl ON c.club_id = cl.id
        LEFT JOIN results r ON c.id = r.cyclist_id
        WHERE c.active = 1 {$disciplineWhere}
        GROUP BY c.id
        ORDER BY c.lastname, c.firstname
    ", $disciplineParam);
} else {
    // Show ONLY riders with results (minimum required results)
    $params = array_merge($disciplineParam, [$minResults]);
    $cyclists = $db->getAll("
        SELECT
            c.id,
            c.firstname,
            c.lastname,
            c.birth_year,
            c.gender,
            c.license_number,
            c.license_type,
            c.license_year,
            c.license_valid_until,
            cl.name as club_name,
            COUNT(DISTINCT r.id) as total_races,
            COUNT(CASE WHEN r.position <= 3 THEN 1 END) as podiums,
            MIN(r.position) as best_position
        FROM riders c
        LEFT JOIN clubs cl ON c.club_id = cl.id
        INNER JOIN results r ON c.id = r.cyclist_id
        WHERE c.active = 1 {$disciplineWhere}
        GROUP BY c.id
        HAVING total_races >= ?
        ORDER BY c.lastname, c.firstname
    ", $params);
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
    /* VERY COMPACT CARD DESIGN - Half height */
    .license-card-compact {
        background: white;
        border-radius: 8px;
        overflow: hidden;
        transition: transform 0.15s, box-shadow 0.15s;
        box-shadow: 0 2px 6px rgba(0, 0, 0, 0.08);
        text-decoration: none;
        color: inherit;
        display: block;
        border-left: 3px solid #667eea;
    }

    .license-card-compact:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.12);
    }

    /* Thin stripe */
    .uci-stripe {
        height: 3px;
        background: linear-gradient(90deg,
            #004a98 0% 25%,
            #8A9A5B 25% 50%,
            #EF761F 50% 75%,
            #FFE009 75% 100%
        );
    }

    /* Compact content - much less padding */
    .license-card-content {
        padding: 10px 12px;
    }

    .rider-name-line {
        font-size: 14px;
        font-weight: 700;
        color: #1a202c;
        margin-bottom: 2px;
        text-transform: uppercase;
        letter-spacing: -0.1px;
        line-height: 1.2;
    }

    .license-id {
        font-size: 10px;
        color: #718096;
        margin-bottom: 8px;
        font-weight: 500;
    }

    /* Simplified grid - only show essential info */
    .license-info-compact {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 6px;
    }

    .info-field-compact {
        background: #f8f9fa;
        padding: 4px 8px;
        border-radius: 4px;
    }

    .info-label-compact {
        font-size: 8px;
        color: #718096;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.2px;
        margin-bottom: 1px;
    }

    .info-value-compact {
        font-size: 12px;
        color: #1a202c;
        font-weight: 600;
    }

    .club-field-wide {
        grid-column: span 2;
        background: #f8f9fa;
        padding: 4px 8px;
        border-radius: 4px;
    }

    /* Mobile: Simple list view */
    @media (max-width: 768px) {
        .license-card-compact {
            border-radius: 6px;
            margin-bottom: 8px;
        }

        .license-card-content {
            padding: 8px 10px;
        }

        .rider-name-line {
            font-size: 13px;
        }

        .license-id {
            font-size: 9px;
            margin-bottom: 6px;
        }

        .license-info-compact {
            gap: 4px;
        }

        .info-field-compact, .club-field-wide {
            padding: 3px 6px;
        }

        .info-value-compact {
            font-size: 11px;
        }
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
                    Börja skriva minst 2 tecken för att hitta deltagare<br>
                    <span style="font-size: 0.875rem; color: #9ca3af;">
                        (<?= number_format($total_count, 0, ',', ' ') ?> deltagare i databasen)
                    </span>
                </p>
            </div>

            <!-- Riders Grid - Will be populated via search -->
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

                            <!-- Card Content -->
                            <div class="license-card-content">
                                <!-- Name -->
                                <div class="rider-name-line">
                                    <?= h($rider['firstname']) ?> <?= h($rider['lastname']) ?>
                                </div>

                                <!-- License ID -->
                                <div class="license-id">
                                    <?php if ($isUciLicense): ?>
                                        UCI <?= h($rider['license_number']) ?>
                                    <?php elseif (!empty($rider['license_number'])): ?>
                                        <?= h($rider['license_number']) ?>
                                    <?php endif; ?>
                                </div>

                                <!-- Essential Info Grid -->
                                <div class="license-info-compact">
                                    <div class="info-field-compact">
                                        <div class="info-label-compact">Ålder</div>
                                        <div class="info-value-compact">
                                            <?= $age !== null ? $age : '–' ?>
                                        </div>
                                    </div>

                                    <div class="info-field-compact">
                                        <div class="info-label-compact">Kön</div>
                                        <div class="info-value-compact">
                                            <?php
                                            if ($rider['gender'] === 'M') {
                                                echo 'Man';
                                            } elseif (in_array($rider['gender'], ['F', 'K'])) {
                                                echo 'Kvinna';
                                            } else {
                                                echo '–';
                                            }
                                            ?>
                                        </div>
                                    </div>

                                    <!-- Club -->
                                    <div class="club-field-wide">
                                        <div class="info-label-compact">Klubb</div>
                                        <div class="info-value-compact" style="font-size: 11px;">
                                            <?= $rider['club_name'] ? h($rider['club_name']) : '–' ?>
                                        </div>
                                    </div>
                                </div>
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
    // Search functionality - optimized for 3000+ riders
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

            // Require at least 2 characters for search
            if (search.length < 2) {
                if (initialMessage) initialMessage.style.display = 'block';
                ridersGrid.style.display = 'none';
                if (resultsCounter) resultsCounter.style.display = 'none';
                if (noResults) noResults.style.display = 'none';
                return;
            }

            // Hide initial message and show grid
            if (initialMessage) initialMessage.style.display = 'none';
            ridersGrid.style.display = 'grid';

            let visibleCount = 0;

            // Filter cards
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

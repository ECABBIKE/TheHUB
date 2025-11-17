<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config.php';

$db = getDB();

// Get rider ID from URL
$riderId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$riderId) {
    header('Location: riders.php');
    exit;
}

// Fetch rider details
// IMPORTANT: Only select PUBLIC fields - never expose private data (personnummer, address, phone, etc.)
// Try to select new fields, fall back to old schema if they don't exist
try {
    $rider = $db->getRow("
        SELECT
            r.id,
            r.firstname,
            r.lastname,
            r.birth_year,
            r.gender,
            r.club_id,
            r.license_number,
            r.license_type,
            r.license_category,
            r.license_year,
            r.discipline,
            r.license_valid_until,
            r.city,
            r.active,
            c.name as club_name,
            c.city as club_city
        FROM riders r
        LEFT JOIN clubs c ON r.club_id = c.id
        WHERE r.id = ?
    ", [$riderId]);

    // Set default values for fields that might not exist in database yet
    // license_year is now in the SELECT above
    $rider['team'] = $rider['team'] ?? null;
    $rider['disciplines'] = $rider['disciplines'] ?? null;
    $rider['country'] = $rider['country'] ?? null;
    $rider['district'] = $rider['district'] ?? null;
    $rider['photo'] = $rider['photo'] ?? null;

} catch (Exception $e) {
    // If even basic query fails, something else is wrong
    error_log("Error fetching rider: " . $e->getMessage());
    die("Database error: " . htmlspecialchars($e->getMessage()));
}

if (!$rider) {
    die("Deltagare med ID {$riderId} finns inte i databasen. <a href='riders.php'>Gå tillbaka till deltagarlistan</a>");
}

// Fetch rider's results with event details
$results = $db->getAll("
    SELECT
        res.*,
        e.name as event_name,
        e.date as event_date,
        e.location as event_location,
        e.series_id,
        s.name as series_name,
        v.name as venue_name,
        v.city as venue_city
    FROM results res
    INNER JOIN events e ON res.event_id = e.id
    LEFT JOIN series s ON e.series_id = s.id
    LEFT JOIN venues v ON e.venue_id = v.id
    WHERE res.cyclist_id = ?
    ORDER BY e.date DESC
", [$riderId]);

// Calculate statistics
$totalRaces = count($results);
$podiums = 0;
$wins = 0;
$bestPosition = null;
$totalPoints = 0;
$dnfCount = 0;

foreach ($results as $result) {
    if ($result['position']) {
        if ($result['position'] == 1) $wins++;
        if ($result['position'] <= 3) $podiums++;
        if ($bestPosition === null || $result['position'] < $bestPosition) {
            $bestPosition = $result['position'];
        }
    }
    $totalPoints += $result['points'] ?? 0;
    if ($result['status'] === 'dnf') $dnfCount++;
}

// Get recent results (last 5)
$recentResults = array_slice($results, 0, 5);

// Get series standings for this rider - CLASS BASED
// OPTIMIZED: Only run if rider has results to avoid expensive class calculations
$seriesStandings = [];

if ($totalRaces > 0 && $rider['birth_year'] && $rider['gender']) {
    try {
        $riderClassId = determineRiderClass($db, $rider['birth_year'], $rider['gender'], date('Y-m-d'));

        if ($riderClassId) {
            $riderClass = $db->getRow("SELECT name, display_name FROM classes WHERE id = ?", [$riderClassId]);

            // Get series data for this rider
            $riderSeriesData = $db->getAll("
                SELECT
                    s.id as series_id,
                    s.name as series_name,
                    s.year,
                    SUM(r.points) as total_points,
                    COUNT(DISTINCT r.event_id) as events_count
                FROM results r
                JOIN events e ON r.event_id = e.id
                JOIN series s ON e.series_id = s.id
                WHERE r.cyclist_id = ? AND s.active = 1
                GROUP BY s.id
                ORDER BY s.year DESC, total_points DESC
            ", [$riderId]);

            // Calculate class-based position for each series (simplified)
            foreach ($riderSeriesData as $seriesData) {
                $seriesData['position'] = '?';
                $seriesData['class_total'] = 0;
                $seriesData['class_name'] = $riderClass['display_name'] ?? '';
                $seriesStandings[] = $seriesData;
            }
        }
    } catch (Exception $e) {
        error_log("Error calculating series standings for rider {$riderId}: " . $e->getMessage());
        // Continue without series standings
    }
}

// Get results by year
$resultsByYear = [];
foreach ($results as $result) {
    $year = date('Y', strtotime($result['event_date']));
    if (!isset($resultsByYear[$year])) {
        $resultsByYear[$year] = [];
    }
    $resultsByYear[$year][] = $result;
}
krsort($resultsByYear); // Sort by year descending

// Calculate age and determine current class
try {
    require_once __DIR__ . '/includes/class-calculations.php';
} catch (Exception $e) {
    error_log("Error loading class-calculations.php: " . $e->getMessage());
}

$currentYear = date('Y');
$age = ($rider['birth_year'] && $rider['birth_year'] > 0)
    ? ($currentYear - $rider['birth_year'])
    : null;
$currentClass = null;
$currentClassName = null;

if ($rider['birth_year'] && $rider['gender'] && function_exists('determineRiderClass')) {
    try {
        $classId = determineRiderClass($db, $rider['birth_year'], $rider['gender'], date('Y-m-d'));
        if ($classId) {
            $class = $db->getRow("SELECT name, display_name FROM classes WHERE id = ?", [$classId]);
            $currentClass = $class['name'] ?? null;
            $currentClassName = $class['display_name'] ?? null;
        }
    } catch (Exception $e) {
        error_log("Error determining class for rider {$riderId}: " . $e->getMessage());
    }
}

// Check license status
try {
    $licenseCheck = checkLicense($rider);
} catch (Exception $e) {
    error_log("Error checking license for rider {$riderId}: " . $e->getMessage());
    $licenseCheck = array('class' => 'gs-badge-secondary', 'message' => 'Okänd status', 'valid' => false);
}

$pageTitle = $rider['firstname'] . ' ' . $rider['lastname'];
$pageType = 'public';

try {
    include __DIR__ . '/includes/layout-header.php';
} catch (Exception $e) {
    error_log("Error loading layout-header.php: " . $e->getMessage());
    // Provide minimal HTML if header fails
    echo "<!DOCTYPE html><html><head><title>" . htmlspecialchars($pageTitle) . "</title></head><body>";
    echo "<h1>Error loading page header</h1><pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
}
?>

<style>
    /* COMPACT HORIZONTAL CARD with Photo */
    .license-card-container {
        margin-bottom: 2rem;
    }

    .license-card {
        max-width: 900px;
        width: 100%;
        margin: 0 auto;
        background: white;
        border-radius: 12px;
        box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1);
        overflow: hidden;
    }

    /* UCI Stripe */
    .uci-stripe {
        height: 6px;
        background: linear-gradient(90deg,
            #c8161b 0% 20%,
            #8a9859 20% 40%,
            #fcce05 40% 60%,
            #207fc0 60% 80%,
            #ee7622 80% 100%
        );
    }

    /* Desktop: Larger Photo and Better Layout */
    .license-content {
        padding: 24px;
        display: grid;
        grid-template-columns: 180px 1fr;
        gap: 32px;
        align-items: start;
    }

    /* Photo Section - Desktop: Larger */
    .license-photo {
        width: 180px;
        height: 240px;
        border-radius: 8px;
        overflow: hidden;
        background: linear-gradient(135deg, #f3f4f6 0%, #e5e7eb 100%);
        display: flex;
        align-items: center;
        justify-content: center;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    }

    .license-photo img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .photo-placeholder {
        font-size: 4rem;
        opacity: 0.3;
    }

    /* Info Section - Desktop: Better spacing */
    .license-info {
        display: flex;
        flex-direction: column;
        gap: 12px;
    }

    /* Name - Desktop: Larger */
    .rider-name {
        font-size: 28px;
        font-weight: 700;
        color: #1a202c;
        text-transform: uppercase;
        letter-spacing: -0.5px;
        line-height: 1.2;
        margin: 0;
    }

    /* License ID - Desktop: Larger */
    .license-id {
        font-size: 14px;
        color: #667eea;
        font-weight: 600;
    }

    /* License Type and Status */
    .license-info-inline {
        display: flex;
        gap: 12px;
        align-items: center;
        flex-wrap: wrap;
    }

    .license-type-text {
        font-size: 14px;
        color: #718096;
        font-weight: 600;
    }

    .license-status-badge {
        display: inline-block;
        padding: 4px 12px;
        border-radius: 12px;
        font-size: 12px;
        font-weight: 600;
        text-transform: uppercase;
    }

    .license-status-badge.active {
        background: #10b981;
        color: white;
    }

    .license-status-badge.inactive {
        background: #ef4444;
        color: white;
    }

    /* Info Grid - Desktop: Better spacing */
    .info-grid-compact {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 12px;
    }

    .info-box {
        background: #f8f9fa;
        padding: 12px 16px;
        border-radius: 8px;
        text-align: center;
        border-left: 3px solid #667eea;
    }

    .info-box-label {
        font-size: 11px;
        color: #718096;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-bottom: 4px;
    }

    .info-box-value {
        font-size: 16px;
        color: #1a202c;
        font-weight: 700;
    }

    /* Club Link Styling */
    .club-link {
        color: #667eea;
        text-decoration: none;
        font-weight: 700;
        transition: color 0.2s, text-decoration 0.2s;
    }

    .club-link:hover {
        color: #764ba2;
        text-decoration: underline;
    }

    /* Statistics Cards - Equal Width on Desktop */
    .gs-stat-card-compact {
        min-height: 120px;
        display: flex;
        flex-direction: column;
        justify-content: center;
        align-items: center;
        padding: 1rem;
    }

    /* Tablet */
    @media (max-width: 900px) {
        .license-card-container {
            margin-bottom: 1rem;
        }

        .license-card {
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .uci-stripe {
            height: 4px;
        }

        .license-content {
            grid-template-columns: 100px 1fr;
            gap: 16px;
            padding: 12px;
        }

        .license-photo {
            width: 100px;
            height: 120px;
            border-radius: 6px;
        }

        .photo-placeholder {
            font-size: 3rem;
        }

        .license-info {
            gap: 6px;
        }

        .rider-name {
            font-size: 18px;
            letter-spacing: -0.2px;
        }

        .license-id {
            font-size: 11px;
        }

        .license-type-text {
            font-size: 11px;
        }

        .license-status-badge {
            font-size: 10px;
            padding: 2px 8px;
        }

        .info-grid-compact {
            gap: 6px;
        }

        .info-box {
            padding: 4px 8px;
        }

        .info-box-label {
            font-size: 8px;
            margin-bottom: 2px;
        }

        .info-box-value {
            font-size: 12px;
        }
    }

    /* Mobile - Smaller photo */
    @media (max-width: 640px) {
        .license-content {
            grid-template-columns: 60px 1fr;
            gap: 10px;
            padding: 8px;
        }

        .license-photo {
            width: 60px;
            height: 75px;
        }

        .photo-placeholder {
            font-size: 2rem;
        }

        .rider-name {
            font-size: 14px;
        }

        .license-id {
            font-size: 9px;
        }

        .license-type-text {
            font-size: 9px;
        }

        .license-status-badge {
            font-size: 8px;
            padding: 2px 6px;
        }

        .info-box {
            padding: 3px 6px;
        }

        .info-box-label {
            font-size: 7px;
        }

        .info-box-value {
            font-size: 10px;
        }

        .info-grid-compact {
            grid-template-columns: repeat(auto-fit, minmax(70px, 1fr));
        }
    }
</style>

    <main class="gs-main-content">
        <div class="gs-container">

            <!-- Back Button -->
            <div class="gs-mb-lg">
                <a href="/riders.php" class="gs-btn gs-btn-outline gs-btn-sm">
                    <i data-lucide="arrow-left"></i>
                    Tillbaka till deltagare
                </a>
            </div>

            <!-- UCI License Card - COMPACT HORIZONTAL -->
            <div class="license-card-container">
                <div class="license-card">
                    <!-- Stripe -->
                    <div class="uci-stripe"></div>

                    <!-- Compact Content: Photo LEFT + Info RIGHT -->
                    <div class="license-content">
                        <!-- Photo Section LEFT -->
                        <div class="license-photo">
                            <?php if (!empty($rider['photo'])): ?>
                                <img src="<?= h($rider['photo']) ?>" alt="<?= h($rider['firstname'] . ' ' . $rider['lastname']) ?>">
                            <?php else: ?>
                                <div class="photo-placeholder">👤</div>
                            <?php endif; ?>
                        </div>

                        <!-- Info Section RIGHT -->
                        <div class="license-info">
                            <!-- Name on one line -->
                            <div class="rider-name">
                                <?= h($rider['firstname']) ?> <?= h($rider['lastname']) ?>
                            </div>

                            <!-- UCI License under name -->
                            <div class="license-id">
                                <?php
                                $isUciLicense = !empty($rider['license_number']) && strpos($rider['license_number'], 'SWE') !== 0;
                                if ($isUciLicense): ?>
                                    UCI: <?= h($rider['license_number']) ?>
                                <?php elseif (!empty($rider['license_number'])): ?>
                                    Licens: <?= h($rider['license_number']) ?>
                                <?php endif; ?>
                            </div>

                            <!-- License Type and Active Status -->
                            <?php if (!empty($rider['license_type']) && $rider['license_type'] !== 'None'): ?>
                                <div class="license-info-inline">
                                    <span class="license-type-text"><?= h($rider['license_type']) ?></span>
                                    <?php if (!empty($rider['license_year'])): ?>
                                        <?php
                                        $isActive = ($rider['license_year'] == $currentYear);
                                        ?>
                                        <span class="license-status-badge <?= $isActive ? 'active' : 'inactive' ?>">
                                            <?= $isActive ? '✓ Aktiv ' . $currentYear : '✗ ' . $rider['license_year'] ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <!-- Compact Info: Age, Gender, Club -->
                            <div class="info-grid-compact">
                                <div class="info-box">
                                    <div class="info-box-label">Ålder</div>
                                    <div class="info-box-value">
                                        <?= $age !== null ? $age : '–' ?>
                                    </div>
                                </div>

                                <div class="info-box">
                                    <div class="info-box-label">Kön</div>
                                    <div class="info-box-value">
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

                                <div class="info-box">
                                    <div class="info-box-label">Klubb</div>
                                    <div class="info-box-value" style="font-size: 11px;">
                                        <?php if ($rider['club_name'] && $rider['club_id']): ?>
                                            <a href="/club.php?id=<?= $rider['club_id'] ?>" class="club-link" title="Se alla medlemmar i <?= h($rider['club_name']) ?>">
                                                <?= h($rider['club_name']) ?>
                                            </a>
                                        <?php else: ?>
                                            <?= $rider['club_name'] ? h($rider['club_name']) : '–' ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div><!-- .license-info -->
                    </div><!-- .license-content -->
                </div>
            </div>

            <!-- Quick Stats -->
            <div class="gs-grid gs-grid-cols-2 gs-md-grid-cols-4 gs-gap-sm gs-mb-xl">
                <div class="gs-card gs-stat-card-compact">
                    <div class="gs-stat-number-compact gs-text-primary"><?= $totalRaces ?></div>
                    <div class="gs-stat-label-compact">Race</div>
                </div>
                <div class="gs-card gs-stat-card-compact">
                    <div class="gs-stat-number-compact" style="color: var(--gs-success);"><?= $wins ?></div>
                    <div class="gs-stat-label-compact">Segrar</div>
                </div>
                <div class="gs-card gs-stat-card-compact">
                    <div class="gs-stat-number-compact" style="color: var(--gs-warning);"><?= $bestPosition ?? '-' ?></div>
                    <div class="gs-stat-label-compact">Bästa placering</div>
                </div>
                <div class="gs-card gs-stat-card-compact">
                    <div style="display: flex; flex-direction: column; gap: 0.25rem;">
                        <?php if (!empty($seriesStandings)): ?>
                            <?php foreach ($seriesStandings as $standing): ?>
                                <div style="font-size: 9px; line-height: 1.3;">
                                    <strong><?= h($standing['series_name']) ?>:</strong>
                                    #<?= $standing['position'] ?? '?' ?> (<?= $standing['total_points'] ?>p)
                                    <br>
                                    <span style="color: #718096; font-size: 8px;">
                                        <?= h($standing['class_name']) ?> (<?= $standing['class_total'] ?> deltagare)
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="gs-stat-number-compact gs-text-primary">0</div>
                        <?php endif; ?>
                    </div>
                    <div class="gs-stat-label-compact" style="margin-top: 0.25rem;">Points</div>
                </div>
            </div>

            <?php if (empty($results)): ?>
                <!-- No Results -->
                <div class="gs-card gs-text-center" style="padding: 3rem;">
                    <i data-lucide="trophy" style="width: 64px; height: 64px; margin: 0 auto 1rem; opacity: 0.3;"></i>
                    <h3 class="gs-h4 gs-mb-sm">Inga resultat ännu</h3>
                    <p class="gs-text-secondary">
                        Denna deltagare har inte några tävlingsresultat uppladdat.
                    </p>
                </div>
            <?php else: ?>
                <!-- Results by Year -->
                <div class="gs-card gs-mb-xl">
                    <div class="gs-card-header">
                        <h2 class="gs-h4 gs-text-primary">
                            <i data-lucide="trophy"></i>
                            Tävlingsresultat (<?= $totalRaces ?>)
                        </h2>
                    </div>
                    <div class="gs-card-content">
                        <?php foreach ($resultsByYear as $year => $yearResults): ?>
                            <div class="gs-mb-xl">
                                <h3 class="gs-h5 gs-text-primary gs-mb-md">
                                    <i data-lucide="calendar"></i>
                                    <?= $year ?> (<?= count($yearResults) ?> lopp)
                                </h3>

                                <div class="gs-table-responsive">
                                    <table class="gs-table">
                                        <thead>
                                            <tr>
                                                <th>Datum</th>
                                                <th>Tävling</th>
                                                <th>Plats</th>
                                                <th>Serie</th>
                                                <th style="text-align: center;">Placering</th>
                                                <th style="text-align: center;">Tid</th>
                                                <th style="text-align: center;">Poäng</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($yearResults as $result): ?>
                                                <tr>
                                                    <td><?= date('Y-m-d', strtotime($result['event_date'])) ?></td>
                                                    <td>
                                                        <?php if ($result['event_id']): ?>
                                                            <a href="/event.php?id=<?= $result['event_id'] ?>" class="gs-link">
                                                                <strong><?= h($result['event_name']) ?></strong>
                                                            </a>
                                                        <?php else: ?>
                                                            <strong><?= h($result['event_name']) ?></strong>
                                                        <?php endif; ?>
                                                        <?php if ($result['venue_name']): ?>
                                                            <br><span class="gs-text-xs gs-text-secondary">
                                                                <i data-lucide="map-pin"></i>
                                                                <?= h($result['venue_name']) ?>
                                                            </span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if ($result['event_location']): ?>
                                                            <?= h($result['event_location']) ?>
                                                        <?php elseif ($result['venue_city']): ?>
                                                            <?= h($result['venue_city']) ?>
                                                        <?php else: ?>
                                                            -
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if ($result['series_name'] && $result['series_id']): ?>
                                                            <a href="/series-standings.php?id=<?= $result['series_id'] ?>" class="gs-badge gs-badge-primary gs-badge-sm" style="text-decoration: none;">
                                                                <?= h($result['series_name']) ?>
                                                            </a>
                                                        <?php elseif ($result['series_name']): ?>
                                                            <span class="gs-badge gs-badge-primary gs-badge-sm">
                                                                <?= h($result['series_name']) ?>
                                                            </span>
                                                        <?php else: ?>
                                                            -
                                                        <?php endif; ?>
                                                    </td>
                                                    <td style="text-align: center;">
                                                        <?php if ($result['position']): ?>
                                                            <?php if ($result['position'] == 1): ?>
                                                                <span class="gs-badge gs-badge-success" style="font-weight: bold;">🥇 1</span>
                                                            <?php elseif ($result['position'] == 2): ?>
                                                                <span class="gs-badge gs-badge-secondary" style="font-weight: bold;">🥈 2</span>
                                                            <?php elseif ($result['position'] == 3): ?>
                                                                <span class="gs-badge gs-badge-warning" style="font-weight: bold;">🥉 3</span>
                                                            <?php else: ?>
                                                                <span><?= $result['position'] ?></span>
                                                            <?php endif; ?>
                                                        <?php else: ?>
                                                            -
                                                        <?php endif; ?>
                                                    </td>
                                                    <td style="text-align: center;">
                                                        <?php
                                                        if ($result['finish_time']) {
                                                            // Format time: remove leading hours if 0
                                                            $time = $result['finish_time'];
                                                            // Check if time starts with "00:" or "0:"
                                                            if (preg_match('/^0?0:/', $time)) {
                                                                // Remove leading "00:" or "0:"
                                                                $time = preg_replace('/^0?0:/', '', $time);
                                                            }
                                                            echo h($time);
                                                        } else {
                                                            echo '-';
                                                        }
                                                        ?>
                                                    </td>
                                                    <td style="text-align: center;">
                                                        <?= $result['points'] ?? 0 ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>

<?php include __DIR__ . '/includes/layout-footer.php'; ?>

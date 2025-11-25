<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/class-calculations.php';
require_once __DIR__ . '/includes/ranking_functions.php';

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
            r.gravity_id,
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

// Fetch rider's results with event details and class position
$results = $db->getAll("
    SELECT
        res.*,
        e.name as event_name,
        e.date as event_date,
        e.location as event_location,
        e.series_id,
        s.name as series_name,
        v.name as venue_name,
        v.city as venue_city,
        cls.name as class_name,
        cls.display_name as class_display_name,
        COALESCE(cls.awards_points, 1) as awards_points,
        COALESCE(cls.series_eligible, 1) as series_eligible,
        (
            SELECT COUNT(*) + 1
            FROM results r2
            WHERE r2.event_id = res.event_id
            AND r2.class_id = res.class_id
            AND r2.status = 'finished'
            AND r2.id != res.id
            AND (
                CASE
                    WHEN r2.finish_time LIKE '%:%:%' THEN
                        CAST(SUBSTRING_INDEX(r2.finish_time, ':', 1) AS DECIMAL(10,2)) * 3600 +
                        CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(r2.finish_time, ':', 2), ':', -1) AS DECIMAL(10,2)) * 60 +
                        CAST(SUBSTRING_INDEX(r2.finish_time, ':', -1) AS DECIMAL(10,2))
                    ELSE
                        CAST(SUBSTRING_INDEX(r2.finish_time, ':', 1) AS DECIMAL(10,2)) * 60 +
                        CAST(SUBSTRING_INDEX(r2.finish_time, ':', -1) AS DECIMAL(10,2))
                END
                <
                CASE
                    WHEN res.finish_time LIKE '%:%:%' THEN
                        CAST(SUBSTRING_INDEX(res.finish_time, ':', 1) AS DECIMAL(10,2)) * 3600 +
                        CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(res.finish_time, ':', 2), ':', -1) AS DECIMAL(10,2)) * 60 +
                        CAST(SUBSTRING_INDEX(res.finish_time, ':', -1) AS DECIMAL(10,2))
                    ELSE
                        CAST(SUBSTRING_INDEX(res.finish_time, ':', 1) AS DECIMAL(10,2)) * 60 +
                        CAST(SUBSTRING_INDEX(res.finish_time, ':', -1) AS DECIMAL(10,2))
                END
            )
        ) as class_position
    FROM results res
    INNER JOIN events e ON res.event_id = e.id
    LEFT JOIN series s ON e.series_id = s.id
    LEFT JOIN venues v ON e.venue_id = v.id
    LEFT JOIN classes cls ON res.class_id = cls.id
    WHERE res.cyclist_id = ?
    ORDER BY e.date DESC
", [$riderId]);

// Calculate statistics based on class position
$totalRaces = 0;
$podiums = 0;
$wins = 0;
$bestPosition = null;
$totalPoints = 0;
$dnfCount = 0;

foreach ($results as $result) {
    // Skip DNS in statistics - they didn't actually race
    if ($result['status'] === 'dns') continue;

    $totalRaces++;

    // Only count competitive stats for classes that award points
    $awardsPoints = $result['awards_points'] ?? 1;

    // Use class_position for statistics (position within rider's class)
    $classPos = $result['class_position'] ?? null;
    if ($result['status'] === 'finished' && $classPos && $awardsPoints) {
        if ($classPos == 1) $wins++;
        if ($classPos <= 3) $podiums++;
        if ($bestPosition === null || $classPos < $bestPosition) {
            $bestPosition = $classPos;
        }
    }

    // Only count points if class awards points
    if ($awardsPoints) {
        $totalPoints += $result['points'] ?? 0;
    }

    if ($result['status'] === 'dnf') $dnfCount++;
}

// Get recent results (last 5)
$recentResults = array_slice($results, 0, 5);

// Get GravitySeries Total stats (individual championship)
$gravityTotalStats = null;
$gravityTotalPosition = null;
$gravityTotalClassTotal = 0;
$currentClassName = null;

// Get GravitySeries Team stats (club points)
$gravityTeamStats = null;
$gravityTeamPosition = null;
$gravityTeamClassTotal = 0;

if ($totalRaces > 0) {
    try {
        // Find GravitySeries Total series (id=8, or by name patterns)
        $totalSeries = $db->getRow("
            SELECT id, name FROM series
            WHERE id = 8
            OR (
                active = 1
                AND (
                    name LIKE '%Total%'
                    OR (name LIKE '%GravitySeries%' AND name NOT LIKE '%Capital%' AND name NOT LIKE '%Götaland%' AND name NOT LIKE '%Jämtland%')
                )
            )
            ORDER BY (id = 8) DESC, year DESC LIMIT 1
        ");

        if ($totalSeries) {
            // Get rider's points in GravitySeries Total (via series_events)
            $gravityTotalStats = $db->getRow("
                SELECT SUM(r.points) as total_points, COUNT(DISTINCT r.event_id) as events_count
                FROM results r
                JOIN events e ON r.event_id = e.id
                JOIN series_events se ON e.id = se.event_id
                WHERE se.series_id = ? AND r.cyclist_id = ?
                AND r.status = 'finished' AND r.points > 0
            ", [$totalSeries['id'], $riderId]);

            // Get rider's most common class from their results in this series (for display)
            $riderResultClass = $db->getRow("
                SELECT r.class_id, c.name, c.display_name, COUNT(*) as cnt
                FROM results r
                JOIN events e ON r.event_id = e.id
                JOIN series_events se ON e.id = se.event_id
                LEFT JOIN classes c ON r.class_id = c.id
                WHERE se.series_id = ? AND r.cyclist_id = ?
                AND r.status = 'finished' AND r.class_id IS NOT NULL
                GROUP BY r.class_id
                ORDER BY cnt DESC
                LIMIT 1
            ", [$totalSeries['id'], $riderId]);

            if ($riderResultClass) {
                $currentClassName = $riderResultClass['display_name'] ?? $riderResultClass['name'] ?? null;
            }

            // Get overall position in series (all classes combined)
            $seriesStandingsAll = $db->getAll("
                SELECT r.cyclist_id, SUM(r.points) as total_points
                FROM results r
                JOIN events e ON r.event_id = e.id
                JOIN series_events se ON e.id = se.event_id
                WHERE se.series_id = ?
                AND r.status = 'finished' AND r.points > 0
                GROUP BY r.cyclist_id
                ORDER BY total_points DESC
            ", [$totalSeries['id']]);

            $gravityTotalClassTotal = count($seriesStandingsAll);
            $position = 1;
            foreach ($seriesStandingsAll as $standing) {
                if ($standing['cyclist_id'] == $riderId) {
                    $gravityTotalPosition = $position;
                    break;
                }
                $position++;
            }

            // Get GravitySeries Team stats (club points for this series)
            // Simple direct query - club_rider_points already has series_id
            if ($rider['club_id']) {
                $gravityTeamStats = $db->getRow("
                    SELECT SUM(club_points) as total_points, COUNT(DISTINCT event_id) as events_count
                    FROM club_rider_points
                    WHERE rider_id = ? AND club_id = ? AND series_id = ?
                ", [$riderId, $rider['club_id'], $totalSeries['id']]);

                // Get rider's position within the club for this series
                if ($gravityTeamStats && $gravityTeamStats['total_points'] > 0) {
                    $clubStandings = $db->getAll("
                        SELECT rider_id, SUM(club_points) as total_points
                        FROM club_rider_points
                        WHERE club_id = ? AND series_id = ?
                        GROUP BY rider_id
                        ORDER BY total_points DESC
                    ", [$rider['club_id'], $totalSeries['id']]);

                    $gravityTeamClassTotal = count($clubStandings);
                    $position = 1;
                    foreach ($clubStandings as $standing) {
                        if ($standing['rider_id'] == $riderId) {
                            $gravityTeamPosition = $position;
                            break;
                        }
                        $position++;
                    }
                }
            }
        }
    } catch (Exception $e) {
        error_log("Error getting GravitySeries stats for rider {$riderId}: " . $e->getMessage());
    }
}

// Get ranking statistics for all disciplines
$rankingStats = [];
try {
    foreach (['ENDURO', 'DH', 'GRAVITY'] as $discipline) {
        $riderData = calculateRankingData($db, $discipline, false);

        // Find this rider's ranking
        foreach ($riderData as $data) {
            if ($data['rider_id'] == $riderId) {
                $rankingStats[$discipline] = $data;
                break;
            }
        }
    }
} catch (Exception $e) {
    error_log("Error getting ranking stats for rider {$riderId}: " . $e->getMessage());
}

// Get series standings for this rider - CLASS BASED
// OPTIMIZED: Only run if rider has results to avoid expensive class calculations
$seriesStandings = [];

if ($totalRaces > 0 && $rider['birth_year'] && $rider['gender']) {
    try {
        // Try ENDURO first (most common for GravitySeries), then fallback to other disciplines
        $riderClassId = determineRiderClass($db, $rider['birth_year'], $rider['gender'], date('Y-m-d'), 'ENDURO');
        if (!$riderClassId) {
            $riderClassId = determineRiderClass($db, $rider['birth_year'], $rider['gender'], date('Y-m-d'), 'DH');
        }
        if (!$riderClassId) {
            $riderClassId = determineRiderClass($db, $rider['birth_year'], $rider['gender'], date('Y-m-d'), 'XC');
        }

        if ($riderClassId) {
            $riderClass = $db->getRow("SELECT name, display_name FROM classes WHERE id = ?", [$riderClassId]);

            // Get series data for this rider (supports both connection methods)
            $riderSeriesData = $db->getAll("
                SELECT
                    series_id,
                    series_name,
                    year,
                    SUM(points) as total_points,
                    COUNT(DISTINCT event_id) as events_count
                FROM (
                    -- Via series_events junction table
                    SELECT
                        s.id as series_id,
                        s.name as series_name,
                        s.year,
                        r.points,
                        r.event_id
                    FROM results r
                    JOIN events e ON r.event_id = e.id
                    JOIN series_events se ON e.id = se.event_id
                    JOIN series s ON se.series_id = s.id
                    WHERE r.cyclist_id = ? AND s.active = 1

                    UNION

                    -- Via direct events.series_id
                    SELECT
                        s.id as series_id,
                        s.name as series_name,
                        s.year,
                        r.points,
                        r.event_id
                    FROM results r
                    JOIN events e ON r.event_id = e.id
                    JOIN series s ON e.series_id = s.id
                    WHERE r.cyclist_id = ? AND s.active = 1
                    AND e.series_id IS NOT NULL
                ) combined
                GROUP BY series_id
                ORDER BY year DESC, total_points DESC
            ", [$riderId, $riderId]);

            // Calculate class-based position for each series
            foreach ($riderSeriesData as $seriesData) {
                // Get all riders in this series with the same class and their total points
                $classStandings = $db->getAll("
                    SELECT
                        cyclist_id,
                        SUM(points) as total_points
                    FROM (
                        -- Via series_events junction table
                        SELECT r.cyclist_id, r.points
                        FROM results r
                        JOIN events e ON r.event_id = e.id
                        JOIN series_events se ON e.id = se.event_id
                        WHERE se.series_id = ?
                        AND r.class_id = ?

                        UNION ALL

                        -- Via direct events.series_id
                        SELECT r.cyclist_id, r.points
                        FROM results r
                        JOIN events e ON r.event_id = e.id
                        WHERE e.series_id = ?
                        AND r.class_id = ?
                    ) combined
                    GROUP BY cyclist_id
                    ORDER BY total_points DESC
                ", [$seriesData['series_id'], $riderClassId, $seriesData['series_id'], $riderClassId]);

                // Find this rider's position
                $position = 1;
                $classTotal = count($classStandings);
                foreach ($classStandings as $standing) {
                    if ($standing['cyclist_id'] == $riderId) {
                        break;
                    }
                    $position++;
                }

                $seriesData['position'] = $position;
                $seriesData['class_total'] = $classTotal;
                $seriesData['class_name'] = $riderClass['display_name'] ?? '';
                $seriesStandings[] = $seriesData;
            }
        }
    } catch (Exception $e) {
        error_log("Error calculating series standings for rider {$riderId}: " . $e->getMessage());
        // Continue without series standings
    }
}

// Get results by year (exclude DNS - Did Not Start)
$resultsByYear = [];
foreach ($results as $result) {
    // Skip DNS results - they shouldn't be shown at all
    if ($result['status'] === 'dns') continue;

    $year = date('Y', strtotime($result['event_date']));
    if (!isset($resultsByYear[$year])) {
        $resultsByYear[$year] = [];
    }
    $resultsByYear[$year][] = $result;
}
krsort($resultsByYear); // Sort by year descending

// Calculate age and determine current class
$currentYear = date('Y');
$age = ($rider['birth_year'] && $rider['birth_year'] > 0)
    ? ($currentYear - $rider['birth_year'])
    : null;
$currentClass = null;
$currentClassName = null;

if ($rider['birth_year'] && $rider['gender'] && function_exists('determineRiderClass')) {
    try {
        // Try ENDURO first (most common for GravitySeries), then fallback
        $classId = determineRiderClass($db, $rider['birth_year'], $rider['gender'], date('Y-m-d'), 'ENDURO');
        if (!$classId) {
            $classId = determineRiderClass($db, $rider['birth_year'], $rider['gender'], date('Y-m-d'), 'DH');
        }
        if (!$classId) {
            $classId = determineRiderClass($db, $rider['birth_year'], $rider['gender'], date('Y-m-d'), 'XC');
        }
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

                            <!-- Gravity ID Badge -->
                            <?php if (!empty($rider['gravity_id'])): ?>
                                <div class="gravity-id-badge">
                                    <span class="gravity-id-icon">★</span>
                                    <span class="gravity-id-text">Gravity ID: <?= h($rider['gravity_id']) ?></span>
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
                                    <div class="info-box-value info-box-value-small">
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
            <style>
                .rider-stats-top {
                    display: grid;
                    grid-template-columns: repeat(3, 1fr);
                    gap: 0.5rem;
                    margin-bottom: 0.5rem;
                }
                .rider-stats-bottom {
                    display: grid;
                    grid-template-columns: repeat(2, 1fr);
                    gap: 0.5rem;
                    margin-bottom: 1.5rem;
                }
                .rider-stats-top .gs-stat-card-compact,
                .rider-stats-bottom .gs-stat-card-compact {
                    padding: 0.75rem 0.5rem;
                    text-align: center;
                }
                .rider-stats-top .gs-stat-number-compact {
                    font-size: 1.5rem;
                }
                .rider-stats-bottom .gs-stat-number-compact {
                    font-size: 1.25rem;
                }
                .rider-stats-top .gs-stat-label-compact,
                .rider-stats-bottom .gs-stat-label-compact {
                    font-size: 0.625rem;
                }
                @media (min-width: 640px) {
                    .rider-stats-top .gs-stat-number-compact {
                        font-size: 2rem;
                    }
                    .rider-stats-bottom .gs-stat-number-compact {
                        font-size: 1.5rem;
                    }
                    .rider-stats-top .gs-stat-label-compact,
                    .rider-stats-bottom .gs-stat-label-compact {
                        font-size: 0.75rem;
                    }
                }
            </style>
            <div class="rider-stats-top">
                <div class="gs-card gs-stat-card-compact">
                    <div class="gs-stat-number-compact gs-text-primary"><?= $totalRaces ?></div>
                    <div class="gs-stat-label-compact">Race</div>
                </div>
                <div class="gs-card gs-stat-card-compact">
                    <div class="gs-stat-number-compact gs-text-warning"><?= $bestPosition ?? '-' ?></div>
                    <div class="gs-stat-label-compact">Bästa</div>
                </div>
                <div class="gs-card gs-stat-card-compact">
                    <div class="gs-stat-number-compact gs-text-success"><?= $wins ?></div>
                    <div class="gs-stat-label-compact">Segrar</div>
                </div>
            </div>
            <div class="rider-stats-bottom">
                <div class="gs-card gs-stat-card-compact">
                    <div class="gs-stat-number-compact gs-text-primary">
                        <?= $gravityTotalStats ? number_format($gravityTotalStats['total_points'] ?? 0) : '0' ?>
                    </div>
                    <div class="gs-stat-label-compact">GravitySeries Total</div>
                    <?php if ($currentClassName || $gravityTotalPosition): ?>
                        <div class="gs-text-xs gs-text-secondary gs-mt-xs">
                            <?php if ($currentClassName): ?>
                                <?= h($currentClassName) ?>
                                <?php if ($gravityTotalPosition): ?>
                                    • #<?= $gravityTotalPosition ?>/<?= $gravityTotalClassTotal ?>
                                <?php endif; ?>
                            <?php elseif ($gravityTotalPosition): ?>
                                #<?= $gravityTotalPosition ?> av <?= $gravityTotalClassTotal ?>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="gs-card gs-stat-card-compact">
                    <div class="gs-stat-number-compact" style="color: #f59e0b;">
                        <?= $gravityTeamStats ? number_format($gravityTeamStats['total_points'] ?? 0, 1) : '0' ?>
                    </div>
                    <div class="gs-stat-label-compact">GravitySeries Team</div>
                    <?php if ($gravityTeamPosition): ?>
                        <div class="gs-text-xs gs-text-secondary gs-mt-xs">
                            #<?= $gravityTeamPosition ?> av <?= $gravityTeamClassTotal ?> i klubben
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Ranking Statistics -->
            <?php if (!empty($rankingStats)): ?>
                <div class="gs-card gs-mb-xl">
                    <div class="gs-card-header">
                        <h2 class="gs-h4 gs-text-primary">
                            <i data-lucide="trending-up"></i>
                            Rankingstatistik (24 månader)
                        </h2>
                    </div>
                    <div class="gs-card-content">
                        <!-- Discipline Tabs -->
                        <div class="gs-tabs gs-mb-lg">
                            <?php foreach (['GRAVITY' => 'Gravity', 'ENDURO' => 'Enduro', 'DH' => 'Downhill'] as $disc => $label): ?>
                                <?php if (isset($rankingStats[$disc])): ?>
                                    <button class="gs-tab <?= $disc === 'GRAVITY' ? 'active' : '' ?>" data-tab="ranking-<?= strtolower($disc) ?>">
                                        <?= $label ?>
                                    </button>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>

                        <!-- Tab Content -->
                        <?php foreach (['GRAVITY' => 'Gravity', 'ENDURO' => 'Enduro', 'DH' => 'Downhill'] as $disc => $label): ?>
                            <?php if (isset($rankingStats[$disc])): ?>
                                <?php $stats = $rankingStats[$disc]; ?>
                                <div class="gs-tab-content <?= $disc === 'GRAVITY' ? 'active' : '' ?>" id="ranking-<?= strtolower($disc) ?>">

                                    <!-- Stats Grid -->
                                    <div class="gs-ranking-stats-grid gs-mb-lg">
                                        <div class="gs-stat-box">
                                            <div class="gs-stat-label">Placering</div>
                                            <div class="gs-stat-value gs-text-primary">#<?= $stats['ranking_position'] ?></div>
                                        </div>
                                        <div class="gs-stat-box">
                                            <div class="gs-stat-label">Totala poäng</div>
                                            <div class="gs-stat-value gs-text-success"><?= number_format($stats['total_points'], 1) ?></div>
                                        </div>
                                        <div class="gs-stat-box">
                                            <div class="gs-stat-label">Antal events</div>
                                            <div class="gs-stat-value gs-text-warning"><?= $stats['events_count'] ?></div>
                                        </div>
                                    </div>

                                    <!-- Point Breakdown -->
                                    <div class="gs-card gs-bg-light gs-mb-lg">
                                        <div class="gs-card-content">
                                            <h4 class="gs-h5 gs-text-primary gs-mb-md">
                                                <i data-lucide="pie-chart"></i>
                                                Poängfördelning
                                            </h4>
                                            <div class="gs-points-breakdown">
                                                <div class="gs-points-row">
                                                    <span class="gs-points-label">
                                                        <i data-lucide="calendar-check"></i>
                                                        Senaste 12 månader (100%)
                                                    </span>
                                                    <span class="gs-points-value gs-text-success">
                                                        <?= number_format($stats['points_12'], 1) ?> p
                                                    </span>
                                                </div>
                                                <div class="gs-points-row">
                                                    <span class="gs-points-label">
                                                        <i data-lucide="calendar"></i>
                                                        Månad 13-24 (viktade)
                                                    </span>
                                                    <span class="gs-points-value gs-text-secondary">
                                                        <?= number_format($stats['points_13_24'] * 0.5, 1) ?> p
                                                    </span>
                                                </div>
                                                <div class="gs-divider gs-my-sm"></div>
                                                <div class="gs-points-row">
                                                    <span class="gs-points-label gs-font-bold gs-text-lg">
                                                        <i data-lucide="trophy"></i>
                                                        Total ranking
                                                    </span>
                                                    <span class="gs-points-value gs-text-primary gs-font-bold gs-text-lg">
                                                        <?= number_format($stats['total_points'], 1) ?> p
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>

                <style>
                /* Ranking stats grid */
                .gs-ranking-stats-grid {
                    display: grid;
                    grid-template-columns: repeat(3, 1fr);
                    gap: 1rem;
                }

                .gs-stat-box {
                    text-align: center;
                    padding: 1rem;
                    background: var(--gs-gray-50);
                    border-radius: var(--gs-radius-md);
                }

                .gs-stat-label {
                    font-size: 0.875rem;
                    color: var(--gs-text-secondary);
                    margin-bottom: 0.5rem;
                }

                .gs-stat-value {
                    font-size: 2rem;
                    font-weight: 700;
                }

                /* Points breakdown */
                .gs-points-breakdown {
                    display: flex;
                    flex-direction: column;
                    gap: 0.75rem;
                }

                .gs-points-row {
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    padding: 0.5rem 0;
                }

                .gs-points-label {
                    display: flex;
                    align-items: center;
                    gap: 0.5rem;
                    font-size: 0.875rem;
                }

                .gs-points-label i {
                    width: 16px;
                    height: 16px;
                }

                .gs-points-value {
                    font-weight: 600;
                    font-size: 1rem;
                }

                .gs-divider {
                    height: 1px;
                    background: var(--gs-gray-200);
                }

                /* Tabs */
                .gs-tabs {
                    display: flex;
                    gap: 0.5rem;
                    border-bottom: 2px solid var(--gs-gray-200);
                }

                .gs-tab {
                    padding: 0.75rem 1.5rem;
                    background: none;
                    border: none;
                    border-bottom: 3px solid transparent;
                    margin-bottom: -2px;
                    cursor: pointer;
                    font-weight: 500;
                    color: var(--gs-text-secondary);
                    transition: all 0.2s;
                }

                .gs-tab:hover {
                    color: var(--gs-primary);
                }

                .gs-tab.active {
                    color: var(--gs-primary);
                    border-bottom-color: var(--gs-primary);
                }

                .gs-tab-content {
                    display: none;
                }

                .gs-tab-content.active {
                    display: block;
                }

                /* Mobile responsive - VERY COMPACT */
                @media (max-width: 767px) {
                    /* Make everything more compact on mobile */
                    .gs-card {
                        margin-bottom: 0.75rem !important;
                    }

                    .gs-card-content {
                        padding: 0.75rem !important;
                    }

                    .gs-card-header {
                        padding: 0.75rem !important;
                    }

                    .gs-h4 {
                        font-size: 1rem !important;
                    }

                    .gs-h5 {
                        font-size: 0.875rem !important;
                    }

                    /* Compact ranking stats grid */
                    .gs-ranking-stats-grid {
                        grid-template-columns: repeat(3, 1fr);
                        gap: 0.25rem;
                    }

                    .gs-stat-box {
                        padding: 0.4rem 0.2rem;
                    }

                    .gs-stat-label {
                        font-size: 0.65rem;
                        margin-bottom: 0.15rem;
                        text-transform: uppercase;
                    }

                    .gs-stat-value {
                        font-size: 1.1rem;
                        font-weight: 700;
                    }

                    /* Compact tabs */
                    .gs-tabs {
                        flex-direction: row;
                        gap: 0.25rem;
                        border-bottom: 1px solid var(--gs-gray-200);
                    }

                    .gs-tab {
                        padding: 0.5rem 0.75rem;
                        font-size: 0.75rem;
                        border-bottom: 2px solid transparent;
                        margin-bottom: -1px;
                    }

                    .gs-tab.active {
                        border-bottom-color: var(--gs-primary);
                    }

                    /* Compact points breakdown */
                    .gs-points-breakdown {
                        gap: 0.5rem;
                    }

                    .gs-points-row {
                        padding: 0.35rem 0;
                    }

                    .gs-points-label {
                        font-size: 0.75rem;
                        gap: 0.25rem;
                    }

                    .gs-points-label i {
                        width: 14px;
                        height: 14px;
                    }

                    .gs-points-value {
                        font-size: 0.875rem;
                    }

                    /* Compact divider */
                    .gs-divider {
                        margin: 0.25rem 0;
                    }

                    /* Overall compact typography */
                    .gs-text-sm {
                        font-size: 0.75rem !important;
                    }

                    .gs-text-xs {
                        font-size: 0.65rem !important;
                    }

                    /* Reduce margins */
                    .gs-mb-lg {
                        margin-bottom: 0.75rem !important;
                    }

                    .gs-mb-md {
                        margin-bottom: 0.5rem !important;
                    }

                    .gs-mb-sm {
                        margin-bottom: 0.35rem !important;
                    }

                    /* Compact background card */
                    .gs-bg-light {
                        padding: 0.5rem !important;
                    }

                    /* Hide table columns on mobile portrait */
                    table th:nth-child(3),  /* Plats */
                    table td:nth-child(3),
                    table th:nth-child(4),  /* Serie */
                    table td:nth-child(4),
                    table th:nth-child(6),  /* Tid */
                    table td:nth-child(6),
                    table th:nth-child(7),  /* Poäng */
                    table td:nth-child(7) {
                        display: none;
                    }
                }

                /* Mobile landscape: show more columns */
                @media (min-width: 768px) and (max-width: 1024px) and (orientation: landscape) {
                    table th:nth-child(3),  /* Plats */
                    table td:nth-child(3),
                    table th:nth-child(6),  /* Tid */
                    table td:nth-child(6),
                    table th:nth-child(7),  /* Poäng */
                    table td:nth-child(7) {
                        display: table-cell;
                    }

                    table th:nth-child(4),  /* Serie - hide on landscape too */
                    table td:nth-child(4) {
                        display: none;
                    }

                    /* Compact font on landscape */
                    table {
                        font-size: 0.85rem;
                    }
                }
                </style>

                <script>
                // Tab functionality
                document.addEventListener('DOMContentLoaded', function() {
                    const tabs = document.querySelectorAll('.gs-tab');
                    tabs.forEach(tab => {
                        tab.addEventListener('click', function() {
                            const targetId = this.dataset.tab;

                            // Remove active class from all tabs and content
                            document.querySelectorAll('.gs-tab').forEach(t => t.classList.remove('active'));
                            document.querySelectorAll('.gs-tab-content').forEach(c => c.classList.remove('active'));

                            // Add active class to clicked tab and corresponding content
                            this.classList.add('active');
                            document.getElementById(targetId).classList.add('active');
                        });
                    });
                });
                </script>
            <?php endif; ?>

            <?php if (empty($results)): ?>
                <!-- No Results -->
                <div class="gs-card gs-empty-state">
                    <i data-lucide="trophy" class="gs-empty-icon"></i>
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
                                                <th class="gs-text-center">Placering</th>
                                                <th class="gs-text-center">Tid</th>
                                                <th class="gs-text-center">Poäng</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($yearResults as $result): ?>
                                                <?php
                                                // Skip DNS (Did Not Start) - don't show these events
                                                if ($result['status'] === 'dns') continue;
                                                ?>
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
                                                            <a href="/series-standings.php?id=<?= $result['series_id'] ?>" class="gs-badge gs-badge-primary gs-badge-sm">
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
                                                    <td class="gs-text-center">
                                                        <?php
                                                        $awardsPoints = $result['awards_points'] ?? 1;
                                                        $displayPos = ($result['status'] === 'finished' && $awardsPoints) ? ($result['class_position'] ?? null) : null;
                                                        ?>
                                                        <?php if ($result['status'] === 'dnf'): ?>
                                                            <span class="gs-badge gs-badge-danger">DNF</span>
                                                        <?php elseif ($displayPos): ?>
                                                            <?php if ($displayPos == 1): ?>
                                                                <span class="gs-badge gs-badge-success">🥇 1</span>
                                                            <?php elseif ($displayPos == 2): ?>
                                                                <span class="gs-badge gs-badge-secondary">🥈 2</span>
                                                            <?php elseif ($displayPos == 3): ?>
                                                                <span class="gs-badge gs-badge-warning">🥉 3</span>
                                                            <?php else: ?>
                                                                <span><?= $displayPos ?></span>
                                                            <?php endif; ?>
                                                        <?php elseif ($result['status'] === 'finished' && !$awardsPoints): ?>
                                                            <span class="gs-text-secondary" style="font-size: 0.75rem;">Ej tävling</span>
                                                        <?php else: ?>
                                                            -
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="gs-text-center">
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
                                                    <td class="gs-text-center">
                                                        <?php if ($awardsPoints): ?>
                                                            <?= $result['points'] ?? 0 ?>
                                                        <?php else: ?>
                                                            <span class="gs-text-secondary">-</span>
                                                        <?php endif; ?>
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

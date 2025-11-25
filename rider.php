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
$gravityTotalRaceDetails = [];

// Get GravitySeries Team stats (club points)
$gravityTeamStats = null;
$gravityTeamPosition = null;
$gravityTeamClassTotal = 0;
$gravityTeamRaceDetails = [];

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
            // Get rider's points in GravitySeries Total (supports both connection methods)
            $gravityTotalStats = $db->getRow("
                SELECT SUM(points) as total_points, COUNT(DISTINCT event_id) as events_count
                FROM (
                    -- Via series_events junction table
                    SELECT r.points, r.event_id
                    FROM results r
                    JOIN events e ON r.event_id = e.id
                    JOIN series_events se ON e.id = se.event_id
                    WHERE se.series_id = ? AND r.cyclist_id = ?
                    AND r.status = 'finished' AND r.points > 0

                    UNION

                    -- Via direct events.series_id
                    SELECT r.points, r.event_id
                    FROM results r
                    JOIN events e ON r.event_id = e.id
                    WHERE e.series_id = ? AND r.cyclist_id = ?
                    AND e.series_id IS NOT NULL
                    AND r.status = 'finished' AND r.points > 0
                ) combined
            ", [$totalSeries['id'], $riderId, $totalSeries['id'], $riderId]);

            // Get rider's most common class from their results in this series (for display)
            $riderResultClass = $db->getRow("
                SELECT class_id, name, display_name, COUNT(*) as cnt
                FROM (
                    -- Via series_events junction table
                    SELECT r.class_id, c.name, c.display_name
                    FROM results r
                    JOIN events e ON r.event_id = e.id
                    JOIN series_events se ON e.id = se.event_id
                    LEFT JOIN classes c ON r.class_id = c.id
                    WHERE se.series_id = ? AND r.cyclist_id = ?
                    AND r.status = 'finished' AND r.class_id IS NOT NULL

                    UNION ALL

                    -- Via direct events.series_id
                    SELECT r.class_id, c.name, c.display_name
                    FROM results r
                    JOIN events e ON r.event_id = e.id
                    LEFT JOIN classes c ON r.class_id = c.id
                    WHERE e.series_id = ? AND r.cyclist_id = ?
                    AND e.series_id IS NOT NULL
                    AND r.status = 'finished' AND r.class_id IS NOT NULL
                ) combined
                GROUP BY class_id
                ORDER BY cnt DESC
                LIMIT 1
            ", [$totalSeries['id'], $riderId, $totalSeries['id'], $riderId]);

            if ($riderResultClass) {
                $currentClassName = $riderResultClass['display_name'] ?? $riderResultClass['name'] ?? null;
            }

            // Get overall position in series (all classes combined)
            $seriesStandingsAll = $db->getAll("
                SELECT cyclist_id, SUM(points) as total_points
                FROM (
                    -- Via series_events junction table
                    SELECT r.cyclist_id, r.points
                    FROM results r
                    JOIN events e ON r.event_id = e.id
                    JOIN series_events se ON e.id = se.event_id
                    WHERE se.series_id = ?
                    AND r.status = 'finished' AND r.points > 0

                    UNION ALL

                    -- Via direct events.series_id
                    SELECT r.cyclist_id, r.points
                    FROM results r
                    JOIN events e ON r.event_id = e.id
                    WHERE e.series_id = ?
                    AND e.series_id IS NOT NULL
                    AND r.status = 'finished' AND r.points > 0
                ) combined
                GROUP BY cyclist_id
                ORDER BY total_points DESC
            ", [$totalSeries['id'], $totalSeries['id']]);

            $gravityTotalClassTotal = count($seriesStandingsAll);
            $position = 1;
            foreach ($seriesStandingsAll as $standing) {
                if ($standing['cyclist_id'] == $riderId) {
                    $gravityTotalPosition = $position;
                    break;
                }
                $position++;
            }

            // Get detailed race results for GravitySeries Total (supports both connection methods)
            $gravityTotalRaceDetails = $db->getAll("
                SELECT
                    points,
                    class_position,
                    event_name,
                    event_date,
                    event_location,
                    class_name
                FROM (
                    -- Via series_events junction table
                    SELECT
                        r.points,
                        r.class_position,
                        e.name as event_name,
                        e.date as event_date,
                        e.location as event_location,
                        cls.display_name as class_name
                    FROM results r
                    JOIN events e ON r.event_id = e.id
                    JOIN series_events se ON e.id = se.event_id
                    LEFT JOIN classes cls ON r.class_id = cls.id
                    WHERE se.series_id = ? AND r.cyclist_id = ?
                    AND r.status = 'finished'

                    UNION

                    -- Via direct events.series_id
                    SELECT
                        r.points,
                        r.class_position,
                        e.name as event_name,
                        e.date as event_date,
                        e.location as event_location,
                        cls.display_name as class_name
                    FROM results r
                    JOIN events e ON r.event_id = e.id
                    LEFT JOIN classes cls ON r.class_id = cls.id
                    WHERE e.series_id = ? AND r.cyclist_id = ?
                    AND e.series_id IS NOT NULL
                    AND r.status = 'finished'
                ) combined
                ORDER BY event_date DESC
            ", [$totalSeries['id'], $riderId, $totalSeries['id'], $riderId]);

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

                // Get detailed race results for GravitySeries Team
                $gravityTeamRaceDetails = $db->getAll("
                    SELECT
                        crp.club_points,
                        crp.original_points,
                        crp.percentage_applied,
                        crp.rider_rank_in_club,
                        e.name as event_name,
                        e.date as event_date,
                        e.location as event_location,
                        cls.display_name as class_name
                    FROM club_rider_points crp
                    JOIN events e ON crp.event_id = e.id
                    LEFT JOIN classes cls ON crp.class_id = cls.id
                    WHERE crp.rider_id = ? AND crp.club_id = ? AND crp.series_id = ?
                    ORDER BY e.date DESC
                ", [$riderId, $rider['club_id'], $totalSeries['id']]);
            }
        }
    } catch (Exception $e) {
        error_log("Error getting GravitySeries stats for rider {$riderId}: " . $e->getMessage());
    }
}

// Get ranking statistics for all disciplines
$rankingStats = [];
$rankingHistory = [];
$rankingRaceDetails = [];
$defaultDiscipline = null; // Auto-select based on priority

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

        // Get ranking history (last 6 months of snapshots)
        $rankingHistory[$discipline] = $db->getAll("
            SELECT
                snapshot_date,
                total_ranking_points,
                ranking_position,
                previous_position,
                position_change,
                events_count
            FROM ranking_snapshots
            WHERE rider_id = ? AND discipline = ?
            ORDER BY snapshot_date DESC
            LIMIT 6
        ", [$riderId, $discipline]);

        // Get detailed ranking points per race (last 24 months)
        // Calculate live from results since ranking_points table might be empty
        $disciplineFilter = '';
        $params = [$riderId];

        if ($discipline === 'GRAVITY') {
            $disciplineFilter = "AND e.discipline IN ('ENDURO', 'DH')";
        } else {
            $disciplineFilter = "AND e.discipline = ?";
            $params[] = $discipline;
        }

        $rawResults = $db->getAll("
            SELECT
                r.cyclist_id as rider_id,
                r.event_id,
                r.class_id,
                r.position,
                COALESCE(
                    CASE
                        WHEN COALESCE(r.run_1_points, 0) > 0 OR COALESCE(r.run_2_points, 0) > 0
                        THEN COALESCE(r.run_1_points, 0) + COALESCE(r.run_2_points, 0)
                        ELSE r.points
                    END,
                    r.points
                ) as original_points,
                e.name as event_name,
                e.date as event_date,
                e.location as event_location,
                e.discipline,
                COALESCE(e.event_level, 'national') as event_level,
                cls.display_name as class_name
            FROM results r
            JOIN events e ON r.event_id = e.id
            LEFT JOIN classes cls ON r.class_id = cls.id
            WHERE r.cyclist_id = ?
            AND r.status = 'finished'
            AND (r.points > 0 OR COALESCE(r.run_1_points, 0) > 0 OR COALESCE(r.run_2_points, 0) > 0)
            AND e.date >= DATE_SUB(NOW(), INTERVAL 24 MONTH)
            {$disciplineFilter}
            AND COALESCE(cls.series_eligible, 1) = 1
            AND COALESCE(cls.awards_points, 1) = 1
            ORDER BY e.date DESC
        ", $params);

        // Calculate ranking points with multipliers
        $fieldMultipliers = getRankingFieldMultipliers($db);
        $eventLevelMultipliers = getEventLevelMultipliers($db);
        $timeDecay = getRankingTimeDecay($db);

        // Get field sizes for each event/class
        $fieldSizes = [];
        foreach ($rawResults as $result) {
            $key = $result['event_id'] . '_' . $result['class_id'];
            if (!isset($fieldSizes[$key])) {
                $count = $db->getRow("
                    SELECT COUNT(*) as cnt
                    FROM results r
                    LEFT JOIN classes cls ON r.class_id = cls.id
                    WHERE r.event_id = ? AND r.class_id = ? AND r.status = 'finished'
                    AND (r.points > 0 OR COALESCE(r.run_1_points, 0) > 0 OR COALESCE(r.run_2_points, 0) > 0)
                    AND COALESCE(cls.series_eligible, 1) = 1
                    AND COALESCE(cls.awards_points, 1) = 1
                ", [$result['event_id'], $result['class_id']]);
                $fieldSizes[$key] = $count['cnt'] ?? 1;
            }
        }

        // Calculate ranking points for each result
        $rankingRaceDetails[$discipline] = [];
        foreach ($rawResults as $result) {
            $key = $result['event_id'] . '_' . $result['class_id'];
            $fieldSize = $fieldSizes[$key] ?? 1;

            // Calculate multipliers
            $fieldMult = getFieldMultiplier($fieldSize, $fieldMultipliers);
            $eventLevelMult = $eventLevelMultipliers[$result['event_level']] ?? 1.00;

            // Calculate time decay
            $eventDate = new DateTime($result['event_date']);
            $today = new DateTime();
            $monthsDiff = $eventDate->diff($today)->m + ($eventDate->diff($today)->y * 12);

            $timeMult = 0;
            if ($monthsDiff < 12) {
                $timeMult = $timeDecay['months_1_12'];
            } elseif ($monthsDiff < 24) {
                $timeMult = $timeDecay['months_13_24'];
            } else {
                $timeMult = $timeDecay['months_25_plus'];
            }

            // Calculate final ranking points
            $rankingPoints = $result['original_points'] * $fieldMult * $eventLevelMult * $timeMult;

            $rankingRaceDetails[$discipline][] = [
                'event_name' => $result['event_name'],
                'event_date' => $result['event_date'],
                'event_location' => $result['event_location'],
                'class_name' => $result['class_name'],
                'position' => $result['position'],
                'original_points' => $result['original_points'],
                'ranking_points' => $rankingPoints,
                'field_size' => $fieldSize,
                'field_multiplier' => $fieldMult,
                'event_level_multiplier' => $eventLevelMult,
                'time_multiplier' => $timeMult
            ];
        }
    }

    // Determine default discipline based on priority: GRAVITY > ENDURO > DH
    if (!empty($rankingStats['GRAVITY'])) {
        $defaultDiscipline = 'GRAVITY';
    } elseif (!empty($rankingStats['ENDURO'])) {
        $defaultDiscipline = 'ENDURO';
    } elseif (!empty($rankingStats['DH'])) {
        $defaultDiscipline = 'DH';
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

            <!-- Main Profile Tabs -->
            <div class="gs-card gs-mb-xl">
                <div class="gs-event-tabs-wrapper gs-mb-lg">
                    <div class="gs-event-tabs">
                        <button class="gs-event-tab active" data-main-tab="ranking-tab">
                            <i data-lucide="trending-up"></i>
                            Ranking
                        </button>
                        <button class="gs-event-tab" data-main-tab="gravity-total-tab">
                            <i data-lucide="trophy"></i>
                            GravitySeries Total
                        </button>
                        <button class="gs-event-tab" data-main-tab="gravity-team-tab">
                            <i data-lucide="users"></i>
                            GravitySeries Team
                        </button>
                        <button class="gs-event-tab" data-main-tab="results-tab">
                            <i data-lucide="list"></i>
                            Tävlingsresultat
                        </button>
                    </div>
                </div>

                <!-- Tab 1: Ranking -->
                <div class="gs-main-tab-content active" id="ranking-tab">
                    <?php if (!empty($rankingStats)): ?>
                        <h2 class="gs-h4 gs-text-primary gs-mb-lg">
                            <i data-lucide="trending-up"></i>
                            Rankingstatistik (24 månader)
                        </h2>

                        <!-- Discipline Tabs -->
                        <div class="gs-tabs gs-mb-lg">
                            <?php foreach (['GRAVITY' => 'Gravity', 'ENDURO' => 'Enduro', 'DH' => 'Downhill'] as $disc => $label): ?>
                                <?php if (isset($rankingStats[$disc])): ?>
                                    <button class="gs-tab <?= $disc === $defaultDiscipline ? 'active' : '' ?>" data-tab="ranking-<?= strtolower($disc) ?>">
                                        <?= $label ?>
                                    </button>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>

                        <!-- Discipline Tab Content -->
                        <?php foreach (['GRAVITY' => 'Gravity', 'ENDURO' => 'Enduro', 'DH' => 'Downhill'] as $disc => $label): ?>
                            <?php if (isset($rankingStats[$disc])): ?>
                                <?php $stats = $rankingStats[$disc]; ?>
                                <div class="gs-tab-content <?= $disc === $defaultDiscipline ? 'active' : '' ?>" id="ranking-<?= strtolower($disc) ?>">

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

                                    <!-- Ranking History -->
                                    <?php if (!empty($rankingHistory[$disc])): ?>
                                        <div class="gs-card gs-bg-light gs-mb-lg">
                                            <div class="gs-card-content">
                                                <h4 class="gs-h5 gs-text-primary gs-mb-md">
                                                    <i data-lucide="calendar-check"></i>
                                                    Ranking-historik
                                                </h4>
                                                <div class="gs-table-responsive">
                                                    <table class="gs-table gs-table-compact">
                                                        <thead>
                                                            <tr>
                                                                <th>Månad</th>
                                                                <th class="gs-text-center">Placering</th>
                                                                <th class="gs-text-center">Förändring</th>
                                                                <th class="gs-text-right">Poäng</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php foreach ($rankingHistory[$disc] as $idx => $history): ?>
                                                                <tr>
                                                                    <td><?= date('Y-m', strtotime($history['snapshot_date'])) ?></td>
                                                                    <td class="gs-text-center">#<?= $history['ranking_position'] ?></td>
                                                                    <td class="gs-text-center">
                                                                        <?php if ($history['position_change'] > 0): ?>
                                                                            <span class="gs-badge gs-badge-success">
                                                                                ↑ +<?= abs($history['position_change']) ?>
                                                                            </span>
                                                                        <?php elseif ($history['position_change'] < 0): ?>
                                                                            <span class="gs-badge gs-badge-danger">
                                                                                ↓ <?= $history['position_change'] ?>
                                                                            </span>
                                                                        <?php else: ?>
                                                                            <span class="gs-text-secondary">→ 0</span>
                                                                        <?php endif; ?>
                                                                    </td>
                                                                    <td class="gs-text-right"><?= number_format($history['total_ranking_points'], 1) ?></td>
                                                                </tr>
                                                            <?php endforeach; ?>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endif; ?>

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

                                    <!-- Ranking Race Details -->
                                    <?php if (!empty($rankingRaceDetails[$disc])): ?>
                                        <div class="gs-card gs-bg-light">
                                            <div class="gs-card-content">
                                                <h4 class="gs-h5 gs-text-primary gs-mb-md">
                                                    <i data-lucide="list"></i>
                                                    Race som gett rankingpoäng
                                                </h4>
                                                <style>
                                                    /* Mobile portrait: Hide extra columns */
                                                    @media (max-width: 767px) {
                                                        .ranking-events-table .hide-mobile-portrait {
                                                            display: none !important;
                                                        }
                                                    }

                                                    /* Mobile landscape and up: Show all columns */
                                                    @media (min-width: 768px) {
                                                        .ranking-events-table .hide-mobile-portrait {
                                                            display: table-cell !important;
                                                        }
                                                    }
                                                </style>
                                                <div class="gs-table-responsive">
                                                    <table class="gs-table gs-table-compact ranking-events-table">
                                                        <thead>
                                                            <tr>
                                                                <th class="hide-mobile-portrait">Datum</th>
                                                                <th>Tävling</th>
                                                                <th class="gs-text-center hide-mobile-portrait">Placering</th>
                                                                <th class="gs-text-center hide-mobile-portrait">Klass</th>
                                                                <th class="gs-text-right hide-mobile-portrait">Event-poäng</th>
                                                                <th class="gs-text-right">Poäng</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php foreach ($rankingRaceDetails[$disc] as $raceDetail): ?>
                                                                <tr>
                                                                    <td class="gs-text-nowrap hide-mobile-portrait"><?= date('Y-m-d', strtotime($raceDetail['event_date'])) ?></td>
                                                                    <td>
                                                                        <strong><?= h($raceDetail['event_name']) ?></strong>
                                                                    </td>
                                                                    <td class="gs-text-center hide-mobile-portrait">
                                                                        <?php if (!empty($raceDetail['position'])): ?>
                                                                            <span class="gs-badge <?= $raceDetail['position'] == 1 ? 'gs-badge-warning' : ($raceDetail['position'] <= 3 ? 'gs-badge-success' : 'gs-badge-secondary') ?>">
                                                                                #<?= $raceDetail['position'] ?>
                                                                            </span>
                                                                        <?php else: ?>
                                                                            <span class="gs-text-secondary">-</span>
                                                                        <?php endif; ?>
                                                                    </td>
                                                                    <td class="gs-text-center hide-mobile-portrait">
                                                                        <span class="gs-text-xs"><?= h($raceDetail['class_name'] ?? '-') ?></span>
                                                                    </td>
                                                                    <td class="gs-text-right hide-mobile-portrait"><?= number_format($raceDetail['original_points'], 0) ?></td>
                                                                    <td class="gs-text-right gs-text-primary gs-font-bold"><?= number_format($raceDetail['ranking_points'], 1) ?></td>
                                                                </tr>
                                                            <?php endforeach; ?>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="gs-empty-state gs-text-center gs-py-xl">
                            <i data-lucide="trending-up" class="gs-empty-icon"></i>
                            <h3 class="gs-h4 gs-mb-sm">Ingen ranking ännu</h3>
                            <p class="gs-text-secondary">
                                Denna deltagare har inte tillräckligt med resultat för att rankas.
                            </p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Tab 2: GravitySeries Total -->
                <div class="gs-main-tab-content" id="gravity-total-tab">
                    <?php if ($gravityTotalStats && $gravityTotalStats['total_points'] > 0): ?>
                        <h2 class="gs-h4 gs-text-primary gs-mb-lg">
                            <i data-lucide="trophy"></i>
                            GravitySeries Total - Individuell
                        </h2>

                        <!-- Stats Grid -->
                        <div class="gs-ranking-stats-grid gs-mb-lg">
                            <div class="gs-stat-box">
                                <div class="gs-stat-label">Placering</div>
                                <div class="gs-stat-value gs-text-primary">
                                    #<?= $gravityTotalPosition ?? '-' ?><?php if ($gravityTotalClassTotal): ?>/<?= $gravityTotalClassTotal ?><?php endif; ?>
                                </div>
                            </div>
                            <div class="gs-stat-box">
                                <div class="gs-stat-label">Totala poäng</div>
                                <div class="gs-stat-value gs-text-success"><?= number_format($gravityTotalStats['total_points']) ?></div>
                            </div>
                            <div class="gs-stat-box">
                                <div class="gs-stat-label">Antal race</div>
                                <div class="gs-stat-value gs-text-warning"><?= $gravityTotalStats['events_count'] ?></div>
                            </div>
                        </div>

                        <?php if ($currentClassName): ?>
                            <div class="gs-mb-lg">
                                <span class="gs-badge gs-badge-primary gs-badge-lg">Klass: <?= h($currentClassName) ?></span>
                            </div>
                        <?php endif; ?>

                        <!-- Race Details -->
                        <?php if (!empty($gravityTotalRaceDetails)): ?>
                            <div class="gs-card gs-bg-light">
                                <div class="gs-card-content">
                                    <h4 class="gs-h5 gs-text-primary gs-mb-md">
                                        <i data-lucide="list"></i>
                                        Race och poäng
                                    </h4>
                                    <style>
                                        /* Mobile portrait: Hide extra columns */
                                        @media (max-width: 767px) {
                                            .gravity-total-table .hide-mobile-portrait {
                                                display: none !important;
                                            }
                                            .gravity-total-table .total-mobile-colspan {
                                                display: table-cell !important;
                                            }
                                        }

                                        /* Mobile landscape and up: Show all columns */
                                        @media (min-width: 768px) {
                                            .gravity-total-table .hide-mobile-portrait {
                                                display: table-cell !important;
                                            }
                                            .gravity-total-table .total-mobile-colspan {
                                                display: none !important;
                                            }
                                        }
                                    </style>
                                    <div class="gs-table-responsive">
                                        <table class="gs-table gs-table-compact gravity-total-table">
                                            <thead>
                                                <tr>
                                                    <th class="hide-mobile-portrait">Datum</th>
                                                    <th>Tävling</th>
                                                    <th class="gs-text-center hide-mobile-portrait">Klass</th>
                                                    <th class="gs-text-center hide-mobile-portrait">Placering</th>
                                                    <th class="gs-text-right">Poäng</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($gravityTotalRaceDetails as $raceDetail): ?>
                                                    <tr>
                                                        <td class="hide-mobile-portrait"><?= date('Y-m-d', strtotime($raceDetail['event_date'])) ?></td>
                                                        <td>
                                                            <strong><?= h($raceDetail['event_name']) ?></strong>
                                                        </td>
                                                        <td class="gs-text-center hide-mobile-portrait"><?= h($raceDetail['class_name'] ?? '-') ?></td>
                                                        <td class="gs-text-center hide-mobile-portrait">
                                                            <?php
                                                            $pos = $raceDetail['class_position'];
                                                            if ($pos == 1) echo '<span class="gs-badge gs-badge-success">🥇 1</span>';
                                                            elseif ($pos == 2) echo '<span class="gs-badge gs-badge-secondary">🥈 2</span>';
                                                            elseif ($pos == 3) echo '<span class="gs-badge gs-badge-warning">🥉 3</span>';
                                                            else echo $pos ?? '-';
                                                            ?>
                                                        </td>
                                                        <td class="gs-text-right gs-text-primary gs-font-bold"><?= number_format($raceDetail['points'], 0) ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                                <tr class="gs-table-footer">
                                                    <td colspan="1" class="gs-text-right gs-font-bold total-mobile-colspan">Total:</td>
                                                    <td colspan="4" class="gs-text-right gs-font-bold hide-mobile-portrait">Total:</td>
                                                    <td class="gs-text-right gs-text-primary gs-font-bold gs-text-lg">
                                                        <?= number_format($gravityTotalStats['total_points'], 0) ?>
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="gs-empty-state gs-text-center gs-py-xl">
                            <i data-lucide="trophy" class="gs-empty-icon"></i>
                            <h3 class="gs-h4 gs-mb-sm">Inga GravitySeries Total-poäng</h3>
                            <p class="gs-text-secondary">
                                Denna deltagare har inte några poäng i GravitySeries Total ännu.
                            </p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Tab 3: GravitySeries Team -->
                <div class="gs-main-tab-content" id="gravity-team-tab">
                    <?php if ($gravityTeamStats && $gravityTeamStats['total_points'] > 0): ?>
                        <h2 class="gs-h4 gs-text-primary gs-mb-lg">
                            <i data-lucide="users"></i>
                            GravitySeries Team - Klubbpoäng
                        </h2>

                        <!-- Stats Grid -->
                        <div class="gs-ranking-stats-grid gs-mb-lg">
                            <div class="gs-stat-box">
                                <div class="gs-stat-label">Placering i klubben</div>
                                <div class="gs-stat-value gs-text-primary">
                                    #<?= $gravityTeamPosition ?? '-' ?><?php if ($gravityTeamClassTotal): ?>/<?= $gravityTeamClassTotal ?><?php endif; ?>
                                </div>
                            </div>
                            <div class="gs-stat-box">
                                <div class="gs-stat-label">Klubbpoäng</div>
                                <div class="gs-stat-value" style="color: #f59e0b;"><?= number_format($gravityTeamStats['total_points'], 1) ?></div>
                            </div>
                            <div class="gs-stat-box">
                                <div class="gs-stat-label">Antal race</div>
                                <div class="gs-stat-value gs-text-warning"><?= $gravityTeamStats['events_count'] ?></div>
                            </div>
                        </div>

                        <?php if ($rider['club_name']): ?>
                            <div class="gs-mb-lg">
                                <span class="gs-badge gs-badge-primary gs-badge-lg">Klubb: <?= h($rider['club_name']) ?></span>
                            </div>
                        <?php endif; ?>

                        <!-- Race Details -->
                        <?php if (!empty($gravityTeamRaceDetails)): ?>
                            <div class="gs-card gs-bg-light">
                                <div class="gs-card-content">
                                    <h4 class="gs-h5 gs-text-primary gs-mb-md">
                                        <i data-lucide="list"></i>
                                        Race och klubbpoäng
                                    </h4>
                                    <style>
                                        /* Mobile portrait: Hide extra columns */
                                        @media (max-width: 767px) {
                                            .club-points-table .hide-mobile-portrait {
                                                display: none !important;
                                            }
                                            .club-points-table .total-mobile-colspan {
                                                display: table-cell !important;
                                            }
                                        }

                                        /* Mobile landscape and up: Show all columns */
                                        @media (min-width: 768px) {
                                            .club-points-table .hide-mobile-portrait {
                                                display: table-cell !important;
                                            }
                                            .club-points-table .total-mobile-colspan {
                                                display: none !important;
                                            }
                                        }
                                    </style>
                                    <div class="gs-table-responsive">
                                        <table class="gs-table gs-table-compact club-points-table">
                                            <thead>
                                                <tr>
                                                    <th class="hide-mobile-portrait">Datum</th>
                                                    <th>Tävling</th>
                                                    <th class="gs-text-center hide-mobile-portrait">Klass</th>
                                                    <th class="gs-text-right hide-mobile-portrait">Original</th>
                                                    <th class="gs-text-center hide-mobile-portrait">Procent</th>
                                                    <th class="gs-text-right">Poäng</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($gravityTeamRaceDetails as $raceDetail): ?>
                                                    <tr>
                                                        <td class="hide-mobile-portrait"><?= date('Y-m-d', strtotime($raceDetail['event_date'])) ?></td>
                                                        <td>
                                                            <strong><?= h($raceDetail['event_name']) ?></strong>
                                                        </td>
                                                        <td class="gs-text-center hide-mobile-portrait"><?= h($raceDetail['class_name'] ?? '-') ?></td>
                                                        <td class="gs-text-right hide-mobile-portrait"><?= number_format($raceDetail['original_points'], 0) ?></td>
                                                        <td class="gs-text-center hide-mobile-portrait"><?= $raceDetail['percentage_applied'] ?>%</td>
                                                        <td class="gs-text-right gs-font-bold" style="color: #f59e0b;">
                                                            <?= number_format($raceDetail['club_points'], 1) ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                                <tr class="gs-table-footer">
                                                    <td colspan="1" class="gs-text-right gs-font-bold total-mobile-colspan">Total:</td>
                                                    <td colspan="5" class="gs-text-right gs-font-bold hide-mobile-portrait">Total:</td>
                                                    <td class="gs-text-right gs-font-bold gs-text-lg" style="color: #f59e0b;">
                                                        <?= number_format($gravityTeamStats['total_points'], 1) ?>
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                    <p class="gs-text-xs gs-text-secondary gs-mt-md">
                                        <i data-lucide="info"></i>
                                        Procent baseras på åldersklass och klubbens regelverk för teampoäng.
                                    </p>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="gs-empty-state gs-text-center gs-py-xl">
                            <i data-lucide="users" class="gs-empty-icon"></i>
                            <h3 class="gs-h4 gs-mb-sm">Inga GravitySeries Team-poäng</h3>
                            <p class="gs-text-secondary">
                                <?php if (!$rider['club_id']): ?>
                                    Denna deltagare är inte medlem i någon klubb.
                                <?php else: ?>
                                    Denna deltagare har inte några klubbpoäng i GravitySeries Team ännu.
                                <?php endif; ?>
                            </p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Tab 4: Race Results -->
                <div class="gs-main-tab-content" id="results-tab">
                    <?php if (!empty($results)): ?>
                        <h2 class="gs-h4 gs-text-primary gs-mb-lg">
                            <i data-lucide="list"></i>
                            Alla tävlingsresultat (<?= $totalRaces ?> race)
                        </h2>

                        <?php foreach ($resultsByYear as $year => $yearResults): ?>
                            <div class="gs-mb-xl">
                                <h3 class="gs-h5 gs-text-primary gs-mb-md">
                                    <i data-lucide="calendar"></i>
                                    <?= $year ?> (<?= count($yearResults) ?> lopp)
                                </h3>

                                <style>
                                    /* Mobile portrait: Hide extra columns */
                                    @media (max-width: 767px) {
                                        .all-races-table .hide-mobile-portrait {
                                            display: none !important;
                                        }
                                    }

                                    /* Mobile landscape and up: Show all columns */
                                    @media (min-width: 768px) {
                                        .all-races-table .hide-mobile-portrait {
                                            display: table-cell !important;
                                        }
                                    }
                                </style>

                                <div class="gs-table-responsive">
                                    <table class="gs-table gs-table-compact all-races-table">
                                        <thead>
                                            <tr>
                                                <th class="hide-mobile-portrait">Datum</th>
                                                <th>Tävling</th>
                                                <th class="hide-mobile-portrait">Plats</th>
                                                <th class="hide-mobile-portrait">Klass</th>
                                                <th class="gs-text-center hide-mobile-portrait">Placering</th>
                                                <th class="gs-text-center hide-mobile-portrait">Tid</th>
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
                                                    <td class="hide-mobile-portrait"><?= date('Y-m-d', strtotime($result['event_date'])) ?></td>
                                                    <td>
                                                        <?php if ($result['event_id']): ?>
                                                            <a href="/event.php?id=<?= $result['event_id'] ?>" class="gs-link">
                                                                <strong><?= h($result['event_name']) ?></strong>
                                                            </a>
                                                        <?php else: ?>
                                                            <strong><?= h($result['event_name']) ?></strong>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="hide-mobile-portrait">
                                                        <?php if ($result['event_location']): ?>
                                                            <?= h($result['event_location']) ?>
                                                        <?php elseif ($result['venue_city']): ?>
                                                            <?= h($result['venue_city']) ?>
                                                        <?php else: ?>
                                                            -
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="hide-mobile-portrait"><?= h($result['class_display_name'] ?? $result['class_name'] ?? '-') ?></td>
                                                    <td class="gs-text-center hide-mobile-portrait">
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
                                                    <td class="gs-text-center hide-mobile-portrait">
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
                    <?php else: ?>
                        <div class="gs-empty-state gs-text-center gs-py-xl">
                            <i data-lucide="list" class="gs-empty-icon"></i>
                            <h3 class="gs-h4 gs-mb-sm">Inga resultat ännu</h3>
                            <p class="gs-text-secondary">
                                Denna deltagare har inte några tävlingsresultat uppladdat.
                            </p>
                        </div>
                    <?php endif; ?>
                </div>

            </div><!-- End Main Profile Tabs card -->

            <!-- CSS for main tabs and styling -->
            <style>
                /* Main Profile Tabs - using event-tab style */
                .gs-event-tab {
                    cursor: pointer;
                }

                .gs-main-tab-content {
                    display: none;
                    animation: fadeIn 0.3s ease-in;
                }

                .gs-main-tab-content.active {
                    display: block;
                }

                @keyframes fadeIn {
                    from { opacity: 0; }
                    to { opacity: 1; }
                }

                /* Table footer styling */
                .gs-table-footer {
                    background: var(--gs-gray-50);
                    font-weight: bold;
                    border-top: 2px solid var(--gs-gray-300);
                }

                /* Compact table styling */
                .gs-table-compact {
                    font-size: 0.9rem;
                }

                .gs-table-compact th,
                .gs-table-compact td {
                    padding: 0.5rem;
                }

                /* Badge sizes */
                .gs-badge-xs {
                    font-size: 0.65rem;
                    padding: 0.125rem 0.375rem;
                }

                .gs-badge-lg {
                    font-size: 1rem;
                    padding: 0.5rem 1rem;
                }

                /* Empty state */
                .gs-empty-icon {
                    width: 64px;
                    height: 64px;
                    opacity: 0.3;
                    margin-bottom: 1rem;
                }

                /* Mobile responsive for main tabs */
                @media (max-width: 767px) {
                    .gs-main-tabs {
                        gap: 0.25rem;
                        border-bottom-width: 2px;
                    }

                    .gs-main-tab {
                        padding: 0.75rem 0.5rem;
                        font-size: 0.75rem;
                        border-bottom-width: 3px;
                        margin-bottom: -2px;
                    }

                    .gs-main-tab i {
                        width: 16px;
                        height: 16px;
                    }

                    /* Stack badge */
                    .gs-badge-lg {
                        font-size: 0.875rem;
                        padding: 0.375rem 0.75rem;
                    }
                }

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
                // Main tab functionality
                document.addEventListener('DOMContentLoaded', function() {
                    // Main profile tabs (using gs-event-tab for main tabs)
                    const mainTabs = document.querySelectorAll('.gs-event-tab[data-main-tab]');
                    mainTabs.forEach(tab => {
                        tab.addEventListener('click', function() {
                            const targetId = this.dataset.mainTab;

                            // Remove active class from all main tabs and content
                            document.querySelectorAll('.gs-event-tab[data-main-tab]').forEach(t => t.classList.remove('active'));
                            document.querySelectorAll('.gs-main-tab-content').forEach(c => c.classList.remove('active'));

                            // Add active class to clicked tab and corresponding content
                            this.classList.add('active');
                            document.getElementById(targetId).classList.add('active');
                        });
                    });

                    // Sub-tabs (discipline tabs within ranking)
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
        </div>
    </main>

<?php include __DIR__ . '/includes/layout-footer.php'; ?>

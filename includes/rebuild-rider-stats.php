<?php
/**
 * TheHUB - Rider Statistics & Achievements Rebuild System
 *
 * Kör denna funktion efter import av historiska resultat för att
 * räkna om all statistik och achievements från grunden.
 *
 * Användning:
 *   - Enskild åkare: rebuildRiderStats($pdo, $rider_id)
 *   - Alla åkare: rebuildAllRiderStats($pdo)
 *   - Via admin: /admin/rebuild-stats.php
 */

// ============================================
// CONFIGURATION
// ============================================

define('EXPERIENCE_LEVELS', [
    1 => ['name' => '1st Year', 'icon' => '⭐'],
    2 => ['name' => '2nd Year', 'icon' => '⭐'],
    3 => ['name' => 'Experienced', 'icon' => '⭐'],
    4 => ['name' => 'Expert', 'icon' => '🌟'],
    5 => ['name' => 'Veteran', 'icon' => '👑']
]);

define('HOT_STREAK_MINIMUM', 3); // Minst 3 raka topp-3 för achievement

// ============================================
// MAIN REBUILD FUNCTIONS
// ============================================

/**
 * Bygger om ALL statistik och achievements för EN åkare
 */
function rebuildRiderStats($pdo, $rider_id) {
    $stats = [
        'rider_id' => $rider_id,
        'started' => date('Y-m-d H:i:s'),
        'achievements_added' => 0,
        'errors' => []
    ];

    try {
        $pdo->beginTransaction();

        // 1. Rensa gamla achievements för denna åkare
        $stmt = $pdo->prepare("DELETE FROM rider_achievements WHERE rider_id = ?");
        $stmt->execute([$rider_id]);

        // 2. Hämta all resultathistorik
        $results = getRiderResultHistory($pdo, $rider_id);

        if (empty($results)) {
            $pdo->commit();
            $stats['message'] = 'Inga resultat hittades';
            $stats['success'] = true;
            return $stats;
        }

        // 3. Beräkna och spara achievements

        // Pallplatser
        $podiums = calculatePodiums($results);
        foreach ($podiums as $type => $count) {
            if ($count > 0) {
                insertAchievement($pdo, $rider_id, $type, $count);
                $stats['achievements_added']++;
            }
        }

        // Hot Streaks
        $hotStreak = calculateHotStreak($results);
        if ($hotStreak >= HOT_STREAK_MINIMUM) {
            insertAchievement($pdo, $rider_id, 'hot_streak', $hotStreak);
            $stats['achievements_added']++;
        }

        // Fullföljt per säsong
        $finishRates = calculateFinishRates($pdo, $rider_id);
        foreach ($finishRates as $year => $rate) {
            if ($rate == 100) {
                insertAchievement($pdo, $rider_id, 'finisher_100', '100%', null, $year);
                $stats['achievements_added']++;
            }
        }

        // Seriesegrar (historiska)
        $seriesWins = calculateSeriesChampionships($pdo, $rider_id);
        foreach ($seriesWins as $win) {
            insertAchievement($pdo, $rider_id, 'series_champion', $win['series_name'], $win['series_id'], $win['year']);
            $stats['achievements_added']++;
        }

        // Stage wins (snabbaste på SS)
        $stageWins = calculateStageWins($pdo, $rider_id);
        if ($stageWins > 0) {
            insertAchievement($pdo, $rider_id, 'stage_win', $stageWins);
            $stats['achievements_added']++;
        }

        // Multi-serie (topp-3 i flera serier samma säsong)
        $multiSeries = calculateMultiSeriesSeasons($pdo, $rider_id);
        foreach ($multiSeries as $year) {
            insertAchievement($pdo, $rider_id, 'multi_series', null, null, $year);
            $stats['achievements_added']++;
        }

        // 4. Uppdatera cached stats på rider-tabellen
        updateRiderCachedStats($pdo, $rider_id, $results);

        // 5. Uppdatera experience level
        updateExperienceLevel($pdo, $rider_id);

        $pdo->commit();
        $stats['success'] = true;
        $stats['completed'] = date('Y-m-d H:i:s');

    } catch (Exception $e) {
        $pdo->rollBack();
        $stats['success'] = false;
        $stats['errors'][] = $e->getMessage();
    }

    return $stats;
}

/**
 * Bygger om statistik för ALLA åkare
 * Använd med försiktighet - kan ta lång tid!
 */
function rebuildAllRiderStats($pdo, $progressCallback = null) {
    $startTime = microtime(true);

    // Hämta alla åkare som har minst ett resultat
    $stmt = $pdo->query("
        SELECT DISTINCT r.cyclist_id as rider_id
        FROM results r
        WHERE r.cyclist_id IS NOT NULL
        ORDER BY r.cyclist_id
    ");
    $riderIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $totalRiders = count($riderIds);
    $processed = 0;
    $failed = 0;
    $results = [];

    foreach ($riderIds as $riderId) {
        $result = rebuildRiderStats($pdo, $riderId);

        if (!isset($result['success']) || !$result['success']) {
            $failed++;
        }

        $processed++;
        $results[$riderId] = $result;

        // Progress callback för UI-uppdatering
        if ($progressCallback && is_callable($progressCallback)) {
            $progressCallback($processed, $totalRiders, $riderId, $result);
        }
    }

    // Uppdatera aktiva serieledare
    updateCurrentSeriesLeaders($pdo);

    $endTime = microtime(true);

    return [
        'total_riders' => $totalRiders,
        'processed' => $processed,
        'failed' => $failed,
        'duration_seconds' => round($endTime - $startTime, 2),
        'details' => $results
    ];
}

// ============================================
// DATA RETRIEVAL
// ============================================

/**
 * Hämtar komplett resultathistorik för en åkare
 */
function getRiderResultHistory($pdo, $rider_id) {
    $stmt = $pdo->prepare("
        SELECT
            r.id as result_id,
            r.position,
            r.finish_time as time,
            r.points,
            r.status,
            CASE WHEN r.status = 'dnf' THEN 1 ELSE 0 END as dnf,
            CASE WHEN r.status = 'dns' THEN 1 ELSE 0 END as dns,
            CASE WHEN r.status = 'dsq' THEN 1 ELSE 0 END as dsq,
            e.id as event_id,
            e.name as event_name,
            e.date as event_date,
            YEAR(e.date) as season_year,
            s.id as series_id,
            s.name as series_name,
            c.id as class_id,
            c.display_name as class_name
        FROM results r
        JOIN events e ON r.event_id = e.id
        LEFT JOIN series s ON e.series_id = s.id
        LEFT JOIN classes c ON r.class_id = c.id
        WHERE r.cyclist_id = ?
        ORDER BY e.date ASC
    ");
    $stmt->execute([$rider_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Hämtar achievements för en åkare
 */
function getRiderAchievements($pdo, $rider_id) {
    $stmt = $pdo->prepare("
        SELECT * FROM rider_achievements
        WHERE rider_id = ?
        ORDER BY earned_at DESC
    ");
    $stmt->execute([$rider_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Hämtar sociala profiler med formaterade URLs
 */
function getRiderSocialProfiles($pdo, $rider_id) {
    $stmt = $pdo->prepare("
        SELECT
            social_instagram,
            social_facebook,
            social_strava,
            social_youtube,
            social_tiktok
        FROM riders WHERE id = ?
    ");
    $stmt->execute([$rider_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) return [];

    $profiles = [];

    if (!empty($row['social_instagram'])) {
        $profiles['instagram'] = [
            'username' => $row['social_instagram'],
            'url' => 'https://instagram.com/' . $row['social_instagram']
        ];
    }

    if (!empty($row['social_strava'])) {
        $profiles['strava'] = [
            'id' => $row['social_strava'],
            'url' => 'https://strava.com/athletes/' . $row['social_strava']
        ];
    }

    if (!empty($row['social_facebook'])) {
        $fb = $row['social_facebook'];
        $profiles['facebook'] = [
            'id' => $fb,
            'url' => strpos($fb, 'http') === 0 ? $fb : 'https://facebook.com/' . $fb
        ];
    }

    if (!empty($row['social_youtube'])) {
        $yt = $row['social_youtube'];
        $profiles['youtube'] = [
            'id' => $yt,
            'url' => strpos($yt, '@') === 0
                ? 'https://youtube.com/' . $yt
                : 'https://youtube.com/channel/' . $yt
        ];
    }

    if (!empty($row['social_tiktok'])) {
        $profiles['tiktok'] = [
            'username' => $row['social_tiktok'],
            'url' => 'https://tiktok.com/@' . ltrim($row['social_tiktok'], '@')
        ];
    }

    return $profiles;
}

/**
 * Hämtar serieställningar med trend
 */
function getRiderSeriesStandings($pdo, $rider_id, $year = null) {
    $year = $year ?? date('Y');

    $stmt = $pdo->prepare("
        SELECT
            s.id as series_id,
            s.name as series_name,
            r.class_id,
            c.display_name as class_name,
            SUM(r.points) as total_points,
            COUNT(r.id) as events_count,
            SUM(CASE WHEN r.position = 1 THEN 1 ELSE 0 END) as wins,
            SUM(CASE WHEN r.position <= 3 THEN 1 ELSE 0 END) as podiums
        FROM results r
        JOIN events e ON r.event_id = e.id
        JOIN series s ON e.series_id = s.id
        JOIN classes c ON r.class_id = c.id
        WHERE r.cyclist_id = ? AND YEAR(e.date) = ? AND r.status = 'finished'
        GROUP BY s.id, r.class_id
        ORDER BY total_points DESC
    ");
    $stmt->execute([$rider_id, $year]);
    $standings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Lägg till ranking och trend för varje serie
    foreach ($standings as &$standing) {
        $standing['ranking'] = getSeriesRanking($pdo, $standing['series_id'], $rider_id, $standing['class_id'], $year);
        $standing['trend'] = calculateTrend($pdo, $standing['series_id'], $rider_id, $standing['class_id'], $year);
        $standing['total_riders'] = getTotalRidersInClass($pdo, $standing['series_id'], $standing['class_id'], $year);
        $standing['gap_to_podium'] = calculateGapToPodium($pdo, $standing['series_id'], $rider_id, $standing['class_id'], $year);
        $standing['results'] = getSeriesResults($pdo, $standing['series_id'], $rider_id, $year);
        // Default series color based on series name
        $standing['series_color'] = getSeriesColor($standing['series_name']);
    }

    return $standings;
}

/**
 * Hämtar ranking position i serie
 */
function getSeriesRanking($pdo, $series_id, $rider_id, $class_id, $year) {
    $stmt = $pdo->prepare("
        SELECT r.cyclist_id, SUM(r.points) as total_points
        FROM results r
        JOIN events e ON r.event_id = e.id
        WHERE e.series_id = ? AND r.class_id = ? AND YEAR(e.date) = ? AND r.status = 'finished'
        GROUP BY r.cyclist_id
        ORDER BY total_points DESC
    ");
    $stmt->execute([$series_id, $class_id, $year]);
    $allRiders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $position = 1;
    foreach ($allRiders as $r) {
        if ($r['cyclist_id'] == $rider_id) {
            return $position;
        }
        $position++;
    }
    return null;
}

/**
 * Hämtar totalt antal åkare i klass/serie
 */
function getTotalRidersInClass($pdo, $series_id, $class_id, $year) {
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT r.cyclist_id) as total
        FROM results r
        JOIN events e ON r.event_id = e.id
        WHERE e.series_id = ? AND r.class_id = ? AND YEAR(e.date) = ? AND r.status = 'finished'
    ");
    $stmt->execute([$series_id, $class_id, $year]);
    return $stmt->fetchColumn() ?: 0;
}

/**
 * Beräknar poängdifferens till pallplats
 */
function calculateGapToPodium($pdo, $series_id, $rider_id, $class_id, $year) {
    $stmt = $pdo->prepare("
        SELECT r.cyclist_id, SUM(r.points) as total_points
        FROM results r
        JOIN events e ON r.event_id = e.id
        WHERE e.series_id = ? AND r.class_id = ? AND YEAR(e.date) = ? AND r.status = 'finished'
        GROUP BY r.cyclist_id
        ORDER BY total_points DESC
        LIMIT 3
    ");
    $stmt->execute([$series_id, $class_id, $year]);
    $topThree = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get rider's points
    $stmt = $pdo->prepare("
        SELECT SUM(r.points) as total_points
        FROM results r
        JOIN events e ON r.event_id = e.id
        WHERE e.series_id = ? AND r.class_id = ? AND YEAR(e.date) = ? AND r.cyclist_id = ? AND r.status = 'finished'
    ");
    $stmt->execute([$series_id, $class_id, $year, $rider_id]);
    $riderPoints = $stmt->fetchColumn() ?: 0;

    if (count($topThree) < 3) {
        return null; // Not enough riders
    }

    // Check if rider is in top 3
    foreach ($topThree as $idx => $rider) {
        if ($rider['cyclist_id'] == $rider_id) {
            if ($idx == 0) {
                // Leader - show gap to second place
                return isset($topThree[1]) ? $riderPoints - $topThree[1]['total_points'] : 0;
            }
            // In podium - show gap to previous position
            return $topThree[$idx - 1]['total_points'] - $riderPoints;
        }
    }

    // Not in podium - show gap to third place
    return $topThree[2]['total_points'] - $riderPoints;
}

// ============================================
// ACHIEVEMENT CALCULATIONS
// ============================================

/**
 * Räknar pallplatser
 */
function calculatePodiums($results) {
    $podiums = ['gold' => 0, 'silver' => 0, 'bronze' => 0];

    foreach ($results as $r) {
        if ($r['status'] !== 'finished') continue;

        switch ($r['position']) {
            case 1: $podiums['gold']++; break;
            case 2: $podiums['silver']++; break;
            case 3: $podiums['bronze']++; break;
        }
    }

    return $podiums;
}

/**
 * Beräknar längsta hot streak (raka topp-3 placeringar)
 */
function calculateHotStreak($results) {
    $currentStreak = 0;
    $maxStreak = 0;

    // Sortera efter datum (bör redan vara sorterat)
    usort($results, fn($a, $b) => strtotime($a['event_date']) - strtotime($b['event_date']));

    foreach ($results as $r) {
        // Hoppa över DNS (ingår inte i streak-beräkning)
        if ($r['status'] === 'dns') continue;

        // DNF/DSQ bryter streak
        if ($r['status'] !== 'finished') {
            $maxStreak = max($maxStreak, $currentStreak);
            $currentStreak = 0;
            continue;
        }

        if ($r['position'] <= 3) {
            $currentStreak++;
        } else {
            $maxStreak = max($maxStreak, $currentStreak);
            $currentStreak = 0;
        }
    }

    return max($maxStreak, $currentStreak);
}

/**
 * Beräknar fullföljt-procent per säsong
 */
function calculateFinishRates($pdo, $rider_id) {
    $stmt = $pdo->prepare("
        SELECT
            YEAR(e.date) as season_year,
            COUNT(*) as total_starts,
            SUM(CASE WHEN r.status = 'finished' THEN 1 ELSE 0 END) as finished
        FROM results r
        JOIN events e ON r.event_id = e.id
        WHERE r.cyclist_id = ?
        GROUP BY YEAR(e.date)
    ");
    $stmt->execute([$rider_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $rates = [];
    foreach ($rows as $row) {
        if ($row['total_starts'] > 0) {
            $rates[$row['season_year']] = round(($row['finished'] / $row['total_starts']) * 100);
        }
    }

    return $rates;
}

/**
 * Hittar seriesegrar (historiska seriemästare)
 */
function calculateSeriesChampionships($pdo, $rider_id) {
    // Simplified version - checks if rider had most points in completed seasons
    $currentYear = date('Y');

    $stmt = $pdo->prepare("
        SELECT
            s.id as series_id,
            s.name as series_name,
            YEAR(e.date) as year,
            r.class_id,
            SUM(r.points) as total_points
        FROM results r
        JOIN events e ON r.event_id = e.id
        JOIN series s ON e.series_id = s.id
        WHERE r.cyclist_id = ? AND YEAR(e.date) < ? AND r.status = 'finished'
        GROUP BY s.id, YEAR(e.date), r.class_id
    ");
    $stmt->execute([$rider_id, $currentYear]);
    $riderSeasons = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $championships = [];

    foreach ($riderSeasons as $season) {
        // Check if this rider had the most points
        $stmt = $pdo->prepare("
            SELECT MAX(total) as max_points FROM (
                SELECT SUM(r.points) as total
                FROM results r
                JOIN events e ON r.event_id = e.id
                WHERE e.series_id = ? AND r.class_id = ? AND YEAR(e.date) = ? AND r.status = 'finished'
                GROUP BY r.cyclist_id
            ) as subq
        ");
        $stmt->execute([$season['series_id'], $season['class_id'], $season['year']]);
        $maxPoints = $stmt->fetchColumn();

        if ($season['total_points'] == $maxPoints && $maxPoints > 0) {
            $championships[] = [
                'series_id' => $season['series_id'],
                'series_name' => $season['series_name'],
                'year' => $season['year']
            ];
        }
    }

    return $championships;
}

/**
 * Räknar stage wins (snabbaste tid på en SS/etapp)
 */
function calculateStageWins($pdo, $rider_id) {
    // Stage wins are not tracked separately in this database
    // Return 0 for now - can be extended later
    return 0;
}

/**
 * Hittar säsonger där åkaren hade topp-3 i flera serier
 */
function calculateMultiSeriesSeasons($pdo, $rider_id) {
    $stmt = $pdo->prepare("
        SELECT YEAR(e.date) as season_year, COUNT(DISTINCT s.id) as series_count
        FROM results r
        JOIN events e ON r.event_id = e.id
        JOIN series s ON e.series_id = s.id
        WHERE r.cyclist_id = ?
          AND r.position <= 3
          AND r.status = 'finished'
        GROUP BY YEAR(e.date)
        HAVING series_count >= 2
    ");
    $stmt->execute([$rider_id]);

    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Beräknar trend (positionsförändring efter senaste event)
 */
function calculateTrend($pdo, $series_id, $rider_id, $class_id, $year) {
    // Hämta senaste två events
    $stmt = $pdo->prepare("
        SELECT e.id, e.date
        FROM events e
        WHERE e.series_id = ? AND YEAR(e.date) = ?
        ORDER BY e.date DESC
        LIMIT 2
    ");
    $stmt->execute([$series_id, $year]);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($events) < 2) {
        return ['direction' => 'same', 'change' => 0];
    }

    // Get standings before latest event
    $latestEventId = $events[0]['id'];
    $previousEventId = $events[1]['id'];

    // Calculate ranking after previous event (excluding latest)
    $stmt = $pdo->prepare("
        SELECT r.cyclist_id, SUM(r.points) as total_points
        FROM results r
        JOIN events e ON r.event_id = e.id
        WHERE e.series_id = ? AND r.class_id = ? AND YEAR(e.date) = ?
          AND e.id != ? AND r.status = 'finished'
        GROUP BY r.cyclist_id
        ORDER BY total_points DESC
    ");
    $stmt->execute([$series_id, $class_id, $year, $latestEventId]);
    $previousStandings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $previousPosition = null;
    $pos = 1;
    foreach ($previousStandings as $r) {
        if ($r['cyclist_id'] == $rider_id) {
            $previousPosition = $pos;
            break;
        }
        $pos++;
    }

    // Get current ranking
    $currentPosition = getSeriesRanking($pdo, $series_id, $rider_id, $class_id, $year);

    if ($previousPosition === null || $currentPosition === null) {
        return ['direction' => 'same', 'change' => 0];
    }

    $change = $previousPosition - $currentPosition;

    if ($change > 0) {
        return ['direction' => 'up', 'change' => $change];
    } elseif ($change < 0) {
        return ['direction' => 'down', 'change' => abs($change)];
    }

    return ['direction' => 'same', 'change' => 0];
}

/**
 * Hämtar resultat för en specifik serie
 */
function getSeriesResults($pdo, $series_id, $rider_id, $year) {
    $stmt = $pdo->prepare("
        SELECT
            e.id as event_id,
            r.position,
            r.finish_time as time,
            r.points,
            r.status,
            e.name as event_name,
            e.date as event_date,
            c.display_name as class_name
        FROM results r
        JOIN events e ON r.event_id = e.id
        JOIN classes c ON r.class_id = c.id
        WHERE r.cyclist_id = ?
          AND e.series_id = ?
          AND YEAR(e.date) = ?
        ORDER BY e.date DESC
    ");
    $stmt->execute([$rider_id, $series_id, $year]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ============================================
// UPDATE FUNCTIONS
// ============================================

/**
 * Sparar achievement till databasen
 */
function insertAchievement($pdo, $rider_id, $type, $value = null, $series_id = null, $year = null) {
    $stmt = $pdo->prepare("
        INSERT INTO rider_achievements
            (rider_id, achievement_type, achievement_value, series_id, season_year)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([$rider_id, $type, $value, $series_id, $year]);
}

/**
 * Uppdaterar cachade stats på rider-tabellen för snabb hämtning
 */
function updateRiderCachedStats($pdo, $rider_id, $results) {
    $totalStarts = count($results);
    $finished = 0;
    $wins = 0;
    $podiums = 0;
    $totalPoints = 0;

    foreach ($results as $r) {
        if ($r['status'] === 'finished') {
            $finished++;
            $totalPoints += $r['points'] ?? 0;

            if ($r['position'] == 1) $wins++;
            if ($r['position'] <= 3) $podiums++;
        }
    }

    $stmt = $pdo->prepare("
        UPDATE riders SET
            stats_total_starts = ?,
            stats_total_finished = ?,
            stats_total_wins = ?,
            stats_total_podiums = ?,
            stats_total_points = ?,
            stats_updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$totalStarts, $finished, $wins, $podiums, $totalPoints, $rider_id]);
}

/**
 * Beräknar och uppdaterar experience level
 */
function updateExperienceLevel($pdo, $rider_id) {
    // Hitta första säsongen
    $stmt = $pdo->prepare("
        SELECT MIN(YEAR(e.date)) as first_season
        FROM results r
        JOIN events e ON r.event_id = e.id
        WHERE r.cyclist_id = ?
    ");
    $stmt->execute([$rider_id]);
    $firstSeason = $stmt->fetchColumn();

    if (!$firstSeason) return;

    $currentYear = (int)date('Y');
    $yearsActive = $currentYear - $firstSeason + 1;
    $experienceLevel = min($yearsActive, 5); // Max 5 (Veteran)

    $stmt = $pdo->prepare("
        UPDATE riders SET
            first_season = ?,
            experience_level = ?
        WHERE id = ?
    ");
    $stmt->execute([$firstSeason, $experienceLevel, $rider_id]);
}

/**
 * Uppdaterar aktiva serieledare (för pågående säsonger)
 * Kräver minst 2 genomförda tävlingar för att vara serieledare
 */
function updateCurrentSeriesLeaders($pdo) {
    $currentYear = date('Y');
    $minEventsForLeader = 2; // Minst 2 tävlingar krävs

    // Rensa gamla serieledare-achievements för innevarande år
    $stmt = $pdo->prepare("
        DELETE FROM rider_achievements
        WHERE achievement_type = 'series_leader'
          AND season_year = ?
    ");
    $stmt->execute([$currentYear]);

    // Hitta nuvarande ledare per serie och klass
    $stmt = $pdo->prepare("
        SELECT DISTINCT e.series_id, r.class_id
        FROM results r
        JOIN events e ON r.event_id = e.id
        WHERE YEAR(e.date) = ? AND e.series_id IS NOT NULL
    ");
    $stmt->execute([$currentYear]);
    $seriesClasses = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($seriesClasses as $sc) {
        // Kolla hur många events som genomförts i denna serie/klass
        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT e.id) as event_count
            FROM events e
            JOIN results r ON r.event_id = e.id
            WHERE e.series_id = ? AND r.class_id = ? AND YEAR(e.date) = ?
        ");
        $stmt->execute([$sc['series_id'], $sc['class_id'], $currentYear]);
        $eventCount = (int)$stmt->fetchColumn();

        // Skippa om färre än 2 events genomförts
        if ($eventCount < $minEventsForLeader) {
            continue;
        }

        // Find leader for this series/class
        $stmt = $pdo->prepare("
            SELECT r.cyclist_id, SUM(r.points) as total_points
            FROM results r
            JOIN events e ON r.event_id = e.id
            WHERE e.series_id = ? AND r.class_id = ? AND YEAR(e.date) = ? AND r.status = 'finished'
            GROUP BY r.cyclist_id
            ORDER BY total_points DESC
            LIMIT 1
        ");
        $stmt->execute([$sc['series_id'], $sc['class_id'], $currentYear]);
        $leader = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($leader && $leader['total_points'] > 0) {
            insertAchievement(
                $pdo,
                $leader['cyclist_id'],
                'series_leader',
                null,
                $sc['series_id'],
                $currentYear
            );
        }
    }
}

/**
 * Experience level helper
 */
function getExperienceLevelInfo($level) {
    $levels = [
        1 => ['name' => '1st Year', 'icon' => '⭐', 'next' => '2nd Year'],
        2 => ['name' => '2nd Year', 'icon' => '⭐', 'next' => 'Experienced'],
        3 => ['name' => 'Experienced', 'icon' => '⭐', 'next' => 'Expert'],
        4 => ['name' => 'Expert', 'icon' => '🌟', 'next' => 'Veteran'],
        5 => ['name' => 'Veteran', 'icon' => '👑', 'next' => null]
    ];
    return $levels[$level] ?? $levels[1];
}

/**
 * Get series color based on name
 */
function getSeriesColor($seriesName) {
    $name = strtolower($seriesName ?? '');

    // GravitySeries colors from CLAUDE.md
    if (strpos($name, 'gravityseries') !== false || strpos($name, 'gravity series') !== false) {
        return '#61CE70'; // --color-gs-green
    }
    if (strpos($name, 'ges') !== false || strpos($name, 'gravity enduro') !== false) {
        return '#EF761F'; // --color-ges-orange
    }
    if (strpos($name, 'ggs') !== false || strpos($name, 'gravity gravel') !== false) {
        return '#8A9A5B'; // --color-ggs-green
    }
    if (strpos($name, 'blue') !== false) {
        return '#004a98'; // --color-gs-blue
    }

    // Default accent color
    return '#61CE70';
}

/**
 * Sanitize social media handles
 */
function sanitizeSocialHandle($value, $platform) {
    if (empty($value)) return null;

    $value = trim($value);

    switch ($platform) {
        case 'instagram':
        case 'tiktok':
            // Remove @ prefix if present
            return ltrim($value, '@');

        case 'strava':
            // Extract athlete ID from URL if full URL provided
            if (preg_match('/strava\.com\/athletes\/(\d+)/', $value, $matches)) {
                return $matches[1];
            }
            // If just numbers, return as-is
            if (is_numeric($value)) {
                return $value;
            }
            return $value;

        case 'facebook':
            // If full URL, extract path
            if (preg_match('/facebook\.com\/(.+?)(?:\/|$|\?)/', $value, $matches)) {
                return $matches[1];
            }
            return $value;

        case 'youtube':
            // Handle @username or channel ID
            if (preg_match('/youtube\.com\/@(.+?)(?:\/|$|\?)/', $value, $matches)) {
                return '@' . $matches[1];
            }
            if (preg_match('/youtube\.com\/channel\/(.+?)(?:\/|$|\?)/', $value, $matches)) {
                return $matches[1];
            }
            // If starts with @, keep it
            if (strpos($value, '@') === 0) {
                return $value;
            }
            return $value;
    }

    return $value;
}

<?php
/**
 * Club Points System for TheHUB
 *
 * Calculates and caches club standings in series based on rider results.
 *
 * Point Rules:
 * - Best rider from each club per class/event: 100% of earned points
 * - Second best rider from same club/class/event: 50% of earned points
 * - All other riders from same club/class/event: 0% (not counted)
 *
 * Performance optimized with cache tables for O(1) lookups.
 */

/**
 * Calculate club points for a single event
 *
 * @param Database $db Database instance
 * @param int $eventId Event ID to calculate points for
 * @return array Statistics about the calculation
 */
function calculateClubPointsForEvent($db, $eventId) {
    $stats = [
        'clubs_processed' => 0,
        'riders_processed' => 0,
        'total_points' => 0
    ];

    // Get event info including series
    $event = $db->getRow("SELECT id, series_id FROM events WHERE id = ?", [$eventId]);
    if (!$event || !$event['series_id']) {
        return $stats;
    }

    $seriesId = $event['series_id'];

    // Clear existing points for this event
    $db->delete('club_rider_points', 'event_id = ?', [$eventId]);
    $db->delete('club_event_points', 'event_id = ?', [$eventId]);

    // Get all results for this event grouped by club and class
    // Only include riders with clubs and finished status
    $results = $db->getAll("
        SELECT
            r.id as result_id,
            r.cyclist_id,
            r.class_id,
            r.class_points,
            rd.club_id,
            rd.firstname,
            rd.lastname
        FROM results r
        JOIN riders rd ON r.cyclist_id = rd.id
        WHERE r.event_id = ?
        AND r.status = 'finished'
        AND rd.club_id IS NOT NULL
        AND r.class_points > 0
        ORDER BY rd.club_id, r.class_id, r.class_points DESC
    ", [$eventId]);

    if (empty($results)) {
        return $stats;
    }

    // Group results by club and class
    $clubClassResults = [];
    foreach ($results as $result) {
        $key = $result['club_id'] . '_' . $result['class_id'];
        if (!isset($clubClassResults[$key])) {
            $clubClassResults[$key] = [];
        }
        $clubClassResults[$key][] = $result;
    }

    // Calculate points for each club/class combination
    $clubEventPoints = [];

    foreach ($clubClassResults as $key => $riders) {
        list($clubId, $classId) = explode('_', $key);

        if (!isset($clubEventPoints[$clubId])) {
            $clubEventPoints[$clubId] = [
                'total_points' => 0,
                'participants_count' => 0,
                'scoring_riders' => 0
            ];
        }

        // Sort by points (highest first) - already sorted in query
        $rank = 1;
        foreach ($riders as $rider) {
            $originalPoints = (int)$rider['class_points'];
            $clubPoints = 0;
            $percentage = 0;

            if ($rank === 1) {
                // Best rider gets 100%
                $clubPoints = $originalPoints;
                $percentage = 100;
            } elseif ($rank === 2) {
                // Second best gets 50%
                $clubPoints = (int)round($originalPoints * 0.5);
                $percentage = 50;
            }
            // Rank 3+ gets 0%

            // Insert rider points record
            $db->insert('club_rider_points', [
                'club_id' => $clubId,
                'event_id' => $eventId,
                'rider_id' => $rider['cyclist_id'],
                'class_id' => $classId,
                'original_points' => $originalPoints,
                'club_points' => $clubPoints,
                'rider_rank_in_club' => $rank,
                'percentage_applied' => $percentage
            ]);

            // Update club totals
            $clubEventPoints[$clubId]['total_points'] += $clubPoints;
            $clubEventPoints[$clubId]['participants_count']++;
            if ($clubPoints > 0) {
                $clubEventPoints[$clubId]['scoring_riders']++;
            }

            $stats['riders_processed']++;
            $stats['total_points'] += $clubPoints;
            $rank++;
        }
    }

    // Insert club event points
    foreach ($clubEventPoints as $clubId => $data) {
        $db->insert('club_event_points', [
            'club_id' => $clubId,
            'event_id' => $eventId,
            'series_id' => $seriesId,
            'total_points' => $data['total_points'],
            'participants_count' => $data['participants_count'],
            'scoring_riders' => $data['scoring_riders']
        ]);
        $stats['clubs_processed']++;
    }

    return $stats;
}

/**
 * Refresh the club standings cache for a series
 *
 * @param Database $db Database instance
 * @param int $seriesId Series ID to refresh cache for
 * @return array Statistics about the refresh
 */
function refreshClubStandingsCache($db, $seriesId = null) {
    $stats = [
        'series_processed' => 0,
        'clubs_updated' => 0
    ];

    // Get series to process
    if ($seriesId) {
        $seriesList = [$db->getRow("SELECT id FROM series WHERE id = ?", [$seriesId])];
    } else {
        // Process all active series
        $seriesList = $db->getAll("SELECT id FROM series WHERE active = 1");
    }

    foreach ($seriesList as $series) {
        if (!$series) continue;

        $sid = $series['id'];

        // Clear existing cache for this series
        $db->delete('club_standings_cache', 'series_id = ?', [$sid]);

        // Aggregate club points from club_event_points
        $clubStats = $db->getAll("
            SELECT
                club_id,
                SUM(total_points) as total_points,
                SUM(participants_count) as total_participants,
                COUNT(event_id) as events_count,
                MAX(total_points) as best_event_points
            FROM club_event_points
            WHERE series_id = ?
            GROUP BY club_id
            HAVING total_points > 0
            ORDER BY total_points DESC
        ", [$sid]);

        // Insert with rankings
        $rank = 1;
        $prevPoints = null;
        $prevRank = 1;

        foreach ($clubStats as $index => $club) {
            // Handle ties - same points = same rank
            if ($prevPoints !== null && $club['total_points'] == $prevPoints) {
                $currentRank = $prevRank;
            } else {
                $currentRank = $rank;
                $prevRank = $rank;
            }
            $prevPoints = $club['total_points'];

            $db->insert('club_standings_cache', [
                'club_id' => $club['club_id'],
                'series_id' => $sid,
                'total_points' => $club['total_points'],
                'total_participants' => $club['total_participants'],
                'events_count' => $club['events_count'],
                'best_event_points' => $club['best_event_points'],
                'ranking' => $currentRank
            ]);

            $stats['clubs_updated']++;
            $rank++;
        }

        $stats['series_processed']++;
    }

    return $stats;
}

/**
 * Get club standings for a series
 *
 * @param Database $db Database instance
 * @param int $seriesId Series ID
 * @param int $limit Optional limit (0 = no limit)
 * @return array Club standings with club info
 */
function getClubStandings($db, $seriesId, $limit = 0) {
    $sql = "
        SELECT
            csc.ranking,
            csc.total_points,
            csc.total_participants,
            csc.events_count,
            csc.best_event_points,
            c.id as club_id,
            c.name as club_name,
            c.short_name,
            c.city,
            c.region,
            c.logo
        FROM club_standings_cache csc
        JOIN clubs c ON csc.club_id = c.id
        WHERE csc.series_id = ?
        ORDER BY csc.ranking ASC, csc.total_points DESC
    ";

    if ($limit > 0) {
        $sql .= " LIMIT " . (int)$limit;
    }

    return $db->getAll($sql, [$seriesId]);
}

/**
 * Get detailed club breakdown for a specific club in a series
 *
 * @param Database $db Database instance
 * @param int $clubId Club ID
 * @param int $seriesId Series ID
 * @return array Detailed breakdown including per-event and per-rider data
 */
function getClubPointsDetail($db, $clubId, $seriesId) {
    // Get club info
    $club = $db->getRow("SELECT * FROM clubs WHERE id = ?", [$clubId]);
    if (!$club) {
        return null;
    }

    // Get standing info
    $standing = $db->getRow("
        SELECT * FROM club_standings_cache
        WHERE club_id = ? AND series_id = ?
    ", [$clubId, $seriesId]);

    // Get event breakdown
    $events = $db->getAll("
        SELECT
            cep.event_id,
            cep.total_points,
            cep.participants_count,
            cep.scoring_riders,
            e.name as event_name,
            e.date as event_date,
            e.location
        FROM club_event_points cep
        JOIN events e ON cep.event_id = e.id
        WHERE cep.club_id = ? AND cep.series_id = ?
        ORDER BY e.date ASC
    ", [$clubId, $seriesId]);

    // Get rider breakdown per event
    $riderDetails = [];
    foreach ($events as $event) {
        $riders = $db->getAll("
            SELECT
                crp.rider_id,
                crp.class_id,
                crp.original_points,
                crp.club_points,
                crp.rider_rank_in_club,
                crp.percentage_applied,
                r.firstname,
                r.lastname,
                cls.display_name as class_name
            FROM club_rider_points crp
            JOIN riders r ON crp.rider_id = r.id
            LEFT JOIN classes cls ON crp.class_id = cls.id
            WHERE crp.club_id = ? AND crp.event_id = ?
            ORDER BY crp.club_points DESC, crp.original_points DESC
        ", [$clubId, $event['event_id']]);

        $riderDetails[$event['event_id']] = $riders;
    }

    return [
        'club' => $club,
        'standing' => $standing,
        'events' => $events,
        'rider_details' => $riderDetails
    ];
}

/**
 * Recalculate all club points for a series
 * Useful after bulk imports or corrections
 *
 * @param Database $db Database instance
 * @param int $seriesId Series ID
 * @return array Statistics
 */
function recalculateSeriesClubPoints($db, $seriesId) {
    $stats = [
        'events_processed' => 0,
        'total_clubs' => 0,
        'total_points' => 0
    ];

    // Get all events in series
    $events = $db->getAll("
        SELECT id FROM events
        WHERE series_id = ? AND status = 'completed'
        ORDER BY date ASC
    ", [$seriesId]);

    // Calculate points for each event
    foreach ($events as $event) {
        $eventStats = calculateClubPointsForEvent($db, $event['id']);
        $stats['events_processed']++;
        $stats['total_points'] += $eventStats['total_points'];
    }

    // Refresh the cache
    $cacheStats = refreshClubStandingsCache($db, $seriesId);
    $stats['total_clubs'] = $cacheStats['clubs_updated'];

    return $stats;
}

/**
 * Get top clubs across all series (for homepage display)
 *
 * @param Database $db Database instance
 * @param int $limit Number of clubs to return
 * @return array Top clubs with their best series performance
 */
function getTopClubsOverall($db, $limit = 10) {
    return $db->getAll("
        SELECT
            c.id as club_id,
            c.name as club_name,
            c.short_name,
            c.city,
            c.logo,
            SUM(csc.total_points) as overall_points,
            COUNT(DISTINCT csc.series_id) as series_count,
            MIN(csc.ranking) as best_ranking
        FROM clubs c
        JOIN club_standings_cache csc ON c.id = csc.club_id
        GROUP BY c.id
        ORDER BY overall_points DESC
        LIMIT ?
    ", [$limit]);
}

/**
 * Check if club points tables exist
 *
 * @param Database $db Database instance
 * @return bool True if tables exist
 */
function clubPointsTablesExist($db) {
    $conn = $db->getConnection();
    if (!$conn) return false;

    try {
        $result = $conn->query("SHOW TABLES LIKE 'club_standings_cache'");
        return $result && $result->rowCount() > 0;
    } catch (Exception $e) {
        return false;
    }
}

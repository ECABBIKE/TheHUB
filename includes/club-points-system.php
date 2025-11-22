<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

/**
 * Club Points System for TheHUB
 *
 * Calculates and caches club standings for series with format='Team'.
 * Uses events linked to the series via series_id.
 *
 * Point Rules:
 * - Best rider from each club per class/event: 100% of earned points
 * - Second best rider from same club/class/event: 50% of earned points
 * - All other riders from same club/class/event: 0% (not counted)
 */

/**
 * Calculate club points for a specific event in a series
 */
function calculateEventClubPoints($db, $eventId, $seriesId) {
    $stats = [
        'clubs_processed' => 0,
        'riders_processed' => 0,
        'total_points' => 0
    ];

    // Clear existing points for this event/series
    $db->delete('club_rider_points', 'event_id = ? AND series_id = ?', [$eventId, $seriesId]);
    $db->delete('club_event_points', 'event_id = ? AND series_id = ?', [$eventId, $seriesId]);

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

        $rank = 1;
        foreach ($riders as $rider) {
            $originalPoints = (float)$rider['class_points'];
            $clubPoints = 0;
            $percentage = 0;

            if ($rank === 1) {
                $clubPoints = $originalPoints;
                $percentage = 100;
            } elseif ($rank === 2) {
                $clubPoints = round($originalPoints * 0.5, 2);
                $percentage = 50;
            }

            // Insert rider points record
            $db->insert('club_rider_points', [
                'club_id' => $clubId,
                'event_id' => $eventId,
                'series_id' => $seriesId,
                'rider_id' => $rider['cyclist_id'],
                'class_id' => $classId,
                'original_points' => $originalPoints,
                'club_points' => $clubPoints,
                'rider_rank_in_club' => $rank,
                'percentage_applied' => $percentage
            ]);

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
 * Recalculate all club points for a series
 */
function recalculateSeriesClubPoints($db, $seriesId) {
    $stats = [
        'events_processed' => 0,
        'total_clubs' => 0,
        'total_points' => 0
    ];

    // Get all events in this series
    $events = $db->getAll("
        SELECT id, name, date
        FROM events
        WHERE series_id = ?
        ORDER BY date ASC
    ", [$seriesId]);

    // Calculate points for each event
    foreach ($events as $event) {
        $eventStats = calculateEventClubPoints($db, $event['id'], $seriesId);
        $stats['events_processed']++;
        $stats['total_points'] += $eventStats['total_points'];
    }

    // Refresh the standings cache
    $cacheStats = refreshSeriesStandingsCache($db, $seriesId);
    $stats['total_clubs'] = $cacheStats['clubs_updated'];

    return $stats;
}

/**
 * Refresh the standings cache for a series
 */
function refreshSeriesStandingsCache($db, $seriesId) {
    $stats = ['clubs_updated' => 0];

    // Clear existing cache
    $db->delete('club_standings_cache', 'series_id = ?', [$seriesId]);

    // Aggregate club points
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
    ", [$seriesId]);

    // Insert with rankings
    $rank = 1;
    $prevPoints = null;
    $prevRank = 1;

    foreach ($clubStats as $club) {
        // Handle ties
        if ($prevPoints !== null && $club['total_points'] == $prevPoints) {
            $currentRank = $prevRank;
        } else {
            $currentRank = $rank;
            $prevRank = $rank;
        }
        $prevPoints = $club['total_points'];

        $db->insert('club_standings_cache', [
            'club_id' => $club['club_id'],
            'series_id' => $seriesId,
            'total_points' => $club['total_points'],
            'total_participants' => $club['total_participants'],
            'events_count' => $club['events_count'],
            'best_event_points' => $club['best_event_points'],
            'ranking' => $currentRank
        ]);

        $stats['clubs_updated']++;
        $rank++;
    }

    return $stats;
}

/**
 * Get club standings for a series
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
 * Get detailed club breakdown for a series
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
            WHERE crp.club_id = ? AND crp.event_id = ? AND crp.series_id = ?
            ORDER BY crp.club_points DESC, crp.original_points DESC
        ", [$clubId, $event['event_id'], $seriesId]);

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
 * Get all series with format='Team' (club point series)
 */
function getTeamSeries($db) {
    return $db->getAll("
        SELECT
            s.*,
            COUNT(e.id) as event_count
        FROM series s
        LEFT JOIN events e ON s.id = e.series_id
        WHERE s.format = 'Team'
        GROUP BY s.id
        ORDER BY s.year DESC, s.name ASC
    ");
}

/**
 * Get series info with event count
 */
function getSeriesWithEvents($db, $seriesId) {
    $series = $db->getRow("SELECT * FROM series WHERE id = ?", [$seriesId]);
    if (!$series) {
        return null;
    }

    $events = $db->getAll("
        SELECT id, name, date, location
        FROM events
        WHERE series_id = ?
        ORDER BY date ASC
    ", [$seriesId]);

    $series['events'] = $events;
    return $series;
}

/**
 * Check if club points tables exist
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

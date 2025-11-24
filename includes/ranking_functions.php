<?php
/**
 * Ranking System Functions for TheHUB
 *
 * Calculates and manages the 24-month rolling ranking system for GravitySeries riders.
 * Points are weighted based on field size and time decay.
 *
 * Formula: Ranking Points = Original Points × Field Multiplier × Time Multiplier
 */

/**
 * Get default field size multipliers
 */
function getDefaultFieldMultipliers() {
    return [
        1 => 0.75, 2 => 0.77, 3 => 0.79, 4 => 0.81, 5 => 0.83,
        6 => 0.85, 7 => 0.86, 8 => 0.87, 9 => 0.88, 10 => 0.89,
        11 => 0.90, 12 => 0.91, 13 => 0.92, 14 => 0.93, 15 => 0.94,
        16 => 0.95, 17 => 0.95, 18 => 0.96, 19 => 0.96, 20 => 0.97,
        21 => 0.97, 22 => 0.98, 23 => 0.98, 24 => 0.99, 25 => 0.99,
        26 => 1.00
    ];
}

/**
 * Get default time decay settings
 */
function getDefaultTimeDecay() {
    return [
        'months_1_12' => 1.00,
        'months_13_24' => 0.50,
        'months_25_plus' => 0.00
    ];
}

/**
 * Get field multipliers from database settings
 */
function getRankingFieldMultipliers($db) {
    $result = $db->getRow(
        "SELECT setting_value FROM ranking_settings WHERE setting_key = 'field_multipliers'"
    );

    if ($result && $result['setting_value']) {
        $decoded = json_decode($result['setting_value'], true);
        if ($decoded) {
            // Convert string keys to integers
            $multipliers = [];
            foreach ($decoded as $key => $value) {
                $multipliers[(int)$key] = (float)$value;
            }
            return $multipliers;
        }
    }

    return getDefaultFieldMultipliers();
}

/**
 * Get time decay settings from database
 */
function getRankingTimeDecay($db) {
    $result = $db->getRow(
        "SELECT setting_value FROM ranking_settings WHERE setting_key = 'time_decay'"
    );

    if ($result && $result['setting_value']) {
        $decoded = json_decode($result['setting_value'], true);
        if ($decoded) {
            return $decoded;
        }
    }

    return getDefaultTimeDecay();
}

/**
 * Get series filter from database
 */
function getRankingSeriesFilter($db) {
    $result = $db->getRow(
        "SELECT setting_value FROM ranking_settings WHERE setting_key = 'series_filter'"
    );

    if ($result && $result['setting_value']) {
        $decoded = json_decode($result['setting_value'], true);
        if ($decoded) {
            return $decoded;
        }
    }

    return ['GravitySeries Total'];
}

/**
 * Get field multiplier for a specific field size
 */
function getFieldMultiplier($fieldSize, $multipliers) {
    $fieldSize = max(1, (int)$fieldSize);

    if ($fieldSize >= 26) {
        return $multipliers[26] ?? 1.00;
    }

    return $multipliers[$fieldSize] ?? 0.75;
}

/**
 * Get time decay multiplier based on event date
 */
function getTimeDecayMultiplier($eventDate, $referenceDate, $timeDecay) {
    $eventDateTime = new DateTime($eventDate);
    $referenceDateTime = new DateTime($referenceDate);

    $interval = $eventDateTime->diff($referenceDateTime);
    $monthsDiff = ($interval->y * 12) + $interval->m;

    // Event is in the future or same month
    if ($eventDateTime > $referenceDateTime) {
        return $timeDecay['months_1_12'];
    }

    if ($monthsDiff < 12) {
        return $timeDecay['months_1_12'];
    } elseif ($monthsDiff < 24) {
        return $timeDecay['months_13_24'];
    } else {
        return $timeDecay['months_25_plus'];
    }
}

/**
 * Get field size (number of unique riders) for a specific event and class
 */
function getClassFieldSize($db, $eventId, $classId) {
    $result = $db->getRow("
        SELECT COUNT(DISTINCT cyclist_id) as field_size
        FROM results
        WHERE event_id = ? AND class_id = ? AND status = 'finished'
    ", [$eventId, $classId]);

    return $result ? (int)$result['field_size'] : 1;
}

/**
 * Calculate ranking points from original points and field size
 */
function calculateRankingPoints($originalPoints, $fieldSize, $multipliers) {
    $fieldMultiplier = getFieldMultiplier($fieldSize, $multipliers);
    return round($originalPoints * $fieldMultiplier, 2);
}

/**
 * Get ranking-eligible results from the past 24 months
 */
function getRankingEligibleResults($db, $cutoffDate = null) {
    if (!$cutoffDate) {
        $cutoffDate = date('Y-m-d', strtotime('-24 months'));
    }

    $seriesFilter = getRankingSeriesFilter($db);

    // Build placeholders for IN clause
    $placeholders = implode(',', array_fill(0, count($seriesFilter), '?'));

    $results = $db->getAll("
        SELECT
            r.id as result_id,
            r.cyclist_id as rider_id,
            r.event_id,
            r.class_id,
            r.points as original_points,
            e.date as event_date,
            e.name as event_name,
            s.name as series_name,
            rd.firstname,
            rd.lastname,
            c.name as class_name
        FROM results r
        JOIN events e ON r.event_id = e.id
        JOIN series_events se ON e.id = se.event_id
        JOIN series s ON se.series_id = s.id
        JOIN riders rd ON r.cyclist_id = rd.id
        JOIN classes c ON r.class_id = c.id
        WHERE r.status = 'finished'
        AND r.points > 0
        AND e.date >= ?
        AND s.name IN ($placeholders)
        AND COALESCE(c.series_eligible, 1) = 1
        AND COALESCE(c.awards_points, 1) = 1
        ORDER BY e.date DESC, r.event_id, r.class_id, r.points DESC
    ", array_merge([$cutoffDate], $seriesFilter));

    return $results;
}

/**
 * Calculate and save all ranking points
 */
function calculateAllRankingPoints($db) {
    $stats = [
        'events_processed' => 0,
        'riders_processed' => 0,
        'total_points' => 0
    ];

    $cutoffDate = date('Y-m-d', strtotime('-24 months'));
    $multipliers = getRankingFieldMultipliers($db);

    // Get all eligible results
    $results = getRankingEligibleResults($db, $cutoffDate);

    if (empty($results)) {
        return $stats;
    }

    // Group by event and class to calculate field sizes
    $eventClasses = [];
    foreach ($results as $result) {
        $key = $result['event_id'] . '_' . $result['class_id'];
        if (!isset($eventClasses[$key])) {
            $eventClasses[$key] = [
                'event_id' => $result['event_id'],
                'class_id' => $result['class_id'],
                'riders' => []
            ];
        }
        $eventClasses[$key]['riders'][] = $result;
    }

    // Clear existing ranking points
    $db->query("DELETE FROM ranking_points WHERE event_date >= ?", [$cutoffDate]);

    $processedEvents = [];

    // Calculate and insert ranking points
    foreach ($eventClasses as $key => $data) {
        $fieldSize = count($data['riders']);
        $eventId = $data['event_id'];

        if (!isset($processedEvents[$eventId])) {
            $processedEvents[$eventId] = true;
            $stats['events_processed']++;
        }

        foreach ($data['riders'] as $rider) {
            $rankingPoints = calculateRankingPoints(
                $rider['original_points'],
                $fieldSize,
                $multipliers
            );

            $db->insert('ranking_points', [
                'rider_id' => $rider['rider_id'],
                'event_id' => $rider['event_id'],
                'class_id' => $rider['class_id'],
                'original_points' => $rider['original_points'],
                'field_size' => $fieldSize,
                'field_multiplier' => getFieldMultiplier($fieldSize, $multipliers),
                'ranking_points' => $rankingPoints,
                'event_date' => $rider['event_date']
            ]);

            $stats['riders_processed']++;
            $stats['total_points'] += $rankingPoints;
        }
    }

    // Update last calculation timestamp
    $db->query("
        UPDATE ranking_settings
        SET setting_value = ?
        WHERE setting_key = 'last_calculation'
    ", [json_encode([
        'date' => date('Y-m-d H:i:s'),
        'riders_processed' => $stats['riders_processed'],
        'events_processed' => $stats['events_processed']
    ])]);

    return $stats;
}

/**
 * Create a ranking snapshot for a specific date
 */
function createRankingSnapshot($db, $snapshotDate = null) {
    if (!$snapshotDate) {
        $snapshotDate = date('Y-m-01'); // First of current month
    }

    $stats = [
        'riders_ranked' => 0
    ];

    $timeDecay = getRankingTimeDecay($db);

    // Calculate date boundaries
    $month12Cutoff = date('Y-m-d', strtotime("$snapshotDate -12 months"));
    $month24Cutoff = date('Y-m-d', strtotime("$snapshotDate -24 months"));

    // Get previous snapshot for position comparison
    $previousSnapshotDate = date('Y-m-01', strtotime("$snapshotDate -1 month"));
    $previousRankings = [];
    $previousData = $db->getAll(
        "SELECT rider_id, ranking_position FROM ranking_snapshots WHERE snapshot_date = ?",
        [$previousSnapshotDate]
    );
    foreach ($previousData as $row) {
        $previousRankings[$row['rider_id']] = $row['ranking_position'];
    }

    // Clear existing snapshot for this date
    $db->delete('ranking_snapshots', 'snapshot_date = ?', [$snapshotDate]);

    // Calculate ranking points with time decay for each rider
    $riderPoints = $db->getAll("
        SELECT
            rp.rider_id,
            SUM(CASE WHEN rp.event_date >= ? THEN rp.ranking_points ELSE 0 END) as points_12,
            SUM(CASE WHEN rp.event_date < ? AND rp.event_date >= ? THEN rp.ranking_points ELSE 0 END) as points_13_24,
            COUNT(DISTINCT rp.event_id) as events_count
        FROM ranking_points rp
        WHERE rp.event_date >= ?
        GROUP BY rp.rider_id
        HAVING SUM(rp.ranking_points) > 0
        ORDER BY (SUM(CASE WHEN rp.event_date >= ? THEN rp.ranking_points ELSE 0 END) * ? +
                  SUM(CASE WHEN rp.event_date < ? AND rp.event_date >= ? THEN rp.ranking_points ELSE 0 END) * ?) DESC
    ", [
        $month12Cutoff, // points_12 condition
        $month12Cutoff, $month24Cutoff, // points_13_24 conditions
        $month24Cutoff, // WHERE condition
        $month12Cutoff, $timeDecay['months_1_12'], // ORDER BY part 1
        $month12Cutoff, $month24Cutoff, $timeDecay['months_13_24'] // ORDER BY part 2
    ]);

    // Insert snapshots with rankings
    $rank = 1;
    $prevPoints = null;
    $prevRank = 1;

    foreach ($riderPoints as $rider) {
        $totalPoints = ($rider['points_12'] * $timeDecay['months_1_12']) +
                       ($rider['points_13_24'] * $timeDecay['months_13_24']);

        // Handle ties
        if ($prevPoints !== null && $totalPoints == $prevPoints) {
            $currentRank = $prevRank;
        } else {
            $currentRank = $rank;
            $prevRank = $rank;
        }
        $prevPoints = $totalPoints;

        // Calculate position change
        $previousPosition = $previousRankings[$rider['rider_id']] ?? null;
        $positionChange = null;
        if ($previousPosition !== null) {
            $positionChange = $previousPosition - $currentRank;
        }

        $db->insert('ranking_snapshots', [
            'rider_id' => $rider['rider_id'],
            'snapshot_date' => $snapshotDate,
            'total_ranking_points' => round($totalPoints, 2),
            'points_last_12_months' => round($rider['points_12'], 2),
            'points_months_13_24' => round($rider['points_13_24'], 2),
            'events_count' => $rider['events_count'],
            'ranking_position' => $currentRank,
            'previous_position' => $previousPosition,
            'position_change' => $positionChange
        ]);

        // Also save to history table
        $db->query("
            INSERT INTO ranking_history (rider_id, month_date, ranking_position, total_points)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE ranking_position = VALUES(ranking_position), total_points = VALUES(total_points)
        ", [$rider['rider_id'], $snapshotDate, $currentRank, round($totalPoints, 2)]);

        $stats['riders_ranked']++;
        $rank++;
    }

    return $stats;
}

/**
 * Get current ranking with rider info
 */
function getCurrentRanking($db, $limit = 50, $offset = 0) {
    // Get the most recent snapshot date
    $latestSnapshot = $db->getRow("
        SELECT MAX(snapshot_date) as snapshot_date FROM ranking_snapshots
    ");

    if (!$latestSnapshot || !$latestSnapshot['snapshot_date']) {
        return ['riders' => [], 'total' => 0, 'snapshot_date' => null];
    }

    $snapshotDate = $latestSnapshot['snapshot_date'];

    // Get total count
    $totalCount = $db->getRow("
        SELECT COUNT(*) as total FROM ranking_snapshots WHERE snapshot_date = ?
    ", [$snapshotDate]);

    // Get rankings with rider info
    $riders = $db->getAll("
        SELECT
            rs.ranking_position,
            rs.total_ranking_points,
            rs.points_last_12_months,
            rs.points_months_13_24,
            rs.events_count,
            rs.previous_position,
            rs.position_change,
            r.id as rider_id,
            r.firstname,
            r.lastname,
            r.birth_year,
            c.name as club_name,
            c.id as club_id
        FROM ranking_snapshots rs
        JOIN riders r ON rs.rider_id = r.id
        LEFT JOIN clubs c ON r.club_id = c.id
        WHERE rs.snapshot_date = ?
        ORDER BY rs.ranking_position ASC
        LIMIT ? OFFSET ?
    ", [$snapshotDate, $limit, $offset]);

    return [
        'riders' => $riders,
        'total' => (int)$totalCount['total'],
        'snapshot_date' => $snapshotDate
    ];
}

/**
 * Get ranking history for a specific rider
 */
function getRiderRankingHistory($db, $riderId, $months = 12) {
    return $db->getAll("
        SELECT
            month_date,
            ranking_position,
            total_points
        FROM ranking_history
        WHERE rider_id = ?
        ORDER BY month_date DESC
        LIMIT ?
    ", [$riderId, $months]);
}

/**
 * Get detailed ranking breakdown for a rider
 */
function getRiderRankingDetails($db, $riderId) {
    // Get rider info
    $rider = $db->getRow("
        SELECT r.*, c.name as club_name
        FROM riders r
        LEFT JOIN clubs c ON r.club_id = c.id
        WHERE r.id = ?
    ", [$riderId]);

    if (!$rider) {
        return null;
    }

    // Get current snapshot info
    $latestSnapshot = $db->getRow("
        SELECT MAX(snapshot_date) as snapshot_date FROM ranking_snapshots
    ");

    $snapshotDate = $latestSnapshot ? $latestSnapshot['snapshot_date'] : date('Y-m-01');

    $ranking = $db->getRow("
        SELECT * FROM ranking_snapshots
        WHERE rider_id = ? AND snapshot_date = ?
    ", [$riderId, $snapshotDate]);

    // Get event breakdown
    $events = $db->getAll("
        SELECT
            rp.event_id,
            rp.class_id,
            rp.original_points,
            rp.field_size,
            rp.field_multiplier,
            rp.ranking_points,
            rp.event_date,
            e.name as event_name,
            e.location,
            cl.name as class_name,
            cl.display_name as class_display_name
        FROM ranking_points rp
        JOIN events e ON rp.event_id = e.id
        JOIN classes cl ON rp.class_id = cl.id
        WHERE rp.rider_id = ?
        ORDER BY rp.event_date DESC
    ", [$riderId]);

    return [
        'rider' => $rider,
        'ranking' => $ranking,
        'events' => $events
    ];
}

/**
 * Save field multipliers to database
 */
function saveFieldMultipliers($db, $multipliers) {
    $json = json_encode($multipliers);

    return $db->query("
        INSERT INTO ranking_settings (setting_key, setting_value, description)
        VALUES ('field_multipliers', ?, 'Field size multipliers (1-26+ riders)')
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()
    ", [$json]);
}

/**
 * Save time decay settings to database
 */
function saveTimeDecay($db, $timeDecay) {
    $json = json_encode($timeDecay);

    return $db->query("
        INSERT INTO ranking_settings (setting_key, setting_value, description)
        VALUES ('time_decay', ?, 'Time decay multipliers by period')
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()
    ", [$json]);
}

/**
 * Check if ranking tables exist
 */
function rankingTablesExist($db) {
    try {
        $tables = $db->getAll("SHOW TABLES LIKE 'ranking_settings'");
        return !empty($tables);
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Get last calculation info
 */
function getLastRankingCalculation($db) {
    $result = $db->getRow(
        "SELECT setting_value FROM ranking_settings WHERE setting_key = 'last_calculation'"
    );

    if ($result && $result['setting_value']) {
        return json_decode($result['setting_value'], true);
    }

    return ['date' => null, 'riders_processed' => 0, 'events_processed' => 0];
}

/**
 * Clean up old ranking data
 */
function cleanupOldRankingData($db, $monthsToKeep = 26) {
    $cutoffDate = date('Y-m-d', strtotime("-$monthsToKeep months"));

    // Delete old ranking points
    $db->query("DELETE FROM ranking_points WHERE event_date < ?", [$cutoffDate]);

    // Delete old snapshots (keep more history for trends)
    $snapshotCutoff = date('Y-m-d', strtotime('-36 months'));
    $db->query("DELETE FROM ranking_snapshots WHERE snapshot_date < ?", [$snapshotCutoff]);

    // Delete old history (keep 36 months)
    $db->query("DELETE FROM ranking_history WHERE month_date < ?", [$snapshotCutoff]);
}

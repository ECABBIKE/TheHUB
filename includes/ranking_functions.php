<?php
/**
 * Ranking System Functions for TheHUB
 *
 * Calculates and manages the 24-month rolling ranking system with separate
 * Enduro, Downhill, and Gravity (combined) rankings.
 *
 * Formula: Ranking Points = Original Points × Field Multiplier × Event Level Multiplier × Time Multiplier
 */

// Valid disciplines for ranking
define('RANKING_DISCIPLINES', ['ENDURO', 'DH', 'GRAVITY']);

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
 * Get default event level multipliers
 */
function getDefaultEventLevelMultipliers() {
    return [
        'national' => 1.00,
        'sportmotion' => 0.50
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
 * Get event level multipliers from database
 */
function getEventLevelMultipliers($db) {
    $result = $db->getRow(
        "SELECT setting_value FROM ranking_settings WHERE setting_key = 'event_level_multipliers'"
    );

    if ($result && $result['setting_value']) {
        $decoded = json_decode($result['setting_value'], true);
        if ($decoded) {
            return $decoded;
        }
    }

    return getDefaultEventLevelMultipliers();
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
 * Get event level multiplier
 */
function getEventLevelMultiplier($eventLevel, $multipliers) {
    $eventLevel = $eventLevel ?: 'national';
    return $multipliers[$eventLevel] ?? 1.00;
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
 * Normalize discipline name for consistency
 */
function normalizeDiscipline($discipline) {
    $discipline = strtoupper(trim($discipline));

    // Map variations to standard names
    $mapping = [
        'ENDURO' => 'ENDURO',
        'DH' => 'DH',
        'DOWNHILL' => 'DH',
        'XC' => 'XC',
        'XCO' => 'XC',
        'XCM' => 'XC',
    ];

    return $mapping[$discipline] ?? $discipline;
}

/**
 * Check if a discipline is valid for ranking
 */
function isRankingDiscipline($discipline) {
    $normalized = normalizeDiscipline($discipline);
    return in_array($normalized, ['ENDURO', 'DH']);
}

/**
 * Get all ranking-eligible results from the past 24 months
 */
function getRankingEligibleResults($db, $cutoffDate = null) {
    if (!$cutoffDate) {
        $cutoffDate = date('Y-m-d', strtotime('-24 months'));
    }

    // Get results from ENDURO and DH events
    // For DH SweCUP events, points may be stored in run_1_points + run_2_points
    $results = $db->getAll("
        SELECT
            r.id as result_id,
            r.cyclist_id as rider_id,
            r.event_id,
            r.class_id,
            COALESCE(
                CASE
                    WHEN COALESCE(r.run_1_points, 0) > 0 OR COALESCE(r.run_2_points, 0) > 0
                    THEN COALESCE(r.run_1_points, 0) + COALESCE(r.run_2_points, 0)
                    ELSE r.points
                END,
                r.points
            ) as original_points,
            e.date as event_date,
            e.name as event_name,
            e.discipline,
            COALESCE(e.event_level, 'national') as event_level,
            rd.firstname,
            rd.lastname,
            c.name as class_name
        FROM results r
        JOIN events e ON r.event_id = e.id
        JOIN riders rd ON r.cyclist_id = rd.id
        JOIN classes c ON r.class_id = c.id
        WHERE r.status = 'finished'
        AND (r.points > 0 OR COALESCE(r.run_1_points, 0) > 0 OR COALESCE(r.run_2_points, 0) > 0)
        AND e.date >= ?
        AND e.discipline IN ('ENDURO', 'DH')
        AND COALESCE(c.series_eligible, 1) = 1
        AND COALESCE(c.awards_points, 1) = 1
        ORDER BY e.date DESC, r.event_id, r.class_id, original_points DESC
    ", [$cutoffDate]);

    return $results;
}

/**
 * Calculate and save all ranking points
 */
function calculateAllRankingPoints($db, $debug = false) {
    $stats = [
        'events_processed' => 0,
        'riders_processed' => 0,
        'total_points' => 0
    ];

    $cutoffDate = date('Y-m-d', strtotime('-24 months'));

    if ($debug) {
        echo "<p>📅 Cutoff date: {$cutoffDate}</p>";
        flush();
    }

    $fieldMultipliers = getRankingFieldMultipliers($db);
    $eventLevelMultipliers = getEventLevelMultipliers($db);

    // Skip clearing - we'll use REPLACE INTO which automatically handles updates
    if ($debug) {
        echo "<p>ℹ️ Using REPLACE INTO - no clearing needed</p>";
        flush();
    }

    // Get count of results to process
    if ($debug) {
        echo "<p>📊 Counting results...</p>";
        flush();
    }

    $countResult = $db->getRow("
        SELECT COUNT(*) as total
        FROM results r
        JOIN events e ON r.event_id = e.id
        JOIN classes c ON r.class_id = c.id
        WHERE r.status = 'finished'
        AND (r.points > 0 OR COALESCE(r.run_1_points, 0) > 0 OR COALESCE(r.run_2_points, 0) > 0)
        AND e.date >= ?
        AND e.discipline IN ('ENDURO', 'DH')
        AND COALESCE(c.series_eligible, 1) = 1
        AND COALESCE(c.awards_points, 1) = 1
    ", [$cutoffDate]);
    $totalResults = $countResult['total'];

    if ($debug) {
        echo "<p>✅ Found {$totalResults} results to process</p>";
        flush();
    }

    if ($totalResults == 0) {
        return $stats;
    }

    // Step 1: Calculate field sizes (lightweight query)
    if ($debug) {
        echo "<p>🔄 Calculating field sizes...</p>";
        flush();
    }

    $fieldSizes = $db->getAll("
        SELECT
            r.event_id,
            r.class_id,
            COUNT(*) as field_size,
            e.discipline,
            COALESCE(e.event_level, 'national') as event_level
        FROM results r
        JOIN events e ON r.event_id = e.id
        JOIN classes c ON r.class_id = c.id
        WHERE r.status = 'finished'
        AND (r.points > 0 OR COALESCE(r.run_1_points, 0) > 0 OR COALESCE(r.run_2_points, 0) > 0)
        AND e.date >= ?
        AND e.discipline IN ('ENDURO', 'DH')
        AND COALESCE(c.series_eligible, 1) = 1
        AND COALESCE(c.awards_points, 1) = 1
        GROUP BY r.event_id, r.class_id, e.discipline, e.event_level
    ", [$cutoffDate]);

    // Build small lookup map
    $fieldSizeMap = [];
    foreach ($fieldSizes as $fs) {
        $key = $fs['event_id'] . '_' . $fs['class_id'];
        $fieldSizeMap[$key] = [
            'size' => (int)$fs['field_size'],
            'discipline' => normalizeDiscipline($fs['discipline']),
            'event_level' => $fs['event_level']
        ];
    }
    unset($fieldSizes); // Free memory

    if ($debug) {
        echo "<p>✅ Field sizes calculated (" . count($fieldSizeMap) . " combinations)</p>";
        echo "<p>💾 Processing results in batches...</p>";
        flush();
    }

    // Step 2: Process in small batches
    $batchSize = 50;
    $offset = 0;
    $processedEvents = [];

    while ($offset < $totalResults) {
        $results = $db->getAll("
            SELECT
                r.cyclist_id as rider_id,
                r.event_id,
                r.class_id,
                COALESCE(
                    CASE
                        WHEN COALESCE(r.run_1_points, 0) > 0 OR COALESCE(r.run_2_points, 0) > 0
                        THEN COALESCE(r.run_1_points, 0) + COALESCE(r.run_2_points, 0)
                        ELSE r.points
                    END,
                    r.points
                ) as original_points,
                e.date as event_date
            FROM results r
            JOIN events e ON r.event_id = e.id
            JOIN classes c ON r.class_id = c.id
            WHERE r.status = 'finished'
            AND (r.points > 0 OR COALESCE(r.run_1_points, 0) > 0 OR COALESCE(r.run_2_points, 0) > 0)
            AND e.date >= ?
            AND e.discipline IN ('ENDURO', 'DH')
            AND COALESCE(c.series_eligible, 1) = 1
            AND COALESCE(c.awards_points, 1) = 1
            LIMIT ? OFFSET ?
        ", [$cutoffDate, $batchSize, $offset]);

        if (empty($results)) {
            break;
        }

        foreach ($results as $result) {
            $key = $result['event_id'] . '_' . $result['class_id'];

            if (!isset($fieldSizeMap[$key])) {
                continue;
            }

            $fieldData = $fieldSizeMap[$key];
            $fieldSize = $fieldData['size'];
            $discipline = $fieldData['discipline'];
            $eventLevel = $fieldData['event_level'];

            if (!isset($processedEvents[$result['event_id']])) {
                $processedEvents[$result['event_id']] = true;
                $stats['events_processed']++;
            }

            $fieldMult = getFieldMultiplier($fieldSize, $fieldMultipliers);
            $eventLevelMult = getEventLevelMultiplier($eventLevel, $eventLevelMultipliers);
            $rankingPoints = round($result['original_points'] * $fieldMult * $eventLevelMult, 2);

            // Use INSERT ... ON DUPLICATE KEY UPDATE to handle existing records
            $db->query("
                INSERT INTO ranking_points (
                    rider_id, event_id, class_id, discipline,
                    original_points, field_size, field_multiplier,
                    event_level_multiplier, ranking_points, event_date
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    discipline = VALUES(discipline),
                    original_points = VALUES(original_points),
                    field_size = VALUES(field_size),
                    field_multiplier = VALUES(field_multiplier),
                    event_level_multiplier = VALUES(event_level_multiplier),
                    ranking_points = VALUES(ranking_points),
                    event_date = VALUES(event_date)
            ", [
                $result['rider_id'],
                $result['event_id'],
                $result['class_id'],
                $discipline,
                $result['original_points'],
                $fieldSize,
                $fieldMult,
                $eventLevelMult,
                $rankingPoints,
                $result['event_date']
            ]);

            $stats['riders_processed']++;
            $stats['total_points'] += $rankingPoints;
        }

        $offset += $batchSize;

        if ($debug && $offset % 100 == 0) {
            $progress = min(100, round(($offset / $totalResults) * 100));
            echo "<p>💾 {$offset}/{$totalResults} ({$progress}%)</p>";
            flush();
        }

        unset($results);
        gc_collect_cycles();
    }

    if ($debug) {
        echo "<p>✅ All ranking points inserted</p>";
        echo "<p>📝 Updating timestamp...</p>";
        flush();
    }

    $db->query("
        UPDATE ranking_settings
        SET setting_value = ?
        WHERE setting_key = 'last_calculation'
    ", [json_encode([
        'date' => date('Y-m-d H:i:s'),
        'riders_processed' => $stats['riders_processed'],
        'events_processed' => $stats['events_processed']
    ])]);

    if ($debug) {
        echo "<p>✅ Complete!</p>";
        flush();
    }

    return $stats;
}

/**
 * Create ranking snapshots for all disciplines
 */
function createRankingSnapshot($db, $snapshotDate = null) {
    if (!$snapshotDate) {
        $snapshotDate = date('Y-m-01');
    }

    $stats = [
        'riders_ranked' => 0,
        'enduro' => 0,
        'dh' => 0,
        'gravity' => 0
    ];

    // Create snapshots for each discipline
    $stats['enduro'] = createDisciplineSnapshot($db, 'ENDURO', $snapshotDate);
    $stats['dh'] = createDisciplineSnapshot($db, 'DH', $snapshotDate);
    $stats['gravity'] = createDisciplineSnapshot($db, 'GRAVITY', $snapshotDate);

    $stats['riders_ranked'] = $stats['enduro'] + $stats['dh'] + $stats['gravity'];

    return $stats;
}

/**
 * Create a ranking snapshot for a specific discipline
 */
function createDisciplineSnapshot($db, $discipline, $snapshotDate) {
    $timeDecay = getRankingTimeDecay($db);

    // Calculate date boundaries
    $month12Cutoff = date('Y-m-d', strtotime("$snapshotDate -12 months"));
    $month24Cutoff = date('Y-m-d', strtotime("$snapshotDate -24 months"));

    // Get previous snapshot for position comparison
    $previousSnapshotDate = date('Y-m-01', strtotime("$snapshotDate -1 month"));
    $previousRankings = [];
    $previousData = $db->getAll(
        "SELECT rider_id, ranking_position FROM ranking_snapshots WHERE discipline = ? AND snapshot_date = ?",
        [$discipline, $previousSnapshotDate]
    );
    foreach ($previousData as $row) {
        $previousRankings[$row['rider_id']] = $row['ranking_position'];
    }

    // Clear existing snapshot for this discipline and date
    $db->query(
        "DELETE FROM ranking_snapshots WHERE discipline = ? AND snapshot_date = ?",
        [$discipline, $snapshotDate]
    );

    // Build discipline filter for query
    if ($discipline === 'GRAVITY') {
        $disciplineCondition = "rp.discipline IN ('ENDURO', 'DH')";
        $params = [$month12Cutoff, $month12Cutoff, $month24Cutoff, $month24Cutoff,
                   $month12Cutoff, $timeDecay['months_1_12'],
                   $month12Cutoff, $month24Cutoff, $timeDecay['months_13_24']];
    } else {
        $disciplineCondition = "rp.discipline = ?";
        $params = [$discipline, $month12Cutoff, $month12Cutoff, $month24Cutoff, $month24Cutoff,
                   $discipline, $month12Cutoff, $timeDecay['months_1_12'],
                   $month12Cutoff, $month24Cutoff, $timeDecay['months_13_24']];
    }

    // Calculate ranking points with time decay for each rider
    if ($discipline === 'GRAVITY') {
        $riderPoints = $db->getAll("
            SELECT
                rp.rider_id,
                SUM(CASE WHEN rp.event_date >= ? THEN rp.ranking_points ELSE 0 END) as points_12,
                SUM(CASE WHEN rp.event_date < ? AND rp.event_date >= ? THEN rp.ranking_points ELSE 0 END) as points_13_24,
                COUNT(DISTINCT rp.event_id) as events_count
            FROM ranking_points rp
            WHERE rp.discipline IN ('ENDURO', 'DH')
            AND rp.event_date >= ?
            GROUP BY rp.rider_id
            HAVING SUM(rp.ranking_points) > 0
            ORDER BY (SUM(CASE WHEN rp.event_date >= ? THEN rp.ranking_points ELSE 0 END) * ? +
                      SUM(CASE WHEN rp.event_date < ? AND rp.event_date >= ? THEN rp.ranking_points ELSE 0 END) * ?) DESC
        ", $params);
    } else {
        $riderPoints = $db->getAll("
            SELECT
                rp.rider_id,
                SUM(CASE WHEN rp.event_date >= ? THEN rp.ranking_points ELSE 0 END) as points_12,
                SUM(CASE WHEN rp.event_date < ? AND rp.event_date >= ? THEN rp.ranking_points ELSE 0 END) as points_13_24,
                COUNT(DISTINCT rp.event_id) as events_count
            FROM ranking_points rp
            WHERE rp.discipline = ?
            AND rp.event_date >= ?
            GROUP BY rp.rider_id
            HAVING SUM(rp.ranking_points) > 0
            ORDER BY (SUM(CASE WHEN rp.event_date >= ? THEN rp.ranking_points ELSE 0 END) * ? +
                      SUM(CASE WHEN rp.event_date < ? AND rp.event_date >= ? THEN rp.ranking_points ELSE 0 END) * ?) DESC
        ", [$month12Cutoff, $month12Cutoff, $month24Cutoff, $discipline, $month24Cutoff,
            $month12Cutoff, $timeDecay['months_1_12'], $month12Cutoff, $month24Cutoff, $timeDecay['months_13_24']]);
    }

    // Insert snapshots with rankings
    $rank = 1;
    $prevPoints = null;
    $prevRank = 1;
    $ridersRanked = 0;

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
            'discipline' => $discipline,
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
            INSERT INTO ranking_history (rider_id, discipline, month_date, ranking_position, total_points)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE ranking_position = VALUES(ranking_position), total_points = VALUES(total_points)
        ", [$rider['rider_id'], $discipline, $snapshotDate, $currentRank, round($totalPoints, 2)]);

        $ridersRanked++;
        $rank++;
    }

    return $ridersRanked;
}

/**
 * Get current ranking for a specific discipline
 */
function getCurrentRanking($db, $discipline = 'GRAVITY', $limit = 50, $offset = 0) {
    // Get the most recent snapshot date for this discipline
    $latestSnapshot = $db->getRow("
        SELECT MAX(snapshot_date) as snapshot_date FROM ranking_snapshots WHERE discipline = ?
    ", [$discipline]);

    if (!$latestSnapshot || !$latestSnapshot['snapshot_date']) {
        return ['riders' => [], 'total' => 0, 'snapshot_date' => null, 'discipline' => $discipline];
    }

    $snapshotDate = $latestSnapshot['snapshot_date'];

    // Get total count
    $totalCount = $db->getRow("
        SELECT COUNT(*) as total FROM ranking_snapshots WHERE discipline = ? AND snapshot_date = ?
    ", [$discipline, $snapshotDate]);

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
        WHERE rs.discipline = ? AND rs.snapshot_date = ?
        ORDER BY rs.ranking_position ASC
        LIMIT ? OFFSET ?
    ", [$discipline, $snapshotDate, $limit, $offset]);

    return [
        'riders' => $riders,
        'total' => (int)$totalCount['total'],
        'snapshot_date' => $snapshotDate,
        'discipline' => $discipline
    ];
}

/**
 * Get ranking history for a specific rider and discipline
 */
function getRiderRankingHistory($db, $riderId, $discipline = 'GRAVITY', $months = 12) {
    return $db->getAll("
        SELECT
            month_date,
            ranking_position,
            total_points
        FROM ranking_history
        WHERE rider_id = ? AND discipline = ?
        ORDER BY month_date DESC
        LIMIT ?
    ", [$riderId, $discipline, $months]);
}

/**
 * Get detailed ranking breakdown for a rider
 */
function getRiderRankingDetails($db, $riderId, $discipline = 'GRAVITY') {
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
        SELECT MAX(snapshot_date) as snapshot_date FROM ranking_snapshots WHERE discipline = ?
    ", [$discipline]);

    $snapshotDate = $latestSnapshot ? $latestSnapshot['snapshot_date'] : date('Y-m-01');

    $ranking = $db->getRow("
        SELECT * FROM ranking_snapshots
        WHERE rider_id = ? AND discipline = ? AND snapshot_date = ?
    ", [$riderId, $discipline, $snapshotDate]);

    // Build discipline filter
    if ($discipline === 'GRAVITY') {
        $disciplineCondition = "rp.discipline IN ('ENDURO', 'DH')";
        $params = [$riderId];
    } else {
        $disciplineCondition = "rp.discipline = ?";
        $params = [$riderId, $discipline];
    }

    // Get event breakdown
    $events = $db->getAll("
        SELECT
            rp.event_id,
            rp.class_id,
            rp.discipline,
            rp.original_points,
            rp.field_size,
            rp.field_multiplier,
            rp.event_level_multiplier,
            rp.ranking_points,
            rp.event_date,
            e.name as event_name,
            e.location,
            e.event_level,
            cl.name as class_name,
            cl.display_name as class_display_name
        FROM ranking_points rp
        JOIN events e ON rp.event_id = e.id
        JOIN classes cl ON rp.class_id = cl.id
        WHERE rp.rider_id = ? AND $disciplineCondition
        ORDER BY rp.event_date DESC
    ", $params);

    return [
        'rider' => $rider,
        'ranking' => $ranking,
        'events' => $events,
        'discipline' => $discipline
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
 * Save event level multipliers to database
 */
function saveEventLevelMultipliers($db, $multipliers) {
    $json = json_encode($multipliers);

    return $db->query("
        INSERT INTO ranking_settings (setting_key, setting_value, description)
        VALUES ('event_level_multipliers', ?, 'Multipliers for event level (national vs sportmotion)')
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
    $db->query("DELETE FROM club_ranking_snapshots WHERE snapshot_date < ?", [$snapshotCutoff]);

    // Delete old history (keep 36 months)
    $db->query("DELETE FROM ranking_history WHERE month_date < ?", [$snapshotCutoff]);
}

/**
 * Get discipline display name
 */
function getDisciplineDisplayName($discipline) {
    $names = [
        'ENDURO' => 'Enduro',
        'DH' => 'Downhill',
        'GRAVITY' => 'Gravity'
    ];
    return $names[$discipline] ?? $discipline;
}

/**
 * Get ranking statistics per discipline
 */
function getRankingStats($db) {
    $stats = [
        'ENDURO' => ['riders' => 0, 'events' => 0, 'clubs' => 0],
        'DH' => ['riders' => 0, 'events' => 0, 'clubs' => 0],
        'GRAVITY' => ['riders' => 0, 'events' => 0, 'clubs' => 0]
    ];

    // Get latest snapshot date
    $latestSnapshot = $db->getRow("SELECT MAX(snapshot_date) as snapshot_date FROM ranking_snapshots");
    if (!$latestSnapshot || !$latestSnapshot['snapshot_date']) {
        return $stats;
    }

    $snapshotDate = $latestSnapshot['snapshot_date'];

    // Get counts per discipline
    foreach (['ENDURO', 'DH', 'GRAVITY'] as $discipline) {
        $count = $db->getRow(
            "SELECT COUNT(*) as count FROM ranking_snapshots WHERE discipline = ? AND snapshot_date = ?",
            [$discipline, $snapshotDate]
        );
        $stats[$discipline]['riders'] = $count ? $count['count'] : 0;

        // Get club counts (if table exists)
        try {
            $clubCount = $db->getRow(
                "SELECT COUNT(*) as count FROM club_ranking_snapshots WHERE discipline = ? AND snapshot_date = ?",
                [$discipline, $snapshotDate]
            );
            $stats[$discipline]['clubs'] = $clubCount ? $clubCount['count'] : 0;
        } catch (Exception $e) {
            $stats[$discipline]['clubs'] = 0;
        }
    }

    // Get event counts
    $enduroEvents = $db->getRow("SELECT COUNT(DISTINCT event_id) as count FROM ranking_points WHERE discipline = 'ENDURO'");
    $dhEvents = $db->getRow("SELECT COUNT(DISTINCT event_id) as count FROM ranking_points WHERE discipline = 'DH'");

    $stats['ENDURO']['events'] = $enduroEvents ? $enduroEvents['count'] : 0;
    $stats['DH']['events'] = $dhEvents ? $dhEvents['count'] : 0;
    $stats['GRAVITY']['events'] = $stats['ENDURO']['events'] + $stats['DH']['events'];

    return $stats;
}

/**
 * Create club ranking snapshots for all disciplines
 * Clubs are ranked based on their riders' combined ranking points
 */
function createClubRankingSnapshot($db, $snapshotDate = null) {
    if (!$snapshotDate) {
        $snapshotDate = date('Y-m-01');
    }

    $stats = [
        'clubs_ranked' => 0,
        'enduro' => 0,
        'dh' => 0,
        'gravity' => 0
    ];

    // Create snapshots for each discipline
    $stats['enduro'] = createClubDisciplineSnapshot($db, 'ENDURO', $snapshotDate);
    $stats['dh'] = createClubDisciplineSnapshot($db, 'DH', $snapshotDate);
    $stats['gravity'] = createClubDisciplineSnapshot($db, 'GRAVITY', $snapshotDate);

    $stats['clubs_ranked'] = $stats['enduro'] + $stats['dh'] + $stats['gravity'];

    return $stats;
}

/**
 * Create a club ranking snapshot for a specific discipline
 * Aggregates rider ranking points by club
 */
function createClubDisciplineSnapshot($db, $discipline, $snapshotDate) {
    $timeDecay = getRankingTimeDecay($db);

    // Calculate date boundaries
    $month12Cutoff = date('Y-m-d', strtotime("$snapshotDate -12 months"));
    $month24Cutoff = date('Y-m-d', strtotime("$snapshotDate -24 months"));

    // Get previous snapshot for position comparison
    $previousSnapshotDate = date('Y-m-01', strtotime("$snapshotDate -1 month"));
    $previousRankings = [];
    $previousData = $db->getAll(
        "SELECT club_id, ranking_position FROM club_ranking_snapshots WHERE discipline = ? AND snapshot_date = ?",
        [$discipline, $previousSnapshotDate]
    );
    foreach ($previousData as $row) {
        $previousRankings[$row['club_id']] = $row['ranking_position'];
    }

    // Clear existing snapshot for this discipline and date
    $db->query(
        "DELETE FROM club_ranking_snapshots WHERE discipline = ? AND snapshot_date = ?",
        [$discipline, $snapshotDate]
    );

    // Build discipline filter for query
    if ($discipline === 'GRAVITY') {
        $disciplineCondition = "rp.discipline IN ('ENDURO', 'DH')";
        $params = [$month12Cutoff, $month12Cutoff, $month24Cutoff, $month24Cutoff,
                   $month12Cutoff, $timeDecay['months_1_12'],
                   $month12Cutoff, $month24Cutoff, $timeDecay['months_13_24']];
    } else {
        $disciplineCondition = "rp.discipline = ?";
        $params = [$discipline, $month12Cutoff, $month12Cutoff, $month24Cutoff, $month24Cutoff,
                   $discipline, $month12Cutoff, $timeDecay['months_1_12'],
                   $month12Cutoff, $month24Cutoff, $timeDecay['months_13_24']];
    }

    // Calculate club ranking points by aggregating rider points
    if ($discipline === 'GRAVITY') {
        $clubPoints = $db->getAll("
            SELECT
                r.club_id,
                SUM(CASE WHEN rp.event_date >= ? THEN rp.ranking_points ELSE 0 END) as points_12,
                SUM(CASE WHEN rp.event_date < ? AND rp.event_date >= ? THEN rp.ranking_points ELSE 0 END) as points_13_24,
                COUNT(DISTINCT rp.rider_id) as riders_count,
                COUNT(DISTINCT rp.event_id) as events_count
            FROM ranking_points rp
            JOIN riders r ON rp.rider_id = r.id
            WHERE rp.discipline IN ('ENDURO', 'DH')
            AND rp.event_date >= ?
            AND r.club_id IS NOT NULL
            GROUP BY r.club_id
            HAVING SUM(rp.ranking_points) > 0
            ORDER BY (SUM(CASE WHEN rp.event_date >= ? THEN rp.ranking_points ELSE 0 END) * ? +
                      SUM(CASE WHEN rp.event_date < ? AND rp.event_date >= ? THEN rp.ranking_points ELSE 0 END) * ?) DESC
        ", $params);
    } else {
        $clubPoints = $db->getAll("
            SELECT
                r.club_id,
                SUM(CASE WHEN rp.event_date >= ? THEN rp.ranking_points ELSE 0 END) as points_12,
                SUM(CASE WHEN rp.event_date < ? AND rp.event_date >= ? THEN rp.ranking_points ELSE 0 END) as points_13_24,
                COUNT(DISTINCT rp.rider_id) as riders_count,
                COUNT(DISTINCT rp.event_id) as events_count
            FROM ranking_points rp
            JOIN riders r ON rp.rider_id = r.id
            WHERE rp.discipline = ?
            AND rp.event_date >= ?
            AND r.club_id IS NOT NULL
            GROUP BY r.club_id
            HAVING SUM(rp.ranking_points) > 0
            ORDER BY (SUM(CASE WHEN rp.event_date >= ? THEN rp.ranking_points ELSE 0 END) * ? +
                      SUM(CASE WHEN rp.event_date < ? AND rp.event_date >= ? THEN rp.ranking_points ELSE 0 END) * ?) DESC
        ", [$month12Cutoff, $month12Cutoff, $month24Cutoff, $discipline, $month24Cutoff,
            $month12Cutoff, $timeDecay['months_1_12'], $month12Cutoff, $month24Cutoff, $timeDecay['months_13_24']]);
    }

    // Insert snapshots with rankings
    $rank = 1;
    $prevPoints = null;
    $prevRank = 1;
    $clubsRanked = 0;

    foreach ($clubPoints as $club) {
        $totalPoints = ($club['points_12'] * $timeDecay['months_1_12']) +
                       ($club['points_13_24'] * $timeDecay['months_13_24']);

        // Handle ties
        if ($prevPoints !== null && $totalPoints == $prevPoints) {
            $currentRank = $prevRank;
        } else {
            $currentRank = $rank;
            $prevRank = $rank;
        }
        $prevPoints = $totalPoints;

        // Calculate position change
        $previousPosition = $previousRankings[$club['club_id']] ?? null;
        $positionChange = null;
        if ($previousPosition !== null) {
            $positionChange = $previousPosition - $currentRank;
        }

        $db->insert('club_ranking_snapshots', [
            'club_id' => $club['club_id'],
            'discipline' => $discipline,
            'snapshot_date' => $snapshotDate,
            'total_ranking_points' => round($totalPoints, 2),
            'points_last_12_months' => round($club['points_12'], 2),
            'points_months_13_24' => round($club['points_13_24'], 2),
            'riders_count' => $club['riders_count'],
            'events_count' => $club['events_count'],
            'ranking_position' => $currentRank,
            'previous_position' => $previousPosition,
            'position_change' => $positionChange
        ]);

        $clubsRanked++;
        $rank++;
    }

    return $clubsRanked;
}

/**
 * Get current club ranking for a specific discipline
 */
function getCurrentClubRanking($db, $discipline = 'GRAVITY', $limit = 50, $offset = 0) {
    // Get the most recent snapshot date for this discipline
    $latestSnapshot = $db->getRow("
        SELECT MAX(snapshot_date) as snapshot_date FROM club_ranking_snapshots WHERE discipline = ?
    ", [$discipline]);

    if (!$latestSnapshot || !$latestSnapshot['snapshot_date']) {
        return ['clubs' => [], 'total' => 0, 'snapshot_date' => null, 'discipline' => $discipline];
    }

    $snapshotDate = $latestSnapshot['snapshot_date'];

    // Get total count
    $totalCount = $db->getRow("
        SELECT COUNT(*) as total FROM club_ranking_snapshots WHERE discipline = ? AND snapshot_date = ?
    ", [$discipline, $snapshotDate]);

    // Get rankings with club info
    $clubs = $db->getAll("
        SELECT
            crs.ranking_position,
            crs.total_ranking_points,
            crs.points_last_12_months,
            crs.points_months_13_24,
            crs.riders_count,
            crs.events_count,
            crs.previous_position,
            crs.position_change,
            c.id as club_id,
            c.name as club_name,
            c.short_name,
            c.city,
            c.region
        FROM club_ranking_snapshots crs
        JOIN clubs c ON crs.club_id = c.id
        WHERE crs.discipline = ? AND crs.snapshot_date = ?
        ORDER BY crs.ranking_position ASC
        LIMIT ? OFFSET ?
    ", [$discipline, $snapshotDate, $limit, $offset]);

    return [
        'clubs' => $clubs,
        'total' => (int)$totalCount['total'],
        'snapshot_date' => $snapshotDate,
        'discipline' => $discipline
    ];
}

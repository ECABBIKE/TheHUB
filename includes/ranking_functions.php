<?php
/**
 * Lightweight Ranking System Functions for TheHUB
 *
 * Calculates ranking on-the-fly from results table without intermediate storage.
 * Only saves monthly snapshots to ranking_snapshots for historical tracking.
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
 * Calculate ranking data on-the-fly from results table
 *
 * @param object $db Database connection
 * @param string $discipline Discipline to calculate (ENDURO, DH, or GRAVITY)
 * @param bool $debug Enable debug output
 * @return array Ranking data with riders sorted by total points
 */
function calculateRankingData($db, $discipline = null, $debug = false) {
    $cutoffDate = date('Y-m-d', strtotime('-24 months'));
    $month12Cutoff = date('Y-m-d', strtotime('-12 months'));

    $fieldMultipliers = getRankingFieldMultipliers($db);
    $eventLevelMultipliers = getEventLevelMultipliers($db);
    $timeDecay = getRankingTimeDecay($db);

    if ($debug) {
        echo "<p>📅 Cutoff dates: 24mo={$cutoffDate}, 12mo={$month12Cutoff}</p>";
        flush();
    }

    // Build discipline filter
    $disciplineFilter = '';
    $params = [$cutoffDate];

    if ($discipline && $discipline !== 'GRAVITY') {
        $disciplineFilter = 'AND e.discipline = ?';
        $params[] = $discipline;
    } elseif ($discipline === 'GRAVITY') {
        $disciplineFilter = "AND e.discipline IN ('ENDURO', 'DH')";
    } else {
        $disciplineFilter = "AND e.discipline IN ('ENDURO', 'DH')";
    }

    // Get all ranking-eligible results
    if ($debug) {
        echo "<p>⏱️ Step 2: Executing query now...</p>";
        flush();
        $queryStart = microtime(true);
    }

    try {
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
            e.date as event_date,
            e.discipline,
            COALESCE(e.event_level, 'national') as event_level
        FROM results r
        STRAIGHT_JOIN events e ON r.event_id = e.id
        STRAIGHT_JOIN classes cl ON r.class_id = cl.id
        WHERE r.status = 'finished'
        AND (r.points > 0 OR COALESCE(r.run_1_points, 0) > 0 OR COALESCE(r.run_2_points, 0) > 0)
        AND e.date >= ?
        {$disciplineFilter}
        AND COALESCE(cl.series_eligible, 1) = 1
        AND COALESCE(cl.awards_points, 1) = 1
    ", $params);
    } catch (Exception $e) {
        if ($debug) {
            echo "<p style='color:red;'>❌ Query failed: " . htmlspecialchars($e->getMessage()) . "</p>";
            flush();
        }
        throw $e;
    }

    if ($debug) {
        $queryTime = round(microtime(true) - $queryStart, 2);
        echo "<p>✅ Query completed! Found " . count($results) . " results (took {$queryTime}s)</p>";
        flush();
    }

    // Calculate field sizes per event/class combination
    if ($debug) {
        echo "<p>📊 Calculating field sizes...</p>";
        flush();
    }

    $fieldSizes = [];
    foreach ($results as $result) {
        $key = $result['event_id'] . '_' . $result['class_id'];
        if (!isset($fieldSizes[$key])) {
            $fieldSizes[$key] = 0;
        }
        $fieldSizes[$key]++;
    }

    // Get rider info separately (more efficient)
    if ($debug) {
        echo "<p>👥 Fetching rider information...</p>";
        flush();
    }

    $riderIds = array_values(array_unique(array_column($results, 'rider_id')));
    $riderInfo = [];
    if (!empty($riderIds)) {
        $placeholders = implode(',', array_fill(0, count($riderIds), '?'));
        $riders = $db->getAll("
            SELECT r.id, r.firstname, r.lastname, r.club_id, c.name as club_name
            FROM riders r
            LEFT JOIN clubs c ON r.club_id = c.id
            WHERE r.id IN ($placeholders)
        ", $riderIds);

        foreach ($riders as $rider) {
            $riderInfo[$rider['id']] = $rider;
        }
    }

    if ($debug) {
        echo "<p>🧮 Processing " . count($results) . " results...</p>";
        flush();
    }

    // Calculate ranking points for each result
    $riderData = [];

    foreach ($results as $result) {
        $riderId = $result['rider_id'];
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
        $rankingPoints = $result['original_points'] * $fieldMult * $eventLevelMult;
        $weightedPoints = $rankingPoints * $timeMult;

        // Initialize rider data if needed
        if (!isset($riderData[$riderId])) {
            $info = $riderInfo[$riderId] ?? ['firstname' => '', 'lastname' => '', 'club_id' => null, 'club_name' => null];
            $riderData[$riderId] = [
                'rider_id' => $riderId,
                'firstname' => $info['firstname'],
                'lastname' => $info['lastname'],
                'club_id' => $info['club_id'],
                'club_name' => $info['club_name'],
                'total_points' => 0,
                'points_12' => 0,
                'points_13_24' => 0,
                'events_count' => 0,
                'events' => []
            ];
        }

        // Add to totals
        $riderData[$riderId]['total_points'] += $weightedPoints;

        if ($monthsDiff < 12) {
            $riderData[$riderId]['points_12'] += $rankingPoints;
        } elseif ($monthsDiff < 24) {
            $riderData[$riderId]['points_13_24'] += $rankingPoints;
        }

        // Track unique events
        if (!in_array($result['event_id'], $riderData[$riderId]['events'])) {
            $riderData[$riderId]['events'][] = $result['event_id'];
            $riderData[$riderId]['events_count']++;
        }
    }

    // Sort by total points descending
    usort($riderData, function($a, $b) {
        return $b['total_points'] <=> $a['total_points'];
    });

    // Assign ranking positions with tie handling
    $rank = 1;
    $prevPoints = null;
    $prevRank = 1;

    foreach ($riderData as &$rider) {
        if ($prevPoints !== null && abs($rider['total_points'] - $prevPoints) < 0.01) {
            $rider['ranking_position'] = $prevRank;
        } else {
            $rider['ranking_position'] = $rank;
            $prevRank = $rank;
        }
        $prevPoints = $rider['total_points'];
        $rank++;
    }

    if ($debug) {
        echo "<p>✅ Calculated ranking for " . count($riderData) . " riders</p>";
        flush();
    }

    return $riderData;
}

/**
 * Create ranking snapshot for a specific discipline
 * Saves calculated ranking data to database with small pauses for shared hosting
 *
 * @param object $db Database connection
 * @param string $discipline Discipline to snapshot
 * @param string $snapshotDate Date for snapshot (YYYY-MM-DD)
 * @param bool $debug Enable debug output
 * @return int Number of riders ranked
 */
function createRankingSnapshot($db, $discipline = 'gravity', $snapshotDate = null, $debug = false) {
    if (!$snapshotDate) {
        $snapshotDate = date('Y-m-01');
    }

    $discipline = strtoupper($discipline);

    if ($debug) {
        echo "<p>🔍 Getting previous snapshot for comparison...</p>";
        flush();
    }

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

    if ($debug) {
        echo "<p>🧮 Starting ranking calculation (skipping DELETE to avoid timeout)...</p>";
        echo "<p>⏱️ Step 1: About to fetch results from database...</p>";
        flush();
    }

    // Calculate ranking data
    $riderData = calculateRankingData($db, $discipline, $debug);

    if ($debug) {
        echo "<p>🗑️ Clearing old snapshot data in small chunks...</p>";
        flush();
    }

    // Delete old snapshot data in small chunks to avoid timeout
    // Keep deleting until no more rows exist for this discipline/date
    $deletedTotal = 0;
    $maxIterations = 100; // Safety limit
    $iteration = 0;

    while ($iteration < $maxIterations) {
        // Delete in chunks of 50 rows
        $result = $db->query("DELETE FROM ranking_snapshots WHERE discipline = ? AND snapshot_date = ? LIMIT 50",
                            [$discipline, $snapshotDate]);

        $affected = $db->affectedRows();
        $deletedTotal += $affected;

        if ($debug && $iteration % 10 == 0 && $deletedTotal > 0) {
            echo "<p>🗑️ Deleted {$deletedTotal} old rows so far...</p>";
            flush();
        }

        if ($affected < 50) {
            // No more rows to delete
            break;
        }

        $iteration++;
        usleep(10000); // 10ms pause between deletes
    }

    if ($debug) {
        echo "<p>✅ Cleared {$deletedTotal} old snapshot rows</p>";
        echo "<p>💾 Inserting " . count($riderData) . " new riders using plain INSERT...</p>";
        flush();
    }

    // Now use plain INSERT (no duplicate key check needed since we deleted old data)
    $inserted = 0;

    foreach ($riderData as $index => $rider) {
        if ($debug && $index % 100 == 0) {
            echo "<p>📝 Inserted " . $inserted . " / " . count($riderData) . " riders...</p>";
            flush();
        }

        $previousPosition = $previousRankings[$rider['rider_id']] ?? null;
        $positionChange = null;
        if ($previousPosition !== null) {
            $positionChange = $previousPosition - $rider['ranking_position'];
        }

        // Plain INSERT - faster than ON DUPLICATE KEY UPDATE
        $db->query("INSERT INTO ranking_snapshots
                (rider_id, discipline, snapshot_date, total_ranking_points, points_last_12_months,
                 points_months_13_24, events_count, ranking_position, previous_position, position_change)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", [
            $rider['rider_id'],
            $discipline,
            $snapshotDate,
            round($rider['total_points'], 2),
            round($rider['points_12'], 2),
            round($rider['points_13_24'], 2),
            $rider['events_count'],
            $rider['ranking_position'],
            $previousPosition,
            $positionChange
        ]);

        // Also insert into ranking_history
        $db->query("INSERT INTO ranking_history (rider_id, discipline, month_date, ranking_position, total_points)
                   VALUES (?, ?, ?, ?, ?)
                   ON DUPLICATE KEY UPDATE ranking_position = VALUES(ranking_position), total_points = VALUES(total_points)", [
            $rider['rider_id'],
            $discipline,
            $snapshotDate,
            $rider['ranking_position'],
            round($rider['total_points'], 2)
        ]);

        $inserted++;
    }

    if ($debug) {
        echo "<p>✅ Saved all " . $inserted . " riders!</p>";
        flush();
    }

    return $inserted;
}

/**
 * Get current ranking for a specific discipline
 * Returns from snapshot if available, otherwise calculates live
 *
 * @param object $db Database connection
 * @param string $discipline Discipline to get ranking for
 * @param int $limit Number of results to return
 * @param int $offset Offset for pagination
 * @param bool $useLive Force live calculation instead of snapshot
 * @return array Ranking data with metadata
 */
function getCurrentRanking($db, $discipline = 'gravity', $limit = 100, $offset = 0, $useLive = false) {
    $discipline = strtoupper($discipline);

    // Try to use snapshot first unless live is requested
    if (!$useLive) {
        $latestSnapshot = $db->getRow("
            SELECT MAX(snapshot_date) as snapshot_date FROM ranking_snapshots WHERE discipline = ?
        ", [$discipline]);

        if ($latestSnapshot && $latestSnapshot['snapshot_date']) {
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
                'discipline' => $discipline,
                'source' => 'snapshot'
            ];
        }
    }

    // Fall back to live calculation
    $riderData = calculateRankingData($db, $discipline, false);

    // Apply pagination
    $total = count($riderData);
    $riders = array_slice($riderData, $offset, $limit);

    return [
        'riders' => $riders,
        'total' => $total,
        'snapshot_date' => null,
        'discipline' => $discipline,
        'source' => 'live'
    ];
}

/**
 * Calculate club ranking by aggregating rider points
 *
 * @param object $db Database connection
 * @param string $discipline Discipline to calculate
 * @return array Club ranking data
 */
function calculateClubRanking($db, $discipline = 'gravity') {
    $riderData = calculateRankingData($db, $discipline, false);

    // Aggregate by club
    $clubData = [];
    foreach ($riderData as $rider) {
        if (!$rider['club_id']) {
            continue;
        }

        $clubId = $rider['club_id'];

        if (!isset($clubData[$clubId])) {
            $clubData[$clubId] = [
                'club_id' => $clubId,
                'club_name' => $rider['club_name'],
                'total_points' => 0,
                'points_12' => 0,
                'points_13_24' => 0,
                'riders_count' => 0,
                'events_count' => 0
            ];
        }

        $clubData[$clubId]['total_points'] += $rider['total_points'];
        $clubData[$clubId]['points_12'] += $rider['points_12'];
        $clubData[$clubId]['points_13_24'] += $rider['points_13_24'];
        $clubData[$clubId]['riders_count']++;
        $clubData[$clubId]['events_count'] = max($clubData[$clubId]['events_count'], $rider['events_count']);
    }

    // Sort by total points
    usort($clubData, function($a, $b) {
        return $b['total_points'] <=> $a['total_points'];
    });

    // Assign rankings
    $rank = 1;
    $prevPoints = null;
    $prevRank = 1;

    foreach ($clubData as &$club) {
        if ($prevPoints !== null && abs($club['total_points'] - $prevPoints) < 0.01) {
            $club['ranking_position'] = $prevRank;
        } else {
            $club['ranking_position'] = $rank;
            $prevRank = $rank;
        }
        $prevPoints = $club['total_points'];
        $rank++;
    }

    return $clubData;
}

/**
 * Create club ranking snapshot
 *
 * @param object $db Database connection
 * @param string $discipline Discipline to snapshot
 * @param string $snapshotDate Date for snapshot
 * @param bool $debug Enable debug output
 * @return int Number of clubs ranked
 */
function createClubRankingSnapshot($db, $discipline = 'gravity', $snapshotDate = null, $debug = false) {
    if (!$snapshotDate) {
        $snapshotDate = date('Y-m-01');
    }

    $discipline = strtoupper($discipline);

    if ($debug) {
        echo "<p>🏛️ Getting previous club rankings...</p>";
        flush();
    }

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

    if ($debug) {
        echo "<p>🧮 Calculating club rankings (skipping DELETE)...</p>";
        flush();
    }

    // Calculate club ranking
    $clubData = calculateClubRanking($db, $discipline);

    if ($debug) {
        echo "<p>💾 Saving " . count($clubData) . " clubs using small batches with INSERT...ON DUPLICATE KEY UPDATE...</p>";
        flush();
    }

    // Use very small batches (10 rows) with INSERT...ON DUPLICATE KEY UPDATE
    $batchSize = 10;
    $batches = array_chunk($clubData, $batchSize);
    $inserted = 0;

    foreach ($batches as $batchIndex => $batch) {
        if ($debug) {
            echo "<p>📝 Processing club batch " . ($batchIndex + 1) . " / " . count($batches) . "...</p>";
            flush();
        }

        // Build multi-row INSERT...ON DUPLICATE KEY UPDATE
        $values = [];
        $params = [];

        foreach ($batch as $club) {
            $previousPosition = $previousRankings[$club['club_id']] ?? null;
            $positionChange = null;
            if ($previousPosition !== null) {
                $positionChange = $previousPosition - $club['ranking_position'];
            }

            $values[] = "(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $params = array_merge($params, [
                $club['club_id'],
                $discipline,
                $snapshotDate,
                round($club['total_points'], 2),
                round($club['points_12'], 2),
                round($club['points_13_24'], 2),
                $club['riders_count'],
                $club['events_count'],
                $club['ranking_position'],
                $previousPosition,
                $positionChange
            ]);
        }

        // Use INSERT...ON DUPLICATE KEY UPDATE instead of REPLACE INTO
        $sql = "INSERT INTO club_ranking_snapshots
                (club_id, discipline, snapshot_date, total_ranking_points, points_last_12_months,
                 points_months_13_24, riders_count, events_count, ranking_position, previous_position, position_change)
                VALUES " . implode(', ', $values) . "
                ON DUPLICATE KEY UPDATE
                    total_ranking_points = VALUES(total_ranking_points),
                    points_last_12_months = VALUES(points_last_12_months),
                    points_months_13_24 = VALUES(points_months_13_24),
                    riders_count = VALUES(riders_count),
                    events_count = VALUES(events_count),
                    ranking_position = VALUES(ranking_position),
                    previous_position = VALUES(previous_position),
                    position_change = VALUES(position_change)";

        $db->query($sql, $params);

        $inserted += count($batch);

        if ($debug) {
            echo "<p>✓ Club batch " . ($batchIndex + 1) . " saved (" . $inserted . " / " . count($clubData) . " total)</p>";
            flush();
        }

        // Small pause between batches
        usleep(50000); // 50ms
    }

    if ($debug) {
        echo "<p>✅ Saved all " . $inserted . " clubs!</p>";
        flush();
    }

    return $inserted;
}

/**
 * Run full ranking update for all disciplines
 * Creates snapshots for riders and clubs
 *
 * @param object $db Database connection
 * @param bool $debug Enable debug output
 * @return array Statistics about the update
 */
function runFullRankingUpdate($db, $debug = false) {
    $stats = [
        'enduro' => ['riders' => 0, 'clubs' => 0],
        'dh' => ['riders' => 0, 'clubs' => 0],
        'gravity' => ['riders' => 0, 'clubs' => 0],
        'total_time' => 0
    ];

    $startTime = microtime(true);

    if ($debug) {
        echo "<p style='background: #e3f2fd; padding: 10px; border-left: 4px solid #2196f3;'>";
        echo "<strong>🔄 Version: 2025-11-25-006</strong><br>";
        echo "Lightweight Ranking System - Debug Mode Active";
        echo "</p>";
        echo "<h3>Creating Ranking Snapshots</h3>";
        flush();
    }

    // Create rider snapshots for each discipline
    foreach (['ENDURO', 'DH', 'GRAVITY'] as $discipline) {
        if ($debug) {
            echo "<p>📊 Processing {$discipline} riders...</p>";
            flush();
        }

        $count = createRankingSnapshot($db, $discipline, null, $debug);
        $stats[strtolower($discipline)]['riders'] = $count;

        if ($debug) {
            echo "<p>✅ {$count} riders ranked</p>";
            flush();
        }
    }

    // Create club snapshots for each discipline
    foreach (['ENDURO', 'DH', 'GRAVITY'] as $discipline) {
        if ($debug) {
            echo "<p>🏛️ Processing {$discipline} clubs...</p>";
            flush();
        }

        $count = createClubRankingSnapshot($db, $discipline, null, $debug);
        $stats[strtolower($discipline)]['clubs'] = $count;

        if ($debug) {
            echo "<p>✅ {$count} clubs ranked</p>";
            flush();
        }
    }

    // Update last calculation timestamp
    $db->query("
        INSERT INTO ranking_settings (setting_key, setting_value, description)
        VALUES ('last_calculation', ?, 'Timestamp of last ranking calculation')
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()
    ", [json_encode([
        'date' => date('Y-m-d H:i:s'),
        'stats' => $stats
    ])]);

    $stats['total_time'] = round(microtime(true) - $startTime, 2);

    if ($debug) {
        echo "<p>✅ All snapshots created in {$stats['total_time']}s</p>";
        flush();
    }

    return $stats;
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
        // Fall back to live calculation
        $clubData = calculateClubRanking($db, $discipline);
        $total = count($clubData);
        $clubs = array_slice($clubData, $offset, $limit);

        return [
            'clubs' => $clubs,
            'total' => $total,
            'snapshot_date' => null,
            'discipline' => $discipline,
            'source' => 'live'
        ];
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
        'discipline' => $discipline,
        'source' => 'snapshot'
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
 * Returns live calculation of current ranking data
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

    // Get current ranking from snapshot if available
    $latestSnapshot = $db->getRow("
        SELECT MAX(snapshot_date) as snapshot_date FROM ranking_snapshots WHERE discipline = ?
    ", [$discipline]);

    $ranking = null;
    if ($latestSnapshot && $latestSnapshot['snapshot_date']) {
        $ranking = $db->getRow("
            SELECT * FROM ranking_snapshots
            WHERE rider_id = ? AND discipline = ? AND snapshot_date = ?
        ", [$riderId, $discipline, $latestSnapshot['snapshot_date']]);
    }

    // Get event breakdown - calculate from results
    $cutoffDate = date('Y-m-d', strtotime('-24 months'));
    $disciplineFilter = $discipline === 'GRAVITY' ? "AND e.discipline IN ('ENDURO', 'DH')" : "AND e.discipline = ?";
    $params = [$riderId, $cutoffDate];
    if ($discipline !== 'GRAVITY') {
        $params[] = $discipline;
    }

    $events = $db->getAll("
        SELECT
            r.event_id,
            r.class_id,
            e.discipline,
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
            e.location,
            e.event_level,
            cl.name as class_name,
            cl.display_name as class_display_name
        FROM results r
        JOIN events e ON r.event_id = e.id
        JOIN classes cl ON r.class_id = cl.id
        WHERE r.cyclist_id = ?
        AND e.date >= ?
        {$disciplineFilter}
        AND r.status = 'finished'
        AND (r.points > 0 OR COALESCE(r.run_1_points, 0) > 0 OR COALESCE(r.run_2_points, 0) > 0)
        ORDER BY e.date DESC
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

    return ['date' => null, 'stats' => []];
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

    // Get event counts from live data
    $cutoffDate = date('Y-m-d', strtotime('-24 months'));

    $enduroEvents = $db->getRow("
        SELECT COUNT(DISTINCT e.id) as count
        FROM events e
        WHERE e.discipline = 'ENDURO' AND e.date >= ?
    ", [$cutoffDate]);

    $dhEvents = $db->getRow("
        SELECT COUNT(DISTINCT e.id) as count
        FROM events e
        WHERE e.discipline = 'DH' AND e.date >= ?
    ", [$cutoffDate]);

    $stats['ENDURO']['events'] = $enduroEvents ? $enduroEvents['count'] : 0;
    $stats['DH']['events'] = $dhEvents ? $dhEvents['count'] : 0;
    $stats['GRAVITY']['events'] = $stats['ENDURO']['events'] + $stats['DH']['events'];

    return $stats;
}

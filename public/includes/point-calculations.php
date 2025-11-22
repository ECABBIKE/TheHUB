<?php
/**
 * Point Calculation Helper Functions
 * Auto-calculate points based on position and event's point scale
 */

/**
 * Calculate points for a DH result (two runs)
 *
 * For standard DH: Only fastest run gets points (position based on fastest time)
 * For SweCUP DH: Both runs get points separately (run_1_points + run_2_points)
 *
 * @param object $db Database instance
 * @param int $event_id Event ID
 * @param int $position Rider's position (based on fastest run)
 * @param int $run1_position Position in run 1
 * @param int $run2_position Position in run 2
 * @param string $status Result status (finished, dnf, dns, dq)
 * @param bool $use_swecup_dh Use SweCUP DH format (both runs award points)
 * @return array ['run_1_points' => int, 'run_2_points' => int, 'total_points' => int]
 */
function calculateDHPoints($db, $event_id, $position, $run1_position, $run2_position, $status = 'finished', $use_swecup_dh = false) {
    // No points for DNF, DNS, or DQ
    if (in_array($status, ['dnf', 'dns', 'dq'])) {
        return [
            'run_1_points' => 0,
            'run_2_points' => 0,
            'total_points' => 0
        ];
    }

    // Get event's point scale
    $event = $db->getRow(
        "SELECT point_scale_id FROM events WHERE id = ?",
        [$event_id]
    );

    if (!$event || !$event['point_scale_id']) {
        // No scale assigned - use default
        $scale = $db->getRow(
            "SELECT id FROM point_scales WHERE is_default = 1 LIMIT 1"
        );

        if (!$scale) {
            error_log("⚠️  No point scale found for event {$event_id}");
            return ['run_1_points' => 0, 'run_2_points' => 0, 'total_points' => 0];
        }

        $scale_id = $scale['id'];
    } else {
        $scale_id = $event['point_scale_id'];
    }

    if ($use_swecup_dh) {
        // SweCUP DH: Both runs award points from separate columns
        $run1_points = 0;
        $run2_points = 0;

        if ($run1_position && $run1_position >= 1) {
            $value = $db->getRow(
                "SELECT run_1_points FROM point_scale_values WHERE scale_id = ? AND position = ?",
                [$scale_id, $run1_position]
            );
            $run1_points = $value ? (float)$value['run_1_points'] : 0;
        }

        if ($run2_position && $run2_position >= 1) {
            $value = $db->getRow(
                "SELECT run_2_points FROM point_scale_values WHERE scale_id = ? AND position = ?",
                [$scale_id, $run2_position]
            );
            $run2_points = $value ? (float)$value['run_2_points'] : 0;
        }

        $total_points = $run1_points + $run2_points;

        error_log("✅ DH SweCUP Points: Run 1 (P{$run1_position}) = {$run1_points}, Run 2 (P{$run2_position}) = {$run2_points}, Total = {$total_points}");

        return [
            'run_1_points' => $run1_points,
            'run_2_points' => $run2_points,
            'total_points' => $total_points
        ];
    } else {
        // Standard DH: Only fastest run counts (position based on best time)
        $points = calculatePoints($db, $event_id, $position, $status);

        error_log("✅ DH Standard Points: Position {$position} = {$points} points");

        return [
            'run_1_points' => 0,
            'run_2_points' => 0,
            'total_points' => $points
        ];
    }
}

/**
 * Calculate points for a result
 *
 * @param object $db Database instance
 * @param int $event_id Event ID
 * @param int $position Rider's position (1-based)
 * @param string $status Result status (finished, dnf, dns, dq)
 * @return float Points earned
 */
function calculatePoints($db, $event_id, $position, $status = 'finished') {
    // No points for DNF, DNS, or DQ
    if (in_array($status, ['dnf', 'dns', 'dq'])) {
        return 0;
    }

    // No points if no position
    if (!$position || $position < 1) {
        return 0;
    }

    // Get event's point scale
    $event = $db->getRow(
        "SELECT point_scale_id FROM events WHERE id = ?",
        [$event_id]
    );

    if (!$event || !$event['point_scale_id']) {
        // No scale assigned - use default
        $scale = $db->getRow(
            "SELECT id FROM point_scales WHERE is_default = 1 LIMIT 1"
        );

        if (!$scale) {
            error_log("⚠️  No point scale found for event {$event_id}");
            return 0;
        }

        $scale_id = $scale['id'];
    } else {
        $scale_id = $event['point_scale_id'];
    }

    // Get points for this position
    $value = $db->getRow(
        "SELECT points FROM point_scale_values WHERE scale_id = ? AND position = ?",
        [$scale_id, $position]
    );

    if (!$value) {
        // Position not in scale - try to find last position
        $last = $db->getRow(
            "SELECT points FROM point_scale_values WHERE scale_id = ? ORDER BY position DESC LIMIT 1",
            [$scale_id]
        );

        if ($last) {
            error_log("⚠️  Position {$position} not in scale, using last value: {$last['points']}");
            return (float)$last['points'];
        }

        error_log("⚠️  No points found for position {$position} in scale {$scale_id}");
        return 0;
    }

    error_log("✅ Points calculated: Position {$position} = {$value['points']} points");
    return (float)$value['points'];
}

/**
 * Get all point scales
 *
 * @param object $db Database instance
 * @param string $discipline Filter by discipline (optional)
 * @param bool $activeOnly Only active scales
 * @return array Point scales
 */
function getPointScales($db, $discipline = null, $activeOnly = true) {
    $where = [];
    $params = [];

    if ($activeOnly) {
        $where[] = "active = 1";
    }

    if ($discipline && $discipline !== 'ALL') {
        $where[] = "(discipline = ? OR discipline = 'ALL')";
        $params[] = $discipline;
    }

    $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $sql = "SELECT id, name, description, discipline, is_default,
                   (SELECT COUNT(*) FROM point_scale_values WHERE scale_id = point_scales.id) as value_count
            FROM point_scales
            {$whereClause}
            ORDER BY is_default DESC, name ASC";

    return $db->getAll($sql, $params);
}

/**
 * Get point scale values
 *
 * @param object $db Database instance
 * @param int $scale_id Scale ID
 * @return array Scale values sorted by position
 */
function getScaleValues($db, $scale_id) {
    return $db->getAll(
        "SELECT position, points FROM point_scale_values WHERE scale_id = ? ORDER BY position ASC",
        [$scale_id]
    );
}

/**
 * Get rider's total points in a series
 *
 * @param object $db Database instance
 * @param int $rider_id Rider ID
 * @param int $series_id Series ID
 * @param int $category_id Category ID (optional)
 * @param bool $apply_best_results Apply series count_best_results rule (default true)
 * @return array Stats including total_points, events, wins, podiums
 */
function getRiderSeriesPoints($db, $rider_id, $series_id, $category_id = null, $apply_best_results = true) {
    $where = ["e.series_id = ?", "r.cyclist_id = ?"];
    $params = [$series_id, $rider_id];

    if ($category_id) {
        $where[] = "r.category_id = ?";
        $params[] = $category_id;
    }

    $whereClause = implode(' AND ', $where);

    // Get series settings
    $series = $db->getRow("SELECT count_best_results FROM series WHERE id = ?", [$series_id]);
    $countBestResults = $series['count_best_results'] ?? null;

    // Get all results with DH points support
    // For DH events (SweCUP format): points = run_1_points + run_2_points
    // For standard events or standard DH: points = points column
    $sql = "SELECT
                r.id,
                r.event_id,
                COALESCE(
                    CASE
                        WHEN r.run_1_points > 0 OR r.run_2_points > 0
                        THEN r.run_1_points + r.run_2_points
                        ELSE r.points
                    END,
                    r.points
                ) as points,
                r.position,
                e.name as event_name,
                e.date as event_date
            FROM results r
            JOIN events e ON r.event_id = e.id
            WHERE {$whereClause}
            ORDER BY points DESC, r.position ASC";

    $results = $db->getAll($sql, $params);

    // Apply best results rule if configured
    if ($apply_best_results && $countBestResults && count($results) > $countBestResults) {
        $results = array_slice($results, 0, $countBestResults);
    }

    // Calculate stats from selected results
    $total_points = 0;
    $wins = 0;
    $podiums = 0;
    $best_position = null;
    $events_count = count($results);

    foreach ($results as $result) {
        $total_points += (float)$result['points'];

        if ($result['position'] == 1) $wins++;
        if ($result['position'] <= 3) $podiums++;

        if ($best_position === null || $result['position'] < $best_position) {
            $best_position = $result['position'];
        }
    }

    return [
        'events_count' => $events_count,
        'total_points' => $total_points,
        'wins' => $wins,
        'podiums' => $podiums,
        'best_position' => $best_position,
        'count_best_results' => $countBestResults,
        'counted_results' => $events_count
    ];
}

/**
 * Get series standings
 *
 * @param object $db Database instance
 * @param int $series_id Series ID
 * @param int $category_id Category ID (optional)
 * @param int $limit Limit results (default 50)
 * @return array Riders sorted by points
 */
function getSeriesStandings($db, $series_id, $category_id = null, $limit = 50) {
    $where = ["e.series_id = ?"];
    $params = [$series_id];

    if ($category_id) {
        $where[] = "r.category_id = ?";
        $params[] = $category_id;
    }

    $whereClause = implode(' AND ', $where);

    // Sum points including DH runs
    // For DH SweCUP events: run_1_points + run_2_points
    // For standard events or standard DH: points column
    $sql = "SELECT
                c.id as rider_id,
                c.firstname,
                c.lastname,
                c.birth_year,
                c.gender,
                cl.name as club_name,
                COUNT(DISTINCT r.event_id) as events_count,
                SUM(
                    COALESCE(
                        CASE
                            WHEN r.run_1_points > 0 OR r.run_2_points > 0
                            THEN r.run_1_points + r.run_2_points
                            ELSE r.points
                        END,
                        r.points
                    )
                ) as total_points,
                COUNT(CASE WHEN r.position = 1 THEN 1 END) as wins,
                COUNT(CASE WHEN r.position <= 3 THEN 1 END) as podiums,
                MIN(r.position) as best_position
            FROM results r
            JOIN events e ON r.event_id = e.id
            JOIN riders c ON r.cyclist_id = c.id
            LEFT JOIN clubs cl ON c.club_id = cl.id
            WHERE {$whereClause}
            GROUP BY c.id
            ORDER BY total_points DESC, wins DESC, podiums DESC
            LIMIT ?";

    $params[] = $limit;

    return $db->getAll($sql, $params);
}

/**
 * Recalculate all points for an event
 * Useful when changing point scale
 *
 * @param object $db Database instance
 * @param int $event_id Event ID
 * @return array Stats (updated, failed)
 */
function recalculateEventPoints($db, $event_id) {
    $results = $db->getAll(
        "SELECT id, position, status FROM results WHERE event_id = ?",
        [$event_id]
    );

    $stats = ['updated' => 0, 'failed' => 0];

    foreach ($results as $result) {
        $points = calculatePoints($db, $event_id, $result['position'], $result['status']);

        try {
            $db->update('results', ['points' => $points], 'id = ?', [$result['id']]);
            $stats['updated']++;
        } catch (Exception $e) {
            error_log("Failed to update points for result {$result['id']}: " . $e->getMessage());
            $stats['failed']++;
        }
    }

    error_log("✅ Recalculated points for event {$event_id}: {$stats['updated']} updated, {$stats['failed']} failed");

    return $stats;
}

/**
 * Get scale preview (first 20 positions)
 *
 * @param object $db Database instance
 * @param int $scale_id Scale ID
 * @return array Preview data
 */
function getScalePreview($db, $scale_id) {
    return $db->getAll(
        "SELECT position, points FROM point_scale_values WHERE scale_id = ? ORDER BY position ASC LIMIT 20",
        [$scale_id]
    );
}

/**
 * Recalculate positions and points for a DH event
 * Recalculates positions for each run separately, then overall position based on fastest time
 *
 * @param object $db Database instance
 * @param int $event_id Event ID
 * @param int $new_scale_id Optional: Change to new point scale
 * @param bool $use_swecup_dh Use SweCUP DH format (both runs award points)
 * @return array Stats (positions_updated, points_updated, errors)
 */
function recalculateDHEventResults($db, $event_id, $new_scale_id = null, $use_swecup_dh = false) {
    $stats = [
        'positions_updated' => 0,
        'points_updated' => 0,
        'errors' => []
    ];

    try {
        // Update point scale if provided
        if ($new_scale_id) {
            $db->update('events', ['point_scale_id' => $new_scale_id], 'id = ?', [$event_id]);
            error_log("Updated point scale for event {$event_id} to scale {$new_scale_id}");
        }

        // Get all results grouped by category/class
        $results = $db->getAll("
            SELECT id, category_id, class_id, run_1_time, run_2_time, status
            FROM results
            WHERE event_id = ?
            ORDER BY category_id, class_id
        ", [$event_id]);

        // Group by category and class
        $byGroup = [];
        foreach ($results as $result) {
            $catId = $result['category_id'] ?? 0;
            $classId = $result['class_id'] ?? 0;
            $groupKey = "{$catId}_{$classId}";

            if (!isset($byGroup[$groupKey])) {
                $byGroup[$groupKey] = [];
            }
            $byGroup[$groupKey][] = $result;
        }

        // Recalculate positions for each group
        foreach ($byGroup as $groupKey => $groupResults) {
            // Sort by run 1 time for run 1 positions
            $run1Results = $groupResults;
            usort($run1Results, function($a, $b) {
                if ($a['status'] !== 'finished' || empty($a['run_1_time'])) return 1;
                if ($b['status'] !== 'finished' || empty($b['run_1_time'])) return -1;
                return strcmp($a['run_1_time'], $b['run_1_time']);
            });

            // Assign run 1 positions
            $run1Positions = [];
            $pos = 1;
            foreach ($run1Results as $result) {
                if ($result['status'] === 'finished' && !empty($result['run_1_time'])) {
                    $run1Positions[$result['id']] = $pos++;
                } else {
                    $run1Positions[$result['id']] = null;
                }
            }

            // Sort by run 2 time for run 2 positions
            $run2Results = $groupResults;
            usort($run2Results, function($a, $b) {
                if ($a['status'] !== 'finished' || empty($a['run_2_time'])) return 1;
                if ($b['status'] !== 'finished' || empty($b['run_2_time'])) return -1;
                return strcmp($a['run_2_time'], $b['run_2_time']);
            });

            // Assign run 2 positions
            $run2Positions = [];
            $pos = 1;
            foreach ($run2Results as $result) {
                if ($result['status'] === 'finished' && !empty($result['run_2_time'])) {
                    $run2Positions[$result['id']] = $pos++;
                } else {
                    $run2Positions[$result['id']] = null;
                }
            }

            // Sort by fastest time (best of two runs) for overall position
            $overallResults = $groupResults;
            usort($overallResults, function($a, $b) {
                if ($a['status'] !== 'finished') return 1;
                if ($b['status'] !== 'finished') return -1;

                // Get fastest time for each rider
                $aFastest = null;
                if (!empty($a['run_1_time'])) $aFastest = $a['run_1_time'];
                if (!empty($a['run_2_time']) && (!$aFastest || $a['run_2_time'] < $aFastest)) {
                    $aFastest = $a['run_2_time'];
                }

                $bFastest = null;
                if (!empty($b['run_1_time'])) $bFastest = $b['run_1_time'];
                if (!empty($b['run_2_time']) && (!$bFastest || $b['run_2_time'] < $bFastest)) {
                    $bFastest = $b['run_2_time'];
                }

                if (!$aFastest) return 1;
                if (!$bFastest) return -1;

                return strcmp($aFastest, $bFastest);
            });

            // Assign overall positions and calculate points
            $pos = 1;
            foreach ($overallResults as $result) {
                $overallPosition = null;
                if ($result['status'] === 'finished' && (!empty($result['run_1_time']) || !empty($result['run_2_time']))) {
                    $overallPosition = $pos++;
                }

                // Calculate DH points
                $run1Pos = $run1Positions[$result['id']];
                $run2Pos = $run2Positions[$result['id']];

                $pointsData = calculateDHPoints(
                    $db,
                    $event_id,
                    $overallPosition,
                    $run1Pos,
                    $run2Pos,
                    $result['status'],
                    $use_swecup_dh
                );

                // Update result with positions and points
                try {
                    $db->update('results', [
                        'position' => $overallPosition,
                        'points' => $pointsData['total_points'],
                        'run_1_points' => $pointsData['run_1_points'],
                        'run_2_points' => $pointsData['run_2_points']
                    ], 'id = ?', [$result['id']]);

                    $stats['positions_updated']++;
                    $stats['points_updated']++;
                } catch (Exception $e) {
                    $stats['errors'][] = "Result {$result['id']}: " . $e->getMessage();
                    error_log("Failed to update DH result {$result['id']}: " . $e->getMessage());
                }
            }
        }

        error_log("✅ Recalculated DH event {$event_id}: {$stats['positions_updated']} positions, {$stats['points_updated']} points");

    } catch (Exception $e) {
        $stats['errors'][] = "Fatal error: " . $e->getMessage();
        error_log("Failed to recalculate DH event {$event_id}: " . $e->getMessage());
    }

    return $stats;
}

/**
 * Recalculate positions and points for an event
 * Recalculates positions within each category based on finish time,
 * then recalculates points based on new positions
 *
 * @param object $db Database instance
 * @param int $event_id Event ID
 * @param int $new_scale_id Optional: Change to new point scale
 * @return array Stats (positions_updated, points_updated, errors)
 */
function recalculateEventResults($db, $event_id, $new_scale_id = null) {
    $stats = [
        'positions_updated' => 0,
        'points_updated' => 0,
        'classes_fixed' => 0,
        'errors' => []
    ];

    try {
        // Update point scale if provided
        if ($new_scale_id) {
            $db->update('events', ['point_scale_id' => $new_scale_id], 'id = ?', [$event_id]);
            error_log("Updated point scale for event {$event_id} to scale {$new_scale_id}");
        }

        // NOTE: We no longer auto-fix class_id here as it was causing data corruption
        // Class assignments should be preserved from import
        // If you need to reassign classes, use assignClassesToEvent() with correct discipline

        // Get all results grouped by class_id
        $results = $db->getAll("
            SELECT id, class_id, finish_time, status
            FROM results
            WHERE event_id = ?
            ORDER BY class_id,
                     CASE WHEN status = 'finished' THEN 0 ELSE 1 END,
                     finish_time ASC
        ", [$event_id]);

        // Group by class_id
        $byClass = [];
        foreach ($results as $result) {
            $classId = $result['class_id'] ?? 0;
            if (!isset($byClass[$classId])) {
                $byClass[$classId] = [];
            }
            $byClass[$classId][] = $result;
        }

        // STEP 3: Recalculate positions within each class
        foreach ($byClass as $classId => $classResults) {
            $position = 1;

            foreach ($classResults as $result) {
                $newPosition = null;

                if ($result['status'] === 'finished' && !empty($result['finish_time'])) {
                    $newPosition = $position;
                    $position++;
                }

                // Calculate points based on new position
                $newPoints = calculatePoints($db, $event_id, $newPosition, $result['status']);

                // Update result
                try {
                    $db->update('results', [
                        'position' => $newPosition,
                        'points' => $newPoints
                    ], 'id = ?', [$result['id']]);

                    $stats['positions_updated']++;
                    $stats['points_updated']++;
                } catch (Exception $e) {
                    $stats['errors'][] = "Result {$result['id']}: " . $e->getMessage();
                    error_log("Failed to update result {$result['id']}: " . $e->getMessage());
                }
            }
        }

        error_log("✅ Recalculated event {$event_id}: {$stats['classes_fixed']} classes fixed, {$stats['positions_updated']} positions, {$stats['points_updated']} points");

    } catch (Exception $e) {
        $stats['errors'][] = "Fatal error: " . $e->getMessage();
        error_log("Failed to recalculate event {$event_id}: " . $e->getMessage());
    }

    return $stats;
}

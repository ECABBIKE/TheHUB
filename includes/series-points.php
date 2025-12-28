<?php
/**
 * Series Points Calculation System
 *
 * IMPORTANT: Series points are stored SEPARATELY from ranking points!
 *
 * - Series points: Stored in series_results table
 *   - Calculated using series_events.template_id -> point_scales -> point_scale_values
 *   - Each series can have different templates per event
 *   - Managed via /admin/series-events.php
 *   - ONLY recalculated when you change template in series-events.php
 *
 * - Ranking points: Stored in results.points
 *   - Uses the same point_scales table
 *   - But stored in different place (results.points vs series_results.points)
 *
 * Both use the same templates (point_scales), but store results separately!
 */

/**
 * Calculate series points for a single result
 *
 * @param object $db Database instance
 * @param int $templateId The point_scales.id to use
 * @param int $position The rider's position (1-based)
 * @param string $status Result status (finished, dnf, dns, dq)
 * @param string $eventLevel Event level (national or sportmotion) - for 50% multiplier
 * @return int Points earned (0 if no points for this position)
 */
function calculateSeriesPointsForPosition($db, $templateId, $position, $status = 'finished', $eventLevel = 'national') {
    // No points for DNF, DNS, or DQ
    if (in_array($status, ['dnf', 'dns', 'dq'])) {
        return 0;
    }

    // No points if no position
    if (!$position || $position < 1) {
        return 0;
    }

    // No template = no points
    if (!$templateId) {
        error_log("Series points: No template_id provided");
        return 0;
    }

    // Get points from point_scale_values table
    // For DH scales, points may be in run_1_points + run_2_points instead of points column
    $pointValue = $db->getRow(
        "SELECT psv.points, psv.run_1_points, psv.run_2_points
         FROM point_scale_values psv
         JOIN point_scales ps ON psv.scale_id = ps.id
         WHERE ps.id = ? AND ps.active = 1 AND psv.position = ?",
        [$templateId, $position]
    );

    if ($pointValue) {
        // Check for DH scale format: run_1_points + run_2_points
        $run1 = (float)($pointValue['run_1_points'] ?? 0);
        $run2 = (float)($pointValue['run_2_points'] ?? 0);

        if ($run1 > 0 || $run2 > 0) {
            // DH scale - use combined run points
            $points = (int)($run1 + $run2);
        } else {
            // Standard scale - use points column
            $points = (int)$pointValue['points'];
        }

        // NOTE: NO event_level multiplier for series points!
        // event_level (sportmotion/national) only affects RANKING points, not series.
        // Series points are determined solely by the template_id in series_events.
        return $points;
    }

    // Log when no points found for debugging
    if ($position <= 10) {
        error_log("Series points: No points found for template_id={$templateId}, position={$position}");
    }

    // Position not in template = 0 points
    return 0;
}

/**
 * Recalculate all series_results for a specific event in a series
 *
 * @param object $db Database instance
 * @param int $seriesId Series ID
 * @param int $eventId Event ID
 * @return array Stats: ['inserted' => X, 'updated' => X, 'deleted' => X]
 */
function recalculateSeriesEventPoints($db, $seriesId, $eventId) {
    $stats = ['inserted' => 0, 'updated' => 0, 'deleted' => 0];

    // Get the template for this event in this series
    $seriesEvent = $db->getRow(
        "SELECT template_id FROM series_events WHERE series_id = ? AND event_id = ?",
        [$seriesId, $eventId]
    );

    if (!$seriesEvent) {
        error_log("Series points: Event {$eventId} not found in series {$seriesId}");
        return $stats;
    }

    $templateId = $seriesEvent['template_id'];

    // Get event_level for sportmotion multiplier
    $event = $db->getRow(
        "SELECT COALESCE(event_level, 'national') as event_level FROM events WHERE id = ?",
        [$eventId]
    );
    $eventLevel = $event ? $event['event_level'] : 'national';

    // Get all results for this event - ONLY classes that award points AND are series eligible
    $results = $db->getAll("
        SELECT r.id, r.cyclist_id, r.class_id, r.position, r.status
        FROM results r
        INNER JOIN classes cl ON r.class_id = cl.id
        WHERE r.event_id = ?
          AND COALESCE(cl.awards_points, 1) = 1
          AND COALESCE(cl.series_eligible, 1) = 1
    ", [$eventId]);

    // PERFORMANCE FIX: Pre-fetch ALL existing series_results for this event/series
    // This eliminates N+1 query problem (was doing 1 query per result)
    $existingResults = $db->getAll("
        SELECT id, cyclist_id, class_id, points
        FROM series_results
        WHERE series_id = ? AND event_id = ?
    ", [$seriesId, $eventId]);

    // Build lookup map: "cyclist_id|class_id" => ['id' => ..., 'points' => ...]
    $existingMap = [];
    foreach ($existingResults as $er) {
        $key = $er['cyclist_id'] . '|' . ($er['class_id'] ?? 'null');
        $existingMap[$key] = ['id' => $er['id'], 'points' => $er['points']];
    }

    foreach ($results as $result) {
        // Calculate points using series template with event_level multiplier
        $points = calculateSeriesPointsForPosition(
            $db,
            $templateId,
            $result['position'],
            $result['status'],
            $eventLevel
        );

        // Check if series_result already exists using lookup map (no DB query!)
        $lookupKey = $result['cyclist_id'] . '|' . ($result['class_id'] ?? 'null');
        $existing = $existingMap[$lookupKey] ?? null;

        if ($existing) {
            // Update if points changed
            if ($existing['points'] != $points) {
                $db->update('series_results', [
                    'position' => $result['position'],
                    'status' => $result['status'],
                    'points' => $points,
                    'template_id' => $templateId,
                    'calculated_at' => date('Y-m-d H:i:s')
                ], 'id = ?', [$existing['id']]);
                $stats['updated']++;
            }
        } else {
            // Insert new series_result
            $db->insert('series_results', [
                'series_id' => $seriesId,
                'event_id' => $eventId,
                'cyclist_id' => $result['cyclist_id'],
                'class_id' => $result['class_id'],
                'position' => $result['position'],
                'status' => $result['status'],
                'points' => $points,
                'template_id' => $templateId
            ]);
            $stats['inserted']++;
        }
    }

    // Delete series_results that no longer have a matching result
    $deleted = $db->query("
        DELETE sr FROM series_results sr
        LEFT JOIN results r ON r.event_id = sr.event_id
            AND r.cyclist_id = sr.cyclist_id
            AND r.class_id <=> sr.class_id
        WHERE sr.series_id = ? AND sr.event_id = ? AND r.id IS NULL
    ", [$seriesId, $eventId]);

    $stats['deleted'] = $deleted ? $deleted->rowCount() : 0;

    return $stats;
}

/**
 * Recalculate all series_results for an entire series
 *
 * @param object $db Database instance
 * @param int $seriesId Series ID
 * @return array Stats: ['events' => X, 'inserted' => X, 'updated' => X, 'deleted' => X]
 */
function recalculateAllSeriesPoints($db, $seriesId) {
    $totalStats = ['events' => 0, 'inserted' => 0, 'updated' => 0, 'deleted' => 0];

    // Get all events in this series
    $events = $db->getAll(
        "SELECT event_id FROM series_events WHERE series_id = ?",
        [$seriesId]
    );

    foreach ($events as $event) {
        $stats = recalculateSeriesEventPoints($db, $seriesId, $event['event_id']);
        $totalStats['events']++;
        $totalStats['inserted'] += $stats['inserted'];
        $totalStats['updated'] += $stats['updated'];
        $totalStats['deleted'] += $stats['deleted'];
    }

    return $totalStats;
}

/**
 * Get series standings using series_results table
 * NOTE: Wrapped in function_exists to prevent conflict with point-calculations.php
 *
 * @param object $db Database instance
 * @param int $seriesId Series ID
 * @param int|null $classId Optional class filter
 * @param int|null $countBest Optional: only count X best results
 * @return array Standings array sorted by total points
 */
if (!function_exists('getSeriesStandings')):
function getSeriesStandings($db, $seriesId, $classId = null, $countBest = null) {
    // Build WHERE clause
    $where = "sr.series_id = ?";
    $params = [$seriesId];

    if ($classId) {
        $where .= " AND sr.class_id = ?";
        $params[] = $classId;
    }

    // Get all series results grouped by rider
    $results = $db->getAll("
        SELECT
            sr.cyclist_id,
            sr.class_id,
            sr.event_id,
            sr.points,
            r.first_name,
            r.last_name,
            r.club,
            c.name AS class_name,
            c.display_name AS class_display_name,
            c.sort_order AS class_sort_order
        FROM series_results sr
        JOIN riders r ON r.id = sr.cyclist_id
        LEFT JOIN classes c ON c.id = sr.class_id
        WHERE {$where}
        ORDER BY sr.cyclist_id, sr.points DESC
    ", $params);

    // Group by rider and calculate totals
    $standings = [];
    foreach ($results as $row) {
        $riderId = $row['cyclist_id'];
        $classKey = $row['class_id'] ?? 0;

        if (!isset($standings[$classKey])) {
            $standings[$classKey] = [
                'class_id' => $row['class_id'],
                'class_name' => $row['class_name'] ?? 'Oklassificerad',
                'class_display_name' => $row['class_display_name'] ?? 'Oklassificerad',
                'class_sort_order' => $row['class_sort_order'] ?? 999,
                'riders' => []
            ];
        }

        if (!isset($standings[$classKey]['riders'][$riderId])) {
            $standings[$classKey]['riders'][$riderId] = [
                'id' => $riderId,
                'first_name' => $row['first_name'],
                'last_name' => $row['last_name'],
                'fullname' => trim($row['first_name'] . ' ' . $row['last_name']),
                'club' => $row['club'],
                'class_id' => $row['class_id'],
                'class_name' => $row['class_name'],
                'event_points' => [],
                'all_points' => [],
                'total_points' => 0
            ];
        }

        $standings[$classKey]['riders'][$riderId]['event_points'][$row['event_id']] = $row['points'];
        if ($row['points'] > 0) {
            $standings[$classKey]['riders'][$riderId]['all_points'][] = $row['points'];
        }
    }

    // Calculate total points (with count_best if specified)
    foreach ($standings as $classKey => &$classData) {
        foreach ($classData['riders'] as $riderId => &$riderData) {
            $allPoints = $riderData['all_points'];

            if ($countBest && count($allPoints) > $countBest) {
                // Sort descending and take best X
                rsort($allPoints);
                $allPoints = array_slice($allPoints, 0, $countBest);
            }

            $riderData['total_points'] = array_sum($allPoints);
            $riderData['counted_results'] = count($allPoints);
            unset($riderData['all_points']); // Clean up
        }

        // Sort riders by total points
        uasort($classData['riders'], function($a, $b) {
            return $b['total_points'] - $a['total_points'];
        });

        // Convert to indexed array and add positions
        $classData['riders'] = array_values($classData['riders']);
        $position = 1;
        foreach ($classData['riders'] as &$rider) {
            $rider['position'] = $position++;
        }
    }

    // Sort classes by sort_order
    uasort($standings, function($a, $b) {
        return $a['class_sort_order'] - $b['class_sort_order'];
    });

    return $standings;
}
endif; // function_exists('getSeriesStandings')

/**
 * Sync results to series_results when new results are imported
 * Call this after importing results for an event
 *
 * @param object $db Database instance
 * @param int $eventId Event ID
 * @return array Stats per series
 */
function syncEventResultsToAllSeries($db, $eventId) {
    $allStats = [];

    // Find all series this event belongs to
    $seriesEvents = $db->getAll(
        "SELECT series_id FROM series_events WHERE event_id = ?",
        [$eventId]
    );

    foreach ($seriesEvents as $se) {
        $stats = recalculateSeriesEventPoints($db, $se['series_id'], $eventId);
        $allStats[$se['series_id']] = $stats;
    }

    return $allStats;
}

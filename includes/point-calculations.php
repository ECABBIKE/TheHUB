<?php
/**
 * Point Calculation Helper Functions
 * Auto-calculate points based on position and event's point scale
 */

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
 * @return array Stats including total_points, events, wins, podiums
 */
function getRiderSeriesPoints($db, $rider_id, $series_id, $category_id = null) {
    $where = ["e.series_id = ?", "r.cyclist_id = ?"];
    $params = [$series_id, $rider_id];

    if ($category_id) {
        $where[] = "r.category_id = ?";
        $params[] = $category_id;
    }

    $whereClause = implode(' AND ', $where);

    $sql = "SELECT
                COUNT(DISTINCT r.event_id) as events_count,
                SUM(r.points) as total_points,
                COUNT(CASE WHEN r.position = 1 THEN 1 END) as wins,
                COUNT(CASE WHEN r.position <= 3 THEN 1 END) as podiums,
                MIN(r.position) as best_position
            FROM results r
            JOIN events e ON r.event_id = e.id
            WHERE {$whereClause}";

    $stats = $db->getRow($sql, $params);

    return [
        'events_count' => (int)($stats['events_count'] ?? 0),
        'total_points' => (float)($stats['total_points'] ?? 0),
        'wins' => (int)($stats['wins'] ?? 0),
        'podiums' => (int)($stats['podiums'] ?? 0),
        'best_position' => $stats['best_position'] ? (int)$stats['best_position'] : null
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

    $sql = "SELECT
                c.id as rider_id,
                c.firstname,
                c.lastname,
                c.birth_year,
                c.gender,
                cl.name as club_name,
                COUNT(DISTINCT r.event_id) as events_count,
                SUM(r.points) as total_points,
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

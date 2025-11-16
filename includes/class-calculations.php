<?php
/**
 * Class Calculation Helper Functions
 * Auto-assign riders to classes (M17, K40, etc.) based on age and gender
 */

/**
 * Calculate rider's age at event date
 *
 * @param int $birthYear Rider's birth year
 * @param string $eventDate Event date (YYYY-MM-DD)
 * @return int Age at event
 */
function calculateAgeAtEvent($birthYear, $eventDate) {
    $eventYear = (int)date('Y', strtotime($eventDate));
    return $eventYear - $birthYear;
}

/**
 * Determine class for a rider based on age and gender
 *
 * @param object $db Database instance
 * @param int $birthYear Rider's birth year
 * @param string $gender Rider's gender (M/K)
 * @param string $eventDate Event date
 * @param string $discipline Event discipline (ROAD/MTB)
 * @return int|null Class ID or null if no match
 */
function determineRiderClass($db, $birthYear, $gender, $eventDate, $discipline = 'ROAD') {
    if (!$birthYear || !$gender || !$eventDate) {
        return null;
    }

    $age = calculateAgeAtEvent($birthYear, $eventDate);

    // Normalize gender
    $gender = strtoupper($gender);
    // Support both F (Female) and K (Kvinna) for backwards compatibility
    if (!in_array($gender, ['M', 'F', 'K'])) {
        return null;
    }

    // Find matching class
    // Support comma-separated disciplines (e.g., "XC,ENDURO")
    // Support both 'F' and 'K' for female classes
    $class = $db->getRow("
        SELECT id
        FROM classes
        WHERE active = 1
          AND (
              gender = ?
              OR gender = 'ALL'
              OR gender IS NULL
              OR gender = ''
              OR (? IN ('F', 'K') AND gender IN ('F', 'K'))
          )
          AND (
              discipline IS NULL
              OR discipline = ''
              OR discipline = ?
              OR discipline LIKE ?
              OR discipline LIKE ?
              OR discipline LIKE ?
          )
          AND (min_age IS NULL OR min_age <= ?)
          AND (max_age IS NULL OR max_age >= ?)
        ORDER BY
            CASE WHEN discipline = ? THEN 0 ELSE 1 END,
            CASE WHEN gender = ? THEN 0 ELSE 1 END,
            sort_order ASC
        LIMIT 1
    ", [
        $gender,
        $gender,
        $discipline,                    // Exact match
        $discipline . ',%',            // Start of list: "XC,..."
        '%,' . $discipline . ',%',     // Middle of list: "...,XC,..."
        '%,' . $discipline,            // End of list: "...,XC"
        $age,
        $age,
        $discipline,
        $gender
    ]);

    return $class ? (int)$class['id'] : null;
}

/**
 * Assign classes to all results for an event
 * Updates results table with class_id based on rider's age and gender
 *
 * @param object $db Database instance
 * @param int $event_id Event ID
 * @return array Stats (assigned, skipped, errors)
 */
function assignClassesToEvent($db, $event_id) {
    $stats = ['assigned' => 0, 'skipped' => 0, 'errors' => []];

    // Get event details
    $event = $db->getRow("
        SELECT id, date, discipline, enable_classes
        FROM events
        WHERE id = ?
    ", [$event_id]);

    if (!$event) {
        $stats['errors'][] = "Event not found";
        return $stats;
    }

    if (!$event['enable_classes']) {
        $stats['errors'][] = "Classes not enabled for this event";
        return $stats;
    }

    $discipline = $event['discipline'] ?? 'ROAD';
    $eventDate = $event['date'];

    // Get all results with rider info
    $results = $db->getAll("
        SELECT r.id as result_id, r.cyclist_id, c.birth_year, c.gender
        FROM results r
        JOIN riders c ON r.cyclist_id = c.id
        WHERE r.event_id = ?
    ", [$event_id]);

    foreach ($results as $result) {
        if (!$result['birth_year'] || !$result['gender']) {
            $stats['skipped']++;
            continue;
        }

        $classId = determineRiderClass(
            $db,
            $result['birth_year'],
            $result['gender'],
            $eventDate,
            $discipline
        );

        if ($classId) {
            try {
                $db->update('results', ['class_id' => $classId], 'id = ?', [$result['result_id']]);
                $stats['assigned']++;
            } catch (Exception $e) {
                $stats['errors'][] = "Result {$result['result_id']}: " . $e->getMessage();
            }
        } else {
            $stats['skipped']++;
        }
    }

    error_log("✅ Assigned classes to event {$event_id}: {$stats['assigned']} assigned, {$stats['skipped']} skipped");

    return $stats;
}

/**
 * Recalculate class positions for an event
 * Assigns position within each class based on overall position
 *
 * @param object $db Database instance
 * @param int $event_id Event ID
 * @return array Stats (updated, errors)
 */
function recalculateClassPositions($db, $event_id) {
    $stats = ['updated' => 0, 'errors' => []];

    // Get all results grouped by class
    $results = $db->getAll("
        SELECT id, class_id, position, status
        FROM results
        WHERE event_id = ? AND class_id IS NOT NULL
        ORDER BY class_id, position ASC
    ", [$event_id]);

    // Group by class
    $byClass = [];
    foreach ($results as $result) {
        $classId = $result['class_id'];
        if (!isset($byClass[$classId])) {
            $byClass[$classId] = [];
        }
        $byClass[$classId][] = $result;
    }

    // Assign positions within each class
    foreach ($byClass as $classId => $classResults) {
        $classPosition = 1;

        foreach ($classResults as $result) {
            $newClassPosition = null;

            if ($result['status'] === 'finished' && $result['position']) {
                $newClassPosition = $classPosition;
                $classPosition++;
            }

            try {
                $db->update('results', [
                    'class_position' => $newClassPosition
                ], 'id = ?', [$result['id']]);
                $stats['updated']++;
            } catch (Exception $e) {
                $stats['errors'][] = "Result {$result['id']}: " . $e->getMessage();
            }
        }
    }

    error_log("✅ Recalculated class positions for event {$event_id}: {$stats['updated']} updated");

    return $stats;
}

/**
 * Calculate class points for a result
 * Uses class-specific point scale if available, otherwise falls back to event scale
 *
 * @param object $db Database instance
 * @param int $event_id Event ID
 * @param int $class_id Class ID
 * @param int $class_position Position within class
 * @param string $status Result status
 * @return float Points earned
 */
function calculateClassPoints($db, $event_id, $class_id, $class_position, $status = 'finished') {
    // No points for DNF, DNS, or DQ
    if (in_array($status, ['dnf', 'dns', 'dq'])) {
        return 0;
    }

    if (!$class_position || $class_position < 1) {
        return 0;
    }

    // Try to get class-specific point scale
    $class = $db->getRow("SELECT point_scale_id FROM classes WHERE id = ?", [$class_id]);
    $scaleId = $class['point_scale_id'] ?? null;

    // Fall back to event's point scale
    if (!$scaleId) {
        $event = $db->getRow("SELECT point_scale_id FROM events WHERE id = ?", [$event_id]);
        $scaleId = $event['point_scale_id'] ?? null;
    }

    // Fall back to default scale
    if (!$scaleId) {
        $scale = $db->getRow("SELECT id FROM point_scales WHERE is_default = 1 LIMIT 1");
        $scaleId = $scale['id'] ?? null;
    }

    if (!$scaleId) {
        error_log("⚠️  No point scale found for class points calculation");
        return 0;
    }

    // Get points for this position
    $value = $db->getRow("
        SELECT points FROM point_scale_values WHERE scale_id = ? AND position = ?
    ", [$scaleId, $class_position]);

    if (!$value) {
        // Position not in scale - use last position
        $last = $db->getRow("
            SELECT points FROM point_scale_values WHERE scale_id = ? ORDER BY position DESC LIMIT 1
        ", [$scaleId]);

        return $last ? (float)$last['points'] : 0;
    }

    return (float)$value['points'];
}

/**
 * Recalculate all class points for an event
 *
 * @param object $db Database instance
 * @param int $event_id Event ID
 * @return array Stats (updated, errors)
 */
function recalculateClassPoints($db, $event_id) {
    $stats = ['updated' => 0, 'errors' => []];

    $results = $db->getAll("
        SELECT id, class_id, class_position, status
        FROM results
        WHERE event_id = ? AND class_id IS NOT NULL
    ", [$event_id]);

    foreach ($results as $result) {
        $classPoints = calculateClassPoints(
            $db,
            $event_id,
            $result['class_id'],
            $result['class_position'],
            $result['status']
        );

        try {
            $db->update('results', ['class_points' => $classPoints], 'id = ?', [$result['id']]);
            $stats['updated']++;
        } catch (Exception $e) {
            $stats['errors'][] = "Result {$result['id']}: " . $e->getMessage();
        }
    }

    error_log("✅ Recalculated class points for event {$event_id}: {$stats['updated']} updated");

    return $stats;
}

/**
 * Get class standings for a series
 *
 * @param object $db Database instance
 * @param int $series_id Series ID
 * @param int $class_id Class ID (optional - all classes if null)
 * @param int $limit Limit results
 * @return array Riders sorted by class points
 */
function getClassSeriesStandings($db, $series_id, $class_id = null, $limit = 100) {
    $where = ["e.series_id = ?", "r.class_id IS NOT NULL"];
    $params = [$series_id];

    if ($class_id) {
        $where[] = "r.class_id = ?";
        $params[] = $class_id;
    }

    $whereClause = implode(' AND ', $where);

    // Get series best results setting
    $series = $db->getRow("SELECT count_best_results, enable_classes FROM series WHERE id = ?", [$series_id]);

    if (!$series['enable_classes']) {
        return [];
    }

    $countBest = $series['count_best_results'] ?? null;

    // Build standings query
    $sql = "
        SELECT
            c.id as rider_id,
            c.firstname,
            c.lastname,
            c.birth_year,
            c.gender,
            cl.name as club_name,
            cls.name as class_name,
            cls.display_name as class_display_name,
            r.class_id,
            COUNT(DISTINCT r.event_id) as events_count,
            SUM(r.class_points) as total_class_points,
            COUNT(CASE WHEN r.class_position = 1 THEN 1 END) as class_wins,
            COUNT(CASE WHEN r.class_position <= 3 THEN 1 END) as class_podiums,
            MIN(r.class_position) as best_class_position
        FROM results r
        JOIN events e ON r.event_id = e.id
        JOIN riders c ON r.cyclist_id = c.id
        LEFT JOIN clubs cl ON c.club_id = cl.id
        LEFT JOIN classes cls ON r.class_id = cls.id
        WHERE {$whereClause}
        GROUP BY c.id, r.class_id
        ORDER BY total_class_points DESC, class_wins DESC, class_podiums DESC
        LIMIT ?
    ";

    $params[] = $limit;

    return $db->getAll($sql, $params);
}

/**
 * Get all active classes
 *
 * @param object $db Database instance
 * @param string $discipline Filter by discipline (optional)
 * @return array Classes sorted by sort_order
 */
function getActiveClasses($db, $discipline = null) {
    $where = ["active = 1"];
    $params = [];

    if ($discipline && $discipline !== 'ALL') {
        // Support comma-separated disciplines
        $where[] = "(
            discipline IS NULL
            OR discipline = ''
            OR discipline = ?
            OR discipline LIKE ?
            OR discipline LIKE ?
            OR discipline LIKE ?
        )";
        $params[] = $discipline;                    // Exact match
        $params[] = $discipline . ',%';            // Start of list
        $params[] = '%,' . $discipline . ',%';     // Middle of list
        $params[] = '%,' . $discipline;            // End of list
    }

    $whereClause = implode(' AND ', $where);

    return $db->getAll("
        SELECT id, name, display_name, gender, min_age, max_age, discipline, point_scale_id, sort_order
        FROM classes
        WHERE {$whereClause}
        ORDER BY sort_order ASC
    ", $params);
}

/**
 * Get class distribution preview for import
 * Shows how many riders would be in each class
 *
 * @param object $db Database instance
 * @param array $riders Array of rider data with birth_year and gender
 * @param string $eventDate Event date
 * @param string $discipline Event discipline
 * @return array Class distribution statistics
 */
function getClassDistributionPreview($riders, $eventDate, $discipline = 'ROAD', $db = null) {
    if (!$db) {
        $db = getDB();
    }

    $distribution = [];
    $unassigned = 0;

    foreach ($riders as $rider) {
        if (!isset($rider['birth_year']) || !isset($rider['gender'])) {
            $unassigned++;
            continue;
        }

        $classId = determineRiderClass(
            $db,
            $rider['birth_year'],
            $rider['gender'],
            $eventDate,
            $discipline
        );

        if ($classId) {
            if (!isset($distribution[$classId])) {
                $class = $db->getRow("SELECT name, display_name FROM classes WHERE id = ?", [$classId]);
                $distribution[$classId] = [
                    'class_id' => $classId,
                    'class_name' => $class['name'],
                    'class_display_name' => $class['display_name'],
                    'count' => 0
                ];
            }
            $distribution[$classId]['count']++;
        } else {
            $unassigned++;
        }
    }

    return [
        'distribution' => array_values($distribution),
        'unassigned' => $unassigned,
        'total' => count($riders)
    ];
}

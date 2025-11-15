<?php
/**
 * Class Calculation Helper Functions
 * Auto-assign riders to classes (M17, K40, etc.) based on age and gender
 */

/**
 * Calculate rider's age at event date
 */
function calculateAgeAtEvent($birthYear, $eventDate) {
    $eventYear = (int)date('Y', strtotime($eventDate));
    return $eventYear - $birthYear;
}

/**
 * Determine class for a rider based on age and gender
 */
function determineRiderClass($db, $birthYear, $gender, $eventDate, $discipline = 'ROAD') {
    if (!$birthYear || !$gender || !$eventDate) {
        return null;
    }

    $age = calculateAgeAtEvent($birthYear, $eventDate);

    // Normalize gender
    $gender = strtoupper($gender);
    if (!in_array($gender, ['M', 'K'])) {
        return null;
    }

    // Find matching class
    $class = $db->getRow("
        SELECT id
        FROM classes
        WHERE active = 1
          AND (gender = ? OR gender = 'ALL')
          AND (discipline = ? OR discipline = 'ALL')
          AND (min_age IS NULL OR min_age <= ?)
          AND (max_age IS NULL OR max_age >= ?)
        ORDER BY
            CASE WHEN discipline = ? THEN 0 ELSE 1 END,
            CASE WHEN gender = ? THEN 0 ELSE 1 END,
            sort_order ASC
        LIMIT 1
    ", [$gender, $discipline, $age, $age, $discipline, $gender]);

    return $class ? (int)$class['id'] : null;
}

/**
 * Get class distribution preview for import
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


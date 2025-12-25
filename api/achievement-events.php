<?php
/**
 * API endpoint to get events related to a specific achievement type for a rider
 */
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

$riderId = isset($_GET['rider_id']) ? (int)$_GET['rider_id'] : 0;
$achievementType = isset($_GET['type']) ? trim($_GET['type']) : '';

if (!$riderId || !$achievementType) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing rider_id or type parameter']);
    exit;
}

$db = getDB();
$events = [];

try {
    switch ($achievementType) {
        case 'gold':
            // Events where rider got 1st place
            $events = $db->getAll("
                SELECT e.id, e.name, e.date, e.location, r.position, c.name as class_name
                FROM results r
                JOIN events e ON r.event_id = e.id
                LEFT JOIN classes c ON r.class_id = c.id
                WHERE r.cyclist_id = ? AND r.position = 1 AND r.status = 'finished'
                ORDER BY e.date DESC
            ", [$riderId]);
            break;

        case 'silver':
            // Events where rider got 2nd place
            $events = $db->getAll("
                SELECT e.id, e.name, e.date, e.location, r.position, c.name as class_name
                FROM results r
                JOIN events e ON r.event_id = e.id
                LEFT JOIN classes c ON r.class_id = c.id
                WHERE r.cyclist_id = ? AND r.position = 2 AND r.status = 'finished'
                ORDER BY e.date DESC
            ", [$riderId]);
            break;

        case 'bronze':
            // Events where rider got 3rd place
            $events = $db->getAll("
                SELECT e.id, e.name, e.date, e.location, r.position, c.name as class_name
                FROM results r
                JOIN events e ON r.event_id = e.id
                LEFT JOIN classes c ON r.class_id = c.id
                WHERE r.cyclist_id = ? AND r.position = 3 AND r.status = 'finished'
                ORDER BY e.date DESC
            ", [$riderId]);
            break;

        case 'hot_streak':
            // Get podium finishes to show streak periods
            $podiums = $db->getAll("
                SELECT e.id, e.name, e.date, e.location, r.position, c.name as class_name
                FROM results r
                JOIN events e ON r.event_id = e.id
                LEFT JOIN classes c ON r.class_id = c.id
                WHERE r.cyclist_id = ? AND r.position <= 3 AND r.status = 'finished'
                ORDER BY e.date ASC
            ", [$riderId]);

            // Find streak sequences
            $streakEvents = [];
            $currentStreak = [];

            foreach ($podiums as $podium) {
                $currentStreak[] = $podium;
                if (count($currentStreak) >= 3) {
                    // This is part of a hot streak
                    foreach ($currentStreak as $ev) {
                        $streakEvents[$ev['id']] = $ev;
                    }
                }
            }

            // Reset streak on non-podium (simplified - just show all podiums that contributed to streaks)
            $events = array_values($streakEvents);
            usort($events, fn($a, $b) => strtotime($b['date']) - strtotime($a['date']));
            break;

        case 'series_completed':
        case 'finisher_100':
            // Series where rider completed 100%
            $events = $db->getAll("
                SELECT DISTINCT s.id, s.name as series_name, s.year,
                       COUNT(DISTINCT se.event_id) as total_events,
                       COUNT(DISTINCT CASE WHEN r.id IS NOT NULL THEN se.event_id END) as participated_events
                FROM series s
                JOIN series_events se ON s.id = se.series_id
                LEFT JOIN results r ON r.event_id = se.event_id AND r.cyclist_id = ? AND r.status = 'finished'
                WHERE se.series_id IN (
                    SELECT se2.series_id
                    FROM series_events se2
                    JOIN results r2 ON r2.event_id = se2.event_id AND r2.cyclist_id = ?
                    GROUP BY se2.series_id
                    HAVING COUNT(DISTINCT se2.event_id) = COUNT(DISTINCT CASE WHEN r2.id IS NOT NULL THEN se2.event_id END)
                )
                GROUP BY s.id
                ORDER BY s.year DESC, s.name
            ", [$riderId, $riderId]);
            break;

        case 'series_leader':
            // Current series where rider is leading
            $events = $db->getAll("
                SELECT s.id, s.name as series_name, s.year
                FROM series s
                WHERE s.status = 'active'
                AND s.id IN (
                    SELECT ss.series_id
                    FROM series_standings ss
                    WHERE ss.rider_id = ? AND ss.rank = 1
                )
                ORDER BY s.year DESC
            ", [$riderId]);
            break;

        case 'series_champion':
        case 'series_wins':
            // Series won by rider
            $events = $db->getAll("
                SELECT s.id, s.name as series_name, s.year
                FROM series s
                WHERE s.status = 'completed'
                AND s.id IN (
                    SELECT ss.series_id
                    FROM series_standings ss
                    WHERE ss.rider_id = ? AND ss.rank = 1
                )
                ORDER BY s.year DESC, s.name
            ", [$riderId]);
            break;

        case 'swedish_champion':
        case 'sm_wins':
            // SM events won
            $events = $db->getAll("
                SELECT e.id, e.name, e.date, e.location, r.position, c.name as class_name
                FROM results r
                JOIN events e ON r.event_id = e.id
                LEFT JOIN classes c ON r.class_id = c.id
                WHERE r.cyclist_id = ?
                AND r.position = 1
                AND r.status = 'finished'
                AND e.is_championship = 1
                ORDER BY e.date DESC
            ", [$riderId]);
            break;

        default:
            // Generic - return recent finishes
            $events = $db->getAll("
                SELECT e.id, e.name, e.date, e.location, r.position, c.name as class_name
                FROM results r
                JOIN events e ON r.event_id = e.id
                LEFT JOIN classes c ON r.class_id = c.id
                WHERE r.cyclist_id = ? AND r.status = 'finished'
                ORDER BY e.date DESC
                LIMIT 10
            ", [$riderId]);
    }

    echo json_encode([
        'success' => true,
        'type' => $achievementType,
        'events' => $events
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}

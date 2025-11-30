<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../core/Database.php';

// Accept series name or slug
$seriesName = isset($_GET['name']) ? trim($_GET['name']) : '';
$seriesSlug = isset($_GET['slug']) ? trim($_GET['slug']) : '';
$seriesId = isset($_GET['id']) ? trim($_GET['id']) : '';
$category = isset($_GET['category']) && $_GET['category'] !== '' ? trim($_GET['category']) : null;

// Series metadata with best_results_count
$seriesMetadata = [
    'Capital Gravity Series' => ['slug' => 'capital-gravity', 'best_results_count' => 4],
    'Götaland Gravity Series' => ['slug' => 'gotaland-gravity', 'best_results_count' => null],
    'GravitySeries Downhill' => ['slug' => 'gravityseries-downhill', 'best_results_count' => null],
    'GravitySeries Total' => ['slug' => 'gravityseries-total', 'best_results_count' => null],
    'Jämtland GravitySeries' => ['slug' => 'jamtland-gravity', 'best_results_count' => 5],
    'SweCup Enduro' => ['slug' => 'swecup-enduro', 'best_results_count' => 5]
];

// Find series name from slug or id
if ($seriesSlug || $seriesId) {
    $lookupSlug = $seriesSlug ?: $seriesId;
    foreach ($seriesMetadata as $name => $meta) {
        if ($meta['slug'] === $lookupSlug) {
            $seriesName = $name;
            break;
        }
    }
}

if (empty($seriesName)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Series name, slug, or id required']);
    exit;
}

try {
    $pdo = Database::pdo();

    // Get best_results_count for this series
    $bestCount = $seriesMetadata[$seriesName]['best_results_count'] ?? null;

    // Get series info
    $seriesInfo = [
        'name' => $seriesName,
        'slug' => $seriesMetadata[$seriesName]['slug'] ?? strtolower(preg_replace('/[^a-z0-9]+/i', '-', $seriesName)),
        'best_results_count' => $bestCount
    ];

    // Get all events in this series (for column headers)
    $stmt = $pdo->prepare("
        SELECT id, name, date
        FROM events
        WHERE series_id = :series_name
        ORDER BY date ASC
    ");
    $stmt->execute(['series_name' => $seriesName]);
    $events = $stmt->fetchAll();
    $eventIds = array_column($events, 'id');

    // Get all results for this series
    $query = "
        SELECT
            res.cyclist_id AS rider_id,
            CONCAT(r.firstname, ' ', r.lastname) AS rider_name,
            r.gravity_id,
            c.name AS club_name,
            res.event_id,
            e.name AS event_name,
            e.date AS event_date,
            res.points,
            res.position,
            res.category_id AS category
        FROM results res
        JOIN events e ON res.event_id = e.id
        JOIN riders r ON res.cyclist_id = r.id
        LEFT JOIN clubs c ON r.club_id = c.id
        WHERE e.series_id = :series_name
    ";

    $params = ['series_name' => $seriesName];

    if ($category) {
        $query .= " AND res.category_id = :category";
        $params['category'] = $category;
    }

    $query .= " ORDER BY res.cyclist_id, res.points DESC";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $allResults = $stmt->fetchAll();

    // Group by rider
    $riders = [];
    foreach ($allResults as $result) {
        $riderId = $result['rider_id'];

        if (!isset($riders[$riderId])) {
            $riders[$riderId] = [
                'rider_id' => $riderId,
                'rider_name' => $result['rider_name'],
                'gravity_id' => $result['gravity_id'],
                'club_name' => $result['club_name'],
                'category' => $result['category'],
                'event_scores' => [],
                'event_points' => [], // Map of event_id => points for display
                'total_points' => 0,
                'counted_events' => 0
            ];
        }

        $riders[$riderId]['event_scores'][] = [
            'event_id' => $result['event_id'],
            'event_name' => $result['event_name'],
            'event_date' => $result['event_date'],
            'points' => (int) $result['points'],
            'position' => $result['position']
        ];

        $riders[$riderId]['event_points'][$result['event_id']] = (int) $result['points'];
    }

    // Calculate totals using "best N results" logic
    foreach ($riders as &$rider) {
        // Sort scores by points descending
        usort($rider['event_scores'], function ($a, $b) {
            return $b['points'] - $a['points'];
        });

        // Take best N or all
        $scoresToCount = $rider['event_scores'];
        if ($bestCount !== null && count($scoresToCount) > $bestCount) {
            $scoresToCount = array_slice($scoresToCount, 0, $bestCount);
        }

        // Mark which events are counted
        $countedEventIds = array_column($scoresToCount, 'event_id');

        // Sum points
        $total = 0;
        foreach ($scoresToCount as $score) {
            $total += $score['points'];
        }

        $rider['total_points'] = $total;
        $rider['counted_events'] = count($scoresToCount);
        $rider['counted_event_ids'] = $countedEventIds;
    }
    unset($rider);

    // Sort by total points descending
    usort($riders, function ($a, $b) {
        if ($b['total_points'] !== $a['total_points']) {
            return $b['total_points'] - $a['total_points'];
        }
        // Tiebreaker: more events counted = better
        return $b['counted_events'] - $a['counted_events'];
    });

    // Add positions
    $position = 1;
    $lastPoints = null;
    $lastPosition = 1;
    foreach ($riders as &$rider) {
        if ($lastPoints !== null && $rider['total_points'] === $lastPoints) {
            $rider['position'] = $lastPosition;
        } else {
            $rider['position'] = $position;
            $lastPosition = $position;
        }
        $lastPoints = $rider['total_points'];
        $position++;
    }
    unset($rider);

    echo json_encode([
        'ok' => true,
        'data' => [
            'series' => $seriesInfo,
            'events' => $events,
            'standings' => array_values($riders)
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB error: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

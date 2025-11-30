<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../core/Database.php';

// Accept series name or slug
$seriesName = isset($_GET['name']) ? trim($_GET['name']) : '';
$seriesSlug = isset($_GET['slug']) ? trim($_GET['slug']) : '';
$seriesId = isset($_GET['id']) ? trim($_GET['id']) : '';

// Series metadata (same as series.php)
$seriesMetadata = [
    'Capital Gravity Series' => [
        'slug' => 'capital-gravity',
        'description' => 'Stockholms egen EWS',
        'best_results_count' => 4,
        'discipline' => 'Enduro'
    ],
    'Götaland Gravity Series' => [
        'slug' => 'gotaland-gravity',
        'description' => 'Södra Sveriges serie',
        'best_results_count' => null,
        'discipline' => 'Mixed'
    ],
    'GravitySeries Downhill' => [
        'slug' => 'gravityseries-downhill',
        'description' => 'Svenska Downhill-serien',
        'best_results_count' => null,
        'discipline' => 'Downhill'
    ],
    'GravitySeries Total' => [
        'slug' => 'gravityseries-total',
        'description' => 'Totalserien där alla event ingår',
        'best_results_count' => null,
        'discipline' => 'Mixed'
    ],
    'Jämtland GravitySeries' => [
        'slug' => 'jamtland-gravity',
        'description' => 'Åre Bike Festival & GravitySeries Downhill Åre',
        'best_results_count' => 5,
        'discipline' => 'Mixed'
    ],
    'SweCup Enduro' => [
        'slug' => 'swecup-enduro',
        'description' => 'Svenska nationella Enduro Serien',
        'best_results_count' => 5,
        'discipline' => 'Enduro'
    ]
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

    // Get series stats
    $stmt = $pdo->prepare("
        SELECT
            e.series_id AS name,
            COUNT(DISTINCT e.id) AS event_count,
            COUNT(DISTINCT res.cyclist_id) AS participant_count,
            MIN(YEAR(e.date)) AS first_year,
            MAX(YEAR(e.date)) AS last_year
        FROM events e
        LEFT JOIN results res ON e.id = res.event_id
        WHERE e.series_id = :series_name
        GROUP BY e.series_id
    ");
    $stmt->execute(['series_name' => $seriesName]);
    $seriesRow = $stmt->fetch();

    if (!$seriesRow) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Series not found']);
        exit;
    }

    $meta = $seriesMetadata[$seriesName] ?? [
        'slug' => strtolower(preg_replace('/[^a-z0-9]+/i', '-', $seriesName)),
        'description' => '',
        'best_results_count' => null,
        'discipline' => 'Mixed'
    ];

    $series = [
        'id' => $meta['slug'],
        'name' => $seriesName,
        'slug' => $meta['slug'],
        'description' => $meta['description'],
        'discipline' => $meta['discipline'],
        'best_results_count' => $meta['best_results_count'],
        'year' => (int) $seriesRow['last_year'],
        'event_count' => (int) $seriesRow['event_count'],
        'participant_count' => (int) $seriesRow['participant_count']
    ];

    // Get events in this series
    $stmt = $pdo->prepare("
        SELECT
            e.id,
            e.name,
            e.date,
            e.location,
            e.discipline,
            e.status,
            COUNT(DISTINCT res.cyclist_id) AS participant_count
        FROM events e
        LEFT JOIN results res ON e.id = res.event_id
        WHERE e.series_id = :series_name
        GROUP BY e.id
        ORDER BY e.date ASC
    ");
    $stmt->execute(['series_name' => $seriesName]);
    $series['events'] = $stmt->fetchAll();

    // Get unique categories in this series
    $stmt = $pdo->prepare("
        SELECT DISTINCT res.category
        FROM results res
        JOIN events e ON res.event_id = e.id
        WHERE e.series_id = :series_name
          AND res.category_id AS category IS NOT NULL
          AND res.category_id AS category <> ''
        ORDER BY res.category
    ");
    $stmt->execute(['series_name' => $seriesName]);
    $series['categories'] = $stmt->fetchAll(PDO::FETCH_COLUMN);

    echo json_encode(['ok' => true, 'data' => $series], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB error: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

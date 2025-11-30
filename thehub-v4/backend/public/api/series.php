<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../core/Database.php';

try {
    $pdo = Database::pdo();

    $year = isset($_GET['year']) && $_GET['year'] !== ''
        ? (int) $_GET['year']
        : (int) date('Y');

    // Get series from events.type field with aggregated stats
    // Series metadata is defined here since we don't have a dedicated series table
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

    // Query distinct series from events
    $sql = "
        SELECT
            e.series_id AS name,
            COUNT(DISTINCT e.id) AS event_count,
            COUNT(DISTINCT res.cyclist_id) AS participant_count,
            MIN(e.date) AS first_event,
            MAX(e.date) AS last_event
        FROM events e
        LEFT JOIN results res ON e.id = res.event_id
        WHERE e.series_id IS NOT NULL
          AND e.series_id <> ''
          AND YEAR(e.date) = :year
        GROUP BY e.series_id
        ORDER BY e.series_id
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(['year' => $year]);
    $rows = $stmt->fetchAll();

    // Enrich with metadata
    $series = [];
    foreach ($rows as $row) {
        $name = $row['name'];
        $meta = $seriesMetadata[$name] ?? [
            'slug' => strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name)),
            'description' => '',
            'best_results_count' => null,
            'discipline' => 'Mixed'
        ];

        $series[] = [
            'id' => $meta['slug'],
            'name' => $name,
            'slug' => $meta['slug'],
            'description' => $meta['description'],
            'discipline' => $meta['discipline'],
            'best_results_count' => $meta['best_results_count'],
            'year' => $year,
            'event_count' => (int) $row['event_count'],
            'participant_count' => (int) $row['participant_count'],
            'first_event' => $row['first_event'],
            'last_event' => $row['last_event']
        ];
    }

    echo json_encode(['ok' => true, 'data' => $series], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB error: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

<?php
// backend/public/api/ranking.php
header('Content-Type: application/json; charset=utf-8');

$ranking = [
    [
        'name'   => 'Rider One',
        'club'   => 'GravitySeries CK',
        'series' => 'Capital Enduro',
        'points' => 1340,
    ],
    [
        'name'   => 'Rider Two',
        'club'   => 'Jämtland Gravity',
        'series' => 'Götaland Enduro',
        'points' => 1260,
    ],
    [
        'name'   => 'Rider Three',
        'club'   => 'Åre Bergcyklister',
        'series' => 'Jämtland',
        'points' => 1210,
    ],
];

echo json_encode($ranking);

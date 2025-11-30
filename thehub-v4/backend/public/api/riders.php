<?php
// backend/public/api/riders.php
header('Content-Type: application/json; charset=utf-8');

$riders = [
    [
        'id'       => 1001,
        'name'     => 'Rider One',
        'club'     => 'GravitySeries CK',
        'nation'   => 'SWE',
        'category' => 'Herrar Senior',
    ],
    [
        'id'       => 1002,
        'name'     => 'Rider Two',
        'club'     => 'Åre Bergcyklister',
        'nation'   => 'SWE',
        'category' => 'Herrar Junior',
    ],
    [
        'id'       => 1003,
        'name'     => 'Rider Three',
        'club'     => 'Järvsö Bergcyklister',
        'nation'   => 'SWE',
        'category' => 'Damer Senior',
    ],
];

echo json_encode($riders);

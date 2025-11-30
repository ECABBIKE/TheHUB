<?php
// backend/public/api/events.php
header('Content-Type: application/json; charset=utf-8');

$events = [
    [
        'id'       => 1,
        'name'     => 'Capital Enduro #1',
        'location' => 'Stockholm',
        'date'     => '2026-05-10',
        'series'   => 'Capital Enduro',
        'status'   => 'Planerad',
    ],
    [
        'id'       => 2,
        'name'     => 'Götaland Enduro #1',
        'location' => 'Ulricehamn',
        'date'     => '2026-05-24',
        'series'   => 'Götaland Enduro',
        'status'   => 'Planerad',
    ],
    [
        'id'       => 3,
        'name'     => 'Jämtland Enduro #1',
        'location' => 'Åre',
        'date'     => '2026-06-14',
        'series'   => 'Jämtland',
        'status'   => 'Planerad',
    ],
];

echo json_encode($events);

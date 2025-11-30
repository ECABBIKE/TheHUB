<?php
// backend/public/api/event.php
header('Content-Type: application/json; charset=utf-8');

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

$event = [
    'id'       => $id,
    'name'     => 'Example Event #' . $id,
    'location' => 'TBD',
    'date'     => '2026-06-01',
    'series'   => 'Example Series',
    'status'   => 'Planerad',
];

echo json_encode($event);

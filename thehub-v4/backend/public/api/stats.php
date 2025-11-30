<?php
// backend/public/api/stats.php
header('Content-Type: application/json; charset=utf-8');

echo json_encode([
    'total_events'  => 6,
    'total_riders'  => 120,
    'total_clubs'   => 18,
    'last_updated'  => date('Y-m-d H:i'),
]);

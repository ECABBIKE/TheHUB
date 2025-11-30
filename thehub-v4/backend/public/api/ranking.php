<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

// Stub endpoint for ranking.php
// Returnerar tom lista men med ok=true så att frontenden fungerar utan fel.

echo json_encode([
    'ok'   => true,
    'data' => [],
]);

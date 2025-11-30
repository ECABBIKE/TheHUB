<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../core/Database.php';

try {
    $pdo = Database::pdo();

    // Hämta upp till 500 riders. Anpassa kolumner vid behov.
    $sql = "SELECT
                id,
                gravity_id,
                CONCAT(first_name, ' ', last_name) AS name,
                club,
                uci_id,
                license_number
            FROM riders
            ORDER BY id DESC
            LIMIT 500";

    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll();

    echo json_encode([
        'ok'   => true,
        'data' => $rows,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok'    => false,
        'error' => 'DB error: ' . $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}

<?php
/**
 * Photo Tags API
 * Hämta taggade deltagare för ett foto
 */
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

$photoId = (int)($_GET['photo_id'] ?? 0);

if (!$photoId) {
    echo json_encode(['success' => false, 'error' => 'photo_id krävs']);
    exit;
}

global $pdo;

try {
    $stmt = $pdo->prepare("
        SELECT prt.id as tag_id, prt.rider_id, prt.created_at,
               r.firstname, r.lastname
        FROM photo_rider_tags prt
        JOIN riders r ON prt.rider_id = r.id
        WHERE prt.photo_id = ?
        ORDER BY r.firstname, r.lastname
    ");
    $stmt->execute([$photoId]);
    $tags = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => $tags]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Databasfel']);
}

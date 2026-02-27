<?php
/**
 * Photo Tags API
 * GET  - Hämta taggade deltagare för ett foto (publikt)
 * POST - Tagga en deltagare på ett foto (kräver inloggning)
 * DELETE - Ta bort en tagg (kräver inloggning, bara egna taggar eller admin)
 */
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

global $pdo;

$method = $_SERVER['REQUEST_METHOD'];

// GET - Hämta taggar för ett foto
if ($method === 'GET') {
    $photoId = (int)($_GET['photo_id'] ?? 0);

    if (!$photoId) {
        echo json_encode(['success' => false, 'error' => 'photo_id krävs']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT prt.id as tag_id, prt.rider_id, prt.tagged_by, prt.created_at,
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
    exit;
}

// POST - Tagga en deltagare
if ($method === 'POST') {
    $riderId = $_SESSION['rider_id'] ?? 0;
    if (!$riderId) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Du måste vara inloggad för att tagga']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        // Fallback to form data
        $input = $_POST;
    }

    $photoId = (int)($input['photo_id'] ?? 0);
    $tagRiderId = (int)($input['rider_id'] ?? 0);

    if (!$photoId || !$tagRiderId) {
        echo json_encode(['success' => false, 'error' => 'photo_id och rider_id krävs']);
        exit;
    }

    // Verifiera att fotot existerar
    try {
        $stmt = $pdo->prepare("SELECT id FROM event_photos WHERE id = ?");
        $stmt->execute([$photoId]);
        if (!$stmt->fetch()) {
            echo json_encode(['success' => false, 'error' => 'Fotot finns inte']);
            exit;
        }
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => 'Databasfel']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("INSERT IGNORE INTO photo_rider_tags (photo_id, rider_id, tagged_by) VALUES (?, ?, ?)");
        $stmt->execute([$photoId, $tagRiderId, $riderId]);

        // Hämta rider-namn för bekräftelse
        $stmt = $pdo->prepare("SELECT firstname, lastname FROM riders WHERE id = ?");
        $stmt->execute([$tagRiderId]);
        $rider = $stmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'tag_id' => (int)$pdo->lastInsertId(),
            'rider_name' => ($rider['firstname'] ?? '') . ' ' . ($rider['lastname'] ?? '')
        ]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => 'Kunde inte spara tagg']);
    }
    exit;
}

// DELETE - Ta bort tagg
if ($method === 'DELETE') {
    $riderId = $_SESSION['rider_id'] ?? 0;
    if (!$riderId) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Du måste vara inloggad']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $tagId = (int)($input['tag_id'] ?? $_GET['tag_id'] ?? 0);

    if (!$tagId) {
        echo json_encode(['success' => false, 'error' => 'tag_id krävs']);
        exit;
    }

    // Kolla om användaren har rätt att ta bort (admin eller den som taggade)
    $isAdmin = !empty($_SESSION['user']['role']) && $_SESSION['user']['role'] === 'admin';
    try {
        $stmt = $pdo->prepare("SELECT tagged_by FROM photo_rider_tags WHERE id = ?");
        $stmt->execute([$tagId]);
        $tag = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$tag) {
            echo json_encode(['success' => false, 'error' => 'Taggen finns inte']);
            exit;
        }

        if (!$isAdmin && (int)$tag['tagged_by'] !== (int)$riderId) {
            echo json_encode(['success' => false, 'error' => 'Du kan bara ta bort taggar du själv skapat']);
            exit;
        }

        $pdo->prepare("DELETE FROM photo_rider_tags WHERE id = ?")->execute([$tagId]);
        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => 'Databasfel']);
    }
    exit;
}

echo json_encode(['success' => false, 'error' => 'Metod stöds ej']);

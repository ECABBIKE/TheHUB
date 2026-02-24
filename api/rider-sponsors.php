<?php
/**
 * Rider Sponsors API
 * CRUD for personal sponsors on rider profiles (Premium only)
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/premium.php';

// Require login
session_start();
$riderId = $_SESSION['rider_id'] ?? $_SESSION['hub_user_id'] ?? null;

if (!$riderId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Inte inloggad']);
    exit;
}

$pdo = $GLOBALS['pdo'];

// Verify premium status
if (!isPremiumMember($pdo, (int)$riderId)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Premium-medlemskap krävs']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$action = $input['action'] ?? $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'list':
            $sponsors = getRiderSponsors($pdo, (int)$riderId);
            echo json_encode(['success' => true, 'sponsors' => $sponsors]);
            break;

        case 'add':
            $name = trim($input['name'] ?? '');
            $logoUrl = trim($input['logo_url'] ?? '');
            $websiteUrl = trim($input['website_url'] ?? '');

            if (empty($name)) {
                throw new Exception('Sponsornamn krävs');
            }

            if (strlen($name) > 150) {
                throw new Exception('Sponsornamn får vara max 150 tecken');
            }

            // Validate URLs if provided
            if ($logoUrl && !filter_var($logoUrl, FILTER_VALIDATE_URL)) {
                throw new Exception('Ogiltig logotyp-URL');
            }
            if ($websiteUrl && !filter_var($websiteUrl, FILTER_VALIDATE_URL)) {
                throw new Exception('Ogiltig webbplats-URL');
            }

            // Check max 6 sponsors
            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM rider_sponsors WHERE rider_id = ? AND active = 1");
            $countStmt->execute([$riderId]);
            if ($countStmt->fetchColumn() >= 6) {
                throw new Exception('Max 6 sponsorer tillåtna');
            }

            // Get next sort_order
            $orderStmt = $pdo->prepare("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM rider_sponsors WHERE rider_id = ?");
            $orderStmt->execute([$riderId]);
            $nextOrder = $orderStmt->fetchColumn();

            $stmt = $pdo->prepare("
                INSERT INTO rider_sponsors (rider_id, name, logo_url, website_url, sort_order)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $riderId,
                $name,
                $logoUrl ?: null,
                $websiteUrl ?: null,
                $nextOrder
            ]);

            echo json_encode([
                'success' => true,
                'sponsor_id' => $pdo->lastInsertId(),
                'message' => 'Sponsor tillagd'
            ]);
            break;

        case 'remove':
            $sponsorId = (int)($input['sponsor_id'] ?? 0);

            if (!$sponsorId) {
                throw new Exception('Sponsor-ID krävs');
            }

            // Verify ownership
            $stmt = $pdo->prepare("DELETE FROM rider_sponsors WHERE id = ? AND rider_id = ?");
            $stmt->execute([$sponsorId, $riderId]);

            if ($stmt->rowCount() === 0) {
                throw new Exception('Sponsor hittades inte');
            }

            echo json_encode(['success' => true, 'message' => 'Sponsor borttagen']);
            break;

        case 'update':
            $sponsorId = (int)($input['sponsor_id'] ?? 0);
            $name = trim($input['name'] ?? '');
            $logoUrl = trim($input['logo_url'] ?? '');
            $websiteUrl = trim($input['website_url'] ?? '');

            if (!$sponsorId || empty($name)) {
                throw new Exception('Sponsor-ID och namn krävs');
            }

            if ($logoUrl && !filter_var($logoUrl, FILTER_VALIDATE_URL)) {
                throw new Exception('Ogiltig logotyp-URL');
            }
            if ($websiteUrl && !filter_var($websiteUrl, FILTER_VALIDATE_URL)) {
                throw new Exception('Ogiltig webbplats-URL');
            }

            $stmt = $pdo->prepare("
                UPDATE rider_sponsors
                SET name = ?, logo_url = ?, website_url = ?, updated_at = NOW()
                WHERE id = ? AND rider_id = ?
            ");
            $stmt->execute([
                $name,
                $logoUrl ?: null,
                $websiteUrl ?: null,
                $sponsorId,
                $riderId
            ]);

            if ($stmt->rowCount() === 0) {
                throw new Exception('Sponsor hittades inte');
            }

            echo json_encode(['success' => true, 'message' => 'Sponsor uppdaterad']);
            break;

        default:
            throw new Exception('Okänd åtgärd: ' . $action);
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

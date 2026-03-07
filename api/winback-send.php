<?php
/**
 * AJAX endpoint for sending winback invitation emails one at a time.
 * Used by the batch sender in winback-campaigns.php to avoid timeouts.
 *
 * POST params:
 *   campaign_id - Campaign ID
 *   rider_id    - Single rider ID to send to
 *   resend      - If '1', skip the already-invited check
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');

// Require admin or promotor login
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Ej inloggad']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Metod ej tillåten']);
    exit;
}

require_once __DIR__ . '/../includes/mail.php';

global $pdo;

$campaignId = (int)($_POST['campaign_id'] ?? 0);
$riderId = (int)($_POST['rider_id'] ?? 0);
$isResend = ($_POST['resend'] ?? '') === '1';

if (!$campaignId || !$riderId) {
    echo json_encode(['error' => 'Saknar campaign_id eller rider_id']);
    exit;
}

// Get campaign
$stmt = $pdo->prepare("SELECT * FROM winback_campaigns WHERE id = ?");
$stmt->execute([$campaignId]);
$campaign = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$campaign) {
    echo json_encode(['error' => 'Kampanj hittades inte']);
    exit;
}

// Permission check
$isAdmin = ($_SESSION['admin_role'] ?? '') === 'admin' || ($_SESSION['admin_role'] ?? '') === 'super_admin';
$isOwner = !empty($campaign['owner_user_id']) && (int)$campaign['owner_user_id'] === (int)($_SESSION['admin_id'] ?? 0);
$hasPromotorAccess = !empty($campaign['allow_promotor_access']) && ($_SESSION['admin_role'] ?? '') === 'promotor';
if (!$isAdmin && !$isOwner && !$hasPromotorAccess) {
    echo json_encode(['error' => 'Ingen behörighet']);
    exit;
}

// Get rider
$stmt = $pdo->prepare("SELECT id, firstname, lastname, email FROM riders WHERE id = ?");
$stmt->execute([$riderId]);
$rider = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$rider || empty($rider['email'])) {
    echo json_encode(['status' => 'skipped', 'reason' => 'Saknar e-post']);
    exit;
}

// Check if already invited (skip if resend mode)
$invTableExists = false;
try {
    $check = $pdo->query("SHOW TABLES LIKE 'winback_invitations'");
    $invTableExists = $check->rowCount() > 0;
} catch (Exception $e) {}

if ($invTableExists && !$isResend) {
    $stmt = $pdo->prepare("SELECT id FROM winback_invitations WHERE campaign_id = ? AND rider_id = ?");
    $stmt->execute([$campaignId, $riderId]);
    if ($stmt->fetch()) {
        echo json_encode(['status' => 'skipped', 'reason' => 'Redan inbjuden']);
        exit;
    }
}

// Build discount info
$isExternalCodes = !empty($campaign['external_codes_enabled']);
$discountCode = null;
$discountText = '';

if (!$isExternalCodes && !empty($campaign['discount_code_id'])) {
    $stmt = $pdo->prepare("SELECT * FROM discount_codes WHERE id = ?");
    $stmt->execute([$campaign['discount_code_id']]);
    $discountCode = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($discountCode) {
        $discountText = $discountCode['discount_type'] === 'percentage'
            ? intval($discountCode['discount_value']) . '% rabatt'
            : number_format($discountCode['discount_value'], 0) . ' kr rabatt';
    }
}

// Generate tracking token
$trackingToken = bin2hex(random_bytes(32));
$surveyUrl = 'https://thehub.gravityseries.se/profile/winback-survey?t=' . $trackingToken;

// Subject
$subject = !empty($campaign['email_subject'])
    ? $campaign['email_subject'] . ' - TheHUB'
    : 'Vi saknar dig! - TheHUB';

// Body
$emailBody = !empty($campaign['email_body'])
    ? $campaign['email_body']
    : "Hej {{name}},\n\nVi har märkt att du inte tävlat på ett tag.\n\nSvara på en kort enkät så får du rabattkoden {{discount_code}} ({{discount_text}}) på din nästa anmälan!";

$codeText = $isExternalCodes
    ? ($campaign['external_code_prefix'] ?? 'KOD')
    : htmlspecialchars($discountCode['code'] ?? '');
$discountLabel = $isExternalCodes
    ? 'rabattkod (delas ut efter enkätsvar)'
    : $discountText;

$emailBody = str_replace([
    '{{name}}',
    '{{discount_code}}',
    '{{discount_text}}',
    '{{hub_link}}',
    '{{survey_link}}'
], [
    htmlspecialchars($rider['firstname']),
    $codeText,
    $discountLabel,
    SITE_URL ?? 'https://thehub.gravityseries.se',
    $surveyUrl
], $emailBody);

// Wrap in HTML with Back to Gravity banner
$body = '
    <div class="campaign-banner">
        <img src="https://thehub.gravityseries.se/uploads/media/branding/697f64b56775d_1769956533.png" alt="Back to Gravity" style="max-width:280px;height:auto;margin:0 auto 8px;display:block;">
        <div class="campaign-banner-sub">En kampanj från GravitySeries</div>
    </div>
    <div class="header">
        <div class="logo">GravitySeries<span class="logo-sub"> - TheHUB</span></div>
    </div>
    <div style="white-space: pre-wrap;">' . nl2br($emailBody) . '</div>
    <p class="text-center" style="margin-top: 24px;">
        <a href="' . $surveyUrl . '" class="btn">Svara på enkäten</a>
    </p>
';

$fullBody = hub_email_template('custom', ['content' => $body]);

// Send
$sent = hub_send_email($rider['email'], $subject, $fullBody);

// Log invitation
if ($invTableExists) {
    if ($isResend) {
        // Update existing invitation record
        $stmt = $pdo->prepare("
            UPDATE winback_invitations SET invitation_status = ?, sent_at = NOW(), tracking_token = ?
            WHERE campaign_id = ? AND rider_id = ?
        ");
        $stmt->execute([$sent ? 'sent' : 'failed', $trackingToken, $campaignId, $riderId]);

        // If no row was updated, insert new
        if ($stmt->rowCount() === 0) {
            $stmt = $pdo->prepare("
                INSERT INTO winback_invitations (campaign_id, rider_id, email_address, invitation_method, invitation_status, sent_at, sent_by, tracking_token)
                VALUES (?, ?, ?, 'email', ?, NOW(), ?, ?)
            ");
            $stmt->execute([
                $campaignId, $riderId, $rider['email'],
                $sent ? 'sent' : 'failed',
                $_SESSION['admin_id'] ?? null,
                $trackingToken
            ]);
        }
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO winback_invitations (campaign_id, rider_id, email_address, invitation_method, invitation_status, sent_at, sent_by, tracking_token)
            VALUES (?, ?, ?, 'email', ?, NOW(), ?, ?)
        ");
        $stmt->execute([
            $campaignId, $riderId, $rider['email'],
            $sent ? 'sent' : 'failed',
            $_SESSION['admin_id'] ?? null,
            $trackingToken
        ]);
    }
}

echo json_encode([
    'status' => $sent ? 'sent' : 'failed',
    'rider_id' => $riderId,
    'name' => $rider['firstname'] . ' ' . $rider['lastname'],
    'email' => $rider['email']
]);

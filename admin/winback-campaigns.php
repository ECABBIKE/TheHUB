<?php
/**
 * Win-Back Campaigns Management
 * Manage survey campaigns and view responses
 * Accessible by: admin, super_admin, and promotors with campaign ownership
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin(); // Allow promotors, admins, and super_admins

global $pdo;

// Get current user info
$currentUser = getCurrentAdmin();
$currentUserId = $currentUser['id'] ?? null;
$isAdmin = hasRole('admin'); // admin or super_admin
$isPromotor = isRole('promotor');

/**
 * Check if current user can access a specific campaign
 * Admins can access all, promotors only their owned campaigns or those with allow_promotor_access
 */
function canAccessCampaign($campaign) {
    global $isAdmin, $currentUserId;

    if ($isAdmin) {
        return true;
    }

    // Promotor can access if they own it or if promotor access is allowed
    if ($campaign['owner_user_id'] == $currentUserId) {
        return true;
    }

    if ($campaign['allow_promotor_access']) {
        return true;
    }

    return false;
}

/**
 * Check if current user can edit a specific campaign
 * Admins can edit all, promotors only their owned campaigns
 */
function canEditCampaign($campaign) {
    global $isAdmin, $currentUserId;

    if ($isAdmin) {
        return true;
    }

    // Promotor can only edit if they own it
    return $campaign['owner_user_id'] == $currentUserId;
}

// Check if tables exist
$tablesExist = false;
try {
    $check = $pdo->query("SHOW TABLES LIKE 'winback_campaigns'");
    $tablesExist = $check->rowCount() > 0;
} catch (Exception $e) {}

// Handle actions
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tablesExist) {
    $action = $_POST['action'] ?? '';

    if ($action === 'toggle_campaign') {
        $id = (int)$_POST['id'];
        $pdo->prepare("UPDATE winback_campaigns SET is_active = NOT is_active WHERE id = ?")->execute([$id]);
        $message = 'Kampanjstatus uppdaterad';
    } elseif ($action === 'create_campaign') {
        $name = trim($_POST['name'] ?? '');
        $brandIds = $_POST['brand_ids'] ?? [];
        $discountType = $_POST['discount_type'] ?? 'fixed';
        $discountValue = (float)($_POST['discount_value'] ?? 100);
        $validUntil = $_POST['valid_until'] ?? null;
        $ownerId = !empty($_POST['owner_user_id']) ? (int)$_POST['owner_user_id'] : $currentUserId;
        $allowPromotorAccess = isset($_POST['allow_promotor_access']) ? 1 : 0;

        if ($name && !empty($brandIds)) {
            $stmt = $pdo->prepare("
                INSERT INTO winback_campaigns (name, target_type, brand_ids, discount_type, discount_value, discount_valid_until, owner_user_id, allow_promotor_access, is_active)
                VALUES (?, 'multi_brand', ?, ?, ?, ?, ?, ?, 1)
            ");
            $stmt->execute([$name, json_encode(array_map('intval', $brandIds)), $discountType, $discountValue, $validUntil ?: null, $ownerId, $allowPromotorAccess]);
            $message = 'Kampanj skapad!';
        } else {
            $error = 'Namn och varumarken kravs';
        }
    } elseif ($action === 'update_campaign_owner') {
        // Only admins can change campaign ownership
        if (!$isAdmin) {
            $error = 'Endast administratorer kan andra agare';
        } else {
            $id = (int)$_POST['id'];
            $ownerId = !empty($_POST['owner_user_id']) ? (int)$_POST['owner_user_id'] : null;
            $allowPromotorAccess = isset($_POST['allow_promotor_access']) ? 1 : 0;

            $stmt = $pdo->prepare("UPDATE winback_campaigns SET owner_user_id = ?, allow_promotor_access = ? WHERE id = ?");
            $stmt->execute([$ownerId, $allowPromotorAccess, $id]);
            $message = 'Kampanjinställningar uppdaterade';
        }
    } elseif ($action === 'update_campaign') {
        $id = (int)$_POST['id'];

        // Get campaign to check permissions
        $stmt = $pdo->prepare("SELECT * FROM winback_campaigns WHERE id = ?");
        $stmt->execute([$id]);
        $campaignToEdit = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$campaignToEdit) {
            $error = 'Kampanj hittades inte';
        } elseif (!canEditCampaign($campaignToEdit)) {
            $error = 'Du har inte behörighet att redigera denna kampanj';
        } else {
            $name = trim($_POST['name'] ?? '');
            $discountType = $_POST['discount_type'] ?? $campaignToEdit['discount_type'];
            $discountValue = (float)($_POST['discount_value'] ?? $campaignToEdit['discount_value']);
            $validUntil = $_POST['valid_until'] ?? $campaignToEdit['discount_valid_until'];

            if (empty($name)) {
                $error = 'Kampanjnamn krävs';
            } else {
                // Admin can also update owner settings
                if ($isAdmin) {
                    $ownerId = !empty($_POST['owner_user_id']) ? (int)$_POST['owner_user_id'] : null;
                    $allowPromotorAccess = isset($_POST['allow_promotor_access']) ? 1 : 0;

                    $stmt = $pdo->prepare("
                        UPDATE winback_campaigns
                        SET name = ?, discount_type = ?, discount_value = ?, discount_valid_until = ?,
                            owner_user_id = ?, allow_promotor_access = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([$name, $discountType, $discountValue, $validUntil ?: null, $ownerId, $allowPromotorAccess, $id]);
                } else {
                    // Non-admin (promotor) can only update basic settings
                    $stmt = $pdo->prepare("
                        UPDATE winback_campaigns
                        SET name = ?, discount_type = ?, discount_value = ?, discount_valid_until = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([$name, $discountType, $discountValue, $validUntil ?: null, $id]);
                }
                $message = 'Kampanj uppdaterad';
            }
        }
    } elseif ($action === 'create_question') {
        $questionText = trim($_POST['question_text'] ?? '');
        $questionType = $_POST['question_type'] ?? 'checkbox';
        $options = trim($_POST['options'] ?? '');
        $sortOrder = (int)($_POST['sort_order'] ?? 0);
        $isRequired = isset($_POST['is_required']) ? 1 : 0;
        $campaignId = !empty($_POST['campaign_id']) ? (int)$_POST['campaign_id'] : null;

        if ($questionText) {
            $optionsJson = null;
            if ($questionType === 'checkbox' || $questionType === 'radio') {
                $optionsArray = array_filter(array_map('trim', explode("\n", $options)));
                $optionsJson = json_encode(array_values($optionsArray));
            }

            $stmt = $pdo->prepare("
                INSERT INTO winback_questions (campaign_id, question_text, question_type, options, sort_order, is_required, active)
                VALUES (?, ?, ?, ?, ?, ?, 1)
            ");
            $stmt->execute([$campaignId, $questionText, $questionType, $optionsJson, $sortOrder, $isRequired]);
            $message = 'Fraga skapad!';
        } else {
            $error = 'Fragetext kravs';
        }
    } elseif ($action === 'update_question') {
        $id = (int)$_POST['id'];
        $questionText = trim($_POST['question_text'] ?? '');
        $questionType = $_POST['question_type'] ?? 'checkbox';
        $options = trim($_POST['options'] ?? '');
        $sortOrder = (int)($_POST['sort_order'] ?? 0);
        $isRequired = isset($_POST['is_required']) ? 1 : 0;

        if ($questionText) {
            $optionsJson = null;
            if ($questionType === 'checkbox' || $questionType === 'radio') {
                $optionsArray = array_filter(array_map('trim', explode("\n", $options)));
                $optionsJson = json_encode(array_values($optionsArray));
            }

            $stmt = $pdo->prepare("
                UPDATE winback_questions
                SET question_text = ?, question_type = ?, options = ?, sort_order = ?, is_required = ?
                WHERE id = ?
            ");
            $stmt->execute([$questionText, $questionType, $optionsJson, $sortOrder, $isRequired, $id]);
            $message = 'Fraga uppdaterad!';
        } else {
            $error = 'Fragetext kravs';
        }
    } elseif ($action === 'toggle_question') {
        $id = (int)$_POST['id'];
        $pdo->prepare("UPDATE winback_questions SET active = NOT active WHERE id = ?")->execute([$id]);
        $message = 'Fragestatus uppdaterad';
    } elseif ($action === 'delete_question') {
        $id = (int)$_POST['id'];
        // Check if question has answers
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM winback_answers WHERE question_id = ?");
        $stmt->execute([$id]);
        if ($stmt->fetchColumn() > 0) {
            $error = 'Kan inte ta bort fraga som har svar. Inaktivera istallet.';
        } else {
            $pdo->prepare("DELETE FROM winback_questions WHERE id = ?")->execute([$id]);
            $message = 'Fraga borttagen';
        }
    } elseif ($action === 'send_invitations') {
        // Send invitations to selected riders
        $campaignId = (int)$_POST['campaign_id'];
        $riderIds = $_POST['rider_ids'] ?? [];

        if (empty($riderIds)) {
            $error = 'Valj minst en deltagare att bjuda in';
        } else {
            require_once __DIR__ . '/../includes/mail.php';

            // Get campaign info
            $stmt = $pdo->prepare("SELECT * FROM winback_campaigns WHERE id = ?");
            $stmt->execute([$campaignId]);
            $campaign = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$campaign) {
                $error = 'Kampanj hittades inte';
            } elseif (!canEditCampaign($campaign)) {
                $error = 'Du har inte behörighet att skicka inbjudningar för denna kampanj';
            } else {
                $sentCount = 0;
                $failedCount = 0;
                $skippedCount = 0;

                // Check if invitations table exists
                $invTableExists = false;
                try {
                    $check = $pdo->query("SHOW TABLES LIKE 'winback_invitations'");
                    $invTableExists = $check->rowCount() > 0;
                } catch (Exception $e) {}

                foreach ($riderIds as $riderId) {
                    $riderId = (int)$riderId;

                    // Get rider info
                    $stmt = $pdo->prepare("SELECT id, firstname, lastname, email FROM riders WHERE id = ?");
                    $stmt->execute([$riderId]);
                    $rider = $stmt->fetch(PDO::FETCH_ASSOC);

                    if (!$rider || empty($rider['email'])) {
                        $skippedCount++;
                        continue;
                    }

                    // Check if already invited (if table exists)
                    if ($invTableExists) {
                        $stmt = $pdo->prepare("SELECT id FROM winback_invitations WHERE campaign_id = ? AND rider_id = ?");
                        $stmt->execute([$campaignId, $riderId]);
                        if ($stmt->fetch()) {
                            $skippedCount++;
                            continue;
                        }
                    }

                    // Generate tracking token
                    $trackingToken = bin2hex(random_bytes(32));

                    // Build email
                    $surveyUrl = 'https://thehub.gravityseries.se/profile/winback-survey?t=' . $trackingToken;
                    $discountText = $campaign['discount_type'] === 'percentage'
                        ? intval($campaign['discount_value']) . '% rabatt'
                        : number_format($campaign['discount_value'], 0) . ' kr rabatt';

                    $subject = 'Vi saknar dig! - TheHUB';
                    $body = hub_email_template('winback_invitation', [
                        'name' => $rider['firstname'],
                        'campaign_name' => $campaign['name'],
                        'discount_text' => $discountText,
                        'survey_link' => $surveyUrl
                    ]);

                    // Send email
                    $sent = hub_send_email($rider['email'], $subject, $body);

                    // Log invitation (if table exists)
                    if ($invTableExists) {
                        $stmt = $pdo->prepare("
                            INSERT INTO winback_invitations (campaign_id, rider_id, email_address, invitation_method, invitation_status, sent_at, sent_by, tracking_token)
                            VALUES (?, ?, ?, 'email', ?, NOW(), ?, ?)
                        ");
                        $stmt->execute([
                            $campaignId,
                            $riderId,
                            $rider['email'],
                            $sent ? 'sent' : 'failed',
                            $_SESSION['admin_id'] ?? null,
                            $trackingToken
                        ]);
                    }

                    if ($sent) {
                        $sentCount++;
                    } else {
                        $failedCount++;
                    }
                }

                if ($sentCount > 0) {
                    $message = "Skickade $sentCount inbjudningar";
                    if ($skippedCount > 0) $message .= ", hoppade over $skippedCount (redan inbjudna eller saknar email)";
                    if ($failedCount > 0) $message .= ", $failedCount misslyckades";
                } else {
                    $error = "Inga inbjudningar skickades. $skippedCount hoppades over, $failedCount misslyckades.";
                }
            }
        }
    }
}

// Add winback invitation template to mail system (inline)
if (!function_exists('hub_email_template_winback')) {
    // Extend the email template function with winback template
    $GLOBALS['winback_email_templates'] = [
        'winback_invitation' => '
            <div class="header">
                <div class="logo">TheHUB</div>
            </div>
            <h1>Vi saknar dig!</h1>
            <p>Hej {{name}},</p>
            <p>Vi har markt att du inte tavlat pa ett tag och vill garna hora hur du mar och vad vi kan gora battre.</p>
            <p>Svara pa en kort enkat (tar bara 2 minuter) sa far du en <strong>{{discount_text}}</strong> pa din nasta anmalan som tack!</p>
            <p class="text-center">
                <a href="{{survey_link}}" class="btn">Svara pa enkaten</a>
            </p>
            <p class="note">Din feedback ar anonym och hjalper oss att skapa battre tavlingar.</p>
        '
    ];
}

// Get data
$campaigns = [];
$responses = [];
$brands = [];
$stats = [];
$promotors = []; // List of promotors for owner assignment

if ($tablesExist) {
    try {
        // Get campaigns with owner info
        $campaignsSql = "
            SELECT wc.*, au.full_name as owner_name, au.username as owner_username
            FROM winback_campaigns wc
            LEFT JOIN admin_users au ON wc.owner_user_id = au.id
            ORDER BY wc.is_active DESC, wc.id DESC
        ";
        $allCampaigns = $pdo->query($campaignsSql)->fetchAll(PDO::FETCH_ASSOC);

        // Filter campaigns based on user access
        $campaigns = [];
        foreach ($allCampaigns as $c) {
            if (canAccessCampaign($c)) {
                $campaigns[] = $c;
            }
        }

        // Get promotors list (for admin to assign ownership)
        if ($isAdmin) {
            $promotors = $pdo->query("
                SELECT id, username, full_name, email, role
                FROM admin_users
                WHERE active = 1 AND role IN ('promotor', 'admin', 'super_admin')
                ORDER BY role DESC, full_name ASC
            ")->fetchAll(PDO::FETCH_ASSOC);
        }
        $brands = $pdo->query("SELECT id, name, short_code FROM brands WHERE active = 1 ORDER BY display_order")->fetchAll(PDO::FETCH_ASSOC);

        // Get response stats per campaign
        foreach ($campaigns as &$c) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM winback_responses WHERE campaign_id = ?");
            $stmt->execute([$c['id']]);
            $c['response_count'] = (int)$stmt->fetchColumn();

            // Get potential audience size
            $brandIds = json_decode($c['brand_ids'] ?? '[]', true) ?: [];
            if (!empty($brandIds)) {
                $placeholders = implode(',', array_fill(0, count($brandIds), '?'));
                $stmt = $pdo->prepare("
                    SELECT COUNT(DISTINCT r.cyclist_id)
                    FROM results r
                    JOIN events e ON r.event_id = e.id
                    JOIN series s ON e.series_id = s.id
                    JOIN brand_series_map bsm ON s.id = bsm.series_id
                    WHERE YEAR(e.date) BETWEEN ? AND ?
                      AND bsm.brand_id IN ($placeholders)
                      AND r.cyclist_id NOT IN (
                          SELECT DISTINCT r2.cyclist_id
                          FROM results r2
                          JOIN events e2 ON r2.event_id = e2.id
                          JOIN series s2 ON e2.series_id = s2.id
                          JOIN brand_series_map bsm2 ON s2.id = bsm2.series_id
                          WHERE YEAR(e2.date) = ?
                            AND bsm2.brand_id IN ($placeholders)
                      )
                ");
                $params = array_merge([$c['start_year'], $c['end_year']], $brandIds, [$c['target_year']], $brandIds);
                $stmt->execute($params);
                $c['potential_audience'] = (int)$stmt->fetchColumn();
            } else {
                $c['potential_audience'] = 0;
            }
        }
        unset($c);

        // Overall stats
        $stats['total_responses'] = (int)$pdo->query("SELECT COUNT(*) FROM winback_responses")->fetchColumn();
        $stats['active_campaigns'] = count(array_filter($campaigns, fn($c) => $c['is_active']));
        $stats['total_questions'] = (int)$pdo->query("SELECT COUNT(*) FROM winback_questions WHERE active = 1")->fetchColumn();

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Get all questions for question management view
$allQuestions = [];
if ($tablesExist) {
    try {
        $allQuestions = $pdo->query("
            SELECT q.*, c.name as campaign_name
            FROM winback_questions q
            LEFT JOIN winback_campaigns c ON q.campaign_id = c.id
            ORDER BY q.sort_order ASC, q.id ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}

// View mode
$viewMode = $_GET['view'] ?? 'campaigns';
$selectedCampaign = isset($_GET['campaign']) ? (int)$_GET['campaign'] : null;

// Get responses for selected campaign
$campaignResponses = [];
$answerStats = [];

if ($selectedCampaign && $tablesExist) {
    try {
        $stmt = $pdo->prepare("
            SELECT wr.*, r.firstname, r.lastname, c.name as club_name
            FROM winback_responses wr
            JOIN riders r ON wr.rider_id = r.id
            LEFT JOIN clubs c ON r.club_id = c.id
            WHERE wr.campaign_id = ?
            ORDER BY wr.submitted_at DESC
        ");
        $stmt->execute([$selectedCampaign]);
        $campaignResponses = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get answer statistics (anonymized)
        $questions = $pdo->prepare("
            SELECT * FROM winback_questions
            WHERE active = 1 AND (campaign_id IS NULL OR campaign_id = ?)
            ORDER BY sort_order
        ");
        $questions->execute([$selectedCampaign]);
        $questions = $questions->fetchAll(PDO::FETCH_ASSOC);

        foreach ($questions as $q) {
            $answerStats[$q['id']] = [
                'question' => $q['question_text'],
                'type' => $q['question_type'],
                'answers' => []
            ];

            if ($q['question_type'] === 'scale') {
                // Get average and distribution
                $stmt = $pdo->prepare("
                    SELECT answer_scale, COUNT(*) as cnt
                    FROM winback_answers wa
                    JOIN winback_responses wr ON wa.response_id = wr.id
                    WHERE wa.question_id = ? AND wr.campaign_id = ?
                    GROUP BY answer_scale
                    ORDER BY answer_scale
                ");
                $stmt->execute([$q['id'], $selectedCampaign]);
                $answerStats[$q['id']]['distribution'] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

                $stmt = $pdo->prepare("
                    SELECT AVG(answer_scale)
                    FROM winback_answers wa
                    JOIN winback_responses wr ON wa.response_id = wr.id
                    WHERE wa.question_id = ? AND wr.campaign_id = ?
                ");
                $stmt->execute([$q['id'], $selectedCampaign]);
                $answerStats[$q['id']]['average'] = round($stmt->fetchColumn(), 1);

            } elseif ($q['question_type'] === 'checkbox') {
                // Count each option
                $stmt = $pdo->prepare("
                    SELECT answer_value
                    FROM winback_answers wa
                    JOIN winback_responses wr ON wa.response_id = wr.id
                    WHERE wa.question_id = ? AND wr.campaign_id = ?
                ");
                $stmt->execute([$q['id'], $selectedCampaign]);
                $allAnswers = $stmt->fetchAll(PDO::FETCH_COLUMN);

                $optionCounts = [];
                foreach ($allAnswers as $answerJson) {
                    $selected = json_decode($answerJson, true) ?: [];
                    foreach ($selected as $opt) {
                        $optionCounts[$opt] = ($optionCounts[$opt] ?? 0) + 1;
                    }
                }
                arsort($optionCounts);
                $answerStats[$q['id']]['options'] = $optionCounts;
            }
        }

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Page config
$page_title = 'Win-Back Kampanjer';
$breadcrumbs = [
    ['label' => 'Verktyg', 'url' => '/admin/tools.php'],
    ['label' => 'Win-Back Kampanjer']
];

include __DIR__ . '/components/unified-layout.php';
?>

<style>
.campaign-card {
    background: var(--color-bg-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    padding: var(--space-lg);
    margin-bottom: var(--space-md);
}
.campaign-card.inactive { opacity: 0.6; }
.campaign-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: var(--space-md);
}
.campaign-name {
    font-size: 1.125rem;
    font-weight: 600;
    color: var(--color-text-primary);
}
.campaign-meta {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-md);
    font-size: 0.875rem;
    color: var(--color-text-secondary);
    margin-bottom: var(--space-md);
}
.campaign-meta-item {
    display: flex;
    align-items: center;
    gap: var(--space-2xs);
}
.campaign-stats {
    display: flex;
    gap: var(--space-lg);
    padding-top: var(--space-md);
    border-top: 1px solid var(--color-border);
}
.campaign-stat {
    text-align: center;
}
.campaign-stat-value {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--color-accent);
}
.campaign-stat-label {
    font-size: 0.75rem;
    color: var(--color-text-muted);
}
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: var(--space-md);
    margin-bottom: var(--space-xl);
}
.stat-box {
    background: var(--color-bg-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    padding: var(--space-lg);
    text-align: center;
}
.stat-box.primary { border-left: 3px solid var(--color-accent); }
.stat-value {
    font-size: 2rem;
    font-weight: 700;
    color: var(--color-accent);
}
.stat-label {
    font-size: 0.875rem;
    color: var(--color-text-muted);
}
.answer-stat {
    background: var(--color-bg-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    padding: var(--space-lg);
    margin-bottom: var(--space-md);
}
.answer-stat-header {
    font-weight: 600;
    margin-bottom: var(--space-md);
    color: var(--color-text-primary);
}
.option-bar {
    display: flex;
    align-items: center;
    gap: var(--space-md);
    margin-bottom: var(--space-sm);
}
.option-label {
    flex: 0 0 200px;
    font-size: 0.875rem;
    color: var(--color-text-secondary);
}
.option-bar-fill {
    flex: 1;
    height: 24px;
    background: var(--color-bg-page);
    border-radius: var(--radius-sm);
    overflow: hidden;
}
.option-bar-inner {
    height: 100%;
    background: var(--color-accent);
    border-radius: var(--radius-sm);
    transition: width 0.3s ease;
}
.option-count {
    flex: 0 0 50px;
    text-align: right;
    font-weight: 600;
    color: var(--color-accent);
}
.scale-avg {
    font-size: 3rem;
    font-weight: 700;
    color: var(--color-accent);
    text-align: center;
}
.scale-avg-label {
    text-align: center;
    color: var(--color-text-muted);
    font-size: 0.875rem;
}
.tabs-nav {
    display: flex;
    gap: var(--space-xs);
    margin-bottom: var(--space-lg);
    border-bottom: 2px solid var(--color-border);
}
.tab-link {
    padding: var(--space-sm) var(--space-lg);
    color: var(--color-text-secondary);
    text-decoration: none;
    border-bottom: 2px solid transparent;
    margin-bottom: -2px;
    transition: all 0.15s;
}
.tab-link:hover { color: var(--color-text-primary); }
.tab-link.active {
    color: var(--color-accent);
    border-bottom-color: var(--color-accent);
}
</style>

<?php if (!$tablesExist): ?>
<div class="alert alert-warning">
    <i data-lucide="alert-triangle"></i>
    <div>
        <strong>Migrering kravs</strong><br>
        Kor migrering <code>014_winback_survey.sql</code> via
        <a href="/admin/migrations.php">Migrationsverktyget</a>.
    </div>
</div>
<?php else: ?>

<?php if ($message): ?>
    <div class="alert alert-success" style="margin-bottom: var(--space-md);"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger" style="margin-bottom: var(--space-md);"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<!-- Stats -->
<div class="stats-grid">
    <div class="stat-box primary">
        <div class="stat-value"><?= $stats['total_responses'] ?? 0 ?></div>
        <div class="stat-label">Totala svar</div>
    </div>
    <div class="stat-box">
        <div class="stat-value"><?= $stats['active_campaigns'] ?? 0 ?></div>
        <div class="stat-label">Aktiva kampanjer</div>
    </div>
    <div class="stat-box">
        <div class="stat-value"><?= $stats['total_questions'] ?? 0 ?></div>
        <div class="stat-label">Aktiva fragor</div>
    </div>
    <div class="stat-box">
        <div class="stat-value"><?= count($campaigns) ?></div>
        <div class="stat-label">Totalt kampanjer</div>
    </div>
</div>

<!-- Tabs -->
<nav class="tabs-nav">
    <a href="?view=campaigns" class="tab-link <?= $viewMode === 'campaigns' ? 'active' : '' ?>">
        <i data-lucide="megaphone" style="width:16px;height:16px;vertical-align:middle;margin-right:var(--space-xs);"></i>
        Kampanjer
    </a>
    <?php if ($isAdmin): ?>
    <a href="?view=questions" class="tab-link <?= $viewMode === 'questions' ? 'active' : '' ?>">
        <i data-lucide="help-circle" style="width:16px;height:16px;vertical-align:middle;margin-right:var(--space-xs);"></i>
        Fragor (<?= $stats['total_questions'] ?? 0 ?>)
    </a>
    <?php endif; ?>
    <?php if ($selectedCampaign): ?>
    <a href="?view=audience&campaign=<?= $selectedCampaign ?>" class="tab-link <?= $viewMode === 'audience' ? 'active' : '' ?>">
        <i data-lucide="users" style="width:16px;height:16px;vertical-align:middle;margin-right:var(--space-xs);"></i>
        Malgrupp
    </a>
    <a href="?view=results&campaign=<?= $selectedCampaign ?>" class="tab-link <?= $viewMode === 'results' ? 'active' : '' ?>">
        <i data-lucide="bar-chart-2" style="width:16px;height:16px;vertical-align:middle;margin-right:var(--space-xs);"></i>
        Resultat
    </a>
    <?php endif; ?>
</nav>

<?php if ($viewMode === 'audience' && $selectedCampaign): ?>
<!-- Audience View -->
<?php
$selectedCampData = null;
foreach ($campaigns as $c) {
    if ($c['id'] == $selectedCampaign) {
        $selectedCampData = $c;
        break;
    }
}

// Access check - redirect if no access to this campaign
if (!$selectedCampData || !canAccessCampaign($selectedCampData)) {
    echo '<div class="alert alert-danger">Du har inte behörighet att se denna kampanj.</div>';
} else {

$canEditThisCampaign = canEditCampaign($selectedCampData);

// Get target audience for this campaign
$audienceRiders = [];
$audienceStats = ['total' => 0, 'with_email' => 0, 'without_email' => 0, 'invited' => 0, 'responded' => 0];

if ($selectedCampData) {
    $brandIds = json_decode($selectedCampData['brand_ids'] ?? '[]', true) ?: [];

    if (!empty($brandIds)) {
        $placeholders = implode(',', array_fill(0, count($brandIds), '?'));

        // Get all riders who match the criteria
        $sql = "
            SELECT DISTINCT
                r.id, r.firstname, r.lastname, r.email,
                c.name as club_name,
                MAX(YEAR(e.date)) as last_active_year,
                COUNT(DISTINCT YEAR(e.date)) as total_seasons
            FROM riders r
            JOIN results res ON r.id = res.cyclist_id
            JOIN events e ON res.event_id = e.id
            JOIN series s ON e.series_id = s.id
            JOIN brand_series_map bsm ON s.id = bsm.series_id
            LEFT JOIN clubs c ON r.club_id = c.id
            WHERE YEAR(e.date) BETWEEN ? AND ?
              AND bsm.brand_id IN ($placeholders)
              AND r.id NOT IN (
                  SELECT DISTINCT res2.cyclist_id
                  FROM results res2
                  JOIN events e2 ON res2.event_id = e2.id
                  JOIN series s2 ON e2.series_id = s2.id
                  JOIN brand_series_map bsm2 ON s2.id = bsm2.series_id
                  WHERE YEAR(e2.date) = ?
                    AND bsm2.brand_id IN ($placeholders)
              )
            GROUP BY r.id
            ORDER BY last_active_year DESC, r.lastname, r.firstname
            LIMIT 500
        ";
        $params = array_merge(
            [$selectedCampData['start_year'], $selectedCampData['end_year']],
            $brandIds,
            [$selectedCampData['target_year']],
            $brandIds
        );
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $audienceRiders = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Calculate stats
        foreach ($audienceRiders as $rider) {
            $audienceStats['total']++;
            if (!empty($rider['email'])) {
                $audienceStats['with_email']++;
            } else {
                $audienceStats['without_email']++;
            }
        }

        // Check invited status (if table exists)
        $invTableExists = false;
        try {
            $check = $pdo->query("SHOW TABLES LIKE 'winback_invitations'");
            $invTableExists = $check->rowCount() > 0;
        } catch (Exception $e) {}

        if ($invTableExists) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM winback_invitations WHERE campaign_id = ?");
            $stmt->execute([$selectedCampaign]);
            $audienceStats['invited'] = (int)$stmt->fetchColumn();
        }

        // Check responded
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM winback_responses WHERE campaign_id = ?");
        $stmt->execute([$selectedCampaign]);
        $audienceStats['responded'] = (int)$stmt->fetchColumn();

        // Mark which riders are already invited/responded
        if ($invTableExists) {
            $invitedIds = [];
            $stmt = $pdo->prepare("SELECT rider_id FROM winback_invitations WHERE campaign_id = ?");
            $stmt->execute([$selectedCampaign]);
            while ($row = $stmt->fetch()) {
                $invitedIds[$row['rider_id']] = true;
            }
        }

        $respondedIds = [];
        $stmt = $pdo->prepare("SELECT rider_id FROM winback_responses WHERE campaign_id = ?");
        $stmt->execute([$selectedCampaign]);
        while ($row = $stmt->fetch()) {
            $respondedIds[$row['rider_id']] = true;
        }

        foreach ($audienceRiders as &$rider) {
            $rider['is_invited'] = isset($invitedIds[$rider['id']]);
            $rider['has_responded'] = isset($respondedIds[$rider['id']]);
        }
        unset($rider);
    }
}
?>

<div class="admin-card">
    <div class="admin-card-header">
        <h2>Malgrupp: <?= htmlspecialchars($selectedCampData['name'] ?? 'Okand') ?></h2>
    </div>
    <div class="admin-card-body">
        <!-- Contact Stats -->
        <div class="audience-stats">
            <div class="audience-stat">
                <div class="audience-stat-value"><?= number_format($audienceStats['total']) ?></div>
                <div class="audience-stat-label">Totalt i malgruppen</div>
            </div>
            <div class="audience-stat has-email">
                <div class="audience-stat-value"><?= number_format($audienceStats['with_email']) ?></div>
                <div class="audience-stat-label">Har email</div>
                <div class="audience-stat-pct"><?= $audienceStats['total'] > 0 ? round(($audienceStats['with_email'] / $audienceStats['total']) * 100) : 0 ?>%</div>
            </div>
            <div class="audience-stat no-email">
                <div class="audience-stat-value"><?= number_format($audienceStats['without_email']) ?></div>
                <div class="audience-stat-label">Saknar email</div>
                <div class="audience-stat-pct"><?= $audienceStats['total'] > 0 ? round(($audienceStats['without_email'] / $audienceStats['total']) * 100) : 0 ?>%</div>
            </div>
            <div class="audience-stat invited">
                <div class="audience-stat-value"><?= number_format($audienceStats['invited']) ?></div>
                <div class="audience-stat-label">Inbjudna</div>
            </div>
            <div class="audience-stat responded">
                <div class="audience-stat-value"><?= number_format($audienceStats['responded']) ?></div>
                <div class="audience-stat-label">Svarat</div>
            </div>
        </div>

        <?php if (empty($audienceRiders)): ?>
            <p style="text-align:center;color:var(--color-text-muted);padding:var(--space-2xl);">
                Ingen malgrupp hittades for denna kampanj.
            </p>
        <?php else: ?>
            <!-- Invitation Form -->
            <form method="POST" id="invitation-form">
                <input type="hidden" name="action" value="send_invitations">
                <input type="hidden" name="campaign_id" value="<?= $selectedCampaign ?>">

                <?php if ($canEditThisCampaign): ?>
                <div class="audience-actions">
                    <div class="audience-select-all">
                        <label style="display:flex;align-items:center;gap:var(--space-xs);cursor:pointer;">
                            <input type="checkbox" id="select-all-riders" onchange="toggleAllRiders(this.checked)">
                            <strong>Valj alla med email</strong>
                        </label>
                    </div>
                    <div class="audience-buttons">
                        <span id="selected-count">0 valda</span>
                        <button type="submit" class="btn-admin btn-admin-primary" id="send-btn" disabled>
                            <i data-lucide="send"></i> Skicka inbjudningar
                        </button>
                    </div>
                </div>
                <?php else: ?>
                <div class="alert alert-info" style="margin-bottom:var(--space-md);">
                    <i data-lucide="info"></i>
                    Du kan se malgruppen men endast kampanjens agare eller administratorer kan skicka inbjudningar.
                </div>
                <?php endif; ?>

                <div class="admin-table-container" style="max-height:500px;overflow-y:auto;">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <?php if ($canEditThisCampaign): ?><th style="width:40px;"></th><?php endif; ?>
                                <th>Namn</th>
                                <th>Email</th>
                                <th>Klubb</th>
                                <th>Senast aktiv</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($audienceRiders as $rider): ?>
                            <tr class="<?= $rider['has_responded'] ? 'responded-row' : ($rider['is_invited'] ? 'invited-row' : '') ?>">
                                <?php if ($canEditThisCampaign): ?>
                                <td>
                                    <?php if (!empty($rider['email']) && !$rider['has_responded'] && !$rider['is_invited']): ?>
                                    <input type="checkbox" name="rider_ids[]" value="<?= $rider['id'] ?>" class="rider-checkbox" onchange="updateSelectedCount()">
                                    <?php endif; ?>
                                </td>
                                <?php endif; ?>
                                <td>
                                    <a href="/rider/<?= $rider['id'] ?>" target="_blank">
                                        <?= htmlspecialchars($rider['firstname'] . ' ' . $rider['lastname']) ?>
                                    </a>
                                </td>
                                <td>
                                    <?php if (!empty($rider['email'])): ?>
                                        <span style="color:var(--color-success);"><?= htmlspecialchars($rider['email']) ?></span>
                                    <?php else: ?>
                                        <span style="color:var(--color-text-muted);">-</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($rider['club_name'] ?? '-') ?></td>
                                <td><?= $rider['last_active_year'] ?></td>
                                <td>
                                    <?php if ($rider['has_responded']): ?>
                                        <span class="badge badge-success">Svarat</span>
                                    <?php elseif ($rider['is_invited']): ?>
                                        <span class="badge badge-info">Inbjuden</span>
                                    <?php elseif (empty($rider['email'])): ?>
                                        <span class="badge badge-warning">Saknar email</span>
                                    <?php else: ?>
                                        <span class="badge badge-secondary">Ej kontaktad</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<style>
.audience-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
    gap: var(--space-md);
    margin-bottom: var(--space-xl);
}
.audience-stat {
    text-align: center;
    padding: var(--space-md);
    background: var(--color-bg-page);
    border-radius: var(--radius-md);
    border: 1px solid var(--color-border);
}
.audience-stat.has-email { border-color: var(--color-success); }
.audience-stat.no-email { border-color: var(--color-warning); }
.audience-stat.invited { border-color: var(--color-info); }
.audience-stat.responded { border-color: var(--color-accent); }
.audience-stat-value {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--color-text-primary);
}
.audience-stat-label {
    font-size: 0.75rem;
    color: var(--color-text-muted);
    text-transform: uppercase;
}
.audience-stat-pct {
    font-size: 0.875rem;
    color: var(--color-text-secondary);
    margin-top: var(--space-2xs);
}
.audience-actions {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: var(--space-md);
    background: var(--color-bg-page);
    border-radius: var(--radius-md);
    margin-bottom: var(--space-md);
}
.audience-buttons {
    display: flex;
    align-items: center;
    gap: var(--space-md);
}
#selected-count {
    color: var(--color-text-secondary);
    font-size: 0.875rem;
}
.invited-row { background: rgba(56, 189, 248, 0.05); }
.responded-row { background: rgba(16, 185, 129, 0.05); }
</style>

<script>
function toggleAllRiders(checked) {
    document.querySelectorAll('.rider-checkbox').forEach(cb => {
        cb.checked = checked;
    });
    updateSelectedCount();
}

function updateSelectedCount() {
    const count = document.querySelectorAll('.rider-checkbox:checked').length;
    document.getElementById('selected-count').textContent = count + ' valda';
    document.getElementById('send-btn').disabled = count === 0;
}

// Confirm before sending
document.getElementById('invitation-form')?.addEventListener('submit', function(e) {
    const count = document.querySelectorAll('.rider-checkbox:checked').length;
    if (!confirm('Skicka inbjudningar till ' + count + ' deltagare?')) {
        e.preventDefault();
    }
});
</script>

<?php } // End access check ?>

<?php elseif ($viewMode === 'questions'): ?>
<!-- Questions View (Admin only) -->
<?php if (!$isAdmin): ?>
<div class="alert alert-warning">
    <i data-lucide="lock"></i>
    Endast administratorer kan hantera fragor.
</div>
<?php else: ?>
<div class="admin-card" style="margin-bottom: var(--space-lg);">
    <div class="admin-card-header">
        <h2><i data-lucide="plus"></i> Lagg till fraga</h2>
    </div>
    <div class="admin-card-body">
        <form method="POST">
            <input type="hidden" name="action" value="create_question">

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:var(--space-md);margin-bottom:var(--space-md);">
                <div class="form-group" style="grid-column: span 2;">
                    <label class="form-label">Fragetext *</label>
                    <input type="text" name="question_text" class="form-input" required placeholder="T.ex. Vad skulle fa dig att tavla igen?">
                </div>
                <div class="form-group">
                    <label class="form-label">Fragetyp</label>
                    <select name="question_type" class="form-select" onchange="toggleOptionsField(this.value)">
                        <option value="checkbox">Flerval (checkbox)</option>
                        <option value="radio">Enkelval (radio)</option>
                        <option value="scale">Skala 1-10</option>
                        <option value="text">Fritext</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Sorteringsordning</label>
                    <input type="number" name="sort_order" class="form-input" value="<?= count($allQuestions) + 1 ?>" min="0">
                </div>
            </div>

            <div class="form-group" id="options-group" style="margin-bottom:var(--space-md);">
                <label class="form-label">Svarsalternativ (ett per rad)</label>
                <textarea name="options" class="form-textarea" rows="5" placeholder="Alternativ 1&#10;Alternativ 2&#10;Alternativ 3"></textarea>
                <small style="color:var(--color-text-muted);">Skriv ett alternativ per rad. Galler for flerval och enkelval.</small>
            </div>

            <div style="display:flex;gap:var(--space-lg);margin-bottom:var(--space-md);">
                <div class="form-group">
                    <label style="display:flex;align-items:center;gap:var(--space-xs);cursor:pointer;">
                        <input type="checkbox" name="is_required" value="1">
                        Obligatorisk fraga
                    </label>
                </div>
                <div class="form-group">
                    <label class="form-label">Kampanj (valfritt)</label>
                    <select name="campaign_id" class="form-select">
                        <option value="">Alla kampanjer (global)</option>
                        <?php foreach ($campaigns as $c): ?>
                        <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <button type="submit" class="btn-admin btn-admin-primary">
                <i data-lucide="plus"></i> Lagg till fraga
            </button>
        </form>
    </div>
</div>

<!-- Questions List -->
<div class="admin-card">
    <div class="admin-card-header">
        <h2>Befintliga fragor (<?= count($allQuestions) ?>)</h2>
    </div>
    <div class="admin-card-body" style="padding:0;">
        <?php if (empty($allQuestions)): ?>
            <p style="text-align:center;color:var(--color-text-muted);padding:var(--space-2xl);">
                Inga fragor skapade. Kor migrering 014 for att ladda standardfragor.
            </p>
        <?php else: ?>
            <div class="admin-table-container">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th style="width:40px;">#</th>
                            <th>Fraga</th>
                            <th>Typ</th>
                            <th>Kampanj</th>
                            <th>Status</th>
                            <th style="width:150px;">Atgarder</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($allQuestions as $q): ?>
                        <tr class="<?= $q['active'] ? '' : 'inactive-row' ?>">
                            <td><?= $q['sort_order'] ?></td>
                            <td>
                                <strong><?= htmlspecialchars($q['question_text']) ?></strong>
                                <?php if ($q['is_required']): ?>
                                    <span class="badge badge-warning" style="margin-left:var(--space-xs);">Obligatorisk</span>
                                <?php endif; ?>
                                <?php if ($q['options']): ?>
                                    <br><small style="color:var(--color-text-muted);">
                                        <?= count(json_decode($q['options'], true) ?: []) ?> alternativ
                                    </small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $typeLabels = [
                                    'checkbox' => 'Flerval',
                                    'radio' => 'Enkelval',
                                    'scale' => 'Skala 1-10',
                                    'text' => 'Fritext'
                                ];
                                echo $typeLabels[$q['question_type']] ?? $q['question_type'];
                                ?>
                            </td>
                            <td><?= $q['campaign_name'] ? htmlspecialchars($q['campaign_name']) : '<span style="color:var(--color-text-muted);">Alla</span>' ?></td>
                            <td>
                                <span class="badge <?= $q['active'] ? 'badge-success' : 'badge-secondary' ?>">
                                    <?= $q['active'] ? 'Aktiv' : 'Inaktiv' ?>
                                </span>
                            </td>
                            <td>
                                <div style="display:flex;gap:var(--space-xs);">
                                    <button type="button" class="btn-admin btn-admin-ghost btn-sm" onclick="editQuestion(<?= htmlspecialchars(json_encode($q)) ?>)">
                                        <i data-lucide="edit-2"></i>
                                    </button>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="action" value="toggle_question">
                                        <input type="hidden" name="id" value="<?= $q['id'] ?>">
                                        <button type="submit" class="btn-admin btn-admin-ghost btn-sm">
                                            <i data-lucide="<?= $q['active'] ? 'eye-off' : 'eye' ?>"></i>
                                        </button>
                                    </form>
                                    <?php if (!$q['active']): ?>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Ta bort denna fraga?');">
                                        <input type="hidden" name="action" value="delete_question">
                                        <input type="hidden" name="id" value="<?= $q['id'] ?>">
                                        <button type="submit" class="btn-admin btn-admin-ghost btn-sm" style="color:var(--color-error);">
                                            <i data-lucide="trash-2"></i>
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Edit Question Modal -->
<div id="edit-question-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:var(--color-bg-surface);border-radius:var(--radius-lg);padding:var(--space-xl);max-width:600px;width:90%;max-height:90vh;overflow-y:auto;">
        <h3 style="margin-bottom:var(--space-lg);">Redigera fraga</h3>
        <form method="POST" id="edit-question-form">
            <input type="hidden" name="action" value="update_question">
            <input type="hidden" name="id" id="edit-q-id">

            <div class="form-group" style="margin-bottom:var(--space-md);">
                <label class="form-label">Fragetext *</label>
                <input type="text" name="question_text" id="edit-q-text" class="form-input" required>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-md);margin-bottom:var(--space-md);">
                <div class="form-group">
                    <label class="form-label">Fragetyp</label>
                    <select name="question_type" id="edit-q-type" class="form-select">
                        <option value="checkbox">Flerval (checkbox)</option>
                        <option value="radio">Enkelval (radio)</option>
                        <option value="scale">Skala 1-10</option>
                        <option value="text">Fritext</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Sorteringsordning</label>
                    <input type="number" name="sort_order" id="edit-q-order" class="form-input" min="0">
                </div>
            </div>

            <div class="form-group" style="margin-bottom:var(--space-md);">
                <label class="form-label">Svarsalternativ (ett per rad)</label>
                <textarea name="options" id="edit-q-options" class="form-textarea" rows="5"></textarea>
            </div>

            <div class="form-group" style="margin-bottom:var(--space-lg);">
                <label style="display:flex;align-items:center;gap:var(--space-xs);cursor:pointer;">
                    <input type="checkbox" name="is_required" id="edit-q-required" value="1">
                    Obligatorisk fraga
                </label>
            </div>

            <div style="display:flex;gap:var(--space-md);justify-content:flex-end;">
                <button type="button" class="btn-admin btn-admin-secondary" onclick="closeEditModal()">Avbryt</button>
                <button type="submit" class="btn-admin btn-admin-primary">Spara</button>
            </div>
        </form>
    </div>
</div>

<script>
function toggleOptionsField(type) {
    const optionsGroup = document.getElementById('options-group');
    if (type === 'checkbox' || type === 'radio') {
        optionsGroup.style.display = 'block';
    } else {
        optionsGroup.style.display = 'none';
    }
}

function editQuestion(q) {
    document.getElementById('edit-q-id').value = q.id;
    document.getElementById('edit-q-text').value = q.question_text;
    document.getElementById('edit-q-type').value = q.question_type;
    document.getElementById('edit-q-order').value = q.sort_order;
    document.getElementById('edit-q-required').checked = q.is_required == 1;

    // Parse options
    if (q.options) {
        try {
            const opts = JSON.parse(q.options);
            document.getElementById('edit-q-options').value = opts.join('\n');
        } catch (e) {
            document.getElementById('edit-q-options').value = '';
        }
    } else {
        document.getElementById('edit-q-options').value = '';
    }

    document.getElementById('edit-question-modal').style.display = 'flex';
}

function closeEditModal() {
    document.getElementById('edit-question-modal').style.display = 'none';
}

// Close modal on outside click
document.getElementById('edit-question-modal').addEventListener('click', function(e) {
    if (e.target === this) closeEditModal();
});
</script>

<style>
.inactive-row { opacity: 0.5; }
.form-textarea {
    width: 100%;
    padding: var(--space-sm);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    background: var(--color-bg-page);
    color: var(--color-text-primary);
    font-family: inherit;
    resize: vertical;
}
</style>

<?php endif; // End admin check for questions ?>

<?php elseif ($viewMode === 'results' && $selectedCampaign): ?>
<!-- Results View -->
<?php
$selectedCampData = null;
foreach ($campaigns as $c) {
    if ($c['id'] == $selectedCampaign) {
        $selectedCampData = $c;
        break;
    }
}

// Access check
if (!$selectedCampData || !canAccessCampaign($selectedCampData)):
?>
<div class="alert alert-danger">Du har inte behörighet att se denna kampanj.</div>
<?php else: ?>

<div class="admin-card">
    <div class="admin-card-header">
        <h2>Resultat: <?= htmlspecialchars($selectedCampData['name'] ?? 'Okand') ?></h2>
        <span class="badge"><?= count($campaignResponses) ?> svar</span>
    </div>
    <div class="admin-card-body">
        <?php if (empty($campaignResponses)): ?>
            <p style="text-align:center;color:var(--color-text-muted);padding:var(--space-2xl);">
                Inga svar annu.
            </p>
        <?php else: ?>
            <!-- Answer Statistics (Anonymized) -->
            <h3 style="margin-bottom: var(--space-lg);">Svarsstatistik (anonymiserad)</h3>

            <?php foreach ($answerStats as $qId => $stat): ?>
                <div class="answer-stat">
                    <div class="answer-stat-header"><?= htmlspecialchars($stat['question']) ?></div>

                    <?php if ($stat['type'] === 'scale'): ?>
                        <div class="scale-avg"><?= $stat['average'] ?? '-' ?></div>
                        <div class="scale-avg-label">Genomsnitt (1-10)</div>

                    <?php elseif ($stat['type'] === 'checkbox' && !empty($stat['options'])): ?>
                        <?php
                        $maxCount = max($stat['options']) ?: 1;
                        foreach ($stat['options'] as $opt => $count):
                            $pct = ($count / $maxCount) * 100;
                        ?>
                        <div class="option-bar">
                            <div class="option-label"><?= htmlspecialchars($opt) ?></div>
                            <div class="option-bar-fill">
                                <div class="option-bar-inner" style="width: <?= $pct ?>%;"></div>
                            </div>
                            <div class="option-count"><?= $count ?></div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

            <!-- Response list (for admin tracking) -->
            <h3 style="margin: var(--space-xl) 0 var(--space-lg);">Svarande (<?= count($campaignResponses) ?>)</h3>
            <div class="admin-table-container">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Datum</th>
                            <th>Namn</th>
                            <th>Klubb</th>
                            <th>Rabattkod</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($campaignResponses as $resp): ?>
                        <tr>
                            <td><?= date('Y-m-d H:i', strtotime($resp['submitted_at'])) ?></td>
                            <td>
                                <a href="/rider/<?= $resp['rider_id'] ?>" target="_blank">
                                    <?= htmlspecialchars($resp['firstname'] . ' ' . $resp['lastname']) ?>
                                </a>
                            </td>
                            <td><?= htmlspecialchars($resp['club_name'] ?? '-') ?></td>
                            <td><code><?= htmlspecialchars($resp['discount_code']) ?></code></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php endif; // End results access check ?>

<?php else: ?>
<!-- Campaigns View -->

<!-- Create Campaign -->
<details class="admin-card" style="margin-bottom: var(--space-lg);">
    <summary style="cursor:pointer;padding:var(--space-md);font-weight:600;">
        <i data-lucide="plus"></i> Skapa ny kampanj
    </summary>
    <div class="admin-card-body">
        <form method="POST">
            <input type="hidden" name="action" value="create_campaign">

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:var(--space-md);margin-bottom:var(--space-md);">
                <div class="form-group">
                    <label class="form-label">Kampanjnamn *</label>
                    <input type="text" name="name" class="form-input" required placeholder="T.ex. GravitySeries Comeback">
                </div>
                <div class="form-group">
                    <label class="form-label">Rabatttyp</label>
                    <select name="discount_type" class="form-select">
                        <option value="fixed">Fast belopp (SEK)</option>
                        <option value="percentage">Procent (%)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Rabattvarde</label>
                    <input type="number" name="discount_value" class="form-input" value="100" min="1">
                </div>
                <div class="form-group">
                    <label class="form-label">Giltig t.o.m.</label>
                    <input type="date" name="valid_until" class="form-input" value="2026-12-31">
                </div>
            </div>

            <div class="form-group" style="margin-bottom:var(--space-md);">
                <label class="form-label">Varumarken *</label>
                <div style="display:flex;flex-wrap:wrap;gap:var(--space-sm);">
                    <?php foreach ($brands as $b): ?>
                    <label style="display:flex;align-items:center;gap:var(--space-xs);padding:var(--space-sm);background:var(--color-bg-page);border-radius:var(--radius-sm);cursor:pointer;">
                        <input type="checkbox" name="brand_ids[]" value="<?= $b['id'] ?>">
                        <?= htmlspecialchars($b['name']) ?> (<?= htmlspecialchars($b['short_code']) ?>)
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php if ($isAdmin && !empty($promotors)): ?>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:var(--space-md);margin-bottom:var(--space-md);padding-top:var(--space-md);border-top:1px solid var(--color-border);">
                <div class="form-group">
                    <label class="form-label">Agare (promotor)</label>
                    <select name="owner_user_id" class="form-select">
                        <option value="">Valj agare...</option>
                        <?php foreach ($promotors as $p): ?>
                        <option value="<?= $p['id'] ?>" <?= $p['id'] == $currentUserId ? 'selected' : '' ?>>
                            <?= htmlspecialchars($p['full_name'] ?: $p['username']) ?>
                            (<?= $p['role'] ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <small style="color:var(--color-text-muted);">Agaren kan hantera kampanjen och se resultat</small>
                </div>
                <div class="form-group" style="display:flex;align-items:center;">
                    <label style="display:flex;align-items:center;gap:var(--space-xs);cursor:pointer;">
                        <input type="checkbox" name="allow_promotor_access" value="1">
                        Tillat alla promotors att se resultat
                    </label>
                </div>
            </div>
            <?php endif; ?>

            <button type="submit" class="btn-admin btn-admin-primary">
                <i data-lucide="plus"></i> Skapa kampanj
            </button>
        </form>
    </div>
</details>

<!-- Campaign List -->
<h3 style="margin-bottom: var(--space-md);">Kampanjer (<?= count($campaigns) ?>)</h3>

<?php if (empty($campaigns)): ?>
    <div class="admin-card">
        <div class="admin-card-body" style="text-align:center;padding:var(--space-2xl);">
            <i data-lucide="megaphone" style="width:48px;height:48px;color:var(--color-text-muted);"></i>
            <p style="margin-top:var(--space-md);color:var(--color-text-muted);">
                Inga kampanjer skapade. Skapa din forsta ovan eller kor migrering 014.
            </p>
        </div>
    </div>
<?php else: ?>
    <?php foreach ($campaigns as $c): ?>
        <?php
        $brandIds = json_decode($c['brand_ids'] ?? '[]', true) ?: [];
        $canEdit = canEditCampaign($c);
        $ownerName = $c['owner_name'] ?: $c['owner_username'] ?: null;
        ?>
        <div class="campaign-card <?= $c['is_active'] ? '' : 'inactive' ?>">
            <div class="campaign-header">
                <div>
                    <div class="campaign-name"><?= htmlspecialchars($c['name']) ?></div>
                    <div style="margin-top:var(--space-xs);display:flex;gap:var(--space-xs);flex-wrap:wrap;">
                        <span class="badge <?= $c['is_active'] ? 'badge-success' : 'badge-secondary' ?>">
                            <?= $c['is_active'] ? 'Aktiv' : 'Inaktiv' ?>
                        </span>
                        <?php if ($ownerName): ?>
                        <span class="badge badge-info" title="Kampanjagare">
                            <i data-lucide="user" style="width:12px;height:12px;"></i>
                            <?= htmlspecialchars($ownerName) ?>
                        </span>
                        <?php endif; ?>
                        <?php if ($c['allow_promotor_access']): ?>
                        <span class="badge badge-warning" title="Synlig for alla promotors">
                            <i data-lucide="users" style="width:12px;height:12px;"></i>
                        </span>
                        <?php endif; ?>
                    </div>
                </div>
                <div style="display:flex;gap:var(--space-xs);">
                    <a href="?view=audience&campaign=<?= $c['id'] ?>" class="btn-admin btn-admin-secondary btn-sm">
                        <i data-lucide="users"></i> Malgrupp
                    </a>
                    <a href="?view=results&campaign=<?= $c['id'] ?>" class="btn-admin btn-admin-secondary btn-sm">
                        <i data-lucide="bar-chart-2"></i> Resultat
                    </a>
                    <?php if ($canEdit): ?>
                    <button type="button" class="btn-admin btn-admin-ghost btn-sm" onclick="editCampaign(<?= htmlspecialchars(json_encode($c)) ?>)" title="Redigera kampanj">
                        <i data-lucide="edit-2"></i>
                    </button>
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="action" value="toggle_campaign">
                        <input type="hidden" name="id" value="<?= $c['id'] ?>">
                        <button type="submit" class="btn-admin btn-admin-ghost btn-sm" title="<?= $c['is_active'] ? 'Pausa' : 'Aktivera' ?>">
                            <i data-lucide="<?= $c['is_active'] ? 'pause' : 'play' ?>"></i>
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>

            <div class="campaign-meta">
                <span class="campaign-meta-item">
                    <i data-lucide="calendar"></i>
                    Malgrupp: Tavlade <?= $c['start_year'] ?>-<?= $c['end_year'] ?>, ej <?= $c['target_year'] ?>
                </span>
                <span class="campaign-meta-item">
                    <i data-lucide="tag"></i>
                    Varumarken:
                    <?php
                    $brandNames = [];
                    foreach ($brands as $b) {
                        if (in_array($b['id'], $brandIds)) {
                            $brandNames[] = $b['short_code'];
                        }
                    }
                    echo implode(', ', $brandNames) ?: 'Alla';
                    ?>
                </span>
                <span class="campaign-meta-item">
                    <i data-lucide="gift"></i>
                    <?= $c['discount_type'] === 'percentage' ? intval($c['discount_value']) . '%' : number_format($c['discount_value'], 0) . ' kr' ?> rabatt
                </span>
            </div>

            <div class="campaign-stats">
                <div class="campaign-stat">
                    <div class="campaign-stat-value"><?= $c['response_count'] ?></div>
                    <div class="campaign-stat-label">Svar</div>
                </div>
                <div class="campaign-stat">
                    <div class="campaign-stat-value"><?= number_format($c['potential_audience']) ?></div>
                    <div class="campaign-stat-label">Potentiell malgrupp</div>
                </div>
                <?php if ($c['potential_audience'] > 0): ?>
                <div class="campaign-stat">
                    <div class="campaign-stat-value"><?= round(($c['response_count'] / $c['potential_audience']) * 100, 1) ?>%</div>
                    <div class="campaign-stat-label">Svarsfrekvens</div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php endif; // viewMode ?>

<!-- Edit Campaign Modal -->
<div id="edit-campaign-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:var(--color-bg-surface);border-radius:var(--radius-lg);padding:var(--space-xl);max-width:500px;width:90%;max-height:90vh;overflow-y:auto;">
        <h3 style="margin-bottom:var(--space-lg);">
            <i data-lucide="edit-2" style="width:20px;height:20px;vertical-align:middle;margin-right:var(--space-xs);"></i>
            Redigera kampanj
        </h3>
        <form method="POST" id="edit-campaign-form">
            <input type="hidden" name="action" value="update_campaign">
            <input type="hidden" name="id" id="edit-campaign-id">

            <div class="form-group" style="margin-bottom:var(--space-md);">
                <label class="form-label">Kampanjnamn *</label>
                <input type="text" name="name" id="edit-campaign-name" class="form-input" required>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-md);margin-bottom:var(--space-md);">
                <div class="form-group">
                    <label class="form-label">Rabatttyp</label>
                    <select name="discount_type" id="edit-discount-type" class="form-select">
                        <option value="fixed">Fast belopp (SEK)</option>
                        <option value="percentage">Procent (%)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Rabattvarde</label>
                    <input type="number" name="discount_value" id="edit-discount-value" class="form-input" min="1">
                </div>
            </div>

            <div class="form-group" style="margin-bottom:var(--space-md);">
                <label class="form-label">Giltig t.o.m.</label>
                <input type="date" name="valid_until" id="edit-valid-until" class="form-input">
            </div>

            <?php if ($isAdmin && !empty($promotors)): ?>
            <div style="padding-top:var(--space-md);border-top:1px solid var(--color-border);margin-bottom:var(--space-md);">
                <div class="form-group" style="margin-bottom:var(--space-md);">
                    <label class="form-label">Agare (promotor)</label>
                    <select name="owner_user_id" id="edit-owner-id" class="form-select">
                        <option value="">Ingen agare</option>
                        <?php foreach ($promotors as $p): ?>
                        <option value="<?= $p['id'] ?>">
                            <?= htmlspecialchars($p['full_name'] ?: $p['username']) ?>
                            (<?= $p['role'] ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <small style="color:var(--color-text-muted);">Agaren kan hantera kampanjen, se malgrupp och skicka inbjudningar</small>
                </div>

                <div class="form-group" style="margin-bottom:var(--space-md);">
                    <label style="display:flex;align-items:center;gap:var(--space-xs);cursor:pointer;">
                        <input type="checkbox" name="allow_promotor_access" id="edit-promotor-access" value="1">
                        Tillat alla promotors att se resultat
                    </label>
                    <small style="color:var(--color-text-muted);display:block;margin-top:var(--space-xs);">
                        Om aktiverad kan alla inloggade promotors se svar och statistik for denna kampanj
                    </small>
                </div>
            </div>
            <?php endif; ?>

            <div style="display:flex;gap:var(--space-md);justify-content:flex-end;">
                <button type="button" class="btn-admin btn-admin-secondary" onclick="closeEditCampaignModal()">Avbryt</button>
                <button type="submit" class="btn-admin btn-admin-primary">
                    <i data-lucide="save"></i> Spara
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function editCampaign(campaign) {
    document.getElementById('edit-campaign-id').value = campaign.id;
    document.getElementById('edit-campaign-name').value = campaign.name;
    document.getElementById('edit-discount-type').value = campaign.discount_type || 'fixed';
    document.getElementById('edit-discount-value').value = campaign.discount_value || 100;
    document.getElementById('edit-valid-until').value = campaign.discount_valid_until ? campaign.discount_valid_until.split(' ')[0] : '';

    <?php if ($isAdmin && !empty($promotors)): ?>
    document.getElementById('edit-owner-id').value = campaign.owner_user_id || '';
    document.getElementById('edit-promotor-access').checked = campaign.allow_promotor_access == 1;
    <?php endif; ?>

    document.getElementById('edit-campaign-modal').style.display = 'flex';
}

function closeEditCampaignModal() {
    document.getElementById('edit-campaign-modal').style.display = 'none';
}

// Close modal on outside click
document.getElementById('edit-campaign-modal')?.addEventListener('click', function(e) {
    if (e.target === this) closeEditCampaignModal();
});
</script>

<?php endif; // tablesExist ?>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>

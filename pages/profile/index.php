<?php
/**
 * TheHUB V1.0 - Min Sida (Profile)
 * Shows user profile, children, registrations, etc.
 */

// Include rider auth functions for multi-profile support
require_once dirname(dirname(__DIR__)) . '/includes/rider-auth.php';

$currentUser = hub_current_user();

if (!$currentUser) {
    header('Location: /profile/login');
    exit;
}

// Debug mode for troubleshooting multi-profile feature
if (isset($_GET['debug_profiles']) && isset($_SESSION['admin_logged_in'])) {
    $db = getDB();
    $debugInfo = [
        'session' => [
            'admin_id' => $_SESSION['admin_id'] ?? 'not set',
            'admin_email' => $_SESSION['admin_email'] ?? 'not set',
            'rider_id' => $_SESSION['rider_id'] ?? 'not set',
            'rider_email' => $_SESSION['rider_email'] ?? 'not set',
            'hub_user_id' => $_SESSION['hub_user_id'] ?? 'not set',
            'hub_user_email' => $_SESSION['hub_user_email'] ?? 'not set',
            'rider_profile_count' => $_SESSION['rider_profile_count'] ?? 'not set',
        ],
        'hub_current_user' => $currentUser,
    ];

    // Try to look up admin email from admin_users
    if (isset($_SESSION['admin_id']) && $_SESSION['admin_id'] > 0) {
        $adminUser = $db->getRow("SELECT id, email, username FROM admin_users WHERE id = ?", [$_SESSION['admin_id']]);
        $debugInfo['admin_users_lookup'] = $adminUser ?: 'not found';
    }

    // Find riders with same email
    $email = $_SESSION['admin_email'] ?? $_SESSION['rider_email'] ?? $currentUser['email'] ?? null;
    if ($email) {
        $riders = $db->getAll("SELECT id, firstname, lastname, email FROM riders WHERE email = ? AND active = 1", [$email]);
        $debugInfo['riders_with_email'] = $riders;
    }

    // Get linked profiles result
    $debugInfo['get_rider_linked_profiles_result'] = get_rider_linked_profiles();
    $debugInfo['get_rider_profile_count_result'] = get_rider_profile_count();

    header('Content-Type: application/json');
    echo json_encode($debugInfo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = hub_db();

// Include avatar helper functions
$avatarHelperPath = dirname(dirname(__DIR__)) . '/includes/get-avatar.php';
if (file_exists($avatarHelperPath)) {
    require_once $avatarHelperPath;
}

// Get linked children
$linkedChildren = hub_get_linked_children($currentUser['id']);

// Get admin clubs
$adminClubs = hub_get_admin_clubs($currentUser['id']);

// Get upcoming registrations (if table exists)
// Match by rider_id (direct) OR via orders placed by this user (rider_id/email)
$upcomingRegs = [];
$buyerEmail = $currentUser['email'] ?? '';
try {
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'event_registrations'");
    if ($tableCheck->rowCount() > 0) {
        $regStmt = $pdo->prepare("
            SELECT r.*, e.name as event_name, e.date as event_date, e.location,
                   COALESCE(cls.display_name, r.category) as class_name,
                   ri.firstname, ri.lastname
            FROM event_registrations r
            JOIN events e ON r.event_id = e.id
            LEFT JOIN classes cls ON r.class_id = cls.id
            LEFT JOIN orders o ON r.order_id = o.id
            LEFT JOIN riders ri ON r.rider_id = ri.id
            WHERE (r.rider_id = ? OR o.rider_id = ? OR (o.customer_email = ? AND o.customer_email != ''))
            AND e.date >= CURDATE() AND r.status != 'cancelled'
            GROUP BY r.id
            ORDER BY e.date ASC
            LIMIT 10
        ");
        $regStmt->execute([$currentUser['id'], $currentUser['id'], $buyerEmail]);
        $upcomingRegs = $regStmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    $upcomingRegs = [];
}

// Get recent results
$resultStmt = $pdo->prepare("
    SELECT res.*, e.name as event_name, e.date as event_date,
           cls.display_name as class_name
    FROM results res
    JOIN events e ON res.event_id = e.id
    LEFT JOIN classes cls ON res.class_id = cls.id
    WHERE res.cyclist_id = ?
    ORDER BY e.date DESC
    LIMIT 5
");
$resultStmt->execute([$currentUser['id']]);
$recentResults = $resultStmt->fetchAll(PDO::FETCH_ASSOC);

// Check for pending winback campaigns
$pendingWinbackCount = 0;
try {
    $winbackCheck = $pdo->query("SHOW TABLES LIKE 'winback_campaigns'");
    if ($winbackCheck->rowCount() > 0) {
        // Get active campaigns
        $campStmt = $pdo->query("SELECT * FROM winback_campaigns WHERE is_active = 1");
        $winbackCampaigns = $campStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($winbackCampaigns as $c) {
            $brandIds = json_decode($c['brand_ids'] ?? '[]', true) ?: [];
            $audienceType = $c['audience_type'] ?? 'churned';
            $placeholders = !empty($brandIds) ? implode(',', array_fill(0, count($brandIds), '?')) : '0';

            // Check if already responded
            $respCheck = $pdo->prepare("SELECT id FROM winback_responses WHERE campaign_id = ? AND rider_id = ?");
            $respCheck->execute([$c['id'], $currentUser['id']]);
            if ($respCheck->fetch()) continue;

            // Check if user qualifies based on audience type
            $qualifies = false;

            if ($audienceType === 'churned') {
                // Churned: competed in start-end years but NOT in target year
                $sql = "SELECT COUNT(DISTINCT e.id) FROM results r
                        JOIN events e ON r.event_id = e.id
                        JOIN series s ON e.series_id = s.id
                        WHERE r.cyclist_id = ? AND YEAR(e.date) BETWEEN ? AND ?" .
                        (!empty($brandIds) ? " AND s.brand_id IN ($placeholders)" : "");
                $params = array_merge([$currentUser['id'], $c['start_year'], $c['end_year']], $brandIds);
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $historicalCount = (int)$stmt->fetchColumn();

                $sql2 = "SELECT COUNT(*) FROM results r
                         JOIN events e ON r.event_id = e.id
                         JOIN series s ON e.series_id = s.id
                         WHERE r.cyclist_id = ? AND YEAR(e.date) = ?" .
                         (!empty($brandIds) ? " AND s.brand_id IN ($placeholders)" : "");
                $params2 = array_merge([$currentUser['id'], $c['target_year']], $brandIds);
                $stmt2 = $pdo->prepare($sql2);
                $stmt2->execute($params2);
                $targetCount = (int)$stmt2->fetchColumn();

                $qualifies = ($historicalCount > 0 && $targetCount == 0);
            } elseif ($audienceType === 'active') {
                // Active: competed in target year
                $sql = "SELECT COUNT(*) FROM results r
                        JOIN events e ON r.event_id = e.id
                        JOIN series s ON e.series_id = s.id
                        WHERE r.cyclist_id = ? AND YEAR(e.date) = ?" .
                        (!empty($brandIds) ? " AND s.brand_id IN ($placeholders)" : "");
                $params = array_merge([$currentUser['id'], $c['target_year']], $brandIds);
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $qualifies = ((int)$stmt->fetchColumn() > 0);
            } elseif ($audienceType === 'one_timer') {
                // One-timer: competed exactly once in target year
                $sql = "SELECT COUNT(DISTINCT e.id) FROM results r
                        JOIN events e ON r.event_id = e.id
                        JOIN series s ON e.series_id = s.id
                        WHERE r.cyclist_id = ? AND YEAR(e.date) = ?" .
                        (!empty($brandIds) ? " AND s.brand_id IN ($placeholders)" : "");
                $params = array_merge([$currentUser['id'], $c['target_year']], $brandIds);
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $qualifies = ((int)$stmt->fetchColumn() == 1);
            }

            if ($qualifies) $pendingWinbackCount++;
        }
    }
} catch (PDOException $e) {
    $pendingWinbackCount = 0;
}
?>

<div class="page-header">
    <h1 class="page-title">
        <i data-lucide="user" class="page-icon"></i>
        Min Sida
    </h1>
</div>

<!-- Profile Card -->
<div class="profile-card">
    <div class="profile-avatar">
        <?php
        // Check for avatar image
        $avatarUrl = $currentUser['avatar_url'] ?? null;
        $initials = function_exists('get_rider_initials')
            ? get_rider_initials($currentUser)
            : strtoupper(substr($currentUser['firstname'] ?? '', 0, 1) . substr($currentUser['lastname'] ?? '', 0, 1));

        if ($avatarUrl):
        ?>
            <img src="<?= htmlspecialchars($avatarUrl) ?>"
                 alt="Din profilbild"
                 class="profile-avatar-image"
                 onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
            <span class="profile-avatar-fallback" style="display: none;"><?= htmlspecialchars($initials) ?></span>
        <?php elseif (function_exists('get_rider_avatar')): ?>
            <img src="<?= htmlspecialchars(get_rider_avatar($currentUser, 80)) ?>"
                 alt="Din profilbild"
                 class="profile-avatar-image">
        <?php else: ?>
            <?= htmlspecialchars($initials) ?>
        <?php endif; ?>
    </div>
    <div class="profile-info">
        <h2 class="profile-name"><?= htmlspecialchars($currentUser['firstname'] . ' ' . $currentUser['lastname']) ?></h2>
        <?php if ($currentUser['email']): ?>
            <p class="profile-email"><?= htmlspecialchars($currentUser['email']) ?></p>
        <?php endif; ?>
        <?php if ($currentUser['club_id']): ?>
            <?php
            $clubStmt = $pdo->prepare("SELECT name FROM clubs WHERE id = ?");
            $clubStmt->execute([$currentUser['club_id']]);
            $clubName = $clubStmt->fetchColumn();
            ?>
            <p class="profile-club"><i data-lucide="shield" class="icon-xs align-middle"></i> <?= htmlspecialchars($clubName) ?></p>
        <?php endif; ?>
    </div>
    <a href="/profile/edit" class="btn btn-outline">Redigera profil</a>
</div>

<!-- Quick Links -->
<div class="quick-links">
    <a href="/profile/receipts" class="quick-link">
        <span class="quick-link-icon"><i data-lucide="shopping-bag"></i></span>
        <span class="quick-link-label">Mina köp</span>
        <span class="quick-link-arrow">›</span>
    </a>
    <a href="/rider/<?= $currentUser['id'] ?>" class="quick-link">
        <span class="quick-link-icon"><i data-lucide="flag"></i></span>
        <span class="quick-link-label">Mina resultat</span>
        <span class="quick-link-arrow">›</span>
    </a>
    <a href="/profile/race-reports" class="quick-link">
        <span class="quick-link-icon"><i data-lucide="file-text"></i></span>
        <span class="quick-link-label">Mina Race Reports</span>
        <span class="quick-link-arrow">›</span>
    </a>
    <a href="/profile/event-ratings" class="quick-link">
        <span class="quick-link-icon"><i data-lucide="star"></i></span>
        <span class="quick-link-label">Betygsatt Events</span>
        <span class="quick-link-arrow">›</span>
    </a>
    <?php
    // Get profile count for "Mina profiler" link
    $profileCount = function_exists('get_rider_profile_count') ? get_rider_profile_count() : 1;
    if ($profileCount > 1):
    ?>
    <a href="/profile/profiles" class="quick-link quick-link--highlight">
        <span class="quick-link-icon"><i data-lucide="users"></i></span>
        <span class="quick-link-label">Mina profiler (<?= $profileCount ?>)</span>
        <span class="quick-link-arrow">›</span>
    </a>
    <?php endif; ?>
    <?php if ($pendingWinbackCount > 0): ?>
    <a href="/profile/winback" class="quick-link" style="background: linear-gradient(135deg, var(--color-accent-light), transparent); border-color: var(--color-accent);">
        <span class="quick-link-icon"><i data-lucide="arrow-right-circle"></i></span>
        <span class="quick-link-label">Back to Gravity</span>
        <?php if ($pendingWinbackCount > 1): ?>
            <span class="quick-link-badge" style="background:var(--color-accent);color:#000;padding:2px 8px;border-radius:12px;font-size:0.75rem;"><?= $pendingWinbackCount ?></span>
        <?php endif; ?>
        <span class="quick-link-arrow">›</span>
    </a>
    <?php endif; ?>
    <?php if (!empty($linkedChildren)): ?>
        <a href="/profile/children" class="quick-link">
            <span class="quick-link-icon"><i data-lucide="users"></i></span>
            <span class="quick-link-label">Kopplade barn (<?= count($linkedChildren) ?>)</span>
            <span class="quick-link-arrow">›</span>
        </a>
    <?php endif; ?>
    <?php if (!empty($adminClubs)): ?>
        <a href="/profile/club-admin" class="quick-link">
            <span class="quick-link-icon"><i data-lucide="settings"></i></span>
            <span class="quick-link-label">Klubb-admin</span>
            <span class="quick-link-arrow">›</span>
        </a>
    <?php endif; ?>
</div>

<!-- Upcoming Registrations -->
<?php if (!empty($upcomingRegs)): ?>
    <div class="section">
        <div class="section-header">
            <h2>Kommande tävlingar</h2>
            <a href="/profile/receipts" class="section-link">Visa alla</a>
        </div>
        <div class="upcoming-list">
            <?php foreach ($upcomingRegs as $reg):
                $regRiderName = trim(($reg['firstname'] ?? $reg['first_name'] ?? '') . ' ' . ($reg['lastname'] ?? $reg['last_name'] ?? ''));
                $isOtherRider = ($reg['rider_id'] != $currentUser['id']);
            ?>
                <a href="/calendar/<?= $reg['event_id'] ?>" class="upcoming-item">
                    <div class="upcoming-date">
                        <span class="upcoming-day"><?= date('j', strtotime($reg['event_date'])) ?></span>
                        <span class="upcoming-month"><?= hub_month_short($reg['event_date']) ?></span>
                    </div>
                    <div class="upcoming-info">
                        <span class="upcoming-name"><?= htmlspecialchars($reg['event_name']) ?></span>
                        <span class="upcoming-class">
                            <?php if ($isOtherRider && $regRiderName): ?>
                                <?= htmlspecialchars($regRiderName) ?> &bull;
                            <?php endif; ?>
                            <?= htmlspecialchars($reg['class_name'] ?? '') ?>
                        </span>
                    </div>
                    <span class="upcoming-status status-<?= $reg['status'] ?>">
                        <?php if ($reg['status'] === 'confirmed'): ?>
                            <i data-lucide="check" style="width:16px;height:16px;"></i>
                        <?php else: ?>
                            <i data-lucide="clock" style="width:16px;height:16px;"></i>
                        <?php endif; ?>
                    </span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<!-- Recent Results -->
<?php if (!empty($recentResults)): ?>
    <div class="section">
        <div class="section-header">
            <h2>Senaste resultat</h2>
            <a href="/rider/<?= $currentUser['id'] ?>" class="section-link">Visa alla</a>
        </div>
        <div class="results-list">
            <?php foreach ($recentResults as $result): ?>
                <a href="/results/<?= $result['event_id'] ?>" class="result-item">
                    <div class="result-position">
                        <?php if ($result['position'] == 1): ?>
                            <img src="/assets/icons/medal-1st.svg" alt="1:a" class="medal-icon">
                        <?php elseif ($result['position'] == 2): ?>
                            <img src="/assets/icons/medal-2nd.svg" alt="2:a" class="medal-icon">
                        <?php elseif ($result['position'] == 3): ?>
                            <img src="/assets/icons/medal-3rd.svg" alt="3:e" class="medal-icon">
                        <?php else: ?>
                            #<?= $result['position'] ?>
                        <?php endif; ?>
                    </div>
                    <div class="result-info">
                        <span class="result-event"><?= htmlspecialchars($result['event_name']) ?></span>
                        <span class="result-class"><?= htmlspecialchars($result['class_name'] ?? '') ?></span>
                    </div>
                    <span class="result-date"><?= date('j M', strtotime($result['event_date'])) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<!-- Theme Settings - DISABLED: Always light theme -->
<!-- Theme selector removed to prevent issues -->

<!-- Logout -->
<div class="logout-section">
    <a href="/logout" class="btn btn-outline btn-danger">Logga ut</a>
</div>


<!-- Avatar image styles -->
<style>
.profile-avatar-image {
    width: 100%;
    height: 100%;
    object-fit: cover;
    border-radius: 50%;
}
.profile-avatar-fallback {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    height: 100%;
}
</style>

<!-- CSS loaded from /assets/css/pages/profile-index.css -->

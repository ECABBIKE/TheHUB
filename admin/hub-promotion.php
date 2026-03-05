<?php
/**
 * TheHUB Promotion - Targeted Email Campaigns
 * Create and send targeted email campaigns filtered by age, gender, and region.
 *
 * Accessible by: admin, super_admin
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

// Only admin/super_admin
if (!hasRole('admin')) {
    header('Location: /admin/dashboard.php');
    exit;
}

global $pdo;

$currentUser = getCurrentAdmin();
$message = '';
$error = '';

// Check if tables exist
$tablesExist = false;
try {
    $check = $pdo->query("SHOW TABLES LIKE 'promotion_campaigns'");
    $tablesExist = $check->rowCount() > 0;
} catch (Exception $e) {}

// ========================================
// Handle POST actions
// ========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tablesExist) {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_campaign') {
        $name = trim($_POST['name'] ?? '');
        $emailSubject = trim($_POST['email_subject'] ?? '');
        $emailBody = trim($_POST['email_body'] ?? '');
        $genderFilter = $_POST['gender_filter'] ?? '';
        $ageMin = !empty($_POST['age_min']) ? (int)$_POST['age_min'] : null;
        $ageMax = !empty($_POST['age_max']) ? (int)$_POST['age_max'] : null;
        $regionFilter = !empty($_POST['region_filter']) ? implode(',', $_POST['region_filter']) : null;
        $districtFilter = !empty($_POST['district_filter']) ? implode(',', $_POST['district_filter']) : null;
        $discountCodeId = !empty($_POST['discount_code_id']) ? (int)$_POST['discount_code_id'] : null;

        if (empty($name)) {
            $error = 'Kampanjnamn krävs';
        } elseif (empty($emailSubject)) {
            $error = 'E-postämne krävs';
        } elseif (empty($emailBody)) {
            $error = 'E-postinnehåll krävs';
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO promotion_campaigns
                (name, email_subject, email_body, gender_filter, age_min, age_max, region_filter, district_filter, discount_code_id, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $name, $emailSubject, $emailBody,
                $genderFilter ?: null, $ageMin, $ageMax,
                $regionFilter, $districtFilter, $discountCodeId,
                $currentUser['id'] ?? null
            ]);
            $message = 'Kampanj skapad!';
        }
    } elseif ($action === 'update_campaign') {
        $id = (int)$_POST['id'];
        $name = trim($_POST['name'] ?? '');
        $emailSubject = trim($_POST['email_subject'] ?? '');
        $emailBody = trim($_POST['email_body'] ?? '');
        $genderFilter = $_POST['gender_filter'] ?? '';
        $ageMin = !empty($_POST['age_min']) ? (int)$_POST['age_min'] : null;
        $ageMax = !empty($_POST['age_max']) ? (int)$_POST['age_max'] : null;
        $regionFilter = !empty($_POST['region_filter']) ? implode(',', $_POST['region_filter']) : null;
        $districtFilter = !empty($_POST['district_filter']) ? implode(',', $_POST['district_filter']) : null;
        $discountCodeId = !empty($_POST['discount_code_id']) ? (int)$_POST['discount_code_id'] : null;

        if (empty($name) || empty($emailSubject) || empty($emailBody)) {
            $error = 'Alla fält krävs';
        } else {
            $stmt = $pdo->prepare("
                UPDATE promotion_campaigns
                SET name = ?, email_subject = ?, email_body = ?,
                    gender_filter = ?, age_min = ?, age_max = ?,
                    region_filter = ?, district_filter = ?, discount_code_id = ?
                WHERE id = ? AND status = 'draft'
            ");
            $stmt->execute([
                $name, $emailSubject, $emailBody,
                $genderFilter ?: null, $ageMin, $ageMax,
                $regionFilter, $districtFilter, $discountCodeId,
                $id
            ]);
            $message = 'Kampanj uppdaterad!';
        }
    } elseif ($action === 'delete_campaign') {
        $id = (int)$_POST['id'];
        $stmt = $pdo->prepare("SELECT status FROM promotion_campaigns WHERE id = ?");
        $stmt->execute([$id]);
        $campaign = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($campaign && $campaign['status'] === 'draft') {
            $pdo->prepare("DELETE FROM promotion_campaigns WHERE id = ?")->execute([$id]);
            $message = 'Kampanj raderad';
        } else {
            $error = 'Kan bara radera utkast';
        }
    } elseif ($action === 'send_campaign') {
        $id = (int)$_POST['campaign_id'];

        $stmt = $pdo->prepare("SELECT * FROM promotion_campaigns WHERE id = ? AND status = 'draft'");
        $stmt->execute([$id]);
        $campaign = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$campaign) {
            $error = 'Kampanjen hittades inte eller är redan skickad';
        } else {
            require_once __DIR__ . '/../includes/mail.php';

            // Build audience query
            $audienceSql = buildAudienceQuery($campaign);
            $audience = $pdo->query($audienceSql)->fetchAll(PDO::FETCH_ASSOC);

            // Get discount code if linked
            $discountCode = null;
            $discountText = '';
            if (!empty($campaign['discount_code_id'])) {
                $dcStmt = $pdo->prepare("SELECT * FROM discount_codes WHERE id = ?");
                $dcStmt->execute([$campaign['discount_code_id']]);
                $discountCode = $dcStmt->fetch(PDO::FETCH_ASSOC);
                if ($discountCode) {
                    $discountText = $discountCode['discount_type'] === 'percentage'
                        ? intval($discountCode['discount_value']) . '% rabatt'
                        : number_format($discountCode['discount_value'], 0) . ' kr rabatt';
                }
            }

            $sentCount = 0;
            $failedCount = 0;
            $skippedCount = 0;

            foreach ($audience as $rider) {
                if (empty($rider['email'])) {
                    $skippedCount++;
                    continue;
                }

                // Check already sent
                $checkStmt = $pdo->prepare("SELECT id FROM promotion_sends WHERE campaign_id = ? AND rider_id = ?");
                $checkStmt->execute([$id, $rider['id']]);
                if ($checkStmt->fetch()) {
                    $skippedCount++;
                    continue;
                }

                // Build personalized email
                $body = $campaign['email_body'];
                $body = str_replace([
                    '{{namn}}',
                    '{{fornamn}}',
                    '{{efternamn}}',
                    '{{klubb}}',
                ], [
                    htmlspecialchars($rider['firstname'] . ' ' . $rider['lastname']),
                    htmlspecialchars($rider['firstname']),
                    htmlspecialchars($rider['lastname']),
                    htmlspecialchars($rider['club_name'] ?? ''),
                ], $body);

                if ($discountCode) {
                    $body = str_replace([
                        '{{rabattkod}}',
                        '{{rabatt}}',
                    ], [
                        htmlspecialchars($discountCode['code']),
                        $discountText,
                    ], $body);
                }

                // Wrap in email template
                $htmlBody = '<div class="header"><div class="logo">TheHUB</div></div>'
                    . '<div style="white-space: pre-wrap;">' . nl2br($body) . '</div>';
                $fullBody = hub_email_template('custom', ['content' => $htmlBody]);

                $subject = $campaign['email_subject'];
                $sent = hub_send_email($rider['email'], $subject, $fullBody);

                // Log send
                $logStmt = $pdo->prepare("
                    INSERT INTO promotion_sends (campaign_id, rider_id, email_address, status)
                    VALUES (?, ?, ?, ?)
                ");
                $logStmt->execute([$id, $rider['id'], $rider['email'], $sent ? 'sent' : 'failed']);

                if ($sent) {
                    $sentCount++;
                } else {
                    $failedCount++;
                }
            }

            // Update campaign status
            $pdo->prepare("
                UPDATE promotion_campaigns
                SET status = 'sent', audience_count = ?, sent_count = ?, failed_count = ?, skipped_count = ?,
                    sent_at = NOW(), sent_by = ?
                WHERE id = ?
            ")->execute([count($audience), $sentCount, $failedCount, $skippedCount, $currentUser['id'] ?? null, $id]);

            $message = "Utskick klart! $sentCount skickade";
            if ($skippedCount > 0) $message .= ", $skippedCount hoppades över";
            if ($failedCount > 0) $message .= ", $failedCount misslyckades";
        }
    } elseif ($action === 'archive_campaign') {
        $id = (int)$_POST['id'];
        $pdo->prepare("UPDATE promotion_campaigns SET status = 'archived' WHERE id = ?")->execute([$id]);
        $message = 'Kampanj arkiverad';
    }
}

/**
 * Build SQL to fetch audience based on campaign filters
 */
function buildAudienceQuery(array $campaign): string {
    $currentYear = (int)date('Y');
    $where = ["r.active = 1", "r.email IS NOT NULL", "r.email != ''"];

    // Gender filter
    if (!empty($campaign['gender_filter'])) {
        $gender = addslashes($campaign['gender_filter']);
        // Handle K = F mapping
        if ($gender === 'F') {
            $where[] = "(r.gender = 'F' OR r.gender = 'K')";
        } else {
            $where[] = "r.gender = '$gender'";
        }
    }

    // Age filter (from birth_year)
    if (!empty($campaign['age_min'])) {
        $maxBirthYear = $currentYear - (int)$campaign['age_min'];
        $where[] = "r.birth_year <= $maxBirthYear";
    }
    if (!empty($campaign['age_max'])) {
        $minBirthYear = $currentYear - (int)$campaign['age_max'];
        $where[] = "r.birth_year >= $minBirthYear";
    }

    // Region filter (club regions)
    if (!empty($campaign['region_filter'])) {
        $regions = array_map(function($r) { return "'" . addslashes(trim($r)) . "'"; }, explode(',', $campaign['region_filter']));
        $regionList = implode(',', $regions);
        $where[] = "c.region IN ($regionList)";
    }

    // District filter (rider districts)
    if (!empty($campaign['district_filter'])) {
        $districts = array_map(function($d) { return "'" . addslashes(trim($d)) . "'"; }, explode(',', $campaign['district_filter']));
        $districtList = implode(',', $districts);
        $where[] = "r.district IN ($districtList)";
    }

    // Only riders with at least 1 result (active participants)
    $where[] = "EXISTS (SELECT 1 FROM results res WHERE res.cyclist_id = r.id)";

    $whereStr = implode(' AND ', $where);

    return "
        SELECT r.id, r.firstname, r.lastname, r.email, r.birth_year, r.gender, r.district,
               c.name AS club_name, c.region AS club_region
        FROM riders r
        LEFT JOIN clubs c ON r.club_id = c.id
        WHERE $whereStr
        ORDER BY r.lastname, r.firstname
    ";
}

// ========================================
// Load data
// ========================================
$campaigns = [];
$regions = [];
$districts = [];
$discountCodes = [];
$viewMode = $_GET['view'] ?? 'list';
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : null;
$editCampaign = null;

if ($tablesExist) {
    try {
        // Get campaigns
        $campaigns = $pdo->query("
            SELECT pc.*, au.full_name AS created_by_name
            FROM promotion_campaigns pc
            LEFT JOIN admin_users au ON pc.created_by = au.id
            ORDER BY
                CASE pc.status WHEN 'draft' THEN 0 WHEN 'sent' THEN 1 ELSE 2 END,
                pc.created_at DESC
        ")->fetchAll(PDO::FETCH_ASSOC);

        // Count audience for each draft campaign
        foreach ($campaigns as &$c) {
            if ($c['status'] === 'draft') {
                try {
                    $sql = buildAudienceQuery($c);
                    $countSql = "SELECT COUNT(*) FROM (" . $sql . ") AS audience";
                    $c['audience_preview'] = (int)$pdo->query($countSql)->fetchColumn();
                } catch (Exception $e) {
                    $c['audience_preview'] = 0;
                }
            }
        }
        unset($c);

        // Get edit campaign
        if ($editId) {
            $stmt = $pdo->prepare("SELECT * FROM promotion_campaigns WHERE id = ?");
            $stmt->execute([$editId]);
            $editCampaign = $stmt->fetch(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Get available regions (from clubs)
try {
    $regions = $pdo->query("
        SELECT DISTINCT region FROM clubs
        WHERE region IS NOT NULL AND region != '' AND active = 1
        ORDER BY region
    ")->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}

// Get available districts (from riders)
try {
    $districts = $pdo->query("
        SELECT DISTINCT district FROM riders
        WHERE district IS NOT NULL AND district != '' AND active = 1
        ORDER BY district
    ")->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}

// Get discount codes
try {
    $discountCodes = $pdo->query("
        SELECT dc.id, dc.code, dc.discount_type, dc.discount_value, dc.is_active,
               e.name AS event_name, s.name AS series_name
        FROM discount_codes dc
        LEFT JOIN events e ON dc.event_id = e.id
        LEFT JOIN series s ON dc.series_id = s.id
        WHERE dc.is_active = 1
        ORDER BY dc.created_at DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Page config
$page_title = 'TheHUB Promotion';
$breadcrumbs = [
    ['label' => 'Analytics', 'url' => '/admin/analytics-dashboard.php'],
    ['label' => 'TheHUB Promotion']
];

include __DIR__ . '/components/unified-layout.php';
?>

<style>
.promo-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: var(--space-md);
    margin-bottom: var(--space-xl);
}
.promo-stat {
    background: var(--color-bg-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    padding: var(--space-lg);
    text-align: center;
}
.promo-stat-value {
    font-size: 1.75rem;
    font-weight: 700;
    color: var(--color-accent);
}
.promo-stat-label {
    font-size: 0.8rem;
    color: var(--color-text-muted);
    margin-top: var(--space-2xs);
}
.promo-card {
    background: var(--color-bg-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    padding: var(--space-lg);
    margin-bottom: var(--space-md);
}
.promo-card-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: var(--space-md);
    margin-bottom: var(--space-md);
}
.promo-card-title {
    font-size: 1.1rem;
    font-weight: 600;
    color: var(--color-text-primary);
}
.promo-card-meta {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-sm) var(--space-lg);
    font-size: 0.85rem;
    color: var(--color-text-secondary);
}
.promo-card-meta i { width: 14px; height: 14px; }
.promo-badge {
    display: inline-flex;
    align-items: center;
    gap: var(--space-2xs);
    padding: 2px var(--space-xs);
    border-radius: var(--radius-full);
    font-size: 0.75rem;
    font-weight: 600;
}
.promo-badge-draft { background: rgba(251, 191, 36, 0.15); color: var(--color-warning); }
.promo-badge-sent { background: rgba(16, 185, 129, 0.15); color: var(--color-success); }
.promo-badge-archived { background: rgba(134, 143, 162, 0.15); color: var(--color-text-muted); }
.promo-actions {
    display: flex;
    gap: var(--space-xs);
    flex-wrap: wrap;
}
.promo-btn {
    display: inline-flex;
    align-items: center;
    gap: var(--space-2xs);
    padding: var(--space-xs) var(--space-sm);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    background: var(--color-bg-surface);
    color: var(--color-text-secondary);
    cursor: pointer;
    font-size: 0.8rem;
    text-decoration: none;
    transition: all 0.15s;
}
.promo-btn:hover { border-color: var(--color-accent); color: var(--color-accent); }
.promo-btn-primary {
    background: var(--color-accent);
    color: #fff;
    border-color: var(--color-accent);
}
.promo-btn-primary:hover { background: var(--color-accent-hover); color: #fff; }
.promo-btn-danger { color: var(--color-error); }
.promo-btn-danger:hover { border-color: var(--color-error); }
.filter-tags {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-2xs);
    margin-top: var(--space-xs);
}
.filter-tag {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    padding: 2px var(--space-xs);
    background: var(--color-accent-light);
    color: var(--color-accent);
    border-radius: var(--radius-full);
    font-size: 0.75rem;
}
.form-section {
    background: var(--color-bg-page);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    padding: var(--space-lg);
    margin-bottom: var(--space-lg);
}
.form-section-title {
    font-size: 0.85rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--color-text-muted);
    margin-bottom: var(--space-md);
    display: flex;
    align-items: center;
    gap: var(--space-xs);
}
.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: var(--space-md);
}
.form-group { margin-bottom: var(--space-md); }
.form-group label {
    display: block;
    font-size: 0.85rem;
    font-weight: 600;
    color: var(--color-text-secondary);
    margin-bottom: var(--space-2xs);
}
.form-group input,
.form-group select,
.form-group textarea {
    width: 100%;
    padding: var(--space-xs) var(--space-sm);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    background: var(--color-bg-surface);
    color: var(--color-text-primary);
    font-size: 0.9rem;
}
.form-group textarea { min-height: 150px; resize: vertical; }
.form-group select[multiple] { min-height: 120px; }
.form-hint {
    font-size: 0.75rem;
    color: var(--color-text-muted);
    margin-top: var(--space-2xs);
}
.audience-preview {
    background: var(--color-accent-light);
    border: 1px solid var(--color-accent);
    border-radius: var(--radius-md);
    padding: var(--space-md) var(--space-lg);
    text-align: center;
    margin-bottom: var(--space-lg);
}
.audience-preview-count {
    font-size: 2rem;
    font-weight: 700;
    color: var(--color-accent);
}
.audience-preview-label {
    font-size: 0.85rem;
    color: var(--color-text-secondary);
}
.send-list-table {
    width: 100%;
    border-collapse: collapse;
}
.send-list-table th,
.send-list-table td {
    padding: var(--space-xs) var(--space-sm);
    text-align: left;
    border-bottom: 1px solid var(--color-border);
    font-size: 0.85rem;
}
.send-list-table th {
    font-weight: 600;
    color: var(--color-text-muted);
    font-size: 0.75rem;
    text-transform: uppercase;
}
@media (max-width: 767px) {
    .promo-stats { grid-template-columns: repeat(2, 1fr); }
    .promo-card, .form-section, .audience-preview {
        margin-left: -16px;
        margin-right: -16px;
        border-radius: 0;
        border-left: none;
        border-right: none;
    }
    .form-row { grid-template-columns: 1fr; }
    .promo-card-header { flex-direction: column; }
}
</style>

<div class="admin-content">
    <div class="page-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: var(--space-md);">
        <h1><i data-lucide="megaphone" style="width: 24px; height: 24px;"></i> TheHUB Promotion</h1>
        <?php if ($tablesExist && $viewMode !== 'create' && !$editId): ?>
            <a href="?view=create" class="promo-btn promo-btn-primary">
                <i data-lucide="plus"></i> Ny kampanj
            </a>
        <?php endif; ?>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-success" style="margin-bottom: var(--space-lg);">
            <i data-lucide="check-circle"></i> <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger" style="margin-bottom: var(--space-lg);">
            <i data-lucide="alert-circle"></i> <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <?php if (!$tablesExist): ?>
        <div class="admin-card">
            <div class="admin-card-body" style="padding: var(--space-xl); text-align: center;">
                <i data-lucide="database" style="width: 48px; height: 48px; color: var(--color-text-muted);"></i>
                <h3 style="margin: var(--space-md) 0;">Databastabeller saknas</h3>
                <p style="color: var(--color-text-secondary);">Kör migration 078 via <a href="/admin/migrations.php">Migrationsverktyget</a></p>
            </div>
        </div>

    <?php elseif ($viewMode === 'create' || $editCampaign): ?>
        <?php
        // CREATE / EDIT FORM
        $isEdit = !empty($editCampaign);
        $c = $editCampaign ?: [];
        $selectedRegions = !empty($c['region_filter']) ? explode(',', $c['region_filter']) : [];
        $selectedDistricts = !empty($c['district_filter']) ? explode(',', $c['district_filter']) : [];
        ?>

        <form method="POST" id="campaignForm">
            <input type="hidden" name="action" value="<?= $isEdit ? 'update_campaign' : 'create_campaign' ?>">
            <?php if ($isEdit): ?>
                <input type="hidden" name="id" value="<?= $c['id'] ?>">
            <?php endif; ?>

            <div class="form-section">
                <div class="form-section-title"><i data-lucide="file-text"></i> Kampanjinfo</div>
                <div class="form-group">
                    <label>Kampanjnamn *</label>
                    <input type="text" name="name" value="<?= htmlspecialchars($c['name'] ?? '') ?>" required placeholder="T.ex. Inbjudan MTB-läger Göteborg">
                </div>
                <div class="form-group">
                    <label>E-postämne *</label>
                    <input type="text" name="email_subject" value="<?= htmlspecialchars($c['email_subject'] ?? '') ?>" required placeholder="T.ex. Du är inbjuden till MTB-läger!">
                </div>
                <div class="form-group">
                    <label>E-postinnehåll *</label>
                    <textarea name="email_body" required placeholder="Hej {{fornamn}},&#10;&#10;Vi vill bjuda in dig till..."><?= htmlspecialchars($c['email_body'] ?? '') ?></textarea>
                    <div class="form-hint">
                        Variabler: <code>{{fornamn}}</code>, <code>{{efternamn}}</code>, <code>{{namn}}</code>, <code>{{klubb}}</code><?php if (!empty($discountCodes)): ?>, <code>{{rabattkod}}</code>, <code>{{rabatt}}</code><?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="form-section">
                <div class="form-section-title"><i data-lucide="filter"></i> Målgruppsfilter</div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Kön</label>
                        <select name="gender_filter">
                            <option value="">Alla</option>
                            <option value="M" <?= ($c['gender_filter'] ?? '') === 'M' ? 'selected' : '' ?>>Herrar</option>
                            <option value="F" <?= ($c['gender_filter'] ?? '') === 'F' ? 'selected' : '' ?>>Damer</option>
                        </select>
                    </div>
                    <div></div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Minimiålder</label>
                        <input type="number" name="age_min" value="<?= htmlspecialchars($c['age_min'] ?? '') ?>" min="5" max="99" placeholder="T.ex. 12">
                    </div>
                    <div class="form-group">
                        <label>Maxålder</label>
                        <input type="number" name="age_max" value="<?= htmlspecialchars($c['age_max'] ?? '') ?>" min="5" max="99" placeholder="T.ex. 18">
                    </div>
                </div>

                <?php if (!empty($regions)): ?>
                <div class="form-group">
                    <label>Region (klubbens region)</label>
                    <select name="region_filter[]" multiple>
                        <?php foreach ($regions as $region): ?>
                            <option value="<?= htmlspecialchars($region) ?>" <?= in_array($region, $selectedRegions) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($region) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-hint">Håll Ctrl/Cmd för att välja flera. Lämna tomt för alla regioner.</div>
                </div>
                <?php endif; ?>

                <?php if (!empty($districts)): ?>
                <div class="form-group">
                    <label>Distrikt (deltagarens distrikt)</label>
                    <select name="district_filter[]" multiple>
                        <?php foreach ($districts as $district): ?>
                            <option value="<?= htmlspecialchars($district) ?>" <?= in_array($district, $selectedDistricts) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($district) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-hint">Håll Ctrl/Cmd för att välja flera. Lämna tomt för alla distrikt.</div>
                </div>
                <?php endif; ?>
            </div>

            <?php if (!empty($discountCodes)): ?>
            <div class="form-section">
                <div class="form-section-title"><i data-lucide="tag"></i> Rabattkod (valfritt)</div>
                <div class="form-group">
                    <label>Koppla rabattkod</label>
                    <select name="discount_code_id">
                        <option value="">Ingen rabattkod</option>
                        <?php foreach ($discountCodes as $dc): ?>
                            <option value="<?= $dc['id'] ?>" <?= ($c['discount_code_id'] ?? '') == $dc['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($dc['code']) ?> -
                                <?= $dc['discount_type'] === 'percentage' ? intval($dc['discount_value']) . '%' : number_format($dc['discount_value'], 0) . ' kr' ?>
                                <?= $dc['event_name'] ? ' (' . htmlspecialchars($dc['event_name']) . ')' : '' ?>
                                <?= $dc['series_name'] ? ' (' . htmlspecialchars($dc['series_name']) . ')' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-hint">Använd <code>{{rabattkod}}</code> och <code>{{rabatt}}</code> i e-posttexten för att inkludera koden.</div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Audience preview -->
            <div class="audience-preview" id="audiencePreview">
                <div class="audience-preview-count" id="audienceCount">-</div>
                <div class="audience-preview-label">deltagare matchar filtren</div>
                <button type="button" class="promo-btn" style="margin-top: var(--space-sm);" onclick="previewAudience()">
                    <i data-lucide="refresh-cw"></i> Uppdatera förhandsgranskning
                </button>
            </div>

            <div style="display: flex; gap: var(--space-md); justify-content: flex-end; flex-wrap: wrap;">
                <a href="/admin/hub-promotion.php" class="promo-btn">Avbryt</a>
                <button type="submit" class="promo-btn promo-btn-primary">
                    <i data-lucide="save"></i> <?= $isEdit ? 'Uppdatera kampanj' : 'Skapa kampanj' ?>
                </button>
            </div>
        </form>

    <?php elseif ($viewMode === 'detail' && isset($_GET['id'])): ?>
        <?php
        // DETAIL VIEW - show sends for a campaign
        $detailId = (int)$_GET['id'];
        $detailCampaign = null;
        $sends = [];
        foreach ($campaigns as $c) {
            if ($c['id'] === $detailId) { $detailCampaign = $c; break; }
        }
        if ($detailCampaign) {
            $sends = $pdo->prepare("
                SELECT ps.*, r.firstname, r.lastname, c.name AS club_name
                FROM promotion_sends ps
                LEFT JOIN riders r ON ps.rider_id = r.id
                LEFT JOIN clubs c ON r.club_id = c.id
                WHERE ps.campaign_id = ?
                ORDER BY ps.sent_at DESC
            ");
            $sends->execute([$detailId]);
            $sends = $sends->fetchAll(PDO::FETCH_ASSOC);
        }
        ?>

        <?php if ($detailCampaign): ?>
            <div class="promo-card">
                <div class="promo-card-header">
                    <div>
                        <div class="promo-card-title"><?= htmlspecialchars($detailCampaign['name']) ?></div>
                        <span class="promo-badge promo-badge-<?= $detailCampaign['status'] ?>">
                            <?= $detailCampaign['status'] === 'draft' ? 'Utkast' : ($detailCampaign['status'] === 'sent' ? 'Skickad' : 'Arkiverad') ?>
                        </span>
                    </div>
                    <a href="/admin/hub-promotion.php" class="promo-btn"><i data-lucide="arrow-left"></i> Tillbaka</a>
                </div>
                <div class="promo-card-meta">
                    <?php if ($detailCampaign['sent_at']): ?>
                        <span><i data-lucide="send"></i> Skickad <?= date('Y-m-d H:i', strtotime($detailCampaign['sent_at'])) ?></span>
                    <?php endif; ?>
                    <span><i data-lucide="users"></i> <?= $detailCampaign['sent_count'] ?> skickade</span>
                    <?php if ($detailCampaign['failed_count'] > 0): ?>
                        <span style="color: var(--color-error);"><i data-lucide="x-circle"></i> <?= $detailCampaign['failed_count'] ?> misslyckade</span>
                    <?php endif; ?>
                    <?php if ($detailCampaign['skipped_count'] > 0): ?>
                        <span><i data-lucide="skip-forward"></i> <?= $detailCampaign['skipped_count'] ?> hoppades över</span>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!empty($sends)): ?>
                <div class="admin-card">
                    <div class="admin-card-header"><h3>Mottagare (<?= count($sends) ?>)</h3></div>
                    <div class="admin-card-body" style="padding: 0;">
                        <div style="overflow-x: auto;">
                            <table class="send-list-table">
                                <thead>
                                    <tr>
                                        <th>Namn</th>
                                        <th>E-post</th>
                                        <th>Klubb</th>
                                        <th>Status</th>
                                        <th>Tid</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($sends as $s): ?>
                                        <tr>
                                            <td>
                                                <a href="/admin/rider-edit.php?id=<?= $s['rider_id'] ?>" style="color: var(--color-accent);">
                                                    <?= htmlspecialchars(($s['firstname'] ?? '') . ' ' . ($s['lastname'] ?? '')) ?>
                                                </a>
                                            </td>
                                            <td><?= htmlspecialchars($s['email_address']) ?></td>
                                            <td><?= htmlspecialchars($s['club_name'] ?? '-') ?></td>
                                            <td>
                                                <?php if ($s['status'] === 'sent'): ?>
                                                    <span style="color: var(--color-success);"><i data-lucide="check" style="width: 14px;"></i> Skickad</span>
                                                <?php elseif ($s['status'] === 'failed'): ?>
                                                    <span style="color: var(--color-error);"><i data-lucide="x" style="width: 14px;"></i> Misslyckad</span>
                                                <?php else: ?>
                                                    <span style="color: var(--color-text-muted);"><i data-lucide="skip-forward" style="width: 14px;"></i> Hoppades över</span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="color: var(--color-text-muted); font-size: 0.8rem;">
                                                <?= $s['sent_at'] ? date('Y-m-d H:i', strtotime($s['sent_at'])) : '-' ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="admin-card">
                    <div class="admin-card-body" style="padding: var(--space-xl); text-align: center; color: var(--color-text-muted);">
                        Inga utskick loggade ännu.
                    </div>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="alert alert-danger">Kampanjen hittades inte.</div>
        <?php endif; ?>

    <?php else: ?>
        <?php
        // LIST VIEW
        $totalCampaigns = count($campaigns);
        $draftCount = count(array_filter($campaigns, fn($c) => $c['status'] === 'draft'));
        $sentCount = count(array_filter($campaigns, fn($c) => $c['status'] === 'sent'));
        $totalSent = array_sum(array_column($campaigns, 'sent_count'));
        ?>

        <!-- Stats -->
        <div class="promo-stats">
            <div class="promo-stat">
                <div class="promo-stat-value"><?= $totalCampaigns ?></div>
                <div class="promo-stat-label">Kampanjer</div>
            </div>
            <div class="promo-stat">
                <div class="promo-stat-value"><?= $draftCount ?></div>
                <div class="promo-stat-label">Utkast</div>
            </div>
            <div class="promo-stat">
                <div class="promo-stat-value"><?= $sentCount ?></div>
                <div class="promo-stat-label">Skickade</div>
            </div>
            <div class="promo-stat">
                <div class="promo-stat-value"><?= $totalSent ?></div>
                <div class="promo-stat-label">E-post skickade</div>
            </div>
        </div>

        <?php if (empty($campaigns)): ?>
            <div class="admin-card">
                <div class="admin-card-body" style="padding: var(--space-2xl); text-align: center;">
                    <i data-lucide="megaphone" style="width: 48px; height: 48px; color: var(--color-text-muted);"></i>
                    <h3 style="margin: var(--space-md) 0;">Inga kampanjer ännu</h3>
                    <p style="color: var(--color-text-secondary); margin-bottom: var(--space-lg);">
                        Skapa riktade e-postutskick baserat på ålder, kön och region.
                    </p>
                    <a href="?view=create" class="promo-btn promo-btn-primary">
                        <i data-lucide="plus"></i> Skapa första kampanjen
                    </a>
                </div>
            </div>
        <?php else: ?>
            <?php foreach ($campaigns as $c): ?>
                <div class="promo-card <?= $c['status'] === 'archived' ? 'style="opacity: 0.6;"' : '' ?>">
                    <div class="promo-card-header">
                        <div>
                            <div class="promo-card-title"><?= htmlspecialchars($c['name']) ?></div>
                            <span class="promo-badge promo-badge-<?= $c['status'] ?>">
                                <?= $c['status'] === 'draft' ? 'Utkast' : ($c['status'] === 'sent' ? 'Skickad' : 'Arkiverad') ?>
                            </span>
                        </div>
                        <div class="promo-actions">
                            <?php if ($c['status'] === 'draft'): ?>
                                <a href="?edit=<?= $c['id'] ?>" class="promo-btn"><i data-lucide="pencil"></i> Redigera</a>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('Skicka kampanjen till <?= $c['audience_preview'] ?? '?' ?> mottagare?');">
                                    <input type="hidden" name="action" value="send_campaign">
                                    <input type="hidden" name="campaign_id" value="<?= $c['id'] ?>">
                                    <button type="submit" class="promo-btn promo-btn-primary"><i data-lucide="send"></i> Skicka</button>
                                </form>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('Radera kampanjen?');">
                                    <input type="hidden" name="action" value="delete_campaign">
                                    <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                    <button type="submit" class="promo-btn promo-btn-danger"><i data-lucide="trash-2"></i></button>
                                </form>
                            <?php elseif ($c['status'] === 'sent'): ?>
                                <a href="?view=detail&id=<?= $c['id'] ?>" class="promo-btn"><i data-lucide="eye"></i> Detaljer</a>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="action" value="archive_campaign">
                                    <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                    <button type="submit" class="promo-btn"><i data-lucide="archive"></i> Arkivera</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="promo-card-meta">
                        <span><i data-lucide="mail"></i> <?= htmlspecialchars($c['email_subject']) ?></span>
                        <?php if ($c['created_by_name']): ?>
                            <span><i data-lucide="user"></i> <?= htmlspecialchars($c['created_by_name']) ?></span>
                        <?php endif; ?>
                        <span><i data-lucide="calendar"></i> <?= date('Y-m-d', strtotime($c['created_at'])) ?></span>
                    </div>

                    <!-- Filter tags -->
                    <div class="filter-tags">
                        <?php if (!empty($c['gender_filter'])): ?>
                            <span class="filter-tag"><i data-lucide="user" style="width: 10px; height: 10px;"></i> <?= $c['gender_filter'] === 'M' ? 'Herrar' : 'Damer' ?></span>
                        <?php endif; ?>
                        <?php if (!empty($c['age_min']) || !empty($c['age_max'])): ?>
                            <span class="filter-tag"><i data-lucide="cake" style="width: 10px; height: 10px;"></i> <?= $c['age_min'] ?? '?' ?>-<?= $c['age_max'] ?? '?' ?> år</span>
                        <?php endif; ?>
                        <?php if (!empty($c['region_filter'])): ?>
                            <?php foreach (explode(',', $c['region_filter']) as $r): ?>
                                <span class="filter-tag"><i data-lucide="map-pin" style="width: 10px; height: 10px;"></i> <?= htmlspecialchars(trim($r)) ?></span>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <?php if (!empty($c['district_filter'])): ?>
                            <?php foreach (explode(',', $c['district_filter']) as $d): ?>
                                <span class="filter-tag"><i data-lucide="compass" style="width: 10px; height: 10px;"></i> <?= htmlspecialchars(trim($d)) ?></span>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <?php if (!empty($c['discount_code_id'])): ?>
                            <span class="filter-tag"><i data-lucide="tag" style="width: 10px; height: 10px;"></i> Rabattkod</span>
                        <?php endif; ?>
                    </div>

                    <?php if ($c['status'] === 'draft' && isset($c['audience_preview'])): ?>
                        <div style="margin-top: var(--space-sm); font-size: 0.85rem; color: var(--color-accent);">
                            <i data-lucide="users" style="width: 14px; height: 14px;"></i> <?= $c['audience_preview'] ?> potentiella mottagare
                        </div>
                    <?php elseif ($c['status'] === 'sent'): ?>
                        <div style="margin-top: var(--space-sm); font-size: 0.85rem; color: var(--color-text-secondary);">
                            <i data-lucide="check-circle" style="width: 14px; height: 14px; color: var(--color-success);"></i>
                            <?= $c['sent_count'] ?> skickade
                            <?php if ($c['failed_count'] > 0): ?> | <span style="color: var(--color-error);"><?= $c['failed_count'] ?> misslyckade</span><?php endif; ?>
                            <?php if ($c['skipped_count'] > 0): ?> | <?= $c['skipped_count'] ?> hoppades över<?php endif; ?>
                            <?php if ($c['sent_at']): ?> | <?= date('Y-m-d H:i', strtotime($c['sent_at'])) ?><?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    <?php endif; ?>
</div>

<script>
// Audience preview via AJAX-like form submission
function previewAudience() {
    const form = document.getElementById('campaignForm');
    if (!form) return;

    const formData = new FormData(form);
    const params = new URLSearchParams();

    // Build filter params
    const gender = formData.get('gender_filter') || '';
    const ageMin = formData.get('age_min') || '';
    const ageMax = formData.get('age_max') || '';
    const regions = formData.getAll('region_filter[]');
    const districts = formData.getAll('district_filter[]');

    params.set('action', 'preview_audience');
    if (gender) params.set('gender', gender);
    if (ageMin) params.set('age_min', ageMin);
    if (ageMax) params.set('age_max', ageMax);
    if (regions.length) params.set('regions', regions.join(','));
    if (districts.length) params.set('districts', districts.join(','));

    document.getElementById('audienceCount').textContent = '...';

    fetch('/api/promotion-preview.php?' + params.toString())
        .then(r => r.json())
        .then(data => {
            document.getElementById('audienceCount').textContent = data.count ?? '?';
        })
        .catch(() => {
            document.getElementById('audienceCount').textContent = '?';
        });
}

// Run preview on page load if creating/editing
document.addEventListener('DOMContentLoaded', function() {
    if (document.getElementById('campaignForm')) {
        previewAudience();
    }
    if (typeof lucide !== 'undefined') lucide.createIcons();
});
</script>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>

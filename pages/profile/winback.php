<?php
/**
 * TheHUB V1.0 - Back to Gravity / Winback
 * Shows pending campaigns, rewards, and prize draw info
 */

$currentUser = hub_current_user();

if (!$currentUser) {
    header('Location: /profile/login');
    exit;
}

$pdo = hub_db();

// Check if tables exist
$tablesExist = false;
try {
    $check = $pdo->query("SHOW TABLES LIKE 'winback_campaigns'");
    $tablesExist = $check->rowCount() > 0;
} catch (Exception $e) {}

$pendingCampaigns = [];
$completedCampaigns = [];
$earnedDiscounts = [];

if ($tablesExist) {
    // Get active campaigns
    $campStmt = $pdo->query("
        SELECT wc.*, dc.code as discount_code, dc.discount_type, dc.discount_value,
               dc.valid_until as code_valid_until, e.name as event_name, s.name as series_name
        FROM winback_campaigns wc
        LEFT JOIN discount_codes dc ON wc.discount_code_id = dc.id
        LEFT JOIN events e ON dc.event_id = e.id
        LEFT JOIN series s ON dc.series_id = s.id
        WHERE wc.is_active = 1
        ORDER BY wc.id DESC
    ");
    $allCampaigns = $campStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($allCampaigns as $c) {
        $brandIds = json_decode($c['brand_ids'] ?? '[]', true) ?: [];
        $audienceType = $c['audience_type'] ?? 'churned';
        $placeholders = !empty($brandIds) ? implode(',', array_fill(0, count($brandIds), '?')) : '0';

        // Check if already responded
        $respCheck = $pdo->prepare("SELECT id, discount_code_given, responded_at FROM winback_responses WHERE campaign_id = ? AND rider_id = ?");
        $respCheck->execute([$c['id'], $currentUser['id']]);
        $response = $respCheck->fetch(PDO::FETCH_ASSOC);

        if ($response) {
            // Already responded - add to completed
            $c['response'] = $response;
            $completedCampaigns[] = $c;

            // Track earned discount
            if (!empty($response['discount_code_given'])) {
                $earnedDiscounts[] = [
                    'code' => $response['discount_code_given'],
                    'campaign' => $c['name'],
                    'responded_at' => $response['responded_at']
                ];
            }
            continue;
        }

        // Check if user qualifies based on audience type
        $qualifies = false;

        if ($audienceType === 'churned') {
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

        if ($qualifies) {
            $pendingCampaigns[] = $c;
        }
    }
}
?>

<style>
.winback-hero {
    text-align: center;
    padding: var(--space-xl) var(--space-md);
    background: linear-gradient(135deg, var(--color-bg-surface), var(--color-accent-light));
    border-radius: var(--radius-lg);
    margin-bottom: var(--space-lg);
}
.winback-hero img {
    max-width: 300px;
    width: 100%;
    margin-bottom: var(--space-md);
}
.winback-hero h1 {
    font-family: var(--font-heading);
    font-size: 2rem;
    margin: 0 0 var(--space-sm);
    color: var(--color-text-primary);
}
.winback-hero p {
    color: var(--color-text-secondary);
    margin: 0;
}

.campaign-card {
    background: var(--color-bg-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    padding: var(--space-lg);
    margin-bottom: var(--space-md);
}
.campaign-card.pending {
    border-color: var(--color-accent);
    border-width: 2px;
}
.campaign-card.completed {
    opacity: 0.8;
}
.campaign-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: var(--space-md);
}
.campaign-name {
    font-size: 1.25rem;
    font-weight: 600;
    color: var(--color-text-primary);
    margin: 0;
}
.campaign-badge {
    padding: var(--space-2xs) var(--space-sm);
    border-radius: var(--radius-full);
    font-size: 0.75rem;
    font-weight: 600;
}
.campaign-badge.pending {
    background: var(--color-accent);
    color: #000;
}
.campaign-badge.completed {
    background: var(--color-success);
    color: #fff;
}

.reward-box {
    background: var(--color-bg-page);
    border-radius: var(--radius-md);
    padding: var(--space-md);
    margin-bottom: var(--space-md);
}
.reward-box h4 {
    margin: 0 0 var(--space-sm);
    font-size: 0.875rem;
    color: var(--color-text-secondary);
}
.reward-code {
    font-family: monospace;
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--color-accent);
    letter-spacing: 2px;
}
.reward-value {
    font-size: 1.125rem;
    color: var(--color-success);
    font-weight: 600;
}
.reward-target {
    font-size: 0.875rem;
    color: var(--color-text-muted);
    margin-top: var(--space-xs);
}

.prize-draw-box {
    background: linear-gradient(135deg, #ffd700, #ffb700);
    color: #000;
    border-radius: var(--radius-md);
    padding: var(--space-lg);
    text-align: center;
    margin-top: var(--space-md);
}
.prize-draw-box h3 {
    margin: 0 0 var(--space-sm);
    font-size: 1.25rem;
}
.prize-draw-box p {
    margin: 0;
    opacity: 0.8;
}

.cta-button {
    display: inline-flex;
    align-items: center;
    gap: var(--space-sm);
    background: var(--color-accent);
    color: #000;
    padding: var(--space-md) var(--space-xl);
    border-radius: var(--radius-md);
    font-weight: 600;
    text-decoration: none;
    transition: all 0.15s;
}
.cta-button:hover {
    background: var(--color-accent-hover);
    transform: translateY(-2px);
}

.empty-state {
    text-align: center;
    padding: var(--space-2xl);
    color: var(--color-text-muted);
}
.empty-state i {
    width: 48px;
    height: 48px;
    margin-bottom: var(--space-md);
}
</style>

<div class="page-header">
    <h1 class="page-title">
        <i data-lucide="arrow-right-circle" class="page-icon"></i>
        Back to Gravity
    </h1>
</div>

<!-- Hero Section -->
<div class="winback-hero">
    <img src="/uploads/media/branding/697f64b56775d_1769956533.png" alt="Back to Gravity" onerror="this.style.display='none'">
    <h1>Vi saknar dig!</h1>
    <p>Svara på en kort enkät och få rabatt på din nästa anmälan</p>
</div>

<?php if (!$tablesExist): ?>
    <div class="alert alert-info">
        <i data-lucide="info"></i>
        Denna funktion är inte aktiverad ännu.
    </div>
<?php elseif (empty($pendingCampaigns) && empty($completedCampaigns)): ?>
    <div class="empty-state">
        <i data-lucide="inbox"></i>
        <p>Du har inga aktiva kampanjer just nu.</p>
        <p>Kom tillbaka senare!</p>
    </div>
<?php else: ?>

    <?php if (!empty($pendingCampaigns)): ?>
        <h2 style="margin-bottom: var(--space-md);">
            <i data-lucide="gift" style="width:24px;height:24px;vertical-align:middle;margin-right:var(--space-xs);color:var(--color-accent);"></i>
            Väntar på ditt svar (<?= count($pendingCampaigns) ?>)
        </h2>

        <?php foreach ($pendingCampaigns as $c): ?>
            <div class="campaign-card pending">
                <div class="campaign-header">
                    <h3 class="campaign-name"><?= htmlspecialchars($c['name']) ?></h3>
                    <span class="campaign-badge pending">Ny!</span>
                </div>

                <?php if (!empty($c['discount_code'])): ?>
                    <div class="reward-box">
                        <h4>Din belöning</h4>
                        <div class="reward-value">
                            <?php if ($c['discount_type'] === 'percentage'): ?>
                                <?= intval($c['discount_value']) ?>% rabatt
                            <?php else: ?>
                                <?= number_format($c['discount_value'], 0) ?> kr rabatt
                            <?php endif; ?>
                        </div>
                        <?php if ($c['event_name']): ?>
                            <div class="reward-target">Gäller för: <?= htmlspecialchars($c['event_name']) ?></div>
                        <?php elseif ($c['series_name']): ?>
                            <div class="reward-target">Gäller för: <?= htmlspecialchars($c['series_name']) ?></div>
                        <?php else: ?>
                            <div class="reward-target">Gäller för alla event</div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <div class="prize-draw-box">
                    <h3><i data-lucide="gift" style="width:20px;height:20px;vertical-align:middle;margin-right:var(--space-xs);"></i> Utlottning av fina priser!</h3>
                    <p>Alla som svarar är med i utlottningen</p>
                </div>

                <div style="text-align: center; margin-top: var(--space-lg);">
                    <a href="/profile/winback-survey?campaign=<?= $c['id'] ?>" class="cta-button">
                        <i data-lucide="clipboard-list"></i>
                        Svara på enkäten (2 min)
                    </a>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php if (!empty($completedCampaigns)): ?>
        <h2 style="margin: var(--space-xl) 0 var(--space-md);">
            <i data-lucide="check-circle" style="width:24px;height:24px;vertical-align:middle;margin-right:var(--space-xs);color:var(--color-success);"></i>
            Avklarade
        </h2>

        <?php foreach ($completedCampaigns as $c): ?>
            <div class="campaign-card completed">
                <div class="campaign-header">
                    <h3 class="campaign-name"><?= htmlspecialchars($c['name']) ?></h3>
                    <span class="campaign-badge completed">Klar!</span>
                </div>

                <?php if (!empty($c['response']['discount_code_given'])): ?>
                    <div class="reward-box">
                        <h4>Din rabattkod</h4>
                        <div class="reward-code"><?= htmlspecialchars($c['response']['discount_code_given']) ?></div>
                        <?php if ($c['discount_type'] === 'percentage'): ?>
                            <div class="reward-value"><?= intval($c['discount_value']) ?>% rabatt</div>
                        <?php else: ?>
                            <div class="reward-value"><?= number_format($c['discount_value'], 0) ?> kr rabatt</div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <p style="color: var(--color-text-muted); font-size: 0.875rem;">
                    <i data-lucide="calendar" style="width:14px;height:14px;vertical-align:middle;"></i>
                    Svarade <?= date('j M Y', strtotime($c['response']['responded_at'])) ?>
                </p>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

<?php endif; ?>

<script>
lucide.createIcons();
</script>

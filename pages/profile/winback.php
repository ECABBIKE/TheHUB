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
        $respCheck = $pdo->prepare("SELECT id, discount_code, submitted_at FROM winback_responses WHERE campaign_id = ? AND rider_id = ?");
        $respCheck->execute([$c['id'], $currentUser['id']]);
        $response = $respCheck->fetch(PDO::FETCH_ASSOC);

        if ($response) {
            // Already responded - add to completed
            $c['response'] = $response;
            $completedCampaigns[] = $c;

            // Track earned discount
            if (!empty($response['discount_code'])) {
                $earnedDiscounts[] = [
                    'code' => $response['discount_code'],
                    'campaign' => $c['name'],
                    'submitted_at' => $response['submitted_at']
                ];
            }
            continue;
        }

        // Check if user qualifies based on audience type
        // Use series_events junction table (correct) with events.series_id fallback
        $qualifies = false;
        $brandFilter = !empty($brandIds)
            ? " AND EXISTS (SELECT 1 FROM series_events se2 JOIN series s2 ON se2.series_id = s2.id WHERE se2.event_id = e.id AND s2.brand_id IN ($placeholders))"
            : "";

        if ($audienceType === 'churned') {
            $sql = "SELECT COUNT(DISTINCT e.id) FROM results r
                    JOIN events e ON r.event_id = e.id
                    WHERE r.cyclist_id = ? AND YEAR(e.date) BETWEEN ? AND ?" . $brandFilter;
            $params = array_merge([$currentUser['id'], $c['start_year'], $c['end_year']], $brandIds);
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $historicalCount = (int)$stmt->fetchColumn();

            $sql2 = "SELECT COUNT(*) FROM results r
                     JOIN events e ON r.event_id = e.id
                     WHERE r.cyclist_id = ? AND YEAR(e.date) = ?" . $brandFilter;
            $params2 = array_merge([$currentUser['id'], $c['target_year']], $brandIds);
            $stmt2 = $pdo->prepare($sql2);
            $stmt2->execute($params2);
            $targetCount = (int)$stmt2->fetchColumn();

            $qualifies = ($historicalCount > 0 && $targetCount == 0);
        } elseif ($audienceType === 'active') {
            $sql = "SELECT COUNT(*) FROM results r
                    JOIN events e ON r.event_id = e.id
                    WHERE r.cyclist_id = ? AND YEAR(e.date) = ?" . $brandFilter;
            $params = array_merge([$currentUser['id'], $c['target_year']], $brandIds);
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $qualifies = ((int)$stmt->fetchColumn() > 0);
        } elseif ($audienceType === 'one_timer') {
            $sql = "SELECT COUNT(DISTINCT e.id) FROM results r
                    JOIN events e ON r.event_id = e.id
                    WHERE r.cyclist_id = ? AND YEAR(e.date) = ?" . $brandFilter;
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
.wb-intro {
    text-align: center;
    margin-bottom: var(--space-lg);
    color: var(--color-text-secondary);
    line-height: 1.5;
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
    opacity: 0.75;
}
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
    margin: 0;
}
.campaign-badge {
    padding: var(--space-2xs) var(--space-sm);
    border-radius: var(--radius-full);
    font-size: 0.75rem;
    font-weight: 600;
    flex-shrink: 0;
}
.campaign-badge.pending {
    background: var(--color-accent-light);
    color: var(--color-accent-text);
}
.campaign-badge.completed {
    background: rgba(16, 185, 129, 0.15);
    color: var(--color-success);
}

.reward-box {
    background: var(--color-bg-page);
    border-radius: var(--radius-sm);
    padding: var(--space-md);
    margin-bottom: var(--space-md);
}
.reward-box h4 {
    margin: 0 0 var(--space-xs);
    font-size: 0.8rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--color-text-muted);
}
.reward-code {
    font-family: monospace;
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--color-accent);
    letter-spacing: 2px;
}
.reward-value {
    font-size: 1rem;
    color: var(--color-accent-text);
    font-weight: 600;
}
.reward-target {
    font-size: 0.85rem;
    color: var(--color-text-muted);
    margin-top: var(--space-xs);
}

.wb-prize-note {
    font-size: 0.85rem;
    color: var(--color-text-muted);
    margin-top: var(--space-sm);
    padding-top: var(--space-sm);
    border-top: 1px solid var(--color-border);
}

.cta-button {
    display: inline-flex;
    align-items: center;
    gap: var(--space-sm);
    background: var(--color-accent);
    color: #000;
    padding: var(--space-sm) var(--space-xl);
    border-radius: var(--radius-md);
    font-weight: 600;
    text-decoration: none;
    transition: all 0.15s;
}
.cta-button:hover {
    background: var(--color-accent-hover);
    transform: translateY(-1px);
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

@media (max-width: 767px) {
    .campaign-card {
        margin-left: -16px;
        margin-right: -16px;
        border-radius: 0 !important;
        border-left: none !important;
        border-right: none !important;
    }
    .campaign-header {
        flex-direction: column;
        gap: var(--space-xs);
    }
    .reward-code {
        font-size: 1.25rem;
        letter-spacing: 1px;
    }
    .cta-button {
        display: flex;
        width: 100%;
        justify-content: center;
        min-height: 48px;
        padding: var(--space-md);
    }
}
</style>

<div class="page-header">
    <h1 class="page-title">
        <i data-lucide="arrow-right-circle" class="page-icon"></i>
        Back to Gravity
    </h1>
</div>

<p class="wb-intro">Vi saknar dig på startlinjen! Svara på en kort enkät och få en rabattkod som tack.</p>

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

                <?php if (!empty($c['external_codes_enabled'])): ?>
                    <div class="reward-box">
                        <h4>Din belöning</h4>
                        <div class="reward-value">Rabattkod</div>
                        <?php if (!empty($c['external_event_name'])): ?>
                            <div class="reward-target">Gäller för: <?= htmlspecialchars($c['external_event_name']) ?></div>
                        <?php endif; ?>
                    </div>
                <?php elseif (!empty($c['discount_code'])): ?>
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

                <?php if (!empty($c['response']['discount_code'])): ?>
                    <div class="reward-box">
                        <h4>Din rabattkod</h4>
                        <div class="reward-code"><?= htmlspecialchars($c['response']['discount_code']) ?></div>
                        <?php if (!empty($c['external_codes_enabled'])): ?>
                            <?php if (!empty($c['external_event_name'])): ?>
                                <div class="reward-target">Gäller för: <?= htmlspecialchars($c['external_event_name']) ?></div>
                            <?php endif; ?>
                            <div style="font-size:0.85rem;color:var(--color-text-muted);margin-top:var(--space-xs);">
                                Ange koden vid anmälan på den externa plattformen.
                            </div>
                        <?php elseif ($c['discount_type'] === 'percentage'): ?>
                            <div class="reward-value"><?= intval($c['discount_value']) ?>% rabatt</div>
                        <?php else: ?>
                            <div class="reward-value"><?= number_format($c['discount_value'], 0) ?> kr rabatt</div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <p style="color: var(--color-text-muted); font-size: 0.875rem;">
                    <i data-lucide="calendar" style="width:14px;height:14px;vertical-align:middle;"></i>
                    Svarade <?= date('j M Y', strtotime($c['response']['submitted_at'])) ?>
                </p>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

<?php endif; ?>

<script>
lucide.createIcons();
</script>

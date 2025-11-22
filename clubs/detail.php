<?php
/**
 * Public Club Points Detail
 * Shows top point scorers for a club in a series
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/club-points-system.php';

$db = getDB();

// Get parameters
$clubId = isset($_GET['club_id']) ? (int)$_GET['club_id'] : 0;
$seriesId = isset($_GET['series_id']) ? (int)$_GET['series_id'] : 0;

if (!$clubId || !$seriesId) {
    header('Location: /clubs/leaderboard.php');
    exit;
}

// Get detailed breakdown
$detail = getClubPointsDetail($db, $clubId, $seriesId);

if (!$detail || !$detail['club']) {
    header('Location: /clubs/leaderboard.php');
    exit;
}

$club = $detail['club'];
$standing = $detail['standing'];
$events = $detail['events'];
$riderDetails = $detail['rider_details'];

// Get series info
$series = $db->getRow("SELECT * FROM series WHERE id = ?", [$seriesId]);

// Calculate top scorers across all events
$riderTotals = [];
foreach ($riderDetails as $eventId => $riders) {
    foreach ($riders as $rider) {
        $riderId = $rider['rider_id'];
        if (!isset($riderTotals[$riderId])) {
            $riderTotals[$riderId] = [
                'name' => $rider['firstname'] . ' ' . $rider['lastname'],
                'class' => $rider['class_name'] ?? 'Okänd',
                'total_points' => 0,
                'events' => 0
            ];
        }
        $riderTotals[$riderId]['total_points'] += $rider['club_points'];
        if ($rider['club_points'] > 0) {
            $riderTotals[$riderId]['events']++;
        }
    }
}

// Sort by total points
uasort($riderTotals, function($a, $b) {
    return $b['total_points'] <=> $a['total_points'];
});

// Get top 10
$topScorers = array_slice($riderTotals, 0, 10, true);

$pageTitle = $club['name'] . ' - Klubbpoäng';
$pageType = 'public';
include __DIR__ . '/../includes/layout-header.php';
?>

<style>
    .detail-container {
        max-width: 600px;
        margin: 0 auto;
    }

    .back-link {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        color: var(--gs-text-secondary);
        text-decoration: none;
        font-size: 0.875rem;
        margin-bottom: 1rem;
    }

    .back-link:hover {
        color: var(--gs-primary);
    }

    .club-header {
        text-align: center;
        margin-bottom: 1.5rem;
    }

    .club-logo {
        width: 80px;
        height: 80px;
        object-fit: contain;
        margin-bottom: 0.5rem;
    }

    .club-name {
        font-size: 1.5rem;
        font-weight: 700;
        color: var(--gs-text-primary);
        margin: 0 0 0.25rem 0;
    }

    .club-location {
        color: var(--gs-text-secondary);
        font-size: 0.875rem;
    }

    .stats-row {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 0.5rem;
        margin-bottom: 1.5rem;
    }

    .stat-box {
        background: white;
        border-radius: 8px;
        padding: 0.75rem;
        text-align: center;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    }

    .stat-value {
        font-size: 1.25rem;
        font-weight: 700;
        color: var(--gs-primary);
    }

    .stat-label {
        font-size: 0.625rem;
        color: var(--gs-text-secondary);
        text-transform: uppercase;
    }

    .section-title {
        font-size: 1rem;
        font-weight: 600;
        color: var(--gs-text-primary);
        margin: 0 0 0.75rem 0;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .scorer-list {
        background: white;
        border-radius: 12px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        overflow: hidden;
        margin-bottom: 1.5rem;
    }

    .scorer-item {
        display: flex;
        align-items: center;
        padding: 0.75rem 1rem;
        border-bottom: 1px solid #f3f4f6;
    }

    .scorer-item:last-child {
        border-bottom: none;
    }

    .scorer-rank {
        width: 32px;
        height: 32px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 0.875rem;
        margin-right: 0.75rem;
        flex-shrink: 0;
    }

    .scorer-item:nth-child(1) .scorer-rank {
        background: #f59e0b;
        color: white;
    }

    .scorer-item:nth-child(2) .scorer-rank {
        background: #9ca3af;
        color: white;
    }

    .scorer-item:nth-child(3) .scorer-rank {
        background: #f97316;
        color: white;
    }

    .scorer-item:nth-child(n+4) .scorer-rank {
        background: #e5e7eb;
        color: #6b7280;
    }

    .scorer-info {
        flex: 1;
        min-width: 0;
    }

    .scorer-name {
        font-weight: 600;
        font-size: 0.875rem;
        color: var(--gs-text-primary);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .scorer-class {
        font-size: 0.75rem;
        color: var(--gs-text-secondary);
    }

    .scorer-points {
        text-align: right;
        flex-shrink: 0;
        margin-left: 0.5rem;
    }

    .scorer-points-value {
        font-weight: 700;
        font-size: 1rem;
        color: var(--gs-primary);
    }

    .scorer-points-label {
        font-size: 0.625rem;
        color: var(--gs-text-secondary);
    }

    .empty-state {
        text-align: center;
        padding: 2rem;
        color: var(--gs-text-secondary);
    }
</style>

<main class="gs-main-content">
    <div class="gs-container">
        <div class="detail-container">
            <!-- Back Link -->
            <a href="/clubs/leaderboard.php?series_id=<?= $seriesId ?>" class="back-link">
                <i data-lucide="arrow-left" style="width: 16px; height: 16px;"></i>
                Tillbaka till ranking
            </a>

            <!-- Club Header -->
            <div class="club-header">
                <?php if ($club['logo']): ?>
                    <img src="<?= h($club['logo']) ?>" alt="" class="club-logo">
                <?php endif; ?>
                <h1 class="club-name"><?= h($club['name']) ?></h1>
                <?php if ($club['city']): ?>
                    <p class="club-location">
                        <?= h($club['city']) ?>
                        <?php if ($club['region']): ?>, <?= h($club['region']) ?><?php endif; ?>
                    </p>
                <?php endif; ?>
            </div>

            <!-- Stats -->
            <?php if ($standing): ?>
            <div class="stats-row">
                <div class="stat-box">
                    <div class="stat-value">#<?= $standing['ranking'] ?></div>
                    <div class="stat-label">Ranking</div>
                </div>
                <div class="stat-box">
                    <div class="stat-value"><?= number_format($standing['total_points']) ?></div>
                    <div class="stat-label">Poäng</div>
                </div>
                <div class="stat-box">
                    <div class="stat-value"><?= $standing['events_count'] ?></div>
                    <div class="stat-label">Events</div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Top Scorers -->
            <h2 class="section-title">
                <i data-lucide="star" style="width: 18px; height: 18px;"></i>
                Toppåkare
            </h2>

            <?php if (empty($topScorers)): ?>
                <div class="scorer-list">
                    <div class="empty-state">
                        Inga poänggivande åkare ännu
                    </div>
                </div>
            <?php else: ?>
                <div class="scorer-list">
                    <?php $rank = 1; foreach ($topScorers as $riderId => $scorer): ?>
                        <div class="scorer-item">
                            <div class="scorer-rank"><?= $rank ?></div>
                            <div class="scorer-info">
                                <div class="scorer-name"><?= h($scorer['name']) ?></div>
                                <div class="scorer-class"><?= h($scorer['class']) ?> • <?= $scorer['events'] ?> events</div>
                            </div>
                            <div class="scorer-points">
                                <div class="scorer-points-value"><?= number_format($scorer['total_points']) ?></div>
                                <div class="scorer-points-label">poäng</div>
                            </div>
                        </div>
                    <?php $rank++; endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Series Info -->
            <div class="gs-text-center gs-mt-lg gs-text-xs gs-text-secondary">
                <p><?= h($series['name'] ?? '') ?></p>
            </div>
        </div>
    </div>
</main>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>

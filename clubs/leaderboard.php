<?php
/**
 * Public Club Leaderboard
 * Mobile-first responsive club rankings with gold/silver/bronze visual ranking
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/club-points-system.php';

$db = getDB();

// Check if tables exist
$tablesExist = clubPointsTablesExist($db);

// Get all series for filter
$seriesList = $db->getAll("
    SELECT id, name, year, discipline
    FROM series
    WHERE active = 1
    ORDER BY year DESC, name ASC
");

// Get selected series
$selectedSeriesId = isset($_GET['series_id']) ? (int)$_GET['series_id'] : 0;
if (!$selectedSeriesId && !empty($seriesList)) {
    $selectedSeriesId = $seriesList[0]['id'];
}

// Get standings
$standings = [];
$seriesInfo = null;
if ($selectedSeriesId && $tablesExist) {
    $standings = getClubStandings($db, $selectedSeriesId);
    $seriesInfo = $db->getRow("SELECT * FROM series WHERE id = ?", [$selectedSeriesId]);
}

$pageTitle = 'Klubbranking';
$pageType = 'public';
include __DIR__ . '/../includes/layout-header.php';
?>

<style>
    .leaderboard-container {
        max-width: 800px;
        margin: 0 auto;
    }

    .series-selector {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
        padding: 0.5rem;
        margin: -0.5rem -0.5rem 1.5rem -0.5rem;
    }

    @media (max-width: 640px) {
        .series-selector {
            flex-wrap: nowrap;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: none;
        }

        .series-selector::-webkit-scrollbar {
            display: none;
        }
    }

    .series-btn {
        flex-shrink: 0;
        padding: 0.5rem 1rem;
        border: 2px solid #e5e7eb;
        background: white;
        border-radius: 999px;
        cursor: pointer;
        transition: all 0.2s;
        font-weight: 600;
        font-size: 0.875rem;
        color: #6b7280;
        text-decoration: none;
        white-space: nowrap;
    }

    .series-btn:hover,
    .series-btn.active {
        background: var(--gs-primary);
        color: white;
        border-color: var(--gs-primary);
    }

    .club-card {
        background: white;
        border-radius: 12px;
        padding: 1rem;
        margin-bottom: 0.75rem;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        display: flex;
        align-items: center;
        gap: 1rem;
        transition: transform 0.2s;
        cursor: pointer;
    }

    .club-card:hover {
        transform: translateY(-2px);
    }

    /* Podium styling */
    .club-card.rank-1 {
        background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
        border: 2px solid #f59e0b;
    }

    .club-card.rank-2 {
        background: linear-gradient(135deg, #f3f4f6 0%, #e5e7eb 100%);
        border: 2px solid #9ca3af;
    }

    .club-card.rank-3 {
        background: linear-gradient(135deg, #fed7aa 0%, #fdba74 100%);
        border: 2px solid #f97316;
    }

    .rank-badge {
        width: 48px;
        height: 48px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 1.25rem;
        flex-shrink: 0;
    }

    .rank-1 .rank-badge {
        background: #f59e0b;
        color: white;
    }

    .rank-2 .rank-badge {
        background: #9ca3af;
        color: white;
    }

    .rank-3 .rank-badge {
        background: #f97316;
        color: white;
    }

    .rank-other .rank-badge {
        background: #e5e7eb;
        color: #6b7280;
    }

    .club-info {
        flex: 1;
        min-width: 0;
    }

    .club-name {
        font-weight: 700;
        font-size: 1rem;
        color: #1f2937;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .club-meta {
        font-size: 0.75rem;
        color: #6b7280;
        margin-top: 0.25rem;
    }

    .club-stats {
        text-align: right;
        flex-shrink: 0;
    }

    .club-points {
        font-weight: 700;
        font-size: 1.5rem;
        color: var(--gs-primary);
        line-height: 1;
    }

    .club-points-label {
        font-size: 0.625rem;
        color: #9ca3af;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }

    .club-participants {
        font-size: 0.75rem;
        color: #6b7280;
        margin-top: 0.25rem;
    }

    /* Trophy icons for top 3 */
    .trophy-icon {
        font-size: 1.5rem;
        margin-bottom: 0.25rem;
    }

    .rank-1 .trophy-icon::before { content: '🥇'; }
    .rank-2 .trophy-icon::before { content: '🥈'; }
    .rank-3 .trophy-icon::before { content: '🥉'; }

    /* Summary stats */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 0.75rem;
        margin-bottom: 1.5rem;
    }

    .stat-card {
        background: white;
        border-radius: 8px;
        padding: 1rem;
        text-align: center;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    }

    .stat-value {
        font-size: 1.5rem;
        font-weight: 700;
        color: var(--gs-primary);
    }

    .stat-label {
        font-size: 0.75rem;
        color: #6b7280;
        text-transform: uppercase;
    }

    /* Mobile optimizations */
    @media (max-width: 640px) {
        .club-card {
            padding: 0.875rem;
        }

        .rank-badge {
            width: 40px;
            height: 40px;
            font-size: 1rem;
        }

        .club-name {
            font-size: 0.875rem;
        }

        .club-points {
            font-size: 1.25rem;
        }

        .stats-grid {
            grid-template-columns: repeat(3, 1fr);
        }

        .stat-value {
            font-size: 1.25rem;
        }

        .stat-label {
            font-size: 0.625rem;
        }
    }

    /* Empty state */
    .empty-state {
        text-align: center;
        padding: 3rem 1rem;
        background: white;
        border-radius: 12px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    }

    .empty-state-icon {
        font-size: 3rem;
        margin-bottom: 1rem;
    }
</style>

<main class="gs-main-content">
    <div class="gs-container">
        <div class="leaderboard-container">
            <!-- Header -->
            <div class="gs-text-center gs-mb-lg">
                <h1 class="gs-h2 gs-text-primary gs-mb-xs">
                    <i data-lucide="trophy"></i>
                    Klubbranking
                </h1>
                <?php if ($seriesInfo): ?>
                    <p class="gs-text-secondary"><?= h($seriesInfo['name']) ?></p>
                <?php endif; ?>
            </div>

            <!-- Series Selector -->
            <?php if (count($seriesList) > 1): ?>
            <div class="series-selector">
                <?php foreach ($seriesList as $series): ?>
                    <a href="?series_id=<?= $series['id'] ?>"
                       class="series-btn <?= $series['id'] == $selectedSeriesId ? 'active' : '' ?>">
                        <?= h($series['name']) ?>
                    </a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if (!$tablesExist): ?>
                <div class="empty-state">
                    <div class="empty-state-icon">⚙️</div>
                    <h3 class="gs-h4 gs-mb-sm">Systemet är inte konfigurerat</h3>
                    <p class="gs-text-secondary">Klubbpoängsystemet behöver konfigureras av en administratör.</p>
                </div>
            <?php elseif (empty($standings)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon">🏆</div>
                    <h3 class="gs-h4 gs-mb-sm">Inga klubbpoäng ännu</h3>
                    <p class="gs-text-secondary">Poängen uppdateras efter att resultat har registrerats.</p>
                </div>
            <?php else: ?>
                <!-- Summary Stats -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-value"><?= count($standings) ?></div>
                        <div class="stat-label">Klubbar</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value"><?= number_format(array_sum(array_column($standings, 'total_points'))) ?></div>
                        <div class="stat-label">Poäng</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value"><?= array_sum(array_column($standings, 'total_participants')) ?></div>
                        <div class="stat-label">Deltagare</div>
                    </div>
                </div>

                <!-- Club Cards -->
                <?php foreach ($standings as $club): ?>
                    <?php
                    $rankClass = 'rank-other';
                    if ($club['ranking'] == 1) $rankClass = 'rank-1';
                    elseif ($club['ranking'] == 2) $rankClass = 'rank-2';
                    elseif ($club['ranking'] == 3) $rankClass = 'rank-3';
                    ?>
                    <a href="/clubs/detail.php?club_id=<?= $club['club_id'] ?>&series_id=<?= $selectedSeriesId ?>" class="club-card <?= $rankClass ?>" style="text-decoration: none; color: inherit;">
                        <div class="rank-badge">
                            <?php if ($club['ranking'] <= 3): ?>
                                <span class="trophy-icon"></span>
                            <?php else: ?>
                                <?= $club['ranking'] ?>
                            <?php endif; ?>
                        </div>

                        <div class="club-info">
                            <div class="club-name"><?= h($club['club_name']) ?></div>
                            <div class="club-meta">
                                <?php if ($club['city']): ?>
                                    <?= h($club['city']) ?>
                                <?php endif; ?>
                                <?php if ($club['events_count']): ?>
                                    • <?= $club['events_count'] ?> events
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="club-stats">
                            <div class="club-points"><?= number_format($club['total_points']) ?></div>
                            <div class="club-points-label">poäng</div>
                            <div class="club-participants"><?= $club['total_participants'] ?> åkare</div>
                        </div>
                    </a>
                <?php endforeach; ?>

                <!-- Info -->
                <div class="gs-text-center gs-mt-lg gs-text-xs gs-text-secondary">
                    <p>Bästa åkare per klubb/klass: 100% • Näst bästa: 50%</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</main>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>

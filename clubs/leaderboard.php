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

<main class="gs-main-content">
    <div class="gs-container">
        <div class="gs-leaderboard-container">
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
            <?php
            // Separate Total series from regional/discipline series
            $totalSeries = [];
            $otherSeries = [];
            foreach ($seriesList as $series) {
                if (stripos($series['name'], 'Total') !== false) {
                    $totalSeries[] = $series;
                } else {
                    $otherSeries[] = $series;
                }
            }
            ?>
            <?php if (!empty($totalSeries)): ?>
            <div class="gs-series-selector gs-series-selector-main">
                <?php foreach ($totalSeries as $series): ?>
                    <a href="?series_id=<?= $series['id'] ?>"
                       class="gs-series-btn gs-series-btn-main <?= $series['id'] == $selectedSeriesId ? 'active' : '' ?>">
                        <?= h($series['name']) ?>
                    </a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <?php if (!empty($otherSeries)): ?>
            <div class="gs-series-selector">
                <?php foreach ($otherSeries as $series): ?>
                    <a href="?series_id=<?= $series['id'] ?>"
                       class="gs-series-btn <?= $series['id'] == $selectedSeriesId ? 'active' : '' ?>">
                        <?= h($series['name']) ?>
                    </a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <?php endif; ?>

            <?php if (!$tablesExist): ?>
                <div class="gs-card gs-text-center gs-empty-state-container">
                    <div class="gs-empty-state-icon">⚙️</div>
                    <h3 class="gs-h4 gs-mb-sm">Systemet är inte konfigurerat</h3>
                    <p class="gs-text-secondary">Klubbpoängsystemet behöver konfigureras av en administratör.</p>
                </div>
            <?php elseif (empty($standings)): ?>
                <div class="gs-card gs-text-center gs-empty-state-container">
                    <div class="gs-empty-state-icon">🏆</div>
                    <h3 class="gs-h4 gs-mb-sm">Inga klubbpoäng ännu</h3>
                    <p class="gs-text-secondary">Poängen uppdateras efter att resultat har registrerats.</p>
                </div>
            <?php else: ?>
                <!-- Summary Stats -->
                <div class="gs-stats-grid">
                    <div class="gs-stat-card">
                        <div class="gs-stat-value"><?= count($standings) ?></div>
                        <div class="gs-stat-label">Klubbar</div>
                    </div>
                    <div class="gs-stat-card">
                        <div class="gs-stat-value"><?= number_format(array_sum(array_column($standings, 'total_points'))) ?></div>
                        <div class="gs-stat-label">Poäng</div>
                    </div>
                    <div class="gs-stat-card">
                        <div class="gs-stat-value"><?= array_sum(array_column($standings, 'total_participants')) ?></div>
                        <div class="gs-stat-label">Deltagare</div>
                    </div>
                </div>

                <!-- Club Cards -->
                <?php foreach ($standings as $club): ?>
                    <?php
                    $rankClass = '';
                    if ($club['ranking'] == 1) $rankClass = 'rank-1';
                    elseif ($club['ranking'] == 2) $rankClass = 'rank-2';
                    elseif ($club['ranking'] == 3) $rankClass = 'rank-3';
                    ?>
                    <a href="/clubs/detail.php?club_id=<?= $club['club_id'] ?>&series_id=<?= $selectedSeriesId ?>" class="gs-club-card <?= $rankClass ?>">
                        <div class="gs-rank-badge">
                            <?php if ($club['ranking'] <= 3): ?>
                                <span class="gs-trophy-icon"><?php
                                    if ($club['ranking'] == 1) echo '🥇';
                                    elseif ($club['ranking'] == 2) echo '🥈';
                                    else echo '🥉';
                                ?></span>
                            <?php else: ?>
                                <?= $club['ranking'] ?>
                            <?php endif; ?>
                        </div>

                        <div class="gs-club-info">
                            <div class="gs-club-name"><?= h($club['club_name']) ?></div>
                            <div class="gs-club-meta">
                                <?php if ($club['city']): ?>
                                    <?= h($club['city']) ?>
                                <?php endif; ?>
                                <?php if ($club['events_count']): ?>
                                    • <?= $club['events_count'] ?> events
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="gs-club-stats">
                            <div class="gs-club-points"><?= number_format($club['total_points']) ?></div>
                            <div class="gs-club-points-label">poäng</div>
                            <div class="gs-club-participants"><?= $club['total_participants'] ?> åkare</div>
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

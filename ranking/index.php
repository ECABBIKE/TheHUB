<?php
/**
 * Public Ranking Page
 * Mobile-first responsive ranking display with gold/silver/bronze medals
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/ranking_functions.php';

$db = getDB();

// Check if tables exist
$tablesExist = rankingTablesExist($db);

// Pagination
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 50;
$offset = ($page - 1) * $perPage;

// Get current ranking
$ranking = ['riders' => [], 'total' => 0, 'snapshot_date' => null];
if ($tablesExist) {
    $ranking = getCurrentRanking($db, $perPage, $offset);
}

$totalPages = ceil($ranking['total'] / $perPage);

$pageTitle = 'GravitySeries Ranking';
$pageType = 'public';
include __DIR__ . '/../includes/layout-header.php';
?>

<main class="gs-main-content">
    <div class="gs-container">
        <div class="gs-ranking-container">
            <!-- Header -->
            <div class="gs-text-center gs-mb-lg">
                <h1 class="gs-h2 gs-text-primary gs-mb-xs">
                    <i data-lucide="trending-up"></i>
                    GravitySeries Ranking
                </h1>
                <?php if ($ranking['snapshot_date']): ?>
                    <p class="gs-text-secondary gs-text-sm">
                        Uppdaterad <?= date('j F Y', strtotime($ranking['snapshot_date'])) ?>
                    </p>
                <?php endif; ?>
            </div>

            <!-- Info Banner -->
            <div class="gs-ranking-info-banner gs-mb-lg">
                <i data-lucide="info" class="gs-text-primary"></i>
                <span>24 månaders rullande ranking baserad på resultat i GravitySeries Total. Poäng viktas efter fältstorlek och ålder.</span>
            </div>

            <?php if (!$tablesExist): ?>
                <div class="gs-card gs-text-center gs-empty-state-container">
                    <div class="gs-empty-state-icon">
                        <i data-lucide="settings" style="width: 48px; height: 48px;"></i>
                    </div>
                    <h3 class="gs-h4 gs-mb-sm">Systemet är inte konfigurerat</h3>
                    <p class="gs-text-secondary">Rankingsystemet behöver konfigureras av en administratör.</p>
                </div>
            <?php elseif (empty($ranking['riders'])): ?>
                <div class="gs-card gs-text-center gs-empty-state-container">
                    <div class="gs-empty-state-icon">
                        <i data-lucide="trophy" style="width: 48px; height: 48px;"></i>
                    </div>
                    <h3 class="gs-h4 gs-mb-sm">Ingen ranking ännu</h3>
                    <p class="gs-text-secondary">Rankingen uppdateras efter att resultat har registrerats och beräknats.</p>
                </div>
            <?php else: ?>
                <!-- Summary Stats -->
                <div class="gs-stats-grid gs-mb-lg">
                    <div class="gs-stat-card">
                        <div class="gs-stat-value"><?= $ranking['total'] ?></div>
                        <div class="gs-stat-label">Rankade</div>
                    </div>
                    <div class="gs-stat-card">
                        <div class="gs-stat-value">24</div>
                        <div class="gs-stat-label">Månader</div>
                    </div>
                </div>

                <!-- Ranking Cards (Mobile) -->
                <div class="gs-ranking-cards">
                    <?php foreach ($ranking['riders'] as $rider): ?>
                        <?php
                        $rankClass = '';
                        if ($rider['ranking_position'] == 1) $rankClass = 'rank-1';
                        elseif ($rider['ranking_position'] == 2) $rankClass = 'rank-2';
                        elseif ($rider['ranking_position'] == 3) $rankClass = 'rank-3';
                        ?>
                        <div class="gs-ranking-card <?= $rankClass ?>">
                            <div class="gs-rank-badge">
                                <?php if ($rider['ranking_position'] <= 3): ?>
                                    <span class="gs-medal"><?php
                                        if ($rider['ranking_position'] == 1) echo '🥇';
                                        elseif ($rider['ranking_position'] == 2) echo '🥈';
                                        else echo '🥉';
                                    ?></span>
                                <?php else: ?>
                                    <?= $rider['ranking_position'] ?>
                                <?php endif; ?>
                            </div>

                            <div class="gs-rider-info">
                                <div class="gs-rider-name"><?= h($rider['firstname'] . ' ' . $rider['lastname']) ?></div>
                                <div class="gs-rider-meta">
                                    <?php if ($rider['club_name']): ?>
                                        <?= h($rider['club_name']) ?>
                                    <?php endif; ?>
                                    <?php if ($rider['events_count']): ?>
                                        • <?= $rider['events_count'] ?> events
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="gs-rider-stats">
                                <div class="gs-rider-points"><?= number_format($rider['total_ranking_points'], 1) ?></div>
                                <div class="gs-rider-points-label">poäng</div>
                                <?php if ($rider['position_change'] !== null): ?>
                                    <div class="gs-position-change <?= $rider['position_change'] > 0 ? 'up' : ($rider['position_change'] < 0 ? 'down' : 'same') ?>">
                                        <?php if ($rider['position_change'] > 0): ?>
                                            <i data-lucide="chevron-up"></i> <?= $rider['position_change'] ?>
                                        <?php elseif ($rider['position_change'] < 0): ?>
                                            <i data-lucide="chevron-down"></i> <?= abs($rider['position_change']) ?>
                                        <?php else: ?>
                                            <i data-lucide="minus"></i>
                                        <?php endif; ?>
                                    </div>
                                <?php elseif ($rider['previous_position'] === null): ?>
                                    <div class="gs-position-change new">NY</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Ranking Table (Desktop) -->
                <div class="gs-ranking-table-wrapper gs-mb-lg">
                    <table class="gs-ranking-table">
                        <thead>
                            <tr>
                                <th style="width: 60px;">Pos</th>
                                <th>Åkare</th>
                                <th>Klubb</th>
                                <th class="gs-text-center">Events</th>
                                <th class="gs-text-center">Förändring</th>
                                <th class="gs-text-right">Poäng</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($ranking['riders'] as $rider): ?>
                                <tr>
                                    <td>
                                        <?php if ($rider['ranking_position'] <= 3): ?>
                                            <span class="gs-medal-badge gs-medal-<?= $rider['ranking_position'] ?>">
                                                <?php
                                                    if ($rider['ranking_position'] == 1) echo '🥇';
                                                    elseif ($rider['ranking_position'] == 2) echo '🥈';
                                                    else echo '🥉';
                                                ?>
                                            </span>
                                        <?php else: ?>
                                            <?= $rider['ranking_position'] ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong><?= h($rider['firstname'] . ' ' . $rider['lastname']) ?></strong>
                                    </td>
                                    <td class="gs-text-secondary"><?= h($rider['club_name'] ?? '-') ?></td>
                                    <td class="gs-text-center"><?= $rider['events_count'] ?></td>
                                    <td class="gs-text-center">
                                        <?php if ($rider['position_change'] !== null): ?>
                                            <?php if ($rider['position_change'] > 0): ?>
                                                <span class="gs-change-up">
                                                    <i data-lucide="chevron-up"></i> <?= $rider['position_change'] ?>
                                                </span>
                                            <?php elseif ($rider['position_change'] < 0): ?>
                                                <span class="gs-change-down">
                                                    <i data-lucide="chevron-down"></i> <?= abs($rider['position_change']) ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="gs-change-same">
                                                    <i data-lucide="minus"></i>
                                                </span>
                                            <?php endif; ?>
                                        <?php elseif ($rider['previous_position'] === null): ?>
                                            <span class="gs-badge gs-badge-accent">NY</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="gs-text-right">
                                        <div class="gs-points-breakdown">
                                            <strong><?= number_format($rider['total_ranking_points'], 1) ?></strong>
                                            <small class="gs-text-secondary gs-text-xs">
                                                (<?= number_format($rider['points_last_12_months'], 1) ?> + <?= number_format($rider['points_months_13_24'] * 0.5, 1) ?>)
                                            </small>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                    <div class="gs-pagination">
                        <?php if ($page > 1): ?>
                            <a href="?page=<?= $page - 1 ?>" class="gs-btn gs-btn-outline gs-btn-sm">
                                <i data-lucide="chevron-left"></i> Föregående
                            </a>
                        <?php endif; ?>

                        <span class="gs-pagination-info">
                            Sida <?= $page ?> av <?= $totalPages ?>
                        </span>

                        <?php if ($page < $totalPages): ?>
                            <a href="?page=<?= $page + 1 ?>" class="gs-btn gs-btn-outline gs-btn-sm">
                                Nästa <i data-lucide="chevron-right"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <!-- Info Footer -->
                <div class="gs-text-center gs-mt-lg gs-text-xs gs-text-secondary">
                    <p>Poäng = Originalpoäng × Fältstorlek × Tidsvikt</p>
                    <p class="gs-mt-xs">Månad 1-12: 100% • Månad 13-24: 50%</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</main>

<style>
/* Ranking-specific styles */
.gs-ranking-container {
    max-width: 900px;
    margin: 0 auto;
}

.gs-ranking-info-banner {
    display: flex;
    align-items: flex-start;
    gap: var(--gs-space-sm);
    padding: var(--gs-space-md);
    background: var(--gs-primary-light);
    border-radius: var(--gs-radius-md);
    font-size: 0.875rem;
}

.gs-ranking-info-banner i {
    flex-shrink: 0;
    width: 16px;
    height: 16px;
    margin-top: 2px;
}

/* Mobile ranking cards */
.gs-ranking-cards {
    display: flex;
    flex-direction: column;
    gap: var(--gs-space-sm);
}

.gs-ranking-card {
    display: flex;
    align-items: center;
    padding: var(--gs-space-md);
    background: var(--gs-white);
    border-radius: var(--gs-radius-md);
    box-shadow: var(--gs-shadow-sm);
    gap: var(--gs-space-md);
}

.gs-ranking-card.rank-1 {
    background: linear-gradient(135deg, rgba(255, 215, 0, 0.1), rgba(255, 215, 0, 0.05));
    border: 1px solid rgba(255, 215, 0, 0.3);
}

.gs-ranking-card.rank-2 {
    background: linear-gradient(135deg, rgba(192, 192, 192, 0.1), rgba(192, 192, 192, 0.05));
    border: 1px solid rgba(192, 192, 192, 0.3);
}

.gs-ranking-card.rank-3 {
    background: linear-gradient(135deg, rgba(205, 127, 50, 0.1), rgba(205, 127, 50, 0.05));
    border: 1px solid rgba(205, 127, 50, 0.3);
}

.gs-rank-badge {
    width: 40px;
    height: 40px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: bold;
    font-size: 1rem;
    background: var(--gs-light);
    border-radius: var(--gs-radius-sm);
    flex-shrink: 0;
}

.gs-medal {
    font-size: 1.5rem;
}

.gs-rider-info {
    flex: 1;
    min-width: 0;
}

.gs-rider-name {
    font-weight: 600;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.gs-rider-meta {
    font-size: 0.75rem;
    color: var(--gs-text-secondary);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.gs-rider-stats {
    text-align: right;
    flex-shrink: 0;
}

.gs-rider-points {
    font-weight: bold;
    font-size: 1.125rem;
    color: var(--gs-primary);
}

.gs-rider-points-label {
    font-size: 0.625rem;
    color: var(--gs-text-secondary);
    text-transform: uppercase;
}

.gs-position-change {
    font-size: 0.75rem;
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 2px;
    margin-top: 2px;
}

.gs-position-change i {
    width: 12px;
    height: 12px;
}

.gs-position-change.up {
    color: var(--gs-success);
}

.gs-position-change.down {
    color: var(--gs-danger);
}

.gs-position-change.same {
    color: var(--gs-text-secondary);
}

.gs-position-change.new {
    color: var(--gs-accent);
    font-weight: bold;
}

/* Desktop table */
.gs-ranking-table-wrapper {
    display: none;
}

.gs-ranking-table {
    width: 100%;
    border-collapse: collapse;
    background: var(--gs-white);
    border-radius: var(--gs-radius-md);
    overflow: hidden;
    box-shadow: var(--gs-shadow-sm);
}

.gs-ranking-table th,
.gs-ranking-table td {
    padding: var(--gs-space-sm) var(--gs-space-md);
    text-align: left;
    border-bottom: 1px solid var(--gs-border);
}

.gs-ranking-table th {
    background: var(--gs-light);
    font-weight: 600;
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

.gs-ranking-table tbody tr:last-child td {
    border-bottom: none;
}

.gs-medal-badge {
    display: inline-block;
    font-size: 1.25rem;
}

.gs-change-up {
    color: var(--gs-success);
    display: inline-flex;
    align-items: center;
    gap: 2px;
}

.gs-change-down {
    color: var(--gs-danger);
    display: inline-flex;
    align-items: center;
    gap: 2px;
}

.gs-change-same {
    color: var(--gs-text-secondary);
}

.gs-change-up i,
.gs-change-down i,
.gs-change-same i {
    width: 14px;
    height: 14px;
}

.gs-points-breakdown {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
}

/* Pagination */
.gs-pagination {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: var(--gs-space-md);
}

.gs-pagination-info {
    font-size: 0.875rem;
    color: var(--gs-text-secondary);
}

/* Desktop view */
@media (min-width: 768px) {
    .gs-ranking-cards {
        display: none;
    }

    .gs-ranking-table-wrapper {
        display: block;
    }
}
</style>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>

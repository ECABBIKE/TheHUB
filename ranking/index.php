<?php
/**
 * Public Ranking Page
 * Mobile-first responsive ranking display with Enduro/Downhill/Gravity tabs
 * Includes both rider and club rankings
 */

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

try {
    require_once __DIR__ . '/../config.php';
    require_once __DIR__ . '/../includes/ranking_functions.php';

    $db = getDB();
} catch (Exception $e) {
    echo "<h1>Initialization Error</h1>";
    echo "<pre>";
    echo "Message: " . htmlspecialchars($e->getMessage()) . "\n\n";
    echo "File: " . htmlspecialchars($e->getFile()) . "\n";
    echo "Line: " . $e->getLine() . "\n\n";
    echo "Stack trace:\n" . htmlspecialchars($e->getTraceAsString());
    echo "</pre>";
    exit;
}

// Check if tables exist
$tablesExist = rankingTablesExist($db);

// Get selected discipline
$discipline = isset($_GET['discipline']) ? strtoupper($_GET['discipline']) : 'GRAVITY';
if (!in_array($discipline, ['ENDURO', 'DH', 'GRAVITY'])) {
    $discipline = 'GRAVITY';
}

// Get selected view (riders or clubs)
$view = isset($_GET['view']) ? $_GET['view'] : 'riders';
if (!in_array($view, ['riders', 'clubs'])) {
    $view = 'riders';
}

// Pagination
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 50;
$offset = ($page - 1) * $perPage;

// Get current ranking based on view
if ($view === 'clubs') {
    $ranking = ['clubs' => [], 'total' => 0, 'snapshot_date' => null, 'discipline' => $discipline];
    if ($tablesExist) {
        try {
            $ranking = getCurrentClubRanking($db, $discipline, $perPage, $offset);
        } catch (Exception $e) {
            // Club ranking table doesn't exist yet
            $ranking = ['clubs' => [], 'total' => 0, 'snapshot_date' => null, 'discipline' => $discipline];
        }
    }
} else {
    $ranking = ['riders' => [], 'total' => 0, 'snapshot_date' => null, 'discipline' => $discipline];
    if ($tablesExist) {
        $ranking = getCurrentRanking($db, $discipline, $perPage, $offset);
    }
}

$totalPages = ceil($ranking['total'] / $perPage);

$pageTitle = getDisciplineDisplayName($discipline) . ' Ranking';
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

            <!-- Discipline Tabs -->
            <div class="gs-discipline-tabs gs-mb-md">
                <a href="?discipline=GRAVITY&view=<?= $view ?>" class="gs-discipline-tab <?= $discipline === 'GRAVITY' ? 'active' : '' ?>">
                    Gravity
                </a>
                <a href="?discipline=ENDURO&view=<?= $view ?>" class="gs-discipline-tab <?= $discipline === 'ENDURO' ? 'active' : '' ?>">
                    Enduro
                </a>
                <a href="?discipline=DH&view=<?= $view ?>" class="gs-discipline-tab <?= $discipline === 'DH' ? 'active' : '' ?>">
                    Downhill
                </a>
            </div>

            <!-- View Toggle (Riders/Clubs) -->
            <div class="gs-view-toggle gs-mb-lg">
                <a href="?discipline=<?= $discipline ?>&view=riders" class="gs-view-btn <?= $view === 'riders' ? 'active' : '' ?>">
                    <i data-lucide="users"></i>
                    Åkare
                </a>
                <a href="?discipline=<?= $discipline ?>&view=clubs" class="gs-view-btn <?= $view === 'clubs' ? 'active' : '' ?>">
                    <i data-lucide="shield"></i>
                    Klubbar
                </a>
            </div>

            <!-- Info Banner -->
            <div class="gs-ranking-info-banner gs-mb-lg">
                <i data-lucide="info" class="gs-text-primary"></i>
                <span>24 månaders rullande ranking. Poäng viktas efter fältstorlek och eventtyp (nationell/sportmotion).</span>
            </div>

            <?php if (!$tablesExist): ?>
                <div class="gs-card gs-text-center gs-empty-state-container">
                    <div class="gs-empty-state-icon">
                        <i data-lucide="settings" style="width: 48px; height: 48px;"></i>
                    </div>
                    <h3 class="gs-h4 gs-mb-sm">Systemet är inte konfigurerat</h3>
                    <p class="gs-text-secondary">Rankingsystemet behöver konfigureras av en administratör.</p>
                </div>
            <?php elseif (($view === 'riders' && empty($ranking['riders'])) || ($view === 'clubs' && empty($ranking['clubs']))): ?>
                <div class="gs-card gs-text-center gs-empty-state-container">
                    <div class="gs-empty-state-icon">
                        <i data-lucide="trophy" style="width: 48px; height: 48px;"></i>
                    </div>
                    <h3 class="gs-h4 gs-mb-sm">Ingen <?= getDisciplineDisplayName($discipline) ?>-ranking ännu</h3>
                    <p class="gs-text-secondary">Rankingen uppdateras efter att resultat har registrerats och beräknats.</p>
                </div>
            <?php else: ?>
                <!-- Summary Stats -->
                <div class="gs-stats-grid gs-mb-lg">
                    <div class="gs-stat-card">
                        <div class="gs-stat-value"><?= $ranking['total'] ?></div>
                        <div class="gs-stat-label"><?= $view === 'clubs' ? 'Klubbar' : 'Åkare' ?></div>
                    </div>
                    <div class="gs-stat-card">
                        <div class="gs-stat-value">24</div>
                        <div class="gs-stat-label">Månader</div>
                    </div>
                </div>

                <?php if ($view === 'riders'): ?>
                <!-- RIDERS VIEW -->
                <!-- Ranking Cards (Mobile) -->
                <div class="gs-ranking-cards">
                    <?php foreach ($ranking['riders'] as $rider): ?>
                        <?php
                        $rankClass = '';
                        if ($rider['ranking_position'] == 1) $rankClass = 'rank-1';
                        elseif ($rider['ranking_position'] == 2) $rankClass = 'rank-2';
                        elseif ($rider['ranking_position'] == 3) $rankClass = 'rank-3';
                        ?>
                        <a href="/ranking/rider.php?id=<?= $rider['rider_id'] ?>&discipline=<?= $discipline ?>" class="gs-ranking-card <?= $rankClass ?>">
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
                        </a>
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
                                <tr class="gs-table-row-clickable" onclick="window.location.href='/ranking/rider.php?id=<?= $rider['rider_id'] ?>&discipline=<?= $discipline ?>'">
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
                            <a href="?discipline=<?= $discipline ?>&view=<?= $view ?>&page=<?= $page - 1 ?>" class="gs-btn gs-btn-outline gs-btn-sm">
                                <i data-lucide="chevron-left"></i> Föregående
                            </a>
                        <?php endif; ?>

                        <span class="gs-pagination-info">
                            Sida <?= $page ?> av <?= $totalPages ?>
                        </span>

                        <?php if ($page < $totalPages): ?>
                            <a href="?discipline=<?= $discipline ?>&view=<?= $view ?>&page=<?= $page + 1 ?>" class="gs-btn gs-btn-outline gs-btn-sm">
                                Nästa <i data-lucide="chevron-right"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php else: ?>
                <!-- CLUBS VIEW -->
                <!-- Club Cards (Mobile) -->
                <div class="gs-ranking-cards">
                    <?php foreach ($ranking['clubs'] as $club): ?>
                        <?php
                        $rankClass = '';
                        if ($club['ranking_position'] == 1) $rankClass = 'rank-1';
                        elseif ($club['ranking_position'] == 2) $rankClass = 'rank-2';
                        elseif ($club['ranking_position'] == 3) $rankClass = 'rank-3';
                        ?>
                        <div class="gs-ranking-card <?= $rankClass ?>">
                            <div class="gs-rank-badge">
                                <?php if ($club['ranking_position'] <= 3): ?>
                                    <span class="gs-medal"><?php
                                        if ($club['ranking_position'] == 1) echo '🥇';
                                        elseif ($club['ranking_position'] == 2) echo '🥈';
                                        else echo '🥉';
                                    ?></span>
                                <?php else: ?>
                                    <?= $club['ranking_position'] ?>
                                <?php endif; ?>
                            </div>

                            <div class="gs-rider-info">
                                <div class="gs-rider-name"><?= h($club['club_name']) ?></div>
                                <div class="gs-rider-meta">
                                    <?php if ($club['city']): ?>
                                        <?= h($club['city']) ?>
                                    <?php endif; ?>
                                    <?php if ($club['events_count']): ?>
                                        • <?= $club['events_count'] ?> events
                                    <?php endif; ?>
                                    <?php if ($club['riders_count']): ?>
                                        • <?= $club['riders_count'] ?> åkare
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="gs-rider-stats">
                                <div class="gs-rider-points"><?= number_format($club['total_ranking_points'], 1) ?></div>
                                <div class="gs-rider-points-label">poäng</div>
                                <?php if ($club['position_change'] !== null): ?>
                                    <div class="gs-position-change <?= $club['position_change'] > 0 ? 'up' : ($club['position_change'] < 0 ? 'down' : 'same') ?>">
                                        <?php if ($club['position_change'] > 0): ?>
                                            <i data-lucide="chevron-up"></i> <?= $club['position_change'] ?>
                                        <?php elseif ($club['position_change'] < 0): ?>
                                            <i data-lucide="chevron-down"></i> <?= abs($club['position_change']) ?>
                                        <?php else: ?>
                                            <i data-lucide="minus"></i>
                                        <?php endif; ?>
                                    </div>
                                <?php elseif ($club['previous_position'] === null): ?>
                                    <div class="gs-position-change new">NY</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Club Table (Desktop) -->
                <div class="gs-ranking-table-wrapper gs-mb-lg">
                    <table class="gs-ranking-table">
                        <thead>
                            <tr>
                                <th style="width: 60px;">Pos</th>
                                <th>Klubb</th>
                                <th>Ort</th>
                                <th class="gs-text-center">Åkare</th>
                                <th class="gs-text-center">Events</th>
                                <th class="gs-text-center">Förändring</th>
                                <th class="gs-text-right">Poäng</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($ranking['clubs'] as $club): ?>
                                <tr>
                                    <td>
                                        <?php if ($club['ranking_position'] <= 3): ?>
                                            <span class="gs-medal-badge gs-medal-<?= $club['ranking_position'] ?>">
                                                <?php
                                                    if ($club['ranking_position'] == 1) echo '🥇';
                                                    elseif ($club['ranking_position'] == 2) echo '🥈';
                                                    else echo '🥉';
                                                ?>
                                            </span>
                                        <?php else: ?>
                                            <?= $club['ranking_position'] ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong><?= h($club['club_name']) ?></strong>
                                    </td>
                                    <td class="gs-text-secondary"><?= h($club['city'] ?? '-') ?></td>
                                    <td class="gs-text-center"><?= $club['riders_count'] ?></td>
                                    <td class="gs-text-center"><?= $club['events_count'] ?></td>
                                    <td class="gs-text-center">
                                        <?php if ($club['position_change'] !== null): ?>
                                            <?php if ($club['position_change'] > 0): ?>
                                                <span class="gs-change-up">
                                                    <i data-lucide="chevron-up"></i> <?= $club['position_change'] ?>
                                                </span>
                                            <?php elseif ($club['position_change'] < 0): ?>
                                                <span class="gs-change-down">
                                                    <i data-lucide="chevron-down"></i> <?= abs($club['position_change']) ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="gs-change-same">
                                                    <i data-lucide="minus"></i>
                                                </span>
                                            <?php endif; ?>
                                        <?php elseif ($club['previous_position'] === null): ?>
                                            <span class="gs-badge gs-badge-accent">NY</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="gs-text-right">
                                        <div class="gs-points-breakdown">
                                            <strong><?= number_format($club['total_ranking_points'], 1) ?></strong>
                                            <small class="gs-text-secondary gs-text-xs">
                                                (<?= number_format($club['points_last_12_months'], 1) ?> + <?= number_format($club['points_months_13_24'] * 0.5, 1) ?>)
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
                            <a href="?discipline=<?= $discipline ?>&view=<?= $view ?>&page=<?= $page - 1 ?>" class="gs-btn gs-btn-outline gs-btn-sm">
                                <i data-lucide="chevron-left"></i> Föregående
                            </a>
                        <?php endif; ?>

                        <span class="gs-pagination-info">
                            Sida <?= $page ?> av <?= $totalPages ?>
                        </span>

                        <?php if ($page < $totalPages): ?>
                            <a href="?discipline=<?= $discipline ?>&view=<?= $view ?>&page=<?= $page + 1 ?>" class="gs-btn gs-btn-outline gs-btn-sm">
                                Nästa <i data-lucide="chevron-right"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                <?php endif; ?>

                <!-- Info Footer -->
                <div class="gs-text-center gs-mt-lg gs-text-xs gs-text-secondary">
                    <?php if ($view === 'clubs'): ?>
                        <p>Klubbar rankas baserat på sammantagna rankingpoäng från alla sina åkare</p>
                        <p class="gs-mt-xs">Poäng = Summa av åkarnas rankingpoäng (viktade efter fältstorlek, eventtyp och tid)</p>
                    <?php else: ?>
                        <p>Poäng = Originalpoäng × Fältstorlek × Eventtyp × Tidsvikt</p>
                        <p class="gs-mt-xs">Månad 1-12: 100% • Månad 13-24: 50%</p>
                    <?php endif; ?>
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

/* Discipline tabs */
.gs-discipline-tabs {
    display: flex;
    justify-content: center;
    gap: var(--gs-space-xs);
    background: var(--gs-light);
    padding: var(--gs-space-xs);
    border-radius: var(--gs-radius-lg);
}

.gs-discipline-tab {
    padding: var(--gs-space-sm) var(--gs-space-lg);
    border-radius: var(--gs-radius-md);
    font-weight: 500;
    font-size: 0.875rem;
    color: var(--gs-text-secondary);
    text-decoration: none;
    transition: all 0.2s;
}

.gs-discipline-tab:hover {
    color: var(--gs-primary);
    background: var(--gs-white);
}

.gs-discipline-tab.active {
    background: var(--gs-primary);
    color: var(--gs-white);
    box-shadow: var(--gs-shadow-sm);
}

/* View toggle (Riders/Clubs) */
.gs-view-toggle {
    display: flex;
    justify-content: center;
    gap: var(--gs-space-xs);
}

.gs-view-btn {
    padding: var(--gs-space-sm) var(--gs-space-lg);
    border-radius: var(--gs-radius-md);
    font-weight: 500;
    font-size: 0.875rem;
    color: var(--gs-text-secondary);
    text-decoration: none;
    transition: all 0.2s;
    background: var(--gs-white);
    border: 1px solid var(--gs-border);
    display: flex;
    align-items: center;
    gap: var(--gs-space-xs);
}

.gs-view-btn i {
    width: 16px;
    height: 16px;
}

.gs-view-btn:hover {
    color: var(--gs-primary);
    border-color: var(--gs-primary);
}

.gs-view-btn.active {
    background: var(--gs-primary);
    color: var(--gs-white);
    border-color: var(--gs-primary);
    box-shadow: var(--gs-shadow-sm);
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
    display: grid;
    grid-template-columns: 50px 1fr auto;
    align-items: center;
    padding: var(--gs-space-sm) var(--gs-space-md);
    background: var(--gs-white);
    border-radius: var(--gs-radius-md);
    box-shadow: var(--gs-shadow-sm);
    gap: var(--gs-space-md);
    text-decoration: none;
    color: inherit;
    transition: all 0.2s;
    cursor: pointer;
}

.gs-ranking-card:hover {
    transform: translateY(-2px);
    box-shadow: var(--gs-shadow-md);
}

.gs-ranking-card:active {
    transform: translateY(0);
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
    width: 44px;
    height: 44px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: bold;
    font-size: 1.125rem;
    background: var(--gs-light);
    border-radius: var(--gs-radius-md);
    flex-shrink: 0;
}

.gs-medal {
    font-size: 1.75rem;
}

.gs-rider-info {
    min-width: 0;
}

.gs-rider-name {
    font-weight: 600;
    font-size: 0.9375rem;
    line-height: 1.3;
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
    margin-top: 2px;
}

.gs-rider-stats {
    text-align: right;
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 4px;
}

.gs-rider-points {
    font-weight: bold;
    font-size: 1.25rem;
    color: var(--gs-primary);
    line-height: 1;
}

.gs-rider-points-label {
    font-size: 0.625rem;
    color: var(--gs-text-secondary);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.gs-position-change {
    font-size: 0.75rem;
    display: none; /* Hidden in portrait */
    align-items: center;
    justify-content: flex-end;
    gap: 2px;
    font-weight: 600;
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

.gs-table-row-clickable {
    cursor: pointer;
    transition: background-color 0.2s;
}

.gs-table-row-clickable:hover {
    background-color: var(--gs-primary-light);
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

/* Landscape mobile - show position changes */
@media (min-width: 568px) and (max-width: 767px) {
    .gs-position-change {
        display: flex;
    }

    .gs-ranking-card {
        grid-template-columns: 50px 1fr auto auto;
        gap: var(--gs-space-sm);
    }

    .gs-rider-stats {
        flex-direction: row;
        gap: var(--gs-space-md);
        align-items: center;
    }

    .gs-position-change {
        padding: 4px 8px;
        background: var(--gs-light);
        border-radius: var(--gs-radius-sm);
    }
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

<?php
/**
 * Rider Ranking Profile Page
 * Detailed ranking information for a specific rider
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/ranking_functions.php';

$db = getDB();

// Get rider ID and discipline from URL
$riderId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$discipline = isset($_GET['discipline']) ? strtoupper($_GET['discipline']) : 'GRAVITY';

if (!in_array($discipline, ['ENDURO', 'DH', 'GRAVITY'])) {
    $discipline = 'GRAVITY';
}

if (!$riderId) {
    header('Location: /ranking/');
    exit;
}

// Get rider information
$rider = $db->getRow("
    SELECT r.*, c.name as club_name, c.id as club_id
    FROM riders r
    LEFT JOIN clubs c ON r.club_id = c.id
    WHERE r.id = ?
", [$riderId]);

if (!$rider) {
    header('Location: /ranking/');
    exit;
}

// Get rider's ranking data
$riderData = calculateRankingData($db, $discipline, false);
$riderRanking = null;

foreach ($riderData as $data) {
    if ($data['rider_id'] == $riderId) {
        $riderRanking = $data;
        break;
    }
}

// Get rider's recent results (last 24 months)
$cutoffDate = date('Y-m-d', strtotime('-24 months'));
$disciplineFilter = '';
$params = [$riderId, $cutoffDate];

if ($discipline !== 'GRAVITY') {
    $disciplineFilter = 'AND e.discipline = ?';
    $params[] = $discipline;
}

$results = $db->getAll("
    SELECT
        r.points,
        r.run_1_points,
        r.run_2_points,
        e.name as event_name,
        e.date as event_date,
        e.discipline,
        e.event_level,
        cl.name as class_name
    FROM results r
    JOIN events e ON r.event_id = e.id
    JOIN classes cl ON r.class_id = cl.id
    WHERE r.cyclist_id = ?
    AND r.status = 'finished'
    AND (r.points > 0 OR COALESCE(r.run_1_points, 0) > 0 OR COALESCE(r.run_2_points, 0) > 0)
    AND e.date >= ?
    {$disciplineFilter}
    AND COALESCE(cl.series_eligible, 1) = 1
    AND COALESCE(cl.awards_points, 1) = 1
    ORDER BY e.date DESC
", $params);

$pageTitle = $rider['firstname'] . ' ' . $rider['lastname'] . ' - ' . getDisciplineDisplayName($discipline) . ' Ranking';
$pageType = 'public';
include __DIR__ . '/../includes/layout-header.php';
?>

<main class="gs-main-content">
    <div class="gs-container">
        <div class="gs-rider-profile-container">
            <!-- Back Button -->
            <div class="gs-mb-md">
                <a href="/ranking/?discipline=<?= $discipline ?>&view=riders" class="gs-btn gs-btn-outline gs-btn-sm">
                    <i data-lucide="arrow-left"></i> Tillbaka till ranking
                </a>
            </div>

            <!-- Rider Header -->
            <div class="gs-rider-header gs-mb-lg">
                <div class="gs-rider-avatar">
                    <i data-lucide="user" style="width: 48px; height: 48px;"></i>
                </div>
                <div class="gs-rider-header-info">
                    <h1 class="gs-h2 gs-text-primary gs-mb-xs">
                        <?= h($rider['firstname'] . ' ' . $rider['lastname']) ?>
                    </h1>
                    <?php if ($rider['club_name']): ?>
                        <p class="gs-text-secondary gs-mb-xs">
                            <i data-lucide="shield" style="width: 16px; height: 16px;"></i>
                            <?= h($rider['club_name']) ?>
                        </p>
                    <?php endif; ?>
                    <?php if ($rider['birth_year']): ?>
                        <p class="gs-text-secondary gs-text-sm">
                            Född <?= $rider['birth_year'] ?>
                        </p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Discipline Tabs -->
            <div class="gs-discipline-tabs gs-mb-lg">
                <a href="?id=<?= $riderId ?>&discipline=GRAVITY" class="gs-discipline-tab <?= $discipline === 'GRAVITY' ? 'active' : '' ?>">
                    Gravity
                </a>
                <a href="?id=<?= $riderId ?>&discipline=ENDURO" class="gs-discipline-tab <?= $discipline === 'ENDURO' ? 'active' : '' ?>">
                    Enduro
                </a>
                <a href="?id=<?= $riderId ?>&discipline=DH" class="gs-discipline-tab <?= $discipline === 'DH' ? 'active' : '' ?>">
                    Downhill
                </a>
            </div>

            <?php if ($riderRanking): ?>
                <!-- Ranking Stats Cards -->
                <div class="gs-stats-grid gs-mb-lg">
                    <div class="gs-stat-card">
                        <div class="gs-stat-icon">
                            <i data-lucide="trophy"></i>
                        </div>
                        <div>
                            <div class="gs-stat-value">#<?= $riderRanking['ranking_position'] ?></div>
                            <div class="gs-stat-label">Placering</div>
                        </div>
                    </div>
                    <div class="gs-stat-card">
                        <div class="gs-stat-icon">
                            <i data-lucide="target"></i>
                        </div>
                        <div>
                            <div class="gs-stat-value"><?= number_format($riderRanking['total_points'], 1) ?></div>
                            <div class="gs-stat-label">Totala poäng</div>
                        </div>
                    </div>
                    <div class="gs-stat-card">
                        <div class="gs-stat-icon">
                            <i data-lucide="calendar"></i>
                        </div>
                        <div>
                            <div class="gs-stat-value"><?= $riderRanking['events_count'] ?></div>
                            <div class="gs-stat-label">Events</div>
                        </div>
                    </div>
                </div>

                <!-- Points Breakdown -->
                <div class="gs-card gs-mb-lg">
                    <div class="gs-card-header">
                        <h2 class="gs-h4 gs-text-primary">
                            <i data-lucide="bar-chart-2"></i>
                            Poängfördelning
                        </h2>
                    </div>
                    <div class="gs-card-content">
                        <div class="gs-points-breakdown-detail">
                            <div class="gs-points-row">
                                <span class="gs-points-label">
                                    <i data-lucide="calendar-check" style="width: 16px; height: 16px;"></i>
                                    Senaste 12 månader (100%)
                                </span>
                                <span class="gs-points-value gs-text-success"><?= number_format($riderRanking['points_12'], 1) ?></span>
                            </div>
                            <div class="gs-points-row">
                                <span class="gs-points-label">
                                    <i data-lucide="calendar" style="width: 16px; height: 16px;"></i>
                                    Månad 13-24 (50% vikt)
                                </span>
                                <span class="gs-points-value gs-text-secondary"><?= number_format($riderRanking['points_13_24'], 1) ?></span>
                            </div>
                            <div class="gs-points-row">
                                <span class="gs-points-label gs-font-bold">
                                    <i data-lucide="award" style="width: 16px; height: 16px;"></i>
                                    Viktade poäng (bidrar till ranking)
                                </span>
                                <span class="gs-points-value gs-text-primary gs-font-bold"><?= number_format($riderRanking['points_13_24'] * 0.5, 1) ?></span>
                            </div>
                            <hr class="gs-my-sm">
                            <div class="gs-points-row gs-total">
                                <span class="gs-points-label gs-font-bold gs-text-lg">
                                    <i data-lucide="trophy" style="width: 18px; height: 18px;"></i>
                                    Totala rankingpoäng
                                </span>
                                <span class="gs-points-value gs-text-primary gs-font-bold gs-text-xl"><?= number_format($riderRanking['total_points'], 1) ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="gs-card gs-text-center gs-mb-lg">
                    <div class="gs-card-content">
                        <p class="gs-text-secondary">Ingen ranking för <?= getDisciplineDisplayName($discipline) ?> ännu</p>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Recent Results -->
            <div class="gs-card">
                <div class="gs-card-header">
                    <h2 class="gs-h4 gs-text-primary">
                        <i data-lucide="list"></i>
                        Resultat (senaste 24 mån)
                    </h2>
                </div>
                <div class="gs-card-content">
                    <?php if (empty($results)): ?>
                        <p class="gs-text-secondary gs-text-center">Inga resultat för <?= getDisciplineDisplayName($discipline) ?></p>
                    <?php else: ?>
                        <div class="gs-results-list">
                            <?php foreach ($results as $result): ?>
                                <?php
                                $points = $result['run_1_points'] || $result['run_2_points']
                                    ? ($result['run_1_points'] + $result['run_2_points'])
                                    : $result['points'];
                                ?>
                                <div class="gs-result-item">
                                    <div class="gs-result-date">
                                        <?= date('j M Y', strtotime($result['event_date'])) ?>
                                    </div>
                                    <div class="gs-result-event">
                                        <div class="gs-result-event-name"><?= h($result['event_name']) ?></div>
                                        <div class="gs-result-meta">
                                            <?= h($result['class_name']) ?>
                                            <?php if ($result['event_level'] === 'sportmotion'): ?>
                                                <span class="gs-badge gs-badge-sm">Sportmotion</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="gs-result-points">
                                        <?= number_format($points, 1) ?> p
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</main>

<style>
.gs-rider-profile-container {
    max-width: 800px;
    margin: 0 auto;
}

.gs-rider-header {
    display: flex;
    align-items: center;
    gap: var(--gs-space-lg);
    padding: var(--gs-space-lg);
    background: var(--gs-white);
    border-radius: var(--gs-radius-lg);
    box-shadow: var(--gs-shadow-sm);
}

.gs-rider-avatar {
    width: 80px;
    height: 80px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--gs-primary-light);
    border-radius: var(--gs-radius-full);
    color: var(--gs-primary);
    flex-shrink: 0;
}

.gs-rider-header-info {
    flex: 1;
}

.gs-rider-header-info p {
    display: flex;
    align-items: center;
    gap: var(--gs-space-xs);
}

.gs-rider-header-info i {
    flex-shrink: 0;
}

.gs-stat-card {
    display: flex;
    align-items: center;
    gap: var(--gs-space-md);
}

.gs-stat-icon {
    width: 48px;
    height: 48px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--gs-primary-light);
    border-radius: var(--gs-radius-md);
    color: var(--gs-primary);
    flex-shrink: 0;
}

.gs-stat-icon i {
    width: 24px;
    height: 24px;
}

.gs-points-breakdown-detail {
    display: flex;
    flex-direction: column;
    gap: var(--gs-space-sm);
}

.gs-points-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: var(--gs-space-sm);
    background: var(--gs-light);
    border-radius: var(--gs-radius-sm);
}

.gs-points-row.gs-total {
    background: var(--gs-primary-light);
    padding: var(--gs-space-md);
}

.gs-points-label {
    display: flex;
    align-items: center;
    gap: var(--gs-space-xs);
    font-size: 0.9375rem;
}

.gs-points-value {
    font-size: 1.125rem;
    font-weight: 600;
}

.gs-results-list {
    display: flex;
    flex-direction: column;
    gap: var(--gs-space-xs);
}

.gs-result-item {
    display: grid;
    grid-template-columns: auto 1fr auto;
    gap: var(--gs-space-md);
    align-items: center;
    padding: var(--gs-space-sm) var(--gs-space-md);
    background: var(--gs-light);
    border-radius: var(--gs-radius-sm);
}

.gs-result-date {
    font-size: 0.875rem;
    color: var(--gs-text-secondary);
    white-space: nowrap;
}

.gs-result-event {
    min-width: 0;
}

.gs-result-event-name {
    font-weight: 600;
    font-size: 0.9375rem;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.gs-result-meta {
    font-size: 0.75rem;
    color: var(--gs-text-secondary);
    display: flex;
    align-items: center;
    gap: var(--gs-space-xs);
}

.gs-result-points {
    font-weight: bold;
    color: var(--gs-primary);
    white-space: nowrap;
}

@media (max-width: 567px) {
    .gs-rider-header {
        flex-direction: column;
        text-align: center;
    }

    .gs-rider-header-info p {
        justify-content: center;
    }

    .gs-result-item {
        grid-template-columns: 1fr;
        gap: var(--gs-space-xs);
    }

    .gs-result-date {
        font-size: 0.75rem;
    }

    .gs-result-points {
        text-align: left;
    }
}
</style>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>

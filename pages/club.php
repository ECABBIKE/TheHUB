<?php
/**
 * V3.5 Club Profile Page - Large logo, all-time members with year badges
 */

$db = hub_db();
$clubId = intval($pageInfo['params']['id'] ?? 0);

// Include club achievements system
$achievementsClubPath = dirname(__DIR__) . '/includes/achievements-club.php';
if (file_exists($achievementsClubPath)) {
    require_once $achievementsClubPath;
}

// Include ranking functions
$rankingFunctionsLoaded = false;
$rankingPaths = [
    dirname(__DIR__) . '/includes/ranking_functions.php',
    __DIR__ . '/../includes/ranking_functions.php',
];
foreach ($rankingPaths as $path) {
    if (file_exists($path)) {
        require_once $path;
        $rankingFunctionsLoaded = true;
        break;
    }
}

if (!$clubId) {
    header('Location: /riders');
    exit;
}

$currentYear = (int)date('Y');

try {
    // Fetch club details
    $stmt = $db->prepare("SELECT * FROM clubs WHERE id = ?");
    $stmt->execute([$clubId]);
    $club = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$club) {
        include HUB_V3_ROOT . '/pages/404.php';
        return;
    }

    // Get ALL unique members across all years with their membership years AND stats
    $stmt = $db->prepare("
        SELECT
            r.id,
            r.firstname,
            r.lastname,
            r.birth_year,
            r.gender,
            GROUP_CONCAT(DISTINCT rcs.season_year ORDER BY rcs.season_year DESC SEPARATOR ',') as member_years,
            COALESCE(r.stats_total_starts, 0) as total_races,
            COALESCE(r.stats_total_wins, 0) as total_wins,
            COALESCE(r.stats_total_podiums, 0) as total_podiums
        FROM riders r
        INNER JOIN rider_club_seasons rcs ON r.id = rcs.rider_id AND rcs.club_id = ?
        WHERE r.active = 1
        GROUP BY r.id
        ORDER BY r.lastname, r.firstname
    ");
    $stmt->execute([$clubId]);
    $allMembers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Also get riders with current club_id but no season records
    $stmt = $db->prepare("
        SELECT
            r.id,
            r.firstname,
            r.lastname,
            r.birth_year,
            r.gender,
            COALESCE(r.stats_total_starts, 0) as total_races,
            COALESCE(r.stats_total_wins, 0) as total_wins,
            COALESCE(r.stats_total_podiums, 0) as total_podiums
        FROM riders r
        LEFT JOIN rider_club_seasons rcs ON r.id = rcs.rider_id AND rcs.club_id = ?
        WHERE r.club_id = ? AND r.active = 1 AND rcs.id IS NULL
        ORDER BY r.lastname, r.firstname
    ");
    $stmt->execute([$clubId, $clubId]);
    $currentMembers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Merge: add current year to those without season records
    foreach ($currentMembers as $member) {
        $member['member_years'] = (string)$currentYear;
        $allMembers[] = $member;
    }

    // Get unique member IDs
    $memberIds = array_unique(array_column($allMembers, 'id'));
    $uniqueMembers = [];
    foreach ($allMembers as $m) {
        if (!isset($uniqueMembers[$m['id']])) {
            $uniqueMembers[$m['id']] = $m;
        } else {
            // Merge years if duplicate
            $existingYears = explode(',', $uniqueMembers[$m['id']]['member_years']);
            $newYears = explode(',', $m['member_years']);
            $allYears = array_unique(array_merge($existingYears, $newYears));
            rsort($allYears);
            $uniqueMembers[$m['id']]['member_years'] = implode(',', $allYears);
        }
    }
    $members = array_values($uniqueMembers);

    // Get available years for stats
    $stmt = $db->prepare("
        SELECT DISTINCT season_year
        FROM rider_club_seasons
        WHERE club_id = ?
        ORDER BY season_year DESC
    ");
    $stmt->execute([$clubId]);
    $availableYears = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'season_year');

    // Calculate total stats across all years
    $totalUniqueMembers = count($members);

    // Get total results count for this club's members
    if (!empty($memberIds)) {
        $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
        $stmt = $db->prepare("
            SELECT COUNT(DISTINCT res.id) as total_races,
                   SUM(res.points) as total_points
            FROM results res
            WHERE res.cyclist_id IN ($placeholders) AND res.status = 'finished'
        ");
        $stmt->execute($memberIds);
        $statsRow = $stmt->fetch(PDO::FETCH_ASSOC);
        $totalRaces = (int)($statsRow['total_races'] ?? 0);
        $totalPoints = (int)($statsRow['total_points'] ?? 0);
    } else {
        $totalRaces = 0;
        $totalPoints = 0;
    }

    // Get club ranking position
    $clubRankingPosition = null;
    $clubRankingPoints = 0;
    $clubRidersCount = 0;
    $clubEventsCount = 0;
    $parentDb = function_exists('getDB') ? getDB() : null;
    if ($rankingFunctionsLoaded && $parentDb && function_exists('getSingleClubRanking')) {
        $clubRanking = getSingleClubRanking($parentDb, $clubId, 'GRAVITY');
        if ($clubRanking) {
            $clubRankingPosition = $clubRanking['ranking_position'] ?? null;
            $clubRankingPoints = $clubRanking['total_ranking_points'] ?? 0;
            $clubRidersCount = $clubRanking['riders_count'] ?? 0;
            $clubEventsCount = $clubRanking['events_count'] ?? 0;
        }
    }

    // Get club ranking history from snapshots (for graph)
    $clubRankingHistory = [];
    $clubRankingHistoryFull = [];
    try {
        $historyStmt = $db->prepare("
            SELECT
                snapshot_date,
                DATE_FORMAT(snapshot_date, '%Y-%m') as month,
                DATE_FORMAT(snapshot_date, '%b') as month_short,
                ranking_position,
                total_ranking_points,
                riders_count,
                events_count,
                position_change
            FROM club_ranking_snapshots
            WHERE club_id = ? AND discipline = 'GRAVITY'
            ORDER BY snapshot_date ASC
        ");
        $historyStmt->execute([$clubId]);
        $allSnapshots = $historyStmt->fetchAll(PDO::FETCH_ASSOC);

        // Store full history for graph (limit to 50 entries for performance)
        $clubRankingHistoryFull = array_slice($allSnapshots, -50);

        // Group by month for compact display (take latest per month)
        $byMonth = [];
        foreach ($allSnapshots as $snap) {
            $byMonth[$snap['month']] = $snap;
        }
        $clubRankingHistory = array_values($byMonth);
        $clubRankingHistory = array_slice($clubRankingHistory, -6);
    } catch (Exception $e) {
        // Ignore errors
    }

    // Calculate ranking change from start
    $clubRankingChange = 0;
    if (!empty($clubRankingHistory) && $clubRankingPosition) {
        $startPosition = $clubRankingHistory[0]['ranking_position'] ?? $clubRankingPosition;
        $clubRankingChange = $startPosition - $clubRankingPosition; // Positive = improved
    }

    // Count members per year for display
    $membersPerYear = [];
    foreach ($availableYears as $year) {
        $membersPerYear[$year] = 0;
    }
    foreach ($members as $m) {
        $years = explode(',', $m['member_years']);
        foreach ($years as $y) {
            if (isset($membersPerYear[$y])) {
                $membersPerYear[$y]++;
            }
        }
    }

} catch (Exception $e) {
    $error = $e->getMessage();
    $club = null;
}

if (!$club) {
    include HUB_V3_ROOT . '/pages/404.php';
    return;
}

// Check for club logo
$clubLogo = null;
$clubLogoDir = dirname(__DIR__) . '/uploads/clubs/';
$clubLogoUrl = '/uploads/clubs/';
foreach (['jpg', 'jpeg', 'png', 'webp', 'svg'] as $ext) {
    if (file_exists($clubLogoDir . $clubId . '.' . $ext)) {
        $clubLogo = $clubLogoUrl . $clubId . '.' . $ext . '?v=' . filemtime($clubLogoDir . $clubId . '.' . $ext);
        break;
    }
}
if (!$clubLogo && !empty($club['logo'])) {
    $clubLogo = $club['logo'];
}
?>

<link rel="stylesheet" href="/assets/css/pages/club.css?v=<?= file_exists(dirname(__DIR__) . '/assets/css/pages/club.css') ? filemtime(dirname(__DIR__) . '/assets/css/pages/club.css') : time() ?>">

<?php if (isset($error)): ?>
<div class="alert alert-error">
    <i data-lucide="alert-circle"></i>
    <?= htmlspecialchars($error) ?>
</div>
<?php endif; ?>

<!-- Club Profile Card -->
<div class="club-profile-card">
    <div class="club-logo-large">
        <?php if ($clubLogo): ?>
            <img src="<?= htmlspecialchars($clubLogo) ?>" alt="<?= htmlspecialchars($club['name']) ?>">
        <?php else: ?>
            <div class="club-logo-placeholder">
                <i data-lucide="users"></i>
            </div>
        <?php endif; ?>
    </div>

    <h1 class="club-name"><?= htmlspecialchars($club['name']) ?></h1>

    <?php if ($club['city']): ?>
    <p class="club-location">
        <i data-lucide="map-pin"></i>
        <?= htmlspecialchars($club['city']) ?>
    </p>
    <?php endif; ?>

    <?php if ($clubRankingPosition): ?>
    <div class="club-ranking">
        <span class="ranking-label">Klubbranking</span>
        <span class="ranking-position">#<?= $clubRankingPosition ?></span>
    </div>
    <?php endif; ?>

    <div class="club-stats">
        <div class="club-stat">
            <span class="stat-value"><?= $totalUniqueMembers ?></span>
            <span class="stat-label">Medlemmar</span>
        </div>
        <div class="club-stat">
            <span class="stat-value"><?= count($availableYears) ?></span>
            <span class="stat-label">Säsonger</span>
        </div>
        <div class="club-stat">
            <span class="stat-value"><?= number_format($totalPoints) ?></span>
            <span class="stat-label">Poäng</span>
        </div>
    </div>

    <?php if ($club['website'] || $club['email']): ?>
    <div class="club-contact">
        <?php if ($club['website']): ?>
        <a href="<?= htmlspecialchars($club['website']) ?>" target="_blank" rel="noopener" class="contact-link">
            <i data-lucide="globe"></i>
            <?= htmlspecialchars(preg_replace('#^https?://#', '', $club['website'])) ?>
        </a>
        <?php endif; ?>
        <?php if ($club['email']): ?>
        <a href="mailto:<?= htmlspecialchars($club['email']) ?>" class="contact-link">
            <i data-lucide="mail"></i>
            <?= htmlspecialchars($club['email']) ?>
        </a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Ranking Statistics Card -->
<?php
// Prepare ranking chart data for Chart.js
$hasClubRankingChart = false;
$clubRankingChartLabels = [];
$clubRankingChartData = [];
$swedishMonthsShort = ['jan', 'feb', 'mar', 'apr', 'maj', 'jun', 'jul', 'aug', 'sep', 'okt', 'nov', 'dec'];

if ($clubRankingPosition && !empty($clubRankingHistoryFull) && count($clubRankingHistoryFull) >= 2) {
    $hasClubRankingChart = true;
    foreach ($clubRankingHistoryFull as $rh) {
        $monthNum = isset($rh['month']) ? (int)date('n', strtotime($rh['month'] . '-01')) - 1 : 0;
        $clubRankingChartLabels[] = ucfirst($swedishMonthsShort[$monthNum % 12] ?? '');
        $clubRankingChartData[] = (int)$rh['ranking_position'];
    }
}

if ($clubRankingPosition):
?>
<div class="club-ranking-section">
    <div class="section-header">
        <h2 class="section-title">
            <i data-lucide="trending-up"></i>
            Klubbranking
        </h2>
    </div>

    <div class="club-ranking-card">
        <div class="dashboard-chart-header">
            <div class="dashboard-chart-stats">
                <div class="dashboard-stat">
                    <span class="dashboard-stat-value dashboard-stat-value--red">#<?= $clubRankingPosition ?></span>
                    <span class="dashboard-stat-label">Position</span>
                </div>
                <div class="dashboard-stat">
                    <span class="dashboard-stat-value"><?= number_format($clubRankingPoints, 0) ?></span>
                    <span class="dashboard-stat-label">Poäng</span>
                </div>
                <div class="dashboard-stat">
                    <span class="dashboard-stat-value"><?= $clubRidersCount ?></span>
                    <span class="dashboard-stat-label">Åkare</span>
                </div>
            </div>
        </div>
        <?php if ($hasClubRankingChart): ?>
        <div class="dashboard-chart-body">
            <canvas id="clubRankingChart"></canvas>
        </div>
        <?php endif; ?>
        <div class="dashboard-chart-footer">
            <?php if (count($clubRankingHistoryFull) >= 3): ?>
            <button type="button" class="btn-calc-ranking-inline" onclick="openClubHistoryModal()">
                <i data-lucide="history"></i>
                <span>Visa historik</span>
            </button>
            <?php endif; ?>
            <a href="/ranking/clubs" class="btn-calc-ranking-inline">
                <i data-lucide="list"></i>
                <span>Alla klubbar</span>
            </a>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Club Ranking History Modal -->
<?php if (!empty($clubRankingHistoryFull) && count($clubRankingHistoryFull) >= 3):
    // Prepare full history data for the modal chart
    $clubHistoryLabels = [];
    $clubHistoryData = [];
    $clubHistoryPoints = [];
    foreach ($clubRankingHistoryFull as $rh) {
        $date = strtotime($rh['snapshot_date'] ?? $rh['month'] . '-01');
        $clubHistoryLabels[] = date('M Y', $date);
        $clubHistoryData[] = (int)$rh['ranking_position'];
        $clubHistoryPoints[] = (float)($rh['total_ranking_points'] ?? 0);
    }

    // Find best and worst positions
    $bestClubHistoryPos = !empty($clubHistoryData) ? min($clubHistoryData) : 0;
    $worstClubHistoryPos = !empty($clubHistoryData) ? max($clubHistoryData) : 0;
    $firstClubPos = $clubHistoryData[0] ?? 0;
    $lastClubPos = end($clubHistoryData) ?: 0;
    $clubImprovement = $firstClubPos - $lastClubPos;
?>
<div id="clubHistoryModal" class="club-modal-overlay">
    <div class="club-modal" style="max-width: 800px;">
        <div class="club-modal-header">
            <h3>
                <i data-lucide="history"></i>
                <span>Rankinghistorik</span>
            </h3>
            <button type="button" class="club-modal-close" onclick="closeClubHistoryModal()">
                <i data-lucide="x"></i>
            </button>
        </div>
        <div class="club-modal-body">
            <div class="ranking-modal-summary">
                <span class="summary-label">Nuvarande position</span>
                <span class="summary-value">#<?= $clubRankingPosition ?></span>
            </div>

            <div class="history-stats-row">
                <div class="history-stat">
                    <span class="history-stat-value text-success">#<?= $bestClubHistoryPos ?></span>
                    <span class="history-stat-label">Bästa</span>
                </div>
                <div class="history-stat">
                    <span class="history-stat-value">#<?= $worstClubHistoryPos ?></span>
                    <span class="history-stat-label">Sämsta</span>
                </div>
                <div class="history-stat">
                    <span class="history-stat-value <?= $clubImprovement > 0 ? 'text-success' : ($clubImprovement < 0 ? 'text-danger' : '') ?>">
                        <?= $clubImprovement > 0 ? '+' : '' ?><?= $clubImprovement ?>
                    </span>
                    <span class="history-stat-label">Utveckling</span>
                </div>
                <div class="history-stat">
                    <span class="history-stat-value"><?= count($clubHistoryData) ?></span>
                    <span class="history-stat-label">Datapunkter</span>
                </div>
            </div>

            <div class="history-chart-container" style="height: 300px; margin: var(--space-lg) 0;">
                <canvas id="clubHistoryChart"></canvas>
            </div>

            <div class="modal-close-footer">
                <button type="button" onclick="closeClubHistoryModal()" class="modal-close-btn">
                    <i data-lucide="x"></i>
                    Stäng
                </button>
            </div>
        </div>
    </div>
</div>

<script>
const clubHistoryChartLabels = <?= json_encode($clubHistoryLabels) ?>;
const clubHistoryChartData = <?= json_encode($clubHistoryData) ?>;
const clubHistoryChartPoints = <?= json_encode($clubHistoryPoints) ?>;
let clubHistoryChartInstance = null;

function openClubHistoryModal() {
    const modal = document.getElementById('clubHistoryModal');
    if (modal) {
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';

        // Initialize chart after modal is visible
        setTimeout(() => {
            initClubHistoryChart();
        }, 100);

        if (typeof lucide !== 'undefined') lucide.createIcons();
    }
}

function closeClubHistoryModal() {
    const modal = document.getElementById('clubHistoryModal');
    if (modal) {
        modal.classList.remove('active');
        document.body.style.overflow = '';
    }
}

function initClubHistoryChart() {
    const ctx = document.getElementById('clubHistoryChart');
    if (!ctx || clubHistoryChartInstance) return;

    clubHistoryChartInstance = new Chart(ctx, {
        type: 'line',
        data: {
            labels: clubHistoryChartLabels,
            datasets: [{
                label: 'Ranking',
                data: clubHistoryChartData,
                borderColor: '#ef4444',
                backgroundColor: 'rgba(239, 68, 68, 0.1)',
                borderWidth: 2,
                fill: true,
                tension: 0.3,
                pointRadius: 3,
                pointHoverRadius: 5
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            const idx = context.dataIndex;
                            const points = clubHistoryChartPoints[idx] || 0;
                            return ['Position: #' + context.raw, 'Poäng: ' + points.toFixed(0)];
                        }
                    }
                }
            },
            scales: {
                y: {
                    reverse: true,
                    min: 1,
                    title: { display: true, text: 'Position' },
                    ticks: { stepSize: 1 }
                },
                x: {
                    ticks: { maxRotation: 45, minRotation: 45 }
                }
            }
        }
    });
}

// Close modal on ESC key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeClubHistoryModal();
    }
});

// Close modal on backdrop click
document.getElementById('clubHistoryModal')?.addEventListener('click', function(e) {
    if (e.target === this) closeClubHistoryModal();
});
</script>
<?php endif; ?>

<!-- Members List -->
<div class="club-members-section">
    <div class="section-header">
        <h2 class="section-title">
            <i data-lucide="users"></i>
            Alla medlemmar
        </h2>
        <p class="section-subtitle"><?= $totalUniqueMembers ?> unika medlemmar genom åren</p>
    </div>

    <?php if (empty($members)): ?>
    <div class="empty-state">
        <div class="empty-icon"><i data-lucide="users"></i></div>
        <h3>Inga medlemmar registrerade</h3>
        <p>Det finns inga registrerade medlemmar för denna klubb.</p>
    </div>
    <?php else: ?>

    <!-- Desktop Table View -->
    <div class="table-responsive members-table-desktop">
        <table class="table table--striped">
            <thead>
                <tr>
                    <th>Namn</th>
                    <th class="text-center">Race</th>
                    <th class="text-center">Vinster</th>
                    <th class="text-center">Pallplatser</th>
                    <th class="text-right">Medlemsår</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($members as $member):
                    $years = explode(',', $member['member_years']);
                    sort($years);
                    $yearsStr = implode(', ', $years);
                    $isCurrentMember = in_array($currentYear, $years);
                ?>
                <tr onclick="window.location='/rider/<?= $member['id'] ?>'" class="cursor-pointer <?= $isCurrentMember ? 'member-current-row' : '' ?>">
                    <td>
                        <div class="member-name-cell">
                            <div class="member-avatar-small">
                                <?= strtoupper(substr($member['firstname'], 0, 1) . substr($member['lastname'], 0, 1)) ?>
                            </div>
                            <div class="member-name-info">
                                <span class="member-name"><?= htmlspecialchars($member['firstname'] . ' ' . $member['lastname']) ?></span>
                                <?php if ($member['birth_year']): ?>
                                <span class="member-birth"><?= $member['birth_year'] ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </td>
                    <td class="text-center"><?= (int)$member['total_races'] ?></td>
                    <td class="text-center"><?= (int)$member['total_wins'] ?></td>
                    <td class="text-center"><?= (int)$member['total_podiums'] ?></td>
                    <td class="text-right member-years-cell"><?= htmlspecialchars($yearsStr) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Mobile Card View -->
    <div class="members-list-mobile">
        <?php foreach ($members as $member):
            $years = explode(',', $member['member_years']);
            sort($years);
            $yearsStr = implode(', ', $years);
            $isCurrentMember = in_array($currentYear, $years);
        ?>
        <a href="/rider/<?= $member['id'] ?>" class="member-row <?= $isCurrentMember ? 'member-current' : '' ?>">
            <div class="member-avatar-small">
                <?= strtoupper(substr($member['firstname'], 0, 1) . substr($member['lastname'], 0, 1)) ?>
            </div>

            <div class="member-info-mobile">
                <span class="member-name"><?= htmlspecialchars($member['firstname'] . ' ' . $member['lastname']) ?></span>
                <div class="member-stats-row">
                    <span class="stat-mini"><?= (int)$member['total_races'] ?> race</span>
                    <span class="stat-mini"><?= (int)$member['total_wins'] ?> vinst</span>
                    <span class="stat-mini"><?= (int)$member['total_podiums'] ?> pall</span>
                </div>
                <span class="member-years-small"><?= htmlspecialchars($yearsStr) ?></span>
            </div>

            <div class="member-arrow">
                <i data-lucide="chevron-right"></i>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<?php if (function_exists('renderClubAchievements')): ?>
<link rel="stylesheet" href="/assets/css/achievements.css?v=<?= file_exists(dirname(__DIR__) . '/assets/css/achievements.css') ? filemtime(dirname(__DIR__) . '/assets/css/achievements.css') : time() ?>">
<style>
/* Club Achievement Modal - Same as rider page */
.club-modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.6);
    display: none;
    align-items: flex-start;
    justify-content: center;
    z-index: 1000;
    padding: calc(var(--header-height, 60px) + 20px) var(--space-md) var(--space-xl);
    overflow-y: auto;
}
.club-modal-overlay.active {
    display: flex;
}
.club-modal {
    background: var(--color-bg-card);
    border-radius: var(--radius-lg);
    width: 100%;
    max-width: 500px;
    box-shadow: var(--shadow-lg);
    overflow: hidden;
}
.club-modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: var(--space-md) var(--space-lg);
    border-bottom: 1px solid var(--color-border);
}
.club-modal-header h3 {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    margin: 0;
    font-size: var(--text-lg);
}
.club-modal-header h3 i {
    width: 20px;
    height: 20px;
    color: var(--color-accent);
}
.club-modal-close {
    background: none;
    border: none;
    padding: var(--space-xs);
    cursor: pointer;
    color: var(--color-text-secondary);
    border-radius: var(--radius-sm);
}
.club-modal-close:hover {
    background: var(--color-bg-secondary);
    color: var(--color-text);
}
.club-modal-close i {
    width: 20px;
    height: 20px;
}
.club-modal-body {
    padding: var(--space-lg);
    max-height: 60vh;
    overflow-y: auto;
}
</style>
<div class="club-achievements-section">
    <?= renderClubAchievements($db, $clubId) ?>
</div>

<?php
// Get detailed achievements for modal
$clubDetailedAchievements = [];
if (function_exists('getClubDetailedAchievements')) {
    $clubDetailedAchievements = getClubDetailedAchievements($db, $clubId);
}
?>

<?php if (!empty($clubDetailedAchievements)): ?>
<!-- Club Achievement Details Modal -->
<div id="clubAchievementModal" class="club-modal-overlay">
    <div class="club-modal">
        <div class="club-modal-header">
            <h3 id="clubAchievementModalTitle">
                <i data-lucide="award"></i>
                <span></span>
            </h3>
            <button type="button" class="club-modal-close" id="closeClubModalBtn">
                <i data-lucide="x"></i>
            </button>
        </div>
        <div class="club-modal-body" id="clubAchievementModalBody">
            <!-- Content populated by JS -->
        </div>
    </div>
</div>

<script>
const clubDetailedAchievements = <?= json_encode($clubDetailedAchievements) ?>;

function openClubAchievementModal(achievementType) {
    const data = clubDetailedAchievements[achievementType];
    if (!data || !data.items || data.items.length === 0) return;

    const modal = document.getElementById('clubAchievementModal');
    const titleSpan = document.querySelector('#clubAchievementModalTitle span');
    const body = document.getElementById('clubAchievementModalBody');

    titleSpan.textContent = data.label;

    let html = '<div class="achievement-details-list">';

    if (achievementType === 'unique_champions') {
        // Special format for unique champions - show rider with total wins
        data.items.forEach(item => {
            const riderName = item.firstname + ' ' + item.lastname;
            const riderId = item.rider_id;
            const wins = item.wins;
            const years = item.years || '';

            html += '<div class="achievement-detail-item">';
            html += `<a href="/rider/${riderId}" class="achievement-detail-link">`;
            html += `<div class="achievement-detail-content">`;
            html += `<span class="achievement-detail-name">${riderName}</span>`;
            html += `<span class="achievement-detail-year">${wins} seger${wins > 1 ? 'ar' : ''} (${years})</span>`;
            html += `</div>`;
            html += `<i data-lucide="chevron-right" class="achievement-detail-arrow"></i></a>`;
            html += '</div>';
        });
    } else {
        // Format for series_champion and swedish_champion
        data.items.forEach(item => {
            const riderName = item.firstname + ' ' + item.lastname;
            const riderId = item.rider_id;
            const year = item.season_year || '';
            const seriesName = item.series_name || item.series_short_name || '';
            const eventName = item.event_name || item.achievement_value || '';
            const eventId = item.event_id;

            html += '<div class="achievement-detail-item">';
            html += `<a href="/rider/${riderId}" class="achievement-detail-link">`;
            html += `<div class="achievement-detail-content">`;
            if (seriesName) {
                html += `<span class="achievement-detail-series">${seriesName}</span>`;
            }
            html += `<span class="achievement-detail-name">${riderName}</span>`;
            html += `<span class="achievement-detail-year">${eventName || year}${year && eventName ? ' (' + year + ')' : ''}</span>`;
            html += `</div>`;
            html += `<i data-lucide="chevron-right" class="achievement-detail-arrow"></i></a>`;
            html += '</div>';
        });
    }

    html += '</div>';

    body.innerHTML = html;
    modal.classList.add('active');
    document.body.style.overflow = 'hidden';

    if (typeof lucide !== 'undefined') lucide.createIcons();
}

function closeClubAchievementModal() {
    const modal = document.getElementById('clubAchievementModal');
    if (modal) {
        modal.classList.remove('active');
        document.body.style.overflow = '';
    }
}

// Setup event listeners when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('clubAchievementModal');
    const closeBtn = document.getElementById('closeClubModalBtn');

    // Close button click
    if (closeBtn) {
        closeBtn.addEventListener('click', closeClubAchievementModal);
    }

    // Click outside modal to close
    if (modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === this) closeClubAchievementModal();
        });
    }

    // ESC key to close
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && modal && modal.classList.contains('active')) {
            closeClubAchievementModal();
        }
    });

    // Add click handlers to badges with data
    document.querySelectorAll('.badge-item.clickable').forEach(badge => {
        badge.addEventListener('click', function() {
            const type = this.dataset.achievement;
            if (type && clubDetailedAchievements[type]) {
                openClubAchievementModal(type);
            }
        });
    });
});
</script>

<style>
.achievement-details-list {
    display: flex;
    flex-direction: column;
    gap: var(--space-sm);
}
.achievement-detail-item {
    background: var(--color-bg-secondary);
    border-radius: var(--radius-sm);
    overflow: hidden;
}
.achievement-detail-link {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: var(--space-md);
    text-decoration: none;
    color: inherit;
    transition: background 0.2s;
}
.achievement-detail-link:hover {
    background: var(--color-bg-hover);
}
.achievement-detail-content {
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.achievement-detail-series {
    font-size: 0.7rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--color-text-secondary);
}
.achievement-detail-name {
    font-weight: 600;
    color: var(--color-text-primary);
}
.achievement-detail-year {
    font-size: 0.85rem;
    color: var(--color-text-secondary);
}
.achievement-detail-arrow {
    width: 20px;
    height: 20px;
    color: var(--color-text-secondary);
}
</style>
<?php endif; ?>
<?php endif; ?>

<?php if ($hasClubRankingChart): ?>
<!-- Club Ranking Chart Initialization -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const clubRankingCtx = document.getElementById('clubRankingChart');
    if (clubRankingCtx && typeof Chart !== 'undefined') {
        const ctx = clubRankingCtx.getContext('2d');
        const gradient = ctx.createLinearGradient(0, 0, 0, 160);
        gradient.addColorStop(0, 'rgba(239, 68, 68, 0.3)');
        gradient.addColorStop(1, 'rgba(239, 68, 68, 0.02)');

        new Chart(clubRankingCtx, {
            type: 'line',
            data: {
                labels: <?= json_encode($clubRankingChartLabels) ?>,
                datasets: [{
                    data: <?= json_encode($clubRankingChartData) ?>,
                    borderColor: '#ef4444',
                    backgroundColor: gradient,
                    fill: 'start',
                    tension: 0.4,
                    pointRadius: 0,
                    pointHoverRadius: 4,
                    pointBackgroundColor: '#ef4444',
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: 'rgba(0,0,0,0.8)',
                        titleFont: { size: 11 },
                        bodyFont: { size: 11 },
                        padding: 8,
                        displayColors: false,
                        callbacks: {
                            label: function(context) {
                                return 'Position: #' + context.raw;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        reverse: true,
                        min: 1,
                        display: false
                    },
                    x: {
                        display: false
                    }
                },
                interaction: {
                    mode: 'index',
                    intersect: false
                }
            }
        });
    }
});
</script>
<?php endif; ?>

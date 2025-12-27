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

    // Get ALL unique members across all years with their membership years
    $stmt = $db->prepare("
        SELECT
            r.id,
            r.firstname,
            r.lastname,
            r.birth_year,
            r.gender,
            GROUP_CONCAT(DISTINCT rcs.season_year ORDER BY rcs.season_year DESC SEPARATOR ',') as member_years
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
            r.gender
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
    $parentDb = function_exists('getDB') ? getDB() : null;
    if ($rankingFunctionsLoaded && $parentDb && function_exists('getSingleClubRanking')) {
        $clubRanking = getSingleClubRanking($parentDb, $clubId, 'GRAVITY');
        if ($clubRanking) {
            $clubRankingPosition = $clubRanking['ranking_position'] ?? null;
            $clubRankingPoints = $clubRanking['ranking_points'] ?? 0;
        }
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
    <div class="members-list">
        <?php foreach ($members as $member):
            $years = explode(',', $member['member_years']);
            $yearCount = count($years);
            $latestYear = $years[0] ?? '';
            $isCurrentMember = in_array($currentYear, $years);
        ?>
        <a href="/rider/<?= $member['id'] ?>" class="member-row <?= $isCurrentMember ? 'member-current' : '' ?>">
            <div class="member-avatar">
                <?= strtoupper(substr($member['firstname'], 0, 1) . substr($member['lastname'], 0, 1)) ?>
            </div>

            <div class="member-info">
                <span class="member-name"><?= htmlspecialchars($member['firstname'] . ' ' . $member['lastname']) ?></span>
                <?php if ($member['birth_year']): ?>
                <span class="member-birth"><?= $member['birth_year'] ?></span>
                <?php endif; ?>
            </div>

            <div class="member-years">
                <?php if ($yearCount <= 3): ?>
                    <?php foreach ($years as $y): ?>
                    <span class="year-badge <?= $y == $currentYear ? 'year-current' : '' ?>"><?= $y ?></span>
                    <?php endforeach; ?>
                <?php else: ?>
                    <span class="year-badge <?= $latestYear == $currentYear ? 'year-current' : '' ?>"><?= $latestYear ?></span>
                    <span class="year-more">+<?= $yearCount - 1 ?> år</span>
                <?php endif; ?>
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
<div id="clubAchievementModal" class="ranking-modal-overlay" style="display:none; padding-top: calc(var(--header-height, 60px) + 10px);">
    <div class="ranking-modal" style="max-height: calc(100vh - var(--header-height, 60px) - 40px); max-width: 500px;">
        <div class="ranking-modal-header">
            <h3 id="clubAchievementModalTitle">
                <i data-lucide="award"></i>
                <span></span>
            </h3>
            <button type="button" class="ranking-modal-close" onclick="closeClubAchievementModal()">
                <i data-lucide="x"></i>
            </button>
        </div>
        <div class="ranking-modal-body" id="clubAchievementModalBody">
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
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';

    if (typeof lucide !== 'undefined') lucide.createIcons();
}

function closeClubAchievementModal() {
    const modal = document.getElementById('clubAchievementModal');
    if (modal) {
        modal.style.display = 'none';
        document.body.style.overflow = '';
    }
}

document.getElementById('clubAchievementModal')?.addEventListener('click', function(e) {
    if (e.target === this) closeClubAchievementModal();
});

// Add click handlers to badges with data
document.addEventListener('DOMContentLoaded', function() {
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

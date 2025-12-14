<?php
/**
 * TheHUB V3.5 - Club Admin
 * Manage clubs where user is admin
 */

$currentUser = hub_current_user();
if (!$currentUser) {
    header('Location: /profile/login');
    exit;
}

$pdo = hub_db();
$adminClubs = hub_get_admin_clubs($currentUser['id']);

if (empty($adminClubs)) {
    header('Location: /profile');
    exit;
}

// Get selected club (default to first)
$selectedClubId = intval($_GET['club'] ?? $adminClubs[0]['id']);
$selectedClub = null;
foreach ($adminClubs as $club) {
    if ($club['id'] === $selectedClubId) {
        $selectedClub = $club;
        break;
    }
}

if (!$selectedClub) {
    header('Location: /profile');
    exit;
}

// Get club members
$membersStmt = $pdo->prepare("
    SELECT r.*, COUNT(res.id) as result_count
    FROM riders r
    LEFT JOIN results res ON r.id = res.cyclist_id
    WHERE r.club_id = ?
    GROUP BY r.id
    ORDER BY r.lastname, r.firstname
");
$membersStmt->execute([$selectedClubId]);
$members = $membersStmt->fetchAll(PDO::FETCH_ASSOC);

// Get club stats
$statsStmt = $pdo->prepare("
    SELECT
        COUNT(DISTINCT r.id) as member_count,
        COUNT(DISTINCT res.id) as total_results,
        COUNT(DISTINCT CASE WHEN res.position <= 3 THEN res.id END) as podiums
    FROM riders r
    LEFT JOIN results res ON r.id = res.cyclist_id
    WHERE r.club_id = ?
");
$statsStmt->execute([$selectedClubId]);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);
?>

<div class="page-header">
    <nav class="breadcrumb">
        <a href="/profile">Min Sida</a>
        <span class="breadcrumb-sep">›</span>
        <span>Klubb-admin</span>
    </nav>
    <h1 class="page-title">
        <span class="page-icon">⚙️</span>
        Klubb-admin
    </h1>
</div>

<!-- Club Selector (if multiple) -->
<?php if (count($adminClubs) > 1): ?>
    <div class="club-selector">
        <?php foreach ($adminClubs as $club): ?>
            <a href="?club=<?= $club['id'] ?>"
               class="club-tab<?= $club['id'] === $selectedClubId ? ' active' : '' ?>">
                <?= htmlspecialchars($club['name']) ?>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- Club Header -->
<div class="club-header card">
    <div class="club-avatar">
        <?= strtoupper(substr($selectedClub['name'], 0, 2)) ?>
    </div>
    <div class="club-info">
        <h2><?= htmlspecialchars($selectedClub['name']) ?></h2>
        <a href="/database/club/<?= $selectedClubId ?>" class="club-link">Visa klubbsida →</a>
    </div>
    <a href="/profile/edit-club/<?= $selectedClubId ?>" class="btn btn-outline">Redigera klubb</a>
</div>

<!-- Stats -->
<div class="stats-grid">
    <div class="stat-card">
        <span class="stat-value"><?= $stats['member_count'] ?></span>
        <span class="stat-label">Medlemmar</span>
    </div>
    <div class="stat-card">
        <span class="stat-value"><?= $stats['total_results'] ?></span>
        <span class="stat-label">Resultat</span>
    </div>
    <div class="stat-card">
        <span class="stat-value"><?= $stats['podiums'] ?></span>
        <span class="stat-label">Pallplatser</span>
    </div>
</div>

<!-- Members -->
<div class="members-section">
    <div class="section-header">
        <h2>Medlemmar (<?= count($members) ?>)</h2>
        <a href="/profile/add-member/<?= $selectedClubId ?>" class="btn btn-primary btn-sm">+ Lägg till</a>
    </div>

    <?php if (empty($members)): ?>
        <div class="empty-state">
            <p>Inga medlemmar i klubben ännu.</p>
        </div>
    <?php else: ?>
        <div class="members-list">
            <?php foreach ($members as $member): ?>
                <div class="member-card">
                    <div class="member-avatar">
                        <?= strtoupper(substr($member['firstname'], 0, 1)) ?>
                    </div>
                    <div class="member-info">
                        <a href="/database/rider/<?= $member['id'] ?>" class="member-name">
                            <?= htmlspecialchars($member['firstname'] . ' ' . $member['lastname']) ?>
                        </a>
                        <span class="member-stats">
                            <?= $member['result_count'] ?> resultat
                        </span>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline"
                            onclick="removeMember(<?= $member['id'] ?>, '<?= htmlspecialchars($member['firstname']) ?>')">
                        Ta bort
                    </button>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<style>
.club-selector {
    display: flex;
    gap: var(--space-xs);
    margin-bottom: var(--space-lg);
}
.club-tab {
    padding: var(--space-sm) var(--space-lg);
    background: var(--color-bg-card);
    border-radius: var(--radius-md);
    text-decoration: none;
    color: var(--color-text-secondary);
    transition: all var(--transition-fast);
}
.club-tab:hover {
    background: var(--color-bg-hover);
}
.club-tab.active {
    background: var(--color-accent);
    color: white;
}

.card {
    background: var(--color-bg-card);
    border-radius: var(--radius-lg);
    padding: var(--space-lg);
    margin-bottom: var(--space-lg);
}

.club-header {
    display: flex;
    align-items: center;
    gap: var(--space-lg);
}
.club-avatar {
    width: 64px;
    height: 64px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--color-bg-surface);
    border-radius: var(--radius-lg);
    font-weight: var(--weight-bold);
    font-size: var(--text-lg);
}
.club-info {
    flex: 1;
}
.club-info h2 {
    margin-bottom: var(--space-2xs);
}
.club-link {
    color: var(--color-accent);
    text-decoration: none;
    font-size: var(--text-sm);
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: var(--space-md);
    margin-bottom: var(--space-xl);
}
.stat-card {
    background: var(--color-bg-card);
    border-radius: var(--radius-lg);
    padding: var(--space-lg);
    text-align: center;
}
.stat-value {
    display: block;
    font-size: var(--text-2xl);
    font-weight: var(--weight-bold);
    color: var(--color-accent);
}
.stat-label {
    font-size: var(--text-sm);
    color: var(--color-text-secondary);
}

.section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: var(--space-md);
}
.section-header h2 {
    font-size: var(--text-lg);
}

.members-list {
    display: flex;
    flex-direction: column;
    gap: var(--space-xs);
}
.member-card {
    display: flex;
    align-items: center;
    gap: var(--space-md);
    padding: var(--space-md);
    background: var(--color-bg-card);
    border-radius: var(--radius-lg);
}
.member-avatar {
    width: 40px;
    height: 40px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--color-accent);
    color: white;
    border-radius: var(--radius-full);
    font-weight: var(--weight-bold);
}
.member-info {
    flex: 1;
}
.member-name {
    display: block;
    font-weight: var(--weight-medium);
    text-decoration: none;
    color: inherit;
}
.member-name:hover {
    color: var(--color-accent);
}
.member-stats {
    font-size: var(--text-sm);
    color: var(--color-text-secondary);
}

.btn {
    padding: var(--space-sm) var(--space-md);
    border-radius: var(--radius-md);
    text-decoration: none;
    cursor: pointer;
    border: none;
}
.btn-primary {
    background: var(--color-accent);
    color: white;
}
.btn-outline {
    background: transparent;
    border: 1px solid var(--color-border);
}
.btn-sm {
    padding: var(--space-xs) var(--space-sm);
    font-size: var(--text-sm);
}

.empty-state {
    text-align: center;
    padding: var(--space-xl);
    background: var(--color-bg-card);
    border-radius: var(--radius-lg);
    color: var(--color-text-secondary);
}
</style>

<script>
function removeMember(riderId, name) {
    if (confirm(`Ta bort ${name} från klubben?`)) {
        window.location.href = `/api/club.php?action=remove_member&rider_id=${riderId}&club_id=<?= $selectedClubId ?>`;
    }
}
</script>

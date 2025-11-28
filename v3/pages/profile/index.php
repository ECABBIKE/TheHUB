<?php
/**
 * TheHUB V3.5 - Min Sida (Profile)
 * Shows user profile, children, registrations, etc.
 */

$currentUser = hub_current_user();

if (!$currentUser) {
    header('Location: /v3/profile/login');
    exit;
}

$pdo = hub_db();

// Get linked children
$linkedChildren = hub_get_linked_children($currentUser['id']);

// Get admin clubs
$adminClubs = hub_get_admin_clubs($currentUser['id']);

// Get upcoming registrations
$regStmt = $pdo->prepare("
    SELECT r.*, e.name as event_name, e.event_date, e.location,
           ec.name as class_name
    FROM registrations r
    JOIN events e ON r.event_id = e.id
    LEFT JOIN event_classes ec ON r.class_id = ec.id
    WHERE r.rider_id = ? AND e.event_date >= CURDATE() AND r.status != 'cancelled'
    ORDER BY e.event_date ASC
    LIMIT 5
");
$regStmt->execute([$currentUser['id']]);
$upcomingRegs = $regStmt->fetchAll(PDO::FETCH_ASSOC);

// Get recent results
$resultStmt = $pdo->prepare("
    SELECT res.*, e.name as event_name, e.event_date,
           ec.name as class_name
    FROM results res
    JOIN events e ON res.event_id = e.id
    LEFT JOIN event_classes ec ON res.class_id = ec.id
    WHERE res.rider_id = ?
    ORDER BY e.event_date DESC
    LIMIT 5
");
$resultStmt->execute([$currentUser['id']]);
$recentResults = $resultStmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="page-header">
    <h1 class="page-title">
        <span class="page-icon">👤</span>
        Min Sida
    </h1>
</div>

<!-- Profile Card -->
<div class="profile-card">
    <div class="profile-avatar">
        <?= strtoupper(substr($currentUser['first_name'], 0, 1) . substr($currentUser['last_name'], 0, 1)) ?>
    </div>
    <div class="profile-info">
        <h2 class="profile-name"><?= htmlspecialchars($currentUser['first_name'] . ' ' . $currentUser['last_name']) ?></h2>
        <?php if ($currentUser['email']): ?>
            <p class="profile-email"><?= htmlspecialchars($currentUser['email']) ?></p>
        <?php endif; ?>
        <?php if ($currentUser['club_id']): ?>
            <?php
            $clubStmt = $pdo->prepare("SELECT name FROM clubs WHERE id = ?");
            $clubStmt->execute([$currentUser['club_id']]);
            $clubName = $clubStmt->fetchColumn();
            ?>
            <p class="profile-club">🛡️ <?= htmlspecialchars($clubName) ?></p>
        <?php endif; ?>
    </div>
    <a href="/v3/profile/edit" class="btn btn-outline">Redigera profil</a>
</div>

<!-- Quick Links -->
<div class="quick-links">
    <a href="/v3/profile/registrations" class="quick-link">
        <span class="quick-link-icon">📝</span>
        <span class="quick-link-label">Mina anmälningar</span>
        <span class="quick-link-arrow">›</span>
    </a>
    <a href="/v3/profile/results" class="quick-link">
        <span class="quick-link-icon">🏁</span>
        <span class="quick-link-label">Mina resultat</span>
        <span class="quick-link-arrow">›</span>
    </a>
    <a href="/v3/profile/receipts" class="quick-link">
        <span class="quick-link-icon">🧾</span>
        <span class="quick-link-label">Kvitton</span>
        <span class="quick-link-arrow">›</span>
    </a>
    <?php if (!empty($linkedChildren)): ?>
        <a href="/v3/profile/children" class="quick-link">
            <span class="quick-link-icon">👨‍👩‍👧</span>
            <span class="quick-link-label">Kopplade barn (<?= count($linkedChildren) ?>)</span>
            <span class="quick-link-arrow">›</span>
        </a>
    <?php endif; ?>
    <?php if (!empty($adminClubs)): ?>
        <a href="/v3/profile/club-admin" class="quick-link">
            <span class="quick-link-icon">⚙️</span>
            <span class="quick-link-label">Klubb-admin</span>
            <span class="quick-link-arrow">›</span>
        </a>
    <?php endif; ?>
</div>

<!-- Upcoming Registrations -->
<?php if (!empty($upcomingRegs)): ?>
    <div class="section">
        <div class="section-header">
            <h2>Kommande tävlingar</h2>
            <a href="/v3/profile/registrations" class="section-link">Visa alla</a>
        </div>
        <div class="upcoming-list">
            <?php foreach ($upcomingRegs as $reg): ?>
                <a href="/v3/calendar/<?= $reg['event_id'] ?>" class="upcoming-item">
                    <div class="upcoming-date">
                        <span class="upcoming-day"><?= date('j', strtotime($reg['event_date'])) ?></span>
                        <span class="upcoming-month"><?= strftime('%b', strtotime($reg['event_date'])) ?></span>
                    </div>
                    <div class="upcoming-info">
                        <span class="upcoming-name"><?= htmlspecialchars($reg['event_name']) ?></span>
                        <span class="upcoming-class"><?= htmlspecialchars($reg['class_name'] ?? '') ?></span>
                    </div>
                    <span class="upcoming-status status-<?= $reg['status'] ?>">
                        <?= $reg['status'] === 'confirmed' ? '✓' : '⏳' ?>
                    </span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<!-- Recent Results -->
<?php if (!empty($recentResults)): ?>
    <div class="section">
        <div class="section-header">
            <h2>Senaste resultat</h2>
            <a href="/v3/profile/results" class="section-link">Visa alla</a>
        </div>
        <div class="results-list">
            <?php foreach ($recentResults as $result): ?>
                <a href="/v3/results/<?= $result['event_id'] ?>" class="result-item">
                    <div class="result-position">
                        <?php if ($result['position'] <= 3): ?>
                            <?= ['🥇', '🥈', '🥉'][$result['position'] - 1] ?>
                        <?php else: ?>
                            #<?= $result['position'] ?>
                        <?php endif; ?>
                    </div>
                    <div class="result-info">
                        <span class="result-event"><?= htmlspecialchars($result['event_name']) ?></span>
                        <span class="result-class"><?= htmlspecialchars($result['class_name'] ?? '') ?></span>
                    </div>
                    <span class="result-date"><?= date('j M', strtotime($result['event_date'])) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<!-- Logout -->
<div class="logout-section">
    <a href="/v3/profile/logout" class="btn btn-outline btn-danger">Logga ut</a>
</div>

<style>
.profile-card {
    display: flex;
    align-items: center;
    gap: var(--space-lg);
    padding: var(--space-lg);
    background: var(--color-bg-card);
    border-radius: var(--radius-xl);
    margin-bottom: var(--space-lg);
}
.profile-avatar {
    width: 80px;
    height: 80px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--color-accent);
    color: white;
    border-radius: var(--radius-full);
    font-size: var(--text-2xl);
    font-weight: var(--weight-bold);
}
.profile-info {
    flex: 1;
}
.profile-name {
    font-size: var(--text-xl);
    font-weight: var(--weight-bold);
    margin-bottom: var(--space-2xs);
}
.profile-email, .profile-club {
    color: var(--color-text-secondary);
    font-size: var(--text-sm);
}

.quick-links {
    display: flex;
    flex-direction: column;
    gap: var(--space-xs);
    margin-bottom: var(--space-xl);
}
.quick-link {
    display: flex;
    align-items: center;
    gap: var(--space-md);
    padding: var(--space-md);
    background: var(--color-bg-card);
    border-radius: var(--radius-lg);
    text-decoration: none;
    color: inherit;
    transition: all var(--transition-fast);
}
.quick-link:hover {
    transform: translateX(4px);
    background: var(--color-bg-hover);
}
.quick-link-icon {
    font-size: var(--text-xl);
}
.quick-link-label {
    flex: 1;
    font-weight: var(--weight-medium);
}
.quick-link-arrow {
    color: var(--color-text-secondary);
    font-size: var(--text-xl);
}

.section {
    margin-bottom: var(--space-xl);
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
.section-link {
    color: var(--color-accent);
    text-decoration: none;
    font-size: var(--text-sm);
}

.upcoming-list, .results-list {
    display: flex;
    flex-direction: column;
    gap: var(--space-xs);
}
.upcoming-item, .result-item {
    display: flex;
    align-items: center;
    gap: var(--space-md);
    padding: var(--space-md);
    background: var(--color-bg-card);
    border-radius: var(--radius-lg);
    text-decoration: none;
    color: inherit;
    transition: all var(--transition-fast);
}
.upcoming-item:hover, .result-item:hover {
    transform: translateX(4px);
}
.upcoming-date {
    display: flex;
    flex-direction: column;
    align-items: center;
    min-width: 48px;
    padding: var(--space-xs);
    background: var(--color-accent);
    border-radius: var(--radius-md);
    color: white;
}
.upcoming-day {
    font-size: var(--text-lg);
    font-weight: var(--weight-bold);
    line-height: 1;
}
.upcoming-month {
    font-size: var(--text-xs);
    text-transform: uppercase;
}
.upcoming-info, .result-info {
    flex: 1;
    min-width: 0;
}
.upcoming-name, .result-event {
    display: block;
    font-weight: var(--weight-medium);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.upcoming-class, .result-class {
    font-size: var(--text-sm);
    color: var(--color-text-secondary);
}
.result-position {
    font-size: var(--text-xl);
    font-weight: var(--weight-bold);
    min-width: 48px;
    text-align: center;
}
.result-date {
    font-size: var(--text-sm);
    color: var(--color-text-secondary);
}

.logout-section {
    padding-top: var(--space-xl);
    border-top: 1px solid var(--color-border);
}
.btn-danger {
    color: var(--color-error);
    border-color: var(--color-error);
}
.btn-danger:hover {
    background: var(--color-error);
    color: white;
}

.btn-outline {
    background: transparent;
    border: 1px solid var(--color-border);
    padding: var(--space-sm) var(--space-md);
    border-radius: var(--radius-md);
    text-decoration: none;
    cursor: pointer;
    transition: all var(--transition-fast);
}

@media (max-width: 600px) {
    .profile-card {
        flex-direction: column;
        text-align: center;
    }
}
</style>

<?php
/**
 * TheHUB V3.5 - My Registrations
 */

$currentUser = hub_current_user();
if (!$currentUser) {
    header('Location: /profile/login');
    exit;
}

$pdo = hub_db();

// Check if event_registrations table exists
$registrations = [];
try {
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'event_registrations'");
    if ($tableCheck->rowCount() > 0) {
        // Get all registrations for user and their children
        $childIds = array_column(hub_get_linked_children($currentUser['id']), 'id');
        $allRiderIds = array_merge([$currentUser['id']], $childIds);
        $placeholders = implode(',', array_fill(0, count($allRiderIds), '?'));

        $stmt = $pdo->prepare("
            SELECT r.*, ri.firstname, ri.lastname,
                   e.name as event_name, e.date as event_date, e.location,
                   r.category as class_name, s.name as series_name
            FROM event_registrations r
            JOIN riders ri ON r.rider_id = ri.id
            JOIN events e ON r.event_id = e.id
            LEFT JOIN series s ON e.series_id = s.id
            WHERE r.rider_id IN ($placeholders) AND r.status != 'cancelled'
            ORDER BY e.date DESC
        ");
        $stmt->execute($allRiderIds);
        $registrations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    // Table doesn't exist yet
    $registrations = [];
}

// Separate upcoming and past
$upcoming = [];
$past = [];
$today = date('Y-m-d');

foreach ($registrations as $reg) {
    if ($reg['event_date'] >= $today) {
        $upcoming[] = $reg;
    } else {
        $past[] = $reg;
    }
}

// Sort upcoming by date ascending
usort($upcoming, fn($a, $b) => strcmp($a['event_date'], $b['event_date']));
?>

<div class="page-header">
    <nav class="breadcrumb">
        <a href="/profile">Min Sida</a>
        <span class="breadcrumb-sep">›</span>
        <span>Mina anmälningar</span>
    </nav>
    <h1 class="page-title">
        <span class="page-icon">📝</span>
        Mina anmälningar
    </h1>
</div>

<!-- Upcoming -->
<section class="registrations-section">
    <h2>Kommande (<?= count($upcoming) ?>)</h2>

    <?php if (empty($upcoming)): ?>
        <div class="empty-state">
            <p>Inga kommande anmälningar.</p>
            <a href="/calendar" class="btn btn-primary">Hitta tävlingar</a>
        </div>
    <?php else: ?>
        <div class="registration-list">
            <?php foreach ($upcoming as $reg): ?>
                <a href="/calendar/<?= $reg['event_id'] ?>" class="registration-card">
                    <div class="reg-date">
                        <span class="reg-day"><?= date('j', strtotime($reg['event_date'])) ?></span>
                        <span class="reg-month"><?= hub_month_short($reg['event_date']) ?></span>
                    </div>
                    <div class="reg-info">
                        <span class="reg-event"><?= htmlspecialchars($reg['event_name']) ?></span>
                        <span class="reg-details">
                            <?= htmlspecialchars($reg['firstname'] . ' ' . $reg['lastname']) ?>
                            <?php if ($reg['class_name']): ?>
                                • <?= htmlspecialchars($reg['class_name']) ?>
                            <?php endif; ?>
                        </span>
                        <?php if ($reg['series_name']): ?>
                            <span class="reg-series"><?= htmlspecialchars($reg['series_name']) ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="reg-status status-<?= $reg['status'] ?>">
                        <?php
                        $statusLabels = [
                            'confirmed' => '✓ Bekräftad',
                            'pending' => '⏳ Väntar',
                            'waitlist' => '📋 Reserv'
                        ];
                        echo $statusLabels[$reg['status']] ?? $reg['status'];
                        ?>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<!-- Past -->
<?php if (!empty($past)): ?>
    <section class="registrations-section">
        <h2>Tidigare (<?= count($past) ?>)</h2>
        <div class="registration-list registration-list-past">
            <?php foreach (array_slice($past, 0, 10) as $reg): ?>
                <a href="/results/<?= $reg['event_id'] ?>" class="registration-card past">
                    <div class="reg-date">
                        <span class="reg-day"><?= date('j', strtotime($reg['event_date'])) ?></span>
                        <span class="reg-month"><?= hub_month_short($reg['event_date']) ?></span>
                    </div>
                    <div class="reg-info">
                        <span class="reg-event"><?= htmlspecialchars($reg['event_name']) ?></span>
                        <span class="reg-details">
                            <?= htmlspecialchars($reg['firstname'] . ' ' . $reg['lastname']) ?>
                        </span>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<style>
.registrations-section {
    margin-bottom: var(--space-xl);
}
.registrations-section h2 {
    font-size: var(--text-lg);
    margin-bottom: var(--space-md);
    color: var(--color-text-secondary);
}
.registration-list {
    display: flex;
    flex-direction: column;
    gap: var(--space-sm);
}
.registration-card {
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
.registration-card:hover {
    transform: translateX(4px);
}
.registration-card.past {
    opacity: 0.7;
}
.reg-date {
    display: flex;
    flex-direction: column;
    align-items: center;
    min-width: 48px;
    padding: var(--space-xs);
    background: var(--color-accent);
    border-radius: var(--radius-md);
    color: white;
}
.reg-day {
    font-size: var(--text-lg);
    font-weight: var(--weight-bold);
    line-height: 1;
}
.reg-month {
    font-size: var(--text-xs);
    text-transform: uppercase;
}
.reg-info {
    flex: 1;
    min-width: 0;
}
.reg-event {
    display: block;
    font-weight: var(--weight-medium);
}
.reg-details {
    font-size: var(--text-sm);
    color: var(--color-text-secondary);
}
.reg-series {
    display: block;
    font-size: var(--text-xs);
    color: var(--color-accent);
}
.reg-status {
    font-size: var(--text-sm);
    padding: var(--space-2xs) var(--space-sm);
    border-radius: var(--radius-full);
}
.status-confirmed {
    background: var(--color-success-bg, rgba(34, 197, 94, 0.1));
    color: var(--color-success, #22c55e);
}
.status-pending {
    background: var(--color-warning-bg, rgba(234, 179, 8, 0.1));
    color: var(--color-warning, #eab308);
}
.empty-state {
    text-align: center;
    padding: var(--space-xl);
    background: var(--color-bg-card);
    border-radius: var(--radius-lg);
}
.btn-primary {
    display: inline-block;
    margin-top: var(--space-md);
    padding: var(--space-sm) var(--space-lg);
    background: var(--color-accent);
    color: white;
    border-radius: var(--radius-md);
    text-decoration: none;
}
</style>

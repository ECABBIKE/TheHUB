<?php
/**
 * Promotor Panel - Shows promotor's assigned events
 * Uses standard admin layout with sidebar
 */

require_once __DIR__ . '/../config.php';
require_admin();

// Require at least promotor role
if (!hasRole('promotor')) {
    set_flash('error', 'Du har inte behörighet till denna sida');
    redirect('/');
}

$db = getDB();
$currentUser = getCurrentAdmin();
$userId = $currentUser['id'] ?? 0;

// Get promotor's events
$events = [];
try {
    $events = $db->getAll("
        SELECT e.*,
               s.name as series_name,
               s.logo as series_logo,
               COALESCE(reg.registration_count, 0) as registration_count,
               COALESCE(reg.confirmed_count, 0) as confirmed_count,
               COALESCE(reg.pending_count, 0) as pending_count,
               COALESCE(ord.total_paid, 0) as total_paid,
               COALESCE(ord.total_pending, 0) as total_pending
        FROM events e
        LEFT JOIN series s ON e.series_id = s.id
        JOIN promotor_events pe ON pe.event_id = e.id
        LEFT JOIN (
            SELECT event_id,
                   COUNT(*) as registration_count,
                   SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) as confirmed_count,
                   SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count
            FROM event_registrations
            GROUP BY event_id
        ) reg ON reg.event_id = e.id
        LEFT JOIN (
            SELECT event_id,
                   SUM(CASE WHEN payment_status = 'paid' THEN total_amount ELSE 0 END) as total_paid,
                   SUM(CASE WHEN payment_status = 'pending' THEN total_amount ELSE 0 END) as total_pending
            FROM orders
            GROUP BY event_id
        ) ord ON ord.event_id = e.id
        WHERE pe.user_id = ?
        ORDER BY e.date DESC
    ", [$userId]);
} catch (Exception $e) {
    error_log("Promotor events error: " . $e->getMessage());
}

// Page config for unified layout
$page_title = 'Mina Tävlingar';
$breadcrumbs = [
    ['label' => 'Mina Tävlingar']
];

include __DIR__ . '/components/unified-layout.php';
?>

<style>
.promotor-grid {
    display: grid;
    gap: var(--space-lg);
}
.event-card {
    background: var(--color-bg-surface);
    border-radius: var(--radius-lg);
    border: 1px solid var(--color-border);
    overflow: hidden;
}
.event-card-header {
    padding: var(--space-lg);
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: var(--space-md);
    border-bottom: 1px solid var(--color-border);
}
.event-info {
    flex: 1;
}
.event-title {
    font-size: var(--text-xl);
    font-weight: 600;
    color: var(--color-text-primary);
    margin: 0 0 var(--space-xs) 0;
}
.event-meta {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-md);
    color: var(--color-text-secondary);
    font-size: var(--text-sm);
}
.event-meta-item {
    display: flex;
    align-items: center;
    gap: var(--space-xs);
}
.event-meta-item i {
    width: 16px;
    height: 16px;
}
.event-series {
    display: flex;
    align-items: center;
    gap: var(--space-xs);
    font-size: var(--text-sm);
    color: var(--color-text-secondary);
    background: var(--color-bg-sunken);
    padding: var(--space-xs) var(--space-sm);
    border-radius: var(--radius-full);
}
.event-series img {
    width: 20px;
    height: 20px;
    object-fit: contain;
}
.event-card-body {
    padding: var(--space-lg);
}
.stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: var(--space-sm);
    margin-bottom: var(--space-lg);
}
@media (max-width: 600px) {
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}
.stat-box {
    background: var(--color-bg-sunken);
    padding: var(--space-md);
    border-radius: var(--radius-md);
    text-align: center;
}
.stat-value {
    font-size: var(--text-2xl);
    font-weight: 700;
    color: var(--color-text-primary);
}
.stat-value.success {
    color: var(--color-success);
}
.stat-value.pending {
    color: var(--color-warning);
}
.stat-label {
    font-size: var(--text-xs);
    color: var(--color-text-secondary);
    text-transform: uppercase;
    letter-spacing: 0.05em;
}
.event-actions {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-sm);
}
.event-actions .btn {
    display: inline-flex;
    align-items: center;
    gap: var(--space-xs);
}
.event-actions .btn i {
    width: 16px;
    height: 16px;
}
.empty-state {
    text-align: center;
    padding: var(--space-2xl);
    color: var(--color-text-secondary);
}
.empty-state i {
    width: 48px;
    height: 48px;
    margin-bottom: var(--space-md);
    opacity: 0.5;
}
.empty-state h2 {
    margin: 0 0 var(--space-sm) 0;
    color: var(--color-text-primary);
}
</style>

<?php if (empty($events)): ?>
<div class="event-card">
    <div class="empty-state">
        <i data-lucide="calendar-x"></i>
        <h2>Inga tävlingar</h2>
        <p>Du har inga tävlingar tilldelade ännu. Kontakta administratören för att få tillgång.</p>
    </div>
</div>
<?php else: ?>
<div class="promotor-grid">
    <?php foreach ($events as $event): ?>
    <div class="event-card">
        <div class="event-card-header">
            <div class="event-info">
                <h2 class="event-title"><?= h($event['name']) ?></h2>
                <div class="event-meta">
                    <span class="event-meta-item">
                        <i data-lucide="calendar"></i>
                        <?= date('j M Y', strtotime($event['date'])) ?>
                    </span>
                    <?php if ($event['location']): ?>
                    <span class="event-meta-item">
                        <i data-lucide="map-pin"></i>
                        <?= h($event['location']) ?>
                    </span>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($event['series_name']): ?>
            <span class="event-series">
                <?php if ($event['series_logo']): ?>
                <img src="<?= h($event['series_logo']) ?>" alt="">
                <?php endif; ?>
                <?= h($event['series_name']) ?>
            </span>
            <?php endif; ?>
        </div>

        <div class="event-card-body">
            <div class="stats-grid">
                <div class="stat-box">
                    <div class="stat-value"><?= (int)$event['registration_count'] ?></div>
                    <div class="stat-label">Anmälda</div>
                </div>
                <div class="stat-box">
                    <div class="stat-value success"><?= (int)$event['confirmed_count'] ?></div>
                    <div class="stat-label">Bekräftade</div>
                </div>
                <div class="stat-box">
                    <div class="stat-value pending"><?= (int)$event['pending_count'] ?></div>
                    <div class="stat-label">Väntande</div>
                </div>
                <div class="stat-box">
                    <div class="stat-value"><?= number_format($event['total_paid'], 0, ',', ' ') ?> kr</div>
                    <div class="stat-label">Betalat</div>
                </div>
            </div>

            <div class="event-actions">
                <a href="/admin/event-edit.php?id=<?= $event['id'] ?>" class="btn btn-primary">
                    <i data-lucide="pencil"></i>
                    Redigera event
                </a>
                <a href="/admin/promotor-registrations.php?event_id=<?= $event['id'] ?>" class="btn btn-secondary">
                    <i data-lucide="users"></i>
                    Anmälningar
                </a>
                <a href="/admin/promotor-payments.php?event_id=<?= $event['id'] ?>" class="btn btn-secondary">
                    <i data-lucide="credit-card"></i>
                    Betalningar
                </a>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>

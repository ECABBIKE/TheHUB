<?php
/**
 * Admin Festivals - Lista och hantera festivaler
 * Dolt: Enbart admin (ej promotorer ännu)
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

global $pdo;

// ============================================================
// HANDLE ACTIONS
// ============================================================

// Delete festival
if (isset($_POST['action']) && $_POST['action'] === 'delete' && isset($_POST['festival_id'])) {
    $festId = intval($_POST['festival_id']);
    $stmt = $pdo->prepare("DELETE FROM festivals WHERE id = ?");
    $stmt->execute([$festId]);
    $_SESSION['flash_message'] = 'Festival raderad';
    $_SESSION['flash_type'] = 'success';
    header('Location: /admin/festivals.php');
    exit;
}

// Toggle status
if (isset($_POST['action']) && $_POST['action'] === 'toggle_status' && isset($_POST['festival_id'])) {
    $festId = intval($_POST['festival_id']);
    $newStatus = $_POST['new_status'] ?? 'draft';
    $allowed = ['draft', 'published', 'completed', 'cancelled'];
    if (in_array($newStatus, $allowed)) {
        $stmt = $pdo->prepare("UPDATE festivals SET status = ? WHERE id = ?");
        $stmt->execute([$newStatus, $festId]);
    }
    header('Location: /admin/festivals.php');
    exit;
}

// ============================================================
// LOAD DATA
// ============================================================

// Check if festivals table exists
$tableExists = false;
try {
    $pdo->query("SELECT 1 FROM festivals LIMIT 1");
    $tableExists = true;
} catch (PDOException $e) {
    // Table doesn't exist yet
}

$festivals = [];
$stats = ['total' => 0, 'draft' => 0, 'published' => 0, 'completed' => 0];

if ($tableExists) {
    // Stats
    $statsRow = $pdo->query("
        SELECT
            COUNT(*) as total,
            SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) as draft,
            SUM(CASE WHEN status = 'published' THEN 1 ELSE 0 END) as published,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed
        FROM festivals WHERE active = 1
    ")->fetch(PDO::FETCH_ASSOC);
    if ($statsRow) {
        $stats = $statsRow;
    }

    // All festivals with event/activity counts
    $festivals = $pdo->query("
        SELECT f.*,
            (SELECT COUNT(*) FROM festival_events fe WHERE fe.festival_id = f.id) as event_count,
            (SELECT COUNT(*) FROM festival_activities fa WHERE fa.festival_id = f.id AND fa.active = 1) as activity_count,
            (SELECT COUNT(*) FROM festival_passes fp WHERE fp.festival_id = f.id AND fp.status = 'active') as pass_count,
            v.name as venue_name
        FROM festivals f
        LEFT JOIN venues v ON f.venue_id = v.id
        WHERE f.active = 1
        ORDER BY f.start_date DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
}

// ============================================================
// PAGE CONFIG
// ============================================================
$page_title = 'Festivaler';
$breadcrumbs = [['label' => 'Festivaler']];
include __DIR__ . '/components/unified-layout.php';
?>

<style>
.festival-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
    gap: var(--space-lg);
    margin-top: var(--space-lg);
}
.festival-card {
    background: var(--color-bg-card);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    overflow: hidden;
    transition: border-color 0.2s;
}
.festival-card:hover {
    border-color: var(--color-border-strong);
}
.festival-card-banner {
    height: 120px;
    background: linear-gradient(135deg, var(--color-accent-light), var(--color-bg-hover));
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
}
.festival-card-banner img {
    max-height: 100%;
    max-width: 100%;
    object-fit: cover;
    width: 100%;
    height: 100%;
}
.festival-card-banner .festival-status {
    position: absolute;
    top: var(--space-xs);
    right: var(--space-xs);
}
.festival-card-body {
    padding: var(--space-md);
}
.festival-card-title {
    font-family: var(--font-heading);
    font-size: 1.25rem;
    color: var(--color-text-primary);
    margin-bottom: var(--space-2xs);
}
.festival-card-meta {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-sm);
    color: var(--color-text-muted);
    font-size: 0.85rem;
    margin-bottom: var(--space-sm);
}
.festival-card-meta span {
    display: flex;
    align-items: center;
    gap: 4px;
}
.festival-card-stats {
    display: flex;
    gap: var(--space-md);
    padding-top: var(--space-sm);
    border-top: 1px solid var(--color-border);
    margin-top: var(--space-sm);
}
.festival-stat {
    text-align: center;
    flex: 1;
}
.festival-stat-value {
    font-size: 1.25rem;
    font-weight: 700;
    color: var(--color-accent);
}
.festival-stat-label {
    font-size: 0.75rem;
    color: var(--color-text-muted);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.festival-card-actions {
    display: flex;
    gap: var(--space-xs);
    padding: var(--space-sm) var(--space-md);
    border-top: 1px solid var(--color-border);
    background: var(--color-bg-hover);
}
.festival-card-actions a,
.festival-card-actions button {
    flex: 1;
}
.empty-state {
    text-align: center;
    padding: var(--space-3xl) var(--space-lg);
    color: var(--color-text-muted);
}
.empty-state i {
    width: 64px;
    height: 64px;
    color: var(--color-accent);
    margin-bottom: var(--space-md);
    opacity: 0.5;
}
@media (max-width: 767px) {
    .festival-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<?php if (!$tableExists): ?>
<div class="alert alert-warning" style="margin-bottom: var(--space-lg);">
    <i data-lucide="alert-triangle"></i>
    Festival-tabellerna finns inte ännu. Kör migration 085 via <a href="/admin/migrations.php">Migrationer</a>.
</div>
<?php endif; ?>

<!-- Stats -->
<div class="stats-grid" style="display: grid; grid-template-columns: repeat(4, 1fr); gap: var(--space-md); margin-bottom: var(--space-lg);">
    <div class="admin-card" style="padding: var(--space-md); text-align: center;">
        <div style="font-size: 1.75rem; font-weight: 700; color: var(--color-accent);"><?= $stats['total'] ?></div>
        <div style="font-size: 0.8rem; color: var(--color-text-muted); text-transform: uppercase;">Totalt</div>
    </div>
    <div class="admin-card" style="padding: var(--space-md); text-align: center;">
        <div style="font-size: 1.75rem; font-weight: 700; color: var(--color-warning);"><?= $stats['draft'] ?></div>
        <div style="font-size: 0.8rem; color: var(--color-text-muted); text-transform: uppercase;">Utkast</div>
    </div>
    <div class="admin-card" style="padding: var(--space-md); text-align: center;">
        <div style="font-size: 1.75rem; font-weight: 700; color: var(--color-success);"><?= $stats['published'] ?></div>
        <div style="font-size: 0.8rem; color: var(--color-text-muted); text-transform: uppercase;">Publicerade</div>
    </div>
    <div class="admin-card" style="padding: var(--space-md); text-align: center;">
        <div style="font-size: 1.75rem; font-weight: 700; color: var(--color-text-muted);"><?= $stats['completed'] ?></div>
        <div style="font-size: 0.8rem; color: var(--color-text-muted); text-transform: uppercase;">Avslutade</div>
    </div>
</div>

<!-- Header med skapa-knapp -->
<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-md);">
    <h2 style="margin: 0; font-family: var(--font-heading-secondary); font-size: 1.1rem; color: var(--color-text-secondary);">
        Alla festivaler
    </h2>
    <a href="/admin/festival-edit.php?new=1" class="btn-admin btn-admin-primary">
        <i data-lucide="plus"></i> Ny festival
    </a>
</div>

<?php if (empty($festivals) && $tableExists): ?>
<div class="empty-state">
    <i data-lucide="tent"></i>
    <h3 style="color: var(--color-text-primary); margin-bottom: var(--space-xs);">Inga festivaler ännu</h3>
    <p>Skapa din första festival för att gruppera tävlingsevent och aktiviteter.</p>
    <a href="/admin/festival-edit.php?new=1" class="btn-admin btn-admin-primary" style="margin-top: var(--space-md);">
        <i data-lucide="plus"></i> Skapa festival
    </a>
</div>
<?php elseif ($tableExists): ?>

<div class="festival-grid">
    <?php foreach ($festivals as $f): ?>
    <?php
        $statusBadge = [
            'draft' => '<span class="badge badge-warning">Utkast</span>',
            'published' => '<span class="badge badge-success">Publicerad</span>',
            'completed' => '<span class="badge" style="background: var(--color-text-muted); color: #fff;">Avslutad</span>',
            'cancelled' => '<span class="badge badge-danger">Inställd</span>',
        ];
        $badge = $statusBadge[$f['status']] ?? '';
        $dateStr = date('j M', strtotime($f['start_date']));
        if ($f['end_date'] && $f['end_date'] !== $f['start_date']) {
            $dateStr .= ' – ' . date('j M Y', strtotime($f['end_date']));
        } else {
            $dateStr .= ' ' . date('Y', strtotime($f['start_date']));
        }
    ?>
    <div class="festival-card">
        <div class="festival-card-banner">
            <?php if ($f['header_banner_media_id']): ?>
                <?php
                    $bannerStmt = $pdo->prepare("SELECT url FROM media WHERE id = ?");
                    $bannerStmt->execute([$f['header_banner_media_id']]);
                    $bannerUrl = $bannerStmt->fetchColumn();
                ?>
                <?php if ($bannerUrl): ?>
                    <img src="<?= htmlspecialchars($bannerUrl) ?>" alt="">
                <?php endif; ?>
            <?php else: ?>
                <i data-lucide="tent" style="width: 48px; height: 48px; color: var(--color-accent); opacity: 0.3;"></i>
            <?php endif; ?>
            <div class="festival-status"><?= $badge ?></div>
        </div>

        <div class="festival-card-body">
            <h3 class="festival-card-title"><?= htmlspecialchars($f['name']) ?></h3>

            <div class="festival-card-meta">
                <span><i data-lucide="calendar" style="width: 14px; height: 14px;"></i> <?= $dateStr ?></span>
                <?php if ($f['location']): ?>
                <span><i data-lucide="map-pin" style="width: 14px; height: 14px;"></i> <?= htmlspecialchars($f['location']) ?></span>
                <?php endif; ?>
                <?php if ($f['venue_name']): ?>
                <span><i data-lucide="mountain" style="width: 14px; height: 14px;"></i> <?= htmlspecialchars($f['venue_name']) ?></span>
                <?php endif; ?>
            </div>

            <?php if ($f['short_description']): ?>
            <p style="color: var(--color-text-secondary); font-size: 0.875rem; margin: 0;">
                <?= htmlspecialchars(mb_strimwidth($f['short_description'], 0, 120, '...')) ?>
            </p>
            <?php endif; ?>

            <div class="festival-card-stats">
                <div class="festival-stat">
                    <div class="festival-stat-value"><?= $f['event_count'] ?></div>
                    <div class="festival-stat-label">Event</div>
                </div>
                <div class="festival-stat">
                    <div class="festival-stat-value"><?= $f['activity_count'] ?></div>
                    <div class="festival-stat-label">Aktiviteter</div>
                </div>
                <div class="festival-stat">
                    <div class="festival-stat-value"><?= $f['pass_count'] ?></div>
                    <div class="festival-stat-label">Pass</div>
                </div>
            </div>
        </div>

        <div class="festival-card-actions">
            <a href="/admin/festival-edit.php?id=<?= $f['id'] ?>" class="btn-admin btn-admin-primary" style="text-align: center;">
                <i data-lucide="pencil"></i> Redigera
            </a>
            <?php if ($f['status'] === 'published'): ?>
            <a href="/festival/<?= $f['id'] ?>" class="btn-admin btn-admin-secondary" style="text-align: center;" target="_blank">
                <i data-lucide="external-link"></i> Visa
            </a>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php endif; ?>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>

<?php
/**
 * Publik festival-lista - /festival
 * Visar alla publicerade festivaler
 */

// Prevent direct access
if (!defined('HUB_ROOT')) {
    header('Location: /');
    exit;
}

$pdo = hub_db();

// Check if festival section is publicly visible
$isAdmin = !empty($_SESSION['admin_role']) && in_array($_SESSION['admin_role'], ['admin', 'super_admin']);
$festivalPublic = (site_setting('festival_public_enabled', '0') === '1');
if (!$festivalPublic && !$isAdmin) {
    http_response_code(404);
    $pageTitle = '404';
    echo '<div class="card" style="padding: var(--space-2xl); text-align: center;"><h2>Sidan hittades inte</h2><p><a href="/">Tillbaka till startsidan</a></p></div>';
    return;
}

// Check table exists
$tableExists = false;
try {
    $pdo->query("SELECT 1 FROM festivals LIMIT 1");
    $tableExists = true;
} catch (PDOException $e) {}

$festivals = [];
if ($tableExists) {
    $statusFilter = $isAdmin ? "f.active = 1" : "f.status = 'published' AND f.active = 1";

    $festivals = $pdo->query("
        SELECT f.*,
            v.name as venue_name,
            (SELECT COUNT(*) FROM festival_events fe WHERE fe.festival_id = f.id) as event_count,
            (SELECT COUNT(*) FROM festival_activities fa WHERE fa.festival_id = f.id AND fa.active = 1) as activity_count
        FROM festivals f
        LEFT JOIN venues v ON f.venue_id = v.id
        WHERE $statusFilter
        ORDER BY f.start_date ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
}

$months = ['jan','feb','mar','apr','maj','jun','jul','aug','sep','okt','nov','dec'];

$pageTitle = 'Festivaler';
?>

<link rel="stylesheet" href="/assets/css/pages/festival.css?v=<?= filemtime(HUB_ROOT . '/assets/css/pages/festival.css') ?>">

<main class="container">

    <div class="festival-list-header">
        <h1 class="festival-list-title">Festivaler</h1>
        <p class="festival-list-subtitle">Upplev tävlingar, clinics, gruppträningar och mer</p>
    </div>

    <?php if (empty($festivals)): ?>
    <div class="card" style="padding: var(--space-2xl); text-align: center;">
        <i data-lucide="tent" style="width: 48px; height: 48px; color: var(--color-accent); opacity: 0.4; margin-bottom: var(--space-md);"></i>
        <h3 style="color: var(--color-text-primary);">Inga festivaler just nu</h3>
        <p style="color: var(--color-text-muted);">Håll utkik efter kommande festivaler!</p>
    </div>
    <?php else: ?>

    <div class="festival-list-grid">
        <?php foreach ($festivals as $f):
            $dateStr = date('j', strtotime($f['start_date']));
            if ($f['end_date'] && $f['end_date'] !== $f['start_date']) {
                $dateStr .= '–' . date('j', strtotime($f['end_date']));
            }
            $dateStr .= ' ' . $months[date('n', strtotime($f['start_date'])) - 1] . ' ' . date('Y', strtotime($f['start_date']));
            $isPast = strtotime($f['end_date'] ?: $f['start_date']) < time();

            // Get banner
            $bannerUrl = null;
            if ($f['header_banner_media_id']) {
                $bStmt = $pdo->prepare("SELECT url FROM media WHERE id = ?");
                $bStmt->execute([$f['header_banner_media_id']]);
                $bannerUrl = $bStmt->fetchColumn() ?: null;
            }
        ?>
        <a href="/festival/<?= $f['id'] ?>" class="festival-list-card <?= $isPast ? 'festival-list-card--past' : '' ?>">
            <div class="festival-list-card-banner" <?php if ($bannerUrl): ?>style="background-image: url('<?= htmlspecialchars($bannerUrl) ?>');"<?php endif; ?>>
                <?php if (!$bannerUrl): ?>
                <i data-lucide="tent" style="width: 40px; height: 40px; color: var(--color-accent); opacity: 0.3;"></i>
                <?php endif; ?>
                <?php if ($f['status'] === 'draft'): ?>
                <span class="badge badge-warning" style="position: absolute; top: 8px; right: 8px;">Utkast</span>
                <?php endif; ?>
            </div>
            <div class="festival-list-card-body">
                <h3 class="festival-list-card-title"><?= htmlspecialchars($f['name']) ?></h3>
                <div class="festival-list-card-meta">
                    <span><i data-lucide="calendar" style="width: 13px; height: 13px;"></i> <?= $dateStr ?></span>
                    <?php if ($f['location']): ?>
                    <span><i data-lucide="map-pin" style="width: 13px; height: 13px;"></i> <?= htmlspecialchars($f['location']) ?></span>
                    <?php endif; ?>
                </div>
                <?php if ($f['short_description']): ?>
                <p class="festival-list-card-desc"><?= htmlspecialchars(mb_strimwidth($f['short_description'], 0, 120, '...')) ?></p>
                <?php endif; ?>
                <div class="festival-list-card-footer">
                    <span><?= $f['event_count'] ?> tävling<?= $f['event_count'] !== 1 ? 'ar' : '' ?></span>
                    <span><?= $f['activity_count'] ?> aktivitet<?= $f['activity_count'] !== 1 ? 'er' : '' ?></span>
                </div>
            </div>
        </a>
        <?php endforeach; ?>
    </div>

    <?php endif; ?>

</main>



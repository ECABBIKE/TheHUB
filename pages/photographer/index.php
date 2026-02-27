<?php
/**
 * TheHUB - Fotografer
 * Lista alla aktiva fotografer
 */

if (!defined('HUB_ROOT')) {
    header('Location: /gallery');
    exit;
}

define('HUB_PAGE_TYPE', 'photographer_list');

$pdo = hub_db();

// Get all active photographers with stats
$photographers = $pdo->query("
    SELECT p.*,
           r.firstname as rider_firstname, r.lastname as rider_lastname,
           COUNT(DISTINCT ea.id) as album_count,
           COALESCE(SUM(ea.photo_count), 0) as total_photos
    FROM photographers p
    LEFT JOIN riders r ON p.rider_id = r.id
    LEFT JOIN event_albums ea ON ea.photographer_id = p.id AND ea.is_published = 1
    WHERE p.active = 1
    GROUP BY p.id
    ORDER BY total_photos DESC, p.name ASC
")->fetchAll(PDO::FETCH_ASSOC);
?>

<h2 style="font-family: var(--font-heading-secondary); font-size: 1.1rem; color: var(--color-text-secondary); margin-bottom: var(--space-md); text-transform: uppercase; letter-spacing: 0.5px;">
    <i data-lucide="camera" style="width: 18px; height: 18px; vertical-align: -3px;"></i> Fotografer
</h2>

<?php if (empty($photographers)): ?>
<div class="card">
    <div style="padding: var(--space-2xl); text-align: center;">
        <i data-lucide="camera-off" style="width: 48px; height: 48px; color: var(--color-text-muted); margin-bottom: var(--space-md);"></i>
        <p style="color: var(--color-text-muted);">Inga fotografer registrerade</p>
    </div>
</div>
<?php else: ?>
<div class="photographer-list-grid">
    <?php foreach ($photographers as $p):
        $socials = [];
        if ($p['website_url']) $socials[] = 'globe';
        if ($p['instagram_url']) $socials[] = 'instagram';
        if ($p['facebook_url']) $socials[] = 'facebook';
        if ($p['youtube_url']) $socials[] = 'youtube';
    ?>
    <a href="/photographer/<?= $p['id'] ?>" class="photographer-list-card">
        <div class="photographer-list-avatar">
            <?php if ($p['avatar_url']): ?>
            <img src="<?= htmlspecialchars($p['avatar_url']) ?>" alt="<?= htmlspecialchars($p['name']) ?>">
            <?php else: ?>
            <i data-lucide="camera" style="width: 32px; height: 32px; color: var(--color-text-muted);"></i>
            <?php endif; ?>
        </div>
        <div class="photographer-list-info">
            <h3 class="photographer-list-name"><?= htmlspecialchars($p['name']) ?></h3>
            <?php if ($p['rider_firstname']): ?>
            <div style="font-size: 0.75rem; color: var(--color-accent-text); margin-bottom: 4px;">
                <i data-lucide="user" style="width: 11px; height: 11px; vertical-align: -1px;"></i>
                Deltagare
            </div>
            <?php endif; ?>
            <div class="photographer-list-stats">
                <span><?= $p['album_count'] ?> album</span>
                <span><?= number_format($p['total_photos']) ?> bilder</span>
            </div>
        </div>
        <?php if (!empty($socials)): ?>
        <div class="photographer-list-socials">
            <?php foreach ($socials as $icon): ?>
            <i data-lucide="<?= $icon ?>" style="width: 14px; height: 14px; color: var(--color-text-muted);"></i>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<style>
.photographer-list-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: var(--space-sm);
}
.photographer-list-card {
    display: flex;
    align-items: center;
    gap: var(--space-md);
    padding: var(--space-md);
    background: var(--color-bg-card);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    text-decoration: none;
    color: inherit;
    transition: border-color 0.2s, background 0.2s;
}
.photographer-list-card:hover {
    border-color: var(--color-accent);
    background: var(--color-bg-hover);
}
.photographer-list-avatar {
    flex-shrink: 0;
    width: 56px;
    height: 56px;
    border-radius: 50%;
    overflow: hidden;
    background: var(--color-bg-hover);
    display: flex;
    align-items: center;
    justify-content: center;
    border: 2px solid var(--color-border);
}
.photographer-list-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.photographer-list-info {
    flex: 1;
    min-width: 0;
}
.photographer-list-name {
    font-family: var(--font-heading-secondary);
    font-size: 1rem;
    font-weight: 600;
    color: var(--color-text-primary);
    margin: 0;
}
.photographer-list-stats {
    display: flex;
    gap: var(--space-sm);
    font-size: 0.75rem;
    color: var(--color-text-muted);
}
.photographer-list-socials {
    display: flex;
    gap: 6px;
    flex-shrink: 0;
}

@media (max-width: 767px) {
    .photographer-list-grid {
        grid-template-columns: 1fr;
        gap: 0;
        margin-left: -16px;
        margin-right: -16px;
        width: calc(100% + 32px);
    }
    .photographer-list-card {
        border-radius: 0;
        border-left: none;
        border-right: none;
        border-bottom: none;
    }
    .photographer-list-card:first-child {
        border-top: none;
    }
}
</style>

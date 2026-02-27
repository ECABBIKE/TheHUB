<?php
/**
 * TheHUB - Fotografprofil
 * Visar en fotografs profil med bio, sociala medier och alla deras gallerier
 */

if (!defined('HUB_ROOT')) {
    header('Location: /gallery');
    exit;
}

define('HUB_PAGE_TYPE', 'photographer');

$pdo = hub_db();
$photographerId = intval($pageInfo['params']['id'] ?? 0);

if (!$photographerId) {
    header('Location: /gallery');
    exit;
}

// Get photographer
$stmt = $pdo->prepare("
    SELECT p.*,
           r.firstname as rider_firstname, r.lastname as rider_lastname, r.id as linked_rider_id
    FROM photographers p
    LEFT JOIN riders r ON p.rider_id = r.id
    WHERE p.id = ? AND p.active = 1
");
$stmt->execute([$photographerId]);
$photographer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$photographer) {
    header('Location: /gallery');
    exit;
}

$pageTitle = $photographer['name'] . ' - Fotograf';

// Generate initials for avatar fallback
$nameParts = explode(' ', trim($photographer['name']));
$initials = strtoupper(substr($nameParts[0] ?? '', 0, 1) . substr(end($nameParts) ?: '', 0, 1));
if (strlen($initials) < 2) $initials = strtoupper(substr($photographer['name'], 0, 2));

$avatarUrl = $photographer['avatar_url'] ?: null;
if (!$avatarUrl) {
    $avatarUrl = 'https://ui-avatars.com/api/?name=' . urlencode($photographer['name']) . '&size=200&background=0066CC&color=ffffff&bold=true&format=svg';
}

// Get their albums
$albumStmt = $pdo->prepare("
    SELECT ea.id, ea.event_id, ea.title, ea.photo_count, ea.created_at,
           e.name as event_name, e.date as event_date, e.location as event_location,
           cover.external_url as cover_url, cover.thumbnail_url as cover_thumb,
           cover_media.filepath as cover_filepath
    FROM event_albums ea
    JOIN events e ON ea.event_id = e.id
    LEFT JOIN event_photos cover ON cover.id = ea.cover_photo_id
    LEFT JOIN media cover_media ON cover.media_id = cover_media.id
    WHERE ea.photographer_id = ? AND ea.is_published = 1
    ORDER BY e.date DESC
");
$albumStmt->execute([$photographerId]);
$albums = $albumStmt->fetchAll(PDO::FETCH_ASSOC);

// If no cover photo set, get first photo from each album
foreach ($albums as &$album) {
    if (!$album['cover_url'] && !$album['cover_filepath']) {
        $firstPhoto = $pdo->prepare("
            SELECT ep.external_url, ep.thumbnail_url, m.filepath
            FROM event_photos ep
            LEFT JOIN media m ON ep.media_id = m.id
            WHERE ep.album_id = ?
            ORDER BY ep.sort_order ASC, ep.id ASC
            LIMIT 1
        ");
        $firstPhoto->execute([$album['id']]);
        $fp = $firstPhoto->fetch(PDO::FETCH_ASSOC);
        if ($fp) {
            $album['cover_url'] = $fp['external_url'] ?: '';
            $album['cover_thumb'] = $fp['thumbnail_url'] ?: '';
            $album['cover_filepath'] = $fp['filepath'] ?: '';
        }
    }
}
unset($album);

// Stats
$totalPhotos = array_sum(array_column($albums, 'photo_count'));
$totalAlbums = count($albums);

// Social media links
$socials = [];
if ($photographer['website_url']) $socials[] = ['icon' => 'globe', 'url' => $photographer['website_url'], 'label' => 'Webbplats'];
if ($photographer['instagram_url']) $socials[] = ['icon' => 'instagram', 'url' => $photographer['instagram_url'], 'label' => 'Instagram'];
if (!empty($photographer['tiktok_url'])) $socials[] = ['icon' => 'music', 'url' => $photographer['tiktok_url'], 'label' => 'TikTok'];
if ($photographer['facebook_url']) $socials[] = ['icon' => 'facebook', 'url' => $photographer['facebook_url'], 'label' => 'Facebook'];
if ($photographer['youtube_url']) $socials[] = ['icon' => 'youtube', 'url' => $photographer['youtube_url'], 'label' => 'YouTube'];
?>

<!-- Photographer Profile Card -->
<div class="photographer-card-v4">
    <!-- Square Photo or Initials -->
    <div class="photographer-photo-hero <?= $photographer['avatar_url'] ? '' : 'initials-bg' ?>">
        <?php if ($photographer['avatar_url']): ?>
        <img src="<?= htmlspecialchars($photographer['avatar_url']) ?>" alt="<?= htmlspecialchars($photographer['name']) ?>" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
        <div class="photographer-initials-fallback" style="display: none;"><?= htmlspecialchars($initials) ?></div>
        <?php else: ?>
        <div class="photographer-initials"><?= htmlspecialchars($initials) ?></div>
        <?php endif; ?>
    </div>

    <!-- Info Section -->
    <div class="photographer-info-section">
        <h1 class="photographer-profile-name">
            <?= htmlspecialchars($photographer['name']) ?>
            <?php if (function_exists('hub_is_admin') && hub_is_admin()): ?>
            <a href="/admin/photographers.php?edit=<?= $photographerId ?>" class="photographer-edit-link" title="Redigera fotograf">
                <i data-lucide="pencil" style="width: 16px; height: 16px;"></i>
            </a>
            <?php endif; ?>
        </h1>

        <div class="photographer-role-badge">
            <i data-lucide="camera" style="width: 14px; height: 14px;"></i> Fotograf
        </div>

        <?php if ($photographer['linked_rider_id']): ?>
        <a href="/rider/<?= $photographer['linked_rider_id'] ?>" class="photographer-rider-link">
            <i data-lucide="user" style="width: 14px; height: 14px;"></i>
            <?= htmlspecialchars($photographer['rider_firstname'] . ' ' . $photographer['rider_lastname']) ?> - Deltagarprofil
        </a>
        <?php endif; ?>

        <?php if ($photographer['bio']): ?>
        <p class="photographer-bio"><?= nl2br(htmlspecialchars($photographer['bio'])) ?></p>
        <?php endif; ?>

        <!-- Stats -->
        <div class="photographer-stats-row">
            <div class="photographer-stat">
                <span class="photographer-stat-value"><?= $totalAlbums ?></span>
                <span class="photographer-stat-label">Album</span>
            </div>
            <div class="photographer-stat">
                <span class="photographer-stat-value"><?= number_format($totalPhotos) ?></span>
                <span class="photographer-stat-label">Bilder</span>
            </div>
        </div>

        <?php if (!empty($socials)): ?>
        <div class="photographer-socials">
            <?php foreach ($socials as $social): ?>
            <a href="<?= htmlspecialchars($social['url']) ?>" target="_blank" rel="noopener" class="photographer-social-link" title="<?= $social['label'] ?>">
                <i data-lucide="<?= $social['icon'] ?>" style="width: 18px; height: 18px;"></i>
                <span><?= $social['label'] ?></span>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Albums -->
<?php if (empty($albums)): ?>
<div class="card">
    <div style="padding: var(--space-2xl); text-align: center;">
        <i data-lucide="image-off" style="width: 48px; height: 48px; color: var(--color-text-muted); margin-bottom: var(--space-md);"></i>
        <p style="color: var(--color-text-muted);">Inga publicerade album</p>
    </div>
</div>
<?php else: ?>
<h2 style="font-family: var(--font-heading-secondary); font-size: 1.1rem; color: var(--color-text-secondary); margin-bottom: var(--space-md); text-transform: uppercase; letter-spacing: 0.5px;">
    <i data-lucide="image" style="width: 18px; height: 18px; vertical-align: -3px;"></i> Album
</h2>
<div class="gallery-listing-grid">
    <?php foreach ($albums as $album):
        $coverSrc = '';
        if ($album['cover_thumb']) {
            $coverSrc = $album['cover_thumb'];
        } elseif ($album['cover_url']) {
            $coverSrc = $album['cover_url'];
        } elseif ($album['cover_filepath']) {
            $coverSrc = '/' . ltrim($album['cover_filepath'], '/');
        }
        $eventDate = $album['event_date'] ? date('j M Y', strtotime($album['event_date'])) : '';
    ?>
    <a href="/event/<?= $album['event_id'] ?>?tab=galleri" class="gallery-listing-card">
        <div class="gallery-listing-cover">
            <?php if ($coverSrc): ?>
            <img src="<?= htmlspecialchars($coverSrc) ?>" alt="<?= htmlspecialchars($album['title'] ?: $album['event_name']) ?>" loading="lazy">
            <?php else: ?>
            <div class="gallery-listing-placeholder">
                <i data-lucide="image" style="width: 48px; height: 48px; color: var(--color-text-muted);"></i>
            </div>
            <?php endif; ?>
            <div class="gallery-listing-photo-count">
                <i data-lucide="image" style="width: 14px; height: 14px;"></i>
                <?= $album['photo_count'] ?>
            </div>
        </div>
        <div class="gallery-listing-info">
            <h3 class="gallery-listing-title"><?= htmlspecialchars($album['title'] ?: $album['event_name']) ?></h3>
            <div class="gallery-listing-meta">
                <?php if ($eventDate): ?><span><?= $eventDate ?></span><?php endif; ?>
                <?php if ($album['event_location']): ?><span><?= htmlspecialchars($album['event_location']) ?></span><?php endif; ?>
            </div>
        </div>
    </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<style>
/* Photographer Profile Card - Same style as rider profile-card-v4 */
.photographer-card-v4 {
    background: var(--color-bg-surface);
    border-radius: var(--radius-lg);
    padding: 0;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    box-shadow: var(--shadow-md);
    border: 1px solid var(--color-border);
    margin-bottom: var(--space-lg);
}
.photographer-photo-hero {
    width: 100%;
    aspect-ratio: 1 / 1;
    background: var(--color-bg-sunken);
    overflow: hidden;
}
.photographer-photo-hero img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    object-position: center top;
}
.photographer-photo-hero.initials-bg {
    background: linear-gradient(135deg, var(--color-accent) 0%, #004d99 100%);
}
.photographer-initials,
.photographer-initials-fallback {
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 4rem;
    font-weight: 700;
    color: white;
    text-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
    background: linear-gradient(135deg, var(--color-accent) 0%, #004d99 100%);
}
.photographer-info-section {
    padding: var(--space-lg);
    text-align: center;
}
.photographer-profile-name {
    font-family: var(--font-heading);
    font-size: 1.5rem;
    color: var(--color-text-primary);
    margin: 0 0 var(--space-xs);
    line-height: 1.2;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: var(--space-xs);
}
.photographer-edit-link {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    border-radius: 50%;
    color: var(--color-text-muted);
    transition: background 0.2s, color 0.2s;
}
.photographer-edit-link:hover {
    background: var(--color-accent-light);
    color: var(--color-accent-text);
}
.photographer-role-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3px 12px;
    border-radius: var(--radius-full);
    background: var(--color-accent-light);
    color: var(--color-accent-text);
    font-size: 0.8rem;
    font-weight: 500;
    margin-bottom: var(--space-sm);
}
.photographer-rider-link {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 0.8rem;
    color: var(--color-accent-text);
    text-decoration: none;
    margin-bottom: var(--space-sm);
}
.photographer-rider-link:hover {
    text-decoration: underline;
}
.photographer-bio {
    font-size: 0.9rem;
    color: var(--color-text-secondary);
    line-height: 1.5;
    margin: var(--space-sm) 0;
    text-align: left;
}
.photographer-stats-row {
    display: flex;
    justify-content: center;
    gap: var(--space-xl);
    padding: var(--space-md) 0;
    border-top: 1px solid var(--color-border);
    margin-top: var(--space-sm);
}
.photographer-stat {
    text-align: center;
}
.photographer-stat-value {
    display: block;
    font-size: 1.4rem;
    font-weight: 700;
    color: var(--color-accent-text);
    font-family: var(--font-heading);
    line-height: 1.2;
}
.photographer-stat-label {
    font-size: 0.7rem;
    color: var(--color-text-muted);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-top: 2px;
}
.photographer-socials {
    display: flex;
    justify-content: center;
    gap: var(--space-sm);
    margin-top: var(--space-md);
    padding-top: var(--space-sm);
    border-top: 1px solid var(--color-border);
}
.photographer-social-link {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 14px;
    border-radius: var(--radius-full);
    background: var(--color-bg-hover);
    color: var(--color-text-secondary);
    font-size: 0.8rem;
    text-decoration: none;
    transition: background 0.2s, color 0.2s;
}
.photographer-social-link:hover {
    background: var(--color-accent-light);
    color: var(--color-accent-text);
}

/* Gallery listing grid (shared with gallery/index.php) */
.gallery-listing-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: var(--space-md);
}
.gallery-listing-card {
    background: var(--color-bg-card);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    overflow: hidden;
    text-decoration: none;
    color: inherit;
    transition: transform 0.2s, box-shadow 0.2s, border-color 0.2s;
}
.gallery-listing-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(0,0,0,0.15);
    border-color: var(--color-accent);
}
.gallery-listing-cover {
    position: relative;
    aspect-ratio: 16/10;
    overflow: hidden;
    background: var(--color-bg-page);
}
.gallery-listing-cover img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform 0.3s ease;
}
.gallery-listing-card:hover .gallery-listing-cover img {
    transform: scale(1.05);
}
.gallery-listing-placeholder {
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--color-bg-hover);
}
.gallery-listing-photo-count {
    position: absolute;
    bottom: var(--space-xs);
    right: var(--space-xs);
    background: rgba(0,0,0,0.7);
    color: #fff;
    padding: 3px 10px;
    border-radius: var(--radius-full);
    font-size: 0.75rem;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 4px;
}
.gallery-listing-info {
    padding: var(--space-sm) var(--space-md) var(--space-md);
}
.gallery-listing-title {
    font-family: var(--font-heading-secondary);
    font-size: 1rem;
    font-weight: 600;
    color: var(--color-text-primary);
    margin: 0 0 2px;
}
.gallery-listing-meta {
    display: flex;
    gap: var(--space-sm);
    font-size: 0.75rem;
    color: var(--color-text-muted);
}

@media (max-width: 767px) {
    .photographer-card-v4 {
        border-radius: 0;
        border-left: none;
        border-right: none;
        margin-left: -16px;
        margin-right: -16px;
        width: calc(100% + 32px);
    }
    .photographer-photo-hero {
        aspect-ratio: 4 / 3;
    }
    .photographer-initials,
    .photographer-initials-fallback {
        font-size: 3rem;
    }
    .photographer-info-section {
        padding: var(--space-md);
    }
    .photographer-profile-name {
        font-size: 1.2rem;
    }
    .photographer-social-link span {
        display: none;
    }
    .photographer-social-link {
        width: 40px;
        height: 40px;
        padding: 0;
        border-radius: 50%;
        justify-content: center;
    }
    .gallery-listing-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: var(--space-xs);
        margin-left: -16px;
        margin-right: -16px;
        width: calc(100% + 32px);
    }
    .gallery-listing-card {
        border-radius: 0;
        border-left: none;
        border-right: none;
    }
    .gallery-listing-info {
        padding: var(--space-xs) var(--space-sm) var(--space-sm);
    }
    .gallery-listing-title {
        font-size: 0.85rem;
    }
}
</style>

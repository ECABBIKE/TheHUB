<?php
/**
 * TheHUB - Galleri
 * Lista alla publicerade fotoalbum med cover-bilder och fotografinfo
 */

// Prevent direct access
if (!defined('HUB_ROOT')) {
    header('Location: /gallery');
    exit;
}

define('HUB_PAGE_TYPE', 'gallery_list');

$pdo = hub_db();

// Filter parameters
$filterYear = isset($_GET['year']) && is_numeric($_GET['year']) ? intval($_GET['year']) : null;
$filterSeries = isset($_GET['series']) && is_numeric($_GET['series']) ? intval($_GET['series']) : null;
$filterPhotographer = isset($_GET['photographer']) && is_numeric($_GET['photographer']) ? intval($_GET['photographer']) : null;
$search = isset($_GET['q']) ? trim($_GET['q']) : '';

// Get available years
$years = $pdo->query("
    SELECT DISTINCT YEAR(e.date) as yr
    FROM event_albums ea
    JOIN events e ON ea.event_id = e.id
    WHERE ea.is_published = 1
    ORDER BY yr DESC
")->fetchAll(PDO::FETCH_COLUMN);

// Get available series for filter
$seriesList = $pdo->query("
    SELECT DISTINCT s.id, s.name, s.year
    FROM series s
    JOIN series_events se ON se.series_id = s.id
    JOIN event_albums ea ON ea.event_id = se.event_id
    WHERE ea.is_published = 1
    ORDER BY s.year DESC, s.name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Get photographers for filter
$photographers = $pdo->query("
    SELECT DISTINCT p.id, p.name
    FROM photographers p
    JOIN event_albums ea ON ea.photographer_id = p.id
    WHERE ea.is_published = 1 AND p.active = 1
    ORDER BY p.name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Build query
$where = ["ea.is_published = 1"];
$params = [];

if ($filterYear) {
    $where[] = "YEAR(e.date) = ?";
    $params[] = $filterYear;
}
if ($filterSeries) {
    $where[] = "se.series_id = ?";
    $params[] = $filterSeries;
}
if ($filterPhotographer) {
    $where[] = "ea.photographer_id = ?";
    $params[] = $filterPhotographer;
}
if ($search) {
    $where[] = "(e.name LIKE ? OR ea.title LIKE ? OR COALESCE(p.name, ea.photographer) LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

$whereClause = implode(' AND ', $where);

// Get albums with cover photos
$stmt = $pdo->prepare("
    SELECT ea.id, ea.event_id, ea.title, ea.photographer, ea.photographer_url,
           ea.photographer_id, ea.photo_count, ea.created_at,
           e.name as event_name, e.date as event_date, e.location as event_location,
           p.name as photographer_name, p.slug as photographer_slug, p.avatar_url as photographer_avatar,
           cover.external_url as cover_url, cover.thumbnail_url as cover_thumb,
           cover_media.filepath as cover_filepath,
           (SELECT COUNT(*) FROM photo_rider_tags prt
            JOIN event_photos ep2 ON prt.photo_id = ep2.id
            WHERE ep2.album_id = ea.id) as tag_count
    FROM event_albums ea
    JOIN events e ON ea.event_id = e.id
    LEFT JOIN photographers p ON ea.photographer_id = p.id
    LEFT JOIN series_events se ON se.event_id = e.id
    LEFT JOIN event_photos cover ON cover.id = ea.cover_photo_id
    LEFT JOIN media cover_media ON cover.media_id = cover_media.id
    WHERE {$whereClause}
    GROUP BY ea.id
    ORDER BY e.date DESC, ea.created_at DESC
");
$stmt->execute($params);
$albums = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
$totalAlbums = count($albums);
$totalPhotos = array_sum(array_column($albums, 'photo_count'));
$totalTags = array_sum(array_column($albums, 'tag_count'));
?>

<!-- Database tabs navigation -->
<div class="card" style="margin-bottom: var(--space-md); padding: var(--space-sm) var(--space-md) 0;">
    <div class="tabs-nav" style="margin-bottom: 0;">
        <button class="tab-pill" onclick="window.location='/database'"><i data-lucide="users"></i> Sök Åkare</button>
        <button class="tab-pill" onclick="window.location='/database?tab=clubs'"><i data-lucide="shield"></i> Sök Klubbar</button>
        <button class="tab-pill active"><i data-lucide="camera"></i> Galleri</button>
    </div>
</div>

<!-- Stats -->
<div class="card" style="margin-bottom: var(--space-lg);">
    <div style="display: grid; grid-template-columns: repeat(3, 1fr); text-align: center; padding: var(--space-md);">
        <div>
            <div style="font-size: 1.5rem; font-weight: 700; color: var(--color-accent-text); font-family: var(--font-heading);"><?= $totalAlbums ?></div>
            <div style="font-size: 0.75rem; color: var(--color-text-muted); text-transform: uppercase; letter-spacing: 0.5px;">Album</div>
        </div>
        <div>
            <div style="font-size: 1.5rem; font-weight: 700; color: var(--color-accent-text); font-family: var(--font-heading);"><?= number_format($totalPhotos) ?></div>
            <div style="font-size: 0.75rem; color: var(--color-text-muted); text-transform: uppercase; letter-spacing: 0.5px;">Bilder</div>
        </div>
        <div>
            <div style="font-size: 1.5rem; font-weight: 700; color: var(--color-accent-text); font-family: var(--font-heading);"><?= $totalTags ?></div>
            <div style="font-size: 0.75rem; color: var(--color-text-muted); text-transform: uppercase; letter-spacing: 0.5px;">Taggningar</div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card" style="margin-bottom: var(--space-md);">
    <div style="padding: var(--space-sm) var(--space-md);">
        <form method="GET" action="/gallery" style="display: flex; flex-wrap: wrap; gap: var(--space-sm); align-items: center;">
            <select name="year" class="form-select" style="flex: 1; min-width: 100px; max-width: 140px;">
                <option value="">Alla år</option>
                <?php foreach ($years as $yr): ?>
                <option value="<?= $yr ?>" <?= $filterYear == $yr ? 'selected' : '' ?>><?= $yr ?></option>
                <?php endforeach; ?>
            </select>
            <?php if (!empty($seriesList)): ?>
            <select name="series" class="form-select" style="flex: 1; min-width: 140px; max-width: 200px;">
                <option value="">Alla serier</option>
                <?php foreach ($seriesList as $s): ?>
                <option value="<?= $s['id'] ?>" <?= $filterSeries == $s['id'] ? 'selected' : '' ?>><?= htmlspecialchars($s['name']) ?> <?= $s['year'] ?></option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>
            <?php if (!empty($photographers)): ?>
            <select name="photographer" class="form-select" style="flex: 1; min-width: 140px; max-width: 200px;">
                <option value="">Alla fotografer</option>
                <?php foreach ($photographers as $ph): ?>
                <option value="<?= $ph['id'] ?>" <?= $filterPhotographer == $ph['id'] ? 'selected' : '' ?>><?= htmlspecialchars($ph['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>
            <div style="flex: 2; min-width: 160px; position: relative;">
                <input type="text" name="q" class="form-input" placeholder="Sök event eller fotograf..." value="<?= htmlspecialchars($search) ?>" style="width: 100%;">
            </div>
            <button type="submit" class="btn btn-primary" style="white-space: nowrap;">
                <i data-lucide="search" style="width: 16px; height: 16px;"></i> Sök
            </button>
            <?php if ($filterYear || $filterSeries || $filterPhotographer || $search): ?>
            <a href="/gallery" class="btn btn-ghost" style="white-space: nowrap;">Rensa</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<?php if (empty($albums)): ?>
<div class="card">
    <div style="padding: var(--space-2xl); text-align: center;">
        <i data-lucide="image-off" style="width: 48px; height: 48px; color: var(--color-text-muted); margin-bottom: var(--space-md);"></i>
        <p style="color: var(--color-text-muted); font-size: 1rem;">Inga gallerier hittades</p>
        <?php if ($filterYear || $filterSeries || $filterPhotographer || $search): ?>
        <a href="/gallery" class="btn btn-ghost" style="margin-top: var(--space-md);">Visa alla gallerier</a>
        <?php endif; ?>
    </div>
</div>
<?php else: ?>

<!-- Gallery Grid -->
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
        $photographerName = $album['photographer_name'] ?: $album['photographer'];
        $eventDate = $album['event_date'] ? date('j M Y', strtotime($album['event_date'])) : '';
    ?>
    <a href="/event/<?= $album['event_id'] ?>?tab=gallery" class="gallery-listing-card">
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
            <div class="gallery-listing-event"><?= htmlspecialchars($album['event_name']) ?></div>
            <div class="gallery-listing-meta">
                <?php if ($eventDate): ?>
                <span><?= $eventDate ?></span>
                <?php endif; ?>
                <?php if ($album['event_location']): ?>
                <span><?= htmlspecialchars($album['event_location']) ?></span>
                <?php endif; ?>
            </div>
            <?php if ($photographerName): ?>
            <div class="gallery-listing-photographer">
                <i data-lucide="camera" style="width: 13px; height: 13px;"></i>
                <?php if (!empty($album['photographer_slug'])): ?>
                <span class="gallery-photographer-link" onclick="event.preventDefault(); event.stopPropagation(); window.location='/photographer/<?= (int)$album['photographer_id'] ?>';" style="color: var(--color-accent-text); cursor: pointer;"><?= htmlspecialchars($photographerName) ?></span>
                <?php else: ?>
                <?= htmlspecialchars($photographerName) ?>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<style>
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
    backdrop-filter: blur(4px);
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
    line-height: 1.3;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.gallery-listing-event {
    font-size: 0.8rem;
    color: var(--color-text-secondary);
    margin-bottom: 4px;
}
.gallery-listing-meta {
    display: flex;
    gap: var(--space-sm);
    font-size: 0.75rem;
    color: var(--color-text-muted);
    margin-bottom: 6px;
}
.gallery-listing-photographer {
    display: flex;
    align-items: center;
    gap: 4px;
    font-size: 0.75rem;
    color: var(--color-accent-text);
    font-weight: 500;
}

@media (max-width: 767px) {
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
    .gallery-listing-event {
        display: none;
    }
}

@media (max-width: 480px) {
    .gallery-listing-grid {
        grid-template-columns: 1fr;
    }
    .gallery-listing-cover {
        aspect-ratio: 16/9;
    }
}
</style>

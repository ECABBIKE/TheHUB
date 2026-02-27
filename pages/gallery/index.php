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
$filterLocation = isset($_GET['location']) ? trim($_GET['location']) : '';
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

// Get available locations (destinations) for filter
$locations = $pdo->query("
    SELECT DISTINCT e.location
    FROM event_albums ea
    JOIN events e ON ea.event_id = e.id
    WHERE ea.is_published = 1 AND e.location IS NOT NULL AND e.location != ''
    ORDER BY e.location ASC
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
if ($filterLocation) {
    $where[] = "e.location = ?";
    $params[] = $filterLocation;
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

<!-- Database tabs navigation + Gallery filters -->
<div class="search-card">
    <div class="tabs-nav">
        <button class="tab-pill" onclick="window.location='/database'"><i data-lucide="users"></i> Sök Åkare</button>
        <button class="tab-pill" onclick="window.location='/database?tab=clubs'"><i data-lucide="shield"></i> Sök Klubbar</button>
        <button class="tab-pill active"><i data-lucide="camera"></i> Galleri</button>
    </div>

</div>

<!-- Filters -->
<form method="GET" action="/gallery" class="filter-bar">
    <div class="filter-select-wrapper">
        <label class="filter-label">År</label>
        <select name="year" class="filter-select" onchange="this.form.submit()">
            <option value="">Alla år</option>
            <?php foreach ($years as $yr): ?>
            <option value="<?= $yr ?>" <?= $filterYear == $yr ? 'selected' : '' ?>><?= $yr ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php if (!empty($locations)): ?>
    <div class="filter-select-wrapper">
        <label class="filter-label">Destination</label>
        <select name="location" class="filter-select" onchange="this.form.submit()">
            <option value="">Alla destinationer</option>
            <?php foreach ($locations as $loc): ?>
            <option value="<?= htmlspecialchars($loc) ?>" <?= $filterLocation === $loc ? 'selected' : '' ?>><?= htmlspecialchars($loc) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>
    <?php if (!empty($seriesList)): ?>
    <div class="filter-select-wrapper">
        <label class="filter-label">Serie</label>
        <select name="series" class="filter-select" onchange="this.form.submit()">
            <option value="">Alla serier</option>
            <?php foreach ($seriesList as $s): ?>
            <option value="<?= $s['id'] ?>" <?= $filterSeries == $s['id'] ? 'selected' : '' ?>><?= htmlspecialchars($s['name']) ?> <?= $s['year'] ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>
    <?php if (!empty($photographers)): ?>
    <div class="filter-select-wrapper">
        <label class="filter-label">Fotograf</label>
        <select name="photographer" class="filter-select" onchange="this.form.submit()">
            <option value="">Alla fotografer</option>
            <?php foreach ($photographers as $ph): ?>
            <option value="<?= $ph['id'] ?>" <?= $filterPhotographer == $ph['id'] ? 'selected' : '' ?>><?= htmlspecialchars($ph['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>
    <div class="filter-search-wrapper">
        <label class="filter-label">Sök</label>
        <input type="text" name="q" class="filter-input" placeholder="Event eller fotograf..." value="<?= htmlspecialchars($search) ?>">
    </div>
    <button type="submit" class="btn btn-primary filter-btn">
        <i data-lucide="search" style="width: 16px; height: 16px;"></i> Sök
    </button>
    <?php if ($filterYear || $filterLocation || $filterSeries || $filterPhotographer || $search): ?>
    <a href="/gallery" class="btn btn-ghost filter-btn">Rensa</a>
    <?php endif; ?>
</form>

<!-- Stats Cards -->
<div class="stats-grid gallery-stats-grid">
    <div class="stat-card">
        <span class="stat-value"><?= $totalAlbums ?></span>
        <span class="stat-label">Album</span>
    </div>
    <div class="stat-card">
        <span class="stat-value"><?= number_format($totalPhotos) ?></span>
        <span class="stat-label">Bilder</span>
    </div>
    <div class="stat-card">
        <span class="stat-value"><?= $totalTags ?></span>
        <span class="stat-label">Taggningar</span>
    </div>
</div>

<?php if (empty($albums)): ?>
<div class="card">
    <div style="padding: var(--space-2xl); text-align: center;">
        <i data-lucide="image-off" style="width: 48px; height: 48px; color: var(--color-text-muted); margin-bottom: var(--space-md);"></i>
        <p style="color: var(--color-text-muted); font-size: 1rem;">Inga gallerier hittades</p>
        <?php if ($filterYear || $filterLocation || $filterSeries || $filterPhotographer || $search): ?>
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

<?php
/**
 * TheHUB - Databas
 * Flikar: Åkare, Klubbar, Hall of Fame, Gallerier
 * Klientsidebaserat flikbyte (som event-sidan)
 */

if (!defined('HUB_ROOT')) {
    header('Location: /database');
    exit;
}

define('HUB_PAGE_TYPE', 'database');

$pdo = hub_db();
$activeTab = $_GET['tab'] ?? 'riders';
if (!in_array($activeTab, ['riders', 'clubs', 'halloffame', 'gallery'])) {
    $activeTab = 'riders';
}

// ── Stats ──
$publicSettings = require HUB_ROOT . '/config/public_settings.php';
$filter = $publicSettings['public_riders_display'] ?? 'all';

if ($filter === 'with_results') {
    $riderCount = $pdo->query("
        SELECT COUNT(DISTINCT r.id)
        FROM riders r
        INNER JOIN results res ON r.id = res.cyclist_id
        WHERE r.active = 1
    ")->fetchColumn();
} else {
    $riderCount = $pdo->query("SELECT COUNT(*) FROM riders WHERE active = 1")->fetchColumn();
}
$clubCount = $pdo->query("SELECT COUNT(*) FROM clubs WHERE active = 1")->fetchColumn();

// ── Tab 1: Åkare - Top 20 ranked ──
$topRiders = [];
try {
    $snapshotCheck = $pdo->query("SELECT COUNT(*) FROM ranking_snapshots WHERE discipline = 'GRAVITY'")->fetchColumn();
    if ($snapshotCheck > 0) {
        $topRiders = $pdo->query("
            SELECT
                rs.rider_id as id,
                r.firstname,
                r.lastname,
                c.name as club_name,
                rs.total_ranking_points as ranking_points,
                rs.events_count,
                rs.ranking_position
            FROM ranking_snapshots rs
            INNER JOIN riders r ON rs.rider_id = r.id
            LEFT JOIN clubs c ON r.club_id = c.id
            WHERE rs.discipline = 'GRAVITY'
              AND rs.snapshot_date = (SELECT MAX(snapshot_date) FROM ranking_snapshots WHERE discipline = 'GRAVITY')
            ORDER BY rs.ranking_position ASC
            LIMIT 20
        ")->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {}

if (empty($topRiders)) {
    $recentEventIds = $pdo->query("SELECT id FROM events WHERE date <= CURDATE() ORDER BY date DESC LIMIT 10")->fetchAll(PDO::FETCH_COLUMN);
    if (!empty($recentEventIds)) {
        $eventIdList = implode(',', array_map('intval', $recentEventIds));
        $topRiders = $pdo->query("
            SELECT r.id, r.firstname, r.lastname, c.name as club_name,
                   SUM(res.points) as ranking_points,
                   COUNT(DISTINCT res.event_id) as events_count,
                   0 as ranking_position
            FROM riders r
            LEFT JOIN clubs c ON r.club_id = c.id
            INNER JOIN results res ON r.id = res.cyclist_id
            LEFT JOIN classes cls ON res.class_id = cls.id
            WHERE r.active = 1 AND res.status = 'finished'
              AND res.event_id IN ({$eventIdList})
              AND COALESCE(cls.awards_points, 1) = 1
            GROUP BY r.id
            ORDER BY ranking_points DESC
            LIMIT 20
        ")->fetchAll(PDO::FETCH_ASSOC);
    }
}

// ── Tab 2: Klubbar - Top 20 ranked ──
$topClubs = [];
try {
    $clubSnapshotCheck = $pdo->query("SELECT COUNT(*) FROM club_ranking_snapshots WHERE discipline = 'GRAVITY'")->fetchColumn();
    if ($clubSnapshotCheck > 0) {
        $topClubs = $pdo->query("
            SELECT
                crs.club_id as id,
                c.name,
                c.city,
                crs.riders_count,
                crs.total_ranking_points as total_points,
                crs.ranking_position
            FROM club_ranking_snapshots crs
            INNER JOIN clubs c ON crs.club_id = c.id
            WHERE crs.discipline = 'GRAVITY'
              AND crs.snapshot_date = (SELECT MAX(snapshot_date) FROM club_ranking_snapshots WHERE discipline = 'GRAVITY')
            ORDER BY crs.ranking_position ASC
            LIMIT 20
        ")->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {}

if (empty($topClubs)) {
    try {
        $topClubs = $pdo->query("
            SELECT c.id, c.name, c.city,
                   COUNT(DISTINCT r.id) as riders_count,
                   SUM(res.points) as total_points,
                   0 as ranking_position
            FROM clubs c
            INNER JOIN riders r ON c.id = r.club_id AND r.active = 1
            INNER JOIN results res ON r.id = res.cyclist_id
            LEFT JOIN classes cls ON res.class_id = cls.id
            WHERE res.status = 'finished' AND COALESCE(cls.awards_points, 1) = 1
            GROUP BY c.id
            HAVING total_points > 0
            ORDER BY total_points DESC
            LIMIT 20
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}

// ── Tab 3: Hall of Fame - all three lists pre-rendered for client-side tab switching ──

// SM-titlar: count from achievements table (matches rider profile exactly)
$hofSm = $pdo->query("
    SELECT r.id, r.firstname, r.lastname, c.name as club_name,
           COUNT(*) as sm_titles,
           r.stats_total_wins as wins,
           r.stats_total_podiums as podiums
    FROM rider_achievements ra
    INNER JOIN riders r ON ra.rider_id = r.id
    LEFT JOIN clubs c ON r.club_id = c.id
    WHERE ra.achievement_type = 'swedish_champion'
    GROUP BY r.id
    ORDER BY sm_titles DESC, wins DESC
    LIMIT 20
")->fetchAll(PDO::FETCH_ASSOC);

// Segrar: position 1 in points-awarding classes
$hofWins = $pdo->query("
    SELECT r.id, r.firstname, r.lastname, c.name as club_name,
           COUNT(CASE WHEN e.is_championship = 1 THEN 1 END) as sm_titles,
           COUNT(*) as wins,
           r.stats_total_podiums as podiums
    FROM results res
    INNER JOIN riders r ON res.cyclist_id = r.id
    LEFT JOIN clubs c ON r.club_id = c.id
    INNER JOIN events e ON res.event_id = e.id
    LEFT JOIN classes cls ON res.class_id = cls.id
    WHERE res.position = 1
      AND res.status = 'finished'
      AND COALESCE(cls.awards_points, 1) = 1
    GROUP BY r.id
    ORDER BY wins DESC, sm_titles DESC
    LIMIT 20
")->fetchAll(PDO::FETCH_ASSOC);

// Pallplatser: position <= 3 in points-awarding classes
$hofPodiums = $pdo->query("
    SELECT r.id, r.firstname, r.lastname, c.name as club_name,
           COUNT(CASE WHEN res.position = 1 AND e.is_championship = 1 THEN 1 END) as sm_titles,
           COUNT(CASE WHEN res.position = 1 THEN 1 END) as wins,
           COUNT(*) as podiums
    FROM results res
    INNER JOIN riders r ON res.cyclist_id = r.id
    LEFT JOIN clubs c ON r.club_id = c.id
    INNER JOIN events e ON res.event_id = e.id
    LEFT JOIN classes cls ON res.class_id = cls.id
    WHERE res.position <= 3
      AND res.status = 'finished'
      AND COALESCE(cls.awards_points, 1) = 1
    GROUP BY r.id
    ORDER BY podiums DESC, wins DESC
    LIMIT 20
")->fetchAll(PDO::FETCH_ASSOC);

$hofLists = [
    'sm' => ['label' => 'SM-titlar', 'primary' => 'sm_titles', 'suffix' => ' SM', 'data' => $hofSm],
    'wins' => ['label' => 'Segrar', 'primary' => 'wins', 'suffix' => ' segrar', 'data' => $hofWins],
    'podiums' => ['label' => 'Pallplatser', 'primary' => 'podiums', 'suffix' => ' pall', 'data' => $hofPodiums],
];

// ── Tab 4: Gallerier (all data fetched once, filtered client-side) ──

$albums = $pdo->query("
    SELECT ea.id, ea.event_id, ea.title, ea.photographer, ea.photographer_id,
           ea.photo_count, ea.created_at,
           e.name as event_name, e.date as event_date, e.location as event_location,
           YEAR(e.date) as event_year,
           p.id as pg_id, p.name as photographer_name, p.avatar_url as photographer_avatar,
           cover.external_url as cover_url, cover.thumbnail_url as cover_thumb,
           cover_media.filepath as cover_filepath,
           GROUP_CONCAT(DISTINCT s.name ORDER BY s.name SEPARATOR ', ') as series_name,
           GROUP_CONCAT(DISTINCT sb.id) as brand_ids
    FROM event_albums ea
    JOIN events e ON ea.event_id = e.id
    LEFT JOIN photographers p ON ea.photographer_id = p.id
    LEFT JOIN series_events se ON se.event_id = e.id
    LEFT JOIN series s ON se.series_id = s.id
    LEFT JOIN series_brands sb ON s.brand_id = sb.id
    LEFT JOIN event_photos cover ON cover.id = ea.cover_photo_id
    LEFT JOIN media cover_media ON cover.media_id = cover_media.id
    WHERE ea.is_published = 1
    GROUP BY ea.id
    ORDER BY e.date DESC, ea.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Build filter options from the actual data
$galleryYears = [];
$galleryLocations = [];
$galleryBrands = [];
$galleryPhotographers = [];
foreach ($albums as &$album) {
    // Cover image fallback
    if (!$album['cover_url'] && !$album['cover_filepath']) {
        try {
            $firstPhoto = $pdo->prepare("SELECT external_url, thumbnail_url FROM event_photos WHERE album_id = ? ORDER BY id ASC LIMIT 1");
            $firstPhoto->execute([$album['id']]);
            $fp = $firstPhoto->fetch(PDO::FETCH_ASSOC);
            if ($fp) {
                $album['cover_url'] = $fp['external_url'];
                $album['cover_thumb'] = $fp['thumbnail_url'];
            }
        } catch (Exception $e) {}
    }

    // Collect unique filter values
    $yr = (int)$album['event_year'];
    if ($yr && !in_array($yr, $galleryYears)) $galleryYears[] = $yr;

    $loc = $album['event_location'] ?? '';
    if ($loc !== '' && !in_array($loc, $galleryLocations)) $galleryLocations[] = $loc;

    if ($album['pg_id'] && $album['photographer_name']) {
        $galleryPhotographers[$album['pg_id']] = $album['photographer_name'];
    }

    if ($album['brand_ids']) {
        foreach (explode(',', $album['brand_ids']) as $bid) {
            if (!isset($galleryBrands[$bid])) $galleryBrands[$bid] = null;
        }
    }
}
unset($album);

rsort($galleryYears);
sort($galleryLocations);
asort($galleryPhotographers);

// Fetch brand names for collected IDs
if (!empty($galleryBrands)) {
    $brandIds = implode(',', array_map('intval', array_keys($galleryBrands)));
    $brandRows = $pdo->query("SELECT id, name FROM series_brands WHERE id IN ({$brandIds}) ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    $galleryBrands = [];
    foreach ($brandRows as $b) $galleryBrands[$b['id']] = $b['name'];
} else {
    $galleryBrands = [];
}

$totalAlbums = count($albums);
$totalPhotos = $pdo->query("SELECT COALESCE(SUM(photo_count), 0) FROM event_albums WHERE is_published = 1")->fetchColumn();
?>

<div class="page-header">
    <h1 class="page-title">
        <i data-lucide="database" class="page-icon"></i>
        Databas
    </h1>
    <p class="page-subtitle">Åkare, klubbar, gallerier och Hall of Fame</p>
</div>

<?= render_global_sponsors('database', 'header_banner', '') ?>
<?= render_global_sponsors('database', 'content_top', '') ?>

<!-- Stats Cards -->
<div class="stats-grid">
    <div class="stat-card">
        <span class="stat-value"><?= number_format($riderCount) ?></span>
        <span class="stat-label">Åkare</span>
    </div>
    <div class="stat-card">
        <span class="stat-value"><?= number_format($clubCount) ?></span>
        <span class="stat-label">Klubbar</span>
    </div>
    <div class="stat-card">
        <span class="stat-value"><?= number_format($totalAlbums) ?></span>
        <span class="stat-label">Album</span>
    </div>
    <div class="stat-card">
        <span class="stat-value"><?= number_format($totalPhotos) ?></span>
        <span class="stat-label">Bilder</span>
    </div>
</div>

<!-- Tab Navigation -->
<div class="search-card">
    <div class="tabs-nav">
        <button class="tab-pill <?= $activeTab === 'riders' ? 'active' : '' ?>" data-db-tab="riders">Åkare</button>
        <button class="tab-pill <?= $activeTab === 'clubs' ? 'active' : '' ?>" data-db-tab="clubs">Klubbar</button>
        <button class="tab-pill <?= $activeTab === 'halloffame' ? 'active' : '' ?>" data-db-tab="halloffame">Hall of Fame</button>
        <button class="tab-pill <?= $activeTab === 'gallery' ? 'active' : '' ?>" data-db-tab="gallery">Gallerier</button>
    </div>
</div>

<!-- ═══════ TAB: ÅKARE ═══════ -->
<div class="db-tab-pane" id="db-tab-riders" style="<?= $activeTab !== 'riders' ? 'display:none' : '' ?>">
    <div class="search-card">
        <div class="search-box">
            <span class="search-icon"><i data-lucide="search"></i></span>
            <input type="text" id="search-riders" class="search-input" placeholder="Sök åkare..." autocomplete="off">
            <button type="button" class="search-clear hidden" data-for="search-riders"><i data-lucide="x"></i></button>
        </div>
        <div class="search-results" id="results-riders"></div>
    </div>

    <div class="card">
        <h2 class="card-title"><i data-lucide="trending-up"></i> Topp 20 rankade</h2>
        <div class="ranking-list">
            <?php foreach ($topRiders as $i => $rider): ?>
            <a href="/rider/<?= $rider['id'] ?>" class="ranking-item">
                <span class="ranking-pos <?= $i < 3 ? 'top-' . ($i + 1) : '' ?>">
                    <?php if ($i === 0): ?>
                        <img src="/assets/icons/medal-1st.svg" alt="1:a" class="medal-icon">
                    <?php elseif ($i === 1): ?>
                        <img src="/assets/icons/medal-2nd.svg" alt="2:a" class="medal-icon">
                    <?php elseif ($i === 2): ?>
                        <img src="/assets/icons/medal-3rd.svg" alt="3:e" class="medal-icon">
                    <?php else: ?>
                        <?= $i + 1 ?>
                    <?php endif; ?>
                </span>
                <div class="ranking-info">
                    <span class="ranking-name"><?= htmlspecialchars($rider['firstname'] . ' ' . $rider['lastname']) ?></span>
                    <span class="ranking-meta"><?= htmlspecialchars($rider['club_name'] ?? '-') ?></span>
                </div>
                <div class="ranking-stats">
                    <span class="stat"><?= $rider['events_count'] ?> event</span>
                    <span class="stat"><?= number_format($rider['ranking_points']) ?> p</span>
                </div>
            </a>
            <?php endforeach; ?>
            <?php if (empty($topRiders)): ?>
            <p style="padding: var(--space-lg); text-align: center; color: var(--color-text-muted);">Ingen rankingdata tillgänglig</p>
            <?php endif; ?>
        </div>
        <a href="/ranking" class="card-link">Visa fullständig ranking <i data-lucide="arrow-right" style="width:14px;height:14px;vertical-align:-2px;"></i></a>
    </div>
</div>

<!-- ═══════ TAB: KLUBBAR ═══════ -->
<div class="db-tab-pane" id="db-tab-clubs" style="<?= $activeTab !== 'clubs' ? 'display:none' : '' ?>">
    <div class="search-card">
        <div class="search-box">
            <span class="search-icon"><i data-lucide="search"></i></span>
            <input type="text" id="search-clubs" class="search-input" placeholder="Sök klubbar..." autocomplete="off">
            <button type="button" class="search-clear hidden" data-for="search-clubs"><i data-lucide="x"></i></button>
        </div>
        <div class="search-results" id="results-clubs"></div>
    </div>

    <div class="card">
        <h2 class="card-title"><i data-lucide="shield"></i> Topp 20 klubbar</h2>
        <div class="ranking-list">
            <?php foreach ($topClubs as $i => $club): ?>
            <a href="/club/<?= $club['id'] ?>" class="ranking-item">
                <span class="ranking-pos <?= $i < 3 ? 'top-' . ($i + 1) : '' ?>">
                    <?php if ($i === 0): ?>
                        <img src="/assets/icons/medal-1st.svg" alt="1:a" class="medal-icon">
                    <?php elseif ($i === 1): ?>
                        <img src="/assets/icons/medal-2nd.svg" alt="2:a" class="medal-icon">
                    <?php elseif ($i === 2): ?>
                        <img src="/assets/icons/medal-3rd.svg" alt="3:e" class="medal-icon">
                    <?php else: ?>
                        <?= $i + 1 ?>
                    <?php endif; ?>
                </span>
                <div class="ranking-info">
                    <span class="ranking-name"><?= htmlspecialchars($club['name']) ?></span>
                    <span class="ranking-meta"><?= htmlspecialchars($club['city'] ?? '') ?> · <?= $club['riders_count'] ?> åkare</span>
                </div>
                <div class="ranking-stats">
                    <span class="stat"><?= number_format($club['total_points']) ?> poäng</span>
                </div>
            </a>
            <?php endforeach; ?>
            <?php if (empty($topClubs)): ?>
            <p style="padding: var(--space-lg); text-align: center; color: var(--color-text-muted);">Ingen rankingdata tillgänglig</p>
            <?php endif; ?>
        </div>
        <a href="/ranking?view=clubs" class="card-link">Visa fullständig klubbranking <i data-lucide="arrow-right" style="width:14px;height:14px;vertical-align:-2px;"></i></a>
    </div>
</div>

<!-- ═══════ TAB: HALL OF FAME ═══════ -->
<div class="db-tab-pane" id="db-tab-halloffame" style="<?= $activeTab !== 'halloffame' ? 'display:none' : '' ?>">
    <div class="search-card">
        <div class="tabs-nav">
            <button class="tab-pill active" data-hof-tab="sm">SM-titlar</button>
            <button class="tab-pill" data-hof-tab="wins">Segrar</button>
            <button class="tab-pill" data-hof-tab="podiums">Pallplatser</button>
        </div>
    </div>

    <?php foreach ($hofLists as $key => $hof): ?>
    <div class="card hof-pane" id="hof-<?= $key ?>" style="<?= $key !== 'sm' ? 'display:none' : '' ?>">
        <h2 class="card-title">Topp 20 – <?= $hof['label'] ?></h2>
        <div class="ranking-list">
            <?php foreach ($hof['data'] as $i => $rider): ?>
            <a href="/rider/<?= $rider['id'] ?>" class="ranking-item">
                <span class="ranking-pos <?= $i < 3 ? 'top-' . ($i + 1) : '' ?>">
                    <?php if ($i === 0): ?>
                        <img src="/assets/icons/medal-1st.svg" alt="1:a" class="medal-icon">
                    <?php elseif ($i === 1): ?>
                        <img src="/assets/icons/medal-2nd.svg" alt="2:a" class="medal-icon">
                    <?php elseif ($i === 2): ?>
                        <img src="/assets/icons/medal-3rd.svg" alt="3:e" class="medal-icon">
                    <?php else: ?>
                        <?= $i + 1 ?>
                    <?php endif; ?>
                </span>
                <div class="ranking-info">
                    <span class="ranking-name"><?= htmlspecialchars($rider['firstname'] . ' ' . $rider['lastname']) ?></span>
                    <span class="ranking-meta"><?= htmlspecialchars($rider['club_name'] ?? '-') ?></span>
                </div>
                <div class="ranking-stats">
                    <?php if ($rider['sm_titles'] > 0): ?>
                    <span class="stat gold"><?= $rider['sm_titles'] ?> SM</span>
                    <?php endif; ?>
                    <span class="stat"><?= $rider['wins'] ?> segrar</span>
                    <span class="stat"><?= $rider['podiums'] ?> pall</span>
                </div>
            </a>
            <?php endforeach; ?>
            <?php if (empty($hof['data'])): ?>
            <p style="padding: var(--space-lg); text-align: center; color: var(--color-text-muted);">Ingen data tillgänglig</p>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- ═══════ TAB: GALLERIER ═══════ -->
<div class="db-tab-pane" id="db-tab-gallery" style="<?= $activeTab !== 'gallery' ? 'display:none' : '' ?>">
    <div class="search-card">
        <div class="gallery-filters">
            <div class="gallery-filters-grid">
                <div class="filter-select-wrapper">
                    <label class="filter-label">År</label>
                    <select id="gf-year" class="filter-select">
                        <option value="">Alla år</option>
                        <?php foreach ($galleryYears as $yr): ?>
                        <option value="<?= $yr ?>"><?= $yr ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if (!empty($galleryLocations)): ?>
                <div class="filter-select-wrapper">
                    <label class="filter-label">Destination</label>
                    <select id="gf-location" class="filter-select">
                        <option value="">Alla destinationer</option>
                        <?php foreach ($galleryLocations as $loc): ?>
                        <option value="<?= htmlspecialchars($loc) ?>"><?= htmlspecialchars($loc) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <?php if (!empty($galleryBrands)): ?>
                <div class="filter-select-wrapper">
                    <label class="filter-label">Serie</label>
                    <select id="gf-brand" class="filter-select">
                        <option value="">Alla serier</option>
                        <?php foreach ($galleryBrands as $bid => $bname): ?>
                        <option value="<?= $bid ?>"><?= htmlspecialchars($bname) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <?php if (!empty($galleryPhotographers)): ?>
                <div class="filter-select-wrapper">
                    <label class="filter-label">Fotograf</label>
                    <select id="gf-photographer" class="filter-select">
                        <option value="">Alla fotografer</option>
                        <?php foreach ($galleryPhotographers as $pid => $pname): ?>
                        <option value="<?= $pid ?>"><?= htmlspecialchars($pname) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
            </div>
            <div id="gf-reset" style="margin-top: var(--space-sm); display:none;">
                <button type="button" class="btn btn-ghost" style="font-size: var(--text-sm);" onclick="resetGalleryFilters()">Rensa filter</button>
            </div>
        </div>
    </div>

    <div id="gallery-empty" class="card" style="text-align:center; padding: var(--space-2xl); display:none;">
        <p style="color:var(--color-text-muted);">Inga gallerier matchar filtret</p>
    </div>
    <div class="gallery-listing-grid" id="gallery-grid">
        <?php foreach ($albums as $album):
            $coverSrc = $album['cover_thumb'] ?: ($album['cover_url'] ?: ($album['cover_filepath'] ? '/' . ltrim($album['cover_filepath'], '/') : ''));
            $photographerName = $album['photographer_name'] ?: ($album['photographer'] ?? '');
            $eventDate = $album['event_date'] ? date('j M Y', strtotime($album['event_date'])) : '';
        ?>
        <a href="/event/<?= $album['event_id'] ?>?tab=galleri" class="gallery-listing-card"
           data-year="<?= $album['event_year'] ?>"
           data-location="<?= htmlspecialchars($album['event_location'] ?? '') ?>"
           data-brands="<?= htmlspecialchars($album['brand_ids'] ?? '') ?>"
           data-photographer="<?= $album['pg_id'] ?? '' ?>">
            <div class="gallery-listing-cover">
                <?php if ($coverSrc): ?>
                <img src="<?= htmlspecialchars($coverSrc) ?>" alt="<?= htmlspecialchars($album['title'] ?: $album['event_name']) ?>" loading="lazy">
                <?php else: ?>
                <div class="gallery-listing-placeholder">
                    <i data-lucide="image" style="width:48px;height:48px;color:var(--color-text-muted);"></i>
                </div>
                <?php endif; ?>
                <div class="gallery-listing-photo-count">
                    <i data-lucide="image" style="width:14px;height:14px;"></i>
                    <?= $album['photo_count'] ?>
                </div>
            </div>
            <div class="gallery-listing-info">
                <h3 class="gallery-listing-title"><?= htmlspecialchars($album['title'] ?: $album['event_name']) ?></h3>
                <?php if (!empty($album['series_name'])): ?>
                <div class="gallery-listing-series"><?= htmlspecialchars($album['series_name']) ?></div>
                <?php endif; ?>
                <div class="gallery-listing-meta">
                    <?php if ($eventDate): ?><span><?= $eventDate ?></span><?php endif; ?>
                    <?php if ($album['event_location']): ?><span><?= htmlspecialchars($album['event_location']) ?></span><?php endif; ?>
                </div>
                <?php if ($photographerName): ?>
                <div class="gallery-listing-photographer">
                    <i data-lucide="camera" style="width:13px;height:13px;"></i>
                    <?= htmlspecialchars($photographerName) ?>
                </div>
                <?php endif; ?>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
</div>

<?= render_global_sponsors('database', 'content_bottom', 'Tack till våra partners') ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // ── Tab switching (client-side, like event page) ──
    const tabBtns = document.querySelectorAll('[data-db-tab]');
    const tabPanes = document.querySelectorAll('.db-tab-pane');

    function switchTab(tabId) {
        tabBtns.forEach(b => b.classList.toggle('active', b.dataset.dbTab === tabId));
        tabPanes.forEach(p => p.style.display = p.id === 'db-tab-' + tabId ? '' : 'none');
        history.replaceState(null, '', '/database?tab=' + tabId);
        if (typeof lucide !== 'undefined') lucide.createIcons();
    }

    tabBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            switchTab(this.dataset.dbTab);
        });
    });

    // ── Hall of Fame sub-tabs (client-side) ──
    document.querySelectorAll('[data-hof-tab]').forEach(btn => {
        btn.addEventListener('click', function() {
            const key = this.dataset.hofTab;
            document.querySelectorAll('[data-hof-tab]').forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            document.querySelectorAll('.hof-pane').forEach(p => p.style.display = 'none');
            const pane = document.getElementById('hof-' + key);
            if (pane) pane.style.display = '';
        });
    });

    // ── Gallery client-side filtering with cascading options ──
    (function() {
        const grid = document.getElementById('gallery-grid');
        const empty = document.getElementById('gallery-empty');
        const reset = document.getElementById('gf-reset');
        if (!grid) return;

        const cards = Array.from(grid.querySelectorAll('.gallery-listing-card'));
        const selYear = document.getElementById('gf-year');
        const selLoc = document.getElementById('gf-location');
        const selBrand = document.getElementById('gf-brand');
        const selPhoto = document.getElementById('gf-photographer');
        const selects = [selYear, selLoc, selBrand, selPhoto].filter(Boolean);

        function filterGallery() {
            const fy = selYear ? selYear.value : '';
            const fl = selLoc ? selLoc.value : '';
            const fb = selBrand ? selBrand.value : '';
            const fp = selPhoto ? selPhoto.value : '';
            let visible = 0;

            // First pass: determine which cards match
            cards.forEach(c => {
                const match =
                    (!fy || c.dataset.year === fy) &&
                    (!fl || c.dataset.location === fl) &&
                    (!fb || (c.dataset.brands || '').split(',').includes(fb)) &&
                    (!fp || c.dataset.photographer === fp);
                c.style.display = match ? '' : 'none';
                if (match) visible++;
            });

            empty.style.display = visible === 0 ? '' : 'none';
            grid.style.display = visible === 0 ? 'none' : '';
            reset.style.display = (fy || fl || fb || fp) ? '' : 'none';

            // Second pass: cascade filter options to only show values present in visible cards
            const visibleCards = cards.filter(c => c.style.display !== 'none');
            updateOptions(selYear, 'year', visibleCards, fy, [selLoc, selBrand, selPhoto]);
            updateOptions(selLoc, 'location', visibleCards, fl, [selYear, selBrand, selPhoto]);
            updateOptions(selBrand, 'brands', visibleCards, fb, [selYear, selLoc, selPhoto]);
            updateOptions(selPhoto, 'photographer', visibleCards, fp, [selYear, selLoc, selBrand]);
        }

        function updateOptions(sel, attr, visCards, currentVal, otherSels) {
            if (!sel) return;
            // Collect values present in cards that match ALL OTHER filters (not this one)
            const available = new Set();
            cards.forEach(c => {
                // Check if card matches all filters EXCEPT this one
                const matchOthers = otherSels.every(os => {
                    if (!os || !os.value) return true;
                    if (os === selYear) return !os.value || c.dataset.year === os.value;
                    if (os === selLoc) return !os.value || c.dataset.location === os.value;
                    if (os === selBrand) return !os.value || (c.dataset.brands || '').split(',').includes(os.value);
                    if (os === selPhoto) return !os.value || c.dataset.photographer === os.value;
                    return true;
                });
                if (!matchOthers) return;

                if (attr === 'brands') {
                    (c.dataset.brands || '').split(',').filter(Boolean).forEach(v => available.add(v));
                } else {
                    const v = c.dataset[attr];
                    if (v) available.add(v);
                }
            });

            // Hide options not present in filtered results
            Array.from(sel.options).forEach(opt => {
                if (!opt.value) return;
                opt.style.display = available.has(opt.value) ? '' : 'none';
            });
        }

        selects.forEach(s => s.addEventListener('change', filterGallery));
        window.resetGalleryFilters = function() {
            selects.forEach(s => { s.value = ''; });
            filterGallery();
        };
    })();

    // ── Search functionality ──
    function initSearch(inputId, resultsId, type) {
        const input = document.getElementById(inputId);
        const results = document.getElementById(resultsId);
        const clearBtn = document.querySelector('.search-clear[data-for="' + inputId + '"]');
        if (!input || !results) return;

        let timeout;

        input.addEventListener('input', function() {
            const q = this.value.trim();
            if (clearBtn) clearBtn.style.display = q ? 'block' : 'none';
            clearTimeout(timeout);

            if (q.length < 2) {
                results.innerHTML = '';
                return;
            }

            timeout = setTimeout(() => {
                fetch('/api/search.php?type=' + type + '&q=' + encodeURIComponent(q))
                    .then(r => r.json())
                    .then(data => {
                        if (data.results && data.results.length > 0) {
                            results.innerHTML = data.results.map(item => {
                                if (type === 'riders') {
                                    return '<a href="/rider/' + item.id + '" class="search-result">' +
                                        '<span class="search-result-avatar">' + ((item.firstname || '?')[0]).toUpperCase() + '</span>' +
                                        '<div class="search-result-info">' +
                                        '<span class="search-result-name">' + (item.firstname || '') + ' ' + (item.lastname || '') + '</span>' +
                                        '<span class="search-result-meta">' + (item.club_name || '-') + '</span>' +
                                        '</div></a>';
                                } else {
                                    return '<a href="/club/' + item.id + '" class="search-result">' +
                                        '<span class="search-result-avatar"><i data-lucide="shield"></i></span>' +
                                        '<div class="search-result-info">' +
                                        '<span class="search-result-name">' + (item.name || '') + '</span>' +
                                        '<span class="search-result-meta">' + (item.member_count || 0) + ' medlemmar</span>' +
                                        '</div></a>';
                                }
                            }).join('');
                            if (typeof lucide !== 'undefined') lucide.createIcons();
                        } else {
                            results.innerHTML = '<div class="search-hint">Inga resultat hittades</div>';
                        }
                    })
                    .catch(() => {
                        results.innerHTML = '<div class="search-hint">Fel vid sökning</div>';
                    });
            }, 300);
        });

        if (clearBtn) {
            clearBtn.addEventListener('click', function() {
                input.value = '';
                results.innerHTML = '';
                this.style.display = 'none';
                input.focus();
            });
        }
    }

    initSearch('search-riders', 'results-riders', 'riders');
    initSearch('search-clubs', 'results-clubs', 'clubs');
});
</script>

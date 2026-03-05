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

// ── Tab 3: Hall of Fame - top 20 by SM titles / wins / podiums ──
$hofSort = $_GET['hof'] ?? 'sm';
if (!in_array($hofSort, ['sm', 'wins', 'podiums'])) $hofSort = 'sm';

$hofRiders = [];

// Check if is_championship_class column exists
$hasChampionshipClass = false;
try {
    $cols = $pdo->query("SHOW COLUMNS FROM classes LIKE 'is_championship_class'")->fetchAll();
    $hasChampionshipClass = !empty($cols);
} catch (Exception $e) {}

if ($hofSort === 'sm') {
    // SM-titlar: vunnit i championship event + championship class
    $champClassFilter = $hasChampionshipClass ? "AND COALESCE(cls.is_championship_class, 0) = 1" : "";
    $hofRiders = $pdo->query("
        SELECT r.id, r.firstname, r.lastname, c.name as club_name,
               COUNT(*) as sm_titles,
               r.stats_total_wins as wins,
               r.stats_total_podiums as podiums
        FROM results res
        INNER JOIN riders r ON res.cyclist_id = r.id
        LEFT JOIN clubs c ON r.club_id = c.id
        INNER JOIN events e ON res.event_id = e.id
        LEFT JOIN classes cls ON res.class_id = cls.id
        WHERE res.position = 1
          AND res.status = 'finished'
          AND e.is_championship = 1
          {$champClassFilter}
        GROUP BY r.id
        ORDER BY sm_titles DESC, wins DESC
        LIMIT 20
    ")->fetchAll(PDO::FETCH_ASSOC);
} elseif ($hofSort === 'wins') {
    $hofRiders = $pdo->query("
        SELECT r.id, r.firstname, r.lastname, c.name as club_name,
               COUNT(CASE WHEN e.is_championship = 1 " . ($hasChampionshipClass ? "AND COALESCE(cls.is_championship_class, 0) = 1" : "") . " THEN 1 END) as sm_titles,
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
} else {
    // podiums
    $hofRiders = $pdo->query("
        SELECT r.id, r.firstname, r.lastname, c.name as club_name,
               COUNT(CASE WHEN res.position = 1 AND e.is_championship = 1 " . ($hasChampionshipClass ? "AND COALESCE(cls.is_championship_class, 0) = 1" : "") . " THEN 1 END) as sm_titles,
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
}

// ── Tab 4: Gallerier ──
$galleryFilterYear = isset($_GET['gy']) && is_numeric($_GET['gy']) ? intval($_GET['gy']) : null;
$galleryFilterLocation = isset($_GET['gl']) ? trim($_GET['gl']) : '';

$galleryYears = $pdo->query("
    SELECT DISTINCT YEAR(e.date) as yr
    FROM event_albums ea JOIN events e ON ea.event_id = e.id
    WHERE ea.is_published = 1
    ORDER BY yr DESC
")->fetchAll(PDO::FETCH_COLUMN);

$galleryLocations = $pdo->query("
    SELECT DISTINCT e.location
    FROM event_albums ea JOIN events e ON ea.event_id = e.id
    WHERE ea.is_published = 1 AND e.location IS NOT NULL AND e.location != ''
    ORDER BY e.location ASC
")->fetchAll(PDO::FETCH_COLUMN);

$gWhere = ["ea.is_published = 1"];
$gParams = [];
if ($galleryFilterYear) {
    $gWhere[] = "YEAR(e.date) = ?";
    $gParams[] = $galleryFilterYear;
}
if ($galleryFilterLocation) {
    $gWhere[] = "e.location = ?";
    $gParams[] = $galleryFilterLocation;
}
$gWhereClause = implode(' AND ', $gWhere);

$gStmt = $pdo->prepare("
    SELECT ea.id, ea.event_id, ea.title, ea.photographer, ea.photographer_id,
           ea.photo_count, ea.created_at,
           e.name as event_name, e.date as event_date, e.location as event_location,
           p.name as photographer_name, p.avatar_url as photographer_avatar,
           cover.external_url as cover_url, cover.thumbnail_url as cover_thumb,
           cover_media.filepath as cover_filepath,
           GROUP_CONCAT(DISTINCT s2.name ORDER BY s2.name SEPARATOR ', ') as series_name
    FROM event_albums ea
    JOIN events e ON ea.event_id = e.id
    LEFT JOIN photographers p ON ea.photographer_id = p.id
    LEFT JOIN series_events se2 ON se2.event_id = e.id
    LEFT JOIN series s2 ON se2.series_id = s2.id
    LEFT JOIN event_photos cover ON cover.id = ea.cover_photo_id
    LEFT JOIN media cover_media ON cover.media_id = cover_media.id
    WHERE {$gWhereClause}
    GROUP BY ea.id
    ORDER BY e.date DESC, ea.created_at DESC
");
$gStmt->execute($gParams);
$albums = $gStmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($albums as &$album) {
    if (!$album['cover_url'] && !$album['cover_filepath']) {
        $fp = $pdo->prepare("
            SELECT ep.external_url, ep.thumbnail_url, m.filepath
            FROM event_photos ep LEFT JOIN media m ON ep.media_id = m.id
            WHERE ep.album_id = ? ORDER BY ep.sort_order ASC, ep.id ASC LIMIT 1
        ");
        $fp->execute([$album['id']]);
        $row = $fp->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $album['cover_url'] = $row['external_url'] ?: '';
            $album['cover_thumb'] = $row['thumbnail_url'] ?: '';
            $album['cover_filepath'] = $row['filepath'] ?: '';
        }
    }
}
unset($album);

$totalAlbums = $pdo->query("SELECT COUNT(*) FROM event_albums WHERE is_published = 1")->fetchColumn();
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
        <button class="tab-pill <?= $activeTab === 'riders' ? 'active' : '' ?>" data-db-tab="riders">
            <i data-lucide="users"></i> Åkare
        </button>
        <button class="tab-pill <?= $activeTab === 'clubs' ? 'active' : '' ?>" data-db-tab="clubs">
            <i data-lucide="shield"></i> Klubbar
        </button>
        <button class="tab-pill <?= $activeTab === 'halloffame' ? 'active' : '' ?>" data-db-tab="halloffame">
            <i data-lucide="trophy"></i> Hall of Fame
        </button>
        <button class="tab-pill <?= $activeTab === 'gallery' ? 'active' : '' ?>" data-db-tab="gallery">
            <i data-lucide="camera"></i> Gallerier
        </button>
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
        <div class="hof-sort-bar">
            <span class="hof-sort-label">Sortera efter:</span>
            <button class="tab-pill <?= $hofSort === 'sm' ? 'active' : '' ?>" data-hof-sort="sm">
                <i data-lucide="award"></i> SM-titlar
            </button>
            <button class="tab-pill <?= $hofSort === 'wins' ? 'active' : '' ?>" data-hof-sort="wins">
                <i data-lucide="trophy"></i> Segrar
            </button>
            <button class="tab-pill <?= $hofSort === 'podiums' ? 'active' : '' ?>" data-hof-sort="podiums">
                <i data-lucide="medal"></i> Pallplatser
            </button>
        </div>
    </div>

    <div class="card">
        <h2 class="card-title">
            <i data-lucide="<?= $hofSort === 'sm' ? 'award' : ($hofSort === 'wins' ? 'trophy' : 'medal') ?>"></i>
            Topp 20 – <?= $hofSort === 'sm' ? 'SM-titlar' : ($hofSort === 'wins' ? 'Segrar' : 'Pallplatser') ?>
        </h2>
        <div class="ranking-list">
            <?php foreach ($hofRiders as $i => $rider): ?>
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
            <?php if (empty($hofRiders)): ?>
            <p style="padding: var(--space-lg); text-align: center; color: var(--color-text-muted);">Ingen data tillgänglig</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ═══════ TAB: GALLERIER ═══════ -->
<div class="db-tab-pane" id="db-tab-gallery" style="<?= $activeTab !== 'gallery' ? 'display:none' : '' ?>">
    <div class="search-card">
        <form method="GET" action="/database" class="gallery-filters" id="gallery-filter-form">
            <input type="hidden" name="tab" value="gallery">
            <div class="gallery-filters-grid">
                <div class="filter-select-wrapper">
                    <label class="filter-label">År</label>
                    <select name="gy" class="filter-select" onchange="this.form.submit()">
                        <option value="">Alla år</option>
                        <?php foreach ($galleryYears as $yr): ?>
                        <option value="<?= $yr ?>" <?= $galleryFilterYear == $yr ? 'selected' : '' ?>><?= $yr ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if (!empty($galleryLocations)): ?>
                <div class="filter-select-wrapper">
                    <label class="filter-label">Destination</label>
                    <select name="gl" class="filter-select" onchange="this.form.submit()">
                        <option value="">Alla destinationer</option>
                        <?php foreach ($galleryLocations as $loc): ?>
                        <option value="<?= htmlspecialchars($loc) ?>" <?= $galleryFilterLocation === $loc ? 'selected' : '' ?>><?= htmlspecialchars($loc) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
            </div>
            <?php if ($galleryFilterYear || $galleryFilterLocation): ?>
            <div style="margin-top: var(--space-sm);">
                <a href="/database?tab=gallery" class="btn btn-ghost" style="font-size: var(--text-sm);">Rensa filter</a>
            </div>
            <?php endif; ?>
        </form>
    </div>

    <?php if (empty($albums)): ?>
    <div class="card" style="text-align:center; padding: var(--space-2xl);">
        <i data-lucide="image-off" style="width:48px;height:48px;color:var(--color-text-muted);margin-bottom:var(--space-md);"></i>
        <p style="color:var(--color-text-muted);">Inga gallerier hittades</p>
    </div>
    <?php else: ?>
    <div class="gallery-listing-grid">
        <?php foreach ($albums as $album):
            $coverSrc = $album['cover_thumb'] ?: ($album['cover_url'] ?: ($album['cover_filepath'] ? '/' . ltrim($album['cover_filepath'], '/') : ''));
            $photographerName = $album['photographer_name'] ?: $album['photographer'];
            $eventDate = $album['event_date'] ? date('j M Y', strtotime($album['event_date'])) : '';
        ?>
        <a href="/event/<?= $album['event_id'] ?>?tab=galleri" class="gallery-listing-card">
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
    <?php endif; ?>
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

    // ── Hall of Fame sort buttons ──
    document.querySelectorAll('[data-hof-sort]').forEach(btn => {
        btn.addEventListener('click', function() {
            window.location = '/database?tab=halloffame&hof=' + this.dataset.hofSort;
        });
    });

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

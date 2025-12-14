<?php
/**
 * TheHUB V3.5 - Databas
 * Sök åkare och klubbar - inspirerad av V2 riders.php och clubs/leaderboard.php
 */

// Prevent direct access
if (!defined('HUB_V3_ROOT')) {
    header('Location: /database');
    exit;
}

$pdo = hub_db();
$tab = $_GET['tab'] ?? 'riders';

// Load filter setting from admin configuration
$publicSettings = require HUB_V3_ROOT . '/config/public_settings.php';
$filter = $publicSettings['public_riders_display'] ?? 'all';

// Get stats based on filter from admin settings
if ($filter === 'with_results') {
    $riderCount = $pdo->query("
        SELECT COUNT(DISTINCT r.id)
        FROM riders r
        INNER JOIN results res ON r.id = res.cyclist_id
        WHERE r.active = 1
    ")->fetchColumn();
    $clubCount = $pdo->query("
        SELECT COUNT(DISTINCT c.id)
        FROM clubs c
        INNER JOIN riders r ON c.id = r.club_id
        INNER JOIN results res ON r.id = res.cyclist_id
    ")->fetchColumn();
} else {
    $riderCount = $pdo->query("SELECT COUNT(*) FROM riders WHERE active = 1")->fetchColumn();
    $clubCount = $pdo->query("SELECT COUNT(*) FROM clubs")->fetchColumn();
}

// Count unique riders with wins and podiums (exclude motion classes by name pattern)
$ridersWithWins = $pdo->query("
    SELECT COUNT(DISTINCT res.cyclist_id)
    FROM results res
    INNER JOIN classes cls ON res.class_id = cls.id
    WHERE res.position = 1
      AND res.status = 'finished'
      AND COALESCE(cls.awards_points, 1) = 1
      AND LOWER(COALESCE(cls.display_name, cls.name, '')) NOT LIKE '%motion%'
      AND LOWER(COALESCE(cls.display_name, cls.name, '')) NOT LIKE '%sport%'
")->fetchColumn();

$ridersWithPodiums = $pdo->query("
    SELECT COUNT(DISTINCT res.cyclist_id)
    FROM results res
    INNER JOIN classes cls ON res.class_id = cls.id
    WHERE res.position <= 3
      AND res.status = 'finished'
      AND COALESCE(cls.awards_points, 1) = 1
      AND LOWER(COALESCE(cls.display_name, cls.name, '')) NOT LIKE '%motion%'
      AND LOWER(COALESCE(cls.display_name, cls.name, '')) NOT LIKE '%sport%'
")->fetchColumn();

// Get top riders from ranking snapshots or calculate from recent events
// Only count competitive classes (awards_points = 1), exclude motion classes
$topRiders = [];

// Motion class name patterns to exclude (backup filter until migration runs)
$motionClassFilter = "
    AND LOWER(COALESCE(cls.display_name, cls.name, '')) NOT LIKE '%motion%'
    AND LOWER(COALESCE(cls.display_name, cls.name, '')) NOT LIKE '%sport%'
";

// Try to get from ranking snapshots first
try {
    $snapshotCheck = $pdo->query("SELECT COUNT(*) FROM ranking_snapshots WHERE discipline = 'GRAVITY'")->fetchColumn();
    if ($snapshotCheck > 0) {
        // Use ranking snapshots - but filter out riders who only have motion class results
        $topRiders = $pdo->query("
            SELECT
                rs.rider_id as id,
                r.firstname,
                r.lastname,
                c.name as club_name,
                rs.total_ranking_points as ranking_score,
                rs.events_count as total_races,
                (SELECT COUNT(*) FROM results res2
                 INNER JOIN classes cls ON res2.class_id = cls.id
                 WHERE res2.cyclist_id = rs.rider_id AND res2.position = 1
                   AND res2.status = 'finished'
                   AND COALESCE(cls.awards_points, 1) = 1
                   AND LOWER(COALESCE(cls.display_name, cls.name, '')) NOT LIKE '%motion%'
                   AND LOWER(COALESCE(cls.display_name, cls.name, '')) NOT LIKE '%sport%') as wins,
                (SELECT COUNT(*) FROM results res2
                 INNER JOIN classes cls ON res2.class_id = cls.id
                 WHERE res2.cyclist_id = rs.rider_id AND res2.position <= 3
                   AND res2.status = 'finished'
                   AND COALESCE(cls.awards_points, 1) = 1
                   AND LOWER(COALESCE(cls.display_name, cls.name, '')) NOT LIKE '%motion%'
                   AND LOWER(COALESCE(cls.display_name, cls.name, '')) NOT LIKE '%sport%') as podiums
            FROM ranking_snapshots rs
            INNER JOIN riders r ON rs.rider_id = r.id
            LEFT JOIN clubs c ON r.club_id = c.id
            WHERE rs.discipline = 'GRAVITY'
              AND rs.snapshot_date = (SELECT MAX(snapshot_date) FROM ranking_snapshots WHERE discipline = 'GRAVITY')
              AND EXISTS (
                  SELECT 1 FROM results res3
                  INNER JOIN classes cls3 ON res3.class_id = cls3.id
                  WHERE res3.cyclist_id = rs.rider_id
                    AND res3.status = 'finished'
                    AND COALESCE(cls3.awards_points, 1) = 1
                    AND LOWER(COALESCE(cls3.display_name, cls3.name, '')) NOT LIKE '%motion%'
                    AND LOWER(COALESCE(cls3.display_name, cls3.name, '')) NOT LIKE '%sport%'
              )
            ORDER BY rs.ranking_position ASC
            LIMIT 10
        ")->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    // Table doesn't exist, fallback below
}

// Fallback: Calculate from 10 most recent events with competitive classes
if (empty($topRiders)) {
    // Get 10 most recent events
    $recentEventIds = $pdo->query("
        SELECT id FROM events WHERE date <= CURDATE() ORDER BY date DESC LIMIT 10
    ")->fetchAll(PDO::FETCH_COLUMN);

    if (!empty($recentEventIds)) {
        $eventIdList = implode(',', array_map('intval', $recentEventIds));

        $topRiders = $pdo->query("
            SELECT
                r.id,
                r.firstname,
                r.lastname,
                c.name as club_name,
                SUM(res.points) as ranking_score,
                COUNT(DISTINCT res.id) as total_races,
                COUNT(CASE WHEN res.position = 1
                    AND COALESCE(cls.awards_points, 1) = 1
                    AND LOWER(COALESCE(cls.display_name, cls.name, '')) NOT LIKE '%motion%'
                    AND LOWER(COALESCE(cls.display_name, cls.name, '')) NOT LIKE '%sport%'
                    THEN 1 END) as wins,
                COUNT(CASE WHEN res.position <= 3
                    AND COALESCE(cls.awards_points, 1) = 1
                    AND LOWER(COALESCE(cls.display_name, cls.name, '')) NOT LIKE '%motion%'
                    AND LOWER(COALESCE(cls.display_name, cls.name, '')) NOT LIKE '%sport%'
                    THEN 1 END) as podiums
            FROM riders r
            LEFT JOIN clubs c ON r.club_id = c.id
            INNER JOIN results res ON r.id = res.cyclist_id
            LEFT JOIN classes cls ON res.class_id = cls.id
            WHERE r.active = 1
              AND res.status = 'finished'
              AND res.event_id IN ({$eventIdList})
              AND COALESCE(cls.awards_points, 1) = 1
              AND LOWER(COALESCE(cls.display_name, cls.name, '')) NOT LIKE '%motion%'
              AND LOWER(COALESCE(cls.display_name, cls.name, '')) NOT LIKE '%sport%'
            GROUP BY r.id
            HAVING total_races >= 1
            ORDER BY ranking_score DESC
            LIMIT 10
        ")->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Get club rankings from snapshots or fallback
$clubRankings = [];

// Try to get from club ranking snapshots first
try {
    $clubSnapshotCheck = $pdo->query("SELECT COUNT(*) FROM club_ranking_snapshots WHERE discipline = 'GRAVITY'")->fetchColumn();
    if ($clubSnapshotCheck > 0) {
        $clubRankings = $pdo->query("
            SELECT
                crs.club_id as id,
                c.name,
                c.city,
                crs.riders_count as riders_with_points,
                crs.total_ranking_points as total_points,
                (SELECT COUNT(*) FROM results res2
                 INNER JOIN riders r2 ON res2.cyclist_id = r2.id
                 INNER JOIN classes cls ON res2.class_id = cls.id
                 WHERE r2.club_id = crs.club_id AND res2.position <= 3
                   AND res2.status = 'finished'
                   AND COALESCE(cls.awards_points, 1) = 1
                   AND LOWER(COALESCE(cls.display_name, cls.name, '')) NOT LIKE '%motion%'
                   AND LOWER(COALESCE(cls.display_name, cls.name, '')) NOT LIKE '%sport%') as podiums
            FROM club_ranking_snapshots crs
            INNER JOIN clubs c ON crs.club_id = c.id
            WHERE crs.discipline = 'GRAVITY'
              AND crs.snapshot_date = (SELECT MAX(snapshot_date) FROM club_ranking_snapshots WHERE discipline = 'GRAVITY')
            ORDER BY crs.ranking_position ASC
            LIMIT 10
        ")->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    // Table doesn't exist
}

// Fallback: Calculate clubs with competitive results only
if (empty($clubRankings)) {
    try {
        $clubRankings = $pdo->query("
            SELECT c.id, c.name, c.city,
                   COUNT(DISTINCT CASE WHEN res.points > 0 THEN r.id END) as riders_with_points,
                   COUNT(DISTINCT res.id) as total_starts,
                   COUNT(CASE WHEN res.position <= 3
                       AND COALESCE(cls.awards_points, 1) = 1
                       AND LOWER(COALESCE(cls.display_name, cls.name, '')) NOT LIKE '%motion%'
                       AND LOWER(COALESCE(cls.display_name, cls.name, '')) NOT LIKE '%sport%'
                       THEN 1 END) as podiums,
                   SUM(res.points) as total_points
            FROM clubs c
            LEFT JOIN riders r ON c.id = r.club_id AND r.active = 1
            LEFT JOIN results res ON r.id = res.cyclist_id
            LEFT JOIN classes cls ON res.class_id = cls.id
            WHERE (COALESCE(cls.awards_points, 1) = 1
                   AND LOWER(COALESCE(cls.display_name, cls.name, '')) NOT LIKE '%motion%'
                   AND LOWER(COALESCE(cls.display_name, cls.name, '')) NOT LIKE '%sport%')
                  OR cls.id IS NULL
            GROUP BY c.id
            HAVING total_starts > 0
            ORDER BY podiums DESC, total_points DESC, total_starts DESC
            LIMIT 10
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // Table might not exist
    }
}

?>

<div class="page-header">
    <h1 class="page-title">
        <i data-lucide="database" class="page-icon"></i>
        Databas
    </h1>
    <p class="page-subtitle">Sök bland åkare och klubbar</p>
</div>

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
        <span class="stat-value"><?= number_format($ridersWithWins) ?></span>
        <span class="stat-label">Med vinster</span>
    </div>
    <div class="stat-card">
        <span class="stat-value"><?= number_format($ridersWithPodiums) ?></span>
        <span class="stat-label">Med pallplatser</span>
    </div>
</div>

<!-- Search Section -->
<div class="search-card">
    <div class="search-tabs">
        <button class="search-tab active" data-tab="riders"><i data-lucide="users"></i> Sök Åkare</button>
        <button class="search-tab" data-tab="clubs"><i data-lucide="shield"></i> Sök Klubbar</button>
    </div>

    <div class="search-box">
        <span class="search-icon"><i data-lucide="search"></i></span>
        <input type="text"
               id="database-search"
               class="search-input"
               placeholder="Skriv namn för att söka..."
               autocomplete="off"
               data-type="riders">
        <button type="button" class="search-clear" style="display:none;"><i data-lucide="x"></i></button>
    </div>

    <div class="search-results" id="search-results"></div>

    <div class="search-hint" id="search-hint">
        Skriv minst 2 tecken för att söka
    </div>
</div>

<!-- Two Column Layout -->
<div class="database-grid">
    <!-- Top Riders -->
    <div class="card">
        <h2 class="card-title"><i data-lucide="trophy"></i> Topprankade</h2>
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
                    <?php if ($rider['wins'] > 0): ?>
                        <span class="stat gold"><?= $rider['wins'] ?> Vinster</span>
                    <?php endif; ?>
                    <span class="stat"><?= $rider['podiums'] ?> pallplatser</span>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
        <a href="/ranking" class="card-link">Visa fullständig ranking →</a>
    </div>

    <!-- Top Clubs -->
    <div class="card">
        <h2 class="card-title"><i data-lucide="shield"></i> Toppklubbar</h2>
        <div class="ranking-list">
            <?php foreach ($clubRankings as $i => $club): ?>
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
                    <span class="ranking-meta"><?= $club['riders_with_points'] ?> åkare med poäng</span>
                </div>
                <div class="ranking-stats">
                    <span class="stat"><?= $club['podiums'] ?> pall</span>
                    <span class="stat"><?= number_format($club['total_points']) ?> poäng</span>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<style>
/* Database-specific styles only - common styles in components.css */
.search-card{background:var(--color-bg-card);border-radius:var(--radius-lg);padding:var(--card-padding);margin-bottom:var(--space-xl);border:1px solid var(--color-border)}
.search-tabs{display:flex;gap:var(--space-sm);margin-bottom:var(--space-md)}
.search-tab{flex:1;padding:var(--space-sm) var(--space-md);border:2px solid var(--color-border);border-radius:var(--radius-md);background:transparent;color:var(--color-text-secondary);font-weight:var(--weight-medium);cursor:pointer;transition:all var(--transition-fast)}
.search-tab:hover{border-color:var(--color-accent);color:var(--color-accent)}
.search-tab.active{background:var(--color-accent);border-color:var(--color-accent);color:white}
.search-box{position:relative;display:flex;align-items:center}
.search-icon{position:absolute;left:var(--space-md)}
.search-input{width:100%;padding:var(--space-md) var(--space-md) var(--space-md) calc(var(--space-md)*2+1.5em);font-size:var(--text-lg);background:var(--color-bg-surface);border:2px solid var(--color-border);border-radius:var(--radius-lg);color:var(--color-text-primary);transition:all var(--transition-fast)}
.search-input:focus{outline:none;border-color:var(--color-accent);box-shadow:0 0 0 3px var(--color-accent-light)}
.search-clear{position:absolute;right:var(--space-md);background:none;border:none;color:var(--color-text-secondary);cursor:pointer}
.search-results{margin-top:var(--space-md);max-height:400px;overflow-y:auto}
.search-result{display:flex;align-items:center;gap:var(--space-md);padding:var(--space-sm) var(--space-md);border-radius:var(--radius-md);text-decoration:none;color:inherit;transition:background var(--transition-fast)}
.search-result:hover{background:var(--color-bg-hover)}
.search-result-avatar{width:40px;height:40px;display:flex;align-items:center;justify-content:center;background:var(--color-accent);color:white;border-radius:var(--radius-full);font-weight:var(--weight-bold)}
.search-result-info{flex:1}
.search-result-name{font-weight:var(--weight-medium);display:block}
.search-result-meta{font-size:var(--text-sm);color:var(--color-text-secondary)}
.search-hint{text-align:center;padding:var(--space-lg);color:var(--color-text-muted)}
.database-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:var(--space-lg);margin-bottom:var(--space-xl)}
.card-link{display:block;text-align:center;padding:var(--space-sm);margin-top:var(--space-md);color:var(--color-accent);text-decoration:none;font-weight:var(--weight-medium);border-top:1px solid var(--color-border)}
.ranking-list{display:flex;flex-direction:column}
.ranking-item{display:flex;align-items:center;gap:var(--space-md);padding:var(--space-sm) 0;border-bottom:1px solid var(--color-border-light);text-decoration:none;color:inherit}
.ranking-item:last-child{border-bottom:none}
.ranking-item:hover{background:var(--color-bg-hover);margin:0 calc(var(--space-sm)*-1);padding-left:var(--space-sm);padding-right:var(--space-sm);border-radius:var(--radius-md)}
.ranking-pos{width:32px;height:32px;display:flex;align-items:center;justify-content:center;font-weight:var(--weight-bold);font-size:var(--text-sm);color:var(--color-text-muted)}
.medal-icon{width:24px;height:24px;vertical-align:middle;display:inline-block}
.ranking-info{flex:1;min-width:0}
.ranking-name{font-weight:var(--weight-medium);display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ranking-meta{font-size:var(--text-sm);color:var(--color-text-secondary)}
.ranking-stats{display:flex;gap:var(--space-sm);flex-shrink:0}
.ranking-stats .stat{font-size:var(--text-xs);color:var(--color-text-secondary);background:var(--color-bg-surface);padding:2px 6px;border-radius:var(--radius-sm)}
.ranking-stats .stat.gold{background:#fef3c7;color:#92400e}
.search-tab i,.search-tab svg{width:16px;height:16px;vertical-align:-3px}
@media(max-width:768px){.database-grid{grid-template-columns:1fr}.ranking-stats{flex-direction:column;gap:2px}}
@media(max-width:480px){.search-card{padding:var(--space-md);margin-bottom:var(--space-md)}.search-tabs{gap:var(--space-xs);margin-bottom:var(--space-sm)}.search-tab{padding:var(--space-xs) var(--space-sm);font-size:var(--text-sm);border-width:1px}.search-input{padding:var(--space-sm) var(--space-sm) var(--space-sm) calc(var(--space-sm)+1.5em);font-size:var(--text-md);border-radius:var(--radius-md)}.search-icon{left:var(--space-sm)}.search-hint{padding:var(--space-sm);font-size:var(--text-sm)}.ranking-item{gap:var(--space-sm);padding:var(--space-xs) 0}.ranking-pos{width:28px;height:28px;font-size:var(--text-xs)}.medal-icon{width:20px;height:20px}.ranking-name{font-size:var(--text-sm)}.ranking-meta{font-size:var(--text-xs);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.ranking-stats{flex-direction:row;flex-wrap:wrap;gap:3px;max-width:90px}.ranking-stats .stat{font-size:10px;padding:1px 4px;white-space:nowrap}}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('database-search');
    const searchResults = document.getElementById('search-results');
    const searchHint = document.getElementById('search-hint');
    const searchClear = document.querySelector('.search-clear');
    const searchTabs = document.querySelectorAll('.search-tab');

    let searchTimeout;
    let currentType = 'riders';

    searchTabs.forEach(tab => {
        tab.addEventListener('click', function() {
            searchTabs.forEach(t => t.classList.remove('active'));
            this.classList.add('active');
            currentType = this.dataset.tab;
            searchInput.dataset.type = currentType;
            searchInput.placeholder = currentType === 'riders' ? 'Skriv namn för att söka åkare...' : 'Skriv namn för att söka klubbar...';
            searchInput.value = '';
            searchResults.innerHTML = '';
            searchHint.style.display = 'block';
            searchClear.style.display = 'none';
        });
    });

    searchInput.addEventListener('input', function() {
        const query = this.value.trim();
        searchClear.style.display = query ? 'block' : 'none';

        clearTimeout(searchTimeout);

        if (query.length < 2) {
            searchResults.innerHTML = '';
            searchHint.style.display = 'block';
            searchHint.textContent = 'Skriv minst 2 tecken för att söka';
            return;
        }

        searchHint.style.display = 'block';
        searchHint.textContent = 'Söker...';

        searchTimeout = setTimeout(() => {
            fetch('/api/search.php?type=' + currentType + '&q=' + encodeURIComponent(query))
                .then(r => {
                    if (!r.ok) {
                        throw new Error('HTTP ' + r.status);
                    }
                    return r.json();
                })
                .then(data => {
                    searchHint.style.display = 'none';

                    if (data.results && data.results.length > 0) {
                        searchResults.innerHTML = data.results.map(item => {
                            if (currentType === 'riders') {
                                return '<a href="/rider/' + item.id + '" class="search-result">' +
                                    '<span class="search-result-avatar">' + ((item.firstname || '?')[0]).toUpperCase() + '</span>' +
                                    '<div class="search-result-info">' +
                                    '<span class="search-result-name">' + item.firstname + ' ' + item.lastname + '</span>' +
                                    '<span class="search-result-meta">' + (item.club_name || '-') + '</span>' +
                                    '</div></a>';
                            } else {
                                return '<a href="/club/' + item.id + '" class="search-result">' +
                                    '<span class="search-result-avatar"><i data-lucide="shield"></i></span>' +
                                    '<div class="search-result-info">' +
                                    '<span class="search-result-name">' + item.name + '</span>' +
                                    '<span class="search-result-meta">' + (item.member_count || 0) + ' medlemmar</span>' +
                                    '</div></a>';
                            }
                        }).join('');
                        // Re-init Lucide icons for dynamic content
                        if (typeof lucide !== 'undefined') lucide.createIcons();
                    } else {
                        searchResults.innerHTML = '<div class="search-hint">Inga resultat hittades</div>';
                    }
                })
                .catch(err => {
                    console.error('Search error:', err);
                    searchHint.style.display = 'block';
                    searchHint.textContent = 'Fel vid sökning: ' + err.message;
                });
        }, 300);
    });

    searchClear.addEventListener('click', function() {
        searchInput.value = '';
        searchResults.innerHTML = '';
        searchHint.style.display = 'block';
        searchHint.textContent = 'Skriv minst 2 tecken för att söka';
        this.style.display = 'none';
        searchInput.focus();
    });
});
</script>

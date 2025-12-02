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

// Recent active riders
$recentRiders = $pdo->query("
    SELECT r.id, r.firstname, r.lastname, c.name as club_name
    FROM riders r
    LEFT JOIN clubs c ON r.club_id = c.id
    WHERE r.active = 1
    ORDER BY r.updated_at DESC
    LIMIT 12
")->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="page-header">
    <h1 class="page-title">Databas</h1>
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
        <button class="search-tab active" data-tab="riders">👥 Sök Åkare</button>
        <button class="search-tab" data-tab="clubs">🛡️ Sök Klubbar</button>
    </div>

    <div class="search-box">
        <span class="search-icon">🔍</span>
        <input type="text"
               id="database-search"
               class="search-input"
               placeholder="Skriv namn för att söka..."
               autocomplete="off"
               data-type="riders">
        <button type="button" class="search-clear" style="display:none;">✕</button>
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
        <h2 class="card-title">🏆 Topprankade</h2>
        <div class="ranking-list">
            <?php foreach ($topRiders as $i => $rider): ?>
            <a href="/rider/<?= $rider['id'] ?>" class="ranking-item">
                <span class="ranking-pos <?= $i < 3 ? 'top-' . ($i + 1) : '' ?>">
                    <?php if ($i === 0): ?>🥇<?php elseif ($i === 1): ?>🥈<?php elseif ($i === 2): ?>🥉<?php else: ?><?= $i + 1 ?><?php endif; ?>
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
        <h2 class="card-title">🛡️ Toppklubbar</h2>
        <div class="ranking-list">
            <?php foreach ($clubRankings as $i => $club): ?>
            <a href="/club/<?= $club['id'] ?>" class="ranking-item">
                <span class="ranking-pos <?= $i < 3 ? 'top-' . ($i + 1) : '' ?>">
                    <?php if ($i === 0): ?>🥇<?php elseif ($i === 1): ?>🥈<?php elseif ($i === 2): ?>🥉<?php else: ?><?= $i + 1 ?><?php endif; ?>
                </span>
                <div class="ranking-info">
                    <span class="ranking-name"><?= htmlspecialchars($club['name']) ?></span>
                    <span class="ranking-meta"><?= $club['riders_with_points'] ?> åkare med poäng</span>
                </div>
                <div class="ranking-stats">
                    <span class="stat"><?= $club['podiums'] ?> 🏆</span>
                    <span class="stat"><?= number_format($club['total_points']) ?> poäng</span>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Recent Activity -->
<div class="card">
    <h2 class="card-title">Senast uppdaterade</h2>
    <div class="recent-grid">
        <?php foreach ($recentRiders as $rider): ?>
        <a href="/rider/<?= $rider['id'] ?>" class="recent-item">
            <span class="recent-avatar"><?= strtoupper(mb_substr($rider['firstname'], 0, 1)) ?></span>
            <span class="recent-name"><?= htmlspecialchars($rider['firstname'] . ' ' . $rider['lastname']) ?></span>
        </a>
        <?php endforeach; ?>
    </div>
</div>

<!-- Alphabet Browse -->
<div class="card">
    <h2 class="card-title">Bläddra A-Ö</h2>
    <div class="alphabet-grid">
        <?php foreach (range('A', 'Z') as $letter): ?>
            <a href="/riders?letter=<?= $letter ?>" class="letter-btn"><?= $letter ?></a>
        <?php endforeach; ?>
        <a href="/riders?letter=Å" class="letter-btn">Å</a>
        <a href="/riders?letter=Ä" class="letter-btn">Ä</a>
        <a href="/riders?letter=Ö" class="letter-btn">Ö</a>
    </div>
</div>

<style>
.stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: var(--space-md);
    margin-bottom: var(--space-xl);
}

.stat-card {
    background: var(--color-bg-card);
    border-radius: var(--radius-lg);
    padding: var(--space-lg);
    text-align: center;
    border: 1px solid var(--color-border);
}

.stat-value {
    display: block;
    font-size: var(--text-3xl);
    font-weight: var(--weight-bold);
    color: var(--color-accent);
    line-height: 1.2;
}

.stat-label {
    font-size: var(--text-sm);
    color: var(--color-text-secondary);
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

.search-card {
    background: var(--color-bg-card);
    border-radius: var(--radius-lg);
    padding: var(--space-lg);
    margin-bottom: var(--space-xl);
    border: 1px solid var(--color-border);
}

.search-tabs {
    display: flex;
    gap: var(--space-sm);
    margin-bottom: var(--space-md);
}

.search-tab {
    flex: 1;
    padding: var(--space-sm) var(--space-md);
    border: 2px solid var(--color-border);
    border-radius: var(--radius-md);
    background: transparent;
    color: var(--color-text-secondary);
    font-weight: var(--weight-medium);
    cursor: pointer;
    transition: all var(--transition-fast);
}

.search-tab:hover {
    border-color: var(--color-accent);
    color: var(--color-accent);
}

.search-tab.active {
    background: var(--color-accent);
    border-color: var(--color-accent);
    color: white;
}

.search-box {
    position: relative;
    display: flex;
    align-items: center;
}

.search-icon {
    position: absolute;
    left: var(--space-md);
    font-size: 1.2em;
}

.search-input {
    width: 100%;
    padding: var(--space-md) var(--space-md) var(--space-md) calc(var(--space-md) * 2 + 1.5em);
    font-size: var(--text-lg);
    background: var(--color-bg-surface);
    border: 2px solid var(--color-border);
    border-radius: var(--radius-lg);
    color: var(--color-text-primary);
    transition: all var(--transition-fast);
}

.search-input:focus {
    outline: none;
    border-color: var(--color-accent);
    box-shadow: 0 0 0 3px var(--color-accent-light);
}

.search-clear {
    position: absolute;
    right: var(--space-md);
    background: none;
    border: none;
    color: var(--color-text-secondary);
    cursor: pointer;
    font-size: 1.2em;
}

.search-results {
    margin-top: var(--space-md);
    max-height: 400px;
    overflow-y: auto;
}

.search-result {
    display: flex;
    align-items: center;
    gap: var(--space-md);
    padding: var(--space-sm) var(--space-md);
    border-radius: var(--radius-md);
    text-decoration: none;
    color: inherit;
    transition: background var(--transition-fast);
}

.search-result:hover {
    background: var(--color-bg-hover);
}

.search-result-avatar {
    width: 40px;
    height: 40px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--color-accent);
    color: white;
    border-radius: var(--radius-full);
    font-weight: var(--weight-bold);
}

.search-result-info {
    flex: 1;
}

.search-result-name {
    font-weight: var(--weight-medium);
    display: block;
}

.search-result-meta {
    font-size: var(--text-sm);
    color: var(--color-text-secondary);
}

.search-hint {
    text-align: center;
    padding: var(--space-lg);
    color: var(--color-text-muted);
}

.database-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: var(--space-lg);
    margin-bottom: var(--space-xl);
}

.card {
    background: var(--color-bg-card);
    border-radius: var(--radius-lg);
    padding: var(--space-lg);
    border: 1px solid var(--color-border);
    margin-bottom: var(--space-lg);
}

.card-title {
    font-size: var(--text-lg);
    font-weight: var(--weight-semibold);
    margin: 0 0 var(--space-md);
}

.card-link {
    display: block;
    text-align: center;
    padding: var(--space-sm);
    margin-top: var(--space-md);
    color: var(--color-accent);
    text-decoration: none;
    font-weight: var(--weight-medium);
    border-top: 1px solid var(--color-border);
}

.ranking-list {
    display: flex;
    flex-direction: column;
}

.ranking-item {
    display: flex;
    align-items: center;
    gap: var(--space-md);
    padding: var(--space-sm) 0;
    border-bottom: 1px solid var(--color-border-light);
    text-decoration: none;
    color: inherit;
}

.ranking-item:last-child {
    border-bottom: none;
}

.ranking-item:hover {
    background: var(--color-bg-hover);
    margin: 0 calc(var(--space-sm) * -1);
    padding-left: var(--space-sm);
    padding-right: var(--space-sm);
    border-radius: var(--radius-md);
}

.ranking-pos {
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: var(--weight-bold);
    font-size: var(--text-sm);
    color: var(--color-text-muted);
}

.ranking-pos.top-1 { font-size: 1.2em; }
.ranking-pos.top-2 { font-size: 1.1em; }
.ranking-pos.top-3 { font-size: 1em; }

.ranking-info {
    flex: 1;
    min-width: 0;
}

.ranking-name {
    font-weight: var(--weight-medium);
    display: block;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.ranking-meta {
    font-size: var(--text-sm);
    color: var(--color-text-secondary);
}

.ranking-stats {
    display: flex;
    gap: var(--space-sm);
    flex-shrink: 0;
}

.ranking-stats .stat {
    font-size: var(--text-xs);
    color: var(--color-text-secondary);
    background: var(--color-bg-surface);
    padding: 2px 6px;
    border-radius: var(--radius-sm);
}

.ranking-stats .stat.gold {
    background: #fef3c7;
    color: #92400e;
}

.recent-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
    gap: var(--space-sm);
}

.recent-item {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    padding: var(--space-sm);
    background: var(--color-bg-surface);
    border-radius: var(--radius-md);
    text-decoration: none;
    color: inherit;
    transition: all var(--transition-fast);
}

.recent-item:hover {
    background: var(--color-bg-hover);
    transform: translateX(4px);
}

.recent-avatar {
    width: 36px;
    height: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--color-accent);
    color: white;
    border-radius: var(--radius-full);
    font-weight: var(--weight-bold);
    font-size: var(--text-sm);
    flex-shrink: 0;
}

.recent-name {
    font-weight: var(--weight-medium);
    font-size: var(--text-sm);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.alphabet-grid {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-xs);
}

.letter-btn {
    width: 36px;
    height: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--color-bg-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    text-decoration: none;
    color: var(--color-text-primary);
    font-weight: var(--weight-medium);
    transition: all var(--transition-fast);
}

.letter-btn:hover {
    background: var(--color-accent);
    border-color: var(--color-accent);
    color: white;
    transform: scale(1.1);
}

@media (max-width: 768px) {
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: var(--space-sm);
    }
    .stat-card {
        padding: var(--space-md);
    }
    .stat-value {
        font-size: var(--text-xl);
    }
    .database-grid {
        grid-template-columns: 1fr;
    }
    .ranking-stats {
        flex-direction: column;
        gap: 2px;
    }
}
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
                                    '<span class="search-result-avatar">🛡️</span>' +
                                    '<div class="search-result-info">' +
                                    '<span class="search-result-name">' + item.name + '</span>' +
                                    '<span class="search-result-meta">' + (item.member_count || 0) + ' medlemmar</span>' +
                                    '</div></a>';
                            }
                        }).join('');
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

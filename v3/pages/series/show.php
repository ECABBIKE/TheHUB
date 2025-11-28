<?php
/**
 * TheHUB V3.5 - Series Detail View
 * Shows series info, events, and standings
 */

// Prevent direct access
if (!defined('HUB_V3_ROOT')) {
    header('Location: /v3/series');
    exit;
}

$pdo = hub_db();
$seriesId = $pageInfo['params']['id'] ?? 0;

if (!$seriesId) {
    include HUB_V3_ROOT . '/pages/404.php';
    return;
}

// Fetch series details
$stmt = $pdo->prepare("SELECT * FROM series WHERE id = ?");
$stmt->execute([$seriesId]);
$series = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$series) {
    include HUB_V3_ROOT . '/pages/404.php';
    return;
}

// Check if series_events table exists
$useSeriesEvents = false;
try {
    $check = $pdo->query("SHOW TABLES LIKE 'series_events'");
    $useSeriesEvents = $check->rowCount() > 0;
} catch (Exception $e) {
    $useSeriesEvents = false;
}

// Check if series_results table has data for this series
$useSeriesResults = false;
try {
    $check = $pdo->prepare("SELECT COUNT(*) as cnt FROM series_results WHERE series_id = ?");
    $check->execute([$seriesId]);
    $row = $check->fetch(PDO::FETCH_ASSOC);
    $useSeriesResults = ($row && $row['cnt'] > 0);
} catch (Exception $e) {
    $useSeriesResults = false;
}

// Get events in series
if ($useSeriesEvents) {
    $stmt = $pdo->prepare("
        SELECT e.*, v.name as venue_name, v.city as venue_city,
               COUNT(DISTINCT r.cyclist_id) as result_count
        FROM series_events se
        JOIN events e ON se.event_id = e.id
        LEFT JOIN venues v ON e.venue_id = v.id
        LEFT JOIN results r ON e.id = r.event_id
        WHERE se.series_id = ?
        GROUP BY e.id
        ORDER BY e.date ASC
    ");
} else {
    $stmt = $pdo->prepare("
        SELECT e.*, v.name as venue_name, v.city as venue_city,
               COUNT(DISTINCT r.cyclist_id) as result_count
        FROM events e
        LEFT JOIN venues v ON e.venue_id = v.id
        LEFT JOIN results r ON e.id = r.event_id
        WHERE e.series_id = ?
        GROUP BY e.id
        ORDER BY e.date ASC
    ");
}
$stmt->execute([$seriesId]);
$events = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get classes with results in this series (only point-awarding classes)
if ($useSeriesEvents) {
    $stmt = $pdo->prepare("
        SELECT DISTINCT c.id, c.name, c.display_name, c.sort_order,
               COUNT(DISTINCT r.cyclist_id) as rider_count
        FROM classes c
        JOIN results r ON c.id = r.class_id
        JOIN series_events se ON r.event_id = se.event_id
        WHERE se.series_id = ?
          AND COALESCE(c.series_eligible, 1) = 1
          AND COALESCE(c.awards_points, 1) = 1
        GROUP BY c.id
        ORDER BY c.sort_order ASC
    ");
    $stmt->execute([$seriesId]);
    $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $stmt = $pdo->prepare("
        SELECT DISTINCT c.id, c.name, c.display_name, c.sort_order,
               COUNT(DISTINCT r.cyclist_id) as rider_count
        FROM classes c
        JOIN results r ON c.id = r.class_id
        JOIN events e ON r.event_id = e.id
        WHERE e.series_id = ?
          AND COALESCE(c.series_eligible, 1) = 1
          AND COALESCE(c.awards_points, 1) = 1
        GROUP BY c.id
        ORDER BY c.sort_order ASC
    ");
    $stmt->execute([$seriesId]);
    $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Selected class filter
$selectedClass = $_GET['class'] ?? 'all';
$searchName = $_GET['search'] ?? '';

// Get standings - ONLY for classes that award points
$standings = [];
if ($useSeriesResults) {
    // Use series_results table - filter by awards_points
    $sql = "
        SELECT
            ri.id as rider_id,
            ri.firstname,
            ri.lastname,
            c.name as club_name,
            cls.display_name as class_name,
            cls.id as class_id,
            cls.sort_order as class_sort_order,
            SUM(sr.points) as total_points,
            COUNT(sr.id) as events_count
        FROM series_results sr
        JOIN riders ri ON sr.cyclist_id = ri.id
        LEFT JOIN clubs c ON ri.club_id = c.id
        LEFT JOIN classes cls ON sr.class_id = cls.id
        WHERE sr.series_id = ?
          AND COALESCE(cls.series_eligible, 1) = 1
          AND COALESCE(cls.awards_points, 1) = 1
    ";
    $params = [$seriesId];

    if ($selectedClass !== 'all') {
        $sql .= " AND sr.class_id = ?";
        $params[] = $selectedClass;
    }

    if ($searchName) {
        $sql .= " AND (ri.firstname LIKE ? OR ri.lastname LIKE ?)";
        $params[] = "%{$searchName}%";
        $params[] = "%{$searchName}%";
    }

    $sql .= " GROUP BY ri.id, cls.id ORDER BY cls.sort_order, total_points DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $standings = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    // Fallback: use results.points - filter by awards_points
    $eventIds = array_column($events, 'id');
    if (!empty($eventIds)) {
        $placeholders = implode(',', array_fill(0, count($eventIds), '?'));
        $sql = "
            SELECT
                ri.id as rider_id,
                ri.firstname,
                ri.lastname,
                c.name as club_name,
                cls.display_name as class_name,
                cls.id as class_id,
                cls.sort_order as class_sort_order,
                SUM(r.points) as total_points,
                COUNT(r.id) as events_count
            FROM results r
            JOIN riders ri ON r.cyclist_id = ri.id
            LEFT JOIN clubs c ON ri.club_id = c.id
            LEFT JOIN classes cls ON r.class_id = cls.id
            WHERE r.event_id IN ({$placeholders})
              AND COALESCE(cls.series_eligible, 1) = 1
              AND COALESCE(cls.awards_points, 1) = 1
        ";
        $params = $eventIds;

        if ($selectedClass !== 'all') {
            $sql .= " AND r.class_id = ?";
            $params[] = $selectedClass;
        }

        if ($searchName) {
            $sql .= " AND (ri.firstname LIKE ? OR ri.lastname LIKE ?)";
            $params[] = "%{$searchName}%";
            $params[] = "%{$searchName}%";
        }

        $sql .= " GROUP BY ri.id, cls.id ORDER BY cls.sort_order, total_points DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $standings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Group standings by class for display
$standingsByClass = [];
foreach ($standings as $row) {
    $className = $row['class_name'] ?? 'Okänd';
    if (!isset($standingsByClass[$className])) {
        $standingsByClass[$className] = [];
    }
    $standingsByClass[$className][] = $row;
}

$countBest = $series['count_best_results'] ?? 5;
?>

<div class="page-header">
    <nav class="breadcrumb">
        <a href="/v3/series">Serier</a>
        <span class="breadcrumb-sep">›</span>
        <span><?= htmlspecialchars($series['name']) ?></span>
    </nav>
</div>

<div class="series-detail">
    <!-- Series Header -->
    <div class="series-hero">
        <div class="series-hero-logo">
            <?php if ($series['logo']): ?>
                <img src="<?= htmlspecialchars($series['logo']) ?>" alt="<?= htmlspecialchars($series['name']) ?>">
            <?php else: ?>
                <span class="series-logo-placeholder">🏆</span>
            <?php endif; ?>
        </div>
        <div class="series-hero-info">
            <h1 class="series-title"><?= htmlspecialchars($series['name']) ?></h1>
            <?php if ($series['description']): ?>
                <p class="series-description"><?= htmlspecialchars($series['description']) ?></p>
            <?php endif; ?>
            <div class="series-meta">
                <span><?= count($events) ?> tävlingar</span>
                <span>Räknar <?= $countBest ?> bästa resultat</span>
            </div>
        </div>
    </div>

    <!-- Events in Series -->
    <div class="card">
        <h2 class="card-title">Tävlingar i serien</h2>
        <?php if (empty($events)): ?>
            <p class="text-muted">Inga tävlingar i serien ännu.</p>
        <?php else: ?>
            <div class="events-list">
                <?php foreach ($events as $i => $event):
                    $eventDate = strtotime($event['date']);
                    $hasResults = $eventDate < time() && $event['result_count'] > 0;
                ?>
                <div class="event-row">
                    <span class="event-num">#<?= $i + 1 ?></span>
                    <span class="event-date"><?= date('Y-m-d', $eventDate) ?></span>
                    <span class="event-name"><?= htmlspecialchars($event['name']) ?></span>
                    <span class="event-location"><?= htmlspecialchars($event['location'] ?? $event['venue_city'] ?? '-') ?></span>
                    <?php if ($hasResults): ?>
                        <a href="/v3/event/<?= $event['id'] ?>" class="btn btn-sm">Resultat (<?= $event['result_count'] ?>)</a>
                    <?php else: ?>
                        <span class="text-muted">Kommande</span>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Filters -->
    <div class="card">
        <form method="get" class="series-filters">
            <div class="filter-group">
                <label for="class-filter">Klass</label>
                <select name="class" id="class-filter" onchange="this.form.submit()">
                    <option value="all" <?= $selectedClass === 'all' ? 'selected' : '' ?>>Alla klasser</option>
                    <?php foreach ($classes as $cls): ?>
                        <option value="<?= $cls['id'] ?>" <?= $selectedClass == $cls['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($cls['display_name'] ?? $cls['name']) ?> (<?= $cls['rider_count'] ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <label for="name-search">Sök namn</label>
                <input type="search" name="search" id="name-search" value="<?= htmlspecialchars($searchName) ?>" placeholder="Skriv namn...">
            </div>
            <button type="submit" class="btn btn-primary">Sök</button>
        </form>
    </div>

    <!-- Standings -->
    <div class="card">
        <h2 class="card-title">Ställning</h2>

        <?php if (empty($standings)): ?>
            <p class="text-muted text-center">Ingen ställning ännu.</p>
        <?php else: ?>
            <?php foreach ($standingsByClass as $className => $classStandings): ?>
            <div class="standings-class">
                <h3 class="standings-class-title"><?= htmlspecialchars($className) ?></h3>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th class="col-pos">#</th>
                                <th class="col-name">Namn</th>
                                <th class="col-club">Klubb</th>
                                <th class="col-events">Tävl.</th>
                                <th class="col-points">Poäng</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($classStandings as $pos => $row): ?>
                            <tr>
                                <td class="col-pos">
                                    <?php if ($pos < 3): ?>
                                        <span class="medal medal-<?= $pos + 1 ?>">
                                            <?= ['🥇', '🥈', '🥉'][$pos] ?>
                                        </span>
                                    <?php else: ?>
                                        <?= $pos + 1 ?>
                                    <?php endif; ?>
                                </td>
                                <td class="col-name">
                                    <a href="/v3/rider/<?= $row['rider_id'] ?>">
                                        <?= htmlspecialchars($row['firstname'] . ' ' . $row['lastname']) ?>
                                    </a>
                                </td>
                                <td class="col-club text-muted"><?= htmlspecialchars($row['club_name'] ?? '-') ?></td>
                                <td class="col-events"><?= $row['events_count'] ?></td>
                                <td class="col-points"><strong><?= number_format($row['total_points'], 0) ?></strong></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

</div>

<style>
.series-hero {
    display: flex;
    gap: var(--space-lg);
    padding: var(--space-lg);
    background: var(--color-bg-card);
    border-radius: var(--radius-lg);
    margin-bottom: var(--space-lg);
}

.series-hero-logo {
    width: 100px;
    height: 100px;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--color-bg-sunken);
    border-radius: var(--radius-md);
}

.series-hero-logo img {
    max-width: 100%;
    max-height: 100%;
    object-fit: contain;
}

.series-logo-placeholder {
    font-size: 3rem;
}

.series-title {
    font-size: var(--text-2xl);
    margin: 0 0 var(--space-xs);
}

.series-description {
    color: var(--color-text-secondary);
    margin: 0 0 var(--space-sm);
}

.series-meta {
    display: flex;
    gap: var(--space-lg);
    font-size: var(--text-sm);
    color: var(--color-text-muted);
}

.card {
    background: var(--color-bg-card);
    border-radius: var(--radius-lg);
    padding: var(--space-lg);
    margin-bottom: var(--space-lg);
}

.card-title {
    font-size: var(--text-lg);
    margin: 0 0 var(--space-md);
}

.events-list {
    display: flex;
    flex-direction: column;
    gap: var(--space-xs);
}

.event-row {
    display: grid;
    grid-template-columns: 50px 100px 1fr 150px 120px;
    gap: var(--space-md);
    align-items: center;
    padding: var(--space-sm);
    border-radius: var(--radius-md);
    background: var(--color-bg-sunken);
}

.event-num {
    font-weight: var(--weight-medium);
    color: var(--color-text-muted);
}

.event-date {
    font-size: var(--text-sm);
}

.event-name {
    font-weight: var(--weight-medium);
}

.event-location {
    color: var(--color-text-secondary);
    font-size: var(--text-sm);
}

.series-filters {
    display: flex;
    gap: var(--space-md);
    align-items: flex-end;
}

.filter-group {
    display: flex;
    flex-direction: column;
    gap: var(--space-xs);
}

.filter-group label {
    font-size: var(--text-sm);
    font-weight: var(--weight-medium);
}

.filter-group select,
.filter-group input {
    padding: var(--space-sm) var(--space-md);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    background: var(--color-bg-surface);
    color: inherit;
    min-width: 180px;
}

.standings-class {
    margin-bottom: var(--space-xl);
}

.standings-class:last-child {
    margin-bottom: 0;
}

.standings-class-title {
    font-size: var(--text-md);
    color: var(--color-accent);
    margin: 0 0 var(--space-sm);
    padding-bottom: var(--space-xs);
    border-bottom: 2px solid var(--color-accent);
}

.table {
    width: 100%;
    border-collapse: collapse;
}

.table th,
.table td {
    padding: var(--space-sm);
    text-align: left;
}

.table th {
    font-size: var(--text-sm);
    font-weight: var(--weight-medium);
    color: var(--color-text-secondary);
    border-bottom: 1px solid var(--color-border);
}

.table td {
    border-bottom: 1px solid var(--color-border-light);
}

.table tbody tr:hover {
    background: var(--color-bg-hover);
}

.col-pos { width: 50px; text-align: center; }
.col-events { width: 60px; text-align: center; }
.col-points { width: 80px; text-align: right; }
.col-club { color: var(--color-text-secondary); }

.medal {
    font-size: 1.2em;
}

.btn {
    padding: var(--space-xs) var(--space-sm);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    background: var(--color-bg-surface);
    color: inherit;
    text-decoration: none;
    font-size: var(--text-sm);
    cursor: pointer;
}

.btn-primary {
    background: var(--color-accent);
    border-color: var(--color-accent);
    color: white;
}

.btn-sm {
    padding: var(--space-xs) var(--space-sm);
    font-size: var(--text-xs);
}

@media (max-width: 768px) {
    .series-hero {
        flex-direction: column;
        text-align: center;
    }

    .series-hero-logo {
        margin: 0 auto;
    }

    .series-meta {
        justify-content: center;
    }

    .event-row {
        grid-template-columns: 1fr;
        gap: var(--space-xs);
    }

    .series-filters {
        flex-direction: column;
        align-items: stretch;
    }

    .filter-group select,
    .filter-group input {
        width: 100%;
    }
}
</style>

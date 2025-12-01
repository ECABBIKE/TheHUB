<?php
/**
 * TheHUB V3.5 - Series Detail View
 * Shows series info, events, and standings
 */

// Prevent direct access
if (!defined('HUB_V3_ROOT')) {
    header('Location: /series');
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
$countBest = $series['count_best_results'] ?? null;

// Build standings with per-event points (like V2)
$standingsByClass = [];
$eventIds = array_column($events, 'id');

if (!empty($eventIds)) {
    // Get all riders who have results in this series (point-awarding classes only)
    $placeholders = implode(',', array_fill(0, count($eventIds), '?'));

    $sql = "
        SELECT DISTINCT
            ri.id as rider_id,
            ri.firstname,
            ri.lastname,
            c.name as club_name,
            cls.id as class_id,
            cls.name as class_name,
            cls.display_name as class_display_name,
            cls.sort_order as class_sort_order
        FROM riders ri
        LEFT JOIN clubs c ON ri.club_id = c.id
        JOIN results r ON ri.id = r.cyclist_id
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

    $sql .= " ORDER BY cls.sort_order ASC, ri.lastname, ri.firstname";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $riders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // For each rider, get their points from each event
    foreach ($riders as $rider) {
        $riderData = [
            'rider_id' => $rider['rider_id'],
            'firstname' => $rider['firstname'],
            'lastname' => $rider['lastname'],
            'club_name' => $rider['club_name'],
            'class_id' => $rider['class_id'],
            'event_points' => [],
            'excluded_events' => [],
            'total_points' => 0
        ];

        // Get points for each event
        $allPoints = [];
        foreach ($events as $event) {
            if ($useSeriesResults) {
                $pStmt = $pdo->prepare("
                    SELECT points FROM series_results
                    WHERE series_id = ? AND cyclist_id = ? AND event_id = ? AND class_id = ?
                    LIMIT 1
                ");
                $pStmt->execute([$seriesId, $rider['rider_id'], $event['id'], $rider['class_id']]);
            } else {
                $pStmt = $pdo->prepare("
                    SELECT points FROM results
                    WHERE cyclist_id = ? AND event_id = ? AND class_id = ?
                    LIMIT 1
                ");
                $pStmt->execute([$rider['rider_id'], $event['id'], $rider['class_id']]);
            }
            $result = $pStmt->fetch(PDO::FETCH_ASSOC);
            $points = $result ? (int)$result['points'] : 0;
            $riderData['event_points'][$event['id']] = $points;
            if ($points > 0) {
                $allPoints[] = ['event_id' => $event['id'], 'points' => $points];
            }
        }

        // Apply count_best_results rule
        if ($countBest && count($allPoints) > $countBest) {
            usort($allPoints, function($a, $b) {
                return $b['points'] - $a['points'];
            });
            for ($i = $countBest; $i < count($allPoints); $i++) {
                $riderData['excluded_events'][$allPoints[$i]['event_id']] = true;
            }
            for ($i = 0; $i < $countBest; $i++) {
                $riderData['total_points'] += $allPoints[$i]['points'];
            }
        } else {
            foreach ($allPoints as $p) {
                $riderData['total_points'] += $p['points'];
            }
        }

        // Only include riders with points
        if ($riderData['total_points'] > 0) {
            $classKey = $rider['class_display_name'] ?? $rider['class_name'] ?? 'Okänd';
            if (!isset($standingsByClass[$classKey])) {
                $standingsByClass[$classKey] = [
                    'class_id' => $rider['class_id'],
                    'sort_order' => $rider['class_sort_order'],
                    'riders' => []
                ];
            }
            $standingsByClass[$classKey]['riders'][] = $riderData;
        }
    }

    // Sort riders within each class by total points
    foreach ($standingsByClass as &$classData) {
        usort($classData['riders'], function($a, $b) {
            return $b['total_points'] - $a['total_points'];
        });
    }
    unset($classData);

    // Sort classes by sort_order
    uasort($standingsByClass, function($a, $b) {
        return ($a['sort_order'] ?? 999) - ($b['sort_order'] ?? 999);
    });
}
?>

<div class="page-header">
    <nav class="breadcrumb">
        <a href="/series">Serier</a>
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
                        <a href="/event/<?= $event['id'] ?>" class="btn btn-sm">Resultat (<?= $event['result_count'] ?>)</a>
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
        <?php if ($countBest): ?>
            <p class="text-muted" style="margin-top: -0.5rem; margin-bottom: 1rem;">Räknar <?= $countBest ?> bästa resultat</p>
        <?php endif; ?>

        <?php if (empty($standingsByClass)): ?>
            <p class="text-muted text-center">Ingen ställning ännu.</p>
        <?php else: ?>
            <?php foreach ($standingsByClass as $className => $classData): ?>
            <div class="standings-class">
                <h3 class="standings-class-title"><?= htmlspecialchars($className) ?> <span class="rider-count">(<?= count($classData['riders']) ?>)</span></h3>
                <div class="table-responsive">
                    <table class="table standings-table">
                        <thead>
                            <tr>
                                <th class="col-pos">#</th>
                                <th class="col-name">Namn</th>
                                <th class="col-club">Klubb</th>
                                <?php $eventNum = 1; foreach ($events as $event): ?>
                                <th class="col-event" title="<?= htmlspecialchars($event['name']) ?>">#<?= $eventNum++ ?></th>
                                <?php endforeach; ?>
                                <th class="col-total">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($classData['riders'] as $pos => $row): ?>
                            <tr>
                                <td class="col-pos">
                                    <?php if ($pos < 3): ?>
                                        <span class="medal"><?= ['🥇', '🥈', '🥉'][$pos] ?></span>
                                    <?php else: ?>
                                        <?= $pos + 1 ?>
                                    <?php endif; ?>
                                </td>
                                <td class="col-name">
                                    <a href="/database/rider/<?= $row['rider_id'] ?>">
                                        <?= htmlspecialchars($row['firstname'] . ' ' . $row['lastname']) ?>
                                    </a>
                                </td>
                                <td class="col-club"><?= htmlspecialchars($row['club_name'] ?? '-') ?></td>
                                <?php foreach ($events as $event):
                                    $pts = $row['event_points'][$event['id']] ?? 0;
                                    $isExcluded = isset($row['excluded_events'][$event['id']]);
                                ?>
                                <td class="col-event <?= $isExcluded ? 'excluded' : '' ?>">
                                    <?php if ($pts > 0): ?>
                                        <?php if ($isExcluded): ?>
                                            <span class="points-excluded" title="Räknas ej"><?= $pts ?></span>
                                        <?php else: ?>
                                            <?= $pts ?>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="no-points">–</span>
                                    <?php endif; ?>
                                </td>
                                <?php endforeach; ?>
                                <td class="col-total"><strong><?= $row['total_points'] ?></strong></td>
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
    background: #E8EAED; /* Fixed light theme color to prevent flash */
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

.col-pos { width: 40px; text-align: center; }
.col-name { white-space: nowrap; }
.col-club { color: var(--color-text-secondary); white-space: nowrap; }
.col-event {
    width: 40px;
    text-align: center;
    font-size: var(--text-sm);
}
.col-total {
    width: 60px;
    text-align: center;
    background: var(--color-bg-sunken);
}

.rider-count {
    font-weight: normal;
    font-size: var(--text-sm);
    color: var(--color-text-muted);
}

.standings-table {
    font-size: var(--text-sm);
}

.standings-table th.col-event {
    font-size: var(--text-xs);
    padding: var(--space-xs);
}

.standings-table td.col-event {
    padding: var(--space-xs);
}

.points-excluded {
    text-decoration: line-through;
    color: var(--color-text-muted);
}

.no-points {
    color: var(--color-text-muted);
}

.medal {
    font-size: 1em;
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

/* Mobile Portrait: Only show position, name, and total - hide club and events */
@media (max-width: 767px) and (orientation: portrait) {
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

    /* Hide club column and all event columns in portrait */
    .standings-table .col-club,
    .standings-table th.col-club,
    .standings-table .col-event,
    .standings-table th.col-event {
        display: none;
    }

    /* Make name column flexible */
    .col-name {
        white-space: normal;
        word-break: break-word;
    }
}

/* Mobile Landscape: Show events but hide club */
@media (max-width: 1023px) and (min-width: 768px),
       (max-width: 767px) and (orientation: landscape) {
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

    /* Hide club column in landscape but show events */
    .standings-table .col-club,
    .standings-table th.col-club {
        display: none;
    }

    /* Reduce event column width for space */
    .col-event {
        width: 35px;
        padding: var(--space-xs) 2px;
    }

    .standings-table th.col-event {
        padding: var(--space-xs) 2px;
    }

    /* Allow name to wrap if needed */
    .col-name {
        white-space: normal;
        word-break: break-word;
    }
}

/* Tablet and small desktop: Hide club if too many events */
@media (min-width: 768px) and (max-width: 1023px) {
    .standings-table .col-club,
    .standings-table th.col-club {
        display: none;
    }
}
</style>

<?php
/**
 * TheHUB V3.5 - Series Detail View
 * Shows series info, events, and standings (Individual + Club with 100%/50% rule)
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
            ri.club_id,
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
            'club_id' => $rider['club_id'],
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
            $classKey = $rider['class_display_name'] ?? $rider['class_name'] ?? 'Okand';
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

// ============================================================================
// CLUB STANDINGS with 100%/50% rule
// Best rider per club/class/event = 100%, second best = 50%, others = 0%
// ============================================================================
$clubStandings = [];
$clubRiderContributions = [];

foreach ($events as $event) {
    $eventId = $event['id'];

    // Get all results for this event with series points, grouped by club and class
    if ($useSeriesResults) {
        $stmt = $pdo->prepare("
            SELECT
                sr.cyclist_id,
                sr.class_id,
                sr.points,
                rd.firstname,
                rd.lastname,
                c.id as club_id,
                c.name as club_name,
                cls.name as class_name,
                cls.display_name as class_display_name
            FROM series_results sr
            JOIN riders rd ON sr.cyclist_id = rd.id
            LEFT JOIN clubs c ON rd.club_id = c.id
            LEFT JOIN classes cls ON sr.class_id = cls.id
            WHERE sr.series_id = ? AND sr.event_id = ?
            AND c.id IS NOT NULL
            AND sr.points > 0
            AND COALESCE(cls.series_eligible, 1) = 1
            AND COALESCE(cls.awards_points, 1) = 1
            ORDER BY c.id, sr.class_id, sr.points DESC
        ");
        $stmt->execute([$seriesId, $eventId]);
    } else {
        $stmt = $pdo->prepare("
            SELECT
                r.cyclist_id,
                r.class_id,
                r.points,
                rd.firstname,
                rd.lastname,
                c.id as club_id,
                c.name as club_name,
                cls.name as class_name,
                cls.display_name as class_display_name
            FROM results r
            JOIN riders rd ON r.cyclist_id = rd.id
            LEFT JOIN clubs c ON rd.club_id = c.id
            LEFT JOIN classes cls ON r.class_id = cls.id
            WHERE r.event_id = ?
            AND r.status = 'finished'
            AND c.id IS NOT NULL
            AND r.points > 0
            AND COALESCE(cls.series_eligible, 1) = 1
            AND COALESCE(cls.awards_points, 1) = 1
            ORDER BY c.id, r.class_id, r.points DESC
        ");
        $stmt->execute([$eventId]);
    }
    $eventResults = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Group by club and class
    $clubClassResults = [];
    foreach ($eventResults as $result) {
        $key = $result['club_id'] . '_' . $result['class_id'];
        if (!isset($clubClassResults[$key])) {
            $clubClassResults[$key] = [];
        }
        $clubClassResults[$key][] = $result;
    }

    // Apply 100%/50% rule for each club/class combo
    foreach ($clubClassResults as $clubRiders) {
        $rank = 1;
        foreach ($clubRiders as $rider) {
            $clubId = $rider['club_id'];
            $clubName = $rider['club_name'];
            $originalPoints = (float)$rider['points'];
            $clubPoints = 0;

            if ($rank === 1) {
                $clubPoints = $originalPoints;
            } elseif ($rank === 2) {
                $clubPoints = round($originalPoints * 0.5, 0);
            }

            // Initialize club if not exists
            if (!isset($clubStandings[$clubId])) {
                $clubStandings[$clubId] = [
                    'club_id' => $clubId,
                    'club_name' => $clubName,
                    'riders' => [],
                    'total_points' => 0,
                    'event_points' => [],
                    'rider_count' => 0
                ];
                foreach ($events as $e) {
                    $clubStandings[$clubId]['event_points'][$e['id']] = 0;
                }
            }

            // Add club points for this event
            $clubStandings[$clubId]['event_points'][$eventId] += $clubPoints;
            $clubStandings[$clubId]['total_points'] += $clubPoints;

            // Track rider contribution
            $riderId = $rider['cyclist_id'];
            $riderKey = $clubId . '_' . $riderId;
            if (!isset($clubRiderContributions[$riderKey])) {
                $clubRiderContributions[$riderKey] = [
                    'rider_id' => $riderId,
                    'club_id' => $clubId,
                    'name' => $rider['firstname'] . ' ' . $rider['lastname'],
                    'class_name' => $rider['class_display_name'] ?? $rider['class_name'],
                    'total_club_points' => 0
                ];
            }
            $clubRiderContributions[$riderKey]['total_club_points'] += $clubPoints;

            $rank++;
        }
    }
}

// Add riders to their clubs
foreach ($clubRiderContributions as $riderData) {
    $clubId = $riderData['club_id'];
    if (isset($clubStandings[$clubId])) {
        $clubStandings[$clubId]['riders'][] = [
            'rider_id' => $riderData['rider_id'],
            'name' => $riderData['name'],
            'class_name' => $riderData['class_name'],
            'points' => $riderData['total_club_points']
        ];
        $clubStandings[$clubId]['rider_count']++;
    }
}

// Sort clubs by total points
uasort($clubStandings, function($a, $b) {
    return $b['total_points'] - $a['total_points'];
});

// Sort riders within each club by points
foreach ($clubStandings as &$club) {
    usort($club['riders'], function($a, $b) {
        return $b['points'] - $a['points'];
    });
}
unset($club);
?>

<div class="page-header">
    <nav class="breadcrumb">
        <a href="/series">Serier</a>
        <span class="breadcrumb-sep">></span>
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
                <span><?= count($events) ?> tavlingar</span>
                <?php if ($countBest): ?>
                <span>Raknar <?= $countBest ?> basta resultat</span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Collapsible Events Section -->
    <details class="events-dropdown">
        <summary class="events-dropdown-header">
            <span>📅 Tavlingar i serien</span>
            <span class="events-count"><?= count($events) ?> st</span>
            <span class="dropdown-arrow">▾</span>
        </summary>
        <div class="events-dropdown-content">
            <?php if (empty($events)): ?>
                <p class="text-muted">Inga tavlingar i serien annu.</p>
            <?php else: ?>
                <?php foreach ($events as $i => $event):
                    $eventDate = strtotime($event['date']);
                    $hasResults = $eventDate < time() && $event['result_count'] > 0;
                ?>
                <a href="/event/<?= $event['id'] ?>" class="event-dropdown-item">
                    <span class="event-num">#<?= $i + 1 ?></span>
                    <span class="event-date"><?= date('j M', $eventDate) ?></span>
                    <span class="event-name"><?= htmlspecialchars($event['name']) ?></span>
                    <span class="event-results"><?= $hasResults ? $event['result_count'] . ' resultat' : 'Kommande' ?></span>
                </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </details>

    <!-- Toggle Buttons: Individual / Clubs -->
    <div class="standings-tabs">
        <button class="standings-tab active" data-tab="individual" onclick="switchTab('individual')">
            👤 Individuellt
        </button>
        <button class="standings-tab" data-tab="club" onclick="switchTab('club')">
            🛡️ Klubbmastarskap
        </button>
    </div>

    <!-- Individual Standings Section -->
    <div id="individual-standings">
        <!-- Filters -->
        <form method="get" class="filters-bar">
            <div class="filter-group">
                <label class="filter-label">Klass</label>
                <select name="class" id="class-filter" class="filter-select" onchange="this.form.submit()">
                    <option value="all" <?= $selectedClass === 'all' ? 'selected' : '' ?>>Alla klasser</option>
                    <?php foreach ($classes as $cls): ?>
                        <option value="<?= $cls['id'] ?>" <?= $selectedClass == $cls['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($cls['display_name'] ?? $cls['name']) ?> (<?= $cls['rider_count'] ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group filter-search-group">
                <label class="filter-label">Sök namn</label>
                <input type="search" name="search" id="name-search" class="filter-select" value="<?= htmlspecialchars($searchName) ?>" placeholder="Skriv namn...">
            </div>
            <button type="submit" class="btn btn-primary filter-btn">Sök</button>
        </form>

        <!-- Individual Standings -->
        <div class="card">
            <h2 class="card-title">Individuell stallning</h2>
            <?php if ($countBest): ?>
                <p class="text-muted standings-note">Raknar <?= $countBest ?> basta resultat</p>
            <?php endif; ?>

            <?php if (empty($standingsByClass)): ?>
                <p class="text-muted text-center">Ingen stallning annu.</p>
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
                                        <?php if ($pos == 0): ?>
                                            <img src="/assets/icons/medal-1st.svg" alt="1:a" class="medal-icon">
                                        <?php elseif ($pos == 1): ?>
                                            <img src="/assets/icons/medal-2nd.svg" alt="2:a" class="medal-icon">
                                        <?php elseif ($pos == 2): ?>
                                            <img src="/assets/icons/medal-3rd.svg" alt="3:e" class="medal-icon">
                                        <?php else: ?>
                                            <?= $pos + 1 ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="col-name">
                                        <a href="/rider/<?= $row['rider_id'] ?>">
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
                                                <span class="points-excluded" title="Raknas ej"><?= $pts ?></span>
                                            <?php else: ?>
                                                <?= $pts ?>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="no-points">-</span>
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

    <!-- Club Standings Section -->
    <div id="club-standings" style="display: none;">
        <div class="card">
            <h2 class="card-title">🛡️ Klubbmastarskap</h2>
            <p class="text-muted standings-note"><?= count($clubStandings) ?> klubbar - Basta akare per klass: 100%, nast basta: 50%</p>

            <?php if (empty($clubStandings)): ?>
                <p class="text-muted text-center">Inga klubbresultat annu.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table standings-table club-table">
                        <thead>
                            <tr>
                                <th class="col-pos">#</th>
                                <th class="col-club-name">Klubb</th>
                                <th class="col-riders">Akare</th>
                                <?php $eventNum = 1; foreach ($events as $event): ?>
                                <th class="col-event" title="<?= htmlspecialchars($event['name']) ?>">#<?= $eventNum++ ?></th>
                                <?php endforeach; ?>
                                <th class="col-total">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $clubPos = 0; foreach ($clubStandings as $club): $clubPos++; ?>
                            <tr class="club-row" data-club="<?= $club['club_id'] ?>">
                                <td class="col-pos">
                                    <?php if ($clubPos == 1): ?>
                                        <img src="/assets/icons/medal-1st.svg" alt="1:a" class="medal-icon">
                                    <?php elseif ($clubPos == 2): ?>
                                        <img src="/assets/icons/medal-2nd.svg" alt="2:a" class="medal-icon">
                                    <?php elseif ($clubPos == 3): ?>
                                        <img src="/assets/icons/medal-3rd.svg" alt="3:e" class="medal-icon">
                                    <?php else: ?>
                                        <?= $clubPos ?>
                                    <?php endif; ?>
                                </td>
                                <td class="col-club-name">
                                    <div class="club-info">
                                        <span class="club-name-text"><?= htmlspecialchars($club['club_name']) ?></span>
                                        <button class="club-expand-btn" onclick="toggleClubRiders(this, event)">▸</button>
                                    </div>
                                </td>
                                <td class="col-riders"><?= $club['rider_count'] ?></td>
                                <?php foreach ($events as $event):
                                    $pts = $club['event_points'][$event['id']] ?? 0;
                                ?>
                                <td class="col-event"><?= $pts > 0 ? $pts : '-' ?></td>
                                <?php endforeach; ?>
                                <td class="col-total"><strong><?= $club['total_points'] ?></strong></td>
                            </tr>
                            <?php foreach ($club['riders'] as $clubRider): ?>
                            <tr class="club-rider-row" data-parent="<?= $club['club_id'] ?>" style="display: none;">
                                <td></td>
                                <td colspan="2" class="club-rider-cell">
                                    <a href="/rider/<?= $clubRider['rider_id'] ?>">
                                        ↳ <?= htmlspecialchars($clubRider['name']) ?>
                                    </a>
                                    <span class="rider-class">(<?= htmlspecialchars($clubRider['class_name']) ?>)</span>
                                </td>
                                <?php foreach ($events as $event): ?>
                                <td class="col-event"></td>
                                <?php endforeach; ?>
                                <td class="col-total text-muted"><?= $clubRider['points'] ?> p</td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function switchTab(tab) {
    document.querySelectorAll('.standings-tab').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.tab === tab);
    });
    document.getElementById('individual-standings').style.display = tab === 'individual' ? '' : 'none';
    document.getElementById('club-standings').style.display = tab === 'club' ? '' : 'none';
}

function toggleClubRiders(btn, e) {
    e.stopPropagation();
    const row = btn.closest('tr');
    const clubId = row.dataset.club;
    const isExpanded = btn.textContent === '▾';
    btn.textContent = isExpanded ? '▸' : '▾';
    document.querySelectorAll(`tr[data-parent="${clubId}"]`).forEach(r => {
        r.style.display = isExpanded ? 'none' : '';
    });
}
</script>

<style>
/* Filters Bar */
.filters-bar {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-md);
    margin-bottom: var(--space-lg);
    padding: var(--space-md);
    background: var(--color-bg-card);
    border-radius: var(--radius-lg);
    border: 1px solid var(--color-border);
    align-items: flex-end;
}
.filter-group {
    display: flex;
    flex-direction: column;
    gap: var(--space-2xs);
    flex: 1;
    min-width: 140px;
}
.filter-search-group {
    flex: 2;
}
.filter-label {
    font-size: var(--text-xs);
    color: var(--color-text-secondary);
    text-transform: uppercase;
    font-weight: var(--weight-medium);
}
.filter-select {
    padding: var(--space-sm) var(--space-md);
    padding-right: var(--space-xl);
    background: var(--color-bg-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    color: var(--color-text-primary);
    font-size: var(--text-sm);
    cursor: pointer;
    appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%236b7280' d='M3 4.5L6 7.5L9 4.5'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 8px center;
    transition: border-color var(--transition-fast);
}
input.filter-select {
    background-image: none;
    padding-right: var(--space-md);
}
.filter-select:focus {
    outline: none;
    border-color: var(--color-accent);
    box-shadow: 0 0 0 3px rgba(59, 158, 255, 0.1);
}
.filter-btn {
    white-space: nowrap;
}

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
    background: #E8EAED;
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

/* Toggle Tabs */
.standings-tabs {
    display: flex;
    gap: var(--space-xs);
    background: var(--color-bg-card);
    padding: var(--space-xs);
    border-radius: var(--radius-lg);
    margin-bottom: var(--space-lg);
}
.standings-tab {
    flex: 1;
    padding: var(--space-sm) var(--space-md);
    border: none;
    border-radius: var(--radius-md);
    background: transparent;
    color: var(--color-text-secondary);
    font-weight: var(--weight-medium);
    font-size: var(--text-sm);
    cursor: pointer;
    transition: all 0.15s;
}
.standings-tab:hover {
    background: var(--color-bg-hover);
}
.standings-tab.active {
    background: var(--color-accent);
    color: white;
}

/* Cards */
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
.standings-note {
    margin-top: -0.5rem;
    margin-bottom: 1rem;
}

/* Standings Tables */
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
.rider-count {
    font-weight: normal;
    font-size: var(--text-sm);
    color: var(--color-text-muted);
}
.table {
    width: 100%;
    border-collapse: collapse;
}
.table th, .table td {
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
/* Standardized column widths */
.standings-table {
    table-layout: fixed;
    width: 100%;
    font-size: var(--text-sm);
}
.col-pos {
    width: 48px;
    text-align: center;
}
.col-name {
    width: auto;
    min-width: 140px;
    white-space: nowrap;
}
.col-club {
    width: auto;
    min-width: 100px;
    color: var(--color-text-secondary);
}
.col-riders {
    width: 60px;
    text-align: center;
    color: var(--color-text-secondary);
}
.col-club-name {
    width: auto;
    min-width: 150px;
}
.col-event {
    width: 44px;
    text-align: center;
    font-size: var(--text-sm);
}
.col-total {
    width: 64px;
    text-align: center;
    background: var(--color-bg-sunken);
}

/* Medal icons */
.medal-icon {
    width: 24px;
    height: 24px;
    vertical-align: middle;
    display: inline-block;
}
.standings-table th.col-event {
    font-size: var(--text-xs);
    padding: var(--space-xs);
}
.points-excluded {
    text-decoration: line-through;
    color: var(--color-text-muted);
}
.no-points {
    color: var(--color-text-muted);
}

/* Club-specific */
.club-info {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
}
.club-name-text {
    font-weight: var(--weight-medium);
}
.club-expand-btn {
    background: none;
    border: none;
    color: var(--color-text-muted);
    cursor: pointer;
    padding: 2px 6px;
    font-size: var(--text-xs);
    border-radius: var(--radius-sm);
}
.club-expand-btn:hover {
    background: var(--color-bg-hover);
}
.club-rider-row {
    background: var(--color-bg-sunken);
}
.club-rider-row td {
    padding-top: var(--space-2xs);
    padding-bottom: var(--space-2xs);
    font-size: var(--text-sm);
}
.club-rider-cell a {
    color: var(--color-text-secondary);
    text-decoration: none;
}
.club-rider-cell a:hover {
    color: var(--color-accent);
}
.rider-class {
    color: var(--color-text-muted);
    font-size: var(--text-xs);
}

/* Buttons */
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

/* Mobile Portrait - hide event columns, compact layout */
@media (max-width: 599px) {
    .filters-bar {
        flex-direction: column;
        padding: var(--space-sm);
        gap: var(--space-sm);
    }
    .filter-group {
        width: 100%;
        min-width: 0;
    }
    .filter-select {
        width: 100%;
    }
    .filter-btn {
        width: 100%;
    }
}

@media (max-width: 599px) and (orientation: portrait) {
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
    .standings-table .col-club,
    .standings-table th.col-club,
    .standings-table .col-riders,
    .standings-table th.col-riders,
    .standings-table .col-event,
    .standings-table th.col-event {
        display: none;
    }
    .col-name, .col-club-name {
        white-space: normal;
        word-break: break-word;
    }
}

/* Mobile Landscape & Tablet - scrollable table with all columns */
@media (max-width: 1024px) and (orientation: landscape),
       (min-width: 600px) and (max-width: 1024px) {
    .series-hero {
        flex-direction: row;
        text-align: left;
    }
    .series-hero-logo {
        width: 80px;
        height: 80px;
    }
    /* Make table horizontally scrollable */
    .table-responsive {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        margin: 0 calc(-1 * var(--space-md));
        padding: 0 var(--space-md);
    }
    .standings-table {
        min-width: 700px;
    }
    /* Compact columns for landscape */
    .standings-table .col-event {
        width: 36px;
        font-size: var(--text-xs);
        padding: var(--space-xs);
    }
    .standings-table th.col-event {
        font-size: 0.65rem;
        padding: var(--space-xs);
    }
    .col-name, .col-club-name {
        white-space: nowrap;
        max-width: 120px;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .col-club {
        max-width: 80px;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .col-total {
        width: 50px;
        font-size: var(--text-sm);
    }
}
</style>

<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/point-calculations.php';
require_once __DIR__ . '/includes/class-calculations.php';

$db = getDB();

// Get series ID from URL
$seriesId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$seriesId) {
    // Redirect to series page if no ID provided
    header('Location: /series.php');
    exit;
}

// Fetch series details
$series = $db->getRow("
    SELECT s.*, COUNT(DISTINCT e.id) as event_count
    FROM series s
    LEFT JOIN events e ON s.id = e.series_id
    WHERE s.id = ?
    GROUP BY s.id
", [$seriesId]);

if (!$series) {
    header('Location: /series.php');
    exit;
}

// Get view mode (overall or class)
$viewMode = isset($_GET['view']) ? $_GET['view'] : 'class';
$selectedClass = isset($_GET['class']) ? $_GET['class'] : 'all';
$searchName = isset($_GET['search']) ? trim($_GET['search']) : '';

// Get all events in this series (using series_events junction table)
$seriesEvents = $db->getAll("
    SELECT
        e.id,
        e.name,
        e.date,
        e.location,
        e.organizer,
        v.name as venue_name,
        v.city as venue_city,
        COUNT(DISTINCT r.id) as result_count
    FROM series_events se
    JOIN events e ON se.event_id = e.id
    LEFT JOIN venues v ON e.venue_id = v.id
    LEFT JOIN results r ON e.id = r.event_id
    WHERE se.series_id = ?
    GROUP BY e.id
    ORDER BY se.sort_order, e.date ASC
", [$seriesId]);

// Get all classes that have results in this series
$activeClasses = $db->getAll("
    SELECT DISTINCT c.id, c.name, c.display_name, c.sort_order,
           COUNT(DISTINCT r.cyclist_id) as rider_count
    FROM classes c
    JOIN results r ON c.id = r.class_id
    JOIN events e ON r.event_id = e.id
    JOIN series_events se ON e.id = se.event_id
    WHERE se.series_id = ?
    GROUP BY c.id
    ORDER BY c.sort_order ASC
", [$seriesId]);

// Build standings with per-event points
$standings = [];
$showAllClasses = ($selectedClass === 'all');

if ($showAllClasses) {
    // Get all riders who have results in this series (all classes)
    $ridersInSeries = $db->getAll("
        SELECT DISTINCT
            riders.id,
            riders.firstname,
            riders.lastname,
            riders.birth_year,
            riders.gender,
            c.name as club_name,
            cls.display_name as class_name,
            r.class_id
        FROM riders
        LEFT JOIN clubs c ON riders.club_id = c.id
        JOIN results r ON riders.id = r.cyclist_id
        JOIN events e ON r.event_id = e.id
        JOIN series_events se ON e.id = se.event_id
        LEFT JOIN classes cls ON r.class_id = cls.id
        WHERE se.series_id = ?
        ORDER BY cls.sort_order ASC, riders.lastname, riders.firstname
    ", [$seriesId]);

    // For each rider, get their points from each event
    foreach ($ridersInSeries as $rider) {
        $riderData = [
            'rider_id' => $rider['id'],
            'firstname' => $rider['firstname'],
            'lastname' => $rider['lastname'],
            'fullname' => $rider['firstname'] . ' ' . $rider['lastname'],
            'birth_year' => $rider['birth_year'],
            'gender' => $rider['gender'],
            'club_name' => $rider['club_name'],
            'class_name' => $rider['class_name'] ?? 'Okänd',
            'class_id' => $rider['class_id'],
            'event_points' => [],
            'total_points' => 0
        ];

        // Get points for each event (using rider's class)
        foreach ($seriesEvents as $event) {
            $result = $db->getRow("
                SELECT points
                FROM results
                WHERE cyclist_id = ? AND event_id = ? AND class_id = ?
                LIMIT 1
            ", [$rider['id'], $event['id'], $rider['class_id']]);

            $points = $result ? (int)$result['points'] : 0;
            $riderData['event_points'][$event['id']] = $points;
            $riderData['total_points'] += $points;
        }

        // Apply name search filter
        if ($searchName === '' || stripos($riderData['fullname'], $searchName) !== false) {
            $standings[] = $riderData;
        }
    }

    // Sort by total points descending
    usort($standings, function($a, $b) {
        return $b['total_points'] - $a['total_points'];
    });
} elseif ($selectedClass && is_numeric($selectedClass)) {
    // Get all riders in this specific class who have results in this series
    $ridersInClass = $db->getAll("
        SELECT DISTINCT
            riders.id,
            riders.firstname,
            riders.lastname,
            riders.birth_year,
            riders.gender,
            c.name as club_name
        FROM riders
        LEFT JOIN clubs c ON riders.club_id = c.id
        JOIN results r ON riders.id = r.cyclist_id
        JOIN events e ON r.event_id = e.id
        JOIN series_events se ON e.id = se.event_id
        WHERE se.series_id = ?
          AND r.class_id = ?
        ORDER BY riders.lastname, riders.firstname
    ", [$seriesId, $selectedClass]);

    // For each rider, get their points from each event
    foreach ($ridersInClass as $rider) {
        $riderData = [
            'rider_id' => $rider['id'],
            'firstname' => $rider['firstname'],
            'lastname' => $rider['lastname'],
            'fullname' => $rider['firstname'] . ' ' . $rider['lastname'],
            'birth_year' => $rider['birth_year'],
            'gender' => $rider['gender'],
            'club_name' => $rider['club_name'],
            'event_points' => [],
            'total_points' => 0
        ];

        // Get points for each event
        foreach ($seriesEvents as $event) {
            $result = $db->getRow("
                SELECT points
                FROM results
                WHERE cyclist_id = ? AND event_id = ? AND class_id = ?
                LIMIT 1
            ", [$rider['id'], $event['id'], $selectedClass]);

            $points = $result ? (int)$result['points'] : 0;
            $riderData['event_points'][$event['id']] = $points;
            $riderData['total_points'] += $points;
        }

        // Apply name search filter
        if ($searchName === '' || stripos($riderData['fullname'], $searchName) !== false) {
            $standings[] = $riderData;
        }
    }

    // Sort by total points descending
    usort($standings, function($a, $b) {
        return $b['total_points'] - $a['total_points'];
    });
}

$pageTitle = $series['name'] . ' - Kvalpoäng & Ställning';
$pageType = 'public';
include __DIR__ . '/includes/layout-header.php';
?>

<style>
    .standings-table {
        font-size: 0.875rem;
    }

    .standings-table th,
    .standings-table td {
        padding: 0.5rem;
        white-space: nowrap;
    }

    .event-col {
        min-width: 50px;
        text-align: center !important;
    }

    .total-col {
        background: #f0fdf4;
        font-weight: bold;
        min-width: 70px;
    }

    @media (max-width: 768px) {
        .standings-table {
            font-size: 0.8125rem;
        }

        .standings-table th,
        .standings-table td {
            padding: 0.375rem 0.5rem;
        }

        .event-col {
            min-width: 45px;
        }

        .total-col {
            min-width: 60px;
        }
    }
</style>

<main class="gs-main-content">
    <div class="gs-container">
        <!-- Back Button -->
        <div class="gs-mb-lg">
            <a href="/series.php" class="gs-btn gs-btn-outline gs-btn-sm">
                <i data-lucide="arrow-left"></i>
                Tillbaka till serier
            </a>
        </div>

        <!-- Header -->
        <div class="gs-mb-xl">
            <h1 class="gs-h2 gs-text-primary gs-mb-sm">
                <i data-lucide="trophy"></i>
                <?= h($series['name']) ?> - Kvalpoäng & Ställning
            </h1>
            <?php if ($series['description']): ?>
                <p class="gs-text-secondary">
                    <?= h($series['description']) ?>
                </p>
            <?php endif; ?>
            <div class="gs-flex gs-gap-sm gs-mt-md">
                <span class="gs-badge gs-badge-primary">
                    <?= $series['year'] ?>
                </span>
                <span class="gs-badge gs-badge-secondary">
                    <?= count($seriesEvents) ?> tävlingar
                </span>
                <?php if ($series['count_best_results']): ?>
                    <span class="gs-badge gs-badge-info">
                        Räknar <?= $series['count_best_results'] ?> bästa resultat
                    </span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Events List -->
        <div class="gs-card gs-mb-xl">
            <div class="gs-card-header">
                <h3 class="gs-h4">
                    <i data-lucide="calendar"></i>
                    Tävlingar i serien (<?= count($seriesEvents) ?>)
                </h3>
            </div>
            <?php if (!empty($seriesEvents)): ?>
                <div class="gs-card-content gs-p-0">
                    <div class="gs-table-responsive">
                        <table class="gs-table">
                            <thead>
                                <tr>
                                    <th>Datum</th>
                                    <th>Tävling</th>
                                    <th>Plats</th>
                                    <th>Arrangör</th>
                                    <th class="gs-text-center">Resultat</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($seriesEvents as $event): ?>
                                    <tr>
                                        <td><?= date('Y-m-d', strtotime($event['date'])) ?></td>
                                        <td>
                                            <strong><?= h($event['name']) ?></strong>
                                            <?php if ($event['venue_name']): ?>
                                                <br><span class="gs-text-xs gs-text-secondary">
                                                    <?= h($event['venue_name']) ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($event['location']): ?>
                                                <?= h($event['location']) ?>
                                            <?php elseif ($event['venue_city']): ?>
                                                <?= h($event['venue_city']) ?>
                                            <?php else: ?>
                                                –
                                            <?php endif; ?>
                                        </td>
                                        <td><?= $event['organizer'] ? h($event['organizer']) : '–' ?></td>
                                        <td class="gs-text-center">
                                            <a href="/event.php?id=<?= $event['id'] ?>" class="gs-btn gs-btn-sm gs-btn-primary">
                                                <i data-lucide="list"></i>
                                                Se resultat (<?= $event['result_count'] ?>)
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php else: ?>
                <div class="gs-card-content gs-empty-state">
                    <i data-lucide="calendar-x" class="gs-empty-icon"></i>
                    <p class="gs-text-secondary">
                        Inga tävlingar har lagts till i denna serie ännu.
                    </p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Class Selector and Search -->
        <?php if (!empty($activeClasses)): ?>
            <div class="gs-card gs-mb-lg">
                <div class="gs-card-content">
                    <div class="gs-grid gs-grid-cols-1 gs-md-grid-cols-2 gs-gap-md">
                        <!-- Class Selector -->
                        <div>
                            <label class="gs-label">Välj klass</label>
                            <select class="gs-input" id="classSelector" onchange="window.location.href='?id=<?= $seriesId ?>&class=' + this.value + '&search=<?= urlencode($searchName) ?>'">
                                <option value="all" <?= $selectedClass === 'all' ? 'selected' : '' ?>>
                                    Alla klasser
                                </option>
                                <?php foreach ($activeClasses as $class): ?>
                                    <option value="<?= $class['id'] ?>" <?= $selectedClass == $class['id'] ? 'selected' : '' ?>>
                                        <?= h($class['display_name']) ?> - <?= h($class['name']) ?> (<?= $class['rider_count'] ?> deltagare)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Name Search -->
                        <div>
                            <label class="gs-label">Sök på namn</label>
                            <form method="get" action="" class="gs-flex gs-gap-sm">
                                <input type="hidden" name="id" value="<?= $seriesId ?>">
                                <input type="hidden" name="class" value="<?= $selectedClass ?>">
                                <input type="text" name="search" class="gs-input" placeholder="Skriv namn..." value="<?= h($searchName) ?>">
                                <button type="submit" class="gs-btn gs-btn-primary">
                                    <i data-lucide="search"></i>
                                </button>
                                <?php if ($searchName): ?>
                                    <a href="?id=<?= $seriesId ?>&class=<?= $selectedClass ?>" class="gs-btn gs-btn-outline">
                                        <i data-lucide="x"></i>
                                    </a>
                                <?php endif; ?>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Standings Table with Event Points -->
        <?php if (!empty($standings)): ?>
            <?php
            // Get selected class name
            $selectedClassName = '';
            $selectedClassDisplay = '';
            if ($showAllClasses) {
                $selectedClassDisplay = 'Alla klasser';
                $selectedClassName = '';
            } else {
                foreach ($activeClasses as $class) {
                    if ($class['id'] == $selectedClass) {
                        $selectedClassName = $class['name'];
                        $selectedClassDisplay = $class['display_name'];
                        break;
                    }
                }
            }
            ?>
            <div class="gs-card">
                <div class="gs-card-header">
                    <h3 class="gs-h4">
                        <i data-lucide="trophy"></i>
                        Kvalpoängställning: <?= h($selectedClassDisplay) ?><?= $selectedClassName ? ' - ' . h($selectedClassName) : '' ?>
                    </h3>
                    <?php if ($searchName): ?>
                        <p class="gs-text-sm gs-text-secondary">
                            Visar resultat för: <strong><?= h($searchName) ?></strong>
                        </p>
                    <?php endif; ?>
                </div>
                <div class="gs-card-table-container">
                    <table class="gs-table standings-table">
                        <thead>
                            <tr>
                                <th class="standings-sticky-th-rank">Plac.</th>
                                <th class="standings-sticky-th-name">Namn</th>
                                <?php if ($showAllClasses): ?>
                                    <th>Klass</th>
                                <?php endif; ?>
                                <th class="standings-sticky-th-club">Klubb</th>
                                <?php $eventNum = 1; ?>
                                <?php foreach ($seriesEvents as $event): ?>
                                    <th class="event-col" title="<?= h($event['name']) ?> - <?= date('Y-m-d', strtotime($event['date'])) ?>">
                                        #<?= $eventNum ?>
                                    </th>
                                    <?php $eventNum++; ?>
                                <?php endforeach; ?>
                                <th class="total-col gs-text-center">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $position = 1; ?>
                            <?php foreach ($standings as $rider): ?>
                                <tr>
                                    <td class="standings-sticky-td-rank">
                                        <?php if ($position == 1): ?>
                                            <span class="gs-badge gs-badge-success gs-badge-xs">🥇 1</span>
                                        <?php elseif ($position == 2): ?>
                                            <span class="gs-badge gs-badge-secondary gs-badge-xs">🥈 2</span>
                                        <?php elseif ($position == 3): ?>
                                            <span class="gs-badge gs-badge-warning gs-badge-xs">🥉 3</span>
                                        <?php else: ?>
                                            <?= $position ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="standings-sticky-td-name">
                                        <a href="/rider.php?id=<?= $rider['rider_id'] ?>" class="gs-link">
                                            <strong><?= h($rider['firstname']) ?> <?= h($rider['lastname']) ?></strong>
                                        </a>
                                    </td>
                                    <?php if ($showAllClasses): ?>
                                        <td class="gs-text-secondary">
                                            <?= h($rider['class_name'] ?? '–') ?>
                                        </td>
                                    <?php endif; ?>
                                    <td class="standings-sticky-td-club">
                                        <?= h($rider['club_name']) ?: '–' ?>
                                    </td>
                                    <?php foreach ($seriesEvents as $event): ?>
                                        <td class="event-col">
                                            <?php
                                            $points = $rider['event_points'][$event['id']] ?? 0;
                                            if ($points > 0):
                                            ?>
                                                <?= $points ?>
                                            <?php else: ?>
                                                <span class="gs-text-muted">–</span>
                                            <?php endif; ?>
                                        </td>
                                    <?php endforeach; ?>
                                    <td class="total-col gs-text-center">
                                        <strong class="gs-text-primary"><?= $rider['total_points'] ?></strong>
                                    </td>
                                </tr>
                                <?php $position++; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php elseif (empty($standings)): ?>
            <div class="gs-card">
                <div class="gs-card-content gs-empty-state">
                    <i data-lucide="inbox" class="gs-empty-icon"></i>
                    <p class="gs-text-secondary">
                        <?php if ($searchName): ?>
                            Inga resultat hittades för "<?= h($searchName) ?>"
                        <?php else: ?>
                            Inga resultat ännu
                        <?php endif; ?>
                    </p>
                </div>
            </div>
        <?php endif; ?>
    </div>
</main>

<?php include __DIR__ . '/includes/layout-footer.php'; ?>

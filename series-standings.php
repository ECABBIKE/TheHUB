<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/point-calculations.php';
require_once __DIR__ . '/includes/class-calculations.php';

$db = getDB();

// Get series ID from URL
$seriesId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$seriesId) {
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
$selectedClass = isset($_GET['class']) ? (int)$_GET['class'] : null;
$searchName = isset($_GET['search']) ? trim($_GET['search']) : '';

// Get all events in this series (chronological order)
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
    FROM events e
    LEFT JOIN venues v ON e.venue_id = v.id
    LEFT JOIN results r ON e.id = r.event_id
    WHERE e.series_id = ?
    GROUP BY e.id
    ORDER BY e.date ASC
", [$seriesId]);

// Get all classes that have results in this series
$activeClasses = $db->getAll("
    SELECT DISTINCT c.id, c.name, c.display_name, c.sort_order,
           COUNT(DISTINCT r.cyclist_id) as rider_count
    FROM classes c
    JOIN results r ON c.id = r.class_id
    JOIN events e ON r.event_id = e.id
    WHERE e.series_id = ? AND c.active = 1
    GROUP BY c.id
    ORDER BY c.sort_order ASC
", [$seriesId]);

// Set default class if not selected
if (!$selectedClass && !empty($activeClasses)) {
    $selectedClass = $activeClasses[0]['id'];
}

// Build standings with per-event points
$standings = [];
if ($selectedClass) {
    // Get all riders in this class who have results in this series
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
        WHERE e.series_id = ?
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

$pageTitle = $series['name'] . ' - Ställning';
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
            font-size: 0.75rem;
        }

        .standings-table th,
        .standings-table td {
            padding: 0.25rem;
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
                <?= h($series['name']) ?>
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
                    <?= $series['event_count'] ?> tävlingar
                </span>
                <?php if ($series['count_best_results']): ?>
                    <span class="gs-badge gs-badge-info">
                        Räknar <?= $series['count_best_results'] ?> bästa resultat
                    </span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Events List -->
        <?php if (!empty($seriesEvents)): ?>
            <div class="gs-card gs-mb-xl">
                <div class="gs-card-header">
                    <h3 class="gs-h4">
                        <i data-lucide="calendar"></i>
                        Tävlingar i serien
                    </h3>
                </div>
                <div class="gs-card-content" style="padding: 0;">
                    <div class="gs-table-responsive">
                        <table class="gs-table">
                            <thead>
                                <tr>
                                    <th>Datum</th>
                                    <th>Tävling</th>
                                    <th>Plats</th>
                                    <th>Arrangör</th>
                                    <th style="text-align: center;">Resultat</th>
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
                                        <td style="text-align: center;">
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
            </div>
        <?php endif; ?>

        <!-- Class Selector and Search -->
        <?php if (!empty($activeClasses)): ?>
            <div class="gs-card gs-mb-lg">
                <div class="gs-card-content">
                    <div class="gs-grid gs-grid-cols-1 gs-md-grid-cols-2 gs-gap-md">
                        <!-- Class Selector -->
                        <div>
                            <label class="gs-label">Välj klass</label>
                            <select class="gs-input" id="classSelector" onchange="window.location.href='?id=<?= $seriesId ?>&class=' + this.value + '&search=<?= urlencode($searchName) ?>'">
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
                            <form method="get" action="" style="display: flex; gap: 0.5rem;">
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
        <?php if ($selectedClass && !empty($standings)): ?>
            <?php
            // Get selected class name
            $selectedClassName = '';
            $selectedClassDisplay = '';
            foreach ($activeClasses as $class) {
                if ($class['id'] == $selectedClass) {
                    $selectedClassName = $class['name'];
                    $selectedClassDisplay = $class['display_name'];
                    break;
                }
            }
            ?>
            <div class="gs-card">
                <div class="gs-card-header">
                    <h3 class="gs-h4">
                        <i data-lucide="trophy"></i>
                        <?= h($selectedClassDisplay) ?> - <?= h($selectedClassName) ?>
                    </h3>
                    <?php if ($searchName): ?>
                        <p class="gs-text-sm gs-text-secondary">
                            Visar resultat för: <strong><?= h($searchName) ?></strong>
                        </p>
                    <?php endif; ?>
                </div>
                <div class="gs-card-content" style="padding: 0; overflow-x: auto;">
                    <table class="gs-table standings-table">
                        <thead>
                            <tr>
                                <th style="position: sticky; left: 0; background: white; z-index: 2;">Plac.</th>
                                <th style="position: sticky; left: 60px; background: white; z-index: 2;">Namn</th>
                                <th style="position: sticky; left: 250px; background: white; z-index: 2;">Klubb</th>
                                <?php $eventNum = 1; ?>
                                <?php foreach ($seriesEvents as $event): ?>
                                    <th class="event-col" title="<?= h($event['name']) ?> - <?= date('Y-m-d', strtotime($event['date'])) ?>">
                                        #<?= $eventNum ?>
                                    </th>
                                    <?php $eventNum++; ?>
                                <?php endforeach; ?>
                                <th class="total-col" style="text-align: center;">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $position = 1; ?>
                            <?php foreach ($standings as $rider): ?>
                                <tr>
                                    <td style="position: sticky; left: 0; background: white;">
                                        <?php if ($position == 1): ?>
                                            <span class="gs-badge gs-badge-success" style="font-size: 0.75rem;">🥇 1</span>
                                        <?php elseif ($position == 2): ?>
                                            <span class="gs-badge gs-badge-secondary" style="font-size: 0.75rem;">🥈 2</span>
                                        <?php elseif ($position == 3): ?>
                                            <span class="gs-badge gs-badge-warning" style="font-size: 0.75rem;">🥉 3</span>
                                        <?php else: ?>
                                            <?= $position ?>
                                        <?php endif; ?>
                                    </td>
                                    <td style="position: sticky; left: 60px; background: white;">
                                        <a href="/rider.php?id=<?= $rider['rider_id'] ?>" class="gs-link">
                                            <strong><?= h($rider['firstname']) ?> <?= h($rider['lastname']) ?></strong>
                                        </a>
                                    </td>
                                    <td style="position: sticky; left: 250px; background: white;">
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
                                                <span style="color: #ccc;">–</span>
                                            <?php endif; ?>
                                        </td>
                                    <?php endforeach; ?>
                                    <td class="total-col" style="text-align: center;">
                                        <strong class="gs-text-primary"><?= $rider['total_points'] ?></strong>
                                    </td>
                                </tr>
                                <?php $position++; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php elseif ($selectedClass && empty($standings)): ?>
            <div class="gs-card">
                <div class="gs-card-content" style="padding: 3rem; text-align: center;">
                    <i data-lucide="inbox" style="width: 48px; height: 48px; margin: 0 auto 1rem; opacity: 0.3;"></i>
                    <p class="gs-text-secondary">
                        <?php if ($searchName): ?>
                            Inga resultat hittades för "<?= h($searchName) ?>"
                        <?php else: ?>
                            Inga resultat för denna klass
                        <?php endif; ?>
                    </p>
                </div>
            </div>
        <?php endif; ?>
    </div>
</main>

<?php include __DIR__ . '/includes/layout-footer.php'; ?>

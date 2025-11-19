<?php
require_once __DIR__ . '/config.php';

$db = getDB();

// Get event ID from URL
$eventId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$eventId) {
    header('Location: /events.php');
    exit;
}

// Fetch event details
$event = $db->getRow("
    SELECT
        e.*,
        s.name as series_name,
        s.logo as series_logo,
        v.name as venue_name,
        v.city as venue_city,
        v.address as venue_address
    FROM events e
    LEFT JOIN series s ON e.series_id = s.id
    LEFT JOIN venues v ON e.venue_id = v.id
    WHERE e.id = ?
", [$eventId]);

if (!$event) {
    header('Location: /events.php');
    exit;
}

// Check event format to determine display mode
$eventFormat = $event['event_format'] ?? 'ENDURO';
$isDH = in_array($eventFormat, ['DH_STANDARD', 'DH_SWECUP']);

// Fetch all results for this event with rider and class info
$results = $db->getAll("
    SELECT
        res.*,
        r.firstname,
        r.lastname,
        r.gender,
        r.birth_year,
        r.license_number,
        c.name as club_name,
        cls.name as class_name,
        cls.display_name as class_display_name,
        cls.sort_order as class_sort_order
    FROM results res
    INNER JOIN riders r ON res.cyclist_id = r.id
    LEFT JOIN clubs c ON r.club_id = c.id
    LEFT JOIN classes cls ON res.class_id = cls.id
    WHERE res.event_id = ?
    ORDER BY
        cls.sort_order ASC,
        COALESCE(cls.name, 'Oklassificerad'),
        CASE WHEN res.status = 'finished' THEN res.class_position ELSE 999 END,
        res.finish_time
", [$eventId]);

// Group results by class
$resultsByClass = [];
$totalParticipants = count($results);
$totalFinished = 0;

foreach ($results as $result) {
    // Group by class
    $className = $result['class_name'] ?? 'Oklassificerad';

    if (!isset($resultsByClass[$className])) {
        $resultsByClass[$className] = [
            'display_name' => $result['class_display_name'] ?? $className,
            'sort_order' => $result['class_sort_order'] ?? 999,
            'results' => []
        ];
    }

    $resultsByClass[$className]['results'][] = $result;

    if ($result['status'] === 'finished') {
        $totalFinished++;
    }
}

// Sort classes by their sort_order
uksort($resultsByClass, function($a, $b) use ($resultsByClass) {
    return $resultsByClass[$a]['sort_order'] - $resultsByClass[$b]['sort_order'];
});

// Calculate time behind leader for each class
foreach ($resultsByClass as $className => &$classData) {
    $winnerTime = null;

    // Find winner's time
    foreach ($classData['results'] as $result) {
        if ($result['class_position'] == 1 && !empty($result['finish_time']) && $result['status'] === 'finished') {
            $winnerTime = $result['finish_time'];
            break;
        }
    }

    // Calculate time behind for each result
    foreach ($classData['results'] as &$result) {
        if ($winnerTime && !empty($result['finish_time']) && $result['status'] === 'finished' && $result['class_position'] > 1) {
            $winnerSeconds = strtotime("1970-01-01 $winnerTime UTC");
            $riderSeconds = strtotime("1970-01-01 {$result['finish_time']} UTC");
            $diffSeconds = $riderSeconds - $winnerSeconds;

            $hours = floor($diffSeconds / 3600);
            $minutes = floor(($diffSeconds % 3600) / 60);
            $seconds = $diffSeconds % 60;

            if ($hours > 0) {
                $result['time_behind_formatted'] = sprintf('+%d:%02d:%02d', $hours, $minutes, $seconds);
            } else {
                $result['time_behind_formatted'] = sprintf('+%d:%02d', $minutes, $seconds);
            }
        } else {
            $result['time_behind_formatted'] = null;
        }
    }
}
unset($classData); // Break reference

$pageTitle = $event['name'];
$pageType = 'public';
include __DIR__ . '/includes/layout-header.php';
?>

    <main class="gs-main-content">
        <div class="gs-container">

            <!-- Event Header -->
            <div class="gs-card gs-mb-xl">
                <div class="gs-card-content event-header-content">
                    <!-- Back Button -->
                    <div class="gs-mb-lg">
                        <a href="/events.php" class="gs-btn gs-btn-outline gs-btn-sm">
                            <i data-lucide="arrow-left" class="gs-icon-md"></i>
                            Tillbaka till tävlingar
                        </a>
                    </div>

                    <div class="event-header-layout">
                        <?php if ($event['series_logo']): ?>
                            <div class="event-logo">
                                <img src="<?= h($event['series_logo']) ?>"
                                     alt="<?= h($event['series_name'] ?? 'Serie') ?>">
                            </div>
                        <?php endif; ?>

                        <div class="event-info">
                            <h1 class="gs-h1 gs-text-primary gs-mb-sm event-title">
                                <?= h($event['name']) ?>
                            </h1>

                            <div class="gs-flex gs-gap-md gs-flex-wrap gs-mb-md event-meta">
                                <div class="gs-flex gs-items-center gs-gap-xs">
                                    <i data-lucide="calendar" class="gs-icon-md"></i>
                                    <span class="gs-text-secondary">
                                        <?= date('l j F Y', strtotime($event['date'])) ?>
                                    </span>
                                </div>

                                <?php if ($event['venue_name']): ?>
                                    <div class="gs-flex gs-items-center gs-gap-xs">
                                        <i data-lucide="map-pin" class="gs-icon-md"></i>
                                        <span class="gs-text-secondary">
                                            <?= h($event['venue_name']) ?>
                                            <?php if ($event['venue_city']): ?>
                                                , <?= h($event['venue_city']) ?>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                <?php elseif ($event['location']): ?>
                                    <div class="gs-flex gs-items-center gs-gap-xs">
                                        <i data-lucide="map-pin" class="gs-icon-md"></i>
                                        <span class="gs-text-secondary"><?= h($event['location']) ?></span>
                                    </div>
                                <?php endif; ?>

                                <?php if ($event['series_name']): ?>
                                    <div class="gs-flex gs-items-center gs-gap-xs">
                                        <i data-lucide="award" class="gs-icon-md"></i>
                                        <span class="gs-badge gs-badge-primary">
                                            <?= h($event['series_name']) ?>
                                        </span>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Event Stats -->
                            <div class="event-stats">
                                <div class="event-stat-full">
                                    <span class="gs-text-sm gs-text-secondary">Deltagare: </span>
                                    <strong class="gs-text-primary"><?= $totalParticipants ?></strong>
                                </div>
                                <div class="event-stat-half">
                                    <span class="gs-text-sm gs-text-secondary">Slutförda: </span>
                                    <strong class="gs-text-success"><?= $totalFinished ?></strong>
                                </div>
                                <div class="event-stat-half">
                                    <span class="gs-text-sm gs-text-secondary">Klasser: </span>
                                    <strong class="gs-text-primary"><?= count($resultsByClass) ?></strong>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (empty($results)): ?>
                <!-- No Results -->
                <div class="gs-card gs-empty-state">
                    <i data-lucide="trophy" class="gs-empty-icon"></i>
                    <h3 class="gs-h4 gs-mb-sm">Inga resultat ännu</h3>
                    <p class="gs-text-secondary">
                        Resultat har inte laddats upp för denna tävling.
                    </p>
                </div>
            <?php else: ?>
                <!-- Results by Class -->
                <?php foreach ($resultsByClass as $groupName => $groupData): ?>
                    <div class="gs-card gs-mb-xl class-section"
                         data-group="<?= h($groupName) ?>">
                        <div class="gs-card-header">
                            <h2 class="gs-h4 gs-text-primary">
                                <i data-lucide="users" class="gs-icon-md"></i>
                                <?= h($groupData['display_name']) ?>
                                <span class="gs-badge gs-badge-primary gs-badge-sm gs-ml-xs">
                                    <?= h($groupName) ?>
                                </span>
                                <span class="gs-badge gs-badge-secondary gs-ml-sm">
                                    <?= count($groupData['results']) ?> deltagare
                                </span>
                            </h2>
                        </div>
                        <div class="gs-card-content gs-card-table-container">
                            <table class="gs-table results-table">
                                <thead>
                                    <tr>
                                        <th class="gs-table-col-narrow" data-sort="position">
                                            <span>
                                                Plac.
                                                <i data-lucide="arrow-up-down" class="gs-icon-sm"></i>
                                            </span>
                                        </th>
                                        <th data-sort="name">
                                            <span>Namn <i data-lucide="arrow-up-down" class="gs-icon-sm"></i></span>
                                        </th>
                                        <th data-sort="club">
                                            <span>Klubb <i data-lucide="arrow-up-down" class="gs-icon-sm"></i></span>
                                        </th>
                                        <th class="gs-table-col-medium">Startnr</th>
                                        <?php if ($isDH): ?>
                                            <th class="gs-table-col-wide" data-sort="run1">
                                                <span>Åk 1 <i data-lucide="arrow-up-down" class="gs-icon-sm"></i></span>
                                            </th>
                                            <th class="gs-table-col-wide" data-sort="run2">
                                                <span>Åk 2 <i data-lucide="arrow-up-down" class="gs-icon-sm"></i></span>
                                            </th>
                                        <?php else: ?>
                                            <th class="gs-table-col-wide" data-sort="time">
                                                <span>Tid <i data-lucide="arrow-up-down" class="gs-icon-sm"></i></span>
                                            </th>
                                        <?php endif; ?>
                                        <th class="gs-table-col-medium">+Tid</th>
                                        <th class="gs-table-col-narrow" data-sort="points">
                                            <span>Poäng <i data-lucide="arrow-up-down" class="gs-icon-sm"></i></span>
                                        </th>
                                        <th class="gs-table-col-medium">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($groupData['results'] as $result): ?>
                                        <tr class="result-row"
                                            data-position="<?= $result['position'] ?? 999 ?>"
                                            data-name="<?= h($result['lastname'] . ' ' . $result['firstname']) ?>"
                                            data-club="<?= h($result['club_name'] ?? '') ?>"
                                            data-time="<?= $result['finish_time'] ?? '' ?>"
                                            data-points="<?= $result['points'] ?? 0 ?>">

                                            <!-- Position (Class) -->
                                            <td class="gs-table-center gs-font-bold">
                                                <?php if ($result['status'] === 'finished' && $result['class_position']): ?>
                                                    <?php if ($result['class_position'] == 1): ?>
                                                        <span class="gs-medal">🥇</span>
                                                    <?php elseif ($result['class_position'] == 2): ?>
                                                        <span class="gs-medal">🥈</span>
                                                    <?php elseif ($result['class_position'] == 3): ?>
                                                        <span class="gs-medal">🥉</span>
                                                    <?php else: ?>
                                                        <?= $result['class_position'] ?>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="gs-text-secondary">-</span>
                                                <?php endif; ?>
                                            </td>

                                            <!-- Name -->
                                            <td>
                                                <a href="/rider.php?id=<?= $result['cyclist_id'] ?>"
                                                   class="gs-rider-link">
                                                    <?= h($result['firstname']) ?> <?= h($result['lastname']) ?>
                                                </a>
                                                <div class="gs-rider-meta">
                                                    <?php if ($result['birth_year']): ?>
                                                        <?= calculateAge($result['birth_year']) ?> år
                                                    <?php endif; ?>
                                                    <?php if ($result['gender']): ?>
                                                        • <?= $result['gender'] == 'M' ? 'Herr' : ($result['gender'] == 'F' ? 'Dam' : '') ?>
                                                    <?php endif; ?>
                                                </div>
                                            </td>

                                            <!-- Club -->
                                            <td>
                                                <?php if ($result['club_name']): ?>
                                                    <span class="gs-badge gs-badge-secondary gs-badge-sm">
                                                        <?= h($result['club_name']) ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="gs-text-secondary">-</span>
                                                <?php endif; ?>
                                            </td>

                                            <!-- Bib Number -->
                                            <td class="gs-table-center">
                                                <?= $result['bib_number'] ? h($result['bib_number']) : '<span class="gs-text-secondary">-</span>' ?>
                                            </td>

                                            <?php if ($isDH): ?>
                                                <?php
                                                // Determine fastest run
                                                $run1IsFastest = false;
                                                $run2IsFastest = false;
                                                if ($result['run_1_time'] && $result['run_2_time']) {
                                                    $run1Seconds = strtotime("1970-01-01 {$result['run_1_time']} UTC");
                                                    $run2Seconds = strtotime("1970-01-01 {$result['run_2_time']} UTC");
                                                    if ($run1Seconds < $run2Seconds) {
                                                        $run1IsFastest = true;
                                                    } elseif ($run2Seconds < $run1Seconds) {
                                                        $run2IsFastest = true;
                                                    }
                                                }
                                                ?>
                                                <!-- DH Run 1 Time -->
                                                <td class="gs-table-time-cell">
                                                    <?php if ($result['run_1_time'] && $result['status'] === 'finished'): ?>
                                                        <span class="<?= $run1IsFastest ? 'gs-table-fastest' : '' ?>">
                                                            <?= h($result['run_1_time']) ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="gs-text-secondary">-</span>
                                                    <?php endif; ?>
                                                </td>

                                                <!-- DH Run 2 Time -->
                                                <td class="gs-table-time-cell">
                                                    <?php if ($result['run_2_time'] && $result['status'] === 'finished'): ?>
                                                        <span class="<?= $run2IsFastest ? 'gs-table-fastest' : '' ?>">
                                                            <?= h($result['run_2_time']) ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="gs-text-secondary">-</span>
                                                    <?php endif; ?>
                                                </td>
                                            <?php else: ?>
                                                <!-- Standard Finish Time -->
                                                <td class="gs-table-time-cell">
                                                    <?php if ($result['finish_time'] && $result['status'] === 'finished'): ?>
                                                        <?= h($result['finish_time']) ?>
                                                    <?php else: ?>
                                                        <span class="gs-text-secondary">-</span>
                                                    <?php endif; ?>
                                                </td>
                                            <?php endif; ?>

                                            <!-- Time Behind -->
                                            <td class="gs-table-center gs-table-mono gs-text-secondary">
                                                <?= $result['time_behind_formatted'] ?? '<span class="gs-text-secondary">-</span>' ?>
                                            </td>

                                            <!-- Points (Class) -->
                                            <td class="gs-table-center gs-font-bold">
                                                <?= $result['class_points'] ?? 0 ?>
                                            </td>

                                            <!-- Status -->
                                            <td class="gs-table-center">
                                                <?php
                                                $statusBadge = 'gs-badge-success';
                                                $statusText = 'OK';
                                                if ($result['status'] === 'dnf') {
                                                    $statusBadge = 'gs-badge-danger';
                                                    $statusText = 'DNF';
                                                } elseif ($result['status'] === 'dns') {
                                                    $statusBadge = 'gs-badge-secondary';
                                                    $statusText = 'DNS';
                                                } elseif ($result['status'] === 'dq') {
                                                    $statusBadge = 'gs-badge-danger';
                                                    $statusText = 'DQ';
                                                }
                                                ?>
                                                <span class="gs-badge <?= $statusBadge ?> gs-badge-sm">
                                                    <?= $statusText ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </main>

<?php
$additionalScripts = <<<'SCRIPT'
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Table sorting functionality
    const tables = document.querySelectorAll('.results-table');

    tables.forEach(table => {
        const headers = table.querySelectorAll('th[data-sort]');

        headers.forEach(header => {
            header.style.cursor = 'pointer';
            header.addEventListener('click', function() {
                const sortKey = this.getAttribute('data-sort');
                const tbody = table.querySelector('tbody');
                const rows = Array.from(tbody.querySelectorAll('tr'));

                // Determine sort direction
                const currentDir = this.getAttribute('data-sort-dir') || 'asc';
                const newDir = currentDir === 'asc' ? 'desc' : 'asc';

                // Reset all headers
                headers.forEach(h => {
                    h.removeAttribute('data-sort-dir');
                    const icon = h.querySelector('i[data-lucide]');
                    if (icon) icon.setAttribute('data-lucide', 'arrow-up-down');
                });

                // Set current header
                this.setAttribute('data-sort-dir', newDir);
                const icon = this.querySelector('i[data-lucide]');
                if (icon) icon.setAttribute('data-lucide', newDir === 'asc' ? 'arrow-up' : 'arrow-down');

                // Sort rows
                rows.sort((a, b) => {
                    let aVal, bVal;

                    if (sortKey === 'position') {
                        aVal = parseInt(a.getAttribute('data-position')) || 999;
                        bVal = parseInt(b.getAttribute('data-position')) || 999;
                    } else if (sortKey === 'points') {
                        aVal = parseInt(a.getAttribute('data-points')) || 0;
                        bVal = parseInt(b.getAttribute('data-points')) || 0;
                    } else if (sortKey === 'time') {
                        aVal = a.getAttribute('data-time') || 'ZZZ';
                        bVal = b.getAttribute('data-time') || 'ZZZ';
                    } else {
                        aVal = a.getAttribute('data-' + sortKey).toLowerCase();
                        bVal = b.getAttribute('data-' + sortKey).toLowerCase();
                    }

                    if (aVal < bVal) return newDir === 'asc' ? -1 : 1;
                    if (aVal > bVal) return newDir === 'asc' ? 1 : -1;
                    return 0;
                });

                // Reorder DOM
                rows.forEach(row => tbody.appendChild(row));

                // Reinitialize Lucide icons
                if (typeof lucide !== 'undefined') {
                    lucide.createIcons();
                }
            });
        });
    });
});
</script>
SCRIPT;

include __DIR__ . '/includes/layout-footer.php';
?>

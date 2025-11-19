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

// Get view mode (category or class)
$viewMode = isset($_GET['view']) ? $_GET['view'] : 'category';

// Fetch all results for this event with rider, category and class info
$results = $db->getAll("
    SELECT
        res.*,
        r.firstname,
        r.lastname,
        r.gender,
        r.birth_year,
        r.license_number,
        c.name as club_name,
        cat.name as category_name,
        cat.short_name as category_short,
        cls.name as class_name,
        cls.display_name as class_display_name
    FROM results res
    INNER JOIN riders r ON res.cyclist_id = r.id
    LEFT JOIN clubs c ON r.club_id = c.id
    LEFT JOIN categories cat ON res.category_id = cat.id
    LEFT JOIN classes cls ON res.class_id = cls.id
    WHERE res.event_id = ?
    ORDER BY
        COALESCE(cat.name, 'Okategoriserad'),
        CASE WHEN res.status = 'finished' THEN res.position ELSE 999 END,
        res.finish_time
", [$eventId]);

// Group results by category
$resultsByCategory = [];
$resultsByClass = [];
$totalParticipants = count($results);
$totalFinished = 0;

foreach ($results as $result) {
    // Group by category
    $categoryName = $result['category_name'] ?? 'Okategoriserad';

    if (!isset($resultsByCategory[$categoryName])) {
        $resultsByCategory[$categoryName] = [
            'short_name' => $result['category_short'] ?? 'N/A',
            'results' => []
        ];
    }

    $resultsByCategory[$categoryName]['results'][] = $result;

    // Group by class (if class is assigned)
    if (!empty($result['class_name'])) {
        $className = $result['class_name'];

        if (!isset($resultsByClass[$className])) {
            $resultsByClass[$className] = [
                'display_name' => $result['class_display_name'] ?? $className,
                'results' => []
            ];
        }

        $resultsByClass[$className]['results'][] = $result;
    }

    if ($result['status'] === 'finished') {
        $totalFinished++;
    }
}

// Sort classes by their sort_order if possible
if (!empty($resultsByClass)) {
    uksort($resultsByClass, function($a, $b) use ($db) {
        $classA = $db->getRow("SELECT sort_order FROM classes WHERE name = ?", [$a]);
        $classB = $db->getRow("SELECT sort_order FROM classes WHERE name = ?", [$b]);
        return ($classA['sort_order'] ?? 999) - ($classB['sort_order'] ?? 999);
    });
}

// Calculate time behind leader for each category
foreach ($resultsByCategory as $categoryName => &$categoryData) {
    $winnerTime = null;

    // Find winner's time
    foreach ($categoryData['results'] as $result) {
        if ($result['position'] == 1 && !empty($result['finish_time']) && $result['status'] === 'finished') {
            $winnerTime = $result['finish_time'];
            break;
        }
    }

    // Calculate time behind for each result
    foreach ($categoryData['results'] as &$result) {
        if ($winnerTime && !empty($result['finish_time']) && $result['status'] === 'finished' && $result['position'] > 1) {
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
unset($categoryData); // Break reference

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
                                    <span class="gs-text-sm gs-text-secondary">Kategorier: </span>
                                    <strong class="gs-text-primary"><?= count($resultsByCategory) ?></strong>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Event Information Section -->
            <?php if (!empty($event['description']) || !empty($event['website']) || !empty($event['registration_url']) || !empty($event['organizer'])): ?>
                <div class="gs-card gs-mb-xl">
                    <div class="gs-card-header">
                        <h2 class="gs-h3 gs-text-primary">
                            <i data-lucide="info"></i>
                            Event-information
                        </h2>
                    </div>
                    <div class="gs-card-content">
                        <div class="gs-grid gs-grid-cols-1 gs-md-grid-cols-2 gs-gap-lg">
                            <!-- Left Column -->
                            <div>
                                <?php if (!empty($event['description'])): ?>
                                    <div class="gs-mb-lg">
                                        <h3 class="gs-h5 gs-mb-sm gs-text-primary">
                                            <i data-lucide="file-text" class="gs-icon-14"></i>
                                            Beskrivning
                                        </h3>
                                        <p class="gs-text-secondary">
                                            <?= nl2br(h($event['description'])) ?>
                                        </p>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($event['distance']) || !empty($event['elevation_gain'])): ?>
                                    <div class="gs-mb-lg">
                                        <h3 class="gs-h5 gs-mb-sm gs-text-primary">
                                            <i data-lucide="mountain" class="gs-icon-14"></i>
                                            Bana-info
                                        </h3>
                                        <div class="gs-flex gs-gap-md">
                                            <?php if (!empty($event['distance'])): ?>
                                                <div>
                                                    <span class="gs-text-sm gs-text-secondary">Distans: </span>
                                                    <strong><?= $event['distance'] ?> km</strong>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (!empty($event['elevation_gain'])): ?>
                                                <div>
                                                    <span class="gs-text-sm gs-text-secondary">Höjdmeter: </span>
                                                    <strong><?= $event['elevation_gain'] ?> m</strong>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($event['organizer'])): ?>
                                    <div class="gs-mb-lg">
                                        <h3 class="gs-h5 gs-mb-sm gs-text-primary">
                                            <i data-lucide="user" class="gs-icon-14"></i>
                                            Arrangör
                                        </h3>
                                        <p class="gs-text-secondary">
                                            <?= h($event['organizer']) ?>
                                        </p>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Right Column -->
                            <div>
                                <?php if (!empty($event['registration_url']) || !empty($event['registration_deadline'])): ?>
                                    <div class="gs-alert gs-alert-primary gs-mb-lg">
                                        <h3 class="gs-h5 gs-mb-sm">
                                            <i data-lucide="user-plus" class="gs-icon-14"></i>
                                            Anmälan
                                        </h3>
                                        <?php if (!empty($event['registration_deadline'])): ?>
                                            <p class="gs-text-sm gs-mb-sm">
                                                <strong>Sista anmälan:</strong> <?= date('d M Y', strtotime($event['registration_deadline'])) ?>
                                            </p>
                                        <?php endif; ?>
                                        <?php if (!empty($event['registration_url'])): ?>
                                            <a href="<?= h($event['registration_url']) ?>"
                                               target="_blank"
                                               rel="noopener noreferrer"
                                               class="gs-btn gs-btn-primary gs-btn-sm gs-w-full">
                                                <i data-lucide="external-link" class="gs-icon-14"></i>
                                                Anmäl dig här
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($event['entry_fee']) || !empty($event['max_participants'])): ?>
                                    <div class="gs-mb-lg">
                                        <h3 class="gs-h5 gs-mb-sm gs-text-primary">
                                            <i data-lucide="info" class="gs-icon-14"></i>
                                            Praktisk information
                                        </h3>
                                        <?php if (!empty($event['entry_fee'])): ?>
                                            <p class="gs-text-sm gs-mb-xs">
                                                <strong>Startavgift:</strong> <?= $event['entry_fee'] ?> kr
                                            </p>
                                        <?php endif; ?>
                                        <?php if (!empty($event['max_participants'])): ?>
                                            <p class="gs-text-sm gs-mb-xs">
                                                <strong>Max deltagare:</strong> <?= $event['max_participants'] ?>
                                            </p>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($event['website'])): ?>
                                    <div>
                                        <a href="<?= h($event['website']) ?>"
                                           target="_blank"
                                           rel="noopener noreferrer"
                                           class="gs-btn gs-btn-outline gs-w-full">
                                            <i data-lucide="globe" class="gs-icon-14"></i>
                                            Event-webbplats
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

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
                <!-- View Mode Tabs (if classes are enabled) -->
                <?php if ($event['enable_classes'] && !empty($resultsByClass)): ?>
                    <div class="gs-tabs gs-mb-lg">
                        <a href="?id=<?= $eventId ?>&view=category"
                           class="gs-tab <?= $viewMode === 'category' ? 'active' : '' ?>">
                            <i data-lucide="folder"></i>
                            Kategorier
                            <span class="gs-badge gs-badge-secondary gs-badge-sm gs-ml-xs">
                                <?= count($resultsByCategory) ?>
                            </span>
                        </a>
                        <a href="?id=<?= $eventId ?>&view=class"
                           class="gs-tab <?= $viewMode === 'class' ? 'active' : '' ?>">
                            <i data-lucide="users"></i>
                            Klasser
                            <span class="gs-badge gs-badge-secondary gs-badge-sm gs-ml-xs">
                                <?= count($resultsByClass) ?>
                            </span>
                        </a>
                    </div>
                <?php endif; ?>

                <!-- Results by Category or Class -->
                <?php if ($viewMode === 'class' && !empty($resultsByClass)): ?>
                    <!-- Results by Class -->
                    <?php $resultsToShow = $resultsByClass; ?>
                    <?php $isClassView = true; ?>
                <?php else: ?>
                    <!-- Results by Category --><?php $resultsToShow = $resultsByCategory; ?>
                    <?php $isClassView = false; ?>
                <?php endif; ?>
                <?php foreach ($resultsToShow as $groupName => $groupData): ?>
                    <div class="gs-card gs-mb-xl <?= $isClassView ? 'class-section' : 'category-section' ?>"
                         data-group="<?= h($groupName) ?>">
                        <div class="gs-card-header">
                            <h2 class="gs-h4 gs-text-primary">
                                <i data-lucide="users" class="gs-icon-md"></i>
                                <?php if ($isClassView): ?>
                                    <?= h($groupData['display_name']) ?>
                                    <span class="gs-badge gs-badge-primary gs-badge-sm gs-ml-xs">
                                        <?= h($groupName) ?>
                                    </span>
                                <?php else: ?>
                                    <?= h($groupName) ?>
                                <?php endif; ?>
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
                                                <?= $isClassView ? 'Klass' : 'Plac.' ?>
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

                                            <!-- Position (Category or Class) -->
                                            <td class="gs-table-center gs-font-bold">
                                                <?php
                                                $displayPosition = $isClassView ? $result['class_position'] : $result['position'];
                                                ?>
                                                <?php if ($result['status'] === 'finished' && $displayPosition): ?>
                                                    <?php if ($displayPosition == 1): ?>
                                                        <span class="gs-medal">🥇</span>
                                                    <?php elseif ($displayPosition == 2): ?>
                                                        <span class="gs-medal">🥈</span>
                                                    <?php elseif ($displayPosition == 3): ?>
                                                        <span class="gs-medal">🥉</span>
                                                    <?php else: ?>
                                                        <?= $displayPosition ?>
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

                                            <!-- Points (Category or Class) -->
                                            <td class="gs-table-center gs-font-bold">
                                                <?php
                                                $displayPoints = $isClassView ? $result['class_points'] : $result['points'];
                                                ?>
                                                <?= $displayPoints ?? 0 ?>
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

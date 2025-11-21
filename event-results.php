<?php
/**
 * Event Results Page
 * Displays results grouped by class (M17, K40, etc.)
 */

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
// Order by finish_time within each class to calculate correct positions
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
        CASE WHEN res.status = 'finished' THEN 0 ELSE 1 END,
        res.finish_time ASC
", [$eventId]);

// Check if any results have bib numbers
$hasBibNumbers = false;
foreach ($results as $result) {
    if (!empty($result['bib_number'])) {
        $hasBibNumbers = true;
        break;
    }
}

// Check if any results have split times
$hasSplitTimes = false;
foreach ($results as $result) {
    for ($i = 1; $i <= 10; $i++) {
        if (!empty($result['ss' . $i])) {
            $hasSplitTimes = true;
            break 2;
        }
    }
}

// Group results by class
$resultsByClass = [];
$totalParticipants = count($results);
$totalFinished = 0;

foreach ($results as $result) {
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

// Sort results within each class by finish_time (converted to seconds)
foreach ($resultsByClass as $className => &$classData) {
    usort($classData['results'], function($a, $b) {
        // DNF/DNS/DQ go last
        if ($a['status'] !== 'finished' && $b['status'] === 'finished') return 1;
        if ($a['status'] === 'finished' && $b['status'] !== 'finished') return -1;
        if ($a['status'] !== 'finished' && $b['status'] !== 'finished') return 0;

        // Both finished - sort by time in seconds
        $aSeconds = timeToSeconds($a['finish_time']);
        $bSeconds = timeToSeconds($b['finish_time']);

        return $aSeconds <=> $bSeconds;
    });

    // Calculate positions after sorting
    $position = 0;
    foreach ($classData['results'] as &$result) {
        if ($result['status'] === 'finished') {
            $position++;
            $result['class_position'] = $position;
        } else {
            $result['class_position'] = null;
        }
    }
}
unset($classData);

// Sort classes by their sort_order
uksort($resultsByClass, function($a, $b) use ($resultsByClass) {
    return $resultsByClass[$a]['sort_order'] - $resultsByClass[$b]['sort_order'];
});

/**
 * Format time string: remove leading 00: but keep hundredths/tenths
 * "00:04:17.54" -> "4:17.54"
 * "01:23:45.12" -> "1:23:45.12"
 */
function formatDisplayTime($time) {
    if (empty($time)) return null;

    // Extract decimal part if present
    $decimal = '';
    if (preg_match('/(\.\d+)$/', $time, $matches)) {
        $decimal = $matches[1];
        $time = preg_replace('/\.\d+$/', '', $time);
    }

    // Parse time parts
    $parts = explode(':', $time);
    if (count($parts) === 3) {
        $hours = (int)$parts[0];
        $minutes = (int)$parts[1];
        $seconds = (int)$parts[2];

        if ($hours > 0) {
            return sprintf('%d:%02d:%02d', $hours, $minutes, $seconds) . $decimal;
        } else {
            return sprintf('%d:%02d', $minutes, $seconds) . $decimal;
        }
    } elseif (count($parts) === 2) {
        $minutes = (int)$parts[0];
        $seconds = (int)$parts[1];
        return sprintf('%d:%02d', $minutes, $seconds) . $decimal;
    }

    return $time . $decimal;
}

/**
 * Convert time string to seconds for calculation (including decimals)
 */
function timeToSeconds($time) {
    if (empty($time)) return 0;

    // Extract decimal part if present
    $decimal = 0;
    if (preg_match('/\.(\d+)$/', $time, $matches)) {
        $decimal = floatval('0.' . $matches[1]);
        $time = preg_replace('/\.\d+$/', '', $time);
    }

    $parts = explode(':', $time);
    if (count($parts) === 3) {
        return (int)$parts[0] * 3600 + (int)$parts[1] * 60 + (int)$parts[2] + $decimal;
    } elseif (count($parts) === 2) {
        return (int)$parts[0] * 60 + (int)$parts[1] + $decimal;
    }
    return $decimal;
}

// Calculate time behind leader for each class
foreach ($resultsByClass as $className => &$classData) {
    $winnerTime = null;
    $winnerSeconds = 0;

    foreach ($classData['results'] as $result) {
        if ($result['class_position'] == 1 && !empty($result['finish_time']) && $result['status'] === 'finished') {
            $winnerTime = $result['finish_time'];
            $winnerSeconds = timeToSeconds($winnerTime);
            break;
        }
    }

    foreach ($classData['results'] as &$result) {
        if ($winnerSeconds > 0 && !empty($result['finish_time']) && $result['status'] === 'finished' && $result['class_position'] > 1) {
            $riderSeconds = timeToSeconds($result['finish_time']);
            $diffSeconds = $riderSeconds - $winnerSeconds;

            if ($diffSeconds > 0) {
                $hours = floor($diffSeconds / 3600);
                $minutes = floor(($diffSeconds % 3600) / 60);
                $wholeSeconds = floor($diffSeconds) % 60;
                $decimals = $diffSeconds - floor($diffSeconds);

                // Format decimal part (keep 2 decimal places)
                $decimalStr = $decimals > 0 ? sprintf('.%02d', round($decimals * 100)) : '';

                if ($hours > 0) {
                    $result['time_behind_formatted'] = sprintf('+%d:%02d:%02d', $hours, $minutes, $wholeSeconds) . $decimalStr;
                } else {
                    $result['time_behind_formatted'] = sprintf('+%d:%02d', $minutes, $wholeSeconds) . $decimalStr;
                }
            } else {
                $result['time_behind_formatted'] = null;
            }
        } else {
            $result['time_behind_formatted'] = null;
        }
    }
}
unset($classData);

$pageTitle = $event['name'] . ' - Resultat';
$pageType = 'public';
include __DIR__ . '/includes/layout-header.php';
?>

<style>
/* Compact results table for desktop */
.results-table {
    font-size: 0.8rem;
}

.results-table th,
.results-table td {
    padding: 0.4rem 0.5rem;
    white-space: nowrap;
}

.results-table .gs-medal {
    font-size: 1rem;
}

/* Hide split times by default on desktop */
.split-time-col {
    display: none;
}

.split-times-visible .split-time-col {
    display: table-cell;
}

/* Hide club column when split times are visible to save space */
.split-times-visible .club-col {
    display: none;
}

/* Toggle button styling */
.split-times-toggle {
    cursor: pointer;
    user-select: none;
}

@media (max-width: 768px) {
    .results-table {
        font-size: 0.75rem;
    }
    .results-table th,
    .results-table td {
        padding: 0.3rem 0.4rem;
    }
}
</style>

<main class="gs-main-content">
    <div class="gs-container">

        <!-- Event Header -->
        <div class="gs-card gs-mb-xl">
            <div class="gs-card-content event-header-content">
                <div class="gs-mb-lg">
                    <a href="/event.php?id=<?= $eventId ?>" class="gs-btn gs-btn-outline gs-btn-sm">
                        <i data-lucide="arrow-left" class="gs-icon-md"></i>
                        Tillbaka till event
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
                            <span class="gs-text-secondary gs-text-base"> - Resultat</span>
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
            <div class="gs-card gs-empty-state">
                <i data-lucide="trophy" class="gs-empty-icon"></i>
                <h3 class="gs-h4 gs-mb-sm">Inga resultat ännu</h3>
                <p class="gs-text-secondary">
                    Resultat har inte laddats upp för denna tävling.
                </p>
            </div>
        <?php else: ?>
            <?php foreach ($resultsByClass as $groupName => $groupData): ?>
                <div class="gs-card gs-mb-xl class-section" data-group="<?= h($groupName) ?>">
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
                    <?php
                    // Check which SS columns this class has data for
                    $classSplitCols = [];
                    if ($hasSplitTimes && !$isDH) {
                        for ($i = 1; $i <= 10; $i++) {
                            foreach ($groupData['results'] as $r) {
                                if (!empty($r['ss' . $i])) {
                                    $classSplitCols[] = $i;
                                    break;
                                }
                            }
                        }
                    }
                    ?>
                    <div class="gs-card-content gs-card-table-container">
                        <?php if (!empty($classSplitCols)): ?>
                        <div class="gs-mb-sm gs-text-right">
                            <label class="gs-checkbox split-times-toggle">
                                <input type="checkbox" onchange="toggleSplitTimes(this)">
                                <span class="gs-text-sm">Visa sträcktider</span>
                            </label>
                        </div>
                        <?php endif; ?>
                        <table class="gs-table results-table">
                            <thead>
                                <tr>
                                    <th class="gs-table-col-narrow">Plac.</th>
                                    <th>Namn</th>
                                    <th class="club-col">Klubb</th>
                                    <?php if ($hasBibNumbers): ?>
                                        <th class="gs-table-col-medium">Startnr</th>
                                    <?php endif; ?>
                                    <?php if ($isDH): ?>
                                        <th class="gs-table-col-medium">Åk 1</th>
                                        <th class="gs-table-col-medium">Åk 2</th>
                                        <th class="gs-table-col-medium">Bästa</th>
                                    <?php else: ?>
                                        <th class="gs-table-col-medium">Tid</th>
                                        <th class="gs-table-col-medium">+Tid</th>
                                        <?php foreach ($classSplitCols as $ssNum): ?>
                                            <th class="gs-table-col-medium split-time-col">SS<?= $ssNum ?></th>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                    <?php if ($isDH): ?>
                                    <th class="gs-table-col-medium">+Tid</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($groupData['results'] as $result): ?>
                                    <tr class="result-row">
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

                                        <td>
                                            <a href="/rider.php?id=<?= $result['cyclist_id'] ?>" class="gs-rider-link">
                                                <?= h($result['firstname']) ?> <?= h($result['lastname']) ?>
                                            </a>
                                        </td>

                                        <td class="club-col">
                                            <?php if ($result['club_name']): ?>
                                                <span class="gs-badge gs-badge-secondary gs-badge-sm">
                                                    <?= h($result['club_name']) ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="gs-text-secondary">-</span>
                                            <?php endif; ?>
                                        </td>

                                        <?php if ($hasBibNumbers): ?>
                                            <td class="gs-table-center">
                                                <?= $result['bib_number'] ? h($result['bib_number']) : '-' ?>
                                            </td>
                                        <?php endif; ?>

                                        <?php if ($isDH): ?>
                                            <!-- DH: Show both run times -->
                                            <td class="gs-table-time-cell">
                                                <?php if ($result['run_1_time']): ?>
                                                    <?= formatDisplayTime($result['run_1_time']) ?>
                                                <?php else: ?>
                                                    <span class="gs-text-secondary">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="gs-table-time-cell">
                                                <?php if ($result['run_2_time']): ?>
                                                    <?= formatDisplayTime($result['run_2_time']) ?>
                                                <?php else: ?>
                                                    <span class="gs-text-secondary">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="gs-table-time-cell gs-font-bold">
                                                <?php
                                                // Show fastest time (for standard DH) or run 2 (for SweCup)
                                                $bestTime = null;
                                                if ($eventFormat === 'DH_SWECUP') {
                                                    $bestTime = $result['run_2_time'];
                                                } else {
                                                    if ($result['run_1_time'] && $result['run_2_time']) {
                                                        $bestTime = min($result['run_1_time'], $result['run_2_time']);
                                                    } elseif ($result['run_1_time']) {
                                                        $bestTime = $result['run_1_time'];
                                                    } else {
                                                        $bestTime = $result['run_2_time'];
                                                    }
                                                }
                                                if ($bestTime && $result['status'] === 'finished'):
                                                ?>
                                                    <?= formatDisplayTime($bestTime) ?>
                                                <?php else: ?>
                                                    <span class="gs-text-secondary">-</span>
                                                <?php endif; ?>
                                            </td>
                                        <?php else: ?>
                                            <!-- Enduro/Other: Show finish time -->
                                            <td class="gs-table-time-cell">
                                                <?php if ($result['finish_time'] && $result['status'] === 'finished'): ?>
                                                    <?= formatDisplayTime($result['finish_time']) ?>
                                                <?php else: ?>
                                                    <span class="gs-text-secondary">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <!-- +Tid right after total time -->
                                            <td class="gs-table-time-cell gs-text-secondary">
                                                <?= $result['time_behind_formatted'] ?? '-' ?>
                                            </td>
                                            <!-- Split times (per class) -->
                                            <?php foreach ($classSplitCols as $ssNum): ?>
                                                <td class="gs-table-time-cell gs-text-secondary split-time-col">
                                                    <?php if (!empty($result['ss' . $ssNum])): ?>
                                                        <?= formatDisplayTime($result['ss' . $ssNum]) ?>
                                                    <?php else: ?>
                                                        -
                                                    <?php endif; ?>
                                                </td>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                        <?php if ($isDH): ?>
                                        <!-- +Tid for DH -->
                                        <td class="gs-table-time-cell gs-text-secondary">
                                            <?= $result['time_behind_formatted'] ?? '-' ?>
                                        </td>
                                        <?php endif; ?>
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

<script>
function toggleSplitTimes(checkbox) {
    // Find the parent card content
    const cardContent = checkbox.closest('.gs-card-content');
    if (cardContent) {
        const table = cardContent.querySelector('.results-table');
        if (table) {
            if (checkbox.checked) {
                table.classList.add('split-times-visible');
            } else {
                table.classList.remove('split-times-visible');
            }
        }
    }
}
</script>

<?php include __DIR__ . '/includes/layout-footer.php'; ?>

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

// Fetch global texts for use_global functionality
$globalTexts = $db->getAll("SELECT field_key, content FROM global_texts WHERE is_active = 1");
$globalTextMap = [];
foreach ($globalTexts as $gt) {
    $globalTextMap[$gt['field_key']] = $gt['content'];
}

// Helper function to get content with global text fallback
function getEventContent($event, $field, $useGlobalField, $globalTextMap) {
    if (!empty($event[$useGlobalField]) && !empty($globalTextMap[$field])) {
        return $globalTextMap[$field];
    }
    return $event[$field] ?? '';
}

// Fetch registered participants for this event
$registrations = $db->getAll("
    SELECT
        reg.*,
        r.id as rider_id,
        r.firstname,
        r.lastname,
        c.name as club_name
    FROM event_registrations reg
    LEFT JOIN riders r ON reg.rider_id = r.id
    LEFT JOIN clubs c ON r.club_id = c.id
    WHERE reg.event_id = ?
    ORDER BY reg.registration_date ASC
", [$eventId]);

$totalRegistrations = count($registrations);
$confirmedRegistrations = count(array_filter($registrations, function($r) {
    return $r['status'] === 'confirmed';
}));

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
        cls.sort_order as class_sort_order,
        COALESCE(cls.ranking_type, 'time') as ranking_type
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
            'ranking_type' => $result['ranking_type'] ?? 'time',
            'results' => []
        ];
    }

    $resultsByClass[$className]['results'][] = $result;

    if ($result['status'] === 'finished') {
        $totalFinished++;
    }
}

// Sort results within each class based on ranking_type setting
foreach ($resultsByClass as $className => &$classData) {
    $rankingType = $classData['ranking_type'] ?? 'time';

    usort($classData['results'], function($a, $b) use ($rankingType) {
        // For time-based ranking, DNF/DNS/DQ go last
        if ($rankingType === 'time') {
            if ($a['status'] !== 'finished' && $b['status'] === 'finished') return 1;
            if ($a['status'] === 'finished' && $b['status'] !== 'finished') return -1;
            if ($a['status'] !== 'finished' && $b['status'] !== 'finished') return 0;

            // Both finished - sort by time in seconds
            $aSeconds = timeToSeconds($a['finish_time']);
            $bSeconds = timeToSeconds($b['finish_time']);

            return $aSeconds <=> $bSeconds;
        } elseif ($rankingType === 'name') {
            // Sort alphabetically by name
            $aName = ($a['firstname'] ?? '') . ' ' . ($a['lastname'] ?? '');
            $bName = ($b['firstname'] ?? '') . ' ' . ($b['lastname'] ?? '');
            return strcasecmp($aName, $bName);
        } elseif ($rankingType === 'bib') {
            // Sort by bib number
            $aBib = (int)($a['bib_number'] ?? 9999);
            $bBib = (int)($b['bib_number'] ?? 9999);
            return $aBib <=> $bBib;
        }

        return 0;
    });

    // Calculate positions after sorting
    // For time-based ranking, only finished riders get positions
    // For name/bib ranking, all riders get sequential numbers (not real positions)
    $position = 0;
    foreach ($classData['results'] as &$result) {
        if ($rankingType === 'time') {
            if ($result['status'] === 'finished') {
                $position++;
                $result['class_position'] = $position;
            } else {
                $result['class_position'] = null;
            }
        } else {
            // For name/bib ranking, no positions shown (it's just a list)
            $result['class_position'] = null;
        }
    }
}
unset($classData);

// Sort classes by their sort_order
uksort($resultsByClass, function($a, $b) use ($resultsByClass) {
    return $resultsByClass[$a]['sort_order'] - $resultsByClass[$b]['sort_order'];
});

// Determine active tab - default to 'resultat' if results exist, otherwise 'info'
$hasResults = !empty($results);
$defaultTab = $hasResults ? 'resultat' : 'info';
$activeTab = isset($_GET['tab']) ? $_GET['tab'] : $defaultTab;

// Check if registration is open for tab ordering
$registrationOpen = !empty($event['registration_deadline']) && strtotime($event['registration_deadline']) >= time();

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

// Calculate time behind leader for each class (only for time-based ranking)
foreach ($resultsByClass as $className => &$classData) {
    $rankingType = $classData['ranking_type'] ?? 'time';

    // Skip time_behind calculation for non-time ranking types
    if ($rankingType !== 'time') {
        foreach ($classData['results'] as &$result) {
            $result['time_behind_formatted'] = null;
        }
        unset($result);
        continue;
    }

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

$pageTitle = $event['name'];
$pageType = 'public';
include __DIR__ . '/includes/layout-header.php';
?>

<style>
/* Tab Navigation - Mobile Responsive */
.event-tabs-wrapper {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    margin: 0 -1rem;
    padding: 0 1rem;
}
.event-tabs {
    display: flex;
    gap: 0.25rem;
    min-width: max-content;
    padding-bottom: 0.5rem;
}
.event-tab {
    display: flex;
    align-items: center;
    gap: 0.375rem;
    padding: 0.625rem 0.875rem;
    border-radius: 0.5rem;
    font-size: 0.8125rem;
    font-weight: 500;
    text-decoration: none;
    white-space: nowrap;
    background: var(--gs-bg-secondary);
    color: var(--gs-text-secondary);
    border: 1px solid var(--gs-border);
    transition: all 0.2s;
}
.event-tab:hover {
    background: var(--gs-bg-tertiary);
    color: var(--gs-text-primary);
}
.event-tab.active {
    background: var(--gs-primary);
    color: white;
    border-color: var(--gs-primary);
}
.event-tab i {
    width: 14px;
    height: 14px;
}
@media (min-width: 768px) {
    .event-tabs-wrapper {
        margin: 0;
        padding: 0;
        overflow-x: visible;
    }
    .event-tabs {
        flex-wrap: wrap;
    }
}

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

                        <!-- Organizing Club Info -->
                        <?php if (!empty($event['organizer'])): ?>
                        <div class="event-organizer-info gs-mt-md">
                            <div class="gs-flex gs-items-center gs-gap-xs gs-mb-sm">
                                <i data-lucide="building-2" class="gs-icon-md gs-text-primary"></i>
                                <strong class="gs-text-primary"><?= h($event['organizer']) ?></strong>
                            </div>

                            <div class="gs-flex gs-gap-md gs-flex-wrap">
                                <?php if (!empty($event['website'])): ?>
                                <a href="<?= h($event['website']) ?>"
                                   target="_blank"
                                   rel="noopener noreferrer"
                                   class="gs-flex gs-items-center gs-gap-xs gs-link gs-text-sm">
                                    <i data-lucide="globe" class="gs-icon-sm"></i>
                                    Webbplats
                                </a>
                                <?php endif; ?>

                                <?php if (!empty($event['contact_email'])): ?>
                                <a href="mailto:<?= h($event['contact_email']) ?>"
                                   class="gs-flex gs-items-center gs-gap-xs gs-link gs-text-sm">
                                    <i data-lucide="mail" class="gs-icon-sm"></i>
                                    <?= h($event['contact_email']) ?>
                                </a>
                                <?php endif; ?>

                                <?php if (!empty($event['contact_phone'])): ?>
                                <a href="tel:<?= h($event['contact_phone']) ?>"
                                   class="gs-flex gs-items-center gs-gap-xs gs-link gs-text-sm">
                                    <i data-lucide="phone" class="gs-icon-sm"></i>
                                    <?= h($event['contact_phone']) ?>
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tab Navigation -->
        <div class="event-tabs-wrapper gs-mb-lg">
            <div class="event-tabs">
                <?php if ($hasResults): ?>
                <a href="?id=<?= $eventId ?>&tab=resultat"
                   class="event-tab <?= $activeTab === 'resultat' ? 'active' : '' ?>">
                    <i data-lucide="trophy"></i>
                    Resultat
                    <span class="gs-badge gs-badge-accent gs-badge-sm"><?= $totalParticipants ?></span>
                </a>
                <?php endif; ?>

                <a href="?id=<?= $eventId ?>&tab=info"
                   class="event-tab <?= $activeTab === 'info' ? 'active' : '' ?>">
                    <i data-lucide="info"></i>
                    Information
                </a>

                <?php if (!empty($event['pm_content']) || !empty($event['pm_use_global'])): ?>
                <a href="?id=<?= $eventId ?>&tab=pm"
                   class="event-tab <?= $activeTab === 'pm' ? 'active' : '' ?>">
                    <i data-lucide="clipboard-list"></i>
                    PM
                </a>
                <?php endif; ?>

                <?php if (!empty($event['jury_communication']) || !empty($event['jury_use_global'])): ?>
                <a href="?id=<?= $eventId ?>&tab=jury"
                   class="event-tab <?= $activeTab === 'jury' ? 'active' : '' ?>">
                    <i data-lucide="gavel"></i>
                    Jurykommuniké
                </a>
                <?php endif; ?>

                <?php if (!empty($event['competition_schedule']) || !empty($event['schedule_use_global'])): ?>
                <a href="?id=<?= $eventId ?>&tab=schema"
                   class="event-tab <?= $activeTab === 'schema' ? 'active' : '' ?>">
                    <i data-lucide="calendar-clock"></i>
                    Tävlingsschema
                </a>
                <?php endif; ?>

                <?php if (!empty($event['start_times']) || !empty($event['start_times_use_global'])): ?>
                <a href="?id=<?= $eventId ?>&tab=starttider"
                   class="event-tab <?= $activeTab === 'starttider' ? 'active' : '' ?>">
                    <i data-lucide="clock"></i>
                    Starttider
                </a>
                <?php endif; ?>

                <?php if (!empty($event['map_content']) || !empty($event['map_image_url']) || !empty($event['map_use_global'])): ?>
                <a href="?id=<?= $eventId ?>&tab=karta"
                   class="event-tab <?= $activeTab === 'karta' ? 'active' : '' ?>">
                    <i data-lucide="map"></i>
                    Karta
                </a>
                <?php endif; ?>

                <a href="?id=<?= $eventId ?>&tab=anmalda"
                   class="event-tab <?= $activeTab === 'anmalda' ? 'active' : '' ?>">
                    <i data-lucide="users"></i>
                    Anmälda
                    <span class="gs-badge gs-badge-secondary gs-badge-sm"><?= $totalRegistrations ?></span>
                </a>

                <?php if ($registrationOpen): ?>
                <a href="?id=<?= $eventId ?>&tab=anmalan"
                   class="event-tab <?= $activeTab === 'anmalan' ? 'active' : '' ?>">
                    <i data-lucide="user-plus"></i>
                    Anmälan
                </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Tab Content -->
        <?php if ($activeTab === 'resultat'): ?>
        <!-- RESULTS TAB -->
        <?php if (empty($results)): ?>
            <div class="gs-card gs-empty-state">
                <i data-lucide="trophy" class="gs-empty-icon"></i>
                <h3 class="gs-h4 gs-mb-sm">Inga resultat ännu</h3>
                <p class="gs-text-secondary">
                    Resultat har inte laddats upp för denna tävling.
                </p>
            </div>
        <?php else: ?>
            <?php if ($hasSplitTimes && !$isDH): ?>
            <div class="gs-mb-md gs-text-right">
                <label class="gs-checkbox split-times-toggle">
                    <input type="checkbox" id="globalSplitToggle" onchange="toggleAllSplitTimes(this.checked)">
                    <span class="gs-text-sm">Visa sträcktider</span>
                </label>
            </div>
            <?php endif; ?>
            <?php foreach ($resultsByClass as $groupName => $groupData): ?>
                <div class="gs-card gs-mb-xl class-section" data-group="<?= h($groupName) ?>">
                    <div class="gs-card-header">
                        <h2 class="gs-h4 gs-text-primary">
                            <i data-lucide="users" class="gs-icon-md"></i>
                            <?= h($groupData['display_name']) ?>
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

        <?php elseif ($activeTab === 'info'): ?>
        <!-- INFORMATION TAB -->
        <div class="gs-card gs-mb-lg">
            <div class="gs-card-header">
                <h2 class="gs-h3 gs-text-primary">
                    <i data-lucide="building"></i>
                    Faciliteter & Logistik
                </h2>
            </div>
            <div class="gs-card-content">
                <div class="gs-grid gs-grid-cols-1 gs-md-grid-cols-2 gs-gap-lg">
                    <!-- Left Column -->
                    <div>
                        <?php
                        $driverMeeting = getEventContent($event, 'driver_meeting', 'driver_meeting_use_global', $globalTextMap);
                        if (!empty($driverMeeting)): ?>
                            <div class="gs-mb-lg">
                                <h3 class="gs-h5 gs-mb-sm gs-text-primary">
                                    <i data-lucide="megaphone" class="gs-icon-14"></i>
                                    Förarmöte
                                </h3>
                                <div class="gs-text-secondary">
                                    <?= nl2br(h($driverMeeting)) ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php
                        $trainingInfo = getEventContent($event, 'training_info', 'training_use_global', $globalTextMap);
                        if (!empty($trainingInfo)): ?>
                            <div class="gs-mb-lg">
                                <h3 class="gs-h5 gs-mb-sm gs-text-primary">
                                    <i data-lucide="bike" class="gs-icon-14"></i>
                                    Träning
                                </h3>
                                <div class="gs-text-secondary">
                                    <?= nl2br(h($trainingInfo)) ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php
                        $timingInfo = getEventContent($event, 'timing_info', 'timing_use_global', $globalTextMap);
                        if (!empty($timingInfo)): ?>
                            <div class="gs-mb-lg">
                                <h3 class="gs-h5 gs-mb-sm gs-text-primary">
                                    <i data-lucide="timer" class="gs-icon-14"></i>
                                    Tidtagning
                                </h3>
                                <div class="gs-text-secondary">
                                    <?= nl2br(h($timingInfo)) ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php
                        $liftInfo = getEventContent($event, 'lift_info', 'lift_use_global', $globalTextMap);
                        if (!empty($liftInfo)): ?>
                            <div class="gs-mb-lg">
                                <h3 class="gs-h5 gs-mb-sm gs-text-primary">
                                    <i data-lucide="cable-car" class="gs-icon-14"></i>
                                    Lift
                                </h3>
                                <div class="gs-text-secondary">
                                    <?= nl2br(h($liftInfo)) ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Right Column -->
                    <div>
                        <?php
                        $foodCafe = getEventContent($event, 'food_cafe', 'food_use_global', $globalTextMap);
                        if (!empty($foodCafe)): ?>
                            <div class="gs-mb-lg">
                                <h3 class="gs-h5 gs-mb-sm gs-text-primary">
                                    <i data-lucide="utensils" class="gs-icon-14"></i>
                                    Mat/Café
                                </h3>
                                <div class="gs-text-secondary">
                                    <?= nl2br(h($foodCafe)) ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($event['parking_detailed'])): ?>
                            <div class="gs-mb-lg">
                                <h3 class="gs-h5 gs-mb-sm gs-text-primary">
                                    <i data-lucide="car" class="gs-icon-14"></i>
                                    Parkering
                                </h3>
                                <div class="gs-text-secondary">
                                    <?= nl2br(h($event['parking_detailed'])) ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($event['hotel_accommodation'])): ?>
                            <div class="gs-mb-lg">
                                <h3 class="gs-h5 gs-mb-sm gs-text-primary">
                                    <i data-lucide="bed" class="gs-icon-14"></i>
                                    Hotell/Boende
                                </h3>
                                <div class="gs-text-secondary">
                                    <?= nl2br(h($event['hotel_accommodation'])) ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <?php elseif ($activeTab === 'pm'): ?>
        <!-- PM TAB -->
        <div class="gs-card gs-mb-xl">
            <div class="gs-card-header">
                <h2 class="gs-h3 gs-text-primary">
                    <i data-lucide="clipboard-list"></i>
                    PM (Promemoria)
                </h2>
            </div>
            <div class="gs-card-content">
                <?php
                $pmContent = $event['pm_content'] ?? '';
                if ($event['pm_use_global'] ?? false) {
                    $globalPm = $db->getRow("SELECT content FROM global_texts WHERE field_key = 'pm_content'");
                    $pmContent = $globalPm['content'] ?? $pmContent;
                }
                ?>
                <?php if ($pmContent): ?>
                    <div class="gs-text-secondary">
                        <?= nl2br(h($pmContent)) ?>
                    </div>
                <?php else: ?>
                    <p class="gs-text-secondary">Inget PM tillgängligt för detta event.</p>
                <?php endif; ?>
            </div>
        </div>

        <?php elseif ($activeTab === 'jury'): ?>
        <!-- JURY COMMUNICATION TAB -->
        <div class="gs-card gs-mb-xl">
            <div class="gs-card-header">
                <h2 class="gs-h3 gs-text-primary">
                    <i data-lucide="gavel"></i>
                    Jurykommuniké
                </h2>
            </div>
            <div class="gs-card-content">
                <?php
                $juryContent = $event['jury_communication'] ?? '';
                if ($event['jury_use_global'] ?? false) {
                    $globalJury = $db->getRow("SELECT content FROM global_texts WHERE field_key = 'jury_communication'");
                    $juryContent = $globalJury['content'] ?? $juryContent;
                }
                ?>
                <?php if ($juryContent): ?>
                    <div class="gs-text-secondary">
                        <?= nl2br(h($juryContent)) ?>
                    </div>
                <?php else: ?>
                    <p class="gs-text-secondary">Ingen jurykommuniké tillgänglig.</p>
                <?php endif; ?>
            </div>
        </div>

        <?php elseif ($activeTab === 'schema'): ?>
        <!-- COMPETITION SCHEDULE TAB -->
        <div class="gs-card gs-mb-xl">
            <div class="gs-card-header">
                <h2 class="gs-h3 gs-text-primary">
                    <i data-lucide="calendar-clock"></i>
                    Tävlingsschema
                </h2>
            </div>
            <div class="gs-card-content">
                <?php
                $scheduleContent = $event['competition_schedule'] ?? '';
                if ($event['schedule_use_global'] ?? false) {
                    $globalSchedule = $db->getRow("SELECT content FROM global_texts WHERE field_key = 'competition_schedule'");
                    $scheduleContent = $globalSchedule['content'] ?? $scheduleContent;
                }
                ?>
                <?php if ($scheduleContent): ?>
                    <div class="gs-text-secondary">
                        <?= nl2br(h($scheduleContent)) ?>
                    </div>
                <?php else: ?>
                    <p class="gs-text-secondary">Inget tävlingsschema tillgängligt.</p>
                <?php endif; ?>
            </div>
        </div>

        <?php elseif ($activeTab === 'starttider'): ?>
        <!-- START TIMES TAB -->
        <div class="gs-card gs-mb-xl">
            <div class="gs-card-header">
                <h2 class="gs-h3 gs-text-primary">
                    <i data-lucide="clock"></i>
                    Starttider
                </h2>
            </div>
            <div class="gs-card-content">
                <?php
                $startContent = $event['start_times'] ?? '';
                if ($event['start_times_use_global'] ?? false) {
                    $globalStart = $db->getRow("SELECT content FROM global_texts WHERE field_key = 'start_times'");
                    $startContent = $globalStart['content'] ?? $startContent;
                }
                ?>
                <?php if ($startContent): ?>
                    <div class="gs-text-secondary">
                        <?= nl2br(h($startContent)) ?>
                    </div>
                <?php else: ?>
                    <p class="gs-text-secondary">Inga starttider publicerade ännu.</p>
                <?php endif; ?>
            </div>
        </div>

        <?php elseif ($activeTab === 'karta'): ?>
        <!-- MAP TAB -->
        <div class="gs-card gs-mb-xl">
            <div class="gs-card-header">
                <h2 class="gs-h3 gs-text-primary">
                    <i data-lucide="map"></i>
                    Karta
                </h2>
            </div>
            <div class="gs-card-content">
                <?php if (!empty($event['map_image_url'])): ?>
                    <div class="gs-mb-lg">
                        <img src="<?= h($event['map_image_url']) ?>"
                             alt="Karta"
                             style="max-width: 100%; height: auto; border-radius: 0.5rem;">
                    </div>
                <?php endif; ?>

                <?php
                $mapContent = $event['map_content'] ?? '';
                if ($event['map_use_global'] ?? false) {
                    $globalMap = $db->getRow("SELECT content FROM global_texts WHERE field_key = 'map_content'");
                    $mapContent = $globalMap['content'] ?? $mapContent;
                }
                ?>
                <?php if ($mapContent): ?>
                    <div class="gs-text-secondary">
                        <?= nl2br(h($mapContent)) ?>
                    </div>
                <?php elseif (empty($event['map_image_url'])): ?>
                    <p class="gs-text-secondary">Ingen karta tillgänglig.</p>
                <?php endif; ?>

                <?php if (!empty($event['venue_coordinates'])): ?>
                    <div class="gs-mt-lg">
                        <a href="https://www.google.com/maps?q=<?= urlencode($event['venue_coordinates']) ?>"
                           target="_blank"
                           class="gs-btn gs-btn-outline">
                            <i data-lucide="navigation"></i>
                            Öppna i Google Maps
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php elseif ($activeTab === 'anmalda'): ?>
        <!-- REGISTERED PARTICIPANTS TAB -->
        <div class="gs-card gs-mb-xl">
            <div class="gs-card-header">
                <h2 class="gs-h3 gs-text-primary">
                    <i data-lucide="users"></i>
                    Anmälda deltagare
                    <span class="gs-badge gs-badge-primary gs-ml-sm">
                        <?= $totalRegistrations ?> anmälda
                    </span>
                </h2>
            </div>
            <div class="gs-card-content">
                <?php if (empty($registrations)): ?>
                    <div class="gs-alert gs-alert-warning">
                        <p>Inga anmälningar ännu.</p>
                    </div>
                <?php else: ?>
                    <div class="gs-table-responsive">
                        <table class="gs-table">
                            <thead>
                                <tr>
                                    <th>Nr</th>
                                    <th>Namn</th>
                                    <th>Klubb</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($registrations as $index => $reg): ?>
                                    <tr>
                                        <td class="gs-table-center"><?= $index + 1 ?></td>
                                        <td>
                                            <strong>
                                                <?= h($reg['first_name']) ?> <?= h($reg['last_name']) ?>
                                            </strong>
                                        </td>
                                        <td>
                                            <?php if (!empty($reg['club_name'])): ?>
                                                <span class="gs-badge gs-badge-secondary gs-badge-sm">
                                                    <?= h($reg['club_name']) ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="gs-text-secondary">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php
                                            $statusBadge = 'gs-badge-secondary';
                                            $statusText = ucfirst($reg['status']);
                                            if ($reg['status'] === 'confirmed') {
                                                $statusBadge = 'gs-badge-success';
                                                $statusText = 'Bekräftad';
                                            } elseif ($reg['status'] === 'pending') {
                                                $statusBadge = 'gs-badge-warning';
                                                $statusText = 'Väntande';
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
                <?php endif; ?>
            </div>
        </div>

        <?php elseif ($activeTab === 'anmalan'): ?>
        <!-- REGISTRATION FORM TAB -->
        <div class="gs-card gs-mb-xl">
            <div class="gs-card-header">
                <h2 class="gs-h3 gs-text-primary">
                    <i data-lucide="user-plus"></i>
                    Anmälan till <?= h($event['name']) ?>
                </h2>
            </div>
            <div class="gs-card-content">
                <?php if (!empty($event['registration_deadline']) && strtotime($event['registration_deadline']) < time()): ?>
                    <div class="gs-alert gs-alert-danger">
                        <h3 class="gs-h5 gs-mb-sm">Anmälan stängd</h3>
                        <p>Anmälan stängde <?= date('d M Y', strtotime($event['registration_deadline'])) ?>.</p>
                    </div>
                <?php elseif (!empty($event['registration_url'])): ?>
                    <div class="gs-alert gs-alert-primary gs-mb-lg">
                        <h3 class="gs-h5 gs-mb-sm">
                            <i data-lucide="external-link" class="gs-icon-14"></i>
                            Extern anmälan
                        </h3>
                        <p class="gs-mb-sm">
                            Anmälan till detta event görs via en extern webbplats.
                        </p>
                        <a href="<?= h($event['registration_url']) ?>"
                           target="_blank"
                           rel="noopener noreferrer"
                           class="gs-btn gs-btn-primary">
                            <i data-lucide="external-link" class="gs-icon-14"></i>
                            Gå till anmälan
                        </a>
                    </div>
                <?php else: ?>
                    <div class="gs-alert gs-alert-info">
                        <h3 class="gs-h5 gs-mb-sm">Anmälningsformulär</h3>
                        <p>Anmälningsfunktionen är under utveckling.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php endif; ?>
    </div>
</main>

<script>
function toggleAllSplitTimes(show) {
    // Toggle split times visibility for ALL result tables
    const tables = document.querySelectorAll('.results-table');
    tables.forEach(table => {
        if (show) {
            table.classList.add('split-times-visible');
        } else {
            table.classList.remove('split-times-visible');
        }
    });
}
</script>

<?php include __DIR__ . '/includes/layout-footer.php'; ?>

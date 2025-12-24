<?php
/**
 * V3 Event Page - Complete port from V2
 * Includes: Results, Information, PM, Jury, Schedule, Start times, Map, Registered, Tickets, Registration
 */

$db = hub_db();
$eventId = intval($pageInfo['params']['id'] ?? 0);

if (!$eventId) {
    header('Location: /results');
    exit;
}

// Check if user can edit this event (admins + promotors assigned to this event)
$canEditEvent = function_exists('canAccessEvent') && canAccessEvent($eventId);

// Helper functions (only define if not already defined)
if (!function_exists('timeToSeconds')) {
    function timeToSeconds($time) {
        if (empty($time)) return PHP_INT_MAX;
        $decimal = 0;
        if (preg_match('/(\.\d+)$/', $time, $matches)) {
            $decimal = floatval($matches[1]);
            $time = preg_replace('/\.\d+$/', '', $time);
        }
        $parts = explode(':', $time);
        $seconds = 0;
        if (count($parts) === 3) {
            $seconds = (int)$parts[0] * 3600 + (int)$parts[1] * 60 + (int)$parts[2];
        } elseif (count($parts) === 2) {
            $seconds = (int)$parts[0] * 60 + (int)$parts[1];
        } elseif (count($parts) === 1) {
            $seconds = (int)$parts[0];
        }
        return $seconds + $decimal;
    }
}

if (!function_exists('formatDisplayTime')) {
    function formatDisplayTime($time) {
        if (empty($time)) return null;
        $decimal = '';
        if (preg_match('/(\.\d+)$/', $time, $matches)) {
            $decimal = $matches[1];
            $time = preg_replace('/\.\d+$/', '', $time);
        }
        $parts = explode(':', $time);
        if (count($parts) === 3) {
            $hours = (int)$parts[0];
            $minutes = (int)$parts[1];
            $seconds = (int)$parts[2];
            if ($hours > 0) {
                return $hours . ':' . sprintf('%02d', $minutes) . ':' . sprintf('%02d', $seconds) . $decimal;
            } else {
                return $minutes . ':' . sprintf('%02d', $seconds) . $decimal;
            }
        }
        return $time . $decimal;
    }
}

if (!function_exists('getEventContent')) {
    function getEventContent($event, $field, $useGlobalField, $globalTextMap) {
        if (!empty($event[$useGlobalField]) && !empty($globalTextMap[$field])) {
            return $globalTextMap[$field];
        }
        return $event[$field] ?? '';
    }
}

try {
    // Fetch event details with venue info, header banner, and organizer club
    $stmt = $db->prepare("
        SELECT
            e.*,
            s.id as series_id,
            s.name as series_name,
            s.logo as series_logo,
            s.gradient_start as series_gradient_start,
            s.gradient_end as series_gradient_end,
            v.name as venue_name,
            v.city as venue_city,
            v.address as venue_address,
            hb.filepath as header_banner_url,
            oc.name as organizer_club_name,
            oc.id as organizer_club_id_ref
        FROM events e
        LEFT JOIN series s ON e.series_id = s.id
        LEFT JOIN venues v ON e.venue_id = v.id
        LEFT JOIN media hb ON e.header_banner_media_id = hb.id
        LEFT JOIN clubs oc ON e.organizer_club_id = oc.id
        WHERE e.id = ?
    ");
    $stmt->execute([$eventId]);
    $event = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$event) {
        include HUB_V3_ROOT . '/pages/404.php';
        return;
    }

    // Fetch global texts for use_global functionality
    $globalTexts = $db->query("SELECT field_key, content FROM global_texts WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC);
    $globalTextMap = [];
    foreach ($globalTexts as $gt) {
        $globalTextMap[$gt['field_key']] = $gt['content'];
    }

    // Check for interactive map (GPX data)
    require_once INCLUDES_PATH . '/map_functions.php';
    $hasInteractiveMap = eventHasMap($db, $eventId);

    // Fetch sponsors - series sponsors take priority over event sponsors
    // Include all logo fields for placement-specific logos
    require_once INCLUDES_PATH . '/sponsor-functions.php';
    $eventSponsors = ['header' => [], 'content' => [], 'sidebar' => [], 'footer' => [], 'partner' => []];

    // First, try series sponsors (these override event sponsors)
    if (!empty($event['series_id'])) {
        try {
            $seriesSponsorStmt = $db->prepare("
                SELECT s.*, ss.placement, ss.display_order,
                       m_banner.filepath as banner_logo_url,
                       m_standard.filepath as standard_logo_url,
                       m_small.filepath as small_logo_url,
                       m_legacy.filepath as legacy_logo_url
                FROM sponsors s
                INNER JOIN series_sponsors ss ON s.id = ss.sponsor_id
                LEFT JOIN media m_banner ON s.logo_banner_id = m_banner.id
                LEFT JOIN media m_standard ON s.logo_standard_id = m_standard.id
                LEFT JOIN media m_small ON s.logo_small_id = m_small.id
                LEFT JOIN media m_legacy ON s.logo_media_id = m_legacy.id
                WHERE ss.series_id = ? AND s.active = 1
                ORDER BY ss.display_order ASC, s.tier ASC
            ");
            $seriesSponsorStmt->execute([$event['series_id']]);
            foreach ($seriesSponsorStmt->fetchAll(PDO::FETCH_ASSOC) as $sponsor) {
                $placement = $sponsor['placement'] ?? 'sidebar';
                $eventSponsors[$placement][] = $sponsor;
            }
        } catch (Exception $e) {
            // Table might not exist yet
        }
    }

    // Event-specific sponsors - these ADD to series sponsors (or replace per placement if specified)
    try {
        $sponsorStmt = $db->prepare("
            SELECT s.*, es.placement, es.display_order,
                   m_banner.filepath as banner_logo_url,
                   m_standard.filepath as standard_logo_url,
                   m_small.filepath as small_logo_url,
                   m_legacy.filepath as legacy_logo_url
            FROM sponsors s
            INNER JOIN event_sponsors es ON s.id = es.sponsor_id
            LEFT JOIN media m_banner ON s.logo_banner_id = m_banner.id
            LEFT JOIN media m_standard ON s.logo_standard_id = m_standard.id
            LEFT JOIN media m_small ON s.logo_small_id = m_small.id
            LEFT JOIN media m_legacy ON s.logo_media_id = m_legacy.id
            WHERE es.event_id = ? AND s.active = 1
            ORDER BY es.display_order ASC, s.tier ASC
        ");
        $sponsorStmt->execute([$eventId]);
        foreach ($sponsorStmt->fetchAll(PDO::FETCH_ASSOC) as $sponsor) {
            $placement = $sponsor['placement'] ?? 'sidebar';
            // Event sponsors override series sponsors for each placement
            if (empty($eventSponsors[$placement])) {
                $eventSponsors[$placement] = [];
            }
            // Add to beginning (event sponsors take priority)
            array_unshift($eventSponsors[$placement], $sponsor);
        }
    } catch (Exception $e) {
        // Table might not exist yet
        error_log("EVENT PAGE: event_sponsors load error: " . $e->getMessage());
    }

    // DEBUG: Log all sponsor placements
    error_log("EVENT PAGE: Sponsor placements - header:" . count($eventSponsors['header']) . ", content:" . count($eventSponsors['content']) . ", sidebar:" . count($eventSponsors['sidebar']));

    // Check event format for DH mode
    $eventFormat = $event['event_format'] ?? 'ENDURO';
    $isDH = in_array($eventFormat, ['DH_STANDARD', 'DH_SWECUP']);

    // Fetch all results for this event
    $stmt = $db->prepare("
        SELECT
            res.*,
            r.id as rider_id,
            r.firstname,
            r.lastname,
            r.gender,
            r.birth_year,
            r.license_number,
            c.name as club_name,
            c.id as club_id,
            cls.id as class_id,
            cls.name as class_name,
            cls.display_name as class_display_name,
            cls.sort_order as class_sort_order,
            cls.awards_points as class_awards_points,
            COALESCE(cls.ranking_type, 'time') as ranking_type
        FROM results res
        INNER JOIN riders r ON res.cyclist_id = r.id
        LEFT JOIN clubs c ON COALESCE(res.club_id, r.club_id) = c.id
        LEFT JOIN classes cls ON res.class_id = cls.id
        WHERE res.event_id = ?
        ORDER BY
            cls.sort_order ASC,
            COALESCE(cls.name, 'Oklassificerad'),
            CASE WHEN res.status = 'finished' THEN 0 ELSE 1 END,
            res.finish_time ASC
    ");
    $stmt->execute([$eventId]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Check if any results have split times
    $hasSplitTimes = false;
    foreach ($results as $result) {
        for ($i = 1; $i <= 15; $i++) {
            if (!empty($result['ss' . $i])) {
                $hasSplitTimes = true;
                break 2;
            }
        }
    }

    // Parse stage names
    $stageNames = [];
    if (!empty($event['stage_names'])) {
        $stageNames = json_decode($event['stage_names'], true) ?: [];
    }

    // Group results by class
    $resultsByClass = [];
    $totalParticipants = count($results);
    $totalFinished = 0;

    foreach ($results as $result) {
        $classKey = $result['class_id'] ?? 'no_class';
        $className = $result['class_name'] ?? 'Oklassificerad';

        if (!isset($resultsByClass[$classKey])) {
            $resultsByClass[$classKey] = [
                'class_id' => $result['class_id'],
                'display_name' => $result['class_display_name'] ?? $className,
                'class_name' => $className,
                'sort_order' => $result['class_sort_order'] ?? 999,
                'awards_points' => (int)($result['class_awards_points'] ?? 1),
                'ranking_type' => $result['ranking_type'] ?? 'time',
                'results' => [],
                'split_stats' => []
            ];
        }

        $resultsByClass[$classKey]['results'][] = $result;

        if ($result['status'] === 'finished') {
            $totalFinished++;
        }
    }

    // Sort results within each class and calculate positions
    foreach ($resultsByClass as $classKey => &$classData) {
        $rankingType = $classData['ranking_type'] ?? 'time';

        usort($classData['results'], function($a, $b) use ($rankingType) {
            if ($rankingType !== 'time') {
                $aName = ($a['lastname'] ?? '') . ' ' . ($a['firstname'] ?? '');
                $bName = ($b['lastname'] ?? '') . ' ' . ($b['firstname'] ?? '');
                return strcasecmp($aName, $bName);
            }

            if ($a['status'] === 'finished' && $b['status'] !== 'finished') return -1;
            if ($a['status'] !== 'finished' && $b['status'] === 'finished') return 1;

            if ($a['status'] === 'finished' && $b['status'] === 'finished') {
                $aSeconds = timeToSeconds($a['finish_time']);
                $bSeconds = timeToSeconds($b['finish_time']);
                return $aSeconds <=> $bSeconds;
            }

            $statusPriority = ['dnf' => 1, 'dq' => 2, 'dns' => 3];
            $aPriority = $statusPriority[$a['status']] ?? 4;
            $bPriority = $statusPriority[$b['status']] ?? 4;
            return $aPriority <=> $bPriority;
        });

        // Calculate positions and time behind
        $position = 0;
        $winnerSeconds = 0;
        foreach ($classData['results'] as &$result) {
            if ($rankingType !== 'time') {
                $result['class_position'] = null;
                $result['time_behind'] = null;
                continue;
            }

            if ($result['status'] === 'finished') {
                $position++;
                $result['class_position'] = $position;

                if ($position === 1 && !empty($result['finish_time'])) {
                    $winnerSeconds = timeToSeconds($result['finish_time']);
                }

                if ($position > 1 && $winnerSeconds > 0 && !empty($result['finish_time'])) {
                    $riderSeconds = timeToSeconds($result['finish_time']);
                    $diffSeconds = $riderSeconds - $winnerSeconds;
                    if ($diffSeconds > 0) {
                        $hours = floor($diffSeconds / 3600);
                        $minutes = floor(($diffSeconds % 3600) / 60);
                        $wholeSeconds = floor($diffSeconds) % 60;
                        $decimals = $diffSeconds - floor($diffSeconds);
                        $decimalStr = $decimals > 0 ? sprintf('.%02d', round($decimals * 100)) : '';

                        if ($hours > 0) {
                            $result['time_behind'] = sprintf('+%d:%02d:%02d', $hours, $minutes, $wholeSeconds) . $decimalStr;
                        } else {
                            $result['time_behind'] = sprintf('+%d:%02d', $minutes, $wholeSeconds) . $decimalStr;
                        }
                    }
                }
            } else {
                $result['class_position'] = null;
            }
        }
        unset($result);

        // Check if this is a motion/kids class - skip split comparisons for these
        $rankingType = $classData['ranking_type'] ?? 'time';
        $className = strtolower($classData['class_name'] ?? '');
        $isMotionOrKids = ($rankingType !== 'time') ||
                          strpos($className, 'kids') !== false ||
                          strpos($className, 'barn') !== false ||
                          strpos($className, 'motion') !== false;

        $classData['is_motion_or_kids'] = $isMotionOrKids;

        // Only calculate split stats and rankings for competitive classes
        if (!$isMotionOrKids) {
            // Calculate split time statistics for color coding
            for ($ss = 1; $ss <= 15; $ss++) {
                $times = [];
                foreach ($classData['results'] as $result) {
                    if (!empty($result['ss' . $ss]) && $result['status'] === 'finished') {
                        $times[] = timeToSeconds($result['ss' . $ss]);
                    }
                }
                if (count($times) >= 2) {
                    sort($times);
                    $min = $times[0];
                    $max = $times[count($times) - 1];
                    if (count($times) >= 3) {
                        $p90Index = (int) floor(count($times) * 0.9);
                        $p90 = $times[$p90Index];
                        if ($max > $p90 * 1.3) {
                            $max = $p90;
                        }
                    }
                    $classData['split_stats'][$ss] = [
                        'min' => $min,
                        'max' => $max,
                        'range' => $max - $min
                    ];
                }
            }

            // Calculate split rankings and +time for each rider in this class
            for ($ss = 1; $ss <= 15; $ss++) {
                // Collect all valid split times with rider index
                $splitTimes = [];
                foreach ($classData['results'] as $idx => $result) {
                    if (!empty($result['ss' . $ss]) && $result['status'] === 'finished') {
                        $splitTimes[] = [
                            'idx' => $idx,
                            'time' => timeToSeconds($result['ss' . $ss])
                        ];
                    }
                }

                if (count($splitTimes) > 0) {
                    // Sort by time to calculate rankings
                    usort($splitTimes, function($a, $b) {
                        return $a['time'] <=> $b['time'];
                    });

                    $bestTime = $splitTimes[0]['time'];

                    // Assign rankings
                    foreach ($splitTimes as $rank => $entry) {
                        $ridx = $entry['idx'];
                        $classData['results'][$ridx]['split_rank_' . $ss] = $rank + 1;
                        $diff = $entry['time'] - $bestTime;
                        if ($diff > 0) {
                            $classData['results'][$ridx]['split_diff_' . $ss] = '+' . number_format($diff, 2);
                        } else {
                            $classData['results'][$ridx]['split_diff_' . $ss] = '';
                        }
                    }
                }
            }
        }
    }
    unset($classData);

    // Sort classes by sort_order
    uasort($resultsByClass, function($a, $b) {
        return $a['sort_order'] - $b['sort_order'];
    });

    // Calculate global split stats for "Sträcktider Total" view
    // Combines all time-ranked classes (excluding motion/kids)
    $globalSplitResults = [];
    $globalSplitStats = [];
    $globalSplits = [];

    if ($hasSplitTimes && !$isDH) {
        // Collect all results from time-ranked classes
        foreach ($resultsByClass as $classKey => $classData) {
            $rankingType = $classData['ranking_type'] ?? 'time';
            $className = strtolower($classData['class_name'] ?? '');

            // Skip motion classes and kids classes
            if ($rankingType !== 'time') continue;
            if (strpos($className, 'kids') !== false || strpos($className, 'barn') !== false) continue;

            foreach ($classData['results'] as $result) {
                if ($result['status'] === 'finished') {
                    $globalSplitResults[] = array_merge($result, [
                        'original_class' => $classData['display_name']
                    ]);
                }
            }
        }

        // Determine which splits exist globally
        for ($ss = 1; $ss <= 15; $ss++) {
            foreach ($globalSplitResults as $r) {
                if (!empty($r['ss' . $ss])) {
                    $globalSplits[] = $ss;
                    break;
                }
            }
        }

        // Calculate global split stats and rankings
        for ($ss = 1; $ss <= 15; $ss++) {
            $splitTimes = [];
            foreach ($globalSplitResults as $idx => $result) {
                if (!empty($result['ss' . $ss])) {
                    $splitTimes[] = [
                        'idx' => $idx,
                        'time' => timeToSeconds($result['ss' . $ss])
                    ];
                }
            }

            if (count($splitTimes) > 0) {
                usort($splitTimes, function($a, $b) {
                    return $a['time'] <=> $b['time'];
                });

                $bestTime = $splitTimes[0]['time'];
                $times = array_column($splitTimes, 'time');
                $min = min($times);
                $max = max($times);

                // Cap outliers at 90th percentile + 30%
                if (count($times) >= 3) {
                    $p90Index = (int) floor(count($times) * 0.9);
                    sort($times);
                    $p90 = $times[$p90Index];
                    if ($max > $p90 * 1.3) {
                        $max = $p90;
                    }
                }

                $globalSplitStats[$ss] = [
                    'min' => $min,
                    'max' => $max,
                    'range' => $max - $min
                ];

                // Assign global rankings
                foreach ($splitTimes as $rank => $entry) {
                    $ridx = $entry['idx'];
                    $globalSplitResults[$ridx]['global_split_rank_' . $ss] = $rank + 1;
                    $diff = $entry['time'] - $bestTime;
                    if ($diff > 0) {
                        $globalSplitResults[$ridx]['global_split_diff_' . $ss] = '+' . number_format($diff, 2);
                    } else {
                        $globalSplitResults[$ridx]['global_split_diff_' . $ss] = '';
                    }
                }
            }
        }

        // Sort global results by first split time by default
        if (count($globalSplits) > 0) {
            $firstSplit = $globalSplits[0];
            usort($globalSplitResults, function($a, $b) use ($firstSplit) {
                $aTime = !empty($a['ss' . $firstSplit]) ? timeToSeconds($a['ss' . $firstSplit]) : PHP_INT_MAX;
                $bTime = !empty($b['ss' . $firstSplit]) ? timeToSeconds($b['ss' . $firstSplit]) : PHP_INT_MAX;
                return $aTime <=> $bTime;
            });
        }
    }

    // Fetch registrations
    $registrations = $db->prepare("
        SELECT reg.*, r.firstname, r.lastname, c.name as club_name
        FROM event_registrations reg
        LEFT JOIN riders r ON reg.rider_id = r.id
        LEFT JOIN clubs c ON r.club_id = c.id
        WHERE reg.event_id = ?
        ORDER BY reg.registration_date ASC
    ");
    $registrations->execute([$eventId]);
    $registrations = $registrations->fetchAll(PDO::FETCH_ASSOC);
    $totalRegistrations = count($registrations);

    // Check if event is in the past
    $eventDate = strtotime($event['date']);
    $isPastEvent = $eventDate < strtotime('today');

    // Check registration status (with time support)
    $registrationDeadline = null;
    if (!empty($event['registration_deadline'])) {
        $deadlineDate = $event['registration_deadline'];
        $deadlineTime = !empty($event['registration_deadline_time']) ? $event['registration_deadline_time'] : '23:59:59';
        $registrationDeadline = strtotime($deadlineDate . ' ' . $deadlineTime);
    }
    $registrationOpen = $registrationDeadline && $registrationDeadline >= time();

    // Check publish dates for starttider, karta and PM
    $starttiderPublished = empty($event['starttider_publish_at']) || strtotime($event['starttider_publish_at']) <= time();
    $kartaPublished = empty($event['karta_publish_at']) || strtotime($event['karta_publish_at']) <= time();
    $pmPublished = empty($event['pm_publish_at']) || strtotime($event['pm_publish_at']) <= time();

    // Determine active tab based on event state
    // Priority: 1. Results (if exists) 2. Inbjudan (default for upcoming events)
    $hasResults = !empty($results);
    if ($hasResults) {
        $defaultTab = 'resultat';
    } else {
        $defaultTab = 'info'; // Inbjudan is default for upcoming events
    }
    $activeTab = isset($_GET['tab']) ? $_GET['tab'] : $defaultTab;

    // Ticketing info
    $ticketingEnabled = !empty($event['ticketing_enabled']);

    // Fetch other events in the same series for navigation
    $seriesEvents = [];
    if (!empty($event['series_id'])) {
        $seriesEventsStmt = $db->prepare("
            SELECT id, name, date
            FROM events
            WHERE series_id = ? AND active = 1
            ORDER BY date ASC
        ");
        $seriesEventsStmt->execute([$event['series_id']]);
        $seriesEvents = $seriesEventsStmt->fetchAll(PDO::FETCH_ASSOC);
    }

} catch (Exception $e) {
    $error = $e->getMessage();
    $event = null;
}

if (!$event) {
    include HUB_V3_ROOT . '/pages/404.php';
    return;
}
?>

<?php if (isset($error)): ?>
<div class="alert alert--error mb-lg">
    <p><?= h($error) ?></p>
</div>
<?php endif; ?>

<?php
// Event Header Banner (uploaded image, not sponsor) - Full width at top
if (!empty($event['header_banner_url'])): ?>
<section class="event-header-banner">
    <img src="/<?= h(ltrim($event['header_banner_url'], '/')) ?>" alt="<?= h($event['name']) ?>" class="event-header-banner-img">
</section>
<?php endif; ?>

<!-- Event Header -->
<section class="event-header mb-sm">
    <div class="event-header-content">
        <?php if ($event['series_logo']): ?>
        <div class="event-logo">
            <img src="<?= h($event['series_logo']) ?>" alt="<?= h($event['series_name'] ?? 'Serie') ?>">
        </div>
        <?php endif; ?>

        <div class="event-info">
            <div class="event-title-row">
                <h1 class="event-title"><?= h($event['name']) ?></h1>
                <?php if ($canEditEvent): ?>
                <a href="/admin/event-edit.php?id=<?= $eventId ?>" class="btn-admin-edit" title="Redigera event">
                    <i data-lucide="pencil"></i>
                </a>
                <?php endif; ?>
            </div>

            <?php if (!empty($event['organizer_club_name'])): ?>
            <div class="event-organizer-club">
                <i data-lucide="users"></i>
                Arrangör:
                <a href="/club/<?= $event['organizer_club_id_ref'] ?>" class="organizer-club-link">
                    <?= h($event['organizer_club_name']) ?>
                </a>
            </div>
            <?php endif; ?>

            <div class="event-meta">
                <span class="event-meta-item">
                    <i data-lucide="calendar"></i>
                    <?= date('j F Y', strtotime($event['date'])) ?>
                </span>

                <?php if ($event['venue_name']): ?>
                <span class="event-meta-item">
                    <i data-lucide="map-pin"></i>
                    <?= h($event['venue_name']) ?><?php if ($event['venue_city']): ?>, <?= h($event['venue_city']) ?><?php endif; ?>
                </span>
                <?php elseif ($event['location']): ?>
                <span class="event-meta-item">
                    <i data-lucide="map-pin"></i>
                    <?= h($event['location']) ?>
                </span>
                <?php endif; ?>

                <?php if ($event['series_name']): ?>
                <a href="/series/<?= $event['series_id'] ?>" class="event-series-badge">
                    <i data-lucide="award"></i>
                    <?= h($event['series_name']) ?>
                </a>
                <?php endif; ?>
            </div>

            <?php if (!empty($event['organizer'])): ?>
            <div class="event-organizer">
                <span class="organizer-name">
                    <i data-lucide="building-2"></i>
                    <?= h($event['organizer']) ?>
                </span>
                <div class="organizer-links">
                    <?php if (!empty($event['website'])): ?>
                    <a href="<?= h($event['website']) ?>" target="_blank" rel="noopener">
                        <i data-lucide="globe"></i> Webb
                    </a>
                    <?php endif; ?>
                    <?php if (!empty($event['contact_email'])): ?>
                    <a href="mailto:<?= h($event['contact_email']) ?>">
                        <i data-lucide="mail"></i> E-post
                    </a>
                    <?php endif; ?>
                    <?php if (!empty($event['contact_phone'])): ?>
                    <a href="tel:<?= h($event['contact_phone']) ?>">
                        <i data-lucide="phone"></i> <?= h($event['contact_phone']) ?>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <div class="event-stats">
            <?php if ($event['series_logo']): ?>
            <div class="event-stat event-stat--logo">
                <img src="<?= h($event['series_logo']) ?>" alt="<?= h($event['series_name'] ?? 'Serie') ?>">
            </div>
            <?php endif; ?>
            <?php if ($totalParticipants > 0): ?>
            <div class="event-stat">
                <span class="event-stat-value"><?= $totalParticipants ?></span>
                <span class="event-stat-label">deltagare</span>
            </div>
            <div class="event-stat">
                <span class="event-stat-value"><?= $totalFinished ?></span>
                <span class="event-stat-label">i mål</span>
            </div>
            <?php elseif ($totalRegistrations > 0): ?>
            <div class="event-stat">
                <span class="event-stat-value"><?= $totalRegistrations ?></span>
                <span class="event-stat-label">anmälda</span>
            </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php
// Sponsor Banner (from sponsor with banner logo)
$headerSponsorsWithLogos = array_filter($eventSponsors['header'] ?? [], function($s) {
    return get_sponsor_logo_for_placement($s, 'header') !== null;
});
if (!empty($headerSponsorsWithLogos)): ?>
<section class="event-sponsor-banner mb-sm">
    <?php foreach ($headerSponsorsWithLogos as $sponsor):
        $bannerLogo = get_sponsor_logo_for_placement($sponsor, 'header');
    ?>
    <a href="<?= h($sponsor['website'] ?? '#') ?>" target="_blank" rel="noopener sponsored" class="sponsor-banner-link">
        <img src="<?= h($bannerLogo) ?>" alt="<?= h($sponsor['name']) ?>" class="sponsor-banner-logo">
    </a>
    <?php endforeach; ?>
</section>
<?php endif; ?>

<?php
// Content sponsors (logo row) - show all, with or without logos
// DEBUG: Log content sponsors count
error_log("EVENT PAGE DEBUG: Content sponsors count = " . count($eventSponsors['content'] ?? []));
if (!empty($eventSponsors['content'])) {
    foreach ($eventSponsors['content'] as $sp) {
        error_log("EVENT PAGE DEBUG: Content sponsor: " . ($sp['name'] ?? 'N/A') . " (ID: " . ($sp['id'] ?? 'N/A') . ")");
    }
}
if (!empty($eventSponsors['content'])): ?>
<section class="event-sponsor-logos mb-sm">
    <div class="sponsor-logos-grid">
        <?php foreach ($eventSponsors['content'] as $sponsor):
            $standardLogo = get_sponsor_logo_for_placement($sponsor, 'content');
        ?>
        <a href="<?= h($sponsor['website'] ?? '#') ?>" target="_blank" rel="noopener sponsored" class="sponsor-logo-item" title="<?= h($sponsor['name']) ?>">
            <?php if ($standardLogo): ?>
            <img src="<?= h($standardLogo) ?>" alt="<?= h($sponsor['name']) ?>">
            <?php else: ?>
            <span><?= h($sponsor['name']) ?></span>
            <?php endif; ?>
        </a>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<!-- Tab Navigation -->
<div class="event-tabs-wrapper mb-sm">
    <div class="event-tabs">
        <?php
        // When results exist, we show fewer tabs (Resultat, Info, Karta only)
        $showAllTabs = !$hasResults;
        ?>

        <?php if ($hasResults): ?>
        <a href="?id=<?= $eventId ?>&tab=resultat" class="event-tab <?= $activeTab === 'resultat' ? 'active' : '' ?>">
            <i data-lucide="trophy"></i>
            Resultat
            <span class="tab-badge"><?= $totalParticipants ?></span>
        </a>
        <?php endif; ?>

        <?php if ($showAllTabs && $registrationOpen): ?>
        <a href="?id=<?= $eventId ?>&tab=anmalan" class="event-tab <?= $activeTab === 'anmalan' ? 'active' : '' ?>">
            <i data-lucide="edit-3"></i>
            Anmälan
        </a>
        <?php endif; ?>

        <a href="?id=<?= $eventId ?>&tab=info" class="event-tab <?= $activeTab === 'info' ? 'active' : '' ?>">
            <i data-lucide="file-text"></i>
            Inbjudan
        </a>

        <?php if ($showAllTabs && $pmPublished && (!empty($event['pm_content']) || !empty($event['pm_use_global']))): ?>
        <a href="?id=<?= $eventId ?>&tab=pm" class="event-tab <?= $activeTab === 'pm' ? 'active' : '' ?>">
            <i data-lucide="file-text"></i>
            PM
        </a>
        <?php endif; ?>

        <?php if ($showAllTabs && (!empty($event['jury_communication']) || !empty($event['jury_use_global']))): ?>
        <a href="?id=<?= $eventId ?>&tab=jury" class="event-tab <?= $activeTab === 'jury' ? 'active' : '' ?>">
            <i data-lucide="scale"></i>
            Jury
        </a>
        <?php endif; ?>

        <?php if ($showAllTabs && (!empty($event['competition_schedule']) || !empty($event['schedule_use_global']))): ?>
        <a href="?id=<?= $eventId ?>&tab=schema" class="event-tab <?= $activeTab === 'schema' ? 'active' : '' ?>">
            <i data-lucide="calendar-clock"></i>
            Schema
        </a>
        <?php endif; ?>

        <?php if ($showAllTabs && $starttiderPublished && (!empty($event['start_times']) || !empty($event['start_times_use_global']))): ?>
        <a href="?id=<?= $eventId ?>&tab=starttider" class="event-tab <?= $activeTab === 'starttider' ? 'active' : '' ?>">
            <i data-lucide="list-ordered"></i>
            Starttider
        </a>
        <?php endif; ?>

        <?php if ($hasInteractiveMap && $kartaPublished): ?>
        <a href="?id=<?= $eventId ?>&tab=karta" class="event-tab <?= $activeTab === 'karta' ? 'active' : '' ?>" onclick="if(window.innerWidth <= 768) { window.location.href='/map.php?id=<?= $eventId ?>'; return false; }">
            <i data-lucide="map-pin"></i>
            Karta
        </a>
        <?php endif; ?>

        <?php if ($showAllTabs && !$isPastEvent && $totalRegistrations > 0): ?>
        <a href="?id=<?= $eventId ?>&tab=anmalda" class="event-tab <?= $activeTab === 'anmalda' ? 'active' : '' ?>">
            <i data-lucide="users"></i>
            Anmälda
            <span class="tab-badge tab-badge--secondary"><?= $totalRegistrations ?></span>
        </a>
        <?php endif; ?>

        <?php if ($showAllTabs && $ticketingEnabled): ?>
        <a href="?id=<?= $eventId ?>&tab=biljetter" class="event-tab <?= $activeTab === 'biljetter' ? 'active' : '' ?>">
            <i data-lucide="ticket"></i>
            Biljetter
        </a>
        <?php endif; ?>

        <?php if (count($seriesEvents) > 1): ?>
        <div class="series-jump-wrapper">
            <span class="series-jump-label"><?= h($event['series_name']) ?> <?= date('Y', strtotime($event['date'])) ?></span>
            <select onchange="if(this.value) window.location.href='/event/' + this.value" class="series-jump-select">
                <?php foreach ($seriesEvents as $sEvent): ?>
                    <option value="<?= $sEvent['id'] ?>" <?= $sEvent['id'] == $eventId ? 'selected' : '' ?>>
                        <?= date('j M', strtotime($sEvent['date'])) ?> – <?= h($sEvent['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Tab Content -->
<?php if ($activeTab === 'resultat'): ?>
<!-- RESULTS TAB -->
<?php if (empty($results)): ?>
<section class="card">
    <div class="empty-state">
        <i data-lucide="trophy" class="empty-state-icon"></i>
        <h3>Inga resultat ännu</h3>
        <p>Resultat har inte laddats upp för denna tävling.</p>
    </div>
</section>
<?php else: ?>

<!-- Filters -->
<div class="filter-row mb-lg">
    <div class="filter-field">
        <label class="filter-label">Klass</label>
        <select class="filter-select" id="classFilter" onchange="filterResults()">
            <option value="all">Alla klasser</option>
            <?php foreach ($resultsByClass as $classKey => $classData): ?>
            <option value="<?= $classKey ?>"><?= h($classData['display_name']) ?> (<?= count($classData['results']) ?>)</option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="filter-field">
        <label class="filter-label">Sök åkare</label>
        <input type="text" class="filter-input" id="searchFilter" placeholder="Namn eller klubb..." oninput="filterResults()">
    </div>
    <?php if ($hasSplitTimes && !$isDH): ?>
    <div class="filter-toggles">
        <label class="toggle-label">
            <input type="checkbox" id="colorToggle" checked onchange="toggleSplitColors(this.checked)">
            <span class="toggle-text">Färgkodning</span>
            <span class="toggle-switch"></span>
        </label>
        <?php if (count($globalSplitResults) > 0): ?>
        <label class="toggle-label">
            <input type="checkbox" id="totalViewToggle" onchange="toggleTotalView(this.checked)">
            <span class="toggle-text">Sträcktider Total</span>
            <span class="toggle-switch"></span>
        </label>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<?php foreach ($resultsByClass as $classKey => $classData):
    $isTimeRanked = ($classData['ranking_type'] ?? 'time') === 'time';
    $isMotionOrKids = $classData['is_motion_or_kids'] ?? false;

    // Calculate which splits this class has
    $classSplits = [];
    if ($hasSplitTimes && !$isDH) {
        for ($ss = 1; $ss <= 15; $ss++) {
            foreach ($classData['results'] as $r) {
                if (!empty($r['ss' . $ss])) {
                    $classSplits[] = $ss;
                    break;
                }
            }
        }
    }
?>
<section class="card mb-lg class-section" id="class-<?= $classKey ?>" data-class="<?= $classKey ?>">
    <div class="card-header">
        <div>
            <h2 class="card-title"><?= h($classData['display_name']) ?></h2>
            <p class="card-subtitle"><?= count($classData['results']) ?> deltagare<?= !$isTimeRanked ? ' (motion)' : '' ?></p>
        </div>
        <?php
        // Only show sidebar sponsor if it has a logo
        $resultSponsor = $eventSponsors['sidebar'][0] ?? null;
        $smallLogo = $resultSponsor ? get_sponsor_logo_for_placement($resultSponsor, 'sidebar') : null;
        if ($resultSponsor && $smallLogo): ?>
        <a href="<?= h($resultSponsor['website'] ?? '#') ?>" target="_blank" rel="noopener sponsored" class="class-sponsor" title="Resultat sponsrat av <?= h($resultSponsor['name']) ?>">
            <span class="class-sponsor-label-desktop">Resultaten presenteras av</span>
            <span class="class-sponsor-label-mobile">Sponsor</span>
            <img src="<?= h($smallLogo) ?>" alt="<?= h($resultSponsor['name']) ?>" class="class-sponsor-logo">
        </a>
        <?php endif; ?>
    </div>

    <!-- Desktop Table -->
    <div class="table-wrapper">
        <table class="table table--striped table--hover results-table">
            <thead>
                <tr>
                    <th class="col-place"><?= $isTimeRanked ? '#' : '' ?></th>
                    <th class="col-rider">Åkare</th>
                    <th class="col-club table-col-hide-mobile">Klubb</th>
                    <?php if ($isDH): ?>
                    <th class="col-time table-col-hide-mobile">Åk 1</th>
                    <th class="col-time table-col-hide-mobile">Åk 2</th>
                    <th class="col-time">Bästa</th>
                    <?php else: ?>
                    <th class="col-time">Tid</th>
                    <?php foreach ($classSplits as $ss): ?>
                    <?php if ($isMotionOrKids): ?>
                    <th class="col-split split-time-col table-col-hide-mobile"><?= $stageNames[$ss] ?? 'SS' . $ss ?></th>
                    <?php else: ?>
                    <th class="col-split split-time-col table-col-hide-mobile sortable-header" data-sort="ss<?= $ss ?>" onclick="sortBySplit(this, '<?= $classKey ?>', <?= $ss ?>)"><?= $stageNames[$ss] ?? 'SS' . $ss ?> <span class="sort-icon"></span></th>
                    <?php endif; ?>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($classData['results'] as $result):
                    $searchData = strtolower($result['firstname'] . ' ' . $result['lastname'] . ' ' . ($result['club_name'] ?? ''));
                ?>
                <tr class="result-row" onclick="window.location='/rider/<?= $result['rider_id'] ?>'" data-search="<?= h($searchData) ?>">
                    <td class="col-place <?= $result['class_position'] && $result['class_position'] <= 3 ? 'col-place--' . $result['class_position'] : '' ?>">
                        <?php if (!$isTimeRanked): ?>
                            ✓
                        <?php elseif ($result['status'] !== 'finished'): ?>
                            <span class="status-badge status-<?= strtolower($result['status']) ?>"><?= strtoupper($result['status']) ?></span>
                        <?php elseif ($result['class_position'] == 1): ?>
                            <img src="/assets/icons/medal-1st.svg" alt="1:a" class="medal-icon">
                        <?php elseif ($result['class_position'] == 2): ?>
                            <img src="/assets/icons/medal-2nd.svg" alt="2:a" class="medal-icon">
                        <?php elseif ($result['class_position'] == 3): ?>
                            <img src="/assets/icons/medal-3rd.svg" alt="3:e" class="medal-icon">
                        <?php else: ?>
                            <?= $result['class_position'] ?>
                        <?php endif; ?>
                    </td>
                    <td class="col-rider">
                        <a href="/rider/<?= $result['rider_id'] ?>" class="rider-link">
                            <?= h($result['firstname'] . ' ' . $result['lastname']) ?>
                        </a>
                    </td>
                    <td class="col-club table-col-hide-mobile">
                        <?php if ($result['club_id']): ?>
                            <a href="/club/<?= $result['club_id'] ?>"><?= h($result['club_name'] ?? '-') ?></a>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                    <?php if ($isDH): ?>
                    <td class="col-time table-col-hide-mobile"><?= $result['run_1_time'] ? formatDisplayTime($result['run_1_time']) : '-' ?></td>
                    <td class="col-time table-col-hide-mobile"><?= $result['run_2_time'] ? formatDisplayTime($result['run_2_time']) : '-' ?></td>
                    <td class="col-time font-bold">
                        <?php
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
                        echo $bestTime && $result['status'] === 'finished' ? formatDisplayTime($bestTime) : '-';
                        ?>
                    </td>
                    <?php else: ?>
                    <td class="col-time">
                        <?php if ($result['status'] === 'finished' && $result['finish_time']): ?>
                        <div class="split-time-main"><?= formatDisplayTime($result['finish_time']) ?></div>
                        <?php if ($isTimeRanked && !empty($result['time_behind']) && $result['time_behind'] !== '-'): ?>
                        <div class="split-time-details"><?= $result['time_behind'] ?></div>
                        <?php endif; ?>
                        <?php else: ?>
                        -
                        <?php endif; ?>
                    </td>
                    <?php foreach ($classSplits as $ss):
                        $splitTime = $result['ss' . $ss] ?? '';
                        $splitClass = '';
                        $splitRank = null;
                        $splitDiff = '';
                        $splitSeconds = !empty($splitTime) ? timeToSeconds($splitTime) : PHP_INT_MAX;

                        // Only show colors and rankings for competitive classes
                        if (!$isMotionOrKids) {
                            $splitRank = $result['split_rank_' . $ss] ?? null;
                            $splitDiff = $result['split_diff_' . $ss] ?? '';
                            if (!empty($splitTime) && isset($classData['split_stats'][$ss])) {
                                $stats = $classData['split_stats'][$ss];
                                $timeSeconds = timeToSeconds($splitTime);
                                if ($stats['range'] > 0.5) {
                                    $position = ($timeSeconds - $stats['min']) / $stats['range'];
                                    $level = min(10, max(1, floor($position * 9) + 1));
                                    $splitClass = 'split-' . $level;
                                }
                            }
                        }
                    ?>
                    <td class="col-split split-time-col table-col-hide-mobile <?= $splitClass ?>" data-sort-value="<?= $splitSeconds ?>">
                        <?php if (!empty($splitTime)): ?>
                        <div class="split-time-main"><?= formatDisplayTime($splitTime) ?></div>
                        <?php if (!$isMotionOrKids && ($splitDiff || $splitRank)): ?>
                        <div class="split-time-details"><?= $splitDiff ?: '' ?><?= $splitDiff && $splitRank ? ' ' : '' ?><?= $splitRank ? '(' . $splitRank . ')' : '' ?></div>
                        <?php endif; ?>
                        <?php else: ?>
                        -
                        <?php endif; ?>
                    </td>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Mobile Card View -->
    <div class="result-list">
        <?php foreach ($classData['results'] as $result):
            $searchData = strtolower($result['firstname'] . ' ' . $result['lastname'] . ' ' . ($result['club_name'] ?? ''));
        ?>
        <a href="/rider/<?= $result['rider_id'] ?>" class="result-item" data-search="<?= h($searchData) ?>">
            <div class="result-place <?= $result['class_position'] && $result['class_position'] <= 3 ? 'top-3' : '' ?>">
                <?php if (!$isTimeRanked): ?>
                    ✓
                <?php elseif ($result['status'] !== 'finished'): ?>
                    <span class="status-mini"><?= strtoupper(substr($result['status'], 0, 3)) ?></span>
                <?php elseif ($result['class_position'] == 1): ?>
                    <img src="/assets/icons/medal-1st.svg" alt="1:a" class="medal-icon-mobile">
                <?php elseif ($result['class_position'] == 2): ?>
                    <img src="/assets/icons/medal-2nd.svg" alt="2:a" class="medal-icon-mobile">
                <?php elseif ($result['class_position'] == 3): ?>
                    <img src="/assets/icons/medal-3rd.svg" alt="3:e" class="medal-icon-mobile">
                <?php else: ?>
                    <?= $result['class_position'] ?>
                <?php endif; ?>
            </div>
            <div class="result-info">
                <div class="result-name"><?= h($result['firstname'] . ' ' . $result['lastname']) ?></div>
                <div class="result-club"><?= h($result['club_name'] ?? '-') ?></div>
            </div>
            <div class="result-time-col">
                <?php if ($result['status'] === 'finished' && $result['finish_time']): ?>
                    <div class="time-value"><?= formatDisplayTime($result['finish_time']) ?></div>
                    <?php if ($isTimeRanked && !empty($result['time_behind'])): ?>
                        <div class="time-behind-small"><?= $result['time_behind'] ?></div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
</section>
<?php endforeach; ?>

<!-- Total Split Times Section (hidden by default, shown when toggle is active) -->
<?php if ($hasSplitTimes && !$isDH && count($globalSplitResults) > 0): ?>
<section class="card mb-lg total-split-section hidden" id="total-split-section">
    <div class="card-header">
        <div>
            <h2 class="card-title">Sträcktider Total</h2>
            <p class="card-subtitle"><?= count($globalSplitResults) ?> deltagare från alla klasser (exkl. Kids/Motion)</p>
        </div>
    </div>

    <!-- Desktop Table -->
    <div class="table-wrapper">
        <table class="table table--striped table--hover results-table" id="total-split-table">
            <thead>
                <tr>
                    <th class="col-place">#</th>
                    <th class="col-rider">Åkare</th>
                    <th class="col-club table-col-hide-mobile">Klubb</th>
                    <th class="col-class table-col-hide-mobile">Klass</th>
                    <?php foreach ($globalSplits as $ss): ?>
                    <th class="col-split split-time-col table-col-hide-mobile sortable-header" data-sort="ss<?= $ss ?>" onclick="sortTotalBySplit(this, <?= $ss ?>)"><?= $stageNames[$ss] ?? 'SS' . $ss ?> <span class="sort-icon"></span></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($globalSplitResults as $gIdx => $result):
                    $searchData = strtolower($result['firstname'] . ' ' . $result['lastname'] . ' ' . ($result['club_name'] ?? ''));
                ?>
                <tr class="result-row" onclick="window.location='/rider/<?= $result['rider_id'] ?>'" data-search="<?= h($searchData) ?>">
                    <td class="col-place total-position"><?= $gIdx + 1 ?></td>
                    <td class="col-rider">
                        <a href="/rider/<?= $result['rider_id'] ?>" class="rider-link">
                            <?= h($result['firstname'] . ' ' . $result['lastname']) ?>
                        </a>
                    </td>
                    <td class="col-club table-col-hide-mobile">
                        <?php if ($result['club_id']): ?>
                            <a href="/club/<?= $result['club_id'] ?>"><?= h($result['club_name'] ?? '-') ?></a>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                    <td class="col-class table-col-hide-mobile">
                        <span class="class-badge"><?= h($result['original_class'] ?? '-') ?></span>
                    </td>
                    <?php foreach ($globalSplits as $ss):
                        $splitTime = $result['ss' . $ss] ?? '';
                        $splitClass = '';
                        $splitRank = $result['global_split_rank_' . $ss] ?? null;
                        $splitDiff = $result['global_split_diff_' . $ss] ?? '';
                        $splitSeconds = !empty($splitTime) ? timeToSeconds($splitTime) : PHP_INT_MAX;
                        if (!empty($splitTime) && isset($globalSplitStats[$ss])) {
                            $stats = $globalSplitStats[$ss];
                            $timeSeconds = timeToSeconds($splitTime);
                            if ($stats['range'] > 0.5) {
                                $position = ($timeSeconds - $stats['min']) / $stats['range'];
                                $level = min(10, max(1, floor($position * 9) + 1));
                                $splitClass = 'split-' . $level;
                            }
                        }
                    ?>
                    <td class="col-split split-time-col table-col-hide-mobile <?= $splitClass ?>" data-sort-value="<?= $splitSeconds ?>">
                        <?php if (!empty($splitTime)): ?>
                        <div class="split-time-main"><?= formatDisplayTime($splitTime) ?></div>
                        <div class="split-time-details"><?= $splitDiff ?: '' ?><?= $splitDiff && $splitRank ? ' ' : '' ?><?= $splitRank ? '(' . $splitRank . ')' : '' ?></div>
                        <?php else: ?>
                        -
                        <?php endif; ?>
                    </td>
                    <?php endforeach; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Mobile Card View -->
    <div class="result-list">
        <?php foreach ($globalSplitResults as $gIdx => $result):
            $searchData = strtolower($result['firstname'] . ' ' . $result['lastname'] . ' ' . ($result['club_name'] ?? ''));
        ?>
        <a href="/rider/<?= $result['rider_id'] ?>" class="result-item result-item-splits" data-search="<?= h($searchData) ?>">
            <div class="result-place">
                <?= $gIdx + 1 ?>
            </div>
            <div class="result-info">
                <div class="result-name"><?= h($result['firstname'] . ' ' . $result['lastname']) ?></div>
                <div class="result-club"><?= h($result['club_name'] ?? '-') ?> &middot; <?= h($result['original_class'] ?? '') ?></div>
                <div class="result-splits-row">
                    <?php foreach ($globalSplits as $ss):
                        $splitTime = $result['ss' . $ss] ?? '';
                        $splitRank = $result['global_split_rank_' . $ss] ?? null;
                    ?>
                    <div class="result-split-item">
                        <span class="split-label"><?= $stageNames[$ss] ?? 'SS' . $ss ?></span>
                        <span class="split-value"><?= !empty($splitTime) ? formatDisplayTime($splitTime) : '-' ?></span>
                        <?php if ($splitRank): ?><span class="split-rank">(<?= $splitRank ?>)</span><?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<?php endif; ?>

<?php elseif ($activeTab === 'info'): ?>
<!-- INBJUDAN TAB - Invitation + Facilities & Logistics -->
<?php
// Get invitation content
$invitationText = getEventContent($event, 'invitation', 'invitation_use_global', $globalTextMap);

// Get all facility content
$hydrationInfo = getEventContent($event, 'hydration_stations', 'hydration_use_global', $globalTextMap);
$toiletsInfo = getEventContent($event, 'toilets_showers', 'toilets_use_global', $globalTextMap);
$bikeWashInfo = getEventContent($event, 'bike_wash', 'bike_wash_use_global', $globalTextMap);
$foodCafe = getEventContent($event, 'food_cafe', 'food_use_global', $globalTextMap);
$shopsInfo = getEventContent($event, 'shops_info', 'shops_use_global', $globalTextMap);
$exhibitorsInfo = getEventContent($event, 'exhibitors', 'exhibitors_use_global', $globalTextMap);
$parkingInfo = $event['parking_detailed'] ?? '';
$hotelInfo = $event['hotel_accommodation'] ?? '';
$localInfo = getEventContent($event, 'local_info', 'local_use_global', $globalTextMap);
$medicalInfo = getEventContent($event, 'medical_info', 'medical_use_global', $globalTextMap);
$mediaInfo = getEventContent($event, 'media_production', 'media_use_global', $globalTextMap);
$contactsInfo = getEventContent($event, 'contacts_info', 'contacts_use_global', $globalTextMap);
$hasFacilities = $hydrationInfo || $toiletsInfo || $bikeWashInfo || $foodCafe || $shopsInfo || $exhibitorsInfo || $parkingInfo || $hotelInfo || $localInfo || $medicalInfo || $mediaInfo || $contactsInfo;
?>

<?php if (!empty($invitationText)): ?>
<section class="card mb-lg">
    <div class="card-header">
        <h2 class="card-title">
            <i data-lucide="mail-open"></i>
            Inbjudan
        </h2>
    </div>
    <div class="card-body">
        <div class="prose"><?= nl2br(h($invitationText)) ?></div>
    </div>
</section>
<?php endif; ?>

<section class="card">
    <div class="card-header">
        <h2 class="card-title">
            <i data-lucide="info"></i>
            Faciliteter & Logistik
        </h2>
    </div>
    <div class="card-body">
        <?php if ($hasFacilities): ?>
        <div class="info-grid">
            <?php if (!empty($hydrationInfo)): ?>
            <div class="info-block">
                <h3><i data-lucide="droplets"></i> Vätskekontroller</h3>
                <p><?= nl2br(h($hydrationInfo)) ?></p>
            </div>
            <?php endif; ?>

            <?php if (!empty($toiletsInfo)): ?>
            <div class="info-block">
                <h3><i data-lucide="bath"></i> Toaletter/Dusch</h3>
                <p><?= nl2br(h($toiletsInfo)) ?></p>
            </div>
            <?php endif; ?>

            <?php if (!empty($bikeWashInfo)): ?>
            <div class="info-block">
                <h3><i data-lucide="sparkles"></i> Cykeltvätt</h3>
                <p><?= nl2br(h($bikeWashInfo)) ?></p>
            </div>
            <?php endif; ?>

            <?php if (!empty($foodCafe)): ?>
            <div class="info-block">
                <h3><i data-lucide="utensils"></i> Mat/Café</h3>
                <p><?= nl2br(h($foodCafe)) ?></p>
            </div>
            <?php endif; ?>

            <?php if (!empty($shopsInfo)): ?>
            <div class="info-block">
                <h3><i data-lucide="shopping-bag"></i> Affärer</h3>
                <p><?= nl2br(h($shopsInfo)) ?></p>
            </div>
            <?php endif; ?>

            <?php if (!empty($exhibitorsInfo)): ?>
            <div class="info-block">
                <h3><i data-lucide="store"></i> Utställare</h3>
                <p><?= nl2br(h($exhibitorsInfo)) ?></p>
            </div>
            <?php endif; ?>

            <?php if (!empty($parkingInfo)): ?>
            <div class="info-block">
                <h3><i data-lucide="car"></i> Parkering</h3>
                <p><?= nl2br(h($parkingInfo)) ?></p>
            </div>
            <?php endif; ?>

            <?php if (!empty($hotelInfo)): ?>
            <div class="info-block">
                <h3><i data-lucide="bed"></i> Hotell/Boende</h3>
                <p><?= nl2br(h($hotelInfo)) ?></p>
            </div>
            <?php endif; ?>

            <?php if (!empty($localInfo)): ?>
            <div class="info-block">
                <h3><i data-lucide="map-pin"></i> Lokal information</h3>
                <p><?= nl2br(h($localInfo)) ?></p>
            </div>
            <?php endif; ?>

            <?php if (!empty($medicalInfo)): ?>
            <div class="info-block">
                <h3><i data-lucide="heart-pulse"></i> Sjukvård</h3>
                <p><?= nl2br(h($medicalInfo)) ?></p>
            </div>
            <?php endif; ?>

            <?php if (!empty($mediaInfo)): ?>
            <div class="info-block">
                <h3><i data-lucide="camera"></i> Media</h3>
                <p><?= nl2br(h($mediaInfo)) ?></p>
            </div>
            <?php endif; ?>

            <?php if (!empty($contactsInfo)): ?>
            <div class="info-block">
                <h3><i data-lucide="phone"></i> Kontakter</h3>
                <p><?= nl2br(h($contactsInfo)) ?></p>
            </div>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <p class="text-muted">Ingen information tillgänglig för detta event ännu.</p>
        <?php endif; ?>
    </div>
</section>

<?php elseif ($activeTab === 'pm'): ?>
<!-- PM TAB - With subcategories -->
<?php
// Check if PM is published yet
if (!$pmPublished):
?>
<section class="card">
    <div class="card-body">
        <p class="text-muted text-center">PM publiceras <?= date('Y-m-d H:i', strtotime($event['pm_publish_at'])) ?></p>
    </div>
</section>
<?php else:
// Get all PM-related content
$pmContent = getEventContent($event, 'pm_content', 'pm_use_global', $globalTextMap);
$driverMeetingPM = getEventContent($event, 'driver_meeting', 'driver_meeting_use_global', $globalTextMap);
$trainingPM = getEventContent($event, 'training_info', 'training_use_global', $globalTextMap);
$timingPM = getEventContent($event, 'timing_info', 'timing_use_global', $globalTextMap);
$liftPM = getEventContent($event, 'lift_info', 'lift_use_global', $globalTextMap);
$rulesPM = getEventContent($event, 'competition_rules', 'rules_use_global', $globalTextMap);
$insurancePM = getEventContent($event, 'insurance_info', 'insurance_use_global', $globalTextMap);
$equipmentPM = getEventContent($event, 'equipment_info', 'equipment_use_global', $globalTextMap);
$scfPM = getEventContent($event, 'scf_representatives', 'scf_use_global', $globalTextMap);
$medicalPM = getEventContent($event, 'medical_info', 'medical_use_global', $globalTextMap);
$hasPMContent = $pmContent || $driverMeetingPM || $trainingPM || $timingPM || $liftPM || $rulesPM || $insurancePM || $equipmentPM || $scfPM || $medicalPM;
?>
<section class="card">
    <div class="card-header">
        <h2 class="card-title"><i data-lucide="clipboard-list"></i> PM (Promemoria)</h2>
    </div>
    <div class="card-body">
        <?php if ($pmContent): ?>
        <div class="prose mb-lg"><?= nl2br(h($pmContent)) ?></div>
        <?php endif; ?>

        <?php if ($driverMeetingPM || $trainingPM || $timingPM || $liftPM || $rulesPM || $insurancePM || $equipmentPM || $scfPM || $medicalPM): ?>
        <div class="info-grid">
            <?php if ($driverMeetingPM): ?>
            <div class="info-block">
                <h3><i data-lucide="megaphone"></i> Förarmöte</h3>
                <p><?= nl2br(h($driverMeetingPM)) ?></p>
            </div>
            <?php endif; ?>

            <?php if ($trainingPM): ?>
            <div class="info-block">
                <h3><i data-lucide="bike"></i> Träning</h3>
                <p><?= nl2br(h($trainingPM)) ?></p>
            </div>
            <?php endif; ?>

            <?php if ($timingPM): ?>
            <div class="info-block">
                <h3><i data-lucide="timer"></i> Tidtagning</h3>
                <p><?= nl2br(h($timingPM)) ?></p>
            </div>
            <?php endif; ?>

            <?php if ($liftPM): ?>
            <div class="info-block">
                <h3><i data-lucide="cable-car"></i> Lift</h3>
                <p><?= nl2br(h($liftPM)) ?></p>
            </div>
            <?php endif; ?>

            <?php if ($rulesPM): ?>
            <div class="info-block">
                <h3><i data-lucide="book-open"></i> Tävlingsregler</h3>
                <p><?= nl2br(h($rulesPM)) ?></p>
            </div>
            <?php endif; ?>

            <?php if ($insurancePM): ?>
            <div class="info-block">
                <h3><i data-lucide="shield-check"></i> Försäkring</h3>
                <p><?= nl2br(h($insurancePM)) ?></p>
            </div>
            <?php endif; ?>

            <?php if ($equipmentPM): ?>
            <div class="info-block">
                <h3><i data-lucide="hard-hat"></i> Utrustning</h3>
                <p><?= nl2br(h($equipmentPM)) ?></p>
            </div>
            <?php endif; ?>

            <?php if ($medicalPM): ?>
            <div class="info-block">
                <h3><i data-lucide="heart-pulse"></i> Sjukvård</h3>
                <p><?= nl2br(h($medicalPM)) ?></p>
            </div>
            <?php endif; ?>

            <?php if ($scfPM): ?>
            <div class="info-block">
                <h3><i data-lucide="badge-check"></i> SCF Representanter</h3>
                <p><?= nl2br(h($scfPM)) ?></p>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if (!$hasPMContent): ?>
        <p class="text-muted">Inget PM tillgängligt för detta event.</p>
        <?php endif; ?>
    </div>
</section>
<?php endif; // end pmPublished check ?>

<?php elseif ($activeTab === 'jury'): ?>
<!-- JURY TAB -->
<section class="card">
    <div class="card-header">
        <h2 class="card-title"><i data-lucide="gavel"></i> Jurykommuniké</h2>
    </div>
    <div class="card-body">
        <?php $juryContent = getEventContent($event, 'jury_communication', 'jury_use_global', $globalTextMap); ?>
        <?php if ($juryContent): ?>
            <div class="prose"><?= nl2br(h($juryContent)) ?></div>
        <?php else: ?>
            <p class="text-muted">Ingen jurykommuniké tillgänglig.</p>
        <?php endif; ?>
    </div>
</section>

<?php elseif ($activeTab === 'schema'): ?>
<!-- SCHEDULE TAB -->
<section class="card">
    <div class="card-header">
        <h2 class="card-title"><i data-lucide="calendar-clock"></i> Tävlingsschema</h2>
    </div>
    <div class="card-body">
        <?php $scheduleContent = getEventContent($event, 'competition_schedule', 'schedule_use_global', $globalTextMap); ?>
        <?php if ($scheduleContent): ?>
            <div class="prose"><?= nl2br(h($scheduleContent)) ?></div>
        <?php else: ?>
            <p class="text-muted">Inget tävlingsschema tillgängligt.</p>
        <?php endif; ?>
    </div>
</section>

<?php elseif ($activeTab === 'starttider'): ?>
<!-- START TIMES TAB -->
<section class="card">
    <div class="card-header">
        <h2 class="card-title"><i data-lucide="clock"></i> Starttider</h2>
    </div>
    <div class="card-body">
        <?php $startContent = getEventContent($event, 'start_times', 'start_times_use_global', $globalTextMap); ?>
        <?php if ($startContent): ?>
            <div class="prose"><?= nl2br(h($startContent)) ?></div>
        <?php else: ?>
            <p class="text-muted">Inga starttider publicerade ännu.</p>
        <?php endif; ?>
    </div>
</section>

<?php elseif ($activeTab === 'karta'): ?>
<!-- MAP TAB - Full width interactive map -->
<?php if ($hasInteractiveMap): ?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
<link rel="stylesheet" href="<?= hub_asset('css/map.css') ?>">
<?php
require_once ROOT_PATH . '/components/event-map.php';
render_event_map($eventId, $db, [
    'height' => 'min(calc(100vh - 250px), 600px)',
    'fullscreen' => false,
    'show_close' => false,
    'event_name' => $event['name'] ?? 'Event'
]);
?>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>
<script>if (typeof lucide !== 'undefined') lucide.createIcons();</script>
<script src="<?= hub_asset('js/event-map.js') ?>"></script>
<?php else: ?>
<p class="text-muted">Ingen interaktiv karta tillgänglig.</p>
<?php endif; ?>

<?php elseif ($activeTab === 'anmalda'): ?>
<!-- REGISTERED PARTICIPANTS TAB -->
<section class="card">
    <div class="card-header">
        <h2 class="card-title">
            <i data-lucide="users"></i>
            Anmälda deltagare
            <span class="badge badge--primary ml-sm"><?= $totalRegistrations ?></span>
        </h2>
    </div>
    <div class="card-body">
        <?php if (empty($registrations)): ?>
            <p class="text-muted">Inga anmälningar ännu.</p>
        <?php else: ?>
        <div class="table-wrapper">
            <table class="table table--striped">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Namn</th>
                        <th>Klubb</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($registrations as $index => $reg): ?>
                    <tr>
                        <td><?= $index + 1 ?></td>
                        <td><strong><?= h($reg['firstname'] . ' ' . $reg['lastname']) ?></strong></td>
                        <td><?= h($reg['club_name'] ?? '-') ?></td>
                        <td>
                            <?php
                            $statusClass = 'badge--secondary';
                            $statusText = ucfirst($reg['status']);
                            if ($reg['status'] === 'confirmed') {
                                $statusClass = 'badge--success';
                                $statusText = 'Bekräftad';
                            } elseif ($reg['status'] === 'pending') {
                                $statusClass = 'badge--warning';
                                $statusText = 'Väntande';
                            }
                            ?>
                            <span class="badge <?= $statusClass ?>"><?= $statusText ?></span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</section>

<?php elseif ($activeTab === 'biljetter'): ?>
<!-- TICKETS TAB -->
<section class="card">
    <div class="card-header">
        <h2 class="card-title"><i data-lucide="ticket"></i> Biljetter</h2>
    </div>
    <div class="card-body">
        <?php if (!$ticketingEnabled): ?>
            <p class="text-muted">Biljettförsäljning är inte aktiverad för detta event.</p>
        <?php else: ?>
            <p>Kontakta arrangören för biljettinformation.</p>
            <?php if (!empty($event['registration_url'])): ?>
            <a href="<?= h($event['registration_url']) ?>" target="_blank" class="btn btn--primary mt-md">
                <i data-lucide="external-link"></i>
                Extern anmälan
            </a>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>

<?php elseif ($activeTab === 'anmalan'): ?>
<!-- REGISTRATION TAB -->
<section class="card">
    <div class="card-header">
        <h2 class="card-title"><i data-lucide="user-plus"></i> Anmälan</h2>
    </div>
    <div class="card-body">
        <?php if (!$registrationOpen): ?>
            <div class="alert alert--warning">
                <strong>Anmälan stängd</strong>
                <?php if (!empty($event['registration_deadline'])): ?>
                <p>Anmälan stängde <?= date('j F Y', strtotime($event['registration_deadline'])) ?>.</p>
                <?php endif; ?>
            </div>
        <?php elseif (!empty($event['registration_url'])): ?>
            <p>Anmälan sker via extern länk:</p>
            <a href="<?= h($event['registration_url']) ?>" target="_blank" class="btn btn--primary mt-md">
                <i data-lucide="external-link"></i>
                Gå till anmälan
            </a>
        <?php else: ?>
            <p class="text-muted">Anmälningssystemet är inte konfigurerat för detta event ännu.</p>
        <?php endif; ?>
    </div>
</section>

<?php endif; ?>

<?php
// Partner sponsors (bottom of page - unlimited)
$partnerSponsorsWithLogos = array_filter($eventSponsors['partner'] ?? [], function($s) {
    return get_sponsor_logo_for_placement($s, 'content') !== null;
});
if (!empty($partnerSponsorsWithLogos)): ?>
<section class="event-partner-sponsors mt-xl mb-lg">
    <div class="partner-sponsors-header">
        <span class="partner-sponsors-label">Samarbetspartners</span>
    </div>
    <div class="partner-sponsors-grid">
        <?php foreach ($partnerSponsorsWithLogos as $sponsor):
            $partnerLogo = get_sponsor_logo_for_placement($sponsor, 'content');
        ?>
        <a href="<?= h($sponsor['website'] ?? '#') ?>" target="_blank" rel="noopener sponsored" class="partner-sponsor-item" title="<?= h($sponsor['name']) ?>">
            <img src="<?= h($partnerLogo) ?>" alt="<?= h($sponsor['name']) ?>">
        </a>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<script>
function filterResults() {
    const classFilter = document.getElementById('classFilter')?.value || 'all';
    const searchFilter = (document.getElementById('searchFilter')?.value || '').toLowerCase().trim();
    const totalViewEnabled = document.getElementById('totalViewToggle')?.checked || false;

    // If total view is enabled, only filter within total section
    if (totalViewEnabled) {
        const totalSection = document.getElementById('total-split-section');
        if (totalSection) {
            totalSection.querySelectorAll('.result-row, .result-item').forEach(row => {
                const searchData = row.dataset.search || '';
                const matchesSearch = !searchFilter || searchData.includes(searchFilter);
                row.style.display = matchesSearch ? '' : 'none';
            });
        }
        return;
    }

    document.querySelectorAll('.class-section').forEach(section => {
        const classId = section.dataset.class;
        const showClass = classFilter === 'all' || classFilter === classId;
        section.style.display = showClass ? '' : 'none';

        if (showClass) {
            section.querySelectorAll('.result-row, .result-item').forEach(row => {
                const searchData = row.dataset.search || '';
                const matchesSearch = !searchFilter || searchData.includes(searchFilter);
                row.style.display = matchesSearch ? '' : 'none';
            });
        }
    });
}

function toggleSplitColors(enabled) {
    document.querySelectorAll('.col-split').forEach(cell => {
        if (enabled) {
            cell.classList.remove('no-color');
        } else {
            cell.classList.add('no-color');
        }
    });
}

function toggleTotalView(enabled) {
    const classSections = document.querySelectorAll('.class-section');
    const totalSection = document.getElementById('total-split-section');
    const classFilter = document.getElementById('classFilter');

    if (enabled) {
        // Hide class sections, show total section
        classSections.forEach(section => section.classList.add('hidden'));
        if (totalSection) totalSection.classList.remove('hidden');
        // Disable class filter when in total view
        if (classFilter) {
            classFilter.disabled = true;
            classFilter.style.opacity = '0.5';
        }
    } else {
        // Show class sections, hide total section
        classSections.forEach(section => section.classList.remove('hidden'));
        if (totalSection) totalSection.classList.add('hidden');
        // Re-enable class filter
        if (classFilter) {
            classFilter.disabled = false;
            classFilter.style.opacity = '1';
        }
        // Re-apply filters
        filterResults();
    }
}

// Sort class section by split time
function sortBySplit(headerEl, classKey, splitNum) {
    const section = document.getElementById('class-' + classKey);
    if (!section) return;

    const table = section.querySelector('.results-table');
    if (!table) return;

    const tbody = table.querySelector('tbody');
    const rows = Array.from(tbody.querySelectorAll('tr.result-row'));

    // Get the column index for this split
    const headers = table.querySelectorAll('thead th');
    let colIndex = -1;
    headers.forEach((th, idx) => {
        if (th.dataset.sort === 'ss' + splitNum) {
            colIndex = idx;
        }
    });

    if (colIndex === -1) return;

    // Determine sort direction
    const isAscending = !headerEl.classList.contains('sort-asc');

    // Remove sort classes from all headers in this table
    table.querySelectorAll('.sortable-header').forEach(th => {
        th.classList.remove('sort-asc', 'sort-desc');
    });

    // Add sort class to clicked header
    headerEl.classList.add(isAscending ? 'sort-asc' : 'sort-desc');

    // Sort rows
    rows.sort((a, b) => {
        const aCell = a.cells[colIndex];
        const bCell = b.cells[colIndex];
        const aVal = parseFloat(aCell?.dataset.sortValue) || Infinity;
        const bVal = parseFloat(bCell?.dataset.sortValue) || Infinity;

        return isAscending ? aVal - bVal : bVal - aVal;
    });

    // Update position numbers and re-append rows
    rows.forEach((row, idx) => {
        const posCell = row.querySelector('.col-place');
        if (posCell && !posCell.querySelector('.status-badge') && !posCell.querySelector('.medal-icon') && posCell.textContent.trim() !== '✓') {
            // Only update numeric positions, not medals/status badges
            const pos = posCell.textContent.trim();
            if (!isNaN(parseInt(pos))) {
                // Keep original position, don't change
            }
        }
        tbody.appendChild(row);
    });
}

// Sort total section by split time
function sortTotalBySplit(headerEl, splitNum) {
    const table = document.getElementById('total-split-table');
    if (!table) return;

    const tbody = table.querySelector('tbody');
    const rows = Array.from(tbody.querySelectorAll('tr.result-row'));

    // Get the column index for this split
    const headers = table.querySelectorAll('thead th');
    let colIndex = -1;
    headers.forEach((th, idx) => {
        if (th.dataset.sort === 'ss' + splitNum) {
            colIndex = idx;
        }
    });

    if (colIndex === -1) return;

    // Determine sort direction
    const isAscending = !headerEl.classList.contains('sort-asc');

    // Remove sort classes from all headers
    table.querySelectorAll('.sortable-header').forEach(th => {
        th.classList.remove('sort-asc', 'sort-desc');
    });

    // Add sort class to clicked header
    headerEl.classList.add(isAscending ? 'sort-asc' : 'sort-desc');

    // Sort rows
    rows.sort((a, b) => {
        const aCell = a.cells[colIndex];
        const bCell = b.cells[colIndex];
        const aVal = parseFloat(aCell?.dataset.sortValue) || Infinity;
        const bVal = parseFloat(bCell?.dataset.sortValue) || Infinity;

        return isAscending ? aVal - bVal : bVal - aVal;
    });

    // Update position numbers and re-append rows
    rows.forEach((row, idx) => {
        const posCell = row.querySelector('.total-position');
        if (posCell) {
            posCell.textContent = idx + 1;
        }
        tbody.appendChild(row);
    });
}

</script>

<style>
/* Series branding colors - dynamic per event */
:root {
    --series-gradient-start: <?= htmlspecialchars($event['series_gradient_start'] ?? '#004A98') ?>;
    --series-gradient-end: <?= htmlspecialchars($event['series_gradient_end'] ?? '#002a5c') ?>;
}
</style>

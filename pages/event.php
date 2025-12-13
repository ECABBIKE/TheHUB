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

// Check if user is admin for edit button
$isAdmin = function_exists('is_admin') && is_admin();

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
    // Fetch event details with venue info
    $stmt = $db->prepare("
        SELECT
            e.*,
            s.id as series_id,
            s.name as series_name,
            s.logo as series_logo,
            v.name as venue_name,
            v.city as venue_city,
            v.address as venue_address
        FROM events e
        LEFT JOIN series s ON e.series_id = s.id
        LEFT JOIN venues v ON e.venue_id = v.id
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
    $eventSponsors = ['header' => [], 'content' => [], 'sidebar' => [], 'footer' => []];

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

    // If no series sponsors, fall back to event-specific sponsors
    if (empty($eventSponsors['header']) && empty($eventSponsors['content']) && empty($eventSponsors['sidebar'])) {
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
                $eventSponsors[$placement][] = $sponsor;
            }
        } catch (Exception $e) {
            // Table might not exist yet
        }
    }

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
        LEFT JOIN clubs c ON r.club_id = c.id
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

    // Check registration status
    $registrationOpen = !empty($event['registration_deadline']) && strtotime($event['registration_deadline']) >= time();

    // Determine active tab based on event state
    $hasResults = !empty($results);
    if ($registrationOpen) {
        $defaultTab = 'anmalan';
    } elseif ($hasResults) {
        $defaultTab = 'resultat';
    } else {
        $defaultTab = 'info';
    }
    $activeTab = isset($_GET['tab']) ? $_GET['tab'] : $defaultTab;

    // Ticketing info
    $ticketingEnabled = !empty($event['ticketing_enabled']);

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

<!-- Event Header -->
<section class="event-header mb-lg">
    <div class="event-header-content">
        <?php if ($event['series_logo']): ?>
        <div class="event-logo">
            <img src="<?= h($event['series_logo']) ?>" alt="<?= h($event['series_name'] ?? 'Serie') ?>">
        </div>
        <?php endif; ?>

        <div class="event-info">
            <div class="event-title-row">
                <h1 class="event-title"><?= h($event['name']) ?></h1>
                <?php if ($isAdmin): ?>
                <a href="/admin/event-edit.php?id=<?= $eventId ?>" class="btn-admin-edit" title="Redigera event">
                    <i data-lucide="pencil"></i>
                </a>
                <?php endif; ?>
            </div>

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
            <div class="event-stat">
                <span class="event-stat-value"><?= $totalParticipants ?></span>
                <span class="event-stat-label">deltagare</span>
            </div>
            <div class="event-stat">
                <span class="event-stat-value"><?= $totalFinished ?></span>
                <span class="event-stat-label">i mål</span>
            </div>
        </div>
    </div>
</section>

<?php if (!empty($eventSponsors['header'])): ?>
<!-- Sponsor Banner -->
<section class="event-sponsor-banner mb-lg">
    <?php foreach ($eventSponsors['header'] as $sponsor):
        $bannerLogo = get_sponsor_logo_for_placement($sponsor, 'header');
    ?>
    <a href="<?= h($sponsor['website'] ?? '#') ?>" target="_blank" rel="noopener sponsored" class="sponsor-banner-link">
        <?php if ($bannerLogo): ?>
        <img src="<?= h($bannerLogo) ?>" alt="<?= h($sponsor['name']) ?>" class="sponsor-banner-logo">
        <?php else: ?>
        <span class="sponsor-banner-name"><?= h($sponsor['name']) ?></span>
        <?php endif; ?>
    </a>
    <?php endforeach; ?>
</section>
<?php endif; ?>

<?php if (!empty($eventSponsors['content'])): ?>
<!-- Sponsor Logos Row -->
<section class="event-sponsor-logos mb-lg">
    <div class="sponsor-logos-label">Sponsorer</div>
    <div class="sponsor-logos-row">
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
<div class="event-tabs-wrapper mb-lg">
    <div class="event-tabs">
        <?php if ($registrationOpen): ?>
        <a href="?id=<?= $eventId ?>&tab=anmalan" class="event-tab <?= $activeTab === 'anmalan' ? 'active' : '' ?>">
            <i data-lucide="edit-3"></i>
            Anmälan
        </a>
        <?php endif; ?>

        <?php if ($hasResults): ?>
        <a href="?id=<?= $eventId ?>&tab=resultat" class="event-tab <?= $activeTab === 'resultat' ? 'active' : '' ?>">
            <i data-lucide="trophy"></i>
            Resultat
            <span class="tab-badge"><?= $totalParticipants ?></span>
        </a>
        <?php endif; ?>

        <a href="?id=<?= $eventId ?>&tab=info" class="event-tab <?= $activeTab === 'info' ? 'active' : '' ?>">
            <i data-lucide="info"></i>
            Information
        </a>

        <?php if (!empty($event['pm_content']) || !empty($event['pm_use_global'])): ?>
        <a href="?id=<?= $eventId ?>&tab=pm" class="event-tab <?= $activeTab === 'pm' ? 'active' : '' ?>">
            <i data-lucide="file-text"></i>
            PM
        </a>
        <?php endif; ?>

        <?php if (!empty($event['jury_communication']) || !empty($event['jury_use_global'])): ?>
        <a href="?id=<?= $eventId ?>&tab=jury" class="event-tab <?= $activeTab === 'jury' ? 'active' : '' ?>">
            <i data-lucide="scale"></i>
            Jury
        </a>
        <?php endif; ?>

        <?php if (!empty($event['competition_schedule']) || !empty($event['schedule_use_global'])): ?>
        <a href="?id=<?= $eventId ?>&tab=schema" class="event-tab <?= $activeTab === 'schema' ? 'active' : '' ?>">
            <i data-lucide="calendar-clock"></i>
            Schema
        </a>
        <?php endif; ?>

        <?php if (!empty($event['start_times']) || !empty($event['start_times_use_global'])): ?>
        <a href="?id=<?= $eventId ?>&tab=starttider" class="event-tab <?= $activeTab === 'starttider' ? 'active' : '' ?>">
            <i data-lucide="list-ordered"></i>
            Starttider
        </a>
        <?php endif; ?>

        <?php if ($hasInteractiveMap || !empty($event['map_content']) || !empty($event['map_image_url']) || !empty($event['map_use_global'])): ?>
        <a href="?id=<?= $eventId ?>&tab=karta" class="event-tab <?= $activeTab === 'karta' ? 'active' : '' ?>" onclick="if(window.innerWidth <= 768) { window.location.href='/map.php?id=<?= $eventId ?>'; return false; }">
            <i data-lucide="map-pin"></i>
            Karta
        </a>
        <?php endif; ?>

        <?php if (!$isPastEvent): ?>
        <a href="?id=<?= $eventId ?>&tab=anmalda" class="event-tab <?= $activeTab === 'anmalda' ? 'active' : '' ?>">
            <i data-lucide="users"></i>
            Anmälda
            <span class="tab-badge tab-badge--secondary"><?= $totalRegistrations ?></span>
        </a>
        <?php endif; ?>

        <?php if ($ticketingEnabled): ?>
        <a href="?id=<?= $eventId ?>&tab=biljetter" class="event-tab <?= $activeTab === 'biljetter' ? 'active' : '' ?>">
            <i data-lucide="ticket"></i>
            Biljetter
        </a>
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
    <div class="filter-field filter-field--toggle">
        <label class="toggle-label">
            <input type="checkbox" id="colorToggle" checked onchange="toggleSplitColors(this.checked)">
            <span class="toggle-text">Färgkodning</span>
            <span class="toggle-switch"></span>
        </label>
    </div>
    <?php if (count($globalSplitResults) > 0): ?>
    <div class="filter-field filter-field--toggle">
        <label class="toggle-label">
            <input type="checkbox" id="totalViewToggle" onchange="toggleTotalView(this.checked)">
            <span class="toggle-text">Sträcktider Total</span>
            <span class="toggle-switch"></span>
        </label>
    </div>
    <?php endif; ?>
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
        <?php if (!empty($eventSponsors['sidebar']) && isset($eventSponsors['sidebar'][0])):
            $resultSponsor = $eventSponsors['sidebar'][0];
            $smallLogo = get_sponsor_logo_for_placement($resultSponsor, 'sidebar');
        ?>
        <a href="<?= h($resultSponsor['website'] ?? '#') ?>" target="_blank" rel="noopener sponsored" class="class-sponsor">
            <span class="class-sponsor-label">Resultat sponsrat av</span>
            <?php if ($smallLogo): ?>
            <img src="<?= h($smallLogo) ?>" alt="<?= h($resultSponsor['name']) ?>" class="class-sponsor-logo">
            <?php else: ?>
            <span class="class-sponsor-name"><?= h($resultSponsor['name']) ?></span>
            <?php endif; ?>
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
                    <?php if ($isTimeRanked): ?>
                    <th class="col-gap table-col-hide-mobile">+Tid</th>
                    <?php endif; ?>
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
                        <?= ($result['status'] === 'finished' && $result['finish_time']) ? formatDisplayTime($result['finish_time']) : '-' ?>
                    </td>
                    <?php if ($isTimeRanked): ?>
                    <td class="col-gap table-col-hide-mobile"><?= $result['time_behind'] ?? '-' ?></td>
                    <?php endif; ?>
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
<section class="card mb-lg total-split-section" id="total-split-section" style="display: none;">
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
        <a href="/rider/<?= $result['rider_id'] ?>" class="result-item" data-search="<?= h($searchData) ?>">
            <div class="result-place">
                <?= $gIdx + 1 ?>
            </div>
            <div class="result-info">
                <div class="result-name"><?= h($result['firstname'] . ' ' . $result['lastname']) ?></div>
                <div class="result-club"><?= h($result['club_name'] ?? '-') ?> &middot; <?= h($result['original_class'] ?? '') ?></div>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<?php endif; ?>

<?php elseif ($activeTab === 'info'): ?>
<!-- INFORMATION TAB -->
<section class="card">
    <div class="card-header">
        <h2 class="card-title">
            <i data-lucide="info"></i>
            Faciliteter & Logistik
        </h2>
    </div>
    <div class="card-body">
        <div class="info-grid">
            <?php
            $driverMeeting = getEventContent($event, 'driver_meeting', 'driver_meeting_use_global', $globalTextMap);
            if (!empty($driverMeeting)): ?>
            <div class="info-block">
                <h3><i data-lucide="megaphone"></i> Förarmöte</h3>
                <p><?= nl2br(h($driverMeeting)) ?></p>
            </div>
            <?php endif; ?>

            <?php
            $trainingInfo = getEventContent($event, 'training_info', 'training_use_global', $globalTextMap);
            if (!empty($trainingInfo)): ?>
            <div class="info-block">
                <h3><i data-lucide="bike"></i> Träning</h3>
                <p><?= nl2br(h($trainingInfo)) ?></p>
            </div>
            <?php endif; ?>

            <?php
            $timingInfo = getEventContent($event, 'timing_info', 'timing_use_global', $globalTextMap);
            if (!empty($timingInfo)): ?>
            <div class="info-block">
                <h3><i data-lucide="timer"></i> Tidtagning</h3>
                <p><?= nl2br(h($timingInfo)) ?></p>
            </div>
            <?php endif; ?>

            <?php
            $liftInfo = getEventContent($event, 'lift_info', 'lift_use_global', $globalTextMap);
            if (!empty($liftInfo)): ?>
            <div class="info-block">
                <h3><i data-lucide="cable-car"></i> Lift</h3>
                <p><?= nl2br(h($liftInfo)) ?></p>
            </div>
            <?php endif; ?>

            <?php
            $foodCafe = getEventContent($event, 'food_cafe', 'food_use_global', $globalTextMap);
            if (!empty($foodCafe)): ?>
            <div class="info-block">
                <h3><i data-lucide="utensils"></i> Mat/Café</h3>
                <p><?= nl2br(h($foodCafe)) ?></p>
            </div>
            <?php endif; ?>

            <?php if (!empty($event['parking_detailed'])): ?>
            <div class="info-block">
                <h3><i data-lucide="car"></i> Parkering</h3>
                <p><?= nl2br(h($event['parking_detailed'])) ?></p>
            </div>
            <?php endif; ?>

            <?php if (!empty($event['hotel_accommodation'])): ?>
            <div class="info-block">
                <h3><i data-lucide="bed"></i> Hotell/Boende</h3>
                <p><?= nl2br(h($event['hotel_accommodation'])) ?></p>
            </div>
            <?php endif; ?>
        </div>

        <?php if (empty($driverMeeting) && empty($trainingInfo) && empty($timingInfo) && empty($liftInfo) && empty($foodCafe) && empty($event['parking_detailed']) && empty($event['hotel_accommodation'])): ?>
        <p class="text-muted">Ingen information tillgänglig för detta event ännu.</p>
        <?php endif; ?>
    </div>
</section>

<?php elseif ($activeTab === 'pm'): ?>
<!-- PM TAB -->
<section class="card">
    <div class="card-header">
        <h2 class="card-title"><i data-lucide="clipboard-list"></i> PM (Promemoria)</h2>
    </div>
    <div class="card-body">
        <?php $pmContent = getEventContent($event, 'pm_content', 'pm_use_global', $globalTextMap); ?>
        <?php if ($pmContent): ?>
            <div class="prose"><?= nl2br(h($pmContent)) ?></div>
        <?php else: ?>
            <p class="text-muted">Inget PM tillgängligt för detta event.</p>
        <?php endif; ?>
    </div>
</section>

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
<!-- MAP TAB -->
<section class="card">
    <div class="card-header">
        <h2 class="card-title"><i data-lucide="map"></i> Karta</h2>
    </div>
    <div class="card-body">
        <?php if ($hasInteractiveMap): ?>
        <!-- Interactive GPX Map -->
        <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
        <link rel="stylesheet" href="<?= hub_asset('css/map.css') ?>">
        <?php
        require_once ROOT_PATH . '/components/event-map.php';
        render_event_map($eventId, $db, [
            'height' => '550px',
            'fullscreen' => false,
            'show_close' => false,
            'event_name' => $event['name'] ?? 'Event'
        ]);
        ?>
        <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
        <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>
        <script>if (typeof lucide !== 'undefined') lucide.createIcons();</script>
        <script src="<?= hub_asset('js/event-map.js') ?>"></script>
        <?php if (!empty($event['map_image_url']) || !empty($mapContent)): ?>
        <hr style="margin: var(--space-lg) 0; border-color: var(--color-border);">
        <?php endif; ?>
        <?php endif; ?>

        <?php if (!empty($event['map_image_url'])): ?>
        <div class="map-image mb-lg">
            <img src="<?= h($event['map_image_url']) ?>" alt="Karta">
        </div>
        <?php endif; ?>

        <?php $mapContent = getEventContent($event, 'map_content', 'map_use_global', $globalTextMap); ?>
        <?php if ($mapContent): ?>
            <div class="prose mb-lg"><?= nl2br(h($mapContent)) ?></div>
        <?php endif; ?>

        <?php if (!empty($event['venue_coordinates'])): ?>
        <a href="https://www.google.com/maps?q=<?= urlencode($event['venue_coordinates']) ?>" target="_blank" class="btn btn--secondary">
            <i data-lucide="navigation"></i>
            Öppna i Google Maps
        </a>
        <?php endif; ?>

        <?php if (!$hasInteractiveMap && empty($event['map_image_url']) && empty($mapContent) && empty($event['venue_coordinates'])): ?>
            <p class="text-muted">Ingen karta tillgänglig.</p>
        <?php endif; ?>
    </div>
</section>

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
        classSections.forEach(section => section.style.display = 'none');
        if (totalSection) totalSection.style.display = '';
        // Disable class filter when in total view
        if (classFilter) {
            classFilter.disabled = true;
            classFilter.style.opacity = '0.5';
        }
    } else {
        // Show class sections, hide total section
        classSections.forEach(section => section.style.display = '');
        if (totalSection) totalSection.style.display = 'none';
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
/* Event Sponsor Banner - Full width ad banner
   Recommended image size: 1200x150px (PNG/WebP with transparent background)
*/
.event-sponsor-banner {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: var(--space-md);
    padding: var(--space-sm);
    background: var(--color-bg-card);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    overflow: hidden;
}
.sponsor-banner-link {
    display: flex;
    align-items: center;
    justify-content: center;
    text-decoration: none;
    transition: opacity 0.2s;
    width: 100%;
}
.sponsor-banner-link:hover { opacity: 0.85; }
.sponsor-banner-logo {
    max-height: 150px;
    max-width: 100%;
    width: auto;
    object-fit: contain;
}
.sponsor-banner-name {
    font-size: var(--text-lg);
    font-weight: var(--weight-semibold);
    color: var(--color-text-primary);
}

/* Event Sponsor Logos Row
   Recommended image size: 200x60px (PNG/WebP with transparent background)
*/
.event-sponsor-logos {
    display: flex;
    align-items: center;
    gap: var(--space-md);
    padding: var(--space-sm) var(--space-md);
    background: var(--color-bg-card);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    overflow-x: auto;
}
.sponsor-logos-label {
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--color-text-secondary);
    white-space: nowrap;
}
.sponsor-logos-row {
    display: flex;
    align-items: center;
    gap: var(--space-lg);
    flex-wrap: wrap;
}
.sponsor-logo-item {
    display: flex;
    align-items: center;
    text-decoration: none;
    transition: transform 0.2s;
}
.sponsor-logo-item:hover { transform: scale(1.05); }
.sponsor-logo-item img {
    max-height: 50px;
    max-width: 180px;
    object-fit: contain;
}
.sponsor-logo-item span {
    font-size: var(--text-sm);
    font-weight: var(--weight-medium);
    color: var(--color-text-secondary);
}

/* Class Results Sponsor
   Recommended image size: 160x40px (PNG/WebP with transparent background)
*/
.class-sponsor {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    text-decoration: none;
    padding: var(--space-xs) var(--space-sm);
    background: var(--color-bg-sunken);
    border-radius: var(--radius-sm);
    transition: background 0.2s;
}
.class-sponsor:hover { background: var(--color-border); }
.class-sponsor-label {
    font-size: 0.7rem;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    color: var(--color-text-secondary);
}
.class-sponsor-logo {
    max-height: 32px;
    max-width: 120px;
    object-fit: contain;
}
.class-sponsor-name {
    font-size: var(--text-sm);
    font-weight: var(--weight-semibold);
    color: var(--color-text-primary);
}

/* Event Header */
.event-header {
    background: var(--color-bg-card);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-lg);
    overflow: hidden;
}

.event-header::before {
    content: '';
    display: block;
    height: 4px;
    background: linear-gradient(90deg, var(--color-accent) 0%, #00A3E0 100%);
}

.event-header-content {
    display: flex;
    gap: var(--space-lg);
    padding: var(--space-lg);
    align-items: flex-start;
}

.event-logo {
    flex-shrink: 0;
}

.event-logo img {
    width: 80px;
    height: 80px;
    object-fit: contain;
    border-radius: var(--radius-md);
}

.event-info {
    flex: 1;
    min-width: 0;
}

.event-title-row {
    display: flex;
    align-items: flex-start;
    gap: var(--space-sm);
    margin-bottom: var(--space-sm);
}

.event-title {
    font-size: var(--text-xl);
    font-weight: var(--weight-bold);
    margin: 0;
    color: var(--color-text-primary);
    flex: 1;
}

.btn-admin-edit {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    background: var(--color-accent);
    color: white;
    border-radius: var(--radius-md);
    text-decoration: none;
    flex-shrink: 0;
    transition: all var(--transition-fast);
    opacity: 0.9;
}

.btn-admin-edit:hover {
    opacity: 1;
    transform: scale(1.05);
}

.btn-admin-edit i {
    width: 16px;
    height: 16px;
}

.event-meta {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-md);
    margin-bottom: var(--space-sm);
}

.event-meta-item {
    display: inline-flex;
    align-items: center;
    gap: var(--space-xs);
    font-size: var(--text-sm);
    color: var(--color-text-secondary);
}

.event-meta-item i {
    width: 16px;
    height: 16px;
}

.event-series-badge {
    display: inline-flex;
    align-items: center;
    gap: var(--space-xs);
    padding: var(--space-xs) var(--space-sm);
    background: var(--color-accent-light);
    color: var(--color-accent);
    border-radius: var(--radius-md);
    font-size: var(--text-sm);
    font-weight: var(--weight-medium);
    text-decoration: none;
}

.event-series-badge:hover {
    background: var(--color-accent);
    color: white;
}

.event-series-badge i {
    width: 14px;
    height: 14px;
}

.event-organizer {
    margin-top: var(--space-md);
    padding-top: var(--space-md);
    border-top: 1px solid var(--color-border);
}

.organizer-name {
    display: flex;
    align-items: center;
    gap: var(--space-xs);
    font-weight: var(--weight-semibold);
    color: var(--color-text-primary);
    margin-bottom: var(--space-xs);
}

.organizer-name i {
    width: 16px;
    height: 16px;
    color: var(--color-accent);
}

.organizer-links {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-md);
}

.organizer-links a {
    display: inline-flex;
    align-items: center;
    gap: var(--space-xs);
    font-size: var(--text-sm);
    color: var(--color-text-secondary);
}

.organizer-links a:hover {
    color: var(--color-accent);
}

.organizer-links i {
    width: 14px;
    height: 14px;
}

.event-stats {
    display: flex;
    gap: var(--space-sm);
    flex-shrink: 0;
    align-items: center;
}

/* Mobile series logo in stats row - hidden on desktop */
.event-stat--logo {
    display: none;
    padding: var(--space-xs);
    background: transparent;
}

.event-stat--logo img {
    width: 40px;
    height: 40px;
    object-fit: contain;
}

.event-stat {
    text-align: center;
    padding: var(--space-sm) var(--space-md);
    background: var(--color-bg-sunken);
    border-radius: var(--radius-md);
}

.event-stat-value {
    display: block;
    font-size: var(--text-xl);
    font-weight: var(--weight-bold);
    color: var(--color-accent);
}

.event-stat-label {
    font-size: var(--text-xs);
    color: var(--color-text-muted);
}

/* Tab Navigation */
.event-tabs-wrapper {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    margin: 0 calc(var(--space-md) * -1);
    padding: 0 var(--space-md);
}

.event-tabs {
    display: flex;
    gap: var(--space-xs);
    min-width: max-content;
    padding-bottom: var(--space-xs);
}

.event-tab {
    display: flex;
    align-items: center;
    gap: var(--space-xs);
    padding: var(--space-sm) var(--space-md);
    background: var(--color-bg-sunken);
    border-radius: var(--radius-md);
    color: var(--color-text-secondary);
    text-decoration: none;
    font-weight: var(--weight-medium);
    white-space: nowrap;
    transition: all var(--transition-fast);
    font-size: var(--text-sm);
}

.event-tab i,
.event-tab svg {
    width: 16px;
    height: 16px;
    flex-shrink: 0;
}

.event-tab:hover {
    background: var(--color-bg-hover);
    color: var(--color-text-primary);
}

.event-tab.active {
    background: var(--color-accent);
    color: white;
}

.tab-badge {
    padding: 2px 6px;
    background: rgba(255,255,255,0.2);
    border-radius: var(--radius-sm);
    font-size: var(--text-xs);
    font-weight: var(--weight-semibold);
}

.tab-badge--secondary {
    background: var(--color-bg-surface);
    color: var(--color-text-secondary);
}

.event-tab.active .tab-badge--secondary {
    background: rgba(255,255,255,0.2);
    color: white;
}

/* Filter Row */
.filter-row {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-md);
    align-items: flex-end;
}
.filter-row .filter-field:not(.filter-field--toggle) {
    flex: 1;
    min-width: 150px;
}

.filter-field {
    display: flex;
    flex-direction: column;
    gap: var(--space-xs);
}

.filter-label {
    font-size: var(--text-sm);
    font-weight: var(--weight-medium);
    color: var(--color-text-secondary);
}

.filter-select,
.filter-input {
    padding: var(--space-sm) var(--space-md);
    font-size: var(--text-sm);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    background: var(--color-bg-surface);
    color: var(--color-text-primary);
}

.filter-select:focus,
.filter-input:focus {
    outline: none;
    border-color: var(--color-accent);
}

.filter-field--toggle {
    display: flex;
    align-items: flex-end;
    padding-bottom: var(--space-sm);
}

.toggle-label {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    cursor: pointer;
    user-select: none;
}

.toggle-label input[type="checkbox"] {
    display: none;
}

.toggle-switch {
    position: relative;
    width: 44px;
    height: 24px;
    background: var(--color-bg-sunken);
    border: 1px solid var(--color-border);
    border-radius: 12px;
    transition: all var(--transition-fast);
}

.toggle-switch::after {
    content: '';
    position: absolute;
    top: 2px;
    left: 2px;
    width: 18px;
    height: 18px;
    background: white;
    border-radius: 50%;
    box-shadow: 0 1px 3px rgba(0,0,0,0.2);
    transition: all var(--transition-fast);
}

.toggle-label input:checked ~ .toggle-switch {
    background: var(--color-accent);
    border-color: var(--color-accent);
}

.toggle-label input:checked ~ .toggle-switch::after {
    left: 22px;
}

.toggle-text {
    font-size: var(--text-sm);
    font-weight: var(--weight-medium);
    color: var(--color-text-secondary);
}

/* Results table */
/* Horizontal scroll for tables with split times */
.table-wrapper {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}

.results-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
}

/* Sticky columns for place and rider name */
.results-table .col-place {
    position: sticky;
    left: 0;
    background: var(--color-bg-card);
    z-index: 2;
}

.results-table .col-rider {
    position: sticky;
    left: 50px;
    background: var(--color-bg-card);
    z-index: 2;
}

.results-table thead .col-place,
.results-table thead .col-rider {
    background: var(--color-bg-surface);
    z-index: 3;
}

/* Add shadow to indicate scrollable content */
.results-table .col-rider::after {
    content: '';
    position: absolute;
    top: 0;
    right: -8px;
    bottom: 0;
    width: 8px;
    background: linear-gradient(to right, rgba(0,0,0,0.08), transparent);
    pointer-events: none;
}

/* Results table columns */
.col-place {
    width: 50px;
    text-align: center;
    font-weight: var(--weight-bold);
}

td.col-place {
    vertical-align: middle;
}

td.col-place img,
td.col-place span,
td.col-place {
    display: table-cell;
    text-align: center;
}

.col-place--1 { color: #FFD700; }
.col-place--2 { color: #C0C0C0; }
.col-place--3 { color: #CD7F32; }

/* Medal icons */
.medal-icon {
    width: 28px;
    height: 28px;
    display: block;
    margin: 0 auto;
}
.medal-icon-mobile {
    width: 36px;
    height: 36px;
    display: block;
    margin: 0 auto;
}

.col-rider { min-width: 150px; }
.col-club { min-width: 100px; }

.col-time {
    min-width: 70px;
    text-align: right;
    font-family: var(--font-mono);
    white-space: nowrap;
}

.col-gap {
    min-width: 65px;
    text-align: right;
    font-family: var(--font-mono);
    font-size: var(--text-sm);
    color: var(--color-text-muted);
}

.col-split {
    min-width: 80px;
    text-align: right;
    font-family: var(--font-mono);
    font-size: var(--text-xs);
    vertical-align: middle;
}

/* Split time display with +time and ranking */
.split-time-main {
    font-weight: var(--weight-medium);
}

.split-time-details {
    font-size: 10px;
    color: var(--color-text-muted);
    margin-top: 1px;
    opacity: 0.85;
}

/* Sortable column headers */
.sortable-header {
    cursor: pointer;
    user-select: none;
    position: relative;
    transition: background var(--transition-fast);
}

.sortable-header:hover {
    background: var(--color-bg-hover);
}

.sortable-header .sort-icon {
    display: inline-block;
    width: 0;
    height: 0;
    margin-left: 4px;
    vertical-align: middle;
    opacity: 0.3;
}

.sortable-header.sort-asc .sort-icon {
    opacity: 1;
    border-left: 4px solid transparent;
    border-right: 4px solid transparent;
    border-bottom: 5px solid currentColor;
}

.sortable-header.sort-desc .sort-icon {
    opacity: 1;
    border-left: 4px solid transparent;
    border-right: 4px solid transparent;
    border-top: 5px solid currentColor;
}

/* Class badge for total view */
.col-class {
    min-width: 100px;
}

.class-badge {
    display: inline-block;
    padding: 2px 8px;
    font-size: var(--text-xs);
    font-weight: var(--weight-medium);
    background: var(--color-bg-sunken);
    border-radius: var(--radius-sm);
    color: var(--color-text-secondary);
}

.result-row {
    cursor: pointer;
}

.result-row:hover {
    background: var(--color-bg-hover);
}

/* Split time color coding (1=fastest green, 10=slowest red) */
.split-1 { background: rgba(34, 197, 94, 0.3); }
.split-2 { background: rgba(34, 197, 94, 0.2); }
.split-3 { background: rgba(132, 204, 22, 0.2); }
.split-4 { background: rgba(234, 179, 8, 0.15); }
.split-5 { background: rgba(234, 179, 8, 0.1); }
.split-6 { background: rgba(249, 115, 22, 0.1); }
.split-7 { background: rgba(249, 115, 22, 0.15); }
.split-8 { background: rgba(239, 68, 68, 0.15); }
.split-9 { background: rgba(239, 68, 68, 0.2); }
.split-10 { background: rgba(239, 68, 68, 0.3); }

.no-color.split-1, .no-color.split-2, .no-color.split-3,
.no-color.split-4, .no-color.split-5, .no-color.split-6,
.no-color.split-7, .no-color.split-8, .no-color.split-9,
.no-color.split-10 {
    background: transparent;
}

/* Status badges */
.status-badge {
    font-size: var(--text-xs);
    font-weight: var(--weight-semibold);
    padding: 2px 6px;
    border-radius: var(--radius-sm);
}

.status-dnf { background: rgba(239, 68, 68, 0.1); color: #ef4444; }
.status-dns { background: rgba(156, 163, 175, 0.1); color: #9ca3af; }
.status-dq { background: rgba(245, 158, 11, 0.1); color: #f59e0b; }

.status-mini {
    font-size: var(--text-xs);
    font-weight: var(--weight-bold);
    color: var(--color-text-muted);
}

.rider-link {
    color: var(--color-text-primary);
    font-weight: var(--weight-medium);
}

.rider-link:hover {
    color: var(--color-accent);
}

/* Mobile result cards */
.result-list {
    display: none;
}

.result-item {
    display: flex;
    align-items: center;
    gap: var(--space-md);
    padding: var(--space-sm);
    text-decoration: none;
    border-bottom: 1px solid var(--color-border);
}

.result-item:last-child {
    border-bottom: none;
}

.result-place {
    width: 40px;
    height: 40px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: var(--weight-bold);
    background: var(--color-bg-sunken);
    border-radius: var(--radius-md);
}

.result-place.top-3 {
    background: var(--color-accent-light);
}

.result-info {
    flex: 1;
    min-width: 0;
}

.result-name {
    font-weight: var(--weight-medium);
    color: var(--color-text-primary);
}

.result-club {
    font-size: var(--text-sm);
    color: var(--color-text-muted);
}

.result-time-col {
    text-align: right;
}

.time-value {
    font-family: var(--font-mono);
    font-weight: var(--weight-medium);
}

.time-behind-small {
    font-family: var(--font-mono);
    font-size: var(--text-xs);
    color: var(--color-text-muted);
}

/* Info grid */
.info-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: var(--space-md);
}

.info-block {
    background: var(--color-bg-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-lg);
    padding: var(--space-md);
    transition: border-color var(--transition-fast), box-shadow var(--transition-fast);
}

.info-block:hover {
    border-color: var(--color-accent);
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
}

.info-block h3 {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    font-size: var(--text-md);
    font-weight: var(--weight-semibold);
    color: var(--color-text-primary);
    margin: 0 0 var(--space-sm);
    padding-bottom: var(--space-sm);
    border-bottom: 1px solid var(--color-border);
}

.info-block h3 i {
    width: 20px;
    height: 20px;
    color: var(--color-accent);
    flex-shrink: 0;
}

.info-block p {
    color: var(--color-text-secondary);
    line-height: 1.6;
    margin: 0;
    font-size: var(--text-sm);
}

/* Map image */
.map-image img {
    max-width: 100%;
    height: auto;
    border-radius: var(--radius-md);
}

/* Prose */
.prose {
    color: var(--color-text-secondary);
    line-height: 1.7;
}

/* Empty state */
.empty-state {
    text-align: center;
    padding: var(--space-2xl);
}

.empty-state-icon {
    width: 48px;
    height: 48px;
    color: var(--color-text-muted);
    margin: 0 auto var(--space-md);
}

/* Badges */
.badge {
    display: inline-flex;
    align-items: center;
    padding: var(--space-xs) var(--space-sm);
    font-size: var(--text-xs);
    font-weight: var(--weight-semibold);
    border-radius: var(--radius-sm);
}

.badge--primary {
    background: var(--color-accent);
    color: white;
}

.badge--secondary {
    background: var(--color-bg-sunken);
    color: var(--color-text-secondary);
}

.badge--success {
    background: rgba(34, 197, 94, 0.1);
    color: #22c55e;
}

.badge--warning {
    background: rgba(234, 179, 8, 0.1);
    color: #eab308;
}

/* Alerts */
.alert {
    padding: var(--space-md);
    border-radius: var(--radius-md);
    margin-bottom: var(--space-md);
}

.alert--warning {
    background: rgba(234, 179, 8, 0.1);
    border: 1px solid rgba(234, 179, 8, 0.3);
    color: var(--color-text-primary);
}

.alert--error {
    background: rgba(239, 68, 68, 0.1);
    border: 1px solid rgba(239, 68, 68, 0.3);
}

/* Button */
.btn {
    display: inline-flex;
    align-items: center;
    gap: var(--space-xs);
    padding: var(--space-sm) var(--space-md);
    font-size: var(--text-sm);
    font-weight: var(--weight-medium);
    border-radius: var(--radius-md);
    text-decoration: none;
    cursor: pointer;
    border: none;
    transition: all var(--transition-fast);
}

.btn--primary {
    background: var(--color-accent);
    color: white;
}

.btn--primary:hover {
    opacity: 0.9;
}

.btn--secondary {
    background: var(--color-bg-sunken);
    color: var(--color-text-primary);
    border: 1px solid var(--color-border);
}

.btn--secondary:hover {
    border-color: var(--color-accent);
    color: var(--color-accent);
}

.btn i {
    width: 16px;
    height: 16px;
}

/* Utilities */
.mb-sm { margin-bottom: var(--space-sm); }
.mb-md { margin-bottom: var(--space-md); }
.mb-lg { margin-bottom: var(--space-lg); }
.mt-md { margin-top: var(--space-md); }
.ml-sm { margin-left: var(--space-sm); }
.text-muted { color: var(--color-text-muted); }
.font-bold { font-weight: var(--weight-bold); }

/* Responsive */
@media (max-width: 768px) {
    .event-header-content {
        flex-direction: column;
    }

    /* Hide big logo on tablet/mobile - use inline version in stats */
    .event-logo {
        display: none;
    }

    /* Show series logo inline with stats on tablet/mobile */
    .event-stat--logo {
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .event-stats {
        width: 100%;
        justify-content: center;
        padding-top: var(--space-md);
        border-top: 1px solid var(--color-border);
    }

    .filter-row {
        flex-direction: column;
        align-items: stretch;
    }
    .filter-row .filter-field:not(.filter-field--toggle) {
        flex: none;
        width: 100%;
    }

    .filter-field--toggle {
        padding-bottom: 0;
        padding-top: var(--space-xs);
    }

    .info-grid {
        grid-template-columns: 1fr;
    }

    .table-col-hide-mobile {
        display: none;
    }
}

/* Mobile portrait: hide table, show cards, hide splits */
@media (max-width: 599px) and (orientation: portrait) {
    .table-wrapper {
        display: none;
    }

    .result-list {
        display: block;
    }

    .event-title {
        font-size: var(--text-lg);
    }

    /* Compact event header on mobile */
    .event-header-content {
        padding: var(--space-md);
        gap: var(--space-sm);
    }

    .event-logo {
        display: none; /* Hide big logo on mobile - small version shown in stats row */
    }

    /* Show series logo in stats row on mobile */
    .event-stat--logo {
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .event-meta {
        gap: var(--space-xs);
        margin-bottom: var(--space-xs);
    }

    .event-meta-item {
        font-size: var(--text-xs);
    }

    .event-organizer {
        display: none; /* Hide organizer section on mobile - available in info tab */
    }

    .event-stats {
        padding-top: var(--space-sm);
        gap: var(--space-xs);
    }

    .event-stat {
        padding: var(--space-xs) var(--space-sm);
    }

    .event-stat-value {
        font-size: var(--text-md);
    }

    .event-stat-label {
        font-size: 0.65rem;
    }

    /* Compact tabs */
    .event-tabs-wrapper {
        margin-bottom: var(--space-sm);
    }

    .event-tab {
        padding: var(--space-xs) var(--space-sm);
        font-size: var(--text-xs);
    }

    .event-tab i {
        display: none; /* Hide icons in tabs on mobile */
    }
}

/* Mobile landscape: show table with splits */
@media (max-width: 900px) and (orientation: landscape) {
    .result-list {
        display: none;
    }

    .table-wrapper {
        display: block;
    }

    .table-col-hide-mobile {
        display: table-cell;
    }

    .split-time-col {
        display: table-cell;
    }

    .event-title {
        font-size: var(--text-lg);
    }
}

/* Small screens in portrait: hide splits in table too */
@media (max-width: 900px) and (orientation: portrait) {
    .split-time-col {
        display: none;
    }
}
</style>

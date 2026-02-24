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
        // Treat "0:00", "0:00:00" etc as invalid (no time recorded)
        if (preg_match('/^0+[:.]?0*[:.]?0*$/', $time)) return PHP_INT_MAX;
        // Treat status values as invalid (including 'finished' that may end up in time columns)
        if (in_array(strtolower(trim($time)), ['dns', 'dnf', 'dq', 'dsq', 'finished', 'fin', 'finish', 'finnished', 'ok'])) return PHP_INT_MAX;
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
        $total = $seconds + $decimal;
        // Return invalid for times under 1 second (impossible for DH)
        return $total < 1 ? PHP_INT_MAX : $total;
    }
}

if (!function_exists('formatDisplayTime')) {
    function formatDisplayTime($time) {
        if (empty($time)) return null;
        // Treat "0:00" etc as no time
        if (preg_match('/^0+[:.]?0*[:.]?0*$/', $time)) return null;
        // Treat status values as no time (including 'finished' that may end up in time columns)
        if (in_array(strtolower(trim($time)), ['dns', 'dnf', 'dq', 'dsq', 'finished', 'fin', 'finish', 'finnished', 'ok'])) return null;
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
    function getEventContent($event, $field, $useGlobalField, $globalTextMap, $hiddenField = null) {
        // Check if content is hidden
        if ($hiddenField && !empty($event[$hiddenField])) {
            return '';
        }
        if (!empty($event[$useGlobalField]) && !empty($globalTextMap[$field])) {
            return $globalTextMap[$field];
        }
        return $event[$field] ?? '';
    }
}

try {
    // Fetch event details with venue info
    // Note: Uses only core columns that exist in base schema
    // organizer_club_id might not exist if migration 053 not run
    $stmt = $db->prepare("
        SELECT
            e.*,
            s.id as series_id,
            s.name as series_name,
            s.logo as series_logo,
            s.gradient_start as series_gradient_start,
            s.gradient_end as series_gradient_end,
            s.organizer as series_organizer,
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

    // Try to get organizer club info if the column exists
    if ($event && isset($event['organizer_club_id']) && $event['organizer_club_id']) {
        try {
            $clubStmt = $db->prepare("SELECT id, name FROM clubs WHERE id = ?");
            $clubStmt->execute([$event['organizer_club_id']]);
            $orgClub = $clubStmt->fetch(PDO::FETCH_ASSOC);
            if ($orgClub) {
                $event['organizer_club_name'] = $orgClub['name'];
                $event['organizer_club_id_ref'] = $orgClub['id'];
            }
        } catch (PDOException $e) {
            // Column or table doesn't exist
        }
    }

    if (!$event) {
        include HUB_ROOT . '/pages/404.php';
        return;
    }

    // Fetch global texts for use_global functionality
    $globalTextMap = [];
    try {
        $globalTexts = $db->query("SELECT field_key, content FROM global_texts WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($globalTexts as $gt) {
            $globalTextMap[$gt['field_key']] = $gt['content'];
        }
    } catch (PDOException $e) {
        // Table might not exist yet
    }

    // Load info links per section for this event (migration 057+058)
    $eventInfoLinks = ['general' => [], 'regulations' => [], 'licenses' => []];
    try {
        $linkStmt = $db->prepare("SELECT section, link_url, link_text FROM event_info_links WHERE event_id = ? ORDER BY sort_order, id");
        $linkStmt->execute([$eventId]);
        $allLinks = $linkStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($allLinks as $link) {
            $sec = $link['section'] ?? 'general';
            $eventInfoLinks[$sec][] = $link;
        }
    } catch (PDOException $e) {
        // Table may not exist yet - fall back to single-link columns
        $singleUrl = $event['general_competition_link_url'] ?? '';
        if (!empty($singleUrl)) {
            $eventInfoLinks['general'][] = ['link_url' => $singleUrl, 'link_text' => $event['general_competition_link_text'] ?? ''];
        }
    }

    // Load global text links (migration 058)
    $globalTextLinksMap = [];
    try {
        $gtlStmt = $db->prepare("SELECT field_key, link_url, link_text FROM global_text_links ORDER BY field_key, sort_order, id");
        $gtlStmt->execute();
        foreach ($gtlStmt->fetchAll(PDO::FETCH_ASSOC) as $gtl) {
            $globalTextLinksMap[$gtl['field_key']][] = $gtl;
        }
    } catch (PDOException $e) {
        // Table may not exist yet
    }

    // Check for interactive map (GPX data)
    require_once INCLUDES_PATH . '/map_functions.php';
    $hasInteractiveMap = eventHasMap($db, $eventId);

    // Check for race reports/news linked to this event
    $eventReportsCount = 0;
    try {
        $reportsStmt = $db->prepare("SELECT COUNT(*) FROM race_reports WHERE event_id = ? AND status = 'published'");
        $reportsStmt->execute([$eventId]);
        $eventReportsCount = (int)$reportsStmt->fetchColumn();
    } catch (PDOException $e) {
        // Table might not exist yet
    }

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

    // Check if this is a Dual Slalom event (discipline = DS or has elimination data with series_class_id)
    $isDS = ($event['discipline'] ?? '') === 'DS';
    if (!$isDS) {
        // Also check if results have series_class_id set (indicating DS-style import)
        $dsCheck = $db->prepare("SELECT COUNT(*) as cnt FROM results WHERE event_id = ? AND series_class_id IS NOT NULL");
        $dsCheck->execute([$eventId]);
        $dsRow = $dsCheck->fetch(PDO::FETCH_ASSOC);
        $isDS = ($dsRow && (int)$dsRow['cnt'] > 0);
    }

    // DEBUG: Log DS detection
    error_log("EVENT {$eventId}: isDS=" . ($isDS ? 'true' : 'false') . ", discipline=" . ($event['discipline'] ?? 'null'));

    // For DH events, calculate run time stats for color coding
    $dhRunStats = [];
    if ($isDH) {
        // Will be populated per class later
    }

    // Fetch all results for this event
    // For DS events: sort by position (elimination result), NULL positions last
    // For other events: sort by finish_time
    $orderBy = $isDS
        ? "cls.sort_order ASC,
           COALESCE(cls.name, 'Oklassificerad'),
           CASE WHEN res.status = 'finished' THEN 0 ELSE 1 END,
           CASE WHEN res.position IS NULL OR res.position = 0 THEN 9999 ELSE res.position END ASC"
        : "cls.sort_order ASC,
           COALESCE(cls.name, 'Oklassificerad'),
           CASE WHEN res.status = 'finished' THEN 0 ELSE 1 END,
           res.finish_time ASC";

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
        ORDER BY {$orderBy}
    ");
    $stmt->execute([$eventId]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Normalize status values (e.g., 'FIN' -> 'finished')
    // ALWAYS set normalized lowercase status to ensure sorting works correctly
    foreach ($results as &$result) {
        $status = strtolower(trim($result['status'] ?? ''));
        if (in_array($status, ['fin', 'finish', 'finished', 'finnished', 'ok', ''])) {
            $result['status'] = 'finished';
        } elseif (in_array($status, ['dnf', 'did not finish'])) {
            $result['status'] = 'dnf';
        } elseif (in_array($status, ['dns', 'did not start'])) {
            $result['status'] = 'dns';
        } elseif (in_array($status, ['dq', 'dsq', 'disqualified'])) {
            $result['status'] = 'dq';
        } else {
            // Unknown status - keep as-is but ensure lowercase
            $result['status'] = $status;
        }
    }
    unset($result); // Break reference

    // Status values that should NOT count as valid split times
    $invalidSplitValues = ['dns', 'dnf', 'dq', 'dsq', 'finished', 'fin', 'finish', 'ok', 'did not finish', 'did not start', 'disqualified', '0:00', '0:00.00', '00:00:00', ''];

    // Check if any results have split times (excluding status values in split columns)
    $hasSplitTimes = false;
    foreach ($results as $result) {
        for ($i = 1; $i <= 15; $i++) {
            $splitVal = $result['ss' . $i] ?? '';
            if (!empty($splitVal) && !in_array(strtolower(trim($splitVal)), $invalidSplitValues)) {
                $hasSplitTimes = true;
                break 2;
            }
        }
    }

    // Check if DH event has run 2 data (exclude status values that may have ended up in time columns)
    $hasRun2Data = false;
    if ($isDH) {
        foreach ($results as $result) {
            $run2Val = strtolower(trim($result['run_2_time'] ?? ''));
            if (!empty($run2Val) && !in_array($run2Val, $invalidSplitValues)) {
                $hasRun2Data = true;
                break;
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

        // For DS events: sort by stored position (elimination result), don't re-sort by time
        if ($isDS) {
            usort($classData['results'], function($a, $b) {
                // Finished riders first
                if ($a['status'] === 'finished' && $b['status'] !== 'finished') return -1;
                if ($a['status'] !== 'finished' && $b['status'] === 'finished') return 1;

                // Sort by position (from database)
                $aPos = (int)($a['position'] ?? 9999);
                $bPos = (int)($b['position'] ?? 9999);
                if ($aPos === 0) $aPos = 9999;
                if ($bPos === 0) $bPos = 9999;
                return $aPos <=> $bPos;
            });
        } else {
            // Standard sorting for non-DS events
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
        }

        // Calculate positions and time behind
        $position = 0;
        $winnerSeconds = 0;
        foreach ($classData['results'] as &$result) {
            // For DS events: use the stored position directly
            if ($isDS) {
                $storedPos = (int)($result['position'] ?? 0);
                $result['class_position'] = ($storedPos > 0) ? $storedPos : null;
                $result['time_behind'] = null; // No time comparison for DS
                continue;
            }

            if ($rankingType !== 'time') {
                $result['class_position'] = null;
                $result['time_behind'] = null;
                continue;
            }

            // Check if rider is actually finished with a valid time
            // Some imports mark DNS riders as 'finished' but without a time
            // For DH events, also check run_1_time and run_2_time
            $hasValidTime = false;
            $invalidTimes = ['DNS', 'DNF', 'DQ', 'DSQ', '0:00', '0:00:00', '0:00.00', ''];

            if (!empty($result['finish_time']) && !in_array(strtoupper($result['finish_time']), $invalidTimes)) {
                $hasValidTime = true;
            } elseif (!empty($result['run_1_time']) && !in_array(strtoupper($result['run_1_time']), $invalidTimes)) {
                $hasValidTime = true;
            } elseif (!empty($result['run_2_time']) && !in_array(strtoupper($result['run_2_time']), $invalidTimes)) {
                $hasValidTime = true;
            } elseif (!empty($result['ss1']) && !in_array(strtoupper($result['ss1']), $invalidTimes)) {
                $hasValidTime = true;
            }

            if ($result['status'] === 'finished' && $hasValidTime) {
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

        // Calculate DH run time stats for color coding
        if ($isDH && !$isMotionOrKids) {
            foreach (['run_1_time', 'run_2_time'] as $runKey) {
                $times = [];
                foreach ($classData['results'] as $result) {
                    if (!empty($result[$runKey]) && $result['status'] === 'finished') {
                        $times[] = timeToSeconds($result[$runKey]);
                    }
                }
                if (count($times) >= 2) {
                    sort($times);
                    $min = $times[0];
                    $max = $times[count($times) - 1];
                    // Cap outliers at 90th percentile + 30%
                    if (count($times) >= 3) {
                        $p90Index = (int) floor(count($times) * 0.9);
                        $p90 = $times[$p90Index];
                        if ($max > $p90 * 1.3) {
                            $max = $p90;
                        }
                    }
                    $classData['dh_run_stats'][$runKey] = [
                        'min' => $min,
                        'max' => $max,
                        'range' => $max - $min
                    ];
                }
            }

            // Calculate run rankings and time behind for each rider
            foreach (['run_1_time', 'run_2_time'] as $runIdx => $runKey) {
                $runTimes = [];
                foreach ($classData['results'] as $idx => $result) {
                    if (!empty($result[$runKey]) && $result['status'] === 'finished') {
                        $seconds = timeToSeconds($result[$runKey]);
                        // Only include valid times (not 0:00 or empty)
                        if ($seconds < PHP_INT_MAX) {
                            $runTimes[] = [
                                'idx' => $idx,
                                'time' => $seconds
                            ];
                        }
                    }
                }

                if (count($runTimes) > 0) {
                    usort($runTimes, function($a, $b) {
                        return $a['time'] <=> $b['time'];
                    });

                    $bestTime = $runTimes[0]['time'];

                    foreach ($runTimes as $rank => $entry) {
                        $ridx = $entry['idx'];
                        $classData['results'][$ridx]['run_rank_' . ($runIdx + 1)] = $rank + 1;
                        $diff = $entry['time'] - $bestTime;
                        if ($diff > 0) {
                            $classData['results'][$ridx]['run_diff_' . ($runIdx + 1)] = '+' . number_format($diff, 2);
                        } else {
                            $classData['results'][$ridx]['run_diff_' . ($runIdx + 1)] = '';
                        }
                    }
                }
            }
        }

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
                        $timeSeconds = timeToSeconds($result['ss' . $ss]);
                        // Only include valid times (not PHP_INT_MAX from invalid data)
                        if ($timeSeconds < PHP_INT_MAX) {
                            $splitTimes[] = [
                                'idx' => $idx,
                                'time' => $timeSeconds
                            ];
                        }
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
                    $timeSeconds = timeToSeconds($result['ss' . $ss]);
                    // Only include valid times (not PHP_INT_MAX from invalid data)
                    if ($timeSeconds < PHP_INT_MAX) {
                        $splitTimes[] = [
                            'idx' => $idx,
                            'time' => $timeSeconds
                        ];
                    }
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

    // Calculate global DH run stats for "Åktider Total" view (DH equivalent of Sträcktider Total)
    $globalDHResults = [];
    $globalDHStats = [];

    if ($isDH) {
        // Collect all results from competitive DH classes (excluding motion/kids)
        foreach ($resultsByClass as $classKey => $classData) {
            $rankingType = $classData['ranking_type'] ?? 'time';
            $className = strtolower($classData['class_name'] ?? '');

            // Skip motion classes and kids classes
            if ($rankingType !== 'time') continue;
            if (strpos($className, 'kids') !== false || strpos($className, 'barn') !== false) continue;
            if (strpos($className, 'motion') !== false) continue;

            foreach ($classData['results'] as $result) {
                if ($result['status'] === 'finished') {
                    $globalDHResults[] = array_merge($result, [
                        'original_class' => $classData['display_name']
                    ]);
                }
            }
        }

        // Calculate global run stats and rankings for run 1 and run 2
        foreach (['run_1_time' => 1, 'run_2_time' => 2] as $runKey => $runNum) {
            $runTimes = [];
            foreach ($globalDHResults as $idx => $result) {
                if (!empty($result[$runKey])) {
                    $seconds = timeToSeconds($result[$runKey]);
                    // Only include valid times (not 0:00 or empty)
                    if ($seconds < PHP_INT_MAX) {
                        $runTimes[] = [
                            'idx' => $idx,
                            'time' => $seconds
                        ];
                    }
                }
            }

            if (count($runTimes) > 0) {
                usort($runTimes, function($a, $b) {
                    return $a['time'] <=> $b['time'];
                });

                $bestTime = $runTimes[0]['time'];
                $times = array_column($runTimes, 'time');
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

                $globalDHStats[$runKey] = [
                    'min' => $min,
                    'max' => $max,
                    'range' => $max - $min
                ];

                // Assign global rankings
                foreach ($runTimes as $rank => $entry) {
                    $ridx = $entry['idx'];
                    $globalDHResults[$ridx]['global_run_rank_' . $runNum] = $rank + 1;
                    $diff = $entry['time'] - $bestTime;
                    if ($diff > 0) {
                        $globalDHResults[$ridx]['global_run_diff_' . $runNum] = '+' . number_format($diff, 2);
                    } else {
                        $globalDHResults[$ridx]['global_run_diff_' . $runNum] = '';
                    }
                }
            }
        }

        // Sort global DH results by run 1 time by default
        usort($globalDHResults, function($a, $b) {
            $aTime = !empty($a['run_1_time']) ? timeToSeconds($a['run_1_time']) : PHP_INT_MAX;
            $bTime = !empty($b['run_1_time']) ? timeToSeconds($b['run_1_time']) : PHP_INT_MAX;
            return $aTime <=> $bTime;
        });
    }

    // Fetch registrations with class info (only paid registrations shown publicly)
    $registrations = $db->prepare("
        SELECT reg.*, r.firstname, r.lastname, r.birth_year, c.name as club_name,
               COALESCE(cl.display_name, cl.name, reg.category) as class_name,
               cl.sort_order as class_sort_order
        FROM event_registrations reg
        LEFT JOIN riders r ON reg.rider_id = r.id
        LEFT JOIN clubs c ON r.club_id = c.id
        LEFT JOIN classes cl ON cl.name = reg.category
        WHERE reg.event_id = ? AND reg.status NOT IN ('cancelled') AND reg.payment_status = 'paid'
        ORDER BY cl.sort_order ASC, cl.name ASC, reg.bib_number ASC, reg.registration_date ASC
    ");
    $registrations->execute([$eventId]);
    $registrations = $registrations->fetchAll(PDO::FETCH_ASSOC);
    $totalRegistrations = count($registrations);

    // Check if bib numbers have been assigned (for tab name: Anmälda vs Startlista)
    $hasBibNumbers = false;
    foreach ($registrations as $reg) {
        if (!empty($reg['bib_number'])) {
            $hasBibNumbers = true;
            break;
        }
    }

    // Group registrations by class (preserve sort_order)
    $registrationsByClass = [];
    $classSortOrders = []; // Track sort_order for each class
    foreach ($registrations as $reg) {
        $className = $reg['class_name'] ?: $reg['category'] ?: 'Okänd klass';
        if (!isset($registrationsByClass[$className])) {
            $registrationsByClass[$className] = [];
            // Store sort_order for this class (use 9999 if null, to sort unknowns last)
            $classSortOrders[$className] = $reg['class_sort_order'] ?? 9999;
        }
        $registrationsByClass[$className][] = $reg;
    }

    // Sort classes by sort_order
    uksort($registrationsByClass, function($a, $b) use ($classSortOrders) {
        return ($classSortOrders[$a] ?? 9999) <=> ($classSortOrders[$b] ?? 9999);
    });

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

    // Registration is only "open" if deadline is set, hasn't passed, AND pricing is configured
    // Check if event has pricing configured (via template or external registration)
    $hasPricing = !empty($event['pricing_template_id']) || !empty($event['registration_url']);

    // Registration tab is shown if: has pricing configured AND either has valid deadline OR has external URL
    $registrationConfigured = $hasPricing;
    $registrationOpen = $registrationConfigured && ($registrationDeadline === null || $registrationDeadline >= time());

    // Check capacity (max participants)
    $maxParticipants = !empty($event['max_participants']) ? intval($event['max_participants']) : null;
    $registrationFull = false;
    $confirmedRegistrations = 0;
    if ($maxParticipants) {
        // Count non-cancelled registrations (pending + confirmed)
        $capStmt = $db->prepare("SELECT COUNT(*) FROM event_registrations WHERE event_id = ? AND status NOT IN ('cancelled')");
        $capStmt->execute([$eventId]);
        $confirmedRegistrations = intval($capStmt->fetchColumn());
        $registrationFull = ($confirmedRegistrations >= $maxParticipants);
        $spotsLeft = max(0, $maxParticipants - $confirmedRegistrations);
    }

    // Check publish dates for starttider, karta and PM
    $starttiderPublished = empty($event['starttider_publish_at']) || strtotime($event['starttider_publish_at']) <= time();
    $kartaPublished = empty($event['karta_publish_at']) || strtotime($event['karta_publish_at']) <= time();
    $pmPublished = empty($event['pm_publish_at']) || strtotime($event['pm_publish_at']) <= time();

    // Check for elimination data
    $hasEliminationData = false;
    try {
        $elimCheck = $db->prepare("SELECT COUNT(*) as cnt FROM elimination_qualifying WHERE event_id = ?");
        $elimCheck->execute([$eventId]);
        $elimRow = $elimCheck->fetch(PDO::FETCH_ASSOC);
        $hasEliminationData = ($elimRow && $elimRow['cnt'] > 0);
    } catch (Exception $e) {
        // Table doesn't exist yet
        $hasEliminationData = false;
    }

    // Live timing flag
    $isTimingLive = !empty($event['timing_live']);

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

    // Pre-check if any facilities data exists (for tab visibility)
    $facilityChecks = [
        ['field' => 'hydration_stations', 'global' => 'hydration_use_global', 'hidden' => 'hydration_hidden'],
        ['field' => 'toilets_showers', 'global' => 'toilets_use_global', 'hidden' => 'toilets_hidden'],
        ['field' => 'bike_wash', 'global' => 'bike_wash_use_global', 'hidden' => 'bike_wash_hidden'],
        ['field' => 'food_cafe', 'global' => 'food_use_global', 'hidden' => 'food_hidden'],
        ['field' => 'shops_info', 'global' => 'shops_use_global', 'hidden' => 'shops_hidden'],
        ['field' => 'exhibitors', 'global' => 'exhibitors_use_global', 'hidden' => 'exhibitors_hidden'],
        ['field' => 'parking_detailed', 'global' => 'parking_use_global', 'hidden' => 'parking_hidden'],
        ['field' => 'hotel_accommodation', 'global' => 'hotel_use_global', 'hidden' => 'hotel_hidden'],
        ['field' => 'local_info', 'global' => 'local_use_global', 'hidden' => 'local_hidden'],
        ['field' => 'medical_info', 'global' => 'medical_use_global', 'hidden' => 'medical_hidden'],
        ['field' => 'media_production', 'global' => 'media_use_global', 'hidden' => 'media_hidden'],
        ['field' => 'contacts_info', 'global' => 'contacts_use_global', 'hidden' => 'contacts_hidden'],
    ];
    $hasFacilitiesData = false;
    foreach ($facilityChecks as $fc) {
        if (empty($event[$fc['hidden']]) && (!empty($event[$fc['field']]) || !empty($event[$fc['global']]))) {
            $hasFacilitiesData = true;
            break;
        }
    }

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

    // Check if series registration is available
    $seriesRegistrationAvailable = false;
    $seriesInfo = null;
    $seriesEventsWithPricing = [];

    if (!empty($event['series_id'])) {
        // Load series information
        $seriesStmt = $db->prepare("
            SELECT id, name, logo, series_discount_percent, year, allow_series_registration, registration_enabled
            FROM series
            WHERE id = ?
        ");
        $seriesStmt->execute([$event['series_id']]);
        $seriesInfo = $seriesStmt->fetch(PDO::FETCH_ASSOC);

        // If series has registration disabled, override event registration
        if ($seriesInfo && empty($seriesInfo['registration_enabled'])) {
            $registrationOpen = false;
        }

        if ($seriesInfo && $registrationOpen && count($seriesEvents) > 1 && !empty($seriesInfo['allow_series_registration'])) {
            // Load all events in series with pricing info
            $eventIds = array_column($seriesEvents, 'id');
            $placeholders = str_repeat('?,', count($eventIds) - 1) . '?';

            $seriesPricingStmt = $db->prepare("
                SELECT e.id, e.name, e.date, e.pricing_template_id,
                       pt.name as template_name
                FROM events e
                LEFT JOIN pricing_templates pt ON e.pricing_template_id = pt.id
                WHERE e.id IN ($placeholders)
                AND e.active = 1
                AND e.pricing_template_id IS NOT NULL
                ORDER BY e.date ASC
            ");
            $seriesPricingStmt->execute($eventIds);
            $seriesEventsWithPricing = $seriesPricingStmt->fetchAll(PDO::FETCH_ASSOC);

            // Series registration is available if all events have pricing
            $seriesRegistrationAvailable = (count($seriesEventsWithPricing) === count($seriesEvents));
        }
    }

} catch (Exception $e) {
    $error = $e->getMessage();
    error_log("EVENT PAGE ERROR (ID: {$eventId}): " . $error);
    $event = null;
}

// Show database error if one occurred
if (isset($error) && $error): ?>
<div class="alert alert--error mb-lg">
    <p><strong>Databasfel:</strong> <?= htmlspecialchars($error) ?></p>
    <p class="text-muted text-sm">Event ID: <?= $eventId ?></p>
</div>
<?php
endif;

if (!$event) {
    // Only show 404 if there was no database error (event simply doesn't exist)
    if (!isset($error)) {
        include HUB_ROOT . '/pages/404.php';
    }
    return;
}
?>

<?php
// Event Header Banner (direct media upload on event) - hämta filepath
$eventHeaderBanner = null;
if (!empty($event['header_banner_media_id'])) {
    try {
        $bannerStmt = $db->prepare("SELECT filepath FROM media WHERE id = ?");
        $bannerStmt->execute([$event['header_banner_media_id']]);
        $bannerRow = $bannerStmt->fetch(PDO::FETCH_ASSOC);
        if ($bannerRow && !empty($bannerRow['filepath'])) {
            $eventHeaderBanner = '/' . ltrim($bannerRow['filepath'], '/');
        }
    } catch (Exception $e) {
        error_log("EVENT PAGE: Error loading header banner: " . $e->getMessage());
    }
}
// Fallback till header_banner_url om det finns
if (!$eventHeaderBanner && !empty($event['header_banner_url'])) {
    $eventHeaderBanner = '/' . ltrim($event['header_banner_url'], '/');
}

// Sponsor banner (från sponsor med placement=header)
$headerSponsorsWithLogos = array_filter($eventSponsors['header'] ?? [], function($s) {
    return get_sponsor_logo_for_placement($s, 'header') !== null;
});

// Visa event-banner ELLER sponsor-banner högst upp (full bredd)
if ($eventHeaderBanner): ?>
<section class="event-header-banner">
    <img src="<?= h($eventHeaderBanner) ?>" alt="<?= h($event['name']) ?>" class="event-header-banner-img">
</section>
<?php elseif (!empty($headerSponsorsWithLogos)): ?>
<section class="event-header-banner">
    <?php foreach ($headerSponsorsWithLogos as $sponsor):
        $bannerLogo = get_sponsor_logo_for_placement($sponsor, 'header');
    ?>
    <a href="<?= h($sponsor['website'] ?? '#') ?>" target="_blank" rel="noopener sponsored" style="display: block;">
        <img src="<?= h($bannerLogo) ?>" alt="<?= h($sponsor['name']) ?>" class="event-header-banner-img">
    </a>
    <?php endforeach; ?>
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

            <?php if (!empty($event['organizer_club_name']) || !empty($event['series_organizer'])): ?>
            <div class="event-organizer-club">
                <i data-lucide="users"></i>
                Arrangör:
                <?php if (!empty($event['organizer_club_name'])): ?>
                <a href="/club/<?= $event['organizer_club_id_ref'] ?>" class="organizer-club-link">
                    <?= h($event['organizer_club_name']) ?>
                </a>
                <?php elseif (!empty($event['series_organizer'])): ?>
                <span class="series-organizer"><?= h($event['series_organizer']) ?></span>
                <?php endif; ?>
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

                <?php if (!empty($event['is_championship'])): ?>
                <span class="event-sm-badge" title="Svenska Mästerskap">
                    <i data-lucide="medal"></i>
                    SM
                </span>
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
            <div class="event-stat event-stat--registered">
                <span class="event-stat-value"><?= $totalRegistrations ?><?php if ($maxParticipants): ?>/<span style="font-size:0.8em"><?= $maxParticipants ?></span><?php endif; ?></span>
                <span class="event-stat-label">anmälda</span>
            </div>
            <?php endif; ?>
        </div>
    </div>
</section>

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

        <?php if ($hasResults || $isTimingLive): ?>
        <a href="?id=<?= $eventId ?>&tab=resultat" class="event-tab <?= $activeTab === 'resultat' ? 'active' : '' ?>">
            <i data-lucide="trophy"></i>
            Resultat
            <?php if ($isTimingLive): ?>
            <span class="tab-badge tab-badge--live" id="live-badge">LIVE</span>
            <?php else: ?>
            <span class="tab-badge"><?= $totalParticipants ?></span>
            <?php endif; ?>
        </a>
        <?php endif; ?>

        <?php if ($hasEliminationData): ?>
        <a href="?id=<?= $eventId ?>&tab=elimination" class="event-tab <?= $activeTab === 'elimination' ? 'active' : '' ?>">
            <i data-lucide="git-branch"></i>
            Elimination
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

        <?php if ($showAllTabs && $hasFacilitiesData): ?>
        <a href="?id=<?= $eventId ?>&tab=faciliteter" class="event-tab <?= $activeTab === 'faciliteter' ? 'active' : '' ?>">
            <i data-lucide="info"></i>
            Faciliteter
        </a>
        <?php endif; ?>

        <?php if ($showAllTabs && $pmPublished && empty($event['pm_hidden']) && (!empty($event['pm_content']) || !empty($event['pm_use_global']))): ?>
        <a href="?id=<?= $eventId ?>&tab=pm" class="event-tab <?= $activeTab === 'pm' ? 'active' : '' ?>">
            <i data-lucide="file-text"></i>
            PM
        </a>
        <?php endif; ?>

        <?php if ($showAllTabs && empty($event['jury_hidden']) && (!empty($event['jury_communication']) || !empty($event['jury_use_global']))): ?>
        <a href="?id=<?= $eventId ?>&tab=jury" class="event-tab <?= $activeTab === 'jury' ? 'active' : '' ?>">
            <i data-lucide="scale"></i>
            Jury
        </a>
        <?php endif; ?>

        <?php if ($showAllTabs && empty($event['schedule_hidden']) && (!empty($event['competition_schedule']) || !empty($event['schedule_use_global']))): ?>
        <a href="?id=<?= $eventId ?>&tab=schema" class="event-tab <?= $activeTab === 'schema' ? 'active' : '' ?>">
            <i data-lucide="calendar-clock"></i>
            Schema
        </a>
        <?php endif; ?>

        <?php if ($showAllTabs && $starttiderPublished && empty($event['start_times_hidden']) && (!empty($event['start_times']) || !empty($event['start_times_use_global']))): ?>
        <a href="?id=<?= $eventId ?>&tab=starttider" class="event-tab <?= $activeTab === 'starttider' ? 'active' : '' ?>">
            <i data-lucide="list-ordered"></i>
            Starttider
        </a>
        <?php endif; ?>

        <?php if ($showAllTabs && (!empty($event['course_tracks']) || !empty($event['course_tracks_use_global']))): ?>
        <a href="?id=<?= $eventId ?>&tab=banstrackning" class="event-tab <?= $activeTab === 'banstrackning' ? 'active' : '' ?>">
            <i data-lucide="route"></i>
            Bansträckningar
        </a>
        <?php endif; ?>

        <?php if ($hasInteractiveMap && $kartaPublished): ?>
        <a href="?id=<?= $eventId ?>&tab=karta" class="event-tab <?= $activeTab === 'karta' ? 'active' : '' ?>" onclick="if(window.innerWidth <= 768) { window.location.href='/map.php?id=<?= $eventId ?>'; return false; }">
            <i data-lucide="map-pin"></i>
            Karta
        </a>
        <?php endif; ?>

        <?php if ($eventReportsCount > 0): ?>
        <a href="?id=<?= $eventId ?>&tab=nyheter" class="event-tab <?= $activeTab === 'nyheter' ? 'active' : '' ?>">
            <i data-lucide="newspaper"></i>
            Media
            <span class="tab-badge tab-badge--accent"><?= $eventReportsCount ?></span>
        </a>
        <?php endif; ?>

        <?php if ($showAllTabs && !$isPastEvent && $totalRegistrations > 0): ?>
        <a href="?id=<?= $eventId ?>&tab=anmalda" class="event-tab <?= $activeTab === 'anmalda' ? 'active' : '' ?>">
            <i data-lucide="<?= $hasBibNumbers ? 'list-ordered' : 'users' ?>"></i>
            <?= $hasBibNumbers ? 'Startlista' : 'Anmälda' ?>
            <span class="tab-badge tab-badge--secondary"><?= $totalRegistrations ?><?php if ($maxParticipants): ?>/<?= $maxParticipants ?><?php endif; ?></span>
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

        <?php if (!empty($event['series_id'])): ?>
        <a href="/series/<?= $event['series_id'] ?>" class="series-standings-btn">
            <i data-lucide="trophy"></i>
            Serietabeller
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
    <?php if ($hasSplitTimes || $isDH): ?>
    <div class="filter-toggles">
        <label class="toggle-label">
            <input type="checkbox" id="colorToggle" checked onchange="toggleSplitColors(this.checked)">
            <span class="toggle-text">Färgkodning</span>
            <span class="toggle-switch"></span>
        </label>
        <?php if (!$isDH && count($globalSplitResults) > 0): ?>
        <label class="toggle-label">
            <input type="checkbox" id="totalViewToggle" onchange="toggleTotalView(this.checked)">
            <span class="toggle-text">Sträcktider Total</span>
            <span class="toggle-switch"></span>
        </label>
        <?php endif; ?>
        <?php if ($isDH && count($globalDHResults) > 0): ?>
        <label class="toggle-label">
            <input type="checkbox" id="dhTotalViewToggle" onchange="toggleDHTotalView(this.checked)">
            <span class="toggle-text">Åktider Total</span>
            <span class="toggle-switch"></span>
        </label>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<?php foreach ($resultsByClass as $classKey => $classData):
    $isTimeRanked = ($classData['ranking_type'] ?? 'time') === 'time';
    $isMotionOrKids = $classData['is_motion_or_kids'] ?? false;

    // Calculate which splits this class has (excluding status values in split columns)
    $classSplits = [];
    if ($hasSplitTimes && !$isDH) {
        for ($ss = 1; $ss <= 15; $ss++) {
            foreach ($classData['results'] as $r) {
                $splitVal = $r['ss' . $ss] ?? '';
                if (!empty($splitVal) && !in_array(strtolower(trim($splitVal)), $invalidSplitValues)) {
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
                    <?php if ($eventFormat === 'DH_SWECUP'): ?>
                    <th class="col-time col-dh-run table-col-hide-mobile sortable-header" data-sort="run1" onclick="sortDHByRun(this, '<?= $classKey ?>', 1)">Kval <span class="sort-icon"></span></th>
                    <th class="col-time col-dh-run table-col-hide-mobile sortable-header" data-sort="run2" onclick="sortDHByRun(this, '<?= $classKey ?>', 2)">Final <span class="sort-icon"></span></th>
                    <th class="col-time sortable-header" data-sort="time" onclick="sortByTime(this, '<?= $classKey ?>')">Tid <span class="sort-icon"></span></th>
                    <?php elseif ($hasRun2Data): ?>
                    <th class="col-time col-dh-run table-col-hide-mobile sortable-header" data-sort="run1" onclick="sortDHByRun(this, '<?= $classKey ?>', 1)">Åk 1 <span class="sort-icon"></span></th>
                    <th class="col-time col-dh-run table-col-hide-mobile sortable-header" data-sort="run2" onclick="sortDHByRun(this, '<?= $classKey ?>', 2)">Åk 2 <span class="sort-icon"></span></th>
                    <th class="col-time sortable-header" data-sort="best" onclick="sortByTime(this, '<?= $classKey ?>')">Bästa <span class="sort-icon"></span></th>
                    <?php else: ?>
                    <th class="col-time sortable-header" data-sort="time" onclick="sortByTime(this, '<?= $classKey ?>')">Tid <span class="sort-icon"></span></th>
                    <?php endif; ?>
                    <?php else: ?>
                    <th class="col-time sortable-header" data-sort="time" onclick="sortByTime(this, '<?= $classKey ?>')">Tid <span class="sort-icon"></span></th>
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
                    <?php if ($isDH && ($eventFormat === 'DH_SWECUP' || $hasRun2Data)):
                        // Calculate color classes for DH runs
                        $run1Class = '';
                        $run2Class = '';
                        $run1Seconds = !empty($result['run_1_time']) ? timeToSeconds($result['run_1_time']) : PHP_INT_MAX;
                        $run2Seconds = !empty($result['run_2_time']) ? timeToSeconds($result['run_2_time']) : PHP_INT_MAX;

                        if (!$isMotionOrKids && $result['status'] === 'finished') {
                            // Run 1 color
                            if (!empty($result['run_1_time']) && isset($classData['dh_run_stats']['run_1_time'])) {
                                $stats = $classData['dh_run_stats']['run_1_time'];
                                if ($stats['range'] > 0.5) {
                                    $position = ($run1Seconds - $stats['min']) / $stats['range'];
                                    $level = min(10, max(1, floor($position * 9) + 1));
                                    $run1Class = 'split-' . $level;
                                }
                            }
                            // Run 2 color
                            if (!empty($result['run_2_time']) && isset($classData['dh_run_stats']['run_2_time'])) {
                                $stats = $classData['dh_run_stats']['run_2_time'];
                                if ($stats['range'] > 0.5) {
                                    $position = ($run2Seconds - $stats['min']) / $stats['range'];
                                    $level = min(10, max(1, floor($position * 9) + 1));
                                    $run2Class = 'split-' . $level;
                                }
                            }
                        }

                        $run1Rank = $result['run_rank_1'] ?? null;
                        $run1Diff = $result['run_diff_1'] ?? '';
                        $run2Rank = $result['run_rank_2'] ?? null;
                        $run2Diff = $result['run_diff_2'] ?? '';
                    ?>
                    <td class="col-time col-dh-run table-col-hide-mobile <?= $run1Class ?>" data-sort-value="<?= $run1Seconds ?>">
                        <?php
                        $r1Display = formatDisplayTime($result['run_1_time'] ?? '');
                        if ($r1Display): ?>
                        <div class="split-time-main"><?= $r1Display ?></div>
                        <?php if (!$isMotionOrKids && ($run1Diff || $run1Rank)): ?>
                        <div class="split-time-details"><?= $run1Diff ?: '' ?><?= $run1Diff && $run1Rank ? ' ' : '' ?><?= $run1Rank ? '(' . $run1Rank . ')' : '' ?></div>
                        <?php endif; ?>
                        <?php else: ?>
                        <span class="text-secondary">DNS</span>
                        <?php endif; ?>
                    </td>
                    <td class="col-time col-dh-run table-col-hide-mobile <?= $run2Class ?>" data-sort-value="<?= $run2Seconds ?>">
                        <?php
                        $r2Display = formatDisplayTime($result['run_2_time'] ?? '');
                        if ($r2Display): ?>
                        <div class="split-time-main"><?= $r2Display ?></div>
                        <?php if (!$isMotionOrKids && ($run2Diff || $run2Rank)): ?>
                        <div class="split-time-details"><?= $run2Diff ?: '' ?><?= $run2Diff && $run2Rank ? ' ' : '' ?><?= $run2Rank ? '(' . $run2Rank . ')' : '' ?></div>
                        <?php endif; ?>
                        <?php else: ?>
                        <span class="text-secondary">DNS</span>
                        <?php endif; ?>
                    </td>
                    <?php
                    $bestTime = null;
                    $bestTimeSeconds = PHP_INT_MAX;
                    // Helper to check if time is valid (not empty, not 0:00)
                    $isValidDHTime = function($t) {
                        return !empty($t) && !preg_match('/^0+[:.]?0*[:.]?0*$/', $t);
                    };
                    if ($eventFormat === 'DH_SWECUP') {
                        $bestTime = $isValidDHTime($result['run_2_time']) ? $result['run_2_time'] : null;
                    } else {
                        $r1Valid = $isValidDHTime($result['run_1_time']);
                        $r2Valid = $isValidDHTime($result['run_2_time']);
                        if ($r1Valid && $r2Valid) {
                            $t1 = timeToSeconds($result['run_1_time']);
                            $t2 = timeToSeconds($result['run_2_time']);
                            $bestTime = $t1 < $t2 ? $result['run_1_time'] : $result['run_2_time'];
                        } elseif ($r1Valid) {
                            $bestTime = $result['run_1_time'];
                        } elseif ($r2Valid) {
                            $bestTime = $result['run_2_time'];
                        }
                    }
                    if ($bestTime) {
                        $bestTimeSeconds = timeToSeconds($bestTime);
                    }
                    ?>
                    <td class="col-time font-bold" data-sort-value="<?= $bestTimeSeconds ?>">
                        <?php if ($bestTime && $result['status'] === 'finished'): ?>
                        <div class="split-time-main"><?= formatDisplayTime($bestTime) ?></div>
                        <?php if (!empty($result['time_behind'])): ?>
                        <div class="split-time-details"><?= $result['time_behind'] ?></div>
                        <?php endif; ?>
                        <?php else: ?>
                        -
                        <?php endif; ?>
                    </td>
                    <?php else: ?>
                    <?php $finishTimeSeconds = !empty($result['finish_time']) ? timeToSeconds($result['finish_time']) : PHP_INT_MAX; ?>
                    <td class="col-time" data-sort-value="<?= $finishTimeSeconds ?>">
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
                    <td class="col-place total-position">
                        <?php if ($result['status'] !== 'finished'): ?>
                            <span class="status-badge status-<?= strtolower($result['status']) ?>"><?= strtoupper(substr($result['status'], 0, 3)) ?></span>
                        <?php else: ?>
                            <?= $gIdx + 1 ?>
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
                <?php if ($result['status'] !== 'finished'): ?>
                    <span class="status-mini"><?= strtoupper(substr($result['status'], 0, 3)) ?></span>
                <?php else: ?>
                    <?= $gIdx + 1 ?>
                <?php endif; ?>
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

<!-- Total DH Run Times Section (hidden by default, shown when toggle is active) -->
<?php if ($isDH && count($globalDHResults) > 0): ?>
<section class="card mb-lg total-dh-section hidden" id="total-dh-section">
    <div class="card-header">
        <div>
            <h2 class="card-title">Åktider Total</h2>
            <p class="card-subtitle"><?= count($globalDHResults) ?> deltagare från alla tävlingsklasser (exkl. Kids/Motion)</p>
        </div>
    </div>

    <!-- Desktop Table -->
    <div class="table-wrapper">
        <table class="table table--striped table--hover results-table" id="total-dh-table">
            <thead>
                <tr>
                    <th class="col-place">#</th>
                    <th class="col-rider">Åkare</th>
                    <th class="col-club table-col-hide-mobile">Klubb</th>
                    <th class="col-class table-col-hide-mobile">Klass</th>
                    <?php if ($eventFormat === 'DH_SWECUP'): ?>
                    <th class="col-time col-dh-run table-col-hide-mobile sortable-header" data-sort="run1" onclick="sortDHTotalByRun(this, 1)">Kval <span class="sort-icon"></span></th>
                    <th class="col-time col-dh-run table-col-hide-mobile sortable-header" data-sort="run2" onclick="sortDHTotalByRun(this, 2)">Final <span class="sort-icon"></span></th>
                    <?php elseif ($hasRun2Data): ?>
                    <th class="col-time col-dh-run table-col-hide-mobile sortable-header" data-sort="run1" onclick="sortDHTotalByRun(this, 1)">Åk 1 <span class="sort-icon"></span></th>
                    <th class="col-time col-dh-run table-col-hide-mobile sortable-header" data-sort="run2" onclick="sortDHTotalByRun(this, 2)">Åk 2 <span class="sort-icon"></span></th>
                    <?php else: ?>
                    <th class="col-time table-col-hide-mobile sortable-header" data-sort="run1" onclick="sortDHTotalByRun(this, 1)">Tid <span class="sort-icon"></span></th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($globalDHResults as $gIdx => $result):
                    $searchData = strtolower($result['firstname'] . ' ' . $result['lastname'] . ' ' . ($result['club_name'] ?? ''));

                    // Calculate color classes for runs
                    $run1Time = $result['run_1_time'] ?? '';
                    $run2Time = $result['run_2_time'] ?? '';
                    $run1Class = '';
                    $run2Class = '';
                    $run1Rank = $result['global_run_rank_1'] ?? null;
                    $run2Rank = $result['global_run_rank_2'] ?? null;
                    $run1Diff = $result['global_run_diff_1'] ?? '';
                    $run2Diff = $result['global_run_diff_2'] ?? '';
                    $run1Seconds = !empty($run1Time) ? timeToSeconds($run1Time) : PHP_INT_MAX;
                    $run2Seconds = !empty($run2Time) ? timeToSeconds($run2Time) : PHP_INT_MAX;

                    if (!empty($run1Time) && isset($globalDHStats['run_1_time'])) {
                        $stats = $globalDHStats['run_1_time'];
                        $timeSeconds = timeToSeconds($run1Time);
                        if ($stats['range'] > 0.5) {
                            $position = ($timeSeconds - $stats['min']) / $stats['range'];
                            $level = min(10, max(1, floor($position * 9) + 1));
                            $run1Class = 'split-' . $level;
                        }
                    }
                    if (!empty($run2Time) && isset($globalDHStats['run_2_time'])) {
                        $stats = $globalDHStats['run_2_time'];
                        $timeSeconds = timeToSeconds($run2Time);
                        if ($stats['range'] > 0.5) {
                            $position = ($timeSeconds - $stats['min']) / $stats['range'];
                            $level = min(10, max(1, floor($position * 9) + 1));
                            $run2Class = 'split-' . $level;
                        }
                    }
                ?>
                <tr class="result-row" onclick="window.location='/rider/<?= $result['rider_id'] ?>'" data-search="<?= h($searchData) ?>">
                    <td class="col-place total-position">
                        <?php if ($result['status'] !== 'finished'): ?>
                            <span class="status-badge status-<?= strtolower($result['status']) ?>"><?= strtoupper(substr($result['status'], 0, 3)) ?></span>
                        <?php else: ?>
                            <?= $gIdx + 1 ?>
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
                    <td class="col-class table-col-hide-mobile">
                        <span class="class-badge"><?= h($result['original_class'] ?? '-') ?></span>
                    </td>
                    <?php if ($eventFormat === 'DH_SWECUP' || $hasRun2Data): ?>
                    <td class="col-time col-dh-run table-col-hide-mobile <?= $run1Class ?>" data-sort-value="<?= $run1Seconds ?>">
                        <?php $r1Disp = formatDisplayTime($run1Time); if ($r1Disp): ?>
                        <div class="split-time-main"><?= $r1Disp ?></div>
                        <div class="split-time-details"><?= $run1Diff ?: '' ?><?= $run1Diff && $run1Rank ? ' ' : '' ?><?= $run1Rank ? '(' . $run1Rank . ')' : '' ?></div>
                        <?php else: ?><span class="text-secondary">DNS</span><?php endif; ?>
                    </td>
                    <td class="col-time col-dh-run table-col-hide-mobile <?= $run2Class ?>" data-sort-value="<?= $run2Seconds ?>">
                        <?php $r2Disp = formatDisplayTime($run2Time); if ($r2Disp): ?>
                        <div class="split-time-main"><?= $r2Disp ?></div>
                        <div class="split-time-details"><?= $run2Diff ?: '' ?><?= $run2Diff && $run2Rank ? ' ' : '' ?><?= $run2Rank ? '(' . $run2Rank . ')' : '' ?></div>
                        <?php else: ?><span class="text-secondary">DNS</span><?php endif; ?>
                    </td>
                    <?php else: ?>
                    <td class="col-time table-col-hide-mobile <?= $run1Class ?>" data-sort-value="<?= $run1Seconds ?>">
                        <?php $r1Disp = formatDisplayTime($run1Time); if ($r1Disp): ?>
                        <div class="split-time-main"><?= $r1Disp ?></div>
                        <?php else: ?><span class="text-secondary">DNS</span><?php endif; ?>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Mobile Card View -->
    <div class="result-list">
        <?php foreach ($globalDHResults as $gIdx => $result):
            $searchData = strtolower($result['firstname'] . ' ' . $result['lastname'] . ' ' . ($result['club_name'] ?? ''));
            $run1Time = $result['run_1_time'] ?? '';
            $run2Time = $result['run_2_time'] ?? '';
            $run1Rank = $result['global_run_rank_1'] ?? null;
            $run2Rank = $result['global_run_rank_2'] ?? null;
        ?>
        <a href="/rider/<?= $result['rider_id'] ?>" class="result-item result-item-splits" data-search="<?= h($searchData) ?>">
            <div class="result-place">
                <?php if ($result['status'] !== 'finished'): ?>
                    <span class="status-mini"><?= strtoupper(substr($result['status'], 0, 3)) ?></span>
                <?php else: ?>
                    <?= $gIdx + 1 ?>
                <?php endif; ?>
            </div>
            <div class="result-info">
                <div class="result-name"><?= h($result['firstname'] . ' ' . $result['lastname']) ?></div>
                <div class="result-club"><?= h($result['club_name'] ?? '-') ?> &middot; <?= h($result['original_class'] ?? '') ?></div>
                <div class="result-splits-row">
                    <?php if ($eventFormat === 'DH_SWECUP' || $hasRun2Data): ?>
                    <div class="result-split-item">
                        <span class="split-label"><?= $eventFormat === 'DH_SWECUP' ? 'Kval' : 'Åk 1' ?></span>
                        <span class="split-value"><?= formatDisplayTime($run1Time) ?: 'DNS' ?></span>
                        <?php if ($run1Rank): ?><span class="split-rank">(<?= $run1Rank ?>)</span><?php endif; ?>
                    </div>
                    <div class="result-split-item">
                        <span class="split-label"><?= $eventFormat === 'DH_SWECUP' ? 'Final' : 'Åk 2' ?></span>
                        <span class="split-value"><?= formatDisplayTime($run2Time) ?: 'DNS' ?></span>
                        <?php if ($run2Rank): ?><span class="split-rank">(<?= $run2Rank ?>)</span><?php endif; ?>
                    </div>
                    <?php else: ?>
                    <div class="result-split-item">
                        <span class="split-label">Tid</span>
                        <span class="split-value"><?= formatDisplayTime($run1Time) ?: 'DNS' ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<?php endif; ?>

<?php elseif ($activeTab === 'info'): ?>
<!-- INBJUDAN TAB - Invitation + General Competition Info -->
<?php
// Get invitation content
$invitationText = getEventContent($event, 'invitation', 'invitation_use_global', $globalTextMap, 'invitation_hidden');
$generalCompInfo = getEventContent($event, 'general_competition_info', 'general_competition_use_global', $globalTextMap, 'general_competition_hidden');
$compClassesInfo = getEventContent($event, 'competition_classes_info', 'competition_classes_use_global', $globalTextMap, 'competition_classes_hidden');
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
        <div class="prose"><?= format_text($invitationText) ?></div>
    </div>
</section>
<?php endif; ?>

<?php if (!empty($generalCompInfo) || !empty($eventInfoLinks['general'])): ?>
<section class="card mb-lg">
    <div class="card-header">
        <h2 class="card-title">
            <i data-lucide="clipboard-list"></i>
            Generell tävlingsinformation
        </h2>
    </div>
    <div class="card-body">
        <?php if (!empty($generalCompInfo)): ?>
        <div class="prose"><?= format_text($generalCompInfo) ?></div>
        <?php endif; ?>
        <?php if (!empty($eventInfoLinks['general'])): ?>
        <div style="margin-top: var(--space-sm); display: flex; flex-direction: column; gap: var(--space-2xs);">
            <?php foreach ($eventInfoLinks['general'] as $link):
                $url = $link['link_url'] ?? '';
                if (empty($url)) continue;
                $text = !empty($link['link_text']) ? $link['link_text'] : $url;
            ?>
            <a href="<?= h($url) ?>" target="_blank" rel="noopener" style="display: inline-flex; align-items: center; gap: var(--space-2xs); color: var(--color-accent-text);">
                <i data-lucide="external-link" style="width: 16px; height: 16px;"></i>
                <?= h($text) ?>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>

<?php
// Regelverk section
$regulationsGlobalType = $event['regulations_global_type'] ?? '';
$regulationsHidden = !empty($event['regulations_hidden'] ?? 0);
$regulationsText = '';
$regulationsGlobalLinks = [];
if (!$regulationsHidden) {
    if ($regulationsGlobalType === 'sportmotion' && !empty($globalTextMap['regulations_sportmotion'])) {
        $regulationsText = $globalTextMap['regulations_sportmotion'];
        $regulationsGlobalLinks = $globalTextLinksMap['regulations_sportmotion'] ?? [];
    } elseif ($regulationsGlobalType === 'competition' && !empty($globalTextMap['regulations_competition'])) {
        $regulationsText = $globalTextMap['regulations_competition'];
        $regulationsGlobalLinks = $globalTextLinksMap['regulations_competition'] ?? [];
    } else {
        $regulationsText = $event['regulations_info'] ?? '';
    }
}
$regulationsEventLinks = $eventInfoLinks['regulations'] ?? [];
$allRegulationsLinks = array_merge($regulationsGlobalLinks, $regulationsEventLinks);
?>
<?php if (!empty($regulationsText) || !empty($allRegulationsLinks)): ?>
<section class="card mb-lg">
    <div class="card-header">
        <h2 class="card-title">
            <i data-lucide="scale"></i>
            Regelverk
        </h2>
    </div>
    <div class="card-body">
        <?php if (!empty($regulationsText)): ?>
        <div class="prose"><?= format_text($regulationsText) ?></div>
        <?php endif; ?>
        <?php if (!empty($allRegulationsLinks)): ?>
        <div style="margin-top: var(--space-sm); display: flex; flex-direction: column; gap: var(--space-2xs);">
            <?php foreach ($allRegulationsLinks as $link):
                $url = $link['link_url'] ?? '';
                if (empty($url)) continue;
                $text = !empty($link['link_text']) ? $link['link_text'] : $url;
            ?>
            <a href="<?= h($url) ?>" target="_blank" rel="noopener" style="display: inline-flex; align-items: center; gap: var(--space-2xs); color: var(--color-accent-text);">
                <i data-lucide="external-link" style="width: 16px; height: 16px;"></i>
                <?= h($text) ?>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>

<?php
// Licenser section
$licenseText = '';
$licenseHidden = !empty($event['license_hidden'] ?? 0);
$licenseGlobalLinks = [];
if (!$licenseHidden) {
    if (!empty($event['license_use_global']) && !empty($globalTextMap['license_info'])) {
        $licenseText = $globalTextMap['license_info'];
        $licenseGlobalLinks = $globalTextLinksMap['license_info'] ?? [];
    } else {
        $licenseText = $event['license_info'] ?? '';
    }
}
$licenseEventLinks = $eventInfoLinks['licenses'] ?? [];
$allLicenseLinks = array_merge($licenseGlobalLinks, $licenseEventLinks);
?>
<?php if (!empty($licenseText) || !empty($allLicenseLinks)): ?>
<section class="card mb-lg">
    <div class="card-header">
        <h2 class="card-title">
            <i data-lucide="badge-check"></i>
            Licenser
        </h2>
    </div>
    <div class="card-body">
        <?php if (!empty($licenseText)): ?>
        <div class="prose"><?= format_text($licenseText) ?></div>
        <?php endif; ?>
        <?php if (!empty($allLicenseLinks)): ?>
        <div style="margin-top: var(--space-sm); display: flex; flex-direction: column; gap: var(--space-2xs);">
            <?php foreach ($allLicenseLinks as $link):
                $url = $link['link_url'] ?? '';
                if (empty($url)) continue;
                $text = !empty($link['link_text']) ? $link['link_text'] : $url;
            ?>
            <a href="<?= h($url) ?>" target="_blank" rel="noopener" style="display: inline-flex; align-items: center; gap: var(--space-2xs); color: var(--color-accent-text);">
                <i data-lucide="external-link" style="width: 16px; height: 16px;"></i>
                <?= h($text) ?>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>

<?php if (!empty($compClassesInfo)): ?>
<section class="card">
    <div class="card-header">
        <h2 class="card-title">
            <i data-lucide="list-checks"></i>
            Tävlingsklasser
        </h2>
    </div>
    <div class="card-body">
        <div class="prose"><?= format_text($compClassesInfo) ?></div>
    </div>
</section>
<?php endif; ?>

<?php if (empty($invitationText) && empty($generalCompInfo) && empty($compClassesInfo)): ?>
<section class="card">
    <div class="empty-state">
        <i data-lucide="file-text" class="empty-state-icon"></i>
        <h3>Ingen inbjudan publicerad</h3>
        <p>Inbjudan har inte publicerats för denna tävling ännu.</p>
    </div>
</section>
<?php endif; ?>

<?php elseif ($activeTab === 'faciliteter'): ?>
<!-- FACILITETER TAB - Facilities & Logistics -->
<?php
$hydrationInfo = getEventContent($event, 'hydration_stations', 'hydration_use_global', $globalTextMap, 'hydration_hidden');
$toiletsInfo = getEventContent($event, 'toilets_showers', 'toilets_use_global', $globalTextMap, 'toilets_hidden');
$bikeWashInfo = getEventContent($event, 'bike_wash', 'bike_wash_use_global', $globalTextMap, 'bike_wash_hidden');
$foodCafe = getEventContent($event, 'food_cafe', 'food_use_global', $globalTextMap, 'food_hidden');
$shopsInfo = getEventContent($event, 'shops_info', 'shops_use_global', $globalTextMap, 'shops_hidden');
$exhibitorsInfo = getEventContent($event, 'exhibitors', 'exhibitors_use_global', $globalTextMap, 'exhibitors_hidden');
$parkingInfo = !empty($event['parking_hidden']) ? '' : ($event['parking_detailed'] ?? '');
$hotelInfo = !empty($event['hotel_hidden']) ? '' : ($event['hotel_accommodation'] ?? '');
$localInfo = getEventContent($event, 'local_info', 'local_use_global', $globalTextMap, 'local_hidden');
$medicalInfo = getEventContent($event, 'medical_info', 'medical_use_global', $globalTextMap, 'medical_hidden');
$mediaInfo = getEventContent($event, 'media_production', 'media_use_global', $globalTextMap, 'media_hidden');
$contactsInfo = getEventContent($event, 'contacts_info', 'contacts_use_global', $globalTextMap, 'contacts_hidden');
$hasFacilities = $hydrationInfo || $toiletsInfo || $bikeWashInfo || $foodCafe || $shopsInfo || $exhibitorsInfo || $parkingInfo || $hotelInfo || $localInfo || $medicalInfo || $mediaInfo || $contactsInfo;
?>

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
                <p><?= format_text($hydrationInfo) ?></p>
            </div>
            <?php endif; ?>

            <?php if (!empty($toiletsInfo)): ?>
            <div class="info-block">
                <h3><i data-lucide="bath"></i> Toaletter/Dusch</h3>
                <p><?= format_text($toiletsInfo) ?></p>
            </div>
            <?php endif; ?>

            <?php if (!empty($bikeWashInfo)): ?>
            <div class="info-block">
                <h3><i data-lucide="sparkles"></i> Cykeltvätt</h3>
                <p><?= format_text($bikeWashInfo) ?></p>
            </div>
            <?php endif; ?>

            <?php if (!empty($foodCafe)): ?>
            <div class="info-block">
                <h3><i data-lucide="utensils"></i> Mat/Café</h3>
                <p><?= format_text($foodCafe) ?></p>
            </div>
            <?php endif; ?>

            <?php if (!empty($shopsInfo)): ?>
            <div class="info-block">
                <h3><i data-lucide="shopping-bag"></i> Affärer</h3>
                <p><?= format_text($shopsInfo) ?></p>
            </div>
            <?php endif; ?>

            <?php if (!empty($exhibitorsInfo)): ?>
            <div class="info-block">
                <h3><i data-lucide="store"></i> Utställare</h3>
                <p><?= format_text($exhibitorsInfo) ?></p>
            </div>
            <?php endif; ?>

            <?php if (!empty($parkingInfo)): ?>
            <div class="info-block">
                <h3><i data-lucide="car"></i> Parkering</h3>
                <p><?= format_text($parkingInfo) ?></p>
            </div>
            <?php endif; ?>

            <?php if (!empty($hotelInfo)): ?>
            <div class="info-block">
                <h3><i data-lucide="bed"></i> Hotell/Boende</h3>
                <p><?= format_text($hotelInfo) ?></p>
            </div>
            <?php endif; ?>

            <?php if (!empty($localInfo)): ?>
            <div class="info-block">
                <h3><i data-lucide="map-pin"></i> Lokal information</h3>
                <p><?= format_text($localInfo) ?></p>
            </div>
            <?php endif; ?>

            <?php if (!empty($medicalInfo)): ?>
            <div class="info-block">
                <h3><i data-lucide="heart-pulse"></i> Sjukvård</h3>
                <p><?= format_text($medicalInfo) ?></p>
            </div>
            <?php endif; ?>

            <?php if (!empty($mediaInfo)): ?>
            <div class="info-block">
                <h3><i data-lucide="camera"></i> Media</h3>
                <p><?= format_text($mediaInfo) ?></p>
            </div>
            <?php endif; ?>

            <?php if (!empty($contactsInfo)): ?>
            <div class="info-block">
                <h3><i data-lucide="phone"></i> Kontakter</h3>
                <p><?= format_text($contactsInfo) ?></p>
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
$pmContent = getEventContent($event, 'pm_content', 'pm_use_global', $globalTextMap, 'pm_hidden');
$driverMeetingPM = getEventContent($event, 'driver_meeting', 'driver_meeting_use_global', $globalTextMap, 'driver_meeting_hidden');
$trainingPM = getEventContent($event, 'training_info', 'training_use_global', $globalTextMap, 'training_hidden');
$timingPM = getEventContent($event, 'timing_info', 'timing_use_global', $globalTextMap, 'timing_hidden');
$liftPM = getEventContent($event, 'lift_info', 'lift_use_global', $globalTextMap, 'lift_hidden');
$rulesPM = getEventContent($event, 'competition_rules', 'rules_use_global', $globalTextMap, 'rules_hidden');
$insurancePM = getEventContent($event, 'insurance_info', 'insurance_use_global', $globalTextMap, 'insurance_hidden');
$equipmentPM = getEventContent($event, 'equipment_info', 'equipment_use_global', $globalTextMap, 'equipment_hidden');
$scfPM = getEventContent($event, 'scf_representatives', 'scf_use_global', $globalTextMap, 'scf_hidden');
$medicalPM = getEventContent($event, 'medical_info', 'medical_use_global', $globalTextMap, 'medical_hidden');
$hasPMContent = $pmContent || $driverMeetingPM || $trainingPM || $timingPM || $liftPM || $rulesPM || $insurancePM || $equipmentPM || $scfPM || $medicalPM;
?>
<section class="card">
    <div class="card-header">
        <h2 class="card-title"><i data-lucide="clipboard-list"></i> PM (Promemoria)</h2>
    </div>
    <div class="card-body">
        <?php if ($pmContent): ?>
        <div class="prose mb-lg"><?= format_text($pmContent) ?></div>
        <?php endif; ?>

        <?php if ($driverMeetingPM || $trainingPM || $timingPM || $liftPM || $rulesPM || $insurancePM || $equipmentPM || $scfPM || $medicalPM): ?>
        <div class="info-grid">
            <?php if ($driverMeetingPM): ?>
            <div class="info-block">
                <h3><i data-lucide="megaphone"></i> Förarmöte</h3>
                <p><?= format_text($driverMeetingPM) ?></p>
            </div>
            <?php endif; ?>

            <?php if ($trainingPM): ?>
            <div class="info-block">
                <h3><i data-lucide="bike"></i> Träning</h3>
                <p><?= format_text($trainingPM) ?></p>
            </div>
            <?php endif; ?>

            <?php if ($timingPM): ?>
            <div class="info-block">
                <h3><i data-lucide="timer"></i> Tidtagning</h3>
                <p><?= format_text($timingPM) ?></p>
            </div>
            <?php endif; ?>

            <?php if ($liftPM): ?>
            <div class="info-block">
                <h3><i data-lucide="cable-car"></i> Lift</h3>
                <p><?= format_text($liftPM) ?></p>
            </div>
            <?php endif; ?>

            <?php if ($rulesPM): ?>
            <div class="info-block">
                <h3><i data-lucide="book-open"></i> Tävlingsregler</h3>
                <p><?= format_text($rulesPM) ?></p>
            </div>
            <?php endif; ?>

            <?php if ($insurancePM): ?>
            <div class="info-block">
                <h3><i data-lucide="shield-check"></i> Försäkring</h3>
                <p><?= format_text($insurancePM) ?></p>
            </div>
            <?php endif; ?>

            <?php if ($equipmentPM): ?>
            <div class="info-block">
                <h3><i data-lucide="hard-hat"></i> Utrustning</h3>
                <p><?= format_text($equipmentPM) ?></p>
            </div>
            <?php endif; ?>

            <?php if ($medicalPM): ?>
            <div class="info-block">
                <h3><i data-lucide="heart-pulse"></i> Sjukvård</h3>
                <p><?= format_text($medicalPM) ?></p>
            </div>
            <?php endif; ?>

            <?php if ($scfPM): ?>
            <div class="info-block">
                <h3><i data-lucide="badge-check"></i> SCF Representanter</h3>
                <p><?= format_text($scfPM) ?></p>
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
        <?php $juryContent = getEventContent($event, 'jury_communication', 'jury_use_global', $globalTextMap, 'jury_hidden'); ?>
        <?php if ($juryContent): ?>
            <div class="prose"><?= format_text($juryContent) ?></div>
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
        <?php $scheduleContent = getEventContent($event, 'competition_schedule', 'schedule_use_global', $globalTextMap, 'schedule_hidden'); ?>
        <?php if ($scheduleContent): ?>
            <div class="prose"><?= format_text($scheduleContent) ?></div>
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
        <?php $startContent = getEventContent($event, 'start_times', 'start_times_use_global', $globalTextMap, 'start_times_hidden'); ?>
        <?php if ($startContent): ?>
            <div class="prose"><?= format_text($startContent) ?></div>
        <?php else: ?>
            <p class="text-muted">Inga starttider publicerade ännu.</p>
        <?php endif; ?>
    </div>
</section>

<?php elseif ($activeTab === 'banstrackning'): ?>
<!-- COURSE TRACKS TAB -->
<section class="card">
    <div class="card-header">
        <h2 class="card-title"><i data-lucide="route"></i> Bansträckningar</h2>
    </div>
    <div class="card-body">
        <?php $courseTracksContent = getEventContent($event, 'course_tracks', 'course_tracks_use_global', $globalTextMap); ?>
        <?php if ($courseTracksContent): ?>
            <div class="prose"><?= format_text($courseTracksContent) ?></div>
        <?php else: ?>
            <p class="text-muted">Ingen information om bansträckningar tillgänglig.</p>
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

<?php elseif ($activeTab === 'nyheter'): ?>
<!-- NEWS/MEDIA TAB -->
<?php
// Fetch race reports for this event
$eventReports = [];
try {
    require_once HUB_ROOT . '/includes/RaceReportManager.php';
    $reportManager = new RaceReportManager($db);
    $reportsResult = $reportManager->listReports([
        'event_id' => $eventId,
        'per_page' => 20
    ]);
    $eventReports = $reportsResult['reports'];
} catch (Exception $e) {
    // Ignore
}
?>
<section class="card">
    <div class="card-header">
        <h2 class="card-title">
            <i data-lucide="newspaper"></i>
            Media och nyheter
            <span class="badge badge--primary ml-sm"><?= count($eventReports) ?></span>
        </h2>
        <a href="/news?event=<?= $eventId ?>" class="btn btn-sm btn-secondary">
            <i data-lucide="external-link"></i>
            Visa alla
        </a>
    </div>
    <div class="card-body">
        <?php if (empty($eventReports)): ?>
            <p class="text-muted">Inga nyheter eller media från detta event än.</p>
        <?php else: ?>
        <div class="event-news-grid">
            <?php foreach ($eventReports as $report): ?>
            <a href="/news/<?= htmlspecialchars($report['slug']) ?>" class="event-news-card">
                <div class="event-news-card__image">
                    <?php if ($report['featured_image']): ?>
                    <img src="<?= htmlspecialchars($report['featured_image']) ?>" alt="" loading="lazy">
                    <?php elseif ($report['youtube_video_id']): ?>
                    <img src="https://img.youtube.com/vi/<?= htmlspecialchars($report['youtube_video_id']) ?>/hqdefault.jpg" alt="" loading="lazy">
                    <div class="event-news-card__play"><i data-lucide="play"></i></div>
                    <?php else: ?>
                    <div class="event-news-card__placeholder"><i data-lucide="image"></i></div>
                    <?php endif; ?>
                </div>
                <div class="event-news-card__content">
                    <h3 class="event-news-card__title"><?= htmlspecialchars($report['title']) ?></h3>
                    <div class="event-news-card__meta">
                        <span><?= htmlspecialchars($report['firstname'] . ' ' . $report['lastname']) ?></span>
                        <span><?= date('j M Y', strtotime($report['published_at'])) ?></span>
                    </div>
                    <div class="event-news-card__stats">
                        <span><i data-lucide="eye"></i> <?= number_format($report['views']) ?></span>
                        <span><i data-lucide="heart"></i> <?= number_format($report['likes']) ?></span>
                    </div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (function_exists('hub_current_user') && hub_current_user()): ?>
        <div class="event-news-cta">
            <p>Deltog du i denna tävling? Dela din upplevelse!</p>
            <a href="/profile/race-reports" class="btn btn-primary">
                <i data-lucide="pen-tool"></i>
                Skriv Race Report
            </a>
        </div>
        <?php endif; ?>
    </div>
</section>

<style>
.event-news-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: var(--space-md);
    margin-bottom: var(--space-lg);
}

.event-news-card {
    display: flex;
    flex-direction: column;
    background: var(--color-bg-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    overflow: hidden;
    text-decoration: none;
    transition: all 0.15s ease;
}

.event-news-card:hover {
    border-color: var(--color-accent);
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}

.event-news-card__image {
    position: relative;
    aspect-ratio: 16 / 9;
    background: var(--color-bg-page);
    overflow: hidden;
}

.event-news-card__image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.event-news-card__placeholder {
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--color-text-muted);
}

.event-news-card__placeholder i {
    width: 32px;
    height: 32px;
}

.event-news-card__play {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    width: 40px;
    height: 40px;
    background: rgba(0,0,0,0.7);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
}

.event-news-card__play i {
    width: 18px;
    height: 18px;
    margin-left: 2px;
}

.event-news-card__content {
    padding: var(--space-md);
}

.event-news-card__title {
    font-size: 1rem;
    font-weight: 600;
    color: var(--color-text-primary);
    margin: 0 0 var(--space-xs);
    line-height: 1.3;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.event-news-card__meta {
    display: flex;
    justify-content: space-between;
    font-size: 0.75rem;
    color: var(--color-text-muted);
    margin-bottom: var(--space-xs);
}

.event-news-card__stats {
    display: flex;
    gap: var(--space-md);
    font-size: 0.75rem;
    color: var(--color-text-muted);
}

.event-news-card__stats span {
    display: flex;
    align-items: center;
    gap: var(--space-2xs);
}

.event-news-card__stats i {
    width: 12px;
    height: 12px;
}

.event-news-cta {
    text-align: center;
    padding: var(--space-lg);
    background: var(--color-accent-light);
    border-radius: var(--radius-md);
}

.event-news-cta p {
    margin: 0 0 var(--space-md);
    color: var(--color-text-secondary);
}

@media (max-width: 767px) {
    .event-news-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<?php elseif ($activeTab === 'anmalda'): ?>
<!-- REGISTERED PARTICIPANTS / STARTLIST TAB -->
<section class="card">
    <div class="card-header">
        <h2 class="card-title">
            <i data-lucide="<?= $hasBibNumbers ? 'list-ordered' : 'users' ?>"></i>
            <?= $hasBibNumbers ? 'Startlista' : 'Anmälda deltagare' ?>
            <span class="badge badge--primary ml-sm"><?= $totalRegistrations ?><?php if ($maxParticipants): ?>/<?= $maxParticipants ?><?php endif; ?></span>
        </h2>
    </div>
    <div class="card-body">
        <?php if (empty($registrationsByClass)): ?>
            <p class="text-muted">Inga anmälningar ännu.</p>
        <?php else: ?>
        <?php foreach ($registrationsByClass as $className => $classRegs): ?>
        <div class="reg-class-group mb-lg">
            <h3 class="reg-class-group__title">
                <?= h($className) ?>
                <span class="badge badge--neutral ml-sm"><?= count($classRegs) ?></span>
            </h3>
            <div class="reg-participants-scroll">
                <table class="table table--striped table--compact reg-participants-table">
                    <?php if ($hasBibNumbers): ?>
                    <colgroup>
                        <col style="width: 12%;">
                        <col style="width: 38%;">
                        <col style="width: 12%;">
                        <col style="width: 38%;">
                    </colgroup>
                    <thead>
                        <tr>
                            <th>Startnr</th>
                            <th>Namn</th>
                            <th>Född</th>
                            <th>Klubb</th>
                        </tr>
                    </thead>
                    <?php else: ?>
                    <colgroup>
                        <col style="width: 45%;">
                        <col style="width: 12%;">
                        <col style="width: 43%;">
                    </colgroup>
                    <thead>
                        <tr>
                            <th>Namn</th>
                            <th>Född</th>
                            <th>Klubb</th>
                        </tr>
                    </thead>
                    <?php endif; ?>
                    <tbody>
                        <?php foreach ($classRegs as $index => $reg):
                            $regName = h(($reg['firstname'] ?? $reg['first_name'] ?? '') . ' ' . ($reg['lastname'] ?? $reg['last_name'] ?? ''));
                            $regClub = h($reg['club_name'] ?? '-');
                            $regBib = h($reg['bib_number'] ?? '-');
                            $regYear = h($reg['birth_year'] ?? '-');
                            $regRiderId = $reg['rider_id'] ?? null;
                        ?>
                        <tr>
                            <?php if ($hasBibNumbers): ?>
                            <td class="text-muted"><?= $regBib ?></td>
                            <?php endif; ?>
                            <td><?php if ($regRiderId): ?><a href="/rider/<?= $regRiderId ?>" class="rider-link"><strong><?= $regName ?></strong></a><?php else: ?><strong><?= $regName ?></strong><?php endif; ?></td>
                            <td class="text-muted"><?= $regYear ?></td>
                            <td class="text-secondary"><?= $regClub ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endforeach; ?>
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
<?php
// Load registration-related data
$currentUser = hub_current_user();
$isLoggedIn = hub_is_logged_in();

// Get pricing info for this event from template
$eventPricing = [];
$pricingTemplate = null;

if (!empty($event['pricing_template_id'])) {
    try {
        // Get template settings (for early bird & late fee percentages)
        $templateStmt = $db->prepare("SELECT * FROM pricing_templates WHERE id = ?");
        $templateStmt->execute([$event['pricing_template_id']]);
        $pricingTemplate = $templateStmt->fetch(PDO::FETCH_ASSOC);

        // Get pricing rules with correct column names
        $pricingStmt = $db->prepare("
            SELECT ptr.class_id, ptr.base_price,
                   c.name as class_name, c.display_name, c.gender, c.min_age, c.max_age
            FROM pricing_template_rules ptr
            JOIN classes c ON c.id = ptr.class_id
            WHERE ptr.template_id = ?
            ORDER BY c.sort_order, c.name
        ");
        $pricingStmt->execute([$event['pricing_template_id']]);
        $eventPricing = $pricingStmt->fetchAll(PDO::FETCH_ASSOC);

        // Calculate early_bird_price and late_fee based on template percentages
        if ($pricingTemplate && !empty($eventPricing)) {
            $earlyBirdPercent = floatval($pricingTemplate['early_bird_percent'] ?? 0);
            $lateFeePercent = floatval($pricingTemplate['late_fee_percent'] ?? 0);

            foreach ($eventPricing as &$pricing) {
                $basePrice = floatval($pricing['base_price']);

                // Early bird = base_price - (base_price * percent / 100)
                $pricing['early_bird_price'] = $basePrice - ($basePrice * $earlyBirdPercent / 100);

                // Late fee = base_price + (base_price * percent / 100)
                $pricing['late_fee'] = $basePrice + ($basePrice * $lateFeePercent / 100);
            }
            unset($pricing); // Break reference
        }

    } catch (PDOException $e) {
        error_log("EVENT PRICING: PDO error loading template pricing for event {$eventId}: " . $e->getMessage());
    }
}

// Fallback: If no template pricing, try event_pricing_rules (legacy)
if (empty($eventPricing)) {
    try {
        $pricingStmt = $db->prepare("
            SELECT epr.class_id, epr.base_price, epr.early_bird_price, epr.late_fee,
                   c.name as class_name, c.display_name, c.gender, c.min_age, c.max_age
            FROM event_pricing_rules epr
            JOIN classes c ON c.id = epr.class_id
            WHERE epr.event_id = ?
            ORDER BY c.sort_order, c.name
        ");
        $pricingStmt->execute([$eventId]);
        $eventPricing = $pricingStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // event_pricing_rules might not exist
    }
}

// Check registration opens time
$now = time();
$registrationOpensTime = null;
$registrationNotYetOpen = false;

if (!empty($event['registration_opens'])) {
    $registrationOpensTime = strtotime($event['registration_opens']);
    $registrationNotYetOpen = ($registrationOpensTime > $now);
}

// Check early bird / late fee status
$isEarlyBird = false;
$isLateFee = false;
$earlyBirdDeadline = null;
$lateFeeStart = null;

// If using pricing template, calculate deadlines from template settings
if ($pricingTemplate && !empty($event['date'])) {
    $eventDate = strtotime($event['date']);

    // Early bird deadline = event_date - early_bird_days_before
    if (!empty($pricingTemplate['early_bird_days_before'])) {
        $earlyBirdDeadline = strtotime("-" . intval($pricingTemplate['early_bird_days_before']) . " days", $eventDate);
        $isEarlyBird = $now < $earlyBirdDeadline;
    }

    // Late fee starts = event_date - late_fee_days_before
    if (!empty($pricingTemplate['late_fee_days_before'])) {
        $lateFeeStart = strtotime("-" . intval($pricingTemplate['late_fee_days_before']) . " days", $eventDate);
        $isLateFee = $now >= $lateFeeStart && $now < $eventDate;
    }
}
// Fallback to event-specific deadlines (legacy or override)
elseif (!empty($event['early_bird_deadline'])) {
    $earlyBirdDeadline = strtotime($event['early_bird_deadline']);
    $isEarlyBird = $now < $earlyBirdDeadline;
}
if (empty($pricingTemplate) && !empty($event['late_fee_start'])) {
    $lateFeeStart = strtotime($event['late_fee_start']);
    $isLateFee = $now >= $lateFeeStart;
}

// Check if user already registered
$existingRegistration = null;
if ($isLoggedIn && $currentUser) {
    try {
        // Note: event_registrations uses 'category' (VARCHAR) instead of class_id
        $checkStmt = $db->prepare("
            SELECT er.*, er.category as class_name
            FROM event_registrations er
            WHERE er.event_id = ? AND er.rider_id = ? AND er.status != 'cancelled'
        ");
        $checkStmt->execute([$eventId, $currentUser['id']]);
        $existingRegistration = $checkStmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {}
}

// Get eligible classes if user is logged in
$eligibleClasses = [];
if ($isLoggedIn && $currentUser && !$existingRegistration) {
    require_once HUB_ROOT . '/includes/registration-validator.php';
    $eligibleClasses = getEligibleClasses($db, $eventId, $currentUser['id']);
}

// Check if event is part of a series with series registration
$seriesPassAvailable = false;
$seriesInfo = null;
if (!empty($event['series_id'])) {
    try {
        $seriesStmt = $db->prepare("
            SELECT s.id, s.name, s.logo, s.allow_series_registration,
                   s.series_discount_percent, s.registration_opens, s.registration_closes
            FROM series s
            WHERE s.id = ? AND s.allow_series_registration = 1
        ");
        $seriesStmt->execute([$event['series_id']]);
        $seriesInfo = $seriesStmt->fetch(PDO::FETCH_ASSOC);
        if ($seriesInfo) {
            $seriesOpen = true;
            if ($seriesInfo['registration_opens'] && strtotime($seriesInfo['registration_opens']) > $now) {
                $seriesOpen = false;
            }
            if ($seriesInfo['registration_closes'] && strtotime($seriesInfo['registration_closes']) < $now) {
                $seriesOpen = false;
            }
            $seriesPassAvailable = $seriesOpen;
        }
    } catch (PDOException $e) {}
}
?>

<style>
/* Registration form styles - mobile first */
.reg-form {
    display: grid;
    gap: var(--space-md);
}

.reg-class-list {
    display: grid;
    gap: var(--space-sm);
}

.reg-class-item {
    display: flex;
    align-items: center;
    gap: var(--space-md);
    padding: var(--space-md);
    background: var(--color-bg-surface);
    border: 2px solid var(--color-border);
    border-radius: var(--radius-md);
    cursor: pointer;
    transition: all 0.2s ease;
}

.reg-class-item:hover {
    border-color: var(--color-accent);
}

.reg-class-item.selected {
    border-color: var(--color-accent);
    background: var(--color-accent-light);
}

.reg-class-item.disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.reg-class-radio {
    width: 20px;
    height: 20px;
    flex-shrink: 0;
}

.reg-class-info {
    flex: 1;
    min-width: 0;
}

.reg-class-name {
    font-weight: 600;
    color: var(--color-text-primary);
}

.reg-class-desc {
    font-size: 0.875rem;
    color: var(--color-text-muted);
}

.reg-class-price {
    text-align: right;
    flex-shrink: 0;
}

.reg-class-price__current {
    font-weight: 700;
    font-size: 1.125rem;
    color: var(--color-accent);
}

.reg-class-price__original {
    font-size: 0.75rem;
    color: var(--color-text-muted);
    text-decoration: line-through;
}

.reg-series-upsell {
    background: linear-gradient(135deg, var(--color-accent-light) 0%, var(--color-bg-surface) 100%);
    border: 1px solid var(--color-accent);
    border-radius: var(--radius-md);
    padding: var(--space-md);
    margin-top: var(--space-md);
}

.reg-series-upsell__header {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    margin-bottom: var(--space-sm);
}

.reg-series-upsell__logo {
    width: 40px;
    height: 40px;
    object-fit: contain;
}

.reg-series-upsell__title {
    font-weight: 600;
    color: var(--color-text-primary);
}

.reg-series-upsell__savings {
    font-size: 0.875rem;
    color: var(--color-success);
    font-weight: 600;
}

.reg-summary {
    background: var(--color-bg-surface);
    border-radius: var(--radius-md);
    padding: var(--space-md);
    margin-top: var(--space-md);
}

.reg-summary__row {
    display: flex;
    justify-content: space-between;
    padding: var(--space-xs) 0;
}

.reg-summary__total {
    border-top: 1px solid var(--color-border);
    padding-top: var(--space-sm);
    margin-top: var(--space-sm);
    font-weight: 700;
    font-size: 1.25rem;
}

.reg-existing {
    padding: var(--space-lg);
    text-align: center;
}

.reg-existing__icon {
    width: 48px;
    height: 48px;
    color: var(--color-success);
    margin-bottom: var(--space-md);
}

@media (max-width: 767px) {
    .reg-class-item {
        flex-wrap: wrap;
        padding: var(--space-sm);
    }
    .reg-class-price {
        width: 100%;
        text-align: left;
        margin-top: var(--space-xs);
        padding-left: 36px;
    }

    /* Compact registration section on mobile */
    .reg-add-rider {
        padding: var(--space-md);
        border-radius: 0;
        border-left: none;
        border-right: none;
        margin-left: calc(-1 * var(--container-padding, 12px));
        margin-right: calc(-1 * var(--container-padding, 12px));
    }

    /* Cart edge-to-edge on mobile */
    .reg-cart {
        border-radius: 0;
        border-left: none;
        border-right: none;
        margin-left: calc(-1 * var(--container-padding, 12px));
        margin-right: calc(-1 * var(--container-padding, 12px));
    }

    .reg-cart__header {
        padding: var(--space-sm) var(--space-md);
    }

    .reg-cart__item {
        padding: var(--space-sm);
        gap: var(--space-sm);
    }

    .reg-cart__summary {
        padding: var(--space-sm) var(--space-md);
    }

    .reg-cart__actions {
        padding: var(--space-sm) var(--space-md);
    }

    /* Compact class list on mobile */
    .reg-class-list {
        gap: var(--space-xs);
    }

    /* Series upsell compact on mobile */
    .reg-series-upsell {
        padding: var(--space-sm);
    }
}

/* Multi-rider cart styles */
.reg-cart {
    background: var(--color-bg-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    margin-bottom: var(--space-lg);
}

.reg-cart__header {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    padding: var(--space-md);
    border-bottom: 1px solid var(--color-border);
}

.reg-cart__header h3 {
    margin: 0;
    font-size: 1rem;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: var(--space-xs);
}

.reg-cart__items {
    padding: var(--space-sm);
}

.reg-cart__item {
    display: flex;
    align-items: center;
    gap: var(--space-md);
    padding: var(--space-sm) var(--space-md);
    background: var(--color-bg-card);
    border-radius: var(--radius-sm);
    margin-bottom: var(--space-xs);
}

.reg-cart__item:last-child {
    margin-bottom: 0;
}

.reg-cart__item-info {
    flex: 1;
    min-width: 0;
}

.reg-cart__item-rider {
    font-weight: 600;
    color: var(--color-text-primary);
}

.reg-cart__item-class {
    font-size: 0.875rem;
    color: var(--color-text-muted);
}

.reg-cart__item-price {
    font-weight: 600;
    color: var(--color-accent);
    white-space: nowrap;
}

.reg-cart__item-remove {
    background: none;
    border: none;
    color: var(--color-text-muted);
    cursor: pointer;
    padding: var(--space-xs);
    border-radius: var(--radius-sm);
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    justify-content: center;
}

.reg-cart__item-remove:hover {
    color: var(--color-error);
    background: rgba(239, 68, 68, 0.1);
}

.reg-cart__summary {
    padding: var(--space-md);
    border-top: 1px solid var(--color-border);
    background: var(--color-bg-hover);
}

.reg-cart__savings {
    background: linear-gradient(135deg, rgba(16, 185, 129, 0.15), rgba(16, 185, 129, 0.05));
    border: 1px solid var(--color-success);
    border-radius: var(--radius-sm);
    padding: var(--space-sm) var(--space-md);
    margin-top: var(--space-sm);
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    color: var(--color-success);
    font-weight: 500;
}

.reg-cart__actions {
    padding: var(--space-md);
    border-top: 1px solid var(--color-border);
}

/* Add rider section */
.reg-add-rider {
    background: var(--color-bg-surface);
    border: 2px dashed var(--color-border);
    border-radius: var(--radius-md);
    padding: var(--space-lg);
}

.reg-add-rider__header {
    margin-bottom: var(--space-md);
}

.reg-add-rider__header h4 {
    margin: 0 0 var(--space-xs) 0;
    font-weight: 600;
}

.reg-rider-select {
    display: grid;
    gap: var(--space-md);
}

.reg-rider-select .form-group {
    margin: 0;
}

/* Create rider inline form */
.reg-create-rider {
    background: var(--color-bg-card);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    padding: var(--space-md);
    margin-top: var(--space-md);
}

.reg-create-rider__fields {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: var(--space-md);
}

@media (max-width: 599px) {
    .reg-create-rider__fields {
        grid-template-columns: 1fr;
    }
}

/* Registered participants by class */
.reg-class-group {
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    overflow: hidden;
}

.reg-class-group__title {
    background: var(--color-bg-surface);
    padding: var(--space-sm) var(--space-md);
    margin: 0;
    font-size: 1rem;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    border-bottom: 1px solid var(--color-border);
}

.reg-class-group .table-wrapper {
    margin: 0;
}

.reg-class-group .table {
    margin: 0;
}

.table--compact th,
.table--compact td {
    padding: var(--space-xs) var(--space-sm);
}

/* Registered participants - horizontally scrollable table */
.reg-participants-scroll {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    margin: 0;
}

.reg-participants-table {
    margin: 0;
    table-layout: fixed;
    width: 100%;
}

.reg-participants-table th,
.reg-participants-table td {
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* Column widths controlled via colgroup in HTML */

@media (max-width: 767px) {
    .reg-participants-scroll {
        margin-left: calc(-1 * var(--space-md));
        margin-right: calc(-1 * var(--space-md));
    }

    .reg-participants-table col:nth-child(1),
    .reg-participants-table th:nth-child(1),
    .reg-participants-table td:nth-child(1) {
        display: none;
    }

    .reg-participants-table th,
    .reg-participants-table td {
        padding: var(--space-xs) var(--space-sm);
        font-size: var(--text-sm);
    }
}

/* Rider search modal */
.rider-search-modal {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    z-index: 9999;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: var(--space-md);
}

.rider-search-modal__backdrop {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.7);
    backdrop-filter: blur(4px);
}

.rider-search-modal__container {
    position: relative;
    background: var(--color-bg-card);
    border-radius: var(--radius-lg);
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
    width: 100%;
    max-width: 600px;
    max-height: 80vh;
    display: flex;
    flex-direction: column;
}

.rider-search-modal__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: var(--space-lg);
    border-bottom: 1px solid var(--color-border);
}

.rider-search-modal__header h3 {
    margin: 0;
    font-size: 1.25rem;
    font-weight: 600;
}

.rider-search-modal__body {
    padding: var(--space-lg);
    overflow-y: auto;
    flex: 1;
}

.rider-search-modal__input-wrapper {
    position: relative;
    margin-bottom: var(--space-lg);
}

.rider-search-modal__search-icon {
    position: absolute;
    left: var(--space-md);
    top: 50%;
    transform: translateY(-50%);
    color: var(--color-text-muted);
    width: 20px;
    height: 20px;
    pointer-events: none;
}

.rider-search-modal__input {
    width: 100%;
    padding: var(--space-md) var(--space-md) var(--space-md) 48px;
    font-size: 1rem;
    border: 2px solid var(--color-border);
    border-radius: var(--radius-md);
    background: var(--color-bg-surface);
    color: var(--color-text-primary);
    transition: border-color 0.2s ease;
}

.rider-search-modal__input:focus {
    outline: none;
    border-color: var(--color-accent);
}

.rider-search-modal__results {
    display: grid;
    gap: var(--space-xs);
}

.rider-search-result {
    padding: var(--space-md);
    background: var(--color-bg-surface);
    border: 2px solid var(--color-border);
    border-radius: var(--radius-md);
    cursor: pointer;
    transition: all 0.2s ease;
}

.rider-search-result:hover {
    border-color: var(--color-accent);
    background: var(--color-accent-light);
}

.rider-search-result__name {
    font-weight: 600;
    font-size: 1.0625rem;
    color: var(--color-text-primary);
    margin-bottom: 4px;
}

.rider-search-result__info {
    font-size: 0.875rem;
    color: var(--color-text-secondary);
    display: flex;
    gap: var(--space-sm);
    flex-wrap: wrap;
}

.rider-search-result__uci {
    color: var(--color-accent);
    font-family: monospace;
    font-weight: 500;
}

.rider-search-empty {
    padding: var(--space-2xl);
    text-align: center;
    color: var(--color-text-muted);
}

.rider-search-empty i {
    width: 48px;
    height: 48px;
    color: var(--color-text-muted);
    margin-bottom: var(--space-md);
}

@media (max-width: 767px) {
    .rider-search-modal {
        padding: 0;
        align-items: stretch;
    }

    .rider-search-modal__container {
        max-width: 100%;
        border-radius: 0;
        height: calc(100vh - var(--header-height));
        height: calc(100dvh - var(--header-height));
        max-height: calc(100vh - var(--header-height));
        max-height: calc(100dvh - var(--header-height));
        margin-top: var(--header-height);
        display: flex;
        flex-direction: column;
    }

    .rider-search-modal__header {
        padding: var(--space-sm) var(--space-md);
        flex-shrink: 0;
    }

    .rider-search-modal__body {
        padding: 0;
        display: flex;
        flex-direction: column;
        flex: 1;
        min-height: 0;
        overflow: hidden;
    }

    .rider-search-modal__input-wrapper {
        margin-bottom: 0;
        padding: var(--space-sm) var(--space-md);
        background: var(--color-bg-card);
        flex-shrink: 0;
        position: relative;
    }

    .rider-search-modal__results {
        flex: 1;
        overflow-y: auto;
        -webkit-overflow-scrolling: touch;
        padding: var(--space-sm) var(--space-md);
    }

    .rider-search-modal__input {
        font-size: 16px; /* Prevents iOS zoom on focus */
        padding: var(--space-sm) var(--space-sm) var(--space-sm) 40px;
    }

    .rider-search-modal__search-icon {
        left: calc(var(--space-md) + var(--space-sm));
        width: 18px;
        height: 18px;
    }

    .rider-search-result {
        padding: var(--space-sm);
    }

    .rider-search-result__name {
        font-size: 1rem;
    }

    .rider-search-result__info {
        font-size: 0.8125rem;
    }

    .rider-search-empty {
        padding: var(--space-xl);
    }
}
</style>

<section class="card">
    <div class="card-header">
        <h2 class="card-title"><i data-lucide="user-plus"></i> Anmälan</h2>
    </div>
    <div class="card-body">
        <?php if ($registrationFull): ?>
            <div class="alert alert--warning">
                <strong>Fullbokat</strong>
                <p>Alla <?= $maxParticipants ?> platser är fyllda. Inga fler anmälningar kan göras.</p>
            </div>

        <?php elseif (!$registrationOpen): ?>
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

        <?php elseif ($registrationNotYetOpen): ?>
            <!-- Registration not yet open - Show countdown -->
            <style>
                .reg-countdown {
                    background: linear-gradient(135deg, var(--color-bg-card) 0%, var(--color-bg-surface) 100%);
                    border: 1px solid var(--color-border);
                    border-radius: var(--radius-lg);
                    padding: var(--space-2xl) var(--space-lg);
                    text-align: center;
                    position: relative;
                    overflow: hidden;
                }
                .reg-countdown::before {
                    content: '';
                    position: absolute;
                    top: 0;
                    left: 0;
                    right: 0;
                    bottom: 0;
                    background: radial-gradient(circle at top right, var(--color-accent-light), transparent 60%);
                    opacity: 0.3;
                    pointer-events: none;
                }
                .reg-countdown__content {
                    position: relative;
                    z-index: 1;
                }
                .reg-countdown__icon {
                    width: 64px;
                    height: 64px;
                    color: var(--color-accent);
                    margin: 0 auto var(--space-md);
                    filter: drop-shadow(0 0 8px var(--color-accent-light));
                }
                .reg-countdown__title {
                    font-size: 1.75rem;
                    font-weight: 700;
                    margin-bottom: var(--space-sm);
                    background: linear-gradient(135deg, var(--color-accent) 0%, var(--color-accent-hover) 100%);
                    -webkit-background-clip: text;
                    background-clip: text;
                    -webkit-text-fill-color: transparent;
                }
                .reg-countdown__date {
                    color: var(--color-text-secondary);
                    margin-bottom: var(--space-xl);
                    font-size: 1.1rem;
                }
                .reg-countdown__timer {
                    display: flex;
                    justify-content: center;
                    gap: var(--space-md);
                    margin: var(--space-xl) 0;
                }
                .reg-countdown__block {
                    display: flex;
                    flex-direction: column;
                    align-items: center;
                    min-width: 70px;
                }
                .reg-countdown__value {
                    font-size: 3rem;
                    font-weight: 800;
                    line-height: 1;
                    background: linear-gradient(180deg, var(--color-text-primary) 0%, var(--color-text-secondary) 100%);
                    -webkit-background-clip: text;
                    background-clip: text;
                    -webkit-text-fill-color: transparent;
                    text-shadow: 0 2px 8px rgba(0,0,0,0.2);
                }
                .reg-countdown__label {
                    font-size: 0.75rem;
                    text-transform: uppercase;
                    letter-spacing: 1px;
                    color: var(--color-text-muted);
                    margin-top: var(--space-xs);
                }
                .reg-countdown__separator {
                    font-size: 2rem;
                    color: var(--color-accent-light);
                    align-self: center;
                    margin-top: -12px;
                }
                @media (max-width: 767px) {
                    .reg-countdown {
                        padding: var(--space-lg) var(--space-sm);
                        margin-left: -16px;
                        margin-right: -16px;
                        border-radius: 0 !important;
                        border-left: none !important;
                        border-right: none !important;
                    }
                    .reg-countdown__icon {
                        width: 48px;
                        height: 48px;
                    }
                    .reg-countdown__title {
                        font-size: 1.3rem;
                    }
                    .reg-countdown__date {
                        font-size: 0.95rem;
                    }
                    .reg-countdown__timer {
                        gap: var(--space-2xs);
                    }
                    .reg-countdown__block {
                        flex: 1;
                        min-width: 0;
                    }
                    .reg-countdown__value {
                        font-size: 2rem;
                    }
                    .reg-countdown__label {
                        font-size: 0.65rem;
                        letter-spacing: 0;
                    }
                    .reg-countdown__separator {
                        font-size: 1.3rem;
                        margin: 0 -2px;
                    }
                }
            </style>
            <div class="reg-countdown">
                <div class="reg-countdown__content">
                    <i data-lucide="clock" class="reg-countdown__icon"></i>
                    <h3 class="reg-countdown__title">Anmälan öppnar snart!</h3>
                    <p class="reg-countdown__date">
                        <strong><?= date('j M Y \k\l. H:i', $registrationOpensTime) ?></strong>
                    </p>
                    <div class="reg-countdown__timer">
                        <div class="reg-countdown__block">
                            <div class="reg-countdown__value" id="countdown-days">00</div>
                            <div class="reg-countdown__label">Dagar</div>
                        </div>
                        <div class="reg-countdown__separator">:</div>
                        <div class="reg-countdown__block">
                            <div class="reg-countdown__value" id="countdown-hours">00</div>
                            <div class="reg-countdown__label">Timmar</div>
                        </div>
                        <div class="reg-countdown__separator">:</div>
                        <div class="reg-countdown__block">
                            <div class="reg-countdown__value" id="countdown-minutes">00</div>
                            <div class="reg-countdown__label">Minuter</div>
                        </div>
                        <div class="reg-countdown__separator">:</div>
                        <div class="reg-countdown__block">
                            <div class="reg-countdown__value" id="countdown-seconds">00</div>
                            <div class="reg-countdown__label">Sekunder</div>
                        </div>
                    </div>
                </div>
            </div>
            <script>
            (function() {
                const opensTime = <?= $registrationOpensTime * 1000 ?>;
                const daysEl = document.getElementById('countdown-days');
                const hoursEl = document.getElementById('countdown-hours');
                const minutesEl = document.getElementById('countdown-minutes');
                const secondsEl = document.getElementById('countdown-seconds');

                function pad(num) {
                    return num < 10 ? '0' + num : num;
                }

                function updateCountdown() {
                    const now = Date.now();
                    const diff = opensTime - now;

                    if (diff <= 0) {
                        location.reload();
                        return;
                    }

                    const days = Math.floor(diff / (1000 * 60 * 60 * 24));
                    const hours = Math.floor((diff % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
                    const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
                    const seconds = Math.floor((diff % (1000 * 60)) / 1000);

                    daysEl.textContent = pad(days);
                    hoursEl.textContent = pad(hours);
                    minutesEl.textContent = pad(minutes);
                    secondsEl.textContent = pad(seconds);

                    setTimeout(updateCountdown, 1000);
                }

                updateCountdown();
            })();
            </script>

        <?php elseif (empty($eventPricing)): ?>
            <?php if (!empty($event['pricing_template_id'])): ?>
                <!-- Has template but no rules - Configuration error -->
                <div class="alert alert--warning" style="display: flex; gap: var(--space-md); text-align: left;">
                    <i data-lucide="alert-triangle" style="flex-shrink: 0; margin-top: 2px;"></i>
                    <div>
                        <strong style="display: block; margin-bottom: var(--space-xs);">Prismall saknar konfiguration</strong>
                        <span>Priserna för detta event är inte konfigurerade ännu. Kontakta arrangören för mer information.</span>
                    </div>
                </div>
            <?php else: ?>
                <!-- No pricing template at all - Either not configured or external registration -->
                <div class="alert alert--info" style="display: flex; gap: var(--space-md); text-align: left;">
                    <i data-lucide="info" style="flex-shrink: 0; margin-top: 2px;"></i>
                    <div>
                        <strong style="display: block; margin-bottom: var(--space-xs);">Anmälan hanteras externt</strong>
                        <span>Eventets anmälan hanteras ej av TheHUB. Kontakta arrangerande klubb för mer information.</span>
                    </div>
                </div>
            <?php endif; ?>

        <?php else: ?>
            <!-- Multi-Rider Registration Form -->
            <?php if ($isLoggedIn && $existingRegistration): ?>
                <!-- Show existing registration info but don't block form -->
                <div class="alert alert--success mb-lg" style="display: flex; gap: var(--space-md); text-align: left;">
                    <i data-lucide="check-circle" style="flex-shrink: 0; margin-top: 2px; width: 24px; height: 24px;"></i>
                    <div style="flex: 1;">
                        <strong style="display: block; margin-bottom: var(--space-xs);">Du är anmäld!</strong>
                        <p style="margin: 0;">
                            Du är anmäld i <strong><?= h($existingRegistration['class_name'] ?: $existingRegistration['category']) ?></strong>
                        </p>
                        <?php if ($existingRegistration['payment_status'] !== 'paid'): ?>
                            <div style="margin-top: var(--space-sm); padding: var(--space-sm); background: rgba(234, 179, 8, 0.1); border-radius: var(--radius-sm);">
                                <i data-lucide="credit-card" style="width: 16px; height: 16px;"></i>
                                <span>Betalning saknas. <a href="/checkout?registration=<?= $existingRegistration['id'] ?>" style="text-decoration: underline;">Betala nu</a></span>
                            </div>
                        <?php else: ?>
                            <span class="badge badge-success" style="margin-top: var(--space-sm);">Betald</span>
                        <?php endif; ?>
                        <p style="margin-top: var(--space-sm); font-size: 0.875rem; color: var(--color-text-secondary);">
                            Du kan lägga till fler deltagare nedan (t.ex. familjemedlemmar).
                        </p>
                    </div>
                </div>
            <?php endif; ?>


            <?php if ($maxParticipants && !$registrationFull): ?>
                <div class="alert alert--info mb-md" style="display: flex; align-items: center; gap: var(--space-sm);">
                    <i data-lucide="users" style="flex-shrink: 0;"></i>
                    <span><strong><?= $spotsLeft ?></strong> av <?= $maxParticipants ?> platser kvar</span>
                </div>
            <?php endif; ?>

            <?php if ($isEarlyBird): ?>
                <div class="alert alert--success mb-md">
                    <i data-lucide="clock"></i>
                    <strong>Early Bird!</strong> Boka före <?= date('j M', $earlyBirdDeadline) ?> och spara pengar.
                </div>
            <?php elseif ($isLateFee): ?>
                <div class="alert alert--warning mb-md">
                    <i data-lucide="alert-triangle"></i>
                    <strong>Efteranmälan</strong> - extra avgift tillkommer.
                </div>
            <?php endif; ?>

            <!-- Cart (hidden until items added) -->
            <div id="registrationCart" class="reg-cart" style="display:none;">
                <div class="reg-cart__header">
                    <h3><i data-lucide="shopping-cart"></i> Anmälningar</h3>
                </div>
                <div id="cartItems" class="reg-cart__items"></div>
                <div class="reg-cart__summary">
                    <div class="reg-summary__row">
                        <span>Antal deltagare</span>
                        <span id="cartCount">0</span>
                    </div>
                    <div class="reg-summary__row reg-summary__total">
                        <span>Totalt</span>
                        <span id="cartTotal">0 kr</span>
                    </div>
                </div>
                <button type="button" id="checkoutBtn" class="btn btn--primary btn--lg btn--block">
                    <i data-lucide="shopping-cart"></i>
                    Gå till varukorgen
                </button>
            </div>

            <!-- Add Rider Section -->
            <div id="addRiderSection" class="reg-add-rider">
                <h3 class="mb-md">Lägg till deltagare</h3>

                <!-- Rider Search -->
                <div class="form-group">
                    <!-- Selected rider display -->
                    <div id="selectedRiderDisplay" style="display: none; margin-bottom: var(--space-sm); padding: var(--space-md); background: var(--color-bg-surface); border-radius: var(--radius-md); border: 2px solid var(--color-accent);">
                        <div style="display: flex; justify-content: space-between; align-items: center; gap: var(--space-md);">
                            <div style="flex: 1;">
                                <strong id="selectedRiderName" style="font-size: 1.125rem; display: block; margin-bottom: 4px;"></strong>
                                <div style="font-size: 0.875rem; color: var(--color-text-secondary);" id="selectedRiderInfo"></div>
                            </div>
                            <button type="button" id="clearSelectedRider" class="btn btn--ghost btn--sm">
                                <i data-lucide="x"></i> Ändra
                            </button>
                        </div>
                        <div id="licenseValidationResult" style="display: none; margin-top: var(--space-sm); padding: var(--space-sm) var(--space-md); border-radius: var(--radius-sm); font-size: 0.875rem;"></div>
                    </div>

                    <!-- Open search modal button -->
                    <button type="button" id="openRiderSearchBtn" class="btn btn--outline btn--block" style="border: 2px solid var(--color-border-strong);">
                        <i data-lucide="search"></i>
                        Sök deltagare (namn eller UCI ID)
                    </button>
                </div>

                <!-- Rider Search Modal -->
                <div id="riderSearchModal" class="rider-search-modal" style="display: none;">
                    <div class="rider-search-modal__backdrop"></div>
                    <div class="rider-search-modal__container">
                        <div class="rider-search-modal__header">
                            <h3>Sök deltagare</h3>
                            <button type="button" id="closeRiderSearchBtn" class="btn btn--ghost btn--sm">
                                <i data-lucide="x"></i>
                            </button>
                        </div>
                        <div class="rider-search-modal__body">
                            <div id="riderSearchSection" style="display: flex; flex-direction: column; height: 100%;">
                                <div class="rider-search-modal__input-wrapper">
                                    <i data-lucide="search" class="rider-search-modal__search-icon"></i>
                                    <input type="text" id="riderSearch" class="rider-search-modal__input"
                                           placeholder="Skriv namn eller UCI ID..."
                                           autocomplete="off">
                                </div>
                                <div style="padding: var(--space-xs) var(--space-lg); flex-shrink: 0;">
                                    <button type="button" id="showCreateRiderFormBtn" style="background: none; border: none; color: var(--color-accent); cursor: pointer; font-size: 0.875rem; display: inline-flex; align-items: center; gap: var(--space-xs); padding: var(--space-2xs) 0;">
                                        <i data-lucide="user-plus" style="width: 16px; height: 16px;"></i> Skapa ny deltagare
                                    </button>
                                </div>
                                <div id="riderSearchResults" class="rider-search-modal__results"></div>
                            </div>
                            <div id="riderCreateSection" style="display: none; height: 100%;"></div>
                        </div>
                    </div>
                </div>

                <!-- Class Selection (shown after rider selected) -->
                <div id="classSelection" style="display:none;">
                    <label class="form-label">Välj klass</label>
                    <div id="classList" class="reg-class-list"></div>

                    <!-- License Commitment (shown if needed) -->
                    <div id="licenseCommitment" style="display:none; margin-top: var(--space-md);">
                        <div style="background: var(--color-bg-surface); border: 2px solid var(--color-warning); border-radius: var(--radius-md); padding: var(--space-md);">
                            <label style="display: flex; gap: var(--space-sm); cursor: pointer; align-items: flex-start;">
                                <input type="checkbox" id="licenseCommitmentCheckbox" style="margin-top: 2px; width: 18px; height: 18px; cursor: pointer;">
                                <span style="flex: 1; font-size: 0.9375rem; line-height: 1.5;">
                                    Jag förbinder mig att köpa en giltig licens innan eventet för att kunna starta
                                </span>
                            </label>
                        </div>
                    </div>

                    <button type="button" id="addToCartBtn" class="btn btn--secondary btn--block mt-md" disabled>
                        <i data-lucide="plus"></i>
                        Lägg till i anmälan
                    </button>
                </div>

                <!-- Keep button for JavaScript but hidden (not shown to user) -->
                <div class="reg-add-more mt-md" style="display: none !important;">
                    <button type="button" id="addAnotherBtn" class="btn btn--ghost btn--sm">
                        <i data-lucide="user-plus"></i>
                        Lägg till fler deltagare
                    </button>
                </div>
            </div>

            <?php if ($seriesRegistrationAvailable && $seriesInfo): ?>
            <!-- SERIES REGISTRATION SECTION -->
            <div style="margin-top: var(--space-2xl); padding-top: var(--space-xl); border-top: 2px solid var(--color-border);">
                <h3 style="display: flex; align-items: center; gap: var(--space-sm); margin-bottom: var(--space-md);">
                    <i data-lucide="trophy"></i>
                    Serieanmälan: <?= h($seriesInfo['name']) ?>
                </h3>
                <p style="margin-bottom: var(--space-lg); color: var(--color-text-secondary);">
                    Anmäl dig till alla <?= count($seriesEventsWithPricing) ?> event i serien samtidigt.
                </p>

                <!-- Series Rider Search -->
                <div class="form-group">
                    <div id="seriesSelectedRiderDisplay" style="display: none; margin-bottom: var(--space-sm); padding: var(--space-md); background: var(--color-bg-surface); border-radius: var(--radius-md); border: 2px solid var(--color-accent);">
                        <div style="display: flex; justify-content: space-between; align-items: center; gap: var(--space-md);">
                            <div style="flex: 1;">
                                <strong id="seriesSelectedRiderName" style="font-size: 1.125rem; display: block; margin-bottom: 4px;"></strong>
                                <div style="font-size: 0.875rem; color: var(--color-text-secondary);" id="seriesSelectedRiderInfo"></div>
                            </div>
                            <button type="button" id="seriesClearSelectedRider" class="btn btn--ghost btn--sm">
                                <i data-lucide="x"></i> Ändra
                            </button>
                        </div>
                        <div id="seriesLicenseValidationResult" style="display: none; margin-top: var(--space-sm); padding: var(--space-sm) var(--space-md); border-radius: var(--radius-sm); font-size: 0.875rem;"></div>
                    </div>

                    <button type="button" id="seriesOpenRiderSearchBtn" class="btn btn--outline btn--block" style="border: 2px solid var(--color-border-strong);">
                        <i data-lucide="search"></i>
                        Sök deltagare (namn eller UCI ID)
                    </button>
                </div>

                <!-- Series Rider Search Modal -->
                <div id="seriesRiderSearchModal" class="rider-search-modal" style="display: none;">
                    <div class="rider-search-modal__backdrop"></div>
                    <div class="rider-search-modal__container">
                        <div class="rider-search-modal__header">
                            <h3>Sök deltagare</h3>
                            <button type="button" id="seriesCloseRiderSearchBtn" class="btn btn--ghost btn--sm">
                                <i data-lucide="x"></i>
                            </button>
                        </div>
                        <div class="rider-search-modal__body">
                            <div id="seriesRiderSearchSection" style="display: flex; flex-direction: column; height: 100%;">
                                <div class="rider-search-modal__input-wrapper">
                                    <i data-lucide="search" class="rider-search-modal__search-icon"></i>
                                    <input type="text" id="seriesRiderSearch" class="rider-search-modal__input"
                                           placeholder="Skriv namn eller UCI ID..."
                                           autocomplete="off">
                                </div>
                                <div style="padding: var(--space-xs) var(--space-lg); flex-shrink: 0;">
                                    <button type="button" id="seriesShowCreateRiderFormBtn" style="background: none; border: none; color: var(--color-accent); cursor: pointer; font-size: 0.875rem; display: inline-flex; align-items: center; gap: var(--space-xs); padding: var(--space-2xs) 0;">
                                        <i data-lucide="user-plus" style="width: 16px; height: 16px;"></i> Skapa ny deltagare
                                    </button>
                                </div>
                                <div id="seriesRiderSearchResults" class="rider-search-modal__results"></div>
                            </div>
                            <div id="seriesRiderCreateSection" style="display: none; height: 100%;"></div>
                        </div>
                    </div>
                </div>

                <!-- Series Class Selection -->
                <div id="seriesClassSelection" style="display:none;">
                    <label class="form-label">Välj klass</label>
                    <div id="seriesClassList" class="reg-class-list"></div>

                    <!-- Series License Commitment -->
                    <div id="seriesLicenseCommitment" style="display:none; margin-top: var(--space-md);">
                        <div style="background: var(--color-bg-surface); border: 2px solid var(--color-warning); border-radius: var(--radius-md); padding: var(--space-md);">
                            <label style="display: flex; gap: var(--space-sm); cursor: pointer; align-items: flex-start;">
                                <input type="checkbox" id="seriesLicenseCommitmentCheckbox" style="margin-top: 2px; width: 18px; height: 18px; cursor: pointer;">
                                <span style="flex: 1; font-size: 0.9375rem; line-height: 1.5;">
                                    Jag förbinder mig att köpa en giltig licens innan eventet för att kunna starta
                                </span>
                            </label>
                        </div>
                    </div>

                    <button type="button" id="seriesAddToCartBtn" class="btn btn--primary btn--block mt-md" disabled>
                        <i data-lucide="plus"></i>
                        Lägg till alla <?= count($seriesEventsWithPricing) ?> event
                    </button>
                </div>
            </div>
            <?php endif; ?>

            <script>
            (function() {
                const eventId = <?= $eventId ?>;
                const currentUserId = <?= $currentUser['id'] ?? 0 ?>;
                const isEarlyBird = <?= $isEarlyBird ? 'true' : 'false' ?>;
                const isLateFee = <?= $isLateFee ? 'true' : 'false' ?>;

                // Cart state - use GlobalCart instead of local array
                let availableRiders = [];
                let selectedRiderId = null;
                let selectedClassId = null;
                let selectedClassData = null;
                let requiresLicenseCommitment = false;
                let licenseCommitmentAccepted = false;

                // DOM elements
                const riderSearchModal = document.getElementById('riderSearchModal');
                const openRiderSearchBtn = document.getElementById('openRiderSearchBtn');
                const closeRiderSearchBtn = document.getElementById('closeRiderSearchBtn');
                const riderSearch = document.getElementById('riderSearch');
                const riderSearchResults = document.getElementById('riderSearchResults');
                const selectedRiderDisplay = document.getElementById('selectedRiderDisplay');
                const selectedRiderName = document.getElementById('selectedRiderName');
                const selectedRiderInfo = document.getElementById('selectedRiderInfo');
                const clearSelectedRider = document.getElementById('clearSelectedRider');
                const classSelection = document.getElementById('classSelection');
                const classList = document.getElementById('classList');
                const addToCartBtn = document.getElementById('addToCartBtn');
                const addAnotherBtn = document.getElementById('addAnotherBtn');
                const registrationCart = document.getElementById('registrationCart');
                const cartItems = document.getElementById('cartItems');
                const cartCount = document.getElementById('cartCount');
                const cartTotal = document.getElementById('cartTotal');
                const checkoutBtn = document.getElementById('checkoutBtn');

                // Search state
                let searchTimeout = null;
                let selectedRider = null;

                // Staging area: items added on this page but not yet committed to cart
                // These are lost on page reload - only committed when clicking "Gå till kundvagn"
                let pendingItems = [];

                // Search riders in database
                async function searchRiders(query) {
                    if (query.length < 2) {
                        riderSearchResults.style.display = 'none';
                        return;
                    }

                    try {
                        const response = await fetch(`/api/orders.php?action=search_riders&q=${encodeURIComponent(query)}`);
                        const data = await response.json();

                        if (data.success && data.riders.length > 0) {
                            renderSearchResults(data.riders);
                        } else {
                            renderEmptyResults();
                        }
                    } catch (e) {
                        console.error('Search failed:', e);
                        riderSearchResults.style.display = 'none';
                    }
                }

                function getCreateRiderFormHtml(prefix = '') {
                    const searchEl = document.getElementById(prefix === 'series' ? 'seriesRiderSearch' : 'riderSearch');
                    const searchVal = (searchEl ? searchEl.value : '').trim();
                    const nameParts = searchVal.split(' ');
                    const suggestedFirst = nameParts[0] || '';
                    const suggestedLast = nameParts.slice(1).join(' ') || '';
                    const p = prefix;

                    const labelStyle = 'display: block; font-size: 0.8rem; font-weight: 600; color: var(--color-text-muted); margin-bottom: var(--space-2xs); text-transform: uppercase; letter-spacing: 0.3px;';
                    const inputStyle = 'width: 100%; padding: 10px 12px; font-size: 1rem; background: var(--color-bg-surface); border: 1px solid var(--color-border); border-radius: var(--radius-sm); color: var(--color-text-primary); box-sizing: border-box;';

                    return `
                        <div style="display: flex; flex-direction: column; height: 100%; overflow: hidden;">
                            <div style="padding: var(--space-sm) var(--space-lg); border-bottom: 1px solid var(--color-border); flex-shrink: 0;">
                                <button type="button" id="${p}backToSearchBtn" style="background: none; border: none; color: var(--color-accent); cursor: pointer; font-size: 0.875rem; display: inline-flex; align-items: center; gap: var(--space-2xs); padding: var(--space-xs) 0;">
                                    <i data-lucide="arrow-left" style="width: 16px; height: 16px;"></i> Tillbaka till sök
                                </button>
                            </div>
                            <div style="flex: 1; overflow-y: auto; -webkit-overflow-scrolling: touch; padding: var(--space-lg);">

                                <!-- UCI ID Lookup -->
                                <div style="margin-bottom: var(--space-lg); padding: var(--space-md); background: var(--color-bg-sunken); border-radius: var(--radius-md); border: 1px solid var(--color-border);">
                                    <label style="display: block; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; color: var(--color-text-muted); margin-bottom: var(--space-xs);">Sök din licens via UCI ID</label>
                                    <div style="display: flex; gap: var(--space-xs);">
                                        <input type="text" id="${p}uciIdLookup" style="${inputStyle} flex: 1;" placeholder="T.ex. 10012345678" inputmode="numeric">
                                        <button type="button" id="${p}uciLookupBtn" style="padding: 10px 16px; background: var(--color-accent); color: var(--color-bg-page); border: none; border-radius: var(--radius-sm); font-weight: 600; cursor: pointer; white-space: nowrap; display: flex; align-items: center; gap: var(--space-2xs); min-height: 44px;">
                                            <i data-lucide="search" style="width: 16px; height: 16px;"></i> Sök
                                        </button>
                                    </div>
                                    <div id="${p}uciLookupStatus" style="display:none; margin-top: var(--space-xs); font-size: 0.875rem;"></div>
                                </div>

                                <div style="display: flex; flex-direction: column; gap: var(--space-sm);">
                                    <div>
                                        <label style="${labelStyle}">Förnamn *</label>
                                        <input type="text" id="${p}newRiderFirstname" style="${inputStyle}" value="${suggestedFirst}" required>
                                    </div>
                                    <div>
                                        <label style="${labelStyle}">Efternamn *</label>
                                        <input type="text" id="${p}newRiderLastname" style="${inputStyle}" value="${suggestedLast}" required>
                                    </div>
                                    <div>
                                        <label style="${labelStyle}">E-post *</label>
                                        <input type="email" id="${p}newRiderEmail" style="${inputStyle}" placeholder="namn@exempel.se" required>
                                    </div>
                                    <div>
                                        <label style="${labelStyle}">Telefon *</label>
                                        <input type="tel" id="${p}newRiderPhone" style="${inputStyle}" placeholder="070-123 45 67" required>
                                    </div>
                                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--space-sm);">
                                        <div>
                                            <label style="${labelStyle}">Födelseår *</label>
                                            <input type="number" id="${p}newRiderBirthYear" style="${inputStyle}" placeholder="t.ex. 1990" min="1920" max="2025" required>
                                        </div>
                                        <div>
                                            <label style="${labelStyle}">Kön *</label>
                                            <select id="${p}newRiderGender" style="${inputStyle}" required>
                                                <option value="">Välj...</option>
                                                <option value="M">Man</option>
                                                <option value="F">Kvinna</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div>
                                        <label style="${labelStyle}">Nationalitet</label>
                                        <select id="${p}newRiderNationality" style="${inputStyle}">
                                            <option value="SWE" selected>Sverige</option>
                                            <option value="NOR">Norge</option>
                                            <option value="DNK">Danmark</option>
                                            <option value="FIN">Finland</option>
                                            <option value="DEU">Tyskland</option>
                                            <option value="GBR">Storbritannien</option>
                                            <option value="USA">USA</option>
                                            <option value="">Annan</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label style="${labelStyle}">Klubb</label>
                                        <div style="position: relative;">
                                            <input type="text" id="${p}newRiderClubSearch" style="${inputStyle}" placeholder="Sök klubb..." autocomplete="off">
                                            <input type="hidden" id="${p}newRiderClubId" value="">
                                            <div id="${p}clubSearchResults" style="display:none; position:absolute; top:100%; left:0; right:0; z-index:100; background:var(--color-bg-card); border:1px solid var(--color-border); border-top:none; border-radius:0 0 var(--radius-sm) var(--radius-sm); max-height:200px; overflow-y:auto; box-shadow: 0 4px 12px rgba(0,0,0,0.3);"></div>
                                        </div>
                                    </div>
                                </div>

                                <div style="margin: var(--space-md) 0; padding-top: var(--space-md); border-top: 1px solid var(--color-border);">
                                    <span style="color: var(--color-text-muted); font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600;">Nödkontakt (ICE)</span>
                                </div>
                                <div style="display: flex; flex-direction: column; gap: var(--space-sm);">
                                    <div>
                                        <label style="${labelStyle}">Namn *</label>
                                        <input type="text" id="${p}newRiderIceName" style="${inputStyle}" placeholder="Förnamn Efternamn" required>
                                    </div>
                                    <div>
                                        <label style="${labelStyle}">Telefon *</label>
                                        <input type="tel" id="${p}newRiderIcePhone" style="${inputStyle}" placeholder="070-123 45 67" required>
                                    </div>
                                </div>

                                <button type="button" id="${p}createRiderBtn" class="btn btn--primary btn--block" style="margin-top: var(--space-lg); padding: var(--space-md); width: 100%; background: var(--color-accent); color: var(--color-bg-page); border: none; border-radius: var(--radius-sm); font-size: 1rem; font-weight: 600; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: var(--space-xs);">
                                    <i data-lucide="user-plus"></i> Skapa och välj
                                </button>
                                <div id="${p}createRiderError" style="display:none; margin-top: var(--space-sm); font-size: 0.875rem;"></div>
                            </div>
                        </div>
                    `;
                }

                function showCreateRiderForm(prefix = '') {
                    const p = prefix;
                    const searchSection = document.getElementById(p ? p + 'RiderSearchSection' : 'riderSearchSection');
                    const createSection = document.getElementById(p ? p + 'RiderCreateSection' : 'riderCreateSection');

                    // Update modal header
                    const modal = document.getElementById(p ? 'seriesRiderSearchModal' : 'riderSearchModal');
                    const headerH3 = modal.querySelector('.rider-search-modal__header h3');
                    if (headerH3) headerH3.textContent = 'Skapa ny deltagare';

                    createSection.innerHTML = getCreateRiderFormHtml(prefix);
                    searchSection.style.display = 'none';
                    createSection.style.display = 'flex';
                    createSection.style.flexDirection = 'column';

                    if (typeof lucide !== 'undefined') lucide.createIcons();

                    // Back to search button
                    const backBtn = document.getElementById(p + 'backToSearchBtn');
                    if (backBtn) {
                        backBtn.addEventListener('click', function() {
                            createSection.style.display = 'none';
                            searchSection.style.display = 'flex';
                            if (headerH3) headerH3.textContent = 'Sök deltagare';
                        });
                    }

                    // Create rider button
                    const createBtn = document.getElementById(p + 'createRiderBtn');
                    if (createBtn) {
                        createBtn.addEventListener('click', function() {
                            handleCreateRider(prefix);
                        });
                    }

                    // UCI ID Lookup
                    const uciInput = document.getElementById(p + 'uciIdLookup');
                    const uciBtn = document.getElementById(p + 'uciLookupBtn');
                    const uciStatus = document.getElementById(p + 'uciLookupStatus');

                    async function doUciLookup() {
                        const uciId = uciInput.value.trim().replace(/\D/g, '');
                        if (!uciId || uciId.length < 9) {
                            uciStatus.innerHTML = 'Ange ett giltigt UCI ID (9-11 siffror)';
                            uciStatus.style.color = 'var(--color-error)';
                            uciStatus.style.display = 'block';
                            return;
                        }

                        uciBtn.disabled = true;
                        uciBtn.innerHTML = '<i data-lucide="loader" style="width:16px;height:16px;"></i> Söker...';
                        uciStatus.style.display = 'none';

                        try {
                            const resp = await fetch('/api/scf-lookup.php?uci_id=' + encodeURIComponent(uciId));
                            const data = await resp.json();

                            if (data.success && data.rider) {
                                const r = data.rider;

                                // If rider already exists in database
                                if (data.source === 'database' && data.existing_rider_id) {
                                    uciStatus.innerHTML = '<span style="color: var(--color-warning);"><strong>' + (r.firstname || '') + ' ' + (r.lastname || '') + '</strong> finns redan i databasen. Sök på namnet istället.</span>';
                                    uciStatus.style.display = 'block';
                                    uciBtn.disabled = false;
                                    uciBtn.innerHTML = '<i data-lucide="search" style="width:16px;height:16px;"></i> Sök';
                                    if (typeof lucide !== 'undefined') lucide.createIcons();
                                    return;
                                }

                                // Auto-fill form fields
                                if (r.firstname) document.getElementById(p + 'newRiderFirstname').value = r.firstname;
                                if (r.lastname) document.getElementById(p + 'newRiderLastname').value = r.lastname;
                                if (r.birth_year) document.getElementById(p + 'newRiderBirthYear').value = r.birth_year;
                                if (r.gender) {
                                    const genderSel = document.getElementById(p + 'newRiderGender');
                                    if (genderSel) genderSel.value = r.gender;
                                }
                                if (r.nationality) {
                                    const natSel = document.getElementById(p + 'newRiderNationality');
                                    if (natSel) {
                                        for (let opt of natSel.options) {
                                            if (opt.value === r.nationality) { natSel.value = r.nationality; break; }
                                        }
                                    }
                                }

                                // Auto-fill club name from SCF lookup
                                if (r.club_name) {
                                    const clubSearch = document.getElementById(p + 'newRiderClubSearch');
                                    if (clubSearch) clubSearch.value = r.club_name;
                                }

                                let info = '<span style="color: var(--color-success);"><strong>' + (r.firstname || '') + ' ' + (r.lastname || '') + '</strong>';
                                if (r.club_name) info += ' - ' + r.club_name;
                                if (r.license_type) info += ' (' + r.license_type + ')';
                                info += '</span>';
                                uciStatus.innerHTML = info;
                                uciStatus.style.display = 'block';

                                // Focus email (first empty required field)
                                const emailInput = document.getElementById(p + 'newRiderEmail');
                                if (emailInput && !emailInput.value) setTimeout(() => emailInput.focus(), 100);
                            } else {
                                uciStatus.innerHTML = '<span style="color: var(--color-text-secondary);">' + (data.error || 'Ingen licens hittades') + '</span>';
                                uciStatus.style.display = 'block';
                            }
                        } catch (err) {
                            uciStatus.innerHTML = '<span style="color: var(--color-error);">Kunde inte kontakta SCF</span>';
                            uciStatus.style.display = 'block';
                        }

                        uciBtn.disabled = false;
                        uciBtn.innerHTML = '<i data-lucide="search" style="width:16px;height:16px;"></i> Sök';
                        if (typeof lucide !== 'undefined') lucide.createIcons();
                    }

                    if (uciBtn) uciBtn.addEventListener('click', doUciLookup);
                    if (uciInput) uciInput.addEventListener('keydown', function(e) {
                        if (e.key === 'Enter') { e.preventDefault(); doUciLookup(); }
                    });

                    // Club search with typeahead
                    const clubInput = document.getElementById(p + 'newRiderClubSearch');
                    const clubIdInput = document.getElementById(p + 'newRiderClubId');
                    const clubResults = document.getElementById(p + 'clubSearchResults');
                    let clubSearchTimer = null;

                    if (clubInput) {
                        clubInput.addEventListener('input', function() {
                            clearTimeout(clubSearchTimer);
                            const q = this.value.trim();
                            if (q.length < 2) {
                                clubResults.style.display = 'none';
                                clubIdInput.value = '';
                                return;
                            }
                            clubSearchTimer = setTimeout(async () => {
                                try {
                                    const resp = await fetch('/api/search.php?q=' + encodeURIComponent(q) + '&type=clubs&limit=8');
                                    const data = await resp.json();
                                    if (data.results && data.results.length > 0) {
                                        clubResults.innerHTML = data.results.map(c =>
                                            '<div style="padding: var(--space-sm) var(--space-md); cursor: pointer; font-size: 0.9rem; border-bottom: 1px solid var(--color-border);" ' +
                                            'onmouseover="this.style.background=\'var(--color-bg-hover)\'" onmouseout="this.style.background=\'none\'" ' +
                                            'data-club-id="' + c.id + '" data-club-name="' + (c.name || '').replace(/"/g, '&quot;') + '">' +
                                            '<strong>' + (c.name || '') + '</strong>' +
                                            (c.member_count ? ' <span style="color: var(--color-text-muted); font-size: 0.8rem;">(' + c.member_count + ' medlemmar)</span>' : '') +
                                            '</div>'
                                        ).join('');
                                        clubResults.style.display = 'block';
                                        clubResults.querySelectorAll('[data-club-id]').forEach(el => {
                                            el.addEventListener('click', function() {
                                                clubInput.value = this.dataset.clubName;
                                                clubIdInput.value = this.dataset.clubId;
                                                clubResults.style.display = 'none';
                                            });
                                        });
                                    } else {
                                        clubResults.innerHTML = '<div style="padding: var(--space-sm) var(--space-md); color: var(--color-text-muted); font-size: 0.875rem;">Ingen klubb hittades</div>';
                                        clubResults.style.display = 'block';
                                    }
                                } catch(e) { clubResults.style.display = 'none'; }
                            }, 300);
                        });

                        // Clear club_id if user types after selecting
                        clubInput.addEventListener('keydown', function() {
                            if (clubIdInput.value) clubIdInput.value = '';
                        });

                        // Close on click outside
                        document.addEventListener('click', function(e) {
                            if (!clubInput.contains(e.target) && !clubResults.contains(e.target)) {
                                clubResults.style.display = 'none';
                            }
                        });
                    }

                    // Focus UCI input first
                    if (uciInput) setTimeout(() => uciInput.focus(), 100);
                }

                async function handleCreateRider(prefix = '') {
                    const p = prefix;
                    const firstname = document.getElementById(p + 'newRiderFirstname').value.trim();
                    const lastname = document.getElementById(p + 'newRiderLastname').value.trim();
                    const email = document.getElementById(p + 'newRiderEmail').value.trim();
                    const birthYear = document.getElementById(p + 'newRiderBirthYear').value.trim();
                    const gender = document.getElementById(p + 'newRiderGender').value;
                    const nationality = document.getElementById(p + 'newRiderNationality').value;
                    const phone = document.getElementById(p + 'newRiderPhone').value.trim();
                    const clubId = document.getElementById(p + 'newRiderClubId').value || null;
                    const iceName = document.getElementById(p + 'newRiderIceName').value.trim();
                    const icePhone = document.getElementById(p + 'newRiderIcePhone').value.trim();
                    const errorDiv = document.getElementById(p + 'createRiderError');
                    const btn = document.getElementById(p + 'createRiderBtn');

                    // Validate all required fields
                    if (!firstname || !lastname) {
                        errorDiv.innerHTML = 'Förnamn och efternamn krävs.';
                        errorDiv.style.color = 'var(--color-error)';
                        errorDiv.style.display = 'block';
                        return;
                    }
                    if (!email) {
                        errorDiv.innerHTML = 'E-post krävs.';
                        errorDiv.style.color = 'var(--color-error)';
                        errorDiv.style.display = 'block';
                        return;
                    }
                    if (!birthYear) {
                        errorDiv.innerHTML = 'Födelseår krävs för att bestämma klass.';
                        errorDiv.style.color = 'var(--color-error)';
                        errorDiv.style.display = 'block';
                        return;
                    }
                    if (!gender) {
                        errorDiv.innerHTML = 'Kön krävs för att bestämma klass.';
                        errorDiv.style.color = 'var(--color-error)';
                        errorDiv.style.display = 'block';
                        return;
                    }
                    if (!phone) {
                        errorDiv.innerHTML = 'Telefonnummer krävs.';
                        errorDiv.style.color = 'var(--color-error)';
                        errorDiv.style.display = 'block';
                        return;
                    }
                    if (!iceName || !icePhone) {
                        errorDiv.innerHTML = 'Nödkontakt (namn och telefon) krävs.';
                        errorDiv.style.color = 'var(--color-error)';
                        errorDiv.style.display = 'block';
                        return;
                    }

                    btn.disabled = true;
                    btn.textContent = 'Skapar...';
                    errorDiv.style.display = 'none';

                    try {
                        const response = await fetch('/api/orders.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({
                                action: 'create_rider',
                                rider: {
                                    firstname, lastname, email,
                                    birth_year: birthYear,
                                    gender, nationality, phone,
                                    club_id: clubId,
                                    ice_name: iceName,
                                    ice_phone: icePhone
                                }
                            })
                        });
                        const data = await response.json();

                        if (data.success && data.rider) {
                            if (prefix === 'series' && window._seriesSelectRider) {
                                window._seriesSelectRider(data.rider);
                            } else {
                                selectRider(data.rider);
                            }
                        } else {
                            // Check for specific error codes
                            if (data.code === 'email_exists_active') {
                                errorDiv.innerHTML = '<span style="color: var(--color-warning);">' + (data.error || '') + '</span>' +
                                    '<br><a href="/login" style="color: var(--color-accent); text-decoration: underline; font-weight: 500;">Logga in här</a>';
                            } else if (data.code === 'email_exists_inactive') {
                                errorDiv.innerHTML = '<span style="color: var(--color-warning);">' + (data.error || '') + '</span>' +
                                    '<br><span style="color: var(--color-text-secondary);">Sök på namnet istället för att hitta profilen.</span>';
                            } else if (data.code === 'name_duplicate') {
                                errorDiv.innerHTML = '<span style="color: var(--color-warning);">' + (data.error || '') + '</span>' +
                                    '<br><button type="button" onclick="document.getElementById(\'' + prefix + 'backToSearchBtn\')?.click()" ' +
                                    'style="color: var(--color-accent); background: none; border: none; cursor: pointer; text-decoration: underline; font-weight: 500; padding: 0; margin-top: var(--space-xs);">' +
                                    'Tillbaka till sök</button>';
                            } else {
                                errorDiv.innerHTML = data.error || 'Kunde inte skapa deltagare.';
                                errorDiv.style.color = 'var(--color-error)';
                            }
                            errorDiv.style.display = 'block';
                            btn.disabled = false;
                            btn.innerHTML = '<i data-lucide="user-plus"></i> Skapa och välj';
                            if (typeof lucide !== 'undefined') lucide.createIcons();
                        }
                    } catch (e) {
                        console.error('Create rider failed:', e);
                        errorDiv.innerHTML = 'Något gick fel. Försök igen.';
                        errorDiv.style.color = 'var(--color-error)';
                        errorDiv.style.display = 'block';
                        btn.disabled = false;
                        btn.innerHTML = '<i data-lucide="user-plus"></i> Skapa och välj';
                        if (typeof lucide !== 'undefined') lucide.createIcons();
                    }
                }

                function renderSearchResults(riders) {
                    let html = riders.map(rider => {
                        const infoItems = [];
                        if (rider.birth_year) infoItems.push(rider.birth_year);
                        if (rider.club_name) infoItems.push(rider.club_name);
                        if (rider.license_number) infoItems.push(`<span class="rider-search-result__uci">UCI: ${rider.license_number}</span>`);

                        const licenseIndicator = rider.has_valid_license && rider.license_display_year
                            ? `<span style="display: inline-flex; align-items: center; gap: 4px; color: var(--color-success); font-weight: 500; font-size: 0.875rem;">
                                 <i data-lucide="check-circle" style="width: 16px; height: 16px;"></i> Licens ${rider.license_display_year}
                               </span>`
                            : '';

                        return `
                            <div class="rider-search-result" data-rider-id="${rider.id}">
                                <div class="rider-search-result__name">
                                    ${rider.firstname} ${rider.lastname}
                                    ${licenseIndicator}
                                </div>
                                <div class="rider-search-result__info">${infoItems.join(' • ')}</div>
                            </div>
                        `;
                    }).join('');

                    riderSearchResults.innerHTML = html;
                    if (typeof lucide !== 'undefined') lucide.createIcons();

                    riderSearchResults.querySelectorAll('.rider-search-result').forEach(el => {
                        el.addEventListener('click', function() {
                            const riderId = this.dataset.riderId;
                            const rider = riders.find(r => r.id == riderId);
                            if (rider) selectRider(rider);
                        });
                    });
                }

                function renderEmptyResults() {
                    riderSearchResults.innerHTML = `
                        <div class="rider-search-empty">
                            <i data-lucide="search-x"></i>
                            <p>Inga deltagare hittades</p>
                            <p style="font-size: 0.875rem; margin-top: var(--space-xs); color: var(--color-text-muted);">Använd "Skapa ny deltagare" ovan för att skapa en ny profil.</p>
                        </div>
                    `;
                    if (typeof lucide !== 'undefined') lucide.createIcons();
                }

                function selectRider(rider) {
                    selectedRider = rider;
                    selectedRiderId = rider.id;

                    // Update display
                    selectedRiderName.textContent = `${rider.firstname} ${rider.lastname}`;
                    const infoItems = [];
                    if (rider.birth_year) infoItems.push(`Född ${rider.birth_year}`);
                    if (rider.club_name) infoItems.push(rider.club_name);
                    if (rider.license_number) infoItems.push(`UCI: ${rider.license_number}`);
                    selectedRiderInfo.textContent = infoItems.join(' • ');

                    // Show/hide elements
                    closeSearchModal();
                    openRiderSearchBtn.style.display = 'none';
                    selectedRiderDisplay.style.display = 'block';

                    // Show loading state for license validation (result comes with loadClasses)
                    showLicenseLoading('licenseValidationResult');

                    // Load classes for this rider (includes license validation from SCF)
                    loadClasses(rider.id);
                }

                // Show loading spinner in license validation result div
                function showLicenseLoading(divId) {
                    const resultDiv = document.getElementById(divId);
                    if (!resultDiv) return;
                    resultDiv.style.display = 'block';
                    resultDiv.style.background = 'var(--color-bg-hover)';
                    resultDiv.style.color = 'var(--color-text-secondary)';
                    resultDiv.innerHTML = '<div style="display: flex; align-items: center; gap: var(--space-xs);"><i data-lucide="loader" style="width: 16px; height: 16px; animation: spin 1s linear infinite;"></i> Kontrollerar licens mot SCF...</div>';
                    if (typeof lucide !== 'undefined') lucide.createIcons();
                }

                // Display license validation result from event_classes API response
                function showLicenseValidation(data, divId, riderInfoEl, riderObj) {
                    const resultDiv = document.getElementById(divId);
                    if (!resultDiv || !data) {
                        if (resultDiv) resultDiv.style.display = 'none';
                        return;
                    }

                    const status = data.status;
                    let icon, color, bg, text;

                    if (status === 'valid') {
                        icon = 'check-circle';
                        color = 'var(--color-success)';
                        bg = 'rgba(16, 185, 129, 0.1)';
                        text = data.message || 'Giltig licens';
                        if (data.license_type) text += ` (${data.license_type})`;
                        if (data.club_name) text += ` - ${data.club_name}`;
                    } else if (status === 'expired') {
                        icon = 'alert-triangle';
                        color = 'var(--color-warning)';
                        bg = 'rgba(251, 191, 36, 0.1)';
                        text = data.message || 'Licensen har gått ut';
                        if (data.license_year) text += ` (senast giltig: ${data.license_year})`;
                    } else if (status === 'not_found') {
                        icon = 'x-circle';
                        color = 'var(--color-error)';
                        bg = 'rgba(239, 68, 68, 0.1)';
                        text = data.message || 'Ingen licens hittades i SCFs register';
                    } else {
                        icon = 'minus-circle';
                        color = 'var(--color-text-muted)';
                        bg = 'var(--color-bg-hover)';
                        text = data.message || 'Ingen licens registrerad';
                    }

                    resultDiv.style.display = 'block';
                    resultDiv.style.background = bg;
                    resultDiv.style.color = color;

                    let html = `<div style="display: flex; align-items: center; gap: var(--space-xs);"><i data-lucide="${icon}" style="width: 16px; height: 16px; flex-shrink: 0;"></i> ${text}</div>`;

                    // Show UCI ID update notice
                    if (data.uci_id_updated && data.uci_id) {
                        html += `<div style="margin-top: var(--space-2xs); font-size: 0.8125rem; color: var(--color-accent);">
                            <i data-lucide="refresh-cw" style="width: 14px; height: 14px; vertical-align: -2px;"></i>
                            UCI ID uppdaterat till: ${data.uci_id}
                        </div>`;
                        // Update the rider info display
                        if (riderObj && riderInfoEl) {
                            riderObj.license_number = data.uci_id;
                            const infoItems = [];
                            if (riderObj.birth_year) infoItems.push('Född ' + riderObj.birth_year);
                            if (data.club_name) infoItems.push(data.club_name);
                            else if (riderObj.club_name) infoItems.push(riderObj.club_name);
                            infoItems.push('UCI: ' + data.uci_id);
                            riderInfoEl.textContent = infoItems.join(' \u2022 ');
                        }
                    }

                    resultDiv.innerHTML = html;
                    if (typeof lucide !== 'undefined') lucide.createIcons();
                }

                function clearRiderSelection() {
                    selectedRider = null;
                    selectedRiderId = null;
                    riderSearch.value = '';
                    riderSearchResults.innerHTML = '';
                    openRiderSearchBtn.style.display = 'block';
                    selectedRiderDisplay.style.display = 'none';
                    classSelection.style.display = 'none';
                    document.getElementById('licenseCommitment').style.display = 'none';
                    document.getElementById('licenseValidationResult').style.display = 'none';
                    selectedClassId = null;
                    requiresLicenseCommitment = false;
                    licenseCommitmentAccepted = false;
                    addToCartBtn.disabled = true;
                }

                // Modal functions - move to body to avoid overflow:hidden clipping from .card
                function openSearchModal() {
                    document.body.appendChild(riderSearchModal);
                    riderSearchModal.style.display = 'flex';
                    document.body.style.overflow = 'hidden';
                    setTimeout(() => riderSearch.focus(), 100);
                }

                function closeSearchModal() {
                    riderSearchModal.style.display = 'none';
                    document.body.style.overflow = '';
                    riderSearch.value = '';
                    riderSearchResults.innerHTML = '';
                    // Reset to search view
                    document.getElementById('riderSearchSection').style.display = 'flex';
                    document.getElementById('riderCreateSection').style.display = 'none';
                    const h3 = riderSearchModal.querySelector('.rider-search-modal__header h3');
                    if (h3) h3.textContent = 'Sök deltagare';
                }

                // Load classes for selected rider
                async function loadClasses(riderId) {
                    classList.innerHTML = '<p class="text-muted">Laddar klasser...</p>';
                    classSelection.style.display = 'block';

                    try {
                        const response = await fetch(`/api/orders.php?action=event_classes&event_id=${eventId}&rider_id=${riderId}`);
                        const data = await response.json();

                        if (data.success) {
                            // Show license validation result (piggybacked on event_classes response)
                            if (data.license_validation) {
                                showLicenseValidation(data.license_validation, 'licenseValidationResult', selectedRiderInfo, selectedRider);
                            } else {
                                document.getElementById('licenseValidationResult').style.display = 'none';
                            }

                            // Save license commitment requirement
                            requiresLicenseCommitment = data.requires_license_commitment || false;
                            licenseCommitmentAccepted = false;

                            if (data.classes && data.classes.length > 0) {
                                // Check if it's an incomplete profile error
                                if (data.classes[0].error === 'incomplete_profile') {
                                    classList.innerHTML = `
                                        <div class="alert alert--warning" style="text-align: left;">
                                            <div style="display: flex; gap: var(--space-sm); align-items: flex-start;">
                                                <i data-lucide="alert-triangle" style="flex-shrink: 0; margin-top: 2px;"></i>
                                                <div>
                                                    <strong style="display: block; margin-bottom: var(--space-xs);">Profilen är inte komplett</strong>
                                                    <p style="margin: 0;">${data.classes[0].message}</p>
                                                    <a href="/profile/edit" class="btn btn-primary" style="margin-top: var(--space-sm); display: inline-flex; align-items: center; gap: var(--space-xs);">
                                                        <i data-lucide="user-pen" style="width:16px;height:16px;"></i> Uppdatera profil
                                                    </a>
                                                </div>
                                            </div>
                                        </div>
                                    `;
                                    if (typeof lucide !== 'undefined') lucide.createIcons();
                                } else if (data.classes[0].error === 'no_eligible_classes') {
                                    // Show debug info about why classes were filtered
                                    const debug = data.classes[0].debug || {};
                                    const reasons = (debug.ineligible_classes || []).map(c =>
                                        `<li><strong>${c.name}:</strong> ${c.reason}</li>`
                                    ).join('');

                                    classList.innerHTML = `
                                        <div class="alert alert--info" style="text-align: left;">
                                            <div style="display: flex; gap: var(--space-sm); align-items: flex-start;">
                                                <i data-lucide="info" style="flex-shrink: 0; margin-top: 2px;"></i>
                                                <div>
                                                    <strong style="display: block; margin-bottom: var(--space-xs);">Inga matchande klasser</strong>
                                                    <p style="margin: 0 0 var(--space-sm) 0;">Deltagaren matchade inte kriterierna för någon klass:</p>
                                                    <div style="font-size: 0.875rem; color: var(--color-text-secondary);">
                                                        <p>Ålder: ${debug.rider_age} år | Kön: ${debug.rider_gender}</p>
                                                    </div>
                                                    <ul style="margin: var(--space-xs) 0 0 0; padding-left: var(--space-lg); font-size: 0.875rem;">
                                                        ${reasons}
                                                    </ul>
                                                </div>
                                            </div>
                                        </div>
                                    `;
                                    if (typeof lucide !== 'undefined') lucide.createIcons();
                                } else if (data.classes[0].error === 'no_classes_configured') {
                                    // Event has no classes configured
                                    const debug = data.classes[0].debug || {};
                                    classList.innerHTML = `
                                        <div class="alert alert--warning" style="text-align: left;">
                                            <div style="display: flex; gap: var(--space-sm); align-items: flex-start;">
                                                <i data-lucide="alert-triangle" style="flex-shrink: 0; margin-top: 2px;"></i>
                                                <div>
                                                    <strong style="display: block; margin-bottom: var(--space-xs);">Eventet saknar klasser</strong>
                                                    <p style="margin: 0;">Detta event har ingen prismall eller inga klasser konfigurerade.</p>
                                                    <p style="margin: var(--space-sm) 0 0 0; font-size: 0.875rem; color: var(--color-text-secondary);">
                                                        Prismall ID: ${debug.pricing_template_id || 'Saknas'}
                                                    </p>
                                                </div>
                                            </div>
                                        </div>
                                    `;
                                    if (typeof lucide !== 'undefined') lucide.createIcons();
                                } else {
                                    renderClasses(data.classes);
                                    showLicenseCommitmentIfNeeded();
                                }
                            } else {
                                classList.innerHTML = '<p class="text-muted">Inga tillgängliga klasser för denna deltagare</p>';
                            }
                        } else {
                            console.error('API error:', data);
                            const errorMsg = data.error || 'Kunde inte ladda klasser';
                            classList.innerHTML = `<p class="text-error">${errorMsg}</p>`;
                        }
                    } catch (e) {
                        console.error('Failed to load classes:', e);
                        classList.innerHTML = '<p class="text-error">Ett fel uppstod vid laddning av klasser</p>';
                    }
                }

                function renderClasses(classes) {
                    classList.innerHTML = '';

                    if (classes.length === 0) {
                        classList.innerHTML = '<p class="text-muted">Inga tillgängliga klasser hittades</p>';
                        return;
                    }

                    classes.forEach(cls => {
                        const div = document.createElement('label');
                        div.className = 'reg-class-item';
                        div.dataset.classId = cls.class_id;
                        div.dataset.price = cls.current_price;
                        const className = cls.name || 'Klass ' + cls.class_id;
                        div.dataset.name = className;

                        // Visa varning om ingen licens
                        let warningHtml = '';
                        if (cls.warning) {
                            warningHtml = `<div class="reg-class-desc" style="color: var(--color-warning);"><i data-lucide="alert-triangle" style="width: 14px; height: 14px; display: inline; vertical-align: text-bottom;"></i> ${cls.warning}</div>`;
                        }

                        div.innerHTML = `
                            <input type="radio" name="class_select" value="${cls.class_id}"
                                   class="reg-class-radio"
                                   data-price="${cls.current_price}" data-name="${className}">
                            <div class="reg-class-info">
                                <div class="reg-class-name">${className}</div>
                                ${warningHtml}
                            </div>
                            <div class="reg-class-price">
                                <div class="reg-class-price__current">${cls.current_price.toLocaleString('sv-SE')} kr</div>
                                ${cls.price_type === 'early_bird' ? `<div class="reg-class-price__original">${cls.base_price.toLocaleString('sv-SE')} kr</div>` : ''}
                            </div>
                        `;

                        div.addEventListener('click', function() { selectClass(cls, this); });
                        classList.appendChild(div);
                    });

                    // Refresh icons
                    if (typeof lucide !== 'undefined') lucide.createIcons();
                }

                function showLicenseCommitmentIfNeeded() {
                    const licenseCommitmentDiv = document.getElementById('licenseCommitment');
                    const licenseCommitmentCheckbox = document.getElementById('licenseCommitmentCheckbox');

                    if (requiresLicenseCommitment) {
                        licenseCommitmentDiv.style.display = 'block';
                        // Reset checkbox state
                        licenseCommitmentCheckbox.checked = false;
                        licenseCommitmentAccepted = false;
                        // Update button state
                        updateAddToCartButton();
                    } else {
                        licenseCommitmentDiv.style.display = 'none';
                    }
                }

                function updateAddToCartButton() {
                    // Enable button only if:
                    // 1. A class is selected
                    // 2. If license commitment required, it must be accepted
                    const canAdd = selectedClassId && (!requiresLicenseCommitment || licenseCommitmentAccepted);
                    addToCartBtn.disabled = !canAdd;
                }

                function selectClass(cls, element) {
                    // Update visual selection
                    document.querySelectorAll('.reg-class-item').forEach(item => {
                        item.classList.remove('selected');
                    });
                    element.classList.add('selected');

                    selectedClassId = cls.class_id;
                    selectedClassData = cls;

                    // Show license commitment checkbox if needed
                    showLicenseCommitmentIfNeeded();

                    updateAddToCartButton();
                }

                function addToCart() {
                    if (!selectedRiderId || !selectedClassId || !selectedRider) return;

                    const newItem = {
                        type: 'event',
                        rider_id: selectedRider.id,
                        rider_name: selectedRider.firstname + ' ' + selectedRider.lastname,
                        club_name: selectedRider.club_name || '-',
                        event_id: eventId,
                        event_name: '<?= addslashes($event['name']) ?>',
                        event_date: '<?= $event['date'] ?>',
                        class_id: selectedClassId,
                        class_name: selectedClassData.name,
                        price: selectedClassData.current_price
                    };

                    // Check for duplicate in pending items
                    const isDuplicate = pendingItems.some(item =>
                        item.event_id == newItem.event_id &&
                        item.rider_id == newItem.rider_id &&
                        item.class_id == newItem.class_id
                    );
                    if (!isDuplicate) {
                        pendingItems.push(newItem);
                    }

                    // Reset form
                    clearRiderSelection();
                    selectedClassId = null;
                    selectedClassData = null;

                    updateCart();
                }

                function removeFromCart(eid, riderId, classId) {
                    // Try to remove from pending items first
                    const pendingIdx = pendingItems.findIndex(item =>
                        item.event_id == eid && item.rider_id == riderId && item.class_id == classId
                    );
                    if (pendingIdx !== -1) {
                        pendingItems.splice(pendingIdx, 1);
                    } else {
                        // Remove from committed GlobalCart
                        GlobalCart.removeItem(eid, riderId, classId);
                    }
                    updateCart();
                }

                function updateCart() {
                    // Combine committed GlobalCart items with pending (not yet committed) items
                    const committedItems = (typeof GlobalCart !== 'undefined') ? GlobalCart.getCart() : [];
                    const allItems = [...committedItems, ...pendingItems];

                    // Show items for this event AND any series registration items linked to this event's series
                    const thisEventItems = allItems.filter(item => item.event_id == eventId);
                    const seriesItems = allItems.filter(item => item.is_series_registration && item.event_id != eventId);

                    // Check if current event has series items too
                    const hasSeriesItems = thisEventItems.some(item => item.is_series_registration) || seriesItems.length > 0;
                    const relevantItems = hasSeriesItems ? allItems.filter(item => item.is_series_registration || item.event_id == eventId) : thisEventItems;

                    if (relevantItems.length === 0) {
                        registrationCart.style.display = 'none';
                        addAnotherBtn.style.display = 'none';
                        return;
                    }

                    registrationCart.style.display = 'block';
                    addAnotherBtn.style.display = 'inline-flex';

                    // Group items by event for display
                    const grouped = {};
                    relevantItems.forEach(item => {
                        const eid = item.event_id;
                        if (!grouped[eid]) {
                            grouped[eid] = { name: item.event_name, date: item.event_date || '', items: [] };
                        }
                        grouped[eid].items.push(item);
                    });

                    // Sort events by date (current event first, then by date)
                    const sortedEventIds = Object.keys(grouped).sort((a, b) => {
                        if (a == eventId) return -1;
                        if (b == eventId) return 1;
                        return (grouped[a].date || '').localeCompare(grouped[b].date || '');
                    });

                    const multiEvent = sortedEventIds.length > 1;
                    let html = '';

                    sortedEventIds.forEach(eid => {
                        const group = grouped[eid];
                        if (multiEvent) {
                            const dateStr = group.date ? `<span class="text-muted" style="font-size: var(--text-xs);">${group.date}</span>` : '';
                            html += `<div style="padding: var(--space-xs) 0; display: flex; align-items: center; gap: var(--space-xs);">
                                <i data-lucide="calendar" style="width: 14px; height: 14px; color: var(--color-text-muted);"></i>
                                <strong style="font-size: var(--text-sm);">${group.name}</strong>
                                ${dateStr}
                            </div>`;
                        }
                        group.items.forEach(item => {
                            const itemKey = `${item.event_id}_${item.rider_id}_${item.class_id}`;
                            html += `<div class="reg-cart__item">
                                <div class="reg-cart__item-info">
                                    <strong>${item.rider_name}</strong>
                                    <span class="text-muted">${item.class_name}</span>
                                    ${item.is_series_registration ? `<span class="reg-cart__item-discount text-xs text-success" data-item-key="${itemKey}" style="display:none;"></span>` : ''}
                                </div>
                                <div class="reg-cart__item-price">${item.price.toLocaleString('sv-SE')} kr</div>
                                <button type="button" class="reg-cart__item-remove"
                                        onclick="window.removeCartItem(${item.event_id}, ${item.rider_id}, ${item.class_id})">
                                    <i data-lucide="x"></i>
                                </button>
                            </div>`;
                        });
                    });

                    cartItems.innerHTML = html;

                    // Calculate series discount
                    const seriesGroups = {};
                    relevantItems.forEach(item => {
                        if (item.is_series_registration && item.season_price > 0) {
                            const key = `${item.rider_id}_${item.class_id}_${item.series_id || 0}`;
                            if (!seriesGroups[key]) {
                                seriesGroups[key] = { items: [], season_price: item.season_price };
                            }
                            seriesGroups[key].items.push(item);
                        }
                    });
                    let seriesDiscount = 0;
                    const perItemDisc = {};
                    Object.values(seriesGroups).forEach(group => {
                        const regularTotal = group.items.reduce((sum, item) => sum + (item.price || 0), 0);
                        if (regularTotal > group.season_price) {
                            const disc = regularTotal - group.season_price;
                            seriesDiscount += disc;
                            const perItem = Math.round(disc / group.items.length);
                            group.items.forEach(item => {
                                perItemDisc[`${item.event_id}_${item.rider_id}_${item.class_id}`] = perItem;
                            });
                        }
                    });

                    // Update per-item discount indicators
                    document.querySelectorAll('.reg-cart__item-discount').forEach(el => {
                        const disc = perItemDisc[el.dataset.itemKey] || 0;
                        if (disc > 0) {
                            el.textContent = 'Serierabatt: -' + disc + ' kr';
                            el.style.display = 'block';
                        }
                    });

                    // Update totals - show all relevant items
                    const total = relevantItems.reduce((sum, item) => sum + item.price, 0);
                    const riderCount = new Set(relevantItems.map(i => i.rider_id)).size;
                    cartCount.textContent = multiEvent ? `${riderCount} (${sortedEventIds.length} event)` : relevantItems.length;

                    // Show series discount and final total
                    if (seriesDiscount > 0) {
                        cartTotal.innerHTML = `<span style="text-decoration: line-through; color: var(--color-text-muted); font-size: var(--text-sm);">${total.toLocaleString('sv-SE')} kr</span><br>` +
                            `<span style="color: var(--color-success); font-size: var(--text-xs);"><i data-lucide="tag" style="width:12px;height:12px;display:inline;"></i> -${seriesDiscount} kr</span><br>` +
                            `${(total - seriesDiscount).toLocaleString('sv-SE')} kr`;
                    } else {
                        cartTotal.textContent = total.toLocaleString('sv-SE') + ' kr';
                    }

                    // VAT info
                    const finalTotal = total - seriesDiscount;
                    const vat = Math.round(finalTotal * 6 / 106);
                    let vatEl = document.getElementById('cartVat');
                    if (!vatEl) {
                        vatEl = document.createElement('div');
                        vatEl.id = 'cartVat';
                        vatEl.className = 'text-xs text-muted';
                        vatEl.style.textAlign = 'right';
                        vatEl.style.marginTop = '2px';
                        cartTotal.parentElement.appendChild(vatEl);
                    }
                    vatEl.textContent = 'varav moms (6%): ' + vat + ' kr';

                    if (typeof lucide !== 'undefined') lucide.createIcons();
                }

                // Global function for remove button
                window.removeCartItem = removeFromCart;

                async function checkout() {
                    // Commit any pending items to GlobalCart first
                    pendingItems.forEach(item => {
                        GlobalCart.addItem(item);
                    });
                    pendingItems = [];

                    // Get cart from GlobalCart
                    const cart = GlobalCart.getCart();
                    if (cart.length === 0) return;

                    checkoutBtn.disabled = true;
                    checkoutBtn.innerHTML = '<i data-lucide="loader-2" class="spin"></i> Bearbetar...';
                    if (typeof lucide !== 'undefined') lucide.createIcons();

                    try {
                        // Buyer data - använd inloggad användares data om tillgänglig
                        const buyerData = {};
                        <?php if ($isLoggedIn && $currentUser): ?>
                        buyerData.name = '<?= h(($currentUser['firstname'] ?? '') . ' ' . ($currentUser['lastname'] ?? '')) ?>';
                        buyerData.email = '<?= h($currentUser['email'] ?? '') ?>';
                        <?php endif; ?>

                        const response = await fetch('/api/orders.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({
                                action: 'create',
                                buyer: buyerData,
                                items: cart
                            })
                        });

                        const data = await response.json();

                        if (data.success) {
                            // DON'T clear cart yet - only after successful payment
                            // Cart will be cleared in checkout.php after payment completes
                            window.location.href = data.order.checkout_url;
                        } else {
                            alert(data.error || 'Ett fel uppstod');
                            checkoutBtn.disabled = false;
                            checkoutBtn.innerHTML = '<i data-lucide="shopping-cart"></i> Gå till varukorgen';
                            if (typeof lucide !== 'undefined') lucide.createIcons();
                        }
                    } catch (error) {
                        console.error('Checkout error:', error);
                        alert('Ett fel uppstod. Försök igen.');
                        checkoutBtn.disabled = false;
                        checkoutBtn.innerHTML = '<i data-lucide="shopping-cart"></i> Gå till varukorgen';
                        if (typeof lucide !== 'undefined') lucide.createIcons();
                    }
                }

                // Event listeners

                // Modal controls
                openRiderSearchBtn.addEventListener('click', openSearchModal);
                closeRiderSearchBtn.addEventListener('click', closeSearchModal);

                // Fixed "Create new rider" link
                document.getElementById('showCreateRiderFormBtn').addEventListener('click', function() {
                    showCreateRiderForm('');
                });

                // Close modal on backdrop click
                riderSearchModal.addEventListener('click', function(e) {
                    if (e.target === riderSearchModal || e.target.classList.contains('rider-search-modal__backdrop')) {
                        closeSearchModal();
                    }
                });

                // Rider search with debounce
                riderSearch.addEventListener('input', function() {
                    clearTimeout(searchTimeout);
                    const query = this.value.trim();

                    if (query.length < 2) {
                        riderSearchResults.innerHTML = '';
                        return;
                    }

                    searchTimeout = setTimeout(() => {
                        searchRiders(query);
                    }, 300);
                });

                // Clear selected rider
                clearSelectedRider.addEventListener('click', clearRiderSelection);

                // License commitment checkbox
                document.getElementById('licenseCommitmentCheckbox').addEventListener('change', function() {
                    licenseCommitmentAccepted = this.checked;
                    updateAddToCartButton();
                });

                addToCartBtn.addEventListener('click', addToCart);
                checkoutBtn.addEventListener('click', function() {
                    // Commit pending items to GlobalCart before navigating
                    pendingItems.forEach(item => {
                        GlobalCart.addItem(item);
                    });
                    pendingItems = [];

                    clearRiderSelection();
                    closeSearchModal();
                    window.location.href = '/cart';
                });
                addAnotherBtn.addEventListener('click', function() {
                    document.getElementById('addRiderSection').scrollIntoView({ behavior: 'smooth' });
                });

                // Listen for cart updates from GlobalCart
                window.addEventListener('cartUpdated', updateCart);

                // Confirmed rider IDs for this event (already registered & paid)
                const confirmedRiders = <?= json_encode(array_values(array_unique(array_map(function($r) { return (int)$r['rider_id']; }, $registrations ?? [])))) ?>;

                // Initial cart render on page load - wait for GlobalCart (loaded in footer)
                function waitForGlobalCart() {
                    if (typeof GlobalCart !== 'undefined') {
                        // Clean up stale cart items for riders already confirmed for this event
                        if (confirmedRiders.length > 0) {
                            const cart = GlobalCart.getCart();
                            // Collect items to remove (don't modify while iterating)
                            const toRemove = [];
                            cart.forEach(item => {
                                if (confirmedRiders.includes(parseInt(item.rider_id))) {
                                    // Remove items for this event
                                    if (item.event_id == eventId) {
                                        toRemove.push(item);
                                    }
                                    // Also remove series items for other events (rider paid for whole series)
                                    else if (item.is_series_registration) {
                                        toRemove.push(item);
                                    }
                                }
                            });
                            toRemove.forEach(item => {
                                GlobalCart.removeItem(item.event_id, item.rider_id, item.class_id);
                            });
                        }
                        updateCart();
                    } else {
                        setTimeout(waitForGlobalCart, 50);
                    }
                }
                waitForGlobalCart();

                // Clear form when navigating back (browser bfcache)
                window.addEventListener('pageshow', function(e) {
                    if (e.persisted) {
                        clearRiderSelection();
                        closeSearchModal();
                    }
                });

                // Expose shared functions for series registration IIFE
                window._showLicenseLoading = showLicenseLoading;
                window._showLicenseValidation = showLicenseValidation;
                window._showCreateRiderForm = showCreateRiderForm;
                window._handleCreateRider = handleCreateRider;
                window._getCreateRiderFormHtml = getCreateRiderFormHtml;
            })();

            <?php if ($seriesRegistrationAvailable && $seriesInfo): ?>
            // SERIES REGISTRATION JavaScript
            (function() {
                const seriesEvents = <?= json_encode(array_map(function($e) {
                    return ['id' => $e['id'], 'name' => $e['name'], 'date' => $e['date'] ?? ''];
                }, $seriesEventsWithPricing)) ?>;

                const currentUserId = <?= $currentUser['id'] ?? 0 ?>;

                let seriesSelectedRiderId = null;
                let seriesSelectedClassId = null;
                let seriesSelectedClassData = null;
                let seriesRequiresLicenseCommitment = false;
                let seriesLicenseCommitmentAccepted = false;
                let seriesSelectedRider = null;

                const seriesRiderSearchModal = document.getElementById('seriesRiderSearchModal');
                const seriesOpenRiderSearchBtn = document.getElementById('seriesOpenRiderSearchBtn');
                const seriesCloseRiderSearchBtn = document.getElementById('seriesCloseRiderSearchBtn');
                const seriesRiderSearch = document.getElementById('seriesRiderSearch');
                const seriesRiderSearchResults = document.getElementById('seriesRiderSearchResults');
                const seriesSelectedRiderDisplay = document.getElementById('seriesSelectedRiderDisplay');
                const seriesSelectedRiderName = document.getElementById('seriesSelectedRiderName');
                const seriesSelectedRiderInfo = document.getElementById('seriesSelectedRiderInfo');
                const seriesClearSelectedRider = document.getElementById('seriesClearSelectedRider');
                const seriesClassSelection = document.getElementById('seriesClassSelection');
                const seriesClassList = document.getElementById('seriesClassList');
                const seriesAddToCartBtn = document.getElementById('seriesAddToCartBtn');
                const seriesLicenseCommitment = document.getElementById('seriesLicenseCommitment');
                const seriesLicenseCommitmentCheckbox = document.getElementById('seriesLicenseCommitmentCheckbox');

                let seriesSearchTimeout = null;

                // Search riders
                async function seriesSearchRiders(query) {
                    if (query.length < 2) {
                        seriesRiderSearchResults.style.display = 'none';
                        return;
                    }

                    try {
                        const response = await fetch(`/api/orders.php?action=search_riders&q=${encodeURIComponent(query)}`);
                        const data = await response.json();

                        if (data.success && data.riders.length > 0) {
                            seriesRenderSearchResults(data.riders);
                        } else {
                            seriesRenderEmptyResults();
                        }
                    } catch (e) {
                        console.error('Search failed:', e);
                        seriesRiderSearchResults.style.display = 'none';
                    }
                }

                function seriesRenderSearchResults(riders) {
                    seriesRiderSearchResults.style.display = '';
                    let html = riders.map(rider => {
                        const infoItems = [];
                        if (rider.birth_year) infoItems.push(rider.birth_year);
                        if (rider.club_name) infoItems.push(rider.club_name);
                        if (rider.license_number) infoItems.push(`<span class="rider-search-result__uci">UCI: ${rider.license_number}</span>`);

                        const licenseIndicator = rider.has_valid_license && rider.license_display_year
                            ? `<span style="display: inline-flex; align-items: center; gap: 4px; color: var(--color-success); font-weight: 500; font-size: 0.875rem;">
                                 <i data-lucide="check-circle" style="width: 16px; height: 16px;"></i> Licens ${rider.license_display_year}
                               </span>`
                            : '';

                        return `
                            <div class="rider-search-result" data-rider-id="${rider.id}">
                                <div class="rider-search-result__name">
                                    ${rider.firstname} ${rider.lastname}
                                    ${licenseIndicator}
                                </div>
                                <div class="rider-search-result__info">${infoItems.join(' • ')}</div>
                            </div>
                        `;
                    }).join('');

                    seriesRiderSearchResults.innerHTML = html;
                    if (typeof lucide !== 'undefined') lucide.createIcons();

                    seriesRiderSearchResults.querySelectorAll('.rider-search-result').forEach(el => {
                        el.addEventListener('click', () => {
                            const riderId = el.dataset.riderId;
                            const rider = riders.find(r => r.id == riderId);
                            seriesSelectRider(rider);
                        });
                    });
                }

                function seriesRenderEmptyResults() {
                    seriesRiderSearchResults.style.display = '';
                    seriesRiderSearchResults.innerHTML = `
                        <div class="rider-search-empty">
                            <i data-lucide="search-x"></i>
                            <p>Inga deltagare hittades</p>
                            <p style="font-size: 0.875rem; margin-top: var(--space-xs); color: var(--color-text-muted);">Använd "Skapa ny deltagare" ovan för att skapa en ny profil.</p>
                        </div>
                    `;
                    if (typeof lucide !== 'undefined') lucide.createIcons();
                }

                function seriesSelectRider(rider) {
                    seriesSelectedRider = rider;
                    seriesSelectedRiderId = rider.id;

                    seriesRiderSearchModal.style.display = 'none';
                    seriesOpenRiderSearchBtn.style.display = 'none';
                    seriesSelectedRiderDisplay.style.display = 'block';
                    seriesSelectedRiderName.textContent = `${rider.firstname} ${rider.lastname}`;
                    seriesSelectedRiderInfo.textContent = `${rider.birth_year || ''} ${rider.club_name ? '• ' + rider.club_name : ''}`;

                    // Show loading state for license validation
                    window._showLicenseLoading('seriesLicenseValidationResult');

                    seriesLoadEligibleClasses(rider.id);
                }

                async function seriesLoadEligibleClasses(riderId) {
                    seriesClassList.innerHTML = '<p class="text-muted">Laddar klasser...</p>';
                    seriesClassSelection.style.display = 'block';

                    try {
                        const response = await fetch(`/api/orders.php?action=event_classes&event_id=${seriesEvents[0].id}&rider_id=${riderId}`);
                        const data = await response.json();

                        if (data.success) {
                            // Show license validation result (piggybacked on event_classes response)
                            if (data.license_validation) {
                                window._showLicenseValidation(data.license_validation, 'seriesLicenseValidationResult', seriesSelectedRiderInfo, seriesSelectedRider);
                            } else {
                                document.getElementById('seriesLicenseValidationResult').style.display = 'none';
                            }

                            if (data.classes && data.classes.length > 0) {
                                if (data.classes[0].error === 'incomplete_profile') {
                                    seriesClassList.innerHTML = `
                                        <div class="alert alert--warning" style="text-align: left;">
                                            <div style="display: flex; gap: var(--space-sm); align-items: flex-start;">
                                                <i data-lucide="alert-triangle" style="flex-shrink: 0; margin-top: 2px;"></i>
                                                <div>
                                                    <strong style="display: block; margin-bottom: var(--space-xs);">Profilen är inte komplett</strong>
                                                    <p style="margin: 0;">${data.classes[0].message}</p>
                                                    <a href="/profile/edit" class="btn btn-primary" style="margin-top: var(--space-sm); display: inline-flex; align-items: center; gap: var(--space-xs);">
                                                        <i data-lucide="user-pen" style="width:16px;height:16px;"></i> Uppdatera profil
                                                    </a>
                                                </div>
                                            </div>
                                        </div>
                                    `;
                                    if (typeof lucide !== 'undefined') lucide.createIcons();
                                } else if (data.classes[0].error === 'no_eligible_classes') {
                                    const debug = data.classes[0].debug || {};
                                    const reasons = (debug.ineligible_classes || []).map(c =>
                                        `<li><strong>${c.name || 'Okänd'}:</strong> ${c.reason}</li>`
                                    ).join('');
                                    seriesClassList.innerHTML = `
                                        <div class="alert alert--info" style="text-align: left;">
                                            <div style="display: flex; gap: var(--space-sm); align-items: flex-start;">
                                                <i data-lucide="info" style="flex-shrink: 0; margin-top: 2px;"></i>
                                                <div>
                                                    <strong style="display: block; margin-bottom: var(--space-xs);">Inga matchande klasser</strong>
                                                    <p style="margin: 0 0 var(--space-sm) 0;">Deltagaren matchade inte kriterierna för någon klass:</p>
                                                    <div style="font-size: 0.875rem; color: var(--color-text-secondary);">
                                                        <p>Ålder: ${debug.rider_age} år | Kön: ${debug.rider_gender}</p>
                                                    </div>
                                                    <ul style="margin: var(--space-xs) 0 0 0; padding-left: var(--space-lg); font-size: 0.875rem;">
                                                        ${reasons}
                                                    </ul>
                                                </div>
                                            </div>
                                        </div>
                                    `;
                                    if (typeof lucide !== 'undefined') lucide.createIcons();
                                } else if (data.classes[0].error === 'no_classes_configured') {
                                    seriesClassList.innerHTML = `
                                        <div class="alert alert--warning" style="text-align: left;">
                                            <div style="display: flex; gap: var(--space-sm); align-items: flex-start;">
                                                <i data-lucide="alert-triangle" style="flex-shrink: 0; margin-top: 2px;"></i>
                                                <div>
                                                    <strong style="display: block; margin-bottom: var(--space-xs);">Eventet saknar klasser</strong>
                                                    <p style="margin: 0;">Detta event har ingen prismall eller inga klasser konfigurerade.</p>
                                                </div>
                                            </div>
                                        </div>
                                    `;
                                    if (typeof lucide !== 'undefined') lucide.createIcons();
                                } else {
                                    seriesRenderClasses(data.classes, data.requires_license_commitment);
                                }
                            } else {
                                seriesClassList.innerHTML = '<p class="text-muted">Inga tillgängliga klasser för denna deltagare</p>';
                            }
                        } else {
                            const errorMsg = data.error || 'Kunde inte ladda klasser';
                            seriesClassList.innerHTML = `<p class="text-error">${errorMsg}</p>`;
                        }
                    } catch (e) {
                        console.error('Failed to load classes:', e);
                        seriesClassList.innerHTML = '<p class="text-error">Ett fel uppstod vid laddning av klasser</p>';
                    }
                }

                function seriesRenderClasses(classes, requiresLicense) {
                    seriesRequiresLicenseCommitment = requiresLicense;
                    const numEvents = seriesEvents.length;

                    seriesClassList.innerHTML = classes.map(cls => {
                        const className = cls.name || 'Klass ' + cls.class_id;
                        const pricePerEvent = cls.current_price;
                        const seasonTotal = cls.season_price && cls.season_price > 0 ? cls.season_price : 0;
                        const regularTotal = pricePerEvent * numEvents;
                        const hasSaving = seasonTotal > 0 && seasonTotal < regularTotal;
                        const saving = hasSaving ? (regularTotal - seasonTotal) : 0;

                        let priceDisplay = pricePerEvent ? `${pricePerEvent} kr / event` : 'Pris saknas';
                        let savingHtml = '';
                        if (hasSaving) {
                            savingHtml = `<div class="reg-class-desc" style="color: var(--color-success);">Seriepris: ${seasonTotal} kr totalt (spara ${saving} kr)</div>`;
                        }
                        return `
                            <div class="reg-class-item" data-class-id="${cls.class_id}" data-season-price="${seasonTotal}" data-saving="${saving}">
                                <input type="radio" name="series_class" value="${cls.class_id}" class="reg-class-radio">
                                <div class="reg-class-info">
                                    <div class="reg-class-name">${className}</div>
                                    ${savingHtml}
                                    ${cls.warning ? `<div class="reg-class-desc" style="color: var(--color-warning);">${cls.warning}</div>` : ''}
                                </div>
                                <div class="reg-class-price">
                                    <div class="reg-class-price__current">${priceDisplay}</div>
                                </div>
                            </div>
                        `;
                    }).join('');

                    seriesClassList.querySelectorAll('.reg-class-item').forEach(el => {
                        const radio = el.querySelector('input[type="radio"]');
                        el.addEventListener('click', () => {
                            radio.checked = true;
                            seriesClassList.querySelectorAll('.reg-class-item').forEach(item => item.classList.remove('selected'));
                            el.classList.add('selected');

                            const classId = parseInt(el.dataset.classId);
                            seriesSelectedClassId = classId;
                            seriesSelectedClassData = classes.find(c => c.class_id === classId);

                            if (seriesRequiresLicenseCommitment) {
                                seriesLicenseCommitment.style.display = 'block';
                                seriesUpdateAddButton();
                            } else {
                                seriesLicenseCommitment.style.display = 'none';
                                seriesLicenseCommitmentAccepted = false;
                                seriesAddToCartBtn.disabled = false;
                            }
                        });
                    });
                }

                function seriesUpdateAddButton() {
                    if (seriesRequiresLicenseCommitment) {
                        seriesAddToCartBtn.disabled = !seriesLicenseCommitmentAccepted;
                    }
                }

                // Open modal - move to body to avoid overflow:hidden clipping from .card
                function resetSeriesModal() {
                    document.getElementById('seriesRiderSearchSection').style.display = 'flex';
                    document.getElementById('seriesRiderCreateSection').style.display = 'none';
                    const h3 = seriesRiderSearchModal.querySelector('.rider-search-modal__header h3');
                    if (h3) h3.textContent = 'Sök deltagare';
                }

                seriesOpenRiderSearchBtn.addEventListener('click', () => {
                    document.body.appendChild(seriesRiderSearchModal);
                    seriesRiderSearchModal.style.display = 'flex';
                    seriesRiderSearch.value = '';
                    seriesRiderSearch.focus();
                    seriesRiderSearchResults.style.display = 'none';
                    resetSeriesModal();
                });

                seriesCloseRiderSearchBtn.addEventListener('click', () => {
                    seriesRiderSearchModal.style.display = 'none';
                    resetSeriesModal();
                });

                seriesRiderSearchModal.querySelector('.rider-search-modal__backdrop').addEventListener('click', () => {
                    seriesRiderSearchModal.style.display = 'none';
                    resetSeriesModal();
                });

                // Fixed "Create new rider" link for series modal
                document.getElementById('seriesShowCreateRiderFormBtn').addEventListener('click', function() {
                    window._showCreateRiderForm('series');
                });

                // Search input
                seriesRiderSearch.addEventListener('input', (e) => {
                    clearTimeout(seriesSearchTimeout);
                    seriesSearchTimeout = setTimeout(() => {
                        seriesSearchRiders(e.target.value);
                    }, 300);
                });

                // Clear selected rider
                seriesClearSelectedRider.addEventListener('click', () => {
                    seriesSelectedRider = null;
                    seriesSelectedRiderId = null;
                    seriesSelectedClassId = null;
                    seriesSelectedClassData = null;
                    seriesSelectedRiderDisplay.style.display = 'none';
                    seriesOpenRiderSearchBtn.style.display = 'block';
                    seriesClassSelection.style.display = 'none';
                    seriesAddToCartBtn.disabled = true;
                    const seriesLicDiv = document.getElementById('seriesLicenseValidationResult');
                    if (seriesLicDiv) seriesLicDiv.style.display = 'none';
                });

                // License commitment checkbox
                if (seriesLicenseCommitmentCheckbox) {
                    seriesLicenseCommitmentCheckbox.addEventListener('change', (e) => {
                        seriesLicenseCommitmentAccepted = e.target.checked;
                        seriesUpdateAddButton();
                    });
                }

                // Add to cart - adds ALL series events at regular per-event price
                // Series discount is calculated at checkout based on season_price
                seriesAddToCartBtn.addEventListener('click', async () => {
                    if (!seriesSelectedRiderId || !seriesSelectedClassId) return;

                    const pricePerEvent = seriesSelectedClassData.current_price;
                    const seasonPrice = seriesSelectedClassData.season_price || 0;

                    // Add to cart for EACH event in the series
                    for (const event of seriesEvents) {
                        GlobalCart.addItem({
                            type: 'event',
                            event_id: event.id,
                            event_name: event.name,
                            event_date: event.date || '',
                            rider_id: seriesSelectedRiderId,
                            rider_name: `${seriesSelectedRider.firstname} ${seriesSelectedRider.lastname}`,
                            class_id: seriesSelectedClassId,
                            class_name: seriesSelectedClassData.name,
                            price: pricePerEvent,
                            license_commitment: seriesRequiresLicenseCommitment,
                            is_series_registration: true,
                            series_id: <?= json_encode($seriesInfo['id'] ?? 0) ?>,
                            season_price: seasonPrice
                        });
                    }

                    // Clear any stale pending order since cart changed
                    sessionStorage.removeItem('pending_order_id');

                    // Reset form
                    seriesSelectedRider = null;
                    seriesSelectedRiderId = null;
                    seriesSelectedClassId = null;
                    seriesSelectedClassData = null;
                    seriesSelectedRiderDisplay.style.display = 'none';
                    seriesOpenRiderSearchBtn.style.display = 'block';
                    seriesClassSelection.style.display = 'none';
                    seriesAddToCartBtn.disabled = true;
                    seriesLicenseCommitment.style.display = 'none';
                    seriesLicenseCommitmentAccepted = false;
                    if (seriesLicenseCommitmentCheckbox) {
                        seriesLicenseCommitmentCheckbox.checked = false;
                    }
                });

                // Expose seriesSelectRider for shared handleCreateRider function
                window._seriesSelectRider = seriesSelectRider;
            })();
            <?php endif; ?>
            </script>
        <?php endif; ?>
    </div>
</section>

<?php elseif ($activeTab === 'elimination'): ?>
<!-- ELIMINATION TAB -->
<section class="card">
    <div class="card-header">
        <h2 class="card-title"><i data-lucide="git-branch"></i> Elimination / Dual Slalom</h2>
    </div>
    <div class="card-body">
        <?php
        // Include the elimination display component
        include INCLUDES_PATH . '/elimination-display.php';
        ?>
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
    // Toggle colors for split times (Enduro) and DH run times
    document.querySelectorAll('.col-split, .col-dh-run').forEach(cell => {
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

// Sort DH class section by run time
function sortDHByRun(headerEl, classKey, runNum) {
    const section = document.getElementById('class-' + classKey);
    if (!section) return;

    const table = section.querySelector('.results-table');
    if (!table) return;

    const tbody = table.querySelector('tbody');
    const rows = Array.from(tbody.querySelectorAll('tr.result-row'));

    // Get the column index for this run
    const headers = table.querySelectorAll('thead th');
    let colIndex = -1;
    headers.forEach((th, idx) => {
        if (th.dataset.sort === 'run' + runNum) {
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

    // Re-append rows
    rows.forEach((row) => {
        tbody.appendChild(row);
    });
}

// Sort class section by time (Bästa/Tid column)
function sortByTime(headerEl, classKey) {
    const section = document.getElementById('class-' + classKey);
    if (!section) return;

    const table = section.querySelector('.results-table');
    if (!table) return;

    const tbody = table.querySelector('tbody');
    const rows = Array.from(tbody.querySelectorAll('tr.result-row'));

    // Get the column index for time/best column
    const headers = table.querySelectorAll('thead th');
    let colIndex = -1;
    headers.forEach((th, idx) => {
        if (th.dataset.sort === 'time' || th.dataset.sort === 'best') {
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

    // Re-append rows
    rows.forEach((row) => {
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

// Toggle DH Total view (Åktider Total)
function toggleDHTotalView(enabled) {
    const classSections = document.querySelectorAll('.class-section');
    const totalSection = document.getElementById('total-dh-section');
    const classFilter = document.getElementById('classFilter');

    if (enabled) {
        // Hide class sections, show DH total section
        classSections.forEach(section => section.classList.add('hidden'));
        if (totalSection) totalSection.classList.remove('hidden');
        // Disable class filter when in total view
        if (classFilter) {
            classFilter.disabled = true;
            classFilter.style.opacity = '0.5';
        }
    } else {
        // Show class sections, hide DH total section
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

// Sort DH total section by run time
function sortDHTotalByRun(headerEl, runNum) {
    const table = document.getElementById('total-dh-table');
    if (!table) return;

    const tbody = table.querySelector('tbody');
    const rows = Array.from(tbody.querySelectorAll('tr.result-row'));

    // Get the column index for this run
    const headers = table.querySelectorAll('thead th');
    let colIndex = -1;
    headers.forEach((th, idx) => {
        if (th.dataset.sort === 'run' + runNum) {
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

<?php if ($isTimingLive && $activeTab === 'resultat'): ?>
<script>
// Live results polling
(function() {
    const eventId = <?= (int)$eventId ?>;
    let lastUpdated = null;
    let pollInterval = 10000; // 10 seconds

    function pollResults() {
        const url = '/api/v1/event-results-status.php?event_id=' + eventId +
            (lastUpdated ? '&since=' + encodeURIComponent(lastUpdated) : '');

        fetch(url)
            .then(r => r.json())
            .then(data => {
                if (!data.success) return;

                // Update LIVE badge pulse
                const badge = document.getElementById('live-badge');
                if (badge) {
                    badge.classList.toggle('pulse', data.is_live);
                    if (!data.is_live) badge.textContent = 'Resultat';
                }

                // If new results since last poll, reload the page
                if (data.last_updated && data.last_updated !== lastUpdated) {
                    if (lastUpdated !== null) {
                        // New results arrived - reload to show them
                        window.location.reload();
                    }
                    lastUpdated = data.last_updated;
                }
            })
            .catch(() => { /* Ignore polling errors silently */ });
    }

    // Start polling
    pollResults();
    setInterval(pollResults, pollInterval);
})();
</script>
<?php endif; ?>

<style>
/* Live badge styling */
.tab-badge--live {
    background: var(--color-error) !important;
    color: #fff !important;
    animation: livePulse 2s ease-in-out infinite;
    font-weight: 700;
    font-size: 0.65rem;
    letter-spacing: 0.5px;
    text-transform: uppercase;
}
@keyframes livePulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.6; }
}

/* Series branding colors - dynamic per event */
:root {
    --series-gradient-start: <?= htmlspecialchars($event['series_gradient_start'] ?? '#004A98') ?>;
    --series-gradient-end: <?= htmlspecialchars($event['series_gradient_end'] ?? '#002a5c') ?>;
}
</style>

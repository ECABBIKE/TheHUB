<?php
/**
 * Publik festivalsida - /festival/{id}
 * Visar festival med hero, program, tävlingsevent, aktiviteter och sponsorer
 */

// Prevent direct access
if (!defined('HUB_ROOT')) {
    header('Location: /festival');
    exit;
}

$pdo = hub_db();

// Check if festival section is publicly visible
$isAdmin = !empty($_SESSION['admin_role']) && in_array($_SESSION['admin_role'], ['admin', 'super_admin']);
$festivalPublic = (site_setting('festival_public_enabled', '0') === '1');
if (!$festivalPublic && !$isAdmin) {
    http_response_code(404);
    $pageTitle = '404';
    echo '<div class="card" style="padding: var(--space-2xl); text-align: center;"><h2>Sidan hittades inte</h2><p><a href="/">Tillbaka till startsidan</a></p></div>';
    return;
}

// Get festival ID from router
$festivalId = intval($pageInfo['params']['id'] ?? $_GET['id'] ?? 0);
if ($festivalId <= 0) {
    header('Location: /festival');
    exit;
}

// Check table exists
try {
    $pdo->query("SELECT 1 FROM festivals LIMIT 1");
} catch (PDOException $e) {
    http_response_code(404);
    echo '<h1>Sidan finns inte ännu</h1>';
    exit;
}

// Load festival
$stmt = $pdo->prepare("
    SELECT f.*, v.name as venue_name
    FROM festivals f
    LEFT JOIN venues v ON f.venue_id = v.id
    WHERE f.id = ? AND f.active = 1
");
$stmt->execute([$festivalId]);
$festival = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$festival) {
    http_response_code(404);
    $pageTitle = 'Festival hittades inte';
    echo '<div class="card" style="padding: var(--space-2xl); text-align: center;"><h2>Festival hittades inte</h2><p><a href="/festival">Visa alla festivaler</a></p></div>';
    return;
}

// Only show published festivals (or draft for admins)
if ($festival['status'] !== 'published' && !$isAdmin) {
    http_response_code(404);
    $pageTitle = 'Festival hittades inte';
    echo '<div class="card" style="padding: var(--space-2xl); text-align: center;"><h2>Festivalen är inte publicerad ännu</h2><p><a href="/festival">Visa alla festivaler</a></p></div>';
    return;
}

// Load linked competition events (include included_in_pass from festival_events)
$events = $pdo->prepare("
    SELECT e.id, e.name, e.date, e.end_date, e.location, e.discipline, e.event_format,
        e.max_participants, e.registration_deadline,
        fe.included_in_pass,
        (SELECT COUNT(*) FROM event_registrations er WHERE er.event_id = e.id AND er.status != 'cancelled') as reg_count,
        GROUP_CONCAT(DISTINCT s.name SEPARATOR ', ') as series_names
    FROM festival_events fe
    JOIN events e ON fe.event_id = e.id
    LEFT JOIN series_events se ON se.event_id = e.id
    LEFT JOIN series s ON se.series_id = s.id
    WHERE fe.festival_id = ? AND e.active = 1
    GROUP BY e.id
    ORDER BY e.date ASC, fe.sort_order ASC
");
$events->execute([$festivalId]);
$events = $events->fetchAll(PDO::FETCH_ASSOC);

// Load activities
$activities = $pdo->prepare("
    SELECT fa.*,
        (SELECT COUNT(*) FROM festival_activity_registrations far WHERE far.activity_id = fa.id AND far.status != 'cancelled') as reg_count
    FROM festival_activities fa
    WHERE fa.festival_id = ? AND fa.active = 1
    ORDER BY fa.date ASC, fa.start_time ASC, fa.sort_order ASC
");
$activities->execute([$festivalId]);
$activities = $activities->fetchAll(PDO::FETCH_ASSOC);

// Load activity slots count and dates (indexed by activity_id)
$activitySlotCounts = [];
$activitySlotDates = []; // activity_id => [date1, date2, ...]
try {
    $slStmt = $pdo->prepare("
        SELECT s.activity_id, COUNT(*) as slot_count
        FROM festival_activity_slots s
        JOIN festival_activities fa ON s.activity_id = fa.id
        WHERE fa.festival_id = ? AND s.active = 1
        GROUP BY s.activity_id
    ");
    $slStmt->execute([$festivalId]);
    foreach ($slStmt->fetchAll(PDO::FETCH_ASSOC) as $sc) {
        $activitySlotCounts[$sc['activity_id']] = (int)$sc['slot_count'];
    }
    // Load unique dates per activity from slots
    $slDateStmt = $pdo->prepare("
        SELECT DISTINCT s.activity_id, s.date
        FROM festival_activity_slots s
        JOIN festival_activities fa ON s.activity_id = fa.id
        WHERE fa.festival_id = ? AND s.active = 1
        ORDER BY s.date ASC
    ");
    $slDateStmt->execute([$festivalId]);
    foreach ($slDateStmt->fetchAll(PDO::FETCH_ASSOC) as $sd) {
        $activitySlotDates[$sd['activity_id']][] = $sd['date'];
    }
} catch (PDOException $e) {}

// Load activity groups (if table exists)
$activityGroups = [];
$groupsById = [];
try {
    $gStmt = $pdo->prepare("
        SELECT g.*,
            (SELECT COUNT(*) FROM festival_activities fa2 WHERE fa2.group_id = g.id AND fa2.active = 1) as activity_count,
            (SELECT SUM(sub.rc) FROM (SELECT (SELECT COUNT(*) FROM festival_activity_registrations far2 WHERE far2.activity_id = fa3.id AND far2.status != 'cancelled') as rc FROM festival_activities fa3 WHERE fa3.group_id = g.id AND fa3.active = 1) sub) as total_reg_count
        FROM festival_activity_groups g
        WHERE g.festival_id = ? AND g.active = 1
        ORDER BY g.date ASC, g.start_time ASC, g.sort_order ASC
    ");
    $gStmt->execute([$festivalId]);
    $activityGroups = $gStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($activityGroups as $g) {
        $groupsById[$g['id']] = $g;
    }
} catch (PDOException $e) {
    // Table doesn't exist yet - groups not available
}

// Separate grouped vs ungrouped activities
$ungroupedActivities = [];
$groupedActivityIds = [];
foreach ($activities as $a) {
    if (!empty($a['group_id']) && isset($groupsById[$a['group_id']])) {
        $groupedActivityIds[] = $a['id'];
    } else {
        $ungroupedActivities[] = $a;
    }
}

// Load banner
$bannerUrl = null;
if ($festival['header_banner_media_id']) {
    $bannerUrl = $pdo->prepare("SELECT url FROM media WHERE id = ?");
    $bannerUrl->execute([$festival['header_banner_media_id']]);
    $bannerUrl = $bannerUrl->fetchColumn() ?: null;
}

// Load logo
$logoUrl = null;
if ($festival['logo_media_id']) {
    $logoUrl = $pdo->prepare("SELECT url FROM media WHERE id = ?");
    $logoUrl->execute([$festival['logo_media_id']]);
    $logoUrl = $logoUrl->fetchColumn() ?: null;
}

// Pass activity slots + event classes loaded on booking page (/festival/{id}/pass)

// Pass stats
$passCount = 0;
if ($festival['pass_enabled']) {
    $ps = $pdo->prepare("SELECT COUNT(*) FROM festival_passes WHERE festival_id = ? AND status = 'active' AND payment_status = 'paid'");
    $ps->execute([$festivalId]);
    $passCount = $ps->fetchColumn();
}

// Group ungrouped activities by date
// Activities with time slots appear under EACH slot date (not just their base date)
$activitiesByDate = [];
foreach ($ungroupedActivities as $a) {
    $aId = (int)$a['id'];
    if (!empty($activitySlotDates[$aId])) {
        // Show activity under each unique slot date
        foreach ($activitySlotDates[$aId] as $slotDate) {
            $activitiesByDate[$slotDate][] = $a;
        }
    } else {
        // No slots: use activity's own date
        $activitiesByDate[$a['date']][] = $a;
    }
}

// Group activity groups by date
// Groups with activities that have slots: use slot dates
$groupsByDate = [];
foreach ($activityGroups as $g) {
    $gId = (int)$g['id'];
    // Collect all slot dates from group's activities
    $groupSlotDates = [];
    foreach ($activities as $ga) {
        if (($ga['group_id'] ?? 0) == $gId && !empty($activitySlotDates[$ga['id']])) {
            $groupSlotDates = array_merge($groupSlotDates, $activitySlotDates[$ga['id']]);
        }
    }
    $groupSlotDates = array_unique($groupSlotDates);

    if (!empty($groupSlotDates)) {
        foreach ($groupSlotDates as $slotDate) {
            $groupsByDate[$slotDate][] = $g;
        }
    } elseif ($g['date']) {
        $groupsByDate[$g['date']][] = $g;
    }
}
// Remove duplicate groups per date
foreach ($groupsByDate as $d => &$gList) {
    $seen = [];
    $gList = array_filter($gList, function($g) use (&$seen) {
        if (in_array($g['id'], $seen)) return false;
        $seen[] = $g['id'];
        return true;
    });
}
unset($gList);

// Group events by date
$eventsByDate = [];
foreach ($events as $e) {
    $eventsByDate[$e['date']][] = $e;
}

// All dates (merged events + activities + groups)
$allDates = array_unique(array_merge(
    array_keys($eventsByDate),
    array_keys($activitiesByDate),
    array_keys($groupsByDate)
));
sort($allDates);

// Date formatting
$months = ['jan','feb','mar','apr','maj','jun','jul','aug','sep','okt','nov','dec'];
$weekdays = ['Söndag','Måndag','Tisdag','Onsdag','Torsdag','Fredag','Lördag'];

function formatFestivalDate($date) {
    global $months, $weekdays;
    $ts = strtotime($date);
    $wd = $weekdays[date('w', $ts)];
    $d = date('j', $ts);
    $m = $months[date('n', $ts) - 1];
    return "$wd $d $m";
}

// Activity type config
$actTypes = [
    'clinic' => ['label' => 'Clinic', 'icon' => 'bike', 'color' => 'var(--series-enduro)'],
    'lecture' => ['label' => 'Föreläsning', 'icon' => 'presentation', 'color' => 'var(--series-xc)'],
    'groupride' => ['label' => 'Grupptur', 'icon' => 'route', 'color' => 'var(--series-gravel)'],
    'workshop' => ['label' => 'Workshop', 'icon' => 'wrench', 'color' => 'var(--series-downhill)'],
    'social' => ['label' => 'Socialt', 'icon' => 'users', 'color' => 'var(--series-dual)'],
    'other' => ['label' => 'Övrigt', 'icon' => 'circle-dot', 'color' => 'var(--color-text-muted)'],
];

// Discipline icons
$discIcons = [
    'Enduro' => 'mountain', 'DH' => 'arrow-down-circle', 'XC' => 'trees',
    'Gravel' => 'road', 'Dual Slalom' => 'git-branch',
];

$dateStr = date('j', strtotime($festival['start_date']));
if ($festival['end_date'] && $festival['end_date'] !== $festival['start_date']) {
    $dateStr .= '–' . date('j', strtotime($festival['end_date']));
}
$dateStr .= ' ' . $months[date('n', strtotime($festival['start_date'])) - 1] . ' ' . date('Y', strtotime($festival['start_date']));

$pageTitle = $festival['name'];
?>

<link rel="stylesheet" href="/assets/css/pages/festival.css?v=<?= filemtime(HUB_ROOT . '/assets/css/pages/festival.css') ?>">

<main class="festival-page">

    <!-- ============ HERO ============ -->
    <section class="festival-hero" <?php if ($bannerUrl): ?>style="background-image: url('<?= htmlspecialchars($bannerUrl) ?>');"<?php endif; ?>>
        <div class="festival-hero-overlay">
            <div class="festival-hero-content">
                <?php if ($logoUrl): ?>
                <img src="<?= htmlspecialchars($logoUrl) ?>" alt="" class="festival-hero-logo">
                <?php endif; ?>
                <?php if ($festival['status'] === 'draft'): ?>
                <span class="badge badge-warning" style="margin-bottom: var(--space-xs);">Utkast</span>
                <?php endif; ?>
                <div style="display: flex; align-items: center; gap: var(--space-sm); justify-content: center;">
                    <h1 class="festival-hero-title" style="margin: 0;"><?= htmlspecialchars($festival['name']) ?></h1>
                    <?php if ($isAdmin): ?>
                    <a href="/admin/festival-edit.php?id=<?= $festivalId ?>" title="Redigera festival" style="color: var(--color-accent); opacity: 0.7; transition: opacity 0.2s;" onmouseover="this.style.opacity='1'" onmouseout="this.style.opacity='0.7'">
                        <i data-lucide="pencil" style="width: 20px; height: 20px;"></i>
                    </a>
                    <?php endif; ?>
                </div>
                <div class="festival-hero-meta">
                    <span><i data-lucide="calendar"></i> <?= $dateStr ?></span>
                    <?php if ($festival['location']): ?>
                    <span><i data-lucide="map-pin"></i> <?= htmlspecialchars($festival['location']) ?></span>
                    <?php endif; ?>
                    <?php if ($festival['venue_name']): ?>
                    <span><i data-lucide="mountain"></i> <?= htmlspecialchars($festival['venue_name']) ?></span>
                    <?php endif; ?>
                </div>
                <?php if ($festival['short_description']): ?>
                <p class="festival-hero-desc"><?= htmlspecialchars($festival['short_description']) ?></p>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- ============ QUICK STATS ============ -->
    <section class="festival-stats">
        <div class="festival-stat-item">
            <span class="festival-stat-num"><?= count($events) ?></span>
            <span class="festival-stat-lbl">Tävlingar</span>
        </div>
        <div class="festival-stat-item">
            <span class="festival-stat-num"><?= count($activities) ?></span>
            <span class="festival-stat-lbl">Aktiviteter</span>
        </div>
        <div class="festival-stat-item">
            <span class="festival-stat-num"><?= count($allDates) ?></span>
            <span class="festival-stat-lbl">Dagar</span>
        </div>
        <?php if ($festival['pass_enabled']): ?>
        <div class="festival-stat-item festival-stat-pass">
            <span class="festival-stat-num"><?= $festival['pass_price'] ? number_format($festival['pass_price'], 0) . ' kr' : 'Gratis' ?></span>
            <span class="festival-stat-lbl"><?= htmlspecialchars($festival['pass_name'] ?: 'Festivalpass') ?></span>
        </div>
        <?php endif; ?>
    </section>

    <div class="festival-body">

        <!-- ============ PROGRAM (per dag) ============ -->
        <section class="festival-program">
            <h2 class="festival-section-title"><i data-lucide="list-ordered"></i> Program</h2>

            <?php foreach ($allDates as $date):
                $dayLabel = formatFestivalDate($date);
                $dayEvents = $eventsByDate[$date] ?? [];
                $dayActivities = $activitiesByDate[$date] ?? [];
                $dayGroups = $groupsByDate[$date] ?? [];
                $totalActivities = count($dayActivities) + count($dayGroups);
            ?>
            <div class="festival-day">
                <div class="festival-day-header">
                    <div class="festival-day-label"><?= $dayLabel ?></div>
                    <div class="festival-day-count">
                        <?= count($dayEvents) ?> tävling<?= count($dayEvents) !== 1 ? 'ar' : '' ?>
                        · <?= $totalActivities ?> aktivitet<?= $totalActivities !== 1 ? 'er' : '' ?>
                    </div>
                </div>

                <div class="festival-day-items">
                    <?php
                    // Merge events, ungrouped activities and activity groups, sorted by time
                    $dayItems = [];
                    foreach ($dayEvents as $e) {
                        $dayItems[] = ['type' => 'event', 'time' => '00:00', 'data' => $e];
                    }
                    foreach ($dayActivities as $a) {
                        $dayItems[] = ['type' => 'activity', 'time' => $a['start_time'] ?? '23:59', 'data' => $a];
                    }
                    foreach ($dayGroups as $g) {
                        $dayItems[] = ['type' => 'group', 'time' => $g['start_time'] ?? '23:59', 'data' => $g];
                    }
                    usort($dayItems, fn($a, $b) => strcmp($a['time'], $b['time']));
                    ?>

                    <?php foreach ($dayItems as $item): ?>

                        <?php if ($item['type'] === 'event'):
                            $e = $item['data'];
                            $discIcon = $discIcons[$e['discipline'] ?? ''] ?? 'flag';
                            $isFull = $e['max_participants'] && $e['reg_count'] >= $e['max_participants'];
                            $deadlinePassed = $e['registration_deadline'] && strtotime($e['registration_deadline']) < time();
                        ?>
                        <a href="/event/<?= $e['id'] ?>" class="festival-item festival-item--event">
                            <div class="festival-item-icon festival-item-icon--event">
                                <i data-lucide="<?= $discIcon ?>"></i>
                            </div>
                            <div class="festival-item-body">
                                <div class="festival-item-title"><?= htmlspecialchars($e['name']) ?></div>
                                <div class="festival-item-meta">
                                    <?php if ($e['discipline']): ?>
                                    <span><?= htmlspecialchars($e['discipline']) ?></span>
                                    <?php endif; ?>
                                    <?php if ($e['series_names']): ?>
                                    <span class="festival-item-series"><?= htmlspecialchars($e['series_names']) ?></span>
                                    <?php endif; ?>
                                    <span><?= $e['reg_count'] ?> anmälda<?= $e['max_participants'] ? ' / ' . $e['max_participants'] : '' ?></span>
                                </div>
                            </div>
                            <div class="festival-item-action">
                                <?php if ($isFull): ?>
                                <span class="badge badge-warning">Fullbokat</span>
                                <?php elseif ($deadlinePassed): ?>
                                <span class="badge" style="background: var(--color-text-muted); color: #fff;">Stängd</span>
                                <?php else: ?>
                                <span class="badge badge-success">Anmälan öppen</span>
                                <?php endif; ?>
                                <i data-lucide="chevron-right" style="width: 16px; height: 16px; color: var(--color-text-muted);"></i>
                            </div>
                        </a>

                        <?php elseif ($item['type'] === 'group'):
                            $g = $item['data'];
                            $gTypeInfo = $actTypes[$g['activity_type']] ?? $actTypes['other'];
                            $gActCount = (int)($g['activity_count'] ?? 0);
                            $gRegCount = (int)($g['total_reg_count'] ?? 0);
                        ?>
                        <a href="/festival/<?= $festivalId ?>/activity/<?= $g['id'] ?>" class="festival-item festival-item--group">
                            <div class="festival-item-icon" style="background: <?= $gTypeInfo['color'] ?>20; color: <?= $gTypeInfo['color'] ?>;">
                                <i data-lucide="<?= $gTypeInfo['icon'] ?>"></i>
                            </div>
                            <div class="festival-item-body">
                                <div class="festival-item-title">
                                    <?= htmlspecialchars($g['name']) ?>
                                </div>
                                <div class="festival-item-meta">
                                    <?php if ($g['start_time']): ?>
                                    <span><i data-lucide="clock" style="width: 12px; height: 12px;"></i> <?= substr($g['start_time'], 0, 5) ?><?= $g['end_time'] ? '–' . substr($g['end_time'], 0, 5) : '' ?></span>
                                    <?php endif; ?>
                                    <span class="festival-item-type"><?= $gTypeInfo['label'] ?></span>
                                    <span class="festival-group-count"><?= $gActCount ?> aktivitet<?= $gActCount !== 1 ? 'er' : '' ?></span>
                                    <?php if ($g['instructor_name']): ?>
                                    <span><i data-lucide="user" style="width: 12px; height: 12px;"></i>
                                    <?php if (!empty($g['instructor_rider_id'])): ?>
                                        <a href="/rider/<?= intval($g['instructor_rider_id']) ?>" class="rider-link"><?= htmlspecialchars($g['instructor_name']) ?></a>
                                    <?php else: ?>
                                        <?= htmlspecialchars($g['instructor_name']) ?>
                                    <?php endif; ?>
                                    </span>
                                    <?php endif; ?>
                                    <?php if ($gRegCount > 0): ?>
                                    <span><i data-lucide="users" style="width: 12px; height: 12px;"></i> <?= $gRegCount ?> anmälda</span>
                                    <?php endif; ?>
                                </div>
                                <?php if ($g['short_description']): ?>
                                <p class="festival-item-desc"><?= htmlspecialchars(mb_strimwidth($g['short_description'], 0, 150, '...')) ?></p>
                                <?php endif; ?>
                            </div>
                            <div class="festival-item-action">
                                <i data-lucide="chevron-right" style="width: 16px; height: 16px; color: var(--color-text-muted);"></i>
                            </div>
                        </a>

                        <?php else:
                            $a = $item['data'];
                            $typeInfo = $actTypes[$a['activity_type']] ?? $actTypes['other'];
                            $spotsFull = $a['max_participants'] && $a['reg_count'] >= $a['max_participants'];
                        ?>
                        <a href="/festival/<?= $festivalId ?>/aktivitet/<?= $a['id'] ?>" class="festival-item festival-item--activity">
                            <div class="festival-item-icon" style="background: <?= $typeInfo['color'] ?>20; color: <?= $typeInfo['color'] ?>;">
                                <i data-lucide="<?= $typeInfo['icon'] ?>"></i>
                            </div>
                            <div class="festival-item-body">
                                <div class="festival-item-title">
                                    <?= htmlspecialchars($a['name']) ?>
                                    <?php if ($a['included_in_pass'] && $festival['pass_enabled']): ?>
                                    <span class="festival-pass-badge"><i data-lucide="ticket" style="width: 10px; height: 10px;"></i> Pass</span>
                                    <?php endif; ?>
                                </div>
                                <div class="festival-item-meta">
                                    <?php if ($a['start_time']): ?>
                                    <span><i data-lucide="clock" style="width: 12px; height: 12px;"></i> <?= substr($a['start_time'], 0, 5) ?><?= $a['end_time'] ? '–' . substr($a['end_time'], 0, 5) : '' ?></span>
                                    <?php endif; ?>
                                    <span class="festival-item-type"><?= $typeInfo['label'] ?></span>
                                    <?php if ($a['instructor_name']): ?>
                                    <span><i data-lucide="user" style="width: 12px; height: 12px;"></i>
                                    <?php if (!empty($a['instructor_rider_id'])): ?>
                                        <a href="/rider/<?= intval($a['instructor_rider_id']) ?>" class="rider-link"><?= htmlspecialchars($a['instructor_name']) ?></a>
                                    <?php else: ?>
                                        <?= htmlspecialchars($a['instructor_name']) ?>
                                    <?php endif; ?>
                                    </span>
                                    <?php endif; ?>
                                    <?php if (!empty($activitySlotCounts[$a['id']])): ?>
                                    <span style="color: var(--color-accent);"><i data-lucide="clock" style="width: 12px; height: 12px;"></i> <?= $activitySlotCounts[$a['id']] ?> tidspass</span>
                                    <?php elseif ($a['max_participants']): ?>
                                    <span><?= $a['reg_count'] ?>/<?= $a['max_participants'] ?> platser</span>
                                    <?php endif; ?>
                                </div>
                                <?php if ($a['description']): ?>
                                <p class="festival-item-desc"><?= htmlspecialchars(mb_strimwidth($a['description'], 0, 150, '...')) ?></p>
                                <?php endif; ?>
                            </div>
                            <div class="festival-item-action">
                                <?php if ($a['price'] > 0): ?>
                                <div class="festival-item-price"><?= number_format($a['price'], 0) ?> kr</div>
                                <?php else: ?>
                                <div class="festival-item-price" style="color: var(--color-success);">Gratis</div>
                                <?php endif; ?>
                                <?php if ($spotsFull): ?>
                                <span class="badge badge-warning" style="font-size: 0.7rem;">Fullbokat</span>
                                <?php endif; ?>
                                <i data-lucide="chevron-right" style="width: 16px; height: 16px; color: var(--color-text-muted);"></i>
                            </div>
                        </a>
                        <?php endif; ?>

                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </section>

        <!-- ============ SIDEBAR ============ -->
        <aside class="festival-sidebar">

            <!-- Festivalpass CTA -->
            <?php if ($festival['pass_enabled']): ?>
            <div class="festival-pass-card">
                <div class="festival-pass-card-header">
                    <i data-lucide="ticket"></i>
                    <span><?= htmlspecialchars($festival['pass_name'] ?: 'Festivalpass') ?></span>
                </div>
                <div class="festival-pass-card-price">
                    <?= $festival['pass_price'] ? number_format($festival['pass_price'], 0) . ' kr' : 'Gratis' ?>
                </div>
                <?php if ($festival['pass_description']): ?>
                <p class="festival-pass-card-desc"><?= htmlspecialchars($festival['pass_description']) ?></p>
                <?php endif; ?>
                <div class="festival-pass-card-includes">
                    <?php
                    // Build pass contents list (same logic as pass.php)
                    $passItems = [];

                    // 1. Groups with pass_included_count > 0
                    // Use fresh simple query to avoid issues with computed columns
                    $_passGroupIds = [];
                    try {
                        $_pgStmt = $pdo->prepare("SELECT id, name, pass_included_count FROM festival_activity_groups WHERE festival_id = ? AND active = 1 AND pass_included_count > 0");
                        $_pgStmt->execute([$festivalId]);
                        foreach ($_pgStmt->fetchAll(PDO::FETCH_ASSOC) as $_pg) {
                            $passItems[] = intval($_pg['pass_included_count']) . 'x ' . $_pg['name'];
                            $_passGroupIds[] = (int)$_pg['id'];
                        }
                    } catch (PDOException $e) {
                        // Column doesn't exist - fallback: detect groups via activities
                        $_passGroupIds = [];
                        $_fallbackGroups = [];
                        foreach ($activities as $a) {
                            if (empty($a['included_in_pass'])) continue;
                            $aGid = intval($a['group_id'] ?? 0);
                            if ($aGid && isset($groupsById[$aGid]) && !isset($_fallbackGroups[$aGid])) {
                                $_fallbackGroups[$aGid] = $groupsById[$aGid]['name'];
                                $_passGroupIds[] = $aGid;
                            }
                        }
                        foreach ($_fallbackGroups as $_fgId => $_fgName) {
                            $passItems[] = '1x ' . $_fgName;
                        }
                    }

                    // 2. Individual activities (skip those in pass-groups)
                    foreach ($activities as $a) {
                        if (empty($a['included_in_pass'])) continue;
                        $aGid = intval($a['group_id'] ?? 0);
                        if ($aGid && in_array($aGid, $_passGroupIds)) continue;
                        $iaPassCount = max(1, intval($a['pass_included_count'] ?? 1));
                        $passItems[] = $iaPassCount . 'x ' . $a['name'];
                    }

                    // 3. Events with included_in_pass
                    $includedEvts = array_filter($events, fn($e) => !empty($e['included_in_pass']));
                    foreach ($includedEvts as $ie) {
                        $evtLabel = 'Startavgift ';
                        if (!empty($ie['series_names'])) {
                            $evtLabel .= $ie['series_names'] . ' - ';
                        }
                        $evtLabel .= $ie['name'];
                        $passItems[] = '1x ' . $evtLabel;
                    }
                    ?>
                    <div style="font-size: 0.75rem; font-weight: 600; text-transform: uppercase; color: var(--color-text-muted); margin-bottom: var(--space-2xs);">
                        <?= count($passItems) ?> <?= count($passItems) === 1 ? 'sak' : 'saker' ?> ingår
                    </div>
                    <?php foreach (array_slice($passItems, 0, 6) as $pi): ?>
                    <div style="font-size: 0.8rem; color: var(--color-text-secondary); display: flex; align-items: center; gap: 4px;">
                        <i data-lucide="check" style="width: 12px; height: 12px; color: var(--color-success); flex-shrink: 0;"></i>
                        <?= htmlspecialchars($pi) ?>
                    </div>
                    <?php endforeach; ?>
                    <?php if (count($passItems) > 6): ?>
                    <div style="font-size: 0.8rem; color: var(--color-text-muted);">+ <?= count($passItems) - 6 ?> till</div>
                    <?php endif; ?>
                </div>
                <a href="/festival/<?= $festivalId ?>/pass" class="festival-pass-btn" id="festivalPassBtn" style="text-decoration: none; text-align: center; display: flex; align-items: center; justify-content: center;">
                    <i data-lucide="shopping-cart"></i> Köp festivalpass
                </a>
            </div>
            <?php endif; ?>

            <!-- Info-kort -->
            <div class="festival-info-card">
                <h3 class="festival-info-title">Information</h3>

                <?php if ($festival['location']): ?>
                <div class="festival-info-row">
                    <i data-lucide="map-pin"></i>
                    <span><?= htmlspecialchars($festival['location']) ?></span>
                </div>
                <?php endif; ?>
                <?php if ($festival['venue_name']): ?>
                <div class="festival-info-row">
                    <i data-lucide="mountain"></i>
                    <span><?= htmlspecialchars($festival['venue_name']) ?></span>
                </div>
                <?php endif; ?>
                <div class="festival-info-row">
                    <i data-lucide="calendar"></i>
                    <span><?= $dateStr ?></span>
                </div>
                <?php if ($festival['contact_email']): ?>
                <div class="festival-info-row">
                    <i data-lucide="mail"></i>
                    <a href="mailto:<?= htmlspecialchars($festival['contact_email']) ?>"><?= htmlspecialchars($festival['contact_email']) ?></a>
                </div>
                <?php endif; ?>
                <?php if ($festival['website']): ?>
                <div class="festival-info-row">
                    <i data-lucide="globe"></i>
                    <a href="<?= htmlspecialchars($festival['website']) ?>" target="_blank" rel="noopener">Webbplats</a>
                </div>
                <?php endif; ?>

                <?php if ($festival['venue_map_url']): ?>
                <a href="<?= htmlspecialchars($festival['venue_map_url']) ?>" target="_blank" rel="noopener" class="festival-map-link">
                    <i data-lucide="map"></i> Visa på karta
                </a>
                <?php endif; ?>
            </div>

            <!-- Beskrivning -->
            <?php if ($festival['description']): ?>
            <div class="festival-info-card">
                <h3 class="festival-info-title">Om festivalen</h3>
                <div class="festival-description">
                    <?php
                    if (function_exists('format_text')) {
                        echo format_text($festival['description']);
                    } else {
                        echo nl2br(htmlspecialchars($festival['description']));
                    }
                    ?>
                </div>
            </div>
            <?php endif; ?>

        </aside>

    </div>


</main>

<script>
// Festival activity cart functionality
const festivalInfo = {
    id: <?= (int)$festival['id'] ?>,
    name: <?= json_encode($festival['name'], JSON_UNESCAPED_UNICODE) ?>,
    start_date: <?= json_encode($festival['start_date']) ?>
};

// Add ungrouped activity to cart via rider search
function addActivityToCart(activityId, activityName, price) {
    openFestivalRiderSearch(function(rider) {
        const riderName = (rider.firstname || '') + ' ' + (rider.lastname || '');
        try {
            GlobalCart.addItem({
                type: 'festival_activity',
                activity_id: activityId,
                festival_id: festivalInfo.id,
                rider_id: rider.id,
                rider_name: riderName,
                activity_name: activityName,
                festival_name: festivalInfo.name,
                festival_date: festivalInfo.start_date,
                price: parseFloat(price) || 0
            });
            alert(rider.firstname + ' tillagd för ' + activityName);
        } catch (e) {
            alert('Kunde inte lägga till: ' + e.message);
        }
    });
}
</script>

<?php include __DIR__ . '/../../components/festival-rider-search.php'; ?>


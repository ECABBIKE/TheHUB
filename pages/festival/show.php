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
    include __DIR__ . '/../../includes/header.php';
    echo '<main class="container"><div class="card" style="padding: var(--space-2xl); text-align: center;"><h2>Sidan hittades inte</h2><p><a href="/">Tillbaka till startsidan</a></p></div></main>';
    include __DIR__ . '/../../includes/footer.php';
    exit;
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
    include __DIR__ . '/../../includes/header.php';
    echo '<main class="container"><div class="card" style="padding: var(--space-2xl); text-align: center;"><h2>Festival hittades inte</h2><p><a href="/festival">Visa alla festivaler</a></p></div></main>';
    include __DIR__ . '/../../includes/footer.php';
    exit;
}

// Only show published festivals (or draft for admins)
if ($festival['status'] !== 'published' && !$isAdmin) {
    http_response_code(404);
    $pageTitle = 'Festival hittades inte';
    include __DIR__ . '/../../includes/header.php';
    echo '<main class="container"><div class="card" style="padding: var(--space-2xl); text-align: center;"><h2>Festivalen är inte publicerad ännu</h2><p><a href="/festival">Visa alla festivaler</a></p></div></main>';
    include __DIR__ . '/../../includes/footer.php';
    exit;
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

// Load activity slots count (indexed by activity_id)
$activitySlotCounts = [];
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

// Load activity slots for pass-included activities (for pass config modal)
$passActivitySlots = [];
try {
    $slotDataStmt = $pdo->prepare("
        SELECT s.id, s.activity_id, s.date, s.start_time, s.end_time, s.max_participants,
            (SELECT COUNT(*) FROM festival_activity_registrations far WHERE far.slot_id = s.id AND far.status != 'cancelled') as reg_count
        FROM festival_activity_slots s
        JOIN festival_activities fa ON s.activity_id = fa.id
        WHERE fa.festival_id = ? AND fa.included_in_pass = 1 AND s.active = 1 AND fa.active = 1
        ORDER BY s.date ASC, s.start_time ASC
    ");
    $slotDataStmt->execute([$festivalId]);
    foreach ($slotDataStmt->fetchAll(PDO::FETCH_ASSOC) as $slot) {
        $passActivitySlots[$slot['activity_id']][] = $slot;
    }
} catch (PDOException $e) {}

// Load classes for pass-included events (for pass config modal)
$passEventClasses = [];
try {
    $includedEventIds = array_map(fn($e) => $e['id'], array_filter($events, fn($e) => !empty($e['included_in_pass'])));
    if (!empty($includedEventIds)) {
        $placeholders = implode(',', array_fill(0, count($includedEventIds), '?'));
        $clsStmt = $pdo->prepare("
            SELECT c.id, c.event_id, COALESCE(c.display_name, c.name) as name, c.gender, c.min_age, c.max_age
            FROM classes c
            WHERE c.event_id IN ($placeholders) AND c.active = 1
            ORDER BY c.sort_order ASC, c.name ASC
        ");
        $clsStmt->execute($includedEventIds);
        foreach ($clsStmt->fetchAll(PDO::FETCH_ASSOC) as $cls) {
            $passEventClasses[$cls['event_id']][] = $cls;
        }
    }
} catch (PDOException $e) {}

// Pass stats
$passCount = 0;
if ($festival['pass_enabled']) {
    $ps = $pdo->prepare("SELECT COUNT(*) FROM festival_passes WHERE festival_id = ? AND status = 'active' AND payment_status = 'paid'");
    $ps->execute([$festivalId]);
    $passCount = $ps->fetchColumn();
}

// Group ungrouped activities by date
$activitiesByDate = [];
foreach ($ungroupedActivities as $a) {
    $activitiesByDate[$a['date']][] = $a;
}

// Group activity groups by date
$groupsByDate = [];
foreach ($activityGroups as $g) {
    if ($g['date']) {
        $groupsByDate[$g['date']][] = $g;
    }
}

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
include __DIR__ . '/../../includes/header.php';
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
                    $includedActs = array_filter($activities, fn($a) => $a['included_in_pass']);
                    ?>
                    <div style="font-size: 0.75rem; font-weight: 600; text-transform: uppercase; color: var(--color-text-muted); margin-bottom: var(--space-2xs);">
                        <?= count($includedActs) ?> aktiviteter ingår
                    </div>
                    <?php foreach (array_slice($includedActs, 0, 5) as $ia):
                        $iaPassCount = intval($ia['pass_included_count'] ?? 1);
                    ?>
                    <div style="font-size: 0.8rem; color: var(--color-text-secondary); display: flex; align-items: center; gap: 4px;">
                        <i data-lucide="check" style="width: 12px; height: 12px; color: var(--color-success);"></i>
                        <?= htmlspecialchars($ia['name']) ?><?= $iaPassCount > 1 ? ' (' . $iaPassCount . 'x)' : '' ?>
                    </div>
                    <?php endforeach; ?>
                    <?php if (count($includedActs) > 5): ?>
                    <div style="font-size: 0.8rem; color: var(--color-text-muted);">+ <?= count($includedActs) - 5 ?> till</div>
                    <?php endif; ?>
                </div>
                <?php if (hub_is_logged_in()): ?>
                <button class="festival-pass-btn" id="festivalPassBtn" onclick="openPassConfigModal()">
                    <i data-lucide="shopping-cart"></i> Köp festivalpass
                </button>
                <?php else: ?>
                <a href="/login?return=<?= urlencode('/festival/' . $festivalId) ?>" class="festival-pass-btn">
                    <i data-lucide="log-in"></i> Logga in för att köpa
                </a>
                <?php endif; ?>
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

<?php if ($festival['pass_enabled'] && hub_is_logged_in()): ?>
<!-- ============ PASS CONFIG MODAL ============ -->
<div class="pass-modal-overlay" id="passModal" style="display:none;">
    <div class="pass-modal">
        <div class="pass-modal-header">
            <h3><i data-lucide="ticket"></i> <?= htmlspecialchars($festival['pass_name'] ?: 'Festivalpass') ?></h3>
            <button type="button" class="pass-modal-close" onclick="closePassModal()">
                <i data-lucide="x"></i>
            </button>
        </div>

        <div class="pass-modal-body">

            <!-- Rider-väljare -->
            <div class="pass-modal-section" id="passRiderSection">
                <label class="pass-modal-label">Deltagare</label>
                <select id="passRiderSelect" class="pass-modal-select" onchange="onPassRiderChange()">
                </select>
            </div>

            <?php
            $includedActs = array_filter($activities, fn($a) => $a['included_in_pass']);
            $includedEvents = array_filter($events, fn($e) => !empty($e['included_in_pass']));
            ?>

            <?php if (!empty($includedActs)): ?>
            <div class="pass-modal-section">
                <label class="pass-modal-label"><i data-lucide="check-circle" style="width:14px;height:14px;color:var(--color-success);"></i> Aktiviteter som ingår</label>

                <?php foreach ($includedActs as $ia):
                    $iaType = $actTypes[$ia['activity_type']] ?? $actTypes['other'];
                    $iaSlots = $passActivitySlots[$ia['id']] ?? [];
                    $iaHasSlots = !empty($iaSlots);
                    $iaPassCount = max(1, intval($ia['pass_included_count'] ?? 1));
                ?>
                <div class="pass-modal-item" data-activity-id="<?= $ia['id'] ?>">
                    <div class="pass-modal-item-header">
                        <span class="pass-modal-item-icon" style="color: <?= $iaType['color'] ?>;">
                            <i data-lucide="<?= $iaType['icon'] ?>"></i>
                        </span>
                        <span class="pass-modal-item-name"><?= htmlspecialchars($ia['name']) ?></span>
                        <span class="pass-modal-item-badge"><?= $iaType['label'] ?></span>
                        <?php if ($iaPassCount > 1): ?>
                        <span class="pass-modal-item-badge" style="background: var(--color-success); color: #fff;"><?= $iaPassCount ?>x</span>
                        <?php endif; ?>
                    </div>

                    <?php if ($iaHasSlots): ?>
                    <div class="pass-modal-item-config">
                        <?php for ($slotIdx = 0; $slotIdx < $iaPassCount; $slotIdx++): ?>
                        <label class="pass-modal-sublabel"><?= $iaPassCount > 1 ? 'Tidspass ' . ($slotIdx + 1) . ':' : 'Välj tidspass:' ?></label>
                        <select class="pass-modal-select pass-slot-select" data-activity-id="<?= $ia['id'] ?>" data-activity-name="<?= htmlspecialchars($ia['name']) ?>" data-slot-index="<?= $slotIdx ?>">
                            <option value="">– Välj tidspass –</option>
                            <?php foreach ($iaSlots as $slot):
                                $slotFull = $slot['max_participants'] && $slot['reg_count'] >= $slot['max_participants'];
                                $slotDate = date('j/n', strtotime($slot['date']));
                                $slotTime = substr($slot['start_time'], 0, 5);
                                $slotEnd = $slot['end_time'] ? '–' . substr($slot['end_time'], 0, 5) : '';
                                $spotsLeft = $slot['max_participants'] ? ($slot['max_participants'] - $slot['reg_count']) : null;
                            ?>
                            <option value="<?= $slot['id'] ?>"
                                data-date="<?= $slotDate ?>"
                                data-time="<?= $slotTime ?>"
                                <?= $slotFull ? 'disabled' : '' ?>>
                                <?= $slotDate ?> <?= $slotTime ?><?= $slotEnd ?>
                                <?php if ($slotFull): ?> (Fullbokat)
                                <?php elseif ($spotsLeft !== null): ?> (<?= $spotsLeft ?> platser kvar)
                                <?php endif; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <?php endfor; ?>
                    </div>
                    <?php else: ?>
                    <?php for ($autoIdx = 0; $autoIdx < $iaPassCount; $autoIdx++): ?>
                    <input type="hidden" class="pass-activity-auto" data-activity-id="<?= $ia['id'] ?>" data-activity-name="<?= htmlspecialchars($ia['name']) ?>" value="1">
                    <?php endfor; ?>
                    <div style="font-size: 0.75rem; color: var(--color-text-muted); margin-top: 2px; padding-left: 28px;">
                        <?= $iaPassCount > 1 ? $iaPassCount . ' tillfällen ingår' : 'Ingår automatiskt' ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if (!empty($includedEvents)): ?>
            <div class="pass-modal-section">
                <label class="pass-modal-label"><i data-lucide="flag" style="width:14px;height:14px;color:var(--color-accent);"></i> Tävlingar som ingår i passet</label>

                <?php foreach ($includedEvents as $ie):
                    $ieClasses = $passEventClasses[$ie['id']] ?? [];
                ?>
                <div class="pass-modal-item" data-event-id="<?= $ie['id'] ?>">
                    <div class="pass-modal-item-header">
                        <span class="pass-modal-item-icon" style="color: var(--color-accent);">
                            <i data-lucide="<?= $discIcons[$ie['discipline'] ?? ''] ?? 'flag' ?>"></i>
                        </span>
                        <span class="pass-modal-item-name"><?= htmlspecialchars($ie['name']) ?></span>
                        <?php if ($ie['discipline']): ?>
                        <span class="pass-modal-item-badge"><?= htmlspecialchars($ie['discipline']) ?></span>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($ieClasses)): ?>
                    <div class="pass-modal-item-config">
                        <label class="pass-modal-sublabel">Välj klass:</label>
                        <select class="pass-modal-select pass-class-select" data-event-id="<?= $ie['id'] ?>" data-event-name="<?= htmlspecialchars($ie['name']) ?>">
                            <option value="">– Välj klass –</option>
                            <?php foreach ($ieClasses as $cls): ?>
                            <option value="<?= $cls['id'] ?>"><?= htmlspecialchars($cls['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php else: ?>
                    <div class="pass-modal-item-note" style="font-size: 0.8rem; color: var(--color-text-muted); margin-top: var(--space-2xs);">
                        Inga klasser tillgängliga ännu. Du anmäls automatiskt.
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- Summering -->
            <div class="pass-modal-summary">
                <div class="pass-modal-summary-row">
                    <span><?= htmlspecialchars($festival['pass_name'] ?: 'Festivalpass') ?></span>
                    <span class="pass-modal-summary-price"><?= $festival['pass_price'] ? number_format($festival['pass_price'], 0) . ' kr' : 'Gratis' ?></span>
                </div>
                <div class="pass-modal-summary-note">
                    Aktiviteter som ingår i passet kostar 0 kr extra.
                </div>
            </div>

        </div>

        <div class="pass-modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closePassModal()" style="padding: 10px 20px;">Avbryt</button>
            <button type="button" class="festival-pass-btn" id="passModalConfirmBtn" onclick="confirmPassToCart()" style="flex: 1;">
                <i data-lucide="shopping-cart"></i> Lägg i kundvagn
            </button>
        </div>
    </div>
</div>

<style>
.pass-modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.6);
    z-index: 99999;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: var(--space-md);
}
.pass-modal {
    background: var(--color-bg-card);
    border: 1px solid var(--color-border-strong);
    border-radius: var(--radius-lg);
    width: 100%;
    max-width: 520px;
    max-height: 90vh;
    display: flex;
    flex-direction: column;
    box-shadow: 0 20px 60px rgba(0,0,0,0.3);
}
.pass-modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: var(--space-md) var(--space-lg);
    border-bottom: 1px solid var(--color-border);
}
.pass-modal-header h3 {
    margin: 0;
    font-family: var(--font-heading);
    font-size: 1.15rem;
    color: var(--color-text-primary);
    display: flex;
    align-items: center;
    gap: var(--space-xs);
}
.pass-modal-header h3 i {
    width: 20px; height: 20px;
    color: var(--color-accent);
}
.pass-modal-close {
    background: none;
    border: none;
    color: var(--color-text-muted);
    cursor: pointer;
    padding: var(--space-2xs);
    border-radius: var(--radius-sm);
}
.pass-modal-close:hover {
    background: var(--color-bg-hover);
    color: var(--color-text-primary);
}
.pass-modal-close i { width: 20px; height: 20px; }
.pass-modal-body {
    overflow-y: auto;
    padding: var(--space-lg);
    flex: 1;
}
.pass-modal-section {
    margin-bottom: var(--space-lg);
}
.pass-modal-label {
    display: flex;
    align-items: center;
    gap: var(--space-2xs);
    font-size: 0.8rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--color-text-muted);
    margin-bottom: var(--space-sm);
}
.pass-modal-sublabel {
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--color-text-muted);
    margin-bottom: var(--space-2xs);
    display: block;
}
.pass-modal-select {
    width: 100%;
    padding: var(--space-xs) var(--space-sm);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    background: var(--color-bg-surface);
    color: var(--color-text-primary);
    font-size: 0.875rem;
}
.pass-modal-item {
    padding: var(--space-sm);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    margin-bottom: var(--space-xs);
    background: var(--color-bg-surface);
}
.pass-modal-item-header {
    display: flex;
    align-items: center;
    gap: var(--space-xs);
}
.pass-modal-item-icon i { width: 16px; height: 16px; }
.pass-modal-item-name {
    font-weight: 600;
    font-size: 0.9rem;
    color: var(--color-text-primary);
    flex: 1;
}
.pass-modal-item-badge {
    font-size: 0.7rem;
    padding: 1px 6px;
    border-radius: var(--radius-full);
    background: var(--color-bg-hover);
    color: var(--color-text-muted);
}
.pass-modal-item-config {
    margin-top: var(--space-xs);
    padding-top: var(--space-xs);
    border-top: 1px solid var(--color-border);
}
.pass-modal-summary {
    background: var(--color-accent-light);
    border: 1px solid var(--color-border-strong);
    border-radius: var(--radius-sm);
    padding: var(--space-md);
}
.pass-modal-summary-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-weight: 700;
    color: var(--color-text-primary);
}
.pass-modal-summary-price {
    font-size: 1.25rem;
    color: var(--color-accent);
}
.pass-modal-summary-note {
    font-size: 0.8rem;
    color: var(--color-text-muted);
    margin-top: var(--space-2xs);
}
.pass-modal-footer {
    display: flex;
    gap: var(--space-sm);
    padding: var(--space-md) var(--space-lg);
    border-top: 1px solid var(--color-border);
}
@media (max-width: 767px) {
    .pass-modal-overlay {
        padding: 0;
        align-items: flex-end;
    }
    .pass-modal {
        border-radius: var(--radius-lg) var(--radius-lg) 0 0;
        max-height: 95vh;
    }
    .pass-modal-body {
        padding: var(--space-md);
    }
    .pass-modal-header,
    .pass-modal-footer {
        padding: var(--space-sm) var(--space-md);
    }
    .pass-modal-select {
        font-size: 16px;
        min-height: 44px;
    }
}
</style>
<?php endif; ?>

</main>

<script>
// Festival pass cart functionality
const festivalInfo = {
    id: <?= (int)$festival['id'] ?>,
    name: <?= json_encode($festival['name'], JSON_UNESCAPED_UNICODE) ?>,
    start_date: <?= json_encode($festival['start_date']) ?>,
    pass_enabled: <?= $festival['pass_enabled'] ? 'true' : 'false' ?>,
    pass_name: <?= json_encode($festival['pass_name'] ?: 'Festivalpass', JSON_UNESCAPED_UNICODE) ?>,
    pass_price: <?= (float)($festival['pass_price'] ?? 0) ?>
};

<?php
$registrableRiders = [];
if (hub_is_logged_in()) {
    require_once __DIR__ . '/../../includes/order-manager.php';
    $registrableRiders = getRegistrableRiders($_SESSION['hub_user_id'] ?? $_SESSION['rider_id'] ?? 0);
}
?>
const registrableRiders = <?= json_encode($registrableRiders, JSON_UNESCAPED_UNICODE) ?>;
const isLoggedIn = <?= hub_is_logged_in() ? 'true' : 'false' ?>;

// Open pass configuration modal
function openPassConfigModal() {
    if (!isLoggedIn || !festivalInfo.pass_enabled) return;

    const riderId = registrableRiders[0] ? registrableRiders[0].id : null;
    if (!riderId) return;

    const cart = GlobalCart.getCart();
    if (cart.some(ci => ci.type === 'festival_pass' && ci.festival_id === festivalInfo.id && ci.rider_id === riderId)) {
        alert('Festivalpasset finns redan i kundvagnen');
        return;
    }

    // Populate rider selector
    const sel = document.getElementById('passRiderSelect');
    if (sel) {
        sel.innerHTML = '';
        registrableRiders.forEach(r => {
            const opt = document.createElement('option');
            opt.value = r.id;
            opt.textContent = r.firstname + ' ' + r.lastname;
            sel.appendChild(opt);
        });
        // Hide section if only one rider
        const section = document.getElementById('passRiderSection');
        if (section) section.style.display = registrableRiders.length > 1 ? '' : 'none';
    }

    document.getElementById('passModal').style.display = 'flex';
    document.documentElement.classList.add('lightbox-open');
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

function closePassModal() {
    document.getElementById('passModal').style.display = 'none';
    document.documentElement.classList.remove('lightbox-open');
}

function onPassRiderChange() {
    // Future: filter classes based on rider gender/age
}

// Confirm and add pass + selected items to cart
function confirmPassToCart() {
    const riderId = parseInt(document.getElementById('passRiderSelect')?.value || registrableRiders[0]?.id);
    if (!riderId) return;

    const rider = registrableRiders.find(r => r.id === riderId);
    if (!rider) return;
    const riderName = rider.firstname + ' ' + rider.lastname;

    // 1. Add the festival pass itself
    try {
        GlobalCart.addItem({
            type: 'festival_pass',
            festival_id: festivalInfo.id,
            rider_id: riderId,
            rider_name: riderName,
            festival_name: festivalInfo.name,
            festival_date: festivalInfo.start_date,
            pass_name: festivalInfo.pass_name,
            price: festivalInfo.pass_price
        });
    } catch (e) {
        alert('Kunde inte lägga till pass: ' + e.message);
        return;
    }

    // 2. Add selected activity slots (with tidspass) — validate no duplicate slots
    const selectedSlots = new Set();
    let slotDuplicateError = false;
    document.querySelectorAll('.pass-slot-select').forEach(sel => {
        const slotId = parseInt(sel.value);
        if (!slotId) return; // No slot selected - skip
        const actId = parseInt(sel.dataset.activityId);
        const key = actId + '_' + slotId;
        if (selectedSlots.has(key)) {
            slotDuplicateError = true;
            sel.style.border = '2px solid var(--color-error)';
            return;
        }
        selectedSlots.add(key);
        sel.style.border = '';
        const actName = sel.dataset.activityName;
        const opt = sel.options[sel.selectedIndex];
        const slotDate = opt.dataset.date || '';
        const slotTime = opt.dataset.time || '';
        try {
            GlobalCart.addItem({
                type: 'festival_activity',
                activity_id: actId,
                slot_id: slotId,
                festival_id: festivalInfo.id,
                rider_id: riderId,
                rider_name: riderName,
                activity_name: actName + ' (' + slotDate + ' ' + slotTime + ')',
                festival_name: festivalInfo.name,
                festival_date: festivalInfo.start_date,
                price: 0,
                included_in_pass: true
            });
        } catch (e) { /* skip on error */ }
    });
    if (slotDuplicateError) {
        alert('Du har valt samma tidspass flera gånger för en aktivitet. Välj olika tidspass.');
        return;
    }

    // 3. Add activities without slots (auto-included)
    document.querySelectorAll('.pass-activity-auto').forEach(input => {
        const actId = parseInt(input.dataset.activityId);
        const actName = input.dataset.activityName;
        try {
            GlobalCart.addItem({
                type: 'festival_activity',
                activity_id: actId,
                festival_id: festivalInfo.id,
                rider_id: riderId,
                rider_name: riderName,
                activity_name: actName,
                festival_name: festivalInfo.name,
                festival_date: festivalInfo.start_date,
                price: 0,
                included_in_pass: true
            });
        } catch (e) { /* skip on error */ }
    });

    // 4. Add selected event classes
    document.querySelectorAll('.pass-class-select').forEach(sel => {
        const classId = parseInt(sel.value);
        if (!classId) return; // No class selected - skip
        const eventId = parseInt(sel.dataset.eventId);
        const eventName = sel.dataset.eventName;
        const className = sel.options[sel.selectedIndex].textContent.trim();
        try {
            GlobalCart.addItem({
                type: 'event',
                event_id: eventId,
                class_id: classId,
                rider_id: riderId,
                rider_name: riderName,
                event_name: eventName,
                class_name: className,
                price: 0,
                festival_pass_event: true,
                festival_id: festivalInfo.id
            });
        } catch (e) { /* skip on error */ }
    });

    // Close modal and update button
    closePassModal();
    const btn = document.getElementById('festivalPassBtn');
    if (btn) {
        btn.innerHTML = '<i data-lucide="check-circle"></i> Tillagd i kundvagnen';
        btn.disabled = true;
        btn.classList.add('btn--success');
    }
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

// Close modal on Escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closePassModal();
});
</script>


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
$isAdmin = !empty($_SESSION['admin_role']) && in_array($_SESSION['admin_role'], ['admin', 'super_admin']);
if ($festival['status'] !== 'published' && !$isAdmin) {
    http_response_code(404);
    $pageTitle = 'Festival hittades inte';
    include __DIR__ . '/../../includes/header.php';
    echo '<main class="container"><div class="card" style="padding: var(--space-2xl); text-align: center;"><h2>Festivalen är inte publicerad ännu</h2><p><a href="/festival">Visa alla festivaler</a></p></div></main>';
    include __DIR__ . '/../../includes/footer.php';
    exit;
}

// Load linked competition events
$events = $pdo->prepare("
    SELECT e.id, e.name, e.date, e.end_date, e.location, e.discipline, e.event_format,
        e.max_participants, e.registration_deadline,
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

// Pass stats
$passCount = 0;
if ($festival['pass_enabled']) {
    $ps = $pdo->prepare("SELECT COUNT(*) FROM festival_passes WHERE festival_id = ? AND status = 'active' AND payment_status = 'paid'");
    $ps->execute([$festivalId]);
    $passCount = $ps->fetchColumn();
}

// Group activities by date
$activitiesByDate = [];
foreach ($activities as $a) {
    $activitiesByDate[$a['date']][] = $a;
}

// Group events by date
$eventsByDate = [];
foreach ($events as $e) {
    $eventsByDate[$e['date']][] = $e;
}

// All dates (merged events + activities)
$allDates = array_unique(array_merge(array_keys($eventsByDate), array_keys($activitiesByDate)));
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
                <h1 class="festival-hero-title"><?= htmlspecialchars($festival['name']) ?></h1>
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
            ?>
            <div class="festival-day">
                <div class="festival-day-header">
                    <div class="festival-day-label"><?= $dayLabel ?></div>
                    <div class="festival-day-count">
                        <?= count($dayEvents) ?> tävling<?= count($dayEvents) !== 1 ? 'ar' : '' ?>
                        · <?= count($dayActivities) ?> aktivitet<?= count($dayActivities) !== 1 ? 'er' : '' ?>
                    </div>
                </div>

                <div class="festival-day-items">
                    <?php
                    // Merge events and activities, sorted by time
                    $dayItems = [];
                    foreach ($dayEvents as $e) {
                        $dayItems[] = ['type' => 'event', 'time' => '00:00', 'data' => $e];
                    }
                    foreach ($dayActivities as $a) {
                        $dayItems[] = ['type' => 'activity', 'time' => $a['start_time'] ?? '23:59', 'data' => $a];
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

                        <?php else:
                            $a = $item['data'];
                            $typeInfo = $actTypes[$a['activity_type']] ?? $actTypes['other'];
                            $spotsFull = $a['max_participants'] && $a['reg_count'] >= $a['max_participants'];
                        ?>
                        <div class="festival-item festival-item--activity" role="button" tabindex="0"
                             onclick="openActivityModal(<?= $a['id'] ?>)"
                             onkeydown="if(event.key==='Enter')openActivityModal(<?= $a['id'] ?>)">
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
                                    <span><i data-lucide="user" style="width: 12px; height: 12px;"></i> <?= htmlspecialchars($a['instructor_name']) ?></span>
                                    <?php endif; ?>
                                    <?php if ($a['max_participants']): ?>
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
                        </div>
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
                    <?php foreach (array_slice($includedActs, 0, 5) as $ia): ?>
                    <div style="font-size: 0.8rem; color: var(--color-text-secondary); display: flex; align-items: center; gap: 4px;">
                        <i data-lucide="check" style="width: 12px; height: 12px; color: var(--color-success);"></i>
                        <?= htmlspecialchars($ia['name']) ?>
                    </div>
                    <?php endforeach; ?>
                    <?php if (count($includedActs) > 5): ?>
                    <div style="font-size: 0.8rem; color: var(--color-text-muted);">+ <?= count($includedActs) - 5 ?> till</div>
                    <?php endif; ?>
                </div>
                <!-- TODO: Koppla till GlobalCart när checkout-integration byggs -->
                <button class="festival-pass-btn" disabled title="Kommer snart">
                    <i data-lucide="shopping-cart"></i> Köp pass
                </button>
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

<!-- Activity Detail Modal -->
<div class="activity-modal-backdrop" id="activityModalBackdrop" style="display:none;" onclick="closeActivityModal()">
    <div class="activity-modal" onclick="event.stopPropagation()">
        <button class="activity-modal-close" onclick="closeActivityModal()" aria-label="Stäng">
            <i data-lucide="x"></i>
        </button>
        <div class="activity-modal-header" id="actModalHeader">
            <div class="activity-modal-icon" id="actModalIcon"></div>
            <div>
                <h2 class="activity-modal-title" id="actModalTitle"></h2>
                <div class="activity-modal-type" id="actModalType"></div>
            </div>
        </div>
        <div class="activity-modal-body">
            <div class="activity-modal-info-grid" id="actModalInfoGrid"></div>
            <div class="activity-modal-desc" id="actModalDesc"></div>
        </div>
        <div class="activity-modal-footer" id="actModalFooter"></div>
    </div>
</div>

<script>
// Activity data for modal
const activityData = <?= json_encode(array_map(function($a) use ($actTypes, $festival) {
    $typeInfo = $actTypes[$a['activity_type']] ?? $actTypes['other'];
    return [
        'id' => (int)$a['id'],
        'name' => $a['name'],
        'description' => $a['description'] ?? '',
        'activity_type' => $a['activity_type'],
        'type_label' => $typeInfo['label'],
        'type_icon' => $typeInfo['icon'],
        'type_color' => $typeInfo['color'],
        'date' => $a['date'],
        'start_time' => $a['start_time'] ? substr($a['start_time'], 0, 5) : null,
        'end_time' => $a['end_time'] ? substr($a['end_time'], 0, 5) : null,
        'price' => (float)$a['price'],
        'max_participants' => (int)$a['max_participants'],
        'reg_count' => (int)$a['reg_count'],
        'instructor_name' => $a['instructor_name'] ?? '',
        'location_details' => $a['location_details'] ?? '',
        'difficulty_level' => $a['difficulty_level'] ?? '',
        'included_in_pass' => (bool)$a['included_in_pass'],
        'pass_enabled' => (bool)$festival['pass_enabled'],
    ];
}, $activities), JSON_UNESCAPED_UNICODE) ?>;

const activityMap = {};
activityData.forEach(a => activityMap[a.id] = a);

const diffLabels = {
    'beginner': 'Nybörjare',
    'intermediate': 'Medel',
    'advanced': 'Avancerad',
    'all_levels': 'Alla nivåer'
};

const weekdays = ['Söndag','Måndag','Tisdag','Onsdag','Torsdag','Fredag','Lördag'];
const months = ['januari','februari','mars','april','maj','juni','juli','augusti','september','oktober','november','december'];

function formatDate(dateStr) {
    const d = new Date(dateStr + 'T00:00:00');
    return weekdays[d.getDay()] + ' ' + d.getDate() + ' ' + months[d.getMonth()];
}

function openActivityModal(id) {
    const a = activityMap[id];
    if (!a) return;

    const backdrop = document.getElementById('activityModalBackdrop');

    // Icon
    const iconEl = document.getElementById('actModalIcon');
    iconEl.style.background = a.type_color + '20';
    iconEl.style.color = a.type_color;
    iconEl.innerHTML = '<i data-lucide="' + a.type_icon + '"></i>';

    // Title & type
    document.getElementById('actModalTitle').textContent = a.name;
    document.getElementById('actModalType').textContent = a.type_label;

    // Info grid
    const grid = document.getElementById('actModalInfoGrid');
    let gridHtml = '';

    // Date
    gridHtml += '<div class="activity-modal-info-item"><i data-lucide="calendar"></i><div><span class="activity-modal-info-label">Datum</span><span>' + formatDate(a.date) + '</span></div></div>';

    // Time
    if (a.start_time) {
        const timeStr = a.start_time + (a.end_time ? ' – ' + a.end_time : '');
        gridHtml += '<div class="activity-modal-info-item"><i data-lucide="clock"></i><div><span class="activity-modal-info-label">Tid</span><span>' + timeStr + '</span></div></div>';
    }

    // Price
    const priceStr = a.price > 0 ? a.price + ' kr' : 'Gratis';
    let priceExtra = '';
    if (a.included_in_pass && a.pass_enabled) {
        priceExtra = '<span class="festival-pass-badge" style="margin-left:6px;"><i data-lucide="ticket" style="width:10px;height:10px;"></i> Ingår i pass</span>';
    }
    gridHtml += '<div class="activity-modal-info-item"><i data-lucide="tag"></i><div><span class="activity-modal-info-label">Pris</span><span>' + priceStr + priceExtra + '</span></div></div>';

    // Spots
    if (a.max_participants > 0) {
        const spotsLeft = a.max_participants - a.reg_count;
        const spotsStr = a.reg_count + ' / ' + a.max_participants + ' platser' + (spotsLeft <= 3 && spotsLeft > 0 ? ' <strong style="color:var(--color-warning);">(' + spotsLeft + ' kvar)</strong>' : '');
        gridHtml += '<div class="activity-modal-info-item"><i data-lucide="users"></i><div><span class="activity-modal-info-label">Deltagare</span><span>' + spotsStr + '</span></div></div>';
    }

    // Instructor
    if (a.instructor_name) {
        gridHtml += '<div class="activity-modal-info-item"><i data-lucide="user"></i><div><span class="activity-modal-info-label">Instruktör</span><span>' + a.instructor_name + '</span></div></div>';
    }

    // Difficulty
    if (a.difficulty_level && diffLabels[a.difficulty_level]) {
        gridHtml += '<div class="activity-modal-info-item"><i data-lucide="signal"></i><div><span class="activity-modal-info-label">Nivå</span><span>' + diffLabels[a.difficulty_level] + '</span></div></div>';
    }

    // Location details
    if (a.location_details) {
        gridHtml += '<div class="activity-modal-info-item"><i data-lucide="map-pin"></i><div><span class="activity-modal-info-label">Plats</span><span>' + a.location_details + '</span></div></div>';
    }

    grid.innerHTML = gridHtml;

    // Description
    const descEl = document.getElementById('actModalDesc');
    if (a.description) {
        descEl.innerHTML = '<p>' + a.description.replace(/\n/g, '<br>') + '</p>';
        descEl.style.display = '';
    } else {
        descEl.style.display = 'none';
    }

    // Footer / CTA
    const footer = document.getElementById('actModalFooter');
    const isFull = a.max_participants > 0 && a.reg_count >= a.max_participants;
    if (isFull) {
        footer.innerHTML = '<div class="activity-modal-cta-full"><i data-lucide="circle-x"></i> Fullbokat</div>';
    } else {
        footer.innerHTML = '<button class="activity-modal-cta" disabled title="Kommer snart"><i data-lucide="shopping-cart"></i> Anmäl dig</button>';
    }

    backdrop.style.display = 'flex';
    document.documentElement.classList.add('activity-modal-open');

    // Re-init lucide icons in modal
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

function closeActivityModal() {
    document.getElementById('activityModalBackdrop').style.display = 'none';
    document.documentElement.classList.remove('activity-modal-open');
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeActivityModal();
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

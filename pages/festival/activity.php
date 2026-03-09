<?php
/**
 * Publik aktivitetsgrupp-sida - /festival/{id}/activity/{groupId}
 * Visar en aktivitetsgrupp med beskrivning, delaktiviteter och deltagarlista
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

// Get IDs from router
$festivalId = intval($pageInfo['params']['id'] ?? 0);
$groupId = intval($pageInfo['params']['group_id'] ?? 0);

if ($festivalId <= 0 || $groupId <= 0) {
    header('Location: /festival');
    exit;
}

// Check tables exist
try {
    $pdo->query("SELECT 1 FROM festival_activity_groups LIMIT 1");
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
    header('Location: /festival');
    exit;
}

// Only show published festivals (or draft for admins)
if ($festival['status'] !== 'published' && !$isAdmin) {
    header('Location: /festival');
    exit;
}

// Load activity group
$stmt = $pdo->prepare("
    SELECT g.*
    FROM festival_activity_groups g
    WHERE g.id = ? AND g.festival_id = ? AND g.active = 1
");
$stmt->execute([$groupId, $festivalId]);
$group = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$group) {
    header("Location: /festival/$festivalId");
    exit;
}

// Load group image
$groupImageUrl = null;
if (!empty($group['image_media_id'])) {
    $imgStmt = $pdo->prepare("SELECT url FROM media WHERE id = ?");
    $imgStmt->execute([$group['image_media_id']]);
    $groupImageUrl = $imgStmt->fetchColumn() ?: null;
}

// Load activities in this group
$stmt = $pdo->prepare("
    SELECT fa.*,
        (SELECT COUNT(*) FROM festival_activity_registrations far WHERE far.activity_id = fa.id AND far.status != 'cancelled') as reg_count
    FROM festival_activities fa
    WHERE fa.group_id = ? AND fa.active = 1
    ORDER BY fa.date ASC, fa.start_time ASC, fa.sort_order ASC
");
$stmt->execute([$groupId]);
$activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Load participants (confirmed registrations for all activities in the group)
$participants = [];
if (!empty($activities)) {
    $activityIds = array_column($activities, 'id');
    $placeholders = implode(',', array_fill(0, count($activityIds), '?'));
    $stmt = $pdo->prepare("
        SELECT far.*, fa.name as activity_name,
            r.firstname, r.lastname, r.id as rider_id,
            c.name as club_name
        FROM festival_activity_registrations far
        LEFT JOIN riders r ON far.rider_id = r.id
        LEFT JOIN clubs c ON r.club_id = c.id
        LEFT JOIN festival_activities fa ON far.activity_id = fa.id
        WHERE far.activity_id IN ($placeholders)
            AND far.status != 'cancelled'
        ORDER BY fa.sort_order ASC, far.first_name ASC, far.last_name ASC
    ");
    $stmt->execute($activityIds);
    $participants = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Group participants by activity
$participantsByActivity = [];
foreach ($participants as $p) {
    $participantsByActivity[$p['activity_id']][] = $p;
}

// Load slots for activities in this group
$slotsByActivity = [];
if (!empty($activities)) {
    try {
        $actIds = array_column($activities, 'id');
        $slotPlaceholders = implode(',', array_fill(0, count($actIds), '?'));
        $slotStmt = $pdo->prepare("
            SELECT s.*,
                (SELECT COUNT(*) FROM festival_activity_registrations far WHERE far.slot_id = s.id AND far.status != 'cancelled') as reg_count
            FROM festival_activity_slots s
            WHERE s.activity_id IN ($slotPlaceholders) AND s.active = 1
            ORDER BY s.date ASC, s.start_time ASC
        ");
        $slotStmt->execute($actIds);
        foreach ($slotStmt->fetchAll(PDO::FETCH_ASSOC) as $slot) {
            $slotsByActivity[$slot['activity_id']][] = $slot;
        }
    } catch (PDOException $e) {}
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

$typeInfo = $actTypes[$group['activity_type']] ?? $actTypes['other'];

// Date formatting
$months = ['jan','feb','mar','apr','maj','jun','jul','aug','sep','okt','nov','dec'];
$weekdays = ['Söndag','Måndag','Tisdag','Onsdag','Torsdag','Fredag','Lördag'];

function formatActivityDate($date) {
    global $months, $weekdays;
    $ts = strtotime($date);
    $wd = $weekdays[date('w', $ts)];
    $d = date('j', $ts);
    $m = $months[date('n', $ts) - 1];
    return "$wd $d $m";
}

// Total registrations for group
$totalRegs = count($participants);

$pageTitle = $group['name'] . ' – ' . $festival['name'];
?>

<link rel="stylesheet" href="/assets/css/pages/festival.css?v=<?= filemtime(HUB_ROOT . '/assets/css/pages/festival.css') ?>">

<main class="festival-page">

    <!-- ============ BREADCRUMB ============ -->
    <nav class="festival-breadcrumb">
        <a href="/festival"><i data-lucide="tent" style="width: 14px; height: 14px;"></i> Festivaler</a>
        <i data-lucide="chevron-right" style="width: 12px; height: 12px;"></i>
        <a href="/festival/<?= $festivalId ?>"><?= htmlspecialchars($festival['name']) ?></a>
        <i data-lucide="chevron-right" style="width: 12px; height: 12px;"></i>
        <span><?= htmlspecialchars($group['name']) ?></span>
    </nav>

    <!-- ============ GROUP HEADER ============ -->
    <section class="activity-group-header">
        <?php if ($groupImageUrl): ?>
        <div class="activity-group-image">
            <img src="<?= htmlspecialchars($groupImageUrl) ?>" alt="<?= htmlspecialchars($group['name']) ?>">
        </div>
        <?php endif; ?>

        <div class="activity-group-info">
            <div class="activity-group-type-badge" style="background: <?= $typeInfo['color'] ?>20; color: <?= $typeInfo['color'] ?>;">
                <i data-lucide="<?= $typeInfo['icon'] ?>"></i>
                <?= $typeInfo['label'] ?>
            </div>
            <h1 class="activity-group-title"><?= htmlspecialchars($group['name']) ?></h1>

            <div class="activity-group-meta">
                <?php if ($group['date']): ?>
                <span><i data-lucide="calendar"></i> <?= formatActivityDate($group['date']) ?></span>
                <?php endif; ?>
                <?php if ($group['start_time']): ?>
                <span><i data-lucide="clock"></i> <?= substr($group['start_time'], 0, 5) ?><?= $group['end_time'] ? ' – ' . substr($group['end_time'], 0, 5) : '' ?></span>
                <?php endif; ?>
                <?php if ($group['instructor_name']): ?>
                <span><i data-lucide="user"></i>
                <?php if (!empty($group['instructor_rider_id'])): ?>
                    <a href="/rider/<?= intval($group['instructor_rider_id']) ?>" class="rider-link"><?= htmlspecialchars($group['instructor_name']) ?></a>
                <?php else: ?>
                    <?= htmlspecialchars($group['instructor_name']) ?>
                <?php endif; ?>
                </span>
                <?php endif; ?>
                <?php if ($group['location_detail']): ?>
                <span><i data-lucide="map-pin"></i> <?= htmlspecialchars($group['location_detail']) ?></span>
                <?php endif; ?>
                <span><i data-lucide="users"></i> <?= $totalRegs ?> anmälda</span>
            </div>
        </div>
    </section>

    <div class="activity-group-body">

        <!-- ============ MAIN CONTENT ============ -->
        <section class="activity-group-main">

            <!-- Description -->
            <?php if ($group['description']): ?>
            <div class="card">
                <div class="card-body">
                    <div class="festival-description">
                        <?php
                        if (function_exists('format_text')) {
                            echo format_text($group['description']);
                        } else {
                            echo nl2br(htmlspecialchars($group['description']));
                        }
                        ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Instructor info -->
            <?php if ($group['instructor_name'] && $group['instructor_info']): ?>
            <div class="card">
                <div class="card-header">
                    <h3><i data-lucide="user" style="width: 18px; height: 18px;"></i> Om <?php if (!empty($group['instructor_rider_id'])): ?><a href="/rider/<?= intval($group['instructor_rider_id']) ?>" class="rider-link"><?= htmlspecialchars($group['instructor_name']) ?></a><?php else: ?><?= htmlspecialchars($group['instructor_name']) ?><?php endif; ?></h3>
                </div>
                <div class="card-body">
                    <div class="festival-description">
                        <?php
                        if (function_exists('format_text')) {
                            echo format_text($group['instructor_info']);
                        } else {
                            echo nl2br(htmlspecialchars($group['instructor_info']));
                        }
                        ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Activities in this group -->
            <?php if (!empty($activities)): ?>
            <div class="card">
                <div class="card-header">
                    <h3><i data-lucide="list" style="width: 18px; height: 18px;"></i> Aktiviteter (<?= count($activities) ?>)</h3>
                </div>
                <div class="card-body" style="padding: 0;">
                    <?php foreach ($activities as $act):
                        $aType = $actTypes[$act['activity_type']] ?? $actTypes['other'];
                        $spotsFull = $act['max_participants'] && $act['reg_count'] >= $act['max_participants'];
                        $actParticipants = $participantsByActivity[$act['id']] ?? [];
                    ?>
                    <div class="activity-list-item">
                        <?php $actSlots = $slotsByActivity[$act['id']] ?? []; $hasActSlots = !empty($actSlots); ?>
                        <div class="activity-list-item-header">
                            <div class="activity-list-item-icon" style="background: <?= $aType['color'] ?>20; color: <?= $aType['color'] ?>;">
                                <i data-lucide="<?= $aType['icon'] ?>"></i>
                            </div>
                            <div class="activity-list-item-info">
                                <div class="activity-list-item-title"><?= htmlspecialchars($act['name']) ?></div>
                                <div class="activity-list-item-meta">
                                    <?php if ($hasActSlots): ?>
                                    <span><i data-lucide="calendar" style="width: 12px; height: 12px;"></i> <?= count($actSlots) ?> tidspass</span>
                                    <?php elseif ($act['start_time']): ?>
                                    <span><i data-lucide="clock" style="width: 12px; height: 12px;"></i> <?= substr($act['start_time'], 0, 5) ?><?= $act['end_time'] ? '–' . substr($act['end_time'], 0, 5) : '' ?></span>
                                    <?php endif; ?>
                                    <?php if ($act['price'] > 0): ?>
                                    <span><i data-lucide="tag" style="width: 12px; height: 12px;"></i> <?= number_format($act['price'], 0) ?> kr</span>
                                    <?php else: ?>
                                    <span style="color: var(--color-success);"><i data-lucide="tag" style="width: 12px; height: 12px;"></i> Gratis</span>
                                    <?php endif; ?>
                                    <?php if (!$hasActSlots): ?>
                                    <span><i data-lucide="users" style="width: 12px; height: 12px;"></i> <?= $act['reg_count'] ?><?= $act['max_participants'] ? '/' . $act['max_participants'] : '' ?></span>
                                    <?php endif; ?>
                                    <?php if ($act['included_in_pass'] && $festival['pass_enabled']): ?>
                                    <span class="festival-pass-badge"><i data-lucide="ticket" style="width: 10px; height: 10px;"></i> Pass</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="activity-list-item-action">
                                <?php if ($hasActSlots): ?>
                                <a href="/festival/<?= $festivalId ?>/aktivitet/<?= $act['id'] ?>" class="btn btn-primary" style="padding: 6px 12px; font-size: 0.8rem;">
                                    <i data-lucide="calendar-clock" style="width: 14px; height: 14px;"></i> Välj pass
                                </a>
                                <?php elseif ($spotsFull): ?>
                                <span class="badge badge-warning">Fullbokat</span>
                                <?php elseif (hub_is_logged_in()): ?>
                                <button class="btn btn-primary" style="padding: 6px 12px; font-size: 0.8rem;" onclick="addActivityToCart(<?= $act['id'] ?>)">
                                    <i data-lucide="shopping-cart" style="width: 14px; height: 14px;"></i> Anmäl
                                </button>
                                <?php else: ?>
                                <a href="/login?return=<?= urlencode('/festival/' . $festivalId . '/activity/' . $groupId) ?>" class="btn btn-primary" style="padding: 6px 12px; font-size: 0.8rem;">
                                    <i data-lucide="log-in" style="width: 14px; height: 14px;"></i> Logga in
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if ($act['description']): ?>
                        <p class="activity-list-item-desc"><?= htmlspecialchars(mb_strimwidth($act['description'], 0, 250, '...')) ?></p>
                        <?php endif; ?>

                        <!-- Participant list per activity -->
                        <?php if (!empty($actParticipants)): ?>
                        <details class="activity-participants">
                            <summary>
                                <i data-lucide="users" style="width: 14px; height: 14px;"></i>
                                <?= count($actParticipants) ?> deltagare
                            </summary>
                            <div class="activity-participants-list">
                                <?php foreach ($actParticipants as $p):
                                    $name = htmlspecialchars(($p['firstname'] ?: $p['first_name']) . ' ' . ($p['lastname'] ?: $p['last_name']));
                                    $club = htmlspecialchars($p['club_name'] ?? '');
                                ?>
                                <div class="activity-participant-row">
                                    <?php if ($p['rider_id']): ?>
                                    <a href="/rider/<?= $p['rider_id'] ?>" class="rider-link"><?= $name ?></a>
                                    <?php else: ?>
                                    <span><?= $name ?></span>
                                    <?php endif; ?>
                                    <?php if ($club): ?>
                                    <span class="activity-participant-club"><?= $club ?></span>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </details>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </section>

        <!-- ============ SIDEBAR ============ -->
        <aside class="festival-sidebar">
            <!-- Back to festival -->
            <a href="/festival/<?= $festivalId ?>" class="activity-group-back-link">
                <i data-lucide="arrow-left"></i> Tillbaka till <?= htmlspecialchars($festival['name']) ?>
            </a>

            <!-- Festival pass CTA -->
            <?php if ($festival['pass_enabled']): ?>
            <div class="festival-pass-card">
                <div class="festival-pass-card-header">
                    <i data-lucide="ticket"></i>
                    <span><?= htmlspecialchars($festival['pass_name'] ?: 'Festivalpass') ?></span>
                </div>
                <div class="festival-pass-card-price">
                    <?= $festival['pass_price'] ? number_format($festival['pass_price'], 0) . ' kr' : 'Gratis' ?>
                </div>
                <?php if (hub_is_logged_in()): ?>
                <button class="festival-pass-btn" id="festivalPassBtn" onclick="addFestivalPassToCart()">
                    <i data-lucide="shopping-cart"></i> Lägg i kundvagn
                </button>
                <?php else: ?>
                <a href="/login?return=<?= urlencode('/festival/' . $festivalId . '/activity/' . $groupId) ?>" class="festival-pass-btn">
                    <i data-lucide="log-in"></i> Logga in för att köpa
                </a>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Info card -->
            <div class="festival-info-card">
                <h3 class="festival-info-title"><?= htmlspecialchars($festival['name']) ?></h3>
                <?php if ($festival['location']): ?>
                <div class="festival-info-row">
                    <i data-lucide="map-pin"></i>
                    <span><?= htmlspecialchars($festival['location']) ?></span>
                </div>
                <?php endif; ?>
                <div class="festival-info-row">
                    <i data-lucide="calendar"></i>
                    <span><?= date('j', strtotime($festival['start_date'])) ?><?php if ($festival['end_date'] && $festival['end_date'] !== $festival['start_date']): ?>–<?= date('j', strtotime($festival['end_date'])) ?><?php endif; ?> <?= $months[date('n', strtotime($festival['start_date'])) - 1] ?> <?= date('Y', strtotime($festival['start_date'])) ?></span>
                </div>
                <?php if ($festival['contact_email']): ?>
                <div class="festival-info-row">
                    <i data-lucide="mail"></i>
                    <a href="mailto:<?= htmlspecialchars($festival['contact_email']) ?>"><?= htmlspecialchars($festival['contact_email']) ?></a>
                </div>
                <?php endif; ?>
            </div>
        </aside>
    </div>

</main>

<script>
// Festival & activity data for cart operations
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

const activityData = <?= json_encode(array_map(function($a) use ($actTypes, $festival) {
    $typeInfo = $actTypes[$a['activity_type']] ?? $actTypes['other'];
    return [
        'id' => (int)$a['id'],
        'name' => $a['name'],
        'activity_type' => $a['activity_type'],
        'price' => (float)$a['price'],
        'max_participants' => (int)$a['max_participants'],
        'reg_count' => (int)$a['reg_count'],
        'included_in_pass' => (bool)$a['included_in_pass'],
    ];
}, $activities), JSON_UNESCAPED_UNICODE) ?>;

const activityMap = {};
activityData.forEach(a => activityMap[a.id] = a);

function addActivityToCart(activityId) {
    const a = activityMap[activityId];
    if (!a) { alert('Aktiviteten hittades inte.'); return; }
    if (!isLoggedIn) { alert('Du måste vara inloggad för att anmäla dig.'); return; }

    const riderId = registrableRiders[0] ? registrableRiders[0].id : null;
    if (!riderId) { alert('Inga deltagare kopplade till ditt konto. Gå till din profil och lägg till en deltagare.'); return; }
    const rider = registrableRiders[0];

    // Check if already in cart
    const cart = GlobalCart.getCart();
    if (cart.some(ci => ci.type === 'festival_activity' && ci.activity_id === a.id && ci.rider_id === riderId)) {
        alert('Aktiviteten finns redan i kundvagnen');
        return;
    }

    try {
        GlobalCart.addItem({
            type: 'festival_activity',
            activity_id: a.id,
            festival_id: festivalInfo.id,
            rider_id: riderId,
            rider_name: rider.firstname + ' ' + rider.lastname,
            activity_name: a.name,
            festival_name: festivalInfo.name,
            festival_date: festivalInfo.start_date,
            price: a.price,
            included_in_pass: a.included_in_pass
        });

        // Update button (find by activity id)
        const btn = document.querySelector('[onclick*="addActivityToCart(' + activityId + ')"]');
        if (btn) {
            btn.innerHTML = '<i data-lucide="check-circle" style="width:14px;height:14px;"></i> Tillagd';
            btn.disabled = true;
            btn.classList.remove('btn-primary');
            btn.style.background = 'var(--color-success)';
            btn.style.color = '#fff';
            if (typeof lucide !== 'undefined') lucide.createIcons();
        }
    } catch (e) {
        alert('Kunde inte lägga till: ' + e.message);
    }
}

function addFestivalPassToCart() {
    if (!isLoggedIn) { alert('Du måste vara inloggad för att köpa festivalpass.'); return; }
    if (!festivalInfo.pass_enabled) { alert('Festivalpass är inte aktiverat för denna festival.'); return; }

    const riderId = registrableRiders[0] ? registrableRiders[0].id : null;
    if (!riderId) { alert('Inga deltagare kopplade till ditt konto. Gå till din profil och lägg till en deltagare.'); return; }
    const rider = registrableRiders[0];

    const cart = GlobalCart.getCart();
    if (cart.some(ci => ci.type === 'festival_pass' && ci.festival_id === festivalInfo.id && ci.rider_id === riderId)) {
        alert('Festivalpasset finns redan i kundvagnen');
        return;
    }

    try {
        GlobalCart.addItem({
            type: 'festival_pass',
            festival_id: festivalInfo.id,
            rider_id: riderId,
            rider_name: rider.firstname + ' ' + rider.lastname,
            festival_name: festivalInfo.name,
            festival_date: festivalInfo.start_date,
            pass_name: festivalInfo.pass_name,
            price: festivalInfo.pass_price
        });

        const btn = document.getElementById('festivalPassBtn');
        if (btn) {
            btn.innerHTML = '<i data-lucide="check-circle"></i> Tillagd i kundvagnen';
            btn.disabled = true;
            btn.classList.add('btn--success');
            if (typeof lucide !== 'undefined') lucide.createIcons();
        }
    } catch (e) {
        alert('Kunde inte lägga till: ' + e.message);
    }
}
</script>



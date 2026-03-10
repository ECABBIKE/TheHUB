<?php
/**
 * Publik enskild aktivitetssida - /festival/{id}/aktivitet/{activityId}
 * Visar en enskild aktivitet med beskrivning, instruktör och deltagarlista
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
$activityId = intval($pageInfo['params']['activity_id'] ?? 0);

if ($festivalId <= 0 || $activityId <= 0) {
    header('Location: /festival');
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

// Load activity
$stmt = $pdo->prepare("
    SELECT fa.*,
        (SELECT COUNT(*) FROM festival_activity_registrations far WHERE far.activity_id = fa.id AND far.status != 'cancelled') as reg_count
    FROM festival_activities fa
    WHERE fa.id = ? AND fa.festival_id = ? AND fa.active = 1
");
$stmt->execute([$activityId, $festivalId]);
$activity = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$activity) {
    header("Location: /festival/$festivalId");
    exit;
}

// Load time slots (if table exists)
$activitySlots = [];
$hasSlots = false;
try {
    $slotStmt = $pdo->prepare("
        SELECT s.*,
            (SELECT COUNT(*) FROM festival_activity_registrations far WHERE far.slot_id = s.id AND far.status != 'cancelled') as reg_count
        FROM festival_activity_slots s
        WHERE s.activity_id = ? AND s.active = 1
        ORDER BY s.date ASC, s.start_time ASC
    ");
    $slotStmt->execute([$activityId]);
    $activitySlots = $slotStmt->fetchAll(PDO::FETCH_ASSOC);
    $hasSlots = !empty($activitySlots);
} catch (PDOException $e) {
    // Table doesn't exist yet
}

// Group slots by date
$slotsByDate = [];
foreach ($activitySlots as $slot) {
    $slotsByDate[$slot['date']][] = $slot;
}

// Load participants
$participants = [];
$stmt = $pdo->prepare("
    SELECT far.*,
        r.firstname, r.lastname, r.id as rider_id,
        c.name as club_name
    FROM festival_activity_registrations far
    LEFT JOIN riders r ON far.rider_id = r.id
    LEFT JOIN clubs c ON r.club_id = c.id
    WHERE far.activity_id = ? AND far.status != 'cancelled'
    ORDER BY far.first_name ASC, far.last_name ASC
");
$stmt->execute([$activityId]);
$participants = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Activity type config
$actTypes = [
    'clinic' => ['label' => 'Clinic', 'icon' => 'bike', 'color' => 'var(--series-enduro)'],
    'lecture' => ['label' => 'Föreläsning', 'icon' => 'presentation', 'color' => 'var(--series-xc)'],
    'groupride' => ['label' => 'Grupptur', 'icon' => 'route', 'color' => 'var(--series-gravel)'],
    'workshop' => ['label' => 'Workshop', 'icon' => 'wrench', 'color' => 'var(--series-downhill)'],
    'social' => ['label' => 'Socialt', 'icon' => 'users', 'color' => 'var(--series-dual)'],
    'other' => ['label' => 'Övrigt', 'icon' => 'circle-dot', 'color' => 'var(--color-text-muted)'],
];

$typeInfo = $actTypes[$activity['activity_type']] ?? $actTypes['other'];

$diffLabels = [
    'beginner' => 'Nybörjare',
    'intermediate' => 'Medel',
    'advanced' => 'Avancerad',
    'all_levels' => 'Alla nivåer',
];

// Date formatting
$months = ['jan','feb','mar','apr','maj','jun','jul','aug','sep','okt','nov','dec'];
$weekdays = ['Söndag','Måndag','Tisdag','Onsdag','Torsdag','Fredag','Lördag'];

$actDate = '';
if ($activity['date']) {
    $ts = strtotime($activity['date']);
    $actDate = $weekdays[date('w', $ts)] . ' ' . date('j', $ts) . ' ' . $months[date('n', $ts) - 1];
}

$spotsFull = $activity['max_participants'] && $activity['reg_count'] >= $activity['max_participants'];

$pageTitle = $activity['name'] . ' – ' . $festival['name'];
?>

<link rel="stylesheet" href="/assets/css/pages/festival.css?v=<?= filemtime(HUB_ROOT . '/assets/css/pages/festival.css') ?>">

<main class="festival-page">

    <!-- ============ BREADCRUMB ============ -->
    <nav class="festival-breadcrumb">
        <a href="/festival"><i data-lucide="tent" style="width: 14px; height: 14px;"></i> Festivaler</a>
        <i data-lucide="chevron-right" style="width: 12px; height: 12px;"></i>
        <a href="/festival/<?= $festivalId ?>"><?= htmlspecialchars($festival['name']) ?></a>
        <i data-lucide="chevron-right" style="width: 12px; height: 12px;"></i>
        <span><?= htmlspecialchars($activity['name']) ?></span>
    </nav>

    <!-- ============ ACTIVITY HEADER ============ -->
    <section class="activity-group-header">
        <div class="activity-group-info">
            <div class="activity-group-type-badge" style="background: <?= $typeInfo['color'] ?>20; color: <?= $typeInfo['color'] ?>;">
                <i data-lucide="<?= $typeInfo['icon'] ?>"></i>
                <?= $typeInfo['label'] ?>
            </div>
            <h1 class="activity-group-title">
                <?= htmlspecialchars($activity['name']) ?>
                <?php
                $restrParts = [];
                $rg = $activity['gender'] ?? null;
                if ($rg === 'F' || $rg === 'K') $restrParts[] = 'Damer';
                elseif ($rg === 'M') $restrParts[] = 'Herrar';
                if (!empty($activity['min_age']) && !empty($activity['max_age'])) $restrParts[] = $activity['min_age'] . '–' . $activity['max_age'] . ' år';
                elseif (!empty($activity['min_age'])) $restrParts[] = $activity['min_age'] . '+ år';
                elseif (!empty($activity['max_age'])) $restrParts[] = '–' . $activity['max_age'] . ' år';
                if (!empty($restrParts)):
                ?>
                <span style="font-size: 0.55em; font-weight: 700; background: var(--color-accent-light); color: var(--color-accent-text); padding: 2px 8px; border-radius: var(--radius-full); white-space: nowrap; vertical-align: middle;"><?= implode(' · ', $restrParts) ?></span>
                <?php endif; ?>
            </h1>

            <div class="activity-group-meta">
                <?php if ($actDate): ?>
                <span><i data-lucide="calendar"></i> <?= $actDate ?></span>
                <?php endif; ?>
                <?php if ($activity['start_time']): ?>
                <span><i data-lucide="clock"></i> <?= substr($activity['start_time'], 0, 5) ?><?= $activity['end_time'] ? ' – ' . substr($activity['end_time'], 0, 5) : '' ?></span>
                <?php endif; ?>
                <?php if ($activity['instructor_name']): ?>
                <span><i data-lucide="user"></i>
                <?php if (!empty($activity['instructor_rider_id'])): ?>
                    <a href="/rider/<?= intval($activity['instructor_rider_id']) ?>" class="rider-link"><?= htmlspecialchars($activity['instructor_name']) ?></a>
                <?php else: ?>
                    <?= htmlspecialchars($activity['instructor_name']) ?>
                <?php endif; ?>
                </span>
                <?php endif; ?>
                <?php if (!empty($activity['location_details'])): ?>
                <span><i data-lucide="map-pin"></i> <?= htmlspecialchars($activity['location_details']) ?></span>
                <?php endif; ?>
                <span><i data-lucide="users"></i> <?= $activity['reg_count'] ?><?= $activity['max_participants'] ? '/' . $activity['max_participants'] : '' ?> anmälda</span>
                <?php if ($activity['price'] > 0): ?>
                <span><i data-lucide="tag"></i> <?= number_format($activity['price'], 0) ?> kr</span>
                <?php else: ?>
                <span style="color: var(--color-success);"><i data-lucide="tag"></i> Gratis</span>
                <?php endif; ?>
                <?php if ($activity['included_in_pass'] && $festival['pass_enabled']): ?>
                <span class="festival-pass-badge"><i data-lucide="ticket" style="width: 10px; height: 10px;"></i> Ingår i pass</span>
                <?php endif; ?>
                <?php if (!empty($activity['difficulty_level']) && isset($diffLabels[$activity['difficulty_level']])): ?>
                <span><i data-lucide="signal"></i> <?= $diffLabels[$activity['difficulty_level']] ?></span>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <div class="activity-group-body">

        <!-- ============ MAIN CONTENT ============ -->
        <section class="activity-group-main">

            <!-- Description -->
            <?php if ($activity['description']): ?>
            <div class="card">
                <div class="card-body">
                    <div class="festival-description">
                        <?php
                        if (function_exists('format_text')) {
                            echo format_text($activity['description']);
                        } else {
                            echo nl2br(htmlspecialchars($activity['description']));
                        }
                        ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Instructor info -->
            <?php if ($activity['instructor_name'] && !empty($activity['instructor_info'])): ?>
            <div class="card">
                <div class="card-header">
                    <h3><i data-lucide="user" style="width: 18px; height: 18px;"></i> Om <?php if (!empty($activity['instructor_rider_id'])): ?><a href="/rider/<?= intval($activity['instructor_rider_id']) ?>" class="rider-link"><?= htmlspecialchars($activity['instructor_name']) ?></a><?php else: ?><?= htmlspecialchars($activity['instructor_name']) ?><?php endif; ?></h3>
                </div>
                <div class="card-body">
                    <div class="festival-description">
                        <?php
                        if (function_exists('format_text')) {
                            echo format_text($activity['instructor_info']);
                        } else {
                            echo nl2br(htmlspecialchars($activity['instructor_info']));
                        }
                        ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Participants -->
            <?php if (!empty($participants)): ?>
            <div class="card">
                <div class="card-header">
                    <h3><i data-lucide="users" style="width: 18px; height: 18px;"></i> Deltagare (<?= count($participants) ?>)</h3>
                </div>
                <div class="card-body" style="padding: 0;">
                    <div class="activity-participants-list" style="padding: var(--space-sm) var(--space-md);">
                        <?php foreach ($participants as $p):
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
                </div>
            </div>
            <?php endif; ?>

            <!-- ============ TIME SLOTS or single CTA ============ -->
            <?php if ($hasSlots): ?>
            <div class="card">
                <div class="card-header">
                    <h3><i data-lucide="clock" style="width: 18px; height: 18px;"></i> Välj tidspass</h3>
                </div>
                <div class="card-body" style="padding: 0;">
                    <?php foreach ($slotsByDate as $slotDate => $slots):
                        $slotTs = strtotime($slotDate);
                        $dayLabel = $weekdays[date('w', $slotTs)] . ' ' . date('j', $slotTs) . ' ' . $months[date('n', $slotTs) - 1];
                    ?>
                    <div class="slot-date-group">
                        <div class="slot-date-header"><?= $dayLabel ?></div>
                        <?php foreach ($slots as $slot):
                            $slotFull = $slot['max_participants'] && $slot['reg_count'] >= $slot['max_participants'];
                            $spotsLeft = $slot['max_participants'] ? ($slot['max_participants'] - $slot['reg_count']) : null;
                        ?>
                        <div class="slot-row <?= $slotFull ? 'slot-row--full' : '' ?>">
                            <div class="slot-time">
                                <span class="slot-time-value"><?= substr($slot['start_time'], 0, 5) ?><?= $slot['end_time'] ? ' – ' . substr($slot['end_time'], 0, 5) : '' ?></span>
                                <?php
                                // Slot restriction badge
                                $slotRP = [];
                                $sG = $slot['gender'] ?? null;
                                if ($sG === 'F' || $sG === 'K') $slotRP[] = 'Damer';
                                elseif ($sG === 'M') $slotRP[] = 'Herrar';
                                if (!empty($slot['min_age'])) $slotRP[] = $slot['min_age'] . '+ år';
                                if (!empty($slot['max_age'])) $slotRP[] = '–' . $slot['max_age'] . ' år';
                                if (!empty($slotRP)):
                                ?>
                                <span style="font-size: 0.7rem; font-weight: 700; background: var(--color-accent-light); color: var(--color-accent-text); padding: 1px 6px; border-radius: var(--radius-full);"><?= implode(' · ', $slotRP) ?></span>
                                <?php endif; ?>
                                <span class="slot-spots">
                                    <?php if ($slotFull): ?>
                                        <span style="color: var(--color-warning);">Fullbokat</span>
                                    <?php elseif ($spotsLeft !== null): ?>
                                        <?= $spotsLeft ?> platser kvar
                                    <?php else: ?>
                                        <?= (int)$slot['reg_count'] ?> anmälda
                                    <?php endif; ?>
                                </span>
                            </div>
                            <div class="slot-action">
                                <?php if ($slotFull): ?>
                                <span class="badge badge-warning" style="font-size: 0.75rem;">Fullbokat</span>
                                <?php else: ?>
                                <button class="btn btn-primary slot-add-btn" onclick="addSlotToCart(event, <?= (int)$slot['id'] ?>, '<?= htmlspecialchars($slotDate) ?>', '<?= substr($slot['start_time'], 0, 5) ?>')">
                                    <i data-lucide="shopping-cart" style="width: 14px; height: 14px;"></i> Välj
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php elseif (!$spotsFull): ?>
            <!-- No slots: single CTA -->
            <div class="card">
                <div class="card-body" style="text-align: center; padding: var(--space-lg);">
                    <button class="btn btn-primary" id="registerBtn" onclick="addActivityToCart(<?= $activityId ?>)" style="padding: 10px 24px; font-size: 0.95rem;">
                        <i data-lucide="shopping-cart" style="width: 16px; height: 16px;"></i> Anmäl dig till denna aktivitet
                    </button>
                </div>
            </div>
            <?php else: ?>
            <div class="card">
                <div class="card-body" style="text-align: center; padding: var(--space-lg);">
                    <span class="badge badge-warning" style="font-size: 0.9rem; padding: 8px 16px;">Fullbokat</span>
                </div>
            </div>
            <?php endif; ?>
        </section>

        <!-- ============ SIDEBAR ============ -->
        <aside class="festival-sidebar">
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
                <a href="/festival/<?= $festivalId ?>/pass" class="festival-pass-btn" id="festivalPassBtn" style="text-decoration: none; text-align: center; display: flex; align-items: center; justify-content: center;">
                    <i data-lucide="shopping-cart"></i> Köp festivalpass
                </a>
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
const festivalInfo = {
    id: <?= (int)$festival['id'] ?>,
    name: <?= json_encode($festival['name'], JSON_UNESCAPED_UNICODE) ?>,
    start_date: <?= json_encode($festival['start_date']) ?>
};

const currentActivity = {
    id: <?= (int)$activity['id'] ?>,
    name: <?= json_encode($activity['name'], JSON_UNESCAPED_UNICODE) ?>,
    price: <?= (float)$activity['price'] ?>,
    included_in_pass: <?= $activity['included_in_pass'] ? 'true' : 'false' ?>,
    has_slots: <?= $hasSlots ? 'true' : 'false' ?>
};

// Pending action — stored while rider search is open
let _pendingSlot = null;
let _pendingActivity = null;
let _pendingPass = false;

function addSlotToCart(evt, slotId, slotDate, slotTime) {
    // Store the slot row element for visual feedback later
    const slotRow = evt.target.closest('.slot-row');
    _pendingSlot = { slotId, slotDate, slotTime, slotRow };
    _pendingActivity = null;
    _pendingPass = false;

    openFestivalRiderSearch(function(rider) {
        _doAddSlot(rider, _pendingSlot);
    });
}

function _doAddSlot(rider, slot) {
    const cart = GlobalCart.getCart();
    if (cart.some(ci => ci.type === 'festival_activity' && ci.activity_id === currentActivity.id && ci.slot_id === slot.slotId && ci.rider_id === rider.id)) {
        alert(rider.firstname + ' ' + rider.lastname + ' har redan detta tidspass i kundvagnen');
        return;
    }

    try {
        GlobalCart.addItem({
            type: 'festival_activity',
            activity_id: currentActivity.id,
            slot_id: slot.slotId,
            festival_id: festivalInfo.id,
            rider_id: rider.id,
            rider_name: rider.firstname + ' ' + rider.lastname,
            activity_name: currentActivity.name + ' (' + slot.slotDate + ' ' + slot.slotTime + ')',
            festival_name: festivalInfo.name,
            festival_date: festivalInfo.start_date,
            price: currentActivity.price,
            included_in_pass: currentActivity.included_in_pass
        });

        // Visual feedback on the slot row
        if (slot.slotRow) {
            const btn = slot.slotRow.querySelector('.slot-add-btn');
            if (btn) {
                btn.innerHTML = '<i data-lucide="check-circle" style="width:14px;height:14px;"></i> ' + rider.firstname;
                btn.style.background = 'var(--color-success)';
            }
            if (typeof lucide !== 'undefined') lucide.createIcons();
        }
    } catch (e) {
        alert('Kunde inte lägga till: ' + e.message);
    }
}

function addActivityToCart(activityId) {
    _pendingActivity = activityId;
    _pendingSlot = null;
    _pendingPass = false;

    openFestivalRiderSearch(function(rider) {
        _doAddActivity(rider);
    });
}

function _doAddActivity(rider) {
    const cart = GlobalCart.getCart();
    if (cart.some(ci => ci.type === 'festival_activity' && ci.activity_id === currentActivity.id && ci.rider_id === rider.id)) {
        alert(rider.firstname + ' ' + rider.lastname + ' är redan anmäld till denna aktivitet');
        return;
    }

    try {
        GlobalCart.addItem({
            type: 'festival_activity',
            activity_id: currentActivity.id,
            festival_id: festivalInfo.id,
            rider_id: rider.id,
            rider_name: rider.firstname + ' ' + rider.lastname,
            activity_name: currentActivity.name,
            festival_name: festivalInfo.name,
            festival_date: festivalInfo.start_date,
            price: currentActivity.price,
            included_in_pass: currentActivity.included_in_pass
        });

        const btn = document.getElementById('registerBtn');
        if (btn) {
            btn.innerHTML = '<i data-lucide="check-circle" style="width:16px;height:16px;"></i> ' + rider.firstname + ' tillagd';
            btn.style.background = 'var(--color-success)';
            btn.style.color = '#fff';
            if (typeof lucide !== 'undefined') lucide.createIcons();
        }
    } catch (e) {
        alert('Kunde inte lägga till: ' + e.message);
    }
}

// Festival pass — now handled by dedicated booking page at /festival/{id}/pass
</script>

<?php include __DIR__ . '/../../components/festival-rider-search.php'; ?>



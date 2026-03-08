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
    include __DIR__ . '/../../includes/header.php';
    echo '<main class="container"><div class="card" style="padding: var(--space-2xl); text-align: center;"><h2>Sidan hittades inte</h2><p><a href="/">Tillbaka till startsidan</a></p></div></main>';
    include __DIR__ . '/../../includes/footer.php';
    exit;
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
include __DIR__ . '/../../includes/header.php';
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
            <h1 class="activity-group-title"><?= htmlspecialchars($activity['name']) ?></h1>

            <div class="activity-group-meta">
                <?php if ($actDate): ?>
                <span><i data-lucide="calendar"></i> <?= $actDate ?></span>
                <?php endif; ?>
                <?php if ($activity['start_time']): ?>
                <span><i data-lucide="clock"></i> <?= substr($activity['start_time'], 0, 5) ?><?= $activity['end_time'] ? ' – ' . substr($activity['end_time'], 0, 5) : '' ?></span>
                <?php endif; ?>
                <?php if ($activity['instructor_name']): ?>
                <span><i data-lucide="user"></i> <?= htmlspecialchars($activity['instructor_name']) ?></span>
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
                    <h3><i data-lucide="user" style="width: 18px; height: 18px;"></i> Om <?= htmlspecialchars($activity['instructor_name']) ?></h3>
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

            <!-- CTA: Register -->
            <?php if (!$spotsFull): ?>
            <div class="card">
                <div class="card-body" style="text-align: center; padding: var(--space-lg);">
                    <?php if (hub_is_logged_in()): ?>
                    <button class="btn btn-primary" id="registerBtn" onclick="addActivityToCart(<?= $activityId ?>)" style="padding: 10px 24px; font-size: 0.95rem;">
                        <i data-lucide="shopping-cart" style="width: 16px; height: 16px;"></i> Anmäl dig till denna aktivitet
                    </button>
                    <?php else: ?>
                    <a href="/login?return=<?= urlencode('/festival/' . $festivalId . '/aktivitet/' . $activityId) ?>" class="btn btn-primary" style="padding: 10px 24px; font-size: 0.95rem;">
                        <i data-lucide="log-in" style="width: 16px; height: 16px;"></i> Logga in för att anmäla dig
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            <?php elseif ($spotsFull): ?>
            <div class="card">
                <div class="card-body" style="text-align: center; padding: var(--space-lg);">
                    <span class="badge badge-warning" style="font-size: 0.9rem; padding: 8px 16px;">Fullbokat</span>
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
                <a href="/login?return=<?= urlencode('/festival/' . $festivalId . '/aktivitet/' . $activityId) ?>" class="festival-pass-btn">
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

const currentActivity = {
    id: <?= (int)$activity['id'] ?>,
    name: <?= json_encode($activity['name'], JSON_UNESCAPED_UNICODE) ?>,
    price: <?= (float)$activity['price'] ?>,
    included_in_pass: <?= $activity['included_in_pass'] ? 'true' : 'false' ?>
};

function addActivityToCart(activityId) {
    if (!isLoggedIn) return;

    const riderId = registrableRiders[0] ? registrableRiders[0].id : null;
    if (!riderId) return;
    const rider = registrableRiders[0];

    const cart = GlobalCart.getCart();
    if (cart.some(ci => ci.type === 'festival_activity' && ci.activity_id === currentActivity.id && ci.rider_id === riderId)) {
        alert('Aktiviteten finns redan i kundvagnen');
        return;
    }

    try {
        GlobalCart.addItem({
            type: 'festival_activity',
            activity_id: currentActivity.id,
            festival_id: festivalInfo.id,
            rider_id: riderId,
            rider_name: rider.firstname + ' ' + rider.lastname,
            activity_name: currentActivity.name,
            festival_name: festivalInfo.name,
            festival_date: festivalInfo.start_date,
            price: currentActivity.price,
            included_in_pass: currentActivity.included_in_pass
        });

        const btn = document.getElementById('registerBtn');
        if (btn) {
            btn.innerHTML = '<i data-lucide="check-circle" style="width:16px;height:16px;"></i> Tillagd i kundvagnen';
            btn.disabled = true;
            btn.style.background = 'var(--color-success)';
            btn.style.color = '#fff';
            if (typeof lucide !== 'undefined') lucide.createIcons();
        }
    } catch (e) {
        alert('Kunde inte lägga till: ' + e.message);
    }
}

function addFestivalPassToCart() {
    if (!isLoggedIn || !festivalInfo.pass_enabled) return;

    const riderId = registrableRiders[0] ? registrableRiders[0].id : null;
    if (!riderId) return;
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

<?php include __DIR__ . '/../../includes/footer.php'; ?>

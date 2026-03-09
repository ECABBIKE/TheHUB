<?php
/**
 * Festivalpass bokningssida - /festival/{id}/pass
 * Fullständig sida (inte popup) för att konfigurera och lägga festivalpass i kundvagnen.
 * Flöde: Sök deltagare → välj tidspass/klasser → lägg i kundvagn
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

if (!$festival['pass_enabled']) {
    header('Location: /festival/' . $festivalId);
    exit;
}

// Only show published festivals (or draft for admins)
if ($festival['status'] !== 'published' && !$isAdmin) {
    http_response_code(404);
    $pageTitle = 'Festival hittades inte';
    echo '<div class="card" style="padding: var(--space-2xl); text-align: center;"><h2>Festivalen är inte publicerad ännu</h2><p><a href="/festival">Visa alla festivaler</a></p></div>';
    return;
}

// Check if slot gender/age columns exist
function _festHasSlotGender($pdo) {
    static $result = null;
    if ($result !== null) return $result;
    try {
        $pdo->query("SELECT gender FROM festival_activity_slots LIMIT 0");
        $result = true;
    } catch (PDOException $e) {
        $result = false;
    }
    return $result;
}

// Load all activities
$actStmt = $pdo->prepare("
    SELECT fa.*,
        (SELECT COUNT(*) FROM festival_activity_registrations far WHERE far.activity_id = fa.id AND far.status != 'cancelled') as reg_count
    FROM festival_activities fa
    WHERE fa.festival_id = ? AND fa.active = 1
    ORDER BY fa.date ASC, fa.start_time ASC, fa.sort_order ASC
");
$actStmt->execute([$festivalId]);
$activities = $actStmt->fetchAll(PDO::FETCH_ASSOC);

// Filter included activities (individual, not in a group with pass_included_count)
$includedActivities = [];
$groupPassActivities = []; // activities in groups that have group-level pass inclusion

// Load groups with pass_included_count
$passGroups = [];
try {
    $grpStmt = $pdo->prepare("SELECT * FROM festival_activity_groups WHERE festival_id = ? AND active = 1 ORDER BY sort_order ASC");
    $grpStmt->execute([$festivalId]);
    foreach ($grpStmt->fetchAll(PDO::FETCH_ASSOC) as $grp) {
        $grpPassCount = intval($grp['pass_included_count'] ?? 0);
        if ($grpPassCount > 0) {
            $passGroups[$grp['id']] = $grp;
            $passGroups[$grp['id']]['activities'] = [];
        }
    }
} catch (PDOException $e) { /* table or column may not exist */ }

foreach ($activities as $a) {
    $gid = intval($a['group_id'] ?? 0);
    if ($gid && isset($passGroups[$gid])) {
        // Activity belongs to a group with group-level pass inclusion
        $passGroups[$gid]['activities'][] = $a;
    } elseif ($a['included_in_pass']) {
        // Individual pass inclusion
        $includedActivities[] = $a;
    }
}

// Load activity slots for ALL pass-related activities (individual + group)
$passActivitySlots = [];
try {
    $slotStmt = $pdo->prepare("
        SELECT s.id, s.activity_id, s.date, s.start_time, s.end_time, s.max_participants,
            (SELECT COUNT(*) FROM festival_activity_registrations far WHERE far.slot_id = s.id AND far.status != 'cancelled') as reg_count
            " . (_festHasSlotGender($pdo) ? ", s.gender, s.min_age, s.max_age" : "") . "
        FROM festival_activity_slots s
        JOIN festival_activities fa ON s.activity_id = fa.id
        WHERE fa.festival_id = ? AND s.active = 1 AND fa.active = 1
        ORDER BY s.date ASC, s.start_time ASC
    ");
    $slotStmt->execute([$festivalId]);
    foreach ($slotStmt->fetchAll(PDO::FETCH_ASSOC) as $slot) {
        $passActivitySlots[$slot['activity_id']][] = $slot;
    }
} catch (PDOException $e) {}

// Load linked competition events
$evtStmt = $pdo->prepare("
    SELECT e.id, e.name, e.date, e.location, e.discipline, e.event_format,
        fe.included_in_pass,
        GROUP_CONCAT(DISTINCT s.name SEPARATOR ', ') as series_names
    FROM festival_events fe
    JOIN events e ON fe.event_id = e.id
    LEFT JOIN series_events se ON se.event_id = e.id
    LEFT JOIN series s ON se.series_id = s.id
    WHERE fe.festival_id = ? AND e.active = 1
    GROUP BY e.id
    ORDER BY e.date ASC, fe.sort_order ASC
");
$evtStmt->execute([$festivalId]);
$events = $evtStmt->fetchAll(PDO::FETCH_ASSOC);
$includedEvents = array_filter($events, fn($e) => !empty($e['included_in_pass']));

// Load products included in pass
$passProducts = [];
try {
    $ppStmt = $pdo->prepare("SELECT p.*, (SELECT COUNT(*) FROM festival_product_orders po WHERE po.product_id = p.id AND po.status != 'cancelled') as order_count FROM festival_products p WHERE p.festival_id = ? AND p.active = 1 AND p.included_in_pass = 1 ORDER BY p.sort_order ASC, p.name ASC");
    $ppStmt->execute([$festivalId]);
    $passProducts = $ppStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

// Load product sizes for pass products
$passProductSizes = [];
try {
    $ppsStmt = $pdo->prepare("SELECT ps.* FROM festival_product_sizes ps JOIN festival_products p ON ps.product_id = p.id WHERE p.festival_id = ? AND p.included_in_pass = 1 AND ps.active = 1 ORDER BY ps.sort_order ASC");
    $ppsStmt->execute([$festivalId]);
    foreach ($ppsStmt->fetchAll(PDO::FETCH_ASSOC) as $pps) {
        $passProductSizes[$pps['product_id']][] = $pps;
    }
} catch (PDOException $e) {}

// Note: Event classes are loaded dynamically via AJAX after rider selection
// (uses /api/orders.php?action=event_classes which filters by rider gender/age)

// Helper: restriction badge for gender/age
function festivalRestrictionBadge($item) {
    $parts = [];
    $g = $item['gender'] ?? null;
    if ($g === 'F' || $g === 'K') $parts[] = 'Damer';
    elseif ($g === 'M') $parts[] = 'Herrar';
    $minAge = $item['min_age'] ?? null;
    $maxAge = $item['max_age'] ?? null;
    if ($minAge && $maxAge) $parts[] = $minAge . '–' . $maxAge . ' år';
    elseif ($minAge) $parts[] = $minAge . '+ år';
    elseif ($maxAge) $parts[] = '–' . $maxAge . ' år';
    if (empty($parts)) return '';
    return '<span style="font-size: 0.65rem; font-weight: 700; background: var(--color-accent-light); color: var(--color-accent-text); padding: 1px 6px; border-radius: var(--radius-full); white-space: nowrap;">' . implode(' · ', $parts) . '</span>';
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

$discIcons = [
    'Enduro' => 'mountain', 'DH' => 'arrow-down-circle', 'XC' => 'trees',
    'Gravel' => 'road', 'Dual Slalom' => 'git-branch',
];

// Date formatting
$months = ['jan','feb','mar','apr','maj','jun','jul','aug','sep','okt','nov','dec'];

$passName = $festival['pass_name'] ?: 'Festivalpass';
$pageTitle = $passName . ' — ' . $festival['name'];
?>

<link rel="stylesheet" href="/assets/css/pages/festival.css?v=<?= filemtime(HUB_ROOT . '/assets/css/pages/festival.css') ?>">

<main class="container" style="max-width: 600px; padding-top: var(--space-lg); padding-bottom: var(--space-3xl);">

    <!-- Breadcrumb -->
    <nav style="margin-bottom: var(--space-md); font-size: 0.85rem;">
        <a href="/festival/<?= $festivalId ?>" style="color: var(--color-accent);">
            <i data-lucide="arrow-left" style="width: 14px; height: 14px; vertical-align: -2px;"></i>
            <?= htmlspecialchars($festival['name']) ?>
        </a>
    </nav>

    <!-- Pass info-kort -->
    <div class="card" style="margin-bottom: var(--space-lg); border: 1px solid var(--color-accent); border-left: 4px solid var(--color-accent);">
        <div class="card-body" style="padding: var(--space-lg);">
            <div style="display: flex; align-items: flex-start; gap: var(--space-md);">
                <div style="flex-shrink: 0; width: 48px; height: 48px; border-radius: var(--radius-md); background: var(--color-accent-light); display: flex; align-items: center; justify-content: center;">
                    <i data-lucide="ticket" style="width: 24px; height: 24px; color: var(--color-accent);"></i>
                </div>
                <div style="flex: 1; min-width: 0;">
                    <h1 style="font-family: var(--font-heading); font-size: 1.35rem; margin: 0 0 var(--space-2xs);">
                        <?= htmlspecialchars($passName) ?>
                    </h1>
                    <?php if ($festival['pass_description']): ?>
                    <p style="color: var(--color-text-secondary); margin: 0 0 var(--space-sm); font-size: 0.9rem;"><?= htmlspecialchars($festival['pass_description']) ?></p>
                    <?php endif; ?>
                    <div style="font-size: 1.5rem; font-weight: 700; color: var(--color-accent); font-family: var(--font-heading);">
                        <?= $festival['pass_price'] ? number_format($festival['pass_price'], 0) . ' kr' : 'Gratis' ?>
                    </div>
                </div>
            </div>

            <?php
            // Build list of what's included
            $passIncludes = [];
            foreach ($passGroups as $grp) {
                $grpPc = intval($grp['pass_included_count'] ?? 0);
                if ($grpPc > 0) {
                    $passIncludes[] = $grpPc . 'x ' . htmlspecialchars($grp['name']);
                }
            }
            foreach ($includedActivities as $ia) {
                $iaPc = max(1, intval($ia['pass_included_count'] ?? 1));
                $passIncludes[] = $iaPc . 'x ' . htmlspecialchars($ia['name']);
            }
            foreach ($includedEvents as $ie) {
                $evtLabel = 'Startavgift ';
                if (!empty($ie['series_names'])) {
                    $evtLabel .= htmlspecialchars($ie['series_names']) . ' - ';
                }
                $evtLabel .= htmlspecialchars($ie['name']);
                $passIncludes[] = '1x ' . $evtLabel;
            }
            // Products included in pass
            foreach ($passProducts as $pp) {
                $ppPc = max(1, intval($pp['pass_included_count']));
                $passIncludes[] = $ppPc . 'x ' . htmlspecialchars($pp['name']);
            }
            if (!empty($passIncludes)): ?>
            <div style="margin-top: var(--space-md); padding-top: var(--space-md); border-top: 1px solid var(--color-border);">
                <div style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: var(--color-text-muted); margin-bottom: var(--space-xs);">Ingår i passet</div>
                <ul style="margin: 0; padding-left: var(--space-md); font-size: 0.85rem; color: var(--color-text-secondary); line-height: 1.6;">
                    <?php foreach ($passIncludes as $pi): ?>
                    <li><?= $pi ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Steg 1: Sök deltagare -->
    <div class="card" style="margin-bottom: var(--space-lg);">
        <div class="card-header" style="display: flex; align-items: center; gap: var(--space-xs);">
            <span class="pass-step-number">1</span>
            <h3 style="margin: 0; font-family: var(--font-heading-secondary); font-size: 1rem;">Välj deltagare</h3>
        </div>
        <div class="card-body">
            <div id="passRiderDisplay" style="display: none;">
                <div id="passRiderInfo" style="display: flex; align-items: center; gap: var(--space-sm); padding: var(--space-sm); background: var(--color-accent-light); border-radius: var(--radius-sm);">
                    <i data-lucide="user" style="width: 20px; height: 20px; color: var(--color-accent); flex-shrink: 0;"></i>
                    <div style="flex: 1; min-width: 0;">
                        <div id="passRiderName" style="font-weight: 600; color: var(--color-text-primary);"></div>
                        <div id="passRiderMeta" style="font-size: 0.8rem; color: var(--color-text-muted);"></div>
                    </div>
                    <button type="button" onclick="changeRider()" style="background: none; border: none; color: var(--color-accent); cursor: pointer; font-size: 0.8rem; font-weight: 600; white-space: nowrap;">
                        Byt
                    </button>
                </div>
            </div>
            <div id="passRiderSearch">
                <button type="button" onclick="selectRider()" class="btn" style="width: 100%; padding: var(--space-sm) var(--space-md); font-size: 0.95rem; min-height: 48px; display: flex; align-items: center; justify-content: center; gap: var(--space-xs); border: 2px dashed var(--color-border-strong); background: var(--color-bg-surface); color: var(--color-text-secondary); border-radius: var(--radius-md); cursor: pointer;">
                    <i data-lucide="search" style="width: 18px; height: 18px;"></i>
                    Sök och välj deltagare
                </button>
            </div>
        </div>
    </div>

    <!-- Steg 2: Välj tidspass och klasser -->
    <div class="card" style="margin-bottom: var(--space-lg); opacity: 0.5; pointer-events: none;" id="passConfigCard">
        <div class="card-header" style="display: flex; align-items: center; gap: var(--space-xs);">
            <span class="pass-step-number">2</span>
            <h3 style="margin: 0; font-family: var(--font-heading-secondary); font-size: 1rem;">Välj aktiviteter</h3>
        </div>
        <div class="card-body" style="padding: 0;">

            <?php if (!empty($includedActivities)): ?>
            <div style="padding: var(--space-md) var(--space-md) 0;">
                <div style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: var(--color-text-muted); margin-bottom: var(--space-sm); display: flex; align-items: center; gap: var(--space-2xs);">
                    <i data-lucide="check-circle" style="width: 14px; height: 14px; color: var(--color-success);"></i>
                    Aktiviteter som ingår
                </div>
            </div>

            <?php foreach ($includedActivities as $ia):
                $iaType = $actTypes[$ia['activity_type']] ?? $actTypes['other'];
                $iaSlots = $passActivitySlots[$ia['id']] ?? [];
                $iaHasSlots = !empty($iaSlots);
                $iaPassCount = max(1, intval($ia['pass_included_count'] ?? 1));
            ?>
            <div class="pass-booking-item" data-activity-id="<?= $ia['id'] ?>">
                <div class="pass-booking-item-header">
                    <span style="color: <?= $iaType['color'] ?>; flex-shrink: 0;">
                        <i data-lucide="<?= $iaType['icon'] ?>" style="width: 18px; height: 18px;"></i>
                    </span>
                    <div style="flex: 1; min-width: 0;">
                        <div style="font-weight: 600; font-size: 0.95rem; color: var(--color-text-primary);">
                            <?= htmlspecialchars($ia['name']) ?> <?= festivalRestrictionBadge($ia) ?>
                        </div>
                        <div style="font-size: 0.75rem; color: var(--color-text-muted);">
                            <?= $iaType['label'] ?>
                            <?php if ($iaPassCount > 1): ?> · <?= $iaPassCount ?> tillfällen ingår<?php endif; ?>
                        </div>
                    </div>
                    <span style="font-size: 0.75rem; font-weight: 600; color: var(--color-success); white-space: nowrap;">0 kr</span>
                </div>

                <?php if ($iaHasSlots): ?>
                <div class="pass-booking-item-config">
                    <?php for ($slotIdx = 0; $slotIdx < $iaPassCount; $slotIdx++): ?>
                    <div style="margin-bottom: var(--space-xs);">
                        <label style="font-size: 0.75rem; font-weight: 600; color: var(--color-text-muted); display: block; margin-bottom: 2px;">
                            <?= $iaPassCount > 1 ? 'Tidspass ' . ($slotIdx + 1) . ':' : 'Välj tidspass:' ?>
                        </label>
                        <select class="pass-slot-select" data-activity-id="<?= $ia['id'] ?>" data-activity-name="<?= htmlspecialchars($ia['name']) ?>" data-slot-index="<?= $slotIdx ?>" style="width: 100%; padding: var(--space-xs) var(--space-sm); border: 1px solid var(--color-border); border-radius: var(--radius-sm); background: var(--color-bg-surface); color: var(--color-text-primary); font-size: 16px; min-height: 44px;">
                            <option value="">– Välj tidspass –</option>
                            <?php foreach ($iaSlots as $slot):
                                $slotFull = $slot['max_participants'] && $slot['reg_count'] >= $slot['max_participants'];
                                $slotDate = date('j/n', strtotime($slot['date']));
                                $slotTime = substr($slot['start_time'], 0, 5);
                                $slotEnd = $slot['end_time'] ? '–' . substr($slot['end_time'], 0, 5) : '';
                                $spotsLeft = $slot['max_participants'] ? ($slot['max_participants'] - $slot['reg_count']) : null;
                            ?>
                            <?php
                                $slotRestr = [];
                                $sg = $slot['gender'] ?? null;
                                if ($sg === 'F' || $sg === 'K') $slotRestr[] = 'Damer';
                                elseif ($sg === 'M') $slotRestr[] = 'Herrar';
                                if (!empty($slot['min_age'])) $slotRestr[] = $slot['min_age'] . '+ år';
                                if (!empty($slot['max_age'])) $slotRestr[] = '–' . $slot['max_age'] . ' år';
                                $slotRestrText = !empty($slotRestr) ? ' [' . implode(', ', $slotRestr) . ']' : '';
                            ?>
                            <option value="<?= $slot['id'] ?>"
                                data-date="<?= $slotDate ?>"
                                data-time="<?= $slotTime ?>"
                                data-gender="<?= $slot['gender'] ?? '' ?>"
                                data-min-age="<?= $slot['min_age'] ?? '' ?>"
                                data-max-age="<?= $slot['max_age'] ?? '' ?>"
                                <?= $slotFull ? 'disabled' : '' ?>>
                                <?= $slotDate ?> <?= $slotTime ?><?= $slotEnd ?><?= $slotRestrText ?>
                                <?php if ($slotFull): ?> (Fullbokat)
                                <?php elseif ($spotsLeft !== null): ?> (<?= $spotsLeft ?> platser kvar)
                                <?php endif; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endfor; ?>
                </div>
                <?php else: ?>
                <?php for ($autoIdx = 0; $autoIdx < $iaPassCount; $autoIdx++): ?>
                <input type="hidden" class="pass-activity-auto" data-activity-id="<?= $ia['id'] ?>" data-activity-name="<?= htmlspecialchars($ia['name']) ?>" value="1">
                <?php endfor; ?>
                <div style="font-size: 0.75rem; color: var(--color-text-muted); margin-top: var(--space-2xs); padding-left: 30px;">
                    <?= $iaPassCount > 1 ? $iaPassCount . ' tillfällen ingår' : 'Ingår automatiskt' ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>

            <?php // ── Groups with pass inclusion (choose N of M) ──
            if (!empty($passGroups)): ?>
            <?php foreach ($passGroups as $grp):
                $grpType = $actTypes[$grp['activity_type']] ?? $actTypes['other'];
                $grpPassCount = intval($grp['pass_included_count']);
                $grpActs = $grp['activities'];
                if (empty($grpActs)) continue;
            ?>
            <div style="padding: var(--space-md) var(--space-md) 0;">
                <div style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: var(--color-text-muted); margin-bottom: var(--space-xs); display: flex; align-items: center; gap: var(--space-2xs);">
                    <i data-lucide="folder" style="width: 14px; height: 14px; color: <?= $grpType['color'] ?>;"></i>
                    <?= htmlspecialchars($grp['name']) ?>
                </div>
                <div style="font-size: 0.8rem; color: var(--color-text-secondary); margin-bottom: var(--space-sm);">
                    Välj <?= $grpPassCount ?> av <?= count($grpActs) ?> aktiviteter (ingår i passet)
                </div>
            </div>

            <?php for ($gi = 0; $gi < $grpPassCount; $gi++): ?>
            <div class="pass-booking-item pass-group-pick" data-group-id="<?= $grp['id'] ?>">
                <div style="margin-bottom: var(--space-xs);">
                    <label style="font-size: 0.75rem; font-weight: 600; color: var(--color-text-muted); display: block; margin-bottom: 2px;">
                        <?= $grpPassCount > 1 ? 'Val ' . ($gi + 1) . ':' : 'Välj aktivitet:' ?>
                    </label>
                    <select class="pass-group-activity-select" data-group-id="<?= $grp['id'] ?>" data-pick-index="<?= $gi ?>"
                        style="width: 100%; padding: var(--space-xs) var(--space-sm); border: 1px solid var(--color-border); border-radius: var(--radius-sm); background: var(--color-bg-surface); color: var(--color-text-primary); font-size: 16px; min-height: 44px;"
                        onchange="onGroupActivityChange(this)">
                        <option value="">– Välj aktivitet –</option>
                        <?php foreach ($grpActs as $ga):
                            $gaSlots = $passActivitySlots[$ga['id']] ?? [];
                        ?>
                        <?php
                            $gaRestrParts = [];
                            $gaG = $ga['gender'] ?? null;
                            if ($gaG === 'F' || $gaG === 'K') $gaRestrParts[] = 'Damer';
                            elseif ($gaG === 'M') $gaRestrParts[] = 'Herrar';
                            if (!empty($ga['min_age'])) $gaRestrParts[] = $ga['min_age'] . '+ år';
                            if (!empty($ga['max_age'])) $gaRestrParts[] = '–' . $ga['max_age'] . ' år';
                            $gaRestrText = !empty($gaRestrParts) ? ' [' . implode(', ', $gaRestrParts) . ']' : '';
                        ?>
                        <option value="<?= $ga['id'] ?>"
                            data-activity-name="<?= htmlspecialchars($ga['name']) ?>"
                            data-has-slots="<?= !empty($gaSlots) ? '1' : '0' ?>"
                            data-price="<?= (float)$ga['price'] ?>"
                            data-gender="<?= $ga['gender'] ?? '' ?>"
                            data-min-age="<?= $ga['min_age'] ?? '' ?>"
                            data-max-age="<?= $ga['max_age'] ?? '' ?>">
                            <?= htmlspecialchars($ga['name']) ?><?= $gaRestrText ?>
                            <?php if ($ga['price'] > 0): ?> (<?= number_format($ga['price'], 0) ?> kr)<?php endif; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <!-- Slot selector appears dynamically if selected activity has slots -->
                <div class="pass-group-slot-container" data-group-id="<?= $grp['id'] ?>" data-pick-index="<?= $gi ?>" style="display: none; margin-top: var(--space-xs);">
                    <label style="font-size: 0.75rem; font-weight: 600; color: var(--color-text-muted); display: block; margin-bottom: 2px;">Välj tidspass:</label>
                    <select class="pass-group-slot-select" data-group-id="<?= $grp['id'] ?>" data-pick-index="<?= $gi ?>"
                        style="width: 100%; padding: var(--space-xs) var(--space-sm); border: 1px solid var(--color-border); border-radius: var(--radius-sm); background: var(--color-bg-surface); color: var(--color-text-primary); font-size: 16px; min-height: 44px;">
                        <option value="">– Välj tidspass –</option>
                    </select>
                </div>
                <div style="font-size: 0.75rem; font-weight: 600; color: var(--color-success); text-align: right; margin-top: var(--space-2xs);">0 kr</div>
            </div>
            <?php endfor; ?>
            <?php endforeach; ?>
            <?php endif; ?>

            <?php if (!empty($includedEvents)): ?>
            <div style="padding: var(--space-md) var(--space-md) 0;">
                <div style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: var(--color-text-muted); margin-bottom: var(--space-sm); display: flex; align-items: center; gap: var(--space-2xs);">
                    <i data-lucide="flag" style="width: 14px; height: 14px; color: var(--color-accent);"></i>
                    Tävlingar som ingår
                </div>
            </div>

            <?php foreach ($includedEvents as $ie): ?>
            <div class="pass-booking-item" data-event-id="<?= $ie['id'] ?>">
                <div class="pass-booking-item-header">
                    <span style="color: var(--color-accent); flex-shrink: 0;">
                        <i data-lucide="<?= $discIcons[$ie['discipline'] ?? ''] ?? 'flag' ?>" style="width: 18px; height: 18px;"></i>
                    </span>
                    <div style="flex: 1; min-width: 0;">
                        <div style="font-weight: 600; font-size: 0.95rem; color: var(--color-text-primary);"><?= htmlspecialchars($ie['name']) ?></div>
                        <?php if ($ie['discipline']): ?>
                        <div style="font-size: 0.75rem; color: var(--color-text-muted);"><?= htmlspecialchars($ie['discipline']) ?></div>
                        <?php endif; ?>
                    </div>
                    <span style="font-size: 0.75rem; font-weight: 600; color: var(--color-success); white-space: nowrap;">0 kr</span>
                </div>

                <div class="pass-booking-item-config pass-event-class-container" data-event-id="<?= $ie['id'] ?>" data-event-name="<?= htmlspecialchars($ie['name']) ?>">
                    <label style="font-size: 0.75rem; font-weight: 600; color: var(--color-text-muted); display: block; margin-bottom: 2px;">Välj klass:</label>
                    <select class="pass-class-select" data-event-id="<?= $ie['id'] ?>" data-event-name="<?= htmlspecialchars($ie['name']) ?>" style="width: 100%; padding: var(--space-xs) var(--space-sm); border: 1px solid var(--color-border); border-radius: var(--radius-sm); background: var(--color-bg-surface); color: var(--color-text-primary); font-size: 16px; min-height: 44px;" disabled>
                        <option value="">– Välj deltagare först –</option>
                    </select>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>

            <?php if (!empty($passProducts)): ?>
            <div style="padding: var(--space-md) var(--space-md) 0;">
                <div style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: var(--color-text-muted); margin-bottom: var(--space-sm); display: flex; align-items: center; gap: var(--space-2xs);">
                    <i data-lucide="shopping-bag" style="width: 14px; height: 14px; color: var(--color-accent);"></i>
                    Produkter som ingår
                </div>
            </div>

            <?php foreach ($passProducts as $pp):
                $ppSizes = $passProductSizes[$pp['id']] ?? [];
                $ppPassCount = max(1, intval($pp['pass_included_count']));
                $ppTypeIcons = ['merch' => 'shirt', 'food' => 'utensils-crossed', 'other' => 'package'];
                $ppIcon = $ppTypeIcons[$pp['product_type']] ?? 'package';
            ?>
            <div class="pass-booking-item" data-product-id="<?= $pp['id'] ?>">
                <div class="pass-booking-item-header">
                    <span style="color: var(--color-accent); flex-shrink: 0;">
                        <i data-lucide="<?= $ppIcon ?>" style="width: 18px; height: 18px;"></i>
                    </span>
                    <div style="flex: 1; min-width: 0;">
                        <div style="font-weight: 600; font-size: 0.95rem; color: var(--color-text-primary);">
                            <?= htmlspecialchars($pp['name']) ?>
                        </div>
                        <div style="font-size: 0.75rem; color: var(--color-text-muted);">
                            <?php if ($ppPassCount > 1): ?><?= $ppPassCount ?> st ingår<?php else: ?>Ingår i passet<?php endif; ?>
                        </div>
                    </div>
                    <span style="font-size: 0.75rem; font-weight: 600; color: var(--color-success); white-space: nowrap;">0 kr</span>
                </div>

                <?php if ($pp['has_sizes'] && !empty($ppSizes)): ?>
                <div class="pass-booking-item-config">
                    <?php for ($psIdx = 0; $psIdx < $ppPassCount; $psIdx++): ?>
                    <div style="margin-bottom: var(--space-xs);">
                        <label style="font-size: 0.75rem; font-weight: 600; color: var(--color-text-muted); display: block; margin-bottom: 2px;">
                            <?= $ppPassCount > 1 ? 'Storlek ' . ($psIdx + 1) . ':' : 'Välj storlek:' ?>
                        </label>
                        <select class="pass-product-size-select" data-product-id="<?= $pp['id'] ?>" data-product-name="<?= htmlspecialchars($pp['name']) ?>" data-size-index="<?= $psIdx ?>"
                            style="width: 100%; padding: var(--space-xs) var(--space-sm); border: 1px solid var(--color-border); border-radius: var(--radius-sm); background: var(--color-bg-surface); color: var(--color-text-primary); font-size: 16px; min-height: 44px;">
                            <option value="">– Välj storlek –</option>
                            <?php foreach ($ppSizes as $pps): ?>
                            <option value="<?= $pps['id'] ?>" data-label="<?= htmlspecialchars($pps['size_label']) ?>"><?= htmlspecialchars($pps['size_label']) ?><?= $pps['stock'] !== null && $pps['stock'] <= 0 ? ' (Slut)' : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endfor; ?>
                </div>
                <?php else: ?>
                <?php for ($autoIdx = 0; $autoIdx < $ppPassCount; $autoIdx++): ?>
                <input type="hidden" class="pass-product-auto" data-product-id="<?= $pp['id'] ?>" data-product-name="<?= htmlspecialchars($pp['name']) ?>" value="1">
                <?php endfor; ?>
                <div style="font-size: 0.75rem; color: var(--color-text-muted); margin-top: var(--space-2xs); padding-left: 30px;">
                    <?= $ppPassCount > 1 ? $ppPassCount . ' st ingår' : 'Ingår automatiskt' ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>

            <?php if (empty($includedActivities) && empty($includedEvents) && empty($passGroups) && empty($passProducts)): ?>
            <div style="padding: var(--space-lg); text-align: center; color: var(--color-text-muted); font-size: 0.9rem;">
                Inga aktiviteter, tävlingar eller produkter ingår i passet ännu.
            </div>
            <?php endif; ?>

        </div>
    </div>

    <!-- Steg 3: Sammanfattning + lägg i kundvagn -->
    <div class="card" style="margin-bottom: var(--space-lg); opacity: 0.5; pointer-events: none;" id="passSummaryCard">
        <div class="card-header" style="display: flex; align-items: center; gap: var(--space-xs);">
            <span class="pass-step-number">3</span>
            <h3 style="margin: 0; font-family: var(--font-heading-secondary); font-size: 1rem;">Sammanfattning</h3>
        </div>
        <div class="card-body">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-xs);">
                <span style="font-weight: 600; color: var(--color-text-primary);"><?= htmlspecialchars($passName) ?></span>
                <span style="font-size: 1.25rem; font-weight: 700; color: var(--color-accent);">
                    <?= $festival['pass_price'] ? number_format($festival['pass_price'], 0) . ' kr' : 'Gratis' ?>
                </span>
            </div>
            <div id="passSummaryRider" style="font-size: 0.85rem; color: var(--color-text-secondary); margin-bottom: var(--space-sm);"></div>
            <div style="font-size: 0.8rem; color: var(--color-text-muted); margin-bottom: var(--space-lg);">
                Aktiviteter och tävlingar som ingår i passet kostar 0 kr extra.
            </div>
            <button type="button" id="passAddToCartBtn" onclick="addPassToCart()" class="festival-pass-btn" style="width: 100%; min-height: 48px; font-size: 1rem;">
                <i data-lucide="shopping-cart"></i> Lägg i kundvagn
            </button>
        </div>
    </div>

    <!-- Success-toast (dolt) -->
    <div id="passSuccessToast" style="display: none; position: fixed; top: var(--space-lg); left: 50%; transform: translateX(-50%); z-index: 10000; width: calc(100% - 32px); max-width: 480px;">
        <div style="background: var(--color-success); color: #fff; padding: var(--space-sm) var(--space-md); border-radius: var(--radius-md); display: flex; align-items: center; gap: var(--space-sm); box-shadow: 0 4px 20px rgba(0,0,0,0.3); font-size: 0.9rem;">
            <i data-lucide="check-circle" style="width: 20px; height: 20px; flex-shrink: 0;"></i>
            <span id="passSuccessToastText" style="flex: 1;"></span>
            <a href="/cart" style="color: #fff; font-weight: 600; white-space: nowrap; text-decoration: underline;">Kundvagn</a>
        </div>
    </div>

</main>

<style>
.pass-step-number {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    border-radius: var(--radius-full);
    background: var(--color-accent);
    color: var(--color-bg-page);
    font-weight: 700;
    font-size: 0.85rem;
    flex-shrink: 0;
}
.pass-booking-item {
    padding: var(--space-md);
    border-bottom: 1px solid var(--color-border);
}
.pass-booking-item:last-child {
    border-bottom: none;
}
.pass-booking-item-header {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
}
.pass-booking-item-config {
    margin-top: var(--space-sm);
    padding-top: var(--space-sm);
    padding-left: 30px;
    border-top: 1px solid var(--color-border);
}
@media (max-width: 767px) {
    .card {
        margin-left: -16px;
        margin-right: -16px;
        border-radius: 0 !important;
        border-left: none !important;
        border-right: none !important;
        width: calc(100% + 32px);
    }
}
</style>

<script>
const festivalInfo = {
    id: <?= (int)$festival['id'] ?>,
    name: <?= json_encode($festival['name'], JSON_UNESCAPED_UNICODE) ?>,
    start_date: <?= json_encode($festival['start_date']) ?>,
    pass_name: <?= json_encode($passName, JSON_UNESCAPED_UNICODE) ?>,
    pass_price: <?= (float)($festival['pass_price'] ?? 0) ?>
};

// Slot data for all activities (needed for group activity selection)
const activitySlotsData = <?= json_encode($passActivitySlots, JSON_UNESCAPED_UNICODE) ?>;

// Activity filter data (gender/age restrictions)
const activityFilters = <?php
$_filters = [];
foreach ($activities as $_a) {
    $_filters[$_a['id']] = [
        'gender' => $_a['gender'] ?? null,
        'min_age' => $_a['min_age'] ?? null,
        'max_age' => $_a['max_age'] ?? null,
    ];
}
echo json_encode($_filters);
?>;

function checkActivityRestriction(activityId, slotData) {
    if (!selectedRider) return null;
    // Slot overrides activity-level filter
    const slotGender = slotData ? slotData.gender : null;
    const slotMinAge = slotData ? slotData.min_age : null;
    const slotMaxAge = slotData ? slotData.max_age : null;
    const act = activityFilters[activityId] || {};
    const gender = slotGender || act.gender;
    const minAge = slotMinAge || act.min_age;
    const maxAge = slotMaxAge || act.max_age;
    if (!gender && !minAge && !maxAge) return null;

    const riderGender = selectedRider.gender;
    const riderBirthYear = parseInt(selectedRider.birth_year || 0);
    const currentYear = new Date().getFullYear();
    const riderAge = riderBirthYear ? (currentYear - riderBirthYear) : 0;

    if (gender) {
        const normalizedGender = (gender === 'K') ? 'F' : gender;
        if (riderGender && normalizedGender !== riderGender) {
            return gender === 'M' ? 'Endast herrar' : 'Endast damer';
        }
    }
    if (minAge && riderAge && riderAge < parseInt(minAge)) {
        return 'Minst ' + minAge + ' år';
    }
    if (maxAge && riderAge && riderAge > parseInt(maxAge)) {
        return 'Max ' + maxAge + ' år';
    }
    return null;
}

function onGroupActivityChange(sel) {
    const groupId = sel.dataset.groupId;
    const pickIndex = sel.dataset.pickIndex;
    const actId = sel.value;
    const slotContainer = document.querySelector('.pass-group-slot-container[data-group-id="' + groupId + '"][data-pick-index="' + pickIndex + '"]');
    const slotSelect = slotContainer.querySelector('.pass-group-slot-select');

    if (!actId) {
        slotContainer.style.display = 'none';
        return;
    }

    const opt = sel.options[sel.selectedIndex];
    const hasSlots = opt.dataset.hasSlots === '1';

    if (hasSlots && activitySlotsData[actId]) {
        // Build slot options
        let html = '<option value="">– Välj tidspass –</option>';
        activitySlotsData[actId].forEach(s => {
            const full = s.max_participants && parseInt(s.reg_count) >= parseInt(s.max_participants);
            const dateStr = new Date(s.date + 'T00:00:00').toLocaleDateString('sv-SE', { day: 'numeric', month: 'numeric' });
            const timeStr = s.start_time.substring(0, 5);
            const endStr = s.end_time ? '–' + s.end_time.substring(0, 5) : '';
            const spotsLeft = s.max_participants ? (s.max_participants - s.reg_count) : null;
            // Slot restriction text
            let restrParts = [];
            if (s.gender === 'F' || s.gender === 'K') restrParts.push('Damer');
            else if (s.gender === 'M') restrParts.push('Herrar');
            if (s.min_age) restrParts.push(s.min_age + '+ år');
            if (s.max_age) restrParts.push('–' + s.max_age + ' år');
            const restrText = restrParts.length ? ' [' + restrParts.join(', ') + ']' : '';
            // Check rider eligibility
            let slotDisabled = full;
            if (selectedRider && (s.gender || s.min_age || s.max_age)) {
                const msg = checkActivityRestriction(actId, { gender: s.gender, min_age: s.min_age, max_age: s.max_age });
                if (msg) slotDisabled = true;
            }
            let label = dateStr + ' ' + timeStr + endStr + restrText;
            if (full) label += ' (Fullbokat)';
            else if (slotDisabled) label += ' (Ej tillgänglig)';
            else if (spotsLeft !== null) label += ' (' + spotsLeft + ' platser kvar)';
            html += '<option value="' + s.id + '" data-date="' + dateStr + '" data-time="' + timeStr + '" data-gender="' + (s.gender || '') + '" data-min-age="' + (s.min_age || '') + '" data-max-age="' + (s.max_age || '') + '"' + (slotDisabled ? ' disabled' : '') + '>' + label + '</option>';
        });
        slotSelect.innerHTML = html;
        slotContainer.style.display = '';
    } else {
        slotContainer.style.display = 'none';
    }
}

let selectedRider = null;

function selectRider() {
    openFestivalRiderSearch(function(rider) {
        setRider(rider);
    });
}

function changeRider() {
    openFestivalRiderSearch(function(rider) {
        setRider(rider);
    });
}

function setRider(rider) {
    selectedRider = rider;

    // Show rider info
    const name = (rider.firstname || '') + ' ' + (rider.lastname || '');
    document.getElementById('passRiderName').textContent = name;
    let meta = '';
    if (rider.birth_year) meta += rider.birth_year;
    if (rider.club_name) meta += (meta ? ' · ' : '') + rider.club_name;
    document.getElementById('passRiderMeta').textContent = meta;

    document.getElementById('passRiderDisplay').style.display = '';
    document.getElementById('passRiderSearch').style.display = 'none';

    // Enable step 2 and 3
    document.getElementById('passConfigCard').style.opacity = '1';
    document.getElementById('passConfigCard').style.pointerEvents = '';
    document.getElementById('passSummaryCard').style.opacity = '1';
    document.getElementById('passSummaryCard').style.pointerEvents = '';

    // Update summary
    document.getElementById('passSummaryRider').textContent = 'Deltagare: ' + name;

    // Load classes for included events based on rider
    loadEventClassesForRider(rider);

    // Show restriction warnings on activities/slots
    updateRestrictionWarnings();

    if (typeof lucide !== 'undefined') lucide.createIcons();
}

function loadEventClassesForRider(rider) {
    const containers = document.querySelectorAll('.pass-event-class-container');
    if (!containers.length) return;

    containers.forEach(container => {
        const eventId = container.dataset.eventId;
        const sel = container.querySelector('.pass-class-select');

        // Show loading state
        sel.innerHTML = '<option value="">Laddar klasser...</option>';
        sel.disabled = true;

        // Remove old license badge
        const oldBadge = container.querySelector('.pass-license-badge');
        if (oldBadge) oldBadge.remove();

        fetch('/api/orders.php?action=event_classes&event_id=' + eventId + '&rider_id=' + rider.id)
            .then(r => r.json())
            .then(data => {
                sel.innerHTML = '<option value="">– Välj klass –</option>';
                if (data.success && data.classes && data.classes.length > 0) {
                    // Check if first item is an error object
                    if (data.classes[0].error) {
                        const errMsg = data.classes[0].error === 'incomplete_profile'
                            ? 'Ofullständig profil – uppdatera profilen först'
                            : data.classes[0].message || 'Kunde inte ladda klasser';
                        sel.innerHTML = '<option value="">– ' + errMsg + ' –</option>';
                        return;
                    }
                    data.classes.forEach(cls => {
                        const opt = document.createElement('option');
                        opt.value = cls.class_id;
                        opt.textContent = cls.name + (cls.current_price > 0 ? '' : '');
                        sel.appendChild(opt);
                    });
                    sel.disabled = false;
                } else if (data.error) {
                    sel.innerHTML = '<option value="">– ' + data.error + ' –</option>';
                } else {
                    sel.innerHTML = '<option value="">– Inga klasser tillgängliga –</option>';
                }

                // Show license validation badge
                if (data.license_validation) {
                    const lv = data.license_validation;
                    let badgeHtml = '';
                    if (lv.status === 'valid') {
                        badgeHtml = '<div class="pass-license-badge" style="font-size: 0.75rem; color: var(--color-success); margin-top: 4px; display: flex; align-items: center; gap: 4px;"><i data-lucide="check-circle" style="width: 14px; height: 14px;"></i> Licens giltig' + (lv.license_type ? ' (' + lv.license_type + ')' : '') + '</div>';
                    } else if (lv.status === 'warning') {
                        badgeHtml = '<div class="pass-license-badge" style="font-size: 0.75rem; color: var(--color-warning); margin-top: 4px; display: flex; align-items: center; gap: 4px;"><i data-lucide="alert-triangle" style="width: 14px; height: 14px;"></i> ' + (lv.message || 'Licensvarning') + '</div>';
                    } else if (lv.status === 'invalid' || lv.status === 'not_found') {
                        badgeHtml = '<div class="pass-license-badge" style="font-size: 0.75rem; color: var(--color-text-muted); margin-top: 4px; display: flex; align-items: center; gap: 4px;"><i data-lucide="info" style="width: 14px; height: 14px;"></i> ' + (lv.message || 'Ingen licens hittad') + '</div>';
                    }
                    if (badgeHtml) {
                        container.insertAdjacentHTML('beforeend', badgeHtml);
                    }
                }

                if (typeof lucide !== 'undefined') lucide.createIcons();
            })
            .catch(() => {
                sel.innerHTML = '<option value="">– Kunde inte ladda klasser –</option>';
            });
    });
}

function updateRestrictionWarnings() {
    // Remove old warnings
    document.querySelectorAll('.restriction-warning').forEach(el => el.remove());

    if (!selectedRider) return;

    // Check individual activities
    document.querySelectorAll('.pass-booking-item[data-activity-id]').forEach(item => {
        const actId = item.dataset.activityId;
        const msg = checkActivityRestriction(actId, null);
        if (msg) {
            const warn = document.createElement('div');
            warn.className = 'restriction-warning';
            warn.style.cssText = 'font-size: 0.75rem; color: var(--color-warning); padding: var(--space-2xs) 0 0 30px; display: flex; align-items: center; gap: 4px;';
            warn.innerHTML = '<i data-lucide="alert-triangle" style="width: 14px; height: 14px; flex-shrink: 0;"></i> ' + msg;
            item.appendChild(warn);
        }
    });

    // Check group activity options - disable ineligible options
    document.querySelectorAll('.pass-group-activity-select').forEach(sel => {
        for (let i = 1; i < sel.options.length; i++) {
            const opt = sel.options[i];
            const actId = opt.value;
            const optGender = opt.dataset.gender || null;
            const optMinAge = opt.dataset.minAge ? parseInt(opt.dataset.minAge) : null;
            const optMaxAge = opt.dataset.maxAge ? parseInt(opt.dataset.maxAge) : null;
            const msg = checkActivityRestriction(actId, { gender: optGender, min_age: optMinAge, max_age: optMaxAge });
            if (msg) {
                opt.disabled = true;
                if (!opt.textContent.includes('(' + msg + ')')) {
                    opt.textContent = opt.textContent.replace(/\s*\(Ej tillgänglig:.*\)$/, '') + ' (Ej tillgänglig: ' + msg + ')';
                }
            }
        }
    });

    // Check slot options - disable ineligible slots
    document.querySelectorAll('.pass-slot-select').forEach(sel => {
        const actId = sel.dataset.activityId;
        for (let i = 1; i < sel.options.length; i++) {
            const opt = sel.options[i];
            const slotGender = opt.dataset.gender || null;
            const slotMinAge = opt.dataset.minAge ? parseInt(opt.dataset.minAge) : null;
            const slotMaxAge = opt.dataset.maxAge ? parseInt(opt.dataset.maxAge) : null;
            if (slotGender || slotMinAge || slotMaxAge) {
                const msg = checkActivityRestriction(actId, { gender: slotGender, min_age: slotMinAge, max_age: slotMaxAge });
                if (msg && !opt.disabled) {
                    opt.disabled = true;
                    opt.textContent += ' (' + msg + ')';
                }
            }
        }
    });

    if (typeof lucide !== 'undefined') lucide.createIcons();
}

function resetPassForm() {
    // Clear selected rider
    selectedRider = null;
    document.getElementById('passRiderDisplay').style.display = 'none';
    document.getElementById('passRiderSearch').style.display = '';

    // Lock step 2 and 3
    document.getElementById('passConfigCard').style.opacity = '0.5';
    document.getElementById('passConfigCard').style.pointerEvents = 'none';
    document.getElementById('passSummaryCard').style.opacity = '0.5';
    document.getElementById('passSummaryCard').style.pointerEvents = 'none';

    // Reset all selects
    document.querySelectorAll('.pass-slot-select, .pass-class-select, .pass-group-activity-select, .pass-group-slot-select, .pass-product-size-select').forEach(sel => {
        sel.selectedIndex = 0;
    });

    // Reset event class selects to initial state
    document.querySelectorAll('.pass-class-select').forEach(sel => {
        sel.innerHTML = '<option value="">– Välj deltagare först –</option>';
        sel.disabled = true;
    });

    // Remove license badges
    document.querySelectorAll('.pass-license-badge').forEach(el => el.remove());

    // Hide dynamic slot containers
    document.querySelectorAll('.pass-group-slot-container').forEach(el => {
        el.style.display = 'none';
    });

    // Clear summary
    document.getElementById('passSummaryRider').textContent = '';

    // Scroll to step 1
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function addPassToCart() {
    if (!selectedRider) {
        alert('Välj en deltagare först.');
        return;
    }

    const riderId = selectedRider.id;
    const riderName = (selectedRider.firstname || '') + ' ' + (selectedRider.lastname || '');

    // Check activity-level restrictions
    let restrictionError = null;
    document.querySelectorAll('.pass-booking-item[data-activity-id]').forEach(item => {
        const actId = item.dataset.activityId;
        const msg = checkActivityRestriction(actId, null);
        if (msg) restrictionError = msg;
    });
    if (restrictionError) {
        alert('Deltagaren uppfyller inte kraven för en inkluderad aktivitet: ' + restrictionError);
        return;
    }

    // Check duplicate
    const cart = GlobalCart.getCart();
    if (cart.some(ci => ci.type === 'festival_pass' && ci.festival_id === festivalInfo.id && ci.rider_id === riderId)) {
        alert('Festivalpasset för ' + riderName + ' finns redan i kundvagnen.');
        return;
    }

    // 1. Add the pass
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

    // 2. Add selected activity slots
    const selectedSlots = new Set();
    let slotDuplicateError = false;
    document.querySelectorAll('.pass-slot-select').forEach(sel => {
        const slotId = parseInt(sel.value);
        if (!slotId) return;
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
        } catch (e) { /* skip */ }
    });
    if (slotDuplicateError) {
        alert('Du har valt samma tidspass flera gånger. Välj olika tidspass.');
        return;
    }

    // 3. Add auto-included activities (no slots)
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
        } catch (e) { /* skip */ }
    });

    // 4. Add group-selected activities
    const groupSelects = document.querySelectorAll('.pass-group-activity-select');
    const groupPicks = {}; // track picks per group for duplicate check
    let groupError = false;
    groupSelects.forEach(sel => {
        const actId = parseInt(sel.value);
        if (!actId) return;
        const groupId = sel.dataset.groupId;
        if (!groupPicks[groupId]) groupPicks[groupId] = [];
        if (groupPicks[groupId].includes(actId)) {
            groupError = true;
            sel.style.border = '2px solid var(--color-error)';
            return;
        }
        groupPicks[groupId].push(actId);
        sel.style.border = '';

        const opt = sel.options[sel.selectedIndex];
        const actName = opt.dataset.activityName;
        const pickIndex = sel.dataset.pickIndex;

        // Check if this activity has a slot selected
        const slotSel = document.querySelector('.pass-group-slot-select[data-group-id="' + groupId + '"][data-pick-index="' + pickIndex + '"]');
        const slotId = slotSel ? parseInt(slotSel.value) : 0;

        if (slotId) {
            const slotOpt = slotSel.options[slotSel.selectedIndex];
            const slotDate = slotOpt.dataset.date || '';
            const slotTime = slotOpt.dataset.time || '';
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
                    included_in_pass: true,
                    group_id: parseInt(groupId)
                });
            } catch (e) { /* skip */ }
        } else {
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
                    included_in_pass: true,
                    group_id: parseInt(groupId)
                });
            } catch (e) { /* skip */ }
        }
    });
    if (groupError) {
        alert('Du har valt samma aktivitet flera gånger i en grupp. Välj olika aktiviteter.');
        return;
    }

    // 5. Add event classes
    document.querySelectorAll('.pass-class-select').forEach(sel => {
        const classId = parseInt(sel.value);
        if (!classId) return;
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
        } catch (e) { /* skip */ }
    });

    // 6. Add products with size selection
    document.querySelectorAll('.pass-product-size-select').forEach(sel => {
        const sizeId = parseInt(sel.value);
        if (!sizeId) return;
        const productId = parseInt(sel.dataset.productId);
        const productName = sel.dataset.productName;
        const sizeLabel = sel.options[sel.selectedIndex].dataset.label || '';
        try {
            GlobalCart.addItem({
                type: 'festival_product',
                product_id: productId,
                size_id: sizeId,
                festival_id: festivalInfo.id,
                rider_id: riderId,
                rider_name: riderName,
                product_name: productName + (sizeLabel ? ' (' + sizeLabel + ')' : ''),
                festival_name: festivalInfo.name,
                festival_date: festivalInfo.start_date,
                price: 0,
                included_in_pass: true
            });
        } catch (e) { /* skip */ }
    });

    // 7. Add auto-included products (no sizes)
    document.querySelectorAll('.pass-product-auto').forEach(input => {
        const productId = parseInt(input.dataset.productId);
        const productName = input.dataset.productName;
        try {
            GlobalCart.addItem({
                type: 'festival_product',
                product_id: productId,
                festival_id: festivalInfo.id,
                rider_id: riderId,
                rider_name: riderName,
                product_name: productName,
                festival_name: festivalInfo.name,
                festival_date: festivalInfo.start_date,
                price: 0,
                included_in_pass: true
            });
        } catch (e) { /* skip */ }
    });

    // Show success toast
    const toast = document.getElementById('passSuccessToast');
    document.getElementById('passSuccessToastText').textContent = festivalInfo.pass_name + ' för ' + riderName + ' tillagd.';
    toast.style.display = '';
    if (typeof lucide !== 'undefined') lucide.createIcons();

    // Auto-hide toast after 5 seconds
    setTimeout(() => { toast.style.display = 'none'; }, 5000);

    // Reset form for next participant
    resetPassForm();
}
</script>

<?php include __DIR__ . '/../../components/festival-rider-search.php'; ?>

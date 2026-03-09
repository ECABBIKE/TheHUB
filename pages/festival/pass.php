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
        fe.included_in_pass
    FROM festival_events fe
    JOIN events e ON fe.event_id = e.id
    WHERE fe.festival_id = ? AND e.active = 1
    ORDER BY e.date ASC, fe.sort_order ASC
");
$evtStmt->execute([$festivalId]);
$events = $evtStmt->fetchAll(PDO::FETCH_ASSOC);
$includedEvents = array_filter($events, fn($e) => !empty($e['included_in_pass']));

// Load classes for included events
$passEventClasses = [];
try {
    $includedEventIds = array_map(fn($e) => $e['id'], $includedEvents);
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
                $grpActCount = count($grp['activities'] ?? []);
                if ($grpPc > 0 && $grpActCount > 0) {
                    $passIncludes[] = ($grpPc > 1 ? $grpPc . 'x ' : '') . htmlspecialchars($grp['name']) . ($grpActCount > $grpPc ? ' (välj ' . $grpPc . ' av ' . $grpActCount . ')' : '');
                }
            }
            foreach ($includedActivities as $ia) {
                $iaPc = max(1, intval($ia['pass_included_count'] ?? 1));
                $passIncludes[] = ($iaPc > 1 ? $iaPc . 'x ' : '') . htmlspecialchars($ia['name']);
            }
            foreach ($includedEvents as $ie) {
                $passIncludes[] = 'Startavgift ' . htmlspecialchars($ie['name']);
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
                        <div style="font-weight: 600; font-size: 0.95rem; color: var(--color-text-primary);"><?= htmlspecialchars($ia['name']) ?></div>
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
                        <option value="<?= $ga['id'] ?>"
                            data-activity-name="<?= htmlspecialchars($ga['name']) ?>"
                            data-has-slots="<?= !empty($gaSlots) ? '1' : '0' ?>"
                            data-price="<?= (float)$ga['price'] ?>">
                            <?= htmlspecialchars($ga['name']) ?>
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

            <?php foreach ($includedEvents as $ie):
                $ieClasses = $passEventClasses[$ie['id']] ?? [];
            ?>
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

                <?php if (!empty($ieClasses)): ?>
                <div class="pass-booking-item-config">
                    <label style="font-size: 0.75rem; font-weight: 600; color: var(--color-text-muted); display: block; margin-bottom: 2px;">Välj klass:</label>
                    <select class="pass-class-select" data-event-id="<?= $ie['id'] ?>" data-event-name="<?= htmlspecialchars($ie['name']) ?>" style="width: 100%; padding: var(--space-xs) var(--space-sm); border: 1px solid var(--color-border); border-radius: var(--radius-sm); background: var(--color-bg-surface); color: var(--color-text-primary); font-size: 16px; min-height: 44px;">
                        <option value="">– Välj klass –</option>
                        <?php foreach ($ieClasses as $cls): ?>
                        <option value="<?= $cls['id'] ?>"><?= htmlspecialchars($cls['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php else: ?>
                <div style="font-size: 0.75rem; color: var(--color-text-muted); margin-top: var(--space-2xs); padding-left: 30px;">
                    Inga klasser tillgängliga ännu
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>

            <?php if (empty($includedActivities) && empty($includedEvents) && empty($passGroups)): ?>
            <div style="padding: var(--space-lg); text-align: center; color: var(--color-text-muted); font-size: 0.9rem;">
                Inga aktiviteter eller tävlingar ingår i passet ännu.
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

    <!-- Success-meddelande (dolt) -->
    <div id="passSuccessMsg" style="display: none;">
        <div class="card" style="border: 2px solid var(--color-success);">
            <div class="card-body" style="text-align: center; padding: var(--space-xl);">
                <i data-lucide="check-circle" style="width: 48px; height: 48px; color: var(--color-success); margin-bottom: var(--space-md);"></i>
                <h3 style="margin: 0 0 var(--space-xs); font-family: var(--font-heading-secondary);">Tillagd i kundvagnen</h3>
                <p style="color: var(--color-text-secondary); margin: 0 0 var(--space-lg); font-size: 0.9rem;" id="passSuccessText"></p>
                <div style="display: flex; gap: var(--space-sm); justify-content: center; flex-wrap: wrap;">
                    <a href="/festival/<?= $festivalId ?>" class="btn btn-secondary" style="padding: var(--space-sm) var(--space-lg);">
                        <i data-lucide="arrow-left" style="width: 16px; height: 16px;"></i> Tillbaka till festivalen
                    </a>
                    <a href="/cart" class="festival-pass-btn" style="padding: var(--space-sm) var(--space-lg); text-decoration: none;">
                        <i data-lucide="shopping-cart" style="width: 16px; height: 16px;"></i> Till kundvagnen
                    </a>
                </div>
            </div>
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
            let label = dateStr + ' ' + timeStr + endStr;
            if (full) label += ' (Fullbokat)';
            else if (spotsLeft !== null) label += ' (' + spotsLeft + ' platser kvar)';
            html += '<option value="' + s.id + '" data-date="' + dateStr + '" data-time="' + timeStr + '"' + (full ? ' disabled' : '') + '>' + label + '</option>';
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

    if (typeof lucide !== 'undefined') lucide.createIcons();
}

function addPassToCart() {
    if (!selectedRider) {
        alert('Välj en deltagare först.');
        return;
    }

    const riderId = selectedRider.id;
    const riderName = (selectedRider.firstname || '') + ' ' + (selectedRider.lastname || '');

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

    // Show success
    document.getElementById('passSuccessText').textContent = festivalInfo.pass_name + ' för ' + riderName + ' har lagts i kundvagnen.';
    document.querySelectorAll('.card').forEach(c => c.style.display = 'none');
    document.querySelector('nav').style.display = 'none';
    document.querySelector('div[style*="margin-bottom"]').style.display = 'none';
    document.getElementById('passSuccessMsg').style.display = '';
    if (typeof lucide !== 'undefined') lucide.createIcons();

    // Scroll to top
    window.scrollTo({ top: 0, behavior: 'smooth' });
}
</script>

<?php include __DIR__ . '/../../components/festival-rider-search.php'; ?>

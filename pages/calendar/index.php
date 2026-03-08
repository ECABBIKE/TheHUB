<?php
/**
 * TheHUB V1.0 - Kalender
 * Visar kommande event med filter (månad, serie, format)
 */

// Define page type for sponsor placements
if (!defined('HUB_PAGE_TYPE')) {
    define('HUB_PAGE_TYPE', 'calendar');
}

$pdo = hub_db();
$currentUser = hub_current_user();

// Filters
$filterSeries = $_GET['series'] ?? '';
$filterFormat = $_GET['format'] ?? '';

// Get upcoming events with series colors and logo from brand (or event logo)
$sql = "
    SELECT e.*,
           e.is_championship,
           e.logo as event_logo,
           e.end_date,
           e.event_type,
           e.formats,
           s.name as series_name,
           s.id as series_id,
           sb.logo as series_logo,
           sb.accent_color as series_accent,
           v.name as venue_name,
           v.city as venue_city,
           (SELECT COUNT(*) FROM event_registrations er WHERE er.event_id = e.id AND er.status != 'cancelled') as registration_count,
           e.max_participants
    FROM events e
    LEFT JOIN series s ON e.series_id = s.id
    LEFT JOIN series_brands sb ON s.brand_id = sb.id
    LEFT JOIN venues v ON e.venue_id = v.id
    WHERE e.date >= CURDATE() AND e.active = 1
";
$params = [];

if ($filterSeries) {
    $sql .= " AND e.series_id = ?";
    $params[] = $filterSeries;
}

if ($filterFormat) {
    $sql .= " AND e.discipline = ?";
    $params[] = $filterFormat;
}

$sql .= " ORDER BY e.date ASC LIMIT 50";

$events = [];
$seriesList = [];
$formatList = [];

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get series for filter - only series that have upcoming events (color from brand)
    $seriesStmt = $pdo->query("
        SELECT DISTINCT s.id, s.name, sb.accent_color
        FROM series s
        LEFT JOIN series_brands sb ON s.brand_id = sb.id
        INNER JOIN events e ON s.id = e.series_id
        WHERE e.date >= CURDATE() AND e.active = 1 AND s.active = 1
        ORDER BY s.name
    ");
    $seriesList = $seriesStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get formats for filter - only formats that have upcoming events
    $formatStmt = $pdo->query("
        SELECT DISTINCT discipline
        FROM events
        WHERE date >= CURDATE() AND active = 1 AND discipline IS NOT NULL AND discipline != ''
        ORDER BY discipline
    ");
    $formatList = $formatStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Database error - show empty state
    error_log("Calendar index database error: " . $e->getMessage());
}

// Load festivals for admins only - group linked events under festival header
$isAdmin = !empty($_SESSION['admin_role']) && in_array($_SESSION['admin_role'], ['admin', 'super_admin']);
$festivalsByEventId = []; // event_id => festival data
$festivalsById = []; // festival_id => festival data with linked events
if ($isAdmin) {
    try {
        // Load all upcoming festivals
        $festStmt = $pdo->query("
            SELECT f.id, f.name, f.start_date, f.end_date, f.location, f.status,
                f.short_description,
                (SELECT COUNT(*) FROM festival_activities fa WHERE fa.festival_id = f.id AND fa.active = 1) as activity_count
            FROM festivals f
            WHERE f.active = 1 AND f.start_date >= CURDATE()
            ORDER BY f.start_date ASC
        ");
        $allFestivals = $festStmt->fetchAll(PDO::FETCH_ASSOC);

        // Load festival-event links
        foreach ($allFestivals as $fest) {
            $fest['_linked_events'] = [];
            $festivalsById[$fest['id']] = $fest;

            $linkStmt = $pdo->prepare("SELECT event_id FROM festival_events WHERE festival_id = ?");
            $linkStmt->execute([$fest['id']]);
            foreach ($linkStmt->fetchAll(PDO::FETCH_COLUMN) as $eventId) {
                $festivalsByEventId[(int)$eventId] = (int)$fest['id'];
            }
        }
    } catch (PDOException $e) {
        // festivals table might not exist yet
    }
}

// If festival filter is active, only keep events linked to a festival
if ($isAdmin && $filterFormat === 'festival') {
    $events = array_filter($events, fn($e) => isset($festivalsByEventId[(int)$e['id']]));
    $events = array_values($events);
}

// Assign linked events to their festival, track which events to skip as standalone
$festivalEventIds = [];
foreach ($events as $event) {
    $eid = (int)$event['id'];
    if (isset($festivalsByEventId[$eid])) {
        $fid = $festivalsByEventId[$eid];
        if (isset($festivalsById[$fid])) {
            $festivalsById[$fid]['_linked_events'][] = $event;
            $festivalEventIds[$eid] = $fid;
        }
    }
}

// For each festival with linked events, inject a festival placeholder into the events array
// at the date position of the festival's start_date
foreach ($festivalsById as $fid => $fest) {
    if (empty($fest['_linked_events'])) continue;
    $events[] = [
        '_is_festival' => true,
        '_festival_id' => $fid,
        'id' => $fid,
        'date' => $fest['start_date'],
        'end_date' => $fest['end_date'],
    ];
}
// Re-sort by date
usort($events, fn($a, $b) => strcmp($a['date'], $b['date']));

// Format display names
$formatNames = [
    'ENDURO' => 'Enduro',
    'DH' => 'Downhill',
    'XC' => 'XC',
    'XCO' => 'XCO',
    'XCM' => 'XCM',
    'DUAL_SLALOM' => 'Dual Slalom',
    'PUMPTRACK' => 'Pumptrack',
    'GRAVEL' => 'Gravel',
    'E-MTB' => 'E-MTB'
];

// Group events by month
$eventsByMonth = [];
foreach ($events as $event) {
    $month = date('Y-m', strtotime($event['date']));
    $eventsByMonth[$month][] = $event;
}

// Helper function for deadline text
if (!function_exists('getDeadlineInfo')) {
    function getDeadlineInfo($deadline) {
        if (empty($deadline)) {
            return null;
        }

        $now = new DateTime();
        $deadlineDate = new DateTime($deadline);
        $diff = $now->diff($deadlineDate);

        if ($deadlineDate < $now) {
            return ['text' => 'Stängd', 'class' => 'closed', 'days' => -1];
        }

        $days = $diff->days;
        if ($days == 0) {
            return ['text' => 'Sista dagen!', 'class' => 'urgent', 'days' => 0];
        } elseif ($days == 1) {
            return ['text' => '1 dag', 'class' => 'soon', 'days' => 1];
        } elseif ($days <= 7) {
            return ['text' => $days . ' dagar', 'class' => 'soon', 'days' => $days];
        } else {
            return ['text' => $days . ' dagar', 'class' => '', 'days' => $days];
        }
    }
}
?>

<div class="page-header">
    <h1 class="page-title">
        <i data-lucide="calendar" class="page-icon"></i>
        Kalender
    </h1>
    <p class="page-subtitle">Kommande tävlingar och event</p>
</div>

<!-- Global Sponsor: Header Banner -->
<?= render_global_sponsors('calendar', 'header_banner', '') ?>

<!-- Global Sponsor: Content Top -->
<?= render_global_sponsors('calendar', 'content_top', '') ?>

<!-- Filters -->
<div class="filter-bar">
    <div class="filter-group">
        <label for="filter-series" class="filter-label">Serie</label>
        <select id="filter-series" class="filter-select" onchange="applyFilters()">
            <option value="">Alla serier</option>
            <?php foreach ($seriesList as $series): ?>
                <option value="<?= $series['id'] ?>" <?= $filterSeries == $series['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($series['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="filter-group">
        <label for="filter-format" class="filter-label">Format</label>
        <select id="filter-format" class="filter-select" onchange="applyFilters()">
            <option value="">Alla format</option>
            <?php foreach ($formatList as $format): ?>
                <option value="<?= htmlspecialchars($format['discipline']) ?>" <?= $filterFormat == $format['discipline'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($formatNames[$format['discipline']] ?? $format['discipline']) ?>
                </option>
            <?php endforeach; ?>
            <?php if ($isAdmin): ?>
                <option value="festival" <?= $filterFormat === 'festival' ? 'selected' : '' ?>>Festival</option>
            <?php endif; ?>
        </select>
    </div>
</div>

<!-- Events List -->
<div class="calendar-events">
    <?php if (empty($events)): ?>
        <div class="empty-state">
            <div class="empty-icon"><i data-lucide="calendar-x"></i></div>
            <h3>Inga kommande event</h3>
            <p>Det finns inga schemalagda tävlingar just nu.</p>
        </div>
    <?php else: ?>
        <?php foreach ($eventsByMonth as $month => $monthEvents): ?>
            <div class="calendar-month-section">
                <div class="calendar-month-divider">
                    <span class="calendar-month-label"><?= hub_format_month_year($month . '-01') ?></span>
                    <span class="calendar-month-line"></span>
                    <span class="calendar-month-count"><?= count($monthEvents) ?> event</span>
                </div>
                <div class="event-list">
                    <?php foreach ($monthEvents as $event):
                        $isFestival = !empty($event['_is_festival']);

                        // Skip events that belong to a festival (rendered inside festival block)
                        if (!$isFestival && isset($festivalEventIds[(int)$event['id']])) continue;

                        $eventDate = strtotime($event['date']);
                        $eventEndDate = !empty($event['end_date']) ? strtotime($event['end_date']) : null;

                        // Format date - show range for multi-day events
                        if ($eventEndDate && $eventEndDate > $eventDate) {
                            $startDay = date('j', $eventDate);
                            $endDay = date('j', $eventEndDate);
                            $startMonth = hub_month_short($eventDate);
                            $endMonth = hub_month_short($eventEndDate);
                            if ($startMonth === $endMonth) {
                                $dateFormatted = $startDay . '-' . $endDay . ' ' . $startMonth;
                            } else {
                                $dateFormatted = $startDay . ' ' . $startMonth . ' - ' . $endDay . ' ' . $endMonth;
                            }
                            $dayName = hub_day_short($eventDate) . '-' . hub_day_short($eventEndDate);
                        } else {
                            $dateFormatted = date('j', $eventDate) . ' ' . hub_month_short($eventDate);
                            $dayName = hub_day_short($eventDate);
                        }

                        if ($isFestival) {
                            // Festival group block with linked events underneath
                            $fest = $festivalsById[$event['_festival_id']] ?? null;
                            if (!$fest || empty($fest['_linked_events'])) continue;
                            $festStatus = $fest['status'] ?? 'draft';
                            $statusBadge = match($festStatus) {
                                'draft' => '<span class="badge badge-warning" style="font-size:0.65rem;">Utkast</span>',
                                'published' => '',
                                'completed' => '<span class="badge" style="font-size:0.65rem;">Avslutad</span>',
                                'cancelled' => '<span class="badge badge-danger" style="font-size:0.65rem;">Inställd</span>',
                                default => ''
                            };
                        ?>
                        <div class="festival-cal-group">
                            <!-- Festival header -->
                            <a href="/festival/<?= $fest['id'] ?>" class="event-row festival-cal-header" style="--event-accent: var(--color-accent)">
                                <div class="event-accent-bar"></div>
                                <div class="event-logo event-logo-placeholder">
                                    <i data-lucide="tent"></i>
                                </div>
                                <span class="event-date-inline">
                                    <strong><?= $dateFormatted ?></strong>
                                    <span class="event-day-name"><?= $dayName ?></span>
                                </span>
                                <?= $statusBadge ?>
                                <span class="event-festival-badge" title="Festival">
                                    <i data-lucide="sparkles"></i>
                                    Festival
                                </span>
                                <h3 class="event-title"><?= htmlspecialchars($fest['name']) ?></h3>
                                <?php if ($fest['location']): ?>
                                <span class="event-location-inline">
                                    <i data-lucide="map-pin"></i>
                                    <?= htmlspecialchars($fest['location']) ?>
                                </span>
                                <?php endif; ?>
                                <span class="event-registrations">
                                    <?= count($fest['_linked_events']) ?> tävlingar<?php if ($fest['activity_count'] > 0): ?> · <?= $fest['activity_count'] ?> aktiviteter<?php endif; ?>
                                </span>
                                <div class="event-arrow">
                                    <i data-lucide="chevron-right"></i>
                                </div>
                            </a>
                            <!-- Linked competition events -->
                            <?php foreach ($fest['_linked_events'] as $le):
                                $leDate = strtotime($le['date']);
                                $leDateStr = date('j', $leDate) . ' ' . hub_month_short($leDate);
                                $leDayName = hub_day_short($leDate);
                                $leLocation = $le['venue_city'] ?: $le['location'];
                                $leAccent = $le['series_accent'] ?: '#61CE70';
                                $leLogo = !empty($le['event_logo']) ? $le['event_logo'] : ($le['series_logo'] ?? '');
                                $leLogoAlt = !empty($le['event_logo']) ? $le['name'] : ($le['series_name'] ?? $le['name']);
                                $leDeadline = getDeadlineInfo($le['registration_deadline']);
                            ?>
                            <a href="/calendar/<?= $le['id'] ?>" class="event-row festival-cal-sub" style="--event-accent: <?= htmlspecialchars($leAccent) ?>">
                                <div class="event-accent-bar"></div>
                                <?php if ($leLogo): ?>
                                <div class="event-logo">
                                    <img src="<?= htmlspecialchars($leLogo) ?>" alt="<?= htmlspecialchars($leLogoAlt) ?>">
                                </div>
                                <?php else: ?>
                                <div class="event-logo event-logo-placeholder">
                                    <i data-lucide="calendar"></i>
                                </div>
                                <?php endif; ?>
                                <span class="event-date-inline">
                                    <strong><?= $leDateStr ?></strong>
                                    <span class="event-day-name"><?= $leDayName ?></span>
                                </span>
                                <?php if ($le['series_name']): ?>
                                <span class="event-series-badge"><?= htmlspecialchars($le['series_name']) ?></span>
                                <?php endif; ?>
                                <?php if ($le['discipline']): ?>
                                <span class="event-format-badge"><?= htmlspecialchars($formatNames[$le['discipline']] ?? $le['discipline']) ?></span>
                                <?php endif; ?>
                                <h3 class="event-title"><?= htmlspecialchars($le['name']) ?></h3>
                                <?php if ($le['registration_count'] > 0 || !empty($le['max_participants'])): ?>
                                <span class="event-registrations"><?= $le['registration_count'] ?><?php if (!empty($le['max_participants'])): ?>/<?= (int)$le['max_participants'] ?><?php endif; ?> anmälda</span>
                                <?php endif; ?>
                                <?php if ($leDeadline && $leDeadline['days'] >= 0): ?>
                                <span class="event-deadline <?= $leDeadline['class'] ?>">
                                    <i data-lucide="clock"></i>
                                    <?= $leDeadline['text'] ?>
                                </span>
                                <?php endif; ?>
                                <div class="event-arrow">
                                    <i data-lucide="chevron-right"></i>
                                </div>
                            </a>
                            <?php endforeach; ?>
                        </div>

                        <?php } else {
                            // Regular event row
                            $deadlineInfo = getDeadlineInfo($event['registration_deadline']);
                            $location = $event['venue_city'] ?: $event['location'];
                            $accentColor = $event['series_accent'] ?: '#61CE70';
                            $displayLogo = !empty($event['event_logo']) ? $event['event_logo'] : ($event['series_logo'] ?? '');
                            $logoAlt = !empty($event['event_logo']) ? $event['name'] : ($event['series_name'] ?? $event['name']);
                            $isMultiFormat = !empty($event['formats']) && strpos($event['formats'], ',') !== false;
                        ?>
                        <a href="/calendar/<?= $event['id'] ?>" class="event-row" style="--event-accent: <?= htmlspecialchars($accentColor) ?>">
                            <div class="event-accent-bar"></div>

                            <?php if ($displayLogo): ?>
                            <div class="event-logo">
                                <img src="<?= htmlspecialchars($displayLogo) ?>" alt="<?= htmlspecialchars($logoAlt) ?>">
                            </div>
                            <?php else: ?>
                            <div class="event-logo event-logo-placeholder">
                                <i data-lucide="calendar"></i>
                            </div>
                            <?php endif; ?>

                            <span class="event-date-inline">
                                <strong><?= $dateFormatted ?></strong>
                                <span class="event-day-name"><?= $dayName ?></span>
                            </span>

                            <?php if ($event['series_name']): ?>
                            <span class="event-series-badge"><?= htmlspecialchars($event['series_name']) ?></span>
                            <?php endif; ?>

                            <?php if (!empty($event['is_championship'])): ?>
                            <span class="event-sm-badge" title="Svenska Mästerskap">
                                <i data-lucide="medal"></i>
                                SM
                            </span>
                            <?php endif; ?>

                            <?php if ($event['event_type'] === 'festival' || $isMultiFormat): ?>
                            <span class="event-festival-badge" title="Flera format">
                                <i data-lucide="sparkles"></i>
                                Festival
                            </span>
                            <?php endif; ?>

                            <h3 class="event-title"><?= htmlspecialchars($event['name']) ?></h3>

                            <?php if ($location): ?>
                            <span class="event-location-inline">
                                <i data-lucide="map-pin"></i>
                                <?= htmlspecialchars($location) ?>
                            </span>
                            <?php endif; ?>

                            <?php if ($event['registration_count'] > 0 || !empty($event['max_participants'])): ?>
                            <span class="event-registrations"><?= $event['registration_count'] ?><?php if (!empty($event['max_participants'])): ?>/<?= (int)$event['max_participants'] ?><?php endif; ?> anmälda<?php if (!empty($event['max_participants']) && $event['registration_count'] >= $event['max_participants']): ?> <strong style="color: var(--color-error);">(Fullbokat)</strong><?php endif; ?></span>
                            <?php endif; ?>

                            <?php if ($deadlineInfo && $deadlineInfo['days'] >= 0): ?>
                            <span class="event-deadline <?= $deadlineInfo['class'] ?>">
                                <i data-lucide="clock"></i>
                                <span class="deadline-label">Anmälan stänger:</span>
                                <?= $deadlineInfo['text'] ?>
                            </span>
                            <?php endif; ?>

                            <div class="event-arrow">
                                <i data-lucide="chevron-right"></i>
                            </div>
                        </a>
                        <?php } ?>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Global Sponsor: Content Bottom -->
<?= render_global_sponsors('calendar', 'content_bottom', 'Tack till våra partners') ?>

<script>
function applyFilters() {
    const series = document.getElementById('filter-series').value;
    const format = document.getElementById('filter-format').value;
    const params = new URLSearchParams();
    if (series) params.set('series', series);
    if (format) params.set('format', format);
    window.location.href = '/calendar' + (params.toString() ? '?' + params : '');
}
</script>

<?php
/**
 * TheHUB V3.5 - Kalender
 * Visar kommande event med filter (månad, serie, format)
 */

$pdo = hub_db();
$currentUser = hub_current_user();

// Filters
$filterSeries = $_GET['series'] ?? '';

// Get upcoming events with series colors and logo from brand
$sql = "
    SELECT e.*,
           s.name as series_name,
           s.id as series_id,
           sb.logo as series_logo,
           sb.accent_color as series_accent,
           v.name as venue_name,
           v.city as venue_city,
           COUNT(DISTINCT er.id) as registration_count
    FROM events e
    LEFT JOIN series s ON e.series_id = s.id
    LEFT JOIN series_brands sb ON s.brand_id = sb.id
    LEFT JOIN venues v ON e.venue_id = v.id
    LEFT JOIN event_registrations er ON e.id = er.event_id AND er.status != 'cancelled'
    WHERE e.date >= CURDATE() AND e.active = 1
";
$params = [];

if ($filterSeries) {
    $sql .= " AND e.series_id = ?";
    $params[] = $filterSeries;
}

$sql .= " GROUP BY e.id ORDER BY e.date ASC LIMIT 50";

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

<!-- Filters -->
<div class="filters-bar">
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
            <div class="calendar-month">
                <h2 class="calendar-month-title">
                    <?= hub_format_month_year($month . '-01') ?>
                </h2>
                <div class="event-list">
                    <?php foreach ($monthEvents as $event): ?>
                        <?php
                        $eventDate = strtotime($event['date']);
                        $dayName = hub_day_short($eventDate);
                        $dayNum = date('j', $eventDate);
                        $monthShort = strtoupper(hub_month_short($eventDate));
                        $deadlineInfo = getDeadlineInfo($event['registration_deadline']);
                        $location = $event['venue_city'] ?: $event['location'];
                        $accentColor = $event['series_accent'] ?: '#61CE70';
                        $seriesLogo = $event['series_logo'] ?? '';
                        ?>
                        <a href="/calendar/<?= $event['id'] ?>" class="event-row" style="--event-accent: <?= htmlspecialchars($accentColor) ?>">
                            <div class="event-accent-bar"></div>

                            <?php if ($seriesLogo): ?>
                            <div class="event-logo">
                                <img src="<?= htmlspecialchars($seriesLogo) ?>" alt="<?= htmlspecialchars($event['series_name']) ?>">
                            </div>
                            <?php else: ?>
                            <div class="event-logo event-logo-placeholder">
                                <i data-lucide="trophy"></i>
                            </div>
                            <?php endif; ?>

                            <div class="event-date">
                                <span class="event-month-abbr"><?= $monthShort ?></span>
                                <span class="event-day-num"><?= $dayNum ?></span>
                                <span class="event-day-name"><?= $dayName ?></span>
                            </div>

                            <div class="event-main">
                                <h3 class="event-title"><?= htmlspecialchars($event['name']) ?></h3>
                                <div class="event-details">
                                    <?php if ($event['series_name']): ?>
                                        <span class="event-series"><?= htmlspecialchars($event['series_name']) ?></span>
                                    <?php endif; ?>
                                    <?php if ($location): ?>
                                        <span class="event-location">
                                            <i data-lucide="map-pin"></i><?= htmlspecialchars($location) ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="event-stats">
                                <?php if ($event['registration_count'] > 0): ?>
                                <div class="event-stat">
                                    <span class="stat-value"><?= $event['registration_count'] ?></span>
                                    <span class="stat-label">anmälda</span>
                                </div>
                                <?php endif; ?>

                                <?php if ($deadlineInfo && $deadlineInfo['days'] >= 0): ?>
                                <div class="event-stat deadline-stat <?= $deadlineInfo['class'] ?>">
                                    <span class="stat-value"><?= $deadlineInfo['text'] ?></span>
                                    <span class="stat-label">anmälan</span>
                                </div>
                                <?php endif; ?>
                            </div>

                            <div class="event-arrow">
                                <i data-lucide="chevron-right"></i>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<script>
function applyFilters() {
    const series = document.getElementById('filter-series').value;
    const params = new URLSearchParams();
    if (series) params.set('series', series);
    window.location.href = '/calendar' + (params.toString() ? '?' + params : '');
}
</script>

<?php
/**
 * TheHUB V3.5 - Kalender
 * Visar kommande event med filter (månad, serie, format)
 */

$pdo = hub_db();
$currentUser = hub_current_user();

// Filters
$filterMonth = $_GET['month'] ?? date('Y-m');
$filterSeries = $_GET['series'] ?? '';
$filterFormat = $_GET['format'] ?? '';

// Get upcoming events
$sql = "
    SELECT e.*, s.name as series_name, s.id as series_id,
           v.name as venue_name, v.city as venue_city,
           COUNT(DISTINCT er.id) as registration_count
    FROM events e
    LEFT JOIN series s ON e.series_id = s.id
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

// Get series for filter - only series that have upcoming events
$seriesStmt = $pdo->query("
    SELECT DISTINCT s.id, s.name
    FROM series s
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
                <div class="event-cards">
                    <?php foreach ($monthEvents as $event): ?>
                        <?php
                        $eventDate = strtotime($event['date']);
                        $isRegistrationOpen = $event['registration_open'] ?? false;
                        $dayName = hub_day_short($eventDate);
                        $dayNum = date('j', $eventDate);
                        ?>
                        <a href="/calendar/<?= $event['id'] ?>" class="event-card">
                            <div class="event-card-date">
                                <span class="event-day-name"><?= $dayName ?></span>
                                <span class="event-day-num"><?= $dayNum ?></span>
                            </div>
                            <div class="event-card-content">
                                <h3 class="event-card-title"><?= htmlspecialchars($event['name']) ?></h3>
                                <?php if ($event['series_name']): ?>
                                    <span class="event-card-series"><?= htmlspecialchars($event['series_name']) ?></span>
                                <?php endif; ?>
                                <?php if ($event['location']): ?>
                                    <span class="event-card-location">
                                        <i data-lucide="map-pin"></i> <?= htmlspecialchars($event['location']) ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <div class="event-card-meta">
                                <?php if ($isRegistrationOpen): ?>
                                    <span class="event-badge event-badge-open">Anmälan öppen</span>
                                <?php endif; ?>
                                <?php if ($event['registration_count'] > 0): ?>
                                    <span class="event-participants">
                                        <i data-lucide="users"></i> <?= $event['registration_count'] ?>
                                    </span>
                                <?php endif; ?>
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

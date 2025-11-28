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

// Get series for filter
$seriesStmt = $pdo->query("SELECT id, name FROM series WHERE active = 1 ORDER BY name");
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
        <span class="page-icon">📅</span>
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
            <div class="empty-icon">📅</div>
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
                        <a href="/v3/calendar/<?= $event['id'] ?>" class="event-card">
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
                                        📍 <?= htmlspecialchars($event['location']) ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <div class="event-card-meta">
                                <?php if ($isRegistrationOpen): ?>
                                    <span class="event-badge event-badge-open">Anmälan öppen</span>
                                <?php endif; ?>
                                <?php if ($event['registration_count'] > 0): ?>
                                    <span class="event-participants">
                                        👥 <?= $event['registration_count'] ?>
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
    window.location.href = '/v3/calendar' + (params.toString() ? '?' + params : '');
}
</script>

<style>
.calendar-month {
    margin-bottom: var(--space-xl);
}
.calendar-month-title {
    font-size: var(--text-lg);
    font-weight: var(--weight-semibold);
    color: var(--color-text-secondary);
    margin-bottom: var(--space-md);
    text-transform: capitalize;
}
.event-cards {
    display: flex;
    flex-direction: column;
    gap: var(--space-sm);
}
.event-card {
    display: flex;
    align-items: center;
    gap: var(--space-md);
    background: var(--color-bg-card);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-lg);
    padding: var(--space-md);
    text-decoration: none;
    color: inherit;
    transition: all var(--transition-fast);
}
.event-card:hover {
    border-color: var(--color-accent);
    transform: translateX(4px);
}
.event-card-date {
    display: flex;
    flex-direction: column;
    align-items: center;
    min-width: 48px;
    padding: var(--space-sm);
    background: var(--color-accent);
    border-radius: var(--radius-md);
    color: white;
}
.event-day-name {
    font-size: var(--text-xs);
    text-transform: uppercase;
    opacity: 0.9;
}
.event-day-num {
    font-size: var(--text-xl);
    font-weight: var(--weight-bold);
    line-height: 1;
}
.event-card-content {
    flex: 1;
    min-width: 0;
}
.event-card-title {
    font-size: var(--text-md);
    font-weight: var(--weight-semibold);
    margin-bottom: var(--space-2xs);
}
.event-card-series {
    display: block;
    font-size: var(--text-sm);
    color: var(--color-accent);
}
.event-card-location {
    display: block;
    font-size: var(--text-sm);
    color: var(--color-text-secondary);
}
.event-card-meta {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: var(--space-xs);
}
.event-badge {
    font-size: var(--text-xs);
    padding: var(--space-2xs) var(--space-sm);
    border-radius: var(--radius-full);
}
.event-badge-open {
    background: var(--color-success-bg);
    color: var(--color-success);
}
.event-participants {
    font-size: var(--text-sm);
    color: var(--color-text-secondary);
}
.filters-bar {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-md);
    margin-bottom: var(--space-lg);
    padding: var(--space-md);
    background: var(--color-bg-card);
    border-radius: var(--radius-lg);
    border: 1px solid var(--color-border);
}
.filter-group {
    display: flex;
    flex-direction: column;
    gap: var(--space-2xs);
    flex: 1;
    min-width: 140px;
}
.filter-label {
    font-size: var(--text-xs);
    color: var(--color-text-secondary);
    text-transform: uppercase;
    font-weight: var(--weight-medium);
}
.filter-select {
    padding: var(--space-sm) var(--space-md);
    padding-right: var(--space-xl);
    background: var(--color-bg-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    color: var(--color-text-primary);
    font-size: var(--text-sm);
    cursor: pointer;
    appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%236b7280' d='M3 4.5L6 7.5L9 4.5'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 8px center;
    transition: border-color var(--transition-fast);
}
.filter-select:focus {
    outline: none;
    border-color: var(--color-accent);
    box-shadow: 0 0 0 3px rgba(59, 158, 255, 0.1);
}

@media (max-width: 599px) {
    .filters-bar {
        flex-direction: column;
        padding: var(--space-sm);
        gap: var(--space-sm);
    }
    .filter-group {
        width: 100%;
        min-width: 0;
    }
    .filter-select {
        width: 100%;
    }
    .event-card {
        padding: var(--space-sm);
        gap: var(--space-sm);
    }
    .event-card:hover {
        transform: none;
    }
    .event-card-date {
        min-width: 48px;
        padding: var(--space-xs);
    }
    .event-day-num {
        font-size: var(--text-lg);
    }
}
</style>

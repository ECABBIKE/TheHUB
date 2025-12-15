<?php
/**
 * V3.5 Results Page - Shows events with results
 * Matching calendar page design with brand colors
 */

$pdo = hub_db();

// Get filter parameters
$filterSeries = isset($_GET['series']) && is_numeric($_GET['series']) ? intval($_GET['series']) : null;
$filterYear = isset($_GET['year']) && is_numeric($_GET['year']) ? intval($_GET['year']) : null;

try {
    // Get years that have results (for filter dropdown)
    $yearsStmt = $pdo->query("
        SELECT DISTINCT YEAR(e.date) as year
        FROM events e
        INNER JOIN results r ON e.id = r.event_id
        WHERE e.date IS NOT NULL
        ORDER BY year DESC
    ");
    $yearsList = $yearsStmt->fetchAll(PDO::FETCH_COLUMN);

    // Get series for filter - only series that have results (with brand info)
    $seriesStmt = $pdo->query("
        SELECT DISTINCT s.id, s.name, sb.accent_color
        FROM series s
        LEFT JOIN series_brands sb ON s.brand_id = sb.id
        INNER JOIN events e ON s.id = e.series_id
        INNER JOIN results r ON e.id = r.event_id
        WHERE s.active = 1
        ORDER BY s.name
    ");
    $seriesList = $seriesStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get events with results, including brand colors and logo
    $sql = "
        SELECT e.id, e.name, e.date, e.location,
               s.id as series_id, s.name as series_name,
               sb.logo as series_logo,
               sb.accent_color as series_accent,
               v.name as venue_name, v.city as venue_city,
               COUNT(DISTINCT r.id) as result_count,
               COUNT(DISTINCT r.cyclist_id) as rider_count
        FROM events e
        LEFT JOIN series s ON e.series_id = s.id
        LEFT JOIN series_brands sb ON s.brand_id = sb.id
        LEFT JOIN venues v ON e.venue_id = v.id
        INNER JOIN results r ON e.id = r.event_id
        WHERE 1=1
    ";
    $params = [];

    if ($filterSeries) {
        $sql .= " AND e.series_id = ?";
        $params[] = $filterSeries;
    }

    if ($filterYear) {
        $sql .= " AND YEAR(e.date) = ?";
        $params[] = $filterYear;
    }

    $sql .= " GROUP BY e.id ORDER BY e.date DESC LIMIT 100";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Group events by year (descending)
    $eventsByYear = [];
    foreach ($events as $event) {
        $year = $event['date'] ? date('Y', strtotime($event['date'])) : 'Okänt';
        $eventsByYear[$year][] = $event;
    }
    // Sort years descending
    krsort($eventsByYear);

    $totalEvents = count($events);

} catch (Exception $e) {
    $events = [];
    $seriesList = [];
    $yearsList = [];
    $eventsByYear = [];
    $totalEvents = 0;
    $error = $e->getMessage();
}
?>

<div class="page-header">
    <h1 class="page-title">
        <i data-lucide="trophy" class="page-icon"></i>
        Resultat
    </h1>
    <p class="page-subtitle"><?= $totalEvents ?> tävlingar med publicerade resultat</p>
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
    <div class="filter-group">
        <label for="filter-year" class="filter-label">År</label>
        <select id="filter-year" class="filter-select" onchange="applyFilters()">
            <option value="">Alla år</option>
            <?php foreach ($yearsList as $year): ?>
                <option value="<?= $year ?>" <?= $filterYear == $year ? 'selected' : '' ?>>
                    <?= $year ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
</div>

<?php if (isset($error)): ?>
<div class="alert alert-error">
    <i data-lucide="alert-circle"></i>
    <?= htmlspecialchars($error) ?>
</div>
<?php endif; ?>

<!-- Events List -->
<div class="calendar-events">
    <?php if (empty($events)): ?>
        <div class="empty-state">
            <div class="empty-icon"><i data-lucide="trophy"></i></div>
            <h3>Inga resultat hittades</h3>
            <p>Det finns inga publicerade resultat just nu.</p>
        </div>
    <?php else: ?>
        <?php foreach ($eventsByYear as $year => $yearEvents): ?>
            <div class="calendar-month">
                <h2 class="calendar-month-title"><?= $year ?></h2>
                <div class="event-list">
                    <?php foreach ($yearEvents as $event): ?>
                        <?php
                        $eventDate = $event['date'] ? strtotime($event['date']) : null;
                        $dayName = $eventDate ? hub_day_short($eventDate) : '';
                        $dayNum = $eventDate ? date('j', $eventDate) : '-';
                        $monthShort = $eventDate ? strtoupper(hub_month_short($eventDate)) : '';
                        $location = $event['venue_city'] ?: $event['location'];
                        $accentColor = $event['series_accent'] ?: '#61CE70';
                        $seriesLogo = $event['series_logo'] ?? '';
                        ?>
                        <a href="/event/<?= $event['id'] ?>" class="event-row" style="--event-accent: <?= htmlspecialchars($accentColor) ?>">
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
                                <div class="event-stat">
                                    <span class="stat-value"><?= $event['rider_count'] ?></span>
                                    <span class="stat-label">deltagare</span>
                                </div>
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
    const year = document.getElementById('filter-year').value;
    const params = new URLSearchParams();
    if (series) params.set('series', series);
    if (year) params.set('year', year);
    window.location.href = '/results' + (params.toString() ? '?' + params : '');
}
</script>

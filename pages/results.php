<?php
/**
 * V3 Results Page - Shows events with results
 * Updated to support series_brands for better organization
 */

$db = hub_db();

// Check if series_brands table exists
$brandsTableExists = false;
try {
    $check = $db->query("SHOW TABLES LIKE 'series_brands'");
    $brandsTableExists = $check->rowCount() > 0;
} catch (Exception $e) {
    $brandsTableExists = false;
}

// Check if brand_id column exists in series
$brandColumnExists = false;
if ($brandsTableExists) {
    try {
        $check = $db->query("SHOW COLUMNS FROM series LIKE 'brand_id'");
        $brandColumnExists = $check->rowCount() > 0;
    } catch (Exception $e) {
        $brandColumnExists = false;
    }
}

// Get filter parameters
$filterBrand = isset($_GET['brand']) && is_numeric($_GET['brand']) ? intval($_GET['brand']) : null;
$filterSeries = isset($_GET['series']) && is_numeric($_GET['series']) ? intval($_GET['series']) : null;
$filterYear = isset($_GET['year']) && is_numeric($_GET['year']) ? intval($_GET['year']) : null;

try {
    // Build query for events with results
    $where = ["1=1"];
    $params = [];

    // Filter by brand (via series.brand_id)
    if ($filterBrand && $brandColumnExists) {
        $where[] = "s.brand_id = ?";
        $params[] = $filterBrand;
    }

    if ($filterSeries) {
        $where[] = "e.series_id = ?";
        $params[] = $filterSeries;
    }
    if ($filterYear) {
        $where[] = "YEAR(e.date) = ?";
        $params[] = $filterYear;
    }

    $whereClause = 'WHERE ' . implode(' AND ', $where);

    // Get events with result counts
    $sql = "SELECT
        e.id, e.name, e.date, e.location,
        s.id as series_id, s.name as series_name,
        s.gradient_start, s.gradient_end,
        COUNT(DISTINCT r.id) as result_count,
        COUNT(DISTINCT r.cyclist_id) as rider_count
    FROM events e
    LEFT JOIN series s ON e.series_id = s.id
    LEFT JOIN results r ON e.id = r.event_id
    {$whereClause}
    GROUP BY e.id
    HAVING result_count > 0
    ORDER BY e.date DESC
    LIMIT 100";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get brands for filter (if table exists)
    $allBrands = [];
    if ($brandsTableExists && $brandColumnExists) {
        $allBrands = $db->query("
            SELECT DISTINCT sb.id, sb.name
            FROM series_brands sb
            INNER JOIN series s ON sb.id = s.brand_id
            INNER JOIN events e ON s.id = e.series_id
            INNER JOIN results r ON e.id = r.event_id
            WHERE sb.active = 1
            ORDER BY sb.display_order ASC, sb.name ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
    }

    // Get series for filter (optionally filtered by brand)
    if ($filterBrand && $brandColumnExists) {
        $seriesStmt = $db->prepare("
            SELECT DISTINCT s.id, s.name, s.year
            FROM series s
            INNER JOIN events e ON s.id = e.series_id
            INNER JOIN results r ON e.id = r.event_id
            WHERE s.status IN ('active', 'completed') AND s.brand_id = ?
            ORDER BY s.year DESC, s.name
        ");
        $seriesStmt->execute([$filterBrand]);
        $allSeries = $seriesStmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $allSeries = $db->query("
            SELECT DISTINCT s.id, s.name, s.year
            FROM series s
            INNER JOIN events e ON s.id = e.series_id
            INNER JOIN results r ON e.id = r.event_id
            WHERE s.status IN ('active', 'completed')
            ORDER BY s.name
        ")->fetchAll(PDO::FETCH_ASSOC);
    }

    // Get years for filter (optionally filtered by brand)
    if ($filterBrand && $brandColumnExists) {
        $yearsStmt = $db->prepare("
            SELECT DISTINCT YEAR(e.date) as year
            FROM events e
            INNER JOIN series s ON e.series_id = s.id
            INNER JOIN results r ON e.id = r.event_id
            WHERE e.date IS NOT NULL AND s.brand_id = ?
            ORDER BY year DESC
        ");
        $yearsStmt->execute([$filterBrand]);
        $allYears = $yearsStmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $allYears = $db->query("
            SELECT DISTINCT YEAR(e.date) as year
            FROM events e
            INNER JOIN results r ON e.id = r.event_id
            WHERE e.date IS NOT NULL
            ORDER BY year DESC
        ")->fetchAll(PDO::FETCH_ASSOC);
    }

    $totalEvents = count($events);

} catch (Exception $e) {
    $events = [];
    $allBrands = [];
    $allSeries = [];
    $allYears = [];
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
  <?php if (!empty($allBrands)): ?>
  <div class="filter-group">
    <label class="filter-label">Tävlingsserie</label>
    <select class="filter-select" onchange="window.location=this.value">
      <option value="/results">Alla serier</option>
      <?php foreach ($allBrands as $b): ?>
      <option value="/results?brand=<?= $b['id'] ?>" <?= $filterBrand == $b['id'] ? 'selected' : '' ?>>
        <?= htmlspecialchars($b['name']) ?>
      </option>
      <?php endforeach; ?>
    </select>
  </div>

  <?php if (!empty($allYears)): ?>
  <div class="filter-group">
    <label class="filter-label">År</label>
    <select class="filter-select" onchange="window.location=this.value">
      <option value="/results<?= $filterBrand ? '?brand=' . $filterBrand : '' ?>">Alla år</option>
      <?php foreach ($allYears as $y): ?>
      <option value="/results?<?= $filterBrand ? 'brand=' . $filterBrand . '&' : '' ?>year=<?= $y['year'] ?>" <?= $filterYear == $y['year'] ? 'selected' : '' ?>>
        <?= $y['year'] ?>
      </option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php endif; ?>

  <?php else: ?>
  <!-- Fallback: Show old series dropdown when no brands exist -->
  <div class="filter-group">
    <label class="filter-label">Serie</label>
    <select class="filter-select" onchange="window.location=this.value">
      <option value="/results<?= $filterYear ? '?year=' . $filterYear : '' ?>">Alla serier</option>
      <?php foreach ($allSeries as $s): ?>
      <option value="/results?series=<?= $s['id'] ?><?= $filterYear ? '&year=' . $filterYear : '' ?>" <?= $filterSeries == $s['id'] ? 'selected' : '' ?>>
        <?= htmlspecialchars($s['name']) ?><?= !empty($s['year']) ? ' (' . $s['year'] . ')' : '' ?>
      </option>
      <?php endforeach; ?>
    </select>
  </div>

  <?php if (!empty($allYears)): ?>
  <div class="filter-group">
    <label class="filter-label">År</label>
    <select class="filter-select" onchange="window.location=this.value">
      <option value="/results<?= $filterSeries ? '?series=' . $filterSeries : '' ?>">Alla år</option>
      <?php foreach ($allYears as $y): ?>
      <option value="/results?<?= $filterSeries ? 'series=' . $filterSeries . '&' : '' ?>year=<?= $y['year'] ?>" <?= $filterYear == $y['year'] ? 'selected' : '' ?>>
        <?= $y['year'] ?>
      </option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>

<?php if (isset($error)): ?>
<section class="card mb-lg">
  <div class="card-title" style="color: var(--color-error)">Fel</div>
  <p><?= htmlspecialchars($error) ?></p>
</section>
<?php endif; ?>

<?php if (empty($events)): ?>
<section class="card">
  <div class="empty-state">
    <div class="empty-state-icon">🏁</div>
    <p>Inga resultat hittades</p>
  </div>
</section>
<?php else: ?>

<section class="card">
  <div class="events-list">
    <?php
    $currentYear = null;
    foreach ($events as $event):
      $eventYear = $event['date'] ? date('Y', strtotime($event['date'])) : null;

      // Show year divider when year changes
      if ($eventYear && $eventYear !== $currentYear):
        $currentYear = $eventYear;
    ?>
    <div class="year-divider">
      <span class="year-divider-line"></span>
      <span class="year-divider-label"><?= $eventYear ?></span>
      <span class="year-divider-line"></span>
    </div>
    <?php endif; ?>
    <a href="/event/<?= $event['id'] ?>" class="event-row">
      <?php
        $gradientStart = $event['gradient_start'] ?? '#004A98';
        $gradientEnd = $event['gradient_end'] ?? '#002a5c';
        $dateStyle = "background: linear-gradient(135deg, {$gradientStart}, {$gradientEnd});";
      ?>
      <div class="event-date-col" style="<?= $dateStyle ?>">
        <?php if ($event['date']): ?>
          <span class="event-day"><?= date('j', strtotime($event['date'])) ?></span>
          <span class="event-month"><?= date('M', strtotime($event['date'])) ?></span>
        <?php else: ?>
          <span class="event-day">-</span>
        <?php endif; ?>
      </div>
      <div class="event-main">
        <div class="event-name"><?= htmlspecialchars($event['name']) ?></div>
        <div class="event-details">
          <?php if ($event['series_name']): ?>
            <span class="event-series"><?= htmlspecialchars($event['series_name']) ?></span>
          <?php endif; ?>
          <?php if ($event['location']): ?>
            <span class="event-location"><?= htmlspecialchars($event['location']) ?></span>
          <?php endif; ?>
        </div>
      </div>
      <div class="event-stats">
        <span class="stat-value"><?= $event['rider_count'] ?></span>
        <span class="stat-label">deltagare</span>
      </div>
      <div class="event-arrow">→</div>
    </a>
    <?php endforeach; ?>
  </div>
</section>

<?php endif; ?>
<!-- CSS loaded from /assets/css/pages/results-index.css -->

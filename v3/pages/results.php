<?php
/**
 * V3 Results Page - Events with results
 */

$db = hub_db();

// Get filter parameters
$filterSeries = isset($_GET['series']) && is_numeric($_GET['series']) ? intval($_GET['series']) : null;
$filterYear = isset($_GET['year']) && is_numeric($_GET['year']) ? intval($_GET['year']) : null;

// Build query
$where = [];
$params = [];

if ($filterSeries) {
    $where[] = "e.series_id = ?";
    $params[] = $filterSeries;
}
if ($filterYear) {
    $where[] = "YEAR(e.date) = ?";
    $params[] = $filterYear;
}

$whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// Get events with results
$sql = "SELECT
    e.id, e.name, e.date, e.location, e.status,
    s.name as series_name, s.id as series_id,
    COUNT(DISTINCT r.id) as result_count,
    COUNT(DISTINCT r.cyclist_id) as rider_count
FROM events e
LEFT JOIN results r ON e.id = r.event_id
LEFT JOIN series s ON e.series_id = s.id
{$whereClause}
GROUP BY e.id
HAVING result_count > 0
ORDER BY e.date DESC
LIMIT 50";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$events = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get series for filter
$allSeries = $db->query("
    SELECT DISTINCT s.id, s.name
    FROM series s
    INNER JOIN events e ON s.id = e.series_id
    INNER JOIN results r ON e.id = r.event_id
    WHERE s.status = 'active'
    ORDER BY s.name
")->fetchAll(PDO::FETCH_ASSOC);

// Get years for filter
$allYears = $db->query("
    SELECT DISTINCT YEAR(e.date) as year
    FROM events e
    INNER JOIN results r ON e.id = r.event_id
    WHERE e.date IS NOT NULL
    ORDER BY year DESC
")->fetchAll(PDO::FETCH_ASSOC);

$total = count($events);
?>

<h1 class="text-2xl font-bold mb-lg">Resultat</h1>

<div class="page-grid">
  <!-- Filters -->
  <section class="card grid-full">
    <div class="flex flex-wrap gap-sm items-center">
      <span class="text-secondary text-sm">Filter:</span>
      <a href="/v3/results" class="btn <?= !$filterSeries && !$filterYear ? 'btn--primary' : 'btn--secondary' ?> text-sm">Alla</a>
      <?php foreach ($allSeries as $s): ?>
      <a href="/v3/results?series=<?= $s['id'] ?>" class="btn <?= $filterSeries == $s['id'] ? 'btn--primary' : 'btn--secondary' ?> text-sm">
        <?= htmlspecialchars($s['name']) ?>
      </a>
      <?php endforeach; ?>
    </div>
    <?php if (!empty($allYears)): ?>
    <div class="flex flex-wrap gap-sm items-center mt-sm">
      <span class="text-secondary text-sm">År:</span>
      <?php foreach (array_slice($allYears, 0, 5) as $y): ?>
      <a href="/v3/results?year=<?= $y['year'] ?>" class="btn <?= $filterYear == $y['year'] ? 'btn--primary' : 'btn--ghost' ?> text-sm">
        <?= $y['year'] ?>
      </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </section>

  <!-- Events List -->
  <section class="card grid-full" aria-labelledby="events-title">
    <div class="card-header">
      <div>
        <h2 id="events-title" class="card-title">Event med resultat</h2>
        <p class="card-subtitle"><?= $total ?> event</p>
      </div>
    </div>

    <?php if (empty($events)): ?>
    <div class="text-center p-lg">
      <p class="text-muted">Inga event hittades med dessa filter</p>
    </div>
    <?php else: ?>
    <div class="table-wrapper">
      <table class="table table--striped table--clickable">
        <thead>
          <tr>
            <th scope="col">Event</th>
            <th scope="col" class="table-col-hide-portrait">Serie</th>
            <th scope="col">Datum</th>
            <th scope="col" class="text-right">Resultat</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($events as $event): ?>
          <tr data-href="/v3/event/<?= $event['id'] ?>">
            <td class="col-rider"><?= htmlspecialchars($event['name']) ?></td>
            <td class="table-col-hide-portrait text-secondary"><?= htmlspecialchars($event['series_name'] ?? '-') ?></td>
            <td><?= $event['date'] ? date('j M Y', strtotime($event['date'])) : '-' ?></td>
            <td class="text-right">
              <span class="chip chip--success"><?= $event['result_count'] ?></span>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- Mobile Card View -->
    <div class="result-list">
      <?php foreach ($events as $event): ?>
      <a href="/v3/event/<?= $event['id'] ?>" class="result-item">
        <div class="result-place"><?= $event['result_count'] ?></div>
        <div class="result-info">
          <div class="result-name"><?= htmlspecialchars($event['name']) ?></div>
          <div class="result-club"><?= htmlspecialchars($event['series_name'] ?? '') ?> • <?= $event['date'] ? date('j M Y', strtotime($event['date'])) : '' ?></div>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </section>
</div>

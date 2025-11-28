<?php
/**
 * V3 Dashboard - Overview with real data
 */

$db = hub_db();

// Get overall stats
$stats = $db->query("
    SELECT
        (SELECT COUNT(*) FROM riders WHERE active = 1) as total_riders,
        (SELECT COUNT(*) FROM clubs WHERE active = 1) as total_clubs,
        (SELECT COUNT(*) FROM events WHERE status = 'completed') as total_events,
        (SELECT COUNT(*) FROM results) as total_results
")->fetch(PDO::FETCH_ASSOC);

// Get latest event with results
$latestEvent = $db->query("
    SELECT e.id, e.name, e.date, e.location, s.name as series_name,
           COUNT(DISTINCT r.id) as result_count
    FROM events e
    LEFT JOIN series s ON e.series_id = s.id
    INNER JOIN results r ON e.id = r.event_id
    GROUP BY e.id
    ORDER BY e.date DESC
    LIMIT 1
")->fetch(PDO::FETCH_ASSOC);

// Get top results from latest event
$topResults = [];
if ($latestEvent) {
    $topResults = $db->query("
        SELECT
            r.id, r.finish_time, r.status,
            c.id as rider_id, c.firstname, c.lastname,
            cl.name as club_name,
            cls.display_name as class_name
        FROM results r
        JOIN riders c ON r.cyclist_id = c.id
        LEFT JOIN clubs cl ON c.club_id = cl.id
        LEFT JOIN classes cls ON r.class_id = cls.id
        WHERE r.event_id = ? AND r.status = 'finished'
        ORDER BY r.finish_time ASC
        LIMIT 10
    ", [$latestEvent['id']])->fetchAll(PDO::FETCH_ASSOC);
}

// Get active series
$activeSeries = $db->query("
    SELECT s.id, s.name, s.year,
           COUNT(DISTINCT e.id) as event_count
    FROM series s
    LEFT JOIN events e ON s.id = e.series_id
    WHERE s.status = 'active'
    GROUP BY s.id
    ORDER BY s.year DESC
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

// Get top riders by results count
$topRiders = $db->query("
    SELECT
        c.id, c.firstname, c.lastname,
        cl.name as club_name,
        COUNT(r.id) as race_count,
        SUM(CASE WHEN r.position <= 3 THEN 1 ELSE 0 END) as podiums
    FROM riders c
    LEFT JOIN clubs cl ON c.club_id = cl.id
    INNER JOIN results r ON c.id = r.cyclist_id
    WHERE c.active = 1
    GROUP BY c.id
    ORDER BY race_count DESC
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);
?>

<h1 class="sr-only">Dashboard</h1>
<div class="page-grid">
  <!-- Stats Overview -->
  <section class="card grid-full" aria-labelledby="stats-title">
    <div class="card-header">
      <h2 id="stats-title" class="card-title">Översikt</h2>
    </div>
    <div class="stats-row">
      <div class="stat-block">
        <div class="stat-value"><?= number_format($stats['total_riders']) ?></div>
        <div class="stat-label">Åkare</div>
      </div>
      <div class="stat-block">
        <div class="stat-value"><?= number_format($stats['total_clubs']) ?></div>
        <div class="stat-label">Klubbar</div>
      </div>
      <div class="stat-block">
        <div class="stat-value"><?= number_format($stats['total_events']) ?></div>
        <div class="stat-label">Genomförda</div>
      </div>
      <div class="stat-block">
        <div class="stat-value"><?= number_format($stats['total_results']) ?></div>
        <div class="stat-label">Resultat</div>
      </div>
    </div>
  </section>

  <?php if ($latestEvent): ?>
  <!-- Latest Event -->
  <section class="card" aria-labelledby="event-title">
    <div class="card-header">
      <div>
        <h2 id="event-title" class="card-title">Senaste event</h2>
        <p class="card-subtitle"><?= htmlspecialchars($latestEvent['series_name'] ?? 'Event') ?></p>
      </div>
      <a href="/v3/event/<?= $latestEvent['id'] ?>" class="btn btn--ghost text-sm">Visa →</a>
    </div>
    <div class="card-body">
      <p><strong><?= htmlspecialchars($latestEvent['name']) ?></strong></p>
      <p class="mt-xs text-secondary"><?= htmlspecialchars($latestEvent['location'] ?? '') ?></p>
      <p class="mt-xs text-muted"><?= date('j M Y', strtotime($latestEvent['date'])) ?></p>
      <div class="flex gap-xs mt-md">
        <span class="chip chip--success"><?= $latestEvent['result_count'] ?> resultat</span>
      </div>
    </div>
  </section>
  <?php endif; ?>

  <!-- Active Series -->
  <section class="card" aria-labelledby="series-title">
    <div class="card-header">
      <div>
        <h2 id="series-title" class="card-title">Aktiva serier</h2>
        <p class="card-subtitle"><?= count($activeSeries) ?> serier</p>
      </div>
      <a href="/v3/series" class="btn btn--ghost text-sm">Alla →</a>
    </div>
    <?php if (empty($activeSeries)): ?>
    <p class="text-muted">Inga aktiva serier</p>
    <?php else: ?>
    <div class="table-wrapper">
      <table class="table table--compact">
        <thead>
          <tr>
            <th scope="col">Serie</th>
            <th scope="col" class="text-right">År</th>
            <th scope="col" class="text-right">Event</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($activeSeries as $series): ?>
          <tr>
            <td><?= htmlspecialchars($series['name']) ?></td>
            <td class="text-right"><?= $series['year'] ?></td>
            <td class="text-right"><?= $series['event_count'] ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </section>

  <?php if (!empty($topResults)): ?>
  <!-- Top Results from Latest Event -->
  <section class="card grid-full" aria-labelledby="results-title">
    <div class="card-header">
      <div>
        <h2 id="results-title" class="card-title">Senaste resultat</h2>
        <p class="card-subtitle"><?= htmlspecialchars($latestEvent['name']) ?> - Top 10</p>
      </div>
      <a href="/v3/event/<?= $latestEvent['id'] ?>" class="btn btn--ghost text-sm">Alla →</a>
    </div>
    <div class="table-wrapper">
      <table class="table table--striped table--clickable">
        <thead>
          <tr>
            <th class="col-place" scope="col">#</th>
            <th class="col-rider" scope="col">Åkare</th>
            <th class="col-club table-col-hide-portrait" scope="col">Klubb</th>
            <th scope="col" class="table-col-hide-portrait">Klass</th>
            <th class="col-time" scope="col">Tid</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($topResults as $i => $result):
            $place = $i + 1;
            $placeClass = $place <= 3 ? "col-place--{$place}" : '';
          ?>
          <tr data-href="/v3/rider/<?= $result['rider_id'] ?>">
            <td class="col-place <?= $placeClass ?>"><?= $place ?></td>
            <td class="col-rider"><?= htmlspecialchars($result['firstname'] . ' ' . $result['lastname']) ?></td>
            <td class="col-club table-col-hide-portrait"><?= htmlspecialchars($result['club_name'] ?? '-') ?></td>
            <td class="table-col-hide-portrait"><?= htmlspecialchars($result['class_name'] ?? '-') ?></td>
            <td class="col-time"><?= htmlspecialchars($result['finish_time'] ?? '-') ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
  <?php endif; ?>

  <!-- Top Riders -->
  <section class="card" aria-labelledby="riders-title">
    <div class="card-header">
      <div>
        <h2 id="riders-title" class="card-title">Mest aktiva åkare</h2>
        <p class="card-subtitle">Top 5</p>
      </div>
      <a href="/v3/riders" class="btn btn--ghost text-sm">Alla →</a>
    </div>
    <?php if (empty($topRiders)): ?>
    <p class="text-muted">Inga åkare med resultat</p>
    <?php else: ?>
    <div class="table-wrapper">
      <table class="table table--compact table--clickable">
        <thead>
          <tr>
            <th scope="col">Åkare</th>
            <th scope="col" class="text-right">Starter</th>
            <th scope="col" class="text-right">Pall</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($topRiders as $rider): ?>
          <tr data-href="/v3/rider/<?= $rider['id'] ?>">
            <td><?= htmlspecialchars($rider['firstname'] . ' ' . $rider['lastname']) ?></td>
            <td class="text-right"><?= $rider['race_count'] ?></td>
            <td class="text-right"><?= $rider['podiums'] ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </section>
</div>

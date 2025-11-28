<?php
/**
 * V3 Single Event Page - Event results grouped by class
 */

$db = hub_db();
$eventId = intval($pageInfo['params']['id'] ?? 0);

if (!$eventId) {
    header('Location: /v3/results');
    exit;
}

// Fetch event details
$stmt = $db->prepare("
    SELECT
        e.*,
        s.name as series_name, s.id as series_id,
        v.name as venue_name, v.city as venue_city
    FROM events e
    LEFT JOIN series s ON e.series_id = s.id
    LEFT JOIN venues v ON e.venue_id = v.id
    WHERE e.id = ?
");
$stmt->execute([$eventId]);
$event = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$event) {
    include HUB_V3_ROOT . '/pages/404.php';
    return;
}

// Fetch all results for this event, grouped by class
$stmt = $db->prepare("
    SELECT
        r.id, r.finish_time, r.status, r.points,
        c.id as rider_id, c.firstname, c.lastname,
        cl.name as club_name,
        cls.id as class_id, cls.name as class_name, cls.display_name as class_display_name
    FROM results r
    JOIN riders c ON r.cyclist_id = c.id
    LEFT JOIN clubs cl ON c.club_id = cl.id
    LEFT JOIN classes cls ON r.class_id = cls.id
    WHERE r.event_id = ?
    ORDER BY cls.sort_order ASC, cls.name ASC, r.finish_time ASC
");
$stmt->execute([$eventId]);
$allResults = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group results by class
$resultsByClass = [];
foreach ($allResults as $result) {
    $className = $result['class_display_name'] ?? $result['class_name'] ?? 'Övrigt';
    if (!isset($resultsByClass[$className])) {
        $resultsByClass[$className] = [];
    }
    $resultsByClass[$className][] = $result;
}

$totalResults = count($allResults);
$totalClasses = count($resultsByClass);
?>

<h1 class="text-2xl font-bold mb-lg"><?= htmlspecialchars($event['name']) ?></h1>

<div class="page-grid">
  <!-- Event Info -->
  <section class="card" aria-labelledby="event-info-title">
    <h2 id="event-info-title" class="card-title">Event-information</h2>
    <div class="card-body">
      <?php if ($event['series_name']): ?>
      <p><strong>Serie:</strong> <?= htmlspecialchars($event['series_name']) ?></p>
      <?php endif; ?>
      <?php if ($event['date']): ?>
      <p class="mt-xs"><strong>Datum:</strong> <?= date('j F Y', strtotime($event['date'])) ?></p>
      <?php endif; ?>
      <?php if ($event['location']): ?>
      <p class="mt-xs"><strong>Plats:</strong> <?= htmlspecialchars($event['location']) ?></p>
      <?php endif; ?>
      <?php if ($event['venue_name']): ?>
      <p class="mt-xs"><strong>Arena:</strong> <?= htmlspecialchars($event['venue_name']) ?><?= $event['venue_city'] ? ', ' . htmlspecialchars($event['venue_city']) : '' ?></p>
      <?php endif; ?>
    </div>
  </section>

  <!-- Stats -->
  <section class="card" aria-labelledby="event-stats-title">
    <h2 id="event-stats-title" class="card-title">Statistik</h2>
    <div class="stats-row">
      <div class="stat-block">
        <div class="stat-value"><?= $totalResults ?></div>
        <div class="stat-label">Deltagare</div>
      </div>
      <div class="stat-block">
        <div class="stat-value"><?= $totalClasses ?></div>
        <div class="stat-label">Klasser</div>
      </div>
    </div>
  </section>

  <?php if (empty($resultsByClass)): ?>
  <section class="card grid-full">
    <div class="text-center p-lg">
      <p class="text-muted">Inga resultat registrerade för detta event</p>
    </div>
  </section>
  <?php else: ?>
    <?php foreach ($resultsByClass as $className => $classResults): ?>
    <section class="card grid-full" aria-labelledby="class-<?= md5($className) ?>">
      <div class="card-header">
        <div>
          <h2 id="class-<?= md5($className) ?>" class="card-title"><?= htmlspecialchars($className) ?></h2>
          <p class="card-subtitle"><?= count($classResults) ?> deltagare</p>
        </div>
      </div>

      <div class="table-wrapper">
        <table class="table table--striped table--clickable">
          <thead>
            <tr>
              <th class="col-place" scope="col">#</th>
              <th class="col-rider" scope="col">Åkare</th>
              <th class="col-club table-col-hide-portrait" scope="col">Klubb</th>
              <th class="col-time" scope="col">Tid</th>
              <th class="col-points table-col-hide-portrait" scope="col">Poäng</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($classResults as $i => $result):
              $place = $i + 1;
              $placeClass = $place <= 3 ? "col-place--{$place}" : '';
            ?>
            <tr data-href="/v3/rider/<?= $result['rider_id'] ?>">
              <td class="col-place <?= $placeClass ?>"><?= $place ?></td>
              <td class="col-rider"><?= htmlspecialchars($result['firstname'] . ' ' . $result['lastname']) ?></td>
              <td class="col-club table-col-hide-portrait"><?= htmlspecialchars($result['club_name'] ?? '-') ?></td>
              <td class="col-time"><?= htmlspecialchars($result['finish_time'] ?? '-') ?></td>
              <td class="col-points table-col-hide-portrait"><?= $result['points'] ?? '-' ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

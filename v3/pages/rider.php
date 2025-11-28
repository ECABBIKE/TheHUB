<?php
/**
 * V3 Single Rider Page - Rider profile with results
 */

$db = hub_db();
$riderId = intval($pageInfo['params']['id'] ?? 0);

if (!$riderId) {
    header('Location: /v3/riders');
    exit;
}

// Fetch rider details
$stmt = $db->prepare("
    SELECT
        r.id, r.firstname, r.lastname, r.birth_year, r.gender,
        r.license_number, r.license_type, r.city, r.active,
        c.id as club_id, c.name as club_name, c.city as club_city
    FROM riders r
    LEFT JOIN clubs c ON r.club_id = c.id
    WHERE r.id = ?
");
$stmt->execute([$riderId]);
$rider = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$rider) {
    include HUB_V3_ROOT . '/pages/404.php';
    return;
}

// Fetch rider's results
$stmt = $db->prepare("
    SELECT
        res.id, res.finish_time, res.status, res.points, res.position,
        e.id as event_id, e.name as event_name, e.date as event_date,
        s.name as series_name,
        cls.display_name as class_name
    FROM results res
    JOIN events e ON res.event_id = e.id
    LEFT JOIN series s ON e.series_id = s.id
    LEFT JOIN classes cls ON res.class_id = cls.id
    WHERE res.cyclist_id = ?
    ORDER BY e.date DESC
    LIMIT 50
");
$stmt->execute([$riderId]);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate stats
$totalRaces = count($results);
$finishedRaces = count(array_filter($results, fn($r) => $r['status'] === 'finished'));
$totalPoints = array_sum(array_column($results, 'points'));
$podiums = count(array_filter($results, fn($r) => $r['position'] && $r['position'] <= 3));

$fullName = htmlspecialchars($rider['firstname'] . ' ' . $rider['lastname']);
?>

<h1 class="text-2xl font-bold mb-lg"><?= $fullName ?></h1>

<div class="page-grid">
  <!-- Profile Card -->
  <section class="card" aria-labelledby="profile-title">
    <h2 id="profile-title" class="sr-only">Profil</h2>
    <div class="rider-profile">
      <div class="rider-avatar"></div>
      <div class="rider-info">
        <h3 class="rider-name"><?= $fullName ?></h3>
        <?php if ($rider['club_name']): ?>
        <p class="rider-club">
          <a href="/v3/club/<?= $rider['club_id'] ?>" class="text-accent"><?= htmlspecialchars($rider['club_name']) ?></a>
        </p>
        <?php endif; ?>
        <div class="rider-meta">
          <?php if ($rider['birth_year']): ?>
          <span>Född <?= $rider['birth_year'] ?></span>
          <?php endif; ?>
          <?php if ($rider['city']): ?>
          <span>•</span>
          <span><?= htmlspecialchars($rider['city']) ?></span>
          <?php endif; ?>
          <?php if ($rider['license_number']): ?>
          <span>•</span>
          <span>Licens: <?= htmlspecialchars($rider['license_number']) ?></span>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </section>

  <!-- Stats Card -->
  <section class="card" aria-labelledby="stats-title">
    <h2 id="stats-title" class="card-title">Statistik</h2>
    <div class="stats-row">
      <div class="stat-block">
        <div class="stat-value"><?= $totalRaces ?></div>
        <div class="stat-label">Starter</div>
      </div>
      <div class="stat-block">
        <div class="stat-value"><?= $finishedRaces ?></div>
        <div class="stat-label">Fullföljt</div>
      </div>
      <div class="stat-block">
        <div class="stat-value"><?= $podiums ?></div>
        <div class="stat-label">Pallplatser</div>
      </div>
      <div class="stat-block">
        <div class="stat-value"><?= number_format($totalPoints) ?></div>
        <div class="stat-label">Poäng</div>
      </div>
    </div>
  </section>

  <!-- Results -->
  <section class="card grid-full" aria-labelledby="results-title">
    <div class="card-header">
      <div>
        <h2 id="results-title" class="card-title">Resultat</h2>
        <p class="card-subtitle"><?= $totalRaces ?> registrerade starter</p>
      </div>
    </div>

    <?php if (empty($results)): ?>
    <div class="text-center p-lg">
      <p class="text-muted">Inga resultat registrerade</p>
    </div>
    <?php else: ?>
    <div class="table-wrapper">
      <table class="table table--striped table--clickable">
        <thead>
          <tr>
            <th scope="col">Event</th>
            <th scope="col" class="table-col-hide-portrait">Serie</th>
            <th scope="col" class="table-col-hide-portrait">Klass</th>
            <th scope="col">Datum</th>
            <th class="col-time" scope="col">Tid</th>
            <th class="col-points" scope="col">Poäng</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($results as $result): ?>
          <tr data-href="/v3/event/<?= $result['event_id'] ?>">
            <td class="col-rider"><?= htmlspecialchars($result['event_name']) ?></td>
            <td class="table-col-hide-portrait text-secondary"><?= htmlspecialchars($result['series_name'] ?? '-') ?></td>
            <td class="table-col-hide-portrait"><?= htmlspecialchars($result['class_name'] ?? '-') ?></td>
            <td><?= $result['event_date'] ? date('j M Y', strtotime($result['event_date'])) : '-' ?></td>
            <td class="col-time"><?= htmlspecialchars($result['finish_time'] ?? '-') ?></td>
            <td class="col-points"><?= $result['points'] ?? '-' ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- Mobile Card View -->
    <div class="result-list">
      <?php foreach ($results as $result): ?>
      <a href="/v3/event/<?= $result['event_id'] ?>" class="result-item">
        <div class="result-place"><?= $result['points'] ?? '-' ?></div>
        <div class="result-info">
          <div class="result-name"><?= htmlspecialchars($result['event_name']) ?></div>
          <div class="result-club"><?= $result['event_date'] ? date('j M Y', strtotime($result['event_date'])) : '' ?></div>
        </div>
        <div class="result-time"><?= htmlspecialchars($result['finish_time'] ?? '-') ?></div>
      </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </section>
</div>

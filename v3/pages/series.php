<?php
/**
 * V3 Series Page - All competition series
 */

$db = hub_db();

// Get all series with event and participant counts
$series = $db->query("
    SELECT s.id, s.name, s.description, s.year, s.status, s.logo,
           COUNT(DISTINCT e.id) as event_count,
           (SELECT COUNT(DISTINCT r.cyclist_id)
            FROM results r
            INNER JOIN events e2 ON r.event_id = e2.id
            WHERE e2.series_id = s.id) as participant_count
    FROM series s
    LEFT JOIN events e ON s.id = e.series_id
    WHERE s.status = 'active'
    GROUP BY s.id
    ORDER BY s.year DESC, s.name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$totalSeries = count($series);
?>

<h1 class="text-2xl font-bold mb-lg">Serier</h1>

<div class="page-grid">
  <!-- Stats -->
  <section class="card grid-full">
    <div class="stats-row">
      <div class="stat-block">
        <div class="stat-value"><?= $totalSeries ?></div>
        <div class="stat-label">Aktiva serier</div>
      </div>
      <div class="stat-block">
        <div class="stat-value"><?= array_sum(array_column($series, 'event_count')) ?></div>
        <div class="stat-label">Totalt event</div>
      </div>
      <div class="stat-block">
        <div class="stat-value"><?= array_sum(array_column($series, 'participant_count')) ?></div>
        <div class="stat-label">Unika deltagare</div>
      </div>
    </div>
  </section>

  <?php if (empty($series)): ?>
  <section class="card grid-full">
    <div class="text-center p-lg">
      <p class="text-muted">Inga aktiva serier ännu</p>
    </div>
  </section>
  <?php else: ?>
    <?php foreach ($series as $s): ?>
    <section class="card card--clickable" onclick="HubRouter.navigate('/v3/results?series=<?= $s['id'] ?>')">
      <div class="card-header">
        <div>
          <h2 class="card-title"><?= htmlspecialchars($s['name']) ?></h2>
          <p class="card-subtitle"><?= $s['year'] ?></p>
        </div>
        <?php if ($s['status'] === 'active'): ?>
        <span class="chip chip--success">Aktiv</span>
        <?php endif; ?>
      </div>
      <?php if ($s['description']): ?>
      <p class="text-secondary mb-md"><?= htmlspecialchars($s['description']) ?></p>
      <?php endif; ?>
      <div class="stats-row">
        <div class="stat-block">
          <div class="stat-value"><?= $s['event_count'] ?></div>
          <div class="stat-label">Event</div>
        </div>
        <div class="stat-block">
          <div class="stat-value"><?= $s['participant_count'] ?></div>
          <div class="stat-label">Deltagare</div>
        </div>
      </div>
    </section>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

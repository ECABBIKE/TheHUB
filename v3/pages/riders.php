<?php
/**
 * V3 Riders Page - Shows all riders from database
 */

$db = hub_db();

// Fetch riders with stats
$stmt = $db->query("
    SELECT
        c.id,
        c.firstname,
        c.lastname,
        c.birth_year,
        c.gender,
        cl.name as club_name,
        COUNT(DISTINCT r.id) as total_races,
        COUNT(CASE WHEN r.position <= 3 THEN 1 END) as podiums,
        MIN(r.position) as best_position
    FROM riders c
    LEFT JOIN clubs cl ON c.club_id = cl.id
    LEFT JOIN results r ON c.id = r.cyclist_id
    WHERE c.active = 1
    GROUP BY c.id
    HAVING total_races > 0
    ORDER BY c.lastname, c.firstname
    LIMIT 100
");
$riders = $stmt->fetchAll(PDO::FETCH_ASSOC);
$total = count($riders);
?>

<h1 class="text-2xl font-bold mb-lg">Åkare</h1>

<div class="page-grid">
  <!-- Stats -->
  <section class="card">
    <div class="card-title">Statistik</div>
    <div class="stats-row">
      <div class="stat-block">
        <div class="stat-value"><?= $total ?></div>
        <div class="stat-label">Aktiva åkare</div>
      </div>
    </div>
  </section>

  <!-- Rider List -->
  <section class="card grid-full" aria-labelledby="riders-title">
    <div class="card-header">
      <div>
        <h2 id="riders-title" class="card-title">Alla åkare</h2>
        <p class="card-subtitle">Visar <?= $total ?> åkare med resultat</p>
      </div>
    </div>

    <div class="table-wrapper">
      <table class="table table--striped table--clickable">
        <thead>
          <tr>
            <th class="col-rider" scope="col">Namn</th>
            <th class="col-club table-col-hide-portrait" scope="col">Klubb</th>
            <th scope="col" class="text-right">Starter</th>
            <th scope="col" class="text-right table-col-hide-portrait">Pallplatser</th>
            <th scope="col" class="text-right">Bästa</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($riders as $rider): ?>
          <tr data-href="/v3/rider/<?= $rider['id'] ?>">
            <td class="col-rider"><?= htmlspecialchars($rider['firstname'] . ' ' . $rider['lastname']) ?></td>
            <td class="col-club table-col-hide-portrait"><?= htmlspecialchars($rider['club_name'] ?? '-') ?></td>
            <td class="text-right"><?= $rider['total_races'] ?></td>
            <td class="text-right table-col-hide-portrait"><?= $rider['podiums'] ?></td>
            <td class="text-right"><?= $rider['best_position'] ? '#' . $rider['best_position'] : '-' ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- Mobile Card View -->
    <div class="result-list">
      <?php foreach ($riders as $rider): ?>
      <a href="/v3/rider/<?= $rider['id'] ?>" class="result-item">
        <div class="result-place"><?= $rider['total_races'] ?></div>
        <div class="result-info">
          <div class="result-name"><?= htmlspecialchars($rider['firstname'] . ' ' . $rider['lastname']) ?></div>
          <div class="result-club"><?= htmlspecialchars($rider['club_name'] ?? 'Ingen klubb') ?></div>
        </div>
        <div class="result-time"><?= $rider['best_position'] ? '#' . $rider['best_position'] : '-' ?></div>
      </a>
      <?php endforeach; ?>
    </div>
  </section>
</div>

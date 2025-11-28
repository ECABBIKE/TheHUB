<?php
/**
 * V3 Single Club Page - Club profile with members
 */

$db = hub_db();
$clubId = intval($pageInfo['params']['id'] ?? 0);

if (!$clubId) {
    header('Location: /v3/clubs');
    exit;
}

// Fetch club details
$stmt = $db->prepare("SELECT * FROM clubs WHERE id = ?");
$stmt->execute([$clubId]);
$club = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$club) {
    include HUB_V3_ROOT . '/pages/404.php';
    return;
}

// Fetch club members with stats
$stmt = $db->prepare("
    SELECT
        r.id, r.firstname, r.lastname, r.birth_year, r.gender,
        COUNT(DISTINCT res.id) as total_races,
        SUM(res.points) as total_points,
        MIN(res.position) as best_position
    FROM riders r
    LEFT JOIN results res ON r.id = res.cyclist_id
    WHERE r.club_id = ? AND r.active = 1
    GROUP BY r.id
    ORDER BY total_points DESC, r.lastname, r.firstname
");
$stmt->execute([$clubId]);
$members = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalMembers = count($members);
$totalRaces = array_sum(array_column($members, 'total_races'));
$totalPoints = array_sum(array_column($members, 'total_points'));
?>

<h1 class="text-2xl font-bold mb-lg"><?= htmlspecialchars($club['name']) ?></h1>

<div class="page-grid">
  <!-- Club Info -->
  <section class="card" aria-labelledby="club-info-title">
    <h2 id="club-info-title" class="card-title">Klubbinformation</h2>
    <div class="card-body">
      <?php if ($club['city']): ?>
      <p><strong>Ort:</strong> <?= htmlspecialchars($club['city']) ?></p>
      <?php endif; ?>
      <?php if ($club['website']): ?>
      <p class="mt-xs"><strong>Hemsida:</strong> <a href="<?= htmlspecialchars($club['website']) ?>" target="_blank" rel="noopener" class="text-accent"><?= htmlspecialchars($club['website']) ?></a></p>
      <?php endif; ?>
      <?php if ($club['email']): ?>
      <p class="mt-xs"><strong>E-post:</strong> <a href="mailto:<?= htmlspecialchars($club['email']) ?>" class="text-accent"><?= htmlspecialchars($club['email']) ?></a></p>
      <?php endif; ?>
    </div>
  </section>

  <!-- Stats -->
  <section class="card" aria-labelledby="club-stats-title">
    <h2 id="club-stats-title" class="card-title">Statistik</h2>
    <div class="stats-row">
      <div class="stat-block">
        <div class="stat-value"><?= $totalMembers ?></div>
        <div class="stat-label">Medlemmar</div>
      </div>
      <div class="stat-block">
        <div class="stat-value"><?= $totalRaces ?></div>
        <div class="stat-label">Starter</div>
      </div>
      <div class="stat-block">
        <div class="stat-value"><?= number_format($totalPoints) ?></div>
        <div class="stat-label">Poäng</div>
      </div>
    </div>
  </section>

  <!-- Members -->
  <section class="card grid-full" aria-labelledby="members-title">
    <div class="card-header">
      <div>
        <h2 id="members-title" class="card-title">Medlemmar</h2>
        <p class="card-subtitle"><?= $totalMembers ?> aktiva åkare</p>
      </div>
    </div>

    <?php if (empty($members)): ?>
    <div class="text-center p-lg">
      <p class="text-muted">Inga registrerade medlemmar</p>
    </div>
    <?php else: ?>
    <div class="table-wrapper">
      <table class="table table--striped table--clickable">
        <thead>
          <tr>
            <th class="col-rider" scope="col">Namn</th>
            <th scope="col" class="text-right">Starter</th>
            <th scope="col" class="text-right table-col-hide-portrait">Bästa</th>
            <th class="col-points" scope="col">Poäng</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($members as $member): ?>
          <tr data-href="/v3/rider/<?= $member['id'] ?>">
            <td class="col-rider"><?= htmlspecialchars($member['firstname'] . ' ' . $member['lastname']) ?></td>
            <td class="text-right"><?= $member['total_races'] ?></td>
            <td class="text-right table-col-hide-portrait"><?= $member['best_position'] ? '#' . $member['best_position'] : '-' ?></td>
            <td class="col-points"><?= number_format($member['total_points'] ?? 0) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- Mobile Card View -->
    <div class="result-list">
      <?php foreach ($members as $member): ?>
      <a href="/v3/rider/<?= $member['id'] ?>" class="result-item">
        <div class="result-place"><?= $member['total_races'] ?></div>
        <div class="result-info">
          <div class="result-name"><?= htmlspecialchars($member['firstname'] . ' ' . $member['lastname']) ?></div>
          <div class="result-club"><?= number_format($member['total_points'] ?? 0) ?> poäng</div>
        </div>
        <div class="result-time"><?= $member['best_position'] ? '#' . $member['best_position'] : '-' ?></div>
      </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </section>
</div>

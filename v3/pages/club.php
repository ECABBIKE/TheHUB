<?php
/**
 * V3 Single Club Page - Club profile with members and stats
 */

$db = hub_db();
$clubId = intval($pageInfo['params']['id'] ?? 0);

if (!$clubId) {
    header('Location: /v3/riders');
    exit;
}

try {
    // Fetch club details
    $stmt = $db->prepare("SELECT * FROM clubs WHERE id = ?");
    $stmt->execute([$clubId]);
    $club = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$club) {
        include HUB_V3_ROOT . '/pages/404.php';
        return;
    }

    // Fetch club members
    $stmt = $db->prepare("
        SELECT id, firstname, lastname, birth_year, gender
        FROM riders
        WHERE club_id = ? AND active = 1
        ORDER BY lastname, firstname
    ");
    $stmt->execute([$clubId]);
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate stats for each member with dynamic class_position
    foreach ($members as &$member) {
        $stmt = $db->prepare("
            SELECT
                res.id,
                res.status,
                res.points,
                (
                    SELECT COUNT(*) + 1
                    FROM results r2
                    WHERE r2.event_id = res.event_id
                    AND r2.class_id = res.class_id
                    AND r2.status = 'finished'
                    AND r2.id != res.id
                    AND (
                        CASE
                            WHEN r2.finish_time LIKE '%:%:%' THEN
                                CAST(SUBSTRING_INDEX(r2.finish_time, ':', 1) AS DECIMAL(10,2)) * 3600 +
                                CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(r2.finish_time, ':', 2), ':', -1) AS DECIMAL(10,2)) * 60 +
                                CAST(SUBSTRING_INDEX(r2.finish_time, ':', -1) AS DECIMAL(10,2))
                            ELSE
                                CAST(SUBSTRING_INDEX(r2.finish_time, ':', 1) AS DECIMAL(10,2)) * 60 +
                                CAST(SUBSTRING_INDEX(r2.finish_time, ':', -1) AS DECIMAL(10,2))
                        END
                        <
                        CASE
                            WHEN res.finish_time LIKE '%:%:%' THEN
                                CAST(SUBSTRING_INDEX(res.finish_time, ':', 1) AS DECIMAL(10,2)) * 3600 +
                                CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(res.finish_time, ':', 2), ':', -1) AS DECIMAL(10,2)) * 60 +
                                CAST(SUBSTRING_INDEX(res.finish_time, ':', -1) AS DECIMAL(10,2))
                            ELSE
                                CAST(SUBSTRING_INDEX(res.finish_time, ':', 1) AS DECIMAL(10,2)) * 60 +
                                CAST(SUBSTRING_INDEX(res.finish_time, ':', -1) AS DECIMAL(10,2))
                        END
                    )
                ) as class_position
            FROM results res
            WHERE res.cyclist_id = ? AND res.status = 'finished'
        ");
        $stmt->execute([$member['id']]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $member['total_races'] = count($results);
        $member['total_points'] = array_sum(array_column($results, 'points'));
        $member['podiums'] = count(array_filter($results, fn($r) => $r['class_position'] && $r['class_position'] <= 3));
        $member['best_position'] = null;
        foreach ($results as $r) {
            if ($r['class_position'] && (!$member['best_position'] || $r['class_position'] < $member['best_position'])) {
                $member['best_position'] = (int)$r['class_position'];
            }
        }
    }
    unset($member);

    // Sort by points
    usort($members, function($a, $b) {
        if ($b['total_points'] != $a['total_points']) {
            return $b['total_points'] - $a['total_points'];
        }
        return $b['total_races'] - $a['total_races'];
    });

    $totalMembers = count($members);
    $totalRaces = array_sum(array_column($members, 'total_races'));
    $totalPoints = array_sum(array_column($members, 'total_points'));
    $totalPodiums = array_sum(array_column($members, 'podiums'));

} catch (Exception $e) {
    $error = $e->getMessage();
    $club = null;
}

if (!$club) {
    include HUB_V3_ROOT . '/pages/404.php';
    return;
}
?>

<div class="page-header">
  <h1 class="page-title"><?= htmlspecialchars($club['name']) ?></h1>
  <div class="page-meta">
    <?php if ($club['city']): ?>
      <span class="chip"><?= htmlspecialchars($club['city']) ?></span>
    <?php endif; ?>
    <span class="chip chip--primary"><?= $totalMembers ?> medlemmar</span>
  </div>
</div>

<?php if (isset($error)): ?>
<section class="card mb-lg">
  <div class="card-title" style="color: var(--color-error)">Fel</div>
  <p><?= htmlspecialchars($error) ?></p>
</section>
<?php endif; ?>

<!-- Club Info -->
<?php if ($club['website'] || $club['email']): ?>
<section class="card mb-lg">
  <div class="club-contact">
    <?php if ($club['website']): ?>
    <a href="<?= htmlspecialchars($club['website']) ?>" target="_blank" rel="noopener" class="contact-link">
      <span class="contact-icon">🌐</span>
      <span><?= htmlspecialchars(preg_replace('#^https?://#', '', $club['website'])) ?></span>
    </a>
    <?php endif; ?>
    <?php if ($club['email']): ?>
    <a href="mailto:<?= htmlspecialchars($club['email']) ?>" class="contact-link">
      <span class="contact-icon">✉️</span>
      <span><?= htmlspecialchars($club['email']) ?></span>
    </a>
    <?php endif; ?>
  </div>
</section>
<?php endif; ?>

<!-- Stats Grid -->
<section class="card mb-lg">
  <div class="stats-grid">
    <div class="stat-card">
      <div class="stat-value"><?= $totalMembers ?></div>
      <div class="stat-label">Medlemmar</div>
    </div>
    <div class="stat-card">
      <div class="stat-value"><?= $totalRaces ?></div>
      <div class="stat-label">Starter</div>
    </div>
    <div class="stat-card">
      <div class="stat-value"><?= $totalPodiums ?></div>
      <div class="stat-label">Pallplatser</div>
    </div>
    <div class="stat-card stat-card--accent">
      <div class="stat-value"><?= number_format($totalPoints) ?></div>
      <div class="stat-label">Poäng</div>
    </div>
  </div>
</section>

<!-- Members -->
<section class="card">
  <div class="card-header">
    <div>
      <h2 class="card-title">Medlemmar</h2>
      <p class="card-subtitle"><?= $totalMembers ?> aktiva åkare</p>
    </div>
  </div>

  <?php if (empty($members)): ?>
  <div class="empty-state">
    <div class="empty-state-icon">👥</div>
    <p>Inga registrerade medlemmar</p>
  </div>
  <?php else: ?>
  <div class="table-wrapper">
    <table class="table table--striped table--hover">
      <thead>
        <tr>
          <th class="col-rider">Namn</th>
          <th class="text-center">Starter</th>
          <th class="text-center table-col-hide-portrait">Pallplatser</th>
          <th class="text-center">Bästa</th>
          <th class="text-right table-col-hide-portrait">Poäng</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($members as $member): ?>
        <tr onclick="window.location='/v3/rider/<?= $member['id'] ?>'" style="cursor:pointer">
          <td class="col-rider">
            <a href="/v3/rider/<?= $member['id'] ?>" class="rider-link">
              <?= htmlspecialchars($member['firstname'] . ' ' . $member['lastname']) ?>
            </a>
            <?php if ($member['birth_year']): ?>
              <span class="rider-year"><?= $member['birth_year'] ?></span>
            <?php endif; ?>
          </td>
          <td class="text-center">
            <strong><?= $member['total_races'] ?: 0 ?></strong>
          </td>
          <td class="text-center table-col-hide-portrait">
            <?php if ($member['podiums'] > 0): ?>
              <span class="podium-badge">🏆 <?= $member['podiums'] ?></span>
            <?php else: ?>
              <span class="text-muted">-</span>
            <?php endif; ?>
          </td>
          <td class="text-center">
            <?php if ($member['best_position']): ?>
              <?php if ($member['best_position'] == 1): ?>
                <span class="position-badge position--1">🥇</span>
              <?php elseif ($member['best_position'] == 2): ?>
                <span class="position-badge position--2">🥈</span>
              <?php elseif ($member['best_position'] == 3): ?>
                <span class="position-badge position--3">🥉</span>
              <?php else: ?>
                <span class="position-badge">#<?= $member['best_position'] ?></span>
              <?php endif; ?>
            <?php else: ?>
              <span class="text-muted">-</span>
            <?php endif; ?>
          </td>
          <td class="text-right table-col-hide-portrait">
            <?php if ($member['total_points'] > 0): ?>
              <span class="points-value"><?= number_format($member['total_points'], 0) ?></span>
            <?php else: ?>
              <span class="text-muted">-</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Mobile Card View -->
  <div class="result-list">
    <?php foreach ($members as $member): ?>
    <a href="/v3/rider/<?= $member['id'] ?>" class="result-item">
      <div class="result-place">
        <?php if ($member['best_position'] && $member['best_position'] <= 3): ?>
          <?= $member['best_position'] == 1 ? '🥇' : ($member['best_position'] == 2 ? '🥈' : '🥉') ?>
        <?php else: ?>
          <?= $member['total_races'] ?: 0 ?>
        <?php endif; ?>
      </div>
      <div class="result-info">
        <div class="result-name"><?= htmlspecialchars($member['firstname'] . ' ' . $member['lastname']) ?></div>
        <div class="result-club"><?= $member['total_races'] ?: 0 ?> starter<?= $member['podiums'] > 0 ? ' • 🏆 ' . $member['podiums'] : '' ?></div>
      </div>
      <div class="result-points">
        <div class="points-big"><?= number_format($member['total_points'] ?? 0) ?></div>
        <div class="points-label">poäng</div>
      </div>
    </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</section>

<style>
.breadcrumb {
  display: flex;
  align-items: center;
  gap: var(--space-xs);
  font-size: var(--text-sm);
  color: var(--color-text-muted);
}
.breadcrumb-link {
  color: var(--color-text-secondary);
}
.breadcrumb-link:hover {
  color: var(--color-accent-text);
}
.breadcrumb-current {
  color: var(--color-text);
  font-weight: var(--weight-medium);
}

.page-header {
  margin-bottom: var(--space-lg);
}
.page-title {
  font-size: var(--text-2xl);
  font-weight: var(--weight-bold);
  margin: 0 0 var(--space-sm) 0;
}
.page-meta {
  display: flex;
  gap: var(--space-sm);
  flex-wrap: wrap;
}
.chip--primary {
  background: var(--color-accent);
  color: var(--color-text-inverse);
}

.mb-md { margin-bottom: var(--space-md); }
.mb-lg { margin-bottom: var(--space-lg); }
.text-muted { color: var(--color-text-muted); }

.club-contact {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-lg);
}
.contact-link {
  display: flex;
  align-items: center;
  gap: var(--space-sm);
  color: var(--color-accent-text);
}
.contact-link:hover {
  text-decoration: underline;
}
.contact-icon {
  font-size: var(--text-lg);
}

.stats-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: var(--space-md);
}
.stat-card {
  text-align: center;
  padding: var(--space-md);
  background: var(--color-bg-sunken);
  border-radius: var(--radius-md);
}
.stat-card--accent {
  background: var(--color-accent);
  color: var(--color-text-inverse);
}
.stat-card--accent .stat-label {
  color: rgba(255,255,255,0.8);
}
.stat-value {
  font-size: var(--text-2xl);
  font-weight: var(--weight-bold);
}
.stat-label {
  font-size: var(--text-xs);
  color: var(--color-text-muted);
  margin-top: var(--space-2xs);
}

.rider-link {
  color: var(--color-text);
  font-weight: var(--weight-medium);
}
.rider-link:hover {
  color: var(--color-accent-text);
}
.rider-year {
  font-size: var(--text-xs);
  color: var(--color-text-muted);
  margin-left: var(--space-xs);
}
.podium-badge {
  font-size: var(--text-sm);
}
.position-badge {
  font-weight: var(--weight-semibold);
}
.points-value {
  font-weight: var(--weight-semibold);
  color: var(--color-accent-text);
}

.result-place.top-3 {
  background: var(--color-accent-light);
}
.result-points {
  text-align: right;
}
.points-big {
  font-size: var(--text-lg);
  font-weight: var(--weight-bold);
  color: var(--color-accent-text);
}
.points-label {
  font-size: var(--text-xs);
  color: var(--color-text-muted);
}

.empty-state {
  text-align: center;
  padding: var(--space-2xl);
  color: var(--color-text-muted);
}
.empty-state-icon {
  font-size: 48px;
  margin-bottom: var(--space-md);
}

@media (max-width: 599px) {
  .stats-grid {
    grid-template-columns: repeat(2, 1fr);
  }
  .page-title {
    font-size: var(--text-xl);
  }
  .club-contact {
    flex-direction: column;
    gap: var(--space-sm);
  }
}
</style>

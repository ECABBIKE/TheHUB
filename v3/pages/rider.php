<?php
/**
 * V3 Single Rider Page - Rider profile with results and ranking
 */

$db = hub_db();
$riderId = intval($pageInfo['params']['id'] ?? 0);

if (!$riderId) {
    header('Location: /v3/riders');
    exit;
}

try {
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

    // Fetch rider's results with calculated class position (exclude DNS)
    $stmt = $db->prepare("
        SELECT
            res.id, res.finish_time, res.status, res.points, res.position,
            res.event_id, res.class_id,
            e.id as event_id, e.name as event_name, e.date as event_date, e.location,
            s.id as series_id, s.name as series_name,
            cls.display_name as class_name,
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
        JOIN events e ON res.event_id = e.id
        LEFT JOIN series s ON e.series_id = s.id
        LEFT JOIN classes cls ON res.class_id = cls.id
        WHERE res.cyclist_id = ? AND res.status != 'dns'
        ORDER BY e.date DESC
    ");
    $stmt->execute([$riderId]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fix: class_position only valid for finished results
    foreach ($results as &$result) {
        if ($result['status'] !== 'finished') {
            $result['class_position'] = null;
        }
    }
    unset($result);

    // Calculate stats
    $totalStarts = count($results);
    $finishedRaces = count(array_filter($results, fn($r) => $r['status'] === 'finished'));
    $totalPoints = array_sum(array_column($results, 'points'));
    $podiums = count(array_filter($results, fn($r) => $r['class_position'] && $r['class_position'] <= 3));
    $wins = count(array_filter($results, fn($r) => $r['class_position'] == 1));
    $bestPosition = null;
    foreach ($results as $r) {
        if ($r['class_position'] && $r['status'] === 'finished') {
            if (!$bestPosition || $r['class_position'] < $bestPosition) {
                $bestPosition = (int)$r['class_position'];
            }
        }
    }

    // Calculate age
    $currentYear = date('Y');
    $age = ($rider['birth_year'] && $rider['birth_year'] > 0)
        ? ($currentYear - $rider['birth_year'])
        : null;

    // GravitySeries Total stats
    $gravityTotalPoints = 0;
    $gravityTotalPosition = null;
    $gravityTotalClassTotal = 0;
    $gravityClassName = null;

    // Find GravitySeries Total series
    $stmt = $db->prepare("
        SELECT id, name FROM series
        WHERE id = 8
        OR (active = 1 AND (name LIKE '%Total%' OR name LIKE '%GravitySeries%'))
        ORDER BY (id = 8) DESC, year DESC
        LIMIT 1
    ");
    $stmt->execute();
    $totalSeries = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($totalSeries) {
        // Get rider's series points (from series_results if exists, otherwise from results)
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(sr.points), 0) as total_points
            FROM series_results sr
            WHERE sr.series_id = ? AND sr.cyclist_id = ?
        ");
        $stmt->execute([$totalSeries['id'], $riderId]);
        $seriesStats = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($seriesStats && $seriesStats['total_points'] > 0) {
            $gravityTotalPoints = $seriesStats['total_points'];
        } else {
            // Fallback: sum from results table
            $stmt = $db->prepare("
                SELECT COALESCE(SUM(res.points), 0) as total_points
                FROM results res
                JOIN events e ON res.event_id = e.id
                LEFT JOIN series_events se ON e.id = se.event_id
                WHERE (e.series_id = ? OR se.series_id = ?)
                AND res.cyclist_id = ? AND res.status = 'finished'
            ");
            $stmt->execute([$totalSeries['id'], $totalSeries['id'], $riderId]);
            $fallbackStats = $stmt->fetch(PDO::FETCH_ASSOC);
            $gravityTotalPoints = $fallbackStats['total_points'] ?? 0;
        }

        // Get rider's most common class in this series
        $stmt = $db->prepare("
            SELECT cls.display_name, COUNT(*) as cnt
            FROM results res
            JOIN events e ON res.event_id = e.id
            LEFT JOIN series_events se ON e.id = se.event_id
            LEFT JOIN classes cls ON res.class_id = cls.id
            WHERE (e.series_id = ? OR se.series_id = ?)
            AND res.cyclist_id = ? AND res.status = 'finished' AND cls.id IS NOT NULL
            GROUP BY cls.id
            ORDER BY cnt DESC
            LIMIT 1
        ");
        $stmt->execute([$totalSeries['id'], $totalSeries['id'], $riderId]);
        $classResult = $stmt->fetch(PDO::FETCH_ASSOC);
        $gravityClassName = $classResult['display_name'] ?? null;

        // Get position in series (by total points)
        if ($gravityTotalPoints > 0) {
            $stmt = $db->prepare("
                SELECT cyclist_id, SUM(points) as total_points
                FROM series_results
                WHERE series_id = ?
                GROUP BY cyclist_id
                ORDER BY total_points DESC
            ");
            $stmt->execute([$totalSeries['id']]);
            $standings = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $gravityTotalClassTotal = count($standings);
            $position = 1;
            foreach ($standings as $standing) {
                if ($standing['cyclist_id'] == $riderId) {
                    $gravityTotalPosition = $position;
                    break;
                }
                $position++;
            }
        }
    }

    // GravitySeries Team stats (club ranking)
    $gravityTeamPoints = 0;
    $gravityTeamPosition = null;
    $gravityTeamTotal = 0;

    if ($totalSeries && $rider['club_id']) {
        // Get club's total points in this series
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(sr.points), 0) as total_points
            FROM series_results sr
            JOIN riders r ON sr.cyclist_id = r.id
            WHERE sr.series_id = ? AND r.club_id = ?
        ");
        $stmt->execute([$totalSeries['id'], $rider['club_id']]);
        $teamStats = $stmt->fetch(PDO::FETCH_ASSOC);
        $gravityTeamPoints = $teamStats['total_points'] ?? 0;

        // Get team position among all clubs
        if ($gravityTeamPoints > 0) {
            $stmt = $db->prepare("
                SELECT r.club_id, SUM(sr.points) as total_points
                FROM series_results sr
                JOIN riders r ON sr.cyclist_id = r.id
                WHERE sr.series_id = ? AND r.club_id IS NOT NULL
                GROUP BY r.club_id
                ORDER BY total_points DESC
            ");
            $stmt->execute([$totalSeries['id']]);
            $teamStandings = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $gravityTeamTotal = count($teamStandings);
            $position = 1;
            foreach ($teamStandings as $team) {
                if ($team['club_id'] == $rider['club_id']) {
                    $gravityTeamPosition = $position;
                    break;
                }
                $position++;
            }
        }
    }

} catch (Exception $e) {
    $error = $e->getMessage();
    $rider = null;
}

if (!$rider) {
    include HUB_V3_ROOT . '/pages/404.php';
    return;
}

$fullName = htmlspecialchars($rider['firstname'] . ' ' . $rider['lastname']);
$genderText = match($rider['gender']) {
    'M' => 'Man',
    'F', 'K' => 'Kvinna',
    default => null
};
?>

<?php if (isset($error)): ?>
<section class="card mb-lg">
  <div class="card-title" style="color: var(--color-error)">Fel</div>
  <p><?= htmlspecialchars($error) ?></p>
</section>
<?php endif; ?>

<!-- Profile Card -->
<section class="profile-card mb-lg">
  <div class="profile-stripe"></div>
  <div class="profile-content">
    <div class="profile-photo">
      <div class="photo-placeholder">👤</div>
    </div>
    <div class="profile-info">
      <h1 class="profile-name"><?= $fullName ?></h1>
      <?php if ($rider['club_name']): ?>
        <a href="/v3/club/<?= $rider['club_id'] ?>" class="profile-club"><?= htmlspecialchars($rider['club_name']) ?></a>
      <?php endif; ?>
      <div class="profile-details">
        <?php if ($age): ?>
          <span class="profile-detail"><?= $age ?> år</span>
        <?php endif; ?>
        <?php if ($genderText): ?>
          <span class="profile-detail"><?= $genderText ?></span>
        <?php endif; ?>
        <?php if ($rider['birth_year']): ?>
          <span class="profile-detail">f. <?= $rider['birth_year'] ?></span>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>

<!-- Stats Grid -->
<section class="stats-grid-4 mb-lg">
  <div class="stat-box">
    <div class="stat-value"><?= $totalStarts ?></div>
    <div class="stat-label">Starter</div>
  </div>
  <div class="stat-box">
    <div class="stat-value"><?= $finishedRaces ?></div>
    <div class="stat-label">Fullföljt</div>
  </div>
  <?php if ($wins > 0 || $podiums > 0): ?>
  <div class="stat-box <?= $wins > 0 ? 'stat-box--gold' : '' ?>">
    <div class="stat-value"><?= $wins ?></div>
    <div class="stat-label">Segrar</div>
  </div>
  <div class="stat-box">
    <div class="stat-value"><?= $podiums ?></div>
    <div class="stat-label">Pallplatser</div>
  </div>
  <?php else: ?>
  <div class="stat-box" style="grid-column: span 2;">
    <div class="stat-value"><?= $bestPosition ? $bestPosition : '-' ?></div>
    <div class="stat-label">Bästa placering</div>
  </div>
  <?php endif; ?>
</section>

<!-- Tab Navigation -->
<nav class="tabs mb-md">
  <button class="tab-btn active" data-tab="resultat">Resultat</button>
  <button class="tab-btn" data-tab="gs-total">GS Total</button>
  <button class="tab-btn" data-tab="gs-team">GS Team</button>
</nav>

<!-- Tab: Resultat -->
<section class="tab-content active" id="tab-resultat">
  <div class="card-header">
    <div>
      <h2 class="card-title">Resultathistorik</h2>
      <p class="card-subtitle"><?= $totalStarts ?> registrerade starter</p>
    </div>
  </div>

  <?php if (empty($results)): ?>
  <div class="empty-state">
    <div class="empty-state-icon">🏁</div>
    <p>Inga resultat registrerade</p>
  </div>
  <?php else: ?>
  <div class="table-wrapper">
    <table class="table table--striped table--hover">
      <thead>
        <tr>
          <th class="col-place">#</th>
          <th>Event</th>
          <th class="table-col-hide-portrait">Serie</th>
          <th class="table-col-hide-portrait">Klass</th>
          <th class="table-col-hide-portrait">Datum</th>
          <th class="col-time">Tid</th>
          <th class="col-points table-col-hide-portrait">Poäng</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($results as $result): ?>
        <tr onclick="window.location='/v3/event/<?= $result['event_id'] ?>'" style="cursor:pointer">
          <td class="col-place <?= $result['class_position'] && $result['class_position'] <= 3 ? 'col-place--' . $result['class_position'] : '' ?>">
            <?php if ($result['status'] !== 'finished'): ?>
              <span class="status-mini"><?= strtoupper(substr($result['status'], 0, 3)) ?></span>
            <?php elseif ($result['class_position'] == 1): ?>
              🥇
            <?php elseif ($result['class_position'] == 2): ?>
              🥈
            <?php elseif ($result['class_position'] == 3): ?>
              🥉
            <?php else: ?>
              <?= $result['class_position'] ?? '-' ?>
            <?php endif; ?>
          </td>
          <td>
            <a href="/v3/event/<?= $result['event_id'] ?>" class="event-link">
              <?= htmlspecialchars($result['event_name']) ?>
            </a>
          </td>
          <td class="table-col-hide-portrait text-muted">
            <?php if ($result['series_id']): ?>
              <a href="/v3/series/<?= $result['series_id'] ?>"><?= htmlspecialchars($result['series_name']) ?></a>
            <?php else: ?>
              -
            <?php endif; ?>
          </td>
          <td class="table-col-hide-portrait"><?= htmlspecialchars($result['class_name'] ?? '-') ?></td>
          <td class="table-col-hide-portrait"><?= $result['event_date'] ? date('j M Y', strtotime($result['event_date'])) : '-' ?></td>
          <td class="col-time"><?= htmlspecialchars($result['finish_time'] ?? '-') ?></td>
          <td class="col-points table-col-hide-portrait">
            <?php if ($result['points']): ?>
              <span class="points-value"><?= $result['points'] ?></span>
            <?php else: ?>
              -
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Mobile Card View -->
  <div class="result-list">
    <?php foreach ($results as $result): ?>
    <a href="/v3/event/<?= $result['event_id'] ?>" class="result-item">
      <div class="result-place <?= $result['class_position'] && $result['class_position'] <= 3 ? 'top-3' : '' ?>">
        <?php if ($result['status'] !== 'finished'): ?>
          <span class="status-mini"><?= strtoupper(substr($result['status'], 0, 3)) ?></span>
        <?php elseif ($result['class_position'] == 1): ?>
          🥇
        <?php elseif ($result['class_position'] == 2): ?>
          🥈
        <?php elseif ($result['class_position'] == 3): ?>
          🥉
        <?php else: ?>
          <?= $result['class_position'] ?? '-' ?>
        <?php endif; ?>
      </div>
      <div class="result-info">
        <div class="result-name"><?= htmlspecialchars($result['event_name']) ?></div>
        <div class="result-club"><?= $result['event_date'] ? date('j M Y', strtotime($result['event_date'])) : '' ?> • <?= htmlspecialchars($result['class_name'] ?? '') ?></div>
      </div>
      <div class="result-time-col">
        <div class="time-value"><?= htmlspecialchars($result['finish_time'] ?? '-') ?></div>
        <?php if ($result['points']): ?>
          <div class="points-small"><?= $result['points'] ?> p</div>
        <?php endif; ?>
      </div>
    </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</section>

<!-- Tab: GS Total -->
<section class="tab-content" id="tab-gs-total">
  <div class="card">
    <div class="card-header">
      <h2 class="card-title">GravitySeries Total</h2>
      <p class="card-subtitle">Individuell poängställning</p>
    </div>
    <div class="gs-stats-card">
      <div class="gs-main-stat">
        <div class="gs-points"><?= number_format($gravityTotalPoints) ?></div>
        <div class="gs-points-label">Poäng</div>
      </div>
      <?php if ($gravityTotalPosition): ?>
      <div class="gs-details">
        <div class="gs-detail">
          <span class="gs-detail-value">#<?= $gravityTotalPosition ?></span>
          <span class="gs-detail-label">Position</span>
        </div>
        <div class="gs-detail">
          <span class="gs-detail-value"><?= $gravityTotalClassTotal ?></span>
          <span class="gs-detail-label">Deltagare</span>
        </div>
        <?php if ($gravityClassName): ?>
        <div class="gs-detail">
          <span class="gs-detail-value"><?= htmlspecialchars($gravityClassName) ?></span>
          <span class="gs-detail-label">Klass</span>
        </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
</section>

<!-- Tab: GS Team -->
<section class="tab-content" id="tab-gs-team">
  <div class="card">
    <div class="card-header">
      <h2 class="card-title">GravitySeries Team</h2>
      <p class="card-subtitle">Lagställning</p>
    </div>
    <?php if ($rider['club_name']): ?>
    <div class="gs-stats-card gs-stats-card--team">
      <div class="gs-team-name"><?= htmlspecialchars($rider['club_name']) ?></div>
      <div class="gs-main-stat">
        <div class="gs-points"><?= number_format($gravityTeamPoints) ?></div>
        <div class="gs-points-label">Lagpoäng</div>
      </div>
      <?php if ($gravityTeamPosition): ?>
      <div class="gs-details">
        <div class="gs-detail">
          <span class="gs-detail-value">#<?= $gravityTeamPosition ?></span>
          <span class="gs-detail-label">Position</span>
        </div>
        <div class="gs-detail">
          <span class="gs-detail-value"><?= $gravityTeamTotal ?></span>
          <span class="gs-detail-label">Lag totalt</span>
        </div>
      </div>
      <?php endif; ?>
    </div>
    <?php else: ?>
    <div class="empty-state">
      <div class="empty-state-icon">👥</div>
      <p>Ingen klubbtillhörighet registrerad</p>
    </div>
    <?php endif; ?>
  </div>
</section>

<script>
// Tab switching
document.querySelectorAll('.tab-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    const tabId = btn.dataset.tab;

    // Update buttons
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');

    // Update content
    document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
    document.getElementById('tab-' + tabId).classList.add('active');
  });
});
</script>

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

.mb-sm { margin-bottom: var(--space-sm); }
.mb-md { margin-bottom: var(--space-md); }
.mb-lg { margin-bottom: var(--space-lg); }
.text-muted { color: var(--color-text-muted); }

/* Tabs */
.tabs {
  display: flex;
  gap: var(--space-xs);
  background: var(--color-bg-surface);
  padding: var(--space-xs);
  border-radius: var(--radius-md);
  overflow-x: auto;
}
.tab-btn {
  flex: 1;
  padding: var(--space-sm) var(--space-md);
  border: none;
  background: transparent;
  color: var(--color-text-secondary);
  font-size: var(--text-sm);
  font-weight: var(--weight-medium);
  border-radius: var(--radius-sm);
  cursor: pointer;
  white-space: nowrap;
  transition: all var(--transition-fast);
}
.tab-btn:hover {
  background: var(--color-bg-sunken);
  color: var(--color-text);
}
.tab-btn.active {
  background: var(--color-accent);
  color: var(--color-text-inverse);
}
.tab-content {
  display: none;
}
.tab-content.active {
  display: block;
}

/* GS Stats Card */
.gs-stats-card {
  padding: var(--space-lg);
  text-align: center;
  background: linear-gradient(135deg, var(--color-accent) 0%, #00A3E0 100%);
  border-radius: var(--radius-md);
  margin: var(--space-md);
  color: var(--color-text-inverse);
}
.gs-stats-card--team {
  background: linear-gradient(135deg, #6B5B95 0%, #9B59B6 100%);
}
.gs-team-name {
  font-size: var(--text-lg);
  font-weight: var(--weight-bold);
  margin-bottom: var(--space-md);
  opacity: 0.9;
}
.gs-main-stat {
  margin-bottom: var(--space-md);
}
.gs-points {
  font-size: 48px;
  font-weight: var(--weight-bold);
  line-height: 1;
}
.gs-points-label {
  font-size: var(--text-sm);
  opacity: 0.8;
  margin-top: var(--space-xs);
  text-transform: uppercase;
  letter-spacing: 1px;
}
.gs-details {
  display: flex;
  justify-content: center;
  gap: var(--space-lg);
  padding-top: var(--space-md);
  border-top: 1px solid rgba(255,255,255,0.2);
}
.gs-detail {
  display: flex;
  flex-direction: column;
  align-items: center;
}
.gs-detail-value {
  font-size: var(--text-lg);
  font-weight: var(--weight-bold);
}
.gs-detail-label {
  font-size: var(--text-xs);
  opacity: 0.7;
  text-transform: uppercase;
}

/* Profile Card */
.profile-card {
  background: var(--color-bg-surface);
  border-radius: var(--radius-lg);
  overflow: hidden;
  box-shadow: var(--shadow-md);
}
.profile-stripe {
  height: 6px;
  background: linear-gradient(90deg, var(--color-accent) 0%, #00A3E0 100%);
}
.profile-content {
  display: flex;
  gap: var(--space-md);
  padding: var(--space-lg);
  align-items: center;
}
.profile-photo {
  flex-shrink: 0;
  width: 80px;
  height: 80px;
  border-radius: var(--radius-md);
  background: var(--color-bg-sunken);
  display: flex;
  align-items: center;
  justify-content: center;
  overflow: hidden;
}
.photo-placeholder {
  font-size: 40px;
  opacity: 0.5;
}
.profile-info {
  flex: 1;
  min-width: 0;
}
.profile-name {
  font-size: var(--text-xl);
  font-weight: var(--weight-bold);
  margin: 0 0 var(--space-2xs) 0;
  line-height: 1.2;
}
.profile-club {
  display: inline-block;
  color: var(--color-accent-text);
  font-size: var(--text-sm);
  font-weight: var(--weight-medium);
  margin-bottom: var(--space-xs);
}
.profile-club:hover {
  text-decoration: underline;
}
.profile-details {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-sm);
  font-size: var(--text-sm);
  color: var(--color-text-secondary);
}
.profile-detail {
  display: flex;
  align-items: center;
  gap: var(--space-2xs);
}
.profile-detail:not(:last-child)::after {
  content: '•';
  margin-left: var(--space-sm);
  color: var(--color-text-muted);
}

/* Stats Grid layouts */
.stats-grid-4 {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: var(--space-sm);
}
.stats-grid-3 {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: var(--space-sm);
}
.stats-grid-2 {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: var(--space-sm);
}
.stat-box {
  text-align: center;
  padding: var(--space-md) var(--space-xs);
  background: var(--color-bg-surface);
  border-radius: var(--radius-md);
  box-shadow: var(--shadow-sm);
}
.stat-box--gold {
  background: linear-gradient(135deg, #FFD700 0%, #FFA500 100%);
  color: #000;
}
.stat-box--gold .stat-label {
  color: rgba(0,0,0,0.7);
}
.stat-box--accent {
  background: var(--color-accent);
  color: var(--color-text-inverse);
}
.stat-box--accent .stat-label {
  color: rgba(255,255,255,0.8);
}
.stat-box--series {
  background: linear-gradient(135deg, var(--color-accent) 0%, #00A3E0 100%);
  color: var(--color-text-inverse);
}
.stat-box--series .stat-label {
  color: rgba(255,255,255,0.85);
}
.stat-box--team {
  background: linear-gradient(135deg, #6B5B95 0%, #9B59B6 100%);
  color: var(--color-text-inverse);
}
.stat-box--team .stat-label {
  color: rgba(255,255,255,0.85);
}
.stat-sub {
  font-size: var(--text-xs);
  color: rgba(255,255,255,0.75);
  margin-top: var(--space-xs);
  line-height: 1.3;
}
.stat-value {
  font-size: var(--text-2xl);
  font-weight: var(--weight-bold);
  line-height: 1;
}
.stat-label {
  font-size: var(--text-xs);
  color: var(--color-text-muted);
  margin-top: var(--space-2xs);
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

.col-place {
  width: 50px;
  text-align: center;
  font-weight: var(--weight-bold);
}
.col-place--1 { color: #FFD700; }
.col-place--2 { color: #C0C0C0; }
.col-place--3 { color: #CD7F32; }

.col-time {
  text-align: right;
  font-family: var(--font-mono);
  white-space: nowrap;
}
.col-points {
  text-align: right;
}
.points-value {
  font-weight: var(--weight-semibold);
  color: var(--color-accent-text);
}

.event-link {
  color: var(--color-text);
  font-weight: var(--weight-medium);
}
.event-link:hover {
  color: var(--color-accent-text);
}

.status-mini {
  font-size: var(--text-xs);
  font-weight: var(--weight-bold);
  color: var(--color-text-muted);
}

.result-place.top-3 {
  background: var(--color-accent-light);
}
.result-time-col {
  text-align: right;
}
.time-value {
  font-family: var(--font-mono);
  font-size: var(--text-sm);
}
.points-small {
  font-size: var(--text-xs);
  color: var(--color-accent-text);
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
  .profile-content {
    padding: var(--space-md);
  }
  .profile-photo {
    width: 64px;
    height: 64px;
  }
  .photo-placeholder {
    font-size: 32px;
  }
  .profile-name {
    font-size: var(--text-lg);
  }
  .stats-grid-4 {
    grid-template-columns: repeat(2, 1fr);
    gap: var(--space-sm);
  }
  .stats-grid-3 {
    grid-template-columns: repeat(3, 1fr);
    gap: var(--space-xs);
  }
  .stats-grid-2 {
    grid-template-columns: repeat(2, 1fr);
    gap: var(--space-xs);
  }
  .stat-box {
    padding: var(--space-sm);
  }
  .stat-value {
    font-size: var(--text-xl);
  }
  .stat-label {
    font-size: 10px;
  }
  .stat-sub {
    font-size: 9px;
  }
}
</style>

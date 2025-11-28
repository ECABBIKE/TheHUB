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

    // Fetch rider's results with position info (exclude DNS)
    $stmt = $db->prepare("
        SELECT
            res.id, res.finish_time, res.status, res.points, res.position, res.class_position,
            e.id as event_id, e.name as event_name, e.date as event_date, e.location,
            s.id as series_id, s.name as series_name,
            cls.display_name as class_name
        FROM results res
        JOIN events e ON res.event_id = e.id
        LEFT JOIN series s ON e.series_id = s.id
        LEFT JOIN classes cls ON res.class_id = cls.id
        WHERE res.cyclist_id = ? AND res.status != 'dns'
        ORDER BY e.date DESC
    ");
    $stmt->execute([$riderId]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

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

<nav class="breadcrumb mb-md">
  <a href="/v3/riders" class="breadcrumb-link">Deltagare</a>
  <span class="breadcrumb-separator">›</span>
  <span class="breadcrumb-current"><?= $fullName ?></span>
</nav>

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
<section class="stats-row mb-lg">
  <div class="stat-box">
    <div class="stat-value"><?= $totalStarts ?></div>
    <div class="stat-label">Starter</div>
  </div>
  <div class="stat-box">
    <div class="stat-value"><?= $finishedRaces ?></div>
    <div class="stat-label">Fullföljt</div>
  </div>
  <?php if ($wins > 0): ?>
  <div class="stat-box stat-box--gold">
    <div class="stat-value"><?= $wins ?></div>
    <div class="stat-label">Segrar</div>
  </div>
  <?php endif; ?>
  <?php if ($podiums > 0): ?>
  <div class="stat-box stat-box--accent">
    <div class="stat-value"><?= $podiums ?></div>
    <div class="stat-label">Pallplatser</div>
  </div>
  <?php elseif ($bestPosition): ?>
  <div class="stat-box">
    <div class="stat-value">#<?= $bestPosition ?></div>
    <div class="stat-label">Bästa</div>
  </div>
  <?php endif; ?>
</section>

<!-- Results -->
<section class="card">
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

.mb-md { margin-bottom: var(--space-md); }
.mb-lg { margin-bottom: var(--space-lg); }
.text-muted { color: var(--color-text-muted); }

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

/* Stats Row */
.stats-row {
  display: flex;
  gap: var(--space-sm);
  flex-wrap: wrap;
}
.stat-box {
  flex: 1;
  min-width: 70px;
  text-align: center;
  padding: var(--space-md) var(--space-sm);
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
  .stat-box {
    min-width: 60px;
    padding: var(--space-sm);
  }
  .stat-value {
    font-size: var(--text-xl);
  }
}
</style>

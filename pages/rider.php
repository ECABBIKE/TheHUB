<?php
/**
 * V3 Single Rider Page - Rider profile with results and ranking
 */

$db = hub_db();
$riderId = intval($pageInfo['params']['id'] ?? 0);

// Include ranking functions for weighted ranking display
$rankingFunctionsLoaded = false;
$rankingPaths = [
    dirname(__DIR__) . '/includes/ranking_functions.php',
    __DIR__ . '/../includes/ranking_functions.php',
];
foreach ($rankingPaths as $path) {
    if (file_exists($path)) {
        require_once $path;
        $rankingFunctionsLoaded = true;
        break;
    }
}

if (!$riderId) {
 header('Location: /riders');
 exit;
}

try {
 // Fetch rider details
 $stmt = $db->prepare("
 SELECT
  r.id, r.firstname, r.lastname, r.birth_year, r.gender,
  r.license_number, r.license_type, r.license_year, r.license_valid_until, r.gravity_id, r.active,
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
  COALESCE(cls.awards_points, 1) as awards_podiums,
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
 // Also check class name for motion/sport patterns (backup until migration runs)
 foreach ($results as &$result) {
 if ($result['status'] !== 'finished') {
  $result['class_position'] = null;
 }
 // Override awards_podiums based on class name pattern (motion/sport = non-competitive)
 $className = strtolower($result['class_name'] ?? '');
 if (strpos($className, 'motion') !== false || strpos($className, 'sport') !== false) {
  $result['awards_podiums'] = 0;
 }
 }
 unset($result);

 // Calculate stats - only count podiums/wins from competitive classes (awards_points = 1)
 $totalStarts = count($results);
 $finishedRaces = count(array_filter($results, fn($r) => $r['status'] === 'finished'));
 $totalPoints = array_sum(array_column($results, 'points'));
 // Motion/Sportmotion classes don't count for podiums/wins (awards_points = 0 or name contains motion/sport)
 $podiums = count(array_filter($results, fn($r) => $r['class_position'] && $r['class_position'] <= 3 && $r['awards_podiums']));
 $wins = count(array_filter($results, fn($r) => $r['class_position'] == 1 && $r['awards_podiums']));
 // Best position also only counts competitive classes
 $bestPosition = null;
 foreach ($results as $r) {
 if ($r['class_position'] && $r['status'] === 'finished' && $r['awards_podiums']) {
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

 // GS Total - Event breakdown (which events gave points)
 $gsEventBreakdown = [];
 if ($totalSeries) {
 $stmt = $db->prepare("
  SELECT
  sr.points,
  e.id as event_id,
  e.name as event_name,
  e.date as event_date,
  cls.display_name as class_name
  FROM series_results sr
  JOIN events e ON sr.event_id = e.id
  LEFT JOIN classes cls ON sr.class_id = cls.id
  WHERE sr.series_id = ? AND sr.cyclist_id = ? AND sr.points > 0
  ORDER BY e.date DESC
 ");
 $stmt->execute([$totalSeries['id'], $riderId]);
 $gsEventBreakdown = $stmt->fetchAll(PDO::FETCH_ASSOC);
 }

 // Ranking stats (24 months rolling) - using weighted ranking system
 $rankingPoints = 0;
 $rankingPosition = null;
 $rankingTotal = 0;
 $rankingMonths = 24;
 $rankingEventBreakdown = [];

 // Get parent db for ranking functions
 $parentDb = function_exists('getDB') ? getDB() : null;

 if ($rankingFunctionsLoaded && $parentDb && function_exists('getRiderRankingDetails')) {
     // Use the weighted ranking system
     $riderRankingDetails = getRiderRankingDetails($parentDb, $riderId, 'GRAVITY');

     if ($riderRankingDetails) {
         $rankingPoints = $riderRankingDetails['total_ranking_points'] ?? 0;
         $rankingPosition = $riderRankingDetails['ranking_position'] ?? null;
         $rankingEventBreakdown = $riderRankingDetails['events'] ?? [];

         // Get total riders count from ranking stats
         if (function_exists('getRankingStats')) {
             $stats = getRankingStats($parentDb);
             $rankingTotal = $stats['GRAVITY']['riders'] ?? 0;
         }
     }
 } else {
     // Fallback: Simple sum (no weighting)
     $cutoffDate = date('Y-m-d', strtotime("-{$rankingMonths} months"));

     $stmt = $db->prepare("
         SELECT COALESCE(SUM(res.points), 0) as total_points
         FROM results res
         JOIN events e ON res.event_id = e.id
         WHERE res.cyclist_id = ?
         AND res.status = 'finished'
         AND res.points > 0
         AND e.date >= ?
     ");
     $stmt->execute([$riderId, $cutoffDate]);
     $rankingStats = $stmt->fetch(PDO::FETCH_ASSOC);
     $rankingPoints = $rankingStats['total_points'] ?? 0;

     if ($rankingPoints > 0) {
         $stmt = $db->prepare("
             SELECT res.cyclist_id, SUM(res.points) as total_points
             FROM results res
             JOIN events e ON res.event_id = e.id
             WHERE res.status = 'finished'
             AND res.points > 0
             AND e.date >= ?
             GROUP BY res.cyclist_id
             ORDER BY total_points DESC
         ");
         $stmt->execute([$cutoffDate]);
         $allRankings = $stmt->fetchAll(PDO::FETCH_ASSOC);

         $rankingTotal = count($allRankings);
         $position = 1;
         foreach ($allRankings as $ranking) {
             if ($ranking['cyclist_id'] == $riderId) {
                 $rankingPosition = $position;
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

// Check for profile image
$profileImage = null;
$profileImageDir = dirname(__DIR__) . '/uploads/riders/';
$profileImageUrl = '/uploads/riders/';
foreach (['jpg', 'jpeg', 'png', 'webp'] as $ext) {
    if (file_exists($profileImageDir . $riderId . '.' . $ext)) {
        $profileImage = $profileImageUrl . $riderId . '.' . $ext . '?v=' . filemtime($profileImageDir . $riderId . '.' . $ext);
        break;
    }
}

// Check license status
$hasLicense = !empty($rider['license_type']);
$licenseActive = false;
if ($hasLicense) {
    // Check license_year first (e.g., 2025 = active if current year <= 2025)
    if (!empty($rider['license_year']) && $rider['license_year'] >= date('Y')) {
        $licenseActive = true;
    }
    // Fallback to license_valid_until
    elseif (!empty($rider['license_valid_until']) && $rider['license_valid_until'] !== '0000-00-00') {
        $licenseActive = strtotime($rider['license_valid_until']) >= strtotime('today');
    }
}

// Extract Gravity ID number (e.g., "GID-034" -> "34")
$gravityIdNumber = null;
if (!empty($rider['gravity_id'])) {
    if (preg_match('/(\d+)$/', $rider['gravity_id'], $matches)) {
        $gravityIdNumber = intval($matches[1]);
    } else {
        $gravityIdNumber = $rider['gravity_id'];
    }
}
?>

<?php if (isset($error)): ?>
<section class="card mb-lg">
 <div class="card-title" style="color: var(--color-error)">Fel</div>
 <p><?= htmlspecialchars($error) ?></p>
</section>
<?php endif; ?>

<!-- Profile Card with Ranking -->
<section class="profile-card mb-lg">
 <div class="profile-stripe"></div>
 <?php if ($rankingPosition): ?>
 <div class="profile-ranking">
 <div class="ranking-position">#<?= $rankingPosition ?></div>
 <div class="ranking-label">Ranking</div>
 </div>
 <?php endif; ?>
 <div class="profile-content">
 <div class="profile-photo">
 <?php if ($profileImage): ?>
 <img src="<?= htmlspecialchars($profileImage) ?>" alt="<?= $fullName ?>" class="profile-photo-img">
 <?php else: ?>
 <div class="photo-placeholder">👤</div>
 <?php endif; ?>
 </div>
 <div class="profile-info">
 <div class="profile-name-row">
 <h1 class="profile-name"><?= $fullName ?></h1>
 <?php if ($age): ?><span class="profile-age"><?= $age ?> år</span><?php endif; ?>
 </div>
 <?php if ($rider['club_name']): ?>
 <a href="/club/<?= $rider['club_id'] ?>" class="profile-club"><?= htmlspecialchars($rider['club_name']) ?></a>
 <?php endif; ?>
 <?php if ($rider['license_number']): ?>
 <div class="profile-details">
  <span class="profile-detail"><i data-lucide="hash"></i> UCI <?= htmlspecialchars($rider['license_number']) ?></span>
 </div>
 <?php endif; ?>
 <div class="profile-badges">
 <?php if ($hasLicense): ?>
  <span class="license-badge <?= $licenseActive ? 'license-active' : 'license-inactive' ?>">
  <i data-lucide="<?= $licenseActive ? 'check-circle' : 'x-circle' ?>"></i>
  <?= htmlspecialchars($rider['license_type']) ?>
  </span>
 <?php endif; ?>
 <?php if (!empty($rider['gravity_id'])): ?>
  <span class="gravity-badge"><i data-lucide="zap"></i> Gravity ID: <?= $gravityIdNumber ?></span>
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
 <button class="tab-btn" data-tab="ranking">Ranking</button>
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
 </tr>
 </thead>
 <tbody>
 <?php foreach ($results as $result): ?>
 <tr onclick="window.location='/event/<?= $result['event_id'] ?>'" style="cursor:pointer">
  <td class="col-place <?= $result['class_position'] && $result['class_position'] <= 3 ? 'col-place--' . $result['class_position'] : '' ?>">
  <?php if ($result['status'] !== 'finished'): ?>
  <span class="status-mini"><?= strtoupper(substr($result['status'], 0, 3)) ?></span>
  <?php elseif ($result['class_position'] == 1): ?>
  <img src="/assets/icons/medal-1st.svg" alt="1:a" class="medal-icon">
  <?php elseif ($result['class_position'] == 2): ?>
  <img src="/assets/icons/medal-2nd.svg" alt="2:a" class="medal-icon">
  <?php elseif ($result['class_position'] == 3): ?>
  <img src="/assets/icons/medal-3rd.svg" alt="3:e" class="medal-icon">
  <?php else: ?>
  <?= $result['class_position'] ?? '-' ?>
  <?php endif; ?>
  </td>
  <td>
  <a href="/event/<?= $result['event_id'] ?>" class="event-link">
  <?= htmlspecialchars($result['event_name']) ?>
  </a>
  </td>
  <td class="table-col-hide-portrait text-muted">
  <?php if ($result['series_id']): ?>
  <a href="/series/<?= $result['series_id'] ?>"><?= htmlspecialchars($result['series_name']) ?></a>
  <?php else: ?>
  -
  <?php endif; ?>
  </td>
  <td class="table-col-hide-portrait"><?= htmlspecialchars($result['class_name'] ?? '-') ?></td>
  <td class="table-col-hide-portrait"><?= $result['event_date'] ? date('j M Y', strtotime($result['event_date'])) : '-' ?></td>
  <td class="col-time"><?= htmlspecialchars($result['finish_time'] ?? '-') ?></td>
 </tr>
 <?php endforeach; ?>
 </tbody>
 </table>
 </div>

 <!-- Mobile Card View -->
 <div class="result-list">
 <?php foreach ($results as $result): ?>
 <a href="/event/<?= $result['event_id'] ?>" class="result-item">
 <div class="result-place <?= $result['class_position'] && $result['class_position'] <= 3 ? 'top-3' : '' ?>">
 <?php if ($result['status'] !== 'finished'): ?>
  <span class="status-mini"><?= strtoupper(substr($result['status'], 0, 3)) ?></span>
 <?php elseif ($result['class_position'] == 1): ?>
  <img src="/assets/icons/medal-1st.svg" alt="1:a" class="medal-icon-mobile">
 <?php elseif ($result['class_position'] == 2): ?>
  <img src="/assets/icons/medal-2nd.svg" alt="2:a" class="medal-icon-mobile">
 <?php elseif ($result['class_position'] == 3): ?>
  <img src="/assets/icons/medal-3rd.svg" alt="3:e" class="medal-icon-mobile">
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
 </div>
 </a>
 <?php endforeach; ?>
 </div>
 <?php endif; ?>
</section>

<!-- Tab: Ranking -->
<section class="tab-content" id="tab-ranking">
 <div class="card">
 <div class="card-header">
 <h2 class="card-title">Ranking</h2>
 <p class="card-subtitle">Viktade poäng senaste <?= $rankingMonths ?> månaderna</p>
 </div>
 <div class="gs-stats-card gs-stats-card--ranking">
 <div class="gs-main-stat">
 <div class="gs-points"><?= number_format($rankingPoints, 1) ?></div>
 <div class="points-label">Rankingpoäng</div>
 </div>
 <?php if ($rankingPosition): ?>
 <div class="gs-details">
 <div class="gs-detail">
  <span class="gs-detail-value">#<?= $rankingPosition ?></span>
  <span class="gs-detail-label">Position</span>
 </div>
 <div class="gs-detail">
  <span class="gs-detail-value"><?= $rankingTotal ?></span>
  <span class="gs-detail-label">Totalt</span>
 </div>
 </div>
 <?php endif; ?>
 </div>

 <?php if (!empty($rankingEventBreakdown)): ?>
 <div class="ranking-breakdown">
 <h3 class="breakdown-title">Poängberäkning per event</h3>
 <p class="breakdown-help">Poäng viktas efter: fältstorlek (0.75-1.00), eventtyp (nationell 100%, sportmotion 50%), och ålder (0-12 mån = 100%, 13-24 mån = 50%)</p>

 <div class="table-wrapper">
  <table class="table table--striped ranking-breakdown-table">
  <thead>
  <tr>
   <th>Event</th>
   <th class="table-col-hide-portrait">Klass</th>
   <th class="table-col-hide-portrait">Datum</th>
   <th class="text-right">Bas</th>
   <th class="text-right table-col-hide-portrait">Fält</th>
   <th class="text-right table-col-hide-portrait">Typ</th>
   <th class="text-right table-col-hide-portrait">Tid</th>
   <th class="text-right">Viktat</th>
  </tr>
  </thead>
  <tbody>
  <?php foreach ($rankingEventBreakdown as $evt): ?>
  <tr onclick="window.location='/event/<?= $evt['event_id'] ?>'" style="cursor:pointer">
   <td>
   <a href="/event/<?= $evt['event_id'] ?>" class="event-link">
    <?= htmlspecialchars($evt['event_name'] ?? 'Okänt event') ?>
   </a>
   </td>
   <td class="table-col-hide-portrait text-muted"><?= htmlspecialchars($evt['class_name'] ?? '-') ?></td>
   <td class="table-col-hide-portrait text-muted"><?= isset($evt['event_date']) ? date('j M Y', strtotime($evt['event_date'])) : '-' ?></td>
   <td class="text-right"><?= number_format($evt['original_points'] ?? 0, 0) ?></td>
   <td class="text-right table-col-hide-portrait text-muted">×<?= number_format($evt['field_multiplier'] ?? 1, 2) ?></td>
   <td class="text-right table-col-hide-portrait text-muted">×<?= number_format($evt['event_level_multiplier'] ?? 1, 2) ?></td>
   <td class="text-right table-col-hide-portrait text-muted">×<?= number_format($evt['time_multiplier'] ?? 1, 2) ?></td>
   <td class="text-right"><strong class="text-accent"><?= number_format($evt['weighted_points'] ?? 0, 1) ?></strong></td>
  </tr>
  <?php endforeach; ?>
  </tbody>
  <tfoot>
  <tr>
   <td colspan="7" class="text-right"><strong>Totalt:</strong></td>
   <td class="text-right"><strong class="text-accent"><?= number_format($rankingPoints, 1) ?></strong></td>
  </tr>
  </tfoot>
  </table>
 </div>

 <!-- Mobile Card View -->
 <div class="ranking-breakdown-cards">
  <?php foreach ($rankingEventBreakdown as $evt): ?>
  <a href="/event/<?= $evt['event_id'] ?>" class="breakdown-card">
  <div class="breakdown-card-main">
   <div class="breakdown-card-name"><?= htmlspecialchars($evt['event_name'] ?? 'Okänt event') ?></div>
   <div class="breakdown-card-meta">
   <?= isset($evt['event_date']) ? date('j M Y', strtotime($evt['event_date'])) : '' ?>
   <?php if (!empty($evt['class_name'])): ?> • <?= htmlspecialchars($evt['class_name']) ?><?php endif; ?>
   </div>
  </div>
  <div class="breakdown-card-points">
   <div class="breakdown-card-weighted"><?= number_format($evt['weighted_points'] ?? 0, 1) ?></div>
   <div class="breakdown-card-calc"><?= number_format($evt['original_points'] ?? 0, 0) ?> × <?= number_format(($evt['field_multiplier'] ?? 1) * ($evt['event_level_multiplier'] ?? 1) * ($evt['time_multiplier'] ?? 1), 2) ?></div>
  </div>
  </a>
  <?php endforeach; ?>
 </div>
 </div>
 <?php endif; ?>
 </div>
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
 <div class="points-label">Poäng</div>
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

 <?php if (!empty($gsEventBreakdown)): ?>
 <div class="event-breakdown">
 <h3 class="breakdown-title">Poäng per event</h3>
 <div class="breakdown-list">
 <?php foreach ($gsEventBreakdown as $event): ?>
 <a href="/event/<?= $event['event_id'] ?>" class="breakdown-item">
  <div class="breakdown-info">
  <div class="breakdown-name"><?= htmlspecialchars($event['event_name']) ?></div>
  <div class="breakdown-meta">
  <?= $event['event_date'] ? date('j M Y', strtotime($event['event_date'])) : '' ?>
  <?php if ($event['class_name']): ?> • <?= htmlspecialchars($event['class_name']) ?><?php endif; ?>
  </div>
  </div>
  <div class="breakdown-points">+<?= $event['points'] ?></div>
 </a>
 <?php endforeach; ?>
 </div>
 </div>
 <?php endif; ?>
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
 <div class="points-label">Lagpoäng</div>
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

 <?php if (!empty($gsEventBreakdown)): ?>
 <div class="event-breakdown">
 <h3 class="breakdown-title">Ditt bidrag till laget</h3>
 <div class="breakdown-list">
 <?php foreach ($gsEventBreakdown as $event): ?>
 <a href="/event/<?= $event['event_id'] ?>" class="breakdown-item">
  <div class="breakdown-info">
  <div class="breakdown-name"><?= htmlspecialchars($event['event_name']) ?></div>
  <div class="breakdown-meta">
  <?= $event['event_date'] ? date('j M Y', strtotime($event['event_date'])) : '' ?>
  </div>
  </div>
  <div class="breakdown-points">+<?= $event['points'] ?></div>
 </a>
 <?php endforeach; ?>
 </div>
 </div>
 <?php endif; ?>
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
.gs-stats-card--ranking {
 background: linear-gradient(135deg, #2ECC71 0%, #27AE60 100%);
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
.points-label {
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
 position: relative;
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
.profile-photo-img {
 width: 100%;
 height: 100%;
 object-fit: cover;
 border-radius: var(--radius-md);
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
.profile-detail i {
 width: 14px;
 height: 14px;
 color: var(--color-accent);
 flex-shrink: 0;
}
.profile-badges {
 display: flex;
 gap: var(--space-xs);
 margin-top: var(--space-xs);
}
.profile-name-row {
 display: flex;
 align-items: baseline;
 gap: var(--space-sm);
 flex-wrap: wrap;
}
.profile-age {
 font-size: var(--text-sm);
 color: var(--color-text-muted);
 font-weight: var(--weight-normal);
}
.gravity-badge,
.license-badge {
 display: inline-flex;
 align-items: center;
 gap: var(--space-2xs);
 padding: var(--space-2xs) var(--space-sm);
 font-size: var(--text-xs);
 font-weight: var(--weight-semibold);
 border-radius: var(--radius-sm);
}
.gravity-badge {
 background: linear-gradient(135deg, #FFD700 0%, #FFA500 100%);
 color: #1a1a1a;
}
.license-badge.license-active {
 background: linear-gradient(135deg, #10B981 0%, #059669 100%);
 color: #fff;
}
.license-badge.license-inactive {
 background: linear-gradient(135deg, #EF4444 0%, #DC2626 100%);
 color: #fff;
}
.gravity-badge i,
.license-badge i {
 width: 12px;
 height: 12px;
}

/* Profile Ranking Badge */
.profile-ranking {
 position: absolute;
 top: var(--space-md);
 right: var(--space-md);
 text-align: center;
 padding: var(--space-xs) var(--space-sm);
 background: linear-gradient(135deg, #2ECC71 0%, #27AE60 100%);
 border-radius: var(--radius-md);
 color: #fff;
 z-index: 1;
}
.ranking-position {
 font-size: var(--text-lg);
 font-weight: var(--weight-bold);
 line-height: 1;
}
.ranking-label {
 font-size: 9px;
 opacity: 0.85;
 text-transform: uppercase;
 margin-top: 2px;
}

/* Event Breakdown */
.event-breakdown {
 padding: var(--space-md);
 border-top: 1px solid var(--color-border);
}
.breakdown-title {
 font-size: var(--text-sm);
 font-weight: var(--weight-semibold);
 color: var(--color-text-secondary);
 margin: 0 0 var(--space-sm) 0;
}
.breakdown-list {
 display: flex;
 flex-direction: column;
 gap: var(--space-xs);
}
.breakdown-item {
 display: flex;
 justify-content: space-between;
 align-items: center;
 padding: var(--space-sm);
 background: var(--color-bg-sunken);
 border-radius: var(--radius-sm);
 text-decoration: none;
 color: inherit;
 transition: background var(--transition-fast);
}
.breakdown-item:hover {
 background: var(--color-bg-hover);
}
.breakdown-info {
 min-width: 0;
}
.breakdown-name {
 font-weight: var(--weight-medium);
 white-space: nowrap;
 overflow: hidden;
 text-overflow: ellipsis;
}
.breakdown-meta {
 font-size: var(--text-xs);
 color: var(--color-text-muted);
}
.breakdown-points {
 font-weight: var(--weight-bold);
 color: var(--color-accent-text);
 flex-shrink: 0;
 margin-left: var(--space-sm);
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

.medal-icon {
 width: 28px;
 height: 28px;
 display: block;
 margin: 0 auto;
}
.medal-icon-mobile {
 width: 32px;
 height: 32px;
 display: block;
 margin: 0 auto;
}

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
 padding-right: var(--space-xl);
 }
 .profile-photo {
 width: 64px;
 height: 64px;
 }
 .photo-placeholder {
 font-size: 32px;
 }
 .profile-name {
 font-size: var(--text-md);
 }
 .profile-age {
 font-size: var(--text-xs);
 }
 .profile-details {
 flex-direction: column;
 gap: var(--space-xs);
 }
 .profile-detail:not(:last-child)::after {
 display: none;
 }
 .profile-ranking {
 top: var(--space-sm);
 right: var(--space-sm);
 padding: var(--space-2xs) var(--space-xs);
 }
 .ranking-position {
 font-size: var(--text-sm);
 }
 .ranking-label {
 font-size: 8px;
 }
 .profile-badges {
 flex-wrap: wrap;
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

/* Ranking Breakdown Styles */
.ranking-breakdown {
 padding: var(--space-md);
 border-top: 1px solid var(--color-border);
}
.breakdown-title {
 font-size: var(--text-base);
 font-weight: var(--weight-semibold);
 margin: 0 0 var(--space-xs) 0;
}
.breakdown-help {
 font-size: var(--text-xs);
 color: var(--color-text-muted);
 margin: 0 0 var(--space-md) 0;
}
.ranking-breakdown-table {
 font-size: var(--text-sm);
}
.ranking-breakdown-table th {
 font-size: var(--text-xs);
 text-transform: uppercase;
 color: var(--color-text-muted);
}
.ranking-breakdown-table tfoot td {
 border-top: 2px solid var(--color-border);
 padding-top: var(--space-sm);
}
.text-accent {
 color: var(--color-accent-text);
}

/* Mobile ranking breakdown cards */
.ranking-breakdown-cards {
 display: none;
 flex-direction: column;
 gap: var(--space-xs);
}
.breakdown-card {
 display: flex;
 justify-content: space-between;
 align-items: center;
 padding: var(--space-sm);
 background: var(--color-bg-sunken);
 border-radius: var(--radius-sm);
 text-decoration: none;
 color: inherit;
}
.breakdown-card:hover {
 background: var(--color-bg-hover);
}
.breakdown-card-main {
 flex: 1;
 min-width: 0;
}
.breakdown-card-name {
 font-weight: var(--weight-medium);
 white-space: nowrap;
 overflow: hidden;
 text-overflow: ellipsis;
}
.breakdown-card-meta {
 font-size: var(--text-xs);
 color: var(--color-text-muted);
}
.breakdown-card-points {
 text-align: right;
 flex-shrink: 0;
 margin-left: var(--space-sm);
}
.breakdown-card-weighted {
 font-size: var(--text-lg);
 font-weight: var(--weight-bold);
 color: var(--color-accent-text);
}
.breakdown-card-calc {
 font-size: var(--text-xs);
 color: var(--color-text-muted);
}

@media (max-width: 599px) {
 .ranking-breakdown .table-wrapper {
  display: none;
 }
 .ranking-breakdown-cards {
  display: flex;
 }
}
</style>

<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/class-calculations.php';
require_once __DIR__ . '/includes/ranking_functions.php';

$db = getDB();

// Get rider ID from URL
$riderId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$riderId) {
 header('Location: /riders');
 exit;
}

// Fetch rider details
// IMPORTANT: Only select PUBLIC fields - never expose private data (personnummer, address, phone, etc.)
// Try to select new fields, fall back to old schema if they don't exist
try {
 $rider = $db->getRow("
 SELECT
  r.id,
  r.firstname,
  r.lastname,
  r.birth_year,
  r.gender,
  r.club_id,
  r.license_number,
  r.license_type,
  r.license_category,
  r.license_year,
  r.discipline,
  r.license_valid_until,
  r.city,
  r.active,
  r.gravity_id,
  c.name as club_name,
  c.city as club_city
 FROM riders r
 LEFT JOIN clubs c ON r.club_id = c.id
 WHERE r.id = ?
", [$riderId]);

 // Set default values for fields that might not exist in database yet
 // license_year is now in the SELECT above
 $rider['team'] = $rider['team'] ?? null;
 $rider['disciplines'] = $rider['disciplines'] ?? null;
 $rider['country'] = $rider['country'] ?? null;
 $rider['district'] = $rider['district'] ?? null;
 $rider['photo'] = $rider['photo'] ?? null;

} catch (Exception $e) {
 // If even basic query fails, something else is wrong
 error_log("Error fetching rider:" . $e->getMessage());
 die("Database error:" . htmlspecialchars($e->getMessage()));
}

if (!$rider) {
 die("Deltagare med ID {$riderId} finns inte i databasen. <a href='/riders'>Gå tillbaka till deltagarlistan</a>");
}

// Fetch rider's results with event details and class position
$results = $db->getAll("
 SELECT
 res.*,
 e.name as event_name,
 e.date as event_date,
 e.location as event_location,
 e.series_id,
 s.name as series_name,
 v.name as venue_name,
 v.city as venue_city,
 cls.name as class_name,
 cls.display_name as class_display_name,
 COALESCE(cls.awards_points, 1) as awards_points,
 COALESCE(cls.series_eligible, 1) as series_eligible,
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
 INNER JOIN events e ON res.event_id = e.id
 LEFT JOIN series s ON e.series_id = s.id
 LEFT JOIN venues v ON e.venue_id = v.id
 LEFT JOIN classes cls ON res.class_id = cls.id
 WHERE res.cyclist_id = ?
 ORDER BY e.date DESC
", [$riderId]);

// Calculate statistics based on class position
$totalRaces = 0;
$podiums = 0;
$wins = 0;
$bestPosition = null;
$totalPoints = 0;
$dnfCount = 0;

foreach ($results as $result) {
 // Skip DNS in statistics - they didn't actually race
 if ($result['status'] === 'dns') continue;

 $totalRaces++;

 // Only count competitive stats for classes that award points
 $awardsPoints = $result['awards_points'] ?? 1;

 // Use class_position for statistics (position within rider's class)
 $classPos = $result['class_position'] ?? null;
 if ($result['status'] === 'finished' && $classPos && $awardsPoints) {
 if ($classPos == 1) $wins++;
 if ($classPos <= 3) $podiums++;
 if ($bestPosition === null || $classPos < $bestPosition) {
  $bestPosition = $classPos;
 }
 }

 // Only count points if class awards points
 if ($awardsPoints) {
 $totalPoints += $result['points'] ?? 0;
 }

 if ($result['status'] === 'dnf') $dnfCount++;
}

// Get recent results (last 5)
$recentResults = array_slice($results, 0, 5);

// Get GravitySeries Total stats (individual championship)
$gravityTotalStats = null;
$gravityTotalPosition = null;
$gravityTotalClassTotal = 0;
$currentClassName = null;
$gravityTotalRaceDetails = [];

// Get GravitySeries Team stats (club points)
$gravityTeamStats = null;
$gravityTeamPosition = null;
$gravityTeamClassTotal = 0;
$gravityTeamRaceDetails = [];

if ($totalRaces > 0) {
 try {
 // Find GravitySeries Total series (id=8, or by name patterns)
 $totalSeries = $db->getRow("
  SELECT id, name FROM series
  WHERE id = 8
  OR (
  active = 1
  AND (
   name LIKE '%Total%'
   OR (name LIKE '%GravitySeries%' AND name NOT LIKE '%Capital%' AND name NOT LIKE '%Götaland%' AND name NOT LIKE '%Jämtland%')
  )
  )
  ORDER BY (id = 8) DESC, year DESC LIMIT 1
 ");

 if ($totalSeries) {
  // Get rider's points in GravitySeries Total (supports both connection methods)
  $gravityTotalStats = $db->getRow("
  SELECT SUM(points) as total_points, COUNT(DISTINCT event_id) as events_count
  FROM (
   -- Via series_events junction table
   SELECT r.points, r.event_id
   FROM results r
   JOIN events e ON r.event_id = e.id
   JOIN series_events se ON e.id = se.event_id
   WHERE se.series_id = ? AND r.cyclist_id = ?
   AND r.status = 'finished' AND r.points > 0

   UNION

   -- Via direct events.series_id
   SELECT r.points, r.event_id
   FROM results r
   JOIN events e ON r.event_id = e.id
   WHERE e.series_id = ? AND r.cyclist_id = ?
   AND e.series_id IS NOT NULL
   AND r.status = 'finished' AND r.points > 0
  ) combined
 ", [$totalSeries['id'], $riderId, $totalSeries['id'], $riderId]);

  // Get rider's most common class from their results in this series (for display)
  $riderResultClass = $db->getRow("
  SELECT class_id, name, display_name, COUNT(*) as cnt
  FROM (
   -- Via series_events junction table
   SELECT r.class_id, c.name, c.display_name
   FROM results r
   JOIN events e ON r.event_id = e.id
   JOIN series_events se ON e.id = se.event_id
   LEFT JOIN classes c ON r.class_id = c.id
   WHERE se.series_id = ? AND r.cyclist_id = ?
   AND r.status = 'finished' AND r.class_id IS NOT NULL

   UNION ALL

   -- Via direct events.series_id
   SELECT r.class_id, c.name, c.display_name
   FROM results r
   JOIN events e ON r.event_id = e.id
   LEFT JOIN classes c ON r.class_id = c.id
   WHERE e.series_id = ? AND r.cyclist_id = ?
   AND e.series_id IS NOT NULL
   AND r.status = 'finished' AND r.class_id IS NOT NULL
  ) combined
  GROUP BY class_id
  ORDER BY cnt DESC
  LIMIT 1
 ", [$totalSeries['id'], $riderId, $totalSeries['id'], $riderId]);

  if ($riderResultClass) {
  $currentClassName = $riderResultClass['display_name'] ?? $riderResultClass['name'] ?? null;
  }

  // Get overall position in series (all classes combined)
  $seriesStandingsAll = $db->getAll("
  SELECT cyclist_id, SUM(points) as total_points
  FROM (
   -- Via series_events junction table
   SELECT r.cyclist_id, r.points
   FROM results r
   JOIN events e ON r.event_id = e.id
   JOIN series_events se ON e.id = se.event_id
   WHERE se.series_id = ?
   AND r.status = 'finished' AND r.points > 0

   UNION ALL

   -- Via direct events.series_id
   SELECT r.cyclist_id, r.points
   FROM results r
   JOIN events e ON r.event_id = e.id
   WHERE e.series_id = ?
   AND e.series_id IS NOT NULL
   AND r.status = 'finished' AND r.points > 0
  ) combined
  GROUP BY cyclist_id
  ORDER BY total_points DESC
 ", [$totalSeries['id'], $totalSeries['id']]);

  $gravityTotalClassTotal = count($seriesStandingsAll);
  $position = 1;
  foreach ($seriesStandingsAll as $standing) {
  if ($standing['cyclist_id'] == $riderId) {
   $gravityTotalPosition = $position;
   break;
  }
  $position++;
  }

  // Get detailed race results for GravitySeries Total (supports both connection methods)
  $gravityTotalRaceDetails = $db->getAll("
  SELECT
   points,
   class_position,
   event_name,
   event_date,
   event_location,
   class_name
  FROM (
   -- Via series_events junction table
   SELECT
   r.points,
   r.class_position,
   e.name as event_name,
   e.date as event_date,
   e.location as event_location,
   cls.display_name as class_name
   FROM results r
   JOIN events e ON r.event_id = e.id
   JOIN series_events se ON e.id = se.event_id
   LEFT JOIN classes cls ON r.class_id = cls.id
   WHERE se.series_id = ? AND r.cyclist_id = ?
   AND r.status = 'finished'

   UNION

   -- Via direct events.series_id
   SELECT
   r.points,
   r.class_position,
   e.name as event_name,
   e.date as event_date,
   e.location as event_location,
   cls.display_name as class_name
   FROM results r
   JOIN events e ON r.event_id = e.id
   LEFT JOIN classes cls ON r.class_id = cls.id
   WHERE e.series_id = ? AND r.cyclist_id = ?
   AND e.series_id IS NOT NULL
   AND r.status = 'finished'
  ) combined
  ORDER BY event_date DESC
 ", [$totalSeries['id'], $riderId, $totalSeries['id'], $riderId]);

  // Get GravitySeries Team stats (club points for this series)
  // Simple direct query - club_rider_points already has series_id
  if ($rider['club_id']) {
  $gravityTeamStats = $db->getRow("
   SELECT SUM(club_points) as total_points, COUNT(DISTINCT event_id) as events_count
   FROM club_rider_points
   WHERE rider_id = ? AND club_id = ? AND series_id = ?
  ", [$riderId, $rider['club_id'], $totalSeries['id']]);

  // Get rider's position within the club for this series
  if ($gravityTeamStats && $gravityTeamStats['total_points'] > 0) {
   $clubStandings = $db->getAll("
   SELECT rider_id, SUM(club_points) as total_points
   FROM club_rider_points
   WHERE club_id = ? AND series_id = ?
   GROUP BY rider_id
   ORDER BY total_points DESC
  ", [$rider['club_id'], $totalSeries['id']]);

   $gravityTeamClassTotal = count($clubStandings);
   $position = 1;
   foreach ($clubStandings as $standing) {
   if ($standing['rider_id'] == $riderId) {
    $gravityTeamPosition = $position;
    break;
   }
   $position++;
   }
  }

  // Get detailed race results for GravitySeries Team
  $gravityTeamRaceDetails = $db->getAll("
   SELECT
   crp.club_points,
   crp.original_points,
   crp.percentage_applied,
   crp.rider_rank_in_club,
   e.name as event_name,
   e.date as event_date,
   e.location as event_location,
   cls.display_name as class_name
   FROM club_rider_points crp
   JOIN events e ON crp.event_id = e.id
   LEFT JOIN classes cls ON crp.class_id = cls.id
   WHERE crp.rider_id = ? AND crp.club_id = ? AND crp.series_id = ?
   ORDER BY e.date DESC
  ", [$riderId, $rider['club_id'], $totalSeries['id']]);
  }
 }
 } catch (Exception $e) {
 error_log("Error getting GravitySeries stats for rider {$riderId}:" . $e->getMessage());
 }
}

// Get ranking statistics for all disciplines
$rankingStats = [];
$rankingHistory = [];
$rankingRaceDetails = [];
$defaultDiscipline = null; // Auto-select based on priority

try {
 foreach (['ENDURO', 'DH', 'GRAVITY'] as $discipline) {
 $riderData = calculateRankingData($db, $discipline, false);

 // Find this rider's ranking
 foreach ($riderData as $data) {
  if ($data['rider_id'] == $riderId) {
  $rankingStats[$discipline] = $data;
  break;
  }
 }

 // Get ranking history (last 6 months of snapshots)
 $rankingHistory[$discipline] = $db->getAll("
  SELECT
  snapshot_date,
  total_ranking_points,
  ranking_position,
  previous_position,
  position_change,
  events_count
  FROM ranking_snapshots
  WHERE rider_id = ? AND discipline = ?
  ORDER BY snapshot_date DESC
  LIMIT 6
 ", [$riderId, $discipline]);

 // Get detailed ranking points per race from ranking_points table
 // If empty or table doesn't exist, fall back to results table
 $disciplineFilter = '';
 $params = [$riderId];

 if ($discipline === 'GRAVITY') {
  $disciplineFilter ="AND e.discipline IN ('ENDURO', 'DH')";
 } else {
  $disciplineFilter ="AND e.discipline = ?";
  $params[] = $discipline;
 }

 // Try to get from ranking_points table, fallback to results if table doesn't exist
 try {
  $rankingRaceDetails[$discipline] = $db->getAll("
  SELECT
   rp.ranking_points,
   rp.original_points,
   rp.field_size,
   rp.field_multiplier,
   rp.event_level_multiplier,
   rp.time_multiplier,
   rp.position,
   e.name as event_name,
   e.date as event_date,
   e.location as event_location,
   e.discipline,
   cls.display_name as class_name
  FROM ranking_points rp
  JOIN events e ON rp.event_id = e.id
  LEFT JOIN classes cls ON rp.class_id = cls.id
  WHERE rp.rider_id = ?
  AND e.date >= DATE_SUB(NOW(), INTERVAL 24 MONTH)
  {$disciplineFilter}
  ORDER BY e.date DESC
 ", $params);
 } catch (Exception $e) {
  // Table doesn't exist or query failed, set to empty to trigger fallback
  $rankingRaceDetails[$discipline] = [];
 }

 // If ranking_points table is empty or doesn't exist, get from results as fallback
 if (empty($rankingRaceDetails[$discipline])) {
  $rawResults = $db->getAll("
  SELECT
   r.event_id,
   r.class_id,
   r.position,
   r.points as original_points,
   e.name as event_name,
   e.date as event_date,
   e.location as event_location,
   e.discipline,
   cls.display_name as class_name
  FROM results r
  JOIN events e ON r.event_id = e.id
  LEFT JOIN classes cls ON r.class_id = cls.id
  WHERE r.cyclist_id = ?
  AND r.status = 'finished'
  AND r.points > 0
  AND e.date >= DATE_SUB(NOW(), INTERVAL 24 MONTH)
  {$disciplineFilter}
  ORDER BY e.date DESC
 ", $params);

  // Convert to expected format with points from results
  foreach ($rawResults as $result) {
  $rankingRaceDetails[$discipline][] = [
   'ranking_points' => $result['original_points'], // Use event points as placeholder
   'original_points' => $result['original_points'],
   'field_size' => 0,
   'field_multiplier' => 1.0,
   'event_level_multiplier' => 1.0,
   'time_multiplier' => 1.0,
   'position' => $result['position'],
   'event_name' => $result['event_name'],
   'event_date' => $result['event_date'],
   'event_location' => $result['event_location'],
   'discipline' => $result['discipline'],
   'class_name' => $result['class_name']
  ];
  }
 }
 }

 // Determine default discipline based on priority: GRAVITY > ENDURO > DH
 if (!empty($rankingStats['GRAVITY'])) {
 $defaultDiscipline = 'GRAVITY';
 } elseif (!empty($rankingStats['ENDURO'])) {
 $defaultDiscipline = 'ENDURO';
 } elseif (!empty($rankingStats['DH'])) {
 $defaultDiscipline = 'DH';
 }
} catch (Exception $e) {
 error_log("Error getting ranking stats for rider {$riderId}:" . $e->getMessage());
}

// Get series standings for this rider - CLASS BASED
// OPTIMIZED: Only run if rider has results to avoid expensive class calculations
$seriesStandings = [];

if ($totalRaces > 0 && $rider['birth_year'] && $rider['gender']) {
 try {
 // Try ENDURO first (most common for GravitySeries), then fallback to other disciplines
 $riderClassId = determineRiderClass($db, $rider['birth_year'], $rider['gender'], date('Y-m-d'), 'ENDURO');
 if (!$riderClassId) {
  $riderClassId = determineRiderClass($db, $rider['birth_year'], $rider['gender'], date('Y-m-d'), 'DH');
 }
 if (!$riderClassId) {
  $riderClassId = determineRiderClass($db, $rider['birth_year'], $rider['gender'], date('Y-m-d'), 'XC');
 }

 if ($riderClassId) {
  $riderClass = $db->getRow("SELECT name, display_name FROM classes WHERE id = ?", [$riderClassId]);

  // Get series data for this rider (supports both connection methods)
  $riderSeriesData = $db->getAll("
  SELECT
   series_id,
   series_name,
   year,
   SUM(points) as total_points,
   COUNT(DISTINCT event_id) as events_count
  FROM (
   -- Via series_events junction table
   SELECT
   s.id as series_id,
   s.name as series_name,
   s.year,
   r.points,
   r.event_id
   FROM results r
   JOIN events e ON r.event_id = e.id
   JOIN series_events se ON e.id = se.event_id
   JOIN series s ON se.series_id = s.id
   WHERE r.cyclist_id = ? AND s.active = 1

   UNION

   -- Via direct events.series_id
   SELECT
   s.id as series_id,
   s.name as series_name,
   s.year,
   r.points,
   r.event_id
   FROM results r
   JOIN events e ON r.event_id = e.id
   JOIN series s ON e.series_id = s.id
   WHERE r.cyclist_id = ? AND s.active = 1
   AND e.series_id IS NOT NULL
  ) combined
  GROUP BY series_id
  ORDER BY year DESC, total_points DESC
 ", [$riderId, $riderId]);

  // Calculate class-based position for each series
  foreach ($riderSeriesData as $seriesData) {
  // Get all riders in this series with the same class and their total points
  $classStandings = $db->getAll("
   SELECT
   cyclist_id,
   SUM(points) as total_points
   FROM (
   -- Via series_events junction table
   SELECT r.cyclist_id, r.points
   FROM results r
   JOIN events e ON r.event_id = e.id
   JOIN series_events se ON e.id = se.event_id
   WHERE se.series_id = ?
   AND r.class_id = ?

   UNION ALL

   -- Via direct events.series_id
   SELECT r.cyclist_id, r.points
   FROM results r
   JOIN events e ON r.event_id = e.id
   WHERE e.series_id = ?
   AND r.class_id = ?
   ) combined
   GROUP BY cyclist_id
   ORDER BY total_points DESC
  ", [$seriesData['series_id'], $riderClassId, $seriesData['series_id'], $riderClassId]);

  // Find this rider's position
  $position = 1;
  $classTotal = count($classStandings);
  foreach ($classStandings as $standing) {
   if ($standing['cyclist_id'] == $riderId) {
   break;
   }
   $position++;
  }

  $seriesData['position'] = $position;
  $seriesData['class_total'] = $classTotal;
  $seriesData['class_name'] = $riderClass['display_name'] ?? '';
  $seriesStandings[] = $seriesData;
  }
 }
 } catch (Exception $e) {
 error_log("Error calculating series standings for rider {$riderId}:" . $e->getMessage());
 // Continue without series standings
 }
}

// Get results by year (exclude DNS - Did Not Start)
$resultsByYear = [];
foreach ($results as $result) {
 // Skip DNS results - they shouldn't be shown at all
 if ($result['status'] === 'dns') continue;

 $year = date('Y', strtotime($result['event_date']));
 if (!isset($resultsByYear[$year])) {
 $resultsByYear[$year] = [];
 }
 $resultsByYear[$year][] = $result;
}
krsort($resultsByYear); // Sort by year descending

// Calculate age and determine current class
$currentYear = date('Y');
$age = ($rider['birth_year'] && $rider['birth_year'] > 0)
 ? ($currentYear - $rider['birth_year'])
 : null;
$currentClass = null;
$currentClassName = null;

if ($rider['birth_year'] && $rider['gender'] && function_exists('determineRiderClass')) {
 try {
 // Try ENDURO first (most common for GravitySeries), then fallback
 $classId = determineRiderClass($db, $rider['birth_year'], $rider['gender'], date('Y-m-d'), 'ENDURO');
 if (!$classId) {
  $classId = determineRiderClass($db, $rider['birth_year'], $rider['gender'], date('Y-m-d'), 'DH');
 }
 if (!$classId) {
  $classId = determineRiderClass($db, $rider['birth_year'], $rider['gender'], date('Y-m-d'), 'XC');
 }
 if ($classId) {
  $class = $db->getRow("SELECT name, display_name FROM classes WHERE id = ?", [$classId]);
  $currentClass = $class['name'] ?? null;
  $currentClassName = $class['display_name'] ?? null;
 }
 } catch (Exception $e) {
 error_log("Error determining class for rider {$riderId}:" . $e->getMessage());
 }
}

// Check license status
try {
 $licenseCheck = checkLicense($rider);
} catch (Exception $e) {
 error_log("Error checking license for rider {$riderId}:" . $e->getMessage());
 $licenseCheck = array('class' => 'badge-secondary', 'message' => 'Okänd status', 'valid' => false);
}

$pageTitle = $rider['firstname'] . ' ' . $rider['lastname'];
$pageType = 'public';

try {
 include __DIR__ . '/includes/layout-header.php';
} catch (Exception $e) {
 error_log("Error loading layout-header.php:" . $e->getMessage());
 // Provide minimal HTML if header fails
 echo"<!DOCTYPE html><html><head><title>" . htmlspecialchars($pageTitle) ."</title></head><body>";
 echo"<h1>Error loading page header</h1><pre>" . htmlspecialchars($e->getMessage()) ."</pre>";
}
?>

 <main class="main-content">
 <div class="container">

  <!-- Back Button -->
  <div class="mb-lg">
  <a href="/riders" class="btn btn--secondary btn--sm">
   <i data-lucide="arrow-left"></i>
   Tillbaka till deltagare
  </a>
  </div>

  <!-- UCI License Card - COMPACT HORIZONTAL -->
  <div class="license-card-container">
  <div class="license-card">
   <!-- Stripe -->
   <div class="uci-stripe"></div>

   <!-- Compact Content: Photo LEFT + Info RIGHT -->
   <div class="license-content">
   <!-- Photo Section LEFT -->
   <div class="license-photo">
    <?php if (!empty($rider['photo'])): ?>
    <img src="<?= h($rider['photo']) ?>" alt="<?= h($rider['firstname'] . ' ' . $rider['lastname']) ?>">
    <?php else: ?>
    <div class="photo-placeholder">👤</div>
    <?php endif; ?>
   </div>

   <!-- Info Section RIGHT -->
   <div class="license-info">
    <!-- Name on one line -->
    <div class="rider-name">
    <?= h($rider['firstname']) ?> <?= h($rider['lastname']) ?>
    </div>

    <!-- UCI License under name -->
    <div class="license-id">
    <?php
    $isUciLicense = !empty($rider['license_number']) && strpos($rider['license_number'], 'SWE') !== 0;
    if ($isUciLicense): ?>
     UCI: <?= h($rider['license_number']) ?>
    <?php elseif (!empty($rider['license_number'])): ?>
     Licens: <?= h($rider['license_number']) ?>
    <?php endif; ?>
    </div>

    <!-- License Type and Active Status -->
    <?php if (!empty($rider['license_type']) && $rider['license_type'] !== 'None'): ?>
    <div class="license-info-inline">
     <span class="license-type-text"><?= h($rider['license_type']) ?></span>
     <?php if (!empty($rider['license_year'])): ?>
     <?php
     $isActive = ($rider['license_year'] == $currentYear);
     ?>
     <span class="license-status-badge <?= $isActive ? 'active' : 'inactive' ?>">
      <?= $isActive ? '✓ Aktiv ' . $currentYear : '✗ ' . $rider['license_year'] ?>
     </span>
     <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Gravity ID Badge -->
    <?php if (!empty($rider['gravity_id'])): ?>
    <div class="gravity-id-badge">
     <span class="gravity-id-icon">★</span>
     <span class="gravity-id-text">Gravity ID: <?= h($rider['gravity_id']) ?></span>
    </div>
    <?php endif; ?>

    <!-- Compact Info: Age, Gender, Club -->
    <div class="info-grid-compact">
    <div class="info-box">
     <div class="info-box-label">Ålder</div>
     <div class="info-box-value">
     <?= $age !== null ? $age : '–' ?>
     </div>
    </div>

    <div class="info-box">
     <div class="info-box-label">Kön</div>
     <div class="info-box-value">
     <?php
     if ($rider['gender'] === 'M') {
      echo 'Man';
     } elseif (in_array($rider['gender'], ['F', 'K'])) {
      echo 'Kvinna';
     } else {
      echo '–';
     }
     ?>
     </div>
    </div>

    <div class="info-box">
     <div class="info-box-label">Klubb</div>
     <div class="info-box-value info-box-value-small">
     <?php if ($rider['club_name'] && $rider['club_id']): ?>
      <a href="/club/<?= $rider['club_id'] ?>" class="club-link" title="Se alla medlemmar i <?= h($rider['club_name']) ?>">
      <?= h($rider['club_name']) ?>
      </a>
     <?php else: ?>
      <?= $rider['club_name'] ? h($rider['club_name']) : '–' ?>
     <?php endif; ?>
     </div>
    </div>
    </div>
   </div><!-- .license-info -->
   </div><!-- .license-content -->
  </div>
  </div>

  <!-- Quick Stats -->
  <style>
  .rider-stats-top {
   display: grid;
   grid-template-columns: repeat(3, 1fr);
   gap: 0.5rem;
   margin-bottom: 0.5rem;
  }
  .rider-stats-bottom {
   display: grid;
   grid-template-columns: repeat(2, 1fr);
   gap: 0.5rem;
   margin-bottom: 1.5rem;
  }
  .rider-stats-top .stat-card-compact,
  .rider-stats-bottom .stat-card-compact {
   padding: 0.75rem 0.5rem;
   text-align: center;
  }
  .rider-stats-top .stat-number-compact {
   font-size: 1.5rem;
  }
  .rider-stats-bottom .stat-number-compact {
   font-size: 1.25rem;
  }
  .rider-stats-top .stat-label-compact,
  .rider-stats-bottom .stat-label-compact {
   font-size: 0.625rem;
  }
  @media (min-width: 640px) {
   .rider-stats-top .stat-number-compact {
   font-size: 2rem;
   }
   .rider-stats-bottom .stat-number-compact {
   font-size: 1.5rem;
   }
   .rider-stats-top .stat-label-compact,
   .rider-stats-bottom .stat-label-compact {
   font-size: 0.75rem;
   }
  }
  </style>
  <div class="rider-stats-top">
  <div class="card stat-card-compact">
   <div class="stat-number-compact text-primary"><?= $totalRaces ?></div>
   <div class="stat-label-compact">Race</div>
  </div>
  <div class="card stat-card-compact">
   <div class="stat-number-compact text-warning"><?= $bestPosition ?? '-' ?></div>
   <div class="stat-label-compact">Bästa</div>
  </div>
  <div class="card stat-card-compact">
   <div class="stat-number-compact text-success"><?= $wins ?></div>
   <div class="stat-label-compact">Segrar</div>
  </div>
  </div>
  <div class="rider-stats-bottom">
  <div class="card stat-card-compact">
   <div class="stat-number-compact text-primary">
   <?= $gravityTotalStats ? number_format($gravityTotalStats['total_points'] ?? 0) : '0' ?>
   </div>
   <div class="stat-label-compact">GravitySeries Total</div>
   <?php if ($currentClassName || $gravityTotalPosition): ?>
   <div class="text-xs text-secondary gs-mt-xs">
    <?php if ($currentClassName): ?>
    <?= h($currentClassName) ?>
    <?php if ($gravityTotalPosition): ?>
     • #<?= $gravityTotalPosition ?>/<?= $gravityTotalClassTotal ?>
    <?php endif; ?>
    <?php elseif ($gravityTotalPosition): ?>
    #<?= $gravityTotalPosition ?> av <?= $gravityTotalClassTotal ?>
    <?php endif; ?>
   </div>
   <?php endif; ?>
  </div>
  <div class="card stat-card-compact">
   <div class="stat-number-compact" style="color: #f59e0b;">
   <?= $gravityTeamStats ? number_format($gravityTeamStats['total_points'] ?? 0, 1) : '0' ?>
   </div>
   <div class="stat-label-compact">GravitySeries Team</div>
   <?php if ($gravityTeamPosition): ?>
   <div class="text-xs text-secondary gs-mt-xs">
    #<?= $gravityTeamPosition ?> av <?= $gravityTeamClassTotal ?> i klubben
   </div>
   <?php endif; ?>
  </div>
  </div>

  <!-- Main Profile Tabs -->
  <div class="card mb-lg">
  <div class="gs-event-tabs-wrapper mb-lg">
   <div class="gs-event-tabs">
   <button class="event-tab active" data-main-tab="ranking-tab">
    <i data-lucide="trending-up"></i>
    Ranking
   </button>
   <button class="event-tab" data-main-tab="gravity-total-tab">
    <i data-lucide="trophy"></i>
    GravitySeries Total
   </button>
   <button class="event-tab" data-main-tab="gravity-team-tab">
    <i data-lucide="users"></i>
    GravitySeries Team
   </button>
   <button class="event-tab" data-main-tab="results-tab">
    <i data-lucide="list"></i>
    Tävlingsresultat
   </button>
   </div>
  </div>

  <!-- Tab 1: Ranking -->
  <div class="gs-main-tab-content active" id="ranking-tab">
   <?php if (!empty($rankingStats)): ?>
   <h2 class="text-primary mb-lg">
    <i data-lucide="trending-up"></i>
    Rankingstatistik (24 månader)
   </h2>

   <!-- Discipline Tabs -->
   <div class="gs-tabs mb-lg">
    <?php foreach (['GRAVITY' => 'Gravity', 'ENDURO' => 'Enduro', 'DH' => 'Downhill'] as $disc => $label): ?>
    <?php if (isset($rankingStats[$disc])): ?>
     <button class="tab <?= $disc === $defaultDiscipline ? 'active' : '' ?>" data-tab="ranking-<?= strtolower($disc) ?>">
     <?= $label ?>
     </button>
    <?php endif; ?>
    <?php endforeach; ?>
   </div>

   <!-- Discipline Tab Content -->
   <?php foreach (['GRAVITY' => 'Gravity', 'ENDURO' => 'Enduro', 'DH' => 'Downhill'] as $disc => $label): ?>
    <?php if (isset($rankingStats[$disc])): ?>
    <?php $stats = $rankingStats[$disc]; ?>
    <div class="tab-content <?= $disc === $defaultDiscipline ? 'active' : '' ?>" id="ranking-<?= strtolower($disc) ?>">

     <!-- Stats Grid -->
     <div class="gs-ranking-stats-grid mb-lg">
     <div class="gs-stat-box">
      <div class="stat-label">Placering</div>
      <div class="stat-value text-primary">#<?= $stats['ranking_position'] ?></div>
     </div>
     <div class="gs-stat-box">
      <div class="stat-label">Totala poäng</div>
      <div class="stat-value text-success"><?= number_format($stats['total_points'], 1) ?></div>
     </div>
     <div class="gs-stat-box">
      <div class="stat-label">Antal events</div>
      <div class="stat-value text-warning"><?= $stats['events_count'] ?></div>
     </div>
     </div>

     <!-- Ranking History -->
     <?php if (!empty($rankingHistory[$disc])): ?>
     <div class="card gs-bg-light mb-lg">
      <div class="card-body">
      <h4 class="text-primary mb-md">
       <i data-lucide="calendar-check"></i>
       Ranking-historik
      </h4>
      <div class="table-responsive">
       <table class="table table-compact">
       <thead>
        <tr>
        <th>Månad</th>
        <th class="text-center">Placering</th>
        <th class="text-center">Förändring</th>
        <th class="text-right">Poäng</th>
        </tr>
       </thead>
       <tbody>
        <?php foreach ($rankingHistory[$disc] as $idx => $history): ?>
        <tr>
         <td><?= date('Y-m', strtotime($history['snapshot_date'])) ?></td>
         <td class="text-center">#<?= $history['ranking_position'] ?></td>
         <td class="text-center">
         <?php if ($history['position_change'] > 0): ?>
          <span class="badge badge-success">
          ↑ +<?= abs($history['position_change']) ?>
          </span>
         <?php elseif ($history['position_change'] < 0): ?>
          <span class="badge badge-danger">
          ↓ <?= $history['position_change'] ?>
          </span>
         <?php else: ?>
          <span class="text-secondary">→ 0</span>
         <?php endif; ?>
         </td>
         <td class="text-right"><?= number_format($history['total_ranking_points'], 1) ?></td>
        </tr>
        <?php endforeach; ?>
       </tbody>
       </table>
      </div>
      </div>
     </div>
     <?php endif; ?>

     <!-- Point Breakdown -->
     <div class="card gs-bg-light mb-lg">
     <div class="card-body">
      <h4 class="text-primary mb-md">
      <i data-lucide="pie-chart"></i>
      Poängfördelning
      </h4>
      <style>
      /* Mobile portrait: Very compact point breakdown */
      @media (max-width: 767px) {
       .points-breakdown .gs-points-row {
       padding: 0.3rem 0;
       gap: 0.5rem;
       }
       .points-breakdown .points-label {
       font-size: 0.7rem;
       flex: 1;
       }
       .points-breakdown .gs-points-value {
       font-size: 0.75rem;
       white-space: nowrap;
       }
       .points-breakdown .points-label i {
       width: 12px;
       height: 12px;
       display: none; /* Hide icons on mobile portrait */
       }
       .points-breakdown .points-label.font-bold {
       font-size: 0.8rem;
       }
       .points-breakdown .gs-points-value.font-bold {
       font-size: 0.9rem;
       }
       .gs-divider {
       margin: 0.3rem 0 !important;
       }
       .card-body h4 {
       font-size: 0.9rem;
       margin-bottom: 0.5rem !important;
       }
      }

      /* Mobile landscape: Slightly larger */
      @media (min-width: 768px) and (max-width: 1023px) {
       .points-breakdown .gs-points-row {
       padding: 0.4rem 0;
       }
       .points-breakdown .points-label {
       font-size: 0.8rem;
       }
       .points-breakdown .gs-points-value {
       font-size: 0.85rem;
       }
       .points-breakdown .points-label i {
       width: 14px;
       height: 14px;
       }
      }
      </style>
      <div class="points-breakdown">
      <div class="gs-points-row">
       <span class="points-label">
       <i data-lucide="calendar-check"></i>
       Senaste 12 månader (100%)
       </span>
       <span class="gs-points-value text-success">
       <?= number_format($stats['points_12'], 1) ?> p
       </span>
      </div>
      <div class="gs-points-row">
       <span class="points-label">
       <i data-lucide="calendar"></i>
       Månad 13-24 (viktade)
       </span>
       <span class="gs-points-value text-secondary">
       <?= number_format($stats['points_13_24'] * 0.5, 1) ?> p
       </span>
      </div>
      <div class="gs-divider gs-my-sm"></div>
      <div class="gs-points-row">
       <span class="points-label font-bold text-lg">
       <i data-lucide="trophy"></i>
       Total ranking
       </span>
       <span class="gs-points-value text-primary font-bold text-lg">
       <?= number_format($stats['total_points'], 1) ?> p
       </span>
      </div>
      </div>
     </div>
     </div>

     <!-- Ranking Race Details -->
     <?php if (!empty($rankingRaceDetails[$disc])): ?>
     <div class="card gs-bg-light">
      <div class="card-body">
      <h4 class="text-primary mb-md">
       <i data-lucide="list"></i>
       Race som gett rankingpoäng
      </h4>
      <style>
       /* Base styles - keep small for mobile */
       .ranking-events-table th,
       .ranking-events-table td {
       font-size: 0.75rem;
       padding: 0.4rem 0.25rem;
       }

       /* Mobile portrait: Hide landscape column */
       @media (max-width: 767px) {
       .ranking-events-table .hide-mobile-portrait {
        display: none !important;
       }
       .ranking-events-table .hide-mobile-landscape {
        display: none !important;
       }
       .ranking-events-table .show-mobile-landscape {
        display: none !important;
       }
       .ranking-events-table th,
       .ranking-events-table td {
        padding: 0.4rem 0.2rem;
       }
       }

       /* Mobile landscape: Show Beräkning column */
       @media (min-width: 768px) and (max-width: 1279px) {
       .ranking-events-table .hide-mobile-portrait {
        display: none !important;
       }
       .ranking-events-table .show-mobile-landscape {
        display: table-cell !important;
        visibility: visible !important;
       }
       .ranking-events-table th,
       .ranking-events-table td {
        padding: 0.4rem 0.3rem;
       }
       }

       /* Desktop: Hide landscape duplicate, show desktop columns */
       @media (min-width: 1280px) {
       .ranking-events-table .hide-mobile-landscape {
        display: none !important;
       }
       .ranking-events-table .show-mobile-landscape {
        display: none !important;
       }
       .ranking-events-table th,
       .ranking-events-table td {
        font-size: 0.85rem;
        padding: 0.5rem 0.3rem;
       }
       }
      </style>
      <div class="table-responsive">
       <table class="table table-compact ranking-events-table">
       <thead>
        <tr>
          <th class="text-center">Placering</th>
        <th>Event</th>
        <th class="text-right" style="display: table-cell !important;">Poäng</th>
        <th class="text-right show-mobile-landscape" style="display: none;">Beräkning</th>
        <th class="hide-mobile-portrait">Datum</th>
        <th class="text-center hide-mobile-portrait">Klass</th>
        <th class="text-center hide-mobile-portrait">Fältstorlek</th>
        <th class="text-right hide-mobile-portrait">Event-poäng</th>
        </tr>
       </thead>
       <tbody>
        <?php foreach ($rankingRaceDetails[$disc] as $idx => $raceDetail): ?>
        <tr>
         <td class="text-center">
         <?php if (!empty($raceDetail['position'])): ?>
          <?php
          $pos = $raceDetail['position'];
          if ($pos == 1) echo '<span class="badge badge-success" style="font-size: 0.7rem; padding: 0.15rem 0.3rem;">🥇</span>';
          elseif ($pos == 2) echo '<span class="badge badge-secondary" style="font-size: 0.7rem; padding: 0.15rem 0.3rem;">🥈</span>';
          elseif ($pos == 3) echo '<span class="badge badge-warning" style="font-size: 0.7rem; padding: 0.15rem 0.3rem;">🥉</span>';
          else echo '<span class="badge badge-secondary" style="font-size: 0.7rem; padding: 0.15rem 0.3rem;">#' . $pos . '</span>';
          ?>
         <?php else: ?>
          <span class="text-secondary">-</span>
         <?php endif; ?>
         </td>
         <td>
         <strong style="font-size: 0.75rem;"><?= h($raceDetail['event_name']) ?></strong>
         <div class="text-xs text-secondary hide-mobile-landscape">
          Field: <?= number_format($raceDetail['field_multiplier'], 2) ?>
          × Event: <?= number_format($raceDetail['event_level_multiplier'], 2) ?>
          × Time: <?= number_format($raceDetail['time_multiplier'], 2) ?>
         </div>
         <button class="btn-link text-xs" onclick="toggleCalc(event, 'calc-<?= $disc ?>-<?= $idx ?>')" style="margin-top: 0.25rem; padding: 0; font-size: 0.7rem; color: #3b82f6;">
          <i data-lucide="help-circle" style="width: 12px; height: 12px;"></i> Visa beräkning
         </button>
         </td>
         <td class="text-right text-primary font-bold" style="font-size: 0.75rem; display: table-cell !important;">
         <?= number_format($raceDetail['ranking_points'] ?? 0, 0) ?>p
         </td>
         <td class="text-right show-mobile-landscape" style="display: none; font-size: 0.7rem; white-space: nowrap;">
         <?php
         // Show calculation: original × field × event_level
         $origPts = number_format($raceDetail['original_points'] ?? 0, 0);
         $fieldMult = number_format($raceDetail['field_multiplier'] ?? 1, 2);
         $eventMult = number_format($raceDetail['event_level_multiplier'] ?? 1, 2);

         // Show simplified calculation if all multipliers are 1.0
         if ($eventMult == '1.00') {
          echo"{$origPts} × {$fieldMult}";
         } else {
          echo"{$origPts} × {$fieldMult} × {$eventMult}";
         }
         ?>
         </td>
         <td class="gs-text-nowrap hide-mobile-portrait"><?= date('Y-m-d', strtotime($raceDetail['event_date'])) ?></td>
         <td class="text-center hide-mobile-portrait">
         <span class="text-xs"><?= h($raceDetail['class_name'] ?? '-') ?></span>
         </td>
         <td class="text-center hide-mobile-portrait">
         <span class="badge badge-secondary"><?= $raceDetail['field_size'] ?></span>
         </td>
         <td class="text-right hide-mobile-portrait"><?= number_format($raceDetail['original_points'], 0) ?></td>
        </tr>
        <!-- Expandable calculation row -->
        <tr id="calc-<?= $disc ?>-<?= $idx ?>" class="calculation-row" style="display: none; background: #f0f9ff;">
         <td colspan="8" style="padding: 0.75rem;">
         <div style="font-size: 0.75rem; line-height: 1.6;">
          <strong style="color: #1e40af;">📊 Poängberäkning för <?= h($raceDetail['event_name']) ?></strong>
          <div style="margin-top: 0.5rem; padding: 0.5rem; background: white; border-radius: 4px; border-left: 3px solid #3b82f6;">
          <div style="margin-bottom: 0.25rem;">
           <strong>Eventpoäng:</strong> <?= number_format($raceDetail['original_points'], 0) ?>p
           <span style="color: #64748b;">(från placering #<?= $raceDetail['position'] ?> i klassen)</span>
          </div>
          <div style="margin-bottom: 0.25rem;">
           <strong>× Fältstorlek:</strong> <?= number_format($raceDetail['field_multiplier'], 2) ?>
           <span style="color: #64748b;">(<?= $raceDetail['field_size'] ?> startande)</span>
          </div>
          <div style="margin-bottom: 0.25rem;">
           <strong>× Event-nivå:</strong> <?= number_format($raceDetail['event_level_multiplier'], 2) ?>
           <span style="color: #64748b;">
           <?php if ($raceDetail['event_level_multiplier'] == 1.0): ?>
            (Nationell tävling)
           <?php elseif ($raceDetail['event_level_multiplier'] == 0.5): ?>
            (Sportmotion/Regional)
           <?php else: ?>
            (Annan nivå)
           <?php endif; ?>
           </span>
          </div>
          <div style="margin-bottom: 0.25rem;">
           <strong>× Tidsviktning:</strong> <?= number_format($raceDetail['time_multiplier'], 2) ?>
           <span style="color: #64748b;">
           <?php
           $monthsAgo = (strtotime('now') - strtotime($raceDetail['event_date'])) / (30 * 24 * 60 * 60);
           if ($monthsAgo <= 12) {
            echo"(0-12 månader gammal)";
           } elseif ($monthsAgo <= 24) {
            echo"(13-24 månader gammal)";
           } else {
            echo"(Äldre än 24 månader)";
           }
           ?>
           </span>
          </div>
          <div style="margin-top: 0.5rem; padding-top: 0.5rem; border-top: 1px solid #e2e8f0; font-weight: bold; color: #1e40af;">
           = <?= number_format($raceDetail['ranking_points'], 0) ?>p rankingpoäng
          </div>
          <div style="margin-top: 0.5rem; font-size: 0.7rem; color: #64748b; font-style: italic;">
           Formel: <?= number_format($raceDetail['original_points'], 0) ?> × <?= number_format($raceDetail['field_multiplier'], 2) ?> × <?= number_format($raceDetail['event_level_multiplier'], 2) ?> × <?= number_format($raceDetail['time_multiplier'], 2) ?> = <?= number_format($raceDetail['ranking_points'], 2) ?>p
          </div>
          </div>
         </div>
         </td>
        </tr>
        <?php endforeach; ?>
       </tbody>
       </table>
      </div>
      </div>
     </div>
     <?php endif; ?>

    </div>
    <?php endif; ?>
   <?php endforeach; ?>
   <?php else: ?>
   <div class="gs-empty-state text-center py-xl">
    <i data-lucide="trending-up" class="gs-empty-icon"></i>
    <h3 class="mb-sm">Ingen ranking ännu</h3>
    <p class="text-secondary">
    Denna deltagare har inte tillräckligt med resultat för att rankas.
    </p>
   </div>
   <?php endif; ?>
  </div>

  <!-- Tab 2: GravitySeries Total -->
  <div class="gs-main-tab-content" id="gravity-total-tab">
   <?php if ($gravityTotalStats && $gravityTotalStats['total_points'] > 0): ?>
   <h2 class="text-primary mb-lg">
    <i data-lucide="trophy"></i>
    GravitySeries Total - Individuell
   </h2>

   <!-- Stats Grid -->
   <div class="gs-ranking-stats-grid mb-lg">
    <div class="gs-stat-box">
    <div class="stat-label">Placering</div>
    <div class="stat-value text-primary">
     #<?= $gravityTotalPosition ?? '-' ?><?php if ($gravityTotalClassTotal): ?>/<?= $gravityTotalClassTotal ?><?php endif; ?>
    </div>
    </div>
    <div class="gs-stat-box">
    <div class="stat-label">Totala poäng</div>
    <div class="stat-value text-success"><?= number_format($gravityTotalStats['total_points']) ?></div>
    </div>
    <div class="gs-stat-box">
    <div class="stat-label">Antal race</div>
    <div class="stat-value text-warning"><?= $gravityTotalStats['events_count'] ?></div>
    </div>
   </div>

   <?php if ($currentClassName): ?>
    <div class="mb-lg">
    <span class="badge badge-primary badge-lg">Klass: <?= h($currentClassName) ?></span>
    </div>
   <?php endif; ?>

   <!-- Race Details -->
   <?php if (!empty($gravityTotalRaceDetails)): ?>
    <div class="card gs-bg-light">
    <div class="card-body">
     <h4 class="text-primary mb-md">
     <i data-lucide="list"></i>
     Race och poäng
     </h4>
     <style>
     /* Base styles - keep small for mobile */
     .gravity-total-table th,
     .gravity-total-table td {
      font-size: 0.75rem;
      padding: 0.4rem 0.25rem;
     }

     /* Mobile portrait: Only Placering, Event, Poäng */
     @media (max-width: 767px) {
      .gravity-total-table .hide-mobile-portrait {
      display: none !important;
      }
      .gravity-total-table .show-mobile-landscape {
      display: none !important;
      }
      .gravity-total-table th,
      .gravity-total-table td {
      padding: 0.4rem 0.2rem;
      }
     }

     /* Mobile landscape: Add Datum, keep same size */
     @media (min-width: 768px) and (max-width: 1279px) {
      .gravity-total-table .hide-mobile-portrait {
      display: none !important;
      }
      .gravity-total-table .show-mobile-landscape {
      display: table-cell !important;
      visibility: visible !important;
      }
      .gravity-total-table th,
      .gravity-total-table td {
      padding: 0.4rem 0.3rem;
      }
     }

     /* Desktop: Show all columns, can be larger */
     @media (min-width: 1280px) {
      .gravity-total-table .show-mobile-landscape {
      display: none !important;
      }
      .gravity-total-table th,
      .gravity-total-table td {
      font-size: 0.85rem;
      padding: 0.5rem 0.3rem;
      }
     }
     </style>
     <div class="table-responsive">
     <table class="table table-compact gravity-total-table">
      <thead>
      <tr>
       <th class="text-center">Placering</th>
       <th>Event</th>
       <th class="text-right" style="display: table-cell !important;">Poäng</th>
       <th class="show-mobile-landscape" style="display: none;">Datum</th>
       <th class="hide-mobile-portrait">Datum</th>
       <th class="text-center hide-mobile-portrait">Klass</th>
      </tr>
      </thead>
      <tbody>
      <?php foreach ($gravityTotalRaceDetails as $raceDetail): ?>
       <tr>
       <td class="text-center">
        <?php
        $pos = $raceDetail['class_position'];
        if ($pos == 1) echo '<span class="badge badge-success" style="font-size: 0.7rem; padding: 0.15rem 0.3rem;">🥇</span>';
        elseif ($pos == 2) echo '<span class="badge badge-secondary" style="font-size: 0.7rem; padding: 0.15rem 0.3rem;">🥈</span>';
        elseif ($pos == 3) echo '<span class="badge badge-warning" style="font-size: 0.7rem; padding: 0.15rem 0.3rem;">🥉</span>';
        else echo '<span class="badge badge-secondary" style="font-size: 0.7rem; padding: 0.15rem 0.3rem;">#' . ($pos ?? '-') . '</span>';
        ?>
       </td>
       <td>
        <strong style="font-size: 0.75rem;"><?= h($raceDetail['event_name']) ?></strong>
       </td>
       <td class="text-right text-primary font-bold" style="font-size: 0.75rem; display: table-cell !important;"><?= number_format($raceDetail['points'], 0) ?>p</td>
       <td class="show-mobile-landscape gs-text-nowrap" style="display: none; font-size: 0.7rem;"><?= date('Y-m-d', strtotime($raceDetail['event_date'])) ?></td>
       <td class="hide-mobile-portrait gs-text-nowrap"><?= date('Y-m-d', strtotime($raceDetail['event_date'])) ?></td>
       <td class="text-center hide-mobile-portrait"><?= h($raceDetail['class_name'] ?? '-') ?></td>
       </tr>
      <?php endforeach; ?>
      <tr class="table-footer">
       <td colspan="2" class="text-right font-bold">Total:</td>
       <td class="text-right text-primary font-bold text-lg">
       <?= number_format($gravityTotalStats['total_points'], 0) ?>p
       </td>
       <td colspan="3" class="hide-mobile-portrait"></td>
      </tr>
      </tbody>
     </table>
     </div>
    </div>
    </div>
   <?php endif; ?>
   <?php else: ?>
   <div class="gs-empty-state text-center py-xl">
    <i data-lucide="trophy" class="gs-empty-icon"></i>
    <h3 class="mb-sm">Inga GravitySeries Total-poäng</h3>
    <p class="text-secondary">
    Denna deltagare har inte några poäng i GravitySeries Total ännu.
    </p>
   </div>
   <?php endif; ?>
  </div>

  <!-- Tab 3: GravitySeries Team -->
  <div class="gs-main-tab-content" id="gravity-team-tab">
   <?php if ($gravityTeamStats && $gravityTeamStats['total_points'] > 0): ?>
   <h2 class="text-primary mb-lg">
    <i data-lucide="users"></i>
    GravitySeries Team - Klubbpoäng
   </h2>

   <!-- Stats Grid -->
   <div class="gs-ranking-stats-grid mb-lg">
    <div class="gs-stat-box">
    <div class="stat-label">Placering i klubben</div>
    <div class="stat-value text-primary">
     #<?= $gravityTeamPosition ?? '-' ?><?php if ($gravityTeamClassTotal): ?>/<?= $gravityTeamClassTotal ?><?php endif; ?>
    </div>
    </div>
    <div class="gs-stat-box">
    <div class="stat-label">Klubbpoäng</div>
    <div class="stat-value" style="color: #f59e0b;"><?= number_format($gravityTeamStats['total_points'], 1) ?></div>
    </div>
    <div class="gs-stat-box">
    <div class="stat-label">Antal race</div>
    <div class="stat-value text-warning"><?= $gravityTeamStats['events_count'] ?></div>
    </div>
   </div>

   <?php if ($rider['club_name']): ?>
    <div class="mb-lg">
    <span class="badge badge-primary badge-lg">Klubb: <?= h($rider['club_name']) ?></span>
    </div>
   <?php endif; ?>

   <!-- Race Details -->
   <?php if (!empty($gravityTeamRaceDetails)): ?>
    <div class="card gs-bg-light">
    <div class="card-body">
     <h4 class="text-primary mb-md">
     <i data-lucide="list"></i>
     Race och klubbpoäng
     </h4>
     <style>
     /* Base styles - keep small for mobile */
     .club-points-table th,
     .club-points-table td {
      font-size: 0.75rem;
      padding: 0.4rem 0.25rem;
     }

     /* Mobile portrait: Only Event and Poäng */
     @media (max-width: 767px) {
      .club-points-table .hide-mobile-portrait {
      display: none !important;
      }
      .club-points-table .show-mobile-landscape {
      display: none !important;
      }
      .club-points-table th,
      .club-points-table td {
      padding: 0.4rem 0.2rem;
      }
     }

     /* Mobile landscape: Add Beräkning, keep same size */
     @media (min-width: 768px) and (max-width: 1279px) {
      .club-points-table .hide-mobile-portrait {
      display: none !important;
      }
      .club-points-table .show-mobile-landscape {
      display: table-cell !important;
      visibility: visible !important;
      }
      .club-points-table th,
      .club-points-table td {
      padding: 0.4rem 0.3rem;
      }
     }

     /* Desktop: Show all columns, can be larger */
     @media (min-width: 1280px) {
      .club-points-table .show-mobile-landscape {
      display: none !important;
      }
      .club-points-table th,
      .club-points-table td {
      font-size: 0.85rem;
      padding: 0.5rem 0.3rem;
      }
     }
     </style>
     <div class="table-responsive">
     <table class="table table-compact club-points-table">
      <thead>
      <tr>
       <th>Event</th>
       <th class="text-right" style="display: table-cell !important;">Poäng</th>
       <th class="text-right show-mobile-landscape" style="display: none;">Beräkning</th>
       <th class="hide-mobile-portrait">Datum</th>
       <th class="text-center hide-mobile-portrait">Klass</th>
       <th class="text-right hide-mobile-portrait">Original</th>
       <th class="text-center hide-mobile-portrait">Procent</th>
      </tr>
      </thead>
      <tbody>
      <?php foreach ($gravityTeamRaceDetails as $raceDetail): ?>
       <tr>
       <td>
        <strong style="font-size: 0.75rem;"><?= h($raceDetail['event_name']) ?></strong>
       </td>
       <td class="text-right font-bold" style="color: #f59e0b; font-size: 0.75rem; display: table-cell !important;">
        <?= number_format($raceDetail['club_points'], 0) ?>p
       </td>
       <td class="text-right show-mobile-landscape" style="display: none; font-size: 0.7rem; white-space: nowrap;">
        <?= number_format($raceDetail['original_points'], 0) ?>×<?= $raceDetail['percentage_applied'] ?>%
       </td>
       <td class="hide-mobile-portrait gs-text-nowrap"><?= date('Y-m-d', strtotime($raceDetail['event_date'])) ?></td>
       <td class="text-center hide-mobile-portrait"><?= h($raceDetail['class_name'] ?? '-') ?></td>
       <td class="text-right hide-mobile-portrait"><?= number_format($raceDetail['original_points'], 0) ?></td>
       <td class="text-center hide-mobile-portrait"><?= $raceDetail['percentage_applied'] ?>%</td>
       </tr>
      <?php endforeach; ?>
      <tr class="table-footer">
       <td class="text-right font-bold">Total:</td>
       <td class="text-right font-bold text-lg" style="color: #f59e0b;">
       <?= number_format($gravityTeamStats['total_points'], 0) ?>p
       </td>
       <td colspan="5" class="hide-mobile-portrait"></td>
      </tr>
      </tbody>
     </table>
     </div>
     <p class="text-xs text-secondary mt-md">
     <i data-lucide="info"></i>
     Procent baseras på åldersklass och klubbens regelverk för teampoäng.
     </p>
    </div>
    </div>
   <?php endif; ?>
   <?php else: ?>
   <div class="gs-empty-state text-center py-xl">
    <i data-lucide="users" class="gs-empty-icon"></i>
    <h3 class="mb-sm">Inga GravitySeries Team-poäng</h3>
    <p class="text-secondary">
    <?php if (!$rider['club_id']): ?>
     Denna deltagare är inte medlem i någon klubb.
    <?php else: ?>
     Denna deltagare har inte några klubbpoäng i GravitySeries Team ännu.
    <?php endif; ?>
    </p>
   </div>
   <?php endif; ?>
  </div>

  <!-- Tab 4: Race Results -->
  <div class="gs-main-tab-content" id="results-tab">
   <?php if (!empty($results)): ?>
   <h2 class="text-primary mb-lg">
    <i data-lucide="list"></i>
    Alla tävlingsresultat (<?= $totalRaces ?> race)
   </h2>

   <?php foreach ($resultsByYear as $year => $yearResults): ?>
    <div class="mb-lg">
    <h3 class="text-primary mb-md">
     <i data-lucide="calendar"></i>
     <?= $year ?> (<?= count($yearResults) ?> lopp)
    </h3>

    <style>
     /* Base styles - keep small for mobile */
     .all-races-table th,
     .all-races-table td {
     font-size: 0.75rem;
     padding: 0.4rem 0.25rem;
     }

     /* Mobile portrait: Only Placering, Event, Poäng */
     @media (max-width: 767px) {
     .all-races-table .hide-mobile-portrait {
      display: none !important;
     }
     .all-races-table .show-mobile-landscape {
      display: none !important;
     }
     .all-races-table th,
     .all-races-table td {
      padding: 0.4rem 0.2rem;
     }
     }

     /* Mobile landscape: Add Datum, keep same size */
     @media (min-width: 768px) and (max-width: 1279px) {
     .all-races-table .hide-mobile-portrait {
      display: none !important;
     }
     .all-races-table .show-mobile-landscape {
      display: table-cell !important;
      visibility: visible !important;
     }
     .all-races-table th,
     .all-races-table td {
      padding: 0.4rem 0.3rem;
     }
     }

     /* Desktop: Show all columns, can be larger */
     @media (min-width: 1280px) {
     .all-races-table .show-mobile-landscape {
      display: none !important;
     }
     .all-races-table th,
     .all-races-table td {
      font-size: 0.85rem;
      padding: 0.5rem 0.3rem;
     }
     }
    </style>

    <div class="table-responsive">
     <table class="table table-compact all-races-table">
     <thead>
      <tr>
      <th class="text-center">Placering</th>
      <th>Event</th>
      <th class="text-center" style="display: table-cell !important;">Poäng</th>
      <th class="show-mobile-landscape" style="display: none;">Datum</th>
      <th class="hide-mobile-portrait">Datum</th>
      <th class="hide-mobile-portrait">Plats</th>
      <th class="hide-mobile-portrait">Klass</th>
      <th class="text-center hide-mobile-portrait">Tid</th>
      </tr>
     </thead>
     <tbody>
      <?php foreach ($yearResults as $result): ?>
      <?php
      // Skip DNS (Did Not Start) - don't show these events
      if ($result['status'] === 'dns') continue;
      $awardsPoints = $result['awards_points'] ?? 1;
      $displayPos = ($result['status'] === 'finished' && $awardsPoints) ? ($result['class_position'] ?? null) : null;
      ?>
      <tr>
       <td class="text-center">
       <?php if ($result['status'] === 'dnf'): ?>
        <span class="badge badge-danger" style="font-size: 0.7rem; padding: 0.15rem 0.3rem;">DNF</span>
       <?php elseif ($displayPos): ?>
        <?php if ($displayPos == 1): ?>
        <span class="badge badge-success" style="font-size: 0.7rem; padding: 0.15rem 0.3rem;">🥇</span>
        <?php elseif ($displayPos == 2): ?>
        <span class="badge badge-secondary" style="font-size: 0.7rem; padding: 0.15rem 0.3rem;">🥈</span>
        <?php elseif ($displayPos == 3): ?>
        <span class="badge badge-warning" style="font-size: 0.7rem; padding: 0.15rem 0.3rem;">🥉</span>
        <?php else: ?>
        <span class="badge badge-secondary" style="font-size: 0.7rem; padding: 0.15rem 0.3rem;">#<?= $displayPos ?></span>
        <?php endif; ?>
       <?php elseif ($result['status'] === 'finished' && !$awardsPoints): ?>
        <span class="text-secondary" style="font-size: 0.65rem;">Ej tävling</span>
       <?php else: ?>
        <span class="text-secondary">-</span>
       <?php endif; ?>
       </td>
       <td>
       <?php if ($result['event_id']): ?>
        <a href="/results/<?= $result['event_id'] ?>" class="link">
        <strong style="font-size: 0.75rem;"><?= h($result['event_name']) ?></strong>
        </a>
       <?php else: ?>
        <strong style="font-size: 0.75rem;"><?= h($result['event_name']) ?></strong>
       <?php endif; ?>
       </td>
       <td class="text-center" style="display: table-cell !important;">
       <?php if ($awardsPoints): ?>
        <strong style="font-size: 0.75rem;"><?= $result['points'] ?? 0 ?>p</strong>
       <?php else: ?>
        <span class="text-secondary">-</span>
       <?php endif; ?>
       </td>
       <td class="show-mobile-landscape gs-text-nowrap" style="display: none; font-size: 0.7rem;"><?= date('Y-m-d', strtotime($result['event_date'])) ?></td>
       <td class="hide-mobile-portrait gs-text-nowrap"><?= date('Y-m-d', strtotime($result['event_date'])) ?></td>
       <td class="hide-mobile-portrait">
       <?php if ($result['event_location']): ?>
        <?= h($result['event_location']) ?>
       <?php elseif ($result['venue_city']): ?>
        <?= h($result['venue_city']) ?>
       <?php else: ?>
        -
       <?php endif; ?>
       </td>
       <td class="hide-mobile-portrait"><?= h($result['class_display_name'] ?? $result['class_name'] ?? '-') ?></td>
       <td class="text-center hide-mobile-portrait">
       <?php
       if ($result['finish_time']) {
        // Format time: remove leading hours if 0
        $time = $result['finish_time'];
        // Check if time starts with"00:" or"0:"
        if (preg_match('/^0?0:/', $time)) {
        // Remove leading"00:" or"0:"
        $time = preg_replace('/^0?0:/', '', $time);
        }
        echo h($time);
       } else {
        echo '-';
       }
       ?>
       </td>
      </tr>
      <?php endforeach; ?>
     </tbody>
     </table>
    </div>
    </div>
   <?php endforeach; ?>
   <?php else: ?>
   <div class="gs-empty-state text-center py-xl">
    <i data-lucide="list" class="gs-empty-icon"></i>
    <h3 class="mb-sm">Inga resultat ännu</h3>
    <p class="text-secondary">
    Denna deltagare har inte några tävlingsresultat uppladdat.
    </p>
   </div>
   <?php endif; ?>
  </div>

  </div><!-- End Main Profile Tabs card -->

  <!-- CSS for main tabs and styling -->
  <style>
  /* Main Profile Tabs - using event-tab style */
  .event-tab {
   cursor: pointer;
  }

  .gs-main-tab-content {
   display: none;
   animation: fadeIn 0.3s ease-in;
  }

  .gs-main-tab-content.active {
   display: block;
  }

  @keyframes fadeIn {
   from { opacity: 0; }
   to { opacity: 1; }
  }

  /* Table footer styling */
  .table-footer {
   background: var(--gs-gray-50);
   font-weight: bold;
   border-top: 2px solid var(--gs-gray-300);
  }

  /* Compact table styling */
  .table-compact {
   font-size: 0.9rem;
  }

  .table-compact th,
  .table-compact td {
   padding: 0.5rem;
  }

  /* Badge sizes */
  .badge-xs {
   font-size: 0.65rem;
   padding: 0.125rem 0.375rem;
  }

  .badge-lg {
   font-size: 1rem;
   padding: 0.5rem 1rem;
  }

  /* Empty state */
  .gs-empty-icon {
   width: 64px;
   height: 64px;
   opacity: 0.3;
   margin-bottom: 1rem;
  }

  /* Mobile responsive for main tabs */
  @media (max-width: 767px) {
   .gs-main-tabs {
   gap: 0.25rem;
   border-bottom-width: 2px;
   }

   .gs-main-tab {
   padding: 0.75rem 0.5rem;
   font-size: 0.75rem;
   border-bottom-width: 3px;
   margin-bottom: -2px;
   }

   .gs-main-tab i {
   width: 16px;
   height: 16px;
   }

   /* Stack badge */
   .badge-lg {
   font-size: 0.875rem;
   padding: 0.375rem 0.75rem;
   }
  }

  /* Ranking stats grid */
  .gs-ranking-stats-grid {
   display: grid;
   grid-template-columns: repeat(3, 1fr);
   gap: 1rem;
  }

  .gs-stat-box {
   text-align: center;
   padding: 1rem;
   background: var(--gs-gray-50);
   border-radius: var(--gs-radius-md);
  }

  .stat-label {
   font-size: 0.875rem;
   color: var(--text-secondary);
   margin-bottom: 0.5rem;
  }

  .stat-value {
   font-size: 2rem;
   font-weight: 700;
  }

  /* Points breakdown */
  .points-breakdown {
   display: flex;
   flex-direction: column;
   gap: 0.75rem;
  }

  .gs-points-row {
   display: flex;
   justify-content: space-between;
   align-items: center;
   padding: 0.5rem 0;
  }

  .points-label {
   display: flex;
   align-items: center;
   gap: 0.5rem;
   font-size: 0.875rem;
  }

  .points-label i {
   width: 16px;
   height: 16px;
  }

  .gs-points-value {
   font-weight: 600;
   font-size: 1rem;
  }

  .gs-divider {
   height: 1px;
   background: var(--gs-gray-200);
  }

  /* Tabs */
  .gs-tabs {
   display: flex;
   gap: 0.5rem;
   border-bottom: 2px solid var(--gs-gray-200);
  }

  .tab {
   padding: 0.75rem 1.5rem;
   background: none;
   border: none;
   border-bottom: 3px solid transparent;
   margin-bottom: -2px;
   cursor: pointer;
   font-weight: 500;
   color: var(--text-secondary);
   transition: all 0.2s;
  }

  .tab:hover {
   color: var(--primary);
  }

  .tab.active {
   color: var(--primary);
   border-bottom-color: var(--primary);
  }

  .tab-content {
   display: none;
  }

  .tab-content.active {
   display: block;
  }

  /* Mobile responsive - VERY COMPACT */
  @media (max-width: 767px) {
   /* Make everything more compact on mobile */
   .card {
   margin-bottom: 0.75rem !important;
   }

   .card-body {
   padding: 0.75rem !important;
   }

   .card-header {
   padding: 0.75rem !important;
   }

   . {
   font-size: 1rem !important;
   }

   . {
   font-size: 0.875rem !important;
   }

   /* Compact ranking stats grid */
   .gs-ranking-stats-grid {
   grid-template-columns: repeat(3, 1fr);
   gap: 0.25rem;
   }

   .gs-stat-box {
   padding: 0.4rem 0.2rem;
   }

   .stat-label {
   font-size: 0.65rem;
   margin-bottom: 0.15rem;
   text-transform: uppercase;
   }

   .stat-value {
   font-size: 1.1rem;
   font-weight: 700;
   }

   /* Compact tabs */
   .gs-tabs {
   flex-direction: row;
   gap: 0.25rem;
   border-bottom: 1px solid var(--gs-gray-200);
   }

   .tab {
   padding: 0.5rem 0.75rem;
   font-size: 0.75rem;
   border-bottom: 2px solid transparent;
   margin-bottom: -1px;
   }

   .tab.active {
   border-bottom-color: var(--primary);
   }

   /* Compact points breakdown */
   .points-breakdown {
   gap: 0.5rem;
   }

   .gs-points-row {
   padding: 0.35rem 0;
   }

   .points-label {
   font-size: 0.75rem;
   gap: 0.25rem;
   }

   .points-label i {
   width: 14px;
   height: 14px;
   }

   .gs-points-value {
   font-size: 0.875rem;
   }

   /* Compact divider */
   .gs-divider {
   margin: 0.25rem 0;
   }

   /* Overall compact typography */
   .text-sm {
   font-size: 0.75rem !important;
   }

   .text-xs {
   font-size: 0.65rem !important;
   }

   /* Reduce margins */
   .mb-lg {
   margin-bottom: 0.75rem !important;
   }

   .mb-md {
   margin-bottom: 0.5rem !important;
   }

   .mb-sm {
   margin-bottom: 0.35rem !important;
   }

   /* Compact background card */
   .gs-bg-light {
   padding: 0.5rem !important;
   }

   /* Hide table columns on mobile portrait */
   table th:nth-child(3), /* Plats */
   table td:nth-child(3),
   table th:nth-child(4), /* Serie */
   table td:nth-child(4),
   table th:nth-child(6), /* Tid */
   table td:nth-child(6),
   table th:nth-child(7), /* Poäng */
   table td:nth-child(7) {
   display: none;
   }
  }

  /* Mobile landscape: show more columns */
  @media (min-width: 768px) and (max-width: 1024px) and (orientation: landscape) {
   table th:nth-child(3), /* Plats */
   table td:nth-child(3),
   table th:nth-child(6), /* Tid */
   table td:nth-child(6),
   table th:nth-child(7), /* Poäng */
   table td:nth-child(7) {
   display: table-cell;
   }

   table th:nth-child(4), /* Serie - hide on landscape too */
   table td:nth-child(4) {
   display: none;
   }

   /* Compact font on landscape */
   table {
   font-size: 0.85rem;
   }
  }
  </style>

  <script>
  // Main tab functionality
  document.addEventListener('DOMContentLoaded', function() {
   // Main profile tabs (using event-tab for main tabs)
   const mainTabs = document.querySelectorAll('.event-tab[data-main-tab]');
   mainTabs.forEach(tab => {
   tab.addEventListener('click', function() {
    const targetId = this.dataset.mainTab;

    // Remove active class from all main tabs and content
    document.querySelectorAll('.event-tab[data-main-tab]').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.gs-main-tab-content').forEach(c => c.classList.remove('active'));

    // Add active class to clicked tab and corresponding content
    this.classList.add('active');
    document.getElementById(targetId).classList.add('active');
   });
   });

   // Sub-tabs (discipline tabs within ranking)
   const tabs = document.querySelectorAll('.tab');
   tabs.forEach(tab => {
   tab.addEventListener('click', function() {
    const targetId = this.dataset.tab;

    // Remove active class from all tabs and content
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));

    // Add active class to clicked tab and corresponding content
    this.classList.add('active');
    document.getElementById(targetId).classList.add('active');
   });
   });

   // Toggle calculation explanation
   window.toggleCalc = function(event, calcId) {
   event.preventDefault();
   event.stopPropagation();
   const row = document.getElementById(calcId);
   const button = event.target.closest('button');

   if (row.style.display === 'none') {
    row.style.display = 'table-row';
    if (button) {
    button.innerHTML = '<i data-lucide="x-circle" style="width: 12px; height: 12px;"></i> Dölj beräkning';
    lucide.createIcons();
    }
   } else {
    row.style.display = 'none';
    if (button) {
    button.innerHTML = '<i data-lucide="help-circle" style="width: 12px; height: 12px;"></i> Visa beräkning';
    lucide.createIcons();
    }
   }
   };
  });
  </script>
 </div>
 </main>

<?php include __DIR__ . '/includes/layout-footer.php'; ?>

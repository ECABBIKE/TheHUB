<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/series-points.php'; // NEW: Series-specific points
require_once __DIR__ . '/includes/class-calculations.php';

$db = getDB();

// Get series ID from URL
$seriesId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$seriesId) {
 // Redirect to series page if no ID provided
 header('Location: /series.php');
 exit;
}

// Check if series_results table exists AND has data for this series
// If not, fall back to old system (results.points)
$useSeriesResults = false;
try {
 $seriesResultsCount = $db->getRow(
"SELECT COUNT(*) as cnt FROM series_results WHERE series_id = ?",
 [$seriesId]
 );
 $useSeriesResults = ($seriesResultsCount && $seriesResultsCount['cnt'] > 0);
} catch (Exception $e) {
 // Table doesn't exist yet, use old system
 $useSeriesResults = false;
}

// Fetch series details
$series = $db->getRow("
 SELECT s.*, COUNT(DISTINCT e.id) as event_count
 FROM series s
 LEFT JOIN events e ON s.id = e.series_id
 WHERE s.id = ?
 GROUP BY s.id
", [$seriesId]);

if (!$series) {
 header('Location: /series.php');
 exit;
}

// Get view mode (overall or class)
$viewMode = isset($_GET['view']) ? $_GET['view'] : 'class';
$selectedClass = isset($_GET['class']) ? $_GET['class'] : 'all';
$searchName = isset($_GET['search']) ? trim($_GET['search']) : '';

// Get all events in this series (using series_events junction table)
$seriesEvents = $db->getAll("
 SELECT
 e.id,
 e.name,
 e.date,
 e.location,
 e.organizer,
 v.name as venue_name,
 v.city as venue_city,
 se.template_id,
 COUNT(DISTINCT r.id) as result_count
 FROM series_events se
 JOIN events e ON se.event_id = e.id
 LEFT JOIN venues v ON e.venue_id = v.id
 LEFT JOIN results r ON e.id = r.event_id
 WHERE se.series_id = ?
 GROUP BY e.id
 ORDER BY e.date ASC
", [$seriesId]);

// Filter events that have templates (these will show in standings columns)
$eventsWithPoints = array_filter($seriesEvents, function($e) {
 return !empty($e['template_id']);
});

// Get all classes that have results in this series (only series-eligible classes that award points)
$activeClasses = $db->getAll("
 SELECT DISTINCT c.id, c.name, c.display_name, c.sort_order,
 COUNT(DISTINCT r.cyclist_id) as rider_count
 FROM classes c
 JOIN results r ON c.id = r.class_id
 JOIN events e ON r.event_id = e.id
 JOIN series_events se ON e.id = se.event_id
 WHERE se.series_id = ?
 AND COALESCE(c.series_eligible, 1) = 1
 AND COALESCE(c.awards_points, 1) = 1
 GROUP BY c.id
 ORDER BY c.sort_order ASC
", [$seriesId]);

// Build standings with per-event points
$standings = [];
$standingsByClass = []; // For"Alla klasser" view - group by class
$showAllClasses = ($selectedClass === 'all');

if ($showAllClasses) {
 // Get all riders who have results in this series (only series-eligible classes that award points)
 $ridersInSeries = $db->getAll("
 SELECT DISTINCT
 riders.id,
 riders.firstname,
 riders.lastname,
 riders.birth_year,
 riders.gender,
 c.name as club_name,
 cls.id as class_id,
 cls.name as class_name,
 cls.display_name as class_display_name,
 cls.sort_order as class_sort_order
 FROM riders
 LEFT JOIN clubs c ON riders.club_id = c.id
 JOIN results r ON riders.id = r.cyclist_id
 JOIN events e ON r.event_id = e.id
 JOIN series_events se ON e.id = se.event_id
 LEFT JOIN classes cls ON r.class_id = cls.id
 WHERE se.series_id = ?
 AND COALESCE(cls.series_eligible, 1) = 1
 AND COALESCE(cls.awards_points, 1) = 1
 ORDER BY cls.sort_order ASC, riders.lastname, riders.firstname
", [$seriesId]);

 // For each rider, get their points from each event
 foreach ($ridersInSeries as $rider) {
 $riderData = [
 'rider_id' => $rider['id'],
 'firstname' => $rider['firstname'],
 'lastname' => $rider['lastname'],
 'fullname' => $rider['firstname'] . ' ' . $rider['lastname'],
 'birth_year' => $rider['birth_year'],
 'gender' => $rider['gender'],
 'club_name' => $rider['club_name'],
 'class_name' => $rider['class_display_name'] ?? 'Okänd',
 'class_id' => $rider['class_id'],
 'event_points' => [],
 'total_points' => 0
 ];

 // Get points for each event (using rider's class)
 // NOTE: Uses series_results for series-specific points (not results.points which is for ranking)
 $allPoints = [];
 foreach ($seriesEvents as $event) {
 if ($useSeriesResults) {
 // NEW: Read from series_results table (series-specific points)
 $result = $db->getRow("
  SELECT points
  FROM series_results
  WHERE series_id = ? AND cyclist_id = ? AND event_id = ? AND class_id <=> ?
  LIMIT 1
 ", [$seriesId, $rider['id'], $event['id'], $rider['class_id']]);
 } else {
 // FALLBACK: Old system using results.points (will be deprecated)
 $result = $db->getRow("
  SELECT points
  FROM results
  WHERE cyclist_id = ? AND event_id = ? AND class_id = ?
  LIMIT 1
 ", [$rider['id'], $event['id'], $rider['class_id']]);
 }

 $points = $result ? (int)$result['points'] : 0;
 $riderData['event_points'][$event['id']] = $points;
 if ($points > 0) {
 $allPoints[] = ['event_id' => $event['id'], 'points' => $points];
 }
 }

 // Apply count_best_results rule
 $countBest = $series['count_best_results'] ?? null;
 $riderData['excluded_events'] = [];

 if ($countBest && count($allPoints) > $countBest) {
 // Sort by points descending
 usort($allPoints, function($a, $b) {
 return $b['points'] - $a['points'];
 });

 // Mark events beyond the best X as excluded
 for ($i = $countBest; $i < count($allPoints); $i++) {
 $riderData['excluded_events'][$allPoints[$i]['event_id']] = true;
 }

 // Sum only the best results
 for ($i = 0; $i < $countBest; $i++) {
 $riderData['total_points'] += $allPoints[$i]['points'];
 }
 } else {
 // Sum all points
 foreach ($allPoints as $p) {
 $riderData['total_points'] += $p['points'];
 }
 }

 // Apply name search filter and skip 0-point riders
 if ($riderData['total_points'] > 0 && ($searchName === '' || stripos($riderData['fullname'], $searchName) !== false)) {
 // Group by class for"Alla klasser" view
 $classKey = $rider['class_id'] ?? 0;
 if (!isset($standingsByClass[$classKey])) {
 $standingsByClass[$classKey] = [
  'class_id' => $rider['class_id'],
  'class_name' => $rider['class_name'] ?? 'Oklassificerad',
  'class_display_name' => $rider['class_display_name'] ?? 'Oklassificerad',
  'class_sort_order' => $rider['class_sort_order'] ?? 999,
  'riders' => []
 ];
 }
 $standingsByClass[$classKey]['riders'][] = $riderData;
 }
 }

 // Sort riders within each class by total points
 foreach ($standingsByClass as &$classData) {
 usort($classData['riders'], function($a, $b) {
 return $b['total_points'] - $a['total_points'];
 });
 }
 unset($classData);

 // Sort classes by sort_order
 uasort($standingsByClass, function($a, $b) {
 return $a['class_sort_order'] - $b['class_sort_order'];
 });
} elseif ($selectedClass && is_numeric($selectedClass)) {
 // Get all riders in this specific class who have results in this series
 $ridersInClass = $db->getAll("
 SELECT DISTINCT
 riders.id,
 riders.firstname,
 riders.lastname,
 riders.birth_year,
 riders.gender,
 c.name as club_name
 FROM riders
 LEFT JOIN clubs c ON riders.club_id = c.id
 JOIN results r ON riders.id = r.cyclist_id
 JOIN events e ON r.event_id = e.id
 JOIN series_events se ON e.id = se.event_id
 WHERE se.series_id = ?
 AND r.class_id = ?
 ORDER BY riders.lastname, riders.firstname
", [$seriesId, $selectedClass]);

 // For each rider, get their points from each event
 foreach ($ridersInClass as $rider) {
 $riderData = [
 'rider_id' => $rider['id'],
 'firstname' => $rider['firstname'],
 'lastname' => $rider['lastname'],
 'fullname' => $rider['firstname'] . ' ' . $rider['lastname'],
 'birth_year' => $rider['birth_year'],
 'gender' => $rider['gender'],
 'club_name' => $rider['club_name'],
 'event_points' => [],
 'total_points' => 0
 ];

 // Get points for each event
 // NOTE: Uses series_results for series-specific points (not results.points which is for ranking)
 $allPoints = [];
 foreach ($seriesEvents as $event) {
 if ($useSeriesResults) {
 // NEW: Read from series_results table (series-specific points)
 $result = $db->getRow("
  SELECT points
  FROM series_results
  WHERE series_id = ? AND cyclist_id = ? AND event_id = ? AND class_id <=> ?
  LIMIT 1
 ", [$seriesId, $rider['id'], $event['id'], $selectedClass]);
 } else {
 // FALLBACK: Old system using results.points (will be deprecated)
 $result = $db->getRow("
  SELECT points
  FROM results
  WHERE cyclist_id = ? AND event_id = ? AND class_id = ?
  LIMIT 1
 ", [$rider['id'], $event['id'], $selectedClass]);
 }

 $points = $result ? (int)$result['points'] : 0;
 $riderData['event_points'][$event['id']] = $points;
 if ($points > 0) {
 $allPoints[] = ['event_id' => $event['id'], 'points' => $points];
 }
 }

 // Apply count_best_results rule
 $countBest = $series['count_best_results'] ?? null;
 $riderData['excluded_events'] = [];

 if ($countBest && count($allPoints) > $countBest) {
 // Sort by points descending
 usort($allPoints, function($a, $b) {
 return $b['points'] - $a['points'];
 });

 // Mark events beyond the best X as excluded
 for ($i = $countBest; $i < count($allPoints); $i++) {
 $riderData['excluded_events'][$allPoints[$i]['event_id']] = true;
 }

 // Sum only the best results
 for ($i = 0; $i < $countBest; $i++) {
 $riderData['total_points'] += $allPoints[$i]['points'];
 }
 } else {
 // Sum all points
 foreach ($allPoints as $p) {
 $riderData['total_points'] += $p['points'];
 }
 }

 // Apply name search filter and skip 0-point riders
 if ($riderData['total_points'] > 0 && ($searchName === '' || stripos($riderData['fullname'], $searchName) !== false)) {
 $standings[] = $riderData;
 }
 }

 // Sort by total points descending
 usort($standings, function($a, $b) {
 return $b['total_points'] - $a['total_points'];
 });
}

$pageTitle = $series['name'] . ' - Kvalpoäng';
$pageType = 'public';
include __DIR__ . '/includes/layout-header.php';
?>


<script>
function toggleStandingsDetails(btn) {
 const card = btn.closest('.card');
 card.classList.toggle('standings-expanded');
 const isExpanded = card.classList.contains('standings-expanded');
 btn.textContent = isExpanded ? 'Dölj poäng' : 'Visa poäng';
}

function setEventRange(btn, range) {
 const container = btn.closest('.card-table-container') || btn.closest('.card');

 // Update button states
 const buttons = btn.parentElement.querySelectorAll('.gs-event-range-btn');
 buttons.forEach(b => b.classList.remove('active'));
 btn.classList.add('active');

 // Update table display
 const table = container.querySelector('.gs-standings-table');
 if (table) {
 table.classList.remove('gs-show-first-half', 'gs-show-second-half', 'show-all');
 table.classList.add('show-' + range);
 }
}
</script>

<main class="main-content">
 <div class="container">
 <!-- Back Button -->
 <div class="mb-lg">
 <a href="/series.php" class="btn btn--secondary btn--sm">
 <i data-lucide="arrow-left"></i>
 Tillbaka till serier
 </a>
 </div>

 <!-- Header -->
 <div class="mb-lg">
 <h1 class="text-primary mb-sm">
 <i data-lucide="trophy"></i>
 <?= h($series['name']) ?> - Kvalpoäng
 </h1>
 <?php if ($series['description']): ?>
 <p class="text-secondary">
  <?= h($series['description']) ?>
 </p>
 <?php endif; ?>
 <div class="flex gap-sm mt-md">
 <span class="badge badge-primary">
  <?= $series['year'] ?>
 </span>
 <span class="badge badge-secondary">
  <?= count($seriesEvents) ?> tävlingar
 </span>
 <?php if ($series['count_best_results']): ?>
  <span class="badge badge-info">
  Räknar <?= $series['count_best_results'] ?> bästa resultat
  </span>
 <?php endif; ?>
 </div>
 </div>

 <!-- Events List -->
 <div class="card mb-lg">
 <div class="card-header">
 <h3 class="">
  <i data-lucide="calendar"></i>
  Tävlingar i serien (<?= count($seriesEvents) ?>)
 </h3>
 </div>
 <?php if (!empty($seriesEvents)): ?>
 <div class="card-body gs-p-0">
  <div class="table-responsive">
  <table class="table">
  <thead>
  <tr>
   <th>#</th>
   <th>Datum</th>
   <th>Tävling</th>
   <th>Plats</th>
   <th>Arrangör</th>
   <th class="text-center">Resultat</th>
  </tr>
  </thead>
  <tbody>
  <?php $eventNum = 1; ?>
  <?php foreach ($seriesEvents as $event): ?>
   <tr>
   <td><span class="badge badge-primary badge-sm">#<?= $eventNum ?></span></td>
   <td><?= date('Y-m-d', strtotime($event['date'])) ?></td>
   <td>
   <?php
   // Strip leading"#X" from event name since we show it in first column
   $displayName = preg_replace('/^#\d+\s+/', '', $event['name']);
   ?>
   <strong><?= h($displayName) ?></strong>
   <?php if ($event['venue_name']): ?>
   <br><span class="text-xs text-secondary">
    <?= h($event['venue_name']) ?>
   </span>
   <?php endif; ?>
   </td>
   <td>
   <?php if ($event['location']): ?>
   <?= h($event['location']) ?>
   <?php elseif ($event['venue_city']): ?>
   <?= h($event['venue_city']) ?>
   <?php else: ?>
   –
   <?php endif; ?>
   </td>
   <td><?= $event['organizer'] ? h($event['organizer']) : '–' ?></td>
   <td class="text-center">
   <a href="/event.php?id=<?= $event['id'] ?>" class="btn btn--sm btn--primary">
   <i data-lucide="list"></i>
   Se resultat (<?= $event['result_count'] ?>)
   </a>
   </td>
   </tr>
   <?php $eventNum++; ?>
  <?php endforeach; ?>
  </tbody>
  </table>
  </div>
 </div>
 <?php else: ?>
 <div class="card-body gs-empty-state">
  <i data-lucide="calendar-x" class="gs-empty-icon"></i>
  <p class="text-secondary">
  Inga tävlingar har lagts till i denna serie ännu.
  </p>
 </div>
 <?php endif; ?>
 </div>

 <!-- Class Selector and Search -->
 <?php if (!empty($activeClasses)): ?>
 <div class="card mb-lg">
 <div class="card-body">
  <div class="grid grid-cols-1 md-grid-cols-2 gap-md">
  <!-- Class Selector -->
  <div>
  <label class="label">Välj klass</label>
  <select class="input" id="classSelector" onchange="window.location.href='?id=<?= $seriesId ?>&class=' + this.value + '&search=<?= urlencode($searchName) ?>'">
  <option value="all" <?= $selectedClass === 'all' ? 'selected' : '' ?>>
   Alla klasser
  </option>
  <?php foreach ($activeClasses as $class): ?>
   <option value="<?= $class['id'] ?>" <?= $selectedClass == $class['id'] ? 'selected' : '' ?>>
   <?= h($class['display_name']) ?> - <?= h($class['name']) ?> (<?= $class['rider_count'] ?> deltagare)
   </option>
  <?php endforeach; ?>
  </select>
  </div>

  <!-- Name Search -->
  <div>
  <label class="label">Sök på namn</label>
  <form method="get" action="" class="flex gap-sm">
  <input type="hidden" name="id" value="<?= $seriesId ?>">
  <input type="hidden" name="class" value="<?= $selectedClass ?>">
  <input type="text" name="search" class="input" placeholder="Skriv namn..." value="<?= h($searchName) ?>">
  <button type="submit" class="btn btn--primary">
   <i data-lucide="search"></i>
  </button>
  <?php if ($searchName): ?>
   <a href="?id=<?= $seriesId ?>&class=<?= $selectedClass ?>" class="btn btn--secondary">
   <i data-lucide="x"></i>
   </a>
  <?php endif; ?>
  </form>
  </div>
  </div>
 </div>
 </div>
 <?php endif; ?>

 <!-- Standings Table with Event Points -->
 <?php if ($showAllClasses && !empty($standingsByClass)): ?>
 <!-- All Classes View - Show each class separately -->
 <?php if ($searchName): ?>
 <div class="alert alert--info mb-lg">
  <i data-lucide="search"></i>
  Visar resultat för: <strong><?= h($searchName) ?></strong>
 </div>
 <?php endif; ?>

 <?php foreach ($standingsByClass as $classData): ?>
 <div class="card mb-lg">
  <div class="card-header flex justify-between items-center">
  <h3 class="gs-mb-0">
  <?= h($classData['class_display_name']) ?>
  <span class="badge badge-secondary badge-sm"><?= count($classData['riders']) ?></span>
  </h3>
  <button type="button" class="btn btn-xs btn--secondary gs-mobile-toggle" onclick="toggleStandingsDetails(this)">Visa poäng</button>
  </div>
  <div class="card-table-container">
  <?php
  $eventCount = count($eventsWithPoints);
  $showEventSelector = $eventCount > 10;
  $midPoint = ceil($eventCount / 2);
  ?>
  <?php if ($showEventSelector): ?>
  <div class="gs-event-range-selector">
  <button type="button" class="gs-event-range-btn active" onclick="setEventRange(this, 'first-half')">
   Event 1-<?= $midPoint ?>
  </button>
  <button type="button" class="gs-event-range-btn" onclick="setEventRange(this, 'second-half')">
   Event <?= $midPoint + 1 ?>-<?= $eventCount ?>
  </button>
  <button type="button" class="gs-event-range-btn" onclick="setEventRange(this, 'all')">
   Alla
  </button>
  </div>
  <?php endif; ?>
  <table class="table gs-standings-table <?= $showEventSelector ? 'gs-show-first-half' : '' ?>">
  <thead>
  <tr>
   <th class="standings-sticky-th-rank">Plac.</th>
   <th class="standings-sticky-th-name">Namn</th>
   <th class="standings-sticky-th-club col-landscape">Klubb</th>
   <?php $eventNum = 1; ?>
   <?php foreach ($eventsWithPoints as $event): ?>
   <?php $halfClass = $eventNum <= $midPoint ? 'first-half' : 'second-half'; ?>
   <th class="gs-event-col <?= $halfClass ?>" title="<?= h($event['name']) ?> - <?= date('Y-m-d', strtotime($event['date'])) ?>">
   #<?= $eventNum ?>
   </th>
   <?php $eventNum++; ?>
   <?php endforeach; ?>
   <th class="gs-total-col text-center">Total</th>
  </tr>
  </thead>
  <tbody>
  <?php $position = 1; ?>
  <?php foreach ($classData['riders'] as $rider): ?>
   <tr>
   <td class="standings-sticky-td-rank">
   <?php if ($position == 1): ?>
   <span class="badge badge-success badge-xs">🥇 1</span>
   <?php elseif ($position == 2): ?>
   <span class="badge badge-secondary badge-xs">🥈 2</span>
   <?php elseif ($position == 3): ?>
   <span class="badge badge-warning badge-xs">🥉 3</span>
   <?php else: ?>
   <?= $position ?>
   <?php endif; ?>
   </td>
   <td class="standings-sticky-td-name">
   <a href="/rider.php?id=<?= $rider['rider_id'] ?>" class="link">
   <strong><?= h($rider['firstname']) ?> <?= h($rider['lastname']) ?></strong>
   </a>
   </td>
   <td class="standings-sticky-td-club col-landscape">
   <?= h($rider['club_name']) ?: '–' ?>
   </td>
   <?php $eventIdx = 1; ?>
   <?php foreach ($eventsWithPoints as $event): ?>
   <?php $halfClass = $eventIdx <= $midPoint ? 'first-half' : 'second-half'; ?>
   <td class="gs-event-col <?= $halfClass ?>">
   <?php
   $points = $rider['event_points'][$event['id']] ?? 0;
   $isExcluded = isset($rider['excluded_events'][$event['id']]);
   if ($points > 0):
    if ($isExcluded):
   ?>
    <span class="text-muted" style="text-decoration: line-through;" title="Räknas ej"><?= $points ?></span>
   <?php else: ?>
    <?= $points ?>
   <?php endif; ?>
   <?php else: ?>
    <span class="text-muted">–</span>
   <?php endif; ?>
   </td>
   <?php $eventIdx++; ?>
   <?php endforeach; ?>
   <td class="gs-total-col text-center">
   <strong class="text-primary"><?= $rider['total_points'] ?></strong>
   </td>
   </tr>
   <?php $position++; ?>
  <?php endforeach; ?>
  </tbody>
  </table>
  </div>
 </div>
 <?php endforeach; ?>
 <?php elseif (!empty($standings)): ?>
 <?php
 // Get selected class name for single class view
 $selectedClassName = '';
 $selectedClassDisplay = '';
 foreach ($activeClasses as $class) {
 if ($class['id'] == $selectedClass) {
  $selectedClassName = $class['name'];
  $selectedClassDisplay = $class['display_name'];
  break;
 }
 }
 ?>
 <div class="card">
 <div class="card-header flex justify-between items-center">
  <h3 class="gs-mb-0">
  <?= h($selectedClassDisplay) ?><?= $selectedClassName ? ' - ' . h($selectedClassName) : '' ?>
  </h3>
  <button type="button" class="btn btn-xs btn--secondary gs-mobile-toggle" onclick="toggleStandingsDetails(this)">Visa poäng</button>
 </div>
 <?php if ($searchName): ?>
  <p class="text-sm text-secondary gs-px-md">
  Sök: <strong><?= h($searchName) ?></strong>
  </p>
 <?php endif; ?>
 <div class="card-table-container">
  <?php
  $eventCount = count($eventsWithPoints);
  $showEventSelector = $eventCount > 10;
  $midPoint = ceil($eventCount / 2);
  ?>
  <?php if ($showEventSelector): ?>
  <div class="gs-event-range-selector">
  <button type="button" class="gs-event-range-btn active" onclick="setEventRange(this, 'first-half')">
  Event 1-<?= $midPoint ?>
  </button>
  <button type="button" class="gs-event-range-btn" onclick="setEventRange(this, 'second-half')">
  Event <?= $midPoint + 1 ?>-<?= $eventCount ?>
  </button>
  <button type="button" class="gs-event-range-btn" onclick="setEventRange(this, 'all')">
  Alla
  </button>
  </div>
  <?php endif; ?>
  <table class="table gs-standings-table <?= $showEventSelector ? 'gs-show-first-half' : '' ?>">
  <thead>
  <tr>
  <th class="standings-sticky-th-rank">Plac.</th>
  <th class="standings-sticky-th-name">Namn</th>
  <th class="standings-sticky-th-club col-landscape">Klubb</th>
  <?php $eventNum = 1; ?>
  <?php foreach ($eventsWithPoints as $event): ?>
   <?php $halfClass = $eventNum <= $midPoint ? 'first-half' : 'second-half'; ?>
   <th class="gs-event-col <?= $halfClass ?>" title="<?= h($event['name']) ?> - <?= date('Y-m-d', strtotime($event['date'])) ?>">
   #<?= $eventNum ?>
   </th>
   <?php $eventNum++; ?>
  <?php endforeach; ?>
  <th class="gs-total-col text-center">Total</th>
  </tr>
  </thead>
  <tbody>
  <?php $position = 1; ?>
  <?php foreach ($standings as $rider): ?>
  <tr>
   <td class="standings-sticky-td-rank">
   <?php if ($position == 1): ?>
   <span class="badge badge-success badge-xs">🥇 1</span>
   <?php elseif ($position == 2): ?>
   <span class="badge badge-secondary badge-xs">🥈 2</span>
   <?php elseif ($position == 3): ?>
   <span class="badge badge-warning badge-xs">🥉 3</span>
   <?php else: ?>
   <?= $position ?>
   <?php endif; ?>
   </td>
   <td class="standings-sticky-td-name">
   <a href="/rider.php?id=<?= $rider['rider_id'] ?>" class="link">
   <strong><?= h($rider['firstname']) ?> <?= h($rider['lastname']) ?></strong>
   </a>
   </td>
   <td class="standings-sticky-td-club col-landscape">
   <?= h($rider['club_name']) ?: '–' ?>
   </td>
   <?php $eventIdx = 1; ?>
   <?php foreach ($eventsWithPoints as $event): ?>
   <?php $halfClass = $eventIdx <= $midPoint ? 'first-half' : 'second-half'; ?>
   <td class="gs-event-col <?= $halfClass ?>">
   <?php
   $points = $rider['event_points'][$event['id']] ?? 0;
   $isExcluded = isset($rider['excluded_events'][$event['id']]);
   if ($points > 0):
   if ($isExcluded):
   ?>
   <span class="text-muted" style="text-decoration: line-through;" title="Räknas ej"><?= $points ?></span>
   <?php else: ?>
   <?= $points ?>
   <?php endif; ?>
   <?php else: ?>
   <span class="text-muted">–</span>
   <?php endif; ?>
   </td>
   <?php $eventIdx++; ?>
   <?php endforeach; ?>
   <td class="gs-total-col text-center">
   <strong class="text-primary"><?= $rider['total_points'] ?></strong>
   </td>
  </tr>
  <?php $position++; ?>
  <?php endforeach; ?>
  </tbody>
  </table>
 </div>
 </div>
 <?php elseif (empty($standings) && empty($standingsByClass)): ?>
 <div class="card">
 <div class="card-body gs-empty-state">
  <i data-lucide="inbox" class="gs-empty-icon"></i>
  <p class="text-secondary">
  <?php if ($searchName): ?>
  Inga resultat hittades för"<?= h($searchName) ?>"
  <?php else: ?>
  Inga resultat ännu
  <?php endif; ?>
  </p>
 </div>
 </div>
 <?php endif; ?>
 </div>
</main>

<?php include __DIR__ . '/includes/layout-footer.php'; ?>

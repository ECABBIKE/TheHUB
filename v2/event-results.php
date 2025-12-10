<?php
/**
 * Event Results Page
 * Displays results grouped by class (M17, K40, etc.)
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/rider-auth.php';

$db = getDB();

// Get current rider if logged in
$currentRider = get_current_rider();
$hasGravityId = $currentRider && !empty($currentRider['gravity_id']);
$chipDiscount = 50; // Chip rental included in price, deducted for Gravity-ID holders

// Get event ID from URL
$eventId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$eventId) {
 header('Location: /events.php');
 exit;
}

// Fetch event details
$event = $db->getRow("
 SELECT
 e.*,
 s.name as series_name,
 s.logo as series_logo,
 v.name as venue_name,
 v.city as venue_city,
 v.address as venue_address
 FROM events e
 LEFT JOIN series s ON e.series_id = s.id
 LEFT JOIN venues v ON e.venue_id = v.id
 WHERE e.id = ?
", [$eventId]);

if (!$event) {
 header('Location: /events.php');
 exit;
}

// Fetch pricing rules for registration form (from pricing template)
$pricingRules = [];
$classRulesMap = [];
$activePriceTier = 'regular'; // 'early_bird', 'regular', or 'late_fee'
$priceTierInfo = [];

if (!empty($event['ticketing_enabled']) && !empty($event['pricing_template_id'])) {
 // Get template with pricing settings
 $template = $db->getRow("SELECT * FROM pricing_templates WHERE id = ?", [$event['pricing_template_id']]);

 if ($template) {
 // Get pricing rules from template (only base_price per class)
 $pricingRules = $db->getAll("
  SELECT ptr.*, c.name as class_name, c.display_name as class_display_name, c.gender as class_gender,
   c.min_age as class_min_age, c.max_age as class_max_age
  FROM pricing_template_rules ptr
  JOIN classes c ON ptr.class_id = c.id
  WHERE ptr.template_id = ?
  ORDER BY c.sort_order ASC
 ", [$event['pricing_template_id']]);

 // Get percentage settings from template
 $earlyBirdPercent = $template['early_bird_percent'] ?? 15;
 $earlyBirdDays = $template['early_bird_days_before'] ?? 21;
 $lateFeePercent = $template['late_fee_percent'] ?? 25;
 $lateFeeDays = $template['late_fee_days_before'] ?? 3;

 // Calculate which price tier is active based on event date
 $eventDate = new DateTime($event['date']);
 $now = new DateTime();
 $daysUntilEvent = (int)$now->diff($eventDate)->format('%r%a');

 if ($daysUntilEvent >= $earlyBirdDays) {
  $activePriceTier = 'early_bird';
  $earlyBirdEndDate = clone $eventDate;
  $earlyBirdEndDate->modify("-{$earlyBirdDays} days");
  $priceTierInfo = [
  'tier' => 'early_bird',
  'label' => 'Early Bird',
  'end_date' => $earlyBirdEndDate->format('Y-m-d'),
  'discount_percent' => $earlyBirdPercent
  ];
 } elseif ($daysUntilEvent <= $lateFeeDays && $daysUntilEvent >= 0) {
  $activePriceTier = 'late_fee';
  $lateFeeStartDate = clone $eventDate;
  $lateFeeStartDate->modify("-{$lateFeeDays} days");
  $priceTierInfo = [
  'tier' => 'late_fee',
  'label' => 'Efteranmälan',
  'start_date' => $lateFeeStartDate->format('Y-m-d'),
  'fee_percent' => $lateFeePercent
  ];
 } else {
  $activePriceTier = 'regular';
  $priceTierInfo = [
  'tier' => 'regular',
  'label' => 'Ordinarie'
  ];
 }

 // Calculate prices for each rule using template percentages
 foreach ($pricingRules as &$rule) {
  $basePrice = $rule['base_price'];

  $rule['early_bird_price'] = $basePrice * (1 - $earlyBirdPercent / 100);
  $rule['late_fee_price'] = $basePrice * (1 + $lateFeePercent / 100);

  // Set the active price based on current tier
  if ($activePriceTier === 'early_bird') {
  $rule['active_price'] = $rule['early_bird_price'];
  } elseif ($activePriceTier === 'late_fee') {
  $rule['active_price'] = $rule['late_fee_price'];
  } else {
  $rule['active_price'] = $basePrice;
  }
 }
 unset($rule);
 }
}

// Get class rules (license restrictions) from series if available
if (!empty($event['series_id'])) {
 $classRules = $db->getAll("
 SELECT *
 FROM series_class_rules
 WHERE series_id = ? AND is_active = 1
", [$event['series_id']]);

 // Convert class rules to map for easy lookup
 foreach ($classRules as $rule) {
 $classRulesMap[$rule['class_id']] = $rule;
 }
}

// Get license-class matrix from class_license_eligibility table
// This determines which license types can register for which classes
// Filter by the event's license_class (national, sportmotion, motion)
$eventLicenseClass = $event['license_class'] ?? 'national';
$licenseMatrixMap = [];
try {
 $licenseMatrix = $db->getAll("
 SELECT class_id, license_type_code
 FROM class_license_eligibility
 WHERE event_license_class = ? AND is_allowed = 1
", [$eventLicenseClass]);
 // Group by class_id: { classId: ['license1', 'license2', ...] }
 foreach ($licenseMatrix as $mapping) {
 if (!isset($licenseMatrixMap[$mapping['class_id']])) {
  $licenseMatrixMap[$mapping['class_id']] = [];
 }
 $licenseMatrixMap[$mapping['class_id']][] = $mapping['license_type_code'];
 }
} catch (Exception $e) {
 // Table might not exist yet or column missing - no license restrictions applied
}

// Get all available license types from database for the registration form
$availableLicenseTypes = [];
try {
 $availableLicenseTypes = $db->getAll("
 SELECT code, name, description, priority
 FROM license_types
 WHERE is_active = 1
 ORDER BY priority DESC
");
} catch (Exception $e) {
 // Fallback to basic types
 $availableLicenseTypes = [
 ['code' => 'elite_men', 'name' => 'Elite Men', 'description' => 'Herr Elit', 'priority' => 90],
 ['code' => 'elite_women', 'name' => 'Elite Women', 'description' => 'Dam Elit', 'priority' => 90],
 ['code' => 'master', 'name' => 'Master', 'description' => 'Master H30+/D30+', 'priority' => 80],
 ['code' => 'u23', 'name' => 'U23', 'description' => 'Under 23', 'priority' => 70],
 ['code' => 'junior', 'name' => 'Junior', 'description' => '17-18 år', 'priority' => 60],
 ['code' => 'baslicens', 'name' => 'Baslicens', 'description' => 'Sport/Sportmotion', 'priority' => 55],
 ['code' => 'youth', 'name' => 'Youth', 'description' => '11-16 år', 'priority' => 40],
 ['code' => 'under11', 'name' => 'Under 11', 'description' => '5-10 år', 'priority' => 30],
 ['code' => 'motionslicens', 'name' => 'Motionslicens', 'description' => 'Motion/Sportmotion', 'priority' => 20],
 ['code' => 'engangslicens', 'name' => 'Engångslicens', 'description' => 'Enstaka tävling', 'priority' => 10]
 ];
}

// Fetch global texts for use_global functionality
$globalTexts = $db->getAll("SELECT field_key, content FROM global_texts WHERE is_active = 1");
$globalTextMap = [];
foreach ($globalTexts as $gt) {
 $globalTextMap[$gt['field_key']] = $gt['content'];
}

// Helper function to get content with global text fallback
function getEventContent($event, $field, $useGlobalField, $globalTextMap) {
 if (!empty($event[$useGlobalField]) && !empty($globalTextMap[$field])) {
 return $globalTextMap[$field];
 }
 return $event[$field] ?? '';
}

// Fetch registered participants for this event
$registrations = $db->getAll("
 SELECT
 reg.*,
 r.id as rider_id,
 r.firstname,
 r.lastname,
 c.name as club_name
 FROM event_registrations reg
 LEFT JOIN riders r ON reg.rider_id = r.id
 LEFT JOIN clubs c ON r.club_id = c.id
 WHERE reg.event_id = ?
 ORDER BY reg.registration_date ASC
", [$eventId]);

$totalRegistrations = count($registrations);
$confirmedRegistrations = count(array_filter($registrations, function($r) {
 return $r['status'] === 'confirmed';
}));

// Fetch ticketing data if ticketing is enabled
$ticketingEnabled = !empty($event['ticketing_enabled']);
$ticketData = null;
$ticketPricing = [];

if ($ticketingEnabled) {
 // Get ticket statistics
 $ticketData = $db->getRow("
 SELECT
  COUNT(*) as total_tickets,
  SUM(CASE WHEN status = 'available' THEN 1 ELSE 0 END) as available_tickets,
  SUM(CASE WHEN status = 'sold' THEN 1 ELSE 0 END) as sold_tickets
 FROM event_tickets
 WHERE event_id = ?
", [$eventId]);

 // Get pricing rules for this event
 $ticketPricing = $db->getAll("
 SELECT
  epr.*,
  c.display_name as class_name
 FROM event_pricing_rules epr
 JOIN classes c ON epr.class_id = c.id
 WHERE epr.event_id = ?
 ORDER BY c.sort_order ASC
", [$eventId]);

 // Check ticket deadline
 $ticketDeadlineDays = $event['ticket_deadline_days'] ?? 7;
 $eventDate = new DateTime($event['date']);
 $ticketDeadline = clone $eventDate;
 $ticketDeadline->modify("-{$ticketDeadlineDays} days");
 $ticketSalesOpen = new DateTime() <= $ticketDeadline;
}

// Check event format to determine display mode
$eventFormat = $event['event_format'] ?? 'ENDURO';
$isDH = in_array($eventFormat, ['DH_STANDARD', 'DH_SWECUP']);

// Fetch all results for this event with rider and class info
// Order by finish_time within each class to calculate correct positions
$results = $db->getAll("
 SELECT
 res.*,
 r.firstname,
 r.lastname,
 r.gender,
 r.birth_year,
 r.license_number,
 c.name as club_name,
 cls.name as class_name,
 cls.display_name as class_display_name,
 cls.sort_order as class_sort_order,
 COALESCE(cls.ranking_type, 'time') as ranking_type
 FROM results res
 INNER JOIN riders r ON res.cyclist_id = r.id
 LEFT JOIN clubs c ON r.club_id = c.id
 LEFT JOIN classes cls ON res.class_id = cls.id
 WHERE res.event_id = ?
 ORDER BY
 cls.sort_order ASC,
 COALESCE(cls.name, 'Oklassificerad'),
 CASE WHEN res.status = 'finished' THEN 0 ELSE 1 END,
 res.finish_time ASC
", [$eventId]);

// Check if any results have bib numbers
$hasBibNumbers = false;
foreach ($results as $result) {
 if (!empty($result['bib_number'])) {
 $hasBibNumbers = true;
 break;
 }
}

// Check if any results have split times
$hasSplitTimes = false;
foreach ($results as $result) {
 for ($i = 1; $i <= 15; $i++) {
 if (!empty($result['ss' . $i])) {
  $hasSplitTimes = true;
  break 2;
 }
 }
}

// Parse stage names configuration
$stageNames = [];
if (!empty($event['stage_names'])) {
 $stageNames = json_decode($event['stage_names'], true) ?: [];
}

// Helper function to get stage display name
function getStageName($stageNum, $stageNames) {
 if (isset($stageNames[$stageNum])) {
 return $stageNames[$stageNum];
 }
 return 'SS' . $stageNum;
}

// Group results by class
$resultsByClass = [];
$totalParticipants = count($results);
$totalFinished = 0;

foreach ($results as $result) {
 // Use class_id as key to avoid grouping classes with same short name
 $classKey = $result['class_id'] ?? 'no_class';
 $className = $result['class_name'] ?? 'Oklassificerad';

 if (!isset($resultsByClass[$classKey])) {
 $resultsByClass[$classKey] = [
  'display_name' => $result['class_display_name'] ?? $className,
  'class_name' => $className,
  'sort_order' => $result['class_sort_order'] ?? 999,
  'ranking_type' => $result['ranking_type'] ?? 'time',
  'results' => []
 ];
 }

 $resultsByClass[$classKey]['results'][] = $result;

 if ($result['status'] === 'finished') {
 $totalFinished++;
 }
}

// Sort results within each class based on ranking_type setting
foreach ($resultsByClass as $className => &$classData) {
 $rankingType = $classData['ranking_type'] ?? 'time';

 usort($classData['results'], function($a, $b) use ($rankingType) {
 // For time-based ranking
 if ($rankingType === 'time') {
  // Finished riders come first
  if ($a['status'] === 'finished' && $b['status'] !== 'finished') return -1;
  if ($a['status'] !== 'finished' && $b['status'] === 'finished') return 1;

  // Both finished - sort by time
  if ($a['status'] === 'finished' && $b['status'] === 'finished') {
  $aSeconds = timeToSeconds($a['finish_time']);
  $bSeconds = timeToSeconds($b['finish_time']);
  return $aSeconds <=> $bSeconds;
  }

  // Both not finished - sort by status priority: DNF > DQ > DNS
  $statusPriority = ['dnf' => 1, 'dq' => 2, 'dns' => 3];
  $aPriority = $statusPriority[$a['status']] ?? 4;
  $bPriority = $statusPriority[$b['status']] ?? 4;

  if ($aPriority !== $bPriority) {
  return $aPriority <=> $bPriority;
  }

  // Both DNF - sort by number of completed stages (more stages = higher)
  if ($a['status'] === 'dnf' && $b['status'] === 'dnf') {
  $aStages = 0;
  $bStages = 0;
  for ($i = 1; $i <= 10; $i++) {
   if (!empty($a['ss' . $i])) $aStages++;
   if (!empty($b['ss' . $i])) $bStages++;
  }
  // More stages = better position (lower in sort)
  return $bStages <=> $aStages;
  }

  return 0;
 } elseif ($rankingType === 'name') {
  // Sort alphabetically by name
  $aName = ($a['firstname'] ?? '') . ' ' . ($a['lastname'] ?? '');
  $bName = ($b['firstname'] ?? '') . ' ' . ($b['lastname'] ?? '');
  return strcasecmp($aName, $bName);
 } elseif ($rankingType === 'bib') {
  // Sort by bib number
  $aBib = (int)($a['bib_number'] ?? 9999);
  $bBib = (int)($b['bib_number'] ?? 9999);
  return $aBib <=> $bBib;
 }

 return 0;
 });

 // Calculate positions after sorting
 // For time-based ranking, only finished riders get positions
 // For name/bib ranking, all riders get sequential numbers (not real positions)
 $position = 0;
 foreach ($classData['results'] as &$result) {
 if ($rankingType === 'time') {
  if ($result['status'] === 'finished') {
  $position++;
  $result['class_position'] = $position;
  } else {
  $result['class_position'] = null;
  }
 } else {
  // For name/bib ranking, no positions shown (it's just a list)
  $result['class_position'] = null;
 }
 }
 unset($result); // Important: unset reference to avoid PHP reference issues
}
unset($classData);

// Calculate split time statistics for color coding
foreach ($resultsByClass as $classKey => &$classData) {
 $classData['split_stats'] = [];

 // For each split (ss1 to ss15)
 for ($ss = 1; $ss <= 15; $ss++) {
 $times = [];
 foreach ($classData['results'] as $result) {
  if (!empty($result['ss' . $ss]) && $result['status'] === 'finished') {
  $times[] = timeToSeconds($result['ss' . $ss]);
  }
 }

 if (count($times) >= 2) {
  sort($times);
  $min = $times[0];
  $max = $times[count($times) - 1];

  // Handle outliers: if max is way behind, use 90th percentile as effective max
  // This prevents one slow time from making everyone else green
  if (count($times) >= 3) {
  $p90Index = (int) floor(count($times) * 0.9);
  $p90 = $times[$p90Index];

  // If max is more than 30% above 90th percentile, it's an outlier
  if ($max > $p90 * 1.3) {
   $max = $p90;
  }
  }

  $classData['split_stats'][$ss] = [
  'min' => $min,
  'max' => $max,
  'range' => $max - $min
  ];
 }
 }
}
unset($classData);

// Sort classes by their sort_order
uksort($resultsByClass, function($a, $b) use ($resultsByClass) {
 return $resultsByClass[$a]['sort_order'] - $resultsByClass[$b]['sort_order'];
});

// Determine active tab - default to 'resultat' if results exist, otherwise 'info'
$hasResults = !empty($results);
$defaultTab = $hasResults ? 'resultat' : 'info';
$activeTab = isset($_GET['tab']) ? $_GET['tab'] : $defaultTab;

// Check if registration is open for tab ordering
$registrationOpen = !empty($event['registration_deadline']) && strtotime($event['registration_deadline']) >= time();

/**
 * Format time string: remove leading 00: but keep hundredths/tenths
 *"00:04:17.54" ->"4:17.54"
 *"01:23:45.12" ->"1:23:45.12"
 */
function formatDisplayTime($time) {
 if (empty($time)) return null;

 // Extract decimal part if present
 $decimal = '';
 if (preg_match('/(\.\d+)$/', $time, $matches)) {
 $decimal = $matches[1];
 $time = preg_replace('/\.\d+$/', '', $time);
 }

 // Parse time parts
 $parts = explode(':', $time);
 if (count($parts) === 3) {
 $hours = (int)$parts[0];
 $minutes = (int)$parts[1];
 $seconds = (int)$parts[2];

 if ($hours > 0) {
  return sprintf('%d:%02d:%02d', $hours, $minutes, $seconds) . $decimal;
 } else {
  return sprintf('%d:%02d', $minutes, $seconds) . $decimal;
 }
 } elseif (count($parts) === 2) {
 $minutes = (int)$parts[0];
 $seconds = (int)$parts[1];
 return sprintf('%d:%02d', $minutes, $seconds) . $decimal;
 }

 return $time . $decimal;
}

/**
 * Convert time string to seconds for calculation (including decimals)
 */
function timeToSeconds($time) {
 if (empty($time)) return 0;

 // Extract decimal part if present
 $decimal = 0;
 if (preg_match('/\.(\d+)$/', $time, $matches)) {
 $decimal = floatval('0.' . $matches[1]);
 $time = preg_replace('/\.\d+$/', '', $time);
 }

 $parts = explode(':', $time);
 if (count($parts) === 3) {
 return (int)$parts[0] * 3600 + (int)$parts[1] * 60 + (int)$parts[2] + $decimal;
 } elseif (count($parts) === 2) {
 return (int)$parts[0] * 60 + (int)$parts[1] + $decimal;
 }
 return $decimal;
}

// Calculate time behind leader for each class (only for time-based ranking)
foreach ($resultsByClass as $className => &$classData) {
 $rankingType = $classData['ranking_type'] ?? 'time';

 // Skip time_behind calculation for non-time ranking types
 if ($rankingType !== 'time') {
 foreach ($classData['results'] as &$result) {
  $result['time_behind_formatted'] = null;
 }
 unset($result);
 continue;
 }

 $winnerTime = null;
 $winnerSeconds = 0;

 foreach ($classData['results'] as $result) {
 if ($result['class_position'] == 1 && !empty($result['finish_time']) && $result['status'] === 'finished') {
  $winnerTime = $result['finish_time'];
  $winnerSeconds = timeToSeconds($winnerTime);
  break;
 }
 }

 foreach ($classData['results'] as &$result) {
 if ($winnerSeconds > 0 && !empty($result['finish_time']) && $result['status'] === 'finished' && $result['class_position'] > 1) {
  $riderSeconds = timeToSeconds($result['finish_time']);
  $diffSeconds = $riderSeconds - $winnerSeconds;

  if ($diffSeconds > 0) {
  $hours = floor($diffSeconds / 3600);
  $minutes = floor(($diffSeconds % 3600) / 60);
  $wholeSeconds = floor($diffSeconds) % 60;
  $decimals = $diffSeconds - floor($diffSeconds);

  // Format decimal part (keep 2 decimal places)
  $decimalStr = $decimals > 0 ? sprintf('.%02d', round($decimals * 100)) : '';

  if ($hours > 0) {
   $result['time_behind_formatted'] = sprintf('+%d:%02d:%02d', $hours, $minutes, $wholeSeconds) . $decimalStr;
  } else {
   $result['time_behind_formatted'] = sprintf('+%d:%02d', $minutes, $wholeSeconds) . $decimalStr;
  }
  } else {
  $result['time_behind_formatted'] = null;
  }
 } else {
  $result['time_behind_formatted'] = null;
 }
 }
 unset($result); // Important: unset reference to avoid PHP reference issues
}
unset($classData);

$pageTitle = $event['name'];
$pageType = 'public';
include __DIR__ . '/includes/layout-header.php';
?>

<main class="main-content">
 <div class="container">

 <!-- Event Header -->
 <div class="card mb-lg">
  <div class="card-body event-header-content">
  <div class="mb-lg">
   <a href="/events.php" class="btn btn--secondary btn--sm">
   <i data-lucide="arrow-left" class="icon-md"></i>
   Tillbaka till tävlingar
   </a>
  </div>

  <div class="event-header-layout">
   <?php if ($event['series_logo']): ?>
   <div class="event-logo">
    <img src="<?= h($event['series_logo']) ?>"
     alt="<?= h($event['series_name'] ?? 'Serie') ?>">
   </div>
   <?php endif; ?>

   <div class="event-info">
   <h1 class="text-primary mb-sm event-title">
    <?= h($event['name']) ?>
   </h1>

   <div class="flex gap-md flex-wrap mb-md event-meta">
    <div class="flex items-center gap-xs">
    <i data-lucide="calendar" class="icon-md"></i>
    <span class="text-secondary">
     <?= date('l j F Y', strtotime($event['date'])) ?>
    </span>
    </div>

    <?php if ($event['venue_name']): ?>
    <div class="flex items-center gap-xs">
     <i data-lucide="map-pin" class="icon-md"></i>
     <span class="text-secondary">
     <?= h($event['venue_name']) ?>
     <?php if ($event['venue_city']): ?>
      , <?= h($event['venue_city']) ?>
     <?php endif; ?>
     </span>
    </div>
    <?php elseif ($event['location']): ?>
    <div class="flex items-center gap-xs">
     <i data-lucide="map-pin" class="icon-md"></i>
     <span class="text-secondary"><?= h($event['location']) ?></span>
    </div>
    <?php endif; ?>

    <?php if ($event['series_name']): ?>
    <div class="flex items-center gap-xs">
     <i data-lucide="award" class="icon-md"></i>
     <span class="badge badge-primary">
     <?= h($event['series_name']) ?>
     </span>
    </div>
    <?php endif; ?>
   </div>

   <!-- Organizing Club Info -->
   <?php if (!empty($event['organizer'])): ?>
   <div class="event-organizer-info mt-md">
    <div class="flex items-center gap-xs mb-sm">
    <i data-lucide="building-2" class="icon-md text-primary"></i>
    <strong class="text-primary"><?= h($event['organizer']) ?></strong>
    </div>

    <div class="flex gap-md flex-wrap">
    <?php if (!empty($event['website'])): ?>
    <a href="<?= h($event['website']) ?>"
     target="_blank"
     rel="noopener noreferrer"
     class="flex items-center gap-xs link text-sm">
     <i data-lucide="globe" class="icon-sm"></i>
     Webbplats
    </a>
    <?php endif; ?>

    <?php if (!empty($event['contact_email'])): ?>
    <a href="mailto:<?= h($event['contact_email']) ?>"
     class="flex items-center gap-xs link text-sm">
     <i data-lucide="mail" class="icon-sm"></i>
     <?= h($event['contact_email']) ?>
    </a>
    <?php endif; ?>

    <?php if (!empty($event['contact_phone'])): ?>
    <a href="tel:<?= h($event['contact_phone']) ?>"
     class="flex items-center gap-xs link text-sm">
     <i data-lucide="phone" class="icon-sm"></i>
     <?= h($event['contact_phone']) ?>
    </a>
    <?php endif; ?>
    </div>
   </div>
   <?php endif; ?>
   </div>
  </div>
  </div>
 </div>

 <!-- Tab Navigation -->
 <div class="gs-event-tabs-wrapper mb-lg">
  <div class="gs-event-tabs">
  <?php if ($hasResults): ?>
  <a href="?id=<?= $eventId ?>&tab=resultat"
   class="event-tab <?= $activeTab === 'resultat' ? 'active' : '' ?>">
   <i data-lucide="trophy"></i>
   Resultat
   <span class="badge badge-accent badge-sm"><?= $totalParticipants ?></span>
  </a>
  <?php endif; ?>

  <a href="?id=<?= $eventId ?>&tab=info"
   class="event-tab <?= $activeTab === 'info' ? 'active' : '' ?>">
   <i data-lucide="info"></i>
   Information
  </a>

  <?php if (!empty($event['pm_content']) || !empty($event['pm_use_global'])): ?>
  <a href="?id=<?= $eventId ?>&tab=pm"
   class="event-tab <?= $activeTab === 'pm' ? 'active' : '' ?>">
   <i data-lucide="clipboard-list"></i>
   PM
  </a>
  <?php endif; ?>

  <?php if (!empty($event['jury_communication']) || !empty($event['jury_use_global'])): ?>
  <a href="?id=<?= $eventId ?>&tab=jury"
   class="event-tab <?= $activeTab === 'jury' ? 'active' : '' ?>">
   <i data-lucide="gavel"></i>
   Jurykommuniké
  </a>
  <?php endif; ?>

  <?php if (!empty($event['competition_schedule']) || !empty($event['schedule_use_global'])): ?>
  <a href="?id=<?= $eventId ?>&tab=schema"
   class="event-tab <?= $activeTab === 'schema' ? 'active' : '' ?>">
   <i data-lucide="calendar-clock"></i>
   Tävlingsschema
  </a>
  <?php endif; ?>

  <?php if (!empty($event['start_times']) || !empty($event['start_times_use_global'])): ?>
  <a href="?id=<?= $eventId ?>&tab=starttider"
   class="event-tab <?= $activeTab === 'starttider' ? 'active' : '' ?>">
   <i data-lucide="clock"></i>
   Starttider
  </a>
  <?php endif; ?>

  <?php if (!empty($event['map_content']) || !empty($event['map_image_url']) || !empty($event['map_use_global'])): ?>
  <a href="/map.php?id=<?= $eventId ?>"
   class="event-tab <?= $activeTab === 'karta' ? 'active' : '' ?>">
   <i data-lucide="map"></i>
   Karta
  </a>
  <?php endif; ?>

  <a href="?id=<?= $eventId ?>&tab=anmalda"
   class="event-tab <?= $activeTab === 'anmalda' ? 'active' : '' ?>">
   <i data-lucide="users"></i>
   Anmälda
   <span class="badge badge-secondary badge-sm"><?= $totalRegistrations ?></span>
  </a>

  <?php if ($ticketingEnabled && !empty($ticketPricing)): ?>
  <a href="?id=<?= $eventId ?>&tab=biljetter"
   class="event-tab <?= $activeTab === 'biljetter' ? 'active' : '' ?>">
   <i data-lucide="ticket"></i>
   Biljetter
   <?php if ($ticketData && $ticketData['available_tickets'] > 0): ?>
   <span class="badge badge-success badge-sm"><?= $ticketData['available_tickets'] ?></span>
   <?php endif; ?>
  </a>
  <?php endif; ?>

  <?php if ($registrationOpen): ?>
  <a href="?id=<?= $eventId ?>&tab=anmalan"
   class="event-tab <?= $activeTab === 'anmalan' ? 'active' : '' ?>">
   <i data-lucide="user-plus"></i>
   Anmälan
  </a>
  <?php endif; ?>
  </div>
 </div>

 <!-- Tab Content -->
 <?php if ($activeTab === 'resultat'): ?>
 <!-- RESULTS TAB -->
 <?php if (empty($results)): ?>
  <div class="card gs-empty-state">
  <i data-lucide="trophy" class="gs-empty-icon"></i>
  <h3 class="mb-sm">Inga resultat ännu</h3>
  <p class="text-secondary">
   Resultat har inte laddats upp för denna tävling.
  </p>
  </div>
 <?php else: ?>
  <?php if ($hasSplitTimes && !$isDH): ?>
  <div class="mb-md flex gs-justify-end gap-md">
  <label class="checkbox gs-split-times-toggle">
   <input type="checkbox" id="globalSplitToggle" onchange="toggleAllSplitTimes(this.checked)">
   <span class="text-sm">Visa sträcktider</span>
  </label>
  <label class="checkbox">
   <input type="checkbox" id="colorToggle" checked onchange="toggleSplitColors(this.checked)">
   <span class="text-sm">Färgkodning</span>
  </label>
  </div>
  <?php endif; ?>
  <?php foreach ($resultsByClass as $groupName => $groupData): ?>
  <div class="card mb-lg class-section" data-group="<?= h($groupName) ?>">
   <div class="card-header">
   <h2 class="text-primary">
    <i data-lucide="users" class="icon-md"></i>
    <?= h($groupData['display_name']) ?>
    <span class="badge badge-secondary ml-sm">
    <?= count($groupData['results']) ?> deltagare
    </span>
   </h2>
   </div>
   <?php
   // Check which SS columns this class has data for (ss1-ss15)
   $classSplitCols = [];
   if ($hasSplitTimes && !$isDH) {
   // DEBUG: Add HTML comment to diagnose segment detection
   $debugInfo = [];

   // Explicitly check all 15 possible segments
   for ($i = 1; $i <= 15; $i++) {
    $hasData = false;
    $riderCount = 0;
    // Check if any rider in this class has data for this segment
    if (isset($groupData['results']) && is_array($groupData['results'])) {
    foreach ($groupData['results'] as $r) {
     // Check if segment column exists and has a non-empty value
     if (isset($r['ss' . $i]) && $r['ss' . $i] !== null && $r['ss' . $i] !== '') {
     $hasData = true;
     $riderCount++;
     }
    }
    }
    // Add this segment number if we found data
    if ($hasData) {
    $classSplitCols[] = $i;
    $debugInfo[] ="ss$i: $riderCount riders";
    }
   }

   // Output debug info as HTML comment
   echo"<!-- DEBUG Segment Detection for class" . h($groupData['display_name']) .":\n";
   echo" Total riders in class:" . count($groupData['results']) ."\n";
   echo" Segments found:" . implode(', ', $debugInfo) ."\n";
   echo" classSplitCols array: [" . implode(', ', $classSplitCols) ."]\n";
   echo"-->\n";
   }
   ?>
   <div class="card-body card-table-container">
   <!-- Desktop/Landscape Table View -->
   <div class="table-wrapper">
   <table class="table gs-results-table">
    <thead>
    <tr>
     <th class="table-col-narrow">Plac.</th>
     <th>Namn</th>
     <th class="gs-club-col col-landscape">Klubb</th>
     <?php if ($hasBibNumbers): ?>
     <th class="table-col-medium col-tablet">Startnr</th>
     <?php endif; ?>
     <?php if ($isDH): ?>
     <th class="table-col-medium col-tablet">Åk 1</th>
     <th class="table-col-medium col-tablet">Åk 2</th>
     <th class="table-col-medium">Bästa</th>
     <?php else: ?>
     <?php $colIndex = 3 + ($hasBibNumbers ? 1 : 0); ?>
     <th class="table-col-medium gs-sortable-header" onclick="sortTable(this, <?= $colIndex++ ?>)">Tid</th>
     <th class="table-col-medium col-landscape">+Tid</th>
     <?php $colIndex++; ?>
     <?php foreach ($classSplitCols as $ssNum): ?>
      <th class="table-col-medium gs-split-time-col gs-col-desktop gs-sortable-header" onclick="sortTable(this, <?= $colIndex++ ?>)"><?= h(getStageName($ssNum, $stageNames)) ?></th>
     <?php endforeach; ?>
     <?php endif; ?>
     <?php if ($isDH): ?>
     <th class="table-col-medium col-landscape">+Tid</th>
     <?php endif; ?>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($groupData['results'] as $result): ?>
     <tr class="result-row">
     <td class="table-center font-bold">
      <?php if ($result['status'] === 'finished' && $result['class_position']): ?>
      <?php if ($result['class_position'] == 1): ?>
       <span class="gs-medal">🥇</span>
      <?php elseif ($result['class_position'] == 2): ?>
       <span class="gs-medal">🥈</span>
      <?php elseif ($result['class_position'] == 3): ?>
       <span class="gs-medal">🥉</span>
      <?php else: ?>
       <?= $result['class_position'] ?>
      <?php endif; ?>
      <?php elseif ($result['status'] === 'dnf'): ?>
      <span class="text-warning">DNF</span>
      <?php elseif ($result['status'] === 'dns'): ?>
      <span class="text-secondary">DNS</span>
      <?php elseif ($result['status'] === 'dq'): ?>
      <span class="text-error">DQ</span>
      <?php else: ?>
      <span class="text-secondary">-</span>
      <?php endif; ?>
     </td>

     <td>
      <a href="/rider/<?= $result['cyclist_id'] ?>" class="gs-rider-link">
      <?= h($result['firstname']) ?> <?= h($result['lastname']) ?>
      </a>
     </td>

     <td class="gs-club-col col-landscape">
      <?php if ($result['club_name']): ?>
      <span class="badge badge-secondary badge-sm">
       <?= h($result['club_name']) ?>
      </span>
      <?php else: ?>
      <span class="text-secondary">-</span>
      <?php endif; ?>
     </td>

     <?php if ($hasBibNumbers): ?>
      <td class="table-center col-tablet">
      <?= $result['bib_number'] ? h($result['bib_number']) : '-' ?>
      </td>
     <?php endif; ?>

     <?php if ($isDH): ?>
      <!-- DH: Show both run times -->
      <td class="table-time-cell col-tablet">
      <?php if ($result['run_1_time']): ?>
       <?= formatDisplayTime($result['run_1_time']) ?>
      <?php else: ?>
       <span class="text-secondary">-</span>
      <?php endif; ?>
      </td>
      <td class="table-time-cell col-tablet">
      <?php if ($result['run_2_time']): ?>
       <?= formatDisplayTime($result['run_2_time']) ?>
      <?php else: ?>
       <span class="text-secondary">-</span>
      <?php endif; ?>
      </td>
      <td class="table-time-cell font-bold">
      <?php
      // Show fastest time (for standard DH) or run 2 (for SweCup)
      $bestTime = null;
      if ($eventFormat === 'DH_SWECUP') {
       $bestTime = $result['run_2_time'];
      } else {
       if ($result['run_1_time'] && $result['run_2_time']) {
       $bestTime = min($result['run_1_time'], $result['run_2_time']);
       } elseif ($result['run_1_time']) {
       $bestTime = $result['run_1_time'];
       } else {
       $bestTime = $result['run_2_time'];
       }
      }
      if ($bestTime && $result['status'] === 'finished'):
      ?>
       <?= formatDisplayTime($bestTime) ?>
      <?php else: ?>
       <span class="text-secondary">-</span>
      <?php endif; ?>
      </td>
     <?php else: ?>
      <!-- Enduro/Other: Show finish time -->
      <td class="table-time-cell">
      <?php if ($result['finish_time'] && $result['status'] === 'finished'): ?>
       <?= formatDisplayTime($result['finish_time']) ?>
      <?php else: ?>
       <span class="text-secondary">-</span>
      <?php endif; ?>
      </td>
      <!-- +Tid right after total time -->
      <td class="table-time-cell text-secondary col-landscape">
      <?= $result['time_behind_formatted'] ?? '-' ?>
      </td>
      <!-- Split times (per class) with color coding -->
      <?php foreach ($classSplitCols as $ssNum):
      $splitClass = '';
      $splitTime = $result['ss' . $ssNum] ?? '';
      // Apply colors to any rider with split time data (including DNF)
      if (!empty($splitTime) && isset($groupData['split_stats'][$ssNum])) {
       $stats = $groupData['split_stats'][$ssNum];
       $timeSeconds = timeToSeconds($splitTime);
       // Only color if there's meaningful range (> 0.5 seconds)
       if ($stats['range'] > 0.5) {
       // Calculate position in range (0 = fastest, 1 = slowest)
       $position = ($timeSeconds - $stats['min']) / $stats['range'];
       // Map to 10 levels: split-1 (fastest) to split-10 (slowest)
       // Use floor to better distribute (0-10% = 1, 10-20% = 2, etc.)
       $level = min(10, max(1, floor($position * 9) + 1));
       $splitClass = 'gs-split-' . $level;
       }
       // If range is 0 or very small, no color (all essentially tied)
      }
      ?>
      <td class="table-time-cell gs-split-time-col gs-col-desktop <?= $splitClass ?>">
       <?php if (!empty($splitTime)): ?>
       <?= formatDisplayTime($splitTime) ?>
       <?php else: ?>
       -
       <?php endif; ?>
      </td>
      <?php endforeach; ?>
     <?php endif; ?>
     <?php if ($isDH): ?>
     <!-- +Tid for DH -->
     <td class="table-time-cell text-secondary col-landscape">
      <?= $result['time_behind_formatted'] ?? '-' ?>
     </td>
     <?php endif; ?>
     </tr>
    <?php endforeach; ?>
    </tbody>
   </table>
   </div>

   <!-- Mobile Portrait Card View -->
   <div class="result-list">
    <?php foreach ($groupData['results'] as $result): ?>
    <a href="/rider/<?= $result['cyclist_id'] ?>" class="result-item">
     <div class="result-place <?= ($result['class_position'] && $result['class_position'] <= 3) ? 'top-3' : '' ?>">
      <?php if ($result['status'] === 'finished' && $result['class_position']): ?>
       <?php if ($result['class_position'] == 1): ?>🥇
       <?php elseif ($result['class_position'] == 2): ?>🥈
       <?php elseif ($result['class_position'] == 3): ?>🥉
       <?php else: ?><?= $result['class_position'] ?>
       <?php endif; ?>
      <?php elseif ($result['status'] === 'dnf'): ?>DNF
      <?php elseif ($result['status'] === 'dns'): ?>DNS
      <?php elseif ($result['status'] === 'dq'): ?>DQ
      <?php else: ?>-
      <?php endif; ?>
     </div>
     <div class="result-info">
      <div class="result-name"><?= h($result['firstname']) ?> <?= h($result['lastname']) ?></div>
      <div class="result-club"><?= h($result['club_name'] ?? '-') ?></div>
     </div>
     <div class="result-time">
      <?php if ($result['status'] === 'finished' && $result['finish_time']): ?>
       <?= formatDisplayTime($result['finish_time']) ?>
      <?php endif; ?>
     </div>
    </a>
    <?php endforeach; ?>
   </div>

   </div>
  </div>
  <?php endforeach; ?>
 <?php endif; ?>

 <?php elseif ($activeTab === 'info'): ?>
 <!-- INFORMATION TAB -->
 <div class="card mb-lg">
  <div class="card-header">
  <h2 class="text-primary">
   <i data-lucide="building"></i>
   Faciliteter & Logistik
  </h2>
  </div>
  <div class="card-body">
  <div class="grid grid-cols-1 md-grid-cols-2 gap-lg">
   <!-- Left Column -->
   <div>
   <?php
   $driverMeeting = getEventContent($event, 'driver_meeting', 'driver_meeting_use_global', $globalTextMap);
   if (!empty($driverMeeting)): ?>
    <div class="mb-lg">
    <h3 class="mb-sm text-primary">
     <i data-lucide="megaphone" class="icon-sm"></i>
     Förarmöte
    </h3>
    <div class="text-secondary">
     <?= nl2br(h($driverMeeting)) ?>
    </div>
    </div>
   <?php endif; ?>

   <?php
   $trainingInfo = getEventContent($event, 'training_info', 'training_use_global', $globalTextMap);
   if (!empty($trainingInfo)): ?>
    <div class="mb-lg">
    <h3 class="mb-sm text-primary">
     <i data-lucide="bike" class="icon-sm"></i>
     Träning
    </h3>
    <div class="text-secondary">
     <?= nl2br(h($trainingInfo)) ?>
    </div>
    </div>
   <?php endif; ?>

   <?php
   $timingInfo = getEventContent($event, 'timing_info', 'timing_use_global', $globalTextMap);
   if (!empty($timingInfo)): ?>
    <div class="mb-lg">
    <h3 class="mb-sm text-primary">
     <i data-lucide="timer" class="icon-sm"></i>
     Tidtagning
    </h3>
    <div class="text-secondary">
     <?= nl2br(h($timingInfo)) ?>
    </div>
    </div>
   <?php endif; ?>

   <?php
   $liftInfo = getEventContent($event, 'lift_info', 'lift_use_global', $globalTextMap);
   if (!empty($liftInfo)): ?>
    <div class="mb-lg">
    <h3 class="mb-sm text-primary">
     <i data-lucide="cable-car" class="icon-sm"></i>
     Lift
    </h3>
    <div class="text-secondary">
     <?= nl2br(h($liftInfo)) ?>
    </div>
    </div>
   <?php endif; ?>
   </div>

   <!-- Right Column -->
   <div>
   <?php
   $foodCafe = getEventContent($event, 'food_cafe', 'food_use_global', $globalTextMap);
   if (!empty($foodCafe)): ?>
    <div class="mb-lg">
    <h3 class="mb-sm text-primary">
     <i data-lucide="utensils" class="icon-sm"></i>
     Mat/Café
    </h3>
    <div class="text-secondary">
     <?= nl2br(h($foodCafe)) ?>
    </div>
    </div>
   <?php endif; ?>

   <?php if (!empty($event['parking_detailed'])): ?>
    <div class="mb-lg">
    <h3 class="mb-sm text-primary">
     <i data-lucide="car" class="icon-sm"></i>
     Parkering
    </h3>
    <div class="text-secondary">
     <?= nl2br(h($event['parking_detailed'])) ?>
    </div>
    </div>
   <?php endif; ?>

   <?php if (!empty($event['hotel_accommodation'])): ?>
    <div class="mb-lg">
    <h3 class="mb-sm text-primary">
     <i data-lucide="bed" class="icon-sm"></i>
     Hotell/Boende
    </h3>
    <div class="text-secondary">
     <?= nl2br(h($event['hotel_accommodation'])) ?>
    </div>
    </div>
   <?php endif; ?>
   </div>
  </div>
  </div>
 </div>

 <?php elseif ($activeTab === 'pm'): ?>
 <!-- PM TAB -->
 <div class="card mb-lg">
  <div class="card-header">
  <h2 class="text-primary">
   <i data-lucide="clipboard-list"></i>
   PM (Promemoria)
  </h2>
  </div>
  <div class="card-body">
  <?php
  $pmContent = $event['pm_content'] ?? '';
  if ($event['pm_use_global'] ?? false) {
   $globalPm = $db->getRow("SELECT content FROM global_texts WHERE field_key = 'pm_content'");
   $pmContent = $globalPm['content'] ?? $pmContent;
  }
  ?>
  <?php if ($pmContent): ?>
   <div class="text-secondary">
   <?= nl2br(h($pmContent)) ?>
   </div>
  <?php else: ?>
   <p class="text-secondary">Inget PM tillgängligt för detta event.</p>
  <?php endif; ?>
  </div>
 </div>

 <?php elseif ($activeTab === 'jury'): ?>
 <!-- JURY COMMUNICATION TAB -->
 <div class="card mb-lg">
  <div class="card-header">
  <h2 class="text-primary">
   <i data-lucide="gavel"></i>
   Jurykommuniké
  </h2>
  </div>
  <div class="card-body">
  <?php
  $juryContent = $event['jury_communication'] ?? '';
  if ($event['jury_use_global'] ?? false) {
   $globalJury = $db->getRow("SELECT content FROM global_texts WHERE field_key = 'jury_communication'");
   $juryContent = $globalJury['content'] ?? $juryContent;
  }
  ?>
  <?php if ($juryContent): ?>
   <div class="text-secondary">
   <?= nl2br(h($juryContent)) ?>
   </div>
  <?php else: ?>
   <p class="text-secondary">Ingen jurykommuniké tillgänglig.</p>
  <?php endif; ?>
  </div>
 </div>

 <?php elseif ($activeTab === 'schema'): ?>
 <!-- COMPETITION SCHEDULE TAB -->
 <div class="card mb-lg">
  <div class="card-header">
  <h2 class="text-primary">
   <i data-lucide="calendar-clock"></i>
   Tävlingsschema
  </h2>
  </div>
  <div class="card-body">
  <?php
  $scheduleContent = $event['competition_schedule'] ?? '';
  if ($event['schedule_use_global'] ?? false) {
   $globalSchedule = $db->getRow("SELECT content FROM global_texts WHERE field_key = 'competition_schedule'");
   $scheduleContent = $globalSchedule['content'] ?? $scheduleContent;
  }
  ?>
  <?php if ($scheduleContent): ?>
   <div class="text-secondary">
   <?= nl2br(h($scheduleContent)) ?>
   </div>
  <?php else: ?>
   <p class="text-secondary">Inget tävlingsschema tillgängligt.</p>
  <?php endif; ?>
  </div>
 </div>

 <?php elseif ($activeTab === 'starttider'): ?>
 <!-- START TIMES TAB -->
 <div class="card mb-lg">
  <div class="card-header">
  <h2 class="text-primary">
   <i data-lucide="clock"></i>
   Starttider
  </h2>
  </div>
  <div class="card-body">
  <?php
  $startContent = $event['start_times'] ?? '';
  if ($event['start_times_use_global'] ?? false) {
   $globalStart = $db->getRow("SELECT content FROM global_texts WHERE field_key = 'start_times'");
   $startContent = $globalStart['content'] ?? $startContent;
  }
  ?>
  <?php if ($startContent): ?>
   <div class="text-secondary">
   <?= nl2br(h($startContent)) ?>
   </div>
  <?php else: ?>
   <p class="text-secondary">Inga starttider publicerade ännu.</p>
  <?php endif; ?>
  </div>
 </div>

 <?php elseif ($activeTab === 'karta'): ?>
 <!-- MAP TAB -->
 <div class="card mb-lg">
  <div class="card-header">
  <h2 class="text-primary">
   <i data-lucide="map"></i>
   Karta
  </h2>
  </div>
  <div class="card-body">
  <?php if (!empty($event['map_image_url'])): ?>
   <div class="mb-lg">
   <img src="<?= h($event['map_image_url']) ?>"
    alt="Karta"
    style="max-width: 100%; height: auto; border-radius: 0.5rem;">
   </div>
  <?php endif; ?>

  <?php
  $mapContent = $event['map_content'] ?? '';
  if ($event['map_use_global'] ?? false) {
   $globalMap = $db->getRow("SELECT content FROM global_texts WHERE field_key = 'map_content'");
   $mapContent = $globalMap['content'] ?? $mapContent;
  }
  ?>
  <?php if ($mapContent): ?>
   <div class="text-secondary">
   <?= nl2br(h($mapContent)) ?>
   </div>
  <?php elseif (empty($event['map_image_url'])): ?>
   <p class="text-secondary">Ingen karta tillgänglig.</p>
  <?php endif; ?>

  <?php if (!empty($event['venue_coordinates'])): ?>
   <div class="mt-lg">
   <a href="https://www.google.com/maps?q=<?= urlencode($event['venue_coordinates']) ?>"
    target="_blank"
    class="btn btn--secondary">
    <i data-lucide="navigation"></i>
    Öppna i Google Maps
   </a>
   </div>
  <?php endif; ?>
  </div>
 </div>

 <?php elseif ($activeTab === 'biljetter'): ?>
 <!-- TICKETS TAB -->
 <div class="card mb-lg">
  <div class="card-header">
  <h2 class="text-primary">
   <i data-lucide="ticket"></i>
   Köp biljett
  </h2>
  </div>
  <div class="card-body">
  <?php if (!$ticketingEnabled): ?>
   <div class="alert alert--info">
   <p>Biljettförsäljning är inte aktiverad för detta event.</p>
   </div>
  <?php elseif (!$ticketSalesOpen): ?>
   <div class="alert alert--warning">
   <i data-lucide="alert-circle" class="icon-md"></i>
   <div>
    <strong>Biljettförsäljningen har stängt</strong>
    <p class="text-sm gs-mt-xs">Försäljningen stängde <?= $ticketDeadline->format('d M Y') ?>.</p>
   </div>
   </div>
  <?php elseif (empty($ticketPricing)): ?>
   <div class="alert alert--info">
   <p>Prissättning för detta event är ännu inte konfigurerad.</p>
   </div>
  <?php else: ?>
   <!-- Ticket Availability -->
   <?php if ($ticketData): ?>
   <div class="mb-lg">
   <h3 class="mb-sm">
    <i data-lucide="bar-chart-2" class="icon-sm"></i>
    Tillgänglighet
   </h3>
   <?php
   $totalTickets = (int)$ticketData['total_tickets'];
   $available = (int)$ticketData['available_tickets'];
   $sold = (int)$ticketData['sold_tickets'];
   $fillPercent = $totalTickets > 0 ? round(($sold / $totalTickets) * 100) : 0;
   ?>
   <?php if ($available > 0): ?>
    <div class="flex items-center gap-md mb-sm">
    <span class="badge badge-success">
     <?= $available ?> biljetter tillgängliga
    </span>
    <span class="text-secondary text-sm">
     (<?= $sold ?>/<?= $totalTickets ?> sålda)
    </span>
    </div>
    <!-- Progress bar -->
    <div class="gs-progress mb-sm" style="height: 8px; background: var(--gs-bg-tertiary); border-radius: 4px; overflow: hidden;">
    <div style="width: <?= $fillPercent ?>%; height: 100%; background: var(--primary); transition: width 0.3s;"></div>
    </div>
   <?php else: ?>
    <div class="badge badge-error badge-lg">
    <i data-lucide="x-circle" class="icon-sm"></i>
    SLUTSÅLT
    </div>
   <?php endif; ?>
   </div>
   <?php endif; ?>

   <!-- Pricing Table -->
   <div class="mb-lg">
   <h3 class="mb-sm">
    <i data-lucide="credit-card" class="icon-sm"></i>
    Priser
   </h3>

   <?php if ($hasGravityId): ?>
    <div class="alert alert--success mb-md">
    <i data-lucide="star" class="icon-sm"></i>
    <div>
     <strong>Gravity-ID rabatt!</strong>
     <p class="text-sm gs-mt-xs">Du får <?= $chipDiscount ?> kr rabatt på chiphyra.</p>
    </div>
    </div>
   <?php endif; ?>

   <div class="table-responsive">
    <table class="table">
    <thead>
     <tr>
     <th>Klass</th>
     <th>Ordinarie pris</th>
     <th>Early-bird</th>
     <?php if ($hasGravityId): ?>
      <th>Gravity-ID</th>
     <?php endif; ?>
     <th>Pris nu</th>
     </tr>
    </thead>
    <tbody>
     <?php foreach ($ticketPricing as $pricing): ?>
     <?php
     $basePrice = (float)$pricing['base_price'];
     $earlyBirdDiscount = (float)$pricing['early_bird_discount_percent'];
     $earlyBirdEnd = $pricing['early_bird_end_date'];
     $isEarlyBird = $earlyBirdEnd && date('Y-m-d') <= $earlyBirdEnd;
     $priceAfterEarlyBird = $isEarlyBird
      ? $basePrice * (1 - $earlyBirdDiscount / 100)
      : $basePrice;
     $finalPrice = $hasGravityId
      ? $priceAfterEarlyBird - $chipDiscount
      : $priceAfterEarlyBird;
     $totalSavings = $basePrice - $finalPrice;
     ?>
     <tr>
      <td>
      <strong><?= h($pricing['class_name']) ?></strong>
      </td>
      <td class="text-secondary">
      <?= number_format($basePrice, 0) ?> kr
      </td>
      <td>
      <?php if ($isEarlyBird && $earlyBirdDiscount > 0): ?>
       <span class="badge badge-success badge-sm">
       -<?= $earlyBirdDiscount ?>%
       </span>
       <span class="text-xs text-secondary gs-ml-xs">
       t.o.m. <?= date('d/m', strtotime($earlyBirdEnd)) ?>
       </span>
      <?php elseif ($earlyBirdEnd): ?>
       <span class="text-secondary text-sm">Avslutat</span>
      <?php else: ?>
       <span class="text-secondary">-</span>
      <?php endif; ?>
      </td>
      <?php if ($hasGravityId): ?>
      <td>
      <span class="badge badge-primary badge-sm">
       -<?= $chipDiscount ?> kr
      </span>
      </td>
      <?php endif; ?>
      <td>
      <strong class="text-primary">
       <?= number_format($finalPrice, 0) ?> kr
      </strong>
      <?php if ($totalSavings > 0): ?>
       <span class="text-success text-sm">
       (spara <?= number_format($totalSavings, 0) ?> kr)
       </span>
      <?php endif; ?>
      </td>
     </tr>
     <?php endforeach; ?>
    </tbody>
    </table>
   </div>

   <p class="text-xs text-secondary mt-sm">
    * I priset ingår <?= $chipDiscount ?> kr för chiphyra. Deltagare med Gravity-ID får avdrag för detta.
   </p>
   </div>

   <!-- Buy Button -->
   <?php if ($ticketData && $ticketData['available_tickets'] > 0): ?>
   <div class="mt-lg">
    <?php if (!empty($event['woo_product_id'])): ?>
    <a href="https://shop.gravityseries.se/?add-to-cart=<?= $event['woo_product_id'] ?>"
     class="btn btn--primary btn-lg"
     target="_blank">
     <i data-lucide="shopping-cart" class="icon-md"></i>
     Köp biljett
    </a>
    <p class="text-secondary text-sm mt-sm">
     Du kommer att skickas till vår butik för att slutföra köpet.
    </p>
    <?php else: ?>
    <div class="alert alert--info">
     <p>Biljettförsäljning online är inte konfigurerad ännu. Kontakta arrangören.</p>
    </div>
    <?php endif; ?>
   </div>
   <?php endif; ?>
  <?php endif; ?>
  </div>
 </div>

 <?php elseif ($activeTab === 'anmalda'): ?>
 <!-- REGISTERED PARTICIPANTS TAB -->
 <div class="card mb-lg">
  <div class="card-header">
  <h2 class="text-primary">
   <i data-lucide="users"></i>
   Anmälda deltagare
   <span class="badge badge-primary ml-sm">
   <?= $totalRegistrations ?> anmälda
   </span>
  </h2>
  </div>
  <div class="card-body">
  <?php if (empty($registrations)): ?>
   <div class="alert alert--warning">
   <p>Inga anmälningar ännu.</p>
   </div>
  <?php else: ?>
   <div class="table-responsive">
   <table class="table">
    <thead>
    <tr>
     <th>Nr</th>
     <th>Namn</th>
     <th>Klubb</th>
     <th>Status</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($registrations as $index => $reg): ?>
     <tr>
     <td class="table-center"><?= $index + 1 ?></td>
     <td>
      <strong>
      <?= h($reg['first_name']) ?> <?= h($reg['last_name']) ?>
      </strong>
     </td>
     <td>
      <?php if (!empty($reg['club_name'])): ?>
      <span class="badge badge-secondary badge-sm">
       <?= h($reg['club_name']) ?>
      </span>
      <?php else: ?>
      <span class="text-secondary">-</span>
      <?php endif; ?>
     </td>
     <td>
      <?php
      $statusBadge = 'badge-secondary';
      $statusText = ucfirst($reg['status']);
      if ($reg['status'] === 'confirmed') {
      $statusBadge = 'badge-success';
      $statusText = 'Bekräftad';
      } elseif ($reg['status'] === 'pending') {
      $statusBadge = 'badge-warning';
      $statusText = 'Väntande';
      }
      ?>
      <span class="badge <?= $statusBadge ?> badge-sm">
      <?= $statusText ?>
      </span>
     </td>
     </tr>
    <?php endforeach; ?>
    </tbody>
   </table>
   </div>
  <?php endif; ?>
  </div>
 </div>

 <?php elseif ($activeTab === 'anmalan'): ?>
 <!-- REGISTRATION FORM TAB -->
 <div class="card mb-lg">
  <div class="card-header">
  <h2 class="text-primary">
   <i data-lucide="user-plus"></i>
   Anmälan till <?= h($event['name']) ?>
  </h2>
  </div>
  <div class="card-body">
  <?php if (!empty($event['registration_deadline']) && strtotime($event['registration_deadline']) < time()): ?>
   <div class="alert alert-danger">
   <h3 class="mb-sm">Anmälan stängd</h3>
   <p>Anmälan stängde <?= date('d M Y', strtotime($event['registration_deadline'])) ?>.</p>
   </div>
  <?php elseif (!empty($event['ticketing_enabled']) && !empty($event['woo_product_id'])): ?>
   <?php
   // Calculate deadline
   $deadlineDays = $event['ticket_deadline_days'] ?? 7;
   $eventDate = new DateTime($event['date']);
   $deadline = clone $eventDate;
   $deadline->modify("-{$deadlineDays} days");
   $now = new DateTime();
   $deadlinePassed = $now > $deadline;

   ?>

   <?php if ($deadlinePassed): ?>
   <div class="alert alert--warning mb-lg">
    <strong>Sista anmälningsdag har passerat</strong> (<?= $deadline->format('d M Y') ?>)
   </div>
   <?php else: ?>
   <!-- Registration Form -->
   <div id="registration-form">
    <p class="text-secondary mb-md">
    Sista anmälningsdag: <strong><?= $deadline->format('d M Y') ?></strong>
    <?php if ($activePriceTier === 'early_bird'): ?>
     <span class="badge badge-success ml-sm">Early Bird aktivt!</span>
    <?php elseif ($activePriceTier === 'late_fee'): ?>
     <span class="badge badge-warning ml-sm">Efteranmälan</span>
    <?php endif; ?>
    </p>

    <!-- Step 1: Find Rider -->
    <div class="card mb-md">
    <div class="card-header">
     <h3 class="">
     <i data-lucide="search"></i>
     1. Hitta deltagare
     </h3>
    </div>
    <div class="card-body">
     <div class="form-group">
     <label class="label">Sök på namn, UCI-ID eller email</label>
     <input type="text" id="rider-search" class="input"
      placeholder="T.ex. Anna Andersson eller UCI-ID">
     </div>
     <div class="mt-sm">
     <button type="button" onclick="showNewRiderForm()" class="btn btn--sm btn--secondary">
      <i data-lucide="user-plus"></i>
      Inte i registret? Registrera ny deltagare
     </button>
     </div>
     <div id="rider-results" class="mt-md" style="display: none;"></div>
     <div id="selected-rider" class="mt-md" style="display: none;">
     <div class="alert alert--success">
      <strong id="rider-name"></strong><br>
      <span class="text-sm" id="rider-details"></span>
      <span id="gravity-id-badge" style="display: none;">
      <br><span class="badge badge-primary mt-sm">Gravity-ID medlem</span>
      </span>
     </div>
     <div id="active-license-locked" class="alert alert--info mt-sm" style="display: none;">
      <i data-lucide="shield-check"></i>
      <span>Aktiv licens (låst)</span>
     </div>
     <input type="hidden" id="selected-rider-id" value="">
     <input type="hidden" id="has-gravity-id" value="0">
     </div>

     <!-- New Rider Form (for engångslicens) -->
     <div id="new-rider-form" class="mt-md" style="display: none;">
     <div class="alert alert--info mb-md">
      <strong>Ny deltagare</strong><br>
      <span class="text-sm">Fyll i uppgifterna nedan för att registrera dig med engångslicens (SWE-ID).</span>
     </div>

     <div class="grid grid-cols-1 md-grid-cols-2 gap-md">
      <div class="form-group">
      <label class="label">Förnamn *</label>
      <input type="text" id="new-rider-firstname" class="input" required>
      </div>
      <div class="form-group">
      <label class="label">Efternamn *</label>
      <input type="text" id="new-rider-lastname" class="input" required>
      </div>
      <div class="form-group">
      <label class="label">Födelseår *</label>
      <input type="number" id="new-rider-birthyear" class="input"
       min="1930" max="<?= date('Y') ?>" placeholder="T.ex. 1990" required>
      </div>
      <div class="form-group">
      <label class="label">Kön *</label>
      <select id="new-rider-gender" class="input" required>
       <option value="">Välj...</option>
       <option value="M">Man</option>
       <option value="F">Kvinna</option>
      </select>
      </div>
      <div class="form-group">
      <label class="label">E-post</label>
      <input type="email" id="new-rider-email" class="input"
       placeholder="din@email.se">
      </div>
      <div class="form-group">
      <label class="label">Telefon</label>
      <input type="tel" id="new-rider-phone" class="input"
       placeholder="070-123 45 67">
      </div>
     </div>

     <div class="mt-md flex gap-sm">
      <button type="button" onclick="createNewRider()" class="btn btn--primary">
      <i data-lucide="user-plus"></i>
      Registrera deltagare
      </button>
      <button type="button" onclick="hideNewRiderForm()" class="btn btn--secondary">
      Avbryt
      </button>
     </div>

     <div id="new-rider-error" class="alert alert-danger mt-md" style="display: none;"></div>
     </div>
    </div>
    </div>

    <!-- License Type Selection -->
    <?php $licenseClass = $event['license_class'] ?? 'national'; ?>
    <div id="license-type-section" class="card mb-md" style="display: none;">
    <div class="card-header">
     <h3 class="">
     <i data-lucide="id-card"></i>
     Din licens
     <?php if ($licenseClass === 'national'): ?>
      <span class="badge badge-warning ml-sm">Nationellt event</span>
     <?php elseif ($licenseClass === 'sportmotion'): ?>
      <span class="badge badge-info ml-sm">Sportmotion</span>
     <?php else: ?>
      <span class="badge badge-success ml-sm">Motion</span>
     <?php endif; ?>
     </h3>
    </div>
    <div class="card-body">
     <div id="license-from-profile" class="alert alert--success mb-md" style="display: none;">
     <i data-lucide="check-circle"></i>
     <span>Din licens hämtades från din profil. Ändra vid behov.</span>
     </div>
     <div id="license-not-found" class="alert alert--info mb-md" style="display: none;">
     <i data-lucide="info"></i>
     <span>Välj vilken licens du tävlar med.</span>
     </div>
     <div class="grid grid-cols-1 md-grid-cols-2 gap-sm">
     <?php foreach ($availableLicenseTypes as $license): ?>
     <label class="card p-md license-option" style="cursor: pointer; border: 2px solid var(--border);">
      <div class="flex items-center gap-md">
      <input type="radio" name="selected_license" value="<?= h($license['code']) ?>" onchange="onLicenseSelected()">
      <div>
       <strong><?= h($license['name']) ?></strong>
       <p class="text-sm text-secondary gs-mb-0"><?= h($license['description']) ?></p>
      </div>
      </div>
     </label>
     <?php endforeach; ?>
     </div>
    </div>
    </div>

    <!-- Step 2: Select Class -->
    <div class="card mb-md">
    <div class="card-header">
     <h3 class="">
     <i data-lucide="users"></i>
     2. Välj klass
     </h3>
    </div>
    <div class="card-body">
     <?php if (empty($pricingRules)): ?>
     <p class="text-secondary">Inga klasser har konfigurerats för detta event ännu.</p>
     <?php else: ?>
     <!-- Price Tier Indicator -->
     <?php if (!empty($priceTierInfo)): ?>
      <div class="alert mb-md <?php
      if ($priceTierInfo['tier'] === 'early_bird') echo 'alert--success';
      elseif ($priceTierInfo['tier'] === 'late_fee') echo 'alert--warning';
      else echo 'alert--info';
      ?>">
      <div class="flex justify-between items-center">
       <div>
       <strong>
        <?php if ($priceTierInfo['tier'] === 'early_bird'): ?>
        EARLY BIRD PRIS
        <?php elseif ($priceTierInfo['tier'] === 'late_fee'): ?>
        EFTERANMÄLAN
        <?php else: ?>
        ORDINARIE PRIS
        <?php endif; ?>
       </strong>
       <?php if ($priceTierInfo['tier'] === 'early_bird' && !empty($priceTierInfo['end_date'])): ?>
        <br><span class="text-sm">Gäller t.o.m. <?= date('j M', strtotime($priceTierInfo['end_date'])) ?> (-<?= $priceTierInfo['discount_percent'] ?>%)</span>
       <?php elseif ($priceTierInfo['tier'] === 'late_fee' && !empty($priceTierInfo['fee_percent'])): ?>
        <br><span class="text-sm">+<?= $priceTierInfo['fee_percent'] ?>% tillägg</span>
       <?php endif; ?>
       </div>
      </div>
      </div>
     <?php endif; ?>

     <div class="grid grid-cols-1 gap-sm">
      <?php foreach ($pricingRules as $rule): ?>
      <label class="card p-md" style="cursor: pointer; border: 2px solid var(--border);">
       <div class="flex justify-between items-center">
       <div class="flex items-center gap-md">
        <input type="radio" name="class_id" value="<?= $rule['class_id'] ?>"
         data-active-price="<?= $rule['active_price'] ?>"
         data-base-price="<?= $rule['base_price'] ?>"
         data-price-tier="<?= $activePriceTier ?>"
         onchange="updatePrice()">
        <div>
        <strong><?= h($rule['class_display_name'] ?: $rule['class_name']) ?></strong>
        </div>
       </div>
       <div class="text-right">
        <?php if ($activePriceTier === 'early_bird'): ?>
        <span class="text-success font-bold"><?= number_format($rule['active_price'], 0) ?> kr</span>
        <br><span class="text-xs text-secondary"><s><?= number_format($rule['base_price'], 0) ?> kr</s></span>
        <?php elseif ($activePriceTier === 'late_fee'): ?>
        <span class="text-warning font-bold"><?= number_format($rule['active_price'], 0) ?> kr</span>
        <br><span class="text-xs text-secondary">(ord. <?= number_format($rule['base_price'], 0) ?> kr)</span>
        <?php else: ?>
        <span class="font-bold"><?= number_format($rule['base_price'], 0) ?> kr</span>
        <?php endif; ?>
       </div>
       </div>
      </label>
      <?php endforeach; ?>
     </div>
     <?php endif; ?>
    </div>
    </div>

    <!-- Step 3: Price Summary & Payment -->
    <div class="card">
    <div class="card-header">
     <h3 class="">
     <i data-lucide="credit-card"></i>
     3. Sammanfattning & Betalning
     </h3>
    </div>
    <div class="card-body">
     <div id="price-summary" style="display: none;">
     <div class="flex justify-between mb-sm">
      <span>Startavgift:</span>
      <span id="base-price-display">0 kr</span>
     </div>
     <div id="gravity-discount-row" class="flex justify-between mb-sm text-success" style="display: none;">
      <span>Gravity-ID rabatt (chip ingår):</span>
      <span>-50 kr</span>
     </div>
     <hr class="gs-my-md">
     <div class="flex justify-between font-bold text-lg">
      <span>Totalt:</span>
      <span id="total-price-display">0 kr</span>
     </div>
     </div>

     <div id="payment-button" class="mt-lg" style="display: none;">
     <button type="button" onclick="proceedToPayment()" class="btn btn--primary btn-lg w-full">
      <i data-lucide="shopping-cart"></i>
      Fortsätt till betalning
     </button>
     </div>

     <div id="form-incomplete" class="alert alert--info">
     Välj deltagare och klass för att fortsätta.
     </div>
    </div>
    </div>
   </div>

   <script>
   let searchTimeout;
   const eventId = <?= $eventId ?>;
   const wooProductId = '<?= h($event['woo_product_id']) ?>';
   const gravityIdDiscount = 50;

   // Event license class: 'national', 'sportmotion', or 'motion'
   // - national: Only tävlingslicens allowed
   // - sportmotion: Engångslicens, motionslicens, and tävlingslicens allowed
   // - motion: All license types allowed
   const eventLicenseClass = '<?= h($event['license_class'] ?? 'national') ?>';

   // Class rules for license validation (from series)
   const classRules = <?= json_encode($classRulesMap) ?>;

   // Class genders from classes table: { classId: 'M'|'K'|'ALL' }
   const classGenders = <?= json_encode(array_column(array_map(function($r) {
    return ['id' => (string)$r['class_id'], 'gender' => $r['class_gender'] ?? 'ALL'];
   }, $pricingRules), 'gender', 'id')) ?>;

   // Class age limits from classes table: { classId: { min: number|null, max: number|null } }
   const classAgeLimits = <?= json_encode(array_column(array_map(function($r) {
    return [
    'id' => (string)$r['class_id'],
    'limits' => [
     'min' => $r['class_min_age'] ?? null,
     'max' => $r['class_max_age'] ?? null
    ]
    ];
   }, $pricingRules), 'limits', 'id')) ?>;

   // License matrix: which license types can register for which classes
   // Format: { classId: ['license1', 'license2', ...] }
   const licenseMatrix = <?= json_encode($licenseMatrixMap) ?>;

   // License name to code mapping (for matching rider's stored license_type to matrix codes)
   const licenseNameToCode = <?= json_encode(array_column(array_map(function($lt) {
    return ['name' => strtolower($lt['name']), 'code' => $lt['code']];
   }, $availableLicenseTypes), 'code', 'name')) ?>;

   // Debug: log matrix to console
   console.log('License Matrix:', licenseMatrix);
   console.log('Class Genders:', classGenders);
   console.log('Class Age Limits:', classAgeLimits);
   console.log('Event License Class:', eventLicenseClass);
   console.log('License Name to Code:', licenseNameToCode);

   // Helper function to convert license name/type to code
   function getLicenseCode(licenseType) {
    if (!licenseType) return null;
    const normalized = licenseType.toLowerCase().trim();

    // Direct match in mapping
    if (licenseNameToCode[normalized]) {
    return licenseNameToCode[normalized];
    }

    // Try partial matches
    for (const [name, code] of Object.entries(licenseNameToCode)) {
    if (normalized.includes(name) || name.includes(normalized)) {
     return code;
    }
    }

    // Fallback: convert to code format
    return normalized
    .replace(/\s+/g, '_')
    .replace(/ä/g, 'a')
    .replace(/å/g, 'a')
    .replace(/ö/g, 'o')
    .replace(/[^a-z0-9_]/g, '');
   }

   // Current rider data for validation
   let currentRiderData = null;

   // Rider search
   document.getElementById('rider-search').addEventListener('input', function() {
    clearTimeout(searchTimeout);
    const query = this.value.trim();

    if (query.length < 2) {
    document.getElementById('rider-results').style.display = 'none';
    return;
    }

    searchTimeout = setTimeout(() => {
    fetch('/api/search-riders.php?q=' + encodeURIComponent(query))
     .then(r => r.json())
     .then(data => {
     const container = document.getElementById('rider-results');
     const riders = data.riders || [];
     if (riders.length === 0) {
      container.innerHTML = '<p class="text-secondary">Ingen deltagare hittades. <a href="#" onclick="showNewRiderForm(); return false;">Registrera ny deltagare</a></p>';
     } else {
      container.innerHTML = riders.map(r => `
      <div class="card p-sm mb-sm" style="cursor: pointer;"
       onclick='selectRider(${JSON.stringify({
        id: r.id,
        name: r.firstname +"" + r.lastname,
        club: r.club_name ||"",
        uciId: r.uci_id ||"",
        hasGravityId: r.gravity_id ? 1 : 0,
        licenseType: r.license_type ||"",
        hasActiveLicense: r.has_active_license ? 1 : 0,
        birthYear: r.birth_year || null,
        gender: r.gender ||""
       })})'>
       <strong>${r.firstname} ${r.lastname}</strong>
       ${r.license_type ? '<span class="badge badge-secondary badge-sm ml-sm">' + r.license_type + '</span>' : ''}
       ${r.has_active_license ? '<span class="badge badge-success badge-sm ml-sm">Aktiv licens</span>' : ''}
       ${r.club_name ? '<br><span class="text-sm text-secondary">' + r.club_name + '</span>' : ''}
       ${r.gravity_id ? '<span class="badge badge-primary badge-sm ml-sm">GID</span>' : ''}
      </div>
      `).join('');
     }
     container.style.display = 'block';
     });
    }, 300);
   });

   function selectRider(riderData) {
    currentRiderData = riderData;

    document.getElementById('selected-rider-id').value = riderData.id;
    document.getElementById('has-gravity-id').value = riderData.hasGravityId;
    document.getElementById('rider-name').textContent = riderData.name;

    let details = riderData.club;
    if (riderData.uciId) details += ' | UCI: ' + riderData.uciId;
    if (riderData.licenseType) details += ' | Licens: ' + riderData.licenseType;

    document.getElementById('rider-details').textContent = details;
    document.getElementById('gravity-id-badge').style.display = riderData.hasGravityId ? 'inline' : 'none';
    document.getElementById('selected-rider').style.display = 'block';
    document.getElementById('rider-results').style.display = 'none';
    document.getElementById('rider-search').value = riderData.name;

    // Hide both alerts initially
    document.getElementById('license-from-profile').style.display = 'none';
    document.getElementById('license-not-found').style.display = 'none';
    document.getElementById('active-license-locked').style.display = 'none';

    // Reset all license selections
    document.querySelectorAll('input[name="selected_license"]').forEach(r => {
    r.checked = false;
    r.disabled = false;
    const card = r.closest('label');
    if (card) {
     card.style.borderColor = 'var(--border)';
     card.style.backgroundColor = '';
     card.style.display = '';
    }
    });

    // Check if rider has active license
    if (riderData.hasActiveLicense) {
    // Rider has active license - hide selector and use their license directly
    document.getElementById('license-type-section').style.display = 'none';

    // Store the license for class filtering
    currentRiderData.effectiveLicense = riderData.licenseType;

    // Show locked license message in selected rider card
    document.getElementById('active-license-locked').style.display = 'flex';
    document.getElementById('active-license-locked').querySelector('span').textContent =
     'Aktiv licens: ' + riderData.licenseType + ' (låst)';
    } else {
    // No active license - show selector with limited options
    document.getElementById('license-type-section').style.display = 'block';

    // Only show: tävlingslicens (competition types), motionslicens, engångslicens
    const allowedForSelection = ['elite_men', 'elite_women', 'u23', 'junior', 'youth', 'under11', 'master', 'motionslicens', 'engangslicens', 'baslicens'];

    document.querySelectorAll('input[name="selected_license"]').forEach(radio => {
     const card = radio.closest('label');
     if (!allowedForSelection.includes(radio.value)) {
     // Hide license types that aren't available for selection
     if (card) card.style.display = 'none';
     }
    });

    // Pre-select if they have a license type stored
    if (riderData.licenseType) {
     const licenseCode = riderData.licenseType.toLowerCase()
     .replace(/\s+/g, '_')
     .replace(/ä/g, 'a')
     .replace(/å/g, 'a')
     .replace(/ö/g, 'o');

     let matchingRadio = document.querySelector(`input[name="selected_license"][value="${licenseCode}"]`);
     if (!matchingRadio) {
     const licenseRadios = document.querySelectorAll('input[name="selected_license"]');
     licenseRadios.forEach(radio => {
      const value = radio.value.toLowerCase();
      if (licenseCode.includes(value) || value.includes(licenseCode)) {
      matchingRadio = radio;
      }
     });
     }

     if (matchingRadio && matchingRadio.closest('label').style.display !== 'none') {
     matchingRadio.checked = true;
     const card = matchingRadio.closest('label');
     if (card) {
      card.style.borderColor = 'var(--primary)';
      card.style.backgroundColor = 'var(--primary-light, rgba(var(--primary-rgb), 0.1))';
     }
     document.getElementById('license-from-profile').style.display = 'flex';
     } else {
     document.getElementById('license-not-found').style.display = 'flex';
     }
    } else {
     document.getElementById('license-not-found').style.display = 'flex';
    }
    }

    // Filter classes based on rider's license
    filterClasses();
    updatePrice();
   }

   // Called when user selects a license type
   function onLicenseSelected() {
    filterClasses();

    // Hide alerts when user manually changes license
    document.getElementById('license-from-profile').style.display = 'none';
    document.getElementById('license-not-found').style.display = 'none';

    // Highlight selected license card
    document.querySelectorAll('input[name="selected_license"]').forEach(radio => {
    const card = radio.closest('label');
    if (radio.checked) {
     card.style.borderColor = 'var(--primary)';
     card.style.backgroundColor = 'var(--primary-light, rgba(var(--primary-rgb), 0.1))';
    } else {
     card.style.borderColor = 'var(--border)';
     card.style.backgroundColor = '';
    }
    });
   }

   function filterClasses() {
    if (!currentRiderData) return;

    const classOptions = document.querySelectorAll('input[name="class_id"]');

    // Get effective license:
    // 1. If rider has active license, use effectiveLicense (stored license type)
    // 2. Otherwise use selected license from radio buttons
    let effectiveLicense = null;
    if (currentRiderData.hasActiveLicense && currentRiderData.effectiveLicense) {
    // Convert stored license name to code using lookup function
    effectiveLicense = getLicenseCode(currentRiderData.effectiveLicense);
    console.log('Active license:', currentRiderData.effectiveLicense, '-> code:', effectiveLicense);
    } else {
    const selectedLicenseRadio = document.querySelector('input[name="selected_license"]:checked');
    effectiveLicense = selectedLicenseRadio?.value || null;
    }

    classOptions.forEach(radio => {
    const classId = radio.value;
    const rules = classRules[classId];
    const container = radio.closest('label');
    let allowed = true;
    let reason = '';

    // GENDER IS ALWAYS ENFORCED - Check class gender from classes table
    const classGender = classGenders[classId];
    if (classGender && classGender !== 'ALL' && currentRiderData.gender) {
     // Normalize rider gender: 'F' or 'K' both mean female
     const riderGender = (currentRiderData.gender === 'F' || currentRiderData.gender === 'K') ? 'K' : currentRiderData.gender;

     if (classGender !== riderGender) {
     allowed = false;
     reason = classGender === 'K' ? 'Endast för kvinnor' : 'Endast för män';
     }
    }

    // Check if license is selected/available
    if (allowed && !effectiveLicense) {
     allowed = false;
     reason = 'Välj licenstyp först';
    }

    // LICENSE MATRIX CHECK
    // The matrix is already filtered by event license class (loaded from PHP)
    // Check if the effective license is allowed for this class
    if (allowed && effectiveLicense) {
     const allowedLicenses = licenseMatrix[classId];
     if (allowedLicenses && allowedLicenses.length > 0) {
     // Matrix has rules for this class - check if effective license is allowed
     if (!allowedLicenses.includes(effectiveLicense)) {
      allowed = false;
      reason = 'Din licenstyp tillåter inte denna klass';
     }
     }
     // If no matrix rules for this class, allow all licenses (backward compatibility)
    }

    // CLASS AGE LIMITS CHECK (from classes table)
    // Check rider's age against class min_age and max_age
    if (allowed && currentRiderData.birthYear) {
     const ageLimits = classAgeLimits[classId];
     if (ageLimits) {
     const currentYear = new Date().getFullYear();
     const riderAge = currentYear - currentRiderData.birthYear;

     if (ageLimits.min !== null && riderAge < ageLimits.min) {
      allowed = false;
      reason = 'Minsta ålder: ' + ageLimits.min + ' år (du är ' + riderAge + ')';
     }
     if (ageLimits.max !== null && riderAge > ageLimits.max) {
      allowed = false;
      reason = 'Maxålder: ' + ageLimits.max + ' år (du är ' + riderAge + ')';
     }
     }
    }

    // Additional series rules (age restrictions etc)
    if (rules && allowed) {
     // Check birth year (age restrictions)
     if (currentRiderData.birthYear) {
     if (rules.min_birth_year && currentRiderData.birthYear < rules.min_birth_year) {
      allowed = false;
      reason = 'Födelseår måste vara ' + rules.min_birth_year + ' eller senare';
     }
     if (rules.max_birth_year && currentRiderData.birthYear > rules.max_birth_year) {
      allowed = false;
      reason = 'Födelseår måste vara ' + rules.max_birth_year + ' eller tidigare';
     }
     }
    }

    // Update UI
    radio.disabled = !allowed;

    // Remove existing reason message
    const existingReason = container.querySelector('.class-restriction-reason');
    if (existingReason) existingReason.remove();

    if (!allowed) {
     container.style.opacity = '0.5';
     container.style.cursor = 'not-allowed';
     if (radio.checked) {
     radio.checked = false;
     updatePrice();
     }

     // Add reason
     const reasonEl = document.createElement('div');
     reasonEl.className = 'class-restriction-reason text-xs text-error gs-mt-xs';
     reasonEl.textContent = reason;
     container.querySelector('div').appendChild(reasonEl);
    } else {
     container.style.opacity = '1';
     container.style.cursor = 'pointer';
    }
    });
   }

   function showNewRiderForm() {
    document.getElementById('rider-results').style.display = 'none';
    document.getElementById('selected-rider').style.display = 'none';
    document.getElementById('new-rider-form').style.display = 'block';
    document.getElementById('new-rider-error').style.display = 'none';
   }

   function hideNewRiderForm() {
    document.getElementById('new-rider-form').style.display = 'none';
    document.getElementById('rider-search').value = '';
   }

   async function createNewRider() {
    const firstname = document.getElementById('new-rider-firstname').value.trim();
    const lastname = document.getElementById('new-rider-lastname').value.trim();
    const birthYear = document.getElementById('new-rider-birthyear').value;
    const gender = document.getElementById('new-rider-gender').value;
    const email = document.getElementById('new-rider-email').value.trim();
    const phone = document.getElementById('new-rider-phone').value.trim();

    // Validate
    if (!firstname || !lastname || !birthYear || !gender) {
    document.getElementById('new-rider-error').textContent = 'Fyll i alla obligatoriska fält';
    document.getElementById('new-rider-error').style.display = 'block';
    return;
    }

    try {
    const response = await fetch('/api/create-rider.php', {
     method: 'POST',
     headers: { 'Content-Type': 'application/json' },
     body: JSON.stringify({
     firstname: firstname,
     lastname: lastname,
     birth_year: parseInt(birthYear),
     gender: gender,
     email: email,
     phone: phone
     })
    });

    const data = await response.json();

    if (data.error) {
     document.getElementById('new-rider-error').textContent = data.error;
     document.getElementById('new-rider-error').style.display = 'block';
     return;
    }

    if (data.success) {
     // Hide form and select the rider
     document.getElementById('new-rider-form').style.display = 'none';

     // Select the newly created rider
     selectRider({
     id: data.rider.id,
     name: data.rider.name,
     club: '',
     uciId: data.rider.sweId || '',
     hasGravityId: 0,
     licenseType: data.rider.licenseType || 'Engångslicens',
     birthYear: data.rider.birthYear,
     gender: data.rider.gender
     });

     if (data.existing) {
     alert(data.rider.message);
     }
    }
    } catch (err) {
    document.getElementById('new-rider-error').textContent = 'Något gick fel. Försök igen.';
    document.getElementById('new-rider-error').style.display = 'block';
    }
   }

   function updatePrice() {
    const riderId = document.getElementById('selected-rider-id').value;
    const classRadio = document.querySelector('input[name="class_id"]:checked');

    if (!riderId || !classRadio) {
    document.getElementById('price-summary').style.display = 'none';
    document.getElementById('payment-button').style.display = 'none';
    document.getElementById('form-incomplete').style.display = 'block';
    return;
    }

    const hasGravityId = document.getElementById('has-gravity-id').value === '1';
    const activePrice = parseFloat(classRadio.dataset.activePrice || classRadio.dataset.basePrice);

    let total = activePrice;

    document.getElementById('base-price-display').textContent = activePrice + ' kr';

    if (hasGravityId) {
    document.getElementById('gravity-discount-row').style.display = 'flex';
    total -= gravityIdDiscount;
    } else {
    document.getElementById('gravity-discount-row').style.display = 'none';
    }

    document.getElementById('total-price-display').textContent = Math.max(0, total) + ' kr';
    document.getElementById('price-summary').style.display = 'block';
    document.getElementById('payment-button').style.display = 'block';
    document.getElementById('form-incomplete').style.display = 'none';
   }

   function proceedToPayment() {
    const riderId = document.getElementById('selected-rider-id').value;
    const classRadio = document.querySelector('input[name="class_id"]:checked');

    if (!riderId || !classRadio) {
    alert('Välj deltagare och klass först');
    return;
    }

    // Redirect to WooCommerce with product
    const url = 'https://gravityseries.se/?add-to-cart=' + wooProductId;
    window.open(url, '_blank');
   }
   </script>
   <?php endif; ?>
  <?php elseif (!empty($event['registration_url'])): ?>
   <div class="alert alert-primary mb-lg">
   <h3 class="mb-sm">
    <i data-lucide="external-link" class="icon-sm"></i>
    Extern anmälan
   </h3>
   <p class="mb-sm">
    Anmälan till detta event görs via en extern webbplats.
   </p>
   <a href="<?= h($event['registration_url']) ?>"
    target="_blank"
    rel="noopener noreferrer"
    class="btn btn--primary">
    <i data-lucide="external-link" class="icon-sm"></i>
    Gå till anmälan
   </a>
   </div>
  <?php else: ?>
   <div class="alert alert--info">
   <h3 class="mb-sm">Anmälningsformulär</h3>
   <p>Anmälningsfunktionen är under utveckling.</p>
   </div>
  <?php endif; ?>
  </div>
 </div>

 <?php endif; ?>
 </div>
</main>

<script>
function toggleAllSplitTimes(show) {
 // Toggle split times visibility for ALL result tables
 const tables = document.querySelectorAll('.gs-results-table');
 tables.forEach(table => {
 if (show) {
  table.classList.add('gs-split-times-visible');
 } else {
  table.classList.remove('gs-split-times-visible');
 }
 });
}

function toggleSplitColors(show) {
 // Toggle color coding by adding/removing gs-no-split-colors class on tables
 const tables = document.querySelectorAll('.gs-results-table');
 tables.forEach(table => {
 if (show) {
  table.classList.remove('gs-no-split-colors');
 } else {
  table.classList.add('gs-no-split-colors');
 }
 });
}

// Table sorting functionality
function sortTable(header, columnIndex) {
 const table = header.closest('table');
 const tbody = table.querySelector('tbody');
 const rows = Array.from(tbody.querySelectorAll('tr'));

 // Determine sort direction
 const isAsc = header.classList.contains('gs-sort-asc');

 // Remove sort classes from all headers in this table
 table.querySelectorAll('th').forEach(th => {
 th.classList.remove('gs-sort-asc', 'gs-sort-desc');
 });

 // Set new sort direction
 header.classList.add(isAsc ? 'gs-sort-desc' : 'gs-sort-asc');

 // Sort rows
 rows.sort((a, b) => {
 const aCell = a.cells[columnIndex];
 const bCell = b.cells[columnIndex];
 const aText = aCell.textContent.trim();
 const bText = bCell.textContent.trim();

 // Convert time format to seconds for comparison
 const aVal = timeToSeconds(aText);
 const bVal = timeToSeconds(bText);

 if (aVal === 0 && bVal === 0) {
  return aText.localeCompare(bText);
 }
 if (aVal === 0) return 1;
 if (bVal === 0) return -1;

 return isAsc ? bVal - aVal : aVal - bVal;
 });

 // Reorder rows
 rows.forEach(row => tbody.appendChild(row));
}

function timeToSeconds(timeStr) {
 if (!timeStr || timeStr === '-') return 0;

 // Handle decimal part
 let decimal = 0;
 const decMatch = timeStr.match(/\.(\d+)$/);
 if (decMatch) {
 decimal = parseFloat('0.' + decMatch[1]);
 timeStr = timeStr.replace(/\.\d+$/, '');
 }

 const parts = timeStr.split(':').map(Number);
 if (parts.length === 3) {
 return parts[0] * 3600 + parts[1] * 60 + parts[2] + decimal;
 } else if (parts.length === 2) {
 return parts[0] * 60 + parts[1] + decimal;
 }
 return decimal;
}
</script>

<?php include __DIR__ . '/includes/layout-footer.php'; ?>

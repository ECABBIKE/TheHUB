<?php
require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();

// Get event ID
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($id <= 0) {
 $_SESSION['message'] = 'Ogiltigt event-ID';
 $_SESSION['messageType'] = 'error';
 header('Location: /admin/events.php');
 exit;
}

// Fetch event data
$event = $db->getRow("SELECT * FROM events WHERE id = ?", [$id]);

if (!$event) {
 $_SESSION['message'] = 'Event hittades inte';
 $_SESSION['messageType'] = 'error';
 header('Location: /admin/events.php');
 exit;
}

// Initialize message variables
$message = '';
$messageType = 'info';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
 checkCsrf();

 // Validate required fields
 $name = trim($_POST['name'] ?? '');
 $date = trim($_POST['date'] ?? '');

 if (empty($name) || empty($date)) {
 $message = 'Namn och datum är obligatoriska';
 $messageType = 'error';
 } else {
 // Prepare event data
 $eventData = [
  'name' => $name,
  'advent_id' => trim($_POST['advent_id'] ?? '') ?: null,
  'date' => $date,
  'location' => trim($_POST['location'] ?? ''),
  'venue_id' => !empty($_POST['venue_id']) ? intval($_POST['venue_id']) : null,
  'discipline' => trim($_POST['discipline'] ?? ''),
  'event_level' => in_array($_POST['event_level'] ?? '', ['national', 'sportmotion']) ? $_POST['event_level'] : 'national',
  'event_format' => trim($_POST['event_format'] ?? 'ENDURO'),
  'stage_names' => !empty($_POST['stage_names']) ? trim($_POST['stage_names']) : null,
  'series_id' => !empty($_POST['series_id']) ? intval($_POST['series_id']) : null,
  'pricing_template_id' => !empty($_POST['pricing_template_id']) ? intval($_POST['pricing_template_id']) : null,
  'distance' => !empty($_POST['distance']) ? floatval($_POST['distance']) : null,
  'elevation_gain' => !empty($_POST['elevation_gain']) ? intval($_POST['elevation_gain']) : null,
  'organizer' => trim($_POST['organizer'] ?? ''),
  'website' => trim($_POST['website'] ?? ''),
  'registration_deadline' => !empty($_POST['registration_deadline']) ? trim($_POST['registration_deadline']) : null,
  'active' => isset($_POST['active']) ? 1 : 0,
  'contact_email' => trim($_POST['contact_email'] ?? ''),
  'contact_phone' => trim($_POST['contact_phone'] ?? ''),
  // New extended fields with global text support
  'venue_details' => trim($_POST['venue_details'] ?? ''),
  'venue_coordinates' => trim($_POST['venue_coordinates'] ?? ''),
  'venue_map_url' => trim($_POST['venue_map_url'] ?? ''),
  'pm_content' => trim($_POST['pm_content'] ?? ''),
  'pm_use_global' => isset($_POST['pm_use_global']) ? 1 : 0,
  'jury_communication' => trim($_POST['jury_communication'] ?? ''),
  'jury_use_global' => isset($_POST['jury_use_global']) ? 1 : 0,
  'competition_schedule' => trim($_POST['competition_schedule'] ?? ''),
  'schedule_use_global' => isset($_POST['schedule_use_global']) ? 1 : 0,
  'start_times' => trim($_POST['start_times'] ?? ''),
  'start_times_use_global' => isset($_POST['start_times_use_global']) ? 1 : 0,
  'map_content' => trim($_POST['map_content'] ?? ''),
  'map_image_url' => trim($_POST['map_image_url'] ?? ''),
  'map_use_global' => isset($_POST['map_use_global']) ? 1 : 0,
  'driver_meeting' => trim($_POST['driver_meeting'] ?? ''),
  'driver_meeting_use_global' => isset($_POST['driver_meeting_use_global']) ? 1 : 0,
  'competition_tracks' => trim($_POST['competition_tracks'] ?? ''),
  'tracks_use_global' => isset($_POST['tracks_use_global']) ? 1 : 0,
  'competition_rules' => trim($_POST['competition_rules'] ?? ''),
  'rules_use_global' => isset($_POST['rules_use_global']) ? 1 : 0,
  'insurance_info' => trim($_POST['insurance_info'] ?? ''),
  'insurance_use_global' => isset($_POST['insurance_use_global']) ? 1 : 0,
  'equipment_info' => trim($_POST['equipment_info'] ?? ''),
  'equipment_use_global' => isset($_POST['equipment_use_global']) ? 1 : 0,
  'training_info' => trim($_POST['training_info'] ?? ''),
  'training_use_global' => isset($_POST['training_use_global']) ? 1 : 0,
  'timing_info' => trim($_POST['timing_info'] ?? ''),
  'timing_use_global' => isset($_POST['timing_use_global']) ? 1 : 0,
  'lift_info' => trim($_POST['lift_info'] ?? ''),
  'lift_use_global' => isset($_POST['lift_use_global']) ? 1 : 0,
  'entry_fees_detailed' => trim($_POST['entry_fees_detailed'] ?? ''),
  'fees_use_global' => isset($_POST['fees_use_global']) ? 1 : 0,
  'results_info' => trim($_POST['results_info'] ?? ''),
  'results_use_global' => isset($_POST['results_use_global']) ? 1 : 0,
  'hydration_stations' => trim($_POST['hydration_stations'] ?? ''),
  'hydration_use_global' => isset($_POST['hydration_use_global']) ? 1 : 0,
  'toilets_showers' => trim($_POST['toilets_showers'] ?? ''),
  'toilets_use_global' => isset($_POST['toilets_use_global']) ? 1 : 0,
  'shops_info' => trim($_POST['shops_info'] ?? ''),
  'shops_use_global' => isset($_POST['shops_use_global']) ? 1 : 0,
  'bike_wash' => trim($_POST['bike_wash'] ?? ''),
  'bike_wash_use_global' => isset($_POST['bike_wash_use_global']) ? 1 : 0,
  'food_cafe' => trim($_POST['food_cafe'] ?? ''),
  'food_use_global' => isset($_POST['food_use_global']) ? 1 : 0,
  'exhibitors' => trim($_POST['exhibitors'] ?? ''),
  'exhibitors_use_global' => isset($_POST['exhibitors_use_global']) ? 1 : 0,
  'parking_detailed' => trim($_POST['parking_detailed'] ?? ''),
  'parking_use_global' => isset($_POST['parking_use_global']) ? 1 : 0,
  'hotel_accommodation' => trim($_POST['hotel_accommodation'] ?? ''),
  'hotel_use_global' => isset($_POST['hotel_use_global']) ? 1 : 0,
  'local_info' => trim($_POST['local_info'] ?? ''),
  'local_use_global' => isset($_POST['local_use_global']) ? 1 : 0,
  'media_production' => trim($_POST['media_production'] ?? ''),
  'media_use_global' => isset($_POST['media_use_global']) ? 1 : 0,
  'medical_info' => trim($_POST['medical_info'] ?? ''),
  'medical_use_global' => isset($_POST['medical_use_global']) ? 1 : 0,
  'contacts_info' => trim($_POST['contacts_info'] ?? ''),
  'contacts_use_global' => isset($_POST['contacts_use_global']) ? 1 : 0,
  'scf_representatives' => trim($_POST['scf_representatives'] ?? ''),
  'scf_use_global' => isset($_POST['scf_use_global']) ? 1 : 0,
 ];

 try {
  $db->update('events', $eventData, 'id = ?', [$id]);
  $_SESSION['message'] = 'Event uppdaterat!';
  $_SESSION['messageType'] = 'success';
  header('Location: /admin/events.php');
  exit;
 } catch (Exception $e) {
  $message = 'Ett fel uppstod: ' . $e->getMessage();
  $messageType = 'error';
 }
 }
}

// Fetch series and venues for dropdowns
$series = $db->getAll("SELECT id, name FROM series WHERE status = 'active' ORDER BY name");
$venues = $db->getAll("SELECT id, name, city FROM venues WHERE active = 1 ORDER BY name");
$pricingTemplates = $db->getAll("SELECT id, name, is_default FROM pricing_templates ORDER BY is_default DESC, name ASC");

// Fetch global texts for"use global" functionality
$globalTexts = $db->getAll("SELECT field_key, content FROM global_texts WHERE is_active = 1");
$globalTextMap = [];
foreach ($globalTexts as $gt) {
 $globalTextMap[$gt['field_key']] = $gt['content'];
}

$pageTitle = 'Redigera Event';
$pageType = 'admin';
include __DIR__ . '/../includes/layout-header.php';
?>

<main class="main-content">
 <div class="container" class="gs-max-w-800">
 <!-- Header -->
 <div class="flex items-center justify-between mb-lg">
  <h1 class="">
  <i data-lucide="edit"></i>
  Redigera Event
  </h1>
  <a href="/admin/events.php" class="btn btn--secondary">
  <i data-lucide="x"></i>
  Avbryt
  </a>
 </div>

 <!-- Messages -->
 <?php if ($message): ?>
  <div class="alert alert-<?= $messageType ?> mb-lg">
  <i data-lucide="<?= $messageType === 'success' ? 'check-circle' : ($messageType === 'error' ? 'alert-circle' : 'info') ?>"></i>
  <?= htmlspecialchars($message) ?>
  </div>
 <?php endif; ?>

 <!-- Form -->
 <div class="card">
  <form method="POST" class="card-body">
  <?= csrf_field() ?>

  <div class="grid grid-cols-1 gap-md">
   <!-- Name (Required) -->
   <div>
   <label for="name" class="label">
    <i data-lucide="calendar"></i>
    Namn <span class="text-error">*</span>
   </label>
   <input
    type="text"
    id="name"
    name="name"
    class="input"
    required
    value="<?= htmlspecialchars($event['name']) ?>"
    placeholder="T.ex. GravitySeries Järvsö"
   >
   </div>

   <!-- Advent ID -->
   <div>
   <label for="advent_id" class="label">
    <i data-lucide="hash"></i>
    Advent ID
   </label>
   <input
    type="text"
    id="advent_id"
    name="advent_id"
    class="input"
    value="<?= htmlspecialchars($event['advent_id'] ?? '') ?>"
    placeholder="T.ex. event-2024-001"
   >
   <small class="text-muted">Externt ID för import av resultat</small>
   </div>

   <!-- Date (Required) -->
   <div>
   <label for="date" class="label">
    <i data-lucide="calendar-days"></i>
    Datum <span class="text-error">*</span>
   </label>
   <input
    type="date"
    id="date"
    name="date"
    class="input"
    required
    value="<?= htmlspecialchars($event['date']) ?>"
   >
   </div>

   <!-- Location and Venue -->
   <div class="grid grid-cols-2 gap-md">
   <div>
    <label for="location" class="label">
    <i data-lucide="map-pin"></i>
    Plats
    </label>
    <input
    type="text"
    id="location"
    name="location"
    class="input"
    value="<?= htmlspecialchars($event['location'] ?? '') ?>"
    placeholder="T.ex. Järvsö"
    >
   </div>
   <div>
    <label for="venue_id" class="label">
    <i data-lucide="mountain"></i>
    Bana/Anläggning
    </label>
    <select id="venue_id" name="venue_id" class="input">
    <option value="">Ingen specifik bana</option>
    <?php foreach ($venues as $venue): ?>
     <option value="<?= $venue['id'] ?>" <?= ($event['venue_id'] == $venue['id']) ? 'selected' : '' ?>>
     <?= htmlspecialchars($venue['name']) ?>
     <?php if ($venue['city']): ?>
      (<?= htmlspecialchars($venue['city']) ?>)
     <?php endif; ?>
     </option>
    <?php endforeach; ?>
    </select>
   </div>
   </div>

   <!-- Discipline -->
   <div>
   <label for="discipline" class="label">
    <i data-lucide="bike"></i>
    Tävlingsformat
   </label>
   <select id="discipline" name="discipline" class="input">
    <option value="">Välj format...</option>
    <option value="ENDURO" <?= ($event['discipline'] ?? '') === 'ENDURO' ? 'selected' : '' ?>>Enduro</option>
    <option value="DH" <?= ($event['discipline'] ?? '') === 'DH' ? 'selected' : '' ?>>Downhill</option>
    <option value="XC" <?= ($event['discipline'] ?? '') === 'XC' ? 'selected' : '' ?>>XC</option>
    <option value="XCO" <?= ($event['discipline'] ?? '') === 'XCO' ? 'selected' : '' ?>>XCO</option>
    <option value="XCM" <?= ($event['discipline'] ?? '') === 'XCM' ? 'selected' : '' ?>>XCM</option>
    <option value="DUAL_SLALOM" <?= ($event['discipline'] ?? '') === 'DUAL_SLALOM' ? 'selected' : '' ?>>Dual Slalom</option>
    <option value="PUMPTRACK" <?= ($event['discipline'] ?? '') === 'PUMPTRACK' ? 'selected' : '' ?>>Pumptrack</option>
    <option value="GRAVEL" <?= ($event['discipline'] ?? '') === 'GRAVEL' ? 'selected' : '' ?>>Gravel</option>
    <option value="E-MTB" <?= ($event['discipline'] ?? '') === 'E-MTB' ? 'selected' : '' ?>>E-MTB</option>
   </select>
   </div>

   <!-- Event Level (Ranking Points) -->
   <div class="grid grid-cols-2 gap-md">
   <div>
    <label for="event_level" class="label">
    <i data-lucide="trophy"></i>
    Rankingklass
    <span class="text-secondary text-xs">(poäng)</span>
    </label>
    <select id="event_level" name="event_level" class="input">
    <option value="national" <?= ($event['event_level'] ?? 'national') === 'national' ? 'selected' : '' ?>>Nationell (100%)</option>
    <option value="sportmotion" <?= ($event['event_level'] ?? 'national') === 'sportmotion' ? 'selected' : '' ?>>Sportmotion (50%)</option>
    </select>
    <small class="text-secondary">Styr rankingpoäng</small>
   </div>
   <div>
    <label for="license_class" class="label">
    <i data-lucide="id-card"></i>
    Licensklass
    <span class="text-secondary text-xs">(anmälan)</span>
    </label>
    <select id="license_class" name="license_class" class="input">
    <option value="national" <?= ($event['license_class'] ?? 'national') === 'national' ? 'selected' : '' ?>>Nationell</option>
    <option value="sportmotion" <?= ($event['license_class'] ?? 'national') === 'sportmotion' ? 'selected' : '' ?>>Sportmotion</option>
    <option value="motion" <?= ($event['license_class'] ?? 'national') === 'motion' ? 'selected' : '' ?>>Motion</option>
    </select>
    <small class="text-secondary">Styr vilka licenser som kan anmäla sig</small>
   </div>
   </div>

   <div class="grid grid-cols-2 gap-md">
   <div>
    <label for="event_format" class="label">
    <i data-lucide="layout-list"></i>
    Event-format
    </label>
    <select id="event_format" name="event_format" class="input">
    <option value="ENDURO" <?= ($event['event_format'] ?? 'ENDURO') === 'ENDURO' ? 'selected' : '' ?>>
     Enduro (en tid, splittider)
    </option>
    <option value="DH_STANDARD" <?= ($event['event_format'] ?? '') === 'DH_STANDARD' ? 'selected' : '' ?>>
     Downhill Standard (två åk, snabbaste räknas)
    </option>
    <option value="DH_SWECUP" <?= ($event['event_format'] ?? '') === 'DH_SWECUP' ? 'selected' : '' ?>>
     SweCUP Downhill (två åk, båda ger poäng)
    </option>
    <option value="DUAL_SLALOM" <?= ($event['event_format'] ?? '') === 'DUAL_SLALOM' ? 'selected' : '' ?>>
     Dual Slalom
    </option>
    </select>
    <small class="text-muted">
    Bestämmer hur resultat visas och poängräknas
    </small>
   </div>
   <div>
    <label for="series_id" class="label">
    <i data-lucide="trophy"></i>
    Serie
    </label>
    <select id="series_id" name="series_id" class="input">
    <option value="">Ingen serie</option>
    <?php foreach ($series as $s): ?>
     <option value="<?= $s['id'] ?>" <?= ($event['series_id'] == $s['id']) ? 'selected' : '' ?>>
     <?= htmlspecialchars($s['name']) ?>
     </option>
    <?php endforeach; ?>
    </select>
   </div>
   </div>

   <!-- Pricing Template -->
   <div>
   <label for="pricing_template_id" class="label">
    <i data-lucide="credit-card"></i>
    Prismall
   </label>
   <select id="pricing_template_id" name="pricing_template_id" class="input">
    <option value="">Ingen prismall</option>
    <?php foreach ($pricingTemplates as $template): ?>
    <option value="<?= $template['id'] ?>" <?= ($event['pricing_template_id'] ?? '') == $template['id'] ? 'selected' : '' ?>>
     <?= htmlspecialchars($template['name']) ?>
     <?php if ($template['is_default']): ?>(Standard)<?php endif; ?>
    </option>
    <?php endforeach; ?>
   </select>
   <small class="text-muted">Välj prismall för detta event. <a href="/admin/pricing-templates.php">Hantera prismallar</a></small>
   </div>

   <!-- Stage Names Configuration -->
   <div>
   <label for="stage_names" class="label">
    <i data-lucide="list-ordered"></i>
    Sträcknamn (JSON)
   </label>
   <textarea
    id="stage_names"
    name="stage_names"
    class="input"
    rows="3"
    placeholder='{"1":"SS1","2":"SS2","3":"SS3","4":"SS3-1","5":"SS4"}'
   ><?= htmlspecialchars($event['stage_names'] ?? '') ?></textarea>
   <small class="text-muted">
    JSON-format för anpassade sträcknamn. Lämna tomt för standard (SS1, SS2, etc).
    Exempel för E-bike med dubbla körningar: {"1":"SS1","2":"SS2","3":"SS3","4":"SS3-1","5":"SS4","6":"SS5","7":"SS6","8":"SS6-1"}
   </small>
   </div>

   <!-- Distance and Elevation -->
   <div class="grid grid-cols-2 gap-md">
   <div>
    <label for="distance" class="label">
    <i data-lucide="route"></i>
    Distans (km)
    </label>
    <input
    type="number"
    id="distance"
    name="distance"
    class="input"
    step="0.01"
    min="0"
    value="<?= htmlspecialchars($event['distance'] ?? '') ?>"
    placeholder="T.ex. 42.5"
    >
   </div>
   <div>
    <label for="elevation_gain" class="label">
    <i data-lucide="mountain"></i>
    Höjdmeter (m)
    </label>
    <input
    type="number"
    id="elevation_gain"
    name="elevation_gain"
    class="input"
    min="0"
    value="<?= htmlspecialchars($event['elevation_gain'] ?? '') ?>"
    placeholder="T.ex. 1200"
    >
   </div>
   </div>


   <!-- Organizer and Website -->
   <div class="grid grid-cols-2 gap-md">
   <div>
    <label for="organizer" class="label">
    <i data-lucide="user"></i>
    Arrangör
    </label>
    <input
    type="text"
    id="organizer"
    name="organizer"
    class="input"
    value="<?= htmlspecialchars($event['organizer'] ?? '') ?>"
    placeholder="T.ex. GravitySeries AB"
    >
   </div>
   <div>
    <label for="website" class="label">
    <i data-lucide="globe"></i>
    Webbplats
    </label>
    <input
    type="url"
    id="website"
    name="website"
    class="input"
    value="<?= htmlspecialchars($event['website'] ?? '') ?>"
    placeholder="https://example.com"
    >
   </div>
   </div>

   <!-- Registration Deadline -->
   <div>
   <label for="registration_deadline" class="label">
    <i data-lucide="calendar-clock"></i>
    Anmälningsfrist
   </label>
   <input
    type="date"
    id="registration_deadline"
    name="registration_deadline"
    class="input"
    value="<?= htmlspecialchars($event['registration_deadline'] ?? '') ?>"
   >
   </div>


   <!-- Contact Information -->
   <div class="grid grid-cols-2 gap-md">
   <div>
    <label for="contact_email" class="label">
    <i data-lucide="mail"></i>
    Kontakt e-post
    </label>
    <input
    type="email"
    id="contact_email"
    name="contact_email"
    class="input"
    value="<?= htmlspecialchars($event['contact_email'] ?? '') ?>"
    placeholder="info@example.com"
    >
   </div>
   <div>
    <label for="contact_phone" class="label">
    <i data-lucide="phone"></i>
    Kontakt telefon
    </label>
    <input
    type="tel"
    id="contact_phone"
    name="contact_phone"
    class="input"
    value="<?= htmlspecialchars($event['contact_phone'] ?? '') ?>"
    placeholder="+46 70 123 45 67"
    >
   </div>
   </div>

   <!-- NEW EXTENDED FIELDS WITH GLOBAL TEXT SUPPORT -->
   <div class="section-divider mt-lg mb-md">
   <h3 class="text-primary">Event-flikar Information</h3>
   <p class="text-sm text-secondary">Innehåll för event-sidans flikar. Markera"Använd global text" för att använda standardtext från <a href="/admin/global-texts.php">Globala Texter</a>.</p>
   </div>

   <!-- PM (Promemoria) -->
   <div class="card mb-md">
   <div class="card-header">
    <h4 class=""><i data-lucide="file-text"></i> PM (Promemoria)</h4>
   </div>
   <div class="card-body">
    <div class="mb-sm">
    <label class="checkbox-label">
     <input type="checkbox" name="pm_use_global" class="checkbox use-global-toggle" data-target="pm_content" data-global="<?= htmlspecialchars($globalTextMap['pm_content'] ?? '') ?>" <?= !empty($event['pm_use_global']) ? 'checked' : '' ?>>
     <span>Använd global text</span>
    </label>
    <?php if (!empty($globalTextMap['pm_content'])): ?>
     <small class="text-muted ml-md">(Global text finns)</small>
    <?php else: ?>
     <small class="text-warning ml-md">(Ingen global text definierad)</small>
    <?php endif; ?>
    </div>
    <?php if (!empty($globalTextMap['pm_content'])): ?>
    <details class="mb-sm">
    <summary class="text-sm text-muted" style="cursor: pointer;">Visa global text</summary>
    <div class="p-sm gs-bg-light gs-rounded gs-mt-xs text-sm" style="white-space: pre-wrap;"><?= htmlspecialchars($globalTextMap['pm_content']) ?></div>
    </details>
    <?php endif; ?>
    <textarea
    id="pm_content"
    name="pm_content"
    class="input"
    rows="6"
    placeholder="Detaljerad PM för eventet..."
    ><?= htmlspecialchars($event['pm_content'] ?? '') ?></textarea>
   </div>
   </div>

   <!-- Jury Communication -->
   <div class="card mb-md">
   <div class="card-header">
    <h4 class=""><i data-lucide="gavel"></i> Jurykommuniké</h4>
   </div>
   <div class="card-body">
    <div class="mb-sm">
    <label class="checkbox-label">
     <input type="checkbox" name="jury_use_global" class="checkbox use-global-toggle" data-target="jury_communication" data-global="<?= htmlspecialchars($globalTextMap['jury_communication'] ?? '') ?>" <?= !empty($event['jury_use_global']) ? 'checked' : '' ?>>
     <span>Använd global text</span>
    </label>
    <?php if (!empty($globalTextMap['jury_communication'])): ?>
     <small class="text-muted ml-md">(Global text finns)</small>
    <?php else: ?>
     <small class="text-warning ml-md">(Ingen global text definierad)</small>
    <?php endif; ?>
    </div>
    <?php if (!empty($globalTextMap['jury_communication'])): ?>
    <details class="mb-sm">
    <summary class="text-sm text-muted" style="cursor: pointer;">Visa global text</summary>
    <div class="p-sm gs-bg-light gs-rounded gs-mt-xs text-sm" style="white-space: pre-wrap;"><?= htmlspecialchars($globalTextMap['jury_communication']) ?></div>
    </details>
    <?php endif; ?>
    <textarea
    id="jury_communication"
    name="jury_communication"
    class="input"
    rows="6"
    placeholder="Jurykommuniké och beslut..."
    ><?= htmlspecialchars($event['jury_communication'] ?? '') ?></textarea>
   </div>
   </div>

   <!-- Competition Schedule -->
   <div class="card mb-md">
   <div class="card-header">
    <h4 class=""><i data-lucide="calendar-clock"></i> Tävlingsschema</h4>
   </div>
   <div class="card-body">
    <div class="mb-sm">
    <label class="checkbox-label">
     <input type="checkbox" name="schedule_use_global" class="checkbox use-global-toggle" data-target="competition_schedule" data-global="<?= htmlspecialchars($globalTextMap['competition_schedule'] ?? '') ?>" <?= !empty($event['schedule_use_global']) ? 'checked' : '' ?>>
     <span>Använd global text</span>
    </label>
    <?php if (!empty($globalTextMap['competition_schedule'])): ?>
     <small class="text-muted ml-md">(Global text finns)</small>
    <?php else: ?>
     <small class="text-warning ml-md">(Ingen global text definierad)</small>
    <?php endif; ?>
    </div>
    <?php if (!empty($globalTextMap['competition_schedule'])): ?>
    <details class="mb-sm">
    <summary class="text-sm text-muted" style="cursor: pointer;">Visa global text</summary>
    <div class="p-sm gs-bg-light gs-rounded gs-mt-xs text-sm" style="white-space: pre-wrap;"><?= htmlspecialchars($globalTextMap['competition_schedule']) ?></div>
    </details>
    <?php endif; ?>
    <textarea
    id="competition_schedule"
    name="competition_schedule"
    class="input"
    rows="6"
    placeholder="Detaljerat tävlingsschema..."
    ><?= htmlspecialchars($event['competition_schedule'] ?? '') ?></textarea>
   </div>
   </div>

   <!-- Start Times -->
   <div class="card mb-md">
   <div class="card-header">
    <h4 class=""><i data-lucide="clock"></i> Starttider</h4>
   </div>
   <div class="card-body">
    <div class="mb-sm">
    <label class="checkbox-label">
     <input type="checkbox" name="start_times_use_global" class="checkbox use-global-toggle" data-target="start_times" data-global="<?= htmlspecialchars($globalTextMap['start_times'] ?? '') ?>" <?= !empty($event['start_times_use_global']) ? 'checked' : '' ?>>
     <span>Använd global text</span>
    </label>
    <?php if (!empty($globalTextMap['start_times'])): ?>
     <small class="text-muted ml-md">(Global text finns)</small>
    <?php else: ?>
     <small class="text-warning ml-md">(Ingen global text definierad)</small>
    <?php endif; ?>
    </div>
    <?php if (!empty($globalTextMap['start_times'])): ?>
    <details class="mb-sm">
    <summary class="text-sm text-muted" style="cursor: pointer;">Visa global text</summary>
    <div class="p-sm gs-bg-light gs-rounded gs-mt-xs text-sm" style="white-space: pre-wrap;"><?= htmlspecialchars($globalTextMap['start_times']) ?></div>
    </details>
    <?php endif; ?>
    <textarea
    id="start_times"
    name="start_times"
    class="input"
    rows="6"
    placeholder="Starttider per klass..."
    ><?= htmlspecialchars($event['start_times'] ?? '') ?></textarea>
   </div>
   </div>

   <!-- Map -->
   <div class="card mb-md">
   <div class="card-header">
    <h4 class=""><i data-lucide="map"></i> Karta</h4>
   </div>
   <div class="card-body">
    <div class="mb-sm">
    <label class="checkbox-label">
     <input type="checkbox" name="map_use_global" class="checkbox use-global-toggle" data-target="map_content" data-global="<?= htmlspecialchars($globalTextMap['map_content'] ?? '') ?>" <?= !empty($event['map_use_global']) ? 'checked' : '' ?>>
     <span>Använd global text</span>
    </label>
    <?php if (!empty($globalTextMap['map_content'])): ?>
     <small class="text-muted ml-md">(Global text finns)</small>
    <?php else: ?>
     <small class="text-warning ml-md">(Ingen global text definierad)</small>
    <?php endif; ?>
    </div>
    <?php if (!empty($globalTextMap['map_content'])): ?>
    <details class="mb-sm">
    <summary class="text-sm text-muted" style="cursor: pointer;">Visa global text</summary>
    <div class="p-sm gs-bg-light gs-rounded gs-mt-xs text-sm" style="white-space: pre-wrap;"><?= htmlspecialchars($globalTextMap['map_content']) ?></div>
    </details>
    <?php endif; ?>
    <div class="mb-md">
    <label for="map_image_url" class="label">Kartbild URL</label>
    <input
     type="url"
     id="map_image_url"
     name="map_image_url"
     class="input"
     value="<?= htmlspecialchars($event['map_image_url'] ?? '') ?>"
     placeholder="https://example.com/karta.jpg"
    >
    </div>
    <textarea
    id="map_content"
    name="map_content"
    class="input"
    rows="4"
    placeholder="Kartbeskrivning och vägbeskrivning..."
    ><?= htmlspecialchars($event['map_content'] ?? '') ?></textarea>
   </div>
   </div>

   <!-- Venue Details -->
   <div class="card mb-md">
   <div class="card-header">
    <h4 class=""><i data-lucide="map-pin"></i> Platsdetaljer</h4>
   </div>
   <div class="card-body">
    <div class="grid grid-cols-2 gap-md mb-md">
    <div>
     <label for="venue_coordinates" class="label">GPS-koordinater</label>
     <input
     type="text"
     id="venue_coordinates"
     name="venue_coordinates"
     class="input"
     value="<?= htmlspecialchars($event['venue_coordinates'] ?? '') ?>"
     placeholder="59.3293, 18.0686"
     >
    </div>
    <div>
     <label for="venue_map_url" class="label">Google Maps URL</label>
     <input
     type="url"
     id="venue_map_url"
     name="venue_map_url"
     class="input"
     value="<?= htmlspecialchars($event['venue_map_url'] ?? '') ?>"
     placeholder="https://maps.google.com/..."
     >
    </div>
    </div>
    <textarea
    id="venue_details"
    name="venue_details"
    class="input"
    rows="4"
    placeholder="Detaljerad platsinformation..."
    ><?= htmlspecialchars($event['venue_details'] ?? '') ?></textarea>
   </div>
   </div>

   <!-- FACILITIES & LOGISTICS -->
   <div class="section-divider mt-lg mb-md">
   <h3 class="text-primary">Faciliteter & Logistik</h3>
   <p class="text-sm text-secondary">Information om faciliteter och logistik på plats</p>
   </div>

   <div class="grid grid-cols-1 md-grid-cols-2 gap-md">
   <!-- Driver Meeting -->
   <div>
    <label for="driver_meeting" class="label">
    <i data-lucide="users"></i> Förarmöte
    <label class="checkbox-label ml-md" style="display: inline-flex; font-weight: normal;">
     <input type="checkbox" name="driver_meeting_use_global" class="checkbox" <?= !empty($event['driver_meeting_use_global']) ? 'checked' : '' ?>>
     <span class="text-xs">Global</span>
    </label>
    </label>
    <textarea
    id="driver_meeting"
    name="driver_meeting"
    class="input"
    rows="3"
    placeholder="Information om förarmöte..."
    ><?= htmlspecialchars($event['driver_meeting'] ?? '') ?></textarea>
   </div>

   <!-- Training -->
   <div>
    <label for="training_info" class="label">
    <i data-lucide="dumbbell"></i> Träning
    <label class="checkbox-label ml-md" style="display: inline-flex; font-weight: normal;">
     <input type="checkbox" name="training_use_global" class="checkbox" <?= !empty($event['training_use_global']) ? 'checked' : '' ?>>
     <span class="text-xs">Global</span>
    </label>
    </label>
    <textarea
    id="training_info"
    name="training_info"
    class="input"
    rows="3"
    placeholder="Träningsmöjligheter..."
    ><?= htmlspecialchars($event['training_info'] ?? '') ?></textarea>
   </div>

   <!-- Timing -->
   <div>
    <label for="timing_info" class="label">
    <i data-lucide="timer"></i> Tidtagning
    <label class="checkbox-label ml-md" style="display: inline-flex; font-weight: normal;">
     <input type="checkbox" name="timing_use_global" class="checkbox" <?= !empty($event['timing_use_global']) ? 'checked' : '' ?>>
     <span class="text-xs">Global</span>
    </label>
    </label>
    <textarea
    id="timing_info"
    name="timing_info"
    class="input"
    rows="3"
    placeholder="Tidtagningssystem..."
    ><?= htmlspecialchars($event['timing_info'] ?? '') ?></textarea>
   </div>

   <!-- Lift -->
   <div>
    <label for="lift_info" class="label">
    <i data-lucide="mountain-snow"></i> Lift
    <label class="checkbox-label ml-md" style="display: inline-flex; font-weight: normal;">
     <input type="checkbox" name="lift_use_global" class="checkbox" <?= !empty($event['lift_use_global']) ? 'checked' : '' ?>>
     <span class="text-xs">Global</span>
    </label>
    </label>
    <textarea
    id="lift_info"
    name="lift_info"
    class="input"
    rows="3"
    placeholder="Liftinformation..."
    ><?= htmlspecialchars($event['lift_info'] ?? '') ?></textarea>
   </div>

   <!-- Hydration Stations -->
   <div>
    <label for="hydration_stations" class="label">
    <i data-lucide="droplets"></i> Vätskekontroller
    <label class="checkbox-label ml-md" style="display: inline-flex; font-weight: normal;">
     <input type="checkbox" name="hydration_use_global" class="checkbox" <?= !empty($event['hydration_use_global']) ? 'checked' : '' ?>>
     <span class="text-xs">Global</span>
    </label>
    </label>
    <textarea
    id="hydration_stations"
    name="hydration_stations"
    class="input"
    rows="3"
    placeholder="Vätskekontroller..."
    ><?= htmlspecialchars($event['hydration_stations'] ?? '') ?></textarea>
   </div>

   <!-- Toilets/Showers -->
   <div>
    <label for="toilets_showers" class="label">
    <i data-lucide="bath"></i> Toaletter/Dusch
    <label class="checkbox-label ml-md" style="display: inline-flex; font-weight: normal;">
     <input type="checkbox" name="toilets_use_global" class="checkbox" <?= !empty($event['toilets_use_global']) ? 'checked' : '' ?>>
     <span class="text-xs">Global</span>
    </label>
    </label>
    <textarea
    id="toilets_showers"
    name="toilets_showers"
    class="input"
    rows="3"
    placeholder="Toaletter och dusch..."
    ><?= htmlspecialchars($event['toilets_showers'] ?? '') ?></textarea>
   </div>

   <!-- Bike Wash -->
   <div>
    <label for="bike_wash" class="label">
    <i data-lucide="spray-can"></i> Cykeltvätt
    <label class="checkbox-label ml-md" style="display: inline-flex; font-weight: normal;">
     <input type="checkbox" name="bike_wash_use_global" class="checkbox" <?= !empty($event['bike_wash_use_global']) ? 'checked' : '' ?>>
     <span class="text-xs">Global</span>
    </label>
    </label>
    <textarea
    id="bike_wash"
    name="bike_wash"
    class="input"
    rows="3"
    placeholder="Cykeltvätt..."
    ><?= htmlspecialchars($event['bike_wash'] ?? '') ?></textarea>
   </div>

   <!-- Food/Cafe -->
   <div>
    <label for="food_cafe" class="label">
    <i data-lucide="coffee"></i> Mat/Café
    <label class="checkbox-label ml-md" style="display: inline-flex; font-weight: normal;">
     <input type="checkbox" name="food_use_global" class="checkbox" <?= !empty($event['food_use_global']) ? 'checked' : '' ?>>
     <span class="text-xs">Global</span>
    </label>
    </label>
    <textarea
    id="food_cafe"
    name="food_cafe"
    class="input"
    rows="3"
    placeholder="Mat och café..."
    ><?= htmlspecialchars($event['food_cafe'] ?? '') ?></textarea>
   </div>

   <!-- Shops -->
   <div>
    <label for="shops_info" class="label">
    <i data-lucide="shopping-bag"></i> Affärer
    <label class="checkbox-label ml-md" style="display: inline-flex; font-weight: normal;">
     <input type="checkbox" name="shops_use_global" class="checkbox" <?= !empty($event['shops_use_global']) ? 'checked' : '' ?>>
     <span class="text-xs">Global</span>
    </label>
    </label>
    <textarea
    id="shops_info"
    name="shops_info"
    class="input"
    rows="3"
    placeholder="Affärer och butiker..."
    ><?= htmlspecialchars($event['shops_info'] ?? '') ?></textarea>
   </div>

   <!-- Exhibitors -->
   <div>
    <label for="exhibitors" class="label">
    <i data-lucide="tent"></i> Utställare
    <label class="checkbox-label ml-md" style="display: inline-flex; font-weight: normal;">
     <input type="checkbox" name="exhibitors_use_global" class="checkbox" <?= !empty($event['exhibitors_use_global']) ? 'checked' : '' ?>>
     <span class="text-xs">Global</span>
    </label>
    </label>
    <textarea
    id="exhibitors"
    name="exhibitors"
    class="input"
    rows="3"
    placeholder="Utställare på plats..."
    ><?= htmlspecialchars($event['exhibitors'] ?? '') ?></textarea>
   </div>

   <!-- Parking (detailed) -->
   <div>
    <label for="parking_detailed" class="label">
    <i data-lucide="car"></i> Parkering (detaljerad)
    <label class="checkbox-label ml-md" style="display: inline-flex; font-weight: normal;">
     <input type="checkbox" name="parking_use_global" class="checkbox" <?= !empty($event['parking_use_global']) ? 'checked' : '' ?>>
     <span class="text-xs">Global</span>
    </label>
    </label>
    <textarea
    id="parking_detailed"
    name="parking_detailed"
    class="input"
    rows="3"
    placeholder="Detaljerad parkeringsinformation..."
    ><?= htmlspecialchars($event['parking_detailed'] ?? '') ?></textarea>
   </div>

   <!-- Hotel/Accommodation -->
   <div>
    <label for="hotel_accommodation" class="label">
    <i data-lucide="hotel"></i> Hotell och boende
    <label class="checkbox-label ml-md" style="display: inline-flex; font-weight: normal;">
     <input type="checkbox" name="hotel_use_global" class="checkbox" <?= !empty($event['hotel_use_global']) ? 'checked' : '' ?>>
     <span class="text-xs">Global</span>
    </label>
    </label>
    <textarea
    id="hotel_accommodation"
    name="hotel_accommodation"
    class="input"
    rows="3"
    placeholder="Hotell och boendealternativ..."
    ><?= htmlspecialchars($event['hotel_accommodation'] ?? '') ?></textarea>
   </div>

   <!-- Local Info -->
   <div>
    <label for="local_info" class="label">
    <i data-lucide="landmark"></i> Lokal information
    <label class="checkbox-label ml-md" style="display: inline-flex; font-weight: normal;">
     <input type="checkbox" name="local_use_global" class="checkbox" <?= !empty($event['local_use_global']) ? 'checked' : '' ?>>
     <span class="text-xs">Global</span>
    </label>
    </label>
    <textarea
    id="local_info"
    name="local_info"
    class="input"
    rows="3"
    placeholder="Lokal information om orten..."
    ><?= htmlspecialchars($event['local_info'] ?? '') ?></textarea>
   </div>
   </div>

   <!-- RULES & SAFETY -->
   <div class="section-divider mt-lg mb-md">
   <h3 class="text-primary">Regler & Säkerhet</h3>
   </div>

   <div class="grid grid-cols-1 md-grid-cols-2 gap-md">
   <!-- Competition Tracks -->
   <div>
    <label for="competition_tracks" class="label">
    <i data-lucide="route"></i> Tävlingssträckor
    <label class="checkbox-label ml-md" style="display: inline-flex; font-weight: normal;">
     <input type="checkbox" name="tracks_use_global" class="checkbox" <?= !empty($event['tracks_use_global']) ? 'checked' : '' ?>>
     <span class="text-xs">Global</span>
    </label>
    </label>
    <textarea
    id="competition_tracks"
    name="competition_tracks"
    class="input"
    rows="3"
    placeholder="Beskrivning av tävlingssträckor..."
    ><?= htmlspecialchars($event['competition_tracks'] ?? '') ?></textarea>
   </div>

   <!-- Competition Rules -->
   <div>
    <label for="competition_rules" class="label">
    <i data-lucide="scale"></i> Tävlingsregler
    <label class="checkbox-label ml-md" style="display: inline-flex; font-weight: normal;">
     <input type="checkbox" name="rules_use_global" class="checkbox" <?= !empty($event['rules_use_global']) ? 'checked' : '' ?>>
     <span class="text-xs">Global</span>
    </label>
    </label>
    <textarea
    id="competition_rules"
    name="competition_rules"
    class="input"
    rows="3"
    placeholder="Tävlingsregler..."
    ><?= htmlspecialchars($event['competition_rules'] ?? '') ?></textarea>
   </div>

   <!-- Insurance -->
   <div>
    <label for="insurance_info" class="label">
    <i data-lucide="shield-check"></i> Försäkring
    <label class="checkbox-label ml-md" style="display: inline-flex; font-weight: normal;">
     <input type="checkbox" name="insurance_use_global" class="checkbox" <?= !empty($event['insurance_use_global']) ? 'checked' : '' ?>>
     <span class="text-xs">Global</span>
    </label>
    </label>
    <textarea
    id="insurance_info"
    name="insurance_info"
    class="input"
    rows="3"
    placeholder="Försäkringsinformation..."
    ><?= htmlspecialchars($event['insurance_info'] ?? '') ?></textarea>
   </div>

   <!-- Equipment -->
   <div>
    <label for="equipment_info" class="label">
    <i data-lucide="hard-hat"></i> Utrustning
    <label class="checkbox-label ml-md" style="display: inline-flex; font-weight: normal;">
     <input type="checkbox" name="equipment_use_global" class="checkbox" <?= !empty($event['equipment_use_global']) ? 'checked' : '' ?>>
     <span class="text-xs">Global</span>
    </label>
    </label>
    <textarea
    id="equipment_info"
    name="equipment_info"
    class="input"
    rows="3"
    placeholder="Utrustningskrav..."
    ><?= htmlspecialchars($event['equipment_info'] ?? '') ?></textarea>
   </div>
   </div>

   <!-- CONTACTS & INFORMATION -->
   <div class="section-divider mt-lg mb-md">
   <h3 class="text-primary">Kontakter & Övrig Information</h3>
   </div>

   <div class="grid grid-cols-1 md-grid-cols-2 gap-md">
   <!-- Entry Fees (detailed) -->
   <div>
    <label for="entry_fees_detailed" class="label">
    <i data-lucide="banknote"></i> Startavgifter (detaljerad)
    <label class="checkbox-label ml-md" style="display: inline-flex; font-weight: normal;">
     <input type="checkbox" name="fees_use_global" class="checkbox" <?= !empty($event['fees_use_global']) ? 'checked' : '' ?>>
     <span class="text-xs">Global</span>
    </label>
    </label>
    <textarea
    id="entry_fees_detailed"
    name="entry_fees_detailed"
    class="input"
    rows="3"
    placeholder="Detaljerad avgiftsinformation per klass..."
    ><?= htmlspecialchars($event['entry_fees_detailed'] ?? '') ?></textarea>
   </div>

   <!-- Results Info -->
   <div>
    <label for="results_info" class="label">
    <i data-lucide="trophy"></i> Resultatinformation
    <label class="checkbox-label ml-md" style="display: inline-flex; font-weight: normal;">
     <input type="checkbox" name="results_use_global" class="checkbox" <?= !empty($event['results_use_global']) ? 'checked' : '' ?>>
     <span class="text-xs">Global</span>
    </label>
    </label>
    <textarea
    id="results_info"
    name="results_info"
    class="input"
    rows="3"
    placeholder="Hur resultat publiceras..."
    ><?= htmlspecialchars($event['results_info'] ?? '') ?></textarea>
   </div>

   <!-- Medical Info -->
   <div>
    <label for="medical_info" class="label">
    <i data-lucide="heart-pulse"></i> Sjukvård
    <label class="checkbox-label ml-md" style="display: inline-flex; font-weight: normal;">
     <input type="checkbox" name="medical_use_global" class="checkbox" <?= !empty($event['medical_use_global']) ? 'checked' : '' ?>>
     <span class="text-xs">Global</span>
    </label>
    </label>
    <textarea
    id="medical_info"
    name="medical_info"
    class="input"
    rows="3"
    placeholder="Sjukvårdsinformation..."
    ><?= htmlspecialchars($event['medical_info'] ?? '') ?></textarea>
   </div>

   <!-- Media Production -->
   <div>
    <label for="media_production" class="label">
    <i data-lucide="video"></i> Mediaproduktion
    <label class="checkbox-label ml-md" style="display: inline-flex; font-weight: normal;">
     <input type="checkbox" name="media_use_global" class="checkbox" <?= !empty($event['media_use_global']) ? 'checked' : '' ?>>
     <span class="text-xs">Global</span>
    </label>
    </label>
    <textarea
    id="media_production"
    name="media_production"
    class="input"
    rows="3"
    placeholder="Foto och video..."
    ><?= htmlspecialchars($event['media_production'] ?? '') ?></textarea>
   </div>

   <!-- Contacts Info -->
   <div>
    <label for="contacts_info" class="label">
    <i data-lucide="contact"></i> Kontakter (detaljerad)
    <label class="checkbox-label ml-md" style="display: inline-flex; font-weight: normal;">
     <input type="checkbox" name="contacts_use_global" class="checkbox" <?= !empty($event['contacts_use_global']) ? 'checked' : '' ?>>
     <span class="text-xs">Global</span>
    </label>
    </label>
    <textarea
    id="contacts_info"
    name="contacts_info"
    class="input"
    rows="3"
    placeholder="Kontaktpersoner..."
    ><?= htmlspecialchars($event['contacts_info'] ?? '') ?></textarea>
   </div>

   <!-- SCF Representatives -->
   <div>
    <label for="scf_representatives" class="label">
    <i data-lucide="badge"></i> SCF Representanter
    <label class="checkbox-label ml-md" style="display: inline-flex; font-weight: normal;">
     <input type="checkbox" name="scf_use_global" class="checkbox" <?= !empty($event['scf_use_global']) ? 'checked' : '' ?>>
     <span class="text-xs">Global</span>
    </label>
    </label>
    <textarea
    id="scf_representatives"
    name="scf_representatives"
    class="input"
    rows="3"
    placeholder="SCF-representanter..."
    ><?= htmlspecialchars($event['scf_representatives'] ?? '') ?></textarea>
   </div>
   </div>

   <!-- Active Status -->
   <div>
   <label class="checkbox-label">
    <input
    type="checkbox"
    name="active"
    class="checkbox"
    <?= $event['active'] ? 'checked' : '' ?>
    >
    <span>
    <i data-lucide="check-circle"></i>
    Aktivt event
    </span>
   </label>
   </div>
  </div>

  <!-- Footer -->
  <div class="flex gs-justify-end gap-md mt-lg">
   <a href="/admin/events.php" class="btn btn--secondary">
   Avbryt
   </a>
   <button type="submit" class="btn btn--primary">
   <i data-lucide="save"></i>
   Spara Ändringar
   </button>
  </div>
  </form>
 </div>
 </div>
</main>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>

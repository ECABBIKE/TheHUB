<?php
require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();

// Initialize message variables
$message = '';
$messageType = 'info';

// Generate suggested advent_id for new events
$suggested_advent_id = generateEventAdventId($pdo);

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
        // Auto-generate advent_id if not provided
        $advent_id_input = trim($_POST['advent_id'] ?? '');
        if (empty($advent_id_input)) {
            // Extract year from event date for better ID generation
            $event_year = date('Y', strtotime($date));
            $advent_id_input = generateEventAdventId($pdo, $event_year);
        }

        $eventData = [
            'name' => $name,
            'advent_id' => $advent_id_input,
            'date' => $date,
            'location' => trim($_POST['location'] ?? ''),
            'venue_id' => !empty($_POST['venue_id']) ? intval($_POST['venue_id']) : null,
            'discipline' => trim($_POST['discipline'] ?? ''),
            'event_level' => in_array($_POST['event_level'] ?? '', ['national', 'sportmotion']) ? $_POST['event_level'] : 'national',
            'event_format' => trim($_POST['event_format'] ?? 'ENDURO'),
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
            // Extended fields with global text support
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
            $db->insert('events', $eventData);
            $_SESSION['message'] = 'Event skapat!';
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
$defaultTemplate = array_filter($pricingTemplates, fn($t) => $t['is_default']);
$defaultTemplateId = $defaultTemplate ? reset($defaultTemplate)['id'] : null;

// Fetch global texts for "use global" functionality
$globalTexts = $db->getAll("SELECT field_key, content FROM global_texts WHERE is_active = 1");
$globalTextMap = [];
foreach ($globalTexts as $gt) {
    $globalTextMap[$gt['field_key']] = $gt['content'];
}

$pageTitle = 'Skapa Event';
$pageType = 'admin';
include __DIR__ . '/../includes/layout-header.php';
?>

<main class="gs-main-content">
    <div class="gs-container" class="gs-max-w-800">
        <!-- Header -->
        <div class="gs-flex gs-items-center gs-justify-between gs-mb-lg">
            <h1 class="gs-h2">
                <i data-lucide="calendar-plus"></i>
                Skapa Nytt Event
            </h1>
            <a href="/admin/events.php" class="gs-btn gs-btn-outline">
                <i data-lucide="x"></i>
                Avbryt
            </a>
        </div>

        <!-- Messages -->
        <?php if ($message): ?>
            <div class="gs-alert gs-alert-<?= $messageType ?> gs-mb-lg">
                <i data-lucide="<?= $messageType === 'success' ? 'check-circle' : ($messageType === 'error' ? 'alert-circle' : 'info') ?>"></i>
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <!-- Form -->
        <div class="gs-card">
            <form method="POST" class="gs-card-content">
                <?= csrf_field() ?>

                <div class="gs-grid gs-grid-cols-1 gs-gap-md">
                    <!-- Name (Required) -->
                    <div>
                        <label for="name" class="gs-label">
                            <i data-lucide="calendar"></i>
                            Namn <span class="gs-text-error">*</span>
                        </label>
                        <input
                            type="text"
                            id="name"
                            name="name"
                            class="gs-input"
                            required
                            value="<?= htmlspecialchars($_POST['name'] ?? '') ?>"
                            placeholder="T.ex. GravitySeries Järvsö"
                        >
                    </div>

                    <!-- Advent ID -->
                    <div>
                        <label for="advent_id" class="gs-label">
                            <i data-lucide="hash"></i>
                            Advent ID
                        </label>
                        <input
                            type="text"
                            id="advent_id"
                            name="advent_id"
                            class="gs-input"
                            value="<?= htmlspecialchars($_POST['advent_id'] ?? $suggested_advent_id) ?>"
                            placeholder="<?= htmlspecialchars($suggested_advent_id) ?>"
                        >
                        <small class="gs-text-muted">Externt ID för import av resultat. Lämna tomt för auto-generering.</small>
                    </div>

                    <!-- Date (Required) -->
                    <div>
                        <label for="date" class="gs-label">
                            <i data-lucide="calendar-days"></i>
                            Datum <span class="gs-text-error">*</span>
                        </label>
                        <input
                            type="date"
                            id="date"
                            name="date"
                            class="gs-input"
                            required
                            value="<?= htmlspecialchars($_POST['date'] ?? '') ?>"
                        >
                    </div>

                    <!-- Location and Venue -->
                    <div class="gs-grid gs-grid-cols-2 gs-gap-md">
                        <div>
                            <label for="location" class="gs-label">
                                <i data-lucide="map-pin"></i>
                                Plats
                            </label>
                            <input
                                type="text"
                                id="location"
                                name="location"
                                class="gs-input"
                                value="<?= htmlspecialchars($_POST['location'] ?? '') ?>"
                                placeholder="T.ex. Järvsö"
                            >
                        </div>
                        <div>
                            <label for="venue_id" class="gs-label">
                                <i data-lucide="mountain"></i>
                                Bana/Anläggning
                            </label>
                            <select id="venue_id" name="venue_id" class="gs-input">
                                <option value="">Ingen specifik bana</option>
                                <?php foreach ($venues as $venue): ?>
                                    <option value="<?= $venue['id'] ?>" <?= ($_POST['venue_id'] ?? '') == $venue['id'] ? 'selected' : '' ?>>
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
                        <label for="discipline" class="gs-label">
                            <i data-lucide="bike"></i>
                            Tävlingsformat
                        </label>
                        <select id="discipline" name="discipline" class="gs-input">
                            <option value="">Välj format...</option>
                            <option value="ENDURO" <?= ($_POST['discipline'] ?? '') === 'ENDURO' ? 'selected' : '' ?>>Enduro</option>
                            <option value="DH" <?= ($_POST['discipline'] ?? '') === 'DH' ? 'selected' : '' ?>>Downhill</option>
                            <option value="XC" <?= ($_POST['discipline'] ?? '') === 'XC' ? 'selected' : '' ?>>XC</option>
                            <option value="XCO" <?= ($_POST['discipline'] ?? '') === 'XCO' ? 'selected' : '' ?>>XCO</option>
                            <option value="XCM" <?= ($_POST['discipline'] ?? '') === 'XCM' ? 'selected' : '' ?>>XCM</option>
                            <option value="DUAL_SLALOM" <?= ($_POST['discipline'] ?? '') === 'DUAL_SLALOM' ? 'selected' : '' ?>>Dual Slalom</option>
                            <option value="PUMPTRACK" <?= ($_POST['discipline'] ?? '') === 'PUMPTRACK' ? 'selected' : '' ?>>Pumptrack</option>
                            <option value="GRAVEL" <?= ($_POST['discipline'] ?? '') === 'GRAVEL' ? 'selected' : '' ?>>Gravel</option>
                            <option value="E-MTB" <?= ($_POST['discipline'] ?? '') === 'E-MTB' ? 'selected' : '' ?>>E-MTB</option>
                        </select>
                    </div>

                    <!-- Event Level (National/Sportmotion) -->
                    <div>
                        <label for="event_level" class="gs-label">
                            <i data-lucide="trophy"></i>
                            Tävlingsnivå
                            <span class="gs-text-secondary gs-text-xs">(påverkar rankingpoäng)</span>
                        </label>
                        <select id="event_level" name="event_level" class="gs-input">
                            <option value="national" <?= ($_POST['event_level'] ?? 'national') === 'national' ? 'selected' : '' ?>>Nationell tävling (100% poäng)</option>
                            <option value="sportmotion" <?= ($_POST['event_level'] ?? 'national') === 'sportmotion' ? 'selected' : '' ?>>Sportmotion (50% poäng)</option>
                        </select>
                        <small class="gs-text-secondary">Nationella tävlingar ger fulla rankingpoäng, Sportmotion-event ger 50% av poängen</small>
                    </div>

                    <div class="gs-grid gs-grid-cols-2 gs-gap-md">
                        <div>
                            <label for="event_format" class="gs-label">
                                <i data-lucide="layout-list"></i>
                                Event-format
                            </label>
                            <select id="event_format" name="event_format" class="gs-input">
                                <option value="ENDURO" <?= ($_POST['event_format'] ?? 'ENDURO') === 'ENDURO' ? 'selected' : '' ?>>
                                    Enduro (en tid, splittider)
                                </option>
                                <option value="DH_STANDARD" <?= ($_POST['event_format'] ?? '') === 'DH_STANDARD' ? 'selected' : '' ?>>
                                    Downhill Standard (två åk, snabbaste räknas)
                                </option>
                                <option value="DH_SWECUP" <?= ($_POST['event_format'] ?? '') === 'DH_SWECUP' ? 'selected' : '' ?>>
                                    SweCUP Downhill (två åk, båda ger poäng)
                                </option>
                                <option value="DUAL_SLALOM" <?= ($_POST['event_format'] ?? '') === 'DUAL_SLALOM' ? 'selected' : '' ?>>
                                    Dual Slalom
                                </option>
                            </select>
                            <small class="gs-text-muted">
                                Bestämmer hur resultat visas och poängräknas
                            </small>
                        </div>
                        <div>
                            <label for="series_id" class="gs-label">
                                <i data-lucide="trophy"></i>
                                Serie
                            </label>
                            <select id="series_id" name="series_id" class="gs-input">
                                <option value="">Ingen serie</option>
                                <?php foreach ($series as $s): ?>
                                    <option value="<?= $s['id'] ?>" <?= ($_POST['series_id'] ?? '') == $s['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($s['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- Pricing Template -->
                    <div>
                        <label for="pricing_template_id" class="gs-label">
                            <i data-lucide="credit-card"></i>
                            Prismall
                        </label>
                        <select id="pricing_template_id" name="pricing_template_id" class="gs-input">
                            <option value="">Ingen prismall</option>
                            <?php foreach ($pricingTemplates as $template): ?>
                                <option value="<?= $template['id'] ?>" <?= ($_POST['pricing_template_id'] ?? $defaultTemplateId) == $template['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($template['name']) ?>
                                    <?php if ($template['is_default']): ?>(Standard)<?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="gs-text-muted">Välj prismall för detta event. <a href="/admin/pricing-templates.php">Hantera prismallar</a></small>
                    </div>

                    <!-- Distance and Elevation -->
                    <div class="gs-grid gs-grid-cols-2 gs-gap-md">
                        <div>
                            <label for="distance" class="gs-label">
                                <i data-lucide="route"></i>
                                Distans (km)
                            </label>
                            <input
                                type="number"
                                id="distance"
                                name="distance"
                                class="gs-input"
                                step="0.01"
                                min="0"
                                value="<?= htmlspecialchars($_POST['distance'] ?? '') ?>"
                                placeholder="T.ex. 42.5"
                            >
                        </div>
                        <div>
                            <label for="elevation_gain" class="gs-label">
                                <i data-lucide="mountain"></i>
                                Höjdmeter (m)
                            </label>
                            <input
                                type="number"
                                id="elevation_gain"
                                name="elevation_gain"
                                class="gs-input"
                                min="0"
                                value="<?= htmlspecialchars($_POST['elevation_gain'] ?? '') ?>"
                                placeholder="T.ex. 1200"
                            >
                        </div>
                    </div>

                    <!-- Organizer and Website -->
                    <div class="gs-grid gs-grid-cols-2 gs-gap-md">
                        <div>
                            <label for="organizer" class="gs-label">
                                <i data-lucide="user"></i>
                                Arrangör
                            </label>
                            <input
                                type="text"
                                id="organizer"
                                name="organizer"
                                class="gs-input"
                                value="<?= htmlspecialchars($_POST['organizer'] ?? '') ?>"
                                placeholder="T.ex. GravitySeries AB"
                            >
                        </div>
                        <div>
                            <label for="website" class="gs-label">
                                <i data-lucide="globe"></i>
                                Webbplats
                            </label>
                            <input
                                type="url"
                                id="website"
                                name="website"
                                class="gs-input"
                                value="<?= htmlspecialchars($_POST['website'] ?? '') ?>"
                                placeholder="https://example.com"
                            >
                        </div>
                    </div>

                    <!-- Registration Deadline -->
                    <div>
                        <label for="registration_deadline" class="gs-label">
                            <i data-lucide="calendar-clock"></i>
                            Anmälningsfrist
                        </label>
                        <input
                            type="date"
                            id="registration_deadline"
                            name="registration_deadline"
                            class="gs-input"
                            value="<?= htmlspecialchars($_POST['registration_deadline'] ?? '') ?>"
                        >
                    </div>

                    <!-- Contact Information -->
                    <div class="gs-grid gs-grid-cols-2 gs-gap-md">
                        <div>
                            <label for="contact_email" class="gs-label">
                                <i data-lucide="mail"></i>
                                Kontakt e-post
                            </label>
                            <input
                                type="email"
                                id="contact_email"
                                name="contact_email"
                                class="gs-input"
                                value="<?= htmlspecialchars($_POST['contact_email'] ?? '') ?>"
                                placeholder="info@example.com"
                            >
                        </div>
                        <div>
                            <label for="contact_phone" class="gs-label">
                                <i data-lucide="phone"></i>
                                Kontakt telefon
                            </label>
                            <input
                                type="tel"
                                id="contact_phone"
                                name="contact_phone"
                                class="gs-input"
                                value="<?= htmlspecialchars($_POST['contact_phone'] ?? '') ?>"
                                placeholder="+46 70 123 45 67"
                            >
                        </div>
                    </div>

                    <!-- NEW EXTENDED FIELDS WITH GLOBAL TEXT SUPPORT -->
                    <div class="gs-section-divider gs-mt-xl gs-mb-md">
                        <h3 class="gs-h4 gs-text-primary">Event-flikar Information</h3>
                        <p class="gs-text-sm gs-text-secondary">Innehåll för event-sidans flikar. Markera "Använd global text" för att använda standardtext från <a href="/admin/system-settings.php?tab=global-texts">Globala Texter</a>.</p>
                    </div>

                    <!-- PM (Promemoria) -->
                    <div class="gs-card gs-mb-md">
                        <div class="gs-card-header">
                            <h4 class="gs-h5"><i data-lucide="file-text"></i> PM (Promemoria)</h4>
                        </div>
                        <div class="gs-card-content">
                            <div class="gs-mb-sm">
                                <label class="gs-checkbox-label">
                                    <input type="checkbox" name="pm_use_global" class="gs-checkbox" <?= !empty($_POST['pm_use_global']) ? 'checked' : '' ?>>
                                    <span>Använd global text</span>
                                </label>
                                <?php if (!empty($globalTextMap['pm_content'])): ?>
                                    <small class="gs-text-muted gs-ml-md">(Global text finns)</small>
                                <?php else: ?>
                                    <small class="gs-text-warning gs-ml-md">(Ingen global text definierad)</small>
                                <?php endif; ?>
                            </div>
                            <textarea
                                id="pm_content"
                                name="pm_content"
                                class="gs-input"
                                rows="4"
                                placeholder="Detaljerad PM för eventet..."
                            ><?= htmlspecialchars($_POST['pm_content'] ?? '') ?></textarea>
                        </div>
                    </div>

                    <!-- Jury Communication -->
                    <div class="gs-card gs-mb-md">
                        <div class="gs-card-header">
                            <h4 class="gs-h5"><i data-lucide="gavel"></i> Jurykommuniké</h4>
                        </div>
                        <div class="gs-card-content">
                            <div class="gs-mb-sm">
                                <label class="gs-checkbox-label">
                                    <input type="checkbox" name="jury_use_global" class="gs-checkbox" <?= !empty($_POST['jury_use_global']) ? 'checked' : '' ?>>
                                    <span>Använd global text</span>
                                </label>
                            </div>
                            <textarea
                                id="jury_communication"
                                name="jury_communication"
                                class="gs-input"
                                rows="4"
                                placeholder="Jurykommuniké och beslut..."
                            ><?= htmlspecialchars($_POST['jury_communication'] ?? '') ?></textarea>
                        </div>
                    </div>

                    <!-- Competition Schedule -->
                    <div class="gs-card gs-mb-md">
                        <div class="gs-card-header">
                            <h4 class="gs-h5"><i data-lucide="calendar-clock"></i> Tävlingsschema</h4>
                        </div>
                        <div class="gs-card-content">
                            <div class="gs-mb-sm">
                                <label class="gs-checkbox-label">
                                    <input type="checkbox" name="schedule_use_global" class="gs-checkbox" <?= !empty($_POST['schedule_use_global']) ? 'checked' : '' ?>>
                                    <span>Använd global text</span>
                                </label>
                            </div>
                            <textarea
                                id="competition_schedule"
                                name="competition_schedule"
                                class="gs-input"
                                rows="4"
                                placeholder="Detaljerat tävlingsschema..."
                            ><?= htmlspecialchars($_POST['competition_schedule'] ?? '') ?></textarea>
                        </div>
                    </div>

                    <!-- Start Times -->
                    <div class="gs-card gs-mb-md">
                        <div class="gs-card-header">
                            <h4 class="gs-h5"><i data-lucide="clock"></i> Starttider</h4>
                        </div>
                        <div class="gs-card-content">
                            <div class="gs-mb-sm">
                                <label class="gs-checkbox-label">
                                    <input type="checkbox" name="start_times_use_global" class="gs-checkbox" <?= !empty($_POST['start_times_use_global']) ? 'checked' : '' ?>>
                                    <span>Använd global text</span>
                                </label>
                            </div>
                            <textarea
                                id="start_times"
                                name="start_times"
                                class="gs-input"
                                rows="4"
                                placeholder="Starttider per klass..."
                            ><?= htmlspecialchars($_POST['start_times'] ?? '') ?></textarea>
                        </div>
                    </div>

                    <!-- Map -->
                    <div class="gs-card gs-mb-md">
                        <div class="gs-card-header">
                            <h4 class="gs-h5"><i data-lucide="map"></i> Karta</h4>
                        </div>
                        <div class="gs-card-content">
                            <div class="gs-mb-sm">
                                <label class="gs-checkbox-label">
                                    <input type="checkbox" name="map_use_global" class="gs-checkbox" <?= !empty($_POST['map_use_global']) ? 'checked' : '' ?>>
                                    <span>Använd global text</span>
                                </label>
                            </div>
                            <div class="gs-mb-md">
                                <label for="map_image_url" class="gs-label">Kartbild URL</label>
                                <input
                                    type="url"
                                    id="map_image_url"
                                    name="map_image_url"
                                    class="gs-input"
                                    value="<?= htmlspecialchars($_POST['map_image_url'] ?? '') ?>"
                                    placeholder="https://example.com/karta.jpg"
                                >
                            </div>
                            <textarea
                                id="map_content"
                                name="map_content"
                                class="gs-input"
                                rows="3"
                                placeholder="Kartbeskrivning och vägbeskrivning..."
                            ><?= htmlspecialchars($_POST['map_content'] ?? '') ?></textarea>
                        </div>
                    </div>

                    <!-- Venue Details -->
                    <div class="gs-card gs-mb-md">
                        <div class="gs-card-header">
                            <h4 class="gs-h5"><i data-lucide="map-pin"></i> Platsdetaljer</h4>
                        </div>
                        <div class="gs-card-content">
                            <div class="gs-grid gs-grid-cols-2 gs-gap-md gs-mb-md">
                                <div>
                                    <label for="venue_coordinates" class="gs-label">GPS-koordinater</label>
                                    <input
                                        type="text"
                                        id="venue_coordinates"
                                        name="venue_coordinates"
                                        class="gs-input"
                                        value="<?= htmlspecialchars($_POST['venue_coordinates'] ?? '') ?>"
                                        placeholder="59.3293, 18.0686"
                                    >
                                </div>
                                <div>
                                    <label for="venue_map_url" class="gs-label">Google Maps URL</label>
                                    <input
                                        type="url"
                                        id="venue_map_url"
                                        name="venue_map_url"
                                        class="gs-input"
                                        value="<?= htmlspecialchars($_POST['venue_map_url'] ?? '') ?>"
                                        placeholder="https://maps.google.com/..."
                                    >
                                </div>
                            </div>
                            <textarea
                                id="venue_details"
                                name="venue_details"
                                class="gs-input"
                                rows="3"
                                placeholder="Detaljerad platsinformation..."
                            ><?= htmlspecialchars($_POST['venue_details'] ?? '') ?></textarea>
                        </div>
                    </div>

                    <!-- FACILITIES & LOGISTICS -->
                    <div class="gs-section-divider gs-mt-xl gs-mb-md">
                        <h3 class="gs-h4 gs-text-primary">Faciliteter & Logistik</h3>
                        <p class="gs-text-sm gs-text-secondary">Information om faciliteter och logistik på plats</p>
                    </div>

                    <div class="gs-grid gs-grid-cols-1 gs-md-grid-cols-2 gs-gap-md">
                        <!-- Driver Meeting -->
                        <div>
                            <label for="driver_meeting" class="gs-label">
                                <i data-lucide="users"></i> Förarmöte
                                <label class="gs-checkbox-label gs-ml-md" style="display: inline-flex; font-weight: normal;">
                                    <input type="checkbox" name="driver_meeting_use_global" class="gs-checkbox" <?= !empty($_POST['driver_meeting_use_global']) ? 'checked' : '' ?>>
                                    <span class="gs-text-xs">Global</span>
                                </label>
                            </label>
                            <textarea
                                id="driver_meeting"
                                name="driver_meeting"
                                class="gs-input"
                                rows="3"
                                placeholder="Information om förarmöte..."
                            ><?= htmlspecialchars($_POST['driver_meeting'] ?? '') ?></textarea>
                        </div>

                        <!-- Training -->
                        <div>
                            <label for="training_info" class="gs-label">
                                <i data-lucide="dumbbell"></i> Träning
                                <label class="gs-checkbox-label gs-ml-md" style="display: inline-flex; font-weight: normal;">
                                    <input type="checkbox" name="training_use_global" class="gs-checkbox" <?= !empty($_POST['training_use_global']) ? 'checked' : '' ?>>
                                    <span class="gs-text-xs">Global</span>
                                </label>
                            </label>
                            <textarea
                                id="training_info"
                                name="training_info"
                                class="gs-input"
                                rows="3"
                                placeholder="Träningsmöjligheter..."
                            ><?= htmlspecialchars($_POST['training_info'] ?? '') ?></textarea>
                        </div>

                        <!-- Timing -->
                        <div>
                            <label for="timing_info" class="gs-label">
                                <i data-lucide="timer"></i> Tidtagning
                                <label class="gs-checkbox-label gs-ml-md" style="display: inline-flex; font-weight: normal;">
                                    <input type="checkbox" name="timing_use_global" class="gs-checkbox" <?= !empty($_POST['timing_use_global']) ? 'checked' : '' ?>>
                                    <span class="gs-text-xs">Global</span>
                                </label>
                            </label>
                            <textarea
                                id="timing_info"
                                name="timing_info"
                                class="gs-input"
                                rows="3"
                                placeholder="Tidtagningssystem..."
                            ><?= htmlspecialchars($_POST['timing_info'] ?? '') ?></textarea>
                        </div>

                        <!-- Lift -->
                        <div>
                            <label for="lift_info" class="gs-label">
                                <i data-lucide="mountain-snow"></i> Lift
                                <label class="gs-checkbox-label gs-ml-md" style="display: inline-flex; font-weight: normal;">
                                    <input type="checkbox" name="lift_use_global" class="gs-checkbox" <?= !empty($_POST['lift_use_global']) ? 'checked' : '' ?>>
                                    <span class="gs-text-xs">Global</span>
                                </label>
                            </label>
                            <textarea
                                id="lift_info"
                                name="lift_info"
                                class="gs-input"
                                rows="3"
                                placeholder="Liftinformation..."
                            ><?= htmlspecialchars($_POST['lift_info'] ?? '') ?></textarea>
                        </div>

                        <!-- Hydration Stations -->
                        <div>
                            <label for="hydration_stations" class="gs-label">
                                <i data-lucide="droplets"></i> Vätskekontroller
                                <label class="gs-checkbox-label gs-ml-md" style="display: inline-flex; font-weight: normal;">
                                    <input type="checkbox" name="hydration_use_global" class="gs-checkbox" <?= !empty($_POST['hydration_use_global']) ? 'checked' : '' ?>>
                                    <span class="gs-text-xs">Global</span>
                                </label>
                            </label>
                            <textarea
                                id="hydration_stations"
                                name="hydration_stations"
                                class="gs-input"
                                rows="3"
                                placeholder="Vätskekontroller..."
                            ><?= htmlspecialchars($_POST['hydration_stations'] ?? '') ?></textarea>
                        </div>

                        <!-- Toilets/Showers -->
                        <div>
                            <label for="toilets_showers" class="gs-label">
                                <i data-lucide="bath"></i> Toaletter/Dusch
                                <label class="gs-checkbox-label gs-ml-md" style="display: inline-flex; font-weight: normal;">
                                    <input type="checkbox" name="toilets_use_global" class="gs-checkbox" <?= !empty($_POST['toilets_use_global']) ? 'checked' : '' ?>>
                                    <span class="gs-text-xs">Global</span>
                                </label>
                            </label>
                            <textarea
                                id="toilets_showers"
                                name="toilets_showers"
                                class="gs-input"
                                rows="3"
                                placeholder="Toaletter och dusch..."
                            ><?= htmlspecialchars($_POST['toilets_showers'] ?? '') ?></textarea>
                        </div>

                        <!-- Bike Wash -->
                        <div>
                            <label for="bike_wash" class="gs-label">
                                <i data-lucide="spray-can"></i> Cykeltvätt
                                <label class="gs-checkbox-label gs-ml-md" style="display: inline-flex; font-weight: normal;">
                                    <input type="checkbox" name="bike_wash_use_global" class="gs-checkbox" <?= !empty($_POST['bike_wash_use_global']) ? 'checked' : '' ?>>
                                    <span class="gs-text-xs">Global</span>
                                </label>
                            </label>
                            <textarea
                                id="bike_wash"
                                name="bike_wash"
                                class="gs-input"
                                rows="3"
                                placeholder="Cykeltvätt..."
                            ><?= htmlspecialchars($_POST['bike_wash'] ?? '') ?></textarea>
                        </div>

                        <!-- Food/Cafe -->
                        <div>
                            <label for="food_cafe" class="gs-label">
                                <i data-lucide="coffee"></i> Mat/Café
                                <label class="gs-checkbox-label gs-ml-md" style="display: inline-flex; font-weight: normal;">
                                    <input type="checkbox" name="food_use_global" class="gs-checkbox" <?= !empty($_POST['food_use_global']) ? 'checked' : '' ?>>
                                    <span class="gs-text-xs">Global</span>
                                </label>
                            </label>
                            <textarea
                                id="food_cafe"
                                name="food_cafe"
                                class="gs-input"
                                rows="3"
                                placeholder="Mat och café..."
                            ><?= htmlspecialchars($_POST['food_cafe'] ?? '') ?></textarea>
                        </div>

                        <!-- Shops -->
                        <div>
                            <label for="shops_info" class="gs-label">
                                <i data-lucide="shopping-bag"></i> Affärer
                                <label class="gs-checkbox-label gs-ml-md" style="display: inline-flex; font-weight: normal;">
                                    <input type="checkbox" name="shops_use_global" class="gs-checkbox" <?= !empty($_POST['shops_use_global']) ? 'checked' : '' ?>>
                                    <span class="gs-text-xs">Global</span>
                                </label>
                            </label>
                            <textarea
                                id="shops_info"
                                name="shops_info"
                                class="gs-input"
                                rows="3"
                                placeholder="Affärer och butiker..."
                            ><?= htmlspecialchars($_POST['shops_info'] ?? '') ?></textarea>
                        </div>

                        <!-- Exhibitors -->
                        <div>
                            <label for="exhibitors" class="gs-label">
                                <i data-lucide="tent"></i> Utställare
                                <label class="gs-checkbox-label gs-ml-md" style="display: inline-flex; font-weight: normal;">
                                    <input type="checkbox" name="exhibitors_use_global" class="gs-checkbox" <?= !empty($_POST['exhibitors_use_global']) ? 'checked' : '' ?>>
                                    <span class="gs-text-xs">Global</span>
                                </label>
                            </label>
                            <textarea
                                id="exhibitors"
                                name="exhibitors"
                                class="gs-input"
                                rows="3"
                                placeholder="Utställare på plats..."
                            ><?= htmlspecialchars($_POST['exhibitors'] ?? '') ?></textarea>
                        </div>

                        <!-- Parking (detailed) -->
                        <div>
                            <label for="parking_detailed" class="gs-label">
                                <i data-lucide="car"></i> Parkering
                                <label class="gs-checkbox-label gs-ml-md" style="display: inline-flex; font-weight: normal;">
                                    <input type="checkbox" name="parking_use_global" class="gs-checkbox" <?= !empty($_POST['parking_use_global']) ? 'checked' : '' ?>>
                                    <span class="gs-text-xs">Global</span>
                                </label>
                            </label>
                            <textarea
                                id="parking_detailed"
                                name="parking_detailed"
                                class="gs-input"
                                rows="3"
                                placeholder="Detaljerad parkeringsinformation..."
                            ><?= htmlspecialchars($_POST['parking_detailed'] ?? '') ?></textarea>
                        </div>

                        <!-- Hotel/Accommodation -->
                        <div>
                            <label for="hotel_accommodation" class="gs-label">
                                <i data-lucide="hotel"></i> Hotell och boende
                                <label class="gs-checkbox-label gs-ml-md" style="display: inline-flex; font-weight: normal;">
                                    <input type="checkbox" name="hotel_use_global" class="gs-checkbox" <?= !empty($_POST['hotel_use_global']) ? 'checked' : '' ?>>
                                    <span class="gs-text-xs">Global</span>
                                </label>
                            </label>
                            <textarea
                                id="hotel_accommodation"
                                name="hotel_accommodation"
                                class="gs-input"
                                rows="3"
                                placeholder="Hotell och boendealternativ..."
                            ><?= htmlspecialchars($_POST['hotel_accommodation'] ?? '') ?></textarea>
                        </div>

                        <!-- Local Info -->
                        <div>
                            <label for="local_info" class="gs-label">
                                <i data-lucide="landmark"></i> Lokal information
                                <label class="gs-checkbox-label gs-ml-md" style="display: inline-flex; font-weight: normal;">
                                    <input type="checkbox" name="local_use_global" class="gs-checkbox" <?= !empty($_POST['local_use_global']) ? 'checked' : '' ?>>
                                    <span class="gs-text-xs">Global</span>
                                </label>
                            </label>
                            <textarea
                                id="local_info"
                                name="local_info"
                                class="gs-input"
                                rows="3"
                                placeholder="Lokal information om orten..."
                            ><?= htmlspecialchars($_POST['local_info'] ?? '') ?></textarea>
                        </div>
                    </div>

                    <!-- RULES & SAFETY -->
                    <div class="gs-section-divider gs-mt-xl gs-mb-md">
                        <h3 class="gs-h4 gs-text-primary">Regler & Säkerhet</h3>
                    </div>

                    <div class="gs-grid gs-grid-cols-1 gs-md-grid-cols-2 gs-gap-md">
                        <!-- Competition Tracks -->
                        <div>
                            <label for="competition_tracks" class="gs-label">
                                <i data-lucide="route"></i> Tävlingssträckor
                                <label class="gs-checkbox-label gs-ml-md" style="display: inline-flex; font-weight: normal;">
                                    <input type="checkbox" name="tracks_use_global" class="gs-checkbox" <?= !empty($_POST['tracks_use_global']) ? 'checked' : '' ?>>
                                    <span class="gs-text-xs">Global</span>
                                </label>
                            </label>
                            <textarea
                                id="competition_tracks"
                                name="competition_tracks"
                                class="gs-input"
                                rows="3"
                                placeholder="Beskrivning av tävlingssträckor..."
                            ><?= htmlspecialchars($_POST['competition_tracks'] ?? '') ?></textarea>
                        </div>

                        <!-- Competition Rules -->
                        <div>
                            <label for="competition_rules" class="gs-label">
                                <i data-lucide="scale"></i> Tävlingsregler
                                <label class="gs-checkbox-label gs-ml-md" style="display: inline-flex; font-weight: normal;">
                                    <input type="checkbox" name="rules_use_global" class="gs-checkbox" <?= !empty($_POST['rules_use_global']) ? 'checked' : '' ?>>
                                    <span class="gs-text-xs">Global</span>
                                </label>
                            </label>
                            <textarea
                                id="competition_rules"
                                name="competition_rules"
                                class="gs-input"
                                rows="3"
                                placeholder="Tävlingsregler..."
                            ><?= htmlspecialchars($_POST['competition_rules'] ?? '') ?></textarea>
                        </div>

                        <!-- Insurance -->
                        <div>
                            <label for="insurance_info" class="gs-label">
                                <i data-lucide="shield-check"></i> Försäkring
                                <label class="gs-checkbox-label gs-ml-md" style="display: inline-flex; font-weight: normal;">
                                    <input type="checkbox" name="insurance_use_global" class="gs-checkbox" <?= !empty($_POST['insurance_use_global']) ? 'checked' : '' ?>>
                                    <span class="gs-text-xs">Global</span>
                                </label>
                            </label>
                            <textarea
                                id="insurance_info"
                                name="insurance_info"
                                class="gs-input"
                                rows="3"
                                placeholder="Försäkringsinformation..."
                            ><?= htmlspecialchars($_POST['insurance_info'] ?? '') ?></textarea>
                        </div>

                        <!-- Equipment -->
                        <div>
                            <label for="equipment_info" class="gs-label">
                                <i data-lucide="hard-hat"></i> Utrustning
                                <label class="gs-checkbox-label gs-ml-md" style="display: inline-flex; font-weight: normal;">
                                    <input type="checkbox" name="equipment_use_global" class="gs-checkbox" <?= !empty($_POST['equipment_use_global']) ? 'checked' : '' ?>>
                                    <span class="gs-text-xs">Global</span>
                                </label>
                            </label>
                            <textarea
                                id="equipment_info"
                                name="equipment_info"
                                class="gs-input"
                                rows="3"
                                placeholder="Utrustningskrav..."
                            ><?= htmlspecialchars($_POST['equipment_info'] ?? '') ?></textarea>
                        </div>
                    </div>

                    <!-- CONTACTS & INFORMATION -->
                    <div class="gs-section-divider gs-mt-xl gs-mb-md">
                        <h3 class="gs-h4 gs-text-primary">Kontakter & Övrig Information</h3>
                    </div>

                    <div class="gs-grid gs-grid-cols-1 gs-md-grid-cols-2 gs-gap-md">
                        <!-- Entry Fees (detailed) -->
                        <div>
                            <label for="entry_fees_detailed" class="gs-label">
                                <i data-lucide="banknote"></i> Startavgifter
                                <label class="gs-checkbox-label gs-ml-md" style="display: inline-flex; font-weight: normal;">
                                    <input type="checkbox" name="fees_use_global" class="gs-checkbox" <?= !empty($_POST['fees_use_global']) ? 'checked' : '' ?>>
                                    <span class="gs-text-xs">Global</span>
                                </label>
                            </label>
                            <textarea
                                id="entry_fees_detailed"
                                name="entry_fees_detailed"
                                class="gs-input"
                                rows="3"
                                placeholder="Detaljerad avgiftsinformation per klass..."
                            ><?= htmlspecialchars($_POST['entry_fees_detailed'] ?? '') ?></textarea>
                        </div>

                        <!-- Results Info -->
                        <div>
                            <label for="results_info" class="gs-label">
                                <i data-lucide="trophy"></i> Resultatinformation
                                <label class="gs-checkbox-label gs-ml-md" style="display: inline-flex; font-weight: normal;">
                                    <input type="checkbox" name="results_use_global" class="gs-checkbox" <?= !empty($_POST['results_use_global']) ? 'checked' : '' ?>>
                                    <span class="gs-text-xs">Global</span>
                                </label>
                            </label>
                            <textarea
                                id="results_info"
                                name="results_info"
                                class="gs-input"
                                rows="3"
                                placeholder="Hur resultat publiceras..."
                            ><?= htmlspecialchars($_POST['results_info'] ?? '') ?></textarea>
                        </div>

                        <!-- Medical Info -->
                        <div>
                            <label for="medical_info" class="gs-label">
                                <i data-lucide="heart-pulse"></i> Sjukvård
                                <label class="gs-checkbox-label gs-ml-md" style="display: inline-flex; font-weight: normal;">
                                    <input type="checkbox" name="medical_use_global" class="gs-checkbox" <?= !empty($_POST['medical_use_global']) ? 'checked' : '' ?>>
                                    <span class="gs-text-xs">Global</span>
                                </label>
                            </label>
                            <textarea
                                id="medical_info"
                                name="medical_info"
                                class="gs-input"
                                rows="3"
                                placeholder="Sjukvårdsinformation..."
                            ><?= htmlspecialchars($_POST['medical_info'] ?? '') ?></textarea>
                        </div>

                        <!-- Media Production -->
                        <div>
                            <label for="media_production" class="gs-label">
                                <i data-lucide="video"></i> Mediaproduktion
                                <label class="gs-checkbox-label gs-ml-md" style="display: inline-flex; font-weight: normal;">
                                    <input type="checkbox" name="media_use_global" class="gs-checkbox" <?= !empty($_POST['media_use_global']) ? 'checked' : '' ?>>
                                    <span class="gs-text-xs">Global</span>
                                </label>
                            </label>
                            <textarea
                                id="media_production"
                                name="media_production"
                                class="gs-input"
                                rows="3"
                                placeholder="Foto och video..."
                            ><?= htmlspecialchars($_POST['media_production'] ?? '') ?></textarea>
                        </div>

                        <!-- Contacts Info -->
                        <div>
                            <label for="contacts_info" class="gs-label">
                                <i data-lucide="contact"></i> Kontakter (detaljerad)
                                <label class="gs-checkbox-label gs-ml-md" style="display: inline-flex; font-weight: normal;">
                                    <input type="checkbox" name="contacts_use_global" class="gs-checkbox" <?= !empty($_POST['contacts_use_global']) ? 'checked' : '' ?>>
                                    <span class="gs-text-xs">Global</span>
                                </label>
                            </label>
                            <textarea
                                id="contacts_info"
                                name="contacts_info"
                                class="gs-input"
                                rows="3"
                                placeholder="Kontaktpersoner..."
                            ><?= htmlspecialchars($_POST['contacts_info'] ?? '') ?></textarea>
                        </div>

                        <!-- SCF Representatives -->
                        <div>
                            <label for="scf_representatives" class="gs-label">
                                <i data-lucide="badge"></i> SCF Representanter
                                <label class="gs-checkbox-label gs-ml-md" style="display: inline-flex; font-weight: normal;">
                                    <input type="checkbox" name="scf_use_global" class="gs-checkbox" <?= !empty($_POST['scf_use_global']) ? 'checked' : '' ?>>
                                    <span class="gs-text-xs">Global</span>
                                </label>
                            </label>
                            <textarea
                                id="scf_representatives"
                                name="scf_representatives"
                                class="gs-input"
                                rows="3"
                                placeholder="SCF-representanter..."
                            ><?= htmlspecialchars($_POST['scf_representatives'] ?? '') ?></textarea>
                        </div>
                    </div>

                    <!-- Active Status -->
                    <div class="gs-mt-lg">
                        <label class="gs-checkbox-label">
                            <input
                                type="checkbox"
                                name="active"
                                class="gs-checkbox"
                                checked
                            >
                            <span>
                                <i data-lucide="check-circle"></i>
                                Aktivt event
                            </span>
                        </label>
                    </div>
                </div>

                <!-- Footer -->
                <div class="gs-flex gs-justify-end gs-gap-md gs-mt-lg">
                    <a href="/admin/events.php" class="gs-btn gs-btn-outline">
                        Avbryt
                    </a>
                    <button type="submit" class="gs-btn gs-btn-primary">
                        <i data-lucide="check"></i>
                        Skapa Event
                    </button>
                </div>
            </form>
        </div>
    </div>
</main>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>

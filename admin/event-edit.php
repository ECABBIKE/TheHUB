<?php
/**
 * Admin Event Edit - V3 Unified Design System
 */
require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();

// Get event ID from URL (supports both /admin/events/edit/123 and ?id=123)
$id = 0;
if (isset($_GET['id'])) {
    $id = intval($_GET['id']);
} else {
    // Check for pretty URL format
    $uri = $_SERVER['REQUEST_URI'];
    if (preg_match('#/admin/events/edit/(\d+)#', $uri, $matches)) {
        $id = intval($matches[1]);
    }
}

if ($id <= 0) {
    $_SESSION['message'] = 'Ogiltigt event-ID';
    $_SESSION['messageType'] = 'error';
    header('Location: /admin/events');
    exit;
}

// Fetch event data
$event = $db->getRow("SELECT * FROM events WHERE id = ?", [$id]);

if (!$event) {
    $_SESSION['message'] = 'Event hittades inte';
    $_SESSION['messageType'] = 'error';
    header('Location: /admin/events');
    exit;
}

// Initialize message variables
$message = '';
$messageType = 'info';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();

    $name = trim($_POST['name'] ?? '');
    $date = trim($_POST['date'] ?? '');

    if (empty($name) || empty($date)) {
        $message = 'Namn och datum är obligatoriska';
        $messageType = 'error';
    } else {
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
            'organizer_club_id' => !empty($_POST['organizer_club_id']) ? intval($_POST['organizer_club_id']) : null,
            'organizer' => '', // Keep for backwards compatibility
            'website' => trim($_POST['website'] ?? ''),
            'registration_deadline' => !empty($_POST['registration_deadline']) ? trim($_POST['registration_deadline']) : null,
            'active' => isset($_POST['active']) ? 1 : 0,
            'is_championship' => isset($_POST['is_championship']) ? 1 : 0,
            'contact_email' => trim($_POST['contact_email'] ?? ''),
            'contact_phone' => trim($_POST['contact_phone'] ?? ''),
            // Extended fields
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
            header('Location: /admin/events');
            exit;
        } catch (Exception $e) {
            $message = 'Ett fel uppstod: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}

// Fetch data for dropdowns
$series = $db->getAll("SELECT id, name FROM series WHERE status = 'active' ORDER BY name");
$venues = $db->getAll("SELECT id, name, city FROM venues WHERE active = 1 ORDER BY name");
$clubs = $db->getAll("SELECT id, name, city FROM clubs WHERE active = 1 ORDER BY name");
$pricingTemplates = $db->getAll("SELECT id, name, is_default FROM pricing_templates ORDER BY is_default DESC, name ASC");

// Fetch global texts
$globalTexts = $db->getAll("SELECT field_key, content FROM global_texts WHERE is_active = 1");
$globalTextMap = [];
foreach ($globalTexts as $gt) {
    $globalTextMap[$gt['field_key']] = $gt['content'];
}

// Page config
$page_title = 'Redigera Event';
$breadcrumbs = [
    ['label' => 'Events', 'url' => '/admin/events'],
    ['label' => htmlspecialchars($event['name'])]
];

include __DIR__ . '/components/unified-layout.php';
?>

<?php if ($message): ?>
    <div class="alert alert-<?= $messageType === 'success' ? 'success' : ($messageType === 'error' ? 'error' : 'info') ?>" style="margin-bottom: var(--space-lg);">
        <?= htmlspecialchars($message) ?>
    </div>
<?php endif; ?>

<form method="POST">
    <?= csrf_field() ?>

    <!-- BASIC INFO -->
    <div class="admin-card" style="margin-bottom: var(--space-lg);">
        <div class="admin-card-header">
            <h2>Grundläggande information</h2>
        </div>
        <div class="admin-card-body">
            <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: var(--space-md);">
                <div class="admin-form-group" style="grid-column: span 2;">
                    <label class="admin-form-label">Namn <span style="color: var(--color-error);">*</span></label>
                    <input type="text" name="name" class="admin-form-input" required value="<?= h($event['name']) ?>">
                </div>

                <div class="admin-form-group">
                    <label class="admin-form-label">Datum <span style="color: var(--color-error);">*</span></label>
                    <input type="date" name="date" class="admin-form-input" required value="<?= h($event['date']) ?>">
                </div>

                <div class="admin-form-group">
                    <label class="admin-form-label">Advent ID</label>
                    <input type="text" name="advent_id" class="admin-form-input" value="<?= h($event['advent_id'] ?? '') ?>" placeholder="Externt ID för import">
                </div>

                <div class="admin-form-group">
                    <label class="admin-form-label">Plats</label>
                    <input type="text" name="location" class="admin-form-input" value="<?= h($event['location'] ?? '') ?>" placeholder="T.ex. Järvsö">
                </div>

                <div class="admin-form-group">
                    <label class="admin-form-label">Bana/Anläggning</label>
                    <select name="venue_id" class="admin-form-select">
                        <option value="">Ingen specifik bana</option>
                        <?php foreach ($venues as $venue): ?>
                            <option value="<?= $venue['id'] ?>" <?= ($event['venue_id'] == $venue['id']) ? 'selected' : '' ?>>
                                <?= h($venue['name']) ?><?php if ($venue['city']): ?> (<?= h($venue['city']) ?>)<?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <!-- COMPETITION SETTINGS -->
    <div class="admin-card" style="margin-bottom: var(--space-lg);">
        <div class="admin-card-header">
            <h2>Tävlingsinställningar</h2>
        </div>
        <div class="admin-card-body">
            <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: var(--space-md);">
                <div class="admin-form-group">
                    <label class="admin-form-label">Tävlingsformat</label>
                    <select name="discipline" class="admin-form-select">
                        <option value="">Välj format...</option>
                        <option value="ENDURO" <?= ($event['discipline'] ?? '') === 'ENDURO' ? 'selected' : '' ?>>Enduro</option>
                        <option value="DH" <?= ($event['discipline'] ?? '') === 'DH' ? 'selected' : '' ?>>Downhill</option>
                        <option value="XC" <?= ($event['discipline'] ?? '') === 'XC' ? 'selected' : '' ?>>XC</option>
                        <option value="XCO" <?= ($event['discipline'] ?? '') === 'XCO' ? 'selected' : '' ?>>XCO</option>
                        <option value="DUAL_SLALOM" <?= ($event['discipline'] ?? '') === 'DUAL_SLALOM' ? 'selected' : '' ?>>Dual Slalom</option>
                        <option value="PUMPTRACK" <?= ($event['discipline'] ?? '') === 'PUMPTRACK' ? 'selected' : '' ?>>Pumptrack</option>
                        <option value="GRAVEL" <?= ($event['discipline'] ?? '') === 'GRAVEL' ? 'selected' : '' ?>>Gravel</option>
                        <option value="E-MTB" <?= ($event['discipline'] ?? '') === 'E-MTB' ? 'selected' : '' ?>>E-MTB</option>
                    </select>
                </div>

                <div class="admin-form-group">
                    <label class="admin-form-label">Serie</label>
                    <select name="series_id" class="admin-form-select">
                        <option value="">Ingen serie</option>
                        <?php foreach ($series as $s): ?>
                            <option value="<?= $s['id'] ?>" <?= ($event['series_id'] == $s['id']) ? 'selected' : '' ?>>
                                <?= h($s['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="admin-form-group">
                    <label class="admin-form-label">Rankingklass</label>
                    <select name="event_level" class="admin-form-select">
                        <option value="national" <?= ($event['event_level'] ?? 'national') === 'national' ? 'selected' : '' ?>>Nationell (100%)</option>
                        <option value="sportmotion" <?= ($event['event_level'] ?? 'national') === 'sportmotion' ? 'selected' : '' ?>>Sportmotion (50%)</option>
                    </select>
                    <small style="color: var(--color-text-secondary);">Styr rankingpoäng</small>
                </div>

                <div class="admin-form-group">
                    <label class="admin-form-label">Event-format</label>
                    <select name="event_format" class="admin-form-select">
                        <option value="ENDURO" <?= ($event['event_format'] ?? 'ENDURO') === 'ENDURO' ? 'selected' : '' ?>>Enduro (en tid)</option>
                        <option value="DH_STANDARD" <?= ($event['event_format'] ?? '') === 'DH_STANDARD' ? 'selected' : '' ?>>Downhill Standard</option>
                        <option value="DH_SWECUP" <?= ($event['event_format'] ?? '') === 'DH_SWECUP' ? 'selected' : '' ?>>SweCUP Downhill</option>
                        <option value="DUAL_SLALOM" <?= ($event['event_format'] ?? '') === 'DUAL_SLALOM' ? 'selected' : '' ?>>Dual Slalom</option>
                    </select>
                </div>

                <div class="admin-form-group">
                    <label class="admin-form-label">Prismall</label>
                    <select name="pricing_template_id" class="admin-form-select">
                        <option value="">Ingen prismall</option>
                        <?php foreach ($pricingTemplates as $template): ?>
                            <option value="<?= $template['id'] ?>" <?= ($event['pricing_template_id'] ?? '') == $template['id'] ? 'selected' : '' ?>>
                                <?= h($template['name']) ?><?php if ($template['is_default']): ?> (Standard)<?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="admin-form-group">
                    <label class="admin-form-label">Distans (km)</label>
                    <input type="number" name="distance" class="admin-form-input" step="0.01" min="0" value="<?= h($event['distance'] ?? '') ?>">
                </div>

                <div class="admin-form-group">
                    <label class="admin-form-label">Höjdmeter (m)</label>
                    <input type="number" name="elevation_gain" class="admin-form-input" min="0" value="<?= h($event['elevation_gain'] ?? '') ?>">
                </div>
            </div>

            <div class="admin-form-group" style="margin-top: var(--space-md);">
                <label class="admin-form-label">Sträcknamn (JSON)</label>
                <input type="text" name="stage_names" class="admin-form-input" value="<?= h($event['stage_names'] ?? '') ?>" placeholder='{"1":"SS1","2":"SS2","3":"SS3"}'>
                <small style="color: var(--color-text-secondary);">Anpassade namn. Lämna tomt för standard.</small>
            </div>
        </div>
    </div>

    <!-- ORGANIZER & CONTACT -->
    <div class="admin-card" style="margin-bottom: var(--space-lg);">
        <div class="admin-card-header">
            <h2>Arrangör & Kontakt</h2>
        </div>
        <div class="admin-card-body">
            <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: var(--space-md);">
                <div class="admin-form-group">
                    <label class="admin-form-label">Arrangör (klubb)</label>
                    <select name="organizer_club_id" class="admin-form-select">
                        <option value="">Välj klubb...</option>
                        <?php foreach ($clubs as $club): ?>
                            <option value="<?= $club['id'] ?>" <?= ($event['organizer_club_id'] ?? '') == $club['id'] ? 'selected' : '' ?>>
                                <?= h($club['name']) ?><?php if ($club['city']): ?> (<?= h($club['city']) ?>)<?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="admin-form-group">
                    <label class="admin-form-label">Webbplats</label>
                    <input type="url" name="website" class="admin-form-input" value="<?= h($event['website'] ?? '') ?>" placeholder="https://...">
                </div>

                <div class="admin-form-group">
                    <label class="admin-form-label">Kontakt e-post</label>
                    <input type="email" name="contact_email" class="admin-form-input" value="<?= h($event['contact_email'] ?? '') ?>">
                </div>

                <div class="admin-form-group">
                    <label class="admin-form-label">Kontakt telefon</label>
                    <input type="tel" name="contact_phone" class="admin-form-input" value="<?= h($event['contact_phone'] ?? '') ?>">
                </div>

                <div class="admin-form-group">
                    <label class="admin-form-label">Anmälningsfrist</label>
                    <input type="date" name="registration_deadline" class="admin-form-input" value="<?= h($event['registration_deadline'] ?? '') ?>">
                </div>
            </div>
        </div>
    </div>

    <!-- LOCATION DETAILS -->
    <div class="admin-card" style="margin-bottom: var(--space-lg);">
        <div class="admin-card-header">
            <h2>Platsdetaljer</h2>
        </div>
        <div class="admin-card-body">
            <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: var(--space-md);">
                <div class="admin-form-group">
                    <label class="admin-form-label">GPS-koordinater</label>
                    <input type="text" name="venue_coordinates" class="admin-form-input" value="<?= h($event['venue_coordinates'] ?? '') ?>" placeholder="59.3293, 18.0686">
                </div>

                <div class="admin-form-group">
                    <label class="admin-form-label">Google Maps URL</label>
                    <input type="url" name="venue_map_url" class="admin-form-input" value="<?= h($event['venue_map_url'] ?? '') ?>">
                </div>

                <div class="admin-form-group" style="grid-column: span 2;">
                    <label class="admin-form-label">Platsdetaljer</label>
                    <textarea name="venue_details" class="admin-form-input" rows="3"><?= h($event['venue_details'] ?? '') ?></textarea>
                </div>
            </div>
        </div>
    </div>

    <!-- EXTENDED CONTENT - Collapsible -->
    <details class="admin-card" style="margin-bottom: var(--space-lg);">
        <summary class="admin-card-header" style="cursor: pointer; user-select: none;">
            <h2>Event-flikar Information</h2>
            <span style="color: var(--color-text-secondary); font-size: var(--text-sm);">Klicka för att expandera</span>
        </summary>
        <div class="admin-card-body">
            <p style="color: var(--color-text-secondary); font-size: var(--text-sm); margin-bottom: var(--space-lg);">
                Innehåll för event-sidans flikar. Markera "Global" för att använda standardtext.
            </p>

            <?php
            $extendedFields = [
                ['key' => 'pm_content', 'label' => 'PM (Promemoria)', 'global_key' => 'pm_use_global'],
                ['key' => 'jury_communication', 'label' => 'Jurykommuniké', 'global_key' => 'jury_use_global'],
                ['key' => 'competition_schedule', 'label' => 'Tävlingsschema', 'global_key' => 'schedule_use_global'],
                ['key' => 'start_times', 'label' => 'Starttider', 'global_key' => 'start_times_use_global'],
                ['key' => 'map_content', 'label' => 'Karta', 'global_key' => 'map_use_global'],
                ['key' => 'driver_meeting', 'label' => 'Förarmöte', 'global_key' => 'driver_meeting_use_global'],
                ['key' => 'training_info', 'label' => 'Träning', 'global_key' => 'training_use_global'],
                ['key' => 'timing_info', 'label' => 'Tidtagning', 'global_key' => 'timing_use_global'],
                ['key' => 'lift_info', 'label' => 'Lift', 'global_key' => 'lift_use_global'],
                ['key' => 'competition_tracks', 'label' => 'Tävlingssträckor', 'global_key' => 'tracks_use_global'],
                ['key' => 'competition_rules', 'label' => 'Tävlingsregler', 'global_key' => 'rules_use_global'],
                ['key' => 'insurance_info', 'label' => 'Försäkring', 'global_key' => 'insurance_use_global'],
                ['key' => 'equipment_info', 'label' => 'Utrustning', 'global_key' => 'equipment_use_global'],
            ];
            ?>

            <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: var(--space-md);">
                <?php foreach ($extendedFields as $field): ?>
                    <div class="admin-form-group">
                        <label class="admin-form-label">
                            <?= $field['label'] ?>
                            <label style="display: inline-flex; align-items: center; gap: 4px; margin-left: var(--space-sm); font-weight: normal; cursor: pointer;">
                                <input type="checkbox" name="<?= $field['global_key'] ?>" <?= !empty($event[$field['global_key']]) ? 'checked' : '' ?>>
                                <span style="font-size: var(--text-xs);">Global</span>
                            </label>
                        </label>
                        <textarea name="<?= $field['key'] ?>" class="admin-form-input" rows="3"><?= h($event[$field['key']] ?? '') ?></textarea>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </details>

    <!-- FACILITIES - Collapsible -->
    <details class="admin-card" style="margin-bottom: var(--space-lg);">
        <summary class="admin-card-header" style="cursor: pointer; user-select: none;">
            <h2>Faciliteter & Logistik</h2>
            <span style="color: var(--color-text-secondary); font-size: var(--text-sm);">Klicka för att expandera</span>
        </summary>
        <div class="admin-card-body">
            <?php
            $facilityFields = [
                ['key' => 'hydration_stations', 'label' => 'Vätskekontroller', 'global_key' => 'hydration_use_global'],
                ['key' => 'toilets_showers', 'label' => 'Toaletter/Dusch', 'global_key' => 'toilets_use_global'],
                ['key' => 'bike_wash', 'label' => 'Cykeltvätt', 'global_key' => 'bike_wash_use_global'],
                ['key' => 'food_cafe', 'label' => 'Mat/Café', 'global_key' => 'food_use_global'],
                ['key' => 'shops_info', 'label' => 'Affärer', 'global_key' => 'shops_use_global'],
                ['key' => 'exhibitors', 'label' => 'Utställare', 'global_key' => 'exhibitors_use_global'],
                ['key' => 'parking_detailed', 'label' => 'Parkering', 'global_key' => 'parking_use_global'],
                ['key' => 'hotel_accommodation', 'label' => 'Hotell/Boende', 'global_key' => 'hotel_use_global'],
                ['key' => 'local_info', 'label' => 'Lokal information', 'global_key' => 'local_use_global'],
                ['key' => 'entry_fees_detailed', 'label' => 'Startavgifter', 'global_key' => 'fees_use_global'],
                ['key' => 'results_info', 'label' => 'Resultatinfo', 'global_key' => 'results_use_global'],
                ['key' => 'medical_info', 'label' => 'Sjukvård', 'global_key' => 'medical_use_global'],
                ['key' => 'media_production', 'label' => 'Media', 'global_key' => 'media_use_global'],
                ['key' => 'contacts_info', 'label' => 'Kontakter', 'global_key' => 'contacts_use_global'],
                ['key' => 'scf_representatives', 'label' => 'SCF Representanter', 'global_key' => 'scf_use_global'],
            ];
            ?>

            <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: var(--space-md);">
                <?php foreach ($facilityFields as $field): ?>
                    <div class="admin-form-group">
                        <label class="admin-form-label">
                            <?= $field['label'] ?>
                            <label style="display: inline-flex; align-items: center; gap: 4px; margin-left: var(--space-sm); font-weight: normal; cursor: pointer;">
                                <input type="checkbox" name="<?= $field['global_key'] ?>" <?= !empty($event[$field['global_key']]) ? 'checked' : '' ?>>
                                <span style="font-size: var(--text-xs);">Global</span>
                            </label>
                        </label>
                        <textarea name="<?= $field['key'] ?>" class="admin-form-input" rows="2"><?= h($event[$field['key']] ?? '') ?></textarea>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </details>

    <!-- STATUS & ACTIONS -->
    <div class="admin-card">
        <div class="admin-card-body">
            <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: var(--space-md);">
                <label style="display: flex; align-items: center; gap: var(--space-sm); cursor: pointer;">
                    <input type="checkbox" name="active" <?= $event['active'] ? 'checked' : '' ?>>
                    <span>Aktivt event</span>
                </label>

                <label style="display: flex; align-items: center; gap: var(--space-sm); cursor: pointer;">
                    <input type="checkbox" name="is_championship" <?= !empty($event['is_championship']) ? 'checked' : '' ?>>
                    <span>🏆 Svenskt Mästerskap</span>
                </label>

                <div style="display: flex; gap: var(--space-sm);">
                    <a href="/admin/events" class="btn-admin btn-admin-secondary">Avbryt</a>
                    <button type="submit" class="btn-admin btn-admin-primary">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 16px; height: 16px;"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                        Spara Ändringar
                    </button>
                </div>
            </div>
        </div>
    </div>
</form>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>

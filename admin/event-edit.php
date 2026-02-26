<?php
/**
 * Admin Event Edit - V3 Unified Design System
 */
require_once __DIR__ . '/../config.php';
require_admin();

$db = getDB();

// Check column status for debugging
$columnStatus = 'unknown';
$columnInfo = [];

// Ensure is_championship column exists using SHOW COLUMNS (more reliable)
try {
    $columns = $db->getAll("SHOW COLUMNS FROM events LIKE 'is_championship'");
    if (empty($columns)) {
        $result = $db->query("ALTER TABLE events ADD COLUMN is_championship TINYINT(1) NOT NULL DEFAULT 0");
        $columnStatus = $result ? 'created' : 'create_failed';
        error_log("EVENT EDIT: Added is_championship column to events table");
    } else {
        $columnStatus = 'exists';
        $columnInfo = $columns[0];
    }
} catch (Exception $e) {
    $columnStatus = 'error: ' . $e->getMessage();
    error_log("EVENT EDIT: Error checking/adding is_championship column: " . $e->getMessage());
}


// Check/create header_banner_media_id column
$headerBannerColumnExists = false;
try {
    $columns = $db->getAll("SHOW COLUMNS FROM events LIKE 'header_banner_media_id'");
    if (empty($columns)) {
        $db->query("ALTER TABLE events ADD COLUMN header_banner_media_id INT NULL");
        error_log("EVENT EDIT: Added header_banner_media_id column to events table");
    }
    $headerBannerColumnExists = true;
} catch (Exception $e) {
    error_log("EVENT EDIT: Error checking/adding header_banner_media_id column: " . $e->getMessage());
}

// Check/create pricing_template_id column
try {
    $columns = $db->getAll("SHOW COLUMNS FROM events LIKE 'pricing_template_id'");
    if (empty($columns)) {
        $db->query("ALTER TABLE events ADD COLUMN pricing_template_id INT NULL");
        error_log("EVENT EDIT: Added pricing_template_id column to events table");
    }
} catch (Exception $e) {
    error_log("EVENT EDIT: Error checking/adding pricing_template_id column: " . $e->getMessage());
}

// Check/create extended event fields (logo, end_date, formats, event_type)
try {
    $columns = $db->getAll("SHOW COLUMNS FROM events LIKE 'logo_media_id'");
    if (empty($columns)) {
        $db->query("ALTER TABLE events ADD COLUMN logo VARCHAR(255) NULL");
        $db->query("ALTER TABLE events ADD COLUMN logo_media_id INT NULL");
        error_log("EVENT EDIT: Added logo columns to events table");
    }
} catch (Exception $e) {
    error_log("EVENT EDIT: Error checking/adding logo columns: " . $e->getMessage());
}

try {
    $columns = $db->getAll("SHOW COLUMNS FROM events LIKE 'end_date'");
    if (empty($columns)) {
        $db->query("ALTER TABLE events ADD COLUMN end_date DATE NULL AFTER date");
        error_log("EVENT EDIT: Added end_date column to events table");
    }
} catch (Exception $e) {
    error_log("EVENT EDIT: Error checking/adding end_date column: " . $e->getMessage());
}

try {
    $columns = $db->getAll("SHOW COLUMNS FROM events LIKE 'formats'");
    if (empty($columns)) {
        $db->query("ALTER TABLE events ADD COLUMN formats VARCHAR(500) NULL");
        error_log("EVENT EDIT: Added formats column to events table");
    }
} catch (Exception $e) {
    error_log("EVENT EDIT: Error checking/adding formats column: " . $e->getMessage());
}

try {
    $columns = $db->getAll("SHOW COLUMNS FROM events LIKE 'event_type'");
    if (empty($columns)) {
        $db->query("ALTER TABLE events ADD COLUMN event_type VARCHAR(50) DEFAULT 'single'");
        error_log("EVENT EDIT: Added event_type column to events table");
    }
} catch (Exception $e) {
    error_log("EVENT EDIT: Error checking/adding event_type column: " . $e->getMessage());
}

// Get media files from events folder for banner selection
$eventMediaFiles = [];
try {
    global $pdo;
    $stmt = $pdo->prepare("SELECT id, filename, original_filename, filepath FROM media WHERE folder = 'events' ORDER BY uploaded_at DESC");
    $stmt->execute();
    $eventMediaFiles = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("EVENT EDIT: Error fetching event media: " . $e->getMessage());
}

// Get media files from series folder for logo selection
$seriesMediaFiles = [];
try {
    $stmt = $pdo->prepare("SELECT id, filename, original_filename, filepath FROM media WHERE folder = 'series' ORDER BY uploaded_at DESC");
    $stmt->execute();
    $seriesMediaFiles = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("EVENT EDIT: Error fetching series media: " . $e->getMessage());
}

// Check/create _hidden columns for event content fields
$hiddenColumns = [
    'invitation_hidden', 'hydration_hidden', 'toilets_hidden', 'bike_wash_hidden',
    'food_hidden', 'shops_hidden', 'exhibitors_hidden', 'parking_hidden',
    'hotel_hidden', 'local_hidden', 'media_hidden', 'contacts_hidden',
    'pm_hidden', 'driver_meeting_hidden', 'training_hidden', 'timing_hidden',
    'lift_hidden', 'rules_hidden', 'insurance_hidden', 'equipment_hidden',
    'medical_hidden', 'scf_hidden', 'jury_hidden', 'schedule_hidden', 'start_times_hidden'
];
try {
    foreach ($hiddenColumns as $col) {
        $columns = $db->getAll("SHOW COLUMNS FROM events LIKE ?", [$col]);
        if (empty($columns)) {
            $db->query("ALTER TABLE events ADD COLUMN {$col} TINYINT(1) NOT NULL DEFAULT 0");
            error_log("EVENT EDIT: Added {$col} column to events table");
        }
    }
} catch (Exception $e) {
    error_log("EVENT EDIT: Error checking/adding hidden columns: " . $e->getMessage());
}

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

// Load info links for this event per section (migration 057+058)
// Dynamic: any section string is supported - no hardcoded list needed
$eventInfoLinks = [];
try {
    $allLinks = $db->getAll("SELECT id, section, link_url, link_text, sort_order FROM event_info_links WHERE event_id = ? ORDER BY sort_order, id", [$id]);
    foreach ($allLinks as $link) {
        $sec = $link['section'] ?? 'general';
        $eventInfoLinks[$sec][] = $link;
    }
} catch (Exception $e) {
    // Table may not exist yet - fall back to single-link columns
    $singleUrl = $event['general_competition_link_url'] ?? '';
    if (!empty($singleUrl)) {
        $eventInfoLinks['general'][] = ['id' => 0, 'link_url' => $singleUrl, 'link_text' => $event['general_competition_link_text'] ?? '', 'sort_order' => 0];
    }
}

// Check if user is promotor (can only edit certain sections)
$isPromotorOnly = function_exists('isRole') && isRole('promotor');

// If promotor, verify they have access to this event
if ($isPromotorOnly) {
    $canAccess = function_exists('canAccessEvent') && canAccessEvent($id);
    if (!$canAccess) {
        $_SESSION['message'] = 'Du har inte behörighet att redigera detta event';
        $_SESSION['messageType'] = 'error';
        header('Location: /admin/events');
        exit;
    }
}

// Function to save sponsor assignments for events
function saveEventSponsorAssignments($db, $eventId, $postData) {
    $pdo = $db->getPdo();

    error_log("saveEventSponsorAssignments called for event $eventId");
    error_log("POST data: sponsor_header=" . ($postData['sponsor_header'] ?? 'empty') . ", sponsor_content=" . json_encode($postData['sponsor_content'] ?? []) . ", sponsor_sidebar=" . ($postData['sponsor_sidebar'] ?? 'empty'));

    // CRITICAL FIX: Ensure placement ENUM includes all values
    try {
        $pdo->exec("ALTER TABLE event_sponsors MODIFY COLUMN placement ENUM('header', 'sidebar', 'footer', 'content', 'partner') DEFAULT 'sidebar'");
        $pdo->exec("ALTER TABLE series_sponsors MODIFY COLUMN placement ENUM('header', 'sidebar', 'footer', 'content', 'partner') DEFAULT 'sidebar'");
    } catch (Exception $e) {
        // May already be correct
    }

    // First, delete existing sponsor assignments for this event
    $deleteStmt = $pdo->prepare("DELETE FROM event_sponsors WHERE event_id = ?");
    $deleteStmt->execute([$eventId]);
    error_log("Deleted existing sponsor assignments for event $eventId");

    // Insert new assignments
    $insertStmt = $pdo->prepare("INSERT INTO event_sponsors (event_id, sponsor_id, placement, display_order) VALUES (?, ?, ?, ?)");

    $insertedCount = 0;

    // Header sponsors (banner at top - single select)
    if (!empty($postData['sponsor_header'])) {
        $insertStmt->execute([$eventId, (int)$postData['sponsor_header'], 'header', 0]);
        $insertedCount++;
        error_log("Inserted header sponsor: " . $postData['sponsor_header']);
    }

    // Content sponsors (logo row - multiple checkboxes)
    if (!empty($postData['sponsor_content']) && is_array($postData['sponsor_content'])) {
        $order = 0;
        foreach ($postData['sponsor_content'] as $sponsorId) {
            $insertStmt->execute([$eventId, (int)$sponsorId, 'content', $order++]);
            $insertedCount++;
            error_log("Inserted content sponsor: $sponsorId");
        }
    }

    // Sidebar/Results sponsor (single select)
    if (!empty($postData['sponsor_sidebar'])) {
        $insertStmt->execute([$eventId, (int)$postData['sponsor_sidebar'], 'sidebar', 0]);
        $insertedCount++;
    }

    // Partner sponsors (bottom logo row - unlimited)
    if (!empty($postData['sponsor_partner']) && is_array($postData['sponsor_partner'])) {
        $order = 0;
        foreach ($postData['sponsor_partner'] as $sponsorId) {
            $insertStmt->execute([$eventId, (int)$sponsorId, 'partner', $order++]);
            $insertedCount++;
        }
    }

    // VERIFY: Check what was actually saved
    $verifyStmt = $pdo->prepare("SELECT sponsor_id, placement FROM event_sponsors WHERE event_id = ?");
    $verifyStmt->execute([$eventId]);
    $savedSponsors = $verifyStmt->fetchAll(PDO::FETCH_ASSOC);
    error_log("VERIFY: Actually saved sponsors: " . json_encode($savedSponsors));
}

// Initialize message variables
$message = '';
$messageType = 'info';

// Check if we just saved
if (isset($_GET['saved']) && $_GET['saved'] == '1' && isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    $messageType = $_SESSION['messageType'] ?? 'success';
    unset($_SESSION['message'], $_SESSION['messageType']);
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();

    $name = trim($_POST['name'] ?? '');
    $date = trim($_POST['date'] ?? '');

    if (empty($name) || empty($date)) {
        $message = 'Namn och datum är obligatoriska';
        $messageType = 'error';
    } else {
        // Process formats (multiple disciplines) - convert array to comma-separated string
        $formats = '';
        if (!empty($_POST['formats']) && is_array($_POST['formats'])) {
            $formats = implode(',', $_POST['formats']);
        }

        $eventData = [
            'name' => $name,
            'advent_id' => trim($_POST['advent_id'] ?? '') ?: null,
            'date' => $date,
            'end_date' => !empty($_POST['end_date']) ? trim($_POST['end_date']) : null,
            'event_type' => in_array($_POST['event_type'] ?? '', ['single', 'festival', 'stage_race', 'multi_event']) ? $_POST['event_type'] : 'single',
            'logo_media_id' => !empty($_POST['logo_media_id']) ? intval($_POST['logo_media_id']) : null,
            'formats' => $formats ?: null,
            'location' => trim($_POST['location'] ?? ''),
            'venue_id' => !empty($_POST['venue_id']) ? intval($_POST['venue_id']) : null,
            'discipline' => trim($_POST['discipline'] ?? ''),
            'event_level' => in_array($_POST['event_level'] ?? '', ['national', 'sportmotion']) ? $_POST['event_level'] : 'national',
            'event_format' => trim($_POST['event_format'] ?? 'ENDURO'),
            'stage_names' => !empty($_POST['stage_names']) ? trim($_POST['stage_names']) : null,
            'series_id' => !empty($_POST['series_id']) ? intval($_POST['series_id']) : null,
            'point_scale_id' => !empty($_POST['point_scale_id']) ? intval($_POST['point_scale_id']) : null,
            'pricing_template_id' => !empty($_POST['pricing_template_id']) ? intval($_POST['pricing_template_id']) : null,
            'distance' => !empty($_POST['distance']) ? floatval($_POST['distance']) : null,
            'elevation_gain' => !empty($_POST['elevation_gain']) ? intval($_POST['elevation_gain']) : null,
            'organizer_club_id' => !empty($_POST['organizer_club_id']) ? intval($_POST['organizer_club_id']) : null,
            'organizer' => '', // Keep for backwards compatibility
            'website' => trim($_POST['website'] ?? ''),
            'registration_opens' => !empty($_POST['registration_opens']) ? trim($_POST['registration_opens']) : null,
            'registration_deadline' => !empty($_POST['registration_deadline']) ? trim($_POST['registration_deadline']) : null,
            'registration_deadline_time' => !empty($_POST['registration_deadline_time']) ? trim($_POST['registration_deadline_time']) : null,
            'max_participants' => !empty($_POST['max_participants']) ? intval($_POST['max_participants']) : null,
            'active' => isset($_POST['active']) ? 1 : 0,
            // is_championship: Only super admins can change this - handled separately below
            'contact_email' => trim($_POST['contact_email'] ?? ''),
            'contact_phone' => trim($_POST['contact_phone'] ?? ''),
            // Extended fields
            'venue_details' => trim($_POST['venue_details'] ?? ''),
            'venue_coordinates' => trim($_POST['venue_coordinates'] ?? ''),
            'venue_map_url' => trim($_POST['venue_map_url'] ?? ''),
            'pm_content' => trim($_POST['pm_content'] ?? ''),
            'pm_use_global' => isset($_POST['pm_use_global']) ? 1 : 0,
            'pm_publish_at' => !empty($_POST['pm_publish_at']) ? trim($_POST['pm_publish_at']) : null,
            'jury_communication' => trim($_POST['jury_communication'] ?? ''),
            'jury_use_global' => isset($_POST['jury_use_global']) ? 1 : 0,
            'competition_schedule' => trim($_POST['competition_schedule'] ?? ''),
            'schedule_use_global' => isset($_POST['schedule_use_global']) ? 1 : 0,
            'start_times' => trim($_POST['start_times'] ?? ''),
            'start_times_use_global' => isset($_POST['start_times_use_global']) ? 1 : 0,
            'starttider_publish_at' => !empty($_POST['starttider_publish_at']) ? trim($_POST['starttider_publish_at']) : null,
            // Note: karta_publish_at is managed in the map editor (event-map.php)
            'driver_meeting' => trim($_POST['driver_meeting'] ?? ''),
            'driver_meeting_use_global' => isset($_POST['driver_meeting_use_global']) ? 1 : 0,
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
            'invitation' => trim($_POST['invitation'] ?? ''),
            'invitation_use_global' => isset($_POST['invitation_use_global']) ? 1 : 0,
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
            // Hidden flags for each content section
            'invitation_hidden' => isset($_POST['invitation_hidden']) ? 1 : 0,
            'hydration_hidden' => isset($_POST['hydration_hidden']) ? 1 : 0,
            'toilets_hidden' => isset($_POST['toilets_hidden']) ? 1 : 0,
            'bike_wash_hidden' => isset($_POST['bike_wash_hidden']) ? 1 : 0,
            'food_hidden' => isset($_POST['food_hidden']) ? 1 : 0,
            'shops_hidden' => isset($_POST['shops_hidden']) ? 1 : 0,
            'exhibitors_hidden' => isset($_POST['exhibitors_hidden']) ? 1 : 0,
            'parking_hidden' => isset($_POST['parking_hidden']) ? 1 : 0,
            'hotel_hidden' => isset($_POST['hotel_hidden']) ? 1 : 0,
            'local_hidden' => isset($_POST['local_hidden']) ? 1 : 0,
            'media_hidden' => isset($_POST['media_hidden']) ? 1 : 0,
            'contacts_hidden' => isset($_POST['contacts_hidden']) ? 1 : 0,
            'pm_hidden' => isset($_POST['pm_hidden']) ? 1 : 0,
            'driver_meeting_hidden' => isset($_POST['driver_meeting_hidden']) ? 1 : 0,
            'training_hidden' => isset($_POST['training_hidden']) ? 1 : 0,
            'timing_hidden' => isset($_POST['timing_hidden']) ? 1 : 0,
            'lift_hidden' => isset($_POST['lift_hidden']) ? 1 : 0,
            'rules_hidden' => isset($_POST['rules_hidden']) ? 1 : 0,
            'insurance_hidden' => isset($_POST['insurance_hidden']) ? 1 : 0,
            'equipment_hidden' => isset($_POST['equipment_hidden']) ? 1 : 0,
            'medical_hidden' => isset($_POST['medical_hidden']) ? 1 : 0,
            'scf_hidden' => isset($_POST['scf_hidden']) ? 1 : 0,
            'jury_hidden' => isset($_POST['jury_hidden']) ? 1 : 0,
            'schedule_hidden' => isset($_POST['schedule_hidden']) ? 1 : 0,
            'start_times_hidden' => isset($_POST['start_times_hidden']) ? 1 : 0,
            // Course tracks (bansträckningar)
            'course_tracks' => trim($_POST['course_tracks'] ?? ''),
            'course_tracks_use_global' => isset($_POST['course_tracks_use_global']) ? 1 : 0,
        ];

        try {
            error_log("EVENT EDIT: Saving event ID {$id}, name: {$name}");

            // is_championship: ONLY super admins can change this
            // Promotors must never be able to modify it (even via manipulated POST data)
            $isChampionship = null; // null = don't update
            if (!$isPromotorOnly) {
                // Super admin/admin - check actual POST value (not just isset!)
                $isChampionship = (!empty($_POST['is_championship']) && $_POST['is_championship'] == '1') ? 1 : 0;
            }

            // First, update the core fields (original 10 that always work)
            $basicResult = $db->query(
                "UPDATE events SET name = ?, date = ?, location = ?, venue_id = ?,
                 discipline = ?, event_level = ?, event_format = ?, series_id = ?,
                 active = ?, website = ? WHERE id = ?",
                [
                    $eventData['name'], $eventData['date'], $eventData['location'],
                    $eventData['venue_id'], $eventData['discipline'], $eventData['event_level'],
                    $eventData['event_format'], $eventData['series_id'], $eventData['active'],
                    $eventData['website'], $id
                ]
            );

            if (!$basicResult) {
                throw new Exception("Kunde inte uppdatera grunddata - kontrollera databasen");
            }

            // Try to update organizer_club_id separately (may not exist in older installs)
            try {
                $db->query("UPDATE events SET organizer_club_id = ? WHERE id = ?",
                    [$eventData['organizer_club_id'], $id]);
            } catch (Exception $clubEx) {
                error_log("EVENT EDIT: organizer_club_id update failed: " . $clubEx->getMessage());
            }

            // Now try to update extended fields (these might not exist in all installs)
            try {
                unset($eventData['name'], $eventData['date'], $eventData['location'],
                      $eventData['venue_id'], $eventData['discipline'], $eventData['event_level'],
                      $eventData['event_format'], $eventData['series_id'], $eventData['active'],
                      $eventData['website'], $eventData['organizer_club_id']);

                if (!empty($eventData)) {
                    $db->update('events', $eventData, 'id = ?', [$id]);
                }
            } catch (Exception $extEx) {
                error_log("EVENT EDIT: Extended fields update failed (non-critical): " . $extEx->getMessage());
            }

            // Update general_competition_info separately (migration 044 may not be run yet)
            try {
                $db->query("UPDATE events SET general_competition_info = ?, general_competition_use_global = ?, general_competition_hidden = ? WHERE id = ?", [
                    trim($_POST['general_competition_info'] ?? ''),
                    isset($_POST['general_competition_use_global']) ? 1 : 0,
                    isset($_POST['general_competition_hidden']) ? 1 : 0,
                    $id
                ]);
            } catch (Exception $gcEx) {
                error_log("EVENT EDIT: general_competition_info update failed (run migration 044): " . $gcEx->getMessage());
            }

            // Save info links per section (migration 057+058 - event_info_links table)
            // Dynamically find all sections from POST keys (info_link_SECTION_url[])
            try {
                $db->query("DELETE FROM event_info_links WHERE event_id = ?", [$id]);
                $linkSections = [];
                foreach (array_keys($_POST) as $postKey) {
                    if (preg_match('/^info_link_(.+)_url$/', $postKey, $m)) {
                        $linkSections[] = $m[1];
                    }
                }
                foreach ($linkSections as $section) {
                    $linkUrls = $_POST["info_link_{$section}_url"] ?? [];
                    $linkTexts = $_POST["info_link_{$section}_text"] ?? [];
                    $sortOrder = 0;
                    foreach ($linkUrls as $i => $url) {
                        $url = trim($url);
                        if (empty($url)) continue;
                        $text = trim($linkTexts[$i] ?? '');
                        $db->query("INSERT INTO event_info_links (event_id, section, link_url, link_text, sort_order) VALUES (?, ?, ?, ?, ?)", [
                            $id, $section, $url, $text, $sortOrder++
                        ]);
                    }
                }
            } catch (Exception $linkEx) {
                error_log("EVENT EDIT: event_info_links save failed (run migration 057): " . $linkEx->getMessage());
            }

            // Save Regelverk fields (migration 058)
            try {
                $db->query("UPDATE events SET regulations_info = ?, regulations_global_type = ?, regulations_hidden = ? WHERE id = ?", [
                    trim($_POST['regulations_info'] ?? ''),
                    trim($_POST['regulations_global_type'] ?? ''),
                    isset($_POST['regulations_hidden']) ? 1 : 0,
                    $id
                ]);
            } catch (Exception $regEx) {
                error_log("EVENT EDIT: regulations update failed (run migration 058): " . $regEx->getMessage());
            }

            // Save Licenser fields (migration 058)
            try {
                $db->query("UPDATE events SET license_info = ?, license_use_global = ?, license_hidden = ? WHERE id = ?", [
                    trim($_POST['license_info'] ?? ''),
                    isset($_POST['license_use_global']) ? 1 : 0,
                    isset($_POST['license_hidden']) ? 1 : 0,
                    $id
                ]);
            } catch (Exception $licEx) {
                error_log("EVENT EDIT: license update failed (run migration 058): " . $licEx->getMessage());
            }

            // Update competition_classes_info separately (migration 045 may not be run yet)
            $db->query("UPDATE events SET competition_classes_info = ?, competition_classes_use_global = ?, competition_classes_hidden = ? WHERE id = ?", [
                trim($_POST['competition_classes_info'] ?? ''),
                isset($_POST['competition_classes_use_global']) ? 1 : 0,
                isset($_POST['competition_classes_hidden']) ? 1 : 0,
                $id
            ]);

            // Update is_championship separately - ONLY if user is super admin (not promotor)
            if ($isChampionship !== null) {
                try {
                    $db->query("UPDATE events SET is_championship = ? WHERE id = ?", [$isChampionship, $id]);
                } catch (Exception $smEx) {
                    error_log("EVENT EDIT: SM column update failed: " . $smEx->getMessage());
                }
            }

            // Update header_banner_media_id separately
            if ($headerBannerColumnExists) {
                try {
                    $headerBannerMediaId = !empty($_POST['header_banner_media_id']) ? intval($_POST['header_banner_media_id']) : null;
                    $db->query("UPDATE events SET header_banner_media_id = ? WHERE id = ?", [$headerBannerMediaId, $id]);
                } catch (Exception $hbEx) {
                    error_log("EVENT EDIT: header_banner_media_id update failed: " . $hbEx->getMessage());
                }
            }

            // Update logo_media_id and logo path separately
            try {
                $logoMediaId = !empty($_POST['logo_media_id']) ? intval($_POST['logo_media_id']) : null;
                $logoPath = null;
                if ($logoMediaId) {
                    global $pdo;
                    $logoStmt = $pdo->prepare("SELECT filepath FROM media WHERE id = ?");
                    $logoStmt->execute([$logoMediaId]);
                    $logoRow = $logoStmt->fetch(PDO::FETCH_ASSOC);
                    if ($logoRow) {
                        $logoPath = '/' . $logoRow['filepath'];
                    }
                }
                $db->query("UPDATE events SET logo_media_id = ?, logo = ? WHERE id = ?", [$logoMediaId, $logoPath, $id]);
            } catch (Exception $logoEx) {
                error_log("EVENT EDIT: logo_media_id update failed: " . $logoEx->getMessage());
            }

            // Update gravity_id_discount (0 = use series setting, >0 = specific discount)
            try {
                $gravityIdDiscount = floatval($_POST['gravity_id_discount'] ?? 0);
                $db->query("UPDATE events SET gravity_id_discount = ? WHERE id = ?", [$gravityIdDiscount, $id]);
            } catch (Exception $gidEx) {
                error_log("EVENT EDIT: gravity_id_discount update failed: " . $gidEx->getMessage());
            }

            // Sync series_events junction table with events.series_id
            try {
                $newSeriesId = !empty($_POST['series_id']) ? intval($_POST['series_id']) : null;

                // Remove from any previous series_events entries that don't match
                $db->query(
                    "DELETE FROM series_events WHERE event_id = ? AND series_id != ?",
                    [$id, $newSeriesId ?? 0]
                );

                // Add to series_events if series_id is set and not already there
                if ($newSeriesId) {
                    $existingLink = $db->getRow(
                        "SELECT id FROM series_events WHERE series_id = ? AND event_id = ?",
                        [$newSeriesId, $id]
                    );

                    if (!$existingLink) {
                        // Get max sort order for this series
                        $maxOrder = $db->getRow(
                            "SELECT MAX(sort_order) as max_order FROM series_events WHERE series_id = ?",
                            [$newSeriesId]
                        );
                        $sortOrder = ($maxOrder['max_order'] ?? 0) + 1;

                        $db->insert('series_events', [
                            'series_id' => $newSeriesId,
                            'event_id' => $id,
                            'sort_order' => $sortOrder
                        ]);
                    }
                }
            } catch (Exception $syncEx) {
                error_log("EVENT EDIT: series_events sync failed (non-critical): " . $syncEx->getMessage());
            }

            // Verify basic fields were saved
            $verifyEvent = $db->getRow("SELECT name FROM events WHERE id = ?", [$id]);
            $savedName = $verifyEvent['name'] ?? '';

            if ($savedName !== $name) {
                $message = "VARNING: Namnet sparades inte! Försökte: '{$name}', DB har: '{$savedName}'";
                $messageType = 'error';
                error_log("EVENT EDIT ERROR: Name mismatch");
            } else {
                // Save sponsor assignments
                $sponsorError = null;
                try {
                    saveEventSponsorAssignments($db, $id, $_POST);
                    error_log("EVENT EDIT: Sponsor assignments saved successfully for event $id");
                    error_log("EVENT EDIT: sponsor_header=" . ($_POST['sponsor_header'] ?? 'none'));
                    error_log("EVENT EDIT: sponsor_content=" . json_encode($_POST['sponsor_content'] ?? []));
                    error_log("EVENT EDIT: sponsor_sidebar=" . ($_POST['sponsor_sidebar'] ?? 'none'));
                } catch (Exception $sponsorEx) {
                    error_log("EVENT EDIT: Sponsor assignments save failed: " . $sponsorEx->getMessage());
                    $sponsorError = $sponsorEx->getMessage();
                }

                if ($sponsorError) {
                    $_SESSION['message'] = 'Event sparat, men fel vid sponsorsparning: ' . $sponsorError;
                    $_SESSION['messageType'] = 'warning';
                } else {
                    $_SESSION['message'] = 'Event uppdaterat!';
                    $_SESSION['messageType'] = 'success';
                }
                header('Location: /admin/events/edit/' . $id . '?saved=1');
                exit;
            }
        } catch (Exception $e) {
            error_log("EVENT EDIT ERROR: " . $e->getMessage());
            $message = 'Ett fel uppstod: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}

// Fetch data for dropdowns
// Get series with brand and year info for better grouping
// Include completed series so events can be added back if needed
$series = $db->getAll("
    SELECT s.id, s.name, s.year, s.brand_id, s.status, sb.name as brand_name
    FROM series s
    LEFT JOIN series_brands sb ON s.brand_id = sb.id
    WHERE s.status IN ('active', 'planning', 'completed')
    ORDER BY sb.name ASC, s.year DESC, s.name ASC
");

// Group series by brand for display
$seriesByBrand = [];
foreach ($series as $s) {
    $brandName = $s['brand_name'] ?? 'Utan varumärke';
    if (!isset($seriesByBrand[$brandName])) {
        $seriesByBrand[$brandName] = [];
    }
    $seriesByBrand[$brandName][] = $s;
}

// Get event year from date for auto-suggestion
$eventYear = !empty($event['date']) ? date('Y', strtotime($event['date'])) : date('Y');
$venues = $db->getAll("SELECT id, name, city FROM venues WHERE active = 1 ORDER BY name");
$clubs = $db->getAll("SELECT id, name, city FROM clubs WHERE active = 1 ORDER BY name");
$pricingTemplates = $db->getAll("SELECT id, name, is_default FROM pricing_templates ORDER BY is_default DESC, name ASC");
$pointScales = $db->getAll("SELECT id, name, discipline FROM point_scales WHERE active = 1 ORDER BY name ASC");

// Fetch global texts
$globalTexts = $db->getAll("SELECT field_key, content FROM global_texts WHERE is_active = 1");
$globalTextMap = [];
foreach ($globalTexts as $gt) {
    $globalTextMap[$gt['field_key']] = $gt['content'];
}

// Get configured classes for this event (from event_pricing_rules)
$eventClasses = [];
try {
    $eventClasses = $db->getAll("
        SELECT c.id, c.name, c.display_name, c.gender, epr.base_price
        FROM event_pricing_rules epr
        JOIN classes c ON epr.class_id = c.id
        WHERE epr.event_id = ?
        ORDER BY c.sort_order ASC, c.name ASC
    ", [$id]);
} catch (Exception $e) {
    error_log("EVENT EDIT: Could not load event classes: " . $e->getMessage());
}

// Get pricing template and its prices if a template is assigned
$assignedTemplate = null;
$templatePrices = [];
if (!empty($event['pricing_template_id'])) {
    try {
        $assignedTemplate = $db->getRow("SELECT * FROM pricing_templates WHERE id = ?", [$event['pricing_template_id']]);
        if ($assignedTemplate) {
            $templatePrices = $db->getAll("
                SELECT ptr.*, c.id as class_id, c.name as class_name, c.display_name, c.gender
                FROM pricing_template_rules ptr
                JOIN classes c ON c.id = ptr.class_id
                WHERE ptr.template_id = ?
                ORDER BY c.sort_order ASC, c.name ASC
            ", [$event['pricing_template_id']]);
        }
    } catch (Exception $e) {
        error_log("EVENT EDIT: Could not load pricing template: " . $e->getMessage());
    }
}

// Get all sponsors for selection (with logo URLs for promotor image view)
$allSponsors = [];
$eventSponsors = ['header' => [], 'content' => [], 'sidebar' => [], 'partner' => []];
try {
    $allSponsors = $db->getAll("
        SELECT s.id, s.name, s.logo, s.tier, s.logo_media_id, s.logo_banner_id,
               m1.filepath as logo_filepath, m2.filepath as banner_filepath
        FROM sponsors s
        LEFT JOIN media m1 ON s.logo_media_id = m1.id
        LEFT JOIN media m2 ON s.logo_banner_id = m2.id
        WHERE s.active = 1
        ORDER BY s.tier ASC, s.name ASC
    ");

    // Build logo URL for each sponsor
    foreach ($allSponsors as &$sp) {
        if (!empty($sp['banner_filepath'])) {
            $sp['logo_url'] = '/' . $sp['banner_filepath'];
        } elseif (!empty($sp['logo_filepath'])) {
            $sp['logo_url'] = '/' . $sp['logo_filepath'];
        } elseif (!empty($sp['logo'])) {
            $sp['logo_url'] = '/uploads/sponsors/' . $sp['logo'];
        } else {
            $sp['logo_url'] = null;
        }
    }
    unset($sp);

    $sponsorAssignments = $db->getAll("
        SELECT sponsor_id, placement
        FROM event_sponsors
        WHERE event_id = ?
    ", [$id]);
    foreach ($sponsorAssignments as $sa) {
        $placement = $sa['placement'] ?? 'sidebar';
        if (!isset($eventSponsors[$placement])) {
            $eventSponsors[$placement] = [];
        }
        $eventSponsors[$placement][] = (int)$sa['sponsor_id'];
    }
} catch (Exception $e) {
    error_log("EVENT EDIT: Could not load sponsors: " . $e->getMessage());
}

// Build sponsor lookup for JS (promotor image view)
$sponsorLookup = [];
foreach ($allSponsors as $sp) {
    $sponsorLookup[(int)$sp['id']] = [
        'id' => (int)$sp['id'],
        'name' => $sp['name'],
        'logo_url' => $sp['logo_url']
    ];
}

// Page config
$page_title = 'Redigera Event';
$breadcrumbs = [
    ['label' => 'Events', 'url' => '/admin/events'],
    ['label' => htmlspecialchars($event['name'])]
];
$page_actions = '<a href="/event/' . $id . '" target="_blank" class="btn-admin btn-admin-secondary">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon-sm"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" x2="21" y1="14" y2="3"/></svg>
    Visa event
</a>
<a href="/admin/event-pricing.php?id=' . $id . '" class="btn-admin btn-admin-secondary">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon-sm"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
    Klasser &amp; Priser
</a>
<a href="/admin/event-map.php?id=' . $id . '" class="btn-admin btn-admin-secondary">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon-sm"><polygon points="3 6 9 3 15 6 21 3 21 18 15 21 9 18 3 21"/><line x1="9" x2="9" y1="3" y2="18"/><line x1="15" x2="15" y1="6" y2="21"/></svg>
    Karta &amp; POI
</a>
<a href="/admin/event-import-paste.php?event_id=' . $id . '" class="btn-admin btn-admin-primary">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon-sm"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M9 14l2 2 4-4"/></svg>
    Importera resultat
</a>';

include __DIR__ . '/components/unified-layout.php';
?>

<?php if ($message): ?>
    <div class="alert alert-<?= $messageType === 'success' ? 'success' : ($messageType === 'error' ? 'error' : 'info') ?> mb-lg">
        <?= htmlspecialchars($message) ?>
    </div>
<?php endif; ?>

<form method="POST" id="eventEditForm">
    <?= csrf_field() ?>

    <!-- BASIC INFO - Locked for promotors -->
    <?php if ($isPromotorOnly): ?>
    <!-- Hidden fields to preserve ALL locked values for promotors -->
    <input type="hidden" name="name" value="<?= h($event['name']) ?>">
    <input type="hidden" name="date" value="<?= h($event['date']) ?>">
    <input type="hidden" name="advent_id" value="<?= h($event['advent_id'] ?? '') ?>">
    <input type="hidden" name="location" value="<?= h($event['location'] ?? '') ?>">
    <input type="hidden" name="venue_id" value="<?= h($event['venue_id'] ?? '') ?>">
    <input type="hidden" name="end_date" value="<?= h($event['end_date'] ?? '') ?>">
    <input type="hidden" name="event_type" value="<?= h($event['event_type'] ?? 'single') ?>">
    <input type="hidden" name="logo_media_id" value="<?= h($event['logo_media_id'] ?? '') ?>">
    <input type="hidden" name="registration_opens" value="<?= h($event['registration_opens'] ?? '') ?>">
    <input type="hidden" name="registration_deadline" value="<?= h($event['registration_deadline'] ?? '') ?>">
    <input type="hidden" name="registration_deadline_time" value="<?= h($event['registration_deadline_time'] ?? '') ?>">
    <input type="hidden" name="max_participants" value="<?= h($event['max_participants'] ?? '') ?>">
    <input type="hidden" name="contact_email" value="<?= h($event['contact_email'] ?? '') ?>">
    <input type="hidden" name="contact_phone" value="<?= h($event['contact_phone'] ?? '') ?>">
    <?php endif; ?>
    <details class="admin-card mb-lg <?= $isPromotorOnly ? 'locked-section' : '' ?>">
        <summary class="admin-card-header collapsible-header">
            <h2>Grundläggande information</h2>
            <?php if ($isPromotorOnly): ?>
            <span class="locked-badge">
                <i data-lucide="lock"></i> Låst
            </span>
            <?php else: ?>
            <span class="text-secondary text-sm">Klicka för att expandera/minimera</span>
            <?php endif; ?>
        </summary>
        <fieldset class="admin-card-body fieldset-reset" style="padding: var(--space-lg);" <?= $isPromotorOnly ? 'disabled' : '' ?>>

            <!-- Event-namn -->
            <div class="form-subsection">
                <div class="admin-form-group">
                    <label class="admin-form-label">Namn <span class="required">*</span></label>
                    <input type="text" class="admin-form-input" <?= $isPromotorOnly ? '' : 'name="name" required' ?> value="<?= h($event['name']) ?>">
                </div>
            </div>

            <!-- Datum & Typ -->
            <div class="form-subsection">
                <div class="form-subsection-label"><i data-lucide="calendar"></i> Datum & typ</div>
                <div class="form-grid form-grid-2">
                    <div class="admin-form-group">
                        <label class="admin-form-label">Startdatum <span class="required">*</span></label>
                        <input type="date" class="admin-form-input" <?= $isPromotorOnly ? '' : 'name="date" required' ?> value="<?= h($event['date']) ?>">
                    </div>
                    <div class="admin-form-group">
                        <label class="admin-form-label">Slutdatum</label>
                        <input type="date" name="end_date" class="admin-form-input" value="<?= h($event['end_date'] ?? '') ?>">
                        <small class="form-help">För flerdagars-event</small>
                    </div>
                    <div class="admin-form-group">
                        <label class="admin-form-label">Eventtyp</label>
                        <select name="event_type" class="admin-form-select">
                            <option value="single" <?= ($event['event_type'] ?? 'single') === 'single' ? 'selected' : '' ?>>Enstaka event</option>
                            <option value="festival" <?= ($event['event_type'] ?? '') === 'festival' ? 'selected' : '' ?>>Festival (flerdagars)</option>
                            <option value="stage_race" <?= ($event['event_type'] ?? '') === 'stage_race' ? 'selected' : '' ?>>Etapplopp</option>
                            <option value="multi_event" <?= ($event['event_type'] ?? '') === 'multi_event' ? 'selected' : '' ?>>Multi-event</option>
                        </select>
                    </div>
                    <div class="admin-form-group">
                        <label class="admin-form-label">Advent ID</label>
                        <input type="text" name="advent_id" class="admin-form-input" value="<?= h($event['advent_id'] ?? '') ?>" placeholder="Externt ID">
                    </div>
                </div>
            </div>

            <!-- Plats & Bana -->
            <div class="form-subsection">
                <div class="form-subsection-label"><i data-lucide="map-pin"></i> Plats</div>
                <div class="form-grid form-grid-2">
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

            <!-- Logga -->
            <div class="form-subsection">
                <div class="form-subsection-label"><i data-lucide="image"></i> Logga</div>
                <div class="admin-form-group">
                    <select name="logo_media_id" class="admin-form-select">
                        <option value="">-- Ingen (använd seriens) --</option>
                        <?php foreach ($seriesMediaFiles as $media): ?>
                        <option value="<?= $media['id'] ?>" <?= ($event['logo_media_id'] ?? '') == $media['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($media['original_filename']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (!empty($event['logo'])): ?>
                    <div class="mt-sm">
                        <img src="<?= h($event['logo']) ?>" alt="Event logo" style="max-height: 60px; border-radius: var(--radius-sm);">
                    </div>
                    <?php endif; ?>
                    <small class="form-help">
                        Välj bild från <a href="/admin/media?folder=series" target="_blank">Mediabiblioteket</a>. Används i kalender om eventet inte tillhör en serie.
                    </small>
                </div>
            </div>

            <!-- Anmälan -->
            <div class="form-subsection">
                <div class="form-subsection-label"><i data-lucide="clipboard-list"></i> Anmälan</div>
                <div class="form-grid form-grid-2">
                    <div class="admin-form-group">
                        <label class="admin-form-label">Anmälan öppnar</label>
                        <input type="datetime-local" name="registration_opens" class="admin-form-input" value="<?= !empty($event['registration_opens']) ? date('Y-m-d\TH:i', strtotime($event['registration_opens'])) : '' ?>">
                        <small class="form-help">Lämna tomt om anmälan redan är öppen</small>
                    </div>
                    <div class="admin-form-group">
                        <label class="admin-form-label">Max antal deltagare</label>
                        <input type="number" name="max_participants" class="admin-form-input" min="1" value="<?= h($event['max_participants'] ?? '') ?>" placeholder="Obegränsat">
                        <small class="form-help">Lämna tomt = obegränsat</small>
                    </div>
                    <div class="admin-form-group">
                        <label class="admin-form-label">Anmälningsfrist (datum)</label>
                        <input type="date" name="registration_deadline" class="admin-form-input" value="<?= h($event['registration_deadline'] ?? '') ?>">
                    </div>
                    <div class="admin-form-group">
                        <label class="admin-form-label">Anmälningsfrist (klockslag)</label>
                        <input type="time" name="registration_deadline_time" class="admin-form-input" value="<?= h($event['registration_deadline_time'] ?? '') ?>">
                        <small class="form-help">Lämna tomt för 23:59</small>
                    </div>
                </div>
            </div>

        </fieldset>
    </details>

    <!-- COMPETITION SETTINGS - Locked for promotors -->
    <?php if ($isPromotorOnly): ?>
    <!-- Hidden fields to preserve ALL locked values for promotors -->
    <input type="hidden" name="discipline" value="<?= h($event['discipline'] ?? '') ?>">
    <input type="hidden" name="series_id" value="<?= h($event['series_id'] ?? '') ?>">
    <input type="hidden" name="event_level" value="<?= h($event['event_level'] ?? 'national') ?>">
    <input type="hidden" name="event_format" value="<?= h($event['event_format'] ?? 'ENDURO') ?>">
    <input type="hidden" name="point_scale_id" value="<?= h($event['point_scale_id'] ?? '') ?>">
    <input type="hidden" name="pricing_template_id" value="<?= h($event['pricing_template_id'] ?? '') ?>">
    <input type="hidden" name="distance" value="<?= h($event['distance'] ?? '') ?>">
    <input type="hidden" name="elevation_gain" value="<?= h($event['elevation_gain'] ?? '') ?>">
    <input type="hidden" name="stage_names" value="<?= h($event['stage_names'] ?? '') ?>">
    <?php
    // Preserve formats[] checkboxes as hidden inputs
    if (!empty($event['formats'])) {
        foreach (array_map('trim', explode(',', $event['formats'])) as $fmt) {
            echo '<input type="hidden" name="formats[]" value="' . h($fmt) . '">';
        }
    }
    ?>
    <?php endif; ?>
    <details class="admin-card mb-lg <?= $isPromotorOnly ? 'locked-section' : '' ?>">
        <summary class="admin-card-header collapsible-header">
            <h2>Tävlingsinställningar</h2>
            <?php if ($isPromotorOnly): ?>
            <span class="locked-badge">
                <i data-lucide="lock"></i> Låst
            </span>
            <?php else: ?>
            <span class="text-secondary text-sm">Klicka för att expandera/minimera</span>
            <?php endif; ?>
        </summary>
        <fieldset class="admin-card-body fieldset-reset" style="padding: var(--space-lg);" <?= $isPromotorOnly ? 'disabled' : '' ?>>

            <!-- Format & Disciplin -->
            <div class="form-subsection">
                <div class="form-subsection-label"><i data-lucide="bike"></i> Format & disciplin</div>
                <div class="form-grid form-grid-2">
                    <div class="admin-form-group">
                        <label class="admin-form-label">Huvudformat</label>
                        <select name="discipline" class="admin-form-select">
                            <option value="">Välj format...</option>
                            <option value="ENDURO" <?= ($event['discipline'] ?? '') === 'ENDURO' ? 'selected' : '' ?>>Enduro</option>
                            <option value="DH" <?= ($event['discipline'] ?? '') === 'DH' ? 'selected' : '' ?>>Downhill</option>
                            <option value="XC" <?= ($event['discipline'] ?? '') === 'XC' ? 'selected' : '' ?>>XC</option>
                            <option value="XCO" <?= ($event['discipline'] ?? '') === 'XCO' ? 'selected' : '' ?>>XCO</option>
                            <option value="XCC" <?= ($event['discipline'] ?? '') === 'XCC' ? 'selected' : '' ?>>XCC</option>
                            <option value="XCE" <?= ($event['discipline'] ?? '') === 'XCE' ? 'selected' : '' ?>>XCE</option>
                            <option value="DUAL_SLALOM" <?= ($event['discipline'] ?? '') === 'DUAL_SLALOM' ? 'selected' : '' ?>>Dual Slalom</option>
                            <option value="PUMPTRACK" <?= ($event['discipline'] ?? '') === 'PUMPTRACK' ? 'selected' : '' ?>>Pumptrack</option>
                            <option value="GRAVEL" <?= ($event['discipline'] ?? '') === 'GRAVEL' ? 'selected' : '' ?>>Gravel</option>
                            <option value="E-MTB" <?= ($event['discipline'] ?? '') === 'E-MTB' ? 'selected' : '' ?>>E-MTB</option>
                        </select>
                    </div>
                    <div class="admin-form-group">
                        <label class="admin-form-label">Event-format</label>
                        <select name="event_format" class="admin-form-select">
                            <option value="ENDURO" <?= ($event['event_format'] ?? 'ENDURO') === 'ENDURO' ? 'selected' : '' ?>>Enduro (en tid, splittider)</option>
                            <option value="DH_STANDARD" <?= ($event['event_format'] ?? '') === 'DH_STANDARD' ? 'selected' : '' ?>>Downhill Standard (två åk)</option>
                            <option value="DH_SWECUP" <?= ($event['event_format'] ?? '') === 'DH_SWECUP' ? 'selected' : '' ?>>SweCUP DH (Kval + Final)</option>
                            <option value="DUAL_SLALOM" <?= ($event['event_format'] ?? '') === 'DUAL_SLALOM' ? 'selected' : '' ?>>Dual Slalom</option>
                        </select>
                    </div>
                </div>
                <?php
                $existingFormats = [];
                if (!empty($event['formats'])) {
                    $existingFormats = array_map('trim', explode(',', $event['formats']));
                }
                $allFormats = [
                    'ENDURO' => 'Enduro', 'DH' => 'Downhill', 'XC' => 'XC',
                    'XCO' => 'XCO', 'XCC' => 'XCC', 'XCE' => 'XCE',
                    'DUAL_SLALOM' => 'Dual Slalom', 'PUMPTRACK' => 'Pumptrack',
                    'GRAVEL' => 'Gravel', 'E-MTB' => 'E-MTB'
                ];
                ?>
                <div class="admin-form-group mt-md">
                    <label class="admin-form-label">Alla format <span class="text-secondary text-sm">(för festivaler/multi-events)</span></label>
                    <div class="checkbox-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: var(--space-sm);">
                        <?php foreach ($allFormats as $key => $label): ?>
                        <label class="checkbox-label" style="display: flex; align-items: center; gap: var(--space-xs); cursor: pointer;">
                            <input type="checkbox" name="formats[]" value="<?= $key ?>" <?= in_array($key, $existingFormats) ? 'checked' : '' ?>>
                            <?= $label ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Serie & Ranking -->
            <div class="form-subsection">
                <div class="form-subsection-label"><i data-lucide="trophy"></i> Serie & ranking</div>
                <div class="form-grid form-grid-2">
                    <div class="admin-form-group">
                        <label class="admin-form-label">Serie</label>
                        <select name="series_id" id="series_id" class="admin-form-select">
                            <option value="">Ingen serie</option>
                            <?php foreach ($seriesByBrand as $brandName => $brandSeries): ?>
                                <optgroup label="<?= h($brandName) ?>">
                                    <?php foreach ($brandSeries as $s):
                                        $yearMatch = ($s['year'] == $eventYear);
                                        $isCompleted = ($s['status'] ?? '') === 'completed';
                                        $matchIndicator = $yearMatch ? ' ✓' : '';
                                        $completedIndicator = $isCompleted ? ' [Avslutad]' : '';
                                    ?>
                                        <option value="<?= $s['id'] ?>"
                                                data-year="<?= $s['year'] ?>"
                                                <?= ($event['series_id'] == $s['id']) ? 'selected' : '' ?>
                                                <?= $yearMatch ? 'style="font-weight: bold;"' : '' ?>>
                                            <?= h($s['name']) ?><?= $matchIndicator ?><?= $completedIndicator ?>
                                        </option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endforeach; ?>
                        </select>
                        <small class="form-help">År <?= $eventYear ?>. <a href="/admin/series" class="text-accent">Seriehantering</a></small>
                    </div>
                    <div class="admin-form-group">
                        <label class="admin-form-label">Rankingklass</label>
                        <select name="event_level" class="admin-form-select">
                            <option value="national" <?= ($event['event_level'] ?? 'national') === 'national' ? 'selected' : '' ?>>Nationell (100%)</option>
                            <option value="sportmotion" <?= ($event['event_level'] ?? 'national') === 'sportmotion' ? 'selected' : '' ?>>Sportmotion (50%)</option>
                        </select>
                        <small class="form-help">Styr rankingpoäng</small>
                    </div>
                    <div class="admin-form-group">
                        <label class="admin-form-label">Poängskala</label>
                        <select name="point_scale_id" class="admin-form-select">
                            <option value="">Ingen poängskala</option>
                            <?php foreach ($pointScales as $scale): ?>
                                <option value="<?= $scale['id'] ?>" <?= ($event['point_scale_id'] ?? '') == $scale['id'] ? 'selected' : '' ?>>
                                    <?= h($scale['name']) ?><?php if ($scale['discipline']): ?> (<?= h($scale['discipline']) ?>)<?php endif; ?>
                                </option>
                            <?php endforeach; ?>
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
                        <small class="form-help"><?php if (isRole('super_admin')): ?><a href="/admin/pricing-templates.php" class="text-accent">Hantera prismallar</a><?php elseif (!empty($event['pricing_template_id'])): ?><a href="/admin/pricing-template-edit.php?id=<?= $event['pricing_template_id'] ?>&event=<?= $id ?>" class="text-accent">Redigera prismall</a><?php endif; ?></small>
                    </div>
                </div>
            </div>

            <!-- Bana -->
            <div class="form-subsection">
                <div class="form-subsection-label"><i data-lucide="route"></i> Bana</div>
                <div class="form-grid form-grid-2">
                    <div class="admin-form-group">
                        <label class="admin-form-label">Distans (km)</label>
                        <input type="number" name="distance" class="admin-form-input" step="0.01" min="0" value="<?= h($event['distance'] ?? '') ?>">
                    </div>
                    <div class="admin-form-group">
                        <label class="admin-form-label">Höjdmeter (m)</label>
                        <input type="number" name="elevation_gain" class="admin-form-input" min="0" value="<?= h($event['elevation_gain'] ?? '') ?>">
                    </div>
                </div>
                <div class="admin-form-group mt-md">
                    <label class="admin-form-label">Sträcknamn (JSON)</label>
                    <input type="text" name="stage_names" class="admin-form-input" value="<?= h($event['stage_names'] ?? '') ?>" placeholder='{"1":"SS1","2":"SS2","3":"SS3"}'>
                    <small class="form-help">Anpassade namn. Lämna tomt för standard.</small>
                </div>
            </div>

        </fieldset>
    </details>

    <!-- EVENT CLASSES & PRICING - Hidden for promotors -->
    <?php if (!$isPromotorOnly): ?>
    <details class="admin-card mb-lg">
        <summary class="admin-card-header collapsible-header">
            <h2>Klasser och Startavgifter</h2>
            <span class="text-secondary text-sm">Klicka för att expandera/minimera</span>
        </summary>
        <div class="admin-card-body">
            <?php if ($assignedTemplate && !empty($templatePrices)):
                // Get pricing settings
                $ebPercent = $assignedTemplate['early_bird_percent'] ?? 15;
                $latePercent = $assignedTemplate['late_fee_percent'] ?? 25;
                $ebDays = $assignedTemplate['early_bird_days_before'] ?? 21;
                $lateDays = $assignedTemplate['late_fee_days_before'] ?? 3;
            ?>
                <!-- Template header -->
                <div class="flex items-center justify-between mb-md">
                    <div class="flex items-center gap-sm">
                        <span class="text-secondary">Prismall:</span>
                        <span class="admin-badge admin-badge-success"><?= htmlspecialchars($assignedTemplate['name']) ?></span>
                    </div>
                    <a href="/admin/pricing-templates.php?edit=<?= $event['pricing_template_id'] ?>" class="text-accent text-sm">Redigera mall</a>
                </div>

                <!-- Compact price table -->
                <div class="admin-table-container">
                    <table class="admin-table" style="font-size: 0.875rem;">
                        <thead>
                            <tr>
                                <th>Klass</th>
                                <th style="text-align: right; color: var(--color-success);">Early Bird (-<?= $ebPercent ?>%)</th>
                                <th style="text-align: right;">Ordinarie</th>
                                <th style="text-align: right; color: var(--color-warning);">Efteranmälan (+<?= $latePercent ?>%)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($templatePrices as $price):
                                $basePrice = $price['base_price'];
                                $ebPrice = round($basePrice * (1 - $ebPercent / 100));
                                $latePrice = round($basePrice * (1 + $latePercent / 100));
                            ?>
                            <tr>
                                <td>
                                    <?= htmlspecialchars($price['display_name'] ?? $price['class_name']) ?>
                                    <?php if ($price['gender'] === 'M'): ?>
                                        <span class="text-xs text-secondary">(H)</span>
                                    <?php elseif ($price['gender'] === 'K' || $price['gender'] === 'F'): ?>
                                        <span class="text-xs text-secondary">(D)</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: right; color: var(--color-success); font-weight: 600;"><?= number_format($ebPrice, 0) ?> kr</td>
                                <td style="text-align: right; font-weight: 600;"><?= number_format($basePrice, 0) ?> kr</td>
                                <td style="text-align: right; color: var(--color-warning); font-weight: 600;"><?= number_format($latePrice, 0) ?> kr</td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pricing rules info -->
                <div class="mt-md p-sm" style="background: var(--color-bg-hover); border-radius: var(--radius-sm); font-size: 0.8rem;">
                    <div class="flex gap-lg flex-wrap">
                        <span><strong style="color: var(--color-success);">Early Bird:</strong> När anmälan öppnar t.o.m. <?= $ebDays ?> dagar före</span>
                        <span><strong>Ordinarie:</strong> <?= $ebDays ?>-<?= $lateDays ?> dagar före</span>
                        <span><strong style="color: var(--color-warning);">Efteranmälan:</strong> Sista <?= $lateDays ?> dagarna</span>
                    </div>
                </div>

            <?php elseif ($assignedTemplate): ?>
                <div class="text-center p-md">
                    <p class="text-secondary mb-sm">Mallen "<?= htmlspecialchars($assignedTemplate['name']) ?>" saknar klasspriser.</p>
                    <a href="/admin/pricing-templates.php?edit=<?= $event['pricing_template_id'] ?>" class="btn-admin btn-admin-sm btn-admin-primary">Konfigurera priser</a>
                </div>

            <?php else: ?>
                <div class="text-center p-md">
                    <p class="text-secondary mb-sm">Ingen prismall tilldelad. Välj en mall i <strong>Tävlingsinställningar</strong> ovan.</p>
                    <a href="/admin/pricing-templates.php" class="text-accent text-sm">Hantera prismallar</a>
                </div>
            <?php endif; ?>
        </div>
    </details>
    <?php endif; /* End hide Klasser for promotors */ ?>

    <!-- ORGANIZER, LOCATION & PRICING - Editable for promotors -->
    <details class="admin-card mb-lg">
        <summary class="admin-card-header collapsible-header">
            <h2>Arrangör, plats & rabatt</h2>
            <span class="text-secondary text-sm">Kontakt, platsdetaljer, Gravity ID</span>
        </summary>
        <div class="admin-card-body" style="padding: var(--space-lg);">

            <!-- Arrangör & Kontakt -->
            <div class="form-subsection">
                <div class="form-subsection-label"><i data-lucide="users"></i> Arrangör & kontakt</div>
                <div class="form-grid form-grid-2">
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
                </div>
            </div>

            <!-- Platsdetaljer -->
            <div class="form-subsection">
                <div class="form-subsection-label"><i data-lucide="map-pin"></i> Platsdetaljer</div>
                <div class="form-grid form-grid-2">
                    <div class="admin-form-group">
                        <label class="admin-form-label">GPS-koordinater</label>
                        <input type="text" name="venue_coordinates" class="admin-form-input" value="<?= h($event['venue_coordinates'] ?? '') ?>" placeholder="59.3293, 18.0686">
                    </div>
                    <div class="admin-form-group">
                        <label class="admin-form-label">Google Maps URL</label>
                        <input type="url" name="venue_map_url" class="admin-form-input" value="<?= h($event['venue_map_url'] ?? '') ?>">
                    </div>
                </div>
                <div class="admin-form-group mt-md">
                    <label class="admin-form-label">Platsdetaljer</label>
                    <textarea name="venue_details" class="admin-form-input" rows="3"><?= h($event['venue_details'] ?? '') ?></textarea>
                </div>
            </div>

            <!-- Gravity ID-rabatt -->
            <div class="form-subsection">
                <div class="form-subsection-label"><i data-lucide="badge-check"></i> Gravity ID-rabatt</div>
                <div class="form-grid form-grid-2">
                    <div class="admin-form-group">
                        <label class="admin-form-label">Rabatt (SEK)</label>
                        <input type="number" name="gravity_id_discount" class="admin-form-input"
                               value="<?= h($event['gravity_id_discount'] ?? 0) ?>"
                               min="0" step="1" placeholder="0">
                        <small class="form-help">0 = använd seriens inställning</small>
                    </div>
                    <div class="admin-form-group">
                        <?php
                        $seriesDiscount = 0;
                        if (!empty($event['series_id'])) {
                            try {
                                $seriesGid = $db->getRow("SELECT gravity_id_discount FROM series WHERE id = ?", [$event['series_id']]);
                                $seriesDiscount = floatval($seriesGid['gravity_id_discount'] ?? 0);
                            } catch (Exception $e) {}
                        }
                        if ($seriesDiscount > 0): ?>
                        <label class="admin-form-label">Seriens rabatt</label>
                        <div class="info-box" style="padding: var(--space-sm); margin-top: var(--space-xs);">
                            <strong><?= $seriesDiscount ?> kr</strong>
                        </div>
                        <?php else: ?>
                        <label class="admin-form-label">&nbsp;</label>
                        <small class="form-help" style="margin-top: var(--space-sm);">Ingen serierabatt konfigurerad</small>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

        </div>
    </details>

    <!-- INVITATION - Editable for promotors -->
    <details class="admin-card mb-lg">
        <summary class="admin-card-header collapsible-header">
            <h2>Inbjudan</h2>
            <span class="text-secondary text-sm">Inbjudningstext, tävlingsinfo, regelverk, licenser, klasser</span>
        </summary>
        <div class="admin-card-body">
            <div class="facility-fields">
                <!-- Inbjudningstext -->
                <div class="facility-field">
                    <div class="facility-field-header">
                        <div class="facility-field-label">
                            <i data-lucide="scroll"></i>
                            <span>Inbjudningstext</span>
                        </div>
                        <label class="global-toggle">
                            <input type="checkbox" name="invitation_use_global" <?= !empty($event['invitation_use_global']) ? 'checked' : '' ?>>
                            <span>Global</span>
                        </label>
                    </div>
                    <textarea name="invitation" class="facility-textarea" rows="4" data-format-toolbar placeholder="Välkommen till... (visas högst upp på Inbjudan-fliken)"><?= h($event['invitation'] ?? '') ?></textarea>
                    <?php $invLinks = $eventInfoLinks['invitation'] ?? []; ?>
                    <div class="info-links-section" id="info-links-invitation">
                        <div class="info-links-header"><i data-lucide="link"></i><span>Länkar</span></div>
                        <div class="info-links-list">
                            <?php foreach ($invLinks as $link): ?>
                            <div class="info-link-row">
                                <input type="url" name="info_link_invitation_url[]" class="admin-form-input" placeholder="https://..." value="<?= h($link['link_url'] ?? '') ?>">
                                <input type="text" name="info_link_invitation_text[]" class="admin-form-input" placeholder="Visningsnamn (valfritt)" value="<?= h($link['link_text'] ?? '') ?>">
                                <button type="button" onclick="this.closest('.info-link-row').remove()" class="btn-admin btn-admin-danger info-link-remove" title="Ta bort länk"><i data-lucide="x" style="width:14px;height:14px;"></i></button>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" onclick="addInfoLink('invitation')" class="btn-admin btn-admin-ghost info-link-add">
                            <i data-lucide="plus" style="width:14px;height:14px;"></i> Lägg till länk
                        </button>
                    </div>
                </div>

                <!-- Generell tävlingsinformation -->
                <div class="facility-field">
                    <div class="facility-field-header">
                        <div class="facility-field-label">
                            <i data-lucide="info"></i>
                            <span>Generell tävlingsinformation</span>
                        </div>
                        <label class="global-toggle">
                            <input type="checkbox" name="general_competition_use_global" <?= !empty($event['general_competition_use_global']) ? 'checked' : '' ?>>
                            <span>Global</span>
                        </label>
                    </div>
                    <textarea name="general_competition_info" class="facility-textarea" rows="6" data-format-toolbar placeholder="Generell information om tävlingen..."><?= h($event['general_competition_info'] ?? '') ?></textarea>
                    <div class="info-links-section" id="info-links-general">
                        <div class="info-links-header">
                            <i data-lucide="link"></i>
                            <span>Länkar</span>
                        </div>
                        <div class="info-links-list">
                            <?php foreach ($eventInfoLinks['general'] ?? [] as $link): ?>
                            <div class="info-link-row">
                                <input type="url" name="info_link_general_url[]" class="admin-form-input" placeholder="https://..." value="<?= h($link['link_url'] ?? '') ?>">
                                <input type="text" name="info_link_general_text[]" class="admin-form-input" placeholder="Visningsnamn (valfritt)" value="<?= h($link['link_text'] ?? '') ?>">
                                <button type="button" onclick="this.closest('.info-link-row').remove()" class="btn-admin btn-admin-danger info-link-remove" title="Ta bort länk"><i data-lucide="x" style="width:14px;height:14px;"></i></button>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" onclick="addInfoLink('general')" class="btn-admin btn-admin-ghost info-link-add">
                            <i data-lucide="plus" style="width:14px;height:14px;"></i> Lägg till länk
                        </button>
                    </div>
                </div>

                <!-- Regelverk -->
                <div class="facility-field">
                    <div class="facility-field-header">
                        <div class="facility-field-label">
                            <i data-lucide="book-open"></i>
                            <span>Regelverk</span>
                        </div>
                        <div class="global-toggle-group">
                            <label class="global-toggle <?= empty($event['regulations_global_type'] ?? '') ? 'active' : '' ?>">
                                <input type="radio" name="regulations_global_type" value="" <?= empty($event['regulations_global_type'] ?? '') ? 'checked' : '' ?>>
                                <span>Egen text</span>
                            </label>
                            <label class="global-toggle <?= ($event['regulations_global_type'] ?? '') === 'sportmotion' ? 'active' : '' ?>">
                                <input type="radio" name="regulations_global_type" value="sportmotion" <?= ($event['regulations_global_type'] ?? '') === 'sportmotion' ? 'checked' : '' ?>>
                                <span>sportMotion</span>
                            </label>
                            <label class="global-toggle <?= ($event['regulations_global_type'] ?? '') === 'competition' ? 'active' : '' ?>">
                                <input type="radio" name="regulations_global_type" value="competition" <?= ($event['regulations_global_type'] ?? '') === 'competition' ? 'checked' : '' ?>>
                                <span>Tävling</span>
                            </label>
                        </div>
                    </div>
                    <textarea name="regulations_info" class="facility-textarea" rows="4" data-format-toolbar placeholder="Regelverk och bestämmelser..."><?= h($event['regulations_info'] ?? '') ?></textarea>
                    <div class="info-links-section" id="info-links-regulations">
                        <div class="info-links-header">
                            <i data-lucide="link"></i>
                            <span>Länkar</span>
                        </div>
                        <div class="info-links-list">
                            <?php foreach ($eventInfoLinks['regulations'] ?? [] as $link): ?>
                            <div class="info-link-row">
                                <input type="url" name="info_link_regulations_url[]" class="admin-form-input" placeholder="https://..." value="<?= h($link['link_url'] ?? '') ?>">
                                <input type="text" name="info_link_regulations_text[]" class="admin-form-input" placeholder="Visningsnamn (valfritt)" value="<?= h($link['link_text'] ?? '') ?>">
                                <button type="button" onclick="this.closest('.info-link-row').remove()" class="btn-admin btn-admin-danger info-link-remove" title="Ta bort länk"><i data-lucide="x" style="width:14px;height:14px;"></i></button>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" onclick="addInfoLink('regulations')" class="btn-admin btn-admin-ghost info-link-add">
                            <i data-lucide="plus" style="width:14px;height:14px;"></i> Lägg till länk
                        </button>
                    </div>
                </div>

                <!-- Licenser -->
                <div class="facility-field">
                    <div class="facility-field-header">
                        <div class="facility-field-label">
                            <i data-lucide="id-card"></i>
                            <span>Licenser</span>
                        </div>
                        <label class="global-toggle">
                            <input type="checkbox" name="license_use_global" <?= !empty($event['license_use_global'] ?? '') ? 'checked' : '' ?>>
                            <span>Global</span>
                        </label>
                    </div>
                    <textarea name="license_info" class="facility-textarea" rows="4" data-format-toolbar placeholder="Licensinformation..."><?= h($event['license_info'] ?? '') ?></textarea>
                    <div class="info-links-section" id="info-links-licenses">
                        <div class="info-links-header">
                            <i data-lucide="link"></i>
                            <span>Länkar</span>
                        </div>
                        <div class="info-links-list">
                            <?php foreach ($eventInfoLinks['licenses'] ?? [] as $link): ?>
                            <div class="info-link-row">
                                <input type="url" name="info_link_licenses_url[]" class="admin-form-input" placeholder="https://..." value="<?= h($link['link_url'] ?? '') ?>">
                                <input type="text" name="info_link_licenses_text[]" class="admin-form-input" placeholder="Visningsnamn (valfritt)" value="<?= h($link['link_text'] ?? '') ?>">
                                <button type="button" onclick="this.closest('.info-link-row').remove()" class="btn-admin btn-admin-danger info-link-remove" title="Ta bort länk"><i data-lucide="x" style="width:14px;height:14px;"></i></button>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" onclick="addInfoLink('licenses')" class="btn-admin btn-admin-ghost info-link-add">
                            <i data-lucide="plus" style="width:14px;height:14px;"></i> Lägg till länk
                        </button>
                    </div>
                </div>

                <!-- Tävlingsklasser -->
                <div class="facility-field">
                    <div class="facility-field-header">
                        <div class="facility-field-label">
                            <i data-lucide="trophy"></i>
                            <span>Tävlingsklasser</span>
                        </div>
                        <label class="global-toggle">
                            <input type="checkbox" name="competition_classes_use_global" <?= !empty($event['competition_classes_use_global']) ? 'checked' : '' ?>>
                            <span>Global</span>
                        </label>
                    </div>
                    <textarea name="competition_classes_info" class="facility-textarea" rows="6" data-format-toolbar placeholder="Beskrivning av tävlingsklasser..."><?= h($event['competition_classes_info'] ?? '') ?></textarea>
                    <?php $classLinks = $eventInfoLinks['competition_classes'] ?? []; ?>
                    <div class="info-links-section" id="info-links-competition_classes">
                        <div class="info-links-header"><i data-lucide="link"></i><span>Länkar</span></div>
                        <div class="info-links-list">
                            <?php foreach ($classLinks as $link): ?>
                            <div class="info-link-row">
                                <input type="url" name="info_link_competition_classes_url[]" class="admin-form-input" placeholder="https://..." value="<?= h($link['link_url'] ?? '') ?>">
                                <input type="text" name="info_link_competition_classes_text[]" class="admin-form-input" placeholder="Visningsnamn (valfritt)" value="<?= h($link['link_text'] ?? '') ?>">
                                <button type="button" onclick="this.closest('.info-link-row').remove()" class="btn-admin btn-admin-danger info-link-remove" title="Ta bort länk"><i data-lucide="x" style="width:14px;height:14px;"></i></button>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" onclick="addInfoLink('competition_classes')" class="btn-admin btn-admin-ghost info-link-add">
                            <i data-lucide="plus" style="width:14px;height:14px;"></i> Lägg till länk
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </details>

    <!-- FACILITETER & LOGISTIK - Own section -->
    <details class="admin-card mb-lg">
        <summary class="admin-card-header collapsible-header">
            <h2>Faciliteter & Logistik</h2>
            <span class="text-secondary text-sm">Visas på egen flik på event-sidan. Lämna tomt = visas ej.</span>
        </summary>
        <div class="admin-card-body">
            <?php
            $facilityFields = [
                ['key' => 'hydration_stations', 'label' => 'Vätskekontroller', 'global_key' => 'hydration_use_global', 'icon' => 'droplet'],
                ['key' => 'toilets_showers', 'label' => 'Toaletter/Dusch', 'global_key' => 'toilets_use_global', 'icon' => 'bath'],
                ['key' => 'bike_wash', 'label' => 'Cykeltvätt', 'global_key' => 'bike_wash_use_global', 'icon' => 'sparkles'],
                ['key' => 'food_cafe', 'label' => 'Mat/Café', 'global_key' => 'food_use_global', 'icon' => 'utensils'],
                ['key' => 'shops_info', 'label' => 'Affärer', 'global_key' => 'shops_use_global', 'icon' => 'store'],
                ['key' => 'exhibitors', 'label' => 'Utställare', 'global_key' => 'exhibitors_use_global', 'icon' => 'tent'],
                ['key' => 'parking_detailed', 'label' => 'Parkering', 'global_key' => 'parking_use_global', 'icon' => 'car'],
                ['key' => 'hotel_accommodation', 'label' => 'Hotell/Boende', 'global_key' => 'hotel_use_global', 'icon' => 'bed'],
                ['key' => 'local_info', 'label' => 'Lokal information', 'global_key' => 'local_use_global', 'icon' => 'info'],
                ['key' => 'media_production', 'label' => 'Media', 'global_key' => 'media_use_global', 'icon' => 'camera'],
                ['key' => 'contacts_info', 'label' => 'Kontakter', 'global_key' => 'contacts_use_global', 'icon' => 'phone'],
            ];
            ?>

            <div class="facility-fields">
                <?php foreach ($facilityFields as $field):
                    $linkSection = $field['key'];
                    $sectionLinks = $eventInfoLinks[$linkSection] ?? [];
                ?>
                    <div class="facility-field">
                        <div class="facility-field-header">
                            <div class="facility-field-label">
                                <i data-lucide="<?= $field['icon'] ?>"></i>
                                <span><?= $field['label'] ?></span>
                            </div>
                            <label class="global-toggle">
                                <input type="checkbox" name="<?= $field['global_key'] ?>" <?= !empty($event[$field['global_key']]) ? 'checked' : '' ?>>
                                <span>Global</span>
                            </label>
                        </div>
                        <textarea name="<?= $field['key'] ?>" class="facility-textarea" rows="4" data-format-toolbar placeholder="Skriv information här..."><?= h($event[$field['key']] ?? '') ?></textarea>
                        <div class="info-links-section" id="info-links-<?= $linkSection ?>">
                            <div class="info-links-header"><i data-lucide="link"></i><span>Länkar</span></div>
                            <div class="info-links-list">
                                <?php foreach ($sectionLinks as $link): ?>
                                <div class="info-link-row">
                                    <input type="url" name="info_link_<?= $linkSection ?>_url[]" class="admin-form-input" placeholder="https://..." value="<?= h($link['link_url'] ?? '') ?>">
                                    <input type="text" name="info_link_<?= $linkSection ?>_text[]" class="admin-form-input" placeholder="Visningsnamn (valfritt)" value="<?= h($link['link_text'] ?? '') ?>">
                                    <button type="button" onclick="this.closest('.info-link-row').remove()" class="btn-admin btn-admin-danger info-link-remove" title="Ta bort länk"><i data-lucide="x" style="width:14px;height:14px;"></i></button>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <button type="button" onclick="addInfoLink('<?= $linkSection ?>')" class="btn-admin btn-admin-ghost info-link-add">
                                <i data-lucide="plus" style="width:14px;height:14px;"></i> Lägg till länk
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </details>

    <!-- PM (PROMEMORIA) - Collapsible -->
    <details class="admin-card mb-lg">
        <summary class="admin-card-header collapsible-header">
            <h2>PM (Promemoria)</h2>
            <span class="text-secondary text-sm">Klicka för att expandera</span>
        </summary>
        <div class="admin-card-body">
            <!-- Publish date/time for PM -->
            <div class="admin-form-group mb-lg pb-md border-bottom">
                <div class="flex items-center gap-sm flex-wrap">
                    <label class="text-sm font-medium whitespace-nowrap">
                        <i data-lucide="calendar-clock" class="icon-sm inline-block"></i>
                        Publiceras:
                    </label>
                    <input type="datetime-local" name="pm_publish_at"
                           class="admin-form-input admin-form-input-sm"
                           style="max-width: 220px;"
                           value="<?= !empty($event['pm_publish_at']) ? date('Y-m-d\TH:i', strtotime($event['pm_publish_at'])) : '' ?>">
                    <small class="form-help text-xs">Lämna tomt = synlig direkt</small>
                </div>
            </div>

            <div class="facility-section-header">
                <h3>PM Innehåll</h3>
                <p>Innehåll för PM-fliken. Markera "Global" för att använda standardtext.</p>
            </div>

            <?php
            $pmFields = [
                ['key' => 'pm_content', 'label' => 'PM Huvudtext', 'global_key' => 'pm_use_global', 'icon' => 'file-text'],
                ['key' => 'driver_meeting', 'label' => 'Förarmöte', 'global_key' => 'driver_meeting_use_global', 'icon' => 'users'],
                ['key' => 'training_info', 'label' => 'Träning', 'global_key' => 'training_use_global', 'icon' => 'bike'],
                ['key' => 'timing_info', 'label' => 'Tidtagning', 'global_key' => 'timing_use_global', 'icon' => 'timer'],
                ['key' => 'lift_info', 'label' => 'Lift', 'global_key' => 'lift_use_global', 'icon' => 'cable-car'],
                ['key' => 'competition_rules', 'label' => 'Tävlingsregler', 'global_key' => 'rules_use_global', 'icon' => 'scroll-text'],
                ['key' => 'insurance_info', 'label' => 'Försäkring', 'global_key' => 'insurance_use_global', 'icon' => 'shield-check'],
                ['key' => 'equipment_info', 'label' => 'Utrustning', 'global_key' => 'equipment_use_global', 'icon' => 'hard-hat'],
                ['key' => 'medical_info', 'label' => 'Sjukvård', 'global_key' => 'medical_use_global', 'icon' => 'heart-pulse'],
                ['key' => 'scf_representatives', 'label' => 'SCF Representanter', 'global_key' => 'scf_use_global', 'icon' => 'badge-check'],
            ];
            ?>

            <div class="facility-fields">
                <?php foreach ($pmFields as $field):
                    $linkSection = $field['key'];
                    $sectionLinks = $eventInfoLinks[$linkSection] ?? [];
                ?>
                    <div class="facility-field">
                        <div class="facility-field-header">
                            <div class="facility-field-label">
                                <i data-lucide="<?= $field['icon'] ?>"></i>
                                <span><?= $field['label'] ?></span>
                            </div>
                            <label class="global-toggle">
                                <input type="checkbox" name="<?= $field['global_key'] ?>" <?= !empty($event[$field['global_key']]) ? 'checked' : '' ?>>
                                <span>Global</span>
                            </label>
                        </div>
                        <textarea name="<?= $field['key'] ?>" class="facility-textarea" rows="4" data-format-toolbar placeholder="Skriv information här..."><?= h($event[$field['key']] ?? '') ?></textarea>
                        <div class="info-links-section" id="info-links-<?= $linkSection ?>">
                            <div class="info-links-header"><i data-lucide="link"></i><span>Länkar</span></div>
                            <div class="info-links-list">
                                <?php foreach ($sectionLinks as $link): ?>
                                <div class="info-link-row">
                                    <input type="url" name="info_link_<?= $linkSection ?>_url[]" class="admin-form-input" placeholder="https://..." value="<?= h($link['link_url'] ?? '') ?>">
                                    <input type="text" name="info_link_<?= $linkSection ?>_text[]" class="admin-form-input" placeholder="Visningsnamn (valfritt)" value="<?= h($link['link_text'] ?? '') ?>">
                                    <button type="button" onclick="this.closest('.info-link-row').remove()" class="btn-admin btn-admin-danger info-link-remove" title="Ta bort länk"><i data-lucide="x" style="width:14px;height:14px;"></i></button>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <button type="button" onclick="addInfoLink('<?= $linkSection ?>')" class="btn-admin btn-admin-ghost info-link-add">
                                <i data-lucide="plus" style="width:14px;height:14px;"></i> Lägg till länk
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </details>

    <!-- ÖVRIGA EVENT-FLIKAR - Collapsible -->
    <details class="admin-card mb-lg">
        <summary class="admin-card-header collapsible-header">
            <h2>Övriga event-flikar</h2>
            <span class="text-secondary text-sm">Jury, Schema, Starttider, Bansträckningar</span>
        </summary>
        <div class="admin-card-body">
            <div class="facility-section-header">
                <h3>Separata flikar</h3>
                <p>Visas om innehåll finns (eller global text aktiverad).</p>
            </div>

            <?php
            $otherTabFields = [
                ['key' => 'jury_communication', 'label' => 'Jurykommuniké', 'global_key' => 'jury_use_global', 'icon' => 'gavel'],
                ['key' => 'competition_schedule', 'label' => 'Tävlingsschema', 'global_key' => 'schedule_use_global', 'icon' => 'calendar-days'],
                ['key' => 'start_times', 'label' => 'Starttider', 'global_key' => 'start_times_use_global', 'icon' => 'clock', 'publish_key' => 'starttider_publish_at'],
                ['key' => 'course_tracks', 'label' => 'Bansträckningar', 'global_key' => 'course_tracks_use_global', 'icon' => 'route'],
            ];
            ?>

            <div class="facility-fields">
                <?php foreach ($otherTabFields as $field):
                    $linkSection = $field['key'];
                    $sectionLinks = $eventInfoLinks[$linkSection] ?? [];
                ?>
                    <div class="facility-field">
                        <div class="facility-field-header">
                            <div class="facility-field-label">
                                <i data-lucide="<?= $field['icon'] ?>"></i>
                                <span><?= $field['label'] ?></span>
                            </div>
                            <label class="global-toggle">
                                <input type="checkbox" name="<?= $field['global_key'] ?>" <?= !empty($event[$field['global_key']]) ? 'checked' : '' ?>>
                                <span>Global</span>
                            </label>
                        </div>
                        <textarea name="<?= $field['key'] ?>" class="facility-textarea" rows="4" data-format-toolbar placeholder="Skriv information här..."><?= h($event[$field['key']] ?? '') ?></textarea>
                        <?php if (!empty($field['publish_key'])): ?>
                        <div class="publish-date-row">
                            <i data-lucide="calendar-clock"></i>
                            <span>Publiceras:</span>
                            <input type="datetime-local" name="<?= $field['publish_key'] ?>"
                                   class="admin-form-input"
                                   value="<?= !empty($event[$field['publish_key']]) ? date('Y-m-d\TH:i', strtotime($event[$field['publish_key']])) : '' ?>">
                            <span class="text-muted">Lämna tomt = synlig direkt</span>
                        </div>
                        <?php endif; ?>
                        <div class="info-links-section" id="info-links-<?= $linkSection ?>">
                            <div class="info-links-header"><i data-lucide="link"></i><span>Länkar</span></div>
                            <div class="info-links-list">
                                <?php foreach ($sectionLinks as $link): ?>
                                <div class="info-link-row">
                                    <input type="url" name="info_link_<?= $linkSection ?>_url[]" class="admin-form-input" placeholder="https://..." value="<?= h($link['link_url'] ?? '') ?>">
                                    <input type="text" name="info_link_<?= $linkSection ?>_text[]" class="admin-form-input" placeholder="Visningsnamn (valfritt)" value="<?= h($link['link_text'] ?? '') ?>">
                                    <button type="button" onclick="this.closest('.info-link-row').remove()" class="btn-admin btn-admin-danger info-link-remove" title="Ta bort länk"><i data-lucide="x" style="width:14px;height:14px;"></i></button>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <button type="button" onclick="addInfoLink('<?= $linkSection ?>')" class="btn-admin btn-admin-ghost info-link-add">
                                <i data-lucide="plus" style="width:14px;height:14px;"></i> Lägg till länk
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Map Link -->
            <div class="admin-form-group mt-lg">
                <label class="admin-form-label">Karta</label>
                <div class="flex gap-sm items-center">
                    <?php if (!empty($event['map_image_url'])): ?>
                    <span class="badge badge-success">Kartbild aktiv</span>
                    <?php endif; ?>
                    <a href="/admin/event-map.php?id=<?= $id ?>" class="btn-admin btn-admin-secondary btn-admin-sm">
                        <i data-lucide="map" class="icon-sm"></i>
                        Hantera karta
                    </a>
                </div>
                <small class="form-help">Ladda upp GPX-fil för interaktiv karta eller statisk kartbild.</small>
            </div>
        </div>
    </details>

    <!-- ========================================================= -->
    <!-- SPONSORER & PARTNERS: Bildbaserad sponsorväljare          -->
    <!-- ========================================================= -->
    <details class="admin-card mb-lg">
        <summary class="admin-card-header collapsible-header">
            <h2>
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="icon-md"><path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/></svg>
                Sponsorer &amp; Partners
            </h2>
            <span class="text-secondary text-sm">Klicka för att expandera/minimera</span>
        </summary>
        <div class="admin-card-body">
            <p class="mb-lg text-secondary text-sm">
                Välj bilder till sponsorplatserna. Ladda upp bilder via
                <a href="/admin/media?folder=sponsors" target="_blank">Mediabiblioteket</a> först.
                <?php if (!$isPromotorOnly): ?>
                <br>Seriens sponsorer visas om inga event-sponsorer anges.
                <?php endif; ?>
            </p>

            <div class="flex flex-col gap-lg">
                <!-- Banner sponsor -->
                <div class="admin-form-group">
                    <label class="admin-form-label">Banner-sponsor (bred bild högst upp)</label>
                    <div id="pl-header" class="placement-imgs"></div>
                    <div id="pl-header-inputs"></div>
                    <button type="button" class="btn btn-sm btn-secondary mt-sm" onclick="openImgPicker('header',1)">
                        <i data-lucide="image-plus" class="icon-sm"></i> Välj bild
                    </button>
                    <small class="form-help block mt-sm">Rekommenderad storlek: 1200 x 150 px</small>
                </div>

                <!-- Logo row -->
                <div class="admin-form-group">
                    <label class="admin-form-label">
                        Logo-rad (under event-info)
                        <span id="pl-content-count" class="font-normal text-secondary ml-sm"></span>
                    </label>
                    <div id="pl-content" class="placement-imgs"></div>
                    <div id="pl-content-inputs"></div>
                    <button type="button" class="btn btn-sm btn-secondary mt-sm" onclick="openImgPicker('content',5)">
                        <i data-lucide="image-plus" class="icon-sm"></i> Lägg till bild
                    </button>
                    <small class="form-help block mt-sm">Max 5. Rekommenderad storlek: 600 x 150 px</small>
                </div>

                <!-- Results sponsor -->
                <div class="admin-form-group">
                    <label class="admin-form-label">Resultat-sponsor</label>
                    <div id="pl-sidebar" class="placement-imgs"></div>
                    <div id="pl-sidebar-inputs"></div>
                    <button type="button" class="btn btn-sm btn-secondary mt-sm" onclick="openImgPicker('sidebar',1)">
                        <i data-lucide="image-plus" class="icon-sm"></i> Välj bild
                    </button>
                    <small class="form-help block mt-sm">Visas som "Resultat sponsrat av..."</small>
                </div>

                <!-- Partners -->
                <div class="admin-form-group">
                    <label class="admin-form-label">
                        Samarbetspartners
                        <span id="pl-partner-count" class="font-normal text-secondary ml-sm"></span>
                    </label>
                    <div id="pl-partner" class="placement-imgs"></div>
                    <div id="pl-partner-inputs"></div>
                    <button type="button" class="btn btn-sm btn-secondary mt-sm" onclick="openImgPicker('partner',0)">
                        <i data-lucide="image-plus" class="icon-sm"></i> Lägg till bild
                    </button>
                    <small class="form-help block mt-sm">Visas längst ner på event-sidan</small>
                </div>
            </div>
        </div>
    </details>

    <!-- Image Picker Modal -->
    <div id="imgPickerModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;z-index:9999;background:rgba(0,0,0,0.6);">
        <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:92%;max-width:700px;background:var(--color-bg-surface);border-radius:var(--radius-lg);border:1px solid var(--color-border);max-height:85vh;display:flex;flex-direction:column;">
            <div style="padding:var(--space-md) var(--space-lg);border-bottom:1px solid var(--color-border);display:flex;justify-content:space-between;align-items:center;">
                <h3 style="margin:0;font-size:1rem;">Välj bild</h3>
                <button type="button" onclick="closeImgPicker()" style="background:none;border:none;cursor:pointer;color:var(--color-text-secondary);font-size:1.5rem;line-height:1;">&times;</button>
            </div>
            <div style="padding:var(--space-md);border-bottom:1px solid var(--color-border);display:flex;gap:var(--space-sm);align-items:center;flex-wrap:wrap;">
                <input type="file" id="imgPickerUpload" accept="image/*" style="display:none" onchange="uploadAndPick(this)">
                <button type="button" class="btn btn-sm btn-primary" onclick="document.getElementById('imgPickerUpload').click()">
                    <i data-lucide="upload" class="icon-sm"></i> Ladda upp ny bild
                </button>
                <span class="text-secondary text-sm">eller välj en befintlig nedan</span>
            </div>
            <div id="imgPickerGrid" style="padding:var(--space-md);overflow-y:auto;flex:1;display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:var(--space-sm);"></div>
        </div>
    </div>

    <!-- STATUS & ACTIONS -->
    <div class="admin-card">
        <div class="admin-card-body">
            <div class="flex items-center justify-between flex-wrap gap-md">
                <?php if ($isPromotorOnly): ?>
                <!-- Readonly status display for promotors -->
                <div class="flex items-center gap-sm text-secondary">
                    <input type="checkbox" disabled <?= $event['active'] ? 'checked' : '' ?>>
                    <span>Aktivt event</span>
                </div>
                <div class="flex items-center gap-sm text-secondary">
                    <input type="checkbox" disabled <?= !empty($event['is_championship']) ? 'checked' : '' ?>>
                    <span><i data-lucide="trophy" class="icon-sm"></i> Svenskt Mästerskap</span>
                    <?php if (!empty($event['is_championship'])): ?>
                    <span class="admin-badge admin-badge-success text-xs ml-sm">SM</span>
                    <?php endif; ?>
                </div>
                <!-- Hidden fields to preserve values -->
                <input type="hidden" name="active" value="<?= $event['active'] ? '1' : '0' ?>">
                <input type="hidden" name="is_championship" value="<?= !empty($event['is_championship']) ? '1' : '0' ?>">
                <?php else: ?>
                <label class="flex items-center gap-sm cursor-pointer">
                    <input type="checkbox" name="active" <?= $event['active'] ? 'checked' : '' ?>>
                    <span>Aktivt event</span>
                </label>

                <label class="flex items-center gap-sm cursor-pointer">
                    <input type="checkbox" name="is_championship" value="1" <?= !empty($event['is_championship']) ? 'checked' : '' ?>>
                    <span><i data-lucide="trophy" class="icon-sm"></i> Svenskt Mästerskap</span>
                    <?php if (!empty($event['is_championship'])): ?>
                    <span class="admin-badge admin-badge-success text-xs ml-sm">SM</span>
                    <?php endif; ?>
                </label>
                <?php endif; ?>

                <div class="flex gap-sm">
                    <a href="/admin/events" class="btn-admin btn-admin-secondary">Avbryt</a>
                    <button type="submit" class="btn-admin btn-admin-primary">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="icon-sm"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                        Spara Ändringar
                    </button>
                </div>
            </div>
        </div>
    </div>
</form>

<!-- Floating Save Button -->
<div class="floating-save-bar">
    <div class="floating-save-content">
        <a href="/admin/events" class="btn-admin btn-admin-secondary">Avbryt</a>
        <button type="submit" form="eventEditForm" class="btn-admin btn-admin-primary">
            <i data-lucide="save" class="icon-sm"></i>
            Spara Ändringar
        </button>
    </div>
</div>

<style>
/* ===== FLOATING SAVE BAR ===== */
.floating-save-bar {
    position: fixed;
    bottom: 0;
    left: 0;
    right: 0;
    background: var(--color-bg-surface);
    border-top: 1px solid var(--color-border);
    box-shadow: 0 -4px 12px rgba(0,0,0,0.1);
    padding: var(--space-xs) var(--space-md);
    z-index: 1000;
    padding-bottom: calc(var(--space-xs) + env(safe-area-inset-bottom, 0));
}
@media (min-width: 769px) {
    .floating-save-bar {
        left: var(--sidebar-width, 280px);
    }
}
@media (max-width: 899px) and (orientation: portrait) {
    .floating-save-bar {
        /* Snap directly on top of mobile nav - no extra gap */
        bottom: var(--mobile-nav-height, 64px);
        padding: var(--space-xs) var(--space-sm);
        padding-bottom: var(--space-xs);
    }
}
.floating-save-content {
    display: flex;
    justify-content: flex-end;
    gap: var(--space-sm);
    max-width: 1200px;
    margin: 0 auto;
}
.admin-content {
    padding-bottom: 70px !important;
}
@media (max-width: 899px) and (orientation: portrait) {
    .admin-content {
        padding-bottom: calc(60px + var(--mobile-nav-height, 64px)) !important;
    }
}

/* ===== FORM SUB-SECTIONS - Visual grouping within cards ===== */
.form-subsection {
    padding-bottom: var(--space-md);
    margin-bottom: var(--space-md);
    border-bottom: 1px solid var(--color-border);
}
.form-subsection:last-child {
    padding-bottom: 0;
    margin-bottom: 0;
    border-bottom: none;
}
.form-subsection-label {
    display: flex;
    align-items: center;
    gap: var(--space-xs);
    font-size: var(--text-xs);
    font-weight: 600;
    color: var(--color-text-muted);
    text-transform: uppercase;
    letter-spacing: 0.05em;
    margin-bottom: var(--space-md);
}
.form-subsection-label i {
    width: 14px;
    height: 14px;
    color: var(--color-accent);
}

/* ===== FORM GRID IMPROVEMENTS ===== */
.form-grid.form-grid-2 {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: var(--space-md);
}
.form-grid.form-grid-3 {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: var(--space-md);
}

/* ===== FACILITY FIELDS ===== */
.facility-section-header {
    margin-bottom: var(--space-lg);
    padding-bottom: var(--space-md);
    border-bottom: 1px solid var(--color-border);
}
.facility-section-header h3 {
    font-size: var(--text-lg);
    font-weight: 600;
    margin: 0 0 var(--space-xs) 0;
    color: var(--color-text-primary);
}
.facility-section-header p {
    font-size: var(--text-sm);
    color: var(--color-text-secondary);
    margin: 0;
}
.facility-fields {
    display: flex;
    flex-direction: column;
    gap: var(--space-md);
}
.facility-field {
    background: var(--color-bg-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    overflow: hidden;
}
.facility-field-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: var(--space-sm) var(--space-md);
    background: var(--color-bg-hover);
    border-bottom: 1px solid var(--color-border);
}
.facility-field-label {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    font-weight: 600;
    font-size: var(--text-sm);
    color: var(--color-text-primary);
}
.facility-field-label i {
    width: 18px;
    height: 18px;
    color: var(--color-accent);
}

/* ===== GLOBAL TOGGLE ===== */
.global-toggle {
    display: flex;
    align-items: center;
    gap: var(--space-xs);
    padding: var(--space-2xs) var(--space-sm);
    background: var(--color-bg-card);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-full);
    font-size: var(--text-xs);
    color: var(--color-text-secondary);
    cursor: pointer;
    transition: all 0.15s ease;
}
.global-toggle:hover {
    border-color: var(--color-accent);
}
.global-toggle:has(input:checked) {
    background: var(--color-accent-light);
    border-color: var(--color-accent);
    color: var(--color-accent);
}
.global-toggle input {
    margin: 0;
    width: 14px;
    height: 14px;
    accent-color: var(--color-accent);
}
.global-toggle span {
    font-weight: 500;
}
.global-toggle-group {
    display: flex;
    gap: var(--space-2xs);
    flex-wrap: wrap;
}

/* ===== TEXTAREA ===== */
.facility-textarea {
    width: 100%;
    min-height: 100px;
    padding: var(--space-md);
    border: none;
    resize: vertical;
    font-size: var(--text-sm);
    line-height: 1.6;
    background: var(--color-bg-card);
    color: var(--color-text-primary);
}
.facility-textarea:focus {
    outline: none;
    background: var(--color-bg-surface);
}
.facility-textarea::placeholder {
    color: var(--color-text-muted);
}

/* ===== PUBLISH DATE ROW ===== */
.publish-date-row {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    padding: var(--space-sm) var(--space-md);
    background: var(--color-bg-hover);
    border-top: 1px solid var(--color-border);
    font-size: var(--text-xs);
    color: var(--color-text-secondary);
    flex-wrap: wrap;
}
.publish-date-row i {
    width: 14px;
    height: 14px;
}
.publish-date-row input {
    padding: var(--space-xs) var(--space-sm);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    font-size: var(--text-xs);
    background: var(--color-bg-card);
}
.publish-date-row .text-muted {
    color: var(--color-text-muted);
}

/* ===== INFO LINKS ===== */
.info-links-section {
    border-top: 1px solid var(--color-border);
    background: var(--color-bg-hover);
}
.info-links-header {
    display: flex;
    align-items: center;
    gap: var(--space-xs);
    padding: var(--space-sm) var(--space-md) var(--space-xs);
    font-size: var(--text-xs);
    font-weight: 600;
    color: var(--color-text-secondary);
    text-transform: uppercase;
    letter-spacing: 0.05em;
}
.info-links-header i {
    width: 14px;
    height: 14px;
    color: var(--color-accent);
}
.info-links-list {
    padding: 0 var(--space-md);
}
.info-link-row {
    display: grid;
    grid-template-columns: 1fr 1fr auto;
    gap: var(--space-xs);
    margin-bottom: var(--space-xs);
    align-items: center;
}
.info-link-row .admin-form-input {
    font-size: var(--text-sm);
    padding: var(--space-xs) var(--space-sm);
    height: 36px;
}
.info-link-remove {
    padding: var(--space-xs) !important;
    height: 36px !important;
    width: 36px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}
.info-link-add {
    margin: var(--space-xs) var(--space-md) var(--space-sm);
    font-size: var(--text-xs) !important;
}

/* ===== LOCKED SECTION BADGE ===== */
.locked-badge {
    display: inline-flex;
    align-items: center;
    gap: var(--space-2xs);
    font-size: var(--text-xs);
    color: var(--color-text-muted);
    padding: var(--space-2xs) var(--space-sm);
    background: var(--color-bg-hover);
    border-radius: var(--radius-full);
}
.locked-badge i {
    width: 12px;
    height: 12px;
}

/* ========================================================
   MOBILE: Form layout improvements for event-edit
   Edge-to-edge handled globally by admin-color-fix.css
   ======================================================== */
@media (max-width: 767px) {
    /* Form grids collapse to single column */
    .form-grid,
    .form-grid.form-grid-2,
    .form-grid.form-grid-3 {
        grid-template-columns: 1fr !important;
        gap: var(--space-md) !important;
    }

    /* Larger touch targets for inputs */
    .admin-form-input,
    .admin-form-select,
    .admin-form-textarea,
    input[type="text"],
    input[type="email"],
    input[type="tel"],
    input[type="url"],
    input[type="number"],
    input[type="date"],
    input[type="time"],
    input[type="datetime-local"],
    select,
    textarea {
        min-height: 44px !important;
        font-size: 16px !important; /* Prevents iOS zoom */
    }

    /* Floating save bar: full width buttons */
    .floating-save-content {
        flex-direction: row;
    }
    .floating-save-content .btn-admin {
        flex: 1;
        justify-content: center;
    }

    /* Facility fields edge-to-edge within card */
    .facility-fields {
        gap: 0;
        margin-left: calc(-1 * var(--container-padding, 16px));
        margin-right: calc(-1 * var(--container-padding, 16px));
    }
    .facility-field {
        border-radius: 0;
        border-left: none;
        border-right: none;
        margin-bottom: -1px;
    }
    .facility-field-header {
        flex-wrap: wrap;
        gap: var(--space-xs);
    }

    /* Info link rows: stack on mobile */
    .info-link-row {
        grid-template-columns: 1fr auto;
    }
    .info-link-row input[type="text"] {
        grid-column: 1;
    }

    /* Collapsible header min height */
    .collapsible-header {
        min-height: 48px;
    }
    .collapsible-header h2 {
        font-size: var(--text-md) !important;
    }

    /* Sub-section labels */
    .form-subsection-label {
        padding-top: var(--space-xs);
    }
}

/* Promotor image-based sponsor picker */
.placement-imgs {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-sm);
    min-height: 40px;
}
.pl-tile {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    background: var(--color-bg-card);
    overflow: hidden;
    max-width: 200px;
    height: 60px;
    padding: var(--space-2xs);
}
.pl-tile img {
    max-width: 100%;
    max-height: 100%;
    object-fit: contain;
}
.pl-tile-text {
    font-size: 0.75rem;
    color: var(--color-text-secondary);
    padding: 0 var(--space-xs);
}
.pl-tile-remove {
    position: absolute;
    top: 0;
    right: 0;
    background: var(--color-error);
    color: #fff;
    border: none;
    cursor: pointer;
    width: 20px;
    height: 20px;
    font-size: 14px;
    line-height: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 0 var(--radius-sm) 0 var(--radius-sm);
    opacity: 0.8;
}
.pl-tile-remove:hover {
    opacity: 1;
}
.img-picker-item {
    cursor: pointer;
    border: 2px solid var(--color-border);
    border-radius: var(--radius-sm);
    overflow: hidden;
    aspect-ratio: 4/3;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    background: var(--color-bg-card);
    transition: border-color 0.15s;
}
.img-picker-item:hover {
    border-color: var(--color-accent);
}
.img-picker-item img {
    width: 100%;
    flex: 1;
    object-fit: contain;
    padding: var(--space-2xs);
}
.img-picker-name {
    font-size: 0.65rem;
    color: var(--color-text-muted);
    padding: 2px var(--space-2xs);
    text-align: center;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    width: 100%;
}
</style>

<script>
// =====================================================
// INFO LINKS: Add/remove links in general competition info
// =====================================================
function addInfoLink(section) {
    const container = document.getElementById('info-links-' + section);
    if (!container) return;
    const list = container.querySelector('.info-links-list');
    if (!list) return;
    const row = document.createElement('div');
    row.className = 'info-link-row';
    row.innerHTML = '<input type="url" name="info_link_' + section + '_url[]" class="admin-form-input" placeholder="https://...">'
        + '<input type="text" name="info_link_' + section + '_text[]" class="admin-form-input" placeholder="Visningsnamn (valfritt)">'
        + '<button type="button" onclick="this.closest(\'.info-link-row\').remove()" class="btn-admin btn-admin-danger info-link-remove" title="Ta bort länk"><i data-lucide="x" style="width:14px;height:14px;"></i></button>';
    list.appendChild(row);
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

// =====================================================
// PROMOTOR: Image-based sponsor placement picker
// =====================================================
const sponsorLookup = <?= json_encode($sponsorLookup) ?>;
const initPlacements = <?= json_encode($eventSponsors) ?>;
let pickerPlacement = null;
let pickerMax = 0;

// Track selections per placement
const placements = { header: [], content: [], sidebar: [], partner: [] };

function initPromotorSponsors() {
    // Populate from existing event_sponsors
    for (const [pl, ids] of Object.entries(initPlacements)) {
        ids.forEach(function(id) {
            const sp = sponsorLookup[id];
            if (sp) addToPlacement(pl, id, sp.name, sp.logo_url, false);
        });
    }
    updateCounts();
}

function addToPlacement(pl, sponsorId, name, logoUrl, updateCount) {
    // Prevent duplicates
    if (placements[pl].some(function(s){ return s.id === sponsorId; })) return;

    // Single-select: clear first
    if ((pl === 'header' || pl === 'sidebar') && placements[pl].length > 0) {
        clearPlacement(pl);
    }

    placements[pl].push({ id: sponsorId, name: name, logo_url: logoUrl });

    // Render image tile
    const container = document.getElementById('pl-' + pl);
    const tile = document.createElement('div');
    tile.className = 'pl-tile';
    tile.dataset.sponsorId = sponsorId;
    tile.innerHTML = (logoUrl
        ? '<img src="' + logoUrl + '" alt="' + (name||'') + '" title="' + (name||'') + '">'
        : '<span class="pl-tile-text">' + (name||'?') + '</span>')
        + '<button type="button" class="pl-tile-remove" onclick="removeFromPlacement(\'' + pl + '\',' + sponsorId + ')" title="Ta bort">&times;</button>';
    container.appendChild(tile);

    // Hidden form input
    const inputContainer = document.getElementById('pl-' + pl + '-inputs');
    const input = document.createElement('input');
    input.type = 'hidden';
    input.id = 'pl-input-' + pl + '-' + sponsorId;
    if (pl === 'header' || pl === 'sidebar') {
        input.name = 'sponsor_' + pl;
    } else {
        input.name = 'sponsor_' + pl + '[]';
    }
    input.value = sponsorId;
    inputContainer.appendChild(input);

    if (updateCount !== false) updateCounts();
}

function removeFromPlacement(pl, sponsorId) {
    placements[pl] = placements[pl].filter(function(s){ return s.id !== sponsorId; });
    const tile = document.querySelector('#pl-' + pl + ' .pl-tile[data-sponsor-id="' + sponsorId + '"]');
    if (tile) tile.remove();
    const input = document.getElementById('pl-input-' + pl + '-' + sponsorId);
    if (input) input.remove();
    updateCounts();
}

function clearPlacement(pl) {
    placements[pl] = [];
    document.getElementById('pl-' + pl).innerHTML = '';
    document.getElementById('pl-' + pl + '-inputs').innerHTML = '';
}

function updateCounts() {
    const cc = document.getElementById('pl-content-count');
    if (cc) cc.textContent = '(' + placements.content.length + '/5 valda)';
    const pc = document.getElementById('pl-partner-count');
    if (pc) pc.textContent = '(' + placements.partner.length + ' valda)';
}

function openImgPicker(pl, max) {
    // Check max
    if (max > 0 && placements[pl].length >= max) {
        alert('Max ' + max + ' bilder tillåtet');
        return;
    }
    pickerPlacement = pl;
    pickerMax = max;
    loadImgPickerGrid();
    document.getElementById('imgPickerModal').style.display = 'block';
}

function closeImgPicker() {
    document.getElementById('imgPickerModal').style.display = 'none';
    pickerPlacement = null;
}

async function loadImgPickerGrid() {
    const grid = document.getElementById('imgPickerGrid');
    grid.innerHTML = '<p style="color:var(--color-text-secondary);text-align:center;grid-column:1/-1;">Laddar bilder...</p>';

    try {
        const response = await fetch('/api/media.php?action=list&folder=sponsors&subfolders=1&limit=200');
        if (!response.ok) throw new Error('HTTP ' + response.status);
        const result = await response.json();
        grid.innerHTML = '';

        if (!result.success || !Array.isArray(result.data) || !result.data.length) {
            grid.innerHTML = '<p style="color:var(--color-text-secondary);text-align:center;grid-column:1/-1;">Inga bilder. Ladda upp en bild ovan eller via <a href="/admin/media?folder=sponsors" target="_blank">Mediabiblioteket</a>.</p>';
            return;
        }

        let imgCount = 0;
        result.data.forEach(function(media) {
            if (!media.mime_type || !media.mime_type.startsWith('image/')) return;
            const imgSrc = media.url || ('/' + media.filepath);
            const div = document.createElement('div');
            div.className = 'img-picker-item';
            div.innerHTML = '<img src="' + imgSrc + '" alt="' + (media.original_filename||'') + '" title="' + (media.original_filename||'') + '" onerror="this.style.display=\'none\'">'
                + '<span class="img-picker-name">' + (media.original_filename||'').substring(0,25) + '</span>';
            div.onclick = function() { selectMediaForPlacement(media.id, imgSrc); };
            grid.appendChild(div);
            imgCount++;
        });

        if (imgCount === 0) {
            grid.innerHTML = '<p style="color:var(--color-text-secondary);text-align:center;grid-column:1/-1;">Inga bilder hittades. Ladda upp via knappen ovan.</p>';
        }
    } catch (e) {
        console.error('Image picker error:', e);
        grid.innerHTML = '<p style="color:var(--color-error);text-align:center;grid-column:1/-1;">Kunde inte ladda bilder: ' + e.message + '</p>';
    }
}

async function uploadAndPick(input) {
    if (!input.files.length) return;
    const file = input.files[0];
    const formData = new FormData();
    formData.append('file', file);
    formData.append('folder', 'sponsors');

    try {
        const response = await fetch('/api/media.php?action=upload', { method: 'POST', body: formData });
        const result = await response.json();
        if (result.success && result.data) {
            const url = result.data.url || ('/' + result.data.filepath);
            await selectMediaForPlacement(result.data.id, url);
        } else {
            alert('Kunde inte ladda upp: ' + (result.error || 'Okänt fel'));
        }
    } catch (e) {
        alert('Uppladdningsfel');
    }
    input.value = '';
}

async function selectMediaForPlacement(mediaId, imageUrl) {
    // Auto find or create sponsor from media
    try {
        const response = await fetch('/api/sponsors.php?action=find_or_create_by_media&media_id=' + mediaId);
        const result = await response.json();
        if (result.success && result.data) {
            // If newly created, prompt for website URL
            if (result.created && !result.data.website) {
                const website = prompt('Ange sponsorns webbplats (krävs):', 'https://');
                if (website && website !== 'https://' && website.length > 5) {
                    // Update sponsor with website
                    await fetch('/api/sponsors.php?action=update_website', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ sponsor_id: result.data.id, website: website })
                    });
                }
            }
            addToPlacement(pickerPlacement, result.data.id, result.data.name, imageUrl, true);
            closeImgPicker();
        } else {
            alert('Fel: ' + (result.error || 'Okänt fel'));
        }
    } catch (e) {
        alert('Kunde inte skapa sponsor');
    }
}

// Init on page load
if (document.getElementById('pl-header')) {
    document.addEventListener('DOMContentLoaded', initPromotorSponsors);
}
</script>

<script>
// Limit checkbox selections for sponsor rows (maxCount = 0 means unlimited)
function setupCheckboxLimit(containerSelector, maxCount, countDisplayId) {
    const container = document.querySelector(containerSelector);
    if (!container) return;

    const checkboxes = container.querySelectorAll('input[type="checkbox"]');
    const countDisplay = document.getElementById(countDisplayId);
    const unlimited = maxCount === 0;

    function updateCount() {
        const checked = container.querySelectorAll('input[type="checkbox"]:checked').length;
        if (countDisplay) {
            if (unlimited) {
                countDisplay.textContent = `(${checked} valda)`;
                countDisplay.style.color = 'var(--color-text-secondary)';
            } else {
                countDisplay.textContent = `(${checked}/${maxCount} valda)`;
                countDisplay.style.color = checked >= maxCount ? 'var(--color-warning)' : 'var(--color-text-secondary)';
            }
        }
        return checked;
    }

    checkboxes.forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            const checked = updateCount();
            if (!unlimited && checked > maxCount) {
                this.checked = false;
                updateCount();
                alert(`Max ${maxCount} val tillåtet`);
            }
        });
    });

    updateCount();
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    setupCheckboxLimit('#logoRowSponsors', 5, 'logoRowCount');
    setupCheckboxLimit('#partnerSponsors', 0, 'partnerCount'); // 0 = unlimited
});

</script>

<?php include __DIR__ . '/components/format-toolbar.php'; ?>
<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>

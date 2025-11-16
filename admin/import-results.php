<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/import-history.php';
require_once __DIR__ . '/../includes/class-calculations.php';
require_admin();

$db = getDB();
$current_admin = get_current_admin();

$message = '';
$messageType = 'info';
$stats = null;
$matching_stats = null;
$errors = [];

// Handle CSV upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['import_file'])) {
    // Validate CSRF token
    checkCsrf();

    $file = $_FILES['import_file'];

    // Validate file
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $message = 'Filuppladdning misslyckades';
        $messageType = 'error';
    } elseif ($file['size'] > MAX_UPLOAD_SIZE) {
        $message = 'Filen är för stor (max ' . (MAX_UPLOAD_SIZE / 1024 / 1024) . 'MB)';
        $messageType = 'error';
    } else {
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if ($extension !== 'csv') {
            $message = 'Endast CSV-filer stöds för resultatimport';
            $messageType = 'error';
        } else {
            // Save file and redirect to preview
            $uploaded = UPLOADS_PATH . '/' . time() . '_preview_' . basename($file['name']);

            if (move_uploaded_file($file['tmp_name'], $uploaded)) {
                // Clear old preview data
                unset($_SESSION['import_preview_file']);
                unset($_SESSION['import_preview_filename']);
                unset($_SESSION['import_preview_data']);
                unset($_SESSION['import_events_summary']);

                // Store in session and redirect to preview
                $_SESSION['import_preview_file'] = $uploaded;
                $_SESSION['import_preview_filename'] = $file['name'];

                header('Location: /admin/import-results-preview.php');
                exit;
            } else {
                $message = 'Kunde inte ladda upp filen';
                $messageType = 'error';
            }
        }
    }
}

/**
 * Import results from CSV file
 */
function importResultsFromCSV($filepath, $db, $importId = null) {
    $stats = [
        'total' => 0,
        'success' => 0,
        'updated' => 0,
        'skipped' => 0,
        'failed' => 0
    ];

    $matching_stats = [
        'events_found' => 0,
        'events_not_found' => 0,
        'events_created' => 0,
        'venues_created' => 0,
        'riders_found' => 0,
        'riders_not_found' => 0,
        'riders_created' => 0,
        'clubs_created' => 0,
        'categories_created' => 0,
        'classes_created' => 0
    ];

    $errors = [];

    if (($handle = fopen($filepath, 'r')) === false) {
        throw new Exception('Kunde inte öppna filen');
    }

    // Auto-detect delimiter (comma or semicolon)
    $firstLine = fgets($handle);
    rewind($handle);
    $delimiter = (substr_count($firstLine, ';') > substr_count($firstLine, ',')) ? ';' : ',';

    // Read header row
    $header = fgetcsv($handle, 1000, $delimiter);

    if (!$header) {
        fclose($handle);
        throw new Exception('Tom fil eller ogiltigt format');
    }

    // Normalize header - accept multiple variants
    $header = array_map(function($col) {
        $col = mb_strtolower(trim($col), 'UTF-8');
        // Remove spaces, hyphens, underscores for comparison
        $col = str_replace([' ', '-', '_'], '', $col);

        // Map Swedish and English column names
        $mappings = [
            // Event fields
            'eventname' => 'event_name',
            'tävling' => 'event_name',
            'tavling' => 'event_name',
            'event' => 'event_name',
            'eventdate' => 'event_date',
            'tävlingsdatum' => 'event_date',
            'tavlingsdatum' => 'event_date',
            'datum' => 'event_date',
            'date' => 'event_date',
            'eventlocation' => 'event_location',
            'location' => 'event_location',
            'plats' => 'event_location',
            'ort' => 'event_location',
            'eventvenue' => 'event_venue',
            'venue' => 'event_venue',
            'bana' => 'event_venue',
            'anläggning' => 'event_venue',
            'anlaggning' => 'event_venue',

            // Rider fields
            'firstname' => 'firstname',
            'förnamn' => 'firstname',
            'fornamn' => 'firstname',
            'fname' => 'firstname',
            'first_name' => 'firstname',
            'lastname' => 'lastname',
            'efternamn' => 'lastname',
            'lname' => 'lastname',
            'surname' => 'lastname',
            'last_name' => 'lastname',

            // License fields
            'licensenumber' => 'license_number',
            'uciid' => 'license_number',
            'ucikod' => 'license_number',
            'sweid' => 'license_number',
            'licens' => 'license_number',
            'uci_id' => 'license_number',
            'swe_id' => 'license_number',
            'licensår' => 'license_year',
            'licensar' => 'license_year',
            'licenseyear' => 'license_year',
            'license_year' => 'license_year',
            'år' => 'license_year',
            'ar' => 'license_year',

            // Club/Team
            'club' => 'club_name',
            'clubname' => 'club_name',
            'team' => 'club_name',
            'klubb' => 'club_name',
            'club_name' => 'club_name',
            'huvudförening' => 'club_name',
            'huvudforening' => 'club_name',

            // Category (race category, not age/gender class)
            'category' => 'category',

            // Class (age/gender class)
            'class' => 'class_name',
            'klass' => 'class_name',
            'classname' => 'class_name',

            // Discipline
            'discipline' => 'discipline',
            'disciplin' => 'discipline',
            'gren' => 'discipline',

            // Contact
            'email' => 'email',
            'epost' => 'email',
            'e-post' => 'email',
            'mail' => 'email',

            'birthyear' => 'birth_year',
            'födelseår' => 'birth_year',
            'fodelsear' => 'birth_year',
            'födelsedatum' => 'birth_year',
            'fodelsedatum' => 'birth_year',
            'position' => 'position',
            'placering' => 'position',
            'finishtime' => 'finish_time',
            'sluttid' => 'finish_time',
            'tid' => 'finish_time',
            'time' => 'finish_time',
            'finish_time' => 'finish_time',
            'bibnumber' => 'bib_number',
            'startnummer' => 'bib_number',
            'bib' => 'bib_number',
            'status' => 'status',
            'points' => 'points',
            'poäng' => 'points',
            'poang' => 'points',
            'notes' => 'notes',
            'anteckningar' => 'notes',
            'gender' => 'gender',
            'kön' => 'gender',
            'kon' => 'gender',
            'kategori' => 'gender',
            'sex' => 'gender',

            // Split times for Enduro/DH
            'ss1' => 'ss1',
            'ss2' => 'ss2',
            'ss3' => 'ss3',
            'ss4' => 'ss4',
            'ss5' => 'ss5',
            'ss6' => 'ss6',
            'ss7' => 'ss7',
            'ss8' => 'ss8',
            'ss9' => 'ss9',
            'ss10' => 'ss10',
            'ss11' => 'ss11',
            'ss12' => 'ss12',
            'ss13' => 'ss13',
            'ss14' => 'ss14',
            'ss15' => 'ss15',
            'splittid1' => 'ss1',
            'splittid2' => 'ss2',
            'splittid3' => 'ss3',
            'splittid4' => 'ss4',
            'splittid5' => 'ss5',
            'splittid6' => 'ss6',
            'splittid7' => 'ss7',
            'splittid8' => 'ss8',
            'splittid9' => 'ss9',
            'splittid10' => 'ss10',
            'splittid11' => 'ss11',
            'splittid12' => 'ss12',
            'splittid13' => 'ss13',
            'splittid14' => 'ss14',
            'splittid15' => 'ss15',
        ];

        return $mappings[$col] ?? $col;
    }, $header);

    // Cache for lookups
    $eventCache = [];
    $riderCache = [];
    $categoryCache = [];
    $clubCache = [];
    $classCache = [];

    $lineNumber = 1;

    while (($row = fgetcsv($handle, 1000, $delimiter)) !== false) {
        $lineNumber++;
        $stats['total']++;

        // Map row to associative array
        $data = array_combine($header, $row);

        // Validate required fields
        if (empty($data['event_name'])) {
            $stats['skipped']++;
            $errors[] = "Rad {$lineNumber}: Saknar tävlingsnamn";
            continue;
        }

        if (empty($data['firstname']) || empty($data['lastname'])) {
            $stats['skipped']++;
            $errors[] = "Rad {$lineNumber}: Saknar namn på cyklist";
            continue;
        }

        try {
            // Find event
            $eventName = trim($data['event_name']);
            $eventId = null;

            if (isset($eventCache[$eventName])) {
                $eventId = $eventCache[$eventName];
            } else {
                // Check if we have event mapping from preview
                global $IMPORT_EVENT_MAPPING;
                $useExistingEvent = false;

                if (!empty($IMPORT_EVENT_MAPPING) && isset($IMPORT_EVENT_MAPPING[$eventName])) {
                    $mappedValue = $IMPORT_EVENT_MAPPING[$eventName];

                    if ($mappedValue !== 'create' && is_numeric($mappedValue)) {
                        // Use existing event from mapping
                        $eventId = (int)$mappedValue;
                        $eventCache[$eventName] = $eventId;
                        $matching_stats['events_found']++;
                        $useExistingEvent = true;
                        error_log("Using mapped event ID {$eventId} for '{$eventName}'");
                    }
                    // else: mappedValue is 'create', proceed with auto-create below
                }

                if (!$useExistingEvent) {
                    // Try exact match
                    $event = $db->getRow(
                        "SELECT id FROM events WHERE name = ? LIMIT 1",
                        [$eventName]
                    );

                    if (!$event) {
                        // Try fuzzy match (LIKE)
                        $event = $db->getRow(
                            "SELECT id FROM events WHERE name LIKE ? LIMIT 1",
                            ['%' . $eventName . '%']
                        );
                    }

                    if ($event) {
                        $eventId = $event['id'];
                        $eventCache[$eventName] = $eventId;
                        $matching_stats['events_found']++;
                    } else {
                        // AUTO-CREATE: Event not found, create it
                        $matching_stats['events_not_found']++;

                        // Get event data from CSV
                        $eventDate = !empty($data['event_date']) ? trim($data['event_date']) : date('Y-m-d');
                        $eventLocation = !empty($data['event_location']) ? trim($data['event_location']) : null;
                        $eventVenueName = !empty($data['event_venue']) ? trim($data['event_venue']) : null;

                        // Parse date if needed (handle various formats)
                        if (!empty($data['event_date'])) {
                            $dateParsed = strtotime($data['event_date']);
                            if ($dateParsed) {
                                $eventDate = date('Y-m-d', $dateParsed);
                            }
                        }

                        // Auto-create venue if specified
                        $venueId = null;
                        if ($eventVenueName) {
                            // Check if venue exists
                            $venue = $db->getRow(
                                "SELECT id FROM venues WHERE name LIKE ? LIMIT 1",
                                ['%' . $eventVenueName . '%']
                            );

                            if ($venue) {
                                $venueId = $venue['id'];
                            } else {
                                // Create new venue
                                $venueData = [
                                    'name' => $eventVenueName,
                                    'city' => $eventLocation,
                                    'active' => 1
                                ];
                                $venueId = $db->insert('venues', $venueData);
                                $matching_stats['venues_created']++;
                                error_log("Auto-created venue: {$eventVenueName}");

                                // Track created venue
                                if ($importId && $venueId) {
                                    trackImportRecord($db, $importId, 'venue', $venueId, 'created');
                                }
                            }
                        }

                        // Create new event
                        // Auto-generate advent_id for new event
                        $event_year = date('Y', strtotime($eventDate));
                        $advent_id = generateEventAdventId($pdo, $event_year);

                        $newEventData = [
                            'name' => $eventName,
                            'advent_id' => $advent_id,
                            'date' => $eventDate,
                            'location' => $eventLocation,
                            'venue_id' => $venueId,
                            'status' => 'completed',
                            'active' => 1
                        ];

                        $eventId = $db->insert('events', $newEventData);
                        $eventCache[$eventName] = $eventId;
                        $matching_stats['events_created']++;
                        error_log("Auto-created event: {$eventName} on {$eventDate}");

                        // Track created event
                        if ($importId && $eventId) {
                            trackImportRecord($db, $importId, 'event', $eventId, 'created');
                        }
                    }
                }
            }

            // Find or create club
            $clubId = null;
            if (!empty($data['club_name'])) {
                $clubName = trim($data['club_name']);
                $clubKey = strtolower($clubName); // Use lowercase for cache key

                if (isset($clubCache[$clubKey])) {
                    $clubId = $clubCache[$clubKey];
                } else {
                    // Fuzzy match with LIKE (case-insensitive)
                    $club = $db->getRow(
                        "SELECT id FROM clubs WHERE LOWER(name) LIKE LOWER(?) LIMIT 1",
                        ['%' . $clubName . '%']
                    );

                    if ($club) {
                        $clubId = $club['id'];
                        $clubCache[$clubKey] = $clubId;
                    } else {
                        // Auto-create club
                        $clubData = [
                            'name' => $clubName,
                            'active' => 1
                        ];
                        $clubId = $db->insert('clubs', $clubData);
                        $clubCache[$clubKey] = $clubId;
                        $matching_stats['clubs_created']++;
                        error_log("Auto-created club: {$clubName}");

                        if ($importId && $clubId) {
                            trackImportRecord($db, $importId, 'club', $clubId, 'created');
                        }
                    }
                }
            }

            // Find or create category
            $categoryId = null;
            if (!empty($data['category'])) {
                $categoryName = trim($data['category']);
                $categoryKey = strtolower($categoryName); // Use lowercase for cache key

                if (isset($categoryCache[$categoryKey])) {
                    $categoryId = $categoryCache[$categoryKey];
                } else {
                    // Case-insensitive match
                    $category = $db->getRow(
                        "SELECT id, name FROM categories WHERE LOWER(name) = LOWER(?) OR LOWER(short_name) = LOWER(?) LIMIT 1",
                        [$categoryName, $categoryName]
                    );

                    if ($category) {
                        $categoryId = $category['id'];
                        $categoryCache[$categoryKey] = $categoryId;
                    } else {
                        // Auto-create category
                        $categoryData = [
                            'name' => $categoryName,
                            'short_name' => substr($categoryName, 0, 20),
                            'active' => 1
                        ];
                        $categoryId = $db->insert('categories', $categoryData);
                        $categoryCache[$categoryKey] = $categoryId;
                        $matching_stats['categories_created']++;
                        error_log("Auto-created category: {$categoryName}");

                        if ($importId && $categoryId) {
                            trackImportRecord($db, $importId, 'category', $categoryId, 'created');
                        }
                    }
                }
            }

            // Find rider
            $firstname = trim($data['firstname']);
            $lastname = trim($data['lastname']);
            $licenseNumber = !empty($data['license_number']) ? trim($data['license_number']) : null;

            // Extract birth year from various formats
            $birthYear = null;
            if (!empty($data['birth_year'])) {
                $birthYearRaw = trim($data['birth_year']);
                // Handle Swedish personnummer format: YYYYMMDD-XXXX or YYMMDD-XXXX
                if (preg_match('/^(\d{4})\d{4}-?\d{4}$/', $birthYearRaw, $matches)) {
                    $birthYear = (int)$matches[1]; // YYYYMMDD-XXXX → YYYY
                } elseif (preg_match('/^(\d{2})\d{4}-?\d{4}$/', $birthYearRaw, $matches)) {
                    // YYMMDD-XXXX → 19YY or 20YY
                    $yy = (int)$matches[1];
                    $birthYear = $yy < 30 ? 2000 + $yy : 1900 + $yy;
                } else {
                    $birthYear = (int)$birthYearRaw; // Just a year number
                }
            }

            // Check for single-use license
            $isSingleUseLicense = false;
            if ($licenseNumber && stripos($licenseNumber, 'engångslicens') !== false) {
                $isSingleUseLicense = true;
                $licenseNumber = null; // Don't use "Engångslicens" as a license number for matching
            }

            $riderId = null;
            $cacheKey = $licenseNumber ?: "{$firstname}|{$lastname}|{$birthYear}";

            if (isset($riderCache[$cacheKey])) {
                $riderId = $riderCache[$cacheKey];
            } else {
                // Try license number first (if not single-use license)
                if ($licenseNumber && !$isSingleUseLicense) {
                    $rider = $db->getRow(
                        "SELECT id FROM riders WHERE license_number = ? LIMIT 1",
                        [$licenseNumber]
                    );
                    if ($rider) {
                        $riderId = $rider['id'];
                        $riderCache[$cacheKey] = $riderId;
                        $matching_stats['riders_found']++;

                        // Normalize gender from CSV
                        $genderToUpdate = null;
                        if (!empty($data['gender'])) {
                            $genderRaw = strtolower(trim($data['gender']));
                            if (in_array($genderRaw, ['woman', 'women', 'female', 'kvinna', 'dam', 'f', 'k'])) {
                                $genderToUpdate = 'F';
                            } elseif (in_array($genderRaw, ['man', 'men', 'male', 'herr', 'm'])) {
                                $genderToUpdate = 'M';
                            }
                        }

                        // Update license_year and gender if provided in CSV
                        $updateData = [];
                        if (!empty($data['license_year'])) {
                            $updateData['license_year'] = (int)$data['license_year'];
                        }
                        if ($genderToUpdate) {
                            $updateData['gender'] = $genderToUpdate;
                        }
                        if (!empty($updateData)) {
                            $db->update('riders', $updateData, 'id = ?', [$riderId]);
                        }
                    }
                }

                // Try name + birth year
                if (!$riderId && $birthYear) {
                    $rider = $db->getRow(
                        "SELECT id FROM riders WHERE firstname = ? AND lastname = ? AND birth_year = ? LIMIT 1",
                        [$firstname, $lastname, $birthYear]
                    );
                    if ($rider) {
                        $riderId = $rider['id'];
                        $riderCache[$cacheKey] = $riderId;
                        $matching_stats['riders_found']++;

                        // Normalize gender from CSV
                        $genderToUpdate = null;
                        if (!empty($data['gender'])) {
                            $genderRaw = strtolower(trim($data['gender']));
                            if (in_array($genderRaw, ['woman', 'women', 'female', 'kvinna', 'dam', 'f', 'k'])) {
                                $genderToUpdate = 'F';
                            } elseif (in_array($genderRaw, ['man', 'men', 'male', 'herr', 'm'])) {
                                $genderToUpdate = 'M';
                            }
                        }

                        // Update license_year and gender if provided in CSV
                        $updateData = [];
                        if (!empty($data['license_year'])) {
                            $updateData['license_year'] = (int)$data['license_year'];
                        }
                        if ($genderToUpdate) {
                            $updateData['gender'] = $genderToUpdate;
                        }
                        if (!empty($updateData)) {
                            $db->update('riders', $updateData, 'id = ?', [$riderId]);
                        }
                    }
                }

                // Try name only (fuzzy)
                if (!$riderId) {
                    $rider = $db->getRow(
                        "SELECT id FROM riders WHERE firstname LIKE ? AND lastname LIKE ? LIMIT 1",
                        ['%' . $firstname . '%', '%' . $lastname . '%']
                    );
                    if ($rider) {
                        $riderId = $rider['id'];
                        $riderCache[$cacheKey] = $riderId;
                        $matching_stats['riders_found']++;

                        // Normalize gender from CSV
                        $genderToUpdate = null;
                        if (!empty($data['gender'])) {
                            $genderRaw = strtolower(trim($data['gender']));
                            if (in_array($genderRaw, ['woman', 'women', 'female', 'kvinna', 'dam', 'f', 'k'])) {
                                $genderToUpdate = 'F';
                            } elseif (in_array($genderRaw, ['man', 'men', 'male', 'herr', 'm'])) {
                                $genderToUpdate = 'M';
                            }
                        }

                        // Update license_year and gender if provided in CSV
                        $updateData = [];
                        if (!empty($data['license_year'])) {
                            $updateData['license_year'] = (int)$data['license_year'];
                        }
                        if ($genderToUpdate) {
                            $updateData['gender'] = $genderToUpdate;
                        }
                        if (!empty($updateData)) {
                            $db->update('riders', $updateData, 'id = ?', [$riderId]);
                        }
                    } else {
                        // AUTO-CREATE: Rider not found, create new rider with SWE-ID
                        $matching_stats['riders_not_found']++;

                        // Generate SWE-ID
                        $sweId = generateSweId($db);

                        // Get gender from data if available
                        $gender = 'M'; // Default
                        if (!empty($data['gender'])) {
                            $genderRaw = strtolower(trim($data['gender']));
                            if (in_array($genderRaw, ['woman', 'women', 'female', 'kvinna', 'dam', 'f', 'k'])) {
                                $gender = 'F';
                            } elseif (in_array($genderRaw, ['man', 'men', 'male', 'herr', 'm'])) {
                                $gender = 'M';
                            }
                        }

                        // Determine license type
                        $licenseType = $isSingleUseLicense ? 'Engångslicens' : 'SWE-ID';

                        // Get license year from data if available
                        $licenseYear = null;
                        if (!empty($data['license_year'])) {
                            $licenseYear = (int)$data['license_year'];
                        }

                        // Create new rider with SWE-ID
                        $newRiderData = [
                            'firstname' => $firstname,
                            'lastname' => $lastname,
                            'birth_year' => $birthYear,
                            'gender' => $gender,
                            'license_number' => $sweId,
                            'license_type' => $licenseType,
                            'license_year' => $licenseYear,
                            'club_id' => $clubId,
                            'email' => !empty($data['email']) ? trim($data['email']) : null,
                            'active' => 1
                        ];

                        $riderId = $db->insert('riders', $newRiderData);
                        $riderCache[$cacheKey] = $riderId;
                        $matching_stats['riders_created']++;

                        $licenseTypeLabel = $isSingleUseLicense ? 'Engångslicens' : 'no license';
                        error_log("Auto-created rider: {$firstname} {$lastname} with SWE-ID: {$sweId} ({$licenseTypeLabel})");

                        // Track created rider
                        if ($importId && $riderId) {
                            trackImportRecord($db, $importId, 'rider', $riderId, 'created');
                        }
                    }
                }
            }

            // Normalize finish time (handle formats like "0:12:41.22" or "02:15:30")
            $finishTime = null;
            if (!empty($data['finish_time'])) {
                $rawTime = trim($data['finish_time']);
                // Remove any newlines, carriage returns, or extra whitespace
                $rawTime = preg_replace('/[\r\n\t]+/', '', $rawTime);
                $rawTime = trim($rawTime);

                // Remove decimal seconds if present (MySQL TIME doesn't support them)
                if (preg_match('/^(\d{1,2}):(\d{2}):(\d{2})\.?\d*$/', $rawTime, $matches)) {
                    $hours = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
                    $minutes = $matches[2];
                    $seconds = $matches[3];
                    $finishTime = "{$hours}:{$minutes}:{$seconds}";
                } elseif (!empty($rawTime)) {
                    // Keep as-is if it's already in the right format
                    $finishTime = $rawTime;
                }
            }

            // Normalize status (FIN/DNS/DNF/DQ -> finished/dns/dnf/dq)
            $status = 'finished';
            if (!empty($data['status'])) {
                $statusRaw = strtoupper(trim($data['status']));
                if ($statusRaw === 'FIN' || $statusRaw === 'FINISHED' || $statusRaw === 'OK') {
                    $status = 'finished';
                } elseif ($statusRaw === 'DNF') {
                    $status = 'dnf';
                } elseif ($statusRaw === 'DNS') {
                    $status = 'dns';
                } elseif ($statusRaw === 'DQ' || $statusRaw === 'DSQ') {
                    $status = 'dq';
                } else {
                    $status = strtolower($statusRaw);
                }
            }

            // Find or create class
            $classId = null;

            // Check if manual class override is set (from form)
            global $IMPORT_FORCE_CLASS_ID;
            if (isset($IMPORT_FORCE_CLASS_ID) && $IMPORT_FORCE_CLASS_ID > 0) {
                $classId = (int)$IMPORT_FORCE_CLASS_ID;
                error_log("Using manual class override: class_id = {$classId}");
            }
            // First, check if class is specified in CSV
            elseif (!empty($data['class_name'])) {
                $className = trim($data['class_name']);
                $classKey = strtolower($className);

                if (isset($classCache[$classKey])) {
                    $classId = $classCache[$classKey];
                } else {
                    // Try to find existing class by name or display_name
                    $class = $db->getRow(
                        "SELECT id FROM classes WHERE LOWER(name) = LOWER(?) OR LOWER(display_name) = LOWER(?) LIMIT 1",
                        [$className, $className]
                    );

                    if ($class) {
                        $classId = $class['id'];
                        $classCache[$classKey] = $classId;
                    } else {
                        // Auto-create class if it doesn't exist
                        $classData = [
                            'name' => $className,
                            'display_name' => $className,
                            'active' => 1,
                            'sort_order' => 999 // Put new classes at the end
                        ];
                        $classId = $db->insert('classes', $classData);
                        $classCache[$classKey] = $classId;
                        $matching_stats['classes_created']++;
                        error_log("Auto-created class: {$className}");

                        if ($importId && $classId) {
                            trackImportRecord($db, $importId, 'class', $classId, 'created');
                        }
                    }
                }
            }

            // If no class specified in CSV, auto-assign based on rider's age and gender
            if (!$classId) {
                $event = $db->getRow("SELECT date, discipline FROM events WHERE id = ?", [$eventId]);
                $rider = $db->getRow("SELECT birth_year, gender FROM riders WHERE id = ?", [$riderId]);

                if ($event && $rider && !empty($rider['birth_year']) && !empty($rider['gender'])) {
                    $eventDate = $event['date'] ?: date('Y-m-d');
                    $discipline = $event['discipline'] ?: 'ROAD';

                    $classId = determineRiderClass($db, $rider['birth_year'], $rider['gender'], $eventDate, $discipline);

                    if ($classId) {
                        error_log("Auto-assigned class ID {$classId} to rider {$riderId} for event {$eventId}");
                    }
                }
            }

            // Normalize split times (handle same format as finish time)
            $splitTimes = [];
            for ($i = 1; $i <= 15; $i++) {
                $splitKey = 'ss' . $i;
                $splitTime = null;

                if (!empty($data[$splitKey])) {
                    $rawTime = trim($data[$splitKey]);
                    $rawTime = preg_replace('/[\r\n\t]+/', '', $rawTime);
                    $rawTime = trim($rawTime);

                    // Remove decimal seconds if present
                    if (preg_match('/^(\d{1,2}):(\d{2}):(\d{2})\.?\d*$/', $rawTime, $matches)) {
                        $hours = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
                        $minutes = $matches[2];
                        $seconds = $matches[3];
                        $splitTime = "{$hours}:{$minutes}:{$seconds}";
                    } elseif (!empty($rawTime)) {
                        $splitTime = $rawTime;
                    }
                }

                $splitTimes[$splitKey] = $splitTime;
            }

            // Prepare result data
            $resultData = [
                'event_id' => $eventId,
                'cyclist_id' => $riderId,
                'category_id' => $categoryId,
                'class_id' => $classId,
                'position' => !empty($data['position']) ? (int)$data['position'] : null,
                'finish_time' => $finishTime,
                'bib_number' => !empty($data['bib_number']) ? trim($data['bib_number']) : null,
                'status' => $status,
                'points' => !empty($data['points']) ? (int)$data['points'] : 0,
                'notes' => !empty($data['notes']) ? trim($data['notes']) : null,
                // Add all split times
                'ss1' => $splitTimes['ss1'],
                'ss2' => $splitTimes['ss2'],
                'ss3' => $splitTimes['ss3'],
                'ss4' => $splitTimes['ss4'],
                'ss5' => $splitTimes['ss5'],
                'ss6' => $splitTimes['ss6'],
                'ss7' => $splitTimes['ss7'],
                'ss8' => $splitTimes['ss8'],
                'ss9' => $splitTimes['ss9'],
                'ss10' => $splitTimes['ss10'],
                'ss11' => $splitTimes['ss11'],
                'ss12' => $splitTimes['ss12'],
                'ss13' => $splitTimes['ss13'],
                'ss14' => $splitTimes['ss14'],
                'ss15' => $splitTimes['ss15']
            ];

            // Check if result already exists
            $existing = $db->getRow(
                "SELECT id FROM results WHERE event_id = ? AND cyclist_id = ? LIMIT 1",
                [$eventId, $riderId]
            );

            if ($existing) {
                // Get old data for rollback
                $oldData = $db->getRow("SELECT * FROM results WHERE id = ?", [$existing['id']]);

                // Update existing result
                $db->update('results', $resultData, 'id = ?', [$existing['id']]);
                $stats['updated']++;

                // Track updated record
                if ($importId) {
                    trackImportRecord($db, $importId, 'result', $existing['id'], 'updated', $oldData);
                }
            } else {
                // Insert new result
                $resultId = $db->insert('results', $resultData);
                $stats['success']++;

                // Track created record
                if ($importId && $resultId) {
                    trackImportRecord($db, $importId, 'result', $resultId, 'created');
                }
            }

        } catch (Exception $e) {
            $stats['failed']++;
            $errors[] = "Rad {$lineNumber}: " . $e->getMessage();
        }
    }

    fclose($handle);

    return [
        'stats' => $stats,
        'matching' => $matching_stats,
        'errors' => $errors
    ];
}

/**
 * Import results from CSV with event mapping and optional class override
 * Same as importResultsFromCSV but uses provided event IDs instead of auto-creating
 */
function importResultsFromCSVWithMapping($filepath, $db, $importId = null, $eventMapping = [], $forceClassId = null) {
    // Store event mapping and class override in globals for use during import
    global $IMPORT_EVENT_MAPPING, $IMPORT_FORCE_CLASS_ID;
    $IMPORT_EVENT_MAPPING = $eventMapping;
    $IMPORT_FORCE_CLASS_ID = $forceClassId;

    return importResultsFromCSV($filepath, $db, $importId);
}

$pageTitle = 'Importera Resultat';
$pageType = 'admin';
include __DIR__ . '/../includes/layout-header.php';
?>

    <main class="gs-content-with-sidebar">
        <div class="gs-container">
            <!-- Header -->
            <div class="gs-flex gs-items-center gs-justify-between gs-mb-xl">
                <div>
                    <h1 class="gs-h1 gs-text-primary">
                        <i data-lucide="trophy"></i>
                        Importera Resultat
                    </h1>
                    <p class="gs-text-secondary gs-mt-sm">
                        Bulk-import av tävlingsresultat från CSV-fil
                    </p>
                </div>
                <a href="/admin/results.php" class="gs-btn gs-btn-outline">
                    <i data-lucide="arrow-left"></i>
                    Tillbaka
                </a>
            </div>

            <!-- Message -->
            <?php if ($message): ?>
                <div class="gs-alert gs-alert-<?= h($messageType) ?> gs-mb-lg">
                    <i data-lucide="<?= $messageType === 'success' ? 'check-circle' : 'alert-circle' ?>"></i>
                    <?= h($message) ?>
                </div>
            <?php endif; ?>

            <!-- Statistics -->
            <?php if ($stats): ?>
                <div class="gs-card gs-mb-lg">
                    <div class="gs-card-header">
                        <h2 class="gs-h4 gs-text-primary">
                            <i data-lucide="bar-chart"></i>
                            Import-statistik
                        </h2>
                    </div>
                    <div class="gs-card-content">
                        <div class="gs-grid gs-grid-cols-2 gs-md-grid-cols-5 gs-gap-md gs-mb-lg">
                            <div class="gs-stat-card">
                                <i data-lucide="file-text" class="gs-icon-lg gs-text-primary gs-mb-sm"></i>
                                <div class="gs-stat-number"><?= number_format($stats['total']) ?></div>
                                <div class="gs-stat-label">Totalt rader</div>
                            </div>
                            <div class="gs-stat-card">
                                <i data-lucide="check-circle" class="gs-icon-lg gs-text-success gs-mb-sm"></i>
                                <div class="gs-stat-number"><?= number_format($stats['success']) ?></div>
                                <div class="gs-stat-label">Nya resultat</div>
                            </div>
                            <div class="gs-stat-card">
                                <i data-lucide="refresh-cw" class="gs-icon-lg gs-text-accent gs-mb-sm"></i>
                                <div class="gs-stat-number"><?= number_format($stats['updated']) ?></div>
                                <div class="gs-stat-label">Uppdaterade</div>
                            </div>
                            <div class="gs-stat-card">
                                <i data-lucide="minus-circle" class="gs-icon-lg gs-text-secondary gs-mb-sm"></i>
                                <div class="gs-stat-number"><?= number_format($stats['skipped']) ?></div>
                                <div class="gs-stat-label">Överhoppade</div>
                            </div>
                            <div class="gs-stat-card">
                                <i data-lucide="x-circle" class="gs-icon-lg gs-text-danger gs-mb-sm"></i>
                                <div class="gs-stat-number"><?= number_format($stats['failed']) ?></div>
                                <div class="gs-stat-label">Misslyckade</div>
                            </div>
                        </div>

                        <?php if ($matching_stats): ?>
                            <div style="padding-top: var(--gs-space-lg); border-top: 1px solid var(--gs-border);">
                                <h3 class="gs-h5 gs-text-primary gs-mb-md">
                                    <i data-lucide="search"></i>
                                    Matchnings- och Auto-Create statistik
                                </h3>
                                <div class="gs-grid gs-grid-cols-2 gs-md-grid-cols-3 gs-gap-md gs-mb-md">
                                    <div style="padding: var(--gs-space-md); background: var(--gs-background-secondary); border-radius: var(--gs-border-radius);">
                                        <div class="gs-text-sm gs-text-secondary">Tävlingar hittade</div>
                                        <div class="gs-h3 gs-text-success"><?= $matching_stats['events_found'] ?></div>
                                    </div>
                                    <div style="padding: var(--gs-space-md); background: var(--gs-background-secondary); border-radius: var(--gs-border-radius);">
                                        <div class="gs-text-sm gs-text-secondary">Nya tävlingar skapade</div>
                                        <div class="gs-h3 gs-text-accent"><?= $matching_stats['events_created'] ?? 0 ?></div>
                                    </div>
                                    <div style="padding: var(--gs-space-md); background: var(--gs-background-secondary); border-radius: var(--gs-border-radius);">
                                        <div class="gs-text-sm gs-text-secondary">Nya banor skapade</div>
                                        <div class="gs-h3 gs-text-primary"><?= $matching_stats['venues_created'] ?? 0 ?></div>
                                    </div>
                                </div>
                                <div class="gs-grid gs-grid-cols-2 gs-md-grid-cols-3 gs-gap-md gs-mb-md">
                                    <div style="padding: var(--gs-space-md); background: var(--gs-background-secondary); border-radius: var(--gs-border-radius);">
                                        <div class="gs-text-sm gs-text-secondary">Deltagare hittade</div>
                                        <div class="gs-h3 gs-text-success"><?= $matching_stats['riders_found'] ?></div>
                                    </div>
                                    <div style="padding: var(--gs-space-md); background: var(--gs-background-secondary); border-radius: var(--gs-border-radius);">
                                        <div class="gs-text-sm gs-text-secondary">Nya deltagare skapade</div>
                                        <div class="gs-h3 gs-text-accent"><?= $matching_stats['riders_created'] ?? 0 ?></div>
                                    </div>
                                    <div style="padding: var(--gs-space-md); background: var(--gs-background-secondary); border-radius: var(--gs-border-radius);">
                                        <div class="gs-text-sm gs-text-secondary">SWE-ID tilldelat</div>
                                        <div class="gs-h3 gs-text-warning"><?= $matching_stats['riders_created'] ?? 0 ?></div>
                                    </div>
                                </div>
                                <div class="gs-grid gs-grid-cols-2 gs-md-grid-cols-3 gs-gap-md">
                                    <div style="padding: var(--gs-space-md); background: var(--gs-background-secondary); border-radius: var(--gs-border-radius);">
                                        <div class="gs-text-sm gs-text-secondary">Nya klubbar skapade</div>
                                        <div class="gs-h3 gs-text-accent"><?= $matching_stats['clubs_created'] ?? 0 ?></div>
                                    </div>
                                    <div style="padding: var(--gs-space-md); background: var(--gs-background-secondary); border-radius: var(--gs-border-radius);">
                                        <div class="gs-text-sm gs-text-secondary">Nya kategorier skapade</div>
                                        <div class="gs-h3 gs-text-accent"><?= $matching_stats['categories_created'] ?? 0 ?></div>
                                    </div>
                                    <div style="padding: var(--gs-space-md); background: var(--gs-background-secondary); border-radius: var(--gs-border-radius);">
                                        <div class="gs-text-sm gs-text-secondary">Nya klasser skapade</div>
                                        <div class="gs-h3 gs-text-accent"><?= $matching_stats['classes_created'] ?? 0 ?></div>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($errors)): ?>
                            <div class="gs-mt-lg" style="padding-top: var(--gs-space-lg); border-top: 1px solid var(--gs-border);">
                                <h3 class="gs-h5 gs-text-danger gs-mb-md">
                                    <i data-lucide="alert-triangle"></i>
                                    Fel och varningar (<?= count($errors) ?>)
                                </h3>
                                <div style="max-height: 300px; overflow-y: auto; background: var(--gs-background-secondary); padding: var(--gs-space-md); border-radius: var(--gs-border-radius);">
                                    <?php foreach (array_slice($errors, 0, 50) as $error): ?>
                                        <div class="gs-text-sm gs-text-secondary" style="margin-bottom: 4px;">
                                            • <?= h($error) ?>
                                        </div>
                                    <?php endforeach; ?>
                                    <?php if (count($errors) > 50): ?>
                                        <div class="gs-text-sm gs-text-secondary gs-mt-sm" style="font-style: italic;">
                                            ... och <?= count($errors) - 50 ?> fler
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Upload Form -->
            <div class="gs-card gs-mb-xl">
                <div class="gs-card-header">
                    <div class="gs-flex gs-justify-between gs-items-center">
                        <h2 class="gs-h4 gs-text-primary">
                            <i data-lucide="upload"></i>
                            Ladda upp CSV-fil
                        </h2>
                        <a href="/admin/import-results-preview.php" class="gs-btn gs-btn-outline gs-btn-sm">
                            <i data-lucide="eye"></i>
                            Förhandsgranska Import
                        </a>
                    </div>
                </div>
                <div class="gs-card-content">
                    <div class="gs-alert gs-alert-info gs-mb-md">
                        <i data-lucide="info"></i>
                        <strong>Auto-Create:</strong> Tävlingar, banor och deltagare som inte hittas skapas automatiskt. Inkludera event_date, event_location och event_venue för bästa resultat.
                        <br>
                        <strong>Rekommendation:</strong> Använd <a href="/admin/import-results-preview.php" class="gs-link">"Förhandsgranska Import"</a> för att granska data innan import.
                    </div>

                    <form method="POST" enctype="multipart/form-data" id="uploadForm" style="max-width: 600px;">
                        <?= csrf_field() ?>

                        <div class="gs-form-group">
                            <label for="import_file" class="gs-label">
                                <i data-lucide="file"></i>
                                Välj CSV-fil
                            </label>
                            <input
                                type="file"
                                id="import_file"
                                name="import_file"
                                class="gs-input"
                                accept=".csv"
                                required
                            >
                            <small class="gs-text-secondary gs-text-sm">
                                Max storlek: <?= round(MAX_UPLOAD_SIZE / 1024 / 1024) ?>MB
                            </small>
                        </div>

                        <button type="submit" class="gs-btn gs-btn-primary gs-btn-lg">
                            <i data-lucide="upload"></i>
                            Importera
                        </button>
                    </form>

                    <!-- Progress Bar -->
                    <div id="progressBar" style="display: none; margin-top: var(--gs-space-lg);">
                        <div class="gs-flex gs-items-center gs-justify-between gs-mb-sm">
                            <span class="gs-text-sm gs-text-primary" style="font-weight: 600;">Importerar och matchar...</span>
                            <span class="gs-text-sm gs-text-secondary" id="progressPercent">0%</span>
                        </div>
                        <div style="width: 100%; height: 8px; background: var(--gs-background-secondary); border-radius: 4px; overflow: hidden;">
                            <div id="progressFill" style="width: 0%; height: 100%; background: var(--gs-primary); transition: width 0.3s;"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- File Format Guide -->
            <div class="gs-card">
                <div class="gs-card-header">
                    <h2 class="gs-h4 gs-text-primary">
                        <i data-lucide="info"></i>
                        CSV-filformat
                    </h2>
                </div>
                <div class="gs-card-content">
                    <p class="gs-text-secondary gs-mb-md">
                        CSV-filen ska ha följande kolumner i första raden (header):
                    </p>

                    <div class="gs-table-responsive">
                        <table class="gs-table">
                            <thead>
                                <tr>
                                    <th>Kolumn</th>
                                    <th>Obligatorisk</th>
                                    <th>Beskrivning</th>
                                    <th>Exempel</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><code>event_name</code></td>
                                    <td><span class="gs-badge gs-badge-danger">Ja</span></td>
                                    <td>Tävlingens namn (skapas automatiskt om den inte finns)</td>
                                    <td>GravitySeries Järvsö XC</td>
                                </tr>
                                <tr>
                                    <td><code>event_date</code></td>
                                    <td><span class="gs-badge gs-badge-secondary">Nej</span></td>
                                    <td>Tävlingsdatum (används vid auto-create)</td>
                                    <td>2025-06-15</td>
                                </tr>
                                <tr>
                                    <td><code>event_location</code></td>
                                    <td><span class="gs-badge gs-badge-secondary">Nej</span></td>
                                    <td>Plats/ort (används vid auto-create)</td>
                                    <td>Järvsö</td>
                                </tr>
                                <tr>
                                    <td><code>event_venue</code></td>
                                    <td><span class="gs-badge gs-badge-secondary">Nej</span></td>
                                    <td>Bana/anläggning (skapas automatiskt om den inte finns)</td>
                                    <td>Järvsö Bergscykelpark</td>
                                </tr>
                                <tr>
                                    <td><code>firstname</code></td>
                                    <td><span class="gs-badge gs-badge-danger">Ja</span></td>
                                    <td>Cyklistens förnamn</td>
                                    <td>Erik</td>
                                </tr>
                                <tr>
                                    <td><code>lastname</code></td>
                                    <td><span class="gs-badge gs-badge-danger">Ja</span></td>
                                    <td>Cyklistens efternamn</td>
                                    <td>Andersson</td>
                                </tr>
                                <tr>
                                    <td><code>position</code></td>
                                    <td><span class="gs-badge gs-badge-secondary">Nej</span></td>
                                    <td>Placering</td>
                                    <td>1</td>
                                </tr>
                                <tr>
                                    <td><code>finish_time</code></td>
                                    <td><span class="gs-badge gs-badge-secondary">Nej</span></td>
                                    <td>Sluttid (HH:MM:SS)</td>
                                    <td>02:15:30</td>
                                </tr>
                                <tr>
                                    <td><code>license_number</code></td>
                                    <td><span class="gs-badge gs-badge-secondary">Nej</span></td>
                                    <td>UCI/SCF licensnummer (används för matchning)</td>
                                    <td>SWE-2025-1234</td>
                                </tr>
                                <tr>
                                    <td><code>birth_year</code></td>
                                    <td><span class="gs-badge gs-badge-secondary">Nej</span></td>
                                    <td>Födelseår (används för matchning)</td>
                                    <td>1995</td>
                                </tr>
                                <tr>
                                    <td><code>bib_number</code></td>
                                    <td><span class="gs-badge gs-badge-secondary">Nej</span></td>
                                    <td>Startnummer</td>
                                    <td>42</td>
                                </tr>
                                <tr>
                                    <td><code>status</code></td>
                                    <td><span class="gs-badge gs-badge-secondary">Nej</span></td>
                                    <td>Status (finished/dnf/dns/dq)</td>
                                    <td>finished</td>
                                </tr>
                                <tr>
                                    <td><code>points</code></td>
                                    <td><span class="gs-badge gs-badge-secondary">Nej</span></td>
                                    <td>Poäng</td>
                                    <td>100</td>
                                </tr>
                                <tr>
                                    <td><code>class</code></td>
                                    <td><span class="gs-badge gs-badge-secondary">Nej</span></td>
                                    <td>Klass (t.ex. "Elite Herr", "Junior Dam"). Skapas automatiskt om den inte finns. Om inte angiven, beräknas automatiskt från ålder/kön.</td>
                                    <td>Elite Herr</td>
                                </tr>
                                <tr>
                                    <td><code>category</code></td>
                                    <td><span class="gs-badge gs-badge-secondary">Nej</span></td>
                                    <td>Kategori/heat (t.ex. "Final", "Kval 1")</td>
                                    <td>Final</td>
                                </tr>
                                <tr>
                                    <td><code>club_name</code></td>
                                    <td><span class="gs-badge gs-badge-secondary">Nej</span></td>
                                    <td>Klubb/team (skapas automatiskt om den inte finns)</td>
                                    <td>Velodrom CC</td>
                                </tr>
                                <tr>
                                    <td><code>notes</code></td>
                                    <td><span class="gs-badge gs-badge-secondary">Nej</span></td>
                                    <td>Anteckningar</td>
                                    <td>-</td>
                                </tr>
                                <tr>
                                    <td><code>ss1</code> till <code>ss15</code></td>
                                    <td><span class="gs-badge gs-badge-secondary">Nej</span></td>
                                    <td>Splittider för Enduro/DH (upp till 15 stycken). Format: HH:MM:SS eller MM:SS</td>
                                    <td>00:05:23</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="gs-mt-lg" style="padding: var(--gs-space-md); background: var(--gs-background-secondary); border-radius: var(--gs-border-radius); border-left: 4px solid var(--gs-primary);">
                        <h3 class="gs-h5 gs-text-primary gs-mb-sm">
                            <i data-lucide="lightbulb"></i>
                            Matchnings-logik
                        </h3>
                        <ul class="gs-text-secondary gs-text-sm" style="margin-left: var(--gs-space-lg); line-height: 1.8;">
                            <li><strong>Tävlingar:</strong> Matchas via exakt namn eller fuzzy match. Om inte hittad, skapas automatiskt med data från CSV.</li>
                            <li><strong>Banor:</strong> Om event_venue anges och inte hittas, skapas automatiskt</li>
                            <li><strong>Cyklister:</strong> Matchas i följande ordning:
                                <ol style="margin-left: var(--gs-space-lg); margin-top: 4px;">
                                    <li>Licensnummer (exakt match)</li>
                                    <li>Namn + födelseår (exakt match)</li>
                                    <li>Namn (fuzzy match)</li>
                                    <li>Om inte hittad: Skapas automatiskt med SWE-ID</li>
                                </ol>
                            </li>
                            <li><strong>Klasser:</strong> Om "class" kolumn finns i CSV, används den klassen (skapas automatiskt om den inte finns). Om inte angiven, beräknas klass automatiskt från ålder/kön.</li>
                            <li><strong>Klubbar & Kategorier:</strong> Skapas automatiskt om de inte finns</li>
                            <li>Dubbletter upptäcks via event_id + cyclist_id</li>
                            <li>Befintliga resultat uppdateras automatiskt</li>
                        </ul>
                    </div>

                    <div class="gs-mt-md">
                        <p class="gs-text-sm gs-text-secondary">
                            <strong>Exempel på CSV-fil med auto-create:</strong>
                        </p>
                        <pre style="background: var(--gs-background-secondary); padding: var(--gs-space-md); border-radius: var(--gs-border-radius); overflow-x: auto; font-size: 12px; margin-top: var(--gs-space-sm);">event_name,event_date,event_location,event_venue,firstname,lastname,position,finish_time,license_number,birth_year,bib_number,status,points,gender
GravitySeries Järvsö XC,2025-06-15,Järvsö,Järvsö Bergscykelpark,Erik,Andersson,1,02:15:30,SWE-2025-1234,1995,42,finished,100,M
GravitySeries Järvsö XC,2025-06-15,Järvsö,Järvsö Bergscykelpark,Anna,Karlsson,2,02:18:45,SWE-2025-2345,1998,43,finished,80,F
GravitySeries Järvsö XC,2025-06-15,Järvsö,Järvsö Bergscykelpark,Johan,Svensson,3,02:20:12,,1992,44,finished,60,M</pre>
                        <p class="gs-text-sm gs-text-secondary gs-mt-sm">
                            <strong>Obs:</strong> Om event, venue eller rider inte finns kommer de skapas automatiskt. Johan Svensson saknar licensnummer och får ett SWE-ID.
                        </p>
                    </div>
                </div>
            </div>
        </div>
<?php
$additionalScripts = <<<'SCRIPT'
<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Show progress bar on form submit
        const form = document.getElementById('uploadForm');
        const progressBar = document.getElementById('progressBar');
        const progressFill = document.getElementById('progressFill');
        const progressPercent = document.getElementById('progressPercent');

        form.addEventListener('submit', function() {
            progressBar.style.display = 'block';

            // Simulate progress
            let progress = 0;
            const interval = setInterval(function() {
                progress += Math.random() * 15;
                if (progress > 90) {
                    progress = 90;
                    clearInterval(interval);
                }
                progressFill.style.width = progress + '%';
                progressPercent.textContent = Math.round(progress) + '%';
            }, 200);
        });
    });
</script>
SCRIPT;

include __DIR__ . '/../includes/layout-footer.php';
?>

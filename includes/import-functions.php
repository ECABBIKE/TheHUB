<?php
/**
 * Import functions for CSV processing
 * Shared between import-results.php and import-results-preview.php
 */

/**
 * Check if a row appears to be a field mapping/description row
 * These rows contain field names like "class", "position", "club_name" instead of actual data
 */
function isFieldMappingRow($row) {
    if (!is_array($row)) return false;

    // Known field mapping keywords that appear in description rows
    $fieldKeywords = ['class', 'position', 'club_name', 'license_number', 'finish_time', 'status', 'firstname', 'lastname'];

    $matchCount = 0;
    foreach ($row as $value) {
        $cleanValue = strtolower(trim($value));
        if (in_array($cleanValue, $fieldKeywords)) {
            $matchCount++;
        }
    }

    // If 3 or more values match field keywords, it's likely a mapping row
    return $matchCount >= 3;
}

/**
 * Import results from CSV file with event mapping
 * Returns stage names mapping for automatic configuration
 */
function importResultsFromCSVWithMapping($filepath, $db, $importId, $eventMapping = [], $forceClassId = null) {
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
    $stageNamesMapping = []; // Will store original header names for split times
    $changelog = []; // Track what changed during updates

    // Set global event mapping for use in import
    global $IMPORT_EVENT_MAPPING;
    $IMPORT_EVENT_MAPPING = $eventMapping;

    if (($handle = fopen($filepath, 'r')) === false) {
        throw new Exception('Kunde inte öppna filen');
    }

    // Auto-detect delimiter (comma or semicolon)
    $firstLine = fgets($handle);
    rewind($handle);
    $delimiter = (substr_count($firstLine, ';') > substr_count($firstLine, ',')) ? ';' : ',';

    // Read header row (0 = unlimited line length)
    $header = fgetcsv($handle, 0, $delimiter);

    if (!$header) {
        fclose($handle);
        throw new Exception('Tom fil eller ogiltigt format');
    }

    // Normalize header - accept multiple variants
    $originalHeaders = $header;

    // First pass: identify split time columns (SS1, SS2, SS3, SS3-1, etc.) and map them in order
    $splitTimeColumns = [];
    $splitTimeIndex = 1;

    foreach ($header as $index => $col) {
        $originalCol = trim($col);
        $normalizedCol = mb_strtolower($originalCol, 'UTF-8');
        $normalizedCol = str_replace([' ', '-', '_'], '', $normalizedCol);

        // Check if this looks like a split time column (ss followed by numbers)
        if (preg_match('/^ss\d+/', $normalizedCol)) {
            $splitTimeColumns[$index] = [
                'original' => $originalCol,
                'mapped' => 'ss' . $splitTimeIndex
            ];
            $stageNamesMapping[$splitTimeIndex] = $originalCol;
            $splitTimeIndex++;
        }
    }

    $header = array_map(function($col) use ($splitTimeColumns, &$stageNamesMapping) {
        static $colIndex = 0;
        $currentIndex = $colIndex++;

        $col = mb_strtolower(trim($col), 'UTF-8');

        // Skip empty columns (give them unique names to avoid conflicts)
        if (empty($col)) {
            return 'empty_' . uniqid();
        }

        // Check if this is a split time column we already mapped
        if (isset($splitTimeColumns[$currentIndex])) {
            return $splitTimeColumns[$currentIndex]['mapped'];
        }

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

            // Club/Team
            'club' => 'club_name',
            'clubname' => 'club_name',
            'team' => 'club_name',
            'klubb' => 'club_name',
            'club_name' => 'club_name',
            'huvudförening' => 'club_name',
            'huvudforening' => 'club_name',

            // Category is the racing class
            'category' => 'class_name',
            'class' => 'class_name',
            'klass' => 'class_name',
            'classname' => 'class_name',

            // PWR is used in some exports but we ignore it
            'pwr' => 'pwr',

            // Position
            'position' => 'position',
            'placering' => 'position',
            'placebycategory' => 'position',
            'place' => 'position',

            // Bib number
            'bibno' => 'bib_number',
            'bib' => 'bib_number',
            'startnummer' => 'bib_number',
            'startnr' => 'bib_number',
            'nummerlapp' => 'bib_number',

            // Time fields
            'time' => 'finish_time',
            'tid' => 'finish_time',
            'finishtime' => 'finish_time',
            'nettime' => 'finish_time',
            'nettid' => 'finish_time',
            'finish_time' => 'finish_time',
            'totaltid' => 'finish_time',
            'totaltime' => 'finish_time',

            // Status
            'status' => 'status',

            // Gender
            'gender' => 'gender',
            'kön' => 'gender',
            'kon' => 'gender',

            // Birth year
            'birthyear' => 'birth_year',
            'födelseår' => 'birth_year',
            'fodelsear' => 'birth_year',

            // Stage times
            'ss1' => 'ss1', 'ss2' => 'ss2', 'ss3' => 'ss3', 'ss4' => 'ss4',
            'ss5' => 'ss5', 'ss6' => 'ss6', 'ss7' => 'ss7', 'ss8' => 'ss8',
            'ss9' => 'ss9', 'ss10' => 'ss10', 'ss11' => 'ss11', 'ss12' => 'ss12',
            'ss13' => 'ss13', 'ss14' => 'ss14', 'ss15' => 'ss15',

            // DH run times
            'run1time' => 'run_1_time',
            'run_1_time' => 'run_1_time',
            'run1' => 'run_1_time',
            'åk1' => 'run_1_time',
            'ak1' => 'run_1_time',
            'run2time' => 'run_2_time',
            'run_2_time' => 'run_2_time',
            'run2' => 'run_2_time',
            'åk2' => 'run_2_time',
            'ak2' => 'run_2_time',

            // DH split times for run 1 (stored in ss1-ss4)
            'run1split1' => 'ss1',
            'run1split2' => 'ss2',
            'run1split3' => 'ss3',
            'run1split4' => 'ss4',
            'split11' => 'ss1',
            'split12' => 'ss2',
            'split13' => 'ss3',
            'split14' => 'ss4',

            // DH split times for run 2 (stored in ss5-ss8)
            'run2split1' => 'ss5',
            'run2split2' => 'ss6',
            'run2split3' => 'ss7',
            'run2split4' => 'ss8',
            'split21' => 'ss5',
            'split22' => 'ss6',
            'split23' => 'ss7',
            'split24' => 'ss8',
        ];

        // Check static mappings
        return $mappings[$col] ?? $col;
    }, $header);

    // Cache for lookups
    $eventCache = [];
    $riderCache = [];
    $categoryCache = [];
    $clubCache = [];
    $classCache = [];

    $lineNumber = 1;

    while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
        $lineNumber++;

        // Skip empty rows (all columns empty or only whitespace)
        if (empty(array_filter($row, function($val) { return !empty(trim($val)); }))) {
            continue;
        }

        // Skip field mapping/description rows (contain field names like "class", "position", etc.)
        if (isFieldMappingRow($row)) {
            continue;
        }

        $stats['total']++;

        // Ensure row has same number of columns as header
        if (count($row) < count($header)) {
            $row = array_pad($row, count($header), '');
        } elseif (count($row) > count($header)) {
            $row = array_slice($row, 0, count($header));
        }

        // Map row to associative array
        $data = array_combine($header, $row);

        // Validate required fields
        $hasEventMapping = !empty($IMPORT_EVENT_MAPPING);

        if (empty($data['event_name']) && !$hasEventMapping) {
            $stats['skipped']++;
            $errors[] = "Rad {$lineNumber}: Saknar tävlingsnamn och ingen event vald";
            continue;
        }

        // If no event_name but we have a mapping, use the same key as preview
        if (empty($data['event_name']) && $hasEventMapping) {
            $data['event_name'] = 'Välj event för alla resultat';
        }

        if (empty($data['firstname']) || empty($data['lastname'])) {
            $stats['skipped']++;
            $errors[] = "Rad {$lineNumber}: Saknar namn på cyklist";
            continue;
        }

        try {
            // Find event - use mapping if available
            $eventKey = $data['event_name'];
            $eventId = null;

            if (isset($eventMapping[$eventKey])) {
                $eventId = $eventMapping[$eventKey];
            } else {
                // Try to find event by name
                if (!isset($eventCache[$eventKey])) {
                    $event = $db->getRow(
                        "SELECT id FROM events WHERE name LIKE ? ORDER BY date DESC LIMIT 1",
                        ['%' . $eventKey . '%']
                    );
                    $eventCache[$eventKey] = $event ? $event['id'] : null;
                }
                $eventId = $eventCache[$eventKey];
            }

            if (!$eventId) {
                $stats['skipped']++;
                $errors[] = "Rad {$lineNumber}: Event '{$eventKey}' hittades inte";
                continue;
            }

            // Find or create rider
            $riderName = trim($data['firstname']) . '|' . trim($data['lastname']);
            $rawLicenseNumber = $data['license_number'] ?? '';
            // Normalize UCI-ID: remove all spaces and non-digit characters
            $licenseNumber = preg_replace('/[^0-9]/', '', $rawLicenseNumber);

            if (!isset($riderCache[$riderName . '|' . $licenseNumber])) {
                // Try to find rider by license number first (normalized)
                $rider = null;
                if (!empty($licenseNumber)) {
                    // Try exact match with normalized number
                    $rider = $db->getRow(
                        "SELECT id FROM riders WHERE REPLACE(REPLACE(license_number, ' ', ''), '-', '') = ?",
                        [$licenseNumber]
                    );
                    if ($rider) {
                        $matching_stats['riders_found']++;
                    }
                }

                // Try by name if no license match
                if (!$rider) {
                    // First try exact match
                    $rider = $db->getRow(
                        "SELECT id, license_number FROM riders WHERE LOWER(firstname) = LOWER(?) AND LOWER(lastname) = LOWER(?)",
                        [trim($data['firstname']), trim($data['lastname'])]
                    );

                    // If no exact match, try fuzzy match (first name starts with, handle middle names)
                    if (!$rider) {
                        $firstName = trim($data['firstname']);
                        $lastName = trim($data['lastname']);

                        // Try matching with first part of firstname (handle middle names)
                        $firstNamePart = explode(' ', $firstName)[0];
                        $rider = $db->getRow(
                            "SELECT id, license_number FROM riders
                             WHERE (LOWER(firstname) LIKE LOWER(?) OR LOWER(firstname) = LOWER(?))
                             AND LOWER(lastname) = LOWER(?)",
                            [$firstNamePart . '%', $firstNamePart, $lastName]
                        );
                    }

                    if ($rider) {
                        $matching_stats['riders_found']++;

                        // If we found by name and import has UCI ID but rider doesn't - update rider
                        if (!empty($licenseNumber) && empty($rider['license_number'])) {
                            $db->update('riders', [
                                'license_number' => $licenseNumber
                            ], 'id = ?', [$rider['id']]);
                            $matching_stats['riders_updated_with_uci'] = ($matching_stats['riders_updated_with_uci'] ?? 0) + 1;
                        }
                    }
                }

                // Create new rider if not found
                if (!$rider) {
                    $matching_stats['riders_not_found']++;
                    $matching_stats['riders_created']++;

                    // Determine gender from class name if available
                    $gender = 'M';
                    $className = $data['class_name'] ?? '';
                    if (preg_match('/(dam|women|female|flickor|girls)/i', $className)) {
                        $gender = 'F';
                    } elseif (preg_match('/(herr|men|male|pojkar|boys)/i', $className)) {
                        $gender = 'M';
                    }

                    // Generate SWE license number if no UCI ID provided
                    $finalLicenseNumber = $licenseNumber ?: generateSweLicenseNumber($db);

                    $riderId = $db->insert('riders', [
                        'firstname' => trim($data['firstname']),
                        'lastname' => trim($data['lastname']),
                        'license_number' => $finalLicenseNumber,
                        'gender' => $gender
                    ]);

                    // Track for rollback
                    if ($importId) {
                        trackImportRecord($db, $importId, 'rider', $riderId, 'created');
                    }

                    $riderCache[$riderName . '|' . $licenseNumber] = $riderId;
                } else {
                    $riderCache[$riderName . '|' . $licenseNumber] = $rider['id'];
                }
            }

            $riderId = $riderCache[$riderName . '|' . $licenseNumber];

            // Find or create club
            $clubId = null;
            $clubName = trim($data['club_name'] ?? '');
            if (!empty($clubName)) {
                if (!isset($clubCache[$clubName])) {
                    $club = $db->getRow(
                        "SELECT id FROM clubs WHERE name LIKE ?",
                        ['%' . $clubName . '%']
                    );
                    if (!$club) {
                        // Create club
                        $matching_stats['clubs_created']++;
                        $newClubId = $db->insert('clubs', [
                            'name' => $clubName,
                            'active' => 1
                        ]);
                        if ($importId) {
                            trackImportRecord($db, $importId, 'club', $newClubId, 'created');
                        }
                        $clubCache[$clubName] = $newClubId;
                    } else {
                        $clubCache[$clubName] = $club['id'];
                    }
                }
                $clubId = $clubCache[$clubName];
            }

            // Find or create class
            $classId = $forceClassId;
            $className = trim($data['class_name'] ?? '');
            if (!$classId && !empty($className)) {
                // Normalize class names for common variants
                $classNameMappings = [
                    'tävling damer' => 'Damer Elit',
                    'tävling herrar' => 'Herrar Elit',
                    'tavling damer' => 'Damer Elit',
                    'tavling herrar' => 'Herrar Elit',
                ];
                $normalizedClassName = strtolower($className);
                if (isset($classNameMappings[$normalizedClassName])) {
                    $className = $classNameMappings[$normalizedClassName];
                }

                if (!isset($classCache[$className])) {
                    // Check if we have a mapping from the preview page
                    global $IMPORT_CLASS_MAPPINGS;
                    if (isset($IMPORT_CLASS_MAPPINGS[$className])) {
                        $classCache[$className] = $IMPORT_CLASS_MAPPINGS[$className];
                    } else {
                        // Try exact match first (case-insensitive)
                        $class = $db->getRow(
                            "SELECT id FROM classes WHERE LOWER(display_name) = LOWER(?) OR LOWER(name) = LOWER(?)",
                            [$className, $className]
                        );

                        // Try partial match if exact fails
                        if (!$class) {
                            $class = $db->getRow(
                                "SELECT id FROM classes WHERE LOWER(display_name) LIKE LOWER(?) OR LOWER(name) LIKE LOWER(?)",
                                ['%' . $className . '%', '%' . $className . '%']
                            );
                        }

                        if (!$class) {
                            // Create class
                            $matching_stats['classes_created']++;
                            $newClassId = $db->insert('classes', [
                                'name' => strtolower(str_replace(' ', '_', $className)),
                                'display_name' => $className,
                                'active' => 1
                            ]);
                            if ($importId) {
                                trackImportRecord($db, $importId, 'class', $newClassId, 'created');
                            }
                            $classCache[$className] = $newClassId;
                        } else {
                            $classCache[$className] = $class['id'];
                        }
                    }
                }
                $classId = $classCache[$className];
            }

            // Validate class gender matches rider gender
            // Get rider's actual gender from database
            if ($classId) {
                $riderInfo = $db->getRow("SELECT gender, birth_year FROM riders WHERE id = ?", [$riderId]);
                if ($riderInfo && $riderInfo['gender']) {
                    // Check if class name suggests wrong gender
                    $classIsFemale = preg_match('/(dam|women|female|flickor|girls|^[FK][\d\-])/i', $className);
                    $classIsMale = preg_match('/(herr|men|male|pojkar|boys|^[MP][\d\-])/i', $className);
                    $riderIsFemale = in_array($riderInfo['gender'], ['F', 'K']);

                    // Gender mismatch detected
                    if (($classIsFemale && !$riderIsFemale) || ($classIsMale && $riderIsFemale)) {
                        // Try to find correct class using determineRiderClass
                        if ($riderInfo['birth_year']) {
                            // Get event date for age calculation
                            $eventInfo = $db->getRow("SELECT date, discipline FROM events WHERE id = ?", [$eventId]);
                            if ($eventInfo) {
                                $correctClassId = determineRiderClass(
                                    $db,
                                    $riderInfo['birth_year'],
                                    $riderInfo['gender'],
                                    $eventInfo['date'],
                                    $eventInfo['discipline'] ?? 'ENDURO'
                                );
                                if ($correctClassId) {
                                    $classId = $correctClassId;
                                    // Log this correction
                                    error_log("Import: Corrected class for {$data['firstname']} {$data['lastname']} from '$className' (gender mismatch)");
                                }
                            }
                        }
                    }
                }
            }

            // Parse time
            $finishTime = null;
            $timeStr = trim($data['finish_time'] ?? '');
            if (!empty($timeStr) && $timeStr !== 'DNF' && $timeStr !== 'DNS' && $timeStr !== 'DQ') {
                $finishTime = $timeStr;
            }

            // Determine status
            $status = strtolower(trim($data['status'] ?? 'finished'));
            if (in_array($status, ['fin', 'finished', 'ok', ''])) {
                $status = 'finished';
            } elseif (in_array($status, ['dnf', 'did not finish'])) {
                $status = 'dnf';
            } elseif (in_array($status, ['dns', 'did not start'])) {
                $status = 'dns';
            } elseif (in_array($status, ['dq', 'disqualified'])) {
                $status = 'dq';
            }

            // Check if result already exists
            $existingResult = $db->getRow(
                "SELECT id FROM results WHERE event_id = ? AND cyclist_id = ?",
                [$eventId, $riderId]
            );

            // Calculate points based on position (only if class awards points)
            $position = !empty($data['position']) ? (int)$data['position'] : null;
            $points = 0;

            // Check if class awards points
            $awardsPoints = true;
            if ($classId) {
                $classSettings = $db->getRow("SELECT awards_points FROM classes WHERE id = ?", [$classId]);
                if ($classSettings && isset($classSettings['awards_points'])) {
                    $awardsPoints = (bool)$classSettings['awards_points'];
                }
            }

            if ($status === 'finished' && $position && $awardsPoints) {
                // Use the event's point scale from point_scales table
                $points = calculatePoints($db, $eventId, $position, $status);
            }

            $resultData = [
                'event_id' => $eventId,
                'cyclist_id' => $riderId,
                'class_id' => $classId,
                'bib_number' => $data['bib_number'] ?? null,
                'position' => $position,
                'finish_time' => $finishTime,
                'status' => $status,
                'points' => $points,
                'run_1_time' => $data['run_1_time'] ?? null,
                'run_2_time' => $data['run_2_time'] ?? null,
                'ss1' => $data['ss1'] ?? null,
                'ss2' => $data['ss2'] ?? null,
                'ss3' => $data['ss3'] ?? null,
                'ss4' => $data['ss4'] ?? null,
                'ss5' => $data['ss5'] ?? null,
                'ss6' => $data['ss6'] ?? null,
                'ss7' => $data['ss7'] ?? null,
                'ss8' => $data['ss8'] ?? null,
                'ss9' => $data['ss9'] ?? null,
                'ss10' => $data['ss10'] ?? null,
                'ss11' => $data['ss11'] ?? null,
                'ss12' => $data['ss12'] ?? null,
                'ss13' => $data['ss13'] ?? null,
                'ss14' => $data['ss14'] ?? null,
                'ss15' => $data['ss15'] ?? null,
            ];

            if ($existingResult) {
                // Update existing
                $oldData = $db->getRow("SELECT * FROM results WHERE id = ?", [$existingResult['id']]);

                // Track what changed
                $changes = [];
                foreach ($resultData as $field => $newValue) {
                    $oldValue = $oldData[$field] ?? null;
                    // Normalize for comparison (treat empty string as null)
                    $oldNorm = ($oldValue === '' || $oldValue === null) ? null : $oldValue;
                    $newNorm = ($newValue === '' || $newValue === null) ? null : $newValue;
                    if ($oldNorm !== $newNorm) {
                        $changes[$field] = ['old' => $oldValue, 'new' => $newValue];
                    }
                }

                if (!empty($changes)) {
                    $db->update('results', $resultData, 'id = ?', [$existingResult['id']]);
                    $stats['updated']++;
                    $changelog[] = [
                        'rider' => $data['firstname'] . ' ' . $data['lastname'],
                        'changes' => $changes
                    ];
                    if ($importId) {
                        trackImportRecord($db, $importId, 'result', $existingResult['id'], 'updated', $oldData);
                    }
                } else {
                    // No changes, count as skipped
                    $stats['skipped']++;
                }
            } else {
                // Insert new
                $resultId = $db->insert('results', $resultData);
                $stats['success']++;
                if ($importId) {
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
        'errors' => $errors,
        'stage_names' => $stageNamesMapping,
        'changelog' => $changelog
    ];
}

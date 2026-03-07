<?php
/**
 * Event Import - Paste Results
 * Quick import tool for pasting tab-separated results class by class
 * Supports both standard format and timing system format (enduro/DH)
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/club-matching.php';
require_once __DIR__ . '/../includes/club-membership.php';
require_once __DIR__ . '/../includes/point-calculations.php';
require_admin();

$db = getDB();
$current_admin = get_current_admin();

// Get event ID from URL
$eventId = (int)($_GET['event_id'] ?? 0);
if (!$eventId) {
    header('Location: /admin/events');
    exit;
}

// Get event info
$event = $db->getRow("SELECT * FROM events WHERE id = ?", [$eventId]);
if (!$event) {
    header('Location: /admin/events');
    exit;
}

$eventYear = (int)date('Y', strtotime($event['date']));

// Get existing classes for this event
$eventClasses = $db->getAll("
    SELECT DISTINCT c.id, c.display_name, c.name
    FROM classes c
    INNER JOIN results r ON r.class_id = c.id
    WHERE r.event_id = ?
    ORDER BY c.display_name
", [$eventId]);

// Get all available classes
$allClasses = $db->getAll("SELECT id, display_name, name FROM classes WHERE active = 1 ORDER BY display_name");

$message = '';
$messageType = 'info';
$stats = null;
$preview = null;

/**
 * Detect if data is in timing system format
 * Format: Place(race) \t Place(cat) \t Bib \t Category \t Name \t Club \t Progress \t Time \t SS1 \t SS2 ...
 */
function detectTimingFormat($lines) {
    // Check header line
    $firstLine = strtolower($lines[0] ?? '');
    if (strpos($firstLine, 'place') !== false && strpos($firstLine, 'category') !== false) {
        return true;
    }
    if (strpos($firstLine, 'bib') !== false && strpos($firstLine, 'category') !== false) {
        return true;
    }

    // Check data lines - timing format has Category in column 3 (0-indexed)
    // and typically has 2 place columns at the start
    $dataLine = null;
    for ($i = 0; $i < min(count($lines), 5); $i++) {
        $line = trim($lines[$i]);
        if (empty($line)) continue;
        $cols = explode("\t", $line);
        if (count($cols) >= 8) {
            // Check if col 3 looks like a class name (H35, D19, Herrar, etc.)
            $possibleClass = trim($cols[3] ?? '');
            if (preg_match('/^[HDhd]\d+|^Herr|^Dam|^Open|^Elit/i', $possibleClass)) {
                return true;
            }
        }
    }

    return false;
}

/**
 * Parse timing system format
 * Columns: Place(race), Place(cat), Bib, Category, Name, Club, Progress, Time, SS1, SS2, ...
 */
function parseTimingSystemResults($text, $db) {
    $lines = preg_split('/\r?\n/', trim($text));
    $results = [];
    $errors = [];
    $detectedClasses = [];

    // Detect header
    $firstLine = strtolower($lines[0] ?? '');
    $hasHeader = (strpos($firstLine, 'place') !== false ||
                  strpos($firstLine, 'bib') !== false ||
                  strpos($firstLine, 'category') !== false ||
                  strpos($firstLine, 'name') !== false);

    $startLine = $hasHeader ? 1 : 0;

    for ($i = $startLine; $i < count($lines); $i++) {
        $line = trim($lines[$i]);
        if (empty($line)) continue;

        $cols = explode("\t", $line);

        if (count($cols) < 5) {
            $errors[] = "Rad " . ($i + 1) . ": För få kolumner";
            continue;
        }

        // Col 0: Place (race) - overall placement
        $placeRace = trim($cols[0]);
        // Col 1: Place (cat) - placement within category
        $placeCat = trim($cols[1]);
        // Col 2: Bib number
        $bibNumber = trim($cols[2]);
        // Col 3: Category (class name like H35, D19, etc.)
        $category = trim($cols[3]);
        // Col 4: Name
        $nameRaw = trim($cols[4]);
        // Col 5: Club/Association
        $clubName = isset($cols[5]) ? trim($cols[5]) : '';
        // Col 6: Progress (e.g. "SS5" = completed through SS5)
        $progress = isset($cols[6]) ? trim($cols[6]) : '';
        // Col 7: Time
        $finishTime = isset($cols[7]) ? trim($cols[7]) : '';

        // Determine position and status
        $position = null;
        $status = 'finished';

        if (is_numeric($placeCat)) {
            $position = (int)$placeCat;
        } elseif (strtoupper($placeRace) === 'DNS' || strtoupper($placeCat) === 'DNS') {
            $status = 'dns';
        } elseif (strtoupper($placeRace) === 'DNF' || strtoupper($placeCat) === 'DNF') {
            $status = 'dnf';
        } elseif (strtoupper($placeRace) === 'DQ' || strtoupper($placeRace) === 'DSQ') {
            $status = 'dq';
        }

        // If no position and no special status - check if empty place means DNS
        if ($position === null && $status === 'finished') {
            if (empty($placeRace) && empty($placeCat)) {
                // Empty positions typically mean DNS
                $status = 'dns';
            } elseif (is_numeric($placeRace) && !is_numeric($placeCat)) {
                // Only race place given, use that
                $position = (int)$placeRace;
            }
        }

        // Parse time - clean up
        if (empty($finishTime) || $finishTime === '-' || $finishTime === '0:00.00') {
            if ($status === 'dns' || $status === 'dnf') {
                $finishTime = null;
            } else if ($finishTime === '0:00.00') {
                $finishTime = null;
                if ($position === null) $status = 'dns';
            }
        }

        // Parse name
        $nameParts = preg_split('/\s+/', $nameRaw);
        $firstname = '';
        $lastname = '';

        if (count($nameParts) >= 2) {
            $lastPart = end($nameParts);
            if (preg_match('/^[A-ZÅÄÖ]+$/', $lastPart)) {
                // firstname + LASTNAME format
                $lastname = ucfirst(strtolower(array_pop($nameParts)));
                $firstname = implode(' ', array_map(function($p) {
                    return ucfirst(strtolower($p));
                }, $nameParts));
            } else {
                // Normal format: first last
                $firstname = $nameParts[0];
                $lastname = implode(' ', array_slice($nameParts, 1));
            }
        } else {
            $lastname = $nameRaw;
        }

        // Collect split times (col 8+)
        $splitTimes = [];
        for ($s = 8; $s < count($cols); $s++) {
            $splitTime = trim($cols[$s]);
            if (empty($splitTime) || $splitTime === '-' || $splitTime === '0:00.00') {
                continue;
            }
            if (preg_match('/^\d+:\d+/', $splitTime) || preg_match('/^\d+\.\d+/', $splitTime)) {
                $splitTimes[] = $splitTime;
            }
        }

        // Track detected classes
        if (!empty($category) && !in_array($category, $detectedClasses)) {
            $detectedClasses[] = $category;
        }

        $results[] = [
            'position' => $position,
            'status' => $status,
            'bib_number' => $bibNumber,
            'category' => $category,
            'firstname' => $firstname,
            'lastname' => $lastname,
            'uci_id' => '',
            'club_name' => $clubName,
            'nationality' => '',
            'laps' => null,
            'finish_time' => $finishTime,
            'split_times' => $splitTimes
        ];
    }

    return ['results' => $results, 'errors' => $errors, 'detected_classes' => $detectedClasses];
}

/**
 * Parse pasted data and extract results (standard format)
 */
function parsePastedResults($text, $db) {
    $lines = preg_split('/\r?\n/', trim($text));

    // Auto-detect timing system format
    if (detectTimingFormat($lines)) {
        return parseTimingSystemResults($text, $db);
    }

    $results = [];
    $errors = [];

    // Detect if first line is header
    $firstLine = strtolower($lines[0] ?? '');
    $hasHeader = (strpos($firstLine, 'plac') !== false ||
                  strpos($firstLine, 'startnr') !== false ||
                  strpos($firstLine, 'deltagare') !== false ||
                  strpos($firstLine, 'namn') !== false);

    $startLine = $hasHeader ? 1 : 0;

    for ($i = $startLine; $i < count($lines); $i++) {
        $line = trim($lines[$i]);
        if (empty($line)) continue;

        // Split by tab
        $cols = explode("\t", $line);

        // Need at least: position, startnr, name
        if (count($cols) < 3) {
            $errors[] = "Rad " . ($i + 1) . ": För få kolumner";
            continue;
        }

        // Parse position (can be DNS, DNF, DQ, or number)
        $posRaw = trim($cols[0]);
        $position = null;
        $status = 'finished';

        if (is_numeric($posRaw)) {
            $position = (int)$posRaw;
        } elseif (strtoupper($posRaw) === 'DNS') {
            $status = 'dns';
        } elseif (strtoupper($posRaw) === 'DNF') {
            $status = 'dnf';
        } elseif (strtoupper($posRaw) === 'DQ' || strtoupper($posRaw) === 'DSQ') {
            $status = 'dq';
        }

        // Parse bib number
        $bibNumber = trim($cols[1]);

        // Parse name - can be "Firstname LASTNAME" or "LASTNAME Firstname"
        $nameRaw = trim($cols[2]);
        $nameParts = preg_split('/\s+/', $nameRaw);

        // Detect format: if first word is ALL CAPS, it might be lastname first
        // Common format from timing: "Olof EKFJELL" (firstname + LASTNAME)
        $firstname = '';
        $lastname = '';

        if (count($nameParts) >= 2) {
            // Check if second part is uppercase (firstname + LASTNAME format)
            $lastPart = end($nameParts);
            if (preg_match('/^[A-ZÅÄÖ]+$/', $lastPart)) {
                // firstname + LASTNAME format
                $lastname = ucfirst(strtolower(array_pop($nameParts)));
                $firstname = implode(' ', array_map(function($p) {
                    return ucfirst(strtolower($p));
                }, $nameParts));
            } else {
                // Normal format or mixed
                $firstname = ucfirst(strtolower($nameParts[0]));
                $lastname = ucfirst(strtolower(implode(' ', array_slice($nameParts, 1))));
            }
        } else {
            $lastname = ucfirst(strtolower($nameRaw));
        }

        // UCI-ID (column 3)
        $uciId = isset($cols[3]) ? trim($cols[3]) : '';

        // Club (column 4)
        $clubName = isset($cols[4]) ? trim($cols[4]) : '';

        // Nationality (column 5) - extract 3-letter code
        $nationality = '';
        if (isset($cols[5])) {
            $natRaw = trim($cols[5]);
            // Extract 3-letter code from "Sverige SWE" or just "SWE"
            if (preg_match('/([A-Z]{3})/', $natRaw, $m)) {
                $nationality = $m[1];
            }
        }

        // Laps (column 6) - number of completed laps for XC
        $laps = null;
        if (isset($cols[6])) {
            $lapsRaw = trim($cols[6]);
            if (is_numeric($lapsRaw) && (int)$lapsRaw > 0) {
                $laps = (int)$lapsRaw;
            }
        }

        // Finish time (column 7)
        $finishTime = isset($cols[7]) ? trim($cols[7]) : '';
        // Clean up time - remove trailing stuff like "km/t"
        $finishTime = preg_replace('/\s+.*$/', '', $finishTime);
        if (empty($finishTime) || $finishTime === '-') {
            $finishTime = null;
        }

        // Lap/split times (columns 8+)
        $splitTimes = [];
        for ($s = 8; $s < min(count($cols), 24); $s++) {
            $splitTime = trim($cols[$s]);
            // Skip empty, speed columns, etc.
            if (empty($splitTime) || strpos($splitTime, 'km/t') !== false || $splitTime === '-') {
                continue;
            }
            // Only add if it looks like a time
            if (preg_match('/^\d+:\d+/', $splitTime) || preg_match('/^\d+\.\d+/', $splitTime)) {
                $splitTimes[] = $splitTime;
            }
        }

        $results[] = [
            'position' => $position,
            'status' => $status,
            'bib_number' => $bibNumber,
            'category' => '',
            'firstname' => $firstname,
            'lastname' => $lastname,
            'uci_id' => $uciId,
            'club_name' => $clubName,
            'nationality' => $nationality,
            'laps' => $laps,
            'finish_time' => $finishTime,
            'split_times' => $splitTimes
        ];
    }

    return ['results' => $results, 'errors' => $errors, 'detected_classes' => []];
}

/**
 * Find or create rider
 */
function findOrCreateRider($db, $firstname, $lastname, $uciId, $nationality, $clubId) {
    // Normalize UCI ID
    $uciIdDigits = preg_replace('/[^0-9]/', '', $uciId);

    // Try UCI ID first
    if (!empty($uciIdDigits)) {
        $rider = $db->getRow(
            "SELECT id FROM riders WHERE REPLACE(REPLACE(license_number, ' ', ''), '-', '') = ?",
            [$uciIdDigits]
        );
        if ($rider) return $rider['id'];
    }

    // Try name match
    $rider = $db->getRow(
        "SELECT id FROM riders WHERE UPPER(firstname) = UPPER(?) AND UPPER(lastname) = UPPER(?)",
        [$firstname, $lastname]
    );
    if ($rider) {
        // Update UCI if missing
        if (!empty($uciId)) {
            $existing = $db->getRow("SELECT license_number FROM riders WHERE id = ?", [$rider['id']]);
            if (empty($existing['license_number'])) {
                $db->update('riders', ['license_number' => $uciId], 'id = ?', [$rider['id']]);
            }
        }
        return $rider['id'];
    }

    // Check if this name was previously merged (to prevent recreating deleted duplicates)
    try {
        $mergedRider = $db->getRow(
            "SELECT canonical_rider_id FROM rider_merge_map
             WHERE UPPER(merged_firstname) = UPPER(?) AND UPPER(merged_lastname) = UPPER(?)
             AND status = 'approved'",
            [$firstname, $lastname]
        );
        if ($mergedRider) {
            return $mergedRider['canonical_rider_id'];
        }
    } catch (Exception $e) { /* Table might not exist yet */ }

    // Create new rider
    $licenseNumber = !empty($uciId) ? $uciId : generateSweId($db);

    $riderId = $db->insert('riders', [
        'firstname' => $firstname,
        'lastname' => $lastname,
        'license_number' => $licenseNumber,
        'nationality' => !empty($nationality) ? $nationality : null,
        'club_id' => $clubId,
        'gender' => 'M', // Default, will be corrected by class
        'active' => 1
    ]);

    return $riderId;
}

/**
 * Map category name to class ID
 */
function findClassByCategory($db, $categoryName) {
    if (empty($categoryName)) return null;

    // Try exact match on display_name or name
    $class = $db->getRow(
        "SELECT id FROM classes WHERE (LOWER(display_name) = LOWER(?) OR LOWER(name) = LOWER(?)) AND active = 1",
        [$categoryName, $categoryName]
    );
    if ($class) return $class['id'];

    // Try partial match (e.g. "H35" matches "Herrar 35+" or "H35")
    $class = $db->getRow(
        "SELECT id FROM classes WHERE (display_name LIKE ? OR name LIKE ?) AND active = 1 ORDER BY id LIMIT 1",
        ['%' . $categoryName . '%', '%' . $categoryName . '%']
    );
    if ($class) return $class['id'];

    return null;
}

// Handle AJAX rider search
if (isset($_GET['ajax']) && $_GET['ajax'] === 'search_rider') {
    header('Content-Type: application/json');
    $q = trim($_GET['q'] ?? '');
    if (strlen($q) < 2) {
        echo json_encode([]);
        exit;
    }

    $words = preg_split('/\s+/', $q);
    if (count($words) >= 2) {
        $riders = $db->getAll("
            SELECT r.id, r.firstname, r.lastname, c.name as club_name
            FROM riders r
            LEFT JOIN clubs c ON r.club_id = c.id
            WHERE r.firstname LIKE ? AND r.lastname LIKE ?
            ORDER BY r.lastname, r.firstname
            LIMIT 10
        ", ['%' . $words[0] . '%', '%' . $words[count($words)-1] . '%']);
    } else {
        $riders = $db->getAll("
            SELECT r.id, r.firstname, r.lastname, c.name as club_name
            FROM riders r
            LEFT JOIN clubs c ON r.club_id = c.id
            WHERE r.firstname LIKE ? OR r.lastname LIKE ?
            ORDER BY r.lastname, r.firstname
            LIMIT 10
        ", ['%' . $q . '%', '%' . $q . '%']);
    }

    echo json_encode($riders);
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();

    $action = $_POST['action'] ?? 'preview';
    $pastedData = $_POST['pasted_data'] ?? '';
    $classId = (int)($_POST['class_id'] ?? 0);
    $newClassName = trim($_POST['new_class_name'] ?? '');
    $complementMode = !empty($_POST['complement_mode']);
    $autoDetectClasses = !empty($_POST['auto_detect_classes']);

    // Create new class if specified
    if (!$classId && !empty($newClassName)) {
        $existingClass = $db->getRow(
            "SELECT id FROM classes WHERE LOWER(display_name) = LOWER(?) OR LOWER(name) = LOWER(?)",
            [$newClassName, $newClassName]
        );

        if ($existingClass) {
            $classId = $existingClass['id'];
        } else {
            $classId = $db->insert('classes', [
                'name' => strtolower(str_replace(' ', '_', $newClassName)),
                'display_name' => $newClassName,
                'active' => 1
            ]);
        }
    }

    if (empty($pastedData)) {
        $message = 'Ingen data inklistrad';
        $messageType = 'error';
    } elseif (!$classId && !$autoDetectClasses) {
        $message = 'Välj eller skapa en klass, eller aktivera auto-detektering';
        $messageType = 'error';
    } else {
        $parsed = parsePastedResults($pastedData, $db);

        // If auto-detect classes from Category column
        $hasDetectedClasses = !empty($parsed['detected_classes']);

        if ($action === 'preview') {
            $preview = $parsed;
            $preview['class_id'] = $classId;
            $preview['complement_mode'] = $complementMode;
            $preview['auto_detect_classes'] = $autoDetectClasses;

            // Map detected classes to DB class IDs
            $classMap = [];
            if ($hasDetectedClasses) {
                foreach ($parsed['detected_classes'] as $catName) {
                    $mappedId = findClassByCategory($db, $catName);
                    $classMap[$catName] = $mappedId;
                }
                $preview['class_map'] = $classMap;
            }

            // If single class selected, use it for all
            if ($classId) {
                $classInfo = $db->getRow("SELECT display_name FROM classes WHERE id = ?", [$classId]);
                $preview['class_name'] = $classInfo['display_name'] ?? 'Okänd klass';
            }

            // Get existing results for complement mode
            $existingRiderIds = [];
            if ($complementMode) {
                if ($classId) {
                    $existing = $db->getAll(
                        "SELECT cyclist_id FROM results WHERE event_id = ? AND class_id = ?",
                        [$eventId, $classId]
                    );
                    $existingRiderIds = array_column($existing, 'cyclist_id');
                }
                // For auto-detect, we check per class in the loop
            }

            // Look up existing riders for preview
            foreach ($preview['results'] as &$row) {
                $uciIdDigits = preg_replace('/[^0-9]/', '', $row['uci_id']);
                $found = false;

                if (!empty($uciIdDigits)) {
                    $rider = $db->getRow(
                        "SELECT id, firstname, lastname FROM riders WHERE REPLACE(REPLACE(license_number, ' ', ''), '-', '') = ?",
                        [$uciIdDigits]
                    );
                    if ($rider) {
                        $row['rider_id'] = $rider['id'];
                        $row['rider_match'] = 'UCI-ID';
                        $row['db_name'] = $rider['firstname'] . ' ' . $rider['lastname'];
                        $found = true;
                    }
                }

                if (!$found) {
                    // Try exact name match
                    $rider = $db->getRow(
                        "SELECT id, firstname, lastname FROM riders WHERE UPPER(firstname) = UPPER(?) AND UPPER(lastname) = UPPER(?)",
                        [$row['firstname'], $row['lastname']]
                    );
                    if ($rider) {
                        $row['rider_id'] = $rider['id'];
                        $row['rider_match'] = 'Namn';
                        $row['db_name'] = $rider['firstname'] . ' ' . $rider['lastname'];
                    } else {
                        // Try fuzzy match (LIKE on firstname + lastname)
                        $fuzzy = $db->getRow(
                            "SELECT id, firstname, lastname FROM riders WHERE firstname LIKE ? AND lastname LIKE ? LIMIT 1",
                            ['%' . substr($row['firstname'], 0, 3) . '%', '%' . substr($row['lastname'], 0, 3) . '%']
                        );
                        if ($fuzzy) {
                            $row['rider_id'] = $fuzzy['id'];
                            $row['rider_match'] = 'Fuzzy';
                            $row['db_name'] = $fuzzy['firstname'] . ' ' . $fuzzy['lastname'];
                        } else {
                            $row['rider_id'] = null;
                            $row['rider_match'] = 'Ny';
                            $row['db_name'] = '';
                        }
                    }
                }

                // Check if already exists in complement mode
                $rowClassId = $classId;
                if ($hasDetectedClasses && $autoDetectClasses && !empty($row['category'])) {
                    $rowClassId = $classMap[$row['category']] ?? $classId;
                }
                $row['_effective_class_id'] = $rowClassId;

                if ($complementMode && $row['rider_id']) {
                    if ($rowClassId) {
                        $existsInClass = $db->getRow(
                            "SELECT id FROM results WHERE event_id = ? AND cyclist_id = ? AND class_id = ?",
                            [$eventId, $row['rider_id'], $rowClassId]
                        );
                        $row['already_exists'] = !empty($existsInClass);
                    } else {
                        $row['already_exists'] = false;
                    }
                } else {
                    $row['already_exists'] = false;
                }
            }
            unset($row);

        } else {
            // ===== DO IMPORT =====
            $riderOverrides = $_POST['rider_id'] ?? [];
            $skipRows = $_POST['skip_row'] ?? [];

            $stats = [
                'total' => count($parsed['results']),
                'success' => 0,
                'updated' => 0,
                'skipped' => 0,
                'failed' => 0,
                'riders_created' => 0,
                'clubs_created' => 0
            ];
            $errors = $parsed['errors'];

            // Map detected classes
            $classMap = [];
            if ($hasDetectedClasses && $autoDetectClasses) {
                foreach ($parsed['detected_classes'] as $catName) {
                    $mappedId = findClassByCategory($db, $catName);
                    if (!$mappedId) {
                        // Create class
                        $mappedId = $db->insert('classes', [
                            'name' => strtolower(str_replace(' ', '_', $catName)),
                            'display_name' => $catName,
                            'active' => 1
                        ]);
                    }
                    $classMap[$catName] = $mappedId;
                }
            }

            foreach ($parsed['results'] as $idx => $row) {
                // Skip if marked to skip
                if (isset($skipRows[$idx])) {
                    $stats['skipped']++;
                    continue;
                }

                try {
                    // Determine class for this row
                    $rowClassId = $classId;
                    if ($hasDetectedClasses && $autoDetectClasses && !empty($row['category'])) {
                        $rowClassId = $classMap[$row['category']] ?? $classId;
                    }

                    if (!$rowClassId) {
                        $errors[] = "{$row['firstname']} {$row['lastname']}: Ingen klass hittad för '{$row['category']}'";
                        $stats['failed']++;
                        continue;
                    }

                    // Check for manual rider override
                    $riderId = null;
                    if (isset($riderOverrides[$idx]) && (int)$riderOverrides[$idx] > 0) {
                        $riderId = (int)$riderOverrides[$idx];
                    }

                    if (!$riderId) {
                        // Find or create club
                        $clubId = null;
                        if (!empty($row['club_name'])) {
                            $club = findClubByName($db, $row['club_name']);
                            if ($club) {
                                $clubId = $club['id'];
                            } else {
                                $clubId = $db->insert('clubs', [
                                    'name' => $row['club_name'],
                                    'active' => 1
                                ]);
                                $stats['clubs_created']++;
                            }
                        }

                        // Find or create rider
                        $riderId = findOrCreateRider(
                            $db,
                            $row['firstname'],
                            $row['lastname'],
                            $row['uci_id'],
                            $row['nationality'],
                            $clubId
                        );
                    } else {
                        // For manually linked riders, still find/create club for the result row
                        $clubId = null;
                        if (!empty($row['club_name'])) {
                            $club = findClubByName($db, $row['club_name']);
                            if ($club) $clubId = $club['id'];
                        }
                    }

                    // Update rider club for this year
                    if ($clubId) {
                        setRiderClubForYear($db, $riderId, $clubId, $eventYear);
                        lockRiderClubForYear($db, $riderId, $eventYear);
                        $db->update('riders', ['club_id' => $clubId], 'id = ?', [$riderId]);
                    }

                    // Check for existing result
                    $existingResult = $db->getRow(
                        "SELECT id FROM results WHERE event_id = ? AND cyclist_id = ? AND class_id = ?",
                        [$eventId, $riderId, $rowClassId]
                    );

                    // Complement mode: skip if result already exists
                    if ($complementMode && $existingResult) {
                        $stats['skipped']++;
                        continue;
                    }

                    // Calculate points
                    $points = 0;
                    if ($row['status'] === 'finished' && $row['position']) {
                        $points = calculatePoints($db, $eventId, $row['position'], $row['status'], $rowClassId);
                    }

                    // Prepare result data
                    $resultData = [
                        'event_id' => $eventId,
                        'cyclist_id' => $riderId,
                        'club_id' => $clubId,
                        'class_id' => $rowClassId,
                        'bib_number' => $row['bib_number'],
                        'position' => $row['position'],
                        'finish_time' => $row['finish_time'],
                        'status' => $row['status'],
                        'laps' => $row['laps'],
                        'points' => $points
                    ];

                    // Add split times
                    foreach ($row['split_times'] as $ssIdx => $time) {
                        $ssCol = 'ss' . ($ssIdx + 1);
                        if ($ssIdx < 15) { // ss1-ss15
                            $resultData[$ssCol] = $time;
                        }
                    }

                    if ($existingResult) {
                        $db->update('results', $resultData, 'id = ?', [$existingResult['id']]);
                        $stats['updated']++;
                    } else {
                        $db->insert('results', $resultData);
                        $stats['success']++;
                    }

                } catch (Exception $e) {
                    $stats['failed']++;
                    $errors[] = "{$row['firstname']} {$row['lastname']}: " . $e->getMessage();
                }
            }

            if ($stats['success'] > 0 || $stats['updated'] > 0) {
                $message = "Import klar! {$stats['success']} nya resultat, {$stats['updated']} uppdaterade.";
                if ($stats['skipped'] > 0) {
                    $message .= " {$stats['skipped']} hoppade över (fanns redan).";
                }
                if ($stats['riders_created'] > 0) {
                    $message .= " {$stats['riders_created']} nya åkare skapade.";
                }
                $messageType = 'success';
            } else {
                $message = "Ingen data importerades.";
                if ($stats['skipped'] > 0) {
                    $message .= " {$stats['skipped']} hoppade över (fanns redan).";
                }
                $messageType = 'warning';
            }

            if (!empty($errors)) {
                $message .= " " . count($errors) . " fel.";
            }
        }
    }
}

// Page config
$page_title = 'Importera resultat - ' . h($event['name']);
$breadcrumbs = [
    ['label' => 'Events', 'url' => '/admin/events'],
    ['label' => $event['name'], 'url' => '/admin/event/edit/' . $eventId],
    ['label' => 'Importera resultat']
];

include __DIR__ . '/components/unified-layout.php';
?>

<!-- Message -->
<?php if ($message): ?>
<div class="alert alert-<?= h($messageType) ?> mb-lg">
    <i data-lucide="<?= $messageType === 'success' ? 'check-circle' : ($messageType === 'error' ? 'alert-circle' : 'info') ?>"></i>
    <?= h($message) ?>
</div>
<?php endif; ?>

<!-- Event Info -->
<div class="admin-card mb-lg">
    <div class="admin-card-body" style="display: flex; align-items: center; gap: var(--space-lg);">
        <div>
            <h2 style="margin: 0;"><?= h($event['name']) ?></h2>
            <p class="text-secondary" style="margin: var(--space-xs) 0 0 0;">
                <?= date('Y-m-d', strtotime($event['date'])) ?>
                <?php if ($event['location']): ?>
                    &bull; <?= h($event['location']) ?>
                <?php endif; ?>
            </p>
        </div>
        <?php if (!empty($eventClasses)): ?>
        <div class="text-secondary" style="margin-left: auto;">
            <i data-lucide="users"></i>
            <?= count($eventClasses) ?> klasser importerade
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($preview): ?>
<!-- Preview -->
<div class="admin-card mb-lg">
    <div class="admin-card-header">
        <h2>
            <i data-lucide="eye"></i>
            Förhandsgranskning
            <?php if (!empty($preview['class_name'])): ?>
                - <?= h($preview['class_name']) ?>
            <?php endif; ?>
        </h2>
    </div>
    <div class="admin-card-body">
        <?php
        $totalRows = count($preview['results']);
        $matchedRows = count(array_filter($preview['results'], fn($r) => $r['rider_match'] !== 'Ny'));
        $newRows = $totalRows - $matchedRows;
        $alreadyExists = count(array_filter($preview['results'], fn($r) => !empty($r['already_exists'])));
        ?>
        <div style="display: flex; gap: var(--space-lg); flex-wrap: wrap; margin-bottom: var(--space-md);">
            <div><strong><?= $totalRows ?></strong> resultat hittades</div>
            <div><span class="badge badge-success"><?= $matchedRows ?> matchade</span></div>
            <div><span class="badge badge-info"><?= $newRows ?> nya</span></div>
            <?php if ($alreadyExists > 0): ?>
            <div><span class="badge badge-warning"><?= $alreadyExists ?> finns redan</span></div>
            <?php endif; ?>
        </div>

        <?php if (!empty($preview['detected_classes'])): ?>
        <div class="alert alert-info mb-md">
            <i data-lucide="layers"></i>
            <strong>Detekterade klasser:</strong>
            <?php foreach ($preview['detected_classes'] as $cat): ?>
                <?php $mapped = $preview['class_map'][$cat] ?? null; ?>
                <span class="badge <?= $mapped ? 'badge-success' : 'badge-warning' ?>" style="margin-left: var(--space-xs);">
                    <?= h($cat) ?>
                    <?php if ($mapped): ?>
                        <?php $ci = $db->getRow("SELECT display_name FROM classes WHERE id = ?", [$mapped]); ?>
                        &rarr; <?= h($ci['display_name'] ?? '?') ?>
                    <?php else: ?>
                        (ny klass)
                    <?php endif; ?>
                </span>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($preview['errors'])): ?>
        <div class="alert alert-warning mb-md">
            <strong>Varningar:</strong>
            <ul style="margin: var(--space-xs) 0 0 0; padding-left: var(--space-lg);">
                <?php foreach ($preview['errors'] as $err): ?>
                <li><?= h($err) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <form method="POST" id="importForm">
            <?= csrf_field() ?>
            <input type="hidden" name="pasted_data" value="<?= h($_POST['pasted_data'] ?? '') ?>">
            <input type="hidden" name="class_id" value="<?= $preview['class_id'] ?>">
            <input type="hidden" name="action" value="import">
            <?php if ($preview['complement_mode']): ?>
            <input type="hidden" name="complement_mode" value="1">
            <?php endif; ?>
            <?php if ($preview['auto_detect_classes'] ?? false): ?>
            <input type="hidden" name="auto_detect_classes" value="1">
            <?php endif; ?>

            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th style="width: 50px;">Plac</th>
                            <th style="width: 60px;">Nr</th>
                            <?php if (!empty($preview['detected_classes'])): ?>
                            <th style="width: 70px;">Klass</th>
                            <?php endif; ?>
                            <th>Namn (import)</th>
                            <th>Klubb</th>
                            <th>Tid</th>
                            <th style="width: 80px;">Match</th>
                            <th style="min-width: 220px;">Kopplad åkare</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($preview['results'] as $idx => $row): ?>
                        <tr class="<?= $row['already_exists'] ? 'opacity-50' : '' ?>" id="row-<?= $idx ?>">
                            <td>
                                <?php if ($row['status'] !== 'finished'): ?>
                                    <span class="badge badge-warning"><?= strtoupper($row['status']) ?></span>
                                <?php else: ?>
                                    <?= $row['position'] ?>
                                <?php endif; ?>
                            </td>
                            <td><?= h($row['bib_number']) ?></td>
                            <?php if (!empty($preview['detected_classes'])): ?>
                            <td><code><?= h($row['category']) ?></code></td>
                            <?php endif; ?>
                            <td>
                                <strong><?= h($row['firstname'] . ' ' . $row['lastname']) ?></strong>
                            </td>
                            <td class="text-sm"><?= h($row['club_name']) ?></td>
                            <td class="text-sm"><?= h($row['finish_time'] ?? '-') ?></td>
                            <td>
                                <?php if ($row['already_exists']): ?>
                                    <span class="badge badge-warning" title="Finns redan i klassen">Finns</span>
                                <?php elseif ($row['rider_match'] === 'Ny'): ?>
                                    <span class="badge badge-info">Ny</span>
                                <?php elseif ($row['rider_match'] === 'UCI-ID'): ?>
                                    <span class="badge badge-success">UCI-ID</span>
                                <?php elseif ($row['rider_match'] === 'Fuzzy'): ?>
                                    <span class="badge badge-warning">Fuzzy</span>
                                <?php else: ?>
                                    <span class="badge badge-secondary">Namn</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="rider-link-cell" data-idx="<?= $idx ?>">
                                    <input type="hidden" name="rider_id[<?= $idx ?>]" value="<?= $row['rider_id'] ?? 0 ?>" id="riderId-<?= $idx ?>">

                                    <?php if ($row['already_exists']): ?>
                                        <span class="text-secondary text-sm">Hoppar över</span>
                                    <?php else: ?>
                                        <div class="rider-link-display" id="riderDisplay-<?= $idx ?>">
                                            <?php if ($row['rider_id']): ?>
                                                <span class="text-sm">
                                                    <a href="/rider/<?= $row['rider_id'] ?>" target="_blank"><?= h($row['db_name']) ?></a>
                                                    <button type="button" class="btn-link text-sm" onclick="openRiderSearch(<?= $idx ?>, '<?= h(addslashes($row['firstname'] . ' ' . $row['lastname'])) ?>')" title="Byt åkare">
                                                        <i data-lucide="pencil" style="width:14px;height:14px;"></i>
                                                    </button>
                                                </span>
                                            <?php else: ?>
                                                <button type="button" class="btn-admin btn-admin-secondary btn-sm" onclick="openRiderSearch(<?= $idx ?>, '<?= h(addslashes($row['firstname'] . ' ' . $row['lastname'])) ?>')">
                                                    <i data-lucide="search" style="width:14px;height:14px;"></i>
                                                    Sök åkare
                                                </button>
                                            <?php endif; ?>
                                        </div>

                                        <div class="rider-search-box" id="riderSearch-<?= $idx ?>" style="display:none;">
                                            <div style="display:flex;gap:4px;">
                                                <input type="text" class="admin-form-input" style="font-size:0.85rem;padding:4px 8px;"
                                                       id="riderSearchInput-<?= $idx ?>"
                                                       placeholder="Sök namn..."
                                                       oninput="searchRider(<?= $idx ?>, this.value)">
                                                <button type="button" class="btn-admin btn-admin-secondary btn-sm" onclick="closeRiderSearch(<?= $idx ?>)" title="Stäng">
                                                    <i data-lucide="x" style="width:14px;height:14px;"></i>
                                                </button>
                                            </div>
                                            <div class="rider-search-results" id="riderResults-<?= $idx ?>" style="max-height:150px;overflow-y:auto;margin-top:4px;"></div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div style="display: flex; gap: var(--space-md); margin-top: var(--space-lg); flex-wrap: wrap;">
                <button type="submit" class="btn-admin btn-admin-primary">
                    <i data-lucide="download"></i>
                    <?php if ($complementMode): ?>
                        Komplettera med saknade resultat
                    <?php else: ?>
                        Importera <?= count($preview['results']) ?> resultat
                    <?php endif; ?>
                </button>
                <a href="?event_id=<?= $eventId ?>" class="btn-admin btn-admin-secondary">
                    <i data-lucide="arrow-left"></i>
                    Tillbaka
                </a>
            </div>
        </form>
    </div>
</div>

<?php else: ?>
<!-- Import Form -->
<div class="admin-card mb-lg">
    <div class="admin-card-header">
        <h2>
            <i data-lucide="clipboard-paste"></i>
            Klistra in resultat
        </h2>
    </div>
    <div class="admin-card-body">
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="preview">

            <!-- Class Selection -->
            <div class="admin-form-group">
                <label class="admin-form-label">
                    <i data-lucide="tag"></i>
                    Välj klass
                </label>
                <div style="display: flex; gap: var(--space-md); flex-wrap: wrap; align-items: center;">
                    <select name="class_id" class="admin-form-select" style="flex: 1; min-width: 200px;" id="classSelect">
                        <option value="">-- Välj befintlig klass --</option>
                        <?php if (!empty($eventClasses)): ?>
                        <optgroup label="Klasser i detta event">
                            <?php foreach ($eventClasses as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= h($c['display_name']) ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                        <?php endif; ?>
                        <optgroup label="Alla klasser">
                            <?php foreach ($allClasses as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= h($c['display_name']) ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                    </select>
                    <span class="text-secondary" style="align-self: center;">eller</span>
                    <input type="text" name="new_class_name" class="admin-form-input"
                           style="flex: 1; min-width: 200px;"
                           placeholder="Skapa ny klass..."
                           id="newClassInput">
                </div>
                <div style="margin-top: var(--space-sm);">
                    <label style="display: flex; align-items: center; gap: var(--space-xs); cursor: pointer;">
                        <input type="checkbox" name="auto_detect_classes" value="1" id="autoDetectCheck">
                        <span class="text-sm">Auto-detektera klasser från data (om "Category"-kolumn finns)</span>
                    </label>
                </div>
            </div>

            <!-- Import Mode -->
            <div class="admin-form-group">
                <label class="admin-form-label">
                    <i data-lucide="settings"></i>
                    Importläge
                </label>
                <div style="display: flex; gap: var(--space-lg); flex-wrap: wrap;">
                    <label style="display: flex; align-items: center; gap: var(--space-xs); cursor: pointer;">
                        <input type="radio" name="complement_mode" value="" checked>
                        <span>Ersätt/uppdatera alla resultat</span>
                    </label>
                    <label style="display: flex; align-items: center; gap: var(--space-xs); cursor: pointer;">
                        <input type="radio" name="complement_mode" value="1">
                        <span>Komplettera (bara lägg till saknade resultat)</span>
                    </label>
                </div>
                <p class="text-secondary text-sm" style="margin-top: var(--space-xs);">
                    Komplettera behåller befintliga resultat och lägger bara till åkare som saknas i klassen.
                </p>
            </div>

            <!-- Paste Area -->
            <div class="admin-form-group">
                <label class="admin-form-label">
                    <i data-lucide="file-text"></i>
                    Klistra in data (tab-separerad)
                </label>
                <textarea name="pasted_data" class="admin-form-textarea"
                          rows="15"
                          placeholder="Klistra in resultat här...

Format 1 (Standardformat):
Plac.  Startnr.  Deltagare  UCI-ID  Klubb  Land  Varv  Tid  SS1  SS2...

Format 2 (Tidtagningssystem):
Place(race)  Place(cat)  Bib  Category  Name  Association  Progress  Time  SS1  SS2...

Exempel (tidtagningssystem):
1  1  132  H35  Oliver Barton  Saltsjöbaden CK  SS5  8:29.31  0:53.14  1:03.93
2  2  42  H35  Erik Svensson  CK Master  SS5  9:01.45  0:55.11  1:05.22"
                          style="font-family: monospace; font-size: 0.85rem;"
                ><?= h($_POST['pasted_data'] ?? '') ?></textarea>
            </div>

            <button type="submit" class="btn-admin btn-admin-primary">
                <i data-lucide="eye"></i>
                Förhandsgranska
            </button>
        </form>
    </div>
</div>

<!-- Format Help -->
<div class="admin-card">
    <div class="admin-card-header">
        <h2>
            <i data-lucide="help-circle"></i>
            Format
        </h2>
    </div>
    <div class="admin-card-body">
        <p class="text-secondary mb-md">
            Kopiera resultat från timing-system, Excel eller webbsida och klistra in.
            Tab-separerade kolumner stöds. Formatet detekteras automatiskt.
        </p>

        <h3 class="mb-sm">Format 1: Standardformat</h3>
        <div class="table-responsive mb-lg">
            <table class="table table-sm">
                <thead>
                    <tr><th>Kolumn</th><th>Beskrivning</th><th>Exempel</th></tr>
                </thead>
                <tbody>
                    <tr><td><code>Plac.</code></td><td>Placering eller DNS/DNF/DQ</td><td>1, DNS</td></tr>
                    <tr><td><code>Startnr.</code></td><td>Nummerlapp</td><td>531</td></tr>
                    <tr><td><code>Deltagare</code></td><td>Namn (Förnamn EFTERNAMN)</td><td>Olof EKFJELL</td></tr>
                    <tr><td><code>UCI-ID</code></td><td>UCI-licensnummer</td><td>10006446036</td></tr>
                    <tr><td><code>Klubb</code></td><td>Klubbnamn</td><td>Alingsås SC</td></tr>
                    <tr><td><code>Land</code></td><td>Nationalitet</td><td>Sverige SWE</td></tr>
                    <tr><td><code>Varv</code></td><td>Genomförda varv</td><td>5</td></tr>
                    <tr><td><code>Tid</code></td><td>Sluttid</td><td>1:02:54</td></tr>
                    <tr><td><code>SS1, SS2...</code></td><td>Split-tider</td><td>11:55</td></tr>
                </tbody>
            </table>
        </div>

        <h3 class="mb-sm">Format 2: Tidtagningssystem (Enduro/DH)</h3>
        <div class="table-responsive">
            <table class="table table-sm">
                <thead>
                    <tr><th>Kolumn</th><th>Beskrivning</th><th>Exempel</th></tr>
                </thead>
                <tbody>
                    <tr><td><code>Place (race)</code></td><td>Totalplacering</td><td>1</td></tr>
                    <tr><td><code>Place (cat)</code></td><td>Placering i klass</td><td>1</td></tr>
                    <tr><td><code>Bib no</code></td><td>Nummerlapp</td><td>132</td></tr>
                    <tr><td><code>Category</code></td><td>Klass (auto-mappas)</td><td>H35</td></tr>
                    <tr><td><code>Name</code></td><td>Deltagarens namn</td><td>Oliver Barton</td></tr>
                    <tr><td><code>Association</code></td><td>Klubb</td><td>Saltsjöbaden CK</td></tr>
                    <tr><td><code>Progress</code></td><td>Senaste SS</td><td>SS5</td></tr>
                    <tr><td><code>Time</code></td><td>Totaltid</td><td>8:29.31</td></tr>
                    <tr><td><code>SS1-SS10</code></td><td>Split-tider per sträcka</td><td>0:53.14</td></tr>
                </tbody>
            </table>
        </div>

        <div class="alert alert-info mt-lg">
            <i data-lucide="info"></i>
            <strong>Tips:</strong> Header-raden ignoreras automatiskt. Vid tidtagningsformat detekteras klasser automatiskt från "Category"-kolumnen.
            Använd "Komplettera"-läget för att bara lägga till saknade resultat utan att röra befintliga.
        </div>
    </div>
</div>
<?php endif; ?>

<style>
.rider-link-cell { position: relative; }
.rider-search-results .rider-option {
    padding: 6px 8px;
    cursor: pointer;
    border-bottom: 1px solid var(--color-border);
    font-size: 0.85rem;
}
.rider-search-results .rider-option:hover {
    background: var(--color-bg-hover);
}
.rider-search-results .rider-option .rider-club {
    color: var(--color-text-muted);
    font-size: 0.8rem;
}
.rider-search-box {
    background: var(--color-bg-card);
    border: 1px solid var(--color-border-strong);
    border-radius: var(--radius-sm);
    padding: var(--space-xs);
}
.btn-link {
    background: none;
    border: none;
    color: var(--color-accent);
    cursor: pointer;
    padding: 2px;
    vertical-align: middle;
}
.btn-link:hover { opacity: 0.8; }
.btn-sm { padding: 4px 8px; font-size: 0.8rem; }
.opacity-50 { opacity: 0.5; }
tr.opacity-50 td { text-decoration: line-through; }
</style>

<script>
let searchTimeout = null;

function openRiderSearch(idx, defaultQuery) {
    const searchBox = document.getElementById('riderSearch-' + idx);
    const display = document.getElementById('riderDisplay-' + idx);
    if (searchBox && display) {
        display.style.display = 'none';
        searchBox.style.display = 'block';
        const input = document.getElementById('riderSearchInput-' + idx);
        if (input) {
            input.value = defaultQuery || '';
            input.focus();
            if (defaultQuery) searchRider(idx, defaultQuery);
        }
    }
}

function closeRiderSearch(idx) {
    const searchBox = document.getElementById('riderSearch-' + idx);
    const display = document.getElementById('riderDisplay-' + idx);
    if (searchBox && display) {
        searchBox.style.display = 'none';
        display.style.display = '';
    }
}

function searchRider(idx, query) {
    clearTimeout(searchTimeout);
    const resultsDiv = document.getElementById('riderResults-' + idx);
    if (!resultsDiv) return;

    if (query.length < 2) {
        resultsDiv.innerHTML = '<div class="text-sm text-secondary" style="padding:4px 8px;">Skriv minst 2 tecken...</div>';
        return;
    }

    searchTimeout = setTimeout(() => {
        fetch('?event_id=<?= $eventId ?>&ajax=search_rider&q=' + encodeURIComponent(query))
            .then(r => r.json())
            .then(riders => {
                if (riders.length === 0) {
                    resultsDiv.innerHTML = '<div class="text-sm text-secondary" style="padding:4px 8px;">Inga träffar. Åkaren skapas vid import.</div>';
                    return;
                }

                let html = '';
                riders.forEach(r => {
                    html += '<div class="rider-option" onclick="selectRider(' + idx + ', ' + r.id + ', \'' +
                        (r.firstname + ' ' + r.lastname).replace(/'/g, "\\'") + '\')">' +
                        '<strong>' + r.firstname + ' ' + r.lastname + '</strong>' +
                        (r.club_name ? ' <span class="rider-club">' + r.club_name + '</span>' : '') +
                        '</div>';
                });
                resultsDiv.innerHTML = html;
            })
            .catch(() => {
                resultsDiv.innerHTML = '<div class="text-sm text-secondary" style="padding:4px 8px;">Sökfel</div>';
            });
    }, 250);
}

function selectRider(idx, riderId, riderName) {
    // Set hidden input
    document.getElementById('riderId-' + idx).value = riderId;

    // Update display
    const display = document.getElementById('riderDisplay-' + idx);
    display.innerHTML = '<span class="text-sm">' +
        '<a href="/rider/' + riderId + '" target="_blank">' + riderName + '</a> ' +
        '<button type="button" class="btn-link text-sm" onclick="openRiderSearch(' + idx + ', \'\')" title="Byt åkare">' +
        '<i data-lucide="pencil" style="width:14px;height:14px;"></i></button></span>';

    // Update match badge
    const row = document.getElementById('row-' + idx);
    if (row) {
        const matchCell = row.querySelectorAll('td');
        const matchTd = matchCell[matchCell.length - 2]; // second to last
        if (matchTd) matchTd.innerHTML = '<span class="badge badge-success">Manuell</span>';
    }

    // Close search
    closeRiderSearch(idx);

    // Re-init lucide icons
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

document.addEventListener('DOMContentLoaded', function() {
    const classSelect = document.getElementById('classSelect');
    const newClassInput = document.getElementById('newClassInput');
    const autoDetectCheck = document.getElementById('autoDetectCheck');

    if (classSelect && newClassInput) {
        classSelect.addEventListener('change', function() {
            if (this.value) {
                newClassInput.value = '';
                if (autoDetectCheck) autoDetectCheck.checked = false;
            }
        });

        newClassInput.addEventListener('input', function() {
            if (this.value) {
                classSelect.value = '';
                if (autoDetectCheck) autoDetectCheck.checked = false;
            }
        });
    }

    if (autoDetectCheck) {
        autoDetectCheck.addEventListener('change', function() {
            if (this.checked) {
                if (classSelect) classSelect.value = '';
                if (newClassInput) newClassInput.value = '';
            }
        });
    }
});
</script>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>

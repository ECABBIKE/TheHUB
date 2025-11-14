<?php
/**
 * TheHUB Helper Functions - Consolidated
 * All utility functions in ONE place
 */

// ============================================================================
// CORE UTILITY FUNCTIONS
// ============================================================================

/**
 * Sanitize output for HTML
 */
function h($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Format date for display
 */
function formatDate($date, $format = 'Y-m-d') {
    if (empty($date)) return '';
    $dt = new DateTime($date);
    return $dt->format($format);
}

/**
 * Format time for display (HH:MM:SS)
 */
function formatTime($time) {
    if (empty($time)) return '';
    return substr($time, 0, 8); // HH:MM:SS
}

/**
 * Calculate age from birth year
 *
 * @param int $birthYear
 * @param int $referenceYear Optional reference year (defaults to current year)
 * @return int Age in years
 */
function calculateAge($birthYear, $referenceYear = null) {
    if (empty($birthYear) || $birthYear < 1900 || $birthYear > date('Y')) {
        return 0;
    }
    $year = $referenceYear ?? date('Y');
    return $year - $birthYear;
}

/**
 * Calculate time difference in seconds
 */
function timeDiff($time1, $time2) {
    if (empty($time1) || empty($time2)) return 0;

    $t1 = strtotime($time1);
    $t2 = strtotime($time2);

    return abs($t2 - $t1);
}

/**
 * Format time difference
 */
function formatTimeDiff($seconds) {
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    $secs = $seconds % 60;

    if ($hours > 0) {
        return sprintf("%02d:%02d:%02d", $hours, $minutes, $secs);
    } else {
        return sprintf("%02d:%02d", $minutes, $secs);
    }
}

/**
 * Redirect to URL (with safety check)
 */
function redirect($url) {
    // Validate URL to prevent open redirect
    // Only allow relative URLs or same-host URLs
    if (strpos($url, 'http://') === 0 || strpos($url, 'https://') === 0) {
        // External URL - validate against current host
        $parsed = parse_url($url);
        $currentHost = $_SERVER['HTTP_HOST'] ?? '';
        if ($parsed['host'] !== $currentHost) {
            // Don't redirect to external sites
            $url = '/';
        }
    } elseif (strpos($url, '/') !== 0) {
        // Ensure URL starts with / for relative paths
        $url = '/' . $url;
    }

    header("Location: " . $url);
    exit;
}

/**
 * Flash message system
 */
function setFlash($message, $type = 'success') {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['flash_message'] = $message;
    $_SESSION['flash_type'] = $type;
}

function getFlash() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (isset($_SESSION['flash_message'])) {
        $message = $_SESSION['flash_message'];
        $type = $_SESSION['flash_type'] ?? 'success';

        unset($_SESSION['flash_message']);
        unset($_SESSION['flash_type']);

        return ['message' => $message, 'type' => $type];
    }

    return null;
}

/**
 * Generate pagination
 */
function paginate($total, $perPage, $currentPage) {
    $totalPages = ceil($total / $perPage);
    $currentPage = max(1, min($currentPage, $totalPages));

    return [
        'total' => $total,
        'per_page' => $perPage,
        'current_page' => $currentPage,
        'total_pages' => $totalPages,
        'offset' => ($currentPage - 1) * $perPage,
        'has_prev' => $currentPage > 1,
        'has_next' => $currentPage < $totalPages
    ];
}

/**
 * Validate email
 */
function isValidEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Generate random string
 */
function generateRandomString($length = 10) {
    return bin2hex(random_bytes($length / 2));
}

/**
 * Check if uploaded file is valid
 */
function validateUpload($file, $allowedExtensions = null) {
    if (!isset($file) || $file['error'] !== UPLOAD_ERR_OK) {
        return ['valid' => false, 'error' => 'Upload failed'];
    }

    if ($file['size'] > MAX_UPLOAD_SIZE) {
        return ['valid' => false, 'error' => 'File too large'];
    }

    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed = $allowedExtensions ?? ALLOWED_EXTENSIONS;

    if (!in_array($extension, $allowed)) {
        return ['valid' => false, 'error' => 'Invalid file type'];
    }

    return ['valid' => true, 'extension' => $extension];
}

/**
 * Get current year for category calculations
 */
function getCurrentYear() {
    return date('Y');
}

/**
 * Determine category based on age and gender
 */
function determineCategory($birthYear, $gender, $eventYear = null) {
    $year = $eventYear ?? getCurrentYear();
    $age = $year - $birthYear;

    $db = getDB();

    $sql = "SELECT id, name FROM categories
            WHERE active = 1
            AND (gender = ? OR gender = 'All')
            AND (age_min IS NULL OR age_min <= ?)
            AND (age_max IS NULL OR age_max >= ?)
            ORDER BY age_min DESC
            LIMIT 1";

    return $db->getRow($sql, [$gender, $age, $age]);
}

/**
 * Log import activity
 */
function logImport($type, $filename, $total, $success, $failed, $errors = null, $user = null) {
    $db = getDB();

    $data = [
        'import_type' => $type,
        'filename' => $filename,
        'records_total' => $total,
        'records_success' => $success,
        'records_failed' => $failed,
        'errors' => $errors ? json_encode($errors) : null,
        'imported_by' => $user ?? 'system'
    ];

    return $db->insert('import_logs', $data);
}

// ============================================================================
// SECURITY FUNCTIONS
// ============================================================================

/**
 * Set secure HTTP headers
 */
function setSecurityHeaders() {
    // Prevent clickjacking
    header('X-Frame-Options: DENY');

    // Prevent MIME sniffing
    header('X-Content-Type-Options: nosniff');

    // Enable XSS protection (legacy browsers)
    header('X-XSS-Protection: 1; mode=block');

    // Referrer policy
    header('Referrer-Policy: strict-origin-when-cross-origin');

    // Content Security Policy (basic)
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://unpkg.com; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self' data:");
}

/**
 * Generate CSRF token
 */
function generateCsrfToken() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

/**
 * Get CSRF token
 */
function getCsrfToken() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    return $_SESSION['csrf_token'] ?? generateCsrfToken();
}

/**
 * Validate CSRF token
 */
function validateCsrfToken($token) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!isset($_SESSION['csrf_token'])) {
        return false;
    }

    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * CSRF token input field (for forms)
 */
function csrfField() {
    $token = getCsrfToken();
    return '<input type="hidden" name="csrf_token" value="' . h($token) . '">';
}

/**
 * Check CSRF token from POST request
 */
function checkCsrf() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf_token'] ?? '';
        if (!validateCsrfToken($token)) {
            http_response_code(403);
            die('CSRF token validation failed');
        }
    }
}

// ============================================================================
// RIDER/CYCLIST FUNCTIONS
// ============================================================================

/**
 * Generate unique SWE ID for riders without UCI ID
 * Format: SWE25XXXXX (5 random digits)
 *
 * @param object $db Database connection
 * @return string Generated SWE ID
 */
function generateSweId($db) {
    do {
        $random = str_pad(rand(0, 99999), 5, '0', STR_PAD_LEFT);
        $swe_id = 'SWE25' . $random;

        // Check if exists
        $existing = $db->getRow(
            "SELECT COUNT(*) as count FROM riders WHERE license_number = ?",
            [$swe_id]
        );
        $exists = ($existing && $existing['count'] > 0);
    } while ($exists);

    return $swe_id;
}

/**
 * Assign SWE IDs to riders without license number
 *
 * @param object $db Database connection
 * @return int Number of riders updated
 */
function assignSweIds($db) {
    // Get riders without license number
    $riders = $db->getAll("
        SELECT id FROM riders
        WHERE (license_number IS NULL OR license_number = '' OR license_number = '0')
        AND active = 1
    ");

    $updated = 0;
    foreach ($riders as $cyclist) {
        $swe_id = generateSweId($db);

        $result = $db->update(
            'riders',
            ['license_number' => $swe_id],
            'id = ?',
            [$cyclist['id']]
        );

        if ($result) {
            $updated++;
        }
    }

    return $updated;
}

/**
 * Get cyclist statistics
 *
 * @param object $db Database connection
 * @param int $cyclist_id Cyclist ID
 * @return array Statistics
 */
function getCyclistStats($db, $cyclist_id) {
    $stats = $db->getRow("
        SELECT
            COUNT(*) as total_races,
            COUNT(CASE WHEN position <= 3 THEN 1 END) as podiums,
            COUNT(CASE WHEN position = 1 THEN 1 END) as wins,
            MIN(position) as best_position,
            AVG(position) as avg_position
        FROM results
        WHERE cyclist_id = ?
    ", [$cyclist_id]);

    return $stats ?: [
        'total_races' => 0,
        'podiums' => 0,
        'wins' => 0,
        'best_position' => null,
        'avg_position' => null
    ];
}

/**
 * Get event statistics
 *
 * @param object $db Database connection
 * @param int $event_id Event ID
 * @return array Statistics
 */
function getEventStats($db, $event_id) {
    $stats = $db->getRow("
        SELECT
            COUNT(DISTINCT r.cyclist_id) as total_participants,
            COUNT(DISTINCT c.club_id) as total_clubs,
            COUNT(CASE WHEN r.status = 'DNF' THEN 1 END) as dnf_count,
            COUNT(CASE WHEN r.status = 'DNS' THEN 1 END) as dns_count,
            COUNT(CASE WHEN r.status = 'Finished' OR r.status IS NULL THEN 1 END) as finished_count
        FROM results r
        LEFT JOIN riders c ON r.cyclist_id = c.id
        WHERE r.event_id = ?
    ", [$event_id]);

    return $stats ?: [
        'total_participants' => 0,
        'total_clubs' => 0,
        'dnf_count' => 0,
        'dns_count' => 0,
        'finished_count' => 0
    ];
}

/**
 * Validate and sanitize license number
 *
 * @param string $license License number
 * @return string|null Sanitized license or null
 */
function validateLicenseNumber($license) {
    if (empty($license)) {
        return null;
    }

    $license = trim(strtoupper($license));

    // Check if it's a valid format (SWE or UCI format)
    if (preg_match('/^(SWE|UCI)\d{2,}/', $license)) {
        return $license;
    }

    // If it's just numbers, treat as potential license
    if (is_numeric($license)) {
        return $license;
    }

    return null;
}

/**
 * Generate athlete slug for URLs
 *
 * @param string $firstname First name
 * @param string $lastname Last name
 * @param int $id Cyclist ID
 * @return string URL-safe slug
 */
function generateAthleteSlug($firstname, $lastname, $id) {
    $slug = strtolower($firstname . '-' . $lastname);
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = trim($slug, '-');
    return $slug . '-' . $id;
}

/**
 * Parse Swedish personnummer to birth year
 *
 * Formats supported:
 * - YYYYMMDD-XXXX (19400525-0651)
 * - YYYYMMDDXXXX (194005250651)
 * - YYMMDD-XXXX (400525-0651) - assumes 1900s if >current year, else 2000s
 * - YYMMDDXXXX (4005250651)
 *
 * @param string $personnummer
 * @return int|null Birth year or null if invalid
 */
function parsePersonnummer($personnummer) {
    if (empty($personnummer)) {
        return null;
    }

    // Remove all non-digits except dash
    $cleaned = preg_replace('/[^0-9-]/', '', trim($personnummer));

    // Pattern 1: YYYYMMDD-XXXX or YYYYMMDDXXXX (full format)
    if (preg_match('/^(\d{4})(\d{2})(\d{2})/', $cleaned, $matches)) {
        $year = (int)$matches[1];
        // Validate year is reasonable (1900-2100)
        if ($year >= 1900 && $year <= 2100) {
            return $year;
        }
    }

    // Pattern 2: YYMMDD-XXXX or YYMMDDXXXX (short format)
    if (preg_match('/^(\d{2})(\d{2})(\d{2})/', $cleaned, $matches)) {
        $yy = (int)$matches[1];
        $currentYear = (int)date('Y');
        $currentCentury = floor($currentYear / 100) * 100;

        // If YY > current year's last 2 digits, it's from previous century
        if ($yy > ($currentYear % 100)) {
            return $currentCentury - 100 + $yy;
        } else {
            return $currentCentury + $yy;
        }
    }

    return null;
}

/**
 * Get age category based on birth year
 *
 * @param int $birthYear
 * @param string $gender Optional: 'M', 'F', 'Other'
 * @return string Category name
 */
function getAgeCategory($birthYear, $gender = null) {
    $age = calculateAge($birthYear);

    $genderSuffix = '';
    if ($gender === 'M') {
        $genderSuffix = ' Men';
    } elseif ($gender === 'F') {
        $genderSuffix = ' Women';
    }

    if ($age < 11) return 'Under 11' . $genderSuffix;
    if ($age < 13) return 'U13' . $genderSuffix;
    if ($age < 15) return 'U15' . $genderSuffix;
    if ($age < 17) return 'U17' . $genderSuffix;
    if ($age < 19) return 'U19' . $genderSuffix;
    if ($age < 21) return 'U21' . $genderSuffix;
    if ($age < 23) return 'U23' . $genderSuffix;
    if ($age < 35) return 'Elite' . $genderSuffix;
    if ($age < 45) return 'Master 35+' . $genderSuffix;
    if ($age < 55) return 'Master 45+' . $genderSuffix;
    return 'Master 55+' . $genderSuffix;
}

/**
 * Get license type options
 *
 * @return array
 */
function getLicenseTypeOptions() {
    return [
        'Elite' => 'Elite',
        'Youth' => 'Youth',
        'Hobby' => 'Hobby',
        'Beginner' => 'Beginner',
        'None' => 'Ingen licens'
    ];
}

/**
 * Get license category options
 *
 * @return array
 */
function getLicenseCategoryOptions() {
    return [
        'Base License Men',
        'Base License Women',
        'Elite Men',
        'Elite Women',
        'Master Men 35+',
        'Master Men 45+',
        'Master Men 55+',
        'Master Women 35+',
        'Master Women 45+',
        'Master Women 55+',
        'Youth Men',
        'Youth Women',
        'U23 Men',
        'U23 Women',
        'U21 Men',
        'U21 Women',
        'U19 Men',
        'U19 Women',
        'U17 Men',
        'U17 Women',
        'U15 Men',
        'U15 Women',
        'U13 Men',
        'U13 Women',
        'Under 11 Men',
        'Under 11 Women'
    ];
}

/**
 * Get discipline options
 *
 * @return array
 */
function getDisciplineOptions() {
    return [
        'MTB' => 'MTB (Mountain Bike)',
        'Road' => 'Road (Landsväg)',
        'Track' => 'Track (Bana)',
        'BMX' => 'BMX',
        'CX' => 'Cyclocross',
        'Trial' => 'Trial',
        'Para' => 'Para-cycling',
        'E-cycling' => 'E-cycling',
        'Gravel' => 'Gravel'
    ];
}

/**
 * Check if license is valid
 *
 * @param array $cyclist Cyclist data
 * @return array ['valid' => bool, 'message' => string, 'class' => string]
 */
function checkLicense($cyclist) {
    $result = [
        'valid' => false,
        'message' => 'Ej aktiv licens',
        'class' => 'gs-badge-danger'
    ];

    // Check if license exists
    if (empty($cyclist['license_type']) || $cyclist['license_type'] === 'None') {
        $result['message'] = 'Ej aktiv licens';
        $result['class'] = 'gs-badge-danger';
        return $result;
    }

    // Check for single-use license
    if ($cyclist['license_type'] === 'Engångslicens') {
        $result['valid'] = true;
        $result['message'] = 'Engångslicens';
        $result['class'] = 'gs-badge-warning';
        return $result;
    }

    // Check expiry date
    if (!empty($cyclist['license_valid_until'])) {
        $validUntil = trim($cyclist['license_valid_until']);

        // Skip invalid dates (0000-00-00, 0000, etc.)
        if ($validUntil === '0000-00-00' || $validUntil === '0000' || $validUntil === '0') {
            // Has license type but invalid date - treat as active
            $result['valid'] = true;
            $result['message'] = 'Aktiv licens';
            $result['class'] = 'gs-badge-success';
            return $result;
        }

        // If only year is provided (e.g., "2025"), treat as end of year (Dec 31)
        if (preg_match('/^\d{4}$/', $validUntil)) {
            $validUntil = $validUntil . '-12-31';
        }

        $expiryDate = strtotime($validUntil);
        $today = strtotime('today');

        // Check if date parsing failed or date is invalid
        if ($expiryDate === false || $expiryDate < 0) {
            // Invalid date but has license type - treat as active
            $result['valid'] = true;
            $result['message'] = 'Aktiv licens';
            $result['class'] = 'gs-badge-success';
            return $result;
        }

        if ($expiryDate < $today) {
            $result['message'] = 'Ej aktiv licens';
            $result['class'] = 'gs-badge-danger';
            return $result;
        }

        // License is valid
        $result['valid'] = true;
        $result['message'] = 'Aktiv licens';
        $result['class'] = 'gs-badge-success';
        return $result;
    }

    // No expiry date but has license type - consider valid
    $result['valid'] = true;
    $result['message'] = 'Aktiv licens';
    $result['class'] = 'gs-badge-success';
    return $result;
}

/**
 * Suggest license category based on age and gender
 *
 * @param int $birthYear
 * @param string $gender 'M', 'F', 'Other'
 * @return string Suggested license category
 */
function suggestLicenseCategory($birthYear, $gender) {
    $age = calculateAge($birthYear);

    $suffix = '';
    if ($gender === 'M') {
        $suffix = ' Men';
    } elseif ($gender === 'F') {
        $suffix = ' Women';
    }

    // Youth categories
    if ($age < 11) return 'Under 11' . $suffix;
    if ($age < 13) return 'U13' . $suffix;
    if ($age < 15) return 'U15' . $suffix;
    if ($age < 17) return 'U17' . $suffix;
    if ($age < 19) return 'Youth' . $suffix;

    // Adult categories
    if ($age < 21) return 'U21' . $suffix;
    if ($age < 23) return 'U23' . $suffix;
    if ($age < 35) return 'Elite' . $suffix;

    // Master categories
    if ($age < 45) return 'Master' . $suffix . ' 35+';
    if ($age < 55) return 'Master' . $suffix . ' 45+';
    return 'Master' . $suffix . ' 55+';
}

// ============================================================================
// CSV PARSING FUNCTIONS
// ============================================================================

/**
 * Detect CSV separator by testing which gives most columns
 *
 * @param string $file_path Path to CSV file
 * @return string Detected separator
 */
function detectCsvSeparator($file_path) {
    $handle = fopen($file_path, 'r');
    if (!$handle) return ',';

    $first_line = fgets($handle);
    fclose($handle);

    if (empty($first_line)) return ',';

    $separators = [',', ';', "\t", '|'];
    $max_count = 0;
    $best_separator = ',';

    foreach ($separators as $sep) {
        $row = str_getcsv($first_line, $sep);
        $count = count($row);

        if ($count > $max_count) {
            $max_count = $count;
            $best_separator = $sep;
        }
    }

    return $best_separator;
}

/**
 * Convert file to UTF-8 if needed
 *
 * @param string $file_path Path to file
 * @return string Original encoding detected
 */
function convertFileEncoding($file_path) {
    $content = file_get_contents($file_path);
    $encoding = mb_detect_encoding($content, ['UTF-8', 'ISO-8859-1', 'Windows-1252', 'UTF-16'], true);

    if ($encoding && $encoding !== 'UTF-8') {
        $content = mb_convert_encoding($content, 'UTF-8', $encoding);
        file_put_contents($file_path, $content);
        return $encoding;
    }

    return 'UTF-8';
}

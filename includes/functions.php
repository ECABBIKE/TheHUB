<?php
/**
 * Common utility functions
 */

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
 */
function calculateAge($birthYear, $referenceYear = null) {
    if (empty($birthYear)) return null;
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
 * Redirect to URL
 */
function redirect($url) {
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

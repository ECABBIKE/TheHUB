<?php
/**
 * Helper functions for TheHUB
 */

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
 * Calculate age from birth year
 *
 * @param int $birthYear
 * @return int Age in years
 */
function calculateAge($birthYear) {
    if (empty($birthYear) || $birthYear < 1900 || $birthYear > date('Y')) {
        return 0;
    }
    return (int)date('Y') - $birthYear;
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
        'message' => '',
        'class' => 'gs-badge-danger'
    ];

    // Check if license exists
    if (empty($cyclist['license_type']) || $cyclist['license_type'] === 'None') {
        $result['message'] = 'Ingen licens';
        $result['class'] = 'gs-badge-secondary';
        return $result;
    }

    // Check expiry date
    if (!empty($cyclist['license_valid_until'])) {
        $expiryDate = strtotime($cyclist['license_valid_until']);
        $today = strtotime('today');

        if ($expiryDate < $today) {
            $result['message'] = 'Utgången: ' . date('Y-m-d', $expiryDate);
            $result['class'] = 'gs-badge-danger';
            return $result;
        }

        // Warning if expires within 30 days
        $daysUntilExpiry = floor(($expiryDate - $today) / 86400);
        if ($daysUntilExpiry <= 30) {
            $result['valid'] = true;
            $result['message'] = 'Går ut om ' . $daysUntilExpiry . ' dagar';
            $result['class'] = 'gs-badge-warning';
            return $result;
        }
    }

    // Check license category
    if (empty($cyclist['license_category'])) {
        $result['message'] = 'Kategori saknas';
        $result['class'] = 'gs-badge-warning';
        return $result;
    }

    // All checks passed
    $result['valid'] = true;
    $result['message'] = 'Giltig licens';
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

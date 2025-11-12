<?php
/**
 * Helper functions for TheHUB
 */

/**
 * Generate unique SWE ID for cyclists without UCI ID
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
            "SELECT COUNT(*) as count FROM cyclists WHERE license_number = ?",
            [$swe_id]
        );
        $exists = ($existing && $existing['count'] > 0);
    } while ($exists);

    return $swe_id;
}

/**
 * Assign SWE IDs to cyclists without license number
 *
 * @param object $db Database connection
 * @return int Number of cyclists updated
 */
function assignSweIds($db) {
    // Get cyclists without license number
    $cyclists = $db->getAll("
        SELECT id FROM cyclists
        WHERE (license_number IS NULL OR license_number = '' OR license_number = '0')
        AND active = 1
    ");

    $updated = 0;
    foreach ($cyclists as $cyclist) {
        $swe_id = generateSweId($db);

        $result = $db->update(
            'cyclists',
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
        LEFT JOIN cyclists c ON r.cyclist_id = c.id
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

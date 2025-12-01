<?php
/**
 * Sponsor Management Helper Functions
 * TheHUB V3 - Media & Sponsor System
 *
 * Functions for managing sponsors, packages, and placements.
 */

// Ensure config is loaded
if (!defined('HUB_V3_ROOT')) {
    define('HUB_V3_ROOT', dirname(__DIR__));
}

// Load media functions for logo handling
require_once __DIR__ . '/media-functions.php';

/**
 * Get sponsor by ID
 *
 * @param int $sponsorId Sponsor ID
 * @return array|null Sponsor data or null
 */
function get_sponsor(int $sponsorId): ?array {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT s.*,
               m.filepath as logo_path,
               m.filename as logo_filename
        FROM sponsors s
        LEFT JOIN media m ON s.logo_media_id = m.id
        WHERE s.id = ?
    ");
    $stmt->execute([$sponsorId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($result) {
        $result['logo_url'] = $result['logo_media_id'] ? get_media_url($result['logo_media_id']) : null;
    }

    return $result ?: null;
}

/**
 * Get sponsor by slug
 *
 * @param string $slug URL slug
 * @return array|null Sponsor data or null
 */
function get_sponsor_by_slug(string $slug): ?array {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT s.*,
               m.filepath as logo_path,
               m.filename as logo_filename
        FROM sponsors s
        LEFT JOIN media m ON s.logo_media_id = m.id
        WHERE s.slug = ?
    ");
    $stmt->execute([$slug]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($result) {
        $result['logo_url'] = $result['logo_media_id'] ? get_media_url($result['logo_media_id']) : null;
    }

    return $result ?: null;
}

/**
 * Get all sponsors
 *
 * @param bool $activeOnly Only return active sponsors
 * @param string|null $tier Filter by tier
 * @return array Sponsors list
 */
function get_sponsors(bool $activeOnly = true, ?string $tier = null): array {
    $db = getDB();

    $sql = "
        SELECT s.*,
               m.filepath as logo_path
        FROM sponsors s
        LEFT JOIN media m ON s.logo_media_id = m.id
        WHERE 1=1
    ";
    $params = [];

    if ($activeOnly) {
        $sql .= " AND s.active = 1";
    }

    if ($tier) {
        $sql .= " AND s.tier = ?";
        $params[] = $tier;
    }

    $sql .= " ORDER BY
        FIELD(s.tier, 'title', 'gold', 'silver', 'bronze'),
        s.display_order ASC,
        s.name ASC
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $sponsors = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Add logo URLs
    foreach ($sponsors as &$sponsor) {
        $sponsor['logo_url'] = $sponsor['logo_media_id'] ? get_media_url($sponsor['logo_media_id']) : null;
    }

    return $sponsors;
}

/**
 * Create a new sponsor
 *
 * @param array $data Sponsor data
 * @return array ['success' => bool, 'sponsor_id' => int|null, 'error' => string|null]
 */
function create_sponsor(array $data): array {
    $db = getDB();

    // Generate slug if not provided
    if (empty($data['slug'])) {
        $data['slug'] = generate_sponsor_slug($data['name']);
    }

    // Check for duplicate slug
    $stmt = $db->prepare("SELECT id FROM sponsors WHERE slug = ?");
    $stmt->execute([$data['slug']]);
    if ($stmt->fetch()) {
        return ['success' => false, 'sponsor_id' => null, 'error' => 'En sponsor med denna URL redan finns'];
    }

    $stmt = $db->prepare("
        INSERT INTO sponsors (name, slug, logo_media_id, website, tier, description,
                              contact_name, contact_email, contact_phone, active, display_order)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $data['name'],
        $data['slug'],
        $data['logo_media_id'] ?? null,
        $data['website'] ?? null,
        $data['tier'] ?? 'bronze',
        $data['description'] ?? null,
        $data['contact_name'] ?? null,
        $data['contact_email'] ?? null,
        $data['contact_phone'] ?? null,
        $data['active'] ?? true,
        $data['display_order'] ?? 0
    ]);

    $sponsorId = (int) $db->lastInsertId();

    // Track logo usage
    if (!empty($data['logo_media_id'])) {
        track_media_usage($data['logo_media_id'], 'sponsor', $sponsorId, 'logo');
    }

    return ['success' => true, 'sponsor_id' => $sponsorId, 'error' => null];
}

/**
 * Update a sponsor
 *
 * @param int $sponsorId Sponsor ID
 * @param array $data Data to update
 * @return bool Success
 */
function update_sponsor(int $sponsorId, array $data): bool {
    $db = getDB();

    // Get current sponsor for logo tracking
    $current = get_sponsor($sponsorId);
    if (!$current) {
        return false;
    }

    // Build update query
    $fields = ['name', 'slug', 'logo_media_id', 'website', 'tier', 'description',
               'contact_name', 'contact_email', 'contact_phone', 'active', 'display_order'];

    $updates = [];
    $values = [];

    foreach ($fields as $field) {
        if (array_key_exists($field, $data)) {
            $updates[] = "$field = ?";
            $values[] = $data[$field];
        }
    }

    if (empty($updates)) {
        return false;
    }

    $values[] = $sponsorId;
    $sql = "UPDATE sponsors SET " . implode(', ', $updates) . " WHERE id = ?";
    $stmt = $db->prepare($sql);
    $result = $stmt->execute($values);

    // Update logo usage tracking
    if (isset($data['logo_media_id']) && $data['logo_media_id'] !== $current['logo_media_id']) {
        // Remove old tracking
        if ($current['logo_media_id']) {
            remove_media_usage('sponsor', $sponsorId, 'logo');
        }
        // Add new tracking
        if ($data['logo_media_id']) {
            track_media_usage($data['logo_media_id'], 'sponsor', $sponsorId, 'logo');
        }
    }

    return $result;
}

/**
 * Delete a sponsor
 *
 * @param int $sponsorId Sponsor ID
 * @return bool Success
 */
function delete_sponsor(int $sponsorId): bool {
    $db = getDB();

    // Remove media usage tracking
    remove_media_usage('sponsor', $sponsorId);

    // Delete sponsor (CASCADE will handle related records)
    $stmt = $db->prepare("DELETE FROM sponsors WHERE id = ?");
    return $stmt->execute([$sponsorId]);
}

/**
 * Generate URL-friendly slug from name
 *
 * @param string $name Sponsor name
 * @return string Slug
 */
function generate_sponsor_slug(string $name): string {
    $slug = strtolower($name);
    $slug = preg_replace('/[åä]/u', 'a', $slug);
    $slug = preg_replace('/[ö]/u', 'o', $slug);
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = trim($slug, '-');
    return $slug;
}

/**
 * Get sponsors for a series
 *
 * @param int $seriesId Series ID
 * @param string|null $placement Filter by placement
 * @return array Sponsors
 */
function get_series_sponsors(int $seriesId, ?string $placement = null): array {
    $db = getDB();

    $sql = "
        SELECT s.*, ss.placement, ss.display_order as placement_order,
               m.filepath as logo_path
        FROM series_sponsors ss
        JOIN sponsors s ON ss.sponsor_id = s.id
        LEFT JOIN media m ON s.logo_media_id = m.id
        WHERE ss.series_id = ? AND s.active = 1
    ";
    $params = [$seriesId];

    if ($placement) {
        $sql .= " AND ss.placement = ?";
        $params[] = $placement;
    }

    // Check date validity
    $sql .= " AND (ss.start_date IS NULL OR ss.start_date <= CURDATE())";
    $sql .= " AND (ss.end_date IS NULL OR ss.end_date >= CURDATE())";

    $sql .= " ORDER BY ss.display_order ASC, s.name ASC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $sponsors = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Add logo URLs
    foreach ($sponsors as &$sponsor) {
        $sponsor['logo_url'] = $sponsor['logo_media_id'] ? get_media_url($sponsor['logo_media_id']) : null;
    }

    return $sponsors;
}

/**
 * Get sponsors for an event
 *
 * @param int $eventId Event ID
 * @param string|null $placement Filter by placement
 * @return array Sponsors
 */
function get_event_sponsors(int $eventId, ?string $placement = null): array {
    $db = getDB();

    $sql = "
        SELECT s.*, es.placement, es.display_order as placement_order,
               m.filepath as logo_path
        FROM event_sponsors es
        JOIN sponsors s ON es.sponsor_id = s.id
        LEFT JOIN media m ON s.logo_media_id = m.id
        WHERE es.event_id = ? AND s.active = 1
    ";
    $params = [$eventId];

    if ($placement) {
        $sql .= " AND es.placement = ?";
        $params[] = $placement;
    }

    $sql .= " ORDER BY es.display_order ASC, s.name ASC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $sponsors = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Add logo URLs
    foreach ($sponsors as &$sponsor) {
        $sponsor['logo_url'] = $sponsor['logo_media_id'] ? get_media_url($sponsor['logo_media_id']) : null;
    }

    return $sponsors;
}

/**
 * Assign sponsor to series
 *
 * @param int $seriesId Series ID
 * @param int $sponsorId Sponsor ID
 * @param string $placement Placement position
 * @param int $displayOrder Sort order
 * @param string|null $startDate Start date
 * @param string|null $endDate End date
 * @return bool Success
 */
function assign_sponsor_to_series(int $seriesId, int $sponsorId, string $placement = 'sidebar',
                                   int $displayOrder = 0, ?string $startDate = null, ?string $endDate = null): bool {
    $db = getDB();

    // Use INSERT ... ON DUPLICATE KEY UPDATE
    $stmt = $db->prepare("
        INSERT INTO series_sponsors (series_id, sponsor_id, placement, display_order, start_date, end_date)
        VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE display_order = VALUES(display_order),
                                start_date = VALUES(start_date),
                                end_date = VALUES(end_date)
    ");

    return $stmt->execute([$seriesId, $sponsorId, $placement, $displayOrder, $startDate, $endDate]);
}

/**
 * Remove sponsor from series
 *
 * @param int $seriesId Series ID
 * @param int $sponsorId Sponsor ID
 * @param string|null $placement Specific placement or null for all
 * @return bool Success
 */
function remove_sponsor_from_series(int $seriesId, int $sponsorId, ?string $placement = null): bool {
    $db = getDB();

    if ($placement) {
        $stmt = $db->prepare("DELETE FROM series_sponsors WHERE series_id = ? AND sponsor_id = ? AND placement = ?");
        return $stmt->execute([$seriesId, $sponsorId, $placement]);
    } else {
        $stmt = $db->prepare("DELETE FROM series_sponsors WHERE series_id = ? AND sponsor_id = ?");
        return $stmt->execute([$seriesId, $sponsorId]);
    }
}

/**
 * Assign sponsor to event
 *
 * @param int $eventId Event ID
 * @param int $sponsorId Sponsor ID
 * @param string $placement Placement position
 * @param int $displayOrder Sort order
 * @return bool Success
 */
function assign_sponsor_to_event(int $eventId, int $sponsorId, string $placement = 'sidebar', int $displayOrder = 0): bool {
    $db = getDB();

    $stmt = $db->prepare("
        INSERT INTO event_sponsors (event_id, sponsor_id, placement, display_order)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE display_order = VALUES(display_order)
    ");

    return $stmt->execute([$eventId, $sponsorId, $placement, $displayOrder]);
}

/**
 * Remove sponsor from event
 *
 * @param int $eventId Event ID
 * @param int $sponsorId Sponsor ID
 * @param string|null $placement Specific placement or null for all
 * @return bool Success
 */
function remove_sponsor_from_event(int $eventId, int $sponsorId, ?string $placement = null): bool {
    $db = getDB();

    if ($placement) {
        $stmt = $db->prepare("DELETE FROM event_sponsors WHERE event_id = ? AND sponsor_id = ? AND placement = ?");
        return $stmt->execute([$eventId, $sponsorId, $placement]);
    } else {
        $stmt = $db->prepare("DELETE FROM event_sponsors WHERE event_id = ? AND sponsor_id = ?");
        return $stmt->execute([$eventId, $sponsorId]);
    }
}

/**
 * Get sponsor packages
 *
 * @param int $sponsorId Sponsor ID
 * @param bool $activeOnly Only active packages
 * @return array Packages
 */
function get_sponsor_packages(int $sponsorId, bool $activeOnly = true): array {
    $db = getDB();

    $sql = "SELECT * FROM sponsor_packages WHERE sponsor_id = ?";
    $params = [$sponsorId];

    if ($activeOnly) {
        $sql .= " AND active = 1";
    }

    $sql .= " ORDER BY start_date DESC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Create sponsor package
 *
 * @param int $sponsorId Sponsor ID
 * @param array $data Package data
 * @return int|false Package ID or false
 */
function create_sponsor_package(int $sponsorId, array $data): int|false {
    $db = getDB();

    $stmt = $db->prepare("
        INSERT INTO sponsor_packages (sponsor_id, package_type, season, price, start_date, end_date, benefits, notes, active)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $result = $stmt->execute([
        $sponsorId,
        $data['package_type'],
        $data['season'] ?? null,
        $data['price'] ?? null,
        $data['start_date'] ?? null,
        $data['end_date'] ?? null,
        $data['benefits'] ?? null,
        $data['notes'] ?? null,
        $data['active'] ?? true
    ]);

    return $result ? (int) $db->lastInsertId() : false;
}

/**
 * Get sponsor statistics
 *
 * @return array Stats by tier
 */
function get_sponsor_stats(): array {
    $db = getDB();

    $stmt = $db->query("
        SELECT
            tier,
            COUNT(*) as count,
            SUM(CASE WHEN active = 1 THEN 1 ELSE 0 END) as active_count
        FROM sponsors
        GROUP BY tier
        ORDER BY FIELD(tier, 'title', 'gold', 'silver', 'bronze')
    ");

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get tier display name
 *
 * @param string $tier Tier code
 * @return string Display name
 */
function get_tier_name(string $tier): string {
    $names = [
        'title' => 'Titelsponsor',
        'gold' => 'Guldsponsor',
        'silver' => 'Silversponsor',
        'bronze' => 'Bronssponsor'
    ];
    return $names[$tier] ?? ucfirst($tier);
}

/**
 * Get tier badge color
 *
 * @param string $tier Tier code
 * @return string CSS color
 */
function get_tier_color(string $tier): string {
    $colors = [
        'title' => '#8B5CF6',    // Purple
        'gold' => '#F59E0B',     // Gold
        'silver' => '#9CA3AF',   // Silver
        'bronze' => '#D97706'    // Bronze
    ];
    return $colors[$tier] ?? '#6B7280';
}

/**
 * Search sponsors
 *
 * @param string $query Search query
 * @param int $limit Max results
 * @return array Sponsors
 */
function search_sponsors(string $query, int $limit = 20): array {
    $db = getDB();
    $searchTerm = '%' . $query . '%';

    $stmt = $db->prepare("
        SELECT s.*, m.filepath as logo_path
        FROM sponsors s
        LEFT JOIN media m ON s.logo_media_id = m.id
        WHERE s.name LIKE ? OR s.description LIKE ? OR s.contact_name LIKE ?
        ORDER BY s.active DESC, s.name ASC
        LIMIT ?
    ");

    $stmt->execute([$searchTerm, $searchTerm, $searchTerm, $limit]);
    $sponsors = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($sponsors as &$sponsor) {
        $sponsor['logo_url'] = $sponsor['logo_media_id'] ? get_media_url($sponsor['logo_media_id']) : null;
    }

    return $sponsors;
}

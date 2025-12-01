<?php
/**
 * Sponsor Management Functions
 * TheHUB V3 - Media & Sponsor System
 */

/**
 * Get all sponsors with optional filters
 */
function get_sponsors($activeOnly = true, $tier = null) {
    global $pdo;
    
    try {
        $sql = "SELECT s.*, m.filepath as logo_url 
                FROM sponsors s 
                LEFT JOIN media m ON s.logo_media_id = m.id";
        $conditions = [];
        $params = [];
        
        if ($activeOnly) {
            $conditions[] = "s.active = 1";
        }
        
        if ($tier) {
            $conditions[] = "s.tier = ?";
            $params[] = $tier;
        }
        
        if (!empty($conditions)) {
            $sql .= " WHERE " . implode(' AND ', $conditions);
        }
        
        $sql .= " ORDER BY s.display_order ASC, s.name ASC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        $sponsors = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Format logo URLs
        foreach ($sponsors as &$sponsor) {
            if ($sponsor['logo_url']) {
                $sponsor['logo_url'] = '/' . ltrim($sponsor['logo_url'], '/');
            }
        }
        
        return $sponsors;
    } catch (PDOException $e) {
        error_log("get_sponsors error: " . $e->getMessage());
        return [];
    }
}

/**
 * Search sponsors
 */
function search_sponsors($query, $limit = 50) {
    global $pdo;
    
    try {
        $searchTerm = "%{$query}%";
        $stmt = $pdo->prepare("
            SELECT s.*, m.filepath as logo_url 
            FROM sponsors s 
            LEFT JOIN media m ON s.logo_media_id = m.id
            WHERE s.name LIKE ? OR s.description LIKE ? OR s.contact_name LIKE ?
            ORDER BY s.display_order ASC, s.name ASC
            LIMIT ?
        ");
        $stmt->execute([$searchTerm, $searchTerm, $searchTerm, (int)$limit]);
        
        $sponsors = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Format logo URLs
        foreach ($sponsors as &$sponsor) {
            if ($sponsor['logo_url']) {
                $sponsor['logo_url'] = '/' . ltrim($sponsor['logo_url'], '/');
            }
        }
        
        return $sponsors;
    } catch (PDOException $e) {
        error_log("search_sponsors error: " . $e->getMessage());
        return [];
    }
}

/**
 * Get single sponsor by ID
 */
function get_sponsor($id) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            SELECT s.*, m.filepath as logo_url 
            FROM sponsors s 
            LEFT JOIN media m ON s.logo_media_id = m.id
            WHERE s.id = ?
        ");
        $stmt->execute([$id]);
        $sponsor = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($sponsor && $sponsor['logo_url']) {
            $sponsor['logo_url'] = '/' . ltrim($sponsor['logo_url'], '/');
        }
        
        return $sponsor;
    } catch (PDOException $e) {
        error_log("get_sponsor error: " . $e->getMessage());
        return null;
    }
}

/**
 * Get sponsor statistics by tier
 */
function get_sponsor_stats() {
    global $pdo;
    
    try {
        $stmt = $pdo->query("
            SELECT 
                tier,
                COUNT(*) as count,
                SUM(CASE WHEN active = 1 THEN 1 ELSE 0 END) as active_count
            FROM sponsors
            GROUP BY tier
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("get_sponsor_stats error: " . $e->getMessage());
        return [];
    }
}

/**
 * Create new sponsor
 */
function create_sponsor($data) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO sponsors (name, tier, website, description, logo_media_id, contact_name, contact_email, contact_phone, display_order, active, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $data['name'] ?? '',
            $data['tier'] ?? 'bronze',
            $data['website'] ?? null,
            $data['description'] ?? null,
            $data['logo_media_id'] ?? null,
            $data['contact_name'] ?? null,
            $data['contact_email'] ?? null,
            $data['contact_phone'] ?? null,
            $data['display_order'] ?? 0,
            isset($data['active']) ? ($data['active'] ? 1 : 0) : 1
        ]);
        
        return [
            'success' => true,
            'id' => $pdo->lastInsertId()
        ];
    } catch (PDOException $e) {
        error_log("create_sponsor error: " . $e->getMessage());
        return ['success' => false, 'error' => 'Kunde inte skapa sponsor'];
    }
}

/**
 * Update sponsor
 */
function update_sponsor($id, $data) {
    global $pdo;
    
    try {
        $allowedFields = ['name', 'tier', 'website', 'description', 'logo_media_id', 'contact_name', 'contact_email', 'contact_phone', 'display_order', 'active'];
        $updates = [];
        $params = [];
        
        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $updates[] = "{$field} = ?";
                
                // Handle boolean for active
                if ($field === 'active') {
                    $params[] = $data[$field] ? 1 : 0;
                } else {
                    $params[] = $data[$field];
                }
            }
        }
        
        if (empty($updates)) {
            return ['success' => false, 'error' => 'Inget att uppdatera'];
        }
        
        $updates[] = "updated_at = NOW()";
        $params[] = $id;
        
        $sql = "UPDATE sponsors SET " . implode(', ', $updates) . " WHERE id = ?";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        return ['success' => true];
    } catch (PDOException $e) {
        error_log("update_sponsor error: " . $e->getMessage());
        return ['success' => false, 'error' => 'Kunde inte uppdatera sponsor'];
    }
}

/**
 * Delete sponsor
 */
function delete_sponsor($id) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("DELETE FROM sponsors WHERE id = ?");
        $stmt->execute([$id]);
        
        return ['success' => true];
    } catch (PDOException $e) {
        error_log("delete_sponsor error: " . $e->getMessage());
        return ['success' => false, 'error' => 'Kunde inte radera sponsor'];
    }
}

/**
 * Get sponsors by tier
 */
function get_sponsors_by_tier($tier) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            SELECT s.*, m.filepath as logo_url 
            FROM sponsors s 
            LEFT JOIN media m ON s.logo_media_id = m.id
            WHERE s.tier = ? AND s.active = 1
            ORDER BY s.display_order ASC, s.name ASC
        ");
        $stmt->execute([$tier]);
        
        $sponsors = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($sponsors as &$sponsor) {
            if ($sponsor['logo_url']) {
                $sponsor['logo_url'] = '/' . ltrim($sponsor['logo_url'], '/');
            }
        }
        
        return $sponsors;
    } catch (PDOException $e) {
        error_log("get_sponsors_by_tier error: " . $e->getMessage());
        return [];
    }
}

/**
 * Get sponsors for a specific series
 */
function get_series_sponsors($seriesId) {
    global $pdo;
    
    try {
        // Check if sponsor_series table exists
        $stmt = $pdo->prepare("
            SELECT s.*, m.filepath as logo_url, ss.display_order as series_display_order
            FROM sponsors s
            INNER JOIN sponsor_series ss ON s.id = ss.sponsor_id
            LEFT JOIN media m ON s.logo_media_id = m.id
            WHERE ss.series_id = ? AND s.active = 1
            ORDER BY ss.display_order ASC, s.display_order ASC
        ");
        $stmt->execute([$seriesId]);
        
        $sponsors = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($sponsors as &$sponsor) {
            if ($sponsor['logo_url']) {
                $sponsor['logo_url'] = '/' . ltrim($sponsor['logo_url'], '/');
            }
        }
        
        return $sponsors;
    } catch (PDOException $e) {
        // Table might not exist, return empty
        error_log("get_series_sponsors error: " . $e->getMessage());
        return [];
    }
}

/**
 * Assign sponsor to series
 */
function assign_sponsor_to_series($sponsorId, $seriesId, $displayOrder = 0) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO sponsor_series (sponsor_id, series_id, display_order)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE display_order = ?
        ");
        $stmt->execute([$sponsorId, $seriesId, $displayOrder, $displayOrder]);
        
        return ['success' => true];
    } catch (PDOException $e) {
        error_log("assign_sponsor_to_series error: " . $e->getMessage());
        return ['success' => false, 'error' => 'Kunde inte tilldela sponsor'];
    }
}

/**
 * Remove sponsor from series
 */
function remove_sponsor_from_series($sponsorId, $seriesId) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("DELETE FROM sponsor_series WHERE sponsor_id = ? AND series_id = ?");
        $stmt->execute([$sponsorId, $seriesId]);
        
        return ['success' => true];
    } catch (PDOException $e) {
        error_log("remove_sponsor_from_series error: " . $e->getMessage());
        return ['success' => false, 'error' => 'Kunde inte ta bort sponsor'];
    }
}

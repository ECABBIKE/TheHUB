<?php
/**
 * Media Library Functions
 * TheHUB V3 - Media & Sponsor System
 */

/**
 * Get media statistics by folder
 */
function get_media_stats() {
    global $pdo;
    
    try {
        $stmt = $pdo->query("
            SELECT 
                folder,
                COUNT(*) as count,
                SUM(size) as total_size
            FROM media
            GROUP BY folder
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("get_media_stats error: " . $e->getMessage());
        return [];
    }
}

/**
 * Get media files by folder
 * Supports subfolders with LIKE matching (e.g., "sponsors" matches "sponsors/mysponsor")
 */
function get_media_by_folder($folder = null, $limit = 50, $offset = 0, $includeSubfolders = false) {
    global $pdo;

    try {
        $sql = "SELECT * FROM media";
        $params = [];

        if ($folder) {
            if ($includeSubfolders) {
                // Match folder and all subfolders
                $sql .= " WHERE (folder = ? OR folder LIKE ?)";
                $params[] = $folder;
                $params[] = $folder . '/%';
            } else {
                $sql .= " WHERE folder = ?";
                $params[] = $folder;
            }
        }

        $sql .= " ORDER BY uploaded_at DESC LIMIT ? OFFSET ?";
        $params[] = (int)$limit;
        $params[] = (int)$offset;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("get_media_by_folder error: " . $e->getMessage());
        return [];
    }
}

/**
 * Get subfolders within a parent folder
 */
function get_media_subfolders($parentFolder) {
    global $pdo;

    try {
        $stmt = $pdo->prepare("
            SELECT
                folder,
                COUNT(*) as count,
                SUM(size) as total_size
            FROM media
            WHERE folder LIKE ?
            GROUP BY folder
            ORDER BY folder
        ");
        $stmt->execute([$parentFolder . '/%']);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Extract subfolder names
        $subfolders = [];
        foreach ($results as $row) {
            // Get the subfolder name (part after parent/)
            $subPath = substr($row['folder'], strlen($parentFolder) + 1);
            // Only include direct subfolders (not nested)
            if (strpos($subPath, '/') === false) {
                $subfolders[] = [
                    'name' => $subPath,
                    'path' => $row['folder'],
                    'count' => (int)$row['count'],
                    'size' => (int)$row['total_size']
                ];
            }
        }

        return $subfolders;
    } catch (PDOException $e) {
        error_log("get_media_subfolders error: " . $e->getMessage());
        return [];
    }
}

/**
 * Create a media subfolder for a sponsor
 * Returns the folder path
 */
function create_sponsor_media_folder($sponsorName) {
    // Generate slug from sponsor name
    $slug = strtolower(trim($sponsorName));
    $slug = preg_replace('/[åä]/u', 'a', $slug);
    $slug = preg_replace('/[ö]/u', 'o', $slug);
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = trim($slug, '-');

    return 'sponsors/' . $slug;
}

/**
 * Search media files
 * Supports searching in subfolders with LIKE matching
 */
function search_media($query, $folder = null, $limit = 50, $includeSubfolders = true) {
    global $pdo;

    try {
        $sql = "SELECT * FROM media WHERE (original_filename LIKE ? OR alt_text LIKE ? OR caption LIKE ?)";
        $searchTerm = "%{$query}%";
        $params = [$searchTerm, $searchTerm, $searchTerm];

        if ($folder) {
            if ($includeSubfolders) {
                $sql .= " AND (folder = ? OR folder LIKE ?)";
                $params[] = $folder;
                $params[] = $folder . '/%';
            } else {
                $sql .= " AND folder = ?";
                $params[] = $folder;
            }
        }

        $sql .= " ORDER BY uploaded_at DESC LIMIT ?";
        $params[] = (int)$limit;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("search_media error: " . $e->getMessage());
        return [];
    }
}

/**
 * Get single media file by ID
 */
function get_media($id) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("SELECT * FROM media WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("get_media error: " . $e->getMessage());
        return null;
    }
}

/**
 * Get media URL
 */
function get_media_url($id) {
    $media = get_media($id);
    if ($media && isset($media['filepath'])) {
        return '/' . ltrim($media['filepath'], '/');
    }
    return null;
}

/**
 * Upload media file
 */
function upload_media($file, $folder = 'general', $uploadedBy = null) {
    global $pdo;
    
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml', 'application/pdf'];
    $maxSize = 10 * 1024 * 1024; // 10MB
    
    // Validate file
    if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return ['success' => false, 'error' => 'Ingen fil uppladdad'];
    }
    
    if ($file['size'] > $maxSize) {
        return ['success' => false, 'error' => 'Filen är för stor (max 10MB)'];
    }
    
    $mimeType = mime_content_type($file['tmp_name']);
    if (!in_array($mimeType, $allowedTypes)) {
        return ['success' => false, 'error' => 'Filtypen stöds inte'];
    }
    
    // Generate filename
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = uniqid() . '_' . time() . '.' . strtolower($ext);
    $relativeFilepath = "uploads/media/{$folder}/{$filename}";

    // Use absolute path for file operations
    $rootPath = defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__);
    $absoluteFilepath = $rootPath . '/' . $relativeFilepath;

    // Create directory if needed
    $dir = dirname($absoluteFilepath);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    // Move file
    if (!move_uploaded_file($file['tmp_name'], $absoluteFilepath)) {
        return ['success' => false, 'error' => 'Kunde inte spara filen'];
    }

    // Get image dimensions if applicable
    $width = null;
    $height = null;
    if (strpos($mimeType, 'image/') === 0 && $mimeType !== 'image/svg+xml') {
        $dimensions = getimagesize($absoluteFilepath);
        if ($dimensions) {
            $width = $dimensions[0];
            $height = $dimensions[1];
        }
    }
    
    // Save to database (store relative path)
    try {
        $stmt = $pdo->prepare("
            INSERT INTO media (filename, original_filename, filepath, mime_type, size, width, height, folder, uploaded_by, uploaded_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $filename,
            $file['name'],
            $relativeFilepath,
            $mimeType,
            $file['size'],
            $width,
            $height,
            $folder,
            $uploadedBy
        ]);

        $mediaId = $pdo->lastInsertId();

        return [
            'success' => true,
            'id' => $mediaId,
            'url' => '/' . $relativeFilepath,
            'filename' => $filename
        ];
    } catch (PDOException $e) {
        error_log("upload_media error: " . $e->getMessage());
        // Clean up file on database error
        @unlink($absoluteFilepath);
        return ['success' => false, 'error' => 'Databasfel'];
    }
}

/**
 * Update media metadata
 */
function update_media($id, $data) {
    global $pdo;
    
    try {
        $allowedFields = ['folder', 'alt_text', 'caption'];
        $updates = [];
        $params = [];
        
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $updates[] = "{$field} = ?";
                $params[] = $data[$field];
            }
        }
        
        if (empty($updates)) {
            return ['success' => false, 'error' => 'Inget att uppdatera'];
        }
        
        $params[] = $id;
        $sql = "UPDATE media SET " . implode(', ', $updates) . " WHERE id = ?";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        return ['success' => true];
    } catch (PDOException $e) {
        error_log("update_media error: " . $e->getMessage());
        return ['success' => false, 'error' => 'Kunde inte uppdatera'];
    }
}

/**
 * Delete media file
 */
function delete_media($id) {
    global $pdo;
    
    try {
        // Get file info first
        $media = get_media($id);
        if (!$media) {
            return ['success' => false, 'error' => 'Media hittades inte'];
        }
        
        // Check if media is in use
        $usage = get_media_usage($id);
        if (!empty($usage)) {
            return ['success' => false, 'error' => 'Filen används och kan inte raderas'];
        }
        
        // Delete from database
        $stmt = $pdo->prepare("DELETE FROM media WHERE id = ?");
        $stmt->execute([$id]);
        
        // Delete file (use absolute path)
        $rootPath = defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__);
        $absolutePath = $rootPath . '/' . ltrim($media['filepath'], '/');
        if (file_exists($absolutePath)) {
            @unlink($absolutePath);
        }

        return ['success' => true];
    } catch (PDOException $e) {
        error_log("delete_media error: " . $e->getMessage());
        return ['success' => false, 'error' => 'Kunde inte radera'];
    }
}

/**
 * Get media usage (where it's used)
 */
function get_media_usage($mediaId) {
    global $pdo;
    $usage = [];
    
    try {
        // Check sponsors
        $stmt = $pdo->prepare("SELECT id, name FROM sponsors WHERE logo_media_id = ?");
        $stmt->execute([$mediaId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $usage[] = [
                'entity_type' => 'Sponsor',
                'entity_id' => $row['id'],
                'entity_name' => $row['name'],
                'field' => 'logo'
            ];
        }
        
        // Check series (if columns exist)
        try {
            $stmt = $pdo->prepare("SELECT id, name FROM series WHERE logo_light_media_id = ? OR logo_dark_media_id = ?");
            $stmt->execute([$mediaId, $mediaId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $usage[] = [
                    'entity_type' => 'Serie',
                    'entity_id' => $row['id'],
                    'entity_name' => $row['name'],
                    'field' => 'logo'
                ];
            }
        } catch (PDOException $e) {
            // Columns might not exist yet
        }
        
        // Check ad_placements
        try {
            $stmt = $pdo->prepare("SELECT id, name FROM ad_placements WHERE media_id = ?");
            $stmt->execute([$mediaId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $usage[] = [
                    'entity_type' => 'Annons',
                    'entity_id' => $row['id'],
                    'entity_name' => $row['name'],
                    'field' => 'bild'
                ];
            }
        } catch (PDOException $e) {
            // Table might not exist yet
        }
        
    } catch (PDOException $e) {
        error_log("get_media_usage error: " . $e->getMessage());
    }
    
    return $usage;
}

/**
 * Format file size for display
 */
function format_file_size($bytes) {
    if ($bytes === null || $bytes === 0) return '0 B';
    
    $units = ['B', 'KB', 'MB', 'GB'];
    $unitIndex = 0;
    $size = (float)$bytes;
    
    while ($size >= 1024 && $unitIndex < count($units) - 1) {
        $size /= 1024;
        $unitIndex++;
    }
    
    return round($size, 1) . ' ' . $units[$unitIndex];
}

/**
 * Check if media exists
 */
function media_exists($id) {
    global $pdo;

    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM media WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetchColumn() > 0;
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Get media thumbnail URL
 * For now returns the same URL, but can be extended to generate actual thumbnails
 */
function get_media_thumbnail($id, $size = 'medium') {
    $media = get_media($id);
    if ($media && isset($media['filepath'])) {
        return '/' . ltrim($media['filepath'], '/');
    }
    return '/assets/images/placeholder.png';
}

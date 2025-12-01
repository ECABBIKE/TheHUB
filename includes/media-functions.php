<?php
/**
 * Media Library Helper Functions
 * TheHUB V3 - Media & Sponsor System
 *
 * Central media management functions for upload, retrieval, thumbnails, and tracking.
 */

// Ensure config is loaded
if (!defined('HUB_V3_ROOT')) {
    define('HUB_V3_ROOT', dirname(__DIR__));
}

/**
 * Get upload base path
 */
function get_media_base_path(): string {
    return HUB_V3_ROOT . '/uploads/media';
}

/**
 * Get upload base URL
 */
function get_media_base_url(): string {
    $baseUrl = defined('HUB_V3_URL') ? HUB_V3_URL : '';
    return rtrim($baseUrl, '/') . '/uploads/media';
}

/**
 * Get media by ID
 *
 * @param int $mediaId Media ID
 * @return array|null Media record or null if not found
 */
function get_media(int $mediaId): ?array {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM media WHERE id = ?");
    $stmt->execute([$mediaId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result ?: null;
}

/**
 * Get full URL for a media file
 *
 * @param int|null $mediaId Media ID
 * @param string $default Default URL if media not found
 * @return string Full URL to media file
 */
function get_media_url(?int $mediaId, string $default = ''): string {
    if (!$mediaId) {
        return $default;
    }

    $media = get_media($mediaId);
    if (!$media) {
        return $default;
    }

    $baseUrl = defined('HUB_V3_URL') ? HUB_V3_URL : '';
    return rtrim($baseUrl, '/') . '/' . ltrim($media['filepath'], '/');
}

/**
 * Get or generate thumbnail for media
 *
 * @param int $mediaId Media ID
 * @param string $size Thumbnail size: 'small' (150), 'medium' (300), 'large' (600)
 * @return string URL to thumbnail
 */
function get_media_thumbnail(int $mediaId, string $size = 'medium'): string {
    $media = get_media($mediaId);
    if (!$media) {
        return '';
    }

    // Only images can have thumbnails
    if (!str_starts_with($media['mime_type'], 'image/')) {
        return get_media_url($mediaId);
    }

    $sizes = [
        'small' => 150,
        'medium' => 300,
        'large' => 600
    ];

    $dimension = $sizes[$size] ?? 300;
    $basePath = get_media_base_path();
    $thumbDir = $basePath . '/thumbnails/' . $size;

    // Create thumbnail directory if needed
    if (!is_dir($thumbDir)) {
        mkdir($thumbDir, 0755, true);
    }

    $thumbFilename = pathinfo($media['filename'], PATHINFO_FILENAME) . '_' . $size . '.jpg';
    $thumbPath = $thumbDir . '/' . $thumbFilename;

    // Generate thumbnail if it doesn't exist
    if (!file_exists($thumbPath)) {
        $sourcePath = HUB_V3_ROOT . '/' . $media['filepath'];
        if (file_exists($sourcePath)) {
            generate_thumbnail($sourcePath, $thumbPath, $dimension);
        }
    }

    if (file_exists($thumbPath)) {
        return get_media_base_url() . '/thumbnails/' . $size . '/' . $thumbFilename;
    }

    // Fallback to original
    return get_media_url($mediaId);
}

/**
 * Generate thumbnail from source image
 *
 * @param string $sourcePath Full path to source image
 * @param string $destPath Full path for thumbnail
 * @param int $maxSize Maximum width/height
 * @return bool Success
 */
function generate_thumbnail(string $sourcePath, string $destPath, int $maxSize): bool {
    if (!file_exists($sourcePath)) {
        return false;
    }

    $imageInfo = @getimagesize($sourcePath);
    if (!$imageInfo) {
        return false;
    }

    $mime = $imageInfo['mime'];
    $width = $imageInfo[0];
    $height = $imageInfo[1];

    // Calculate new dimensions maintaining aspect ratio
    if ($width > $height) {
        $newWidth = $maxSize;
        $newHeight = (int) ($height * ($maxSize / $width));
    } else {
        $newHeight = $maxSize;
        $newWidth = (int) ($width * ($maxSize / $height));
    }

    // Create source image resource
    switch ($mime) {
        case 'image/jpeg':
            $source = @imagecreatefromjpeg($sourcePath);
            break;
        case 'image/png':
            $source = @imagecreatefrompng($sourcePath);
            break;
        case 'image/gif':
            $source = @imagecreatefromgif($sourcePath);
            break;
        case 'image/webp':
            $source = @imagecreatefromwebp($sourcePath);
            break;
        default:
            return false;
    }

    if (!$source) {
        return false;
    }

    // Create thumbnail
    $thumb = imagecreatetruecolor($newWidth, $newHeight);

    // Preserve transparency for PNG/GIF
    if ($mime === 'image/png' || $mime === 'image/gif') {
        imagealphablending($thumb, false);
        imagesavealpha($thumb, true);
        $transparent = imagecolorallocatealpha($thumb, 255, 255, 255, 127);
        imagefilledrectangle($thumb, 0, 0, $newWidth, $newHeight, $transparent);
    }

    // Resize
    imagecopyresampled($thumb, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

    // Save as JPEG for smaller file size
    $result = imagejpeg($thumb, $destPath, 85);

    // Cleanup
    imagedestroy($source);
    imagedestroy($thumb);

    return $result;
}

/**
 * Track media usage in the system
 *
 * @param int $mediaId Media ID
 * @param string $entityType Entity type: series|event|sponsor|club|page|ad
 * @param int $entityId Entity ID
 * @param string $field Field name: logo|header|banner|badge_logo
 * @return bool Success
 */
function track_media_usage(int $mediaId, string $entityType, int $entityId, string $field): bool {
    $db = getDB();

    // Remove existing usage for this entity/field
    $stmt = $db->prepare("DELETE FROM media_usage WHERE entity_type = ? AND entity_id = ? AND field = ?");
    $stmt->execute([$entityType, $entityId, $field]);

    // Add new usage
    $stmt = $db->prepare("INSERT INTO media_usage (media_id, entity_type, entity_id, field) VALUES (?, ?, ?, ?)");
    return $stmt->execute([$mediaId, $entityType, $entityId, $field]);
}

/**
 * Remove media usage tracking
 *
 * @param string $entityType Entity type
 * @param int $entityId Entity ID
 * @param string|null $field Specific field or null for all
 * @return bool Success
 */
function remove_media_usage(string $entityType, int $entityId, ?string $field = null): bool {
    $db = getDB();

    if ($field) {
        $stmt = $db->prepare("DELETE FROM media_usage WHERE entity_type = ? AND entity_id = ? AND field = ?");
        return $stmt->execute([$entityType, $entityId, $field]);
    } else {
        $stmt = $db->prepare("DELETE FROM media_usage WHERE entity_type = ? AND entity_id = ?");
        return $stmt->execute([$entityType, $entityId]);
    }
}

/**
 * Delete media file and all references
 *
 * @param int $mediaId Media ID
 * @return bool Success
 */
function delete_media(int $mediaId): bool {
    $media = get_media($mediaId);
    if (!$media) {
        return false;
    }

    $db = getDB();

    // Delete physical file
    $filePath = HUB_V3_ROOT . '/' . $media['filepath'];
    if (file_exists($filePath)) {
        unlink($filePath);
    }

    // Delete thumbnails
    $sizes = ['small', 'medium', 'large'];
    foreach ($sizes as $size) {
        $thumbFilename = pathinfo($media['filename'], PATHINFO_FILENAME) . '_' . $size . '.jpg';
        $thumbPath = get_media_base_path() . '/thumbnails/' . $size . '/' . $thumbFilename;
        if (file_exists($thumbPath)) {
            unlink($thumbPath);
        }
    }

    // Delete from database (CASCADE will handle media_usage)
    $stmt = $db->prepare("DELETE FROM media WHERE id = ?");
    return $stmt->execute([$mediaId]);
}

/**
 * Get media files by folder
 *
 * @param string|null $folder Folder name or null for all
 * @param int $limit Number of results
 * @param int $offset Offset for pagination
 * @return array Media records
 */
function get_media_by_folder(?string $folder = null, int $limit = 50, int $offset = 0): array {
    $db = getDB();

    if ($folder) {
        $stmt = $db->prepare("
            SELECT * FROM media
            WHERE folder = ?
            ORDER BY uploaded_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$folder, $limit, $offset]);
    } else {
        $stmt = $db->prepare("
            SELECT * FROM media
            ORDER BY uploaded_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$limit, $offset]);
    }

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Search media by filename or caption
 *
 * @param string $query Search query
 * @param string|null $folder Limit to folder
 * @param int $limit Number of results
 * @return array Media records
 */
function search_media(string $query, ?string $folder = null, int $limit = 50): array {
    $db = getDB();
    $searchTerm = '%' . $query . '%';

    if ($folder) {
        $stmt = $db->prepare("
            SELECT * FROM media
            WHERE folder = ? AND (
                filename LIKE ? OR
                original_filename LIKE ? OR
                caption LIKE ? OR
                alt_text LIKE ?
            )
            ORDER BY uploaded_at DESC
            LIMIT ?
        ");
        $stmt->execute([$folder, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $limit]);
    } else {
        $stmt = $db->prepare("
            SELECT * FROM media
            WHERE filename LIKE ? OR
                  original_filename LIKE ? OR
                  caption LIKE ? OR
                  alt_text LIKE ?
            ORDER BY uploaded_at DESC
            LIMIT ?
        ");
        $stmt->execute([$searchTerm, $searchTerm, $searchTerm, $searchTerm, $limit]);
    }

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get media statistics by folder
 *
 * @return array Folder stats with count and total size
 */
function get_media_stats(): array {
    $db = getDB();
    $stmt = $db->query("
        SELECT
            folder,
            COUNT(*) as count,
            SUM(size) as total_size
        FROM media
        GROUP BY folder
        ORDER BY folder
    ");

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Format file size for display
 *
 * @param int $bytes Size in bytes
 * @return string Formatted size (e.g., "1.5 MB")
 */
function format_file_size(int $bytes): string {
    $units = ['B', 'KB', 'MB', 'GB'];
    $unitIndex = 0;
    $size = (float) $bytes;

    while ($size >= 1024 && $unitIndex < count($units) - 1) {
        $size /= 1024;
        $unitIndex++;
    }

    return round($size, 1) . ' ' . $units[$unitIndex];
}

/**
 * Upload a media file
 *
 * @param array $file $_FILES array element
 * @param string $folder Target folder
 * @param int|null $uploadedBy Admin user ID
 * @param array $metadata Additional metadata
 * @return array ['success' => bool, 'media_id' => int|null, 'error' => string|null]
 */
function upload_media(array $file, string $folder = 'general', ?int $uploadedBy = null, array $metadata = []): array {
    // Validate file
    if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return ['success' => false, 'media_id' => null, 'error' => 'Ingen fil uppladdad'];
    }

    // Check for upload errors
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors = [
            UPLOAD_ERR_INI_SIZE => 'Filen överskrider max storlek',
            UPLOAD_ERR_FORM_SIZE => 'Filen överskrider formulärets max storlek',
            UPLOAD_ERR_PARTIAL => 'Filen laddades endast upp delvis',
            UPLOAD_ERR_NO_FILE => 'Ingen fil valdes',
            UPLOAD_ERR_NO_TMP_DIR => 'Temporär mapp saknas',
            UPLOAD_ERR_CANT_WRITE => 'Kunde inte skriva fil',
            UPLOAD_ERR_EXTENSION => 'Uppladdning stoppades av extension'
        ];
        return ['success' => false, 'media_id' => null, 'error' => $errors[$file['error']] ?? 'Okänt uppladdningsfel'];
    }

    // Allowed mime types
    $allowedTypes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/svg+xml' => 'svg',
        'application/pdf' => 'pdf'
    ];

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file['tmp_name']);

    if (!isset($allowedTypes[$mimeType])) {
        return ['success' => false, 'media_id' => null, 'error' => 'Filtypen är inte tillåten: ' . $mimeType];
    }

    // Max file size (10MB)
    $maxSize = 10 * 1024 * 1024;
    if ($file['size'] > $maxSize) {
        return ['success' => false, 'media_id' => null, 'error' => 'Filen är för stor (max 10MB)'];
    }

    // Generate unique filename
    $extension = $allowedTypes[$mimeType];
    $filename = uniqid('media_') . '_' . time() . '.' . $extension;

    // Ensure folder exists
    $basePath = get_media_base_path();
    $folderPath = $basePath . '/' . $folder;
    if (!is_dir($folderPath)) {
        mkdir($folderPath, 0755, true);
    }

    // Move file
    $destPath = $folderPath . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        return ['success' => false, 'media_id' => null, 'error' => 'Kunde inte spara filen'];
    }

    // Get image dimensions
    $width = null;
    $height = null;
    if (str_starts_with($mimeType, 'image/') && $mimeType !== 'image/svg+xml') {
        $imageInfo = @getimagesize($destPath);
        if ($imageInfo) {
            $width = $imageInfo[0];
            $height = $imageInfo[1];
        }
    }

    // Save to database
    $db = getDB();
    $stmt = $db->prepare("
        INSERT INTO media (filename, original_filename, filepath, mime_type, size, width, height, folder, uploaded_by, alt_text, caption, metadata)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $relativePath = 'uploads/media/' . $folder . '/' . $filename;

    $stmt->execute([
        $filename,
        $file['name'],
        $relativePath,
        $mimeType,
        $file['size'],
        $width,
        $height,
        $folder,
        $uploadedBy,
        $metadata['alt_text'] ?? null,
        $metadata['caption'] ?? null,
        !empty($metadata) ? json_encode($metadata) : null
    ]);

    $mediaId = (int) $db->lastInsertId();

    // Generate thumbnails for images
    if (str_starts_with($mimeType, 'image/') && $mimeType !== 'image/svg+xml') {
        foreach (['small', 'medium', 'large'] as $size) {
            get_media_thumbnail($mediaId, $size);
        }
    }

    return ['success' => true, 'media_id' => $mediaId, 'error' => null];
}

/**
 * Update media metadata
 *
 * @param int $mediaId Media ID
 * @param array $data Data to update (alt_text, caption, folder)
 * @return bool Success
 */
function update_media(int $mediaId, array $data): bool {
    $db = getDB();

    $allowed = ['alt_text', 'caption', 'folder'];
    $updates = [];
    $values = [];

    foreach ($allowed as $field) {
        if (isset($data[$field])) {
            $updates[] = "$field = ?";
            $values[] = $data[$field];
        }
    }

    if (empty($updates)) {
        return false;
    }

    $values[] = $mediaId;
    $sql = "UPDATE media SET " . implode(', ', $updates) . " WHERE id = ?";
    $stmt = $db->prepare($sql);
    return $stmt->execute($values);
}

/**
 * Get media usage information
 *
 * @param int $mediaId Media ID
 * @return array Usage records
 */
function get_media_usage(int $mediaId): array {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT mu.*,
            CASE mu.entity_type
                WHEN 'series' THEN (SELECT name FROM series WHERE id = mu.entity_id)
                WHEN 'event' THEN (SELECT name FROM events WHERE id = mu.entity_id)
                WHEN 'sponsor' THEN (SELECT name FROM sponsors WHERE id = mu.entity_id)
                WHEN 'club' THEN (SELECT name FROM clubs WHERE id = mu.entity_id)
                ELSE NULL
            END as entity_name
        FROM media_usage mu
        WHERE mu.media_id = ?
        ORDER BY mu.created_at DESC
    ");
    $stmt->execute([$mediaId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Check if media is in use
 *
 * @param int $mediaId Media ID
 * @return bool True if media is referenced somewhere
 */
function is_media_in_use(int $mediaId): bool {
    $db = getDB();
    $stmt = $db->prepare("SELECT COUNT(*) FROM media_usage WHERE media_id = ?");
    $stmt->execute([$mediaId]);
    return (int) $stmt->fetchColumn() > 0;
}

/**
 * Get total media count
 *
 * @param string|null $folder Filter by folder
 * @return int Total count
 */
function get_media_count(?string $folder = null): int {
    $db = getDB();

    if ($folder) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM media WHERE folder = ?");
        $stmt->execute([$folder]);
    } else {
        $stmt = $db->query("SELECT COUNT(*) FROM media");
    }

    return (int) $stmt->fetchColumn();
}

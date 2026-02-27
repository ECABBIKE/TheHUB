<?php
/**
 * AJAX Upload Album Photo
 * Hanterar uppladdning av EN bild åt gången till R2
 * Anropas från chunked uploader i event-albums.php
 */
set_time_limit(60);
ini_set('memory_limit', '256M');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/r2-storage.php';
require_admin();

header('Content-Type: application/json');

global $pdo;

$albumId = (int)($_POST['album_id'] ?? 0);

if (!$albumId) {
    echo json_encode(['success' => false, 'error' => 'album_id saknas']);
    exit;
}

// Hämta event_id för R2-nyckel
try {
    $stmt = $pdo->prepare("SELECT event_id FROM event_albums WHERE id = ?");
    $stmt->execute([$albumId]);
    $albumEventId = (int)$stmt->fetchColumn();
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Kunde inte hitta albumet']);
    exit;
}

if (!$albumEventId) {
    echo json_encode(['success' => false, 'error' => 'Albumet saknar event_id']);
    exit;
}

// Kolla att en fil skickades
if (empty($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
    $errorCode = $_FILES['photo']['error'] ?? -1;
    $errorMessages = [
        UPLOAD_ERR_INI_SIZE => 'Filen överskrider upload_max_filesize',
        UPLOAD_ERR_FORM_SIZE => 'Filen överskrider MAX_FILE_SIZE',
        UPLOAD_ERR_PARTIAL => 'Filen laddades bara upp delvis',
        UPLOAD_ERR_NO_FILE => 'Ingen fil vald',
        UPLOAD_ERR_NO_TMP_DIR => 'Temporär mapp saknas',
        UPLOAD_ERR_CANT_WRITE => 'Kunde inte skriva fil',
    ];
    echo json_encode(['success' => false, 'error' => $errorMessages[$errorCode] ?? "Uppladdningsfel (kod $errorCode)"]);
    exit;
}

$file = $_FILES['photo'];

// Validera filtyp
$allowedTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

if (!in_array($mimeType, $allowedTypes)) {
    echo json_encode(['success' => false, 'error' => 'Otillåten filtyp: ' . $mimeType]);
    exit;
}

$r2 = R2Storage::getInstance();
if (!$r2) {
    echo json_encode(['success' => false, 'error' => 'R2 är inte konfigurerat']);
    exit;
}

// Optimera bilden
$optimized = R2Storage::optimizeImage($file['tmp_name'], 1920, 82);
$key = R2Storage::generatePhotoKey($albumEventId, $file['name']);
$contentType = $mimeType ?: 'image/jpeg';

// Ladda upp till R2
$r2Result = $r2->upload($optimized['path'], $key, $contentType);

// Generera och ladda upp thumbnail
$thumbUrl = null;
$thumbResult = R2Storage::generateThumbnail($file['tmp_name'], 400, 75);
if ($thumbResult['path'] !== $file['tmp_name']) {
    $thumbKey = 'thumbs/' . $key;
    $thumbUpload = $r2->upload($thumbResult['path'], $thumbKey, $contentType);
    if ($thumbUpload['success']) {
        $thumbUrl = $thumbUpload['url'];
    }
    @unlink($thumbResult['path']);
}

// Rensa optimerad temp-fil
if ($optimized['path'] !== $file['tmp_name']) {
    @unlink($optimized['path']);
}

if (!$r2Result['success']) {
    echo json_encode(['success' => false, 'error' => $r2Result['error'] ?? 'R2-uppladdning misslyckades']);
    exit;
}

// Spara i databas
try {
    $sortOrder = 0;
    $stmt = $pdo->prepare("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM event_photos WHERE album_id = ?");
    $stmt->execute([$albumId]);
    $sortOrder = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("
        INSERT INTO event_photos (album_id, external_url, thumbnail_url, r2_key, sort_order)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([$albumId, $r2Result['url'], $thumbUrl ?? $r2Result['url'], $key, $sortOrder]);

    // Uppdatera bildräknare
    $pdo->prepare("UPDATE event_albums SET photo_count = (SELECT COUNT(*) FROM event_photos WHERE album_id = ?) WHERE id = ?")
        ->execute([$albumId, $albumId]);

    echo json_encode([
        'success' => true,
        'photo_id' => (int)$pdo->lastInsertId(),
        'url' => $r2Result['url'],
        'thumbnail_url' => $thumbUrl ?? $r2Result['url'],
        'key' => $key
    ]);
} catch (PDOException $e) {
    error_log("Insert album photo error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Databasfel: ' . $e->getMessage()]);
}

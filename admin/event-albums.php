<?php
/**
 * Event Photo Albums - Admin
 * Hantera fotoalbum per event med Cloudflare R2-lagring och manuell rider-taggning
 */
set_time_limit(300);
ini_set('memory_limit', '256M');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/media-functions.php';
require_once __DIR__ . '/../includes/r2-storage.php';
require_admin();

global $pdo;

$pageTitle = 'Fotoalbum';
$currentPage = 'event-albums';

// Get action and IDs
$action = $_GET['action'] ?? 'list';
$albumId = (int)($_GET['album_id'] ?? 0);
$eventId = (int)($_GET['event_id'] ?? 0);

// Handle POST actions
$message = '';
$messageType = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['action'] ?? '';

    // Create or update album
    if ($postAction === 'save_album') {
        $aEventId = (int)($_POST['event_id'] ?? 0);
        $aTitle = trim($_POST['title'] ?? '');
        $aGoogleUrl = trim($_POST['google_photos_url'] ?? '');
        $aDescription = trim($_POST['description'] ?? '');
        $aPhotographer = trim($_POST['photographer'] ?? '');
        $aPhotographerUrl = trim($_POST['photographer_url'] ?? '');
        $aPublished = isset($_POST['is_published']) ? 1 : 0;
        $aId = (int)($_POST['album_id'] ?? 0);

        if (!$aEventId) {
            $message = 'Välj ett event';
            $messageType = 'danger';
        } else {
            try {
                if ($aId > 0) {
                    $stmt = $pdo->prepare("
                        UPDATE event_albums SET
                            event_id = ?, title = ?, google_photos_url = ?, description = ?,
                            photographer = ?, photographer_url = ?, is_published = ?,
                            updated_at = NOW()
                        WHERE id = ?
                    ");
                    $stmt->execute([$aEventId, $aTitle ?: null, $aGoogleUrl ?: null, $aDescription ?: null,
                        $aPhotographer ?: null, $aPhotographerUrl ?: null, $aPublished, $aId]);
                    $message = 'Albumet uppdaterat';
                    $messageType = 'success';
                    $albumId = $aId;
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO event_albums (event_id, title, google_photos_url, description, photographer, photographer_url, is_published)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$aEventId, $aTitle ?: null, $aGoogleUrl ?: null, $aDescription ?: null,
                        $aPhotographer ?: null, $aPhotographerUrl ?: null, $aPublished]);
                    $albumId = (int)$pdo->lastInsertId();
                    $message = 'Album skapat';
                    $messageType = 'success';
                }
                $action = 'edit';
            } catch (PDOException $e) {
                error_log("Save album error: " . $e->getMessage());
                $message = 'Kunde inte spara: ' . $e->getMessage();
                $messageType = 'danger';
            }
        }
    }

    // Upload photos (R2 or local fallback)
    if ($postAction === 'upload_photos' && $albumId > 0) {
        $uploaded = 0;
        $errors = [];

        // Get event_id for R2 key generation
        $albumEventId = 0;
        try {
            $stmt = $pdo->prepare("SELECT event_id FROM event_albums WHERE id = ?");
            $stmt->execute([$albumId]);
            $albumEventId = (int)$stmt->fetchColumn();
        } catch (PDOException $e) {}

        $r2 = R2Storage::getInstance();

        if (!empty($_FILES['photos']['name'][0])) {
            foreach ($_FILES['photos']['name'] as $i => $name) {
                $file = [
                    'name' => $_FILES['photos']['name'][$i],
                    'type' => $_FILES['photos']['type'][$i],
                    'tmp_name' => $_FILES['photos']['tmp_name'][$i],
                    'error' => $_FILES['photos']['error'][$i],
                    'size' => $_FILES['photos']['size'][$i]
                ];

                if ($file['error'] !== UPLOAD_ERR_OK) continue;

                if ($r2 && $albumEventId) {
                    // R2-uppladdning: optimera → ladda upp → spara URL
                    $optimized = R2Storage::optimizeImage($file['tmp_name'], 1920, 82);
                    $key = R2Storage::generatePhotoKey($albumEventId, $file['name']);

                    $contentType = $file['type'] ?: 'image/jpeg';
                    $r2Result = $r2->upload($optimized['path'], $key, $contentType);

                    // Generera thumbnail
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

                    // Rensa temp-filer
                    if ($optimized['path'] !== $file['tmp_name']) {
                        @unlink($optimized['path']);
                    }

                    if ($r2Result['success']) {
                        try {
                            $stmt = $pdo->prepare("
                                INSERT INTO event_photos (album_id, external_url, thumbnail_url, r2_key, sort_order)
                                VALUES (?, ?, ?, ?, ?)
                            ");
                            $stmt->execute([$albumId, $r2Result['url'], $thumbUrl ?? $r2Result['url'], $key, $uploaded]);
                            $uploaded++;
                        } catch (PDOException $e) {
                            error_log("Insert R2 photo error: " . $e->getMessage());
                            $errors[] = $file['name'] . ': DB-fel';
                        }
                    } else {
                        $errors[] = $file['name'] . ': ' . ($r2Result['error'] ?? 'R2-fel');
                    }
                } else {
                    // Lokal fallback (via media-system)
                    $result = upload_media($file, 'events/photos', $_SESSION['user']['id'] ?? null);
                    if ($result['success']) {
                        try {
                            $stmt = $pdo->prepare("
                                INSERT INTO event_photos (album_id, media_id, sort_order) VALUES (?, ?, ?)
                            ");
                            $stmt->execute([$albumId, $result['id'], $uploaded]);
                            $uploaded++;
                        } catch (PDOException $e) {
                            error_log("Insert photo error: " . $e->getMessage());
                        }
                    }
                }
            }
        }

        // Update photo count
        try {
            $pdo->prepare("UPDATE event_albums SET photo_count = (SELECT COUNT(*) FROM event_photos WHERE album_id = ?) WHERE id = ?")->execute([$albumId, $albumId]);
        } catch (PDOException $e) {}

        $message = $uploaded . ' bilder uppladdade' . ($r2 ? ' till R2' : ' lokalt');
        if (!empty($errors)) {
            $message .= ' (' . count($errors) . ' fel: ' . implode(', ', array_slice($errors, 0, 3)) . ')';
            $messageType = 'warning';
        } else {
            $messageType = 'success';
        }
        $action = 'edit';
    }

    // Bulk add external URLs (multiple URLs at once)
    if ($postAction === 'bulk_add_urls' && $albumId > 0) {
        $urlsText = trim($_POST['urls'] ?? '');
        $urls = array_filter(array_map('trim', preg_split('/[\r\n]+/', $urlsText)));
        $added = 0;

        foreach ($urls as $url) {
            if (!filter_var($url, FILTER_VALIDATE_URL)) continue;
            try {
                $stmt = $pdo->prepare("INSERT INTO event_photos (album_id, external_url, thumbnail_url) VALUES (?, ?, ?)");
                $stmt->execute([$albumId, $url, $url]);
                $added++;
            } catch (PDOException $e) {
                error_log("Bulk add URL error: " . $e->getMessage());
            }
        }

        if ($added > 0) {
            $pdo->prepare("UPDATE event_albums SET photo_count = (SELECT COUNT(*) FROM event_photos WHERE album_id = ?) WHERE id = ?")->execute([$albumId, $albumId]);
        }

        $message = $added . ' av ' . count($urls) . ' URL:er tillagda';
        $messageType = $added > 0 ? 'success' : 'warning';
        $action = 'edit';
    }

    // Add external photo URL
    if ($postAction === 'add_external_photo' && $albumId > 0) {
        $extUrl = trim($_POST['external_url'] ?? '');
        $thumbUrl = trim($_POST['thumbnail_url'] ?? '');
        $caption = trim($_POST['caption'] ?? '');

        if ($extUrl) {
            try {
                $stmt = $pdo->prepare("INSERT INTO event_photos (album_id, external_url, thumbnail_url, caption) VALUES (?, ?, ?, ?)");
                $stmt->execute([$albumId, $extUrl, $thumbUrl ?: $extUrl, $caption ?: null]);
                $pdo->prepare("UPDATE event_albums SET photo_count = (SELECT COUNT(*) FROM event_photos WHERE album_id = ?) WHERE id = ?")->execute([$albumId, $albumId]);
                $message = 'Bild tillagd';
                $messageType = 'success';
            } catch (PDOException $e) {
                $message = 'Kunde inte lägga till bild';
                $messageType = 'danger';
            }
        }
        $action = 'edit';
    }

    // Tag rider
    if ($postAction === 'tag_rider') {
        $photoId = (int)($_POST['photo_id'] ?? 0);
        $riderId = (int)($_POST['rider_id'] ?? 0);
        $taggedBy = (int)($_SESSION['user']['id'] ?? 0);

        if ($photoId && $riderId) {
            try {
                $stmt = $pdo->prepare("INSERT IGNORE INTO photo_rider_tags (photo_id, rider_id, tagged_by) VALUES (?, ?, ?)");
                $stmt->execute([$photoId, $riderId, $taggedBy ?: null]);
                echo json_encode(['success' => true]);
                exit;
            } catch (PDOException $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                exit;
            }
        }
        echo json_encode(['success' => false, 'error' => 'photo_id och rider_id krävs']);
        exit;
    }

    // Remove tag
    if ($postAction === 'remove_tag') {
        $tagId = (int)($_POST['tag_id'] ?? 0);
        if ($tagId) {
            try {
                $pdo->prepare("DELETE FROM photo_rider_tags WHERE id = ?")->execute([$tagId]);
                echo json_encode(['success' => true]);
                exit;
            } catch (PDOException $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                exit;
            }
        }
    }

    // Delete photo
    if ($postAction === 'delete_photo') {
        $photoId = (int)($_POST['photo_id'] ?? 0);
        if ($photoId) {
            try {
                // Get media_id and r2_key to delete the file too
                $stmt = $pdo->prepare("SELECT media_id, r2_key FROM event_photos WHERE id = ?");
                $stmt->execute([$photoId]);
                $photo = $stmt->fetch(PDO::FETCH_ASSOC);

                $pdo->prepare("DELETE FROM event_photos WHERE id = ?")->execute([$photoId]);

                // Radera från R2 om r2_key finns
                if ($photo && !empty($photo['r2_key'])) {
                    $r2 = R2Storage::getInstance();
                    if ($r2) {
                        $r2->deleteObject($photo['r2_key']);
                        // Radera thumbnail också
                        $r2->deleteObject('thumbs/' . $photo['r2_key']);
                    }
                }

                if ($photo && $photo['media_id']) {
                    delete_media($photo['media_id'], true);
                }

                // Update count
                if ($albumId > 0) {
                    $pdo->prepare("UPDATE event_albums SET photo_count = (SELECT COUNT(*) FROM event_photos WHERE album_id = ?) WHERE id = ?")->execute([$albumId, $albumId]);
                }

                echo json_encode(['success' => true]);
                exit;
            } catch (PDOException $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                exit;
            }
        }
    }

    // Delete album
    if ($postAction === 'delete_album') {
        $delId = (int)($_POST['album_id'] ?? 0);
        if ($delId) {
            try {
                // Radera R2-objekt för alla bilder i albumet
                $r2 = R2Storage::getInstance();
                if ($r2) {
                    $stmt = $pdo->prepare("SELECT r2_key FROM event_photos WHERE album_id = ? AND r2_key IS NOT NULL AND r2_key != ''");
                    $stmt->execute([$delId]);
                    $keys = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    foreach ($keys as $key) {
                        $r2->deleteObject($key);
                        $r2->deleteObject('thumbs/' . $key);
                    }
                }

                // Radera album (CASCADE tar bort event_photos + photo_rider_tags)
                $pdo->prepare("DELETE FROM event_albums WHERE id = ?")->execute([$delId]);
                $message = 'Album raderat' . ($keys ? ' (' . count($keys) . ' bilder borttagna från R2)' : '');
                $messageType = 'success';
                $action = 'list';
                $albumId = 0;
            } catch (PDOException $e) {
                $message = 'Kunde inte radera: ' . $e->getMessage();
                $messageType = 'danger';
            }
        }
    }
}

// Load data based on action
$albums = [];
$album = null;
$photos = [];
$events = [];

// Get events for dropdown
try {
    $events = $pdo->query("
        SELECT id, name, date, location
        FROM events
        WHERE date >= DATE_SUB(CURDATE(), INTERVAL 2 YEAR)
        ORDER BY date DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

if ($action === 'list') {
    try {
        $albums = $pdo->query("
            SELECT ea.*, e.name as event_name, e.date as event_date, e.location as event_location
            FROM event_albums ea
            JOIN events e ON ea.event_id = e.id
            ORDER BY e.date DESC
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("List albums error: " . $e->getMessage());
    }
}

if (($action === 'edit' || $action === 'photos') && $albumId > 0) {
    try {
        $stmt = $pdo->prepare("
            SELECT ea.*, e.name as event_name, e.date as event_date
            FROM event_albums ea
            JOIN events e ON ea.event_id = e.id
            WHERE ea.id = ?
        ");
        $stmt->execute([$albumId]);
        $album = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($album) {
            $photoFields = 'ep.id, ep.album_id, ep.media_id, ep.external_url, ep.thumbnail_url, ep.caption, ep.photographer, ep.sort_order, ep.is_highlight';
            // Check if r2_key column exists
            try {
                $pdo->query("SELECT r2_key FROM event_photos LIMIT 0");
                $photoFields .= ', ep.r2_key';
            } catch (PDOException $e) {
                // Column doesn't exist yet (migration 064 not run)
            }
            $stmt = $pdo->prepare("
                SELECT {$photoFields}, m.filepath, m.original_filename, m.width, m.height,
                    (SELECT COUNT(*) FROM photo_rider_tags WHERE photo_id = ep.id) as tag_count
                FROM event_photos ep
                LEFT JOIN media m ON ep.media_id = m.id
                WHERE ep.album_id = ?
                ORDER BY ep.sort_order, ep.id
            ");
            $stmt->execute([$albumId]);
            $photos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {
        error_log("Load album error: " . $e->getMessage());
    }
}

// Layout
include __DIR__ . '/components/unified-layout.php';
?>

<?php if ($message): ?>
<div class="alert alert-<?= $messageType ?>" style="margin-bottom: var(--space-md);">
    <?= htmlspecialchars($message) ?>
</div>
<?php endif; ?>

<?php if ($action === 'list'): ?>
<!-- ALBUM LIST -->
<div class="page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-lg);">
    <h1><?= $pageTitle ?></h1>
    <a href="/admin/event-albums?action=edit" class="btn btn-primary">
        <i data-lucide="plus" class="icon-sm"></i> Skapa album
    </a>
</div>

<?php if (empty($albums)): ?>
<div class="admin-card">
    <div class="admin-card-body" style="text-align: center; padding: var(--space-2xl);">
        <i data-lucide="images" style="width: 48px; height: 48px; color: var(--color-text-muted); margin-bottom: var(--space-md);"></i>
        <h3 style="margin: 0 0 var(--space-sm);">Inga album ännu</h3>
        <p style="color: var(--color-text-secondary);">Skapa ett album för att börja lägga till bilder från tävlingar.</p>
    </div>
</div>

<!-- Handbok -->
<details class="admin-card" style="margin-top: var(--space-md);">
    <summary class="admin-card-header" style="cursor: pointer;">
        <h3 style="margin: 0; display: flex; align-items: center; gap: var(--space-sm);">
            <i data-lucide="book-open" class="icon-sm"></i> Så här fungerar fotoalbum
        </h3>
    </summary>
    <div class="admin-card-body" style="font-size: 0.9rem; line-height: 1.7; color: var(--color-text-secondary);">
        <h4 style="color: var(--color-text-primary); margin: 0 0 var(--space-xs);">1. Skapa album</h4>
        <p>Klicka <strong>"Skapa album"</strong> ovan. Välj ett event i dropdown-listan och fyll i fotografens namn. Albumet kan sparas som utkast eller publiceras direkt.</p>

        <h4 style="color: var(--color-text-primary); margin: var(--space-md) 0 var(--space-xs);">2. Lägg till bilder</h4>
        <p>Det finns tre sätt att lägga till bilder:</p>
        <ul style="margin: var(--space-xs) 0; padding-left: var(--space-lg);">
            <?php if (R2Storage::isConfigured()): ?>
            <li><strong>Ladda upp filer</strong> — Bilder optimeras automatiskt (max 1920px, JPEG 82%) och lagras i Cloudflare R2. Välj flera filer samtidigt.</li>
            <?php endif; ?>
            <li><strong>Extern URL</strong> — Klistra in en bild-URL från valfri bildtjänst.</li>
            <li><strong>Bulk-URL</strong> — Klistra in flera URL:er samtidigt, en per rad.</li>
        </ul>

        <h4 style="color: var(--color-text-primary); margin: var(--space-md) 0 var(--space-xs);">3. Tagga deltagare</h4>
        <p>Klicka på en bild för att öppna taggningsmodalen. Sök på deltagarens namn och klicka för att tagga. En bild kan ha flera taggade deltagare (t.ex. om flera cyklister syns på samma bild).</p>

        <h4 style="color: var(--color-text-primary); margin: var(--space-md) 0 var(--space-xs);">4. Publicera</h4>
        <p>Markera <strong>"Publicerat"</strong> i albuminställningarna. Albumet syns då som en <strong>Galleri-flik</strong> på event-sidan. Premium-medlemmar ser sina taggade bilder på sin profilsida.</p>

        <?php if (!R2Storage::isConfigured()): ?>
        <div style="margin-top: var(--space-md); padding: var(--space-md); background: var(--color-accent-light); border-radius: var(--radius-sm);">
            <strong>Tips:</strong> Konfigurera Cloudflare R2 för att kunna ladda upp bilder direkt.
            <a href="/admin/tools/r2-config.php" style="color: var(--color-accent-text);">Konfigurera R2</a>
        </div>
        <?php endif; ?>
    </div>
</details>
<?php else: ?>
<div style="display: grid; gap: var(--space-md);">
    <?php foreach ($albums as $a): ?>
    <div class="admin-card">
        <div class="admin-card-body" style="display: flex; justify-content: space-between; align-items: center; padding: var(--space-md); cursor: pointer;" onclick="location.href='/admin/event-albums?action=edit&album_id=<?= $a['id'] ?>'">
            <div style="display: flex; align-items: center; gap: var(--space-md);">
                <div style="width: 48px; height: 48px; border-radius: var(--radius-sm); background: var(--color-accent-light); display: flex; align-items: center; justify-content: center;">
                    <i data-lucide="camera" style="width: 24px; height: 24px; color: var(--color-accent);"></i>
                </div>
                <div>
                    <h3 style="margin: 0; font-size: 1rem;"><?= htmlspecialchars($a['event_name']) ?></h3>
                    <div style="font-size: 0.8rem; color: var(--color-text-secondary);">
                        <?= date('Y-m-d', strtotime($a['event_date'])) ?>
                        <?php if ($a['title']): ?> &mdash; <?= htmlspecialchars($a['title']) ?><?php endif; ?>
                        <?php if ($a['photographer']): ?> &bull; Foto: <?= htmlspecialchars($a['photographer']) ?><?php endif; ?>
                    </div>
                </div>
            </div>
            <div style="display: flex; align-items: center; gap: var(--space-md);">
                <div style="text-align: center;">
                    <div style="font-size: 1.25rem; font-weight: 600;"><?= $a['photo_count'] ?></div>
                    <div style="font-size: 0.7rem; color: var(--color-text-muted);">bilder</div>
                </div>
                <?php if ($a['is_published']): ?>
                <span class="badge badge-success">Publicerat</span>
                <?php else: ?>
                <span class="badge badge-warning">Utkast</span>
                <?php endif; ?>
                <?php if ($a['google_photos_url']): ?>
                <i data-lucide="external-link" style="width: 16px; height: 16px; color: var(--color-text-muted);" title="Källänk"></i>
                <?php endif; ?>
                <form method="POST" style="margin: 0;" onclick="event.stopPropagation();">
                    <input type="hidden" name="action" value="delete_album">
                    <input type="hidden" name="album_id" value="<?= $a['id'] ?>">
                    <button type="submit" class="btn btn-ghost" style="padding: var(--space-xs); color: var(--color-text-muted);" onclick="return confirm('Radera albumet &quot;<?= htmlspecialchars($a['event_name'], ENT_QUOTES) ?>&quot; och alla dess bilder?')" title="Radera album">
                        <i data-lucide="trash-2" style="width: 16px; height: 16px;"></i>
                    </button>
                </form>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php elseif ($action === 'edit' || $action === 'photos'): ?>
<!-- EDIT / CREATE ALBUM -->
<div class="page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-lg);">
    <div>
        <a href="/admin/event-albums" style="font-size: 0.8rem; color: var(--color-text-secondary); text-decoration: none;">
            <i data-lucide="arrow-left" class="icon-sm"></i> Tillbaka
        </a>
        <h1 style="margin: var(--space-xs) 0 0;"><?= $album ? htmlspecialchars($album['event_name']) : 'Nytt album' ?></h1>
    </div>
</div>

<!-- Album settings -->
<details class="admin-card" <?= !$album ? 'open' : '' ?>>
    <summary class="admin-card-header" style="cursor: pointer;">
        <h3 style="margin: 0; display: flex; align-items: center; gap: var(--space-sm);">
            <i data-lucide="settings" class="icon-sm"></i> Albuminställningar
        </h3>
    </summary>
    <div class="admin-card-body">
        <form method="POST">
            <input type="hidden" name="action" value="save_album">
            <input type="hidden" name="album_id" value="<?= $album['id'] ?? 0 ?>">

            <div class="admin-form-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--space-md);">
                <div class="admin-form-group">
                    <label class="admin-form-label">Event *</label>
                    <select name="event_id" class="form-select" required>
                        <option value="">Välj event...</option>
                        <?php foreach ($events as $ev): ?>
                        <option value="<?= $ev['id'] ?>" <?= ($album['event_id'] ?? $eventId) == $ev['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($ev['name']) ?> (<?= $ev['date'] ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="admin-form-group">
                    <label class="admin-form-label">Albumtitel (valfritt)</label>
                    <input type="text" name="title" class="form-input" value="<?= htmlspecialchars($album['title'] ?? '') ?>" placeholder="T.ex. Tävlingsbilder">
                </div>
                <div class="admin-form-group">
                    <label class="admin-form-label">Källänk (valfritt)</label>
                    <input type="url" name="google_photos_url" class="form-input" value="<?= htmlspecialchars($album['google_photos_url'] ?? '') ?>" placeholder="https://photos.google.com/share/... eller annan källa">
                    <small class="form-help">Länk till originalalbum hos fotografen (Google Photos, Flickr, etc.)</small>
                </div>
                <div class="admin-form-group">
                    <label class="admin-form-label">Fotograf</label>
                    <input type="text" name="photographer" class="form-input" value="<?= htmlspecialchars($album['photographer'] ?? '') ?>" placeholder="Namn">
                </div>
                <div class="admin-form-group">
                    <label class="admin-form-label">Fotografens webbplats</label>
                    <input type="url" name="photographer_url" class="form-input" value="<?= htmlspecialchars($album['photographer_url'] ?? '') ?>" placeholder="https://...">
                </div>
                <div class="admin-form-group" style="display: flex; align-items: end;">
                    <label style="display: flex; align-items: center; gap: var(--space-sm); cursor: pointer;">
                        <input type="checkbox" name="is_published" value="1" <?= ($album['is_published'] ?? 0) ? 'checked' : '' ?>>
                        Publicerat (synligt på event-sidan)
                    </label>
                </div>
            </div>
            <div class="admin-form-group" style="margin-top: var(--space-md);">
                <label class="admin-form-label">Beskrivning</label>
                <textarea name="description" class="form-input" rows="2" placeholder="Kort beskrivning av albumet..."><?= htmlspecialchars($album['description'] ?? '') ?></textarea>
            </div>
            <div style="display: flex; gap: var(--space-sm); margin-top: var(--space-md);">
                <button type="submit" class="btn btn-primary">
                    <i data-lucide="save" class="icon-sm"></i> Spara
                </button>
                <?php if ($album): ?>
                <button type="submit" name="action" value="delete_album" class="btn btn-danger" onclick="return confirm('Radera albumet och alla bilder?')">
                    <i data-lucide="trash-2" class="icon-sm"></i> Radera album
                </button>
                <?php endif; ?>
            </div>
        </form>
    </div>
</details>

<?php if ($album): ?>
<!-- Add photos -->
<?php $r2Active = R2Storage::isConfigured(); ?>
<div class="admin-card" style="margin-top: var(--space-md);">
    <div class="admin-card-header">
        <h3 style="margin: 0; display: flex; align-items: center; gap: var(--space-sm);">
            <i data-lucide="image-plus" class="icon-sm"></i> Lägg till bilder
            <?php if ($r2Active): ?>
            <span class="badge badge-success" style="font-size: 0.65rem; font-weight: 400;">R2</span>
            <?php endif; ?>
        </h3>
    </div>
    <div class="admin-card-body">
        <?php if ($album['google_photos_url']): ?>
        <p style="font-size: 0.85rem; color: var(--color-text-secondary); margin: 0 0 var(--space-md);">
            <i data-lucide="external-link" class="icon-sm" style="vertical-align: text-bottom;"></i>
            Originalalbum:
            <a href="<?= htmlspecialchars($album['google_photos_url']) ?>" target="_blank" style="color: var(--color-accent-text);">Öppna källänk</a>
        </p>
        <?php endif; ?>

        <!-- Filuppladdning (chunked AJAX - hanterar stora album) -->
        <?php if ($r2Active): ?>
        <div style="margin-bottom: var(--space-lg);" id="uploadSection">
            <h4 style="margin: 0 0 var(--space-sm); font-size: 0.85rem; color: var(--color-text-primary); display: flex; align-items: center; gap: var(--space-xs);">
                <i data-lucide="upload" class="icon-sm"></i> Ladda upp bilder
                <span style="font-size: 0.75rem; color: var(--color-text-muted); font-weight: 400;">(optimeras och lagras i Cloudflare R2)</span>
            </h4>
            <div style="display: flex; flex-wrap: wrap; gap: var(--space-sm); align-items: end;">
                <div class="admin-form-group" style="flex: 1; min-width: 200px;">
                    <input type="file" id="r2FileInput" multiple accept="image/*" class="form-input">
                    <small class="form-help">Välj upp till hundratals bilder. Laddas upp en åt gången med progressindikator.</small>
                </div>
                <button type="button" class="btn btn-primary" id="r2UploadBtn" onclick="startChunkedUpload()" disabled>
                    <i data-lucide="upload" class="icon-sm"></i> Ladda upp till R2
                </button>
            </div>

            <!-- Progress UI (dolt tills uppladdning startar) -->
            <div id="uploadProgress" style="display: none; margin-top: var(--space-md);">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-xs);">
                    <span id="uploadStatusText" style="font-size: 0.85rem; color: var(--color-text-secondary);">Förbereder...</span>
                    <span id="uploadPercent" style="font-size: 0.85rem; font-weight: 600; color: var(--color-accent-text);">0%</span>
                </div>
                <div style="background: var(--color-bg-hover); border-radius: var(--radius-full); height: 8px; overflow: hidden;">
                    <div id="uploadBar" style="height: 100%; width: 0%; background: var(--color-accent); border-radius: var(--radius-full); transition: width 0.3s ease;"></div>
                </div>
                <div id="uploadDetails" style="display: flex; gap: var(--space-lg); margin-top: var(--space-xs); font-size: 0.75rem; color: var(--color-text-muted);">
                    <span id="uploadCount">0 / 0 bilder</span>
                    <span id="uploadSpeed"></span>
                    <span id="uploadEta"></span>
                </div>
                <div id="uploadErrors" style="display: none; margin-top: var(--space-sm); padding: var(--space-sm); background: rgba(239,68,68,0.1); border-radius: var(--radius-sm); font-size: 0.8rem; color: var(--color-error); max-height: 120px; overflow-y: auto;"></div>
                <div style="margin-top: var(--space-sm);">
                    <button type="button" id="uploadCancelBtn" class="btn btn-secondary" onclick="cancelUpload()" style="display: none;">
                        <i data-lucide="x" class="icon-sm"></i> Avbryt
                    </button>
                </div>
            </div>
        </div>
        <hr style="border: none; border-top: 1px solid var(--color-border); margin: var(--space-md) 0;">
        <?php endif; ?>

        <!-- Enstaka extern URL -->
        <div style="margin-bottom: var(--space-md);">
            <h4 style="margin: 0 0 var(--space-sm); font-size: 0.85rem; color: var(--color-text-primary); display: flex; align-items: center; gap: var(--space-xs);">
                <i data-lucide="link" class="icon-sm"></i> Extern bild-URL
            </h4>
            <form method="POST" id="addPhotoForm" style="display: flex; flex-wrap: wrap; gap: var(--space-sm); align-items: end;">
                <input type="hidden" name="action" value="add_external_photo">
                <input type="hidden" name="album_id" value="<?= $album['id'] ?>">
                <div class="admin-form-group" style="flex: 2; min-width: 250px;">
                    <input type="url" name="external_url" class="form-input" placeholder="https://..." required>
                </div>
                <div class="admin-form-group" style="flex: 1; min-width: 150px;">
                    <input type="text" name="caption" class="form-input" placeholder="Bildtext (valfritt)">
                </div>
                <button type="submit" class="btn btn-secondary">
                    <i data-lucide="plus" class="icon-sm"></i> Lägg till
                </button>
            </form>
        </div>

        <!-- Bulk-URL:er -->
        <details>
            <summary style="font-size: 0.85rem; color: var(--color-text-muted); cursor: pointer;">
                <i data-lucide="list" class="icon-sm" style="vertical-align: text-bottom;"></i>
                Klistra in flera URL:er samtidigt
            </summary>
            <form method="POST" style="margin-top: var(--space-sm);">
                <input type="hidden" name="action" value="bulk_add_urls">
                <input type="hidden" name="album_id" value="<?= $album['id'] ?>">
                <div class="admin-form-group">
                    <textarea name="urls" class="form-input" rows="6" placeholder="En URL per rad:&#10;https://example.com/photo1.jpg&#10;https://example.com/photo2.jpg&#10;https://example.com/photo3.jpg" required></textarea>
                    <small class="form-help">En URL per rad. Ogiltiga URL:er ignoreras.</small>
                </div>
                <button type="submit" class="btn btn-secondary">
                    <i data-lucide="plus" class="icon-sm"></i> Lägg till alla
                </button>
            </form>
        </details>

        <?php if (!$r2Active): ?>
        <!-- Lokal uppladdning (fallback) -->
        <details style="margin-top: var(--space-md);">
            <summary style="font-size: 0.85rem; color: var(--color-text-muted); cursor: pointer;">
                <i data-lucide="upload" class="icon-sm" style="vertical-align: text-bottom;"></i>
                Ladda upp från fil (lokal lagring)
            </summary>
            <form method="POST" enctype="multipart/form-data" style="display: flex; flex-wrap: wrap; gap: var(--space-md); align-items: end; margin-top: var(--space-sm);">
                <input type="hidden" name="action" value="upload_photos">
                <input type="hidden" name="album_id" value="<?= $album['id'] ?>">
                <div class="admin-form-group" style="flex: 1; min-width: 200px;">
                    <input type="file" name="photos[]" multiple accept="image/*" class="form-input" required>
                </div>
                <button type="submit" class="btn btn-secondary">
                    <i data-lucide="upload" class="icon-sm"></i> Ladda upp
                </button>
            </form>
        </details>
        <?php endif; ?>
    </div>
</div>

<!-- Photo grid with tagging -->
<?php if (!empty($photos)): ?>
<div class="admin-card" style="margin-top: var(--space-md);">
    <div class="admin-card-header">
        <h3 style="margin: 0; display: flex; align-items: center; gap: var(--space-sm);">
            <i data-lucide="images" class="icon-sm"></i> Bilder (<?= count($photos) ?>)
            <span style="font-size: 0.8rem; font-weight: 400; color: var(--color-text-secondary);">Klicka på en bild för att tagga deltagare</span>
        </h3>
    </div>
    <div class="admin-card-body" style="padding: var(--space-sm);">
        <div class="photo-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: var(--space-sm);">
            <?php foreach ($photos as $photo): ?>
            <?php
                $imgSrc = '';
                if ($photo['media_id'] && $photo['filepath']) {
                    $imgSrc = '/' . ltrim($photo['filepath'], '/');
                } elseif ($photo['thumbnail_url']) {
                    $imgSrc = $photo['thumbnail_url'];
                } elseif ($photo['external_url']) {
                    $imgSrc = $photo['external_url'];
                }
            ?>
            <div class="photo-card" data-photo-id="<?= $photo['id'] ?>" style="border: 1px solid var(--color-border); border-radius: var(--radius-sm); overflow: hidden; background: var(--color-bg-card); cursor: pointer;" onclick="openTagModal(<?= $photo['id'] ?>, '<?= htmlspecialchars($imgSrc, ENT_QUOTES) ?>')">
                <?php if ($imgSrc): ?>
                <div style="aspect-ratio: 4/3; overflow: hidden; background: var(--color-bg-sunken);">
                    <img src="<?= htmlspecialchars($imgSrc) ?>" alt="" style="width: 100%; height: 100%; object-fit: cover;" loading="lazy">
                </div>
                <?php else: ?>
                <div style="aspect-ratio: 4/3; display: flex; align-items: center; justify-content: center; background: var(--color-bg-sunken);">
                    <i data-lucide="image-off" style="width: 32px; height: 32px; color: var(--color-text-muted);"></i>
                </div>
                <?php endif; ?>
                <div style="padding: var(--space-xs) var(--space-sm); display: flex; justify-content: space-between; align-items: center;">
                    <div style="font-size: 0.75rem; color: var(--color-text-secondary);">
                        <?php if ($photo['tag_count'] > 0): ?>
                        <span style="color: var(--color-accent);">
                            <i data-lucide="users" class="icon-xs" style="vertical-align: text-bottom;"></i>
                            <?= $photo['tag_count'] ?> taggad<?= $photo['tag_count'] > 1 ? 'e' : '' ?>
                        </span>
                        <?php else: ?>
                        <span style="color: var(--color-text-muted);">Inga taggar</span>
                        <?php endif; ?>
                    </div>
                    <button type="button" class="btn-icon" style="padding: 2px; background: none; border: none; cursor: pointer; color: var(--color-text-muted);" onclick="event.stopPropagation(); deletePhoto(<?= $photo['id'] ?>)" title="Radera">
                        <i data-lucide="trash-2" style="width: 14px; height: 14px;"></i>
                    </button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>

<!-- Tag Modal -->
<div id="tagModal" style="display: none; position: fixed; inset: 0; z-index: 9999; background: rgba(0,0,0,0.7);">
    <div style="position: absolute; inset: var(--space-lg); background: var(--color-bg-surface); border-radius: var(--radius-lg); display: flex; flex-direction: column; max-width: 900px; margin: auto; max-height: calc(100vh - 48px);">
        <div style="display: flex; justify-content: space-between; align-items: center; padding: var(--space-md) var(--space-lg); border-bottom: 1px solid var(--color-border); flex-shrink: 0;">
            <h3 style="margin: 0;">Tagga deltagare</h3>
            <button onclick="closeTagModal()" style="background: none; border: none; cursor: pointer; padding: var(--space-xs); color: var(--color-text-secondary);">
                <i data-lucide="x" style="width: 24px; height: 24px;"></i>
            </button>
        </div>
        <div style="display: grid; grid-template-columns: 1fr 300px; gap: var(--space-lg); padding: var(--space-lg); overflow-y: auto; flex: 1;">
            <!-- Photo preview -->
            <div>
                <img id="tagModalImg" src="" alt="" style="max-width: 100%; max-height: 500px; object-fit: contain; border-radius: var(--radius-sm);">
            </div>
            <!-- Tag panel -->
            <div>
                <div style="margin-bottom: var(--space-md);">
                    <label class="admin-form-label">Sök deltagare</label>
                    <input type="text" id="tagSearchInput" class="form-input" placeholder="Namn..." oninput="searchRiders(this.value)" autocomplete="off">
                    <div id="tagSearchResults" style="margin-top: var(--space-xs);"></div>
                </div>

                <div>
                    <label class="admin-form-label">Taggade deltagare</label>
                    <div id="tagList" style="display: flex; flex-direction: column; gap: var(--space-xs);"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php endif; ?>

<script>
let currentPhotoId = null;
let searchTimeout = null;

function openTagModal(photoId, imgSrc) {
    currentPhotoId = photoId;
    document.getElementById('tagModalImg').src = imgSrc;
    document.getElementById('tagModal').style.display = 'block';
    document.getElementById('tagSearchInput').value = '';
    document.getElementById('tagSearchResults').innerHTML = '';
    loadTags(photoId);
}

function closeTagModal() {
    document.getElementById('tagModal').style.display = 'none';
    currentPhotoId = null;
}

document.getElementById('tagModal').addEventListener('click', function(e) {
    if (e.target === this) closeTagModal();
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeTagModal();
});

async function loadTags(photoId) {
    const list = document.getElementById('tagList');
    list.innerHTML = '<div style="color: var(--color-text-muted); font-size: 0.8rem;">Laddar...</div>';

    try {
        const response = await fetch('/api/photo-tags.php?photo_id=' + photoId);
        const result = await response.json();

        if (result.success && result.data.length > 0) {
            list.innerHTML = result.data.map(tag =>
                '<div style="display: flex; align-items: center; justify-content: space-between; padding: var(--space-xs) var(--space-sm); background: var(--color-bg-hover); border-radius: var(--radius-sm); font-size: 0.85rem;">' +
                    '<a href="/rider/' + tag.rider_id + '" target="_blank" style="color: var(--color-accent-text); text-decoration: none;">' +
                        (tag.firstname || '') + ' ' + (tag.lastname || '') +
                    '</a>' +
                    '<button onclick="removeTag(' + tag.tag_id + ')" style="background: none; border: none; cursor: pointer; color: var(--color-text-muted); padding: 2px;" title="Ta bort">' +
                        '<i data-lucide="x" style="width: 14px; height: 14px;"></i>' +
                    '</button>' +
                '</div>'
            ).join('');
        } else {
            list.innerHTML = '<div style="color: var(--color-text-muted); font-size: 0.8rem;">Inga taggade deltagare</div>';
        }

        if (typeof lucide !== 'undefined') lucide.createIcons();
    } catch (e) {
        list.innerHTML = '<div style="color: var(--color-error); font-size: 0.8rem;">Kunde inte ladda taggar</div>';
    }
}

function searchRiders(query) {
    clearTimeout(searchTimeout);
    const results = document.getElementById('tagSearchResults');

    if (query.length < 2) {
        results.innerHTML = '';
        return;
    }

    searchTimeout = setTimeout(async () => {
        try {
            const response = await fetch('/api/search.php?type=riders&q=' + encodeURIComponent(query) + '&limit=8');
            const data = await response.json();
            const riders = data.results || data.data || data;

            if (Array.isArray(riders) && riders.length > 0) {
                results.innerHTML = riders.map(r =>
                    '<div onclick="tagRider(' + r.id + ', \'' + ((r.firstname || '') + ' ' + (r.lastname || '')).replace(/'/g, "\\'") + '\')" ' +
                    'style="padding: var(--space-xs) var(--space-sm); cursor: pointer; font-size: 0.85rem; border-radius: var(--radius-sm); display: flex; justify-content: space-between; align-items: center;" ' +
                    'onmouseover="this.style.background=\'var(--color-bg-hover)\'" onmouseout="this.style.background=\'none\'">' +
                        '<span>' + (r.firstname || '') + ' ' + (r.lastname || '') + '</span>' +
                        '<span style="font-size: 0.7rem; color: var(--color-text-muted);">' + (r.club_name || r.club || '') + '</span>' +
                    '</div>'
                ).join('');
            } else {
                results.innerHTML = '<div style="font-size: 0.8rem; color: var(--color-text-muted); padding: var(--space-xs);">Inga träffar</div>';
            }
        } catch (e) {
            results.innerHTML = '';
        }
    }, 300);
}

async function tagRider(riderId, name) {
    if (!currentPhotoId) return;

    try {
        const formData = new FormData();
        formData.append('action', 'tag_rider');
        formData.append('photo_id', currentPhotoId);
        formData.append('rider_id', riderId);

        const response = await fetch('/admin/event-albums.php', {
            method: 'POST',
            body: formData
        });
        const result = await response.json();

        if (result.success) {
            document.getElementById('tagSearchInput').value = '';
            document.getElementById('tagSearchResults').innerHTML = '';
            loadTags(currentPhotoId);
        } else {
            alert(result.error || 'Kunde inte tagga');
        }
    } catch (e) {
        alert('Fel vid taggning');
    }
}

async function removeTag(tagId) {
    try {
        const formData = new FormData();
        formData.append('action', 'remove_tag');
        formData.append('tag_id', tagId);

        const response = await fetch('/admin/event-albums.php', {
            method: 'POST',
            body: formData
        });
        const result = await response.json();

        if (result.success && currentPhotoId) {
            loadTags(currentPhotoId);
        }
    } catch (e) {
        console.error('Remove tag error:', e);
    }
}

async function deletePhoto(photoId) {
    if (!confirm('Radera denna bild?')) return;

    try {
        const formData = new FormData();
        formData.append('action', 'delete_photo');
        formData.append('photo_id', photoId);

        const response = await fetch('/admin/event-albums.php', {
            method: 'POST',
            body: formData
        });
        const result = await response.json();

        if (result.success) {
            const card = document.querySelector('.photo-card[data-photo-id="' + photoId + '"]');
            if (card) card.remove();
        } else {
            alert(result.error || 'Kunde inte radera');
        }
    } catch (e) {
        alert('Fel vid radering');
    }
}

// =================================================================
// Chunked Upload System - laddar upp bilder en åt gången via AJAX
// =================================================================
let uploadCancelled = false;
const ALBUM_ID = <?= json_encode($albumId) ?>;

(function() {
    const fileInput = document.getElementById('r2FileInput');
    const uploadBtn = document.getElementById('r2UploadBtn');
    if (!fileInput || !uploadBtn) return;

    fileInput.addEventListener('change', function() {
        uploadBtn.disabled = this.files.length === 0;
        if (this.files.length > 0) {
            uploadBtn.textContent = '';
            uploadBtn.innerHTML = '<i data-lucide="upload" class="icon-sm"></i> Ladda upp ' + this.files.length + ' bilder';
            if (typeof lucide !== 'undefined') lucide.createIcons({nodes: [uploadBtn]});
        }
    });
})();

async function uploadOneFile(file) {
    const formData = new FormData();
    formData.append('photo', file);
    formData.append('album_id', ALBUM_ID);

    const response = await fetch('/api/upload-album-photo.php', {
        method: 'POST',
        body: formData
    });

    if (!response.ok) {
        throw new Error('HTTP ' + response.status);
    }

    const result = await response.json();
    if (!result.success) {
        throw new Error(result.error || 'Okänt fel');
    }
    return result;
}

async function startChunkedUpload() {
    const CONCURRENT = 3; // Antal parallella uppladdningar
    const fileInput = document.getElementById('r2FileInput');
    const files = Array.from(fileInput.files);
    if (files.length === 0) return;

    uploadCancelled = false;

    // Visa progress UI
    const progressDiv = document.getElementById('uploadProgress');
    const statusText = document.getElementById('uploadStatusText');
    const percentText = document.getElementById('uploadPercent');
    const bar = document.getElementById('uploadBar');
    const countText = document.getElementById('uploadCount');
    const speedText = document.getElementById('uploadSpeed');
    const etaText = document.getElementById('uploadEta');
    const errorsDiv = document.getElementById('uploadErrors');
    const cancelBtn = document.getElementById('uploadCancelBtn');
    const uploadBtn = document.getElementById('r2UploadBtn');

    progressDiv.style.display = 'block';
    cancelBtn.style.display = 'inline-flex';
    uploadBtn.disabled = true;
    fileInput.disabled = true;
    errorsDiv.style.display = 'none';
    errorsDiv.innerHTML = '';

    const totalFiles = files.length;
    let uploaded = 0;
    let failed = 0;
    let processed = 0;
    let errors = [];
    const startTime = Date.now();

    function updateProgress() {
        const done = uploaded + failed;
        const pct = Math.round((done / totalFiles) * 100);
        bar.style.width = pct + '%';
        percentText.textContent = pct + '%';
        countText.textContent = done + ' / ' + totalFiles + ' bilder';

        const elapsed = (Date.now() - startTime) / 1000;
        if (done > 0 && elapsed > 0) {
            const perImage = elapsed / done;
            const remaining = ((totalFiles - done) / CONCURRENT) * perImage;
            speedText.textContent = (perImage / CONCURRENT).toFixed(1) + 's/bild';
            if (remaining > 60) {
                etaText.textContent = 'ca ' + Math.ceil(remaining / 60) + ' min kvar';
            } else {
                etaText.textContent = 'ca ' + Math.ceil(remaining) + 's kvar';
            }
        }
    }

    // Bearbeta i parallella batchar om CONCURRENT
    let index = 0;

    async function processNext() {
        while (index < totalFiles && !uploadCancelled) {
            const i = index++;
            const file = files[i];
            statusText.textContent = 'Laddar upp (' + CONCURRENT + ' parallella)...';
            updateProgress();

            try {
                await uploadOneFile(file);
                uploaded++;
            } catch (e) {
                failed++;
                errors.push(file.name + ': ' + e.message);
            }
            updateProgress();
        }
    }

    // Starta CONCURRENT parallella workers
    const workers = [];
    for (let w = 0; w < CONCURRENT; w++) {
        workers.push(processNext());
    }
    await Promise.all(workers);

    // Klar
    bar.style.width = '100%';
    percentText.textContent = '100%';
    cancelBtn.style.display = 'none';
    speedText.textContent = '';
    etaText.textContent = '';

    if (uploadCancelled) {
        statusText.textContent = 'Avbruten. ' + uploaded + ' av ' + totalFiles + ' bilder uppladdade.';
        bar.style.background = 'var(--color-warning)';
    } else if (failed > 0) {
        statusText.textContent = uploaded + ' bilder uppladdade, ' + failed + ' misslyckades.';
        bar.style.background = 'var(--color-warning)';
    } else {
        statusText.textContent = uploaded + ' bilder uppladdade!';
        bar.style.background = 'var(--color-success)';
    }

    countText.textContent = uploaded + ' / ' + totalFiles + ' bilder';

    if (errors.length > 0) {
        errorsDiv.style.display = 'block';
        errorsDiv.innerHTML = '<strong>Fel:</strong><br>' + errors.map(e => '• ' + e).join('<br>');
    }

    // Återställ filväljare
    fileInput.disabled = false;
    fileInput.value = '';
    uploadBtn.disabled = true;
    uploadBtn.innerHTML = '<i data-lucide="upload" class="icon-sm"></i> Ladda upp till R2';
    if (typeof lucide !== 'undefined') lucide.createIcons({nodes: [uploadBtn]});

    // Ladda om sidan efter 2 sekunder för att visa nya bilder
    if (uploaded > 0) {
        setTimeout(() => {
            window.location.reload();
        }, 2000);
    }
}

function cancelUpload() {
    if (confirm('Avbryta uppladdningen? Redan uppladdade bilder behålls.')) {
        uploadCancelled = true;
    }
}
</script>

<style>
@media (max-width: 767px) {
    #tagModal > div {
        inset: 0 !important;
        border-radius: 0 !important;
        max-height: 100vh !important;
    }
    #tagModal > div > div:last-child {
        grid-template-columns: 1fr !important;
    }
}
</style>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>

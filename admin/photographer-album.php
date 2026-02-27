<?php
/**
 * Photographer Album Management
 * Fotografens vy för att hantera bilder i ett album
 * Uppladdning via chunked AJAX (samma som event-albums.php)
 * Fotografer kan INTE radera album - bara admin kan det
 */
set_time_limit(300);
ini_set('memory_limit', '256M');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/r2-storage.php';
require_admin();

// Kräv minst photographer-roll
if (!hasRole('photographer')) {
    set_flash('error', 'Du har inte behörighet till denna sida');
    redirect('/');
}

global $pdo;
$currentUser = getCurrentAdmin();
$userId = $currentUser['id'] ?? 0;
$isAdmin = hasRole('admin');

$albumId = (int)($_GET['album_id'] ?? 0);

if (!$albumId) {
    redirect('/admin/photographer-dashboard.php');
}

// Kolla behörighet - fotografer kan bara se sina album
if (!$isAdmin && !canAccessAlbum($albumId)) {
    set_flash('error', 'Du har inte behörighet till detta album');
    redirect('/admin/photographer-dashboard.php');
}

// Hämta album
$stmt = $pdo->prepare("
    SELECT ea.*, e.name as event_name, e.date as event_date, e.location as event_location,
           p.name as photographer_name
    FROM event_albums ea
    JOIN events e ON ea.event_id = e.id
    LEFT JOIN photographers p ON ea.photographer_id = p.id
    WHERE ea.id = ?
");
$stmt->execute([$albumId]);
$album = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$album) {
    redirect('/admin/photographer-dashboard.php');
}

// Hantera POST-åtgärder
$message = '';
$messageType = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['action'] ?? '';

    // Uppdatera album-info (titel, publicering)
    if ($postAction === 'update_album') {
        $title = trim($_POST['title'] ?? '');
        $isPublished = isset($_POST['is_published']) ? 1 : 0;
        $description = trim($_POST['description'] ?? '');

        try {
            $stmt = $pdo->prepare("
                UPDATE event_albums SET title = ?, description = ?, is_published = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$title ?: null, $description ?: null, $isPublished, $albumId]);
            $message = 'Albumet uppdaterat';
            $messageType = 'success';

            // Uppdatera lokalt
            $album['title'] = $title;
            $album['description'] = $description;
            $album['is_published'] = $isPublished;
        } catch (PDOException $e) {
            $message = 'Kunde inte uppdatera: ' . $e->getMessage();
            $messageType = 'danger';
        }
    }

    // Radera foto (fotografer FÅR radera enskilda foton men INTE hela album)
    if ($postAction === 'delete_photo') {
        $photoId = (int)($_POST['photo_id'] ?? 0);
        if ($photoId) {
            try {
                // Hämta R2-nyckel
                $stmt = $pdo->prepare("SELECT r2_key FROM event_photos WHERE id = ? AND album_id = ?");
                $stmt->execute([$photoId, $albumId]);
                $r2Key = $stmt->fetchColumn();

                // Radera från R2
                if ($r2Key) {
                    $r2 = R2Storage::getInstance();
                    if ($r2) {
                        $r2->deleteObject($r2Key);
                        $r2->deleteObject('thumbs/' . $r2Key);
                    }
                }

                // Radera från databas
                $pdo->prepare("DELETE FROM event_photos WHERE id = ? AND album_id = ?")->execute([$photoId, $albumId]);
                $pdo->prepare("UPDATE event_albums SET photo_count = (SELECT COUNT(*) FROM event_photos WHERE album_id = ?) WHERE id = ?")
                    ->execute([$albumId, $albumId]);

                $message = 'Bilden borttagen';
                $messageType = 'success';
            } catch (PDOException $e) {
                $message = 'Kunde inte radera: ' . $e->getMessage();
                $messageType = 'danger';
            }
        }
    }

    // Sätt cover-foto
    if ($postAction === 'set_cover') {
        $photoId = (int)($_POST['photo_id'] ?? 0);
        if ($photoId) {
            try {
                $pdo->prepare("UPDATE event_albums SET cover_photo_id = ? WHERE id = ?")->execute([$photoId, $albumId]);
                $message = 'Omslagsbild vald';
                $messageType = 'success';
                $album['cover_photo_id'] = $photoId;
            } catch (PDOException $e) {
                $message = 'Kunde inte sätta omslag: ' . $e->getMessage();
                $messageType = 'danger';
            }
        }
    }
}

// Hämta bilder
$photos = $pdo->prepare("
    SELECT ep.*,
           GROUP_CONCAT(CONCAT(r.firstname, ' ', r.lastname) ORDER BY r.lastname SEPARATOR ', ') as tagged_riders
    FROM event_photos ep
    LEFT JOIN photo_rider_tags prt ON prt.photo_id = ep.id
    LEFT JOIN riders r ON prt.rider_id = r.id
    WHERE ep.album_id = ?
    GROUP BY ep.id
    ORDER BY ep.sort_order ASC, ep.id ASC
");
$photos->execute([$albumId]);
$photos = $photos->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Album: ' . ($album['title'] ?: $album['event_name']);
include __DIR__ . '/components/unified-layout.php';
?>

<!-- Tillbaka -->
<div style="margin-bottom: var(--space-md);">
    <a href="/admin/photographer-dashboard.php" class="btn btn-ghost">
        <i data-lucide="arrow-left" style="width: 16px; height: 16px;"></i> Tillbaka till mina album
    </a>
</div>

<?php if ($message): ?>
<div class="alert alert-<?= $messageType ?>" style="margin-bottom: var(--space-md);"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<!-- Album-info -->
<div class="card" style="margin-bottom: var(--space-md);">
    <div class="card-header">
        <h3><?= htmlspecialchars($album['title'] ?: $album['event_name']) ?></h3>
    </div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="action" value="update_album">
            <div style="display: grid; grid-template-columns: 1fr 1fr auto; gap: var(--space-md); align-items: end;">
                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label">Albumtitel</label>
                    <input type="text" name="title" class="form-input" value="<?= htmlspecialchars($album['title'] ?? '') ?>" placeholder="<?= htmlspecialchars($album['event_name']) ?>">
                </div>
                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label">Beskrivning</label>
                    <input type="text" name="description" class="form-input" value="<?= htmlspecialchars($album['description'] ?? '') ?>" placeholder="Valfri beskrivning...">
                </div>
                <div style="display: flex; align-items: center; gap: var(--space-sm);">
                    <label style="display: flex; align-items: center; gap: var(--space-xs); cursor: pointer; white-space: nowrap;">
                        <input type="checkbox" name="is_published" value="1" <?= $album['is_published'] ? 'checked' : '' ?>>
                        Publicerat
                    </label>
                    <button type="submit" class="btn btn-primary" style="white-space: nowrap;">Spara</button>
                </div>
            </div>
        </form>
        <div style="margin-top: var(--space-sm); font-size: 0.8rem; color: var(--color-text-muted);">
            <i data-lucide="calendar" style="width: 13px; height: 13px; vertical-align: -2px;"></i>
            <?= htmlspecialchars($album['event_name']) ?> &mdash;
            <?= date('Y-m-d', strtotime($album['event_date'])) ?>
            <?php if ($album['event_location']): ?>
            &mdash; <?= htmlspecialchars($album['event_location']) ?>
            <?php endif; ?>
            &mdash; <?= count($photos) ?> bilder
        </div>
    </div>
</div>

<!-- Uppladdning -->
<div class="card" style="margin-bottom: var(--space-md);">
    <div class="card-header">
        <h3><i data-lucide="upload" style="width: 18px; height: 18px; vertical-align: -3px;"></i> Ladda upp bilder</h3>
    </div>
    <div class="card-body">
        <div style="display: flex; gap: var(--space-sm); align-items: center; flex-wrap: wrap;">
            <label class="btn btn-secondary" style="cursor: pointer;" id="fileSelectLabel">
                <i data-lucide="image-plus" style="width: 16px; height: 16px;"></i> Välj bilder
                <input type="file" id="photoFiles" multiple accept="image/jpeg,image/png,image/webp,image/gif" style="display: none;" onchange="updateFileCount()">
            </label>
            <button type="button" class="btn btn-primary" id="startUploadBtn" onclick="startChunkedUpload()" disabled>
                <i data-lucide="upload" style="width: 16px; height: 16px;"></i> Ladda upp
            </button>
            <button type="button" class="btn btn-ghost" id="cancelUploadBtn" onclick="cancelUpload()" style="display: none;">
                Avbryt
            </button>
            <span id="fileCount" style="font-size: 0.85rem; color: var(--color-text-muted);"></span>
        </div>
        <div id="uploadProgress" style="display: none; margin-top: var(--space-md);">
            <div style="background: var(--color-bg-hover); border-radius: var(--radius-full); height: 20px; overflow: hidden; margin-bottom: var(--space-xs);">
                <div id="uploadBar" style="background: var(--color-accent); height: 100%; width: 0%; transition: width 0.3s; border-radius: var(--radius-full);"></div>
            </div>
            <div id="uploadStatus" style="font-size: 0.8rem; color: var(--color-text-muted);"></div>
        </div>
    </div>
</div>

<!-- Bildgrid -->
<?php if (!empty($photos)): ?>
<div class="card">
    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
        <h3>Bilder (<?= count($photos) ?>)</h3>
    </div>
    <div class="card-body">
        <div class="photographer-photo-grid">
            <?php foreach ($photos as $photo):
                $thumbSrc = $photo['thumbnail_url'] ?: $photo['external_url'] ?: '';
                $isCover = ($album['cover_photo_id'] == $photo['id']);
            ?>
            <div class="photographer-photo-item <?= $isCover ? 'is-cover' : '' ?>">
                <?php if ($thumbSrc): ?>
                <img src="<?= htmlspecialchars($thumbSrc) ?>" alt="" loading="lazy">
                <?php else: ?>
                <div class="photographer-photo-placeholder">
                    <i data-lucide="image" style="width: 24px; height: 24px; color: var(--color-text-muted);"></i>
                </div>
                <?php endif; ?>

                <?php if ($isCover): ?>
                <div class="photographer-photo-cover-badge">Omslag</div>
                <?php endif; ?>

                <?php if ($photo['tagged_riders']): ?>
                <div class="photographer-photo-tags" title="<?= htmlspecialchars($photo['tagged_riders']) ?>">
                    <i data-lucide="user" style="width: 10px; height: 10px;"></i>
                    <?= substr_count($photo['tagged_riders'], ',') + 1 ?>
                </div>
                <?php endif; ?>

                <div class="photographer-photo-actions">
                    <?php if (!$isCover): ?>
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="action" value="set_cover">
                        <input type="hidden" name="photo_id" value="<?= $photo['id'] ?>">
                        <button type="submit" class="btn btn-ghost" style="padding: 2px 6px; font-size: 0.7rem;" title="Sätt som omslag">
                            <i data-lucide="star" style="width: 12px; height: 12px;"></i>
                        </button>
                    </form>
                    <?php endif; ?>
                    <form method="POST" style="display: inline;" onsubmit="return confirm('Radera denna bild?')">
                        <input type="hidden" name="action" value="delete_photo">
                        <input type="hidden" name="photo_id" value="<?= $photo['id'] ?>">
                        <button type="submit" class="btn btn-ghost" style="padding: 2px 6px; font-size: 0.7rem; color: var(--color-error);" title="Radera">
                            <i data-lucide="trash-2" style="width: 12px; height: 12px;"></i>
                        </button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<style>
.photographer-photo-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
    gap: var(--space-sm);
}
.photographer-photo-item {
    position: relative;
    aspect-ratio: 1;
    overflow: hidden;
    border-radius: var(--radius-sm);
    background: var(--color-bg-hover);
    border: 2px solid transparent;
}
.photographer-photo-item.is-cover {
    border-color: var(--color-accent);
}
.photographer-photo-item img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.photographer-photo-placeholder {
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
}
.photographer-photo-cover-badge {
    position: absolute;
    top: 4px;
    left: 4px;
    background: var(--color-accent);
    color: #000;
    padding: 1px 6px;
    border-radius: var(--radius-full);
    font-size: 0.65rem;
    font-weight: 700;
    text-transform: uppercase;
}
.photographer-photo-tags {
    position: absolute;
    top: 4px;
    right: 4px;
    background: rgba(0,0,0,0.7);
    color: #fff;
    padding: 1px 6px;
    border-radius: var(--radius-full);
    font-size: 0.65rem;
    display: flex;
    align-items: center;
    gap: 2px;
}
.photographer-photo-actions {
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    background: linear-gradient(transparent, rgba(0,0,0,0.7));
    padding: 16px 4px 4px;
    display: flex;
    justify-content: center;
    gap: 4px;
    opacity: 0;
    transition: opacity 0.2s;
}
.photographer-photo-item:hover .photographer-photo-actions {
    opacity: 1;
}

@media (max-width: 767px) {
    .photographer-photo-grid {
        grid-template-columns: repeat(3, 1fr);
        gap: var(--space-2xs);
    }
    .photographer-photo-actions {
        opacity: 1;
    }
}
</style>

<script>
let uploadCancelled = false;

function updateFileCount() {
    const input = document.getElementById('photoFiles');
    const label = document.getElementById('fileSelectLabel');
    const btn = document.getElementById('startUploadBtn');
    const count = document.getElementById('fileCount');

    if (input.files.length > 0) {
        count.textContent = input.files.length + ' bilder valda';
        btn.disabled = false;
    } else {
        count.textContent = '';
        btn.disabled = true;
    }
}

async function startChunkedUpload() {
    const input = document.getElementById('photoFiles');
    const files = Array.from(input.files);
    if (!files.length) return;

    uploadCancelled = false;
    const progressDiv = document.getElementById('uploadProgress');
    const bar = document.getElementById('uploadBar');
    const status = document.getElementById('uploadStatus');
    const startBtn = document.getElementById('startUploadBtn');
    const cancelBtn = document.getElementById('cancelUploadBtn');

    progressDiv.style.display = 'block';
    startBtn.disabled = true;
    cancelBtn.style.display = 'inline-flex';

    let uploaded = 0;
    let errors = 0;
    const total = files.length;
    const startTime = Date.now();

    for (let i = 0; i < total; i++) {
        if (uploadCancelled) break;

        const fd = new FormData();
        fd.append('album_id', '<?= $albumId ?>');
        fd.append('photo', files[i]);

        try {
            const res = await fetch('/api/upload-album-photo.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.success) {
                uploaded++;
            } else {
                errors++;
            }
        } catch (e) {
            errors++;
        }

        const pct = Math.round(((i + 1) / total) * 100);
        bar.style.width = pct + '%';

        const elapsed = (Date.now() - startTime) / 1000;
        const perImage = elapsed / (i + 1);
        const remaining = Math.round(perImage * (total - i - 1));

        status.textContent = `${uploaded}/${total} uppladdade` +
            (errors ? `, ${errors} fel` : '') +
            ` — ${pct}%` +
            (remaining > 0 ? ` — ~${remaining}s kvar` : '');
    }

    cancelBtn.style.display = 'none';
    startBtn.disabled = false;

    if (uploaded > 0) {
        status.textContent = `Klart! ${uploaded} bilder uppladdade` + (errors ? `, ${errors} fel` : '') + '. Laddar om...';
        setTimeout(() => location.reload(), 1500);
    } else {
        status.textContent = 'Inga bilder kunde laddas upp. Kontrollera filerna och försök igen.';
    }
}

function cancelUpload() {
    uploadCancelled = true;
    document.getElementById('cancelUploadBtn').style.display = 'none';
    document.getElementById('uploadStatus').textContent += ' (Avbruten)';
}
</script>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>

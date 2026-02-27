<?php
/**
 * Photographer Dashboard
 * Fotografens egen vy för att hantera profil, album och bilder
 * Liknande promotor.php men anpassat för fotografer
 */

require_once __DIR__ . '/../config.php';
require_admin();

// Kräv minst photographer-roll
if (!hasRole('photographer')) {
    set_flash('error', 'Du har inte behörighet till denna sida');
    redirect('/');
}

$db = getDB();
$pdo = $db->getConnection();
$currentUser = getCurrentAdmin();
$userId = $currentUser['id'] ?? 0;
$isAdmin = hasRole('admin');

// Hämta kopplad fotografprofil
$photographer = null;
try {
    $stmt = $pdo->prepare("SELECT * FROM photographers WHERE admin_user_id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $photographer = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Tabellen kanske inte finns ännu
}

// ============================================================
// AJAX: Spara profil
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $postAction = $_POST['action'] ?? '';

    if ($postAction === 'save_profile' && $photographer) {
        $name = trim($_POST['name'] ?? '');
        $bio = trim($_POST['bio'] ?? '');
        $avatar_url = trim($_POST['avatar_url'] ?? '');
        $website_url = trim($_POST['website_url'] ?? '');
        $instagram_url = trim($_POST['instagram_url'] ?? '');
        $facebook_url = trim($_POST['facebook_url'] ?? '');
        $youtube_url = trim($_POST['youtube_url'] ?? '');

        if (!$name) {
            echo json_encode(['success' => false, 'error' => 'Namn krävs']);
            exit;
        }

        try {
            $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', transliterator_transliterate('Any-Latin; Latin-ASCII', $name)), '-'));
            if (!$slug) $slug = 'photographer-' . $photographer['id'];

            $stmt = $pdo->prepare("
                UPDATE photographers SET
                    name = ?, slug = ?, bio = ?, avatar_url = ?,
                    website_url = ?, instagram_url = ?, facebook_url = ?, youtube_url = ?
                WHERE id = ? AND admin_user_id = ?
            ");
            $stmt->execute([$name, $slug, $bio ?: null, $avatar_url ?: null,
                $website_url ?: null, $instagram_url ?: null, $facebook_url ?: null,
                $youtube_url ?: null, $photographer['id'], $userId]);
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => 'Kunde inte spara: ' . $e->getMessage()]);
        }
        exit;
    }

    if ($postAction === 'create_album' && $photographer) {
        $eventId = intval($_POST['event_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');

        if (!$eventId) {
            echo json_encode(['success' => false, 'error' => 'Välj ett event']);
            exit;
        }

        try {
            $stmt = $pdo->prepare("
                INSERT INTO event_albums (event_id, title, photographer_id, photographer, is_published)
                VALUES (?, ?, ?, ?, 0)
            ");
            $stmt->execute([$eventId, $title ?: null, $photographer['id'], $photographer['name']]);
            $albumId = (int)$pdo->lastInsertId();

            // Koppla albumet till fotografen
            $pdo->prepare("INSERT INTO photographer_albums (user_id, album_id, can_upload, can_edit) VALUES (?, ?, 1, 1)")
                ->execute([$userId, $albumId]);

            echo json_encode(['success' => true, 'album_id' => $albumId]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => 'Kunde inte skapa album: ' . $e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Okänd åtgärd']);
    exit;
}

// Aktiv flik
$tab = $_GET['tab'] ?? 'albums';

// Hämta fotografens album
$albums = [];
if ($photographer) {
    try {
        $stmt = $pdo->prepare("
            SELECT ea.*, e.name as event_name, e.date as event_date, e.location as event_location,
                   pa.can_upload, pa.can_edit,
                   (SELECT COUNT(*) FROM event_photos ep WHERE ep.album_id = ea.id) as actual_photo_count,
                   cover.external_url as cover_url, cover.thumbnail_url as cover_thumb,
                   cover_media.filepath as cover_filepath
            FROM event_albums ea
            JOIN photographer_albums pa ON ea.id = pa.album_id
            JOIN events e ON ea.event_id = e.id
            LEFT JOIN event_photos cover ON cover.id = ea.cover_photo_id
            LEFT JOIN media cover_media ON cover.media_id = cover_media.id
            WHERE pa.user_id = ?
            ORDER BY e.date DESC, ea.created_at DESC
        ");
        $stmt->execute([$userId]);
        $albums = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Tabellerna kanske inte finns
    }
}

// Stats
$totalAlbums = count($albums);
$totalPhotos = array_sum(array_column($albums, 'actual_photo_count'));
$publishedAlbums = count(array_filter($albums, fn($a) => $a['is_published']));

// Hämta events för nytt album-skapande
$events = [];
try {
    $events = $pdo->query("
        SELECT id, name, date, location
        FROM events
        WHERE date >= DATE_SUB(NOW(), INTERVAL 2 YEAR)
        ORDER BY date DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

$pageTitle = 'Fotograf';
include __DIR__ . '/components/unified-layout.php';
?>

<?php if (!$photographer): ?>
<!-- Ingen profil kopplad -->
<div class="card">
    <div style="padding: var(--space-2xl); text-align: center;">
        <i data-lucide="camera-off" style="width: 48px; height: 48px; color: var(--color-text-muted); margin-bottom: var(--space-md);"></i>
        <h2 style="color: var(--color-text-primary); margin-bottom: var(--space-sm);">Ingen fotografprofil kopplad</h2>
        <p style="color: var(--color-text-muted);">Kontakta admin för att koppla ditt konto till en fotografprofil.</p>
    </div>
</div>
<?php else: ?>

<!-- Tabs -->
<div class="card" style="margin-bottom: var(--space-md); padding: var(--space-sm) var(--space-md) 0;">
    <div class="tabs-nav" style="margin-bottom: 0;">
        <a href="?tab=albums" class="tab-pill <?= $tab === 'albums' ? 'active' : '' ?>">
            <i data-lucide="image"></i> Mina album
        </a>
        <a href="?tab=profile" class="tab-pill <?= $tab === 'profile' ? 'active' : '' ?>">
            <i data-lucide="user"></i> Min profil
        </a>
        <a href="/photographer/<?= $photographer['id'] ?>" class="tab-pill" target="_blank">
            <i data-lucide="external-link"></i> Publik profil
        </a>
    </div>
</div>

<?php if ($tab === 'profile'): ?>
<!-- ============================================================ -->
<!-- PROFIL-FLIK -->
<!-- ============================================================ -->
<div class="card">
    <div class="card-header">
        <h3>Redigera profil</h3>
    </div>
    <div class="card-body">
        <form id="profileForm" onsubmit="saveProfile(event)">
            <div id="profileMessage"></div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--space-md);">
                <div class="form-group">
                    <label class="form-label">Namn *</label>
                    <input type="text" name="name" class="form-input" value="<?= htmlspecialchars($photographer['name'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label">E-post</label>
                    <input type="email" class="form-input" value="<?= htmlspecialchars($photographer['email'] ?? '') ?>" disabled>
                    <small style="color: var(--color-text-muted);">Kontakta admin för att ändra e-post</small>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Biografi</label>
                <textarea name="bio" class="form-input" rows="4" placeholder="Berätta om dig som fotograf..."><?= htmlspecialchars($photographer['bio'] ?? '') ?></textarea>
            </div>

            <div class="form-group">
                <label class="form-label">Profilbild (URL)</label>
                <input type="url" name="avatar_url" class="form-input" value="<?= htmlspecialchars($photographer['avatar_url'] ?? '') ?>" placeholder="https://...">
                <?php if ($photographer['avatar_url']): ?>
                <div style="margin-top: var(--space-xs);">
                    <img src="<?= htmlspecialchars($photographer['avatar_url']) ?>" style="width: 64px; height: 64px; border-radius: 50%; object-fit: cover; border: 2px solid var(--color-border);">
                </div>
                <?php endif; ?>
            </div>

            <h4 style="margin: var(--space-lg) 0 var(--space-sm); font-family: var(--font-heading-secondary); color: var(--color-text-secondary);">
                <i data-lucide="link" style="width: 16px; height: 16px; vertical-align: -2px;"></i> Sociala medier
            </h4>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--space-md);">
                <div class="form-group">
                    <label class="form-label"><i data-lucide="globe" style="width: 14px; height: 14px; vertical-align: -2px;"></i> Webbplats</label>
                    <input type="url" name="website_url" class="form-input" value="<?= htmlspecialchars($photographer['website_url'] ?? '') ?>" placeholder="https://...">
                </div>
                <div class="form-group">
                    <label class="form-label"><i data-lucide="instagram" style="width: 14px; height: 14px; vertical-align: -2px;"></i> Instagram</label>
                    <input type="url" name="instagram_url" class="form-input" value="<?= htmlspecialchars($photographer['instagram_url'] ?? '') ?>" placeholder="https://instagram.com/...">
                </div>
                <div class="form-group">
                    <label class="form-label"><i data-lucide="facebook" style="width: 14px; height: 14px; vertical-align: -2px;"></i> Facebook</label>
                    <input type="url" name="facebook_url" class="form-input" value="<?= htmlspecialchars($photographer['facebook_url'] ?? '') ?>" placeholder="https://facebook.com/...">
                </div>
                <div class="form-group">
                    <label class="form-label"><i data-lucide="youtube" style="width: 14px; height: 14px; vertical-align: -2px;"></i> YouTube</label>
                    <input type="url" name="youtube_url" class="form-input" value="<?= htmlspecialchars($photographer['youtube_url'] ?? '') ?>" placeholder="https://youtube.com/...">
                </div>
            </div>

            <?php if ($photographer['rider_id']): ?>
            <div class="alert alert-info" style="margin-top: var(--space-md);">
                <i data-lucide="user" style="width: 16px; height: 16px;"></i>
                Du är kopplad till en deltagarprofil.
                <a href="/rider/<?= $photographer['rider_id'] ?>" target="_blank" style="color: var(--color-accent-text);">Visa deltagarprofil</a>
            </div>
            <?php endif; ?>

            <div style="margin-top: var(--space-lg);">
                <button type="submit" class="btn btn-primary" id="saveProfileBtn">
                    <i data-lucide="save" style="width: 16px; height: 16px;"></i> Spara profil
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function saveProfile(e) {
    e.preventDefault();
    const form = document.getElementById('profileForm');
    const fd = new FormData(form);
    fd.append('action', 'save_profile');

    const btn = document.getElementById('saveProfileBtn');
    btn.disabled = true;
    btn.textContent = 'Sparar...';

    fetch('/admin/photographer-dashboard.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            const msg = document.getElementById('profileMessage');
            if (data.success) {
                msg.className = 'alert alert-success';
                msg.textContent = 'Profilen sparad!';
                msg.style.display = 'block';
            } else {
                msg.className = 'alert alert-danger';
                msg.textContent = data.error || 'Kunde inte spara';
                msg.style.display = 'block';
            }
            btn.disabled = false;
            btn.innerHTML = '<i data-lucide="save" style="width:16px;height:16px;"></i> Spara profil';
            if (typeof lucide !== 'undefined') lucide.createIcons();
        })
        .catch(err => {
            const msg = document.getElementById('profileMessage');
            msg.className = 'alert alert-danger';
            msg.textContent = 'Nätverksfel';
            msg.style.display = 'block';
            btn.disabled = false;
            btn.innerHTML = '<i data-lucide="save" style="width:16px;height:16px;"></i> Spara profil';
        });
}
</script>

<?php else: ?>
<!-- ============================================================ -->
<!-- ALBUM-FLIK (default) -->
<!-- ============================================================ -->

<!-- Stats -->
<div class="card" style="margin-bottom: var(--space-lg);">
    <div style="display: grid; grid-template-columns: repeat(3, 1fr); text-align: center; padding: var(--space-md);">
        <div>
            <div style="font-size: 1.5rem; font-weight: 700; color: var(--color-accent-text); font-family: var(--font-heading);"><?= $totalAlbums ?></div>
            <div style="font-size: 0.75rem; color: var(--color-text-muted); text-transform: uppercase; letter-spacing: 0.5px;">Album</div>
        </div>
        <div>
            <div style="font-size: 1.5rem; font-weight: 700; color: var(--color-accent-text); font-family: var(--font-heading);"><?= number_format($totalPhotos) ?></div>
            <div style="font-size: 0.75rem; color: var(--color-text-muted); text-transform: uppercase; letter-spacing: 0.5px;">Bilder</div>
        </div>
        <div>
            <div style="font-size: 1.5rem; font-weight: 700; color: var(--color-accent-text); font-family: var(--font-heading);"><?= $publishedAlbums ?></div>
            <div style="font-size: 0.75rem; color: var(--color-text-muted); text-transform: uppercase; letter-spacing: 0.5px;">Publicerade</div>
        </div>
    </div>
</div>

<!-- Skapa nytt album -->
<div class="card" style="margin-bottom: var(--space-md);">
    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
        <h3>Skapa nytt album</h3>
    </div>
    <div class="card-body">
        <form id="createAlbumForm" onsubmit="createAlbum(event)" style="display: flex; flex-wrap: wrap; gap: var(--space-sm); align-items: flex-end;">
            <div class="form-group" style="flex: 2; min-width: 200px; margin-bottom: 0;">
                <label class="form-label">Event *</label>
                <select name="event_id" class="form-select" required>
                    <option value="">Välj event...</option>
                    <?php foreach ($events as $ev): ?>
                    <option value="<?= $ev['id'] ?>"><?= htmlspecialchars($ev['name']) ?> (<?= date('Y-m-d', strtotime($ev['date'])) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="flex: 1; min-width: 150px; margin-bottom: 0;">
                <label class="form-label">Albumtitel (valfritt)</label>
                <input type="text" name="title" class="form-input" placeholder="T.ex. Dag 1, Tävlingsbilder...">
            </div>
            <button type="submit" class="btn btn-primary" style="white-space: nowrap;">
                <i data-lucide="plus" style="width: 16px; height: 16px;"></i> Skapa album
            </button>
        </form>
        <div id="createAlbumMessage" style="display: none; margin-top: var(--space-sm);"></div>
    </div>
</div>

<!-- Albumlista -->
<?php if (empty($albums)): ?>
<div class="card">
    <div style="padding: var(--space-2xl); text-align: center;">
        <i data-lucide="image-off" style="width: 48px; height: 48px; color: var(--color-text-muted); margin-bottom: var(--space-md);"></i>
        <p style="color: var(--color-text-muted); font-size: 1rem;">Du har inga album ännu. Skapa ditt första album ovan!</p>
    </div>
</div>
<?php else: ?>
<div class="card">
    <div class="card-header">
        <h3>Mina album (<?= $totalAlbums ?>)</h3>
    </div>
    <div class="card-body" style="padding: 0;">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Album</th>
                        <th>Event</th>
                        <th>Bilder</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($albums as $album): ?>
                    <tr>
                        <td>
                            <strong><?= htmlspecialchars($album['title'] ?: $album['event_name']) ?></strong>
                            <?php if ($album['title'] && $album['title'] !== $album['event_name']): ?>
                            <div style="font-size: 0.75rem; color: var(--color-text-muted);"><?= htmlspecialchars($album['event_name']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span style="font-size: 0.85rem;"><?= date('Y-m-d', strtotime($album['event_date'])) ?></span>
                            <?php if ($album['event_location']): ?>
                            <div style="font-size: 0.75rem; color: var(--color-text-muted);"><?= htmlspecialchars($album['event_location']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?= $album['actual_photo_count'] ?></td>
                        <td>
                            <span class="badge <?= $album['is_published'] ? 'badge-success' : 'badge-warning' ?>">
                                <?= $album['is_published'] ? 'Publicerat' : 'Utkast' ?>
                            </span>
                        </td>
                        <td style="text-align: right;">
                            <?php if ($album['can_upload']): ?>
                            <a href="/admin/photographer-album.php?album_id=<?= $album['id'] ?>" class="btn btn-primary" style="padding: 4px 12px; font-size: 0.8rem;">
                                <i data-lucide="upload" style="width: 14px; height: 14px;"></i> Hantera
                            </a>
                            <?php endif; ?>
                            <a href="/event/<?= $album['event_id'] ?>?tab=gallery" target="_blank" class="btn btn-ghost" style="padding: 4px 8px; font-size: 0.8rem;" title="Visa publikt">
                                <i data-lucide="external-link" style="width: 14px; height: 14px;"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
function createAlbum(e) {
    e.preventDefault();
    const form = document.getElementById('createAlbumForm');
    const fd = new FormData(form);
    fd.append('action', 'create_album');

    fetch('/admin/photographer-dashboard.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            const msg = document.getElementById('createAlbumMessage');
            if (data.success) {
                window.location.href = '/admin/photographer-album.php?album_id=' + data.album_id;
            } else {
                msg.className = 'alert alert-danger';
                msg.textContent = data.error || 'Kunde inte skapa album';
                msg.style.display = 'block';
            }
        })
        .catch(err => {
            const msg = document.getElementById('createAlbumMessage');
            msg.className = 'alert alert-danger';
            msg.textContent = 'Nätverksfel';
            msg.style.display = 'block';
        });
}
</script>
<?php endif; ?>

<?php endif; ?>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>

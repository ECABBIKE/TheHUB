<?php
require_once __DIR__ . '/../config.php';
require_admin();

global $pdo;
$message = '';
$error = '';

// Handle actions
$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'save') {
        $id = intval($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $bio = trim($_POST['bio'] ?? '');
        $avatar_url = trim($_POST['avatar_url'] ?? '');
        $website_url = trim($_POST['website_url'] ?? '');
        $instagram_url = trim($_POST['instagram_url'] ?? '');
        $tiktok_url = trim($_POST['tiktok_url'] ?? '');
        $strava_url = trim($_POST['strava_url'] ?? '');
        $facebook_url = trim($_POST['facebook_url'] ?? '');
        $youtube_url = trim($_POST['youtube_url'] ?? '');
        $rider_id = intval($_POST['rider_id'] ?? 0) ?: null;
        $admin_user_id = intval($_POST['admin_user_id'] ?? 0) ?: null;
        $active = isset($_POST['active']) ? 1 : 0;

        // Generate slug
        $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', transliterator_transliterate('Any-Latin; Latin-ASCII', $name)), '-'));
        if (!$slug) $slug = 'photographer-' . time();

        if (!$name) {
            $error = 'Namn krävs';
        } else {
            // Check which columns exist (strava_url might not exist yet)
            $columns = [];
            try {
                $cols = $pdo->query("SHOW COLUMNS FROM photographers")->fetchAll(PDO::FETCH_COLUMN);
                $columns = array_flip($cols);
            } catch (PDOException $e) {}

            $hasStrava = isset($columns['strava_url']);

            if ($id > 0) {
                $sql = "UPDATE photographers SET
                    name = ?, slug = ?, email = ?, bio = ?, avatar_url = ?,
                    website_url = ?, instagram_url = ?, tiktok_url = ?, facebook_url = ?,
                    youtube_url = ?, rider_id = ?, admin_user_id = ?, active = ?";
                $params = [$name, $slug, $email, $bio, $avatar_url, $website_url, $instagram_url, $tiktok_url, $facebook_url, $youtube_url, $rider_id, $admin_user_id, $active];

                if ($hasStrava) {
                    $sql = "UPDATE photographers SET
                        name = ?, slug = ?, email = ?, bio = ?, avatar_url = ?,
                        website_url = ?, instagram_url = ?, tiktok_url = ?, strava_url = ?, facebook_url = ?,
                        youtube_url = ?, rider_id = ?, admin_user_id = ?, active = ?";
                    $params = [$name, $slug, $email, $bio, $avatar_url, $website_url, $instagram_url, $tiktok_url, $strava_url, $facebook_url, $youtube_url, $rider_id, $admin_user_id, $active];
                }

                $sql .= " WHERE id = ?";
                $params[] = $id;
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $message = 'Fotograf uppdaterad';
            } else {
                if ($hasStrava) {
                    $stmt = $pdo->prepare("
                        INSERT INTO photographers (name, slug, email, bio, avatar_url, website_url, instagram_url, tiktok_url, strava_url, facebook_url, youtube_url, rider_id, admin_user_id, active)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$name, $slug, $email, $bio, $avatar_url, $website_url, $instagram_url, $tiktok_url, $strava_url, $facebook_url, $youtube_url, $rider_id, $admin_user_id, $active]);
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO photographers (name, slug, email, bio, avatar_url, website_url, instagram_url, tiktok_url, facebook_url, youtube_url, rider_id, admin_user_id, active)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$name, $slug, $email, $bio, $avatar_url, $website_url, $instagram_url, $tiktok_url, $facebook_url, $youtube_url, $rider_id, $admin_user_id, $active]);
                }
                $id = $pdo->lastInsertId();
                $message = 'Fotograf skapad';
            }

            // Auto-koppla befintliga album till fotografens admin-konto
            if ($admin_user_id && $id) {
                try {
                    $pdo->prepare("
                        INSERT IGNORE INTO photographer_albums (user_id, album_id, can_upload, can_edit)
                        SELECT ?, ea.id, 1, 1
                        FROM event_albums ea
                        WHERE ea.photographer_id = ?
                    ")->execute([$admin_user_id, $id]);
                } catch (PDOException $e) {
                    // photographer_albums kanske inte finns ännu
                }
            }
        }
    } elseif ($action === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        if ($id) {
            // Clear references first
            $pdo->prepare("UPDATE event_albums SET photographer_id = NULL WHERE photographer_id = ?")->execute([$id]);
            $pdo->prepare("UPDATE event_photos SET photographer_id = NULL WHERE photographer_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM photographers WHERE id = ?")->execute([$id]);
            $message = 'Fotograf borttagen';
        }
    }
}

// Get editing photographer
$editId = intval($_GET['edit'] ?? $_POST['id'] ?? 0);
$editPhotographer = null;
if ($editId) {
    $stmt = $pdo->prepare("SELECT * FROM photographers WHERE id = ?");
    $stmt->execute([$editId]);
    $editPhotographer = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Get available admin users (photographer role) for linking
$adminUsers = [];
try {
    $adminUsers = $pdo->query("
        SELECT au.id, au.full_name, au.email, au.role
        FROM admin_users au
        WHERE au.active = 1 AND au.role IN ('photographer', 'admin', 'super_admin')
        ORDER BY au.full_name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

// Get all photographers
$photographers = $pdo->query("
    SELECT p.*,
           r.firstname as rider_firstname, r.lastname as rider_lastname,
           au.full_name as admin_user_name, au.email as admin_user_email,
           COUNT(DISTINCT ea.id) as album_count,
           COALESCE(SUM(ea.photo_count), 0) as photo_count
    FROM photographers p
    LEFT JOIN riders r ON p.rider_id = r.id
    LEFT JOIN admin_users au ON p.admin_user_id = au.id
    LEFT JOIN event_albums ea ON ea.photographer_id = p.id
    GROUP BY p.id
    ORDER BY p.name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Fotografer';
$page_actions = '<a href="?edit=0" class="btn btn--primary btn--sm"><i data-lucide="plus"></i> Ny fotograf</a>';
include __DIR__ . '/components/unified-layout.php';
?>

<?php if ($message): ?>
<div class="alert alert-success" style="margin-bottom: var(--space-md);"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger" style="margin-bottom: var(--space-md);"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<?php if ($editPhotographer !== null || isset($_GET['edit'])): ?>
<!-- Edit/Create Form -->
<div class="card" style="margin-bottom: var(--space-lg);">
    <div class="card-header">
        <h3><?= $editPhotographer ? 'Redigera fotograf' : 'Ny fotograf' ?></h3>
    </div>
    <div class="card-body">
        <form method="POST" action="/admin/photographers.php">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= $editPhotographer['id'] ?? 0 ?>">

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--space-md);">
                <div class="form-group">
                    <label class="form-label">Namn *</label>
                    <input type="text" name="name" class="form-input" value="<?= htmlspecialchars($editPhotographer['name'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label">E-post</label>
                    <input type="email" name="email" class="form-input" value="<?= htmlspecialchars($editPhotographer['email'] ?? '') ?>">
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Biografi</label>
                <textarea name="bio" class="form-input" rows="3" placeholder="Kort presentation..."><?= htmlspecialchars($editPhotographer['bio'] ?? '') ?></textarea>
            </div>

            <div class="form-group">
                <label class="form-label">Profilbild</label>
                <input type="hidden" name="avatar_url" id="avatarUrlInput" value="<?= htmlspecialchars($editPhotographer['avatar_url'] ?? '') ?>">
                <?php
                $avatarUrl = $editPhotographer['avatar_url'] ?? '';
                $initials = strtoupper(substr($editPhotographer['name'] ?? 'F', 0, 1));
                ?>
                <div style="display: flex; align-items: center; gap: var(--space-lg);">
                    <div class="pg-avatar-upload-container" id="avatarContainer">
                        <div class="pg-avatar-preview" id="avatarPreview">
                            <?php if ($avatarUrl): ?>
                                <img src="<?= htmlspecialchars($avatarUrl) ?>" alt="Profilbild" class="pg-avatar-image" id="avatarImage">
                            <?php else: ?>
                                <div class="pg-avatar-fallback" id="avatarFallback">
                                    <span class="pg-avatar-initials"><?= htmlspecialchars($initials) ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="pg-avatar-overlay" id="avatarOverlay">
                            <i data-lucide="camera"></i>
                        </div>
                        <div class="pg-avatar-loading" id="avatarLoading" style="display: none;"></div>
                        <input type="file" id="avatarInput" class="pg-avatar-file-input" accept="image/jpeg,image/png,image/gif,image/webp" aria-label="Välj profilbild">
                    </div>
                    <div>
                        <p style="color: var(--color-text-secondary); font-size: 0.85rem; margin: 0;">Klicka för att välja en bild</p>
                        <p style="color: var(--color-text-muted); font-size: 0.75rem; margin: var(--space-2xs) 0 0;">Max 2MB. JPG, PNG, GIF eller WebP.</p>
                        <?php if ($avatarUrl): ?>
                        <button type="button" class="btn btn-ghost" id="avatarRemoveBtn" style="padding: 4px 10px; font-size: 0.8rem; color: var(--color-error); margin-top: var(--space-xs);" onclick="clearAvatar()">
                            <i data-lucide="x" style="width: 12px; height: 12px;"></i> Ta bort bild
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="pg-avatar-status" id="avatarStatus" style="display: none;"></div>
            </div>

            <h4 style="margin: var(--space-lg) 0 var(--space-sm); font-family: var(--font-heading-secondary); color: var(--color-text-secondary);">
                <i data-lucide="link" style="width: 16px; height: 16px; vertical-align: -2px;"></i> Sociala medier
            </h4>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--space-md);">
                <div class="form-group">
                    <label class="form-label"><i data-lucide="globe" style="width: 14px; height: 14px; vertical-align: -2px;"></i> Webbplats</label>
                    <input type="url" name="website_url" class="form-input" value="<?= htmlspecialchars($editPhotographer['website_url'] ?? '') ?>" placeholder="https://...">
                </div>
                <div class="form-group">
                    <label class="form-label"><i data-lucide="instagram" style="width: 14px; height: 14px; vertical-align: -2px;"></i> Instagram</label>
                    <input type="url" name="instagram_url" class="form-input" value="<?= htmlspecialchars($editPhotographer['instagram_url'] ?? '') ?>" placeholder="https://instagram.com/...">
                </div>
                <div class="form-group">
                    <label class="form-label"><i data-lucide="music" style="width: 14px; height: 14px; vertical-align: -2px;"></i> TikTok</label>
                    <input type="url" name="tiktok_url" class="form-input" value="<?= htmlspecialchars($editPhotographer['tiktok_url'] ?? '') ?>" placeholder="https://tiktok.com/@...">
                </div>
                <div class="form-group">
                    <label class="form-label"><i data-lucide="activity" style="width: 14px; height: 14px; vertical-align: -2px;"></i> Strava</label>
                    <input type="url" name="strava_url" class="form-input" value="<?= htmlspecialchars($editPhotographer['strava_url'] ?? '') ?>" placeholder="https://strava.com/athletes/...">
                </div>
                <div class="form-group">
                    <label class="form-label"><i data-lucide="facebook" style="width: 14px; height: 14px; vertical-align: -2px;"></i> Facebook</label>
                    <input type="url" name="facebook_url" class="form-input" value="<?= htmlspecialchars($editPhotographer['facebook_url'] ?? '') ?>" placeholder="https://facebook.com/...">
                </div>
                <div class="form-group">
                    <label class="form-label"><i data-lucide="youtube" style="width: 14px; height: 14px; vertical-align: -2px;"></i> YouTube</label>
                    <input type="url" name="youtube_url" class="form-input" value="<?= htmlspecialchars($editPhotographer['youtube_url'] ?? '') ?>" placeholder="https://youtube.com/...">
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--space-md);">
                <div class="form-group">
                    <label class="form-label">Kopplad deltagare (rider_id)</label>
                    <input type="number" name="rider_id" class="form-input" value="<?= $editPhotographer['rider_id'] ?? '' ?>" placeholder="Lämna tomt om ej deltagare">
                    <small style="color: var(--color-text-muted);">Om fotografen även är deltagare</small>
                </div>
                <div class="form-group">
                    <label class="form-label"><i data-lucide="key" style="width: 14px; height: 14px; vertical-align: -2px;"></i> Kopplat inloggningskonto</label>
                    <select name="admin_user_id" class="form-select">
                        <option value="">Inget konto (kan ej logga in)</option>
                        <?php foreach ($adminUsers as $au): ?>
                        <option value="<?= $au['id'] ?>" <?= ($editPhotographer['admin_user_id'] ?? 0) == $au['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($au['full_name'] ?: $au['email']) ?> (<?= $au['role'] ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <small style="color: var(--color-text-muted);">Koppla till admin-användare med rollen "photographer"</small>
                </div>
            </div>

            <div class="form-group">
                <label style="display: flex; align-items: center; gap: var(--space-xs); cursor: pointer;">
                    <input type="checkbox" name="active" value="1" <?= ($editPhotographer['active'] ?? 1) ? 'checked' : '' ?>>
                    Aktiv
                </label>
            </div>

            <div style="display: flex; gap: var(--space-sm); margin-top: var(--space-md);">
                <button type="submit" class="btn btn-primary">Spara</button>
                <a href="/admin/photographers.php" class="btn btn-ghost">Avbryt</a>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Photographer List -->
<div class="card">
    <div class="card-header">
        <h3>Alla fotografer (<?= count($photographers) ?>)</h3>
    </div>
    <div class="card-body" style="padding: 0;">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Namn</th>
                        <th>Album</th>
                        <th>Bilder</th>
                        <th>Konto</th>
                        <th>Kopplad deltagare</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($photographers)): ?>
                    <tr><td colspan="7" style="text-align: center; color: var(--color-text-muted);">Inga fotografer</td></tr>
                    <?php else: ?>
                    <?php foreach ($photographers as $p): ?>
                    <tr>
                        <td>
                            <div style="display: flex; align-items: center; gap: var(--space-sm);">
                                <?php if ($p['avatar_url']): ?>
                                <img src="<?= htmlspecialchars($p['avatar_url']) ?>" style="width: 32px; height: 32px; border-radius: 50%; object-fit: cover;">
                                <?php endif; ?>
                                <div>
                                    <strong><?= htmlspecialchars($p['name']) ?></strong>
                                    <?php if ($p['email']): ?>
                                    <div style="font-size: 0.75rem; color: var(--color-text-muted);"><?= htmlspecialchars($p['email']) ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td><?= $p['album_count'] ?></td>
                        <td><?= number_format($p['photo_count']) ?></td>
                        <td>
                            <?php if ($p['admin_user_id']): ?>
                            <span class="badge badge-success" title="<?= htmlspecialchars($p['admin_user_email'] ?? '') ?>">
                                <i data-lucide="key" style="width: 10px; height: 10px;"></i>
                                <?= htmlspecialchars($p['admin_user_name'] ?? 'Kopplat') ?>
                            </span>
                            <?php else: ?>
                            <span style="color: var(--color-text-muted);">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($p['rider_id']): ?>
                            <a href="/rider/<?= $p['rider_id'] ?>"><?= htmlspecialchars(($p['rider_firstname'] ?? '') . ' ' . ($p['rider_lastname'] ?? '')) ?></a>
                            <?php else: ?>
                            <span style="color: var(--color-text-muted);">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge <?= $p['active'] ? 'badge-success' : 'badge-danger' ?>"><?= $p['active'] ? 'Aktiv' : 'Inaktiv' ?></span>
                        </td>
                        <td style="text-align: right;">
                            <a href="?edit=<?= $p['id'] ?>" class="btn btn-ghost" style="padding: 4px 8px; font-size: 0.8rem;">
                                <i data-lucide="pencil" style="width: 14px; height: 14px;"></i>
                            </a>
                            <a href="/photographer/<?= $p['id'] ?>" target="_blank" class="btn btn-ghost" style="padding: 4px 8px; font-size: 0.8rem;" title="Visa publik profil">
                                <i data-lucide="external-link" style="width: 14px; height: 14px;"></i>
                            </a>
                            <form method="POST" style="display: inline;" onsubmit="return confirm('Radera denna fotograf?')">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $p['id'] ?>">
                                <button type="submit" class="btn btn-ghost" style="padding: 4px 8px; font-size: 0.8rem; color: var(--color-error);">
                                    <i data-lucide="trash-2" style="width: 14px; height: 14px;"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Avatar Upload Styles -->
<style>
.pg-avatar-upload-container {
    position: relative;
    cursor: pointer;
    flex-shrink: 0;
}
.pg-avatar-preview {
    width: 120px;
    height: 120px;
    border-radius: 50%;
    overflow: hidden;
    background: var(--color-accent-light);
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    transition: transform 0.3s ease, box-shadow 0.3s ease;
}
.pg-avatar-upload-container:hover .pg-avatar-preview {
    transform: scale(1.02);
    box-shadow: 0 4px 16px rgba(0,0,0,0.15);
}
.pg-avatar-image {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.pg-avatar-fallback {
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--color-accent);
    color: #ffffff;
}
.pg-avatar-initials {
    font-size: 3rem;
    font-weight: 700;
    text-transform: uppercase;
}
.pg-avatar-overlay {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(0, 0, 0, 0.5);
    border-radius: 50%;
    opacity: 0;
    transition: opacity 0.3s ease;
    pointer-events: none;
}
.pg-avatar-upload-container:hover .pg-avatar-overlay {
    opacity: 1;
}
.pg-avatar-overlay i,
.pg-avatar-overlay svg {
    color: #ffffff;
    width: 36px;
    height: 36px;
}
.pg-avatar-file-input {
    position: absolute;
    inset: 0;
    opacity: 0;
    cursor: pointer;
    border-radius: 50%;
}
.pg-avatar-loading {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(0, 0, 0, 0.6);
    border-radius: 50%;
}
.pg-avatar-loading::after {
    content: '';
    width: 36px;
    height: 36px;
    border: 3px solid rgba(255, 255, 255, 0.3);
    border-top-color: #ffffff;
    border-radius: 50%;
    animation: pg-avatar-spin 0.8s linear infinite;
}
@keyframes pg-avatar-spin {
    to { transform: rotate(360deg); }
}
.pg-avatar-status {
    margin-top: var(--space-sm);
    padding: var(--space-xs) var(--space-md);
    border-radius: var(--radius-sm);
    font-size: 0.85rem;
}
.pg-avatar-status.success {
    background: rgba(16, 185, 129, 0.1);
    color: var(--color-success);
    border: 1px solid var(--color-success);
}
.pg-avatar-status.error {
    background: rgba(239, 68, 68, 0.1);
    color: var(--color-error);
    border: 1px solid var(--color-error);
}
</style>

<!-- Avatar Upload Script -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    var avatarInput = document.getElementById('avatarInput');
    if (!avatarInput) return;

    var avatarPreview = document.getElementById('avatarPreview');
    var avatarLoading = document.getElementById('avatarLoading');
    var avatarStatus = document.getElementById('avatarStatus');

    avatarInput.addEventListener('change', async function(e) {
        var file = e.target.files[0];
        if (!file) return;

        var allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (allowedTypes.indexOf(file.type) === -1) {
            showStatus('Otillåten filtyp. Välj JPG, PNG, GIF eller WebP.', 'error');
            return;
        }
        if (file.size > 2 * 1024 * 1024) {
            showStatus('Bilden är för stor. Max 2MB.', 'error');
            return;
        }

        // Show preview immediately
        var reader = new FileReader();
        reader.onload = function(ev) {
            avatarPreview.innerHTML = '<img src="' + ev.target.result + '" alt="Profilbild" class="pg-avatar-image">';
        };
        reader.readAsDataURL(file);

        avatarLoading.style.display = 'flex';
        hideStatus();

        // Upload via media API
        var formData = new FormData();
        formData.append('file', file);
        formData.append('folder', 'general');

        try {
            var response = await fetch('/api/media.php?action=upload', { method: 'POST', body: formData });
            var result = await response.json();

            if (result.success) {
                var url = result.url || ('/' + result.filepath);
                document.getElementById('avatarUrlInput').value = url;
                avatarPreview.innerHTML = '<img src="' + url + '" alt="Profilbild" class="pg-avatar-image">';
                showStatus('Bilden har laddats upp!', 'success');

                // Show remove button
                var removeBtn = document.getElementById('avatarRemoveBtn');
                if (!removeBtn) {
                    var infoDiv = document.getElementById('avatarContainer').nextElementSibling;
                    if (infoDiv) {
                        var btn = document.createElement('button');
                        btn.type = 'button';
                        btn.id = 'avatarRemoveBtn';
                        btn.className = 'btn btn-ghost';
                        btn.style.cssText = 'padding:4px 10px; font-size:0.8rem; color:var(--color-error); margin-top:var(--space-xs);';
                        btn.innerHTML = '<i data-lucide="x" style="width:12px; height:12px;"></i> Ta bort bild';
                        btn.onclick = clearAvatar;
                        infoDiv.appendChild(btn);
                        if (window.lucide) lucide.createIcons();
                    }
                }
            } else {
                showStatus(result.error || 'Kunde inte ladda upp bilden.', 'error');
            }
        } catch (err) {
            showStatus('Kunde inte ladda upp bilden. Försök igen.', 'error');
        } finally {
            avatarLoading.style.display = 'none';
        }
    });

    function showStatus(message, type) {
        avatarStatus.textContent = message;
        avatarStatus.className = 'pg-avatar-status ' + type;
        avatarStatus.style.display = 'block';
        if (type === 'success') {
            setTimeout(hideStatus, 4000);
        }
    }

    function hideStatus() {
        avatarStatus.style.display = 'none';
    }

    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }
});

function clearAvatar() {
    document.getElementById('avatarUrlInput').value = '';
    var preview = document.getElementById('avatarPreview');
    var name = document.querySelector('input[name="name"]');
    var initial = (name && name.value) ? name.value.charAt(0).toUpperCase() : 'F';
    preview.innerHTML = '<div class="pg-avatar-fallback"><span class="pg-avatar-initials">' + initial + '</span></div>';
    var removeBtn = document.getElementById('avatarRemoveBtn');
    if (removeBtn) removeBtn.remove();
}
</script>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>

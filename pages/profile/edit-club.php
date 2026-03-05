<?php
/**
 * TheHUB - Redigera klubb (Club Admin, public profile side)
 * Matches admin/my-club-edit.php fields but uses public layout
 */

$currentUser = hub_current_user();
if (!$currentUser) {
    header('Location: /profile/login');
    exit;
}

$pdo = hub_db();

// Get club ID from URL
$clubId = intval($pageInfo['params']['id'] ?? 0);
if (!$clubId) {
    header('Location: /profile/club-admin');
    exit;
}

// Check permission
if (!hub_can_edit_club($clubId)) {
    header('Location: /profile/club-admin');
    exit;
}

// Get club data
$stmt = $pdo->prepare("SELECT * FROM clubs WHERE id = ?");
$stmt->execute([$clubId]);
$club = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$club) {
    header('Location: /profile/club-admin');
    exit;
}

// Handle form submission
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');

    if (empty($name)) {
        $message = 'Klubbnamn krävs.';
        $messageType = 'error';
    } else {
        $logoValue = trim($_POST['logo'] ?? '');

        // Build dynamic update - only include columns that exist
        $fields = [
            'name' => $name,
            'short_name' => trim($_POST['short_name'] ?? ''),
            'org_number' => trim($_POST['org_number'] ?? ''),
            'region' => trim($_POST['region'] ?? ''),
            'city' => trim($_POST['city'] ?? ''),
            'country' => trim($_POST['country'] ?? 'Sverige'),
            'address' => trim($_POST['address'] ?? ''),
            'postal_code' => trim($_POST['postal_code'] ?? ''),
            'website' => trim($_POST['website'] ?? ''),
            'email' => trim($_POST['email'] ?? ''),
            'phone' => trim($_POST['phone'] ?? ''),
            'contact_person' => trim($_POST['contact_person'] ?? ''),
            'description' => trim($_POST['description'] ?? ''),
            'facebook' => trim($_POST['facebook'] ?? ''),
            'instagram' => trim($_POST['instagram'] ?? ''),
            'youtube' => trim($_POST['youtube'] ?? ''),
            'tiktok' => trim($_POST['tiktok'] ?? ''),
            'swish_number' => trim($_POST['swish_number'] ?? '') ?: null,
            'swish_name' => trim($_POST['swish_name'] ?? '') ?: null,
        ];

        // Logo URL (set via JS upload, stored in hidden input)
        if ($logoValue) {
            $fields['logo'] = $logoValue;
            $fields['logo_url'] = $logoValue;
        }

        try {
            $setClauses = [];
            $params = [];
            foreach ($fields as $col => $val) {
                $setClauses[] = "`$col` = ?";
                $params[] = $val;
            }
            $params[] = $clubId;

            $sql = "UPDATE clubs SET " . implode(', ', $setClauses) . " WHERE id = ?";
            $updateStmt = $pdo->prepare($sql);
            $updateStmt->execute($params);

            $message = 'Klubbinformationen har sparats.';
            $messageType = 'success';

            // Refresh
            $stmt->execute([$clubId]);
            $club = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            // If column doesn't exist, try without it
            error_log("Club edit error: " . $e->getMessage());
            $message = 'Kunde inte spara ändringar.';
            $messageType = 'error';
        }
    }
}

// Get member count
$memberStmt = $pdo->prepare("SELECT COUNT(*) FROM riders WHERE club_id = ?");
$memberStmt->execute([$clubId]);
$riderCount = (int) $memberStmt->fetchColumn();

$logoUrl = $club['logo_url'] ?? $club['logo'] ?? '';
?>

<link rel="stylesheet" href="/assets/css/forms.css?v=<?= filemtime(HUB_ROOT . '/assets/css/forms.css') ?>">

<div class="page-header">
    <nav class="breadcrumb">
        <a href="/profile">Min Sida</a>
        <span class="breadcrumb-sep">›</span>
        <a href="/profile/club-admin">Klubb-admin</a>
        <span class="breadcrumb-sep">›</span>
        <span>Redigera</span>
    </nav>
    <h1 class="page-title">
        <i data-lucide="pencil" class="page-icon"></i>
        Redigera <?= htmlspecialchars($club['name']) ?>
    </h1>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?= $messageType === 'success' ? 'success' : 'danger' ?>">
        <?= htmlspecialchars($message) ?>
    </div>
<?php endif; ?>

<form method="POST" action="">

<!-- Grundläggande information -->
<div class="card" style="margin-bottom: var(--space-lg);">
    <div class="card-header"><h3><i data-lucide="info"></i> Grundläggande information</h3></div>
    <div class="card-body">
        <div class="form-group">
            <label class="form-label">Klubbnamn *</label>
            <input type="text" name="name" class="form-input" required
                   value="<?= htmlspecialchars($club['name'] ?? '') ?>">
        </div>

        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Förkortning</label>
                <input type="text" name="short_name" class="form-input"
                       value="<?= htmlspecialchars($club['short_name'] ?? '') ?>"
                       placeholder="T.ex. UCK">
            </div>
            <div class="form-group">
                <label class="form-label">Organisationsnummer</label>
                <input type="text" name="org_number" class="form-input"
                       value="<?= htmlspecialchars($club['org_number'] ?? '') ?>"
                       placeholder="123456-7890">
            </div>
        </div>

        <div class="form-group">
            <label class="form-label">Medlemmar</label>
            <input type="text" class="form-input" value="<?= $riderCount ?> aktiva" disabled>
        </div>

        <div class="form-group">
            <label class="form-label">Beskrivning</label>
            <textarea name="description" class="form-textarea" rows="3"
                      placeholder="Beskriv klubben..."><?= htmlspecialchars($club['description'] ?? '') ?></textarea>
        </div>
    </div>
</div>

<!-- Logotyp -->
<div class="card" style="margin-bottom: var(--space-lg);">
    <div class="card-header"><h3><i data-lucide="image"></i> Logotyp</h3></div>
    <div class="card-body">
        <div style="display: flex; gap: var(--space-lg); align-items: flex-start; flex-wrap: wrap;">
            <div style="position: relative;">
                <div id="clubLogoPreview"
                     style="width: 150px; height: 100px; border-radius: var(--radius-md); background: var(--color-bg-hover); display: flex; align-items: center; justify-content: center; overflow: hidden; cursor: pointer; border: 2px dashed var(--color-border);"
                     onclick="document.getElementById('clubLogoInput').click()">
                    <?php if ($logoUrl): ?>
                    <img src="<?= htmlspecialchars($logoUrl) ?>" alt="Logotyp" style="max-width: 100%; max-height: 100%; object-fit: contain;">
                    <?php else: ?>
                    <div style="text-align: center;">
                        <i data-lucide="image" style="width: 32px; height: 32px; color: var(--color-text-muted);"></i>
                        <p style="font-size: 0.75rem; color: var(--color-text-muted); margin-top: 4px;">Klicka för att ladda upp</p>
                    </div>
                    <?php endif; ?>
                </div>
                <div id="clubLogoLoading" style="display: none; position: absolute; inset: 0; background: rgba(0,0,0,0.6); border-radius: var(--radius-md); align-items: center; justify-content: center;">
                    <div style="width: 24px; height: 24px; border: 2px solid rgba(255,255,255,0.3); border-top-color: #fff; border-radius: 50%; animation: spin 0.8s linear infinite;"></div>
                </div>
            </div>
            <div style="flex: 1; min-width: 200px;">
                <label class="btn btn-secondary" style="cursor: pointer; display: inline-flex; gap: var(--space-xs); align-items: center;">
                    <i data-lucide="upload" style="width: 16px; height: 16px;"></i>
                    Ladda upp logotyp
                    <input type="file" id="clubLogoInput" accept="image/jpeg,image/png,image/gif,image/webp" style="display: none;">
                </label>
                <span id="clubLogoStatus" style="font-size: 0.875rem; display: block; margin-top: var(--space-xs);"></span>
                <input type="hidden" name="logo" id="logoUrlInput" value="<?= htmlspecialchars($logoUrl) ?>">
                <p style="font-size: 0.75rem; color: var(--color-text-muted); margin-top: var(--space-sm);">
                    Max 2MB. JPG, PNG, GIF eller WebP. Rekommenderad storlek: 400x300px.
                </p>
            </div>
        </div>
    </div>
</div>

<!-- Kontaktinformation -->
<div class="card" style="margin-bottom: var(--space-lg);">
    <div class="card-header"><h3><i data-lucide="phone"></i> Kontaktinformation</h3></div>
    <div class="card-body">
        <div class="form-group">
            <label class="form-label">Kontaktperson</label>
            <input type="text" name="contact_person" class="form-input"
                   value="<?= htmlspecialchars($club['contact_person'] ?? '') ?>"
                   placeholder="Namn på kontaktperson">
        </div>

        <div class="form-row">
            <div class="form-group">
                <label class="form-label">E-post</label>
                <input type="email" name="email" class="form-input"
                       value="<?= htmlspecialchars($club['email'] ?? '') ?>"
                       placeholder="info@klubb.se">
            </div>
            <div class="form-group">
                <label class="form-label">Telefon</label>
                <input type="tel" name="phone" class="form-input"
                       value="<?= htmlspecialchars($club['phone'] ?? '') ?>"
                       placeholder="070-123 45 67">
            </div>
        </div>

        <div class="form-group">
            <label class="form-label">Webbplats</label>
            <input type="url" name="website" class="form-input"
                   value="<?= htmlspecialchars($club['website'] ?? '') ?>"
                   placeholder="https://klubb.se">
        </div>

        <!-- Sociala medier -->
        <div class="form-row">
            <div class="form-group">
                <label class="form-label"><i data-lucide="facebook" style="width: 14px; height: 14px; display: inline;"></i> Facebook</label>
                <input type="url" name="facebook" class="form-input"
                       value="<?= htmlspecialchars($club['facebook'] ?? '') ?>"
                       placeholder="https://facebook.com/...">
            </div>
            <div class="form-group">
                <label class="form-label"><i data-lucide="instagram" style="width: 14px; height: 14px; display: inline;"></i> Instagram</label>
                <input type="text" name="instagram" class="form-input"
                       value="<?= htmlspecialchars($club['instagram'] ?? '') ?>"
                       placeholder="@användarnamn">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label class="form-label"><i data-lucide="youtube" style="width: 14px; height: 14px; display: inline;"></i> YouTube</label>
                <input type="url" name="youtube" class="form-input"
                       value="<?= htmlspecialchars($club['youtube'] ?? '') ?>"
                       placeholder="https://youtube.com/...">
            </div>
            <div class="form-group">
                <label class="form-label"><i data-lucide="music" style="width: 14px; height: 14px; display: inline;"></i> TikTok</label>
                <input type="text" name="tiktok" class="form-input"
                       value="<?= htmlspecialchars($club['tiktok'] ?? '') ?>"
                       placeholder="@användarnamn">
            </div>
        </div>
    </div>
</div>

<!-- Adress -->
<div class="card" style="margin-bottom: var(--space-lg);">
    <div class="card-header"><h3><i data-lucide="map-pin"></i> Adress</h3></div>
    <div class="card-body">
        <div class="form-group">
            <label class="form-label">Gatuadress</label>
            <input type="text" name="address" class="form-input"
                   value="<?= htmlspecialchars($club['address'] ?? '') ?>"
                   placeholder="Storgatan 1">
        </div>

        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Postnummer</label>
                <input type="text" name="postal_code" class="form-input"
                       value="<?= htmlspecialchars($club['postal_code'] ?? '') ?>"
                       placeholder="123 45">
            </div>
            <div class="form-group">
                <label class="form-label">Stad</label>
                <input type="text" name="city" class="form-input"
                       value="<?= htmlspecialchars($club['city'] ?? '') ?>">
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Region/Län</label>
                <input type="text" name="region" class="form-input"
                       value="<?= htmlspecialchars($club['region'] ?? '') ?>"
                       placeholder="T.ex. Stockholms län">
            </div>
            <div class="form-group">
                <label class="form-label">Land</label>
                <select name="country" class="form-select">
                    <?php
                    $countries = ['Sverige', 'Norge', 'Danmark', 'Finland', 'Tyskland', 'Frankrike', 'Schweiz', 'Österrike', 'Italien', 'Spanien', 'Storbritannien', 'USA'];
                    foreach ($countries as $c):
                    ?>
                    <option value="<?= $c ?>" <?= ($club['country'] ?? 'Sverige') === $c ? 'selected' : '' ?>><?= $c ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>
</div>

<!-- Betalningsinformation -->
<div class="card" style="margin-bottom: var(--space-lg);">
    <div class="card-header"><h3><i data-lucide="credit-card"></i> Betalningsinformation</h3></div>
    <div class="card-body">
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Swish-nummer</label>
                <input type="tel" name="swish_number" class="form-input"
                       value="<?= htmlspecialchars($club['swish_number'] ?? '') ?>"
                       placeholder="070-123 45 67">
            </div>
            <div class="form-group">
                <label class="form-label">Swish-namn</label>
                <input type="text" name="swish_name" class="form-input"
                       value="<?= htmlspecialchars($club['swish_name'] ?? '') ?>"
                       placeholder="Klubbens namn i Swish">
            </div>
        </div>
        <p style="font-size: 0.8125rem; color: var(--color-text-muted); margin-top: var(--space-xs);">
            Swish-uppgifter används för att ta emot betalningar vid tävlingar som klubben arrangerar.
        </p>
    </div>
</div>

<!-- Actions -->
<div style="display: flex; gap: var(--space-sm); margin-bottom: var(--space-xl);">
    <button type="submit" class="btn btn-primary">
        <i data-lucide="save"></i> Spara ändringar
    </button>
    <a href="/profile/club-admin?club=<?= $clubId ?>" class="btn btn-secondary">Avbryt</a>
    <a href="/club/<?= $clubId ?>" class="btn btn-ghost" target="_blank">
        <i data-lucide="eye"></i> Visa publik sida
    </a>
</div>

</form>

<style>
@keyframes spin { to { transform: rotate(360deg); } }
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const logoInput = document.getElementById('clubLogoInput');
    const logoPreview = document.getElementById('clubLogoPreview');
    const logoLoading = document.getElementById('clubLogoLoading');
    const logoStatus = document.getElementById('clubLogoStatus');
    const urlInput = document.getElementById('logoUrlInput');
    const clubId = <?= intval($clubId) ?>;

    if (!logoInput) return;

    logoInput.addEventListener('change', async function(e) {
        const file = e.target.files[0];
        if (!file) return;

        const allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!allowedTypes.includes(file.type)) {
            showStatus('Otillåten filtyp. Välj JPG, PNG, GIF eller WebP.', 'error');
            return;
        }

        if (file.size > 2 * 1024 * 1024) {
            showStatus('Filen är för stor. Max 2MB.', 'error');
            return;
        }

        logoLoading.style.display = 'flex';
        showStatus('Laddar upp...', 'info');

        const formData = new FormData();
        formData.append('logo', file);
        formData.append('club_id', clubId);

        try {
            const response = await fetch('/api/update-club-logo.php', {
                method: 'POST',
                body: formData
            });
            const result = await response.json();

            if (result.success) {
                showStatus('Logotypen har laddats upp!', 'success');
                logoPreview.innerHTML = '<img src="' + result.logo_url + '" alt="Logotyp" style="max-width: 100%; max-height: 100%; object-fit: contain;">';
                logoPreview.style.border = 'none';
                urlInput.value = result.logo_url;
            } else {
                showStatus(result.error || 'Något gick fel', 'error');
            }
        } catch (error) {
            showStatus('Uppladdning misslyckades. Försök igen.', 'error');
        } finally {
            logoLoading.style.display = 'none';
        }
    });

    function showStatus(msg, type) {
        logoStatus.textContent = msg;
        logoStatus.style.color = type === 'error' ? 'var(--color-error)' : type === 'success' ? 'var(--color-success)' : 'var(--color-text-muted)';
        if (type === 'success') setTimeout(() => { logoStatus.textContent = ''; }, 5000);
    }

    if (typeof lucide !== 'undefined') lucide.createIcons();
});
</script>

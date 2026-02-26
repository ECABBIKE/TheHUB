<?php
/**
 * TheHUB V1.0 - Edit Profile with Social Profiles and Avatar Upload
 */

$currentUser = hub_current_user();
if (!$currentUser) {
    header('Location: /profile/login');
    exit;
}

$pdo = hub_db();
$message = '';
$error = '';

// Include premium helpers
$premiumPath = dirname(dirname(__DIR__)) . '/includes/premium.php';
if (file_exists($premiumPath)) {
    require_once $premiumPath;
}

$isPremium = function_exists('isPremiumMember') && isPremiumMember($pdo, (int)$currentUser['id']);
$riderSponsors = ($isPremium && function_exists('getRiderSponsors')) ? getRiderSponsors($pdo, (int)$currentUser['id']) : [];

// Include social profile sanitizer
$rebuildPath = dirname(dirname(__DIR__)) . '/includes/rebuild-rider-stats.php';
if (file_exists($rebuildPath)) {
    require_once $rebuildPath;
}

// Include avatar helper functions
$avatarHelperPath = dirname(dirname(__DIR__)) . '/includes/get-avatar.php';
if (file_exists($avatarHelperPath)) {
    require_once $avatarHelperPath;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $firstName = trim($_POST['firstname'] ?? '');
    $lastName = trim($_POST['lastname'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $clubId = intval($_POST['club_id'] ?? 0) ?: null;

    // New profile fields
    $birthYear = intval($_POST['birth_year'] ?? 0) ?: null;
    $gender = trim($_POST['gender'] ?? '');
    $nationality = trim($_POST['nationality'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $uciId = trim($_POST['uci_id'] ?? '');
    $iceName = trim($_POST['ice_name'] ?? '');
    $icePhone = trim($_POST['ice_phone'] ?? '');

    // Address fields for purchases/receipts
    $address = trim($_POST['address'] ?? '');
    $postalCode = trim($_POST['postal_code'] ?? '');
    $postalCity = trim($_POST['postal_city'] ?? '');

    // Social profiles
    $socialInstagram = trim($_POST['social_instagram'] ?? '');
    $socialStrava = trim($_POST['social_strava'] ?? '');
    $socialFacebook = trim($_POST['social_facebook'] ?? '');
    $socialYoutube = trim($_POST['social_youtube'] ?? '');
    $socialTiktok = trim($_POST['social_tiktok'] ?? '');

    // Sanitize social handles if function exists
    if (function_exists('sanitizeSocialHandle')) {
        $socialInstagram = sanitizeSocialHandle($socialInstagram, 'instagram');
        $socialStrava = sanitizeSocialHandle($socialStrava, 'strava');
        $socialFacebook = sanitizeSocialHandle($socialFacebook, 'facebook');
        $socialYoutube = sanitizeSocialHandle($socialYoutube, 'youtube');
        $socialTiktok = sanitizeSocialHandle($socialTiktok, 'tiktok');
    }

    if (empty($firstName) || empty($lastName)) {
        $error = 'Förnamn och efternamn krävs.';
    } else {
        try {
            // Build dynamic update query based on which columns exist
            // Start with core fields that always exist
            $updateFields = [
                'firstname = ?', 'lastname = ?', 'email = ?', 'club_id = ?'
            ];
            $updateValues = [
                $firstName, $lastName, $email, $clubId
            ];

            // Check which optional columns exist
            $existingColumns = [];
            $colStmt = $pdo->query("SHOW COLUMNS FROM riders");
            while ($col = $colStmt->fetch(PDO::FETCH_ASSOC)) {
                $existingColumns[] = $col['Field'];
            }

            // Add social columns if they exist
            $socialColumns = [
                'social_instagram' => $socialInstagram ?: null,
                'social_strava' => $socialStrava ?: null,
                'social_facebook' => $socialFacebook ?: null,
                'social_youtube' => $socialYoutube ?: null,
                'social_tiktok' => $socialTiktok ?: null
            ];
            foreach ($socialColumns as $colName => $colValue) {
                if (in_array($colName, $existingColumns)) {
                    $updateFields[] = "$colName = ?";
                    $updateValues[] = $colValue;
                }
            }

            // Add new profile fields if they exist
            if (in_array('gender', $existingColumns) && in_array($gender, ['M', 'F'])) {
                $updateFields[] = 'gender = ?';
                $updateValues[] = $gender;
            }
            if (in_array('nationality', $existingColumns) && !empty($nationality)) {
                $updateFields[] = 'nationality = ?';
                $updateValues[] = $nationality;
            }
            if (in_array('phone', $existingColumns)) {
                $updateFields[] = 'birth_year = ?';
                $updateFields[] = 'phone = ?';
                $updateValues[] = $birthYear;
                $updateValues[] = $phone ?: null;
            }
            if (in_array('ice_name', $existingColumns)) {
                $updateFields[] = 'ice_name = ?';
                $updateValues[] = $iceName ?: null;
            }
            if (in_array('ice_phone', $existingColumns)) {
                $updateFields[] = 'ice_phone = ?';
                $updateValues[] = $icePhone ?: null;
            }

            // Address fields for purchases/receipts
            if (in_array('address', $existingColumns)) {
                $updateFields[] = 'address = ?';
                $updateValues[] = $address ?: null;
            }
            if (in_array('postal_code', $existingColumns)) {
                $updateFields[] = 'postal_code = ?';
                $updateValues[] = $postalCode ?: null;
            }
            if (in_array('postal_city', $existingColumns)) {
                $updateFields[] = 'postal_city = ?';
                $updateValues[] = $postalCity ?: null;
            }

            // UCI ID (stored as license_number) - only allow setting if not already set
            if (in_array('license_number', $existingColumns) && empty($currentUser['license_number']) && !empty($uciId)) {
                $updateFields[] = 'license_number = ?';
                $updateValues[] = $uciId;
            }

            $updateValues[] = $currentUser['id'];

            $stmt = $pdo->prepare("
                UPDATE riders
                SET " . implode(', ', $updateFields) . "
                WHERE id = ?
            ");
            $stmt->execute($updateValues);
            $message = 'Profilen har uppdaterats!';

            // Refresh user data
            $currentUser = hub_get_rider_by_id($currentUser['id']);
        } catch (PDOException $e) {
            $error = 'Kunde inte uppdatera profilen: ' . $e->getMessage();
            error_log("Profile update error: " . $e->getMessage());
        }
    }
}

// Get active clubs for dropdown
$clubs = $pdo->query("SELECT id, name FROM clubs WHERE active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="page-header">
    <nav class="breadcrumb">
        <a href="/profile">Min Sida</a>
        <span class="breadcrumb-sep">›</span>
        <span>Redigera profil</span>
    </nav>
    <h1 class="page-title">Redigera profil</h1>
</div>

<?php if (isset($_GET['complete'])): ?>
    <div class="alert alert-warning" style="display: flex; gap: var(--space-sm); align-items: flex-start;">
        <i data-lucide="alert-triangle" style="flex-shrink: 0; margin-top: 2px;"></i>
        <div>
            <strong>Komplettera din profil</strong>
            <p style="margin: var(--space-xs) 0 0 0;">Alla obligatoriska fält (markerade med *) måste fyllas i innan du kan anmäla dig till tävlingar. Fyll i nödkontakt (ICE), telefon och övriga uppgifter.</p>
        </div>
    </div>
<?php endif; ?>

<?php if ($message): ?>
    <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<!-- Avatar Upload Section -->
<div class="card avatar-section">
    <div class="card-body">
        <h2>Profilbild</h2>
        <p class="form-help">Ladda upp en profilbild som visas på din profil och i resultatlistor.</p>

        <div class="avatar-upload-wrapper">
            <?php
            // Get current avatar URL
            $avatarUrl = '';
            if (function_exists('get_rider_avatar')) {
                $avatarUrl = get_rider_avatar($currentUser, 200);
            } elseif (!empty($currentUser['avatar_url'])) {
                $avatarUrl = $currentUser['avatar_url'];
            }

            $initials = '';
            if (function_exists('get_rider_initials')) {
                $initials = get_rider_initials($currentUser);
            } else {
                $initials = strtoupper(
                    substr($currentUser['firstname'] ?? '', 0, 1) .
                    substr($currentUser['lastname'] ?? '', 0, 1)
                );
            }
            ?>

            <div class="avatar-upload-container" id="avatarContainer">
                <div class="avatar-preview" id="avatarPreview" style="width: 200px; height: 200px;">
                    <?php if (!empty($currentUser['avatar_url'])): ?>
                        <img src="<?= htmlspecialchars($currentUser['avatar_url']) ?>" alt="Din profilbild" class="avatar-image" id="avatarImage">
                    <?php elseif ($avatarUrl): ?>
                        <img src="<?= htmlspecialchars($avatarUrl) ?>" alt="Din profilbild" class="avatar-image" id="avatarImage">
                    <?php else: ?>
                        <div class="avatar-fallback" id="avatarFallback">
                            <span class="avatar-initials"><?= htmlspecialchars($initials) ?></span>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="avatar-upload-overlay" id="avatarOverlay">
                    <i data-lucide="camera"></i>
                </div>

                <div class="avatar-loading" id="avatarLoading" style="display: none;"></div>

                <input type="file"
                       id="avatarInput"
                       class="avatar-upload-input"
                       accept="image/jpeg,image/png,image/gif,image/webp"
                       aria-label="Välj profilbild">
            </div>

            <div class="avatar-upload-info">
                <p class="text-secondary text-sm">Klicka för att välja en bild</p>
                <p class="text-secondary text-xs">Max 2MB. JPG, PNG, GIF eller WebP.</p>
            </div>

            <div class="avatar-upload-status" id="avatarStatus" style="display: none;"></div>
        </div>
    </div>
</div>

<form method="POST" class="profile-form">
    <div class="form-section">
        <h2>Personuppgifter</h2>
        <p class="form-help">Denna information används för att förifylla anmälningsformulär.</p>

        <div class="form-row">
            <div class="form-group">
                <label for="firstname">Förnamn *</label>
                <input type="text" id="firstname" name="firstname"
                       value="<?= htmlspecialchars($currentUser['firstname'] ?? '') ?>" required>
            </div>
            <div class="form-group">
                <label for="lastname">Efternamn *</label>
                <input type="text" id="lastname" name="lastname"
                       value="<?= htmlspecialchars($currentUser['lastname'] ?? '') ?>" required>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="email">E-post *</label>
                <input type="email" id="email" name="email"
                       value="<?= htmlspecialchars($currentUser['email'] ?? '') ?>"
                       placeholder="din@email.se" required>
            </div>
            <div class="form-group">
                <label for="phone">Telefon *</label>
                <input type="tel" id="phone" name="phone"
                       value="<?= htmlspecialchars($currentUser['phone'] ?? '') ?>"
                       placeholder="07X XXX XX XX" required>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="birth_year">Födelseår *</label>
                <input type="number" id="birth_year" name="birth_year"
                       value="<?= htmlspecialchars($currentUser['birth_year'] ?? '') ?>"
                       min="1920" max="<?= date('Y') ?>"
                       placeholder="ÅÅÅÅ" required>
            </div>
            <div class="form-group">
                <label for="gender">Kön *</label>
                <select id="gender" name="gender" class="form-select" required>
                    <option value="">Välj...</option>
                    <option value="M" <?= ($currentUser['gender'] ?? '') === 'M' ? 'selected' : '' ?>>Man</option>
                    <option value="F" <?= ($currentUser['gender'] ?? '') === 'F' ? 'selected' : '' ?>>Kvinna</option>
                </select>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="nationality">Nationalitet</label>
                <select id="nationality" name="nationality" class="form-select">
                    <option value="">Välj...</option>
                    <option value="SWE" <?= ($currentUser['nationality'] ?? '') === 'SWE' ? 'selected' : '' ?>>Sverige</option>
                    <option value="NOR" <?= ($currentUser['nationality'] ?? '') === 'NOR' ? 'selected' : '' ?>>Norge</option>
                    <option value="DNK" <?= ($currentUser['nationality'] ?? '') === 'DNK' ? 'selected' : '' ?>>Danmark</option>
                    <option value="FIN" <?= ($currentUser['nationality'] ?? '') === 'FIN' ? 'selected' : '' ?>>Finland</option>
                    <option value="DEU" <?= ($currentUser['nationality'] ?? '') === 'DEU' ? 'selected' : '' ?>>Tyskland</option>
                    <option value="GBR" <?= ($currentUser['nationality'] ?? '') === 'GBR' ? 'selected' : '' ?>>Storbritannien</option>
                    <option value="USA" <?= ($currentUser['nationality'] ?? '') === 'USA' ? 'selected' : '' ?>>USA</option>
                </select>
            </div>
            <div class="form-group">
                <label for="uci_id">UCI ID</label>
                <?php if (!empty($currentUser['license_number'])): ?>
                    <input type="text" id="uci_id" name="uci_id"
                           value="<?= htmlspecialchars($currentUser['license_number']) ?>"
                           readonly disabled
                           class="input-disabled">
                    <small class="form-help">UCI ID kan inte ändras efter att det sparats.</small>
                <?php else: ?>
                    <input type="text" id="uci_id" name="uci_id"
                           value=""
                           placeholder="10012345678">
                    <small class="form-help">Fyll i ditt UCI ID om du har ett (11 siffror).</small>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="form-section">
        <h2>Klubb</h2>

        <div class="form-group">
            <label for="club_id">Cykelklubb</label>
            <?php if (!empty($currentUser['club_id'])): ?>
            <?php
                $currentClubName = '';
                foreach ($clubs as $club) {
                    if ($club['id'] == $currentUser['club_id']) {
                        $currentClubName = $club['name'];
                        break;
                    }
                }
            ?>
            <input type="text" value="<?= htmlspecialchars($currentClubName) ?>" readonly disabled class="input-disabled">
            <input type="hidden" name="club_id" value="<?= $currentUser['club_id'] ?>">
            <small class="form-help">Klubb kan inte ändras. Kontakta admin vid behov.</small>
            <?php else: ?>
            <select id="club_id" name="club_id">
                <option value="">Ingen klubb</option>
                <?php foreach ($clubs as $club): ?>
                    <option value="<?= $club['id'] ?>">
                        <?= htmlspecialchars($club['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>
        </div>
    </div>

    <div class="form-section">
        <h2>Nödkontakt (ICE)</h2>
        <p class="form-help">In Case of Emergency - kontaktperson vid olycka. Krävs för anmälan till tävling.</p>

        <div class="form-row">
            <div class="form-group">
                <label for="ice_name">Kontaktperson *</label>
                <input type="text" id="ice_name" name="ice_name"
                       value="<?= htmlspecialchars($currentUser['ice_name'] ?? '') ?>"
                       placeholder="Namn på anhörig" required>
            </div>
            <div class="form-group">
                <label for="ice_phone">Telefon (ICE) *</label>
                <input type="tel" id="ice_phone" name="ice_phone"
                       value="<?= htmlspecialchars($currentUser['ice_phone'] ?? '') ?>"
                       placeholder="07X XXX XX XX" required>
            </div>
        </div>
    </div>

    <div class="form-section">
        <h2>Leveransadress</h2>
        <p class="form-help">Används vid köp för leverans och kvitton. Sparas för framtida köp.</p>

        <div class="form-group">
            <label for="address">Adress</label>
            <input type="text" id="address" name="address"
                   value="<?= htmlspecialchars($currentUser['address'] ?? '') ?>"
                   placeholder="Gatuadress 123">
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="postal_code">Postnummer</label>
                <input type="text" id="postal_code" name="postal_code"
                       value="<?= htmlspecialchars($currentUser['postal_code'] ?? '') ?>"
                       placeholder="123 45"
                       pattern="[0-9\s]{5,6}"
                       maxlength="6">
            </div>
            <div class="form-group">
                <label for="postal_city">Postort</label>
                <input type="text" id="postal_city" name="postal_city"
                       value="<?= htmlspecialchars($currentUser['postal_city'] ?? '') ?>"
                       placeholder="Stockholm">
            </div>
        </div>
    </div>

    <div class="form-section">
        <h2>Sociala profiler</h2>
        <p class="form-help">Länka dina sociala profiler så att andra kan hitta dig.</p>

        <div class="form-group">
            <label for="social_instagram">
                <span class="social-icon instagram">📷</span> Instagram
            </label>
            <input type="text" id="social_instagram" name="social_instagram"
                   value="<?= htmlspecialchars($currentUser['social_instagram'] ?? '') ?>"
                   placeholder="användarnamn (utan @)">
        </div>

        <div class="form-group">
            <label for="social_strava">
                <i data-lucide="bike" class="social-icon strava"></i> Strava
            </label>
            <input type="text" id="social_strava" name="social_strava"
                   value="<?= htmlspecialchars($currentUser['social_strava'] ?? '') ?>"
                   placeholder="athlete ID eller profil-URL">
        </div>

        <div class="form-group">
            <label for="social_facebook">
                <i data-lucide="facebook" class="social-icon facebook"></i> Facebook
            </label>
            <input type="text" id="social_facebook" name="social_facebook"
                   value="<?= htmlspecialchars($currentUser['social_facebook'] ?? '') ?>"
                   placeholder="profil-URL eller användarnamn">
        </div>

        <div class="form-group">
            <label for="social_youtube">
                <span class="social-icon youtube">🎬</span> YouTube
            </label>
            <input type="text" id="social_youtube" name="social_youtube"
                   value="<?= htmlspecialchars($currentUser['social_youtube'] ?? '') ?>"
                   placeholder="@kanal eller kanal-ID">
        </div>

        <div class="form-group">
            <label for="social_tiktok">
                <span class="social-icon tiktok">🎵</span> TikTok
            </label>
            <input type="text" id="social_tiktok" name="social_tiktok"
                   value="<?= htmlspecialchars($currentUser['social_tiktok'] ?? '') ?>"
                   placeholder="användarnamn (utan @)">
        </div>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn btn--primary">Spara ändringar</button>
        <a href="/profile" class="btn btn-outline">Avbryt</a>
    </div>
</form>

<!-- SPONSOR MANAGEMENT (Premium only) -->
<?php if ($isPremium): ?>
<div class="form-section" id="sponsorSection">
    <div style="display: flex; align-items: center; gap: var(--space-sm); margin-bottom: var(--space-sm);">
        <div style="width: 28px; height: 28px; border-radius: var(--radius-full); background: linear-gradient(135deg, #fbbf24, #f59e0b); display: flex; align-items: center; justify-content: center;">
            <i data-lucide="crown" style="width: 14px; height: 14px; color: #1a1a1a;"></i>
        </div>
        <h2 style="margin: 0;">Mina sponsorer</h2>
        <span class="badge badge-warning" style="font-size: 0.7rem;">Premium</span>
    </div>
    <p class="form-help">Lägg till dina personliga sponsorer. De visas på din profilsida. Max 6 sponsorer.</p>

    <div id="sponsorList" class="sponsor-manage-list">
        <?php foreach ($riderSponsors as $sponsor): ?>
        <div class="sponsor-manage-item" data-id="<?= $sponsor['id'] ?>">
            <div class="sponsor-manage-info">
                <?php if ($sponsor['logo_url']): ?>
                <img src="<?= htmlspecialchars($sponsor['logo_url']) ?>" alt="" class="sponsor-manage-thumb">
                <?php else: ?>
                <div class="sponsor-manage-thumb sponsor-manage-thumb-text">
                    <?= strtoupper(substr($sponsor['name'], 0, 2)) ?>
                </div>
                <?php endif; ?>
                <div>
                    <strong><?= htmlspecialchars($sponsor['name']) ?></strong>
                    <?php if ($sponsor['website_url']): ?>
                    <small class="text-muted" style="display: block;"><?= htmlspecialchars($sponsor['website_url']) ?></small>
                    <?php endif; ?>
                </div>
            </div>
            <button type="button" class="btn-sponsor-remove" onclick="removeSponsor(<?= $sponsor['id'] ?>)" title="Ta bort">
                <i data-lucide="trash-2"></i>
            </button>
        </div>
        <?php endforeach; ?>
    </div>

    <?php if (count($riderSponsors) < 6): ?>
    <div id="addSponsorForm" class="sponsor-add-form">
        <h3 style="font-size: 0.95rem; margin-bottom: var(--space-sm);">Lägg till sponsor</h3>

        <!-- Option 1: Pick from media library -->
        <div style="margin-bottom: var(--space-md); padding: var(--space-md); background: var(--color-bg-hover); border-radius: var(--radius-md);">
            <p class="form-help" style="margin-bottom: var(--space-sm);">Välj en befintlig sponsorlogotyp eller ladda upp en ny:</p>
            <button type="button" class="btn btn-secondary btn-sm" onclick="openRiderImgPicker()">
                <i data-lucide="image-plus"></i> Välj bild från biblioteket
            </button>
            <div id="selectedSponsorImg" style="margin-top: var(--space-sm);"></div>
            <input type="hidden" id="sponsorLogoFromMedia" value="">
        </div>

        <!-- Sponsor details -->
        <div class="form-group">
            <label>Sponsornamn *</label>
            <input type="text" id="sponsorName" placeholder="T.ex. Fox Racing" maxlength="150">
        </div>
        <div class="form-group">
            <label>Logotyp (URL) <small class="text-muted">- eller välj bild ovan</small></label>
            <input type="url" id="sponsorLogo" placeholder="https://example.com/logo.png">
        </div>
        <div class="form-group">
            <label>Webbplats *</label>
            <input type="url" id="sponsorWebsite" placeholder="https://www.example.com" required>
            <small class="form-help">Länk till sponsorns webbplats (krävs).</small>
        </div>
        <button type="button" class="btn btn-secondary" onclick="addSponsor()" id="addSponsorBtn">
            <i data-lucide="plus"></i> Lägg till
        </button>
    </div>
    <?php endif; ?>

    <!-- Rider Image Picker Modal -->
    <div id="riderImgPickerModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;z-index:9999;background:rgba(0,0,0,0.6);">
        <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:92%;max-width:700px;background:var(--color-bg-surface);border-radius:var(--radius-lg);border:1px solid var(--color-border);max-height:85vh;display:flex;flex-direction:column;">
            <div style="padding:var(--space-md) var(--space-lg);border-bottom:1px solid var(--color-border);display:flex;justify-content:space-between;align-items:center;">
                <h3 style="margin:0;font-size:1rem;">Välj sponsorlogotyp</h3>
                <button type="button" onclick="closeRiderImgPicker()" style="background:none;border:none;cursor:pointer;color:var(--color-text-secondary);font-size:1.5rem;line-height:1;">&times;</button>
            </div>
            <div style="padding:var(--space-md);border-bottom:1px solid var(--color-border);display:flex;gap:var(--space-sm);align-items:center;flex-wrap:wrap;">
                <input type="file" id="riderImgUpload" accept="image/*" style="display:none" onchange="riderUploadAndPick(this)">
                <button type="button" class="btn btn-sm btn-primary" onclick="document.getElementById('riderImgUpload').click()">
                    <i data-lucide="upload" class="icon-sm"></i> Ladda upp ny bild
                </button>
                <span class="text-secondary text-sm">eller välj en befintlig nedan</span>
            </div>
            <div id="riderImgPickerGrid" style="padding:var(--space-md);overflow-y:auto;flex:1;display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:var(--space-sm);"></div>
        </div>
    </div>
</div>
<?php else: ?>
<!-- Premium upsell - hidden until Premium is activated -->
<?php endif; ?>

<!-- Avatar Upload Styles -->
<style>
.avatar-section {
    margin-bottom: var(--space-lg);
}

.avatar-upload-wrapper {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: var(--space-md);
    padding: var(--space-lg) 0;
}

.avatar-upload-container {
    position: relative;
    cursor: pointer;
}

.avatar-preview {
    border-radius: 50%;
    overflow: hidden;
    background: var(--color-accent, #61CE70);
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: var(--shadow-md);
    transition: transform 0.3s ease, box-shadow 0.3s ease;
}

.avatar-upload-container:hover .avatar-preview {
    transform: scale(1.02);
    box-shadow: var(--shadow-lg);
}

.avatar-image {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.avatar-fallback {
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--color-accent, #61CE70);
    color: #ffffff;
}

.avatar-initials {
    font-size: 4rem;
    font-weight: 700;
    text-transform: uppercase;
}

.avatar-upload-overlay {
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

.avatar-upload-container:hover .avatar-upload-overlay {
    opacity: 1;
}

.avatar-upload-overlay i,
.avatar-upload-overlay svg {
    color: #ffffff;
    width: 48px;
    height: 48px;
}

.avatar-upload-input {
    position: absolute;
    inset: 0;
    opacity: 0;
    cursor: pointer;
    border-radius: 50%;
}

.avatar-loading {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(0, 0, 0, 0.6);
    border-radius: 50%;
}

.avatar-loading::after {
    content: '';
    width: 48px;
    height: 48px;
    border: 4px solid rgba(255, 255, 255, 0.3);
    border-top-color: #ffffff;
    border-radius: 50%;
    animation: avatar-spin 0.8s linear infinite;
}

@keyframes avatar-spin {
    to { transform: rotate(360deg); }
}

.avatar-upload-info {
    text-align: center;
}

.avatar-upload-status {
    padding: var(--space-sm) var(--space-md);
    border-radius: var(--radius-sm);
    text-align: center;
    max-width: 300px;
}

.avatar-upload-status.success {
    background: rgba(97, 206, 112, 0.1);
    color: var(--color-success, #61CE70);
    border: 1px solid var(--color-success, #61CE70);
}

.avatar-upload-status.error {
    background: rgba(239, 68, 68, 0.1);
    color: var(--color-danger, #ef4444);
    border: 1px solid var(--color-danger, #ef4444);
}

/* Responsive */
@media (min-width: 768px) {
    .avatar-upload-wrapper {
        flex-direction: row;
        justify-content: flex-start;
        gap: var(--space-xl);
    }

    .avatar-upload-info {
        text-align: left;
    }
}

/* Form row for side-by-side inputs */
.form-row {
    display: grid;
    grid-template-columns: 1fr;
    gap: var(--space-md);
}

@media (min-width: 768px) {
    .form-row {
        grid-template-columns: 1fr 1fr;
    }
}

/* Disabled input styling */
.input-disabled,
input:disabled {
    background: var(--color-bg-sunken, #f5f5f5);
    color: var(--color-text-secondary, #6b7280);
    cursor: not-allowed;
    opacity: 0.7;
}

/* Form section spacing */
.form-section {
    margin-bottom: var(--space-xl);
    padding-bottom: var(--space-lg);
    border-bottom: 1px solid var(--color-border, #e5e7eb);
}

.form-section:last-of-type {
    border-bottom: none;
}

.form-section h2 {
    margin-bottom: var(--space-sm);
    font-size: 1.125rem;
}

.form-help {
    color: var(--color-text-secondary, #6b7280);
    font-size: 0.875rem;
    margin-bottom: var(--space-md);
}

.form-group small.form-help {
    margin-top: var(--space-xs);
    margin-bottom: 0;
    display: block;
}
</style>

<!-- Avatar Upload JavaScript -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const avatarInput = document.getElementById('avatarInput');
    const avatarPreview = document.getElementById('avatarPreview');
    const avatarLoading = document.getElementById('avatarLoading');
    const avatarStatus = document.getElementById('avatarStatus');

    if (!avatarInput) return;

    avatarInput.addEventListener('change', async function(e) {
        const file = e.target.files[0];
        if (!file) return;

        // Validate file type
        const allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!allowedTypes.includes(file.type)) {
            showStatus('Otillåten filtyp. Välj JPG, PNG, GIF eller WebP.', 'error');
            return;
        }

        // Validate file size (2MB max)
        const maxSize = 2 * 1024 * 1024;
        if (file.size > maxSize) {
            showStatus('Filen är för stor. Max 2MB tillåten.', 'error');
            return;
        }

        // Show preview immediately
        const reader = new FileReader();
        reader.onload = function(e) {
            showPreviewImage(e.target.result);
        };
        reader.readAsDataURL(file);

        // Show loading state
        avatarLoading.style.display = 'flex';
        hideStatus();

        // Upload to server
        const formData = new FormData();
        formData.append('avatar', file);

        try {
            const response = await fetch('/api/update-avatar.php', {
                method: 'POST',
                body: formData
            });

            const result = await response.json();

            if (result.success) {
                showStatus('Profilbilden har uppdaterats!', 'success');
                // Update the preview with the ImgBB URL
                if (result.avatar_url) {
                    showPreviewImage(result.avatar_url);
                }
            } else {
                showStatus(result.error || 'Något gick fel', 'error');
            }
        } catch (error) {
            console.error('Avatar upload error:', error);
            showStatus('Kunde inte ladda upp bilden. Försök igen.', 'error');
        } finally {
            avatarLoading.style.display = 'none';
        }
    });

    function showPreviewImage(src) {
        // Remove existing content
        avatarPreview.innerHTML = '';

        // Create and add new image
        const img = document.createElement('img');
        img.src = src;
        img.alt = 'Din profilbild';
        img.className = 'avatar-image';
        img.id = 'avatarImage';
        avatarPreview.appendChild(img);
    }

    function showStatus(message, type) {
        avatarStatus.textContent = message;
        avatarStatus.className = 'avatar-upload-status ' + type;
        avatarStatus.style.display = 'block';

        // Auto-hide success messages after 5 seconds
        if (type === 'success') {
            setTimeout(hideStatus, 5000);
        }
    }

    function hideStatus() {
        avatarStatus.style.display = 'none';
    }

    // Reinitialize Lucide icons for the camera icon
    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }
});
</script>

<!-- Sponsor Management Styles & Scripts -->
<?php if ($isPremium): ?>
<style>
.sponsor-manage-list {
    display: flex;
    flex-direction: column;
    gap: var(--space-xs);
    margin-bottom: var(--space-md);
}
.sponsor-manage-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: var(--space-sm);
    background: var(--color-bg-hover);
    border-radius: var(--radius-sm);
    border: 1px solid var(--color-border);
}
.sponsor-manage-info {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    min-width: 0;
}
.sponsor-manage-info div {
    min-width: 0;
}
.sponsor-manage-info strong {
    display: block;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.sponsor-manage-info small {
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 200px;
}
.sponsor-manage-thumb {
    width: 40px;
    height: 40px;
    border-radius: var(--radius-sm);
    object-fit: contain;
    background: var(--color-bg-surface);
    flex-shrink: 0;
}
.sponsor-manage-thumb-text {
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 0.75rem;
    color: var(--color-text-muted);
    border: 1px solid var(--color-border);
}
.btn-sponsor-remove {
    background: none;
    border: none;
    color: var(--color-text-muted);
    cursor: pointer;
    padding: var(--space-xs);
    border-radius: var(--radius-sm);
    transition: color 0.2s;
}
.btn-sponsor-remove:hover {
    color: var(--color-error);
}
.btn-sponsor-remove i { width: 16px; height: 16px; }
.sponsor-add-form {
    padding: var(--space-md);
    background: var(--color-bg-hover);
    border-radius: var(--radius-md);
    border: 1px dashed var(--color-border);
}
</style>
<script>
async function addSponsor() {
    const name = document.getElementById('sponsorName').value.trim();
    const logoUrl = document.getElementById('sponsorLogo').value.trim();
    const mediaLogo = document.getElementById('sponsorLogoFromMedia').value.trim();
    const website = document.getElementById('sponsorWebsite').value.trim();
    const finalLogo = mediaLogo || logoUrl;

    if (!name) {
        alert('Sponsornamn krävs');
        return;
    }
    if (!website) {
        alert('Webbplats krävs');
        return;
    }

    const btn = document.getElementById('addSponsorBtn');
    btn.disabled = true;
    btn.textContent = 'Sparar...';

    try {
        const res = await fetch('/api/rider-sponsors.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'add', name, logo_url: finalLogo, website_url: website })
        });
        const data = await res.json();

        if (data.success) {
            location.reload();
        } else {
            alert(data.error || 'Kunde inte lägga till sponsor');
            btn.disabled = false;
            btn.innerHTML = '<i data-lucide="plus"></i> Lägg till';
            if (typeof lucide !== 'undefined') lucide.createIcons();
        }
    } catch (e) {
        alert('Ett fel uppstod');
        btn.disabled = false;
        btn.innerHTML = '<i data-lucide="plus"></i> Lägg till';
        if (typeof lucide !== 'undefined') lucide.createIcons();
    }
}

async function removeSponsor(id) {
    if (!confirm('Ta bort denna sponsor?')) return;

    try {
        const res = await fetch('/api/rider-sponsors.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'remove', sponsor_id: id })
        });
        const data = await res.json();

        if (data.success) {
            const item = document.querySelector(`.sponsor-manage-item[data-id="${id}"]`);
            if (item) item.remove();
        } else {
            alert(data.error || 'Kunde inte ta bort sponsor');
        }
    } catch (e) {
        alert('Ett fel uppstod');
    }
}

// Rider Image Picker
function openRiderImgPicker() {
    loadRiderImgGrid();
    document.getElementById('riderImgPickerModal').style.display = 'block';
}

function closeRiderImgPicker() {
    document.getElementById('riderImgPickerModal').style.display = 'none';
}

async function loadRiderImgGrid() {
    const grid = document.getElementById('riderImgPickerGrid');
    grid.innerHTML = '<p style="color:var(--color-text-secondary);text-align:center;grid-column:1/-1;">Laddar bilder...</p>';
    try {
        const response = await fetch('/api/media.php?action=list&folder=sponsors&subfolders=1&limit=200');
        if (!response.ok) throw new Error('HTTP ' + response.status);
        const result = await response.json();
        grid.innerHTML = '';
        if (!result.success || !Array.isArray(result.data) || !result.data.length) {
            grid.innerHTML = '<p style="color:var(--color-text-secondary);text-align:center;grid-column:1/-1;">Inga bilder tillgängliga. Ladda upp en ny bild.</p>';
            return;
        }
        result.data.forEach(function(media) {
            if (!media.mime_type || !media.mime_type.startsWith('image/')) return;
            const imgSrc = media.url || ('/' + media.filepath);
            const div = document.createElement('div');
            div.style.cssText = 'cursor:pointer;border:2px solid var(--color-border);border-radius:var(--radius-sm);overflow:hidden;aspect-ratio:4/3;display:flex;flex-direction:column;align-items:center;justify-content:center;background:var(--color-bg-card);transition:border-color 0.15s;';
            div.innerHTML = '<img src="' + imgSrc + '" style="width:100%;flex:1;object-fit:contain;padding:4px;" onerror="this.style.display=\'none\'">'
                + '<span style="font-size:0.6rem;color:var(--color-text-muted);padding:2px 4px;text-align:center;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;width:100%;">' + (media.original_filename||'').substring(0,20) + '</span>';
            div.onmouseover = function() { div.style.borderColor = 'var(--color-accent)'; };
            div.onmouseout = function() { div.style.borderColor = 'var(--color-border)'; };
            div.onclick = function() { selectRiderSponsorImg(imgSrc, media.original_filename); };
            grid.appendChild(div);
        });
    } catch (e) {
        console.error('Rider image picker error:', e);
        grid.innerHTML = '<p style="color:var(--color-error);text-align:center;grid-column:1/-1;">Kunde inte ladda bilder</p>';
    }
}

async function riderUploadAndPick(input) {
    if (!input.files.length) return;
    const formData = new FormData();
    formData.append('file', input.files[0]);
    formData.append('folder', 'sponsors');
    try {
        const response = await fetch('/api/media.php?action=upload', { method: 'POST', body: formData });
        const result = await response.json();
        if (result.success && result.data) {
            const url = result.data.url || ('/' + result.data.filepath);
            selectRiderSponsorImg(url, result.data.original_filename || '');
        } else {
            alert('Kunde inte ladda upp: ' + (result.error || 'Okänt fel'));
        }
    } catch (e) {
        alert('Uppladdningsfel');
    }
    input.value = '';
}

function selectRiderSponsorImg(imgUrl, filename) {
    document.getElementById('sponsorLogoFromMedia').value = imgUrl;
    document.getElementById('sponsorLogo').value = '';
    document.getElementById('selectedSponsorImg').innerHTML =
        '<div style="display:flex;align-items:center;gap:var(--space-sm);padding:var(--space-xs);border:1px solid var(--color-border);border-radius:var(--radius-sm);background:var(--color-bg-card);max-width:200px;">'
        + '<img src="' + imgUrl + '" style="max-height:40px;max-width:100px;object-fit:contain;">'
        + '<span style="font-size:0.8rem;color:var(--color-text-secondary);">' + (filename||'').substring(0,20) + '</span>'
        + '<button type="button" onclick="clearRiderSponsorImg()" style="background:none;border:none;cursor:pointer;color:var(--color-text-muted);font-size:1.2rem;">&times;</button>'
        + '</div>';
    // Auto-fill name from filename if empty
    const nameInput = document.getElementById('sponsorName');
    if (!nameInput.value.trim() && filename) {
        const autoName = filename.replace(/\.[^.]+$/, '').replace(/[-_]/g, ' ');
        nameInput.value = autoName.charAt(0).toUpperCase() + autoName.slice(1);
    }
    closeRiderImgPicker();
}

function clearRiderSponsorImg() {
    document.getElementById('sponsorLogoFromMedia').value = '';
    document.getElementById('selectedSponsorImg').innerHTML = '';
}
</script>
<?php endif; ?>

<!-- CSS loaded from /assets/css/pages/profile-edit.css -->

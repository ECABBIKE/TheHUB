<?php
/**
 * TheHUB V3.5 - Edit Profile with Social Profiles
 */

$currentUser = hub_current_user();
if (!$currentUser) {
    header('Location: /profile/login');
    exit;
}

$pdo = hub_db();
$message = '';
$error = '';

// Include social profile sanitizer
$rebuildPath = dirname(dirname(__DIR__)) . '/includes/rebuild-rider-stats.php';
if (file_exists($rebuildPath)) {
    require_once $rebuildPath;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $firstName = trim($_POST['firstname'] ?? '');
    $lastName = trim($_POST['lastname'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $clubId = intval($_POST['club_id'] ?? 0) ?: null;

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
            $stmt = $pdo->prepare("
                UPDATE riders
                SET firstname = ?, lastname = ?, email = ?, club_id = ?,
                    social_instagram = ?, social_strava = ?, social_facebook = ?,
                    social_youtube = ?, social_tiktok = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $firstName, $lastName, $email, $clubId,
                $socialInstagram ?: null, $socialStrava ?: null, $socialFacebook ?: null,
                $socialYoutube ?: null, $socialTiktok ?: null,
                $currentUser['id']
            ]);
            $message = 'Profilen har uppdaterats!';

            // Refresh user data
            $currentUser = hub_get_rider_by_id($currentUser['id']);
        } catch (PDOException $e) {
            $error = 'Kunde inte uppdatera profilen: ' . $e->getMessage();
            error_log("Profile update error: " . $e->getMessage());
        }
    }
}

// Get clubs for dropdown
$clubs = $pdo->query("SELECT id, name FROM clubs ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="page-header">
    <nav class="breadcrumb">
        <a href="/profile">Min Sida</a>
        <span class="breadcrumb-sep">›</span>
        <span>Redigera profil</span>
    </nav>
    <h1 class="page-title">Redigera profil</h1>
</div>

<?php if ($message): ?>
    <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<form method="POST" class="profile-form">
    <div class="form-section">
        <h2>Personuppgifter</h2>

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

        <div class="form-group">
            <label for="email">E-post</label>
            <input type="email" id="email" name="email"
                   value="<?= htmlspecialchars($currentUser['email'] ?? '') ?>">
        </div>
    </div>

    <div class="form-section">
        <h2>Klubb</h2>

        <div class="form-group">
            <label for="club_id">Välj klubb</label>
            <select id="club_id" name="club_id">
                <option value="">Ingen klubb</option>
                <?php foreach ($clubs as $club): ?>
                    <option value="<?= $club['id'] ?>" <?= $currentUser['club_id'] == $club['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($club['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
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
                <span class="social-icon strava">🚴</span> Strava
            </label>
            <input type="text" id="social_strava" name="social_strava"
                   value="<?= htmlspecialchars($currentUser['social_strava'] ?? '') ?>"
                   placeholder="athlete ID eller profil-URL">
        </div>

        <div class="form-group">
            <label for="social_facebook">
                <span class="social-icon facebook">👤</span> Facebook
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
        <button type="submit" class="btn btn-primary">Spara ändringar</button>
        <a href="/profile" class="btn btn-outline">Avbryt</a>
    </div>
</form>


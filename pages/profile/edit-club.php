<?php
/**
 * TheHUB - Redigera klubb (Club Admin)
 * Allows club admins to edit their club's information
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
    $city = trim($_POST['city'] ?? '');
    $region = trim($_POST['region'] ?? '');
    $website = trim($_POST['website'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $logoUrl = trim($_POST['logo_url'] ?? '');

    if (empty($name)) {
        $message = 'Klubbnamn krävs.';
        $messageType = 'error';
    } else {
        try {
            // Check if logo_url column exists
            $hasLogoUrl = false;
            try {
                $colCheck = $pdo->query("SHOW COLUMNS FROM clubs LIKE 'logo_url'");
                $hasLogoUrl = $colCheck->rowCount() > 0;
            } catch (PDOException $e) {}

            // Check if description column exists
            $hasDescription = false;
            try {
                $colCheck = $pdo->query("SHOW COLUMNS FROM clubs LIKE 'description'");
                $hasDescription = $colCheck->rowCount() > 0;
            } catch (PDOException $e) {}

            $sql = "UPDATE clubs SET name = ?, city = ?, region = ?, website = ?";
            $params = [$name, $city, $region, $website];

            if ($hasLogoUrl) {
                $sql .= ", logo_url = ?";
                $params[] = $logoUrl ?: null;
            }
            if ($hasDescription) {
                $sql .= ", description = ?";
                $params[] = $description ?: null;
            }

            $sql .= " WHERE id = ?";
            $params[] = $clubId;

            $updateStmt = $pdo->prepare($sql);
            $updateStmt->execute($params);

            $message = 'Klubbinformationen har sparats.';
            $messageType = 'success';

            // Refresh club data
            $stmt->execute([$clubId]);
            $club = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $message = 'Kunde inte spara ändringar.';
            $messageType = 'error';
        }
    }
}
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

<div class="card">
    <div class="card-body">
        <form method="POST" action="">
            <div class="form-group">
                <label class="form-label">Klubbnamn *</label>
                <input type="text" name="name" class="form-input" required
                       value="<?= htmlspecialchars($club['name'] ?? '') ?>">
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Ort</label>
                    <input type="text" name="city" class="form-input"
                           value="<?= htmlspecialchars($club['city'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Region</label>
                    <input type="text" name="region" class="form-input"
                           value="<?= htmlspecialchars($club['region'] ?? '') ?>"
                           placeholder="t.ex. Västra Götaland">
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Webbplats</label>
                <input type="url" name="website" class="form-input"
                       value="<?= htmlspecialchars($club['website'] ?? '') ?>"
                       placeholder="https://...">
            </div>

            <?php
            // Check if logo_url column exists
            $hasLogoUrl = false;
            try {
                $colCheck = $pdo->query("SHOW COLUMNS FROM clubs LIKE 'logo_url'");
                $hasLogoUrl = $colCheck->rowCount() > 0;
            } catch (PDOException $e) {}
            if ($hasLogoUrl):
            ?>
            <div class="form-group">
                <label class="form-label">Logotyp URL</label>
                <input type="url" name="logo_url" class="form-input"
                       value="<?= htmlspecialchars($club['logo_url'] ?? '') ?>"
                       placeholder="https://...">
                <?php if (!empty($club['logo_url'])): ?>
                    <div style="margin-top: var(--space-xs);">
                        <img src="<?= htmlspecialchars($club['logo_url']) ?>" alt="Logotyp"
                             style="max-height: 60px; max-width: 200px; border-radius: var(--radius-sm);">
                    </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php
            // Check if description column exists
            $hasDescription = false;
            try {
                $colCheck = $pdo->query("SHOW COLUMNS FROM clubs LIKE 'description'");
                $hasDescription = $colCheck->rowCount() > 0;
            } catch (PDOException $e) {}
            if ($hasDescription):
            ?>
            <div class="form-group">
                <label class="form-label">Beskrivning</label>
                <textarea name="description" class="form-textarea" rows="4"
                          placeholder="Beskriv klubben..."><?= htmlspecialchars($club['description'] ?? '') ?></textarea>
            </div>
            <?php endif; ?>

            <div class="form-actions" style="display: flex; gap: var(--space-sm); margin-top: var(--space-lg);">
                <button type="submit" class="btn btn-primary">
                    <i data-lucide="save"></i> Spara ändringar
                </button>
                <a href="/profile/club-admin?club=<?= $clubId ?>" class="btn btn-secondary">Avbryt</a>
            </div>
        </form>
    </div>
</div>

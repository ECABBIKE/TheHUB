<?php
/**
 * TheHUB V3.5 - Edit Profile
 */

$currentUser = hub_current_user();
if (!$currentUser) {
    header('Location: /profile/login');
    exit;
}

$pdo = hub_db();
$message = '';
$error = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $firstName = trim($_POST['firstname'] ?? '');
    $lastName = trim($_POST['lastname'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $clubId = intval($_POST['club_id'] ?? 0) ?: null;

    if (empty($firstName) || empty($lastName)) {
        $error = 'Förnamn och efternamn krävs.';
    } else {
        try {
            $stmt = $pdo->prepare("
                UPDATE riders
                SET firstname = ?, lastname = ?, email = ?, phone = ?, club_id = ?
                WHERE id = ?
            ");
            $stmt->execute([$firstName, $lastName, $email, $phone, $clubId, $currentUser['id']]);
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

        <div class="form-group">
            <label for="phone">Telefon</label>
            <input type="tel" id="phone" name="phone"
                   value="<?= htmlspecialchars($currentUser['phone'] ?? '') ?>">
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

    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Spara ändringar</button>
        <a href="/profile" class="btn btn-outline">Avbryt</a>
    </div>
</form>

<style>
.profile-form {
    max-width: 600px;
}
.form-section {
    background: var(--color-bg-card);
    border-radius: var(--radius-lg);
    padding: var(--space-lg);
    margin-bottom: var(--space-lg);
}
.form-section h2 {
    font-size: var(--text-lg);
    margin-bottom: var(--space-lg);
    padding-bottom: var(--space-sm);
    border-bottom: 1px solid var(--color-border);
}
.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: var(--space-md);
}
.form-group {
    margin-bottom: var(--space-md);
}
.form-group label {
    display: block;
    margin-bottom: var(--space-xs);
    font-weight: var(--weight-medium);
    font-size: var(--text-sm);
}
.form-group input,
.form-group select {
    width: 100%;
    padding: var(--space-md);
    background: var(--color-bg-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    font-size: var(--text-md);
    color: var(--color-text-primary);
}
.form-group input:focus,
.form-group select:focus {
    outline: none;
    border-color: var(--color-accent);
}
.form-actions {
    display: flex;
    gap: var(--space-md);
}
.btn {
    padding: var(--space-md) var(--space-xl);
    border-radius: var(--radius-md);
    font-weight: var(--weight-medium);
    cursor: pointer;
    text-decoration: none;
    border: none;
}
.btn-primary {
    background: var(--color-accent);
    color: white;
}
.btn-outline {
    background: transparent;
    border: 1px solid var(--color-border);
    color: var(--color-text-primary);
}
.alert {
    padding: var(--space-md);
    border-radius: var(--radius-md);
    margin-bottom: var(--space-lg);
}
.alert-success {
    background: var(--color-success-bg, rgba(34, 197, 94, 0.1));
    color: var(--color-success, #22c55e);
}
.alert-error {
    background: var(--color-error-bg, rgba(239, 68, 68, 0.1));
    color: var(--color-error, #ef4444);
}
@media (max-width: 600px) {
    .form-row { grid-template-columns: 1fr; }
}
</style>

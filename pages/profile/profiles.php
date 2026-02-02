<?php
/**
 * TheHUB V1.0 - Mina Profiler (My Profiles)
 * Shows all rider profiles linked to the same email
 * Allows switching between profiles and managing them
 */

require_once __DIR__ . '/../../includes/rider-auth.php';

$currentUser = hub_current_user();
if (!$currentUser) {
    header('Location: /profile/login');
    exit;
}

$pdo = hub_db();

// Handle profile switch
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['switch_profile'])) {
    $switchToId = (int)$_POST['rider_id'];
    if (rider_switch_profile($switchToId)) {
        header('Location: /profile/profiles?switched=1');
        exit;
    }
}

// Get all linked profiles
$linkedProfiles = get_rider_linked_profiles();

// Get current rider ID from various session types
$currentRiderId = $_SESSION['rider_id'] ?? $_SESSION['admin_id'] ?? $_SESSION['hub_user_id'] ?? $currentUser['id'] ?? 0;
$message = $_GET['switched'] ?? '';

// Get additional info for each profile
$profilesWithDetails = [];
foreach ($linkedProfiles as $profile) {
    $details = $pdo->prepare("
        SELECT r.*, c.name as club_name,
               (SELECT COUNT(*) FROM results WHERE cyclist_id = r.id) as result_count,
               (SELECT COUNT(*) FROM event_registrations WHERE rider_id = r.id AND status != 'cancelled') as registration_count
        FROM riders r
        LEFT JOIN clubs c ON r.club_id = c.id
        WHERE r.id = ?
    ");
    $details->execute([$profile['id']]);
    $fullProfile = $details->fetch(PDO::FETCH_ASSOC);
    if ($fullProfile) {
        $profilesWithDetails[] = $fullProfile;
    }
}
?>

<div class="page-header">
    <nav class="breadcrumb">
        <a href="/profile">Min Sida</a>
        <span class="breadcrumb-sep">›</span>
        <span>Mina profiler</span>
    </nav>
    <h1 class="page-title">
        <i data-lucide="users" class="page-icon"></i>
        Mina profiler
    </h1>
    <p class="page-subtitle">
        Alla profiler kopplade till <?= htmlspecialchars($currentUser['email']) ?>
    </p>
</div>

<?php if ($message === '1'): ?>
    <div class="alert alert-success">
        <i data-lucide="check-circle"></i>
        Profil bytt!
    </div>
<?php endif; ?>

<?php if (count($profilesWithDetails) > 1): ?>
    <div class="info-box mb-lg">
        <i data-lucide="info"></i>
        <div>
            <strong>Du har <?= count($profilesWithDetails) ?> profiler</strong><br>
            Klicka på "Byt till denna" för att hantera en annan profil.
        </div>
    </div>
<?php endif; ?>

<div class="profiles-list">
    <?php foreach ($profilesWithDetails as $profile): ?>
        <?php $isActive = ($profile['id'] == $currentRiderId); ?>
        <div class="profile-card <?= $isActive ? 'profile-card--active' : '' ?>">
            <div class="profile-card-header">
                <div class="profile-avatar">
                    <?= strtoupper(substr($profile['firstname'], 0, 1) . substr($profile['lastname'], 0, 1)) ?>
                </div>
                <div class="profile-main-info">
                    <h3 class="profile-name">
                        <?= htmlspecialchars($profile['firstname'] . ' ' . $profile['lastname']) ?>
                        <?php if ($isActive): ?>
                            <span class="badge badge-success">Aktiv</span>
                        <?php endif; ?>
                    </h3>
                    <?php if ($profile['birth_year']): ?>
                        <span class="profile-meta">
                            <i data-lucide="calendar" class="icon-xs"></i>
                            Född <?= $profile['birth_year'] ?> (<?= date('Y') - $profile['birth_year'] ?> år)
                        </span>
                    <?php endif; ?>
                    <?php if ($profile['club_name']): ?>
                        <span class="profile-meta">
                            <i data-lucide="shield" class="icon-xs"></i>
                            <?= htmlspecialchars($profile['club_name']) ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="profile-card-stats">
                <div class="stat">
                    <span class="stat-value"><?= $profile['result_count'] ?></span>
                    <span class="stat-label">Resultat</span>
                </div>
                <div class="stat">
                    <span class="stat-value"><?= $profile['registration_count'] ?></span>
                    <span class="stat-label">Anmälningar</span>
                </div>
                <?php if ($profile['license_number']): ?>
                <div class="stat">
                    <span class="stat-value"><i data-lucide="badge-check" class="icon-sm text-success"></i></span>
                    <span class="stat-label">Licens</span>
                </div>
                <?php endif; ?>
            </div>

            <div class="profile-card-actions">
                <?php if ($isActive): ?>
                    <a href="/profile/edit" class="btn btn-primary">
                        <i data-lucide="pencil"></i>
                        Redigera profil
                    </a>
                    <a href="/rider/<?= $profile['id'] ?>" class="btn btn-outline">
                        <i data-lucide="flag"></i>
                        Visa resultat
                    </a>
                <?php else: ?>
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="rider_id" value="<?= $profile['id'] ?>">
                        <button type="submit" name="switch_profile" class="btn btn-primary">
                            <i data-lucide="repeat"></i>
                            Byt till denna
                        </button>
                    </form>
                    <a href="/rider/<?= $profile['id'] ?>" class="btn btn-outline">
                        <i data-lucide="flag"></i>
                        Visa resultat
                    </a>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<?php if (count($profilesWithDetails) === 1): ?>
<div class="empty-state card mt-xl">
    <div class="empty-icon"><i data-lucide="user-plus" class="icon-xl"></i></div>
    <h3>Endast en profil</h3>
    <p>Du har endast en profil kopplad till denna e-postadress. Om du registrerar fler familjemedlemmar med samma e-post kommer de automatiskt att visas här.</p>
</div>
<?php endif; ?>

<style>
.profiles-list {
    display: flex;
    flex-direction: column;
    gap: var(--space-md);
}

.profile-card {
    background: var(--color-bg-card);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-lg);
    padding: var(--space-lg);
    transition: border-color 0.2s, box-shadow 0.2s;
}

.profile-card--active {
    border-color: var(--color-accent);
    box-shadow: 0 0 0 1px var(--color-accent);
}

.profile-card-header {
    display: flex;
    align-items: flex-start;
    gap: var(--space-md);
    margin-bottom: var(--space-md);
}

.profile-avatar {
    width: 56px;
    height: 56px;
    border-radius: var(--radius-full);
    background: var(--color-accent-light);
    color: var(--color-accent);
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
    font-size: 1.1rem;
    flex-shrink: 0;
}

.profile-main-info {
    flex: 1;
}

.profile-name {
    font-size: 1.15rem;
    font-weight: 600;
    margin: 0 0 var(--space-xs) 0;
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    flex-wrap: wrap;
}

.profile-meta {
    display: inline-flex;
    align-items: center;
    gap: var(--space-2xs);
    color: var(--color-text-secondary);
    font-size: 0.9rem;
    margin-right: var(--space-md);
}

.profile-card-stats {
    display: flex;
    gap: var(--space-xl);
    padding: var(--space-md) 0;
    border-top: 1px solid var(--color-border);
    border-bottom: 1px solid var(--color-border);
    margin-bottom: var(--space-md);
}

.stat {
    text-align: center;
}

.stat-value {
    display: block;
    font-size: 1.25rem;
    font-weight: 600;
    color: var(--color-text-primary);
}

.stat-label {
    font-size: 0.8rem;
    color: var(--color-text-muted);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.profile-card-actions {
    display: flex;
    gap: var(--space-sm);
    flex-wrap: wrap;
}

.info-box {
    display: flex;
    align-items: flex-start;
    gap: var(--space-md);
    padding: var(--space-md);
    background: var(--color-accent-light);
    border-radius: var(--radius-md);
    color: var(--color-accent-text);
}

.info-box i {
    flex-shrink: 0;
    margin-top: 2px;
}

@media (max-width: 599px) {
    .profile-card-stats {
        gap: var(--space-md);
    }

    .profile-card-actions {
        flex-direction: column;
    }

    .profile-card-actions .btn {
        width: 100%;
        justify-content: center;
    }
}
</style>

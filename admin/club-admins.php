<?php
/**
 * Club Admins Management
 * Allows admins to assign riders as administrators for clubs
 */
require_once __DIR__ . '/../config.php';
require_admin();

if (!hasRole('admin')) {
    http_response_code(403);
    die('Access denied');
}

$db = getDB();
$currentAdmin = getCurrentAdmin();

$message = '';
$messageType = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'assign_club_admin') {
        $riderId = intval($_POST['rider_id'] ?? 0);
        $clubId = intval($_POST['club_id'] ?? 0);

        if ($riderId && $clubId) {
            try {
                $rider = $db->getRow("SELECT * FROM riders WHERE id = ?", [$riderId]);
                $club = $db->getRow("SELECT * FROM clubs WHERE id = ?", [$clubId]);

                if (!$rider || !$club) {
                    throw new Exception('Rider eller klubb hittades inte');
                }

                // Check if rider has an admin_users account
                $link = $db->getRow("
                    SELECT rp.*, au.id as user_id
                    FROM rider_profiles rp
                    JOIN admin_users au ON rp.user_id = au.id
                    WHERE rp.rider_id = ?
                ", [$riderId]);

                if (!$link) {
                    // Create admin account if rider has email
                    if (empty($rider['email'])) {
                        throw new Exception('Rider saknar e-postadress');
                    }

                    $username = strtolower($rider['firstname'] . '.' . $rider['lastname']);
                    $username = preg_replace('/[^a-z0-9.]/', '', $username);
                    $baseUsername = $username;
                    $counter = 1;
                    while ($db->getRow("SELECT id FROM admin_users WHERE username = ?", [$username])) {
                        $username = $baseUsername . $counter;
                        $counter++;
                    }

                    $password = $rider['password'] ?: password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);

                    $db->insert('admin_users', [
                        'username' => $username,
                        'email' => $rider['email'],
                        'password' => $password,
                        'full_name' => $rider['firstname'] . ' ' . $rider['lastname'],
                        'role' => 'rider',
                        'active' => 1
                    ]);
                    $userId = $db->lastInsertId();

                    $db->insert('rider_profiles', [
                        'user_id' => $userId,
                        'rider_id' => $riderId,
                        'can_edit_profile' => 1,
                        'can_manage_club' => 1,
                        'approved_by' => $currentAdmin['id'],
                        'approved_at' => date('Y-m-d H:i:s')
                    ]);
                } else {
                    $userId = $link['user_id'];
                    // Enable can_manage_club on rider_profiles
                    $db->update('rider_profiles', ['can_manage_club' => 1], 'rider_id = ?', [$riderId]);
                }

                // Check if already assigned
                $existing = $db->getRow("SELECT id FROM club_admins WHERE user_id = ? AND club_id = ?", [$userId, $clubId]);
                if ($existing) {
                    throw new Exception('Denna rider är redan admin för denna klubb');
                }

                // Add to club_admins
                $db->insert('club_admins', [
                    'user_id' => $userId,
                    'club_id' => $clubId,
                    'can_edit_profile' => 1,
                    'can_upload_logo' => 1,
                    'can_manage_members' => 0,
                    'granted_by' => $currentAdmin['id']
                ]);

                $message = $rider['firstname'] . ' ' . $rider['lastname'] . ' är nu admin för ' . $club['name'] . '!';
                $messageType = 'success';
            } catch (Exception $e) {
                $message = 'Fel: ' . $e->getMessage();
                $messageType = 'error';
            }
        }
    } elseif ($action === 'remove_club_admin') {
        $id = intval($_POST['id'] ?? 0);
        if ($id) {
            try {
                $db->delete('club_admins', 'id = ?', [$id]);
                $message = 'Klubb-admin borttagen!';
                $messageType = 'success';
            } catch (Exception $e) {
                $message = 'Fel: ' . $e->getMessage();
                $messageType = 'error';
            }
        }
    }
}

// Get all club admins
$clubAdmins = $db->getAll("
    SELECT
        ca.id,
        ca.club_id,
        ca.user_id,
        ca.can_edit_profile,
        ca.can_upload_logo,
        c.name as club_name,
        c.city as club_city,
        r.id as rider_id,
        r.firstname,
        r.lastname,
        r.email
    FROM club_admins ca
    JOIN clubs c ON ca.club_id = c.id
    JOIN admin_users au ON ca.user_id = au.id
    LEFT JOIN rider_profiles rp ON au.id = rp.user_id
    LEFT JOIN riders r ON rp.rider_id = r.id
    ORDER BY c.name, r.lastname, r.firstname
");

// Get all clubs for dropdown
$clubs = $db->getAll("SELECT id, name, city FROM clubs WHERE active = 1 ORDER BY name");

// Get riders that can become club admins (have email)
$eligibleRiders = $db->getAll("
    SELECT
        r.id,
        r.firstname,
        r.lastname,
        r.email,
        c.id as club_id,
        c.name as club_name
    FROM riders r
    LEFT JOIN clubs c ON r.club_id = c.id
    WHERE r.email IS NOT NULL AND r.email != ''
    AND r.active = 1
    ORDER BY r.lastname, r.firstname
    LIMIT 200
");

$page_title = 'Klubb-administratörer';
$current_admin_page = 'club-admins';
$breadcrumbs = [
    ['label' => 'System', 'url' => '/admin/settings'],
    ['label' => 'Klubb-administratörer']
];

include __DIR__ . '/components/unified-layout.php';
?>

<?php if ($message): ?>
<div class="alert alert--<?= $messageType ?> mb-lg">
    <i data-lucide="<?= $messageType === 'success' ? 'check-circle' : 'alert-circle' ?>"></i>
    <?= h($message) ?>
</div>
<?php endif; ?>

<div class="mb-lg">
    <p class="text-secondary">
        Ge riders behörighet att redigera klubbinformation och ladda upp logotyper.
    </p>
</div>

<style>
/* Mobile-first CSS */
.admin-grid {
    display: flex;
    flex-direction: column;
    gap: var(--space-md);
}

.admin-item {
    display: flex;
    flex-direction: column;
    gap: var(--space-sm);
    padding: var(--space-md);
    background: var(--color-bg);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
}

.admin-item-name {
    font-weight: 600;
}

.admin-item-meta {
    font-size: 0.875rem;
    color: var(--color-text-secondary);
}

.assign-form {
    display: flex;
    flex-direction: column;
    gap: var(--space-md);
}

.assign-form-row {
    display: flex;
    flex-direction: column;
    gap: var(--space-sm);
}

/* Desktop */
@media (min-width: 768px) {
    .admin-item {
        flex-direction: row;
        align-items: center;
        justify-content: space-between;
    }

    .assign-form {
        flex-direction: row;
        align-items: flex-end;
    }

    .assign-form-row {
        flex: 1;
    }
}
</style>

<!-- Add Club Admin Form -->
<div class="card mb-lg">
    <div class="card-header">
        <h2>
            <i data-lucide="plus"></i>
            Lägg till klubb-admin
        </h2>
    </div>
    <div class="card-body">
        <form method="POST" class="assign-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="assign_club_admin">

            <div class="assign-form-row">
                <label class="label">Rider</label>
                <select name="rider_id" class="input" required id="riderSelect">
                    <option value="">Välj rider...</option>
                    <?php foreach ($eligibleRiders as $r): ?>
                    <option value="<?= $r['id'] ?>" data-club="<?= $r['club_id'] ?>">
                        <?= h($r['firstname'] . ' ' . $r['lastname']) ?>
                        <?php if ($r['club_name']): ?>(<?= h($r['club_name']) ?>)<?php endif; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="assign-form-row">
                <label class="label">Klubb</label>
                <select name="club_id" class="input" required id="clubSelect">
                    <option value="">Välj klubb...</option>
                    <?php foreach ($clubs as $c): ?>
                    <option value="<?= $c['id'] ?>">
                        <?= h($c['name']) ?>
                        <?php if ($c['city']): ?>(<?= h($c['city']) ?>)<?php endif; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <button type="submit" class="btn btn--primary">
                    <i data-lucide="plus"></i> Lägg till
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Current Club Admins -->
<div class="card">
    <div class="card-header">
        <h2>
            <i data-lucide="building"></i>
            Klubb-administratörer (<?= count($clubAdmins) ?>)
        </h2>
    </div>
    <div class="card-body">
        <?php if (empty($clubAdmins)): ?>
        <p class="text-secondary">Inga klubb-administratörer ännu.</p>
        <?php else: ?>
        <div class="admin-grid">
            <?php
            $currentClub = null;
            foreach ($clubAdmins as $admin):
                if ($currentClub !== $admin['club_id']):
                    if ($currentClub !== null) echo '</div>';
                    $currentClub = $admin['club_id'];
            ?>
            <h3 class="mt-md mb-sm"><?= h($admin['club_name']) ?></h3>
            <div class="admin-grid">
            <?php endif; ?>
                <div class="admin-item">
                    <div>
                        <div class="admin-item-name">
                            <?php if ($admin['rider_id']): ?>
                            <a href="/admin/rider-edit.php?id=<?= $admin['rider_id'] ?>" class="link">
                                <?= h($admin['firstname'] . ' ' . $admin['lastname']) ?>
                            </a>
                            <?php else: ?>
                            <?= h($admin['email']) ?>
                            <?php endif; ?>
                        </div>
                        <div class="admin-item-meta">
                            <?= h($admin['email']) ?>
                        </div>
                        <div class="flex gap-xs mt-xs">
                            <?php if ($admin['can_edit_profile']): ?>
                            <span class="badge badge-success badge-sm">Redigera</span>
                            <?php endif; ?>
                            <?php if ($admin['can_upload_logo']): ?>
                            <span class="badge badge-success badge-sm">Logo</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div>
                        <form method="POST" style="display: inline;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="remove_club_admin">
                            <input type="hidden" name="id" value="<?= $admin['id'] ?>">
                            <button type="submit" class="btn btn--secondary btn--sm" onclick="return confirm('Ta bort klubb-admin?')">
                                <i data-lucide="x"></i>
                            </button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
// Auto-select rider's club when selecting rider
document.getElementById('riderSelect')?.addEventListener('change', function() {
    const option = this.options[this.selectedIndex];
    const clubId = option?.dataset.club;
    if (clubId) {
        document.getElementById('clubSelect').value = clubId;
    }
});
</script>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>

<?php
/**
 * Role Management - Manage Promotors and User Roles
 * Allows admins to upgrade riders to promotors directly
 */
require_once __DIR__ . '/../config.php';
require_admin();

// Only admin and super_admin can access
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

    if ($action === 'make_promotor') {
        $riderId = intval($_POST['rider_id'] ?? 0);
        if ($riderId) {
            try {
                // Get rider info
                $rider = $db->getRow("SELECT * FROM riders WHERE id = ?", [$riderId]);
                if (!$rider) {
                    throw new Exception('Rider hittades inte');
                }
                if (empty($rider['email'])) {
                    throw new Exception('Rider saknar e-postadress');
                }

                // Check if rider already has an admin_users account
                $existingLink = $db->getRow("
                    SELECT rp.*, au.role, au.id as user_id
                    FROM rider_profiles rp
                    JOIN admin_users au ON rp.user_id = au.id
                    WHERE rp.rider_id = ?
                ", [$riderId]);

                if ($existingLink) {
                    // Just update role
                    $db->update('admin_users', ['role' => 'promotor'], 'id = ?', [$existingLink['user_id']]);
                    $message = $rider['firstname'] . ' ' . $rider['lastname'] . ' är nu Promotör!';
                } else {
                    // Create admin_users account and link
                    $username = strtolower($rider['firstname'] . '.' . $rider['lastname']);
                    $username = preg_replace('/[^a-z0-9.]/', '', $username);

                    // Make username unique
                    $baseUsername = $username;
                    $counter = 1;
                    while ($db->getRow("SELECT id FROM admin_users WHERE username = ?", [$username])) {
                        $username = $baseUsername . $counter;
                        $counter++;
                    }

                    // Create admin user with promotor role
                    // Use rider's password if set, otherwise generate a random one
                    $password = $rider['password'] ?: password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);

                    $db->insert('admin_users', [
                        'username' => $username,
                        'email' => $rider['email'],
                        'password' => $password,
                        'full_name' => $rider['firstname'] . ' ' . $rider['lastname'],
                        'role' => 'promotor',
                        'active' => 1
                    ]);
                    $userId = $db->lastInsertId();

                    // Link to rider
                    $db->insert('rider_profiles', [
                        'user_id' => $userId,
                        'rider_id' => $riderId,
                        'can_edit_profile' => 1,
                        'can_manage_club' => 0,
                        'approved_by' => $currentAdmin['id'],
                        'approved_at' => date('Y-m-d H:i:s')
                    ]);

                    $message = $rider['firstname'] . ' ' . $rider['lastname'] . ' är nu Promotör! Användarnamn: ' . $username;
                }
                $messageType = 'success';
            } catch (Exception $e) {
                $message = 'Fel: ' . $e->getMessage();
                $messageType = 'error';
            }
        }
    } elseif ($action === 'remove_promotor') {
        $riderId = intval($_POST['rider_id'] ?? 0);
        if ($riderId) {
            try {
                $link = $db->getRow("
                    SELECT rp.*, au.id as user_id
                    FROM rider_profiles rp
                    JOIN admin_users au ON rp.user_id = au.id
                    WHERE rp.rider_id = ?
                ", [$riderId]);

                if ($link) {
                    // Remove event assignments
                    $db->delete('promotor_events', 'user_id = ?', [$link['user_id']]);
                    // Change role to rider
                    $db->update('admin_users', ['role' => 'rider'], 'id = ?', [$link['user_id']]);
                    $message = 'Promotör-rollen borttagen!';
                    $messageType = 'success';
                }
            } catch (Exception $e) {
                $message = 'Fel: ' . $e->getMessage();
                $messageType = 'error';
            }
        }
    }
}

// Get current promotors (riders with promotor role)
$promotors = $db->getAll("
    SELECT
        r.id as rider_id,
        r.firstname,
        r.lastname,
        r.email,
        r.license_number,
        c.name as club_name,
        au.id as user_id,
        au.username,
        (SELECT COUNT(*) FROM promotor_events pe WHERE pe.user_id = au.id) as event_count
    FROM riders r
    JOIN rider_profiles rp ON r.id = rp.rider_id
    JOIN admin_users au ON rp.user_id = au.id
    LEFT JOIN clubs c ON r.club_id = c.id
    WHERE au.role = 'promotor'
    ORDER BY r.lastname, r.firstname
");

// Get riders that can become promotors (have email, not already promotor/admin)
$eligibleRiders = $db->getAll("
    SELECT
        r.id,
        r.firstname,
        r.lastname,
        r.email,
        r.license_number,
        c.name as club_name,
        CASE WHEN rp.id IS NOT NULL THEN 1 ELSE 0 END as has_account,
        COALESCE(au.role, 'none') as current_role
    FROM riders r
    LEFT JOIN clubs c ON r.club_id = c.id
    LEFT JOIN rider_profiles rp ON r.id = rp.rider_id
    LEFT JOIN admin_users au ON rp.user_id = au.id
    WHERE r.email IS NOT NULL AND r.email != ''
    AND r.active = 1
    AND (au.role IS NULL OR au.role = 'rider')
    ORDER BY r.lastname, r.firstname
    LIMIT 100
");

$page_title = 'Rollhantering';
$current_admin_page = 'role-management';
$breadcrumbs = [
    ['label' => 'System', 'url' => '/admin/settings'],
    ['label' => 'Rollhantering']
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
        Ge riders promotör-behörighet så de kan hantera tävlingar. Rider-kontot används direkt.
    </p>
</div>

<style>
/* Mobile-first CSS */
.role-grid {
    display: flex;
    flex-direction: column;
    gap: var(--space-md);
}

.role-item {
    display: flex;
    flex-direction: column;
    gap: var(--space-sm);
    padding: var(--space-md);
    background: var(--color-bg);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
}

.role-item-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: var(--space-sm);
}

.role-item-name {
    font-weight: 600;
}

.role-item-meta {
    font-size: 0.875rem;
    color: var(--color-text-secondary);
}

.role-item-actions {
    margin-top: var(--space-sm);
}

/* Desktop */
@media (min-width: 768px) {
    .role-item {
        flex-direction: row;
        align-items: center;
        justify-content: space-between;
    }

    .role-item-actions {
        margin-top: 0;
    }
}
</style>

<!-- Current Promotors -->
<div class="card mb-lg">
    <div class="card-header">
        <h2>
            <i data-lucide="star"></i>
            Promotörer (<?= count($promotors) ?>)
        </h2>
    </div>
    <div class="card-body">
        <?php if (empty($promotors)): ?>
        <p class="text-secondary">Inga promotörer ännu.</p>
        <?php else: ?>
        <div class="role-grid">
            <?php foreach ($promotors as $p): ?>
            <div class="role-item">
                <div>
                    <div class="role-item-header">
                        <div>
                            <div class="role-item-name">
                                <a href="/admin/rider-edit.php?id=<?= $p['rider_id'] ?>" class="link">
                                    <?= h($p['firstname'] . ' ' . $p['lastname']) ?>
                                </a>
                            </div>
                            <div class="role-item-meta">
                                <?= h($p['club_name'] ?? 'Ingen klubb') ?> &bull; <?= h($p['email']) ?>
                            </div>
                        </div>
                        <div class="flex gap-xs">
                            <span class="badge badge-primary">Promotör</span>
                            <?php if ($p['event_count'] > 0): ?>
                            <span class="badge badge-secondary"><?= $p['event_count'] ?> events</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="role-item-actions flex gap-sm">
                    <a href="/admin/user-events.php?id=<?= $p['user_id'] ?>" class="btn btn--secondary btn--sm">
                        <i data-lucide="calendar"></i> Events
                    </a>
                    <form method="POST" style="display: inline;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="remove_promotor">
                        <input type="hidden" name="rider_id" value="<?= $p['rider_id'] ?>">
                        <button type="submit" class="btn btn--secondary btn--sm" onclick="return confirm('Ta bort promotör-rollen?')">
                            <i data-lucide="x"></i>
                        </button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Eligible Riders -->
<div class="card">
    <div class="card-header flex justify-between items-center">
        <h2>
            <i data-lucide="users"></i>
            Riders som kan bli promotör
        </h2>
    </div>
    <div class="card-body">
        <?php if (empty($eligibleRiders)): ?>
        <p class="text-secondary">Inga riders med e-post hittades.</p>
        <?php else: ?>

        <div class="mb-md">
            <input type="text" id="riderSearch" class="input" placeholder="Sök rider..." style="max-width: 300px;">
        </div>

        <div class="role-grid" id="riderList">
            <?php foreach ($eligibleRiders as $r): ?>
            <div class="role-item" data-name="<?= strtolower(h($r['firstname'] . ' ' . $r['lastname'])) ?>">
                <div>
                    <div class="role-item-name">
                        <?= h($r['firstname'] . ' ' . $r['lastname']) ?>
                    </div>
                    <div class="role-item-meta">
                        <?= h($r['club_name'] ?? 'Ingen klubb') ?>
                        <?php if ($r['license_number']): ?>
                            &bull; <?= h($r['license_number']) ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="role-item-actions">
                    <form method="POST" style="display: inline;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="make_promotor">
                        <input type="hidden" name="rider_id" value="<?= $r['id'] ?>">
                        <button type="submit" class="btn btn--primary btn--sm" onclick="return confirm('Gör <?= h($r['firstname']) ?> till Promotör?')">
                            <i data-lucide="arrow-up"></i> Gör till Promotör
                        </button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <?php if (count($eligibleRiders) >= 100): ?>
        <p class="text-secondary mt-md text-sm">Visar max 100 riders. Använd sökfunktionen.</p>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<script>
document.getElementById('riderSearch')?.addEventListener('input', function() {
    const query = this.value.toLowerCase();
    document.querySelectorAll('#riderList .role-item').forEach(item => {
        const name = item.dataset.name || '';
        item.style.display = name.includes(query) ? '' : 'none';
    });
});
</script>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>

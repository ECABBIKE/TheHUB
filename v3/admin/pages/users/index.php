<?php
/**
 * TheHUB V3.5 Admin - User Management
 * List and manage user roles
 */

// Require Super Admin
hub_require_role(ROLE_SUPER_ADMIN);

$pdo = hub_db();
$success = '';
$error = '';

// Handle role change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $targetUserId = (int) ($_POST['user_id'] ?? 0);
    $currentUserId = $_SESSION['hub_user_id'];

    if ($_POST['action'] === 'change_role' && $targetUserId && $targetUserId !== $currentUserId) {
        $newRole = (int) ($_POST['role_id'] ?? ROLE_RIDER);

        // Check target user's current role
        $stmt = $pdo->prepare("SELECT role_id, firstname, lastname FROM riders WHERE id = ?");
        $stmt->execute([$targetUserId]);
        $targetUser = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$targetUser) {
            $error = 'Anvandaren hittades inte.';
        } elseif ($targetUser['role_id'] == ROLE_SUPER_ADMIN) {
            $error = 'Kan inte andra roll for annan Super Admin.';
        } else {
            $stmt = $pdo->prepare("
                UPDATE riders
                SET role_id = ?, role_updated_at = NOW(), role_updated_by = ?
                WHERE id = ?
            ");
            $stmt->execute([$newRole, $currentUserId, $targetUserId]);
            $success = 'Roll uppdaterad for ' . htmlspecialchars($targetUser['firstname'] . ' ' . $targetUser['lastname']) . '!';
        }
    }
}

// Filters
$roleFilter = $_GET['role'] ?? '';
$search = trim($_GET['search'] ?? '');

$where = [];
$params = [];

if ($roleFilter !== '') {
    $where[] = "r.role_id = ?";
    $params[] = (int) $roleFilter;
}

if ($search) {
    $where[] = "(r.firstname LIKE ? OR r.lastname LIKE ? OR r.email LIKE ?)";
    $searchParam = "%{$search}%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
}

$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Fetch users
$sql = "
    SELECT r.id, r.firstname, r.lastname, r.email, r.role_id, r.last_login,
           COUNT(DISTINCT pe.id) as event_count,
           COUNT(DISTINCT ps.id) as series_count
    FROM riders r
    LEFT JOIN promotor_events pe ON pe.rider_id = r.id
    LEFT JOIN promotor_series ps ON ps.rider_id = r.id
    {$whereClause}
    GROUP BY r.id
    ORDER BY r.role_id DESC, r.lastname, r.firstname
    LIMIT 100
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Count by role
$roleCounts = $pdo->query("
    SELECT role_id, COUNT(*) as cnt
    FROM riders
    GROUP BY role_id
")->fetchAll(PDO::FETCH_KEY_PAIR);
?>

<div class="admin-page-header">
    <div>
        <h1><?= hub_icon('users', 'icon-lg') ?> Anvandare & Roller</h1>
        <p class="text-secondary"><?= count($users) ?> anvandare visas</p>
    </div>
</div>

<?php if ($success): ?>
<div class="alert alert--success mb-lg">
    <?= hub_icon('check-circle', 'icon-sm') ?>
    <?= $success ?>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="alert alert--error mb-lg">
    <?= hub_icon('alert-circle', 'icon-sm') ?>
    <?= $error ?>
</div>
<?php endif; ?>

<!-- Filters -->
<div class="admin-filters mb-lg">
    <form method="get" class="admin-filter-form">
        <select name="role" class="form-select" onchange="this.form.submit()">
            <option value="">Alla roller</option>
            <option value="<?= ROLE_RIDER ?>" <?= $roleFilter === '1' ? 'selected' : '' ?>>
                Rider (<?= $roleCounts[ROLE_RIDER] ?? 0 ?>)
            </option>
            <option value="<?= ROLE_PROMOTOR ?>" <?= $roleFilter === '2' ? 'selected' : '' ?>>
                Promotor (<?= $roleCounts[ROLE_PROMOTOR] ?? 0 ?>)
            </option>
            <option value="<?= ROLE_ADMIN ?>" <?= $roleFilter === '3' ? 'selected' : '' ?>>
                Admin (<?= $roleCounts[ROLE_ADMIN] ?? 0 ?>)
            </option>
            <option value="<?= ROLE_SUPER_ADMIN ?>" <?= $roleFilter === '4' ? 'selected' : '' ?>>
                Super Admin (<?= $roleCounts[ROLE_SUPER_ADMIN] ?? 0 ?>)
            </option>
        </select>

        <div class="admin-search-box">
            <input type="search" name="search" value="<?= htmlspecialchars($search) ?>"
                   placeholder="Sok namn eller email..." class="form-input">
            <button type="submit" class="btn btn--primary btn--icon">
                <?= hub_icon('search', 'icon-sm') ?>
            </button>
        </div>
    </form>
</div>

<!-- User list -->
<div class="admin-card">
    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Namn</th>
                    <th>Email</th>
                    <th>Roll</th>
                    <th class="text-center">Tilldelade</th>
                    <th class="text-center">Senast inloggad</th>
                    <th class="text-right">Atgarder</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $user): ?>
                <tr>
                    <td>
                        <strong><?= htmlspecialchars($user['firstname'] . ' ' . $user['lastname']) ?></strong>
                    </td>
                    <td class="text-secondary">
                        <?= htmlspecialchars($user['email']) ?>
                    </td>
                    <td>
                        <span class="badge badge--role-<?= $user['role_id'] ?>">
                            <?= htmlspecialchars(hub_get_role_name($user['role_id'])) ?>
                        </span>
                    </td>
                    <td class="text-center">
                        <?php if ($user['role_id'] == ROLE_PROMOTOR): ?>
                            <span title="Events: <?= $user['event_count'] ?>, Serier: <?= $user['series_count'] ?>">
                                <?= $user['event_count'] ?> / <?= $user['series_count'] ?>
                            </span>
                        <?php else: ?>
                            <span class="text-muted">-</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center text-secondary">
                        <?php if ($user['last_login']): ?>
                            <?= date('Y-m-d', strtotime($user['last_login'])) ?>
                        <?php else: ?>
                            <span class="text-muted">Aldrig</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-right">
                        <div class="admin-actions">
                            <?php if ($user['id'] != $_SESSION['hub_user_id']): ?>
                            <button type="button" class="btn btn--ghost btn--sm"
                                    onclick="openRoleModal(<?= $user['id'] ?>, <?= $user['role_id'] ?>, '<?= htmlspecialchars(addslashes($user['firstname'] . ' ' . $user['lastname'])) ?>')"
                                    title="Andra roll">
                                <?= hub_icon('edit', 'icon-sm') ?>
                            </button>
                            <?php endif; ?>

                            <?php if ($user['role_id'] == ROLE_PROMOTOR): ?>
                            <a href="<?= admin_url('users/' . $user['id'] . '/assignments') ?>"
                               class="btn btn--ghost btn--sm" title="Hantera tilldelningar">
                                <?= hub_icon('settings', 'icon-sm') ?>
                            </a>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>

                <?php if (empty($users)): ?>
                <tr>
                    <td colspan="6" class="text-center text-muted py-xl">
                        Inga anvandare hittades.
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Role Modal -->
<div id="role-modal" class="modal" style="display: none;">
    <div class="modal-backdrop" onclick="closeRoleModal()"></div>
    <div class="modal-content">
        <div class="modal-header">
            <h2><?= hub_icon('user', 'icon-sm') ?> Andra roll</h2>
            <button type="button" onclick="closeRoleModal()" class="btn btn--ghost btn--icon">
                <?= hub_icon('x', 'icon-sm') ?>
            </button>
        </div>

        <form method="post">
            <input type="hidden" name="action" value="change_role">
            <input type="hidden" name="user_id" id="modal-user-id">

            <div class="modal-body">
                <p class="mb-md">Andra roll for <strong id="modal-user-name"></strong>:</p>

                <div class="form-group">
                    <select name="role_id" id="modal-role" class="form-select">
                        <option value="<?= ROLE_RIDER ?>">Rider - Kan se profil och anmala sig</option>
                        <option value="<?= ROLE_PROMOTOR ?>">Promotor - Kan hantera tilldelade events</option>
                        <option value="<?= ROLE_ADMIN ?>">Admin - Kan hantera allt innehall</option>
                        <option value="<?= ROLE_SUPER_ADMIN ?>">Super Admin - Full systematkomst</option>
                    </select>
                </div>

                <div class="alert alert--info mt-md">
                    <strong>Rollbeskrivningar:</strong><br>
                    <strong>Rider</strong> - Kan se profil och anmala sig<br>
                    <strong>Promotor</strong> - Kan hantera tilldelade events/serier<br>
                    <strong>Admin</strong> - Kan hantera allt innehall<br>
                    <strong>Super Admin</strong> - Full systematkomst inkl roller
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" onclick="closeRoleModal()" class="btn btn--ghost">Avbryt</button>
                <button type="submit" class="btn btn--primary">
                    <?= hub_icon('check', 'icon-sm') ?> Spara
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openRoleModal(userId, currentRole, userName) {
    document.getElementById('modal-user-id').value = userId;
    document.getElementById('modal-user-name').textContent = userName;
    document.getElementById('modal-role').value = currentRole;
    document.getElementById('role-modal').style.display = 'flex';
}

function closeRoleModal() {
    document.getElementById('role-modal').style.display = 'none';
}

// Close on escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeRoleModal();
    }
});
</script>

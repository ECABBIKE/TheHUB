<?php
/**
 * Admin Users Management
 * Only accessible by super_admin
 */
require_once __DIR__ . '/../config.php';
require_admin();

// Only super_admin can access this page
if (!hasRole('super_admin')) {
    http_response_code(403);
    die('Access denied: Only super administrators can manage users.');
}

global $pdo;
$db = getDB();

// Handle search and filters
$search = $_GET['search'] ?? '';
$roleFilter = $_GET['role'] ?? '';
$activeFilter = isset($_GET['active']) ? $_GET['active'] : '';

// Build query filters
$where = [];
$params = [];

if ($search) {
    $where[] = "(username LIKE ? OR email LIKE ? OR full_name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($roleFilter) {
    $where[] = "role = ?";
    $params[] = $roleFilter;
}

if ($activeFilter !== '') {
    $where[] = "active = ?";
    $params[] = (int)$activeFilter;
}

$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$sql = "SELECT
    id, username, email, full_name, role, active, last_login, created_at
FROM admin_users
$whereClause
ORDER BY
    CASE role
        WHEN 'super_admin' THEN 1
        WHEN 'admin' THEN 2
        WHEN 'promotor' THEN 3
        WHEN 'rider' THEN 4
    END,
    username ASC";

$users = $db->getAll($sql, $params);

// Get role counts for stats
$roleCounts = $db->getAll("SELECT role, COUNT(*) as count FROM admin_users GROUP BY role");
$roleStats = [];
foreach ($roleCounts as $row) {
    $roleStats[$row['role']] = $row['count'];
}

$pageTitle = 'Användarhantering';
$pageType = 'admin';
include __DIR__ . '/../includes/layout-header.php';
?>

<main class="gs-main-content">
    <div class="gs-container">
        <div class="gs-flex gs-justify-between gs-items-center gs-mb-lg">
            <h1 class="gs-h2">
                <i data-lucide="users-cog"></i>
                Användarhantering
            </h1>
            <a href="/admin/user-edit.php" class="gs-btn gs-btn-primary">
                <i data-lucide="user-plus"></i>
                Ny användare
            </a>
        </div>

        <!-- Role Stats -->
        <div class="gs-grid gs-grid-cols-2 gs-md-grid-cols-4 gs-gap-md gs-mb-lg">
            <div class="gs-stat-card">
                <i data-lucide="shield" class="gs-icon-lg gs-text-error gs-mb-md"></i>
                <div class="gs-stat-number"><?= $roleStats['super_admin'] ?? 0 ?></div>
                <div class="gs-stat-label">Super Admin</div>
            </div>
            <div class="gs-stat-card">
                <i data-lucide="settings" class="gs-icon-lg gs-text-warning gs-mb-md"></i>
                <div class="gs-stat-number"><?= $roleStats['admin'] ?? 0 ?></div>
                <div class="gs-stat-label">Admin</div>
            </div>
            <div class="gs-stat-card">
                <i data-lucide="calendar-check" class="gs-icon-lg gs-text-primary gs-mb-md"></i>
                <div class="gs-stat-number"><?= $roleStats['promotor'] ?? 0 ?></div>
                <div class="gs-stat-label">Promotor</div>
            </div>
            <div class="gs-stat-card">
                <i data-lucide="bike" class="gs-icon-lg gs-text-success gs-mb-md"></i>
                <div class="gs-stat-number"><?= $roleStats['rider'] ?? 0 ?></div>
                <div class="gs-stat-label">Rider</div>
            </div>
        </div>

        <!-- Filters -->
        <div class="gs-card gs-mb-lg">
            <div class="gs-card-content">
                <form method="GET" class="gs-flex gs-gap-md gs-items-center gs-flex-wrap">
                    <div class="gs-flex-1">
                        <div class="gs-input-group">
                            <i data-lucide="search"></i>
                            <input
                                type="text"
                                name="search"
                                class="gs-input"
                                placeholder="Sök efter namn, email eller användarnamn..."
                                value="<?= h($search) ?>"
                            >
                        </div>
                    </div>
                    <select name="role" class="gs-input" style="width: auto;" onchange="this.form.submit()">
                        <option value="">Alla roller</option>
                        <option value="super_admin" <?= $roleFilter === 'super_admin' ? 'selected' : '' ?>>Super Admin</option>
                        <option value="admin" <?= $roleFilter === 'admin' ? 'selected' : '' ?>>Admin</option>
                        <option value="promotor" <?= $roleFilter === 'promotor' ? 'selected' : '' ?>>Promotor</option>
                        <option value="rider" <?= $roleFilter === 'rider' ? 'selected' : '' ?>>Rider</option>
                    </select>
                    <select name="active" class="gs-input" style="width: auto;" onchange="this.form.submit()">
                        <option value="">Alla status</option>
                        <option value="1" <?= $activeFilter === '1' ? 'selected' : '' ?>>Aktiva</option>
                        <option value="0" <?= $activeFilter === '0' ? 'selected' : '' ?>>Inaktiva</option>
                    </select>
                    <button type="submit" class="gs-btn gs-btn-primary">
                        <i data-lucide="search"></i>
                        Sök
                    </button>
                    <?php if ($search || $roleFilter || $activeFilter !== ''): ?>
                        <a href="/admin/users.php" class="gs-btn gs-btn-outline">
                            <i data-lucide="x"></i>
                            Rensa
                        </a>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <!-- Users Table -->
        <div class="gs-card">
            <div class="gs-card-content gs-p-0">
                <div class="gs-table-container">
                    <table class="gs-table">
                        <thead>
                            <tr>
                                <th>Användare</th>
                                <th>Roll</th>
                                <th>Status</th>
                                <th>Senaste inloggning</th>
                                <th>Skapad</th>
                                <th class="gs-text-right">Åtgärder</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($users)): ?>
                                <tr>
                                    <td colspan="6" class="gs-text-center gs-text-secondary gs-py-xl">
                                        <i data-lucide="users" class="gs-icon-xl gs-mb-md"></i>
                                        <p>Inga användare hittades</p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($users as $user): ?>
                                    <tr>
                                        <td>
                                            <div class="gs-flex gs-items-center gs-gap-sm">
                                                <div class="gs-avatar gs-avatar-sm">
                                                    <?= strtoupper(substr($user['username'], 0, 1)) ?>
                                                </div>
                                                <div>
                                                    <div class="gs-font-medium"><?= h($user['full_name'] ?: $user['username']) ?></div>
                                                    <div class="gs-text-xs gs-text-secondary"><?= h($user['email']) ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <?php
                                            $roleColors = [
                                                'super_admin' => 'error',
                                                'admin' => 'warning',
                                                'promotor' => 'primary',
                                                'rider' => 'success'
                                            ];
                                            $roleLabels = [
                                                'super_admin' => 'Super Admin',
                                                'admin' => 'Admin',
                                                'promotor' => 'Promotor',
                                                'rider' => 'Rider'
                                            ];
                                            $color = $roleColors[$user['role']] ?? 'secondary';
                                            $label = $roleLabels[$user['role']] ?? $user['role'];
                                            ?>
                                            <span class="gs-badge gs-badge-<?= $color ?>"><?= h($label) ?></span>
                                        </td>
                                        <td>
                                            <?php if ($user['active']): ?>
                                                <span class="gs-badge gs-badge-success">Aktiv</span>
                                            <?php else: ?>
                                                <span class="gs-badge gs-badge-secondary">Inaktiv</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($user['last_login']): ?>
                                                <span class="gs-text-sm"><?= date('Y-m-d H:i', strtotime($user['last_login'])) ?></span>
                                            <?php else: ?>
                                                <span class="gs-text-secondary gs-text-sm">Aldrig</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="gs-text-sm"><?= date('Y-m-d', strtotime($user['created_at'])) ?></span>
                                        </td>
                                        <td class="gs-text-right">
                                            <div class="gs-flex gs-gap-xs gs-justify-end">
                                                <a href="/admin/user-edit.php?id=<?= $user['id'] ?>" class="gs-btn gs-btn-sm gs-btn-outline" title="Redigera">
                                                    <i data-lucide="edit"></i>
                                                </a>
                                                <?php if ($user['role'] === 'promotor'): ?>
                                                    <a href="/admin/user-events.php?id=<?= $user['id'] ?>" class="gs-btn gs-btn-sm gs-btn-outline" title="Hantera events">
                                                        <i data-lucide="calendar"></i>
                                                    </a>
                                                <?php endif; ?>
                                                <?php if ($user['role'] === 'rider'): ?>
                                                    <a href="/admin/user-rider.php?id=<?= $user['id'] ?>" class="gs-btn gs-btn-sm gs-btn-outline" title="Koppla rider">
                                                        <i data-lucide="link"></i>
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Role Description -->
        <div class="gs-card gs-mt-lg">
            <div class="gs-card-header">
                <h2 class="gs-h4">Rollbeskrivningar</h2>
            </div>
            <div class="gs-card-content">
                <div class="gs-grid gs-grid-cols-1 gs-md-grid-cols-2 gs-gap-lg">
                    <div>
                        <h3 class="gs-font-medium gs-text-error gs-mb-xs">
                            <i data-lucide="shield" class="gs-icon-sm"></i>
                            Super Admin
                        </h3>
                        <p class="gs-text-sm gs-text-secondary">Full tillgång till allt. Kan hantera användare, systeminställningar och alla andra funktioner.</p>
                    </div>
                    <div>
                        <h3 class="gs-font-medium gs-text-warning gs-mb-xs">
                            <i data-lucide="settings" class="gs-icon-sm"></i>
                            Admin
                        </h3>
                        <p class="gs-text-sm gs-text-secondary">Kan hantera events, serier, riders, klubbar och importera data. Har inte tillgång till användarhantering.</p>
                    </div>
                    <div>
                        <h3 class="gs-font-medium gs-text-primary gs-mb-xs">
                            <i data-lucide="calendar-check" class="gs-icon-sm"></i>
                            Promotor
                        </h3>
                        <p class="gs-text-sm gs-text-secondary">Kan endast hantera tilldelade events - redigera eventinfo, hantera resultat och registreringar.</p>
                    </div>
                    <div>
                        <h3 class="gs-font-medium gs-text-success gs-mb-xs">
                            <i data-lucide="bike" class="gs-icon-sm"></i>
                            Rider
                        </h3>
                        <p class="gs-text-sm gs-text-secondary">Kan redigera sin egen profil och hantera sin klubb (om godkänt av admin).</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<style>
.gs-avatar {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: var(--gs-primary);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
    font-size: 14px;
}
.gs-avatar-sm {
    width: 32px;
    height: 32px;
    font-size: 12px;
}
</style>

<?php include __DIR__ . '/../includes/layout-footer.php'; ?>

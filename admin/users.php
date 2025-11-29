<?php
/**
 * Admin Users Management
 * Only accessible by super_admin
 */
require_once __DIR__ . '/../config.php';
require_admin();
require_once __DIR__ . '/../includes/admin-layout.php';

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

// Build query filters - exclude riders (they are managed via rider-edit.php)
$where = ["role != 'rider'"];
$params = [];

if ($search) {
 $where[] ="(username LIKE ? OR email LIKE ? OR full_name LIKE ?)";
 $params[] ="%$search%";
 $params[] ="%$search%";
 $params[] ="%$search%";
}

if ($roleFilter && $roleFilter !== 'rider') {
 $where[] ="role = ?";
 $params[] = $roleFilter;
}

if ($activeFilter !== '') {
 $where[] ="active = ?";
 $params[] = (int)$activeFilter;
}

$whereClause = 'WHERE ' . implode(' AND ', $where);

$sql ="SELECT
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

<main class="main-content">
 <div class="container">
 <?php
 render_admin_header('Inställningar', [
 ['label' => 'Ny användare', 'url' => '/admin/user-edit.php', 'icon' => 'user-plus', 'class' => 'btn--primary']
 ]);
 ?>

 <!-- Info about riders -->
 <div class="alert alert--info mb-lg">
 <i data-lucide="info"></i>
 <span>
 <strong>Rider-användare</strong> hanteras via
 <a href="/admin/riders.php" class="link">Deltagare</a> →
 Redigera deltagare → Användarkonto-sektionen.
 </span>
 </div>

 <!-- Role Stats -->
 <div class="grid grid-cols-3 gap-md mb-lg">
 <div class="stat-card">
 <i data-lucide="shield" class="icon-lg text-error mb-md"></i>
 <div class="stat-number"><?= $roleStats['super_admin'] ?? 0 ?></div>
 <div class="stat-label">Super Admin</div>
 </div>
 <div class="stat-card">
 <i data-lucide="settings" class="icon-lg text-warning mb-md"></i>
 <div class="stat-number"><?= $roleStats['admin'] ?? 0 ?></div>
 <div class="stat-label">Admin</div>
 </div>
 <div class="stat-card">
 <i data-lucide="calendar-check" class="icon-lg text-primary mb-md"></i>
 <div class="stat-number"><?= $roleStats['promotor'] ?? 0 ?></div>
 <div class="stat-label">Promotor</div>
 </div>
 </div>

 <!-- Filters -->
 <div class="card mb-lg">
 <div class="card-body">
 <form method="GET" class="flex gap-md items-center flex-wrap">
  <div class="flex-1">
  <div class="input-group">
  <i data-lucide="search"></i>
  <input
  type="text"
  name="search"
  class="input"
  placeholder="Sök efter namn, email eller användarnamn..."
  value="<?= h($search) ?>"
  >
  </div>
  </div>
  <select name="role" class="input" style="width: auto;" onchange="this.form.submit()">
  <option value="">Alla roller</option>
  <option value="super_admin" <?= $roleFilter === 'super_admin' ? 'selected' : '' ?>>Super Admin</option>
  <option value="admin" <?= $roleFilter === 'admin' ? 'selected' : '' ?>>Admin</option>
  <option value="promotor" <?= $roleFilter === 'promotor' ? 'selected' : '' ?>>Promotor</option>
  </select>
  <select name="active" class="input" style="width: auto;" onchange="this.form.submit()">
  <option value="">Alla status</option>
  <option value="1" <?= $activeFilter === '1' ? 'selected' : '' ?>>Aktiva</option>
  <option value="0" <?= $activeFilter === '0' ? 'selected' : '' ?>>Inaktiva</option>
  </select>
  <button type="submit" class="btn btn--primary">
  <i data-lucide="search"></i>
  Sök
  </button>
  <?php if ($search || $roleFilter || $activeFilter !== ''): ?>
  <a href="/admin/users.php" class="btn btn--secondary">
  <i data-lucide="x"></i>
  Rensa
  </a>
  <?php endif; ?>
 </form>
 </div>
 </div>

 <!-- Users Table -->
 <div class="card">
 <div class="card-body gs-p-0">
 <div class="table-container">
  <table class="table">
  <thead>
  <tr>
  <th>Användare</th>
  <th>Roll</th>
  <th>Status</th>
  <th>Senaste inloggning</th>
  <th>Skapad</th>
  <th class="text-right">Åtgärder</th>
  </tr>
  </thead>
  <tbody>
  <?php if (empty($users)): ?>
  <tr>
   <td colspan="6" class="text-center text-secondary py-xl">
   <i data-lucide="users" class="gs-icon-xl mb-md"></i>
   <p>Inga användare hittades</p>
   </td>
  </tr>
  <?php else: ?>
  <?php foreach ($users as $user): ?>
   <tr>
   <td>
   <div class="flex items-center gap-sm">
   <div class="gs-avatar gs-avatar-sm">
    <?= strtoupper(substr($user['username'], 0, 1)) ?>
   </div>
   <div>
    <div class="font-medium"><?= h($user['full_name'] ?: $user['username']) ?></div>
    <div class="text-xs text-secondary"><?= h($user['email']) ?></div>
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
   <span class="badge badge-<?= $color ?>"><?= h($label) ?></span>
   </td>
   <td>
   <?php if ($user['active']): ?>
   <span class="badge badge-success">Aktiv</span>
   <?php else: ?>
   <span class="badge badge-secondary">Inaktiv</span>
   <?php endif; ?>
   </td>
   <td>
   <?php if ($user['last_login']): ?>
   <span class="text-sm"><?= date('Y-m-d H:i', strtotime($user['last_login'])) ?></span>
   <?php else: ?>
   <span class="text-secondary text-sm">Aldrig</span>
   <?php endif; ?>
   </td>
   <td>
   <span class="text-sm"><?= date('Y-m-d', strtotime($user['created_at'])) ?></span>
   </td>
   <td class="text-right">
   <div class="flex gap-xs gs-justify-end">
   <a href="/admin/user-edit.php?id=<?= $user['id'] ?>" class="btn btn--sm btn--secondary" title="Redigera">
    <i data-lucide="edit"></i>
   </a>
   <?php if ($user['role'] === 'promotor'): ?>
    <a href="/admin/user-events.php?id=<?= $user['id'] ?>" class="btn btn--sm btn--secondary" title="Hantera events">
    <i data-lucide="calendar"></i>
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
 <div class="card mt-lg">
 <div class="card-header">
 <h2 class="">Rollbeskrivningar</h2>
 </div>
 <div class="card-body">
 <div class="grid grid-cols-1 md-grid-cols-3 gap-lg">
  <div>
  <h3 class="font-medium text-error gs-mb-xs">
  <i data-lucide="shield" class="icon-sm"></i>
  Super Admin
  </h3>
  <p class="text-sm text-secondary">Full tillgång till allt. Kan hantera användare, systeminställningar och alla andra funktioner.</p>
  </div>
  <div>
  <h3 class="font-medium text-warning gs-mb-xs">
  <i data-lucide="settings" class="icon-sm"></i>
  Admin
  </h3>
  <p class="text-sm text-secondary">Kan hantera events, serier, riders, klubbar och importera data. Har inte tillgång till användarhantering.</p>
  </div>
  <div>
  <h3 class="font-medium text-primary gs-mb-xs">
  <i data-lucide="calendar-check" class="icon-sm"></i>
  Promotor
  </h3>
  <p class="text-sm text-secondary">Kan endast hantera tilldelade events - redigera eventinfo, hantera resultat och registreringar.</p>
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
 background: var(--primary);
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

<?php render_admin_footer(); ?>
<?php include __DIR__ . '/../includes/layout-footer.php'; ?>

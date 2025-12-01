<?php
/**
 * Role Permissions Management - V3 Unified Design System
 * Edit what each role can access
 * Only accessible by super_admin
 */
require_once __DIR__ . '/../config.php';
require_admin();

// Only super_admin can access this page
if (!hasRole('super_admin')) {
 http_response_code(403);
 die('Access denied: Only super administrators can manage role permissions.');
}

$db = getDB();

$message = '';
$messageType = 'info';

// Get all permissions
$permissions = $db->getAll("SELECT * FROM permissions ORDER BY category, name");

// Group permissions by category
$permissionsByCategory = [];
foreach ($permissions as $perm) {
 $cat = $perm['category'] ?: 'other';
 if (!isset($permissionsByCategory[$cat])) {
 $permissionsByCategory[$cat] = [];
 }
 $permissionsByCategory[$cat][] = $perm;
}

// Get current role permissions
$rolePermissions = [];
$roles = ['super_admin', 'admin', 'promotor', 'rider'];
foreach ($roles as $role) {
 $perms = $db->getAll("
 SELECT p.id FROM permissions p
 JOIN role_permissions rp ON p.id = rp.permission_id
 WHERE rp.role = ?
", [$role]);
 $rolePermissions[$role] = array_column($perms, 'id');
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
 checkCsrf();

 $action = $_POST['action'] ?? '';

 if ($action === 'update_permissions') {
 try {
 // Update each role's permissions (except super_admin which always has all)
 foreach (['admin', 'promotor', 'rider'] as $role) {
 $selectedPerms = $_POST['permissions'][$role] ?? [];

 // Delete existing permissions for this role
 $db->delete('role_permissions', 'role = ?', [$role]);

 // Insert new permissions
 foreach ($selectedPerms as $permId) {
  $db->insert('role_permissions', [
  'role' => $role,
  'permission_id' => (int)$permId
  ]);
 }

 // Refresh role permissions
 $perms = $db->getAll("
  SELECT p.id FROM permissions p
  JOIN role_permissions rp ON p.id = rp.permission_id
  WHERE rp.role = ?
 ", [$role]);
 $rolePermissions[$role] = array_column($perms, 'id');
 }

 $message = 'Behörigheter uppdaterade!';
 $messageType = 'success';
 } catch (Exception $e) {
 $message = 'Kunde inte uppdatera behörigheter: ' . $e->getMessage();
 $messageType = 'error';
 }
 }
}

// Category labels
$categoryLabels = [
 'system' => 'System',
 'events' => 'Events',
 'series' => 'Serier',
 'riders' => 'Deltagare',
 'clubs' => 'Klubbar',
 'import' => 'Import',
 'export' => 'Export',
 'other' => 'Övrigt'
];

// Role labels
$roleLabels = [
 'super_admin' => 'Super Admin',
 'admin' => 'Admin',
 'promotor' => 'Promotor',
 'rider' => 'Rider'
];

$roleColors = [
 'super_admin' => 'error',
 'admin' => 'warning',
 'promotor' => 'primary',
 'rider' => 'success'
];

// Page config for unified layout
$page_title = 'Rollbehörigheter';
$breadcrumbs = [
    ['label' => 'Inställningar', 'url' => '/admin/settings'],
    ['label' => 'Roller']
];

// Include unified layout
include __DIR__ . '/components/unified-layout.php';
?>

<!-- Settings Tabs -->
<div class="admin-tabs">
    <a href="/admin/settings" class="admin-tab">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 16px; height: 16px;"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
        Översikt
    </a>
    <a href="/admin/global-texts.php" class="admin-tab">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 16px; height: 16px;"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><line x1="10" y1="9" x2="8" y2="9"/></svg>
        Texter
    </a>
    <a href="/admin/role-permissions.php" class="admin-tab active">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 16px; height: 16px;"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
        Roller
    </a>
    <a href="/admin/pricing-templates.php" class="admin-tab">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 16px; height: 16px;"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
        Prismallar
    </a>
    <a href="/admin/tools.php" class="admin-tab">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 16px; height: 16px;"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>
        Verktyg
    </a>
    <a href="/admin/system-settings.php" class="admin-tab">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 16px; height: 16px;"><rect width="20" height="8" x="2" y="2" rx="2" ry="2"/><rect width="20" height="8" x="2" y="14" rx="2" ry="2"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/></svg>
        System
    </a>
</div>

 <!-- Message -->
 <?php if ($message): ?>
 <div class="alert alert-<?= h($messageType) ?> mb-lg">
 <i data-lucide="<?= $messageType === 'success' ? 'check-circle' : 'alert-circle' ?>"></i>
 <?= h($message) ?>
 </div>
 <?php endif; ?>

 <!-- Info -->
 <div class="alert alert--info mb-lg">
 <i data-lucide="info"></i>
 <div>
 <strong>Super Admin</strong> har alltid alla behörigheter och kan inte begränsas.
 Ändra behörigheter för Admin, Promotor och Rider nedan.
 </div>
 </div>

 <form method="POST">
 <?= csrf_field() ?>
 <input type="hidden" name="action" value="update_permissions">

 <?php foreach ($permissionsByCategory as $category => $perms): ?>
 <div class="card mb-lg">
 <div class="card-header">
  <h2 class=""><?= h($categoryLabels[$category] ?? ucfirst($category)) ?></h2>
 </div>
 <div class="card-body gs-p-0">
  <div class="table-container">
  <table class="table">
  <thead>
  <tr>
   <th style="width: 40%;">Behörighet</th>
   <?php foreach ($roles as $role): ?>
   <th class="text-center" style="width: 15%;">
   <span class="badge badge-<?= $roleColors[$role] ?>"><?= $roleLabels[$role] ?></span>
   </th>
   <?php endforeach; ?>
  </tr>
  </thead>
  <tbody>
  <?php foreach ($perms as $perm): ?>
  <tr>
   <td>
   <div class="font-medium"><?= h($perm['name']) ?></div>
   <?php if ($perm['description']): ?>
   <div class="text-xs text-secondary"><?= h($perm['description']) ?></div>
   <?php endif; ?>
   </td>
   <?php foreach ($roles as $role): ?>
   <td class="text-center">
   <?php if ($role === 'super_admin'): ?>
   <input type="checkbox" checked disabled class="checkbox-input">
   <?php else: ?>
   <input
    type="checkbox"
    name="permissions[<?= $role ?>][]"
    value="<?= $perm['id'] ?>"
    <?= in_array($perm['id'], $rolePermissions[$role]) ? 'checked' : '' ?>
    class="checkbox-input"
   >
   <?php endif; ?>
   </td>
   <?php endforeach; ?>
  </tr>
  <?php endforeach; ?>
  </tbody>
  </table>
  </div>
 </div>
 </div>
 <?php endforeach; ?>

 <div class="card">
 <div class="card-body">
  <div class="flex justify-between items-center">
  <div class="text-secondary">
  <i data-lucide="info" class="icon-sm"></i>
  Ändringar träder i kraft direkt vid nästa sidladdning för användare
  </div>
  <button type="submit" class="btn btn--primary">
  <i data-lucide="save"></i>
  Spara behörigheter
  </button>
  </div>
 </div>
 </div>
 </form>

 <!-- Quick Actions -->
 <div class="card mt-lg">
 <div class="card-header">
 <h2 class="">Snabbåtgärder</h2>
 </div>
 <div class="card-body">
 <div class="flex gap-md flex-wrap">
  <button type="button" onclick="selectAllForRole('admin')" class="btn btn--secondary btn--sm">
  Alla till Admin
  </button>
  <button type="button" onclick="clearAllForRole('promotor')" class="btn btn--secondary btn--sm">
  Rensa Promotor
  </button>
  <button type="button" onclick="clearAllForRole('rider')" class="btn btn--secondary btn--sm">
  Rensa Rider
  </button>
 </div>
 </div>
 </div>
 </div>

<style>
.checkbox-input {
 width: 18px;
 height: 18px;
 cursor: pointer;
}
.checkbox-input:disabled {
 cursor: not-allowed;
 opacity: 0.7;
}
</style>

<script>
function selectAllForRole(role) {
 document.querySelectorAll(`input[name="permissions[${role}][]"]`).forEach(cb => {
 cb.checked = true;
 });
}

function clearAllForRole(role) {
 document.querySelectorAll(`input[name="permissions[${role}][]"]`).forEach(cb => {
 cb.checked = false;
 });
}
</script>

<?php include __DIR__ . '/components/unified-layout-footer.php'; ?>

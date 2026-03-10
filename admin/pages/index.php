<?php
/**
 * Admin — Sidhantering (Pages CMS)
 * Lista alla sidor
 */
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
requireAdmin();

// DB connection
try {
    global $pdo;
    if (!$pdo) {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    }
} catch (PDOException $e) {
    die('DB-fel: ' . $e->getMessage());
}

// Check if table exists
try {
    $pdo->query("SELECT 1 FROM pages LIMIT 1");
} catch (PDOException $e) {
    $tableError = true;
}

// Flash message
$flash = $_SESSION['gs_flash'] ?? null;
unset($_SESSION['gs_flash']);

// Filter
$statusFilter = $_GET['status'] ?? 'all';
$where = '';
$params = [];
if ($statusFilter === 'published') {
    $where = ' WHERE status = ?';
    $params[] = 'published';
} elseif ($statusFilter === 'draft') {
    $where = ' WHERE status = ?';
    $params[] = 'draft';
}

$pages = [];
if (empty($tableError)) {
    $stmt = $pdo->prepare("SELECT * FROM pages{$where} ORDER BY nav_order ASC, title ASC");
    $stmt->execute($params);
    $pages = $stmt->fetchAll();
}

$page_title = 'Sidor (GravitySeries)';
$current_admin_page = 'pages';
include __DIR__ . '/../components/unified-layout.php';
?>

<div class="admin-content" style="padding: 24px;">

  <!-- Header -->
  <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:24px; flex-wrap:wrap; gap:12px;">
    <h1 style="font-size:1.5rem; font-weight:700; margin:0;">Sidor — GravitySeries</h1>
    <a href="/admin/pages/edit.php" style="background:var(--color-accent,#37d4d6); color:#fff !important; padding:8px 16px; border-radius:var(--radius-sm,6px); text-decoration:none; font-weight:600; font-size:14px; display:inline-flex; align-items:center; gap:6px;">
      <i data-lucide="plus" style="width:16px;height:16px;"></i> Skapa ny sida
    </a>
  </div>

  <?php if (!empty($tableError)): ?>
    <div style="background:#fef3cd; border:1px solid #ffc107; border-radius:var(--radius-sm,6px); padding:16px; margin-bottom:24px;">
      <strong>Tabellen <code>pages</code> saknas.</strong><br>
      Kör migreringen <code>admin/migrations/create_pages_table.sql</code> och sedan <code>admin/migrations/seed_pages.php</code> för att skapa tabellen och grundsidorna.
    </div>
  <?php endif; ?>

  <?php if ($flash): ?>
    <div style="background:<?= $flash['type'] === 'success' ? '#d1fae5' : '#fee2e2' ?>; border:1px solid <?= $flash['type'] === 'success' ? '#10b981' : '#ef4444' ?>; border-radius:var(--radius-sm,6px); padding:12px 16px; margin-bottom:16px; font-size:14px;">
      <?= htmlspecialchars($flash['message']) ?>
    </div>
  <?php endif; ?>

  <!-- Filter -->
  <div style="display:flex; gap:8px; margin-bottom:20px; flex-wrap:wrap;">
    <a href="?status=all" style="padding:6px 14px; border-radius:4px; text-decoration:none; font-size:13px; font-weight:600; <?= $statusFilter === 'all' ? 'background:var(--color-accent,#37d4d6);color:#fff !important;' : 'background:var(--color-bg-card,#fff);border:1px solid var(--color-border,#ddd);color:var(--color-text-secondary,#666) !important;' ?>">
      Alla (<?= count($pages) ?>)
    </a>
    <a href="?status=published" style="padding:6px 14px; border-radius:4px; text-decoration:none; font-size:13px; font-weight:600; <?= $statusFilter === 'published' ? 'background:#10b981;color:#fff !important;' : 'background:var(--color-bg-card,#fff);border:1px solid var(--color-border,#ddd);color:var(--color-text-secondary,#666) !important;' ?>">
      Publicerade
    </a>
    <a href="?status=draft" style="padding:6px 14px; border-radius:4px; text-decoration:none; font-size:13px; font-weight:600; <?= $statusFilter === 'draft' ? 'background:#fbbf24;color:#000 !important;' : 'background:var(--color-bg-card,#fff);border:1px solid var(--color-border,#ddd);color:var(--color-text-secondary,#666) !important;' ?>">
      Utkast
    </a>
  </div>

  <?php if (empty($tableError)): ?>
  <!-- Table -->
  <div style="background:var(--color-bg-card,#fff); border:1px solid var(--color-border,#e5e7eb); border-radius:var(--radius-md,10px); overflow:hidden;">
    <div style="overflow-x:auto;">
      <table style="width:100%; border-collapse:collapse; font-size:14px;">
        <thead>
          <tr style="background:var(--color-bg-surface,#f9fafb); border-bottom:1px solid var(--color-border,#e5e7eb);">
            <th style="padding:12px 16px; text-align:left; font-weight:600; font-size:12px; text-transform:uppercase; letter-spacing:.05em; color:var(--color-text-muted,#888);">Titel</th>
            <th style="padding:12px 16px; text-align:left; font-weight:600; font-size:12px; text-transform:uppercase; letter-spacing:.05em; color:var(--color-text-muted,#888);">Slug</th>
            <th style="padding:12px 16px; text-align:center; font-weight:600; font-size:12px; text-transform:uppercase; letter-spacing:.05em; color:var(--color-text-muted,#888);">Status</th>
            <th style="padding:12px 16px; text-align:center; font-weight:600; font-size:12px; text-transform:uppercase; letter-spacing:.05em; color:var(--color-text-muted,#888);">I nav</th>
            <th style="padding:12px 16px; text-align:left; font-weight:600; font-size:12px; text-transform:uppercase; letter-spacing:.05em; color:var(--color-text-muted,#888);">Uppdaterad</th>
            <th style="padding:12px 16px; text-align:right; font-weight:600; font-size:12px; text-transform:uppercase; letter-spacing:.05em; color:var(--color-text-muted,#888);">Åtgärder</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($pages)): ?>
            <tr><td colspan="6" style="padding:24px 16px; text-align:center; color:var(--color-text-muted,#888);">Inga sidor skapade ännu.</td></tr>
          <?php endif; ?>
          <?php foreach ($pages as $p): ?>
          <tr style="border-bottom:1px solid var(--color-border,#e5e7eb);">
            <td style="padding:12px 16px; font-weight:600;"><?= htmlspecialchars($p['title']) ?></td>
            <td style="padding:12px 16px; font-family:monospace; font-size:13px; color:var(--color-text-muted,#888);">/<?= htmlspecialchars($p['slug']) ?></td>
            <td style="padding:12px 16px; text-align:center;">
              <?php if ($p['status'] === 'published'): ?>
                <span style="background:#d1fae5; color:#065f46; padding:2px 10px; border-radius:12px; font-size:12px; font-weight:600;">Publicerad</span>
              <?php else: ?>
                <span style="background:#fef3cd; color:#92400e; padding:2px 10px; border-radius:12px; font-size:12px; font-weight:600;">Utkast</span>
              <?php endif; ?>
            </td>
            <td style="padding:12px 16px; text-align:center;">
              <?= $p['show_in_nav'] ? '<i data-lucide="check" style="width:16px;height:16px;color:#10b981;"></i>' : '<span style="color:#ccc;">—</span>' ?>
            </td>
            <td style="padding:12px 16px; font-size:13px; color:var(--color-text-muted,#888);">
              <?= date('Y-m-d H:i', strtotime($p['updated_at'])) ?>
            </td>
            <td style="padding:12px 16px; text-align:right;">
              <div style="display:flex; gap:8px; justify-content:flex-end;">
                <a href="/admin/pages/edit.php?id=<?= $p['id'] ?>" title="Redigera" style="padding:6px; border-radius:4px; color:var(--color-text-secondary,#666); text-decoration:none;">
                  <i data-lucide="pencil" style="width:16px;height:16px;"></i>
                </a>
                <a href="/gravityseries/sida.php?slug=<?= htmlspecialchars($p['slug']) ?>" target="_blank" title="Förhandsgranska" style="padding:6px; border-radius:4px; color:var(--color-text-secondary,#666); text-decoration:none;">
                  <i data-lucide="external-link" style="width:16px;height:16px;"></i>
                </a>
                <form method="post" action="/admin/pages/delete.php" style="display:inline;" onsubmit="return confirm('Vill du verkligen ta bort sidan &quot;<?= htmlspecialchars($p['title']) ?>&quot;?');">
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                  <input type="hidden" name="id" value="<?= $p['id'] ?>">
                  <button type="submit" title="Ta bort" style="padding:6px; border-radius:4px; color:#ef4444; background:none; border:none; cursor:pointer;">
                    <i data-lucide="trash-2" style="width:16px;height:16px;"></i>
                  </button>
                </form>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

</div>

<?php include __DIR__ . '/../components/unified-layout-footer.php'; ?>

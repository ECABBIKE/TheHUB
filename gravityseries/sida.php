<?php
/**
 * GravitySeries — Statisk sidvisning
 * Visar publicerade sidor via ?slug=
 */

$slug = $_GET['slug'] ?? '';

// Validate slug format
if (!$slug || !preg_match('/^[a-z0-9-]+$/', $slug)) {
    http_response_code(404);
    $gsPageTitle = 'Sidan hittades inte';
    $gsActiveNav = '';
    require_once __DIR__ . '/includes/gs-header.php';
    echo '<div class="page-hero"><div class="page-hero-stripe"></div><div class="page-hero-inner"><h1 class="page-hero-title">404</h1><p class="page-hero-ingress">Sidan du söker finns inte.</p></div></div>';
    echo '<div class="page-content-wrap"><div class="page-content"><p><a href="/gravityseries/">Tillbaka till startsidan</a></p></div></div>';
    require_once __DIR__ . '/includes/gs-footer.php';
    exit;
}

// Connect to DB before header to get page data for title
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../config/database.php';
try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . (defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4'),
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    die('Databasanslutning misslyckades.');
}

// Load page from database
try {
    $stmt = $pdo->prepare("SELECT * FROM pages WHERE slug = ? AND status = 'published' LIMIT 1");
    $stmt->execute([$slug]);
    $page = $stmt->fetch();
} catch (PDOException $e) {
    $page = null;
}

if (!$page) {
    http_response_code(404);
    $gsPageTitle = 'Sidan hittades inte';
    $gsActiveNav = '';
    $gsCurrentSlug = '';
    require_once __DIR__ . '/includes/gs-header.php';
    ?>
    <div class="page-hero">
      <div class="page-hero-stripe"></div>
      <div class="page-hero-inner">
        <h1 class="page-hero-title">404</h1>
        <p class="page-hero-ingress">Sidan du söker finns inte.</p>
      </div>
    </div>
    <div class="page-content-wrap">
      <div class="page-content">
        <p><a href="/gravityseries/">Tillbaka till startsidan</a></p>
      </div>
    </div>
    <?php
    require_once __DIR__ . '/includes/gs-footer.php';
    exit;
}

// Set header variables
$gsPageTitle = $page['title'];
$gsMetaDesc = $page['meta_description'] ?: '';
$gsActiveNav = '';
$gsCurrentSlug = $slug;

require_once __DIR__ . '/includes/gs-header.php';

// Hero background style
$heroStyle = '';
if (!empty($page['hero_image'])) {
    $opacity = ($page['hero_overlay_opacity'] ?? 50) / 100;
    $position = $page['hero_image_position'] ?? 'center';
    $heroStyle = sprintf(
        'background-image: linear-gradient(rgba(0,0,0,%s), rgba(0,0,0,%s)), url(\'%s\'); background-size:cover; background-position:%s;',
        $opacity,
        min($opacity + 0.2, 1),
        htmlspecialchars($page['hero_image']),
        $position
    );
}
?>

<!-- PAGE HERO -->
<div class="page-hero"<?= $heroStyle ? ' style="' . $heroStyle . '"' : '' ?>>
  <div class="page-hero-stripe"></div>
  <div class="page-hero-inner">
    <h1 class="page-hero-title"><?= htmlspecialchars($page['title']) ?></h1>
    <?php if ($page['meta_description']): ?>
      <p class="page-hero-ingress"><?= htmlspecialchars($page['meta_description']) ?></p>
    <?php endif; ?>
  </div>
</div>

<!-- PAGE CONTENT -->
<div class="page-content-wrap">
  <div class="page-content">
    <?= $page['content'] ?>
  </div>
</div>

<?php if (!empty($gsIsAdmin)): ?>
<style>
.gs-admin-bar{position:fixed;bottom:20px;right:20px;z-index:9999;display:flex;gap:8px;align-items:center;}
.gs-admin-btn{background:var(--ink,#0a0f0d);color:#fff;padding:10px 16px;border-radius:8px;text-decoration:none;font-family:'Barlow',sans-serif;font-size:14px;font-weight:600;display:flex;align-items:center;gap:6px;box-shadow:0 4px 16px rgba(0,0,0,.3);transition:background .2s;}
.gs-admin-btn:hover{background:#333;}
.gs-admin-btn svg{width:16px;height:16px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;}
</style>
<div class="gs-admin-bar">
  <a class="gs-admin-btn" href="/admin/pages/edit.php?id=<?= (int)$page['id'] ?>">
    <svg viewBox="0 0 24 24"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
    Redigera sida
  </a>
  <a class="gs-admin-btn" href="/admin/pages/">
    <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
    Alla sidor
  </a>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/gs-footer.php'; ?>

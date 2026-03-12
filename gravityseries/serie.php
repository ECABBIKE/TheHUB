<?php
/**
 * GravitySeries — Serie-infosida
 * /gravityseries/serie/{brand-slug}
 *
 * Visar CMS-innehåll för en tävlingsserie med serie-branded hero.
 * Redigeras via admin/pages/edit.php (samma CMS som övriga sidor).
 */

$slug = $_GET['slug'] ?? '';

// Validate slug format
if (!$slug || !preg_match('/^[a-z0-9-]+$/', $slug)) {
    http_response_code(404);
    $gsPageTitle = 'Serien hittades inte';
    $gsActiveNav = 'serier';
    require_once __DIR__ . '/includes/gs-header.php';
    echo '<div class="page-hero"><div class="page-hero-stripe"></div><div class="page-hero-inner"><h1 class="page-hero-title">404</h1><p class="page-hero-ingress">Serien du söker finns inte.</p></div></div>';
    echo '<div class="page-content-wrap"><div class="page-content"><p><a href="/gravityseries/">Tillbaka till startsidan</a></p></div></div>';
    require_once __DIR__ . '/includes/gs-footer.php';
    exit;
}

// Connect to DB
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../config/database.php';
if (!isset($pdo)) {
    try {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . (defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4'),
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    } catch (PDOException $e) {
        die('Databasanslutning misslyckades.');
    }
}

// Find brand by slug
$brand = null;
try {
    $bStmt = $pdo->prepare("SELECT * FROM series_brands WHERE slug = ? AND active = 1 LIMIT 1");
    $bStmt->execute([$slug]);
    $brand = $bStmt->fetch();
} catch (PDOException $e) {}

if (!$brand) {
    http_response_code(404);
    $gsPageTitle = 'Serien hittades inte';
    $gsActiveNav = 'serier';
    require_once __DIR__ . '/includes/gs-header.php';
    echo '<div class="page-hero"><div class="page-hero-stripe"></div><div class="page-hero-inner"><h1 class="page-hero-title">404</h1><p class="page-hero-ingress">Serien du söker finns inte.</p></div></div>';
    echo '<div class="page-content-wrap"><div class="page-content"><p><a href="/gravityseries/#serier">Tillbaka till serierna</a></p></div></div>';
    require_once __DIR__ . '/includes/gs-footer.php';
    exit;
}

// Load the CMS page linked to this brand
$page = null;
try {
    // Try series_brand_id first (new column), then fallback to slug match
    $pStmt = $pdo->prepare("
        SELECT * FROM pages
        WHERE (series_brand_id = ? OR slug = ?)
        AND status = 'published'
        ORDER BY series_brand_id IS NOT NULL DESC
        LIMIT 1
    ");
    $pStmt->execute([$brand['id'], $slug]);
    $page = $pStmt->fetch();
} catch (PDOException $e) {
    // If series_brand_id column doesn't exist yet, fallback to slug
    try {
        $pStmt = $pdo->prepare("SELECT * FROM pages WHERE slug = ? AND status = 'published' LIMIT 1");
        $pStmt->execute([$slug]);
        $page = $pStmt->fetch();
    } catch (PDOException $e2) {}
}

$accentColor = $brand['accent_color'] ?: '#61CE70';

// Set header variables
$gsPageTitle = $brand['name'];
$gsMetaDesc = $page['meta_description'] ?? ($brand['description'] ?: ($brand['name'] . ' — tävlingsserie inom GravitySeries'));
$gsActiveNav = 'serier';
$gsEditUrl = $page ? '/admin/pages/edit.php?id=' . (int)$page['id'] : '/admin/pages/edit.php';

require_once __DIR__ . '/includes/gs-header.php';

$gsBaseUrl = '/gravityseries';

// Hero background style (from page hero_image or brand gradient)
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

<!-- SERIE HERO -->
<div class="gs-serie-hero" style="--c: <?= htmlspecialchars($accentColor) ?>">
  <div class="gs-serie-hero-bg"<?= $heroStyle ? ' style="' . $heroStyle . '"' : '' ?>></div>
  <div class="gs-serie-hero-inner">
    <a href="<?= $gsBaseUrl ?>/#serier" class="gs-serie-back">&larr; Alla serier</a>
    <h1 class="gs-serie-hero-title"><?= htmlspecialchars($brand['name']) ?></h1>
    <?php if ($brand['description']): ?>
      <p class="gs-serie-hero-desc"><?= htmlspecialchars($brand['description']) ?></p>
    <?php endif; ?>
  </div>
</div>

<!-- PAGE CONTENT -->
<?php if ($page && $page['content']): ?>
<div class="page-content-wrap">
  <div class="page-content">
    <?= $page['content'] ?>
  </div>
</div>
<?php else: ?>
<div class="page-content-wrap">
  <div class="page-content" style="text-align: center; padding: 64px 24px;">
    <p style="color: var(--text-2, var(--ink-2)); font-size: 16px;">
      Innehåll för denna serie kommer snart.
    </p>
    <p style="margin-top: 16px;">
      <a href="<?= $gsBaseUrl ?>/" style="color: var(--accent);">Tillbaka till startsidan</a>
    </p>
  </div>
</div>
<?php endif; ?>

<!-- HUB CTA -->
<div class="hub-cta-section" style="margin-top: 0;">
  <div class="hub-cta-inner">
    <div>
      <div class="hub-cta-title">Anmäl dig &amp; se resultat</div>
      <div class="hub-cta-sub">All anmälan och resultat finns på TheHUB.</div>
    </div>
    <a class="hub-cta-btn" href="https://thehub.gravityseries.se">
      <svg viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
      Öppna TheHUB
    </a>
  </div>
</div>

<?php if (!empty($gsIsAdmin)): ?>
<div class="gs-admin-bar">
  <?php if ($page): ?>
  <a class="gs-admin-btn" href="/admin/pages/edit.php?id=<?= (int)$page['id'] ?>">
    <svg viewBox="0 0 24 24"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
    Redigera
  </a>
  <?php else: ?>
  <a class="gs-admin-btn" href="/admin/pages/edit.php">
    <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
    Skapa sida
  </a>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/gs-footer.php'; ?>

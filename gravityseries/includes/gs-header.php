<?php
/**
 * GravitySeries — Site Header
 *
 * Variables available:
 *   $gsPageTitle   — Page title (for <title>)
 *   $gsMetaDesc    — Meta description
 *   $gsActiveNav   — Active nav slug (e.g. 'start', 'serier')
 *   $gsNavPages    — Array of nav pages from DB (optional, auto-loaded)
 */

// Ensure database connection
if (!isset($pdo)) {
    require_once __DIR__ . '/../../config.php';
    require_once __DIR__ . '/../../config/database.php';
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
}

// Load nav pages from DB
if (!isset($gsNavPages)) {
    try {
        $gsNavPages = $pdo->query("
            SELECT slug, nav_label, title
            FROM pages
            WHERE show_in_nav = 1 AND status = 'published'
            ORDER BY nav_order ASC
        ")->fetchAll();
    } catch (PDOException $e) {
        $gsNavPages = [];
    }
}

$gsPageTitle = $gsPageTitle ?? 'GravitySeries';
$gsMetaDesc = $gsMetaDesc ?? 'GravitySeries — Organisationen bakom svensk gravitycykling. Enduro och Downhill från Motion till Elite.';
$gsActiveNav = $gsActiveNav ?? '';
$gsCurrentSlug = $gsCurrentSlug ?? '';

// Determine base URL
$gsBaseUrl = '/gravityseries';

// Check if current user is admin (for edit pencil)
$gsIsAdmin = false;
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!empty($_SESSION['admin_role']) && $_SESSION['admin_role'] === 'admin') {
    $gsIsAdmin = true;
}
?>
<!DOCTYPE html>
<html lang="sv">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($gsPageTitle) ?> — GravitySeries</title>
<meta name="description" content="<?= htmlspecialchars($gsMetaDesc) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Barlow+Condensed:ital,wght@0,300;0,400;0,600;0,700;1,400&family=Barlow:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= $gsBaseUrl ?>/assets/css/gs-site.css">
</head>
<body>

<!-- SITE HEADER -->
<header class="site-header">
  <div class="header-inner">
    <a class="site-logo" href="<?= $gsBaseUrl ?>/">
      <span class="logo-dot"></span>
      GravitySeries
    </a>
    <nav class="site-nav">
      <a href="<?= $gsBaseUrl ?>/"<?= $gsActiveNav === 'start' ? ' class="active"' : '' ?>>Start</a>
      <a href="<?= $gsBaseUrl ?>/#serier"<?= $gsActiveNav === 'serier' ? ' class="active"' : '' ?>>Serier</a>
      <?php foreach ($gsNavPages as $navPage): ?>
        <a href="<?= $gsBaseUrl ?>/<?= htmlspecialchars($navPage['slug']) ?>"<?= $gsCurrentSlug === $navPage['slug'] ? ' class="active"' : '' ?>>
          <?= htmlspecialchars($navPage['nav_label'] ?: $navPage['title']) ?>
        </a>
      <?php endforeach; ?>
    </nav>
    <a class="hub-btn" href="https://thehub.gravityseries.se">
      <svg viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
      TheHUB
    </a>
    <button class="nav-toggle" onclick="toggleMobileNav()">
      <svg viewBox="0 0 24 24"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
    </button>
  </div>
</header>

<!-- Mobile nav -->
<div class="mobile-nav-overlay" id="mobileNavOverlay" onclick="toggleMobileNav()"></div>
<div class="mobile-nav-menu" id="mobileNavMenu">
  <a href="<?= $gsBaseUrl ?>/"<?= $gsActiveNav === 'start' ? ' class="active"' : '' ?>>Start</a>
  <a href="<?= $gsBaseUrl ?>/#serier"<?= $gsActiveNav === 'serier' ? ' class="active"' : '' ?>>Serier</a>
  <?php foreach ($gsNavPages as $navPage): ?>
    <a href="<?= $gsBaseUrl ?>/<?= htmlspecialchars($navPage['slug']) ?>"<?= $gsCurrentSlug === $navPage['slug'] ? ' class="active"' : '' ?>>
      <?= htmlspecialchars($navPage['nav_label'] ?: $navPage['title']) ?>
    </a>
  <?php endforeach; ?>
  <a class="hub-btn-mobile" href="https://thehub.gravityseries.se">
    <svg viewBox="0 0 24 24" style="width:14px;height:14px;stroke:var(--ink);fill:none;stroke-width:2.2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
    TheHUB
  </a>
</div>

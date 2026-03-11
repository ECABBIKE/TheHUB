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

// Load TheHUB config (gives us session, $pdo, hub_is_logged_in(), hub_current_user())
if (!isset($pdo)) {
    require_once __DIR__ . '/../../config.php';
    require_once __DIR__ . '/../../config/database.php';
    if (!isset($pdo)) {
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
}
// Load hub-config for user functions
if (!function_exists('hub_is_logged_in')) {
    require_once __DIR__ . '/../../hub-config.php';
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

// Check user status
$gsIsAdmin = false;
$gsIsLoggedIn = false;
$gsUserName = '';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_adminRole = $_SESSION['admin_role'] ?? '';
if (in_array($_adminRole, ['admin', 'super_admin'], true)) {
    $gsIsAdmin = true;
}
if (function_exists('hub_is_logged_in') && hub_is_logged_in()) {
    $gsIsLoggedIn = true;
    if (function_exists('hub_current_user')) {
        $gsUser = hub_current_user();
        $gsUserName = ($gsUser['firstname'] ?? '') ?: ($_SESSION['hub_user_name'] ?? '');
    }
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
    <div class="header-actions">
      <?php if ($gsIsAdmin): ?>
        <a class="header-icon-btn" href="/admin/pages/" title="Hantera sidor">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#888" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
        </a>
      <?php endif; ?>
      <?php if ($gsIsLoggedIn): ?>
        <a class="header-icon-btn" href="https://thehub.gravityseries.se/profile" title="Min profil">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#888" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        </a>
        <?php if ($gsIsAdmin): ?>
          <a class="header-icon-btn" href="https://thehub.gravityseries.se/admin/dashboard.php" title="Admin">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#888" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
          </a>
        <?php endif; ?>
      <?php endif; ?>
      <a class="hub-btn" href="https://thehub.gravityseries.se">
        <svg viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
        TheHUB
      </a>
    </div>
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
  <div class="mobile-nav-divider"></div>
  <?php if ($gsIsLoggedIn): ?>
    <a href="https://thehub.gravityseries.se/profile">
      <svg viewBox="0 0 24 24" style="width:16px;height:16px;stroke:var(--ink);fill:none;stroke-width:2" class="mobile-nav-icon"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
      Min profil
    </a>
  <?php endif; ?>
  <?php if ($gsIsAdmin): ?>
    <a href="/admin/pages/">
      <svg viewBox="0 0 24 24" style="width:16px;height:16px;stroke:var(--ink);fill:none;stroke-width:2" class="mobile-nav-icon"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
      Hantera sidor
    </a>
    <a href="https://thehub.gravityseries.se/admin/dashboard.php">
      <svg viewBox="0 0 24 24" style="width:16px;height:16px;stroke:var(--ink);fill:none;stroke-width:2" class="mobile-nav-icon"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
      Admin
    </a>
  <?php endif; ?>
  <a class="hub-btn-mobile" href="https://thehub.gravityseries.se">
    <svg viewBox="0 0 24 24" style="width:14px;height:14px;stroke:var(--ink);fill:none;stroke-width:2.2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
    TheHUB
  </a>
</div>

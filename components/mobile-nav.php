<?php
/**
 * TheHUB V3.5 Bottom Navigation
 * Uses SVG icons (Lucide-style)
 */
require_once __DIR__ . '/../v3-config.php';
require_once __DIR__ . '/icons.php';

$currentPage = $pageInfo['page'] ?? 'dashboard';
$isLoggedIn = hub_is_logged_in();

// Icon mapping for nav items
$navIcons = [
    'calendar' => 'calendar',
    'results' => 'flag',
    'series' => 'trophy',
    'database' => 'search',
    'ranking' => 'trending-up',
    'profile' => 'user',
];
?>
<nav class="mobile-nav" role="navigation" aria-label="Huvudnavigering">
  <div class="mobile-nav-inner">
    <?php foreach (HUB_NAV as $item): ?>
      <?php
      $isActive = hub_is_nav_active($item['id'], $currentPage);
      $iconName = $navIcons[$item['id']] ?? 'info';
      ?>
      <a href="<?= htmlspecialchars($item['url']) ?>"
         class="mobile-nav-link<?= $isActive ? ' active' : '' ?>"
         data-nav="<?= htmlspecialchars($item['id']) ?>"
         <?= $isActive ? 'aria-current="page"' : '' ?>
         aria-label="<?= htmlspecialchars($item['aria']) ?>">
        <span class="mobile-nav-icon" aria-hidden="true"><?= hub_icon($iconName) ?></span>
        <span class="mobile-nav-label"><?= htmlspecialchars($item['label']) ?></span>
      </a>
    <?php endforeach; ?>
  </div>
</nav>

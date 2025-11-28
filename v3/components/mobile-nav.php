<?php
/**
 * TheHUB V3.5 Bottom Navigation
 * 5 main sections: Kalender, Resultat, Databas, Ranking, Mitt
 */
$currentPage = $pageInfo['page'] ?? 'dashboard';
$isLoggedIn = hub_is_logged_in();

// Icon mapping for nav items
$icons = [
    'calendar' => '📅',
    'flag' => '🏁',
    'search' => '🔍',
    'trending-up' => '📊',
    'user' => '👤',
    // Legacy
    'home' => '🏠',
    'trophy' => '🏆',
    'users' => '👥',
    'shield' => '🛡️'
];
?>
<nav class="mobile-nav" role="navigation" aria-label="Huvudnavigering">
  <div class="mobile-nav-inner">
    <?php foreach (HUB_NAV as $item): ?>
      <?php $isActive = hub_is_nav_active($item['id'], $currentPage); ?>
      <a href="<?= htmlspecialchars($item['url']) ?>"
         class="mobile-nav-link<?= $isActive ? ' active' : '' ?>"
         data-nav="<?= htmlspecialchars($item['id']) ?>"
         <?= $isActive ? 'aria-current="page"' : '' ?>
         aria-label="<?= htmlspecialchars($item['aria']) ?>">
        <span class="mobile-nav-icon" aria-hidden="true"><?= $icons[$item['icon']] ?? '📄' ?></span>
        <span class="mobile-nav-label"><?= htmlspecialchars($item['label']) ?></span>
      </a>
    <?php endforeach; ?>
  </div>
</nav>

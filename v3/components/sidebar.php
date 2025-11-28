<?php
$currentPage = $pageInfo['page'] ?? 'dashboard';
$icons = [
    'calendar' => '📅',
    'flag' => '🏁',
    'trophy' => '🏆',
    'search' => '🔍',
    'trending-up' => '📈',
    'user' => '👤',
    'home' => '🏠',
    'users' => '👥',
    'shield' => '🛡️'
];
?>
<aside class="sidebar" role="navigation" aria-label="Huvudnavigering">
  <nav class="sidebar-nav">
    <?php foreach (HUB_NAV as $item): ?>
      <?php $isActive = hub_is_nav_active($item['id'], $currentPage); ?>
      <a href="<?= htmlspecialchars($item['url']) ?>"
         class="sidebar-link"
         data-nav="<?= htmlspecialchars($item['id']) ?>"
         <?= $isActive ? 'aria-current="page"' : '' ?>
         aria-label="<?= htmlspecialchars($item['aria']) ?>">
        <span class="sidebar-icon" aria-hidden="true"><?= $icons[$item['icon']] ?? '📄' ?></span>
        <span class="sidebar-label"><?= htmlspecialchars($item['label']) ?></span>
      </a>
    <?php endforeach; ?>
  </nav>
</aside>

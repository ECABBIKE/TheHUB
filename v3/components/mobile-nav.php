<?php
$currentPage = $pageInfo['page'] ?? 'dashboard';
$icons = ['home'=>'🏠','trophy'=>'🏆','flag'=>'🏁','users'=>'👥','shield'=>'🛡️','trending-up'=>'📈'];
?>
<nav class="mobile-nav" role="navigation" aria-label="Mobilnavigering">
  <div class="mobile-nav-inner">
    <?php foreach (HUB_NAV as $item): ?>
      <?php $isActive = hub_is_nav_active($item['id'], $currentPage); ?>
      <a href="<?= htmlspecialchars($item['url']) ?>"
         class="mobile-nav-link"
         data-nav="<?= htmlspecialchars($item['id']) ?>"
         <?= $isActive ? 'aria-current="page"' : '' ?>
         aria-label="<?= htmlspecialchars($item['aria']) ?>">
        <span class="mobile-nav-icon" aria-hidden="true"><?= $icons[$item['icon']] ?? '📄' ?></span>
        <span><?= htmlspecialchars($item['label']) ?></span>
      </a>
    <?php endforeach; ?>
  </div>
</nav>

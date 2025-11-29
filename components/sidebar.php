<?php
/**
 * TheHUB V3.5 - Sidebar Navigation
 * Uses Lucide-style SVG icons
 */
require_once HUB_V3_ROOT . '/components/icons.php';

$currentPage = $pageInfo['page'] ?? 'dashboard';
?>
<aside class="sidebar" role="navigation" aria-label="Huvudnavigering">
  <nav class="sidebar-nav">
    <?php foreach (HUB_NAV as $item): ?>
      <?php $isActive = hub_is_nav_active($item['id'], $currentPage); ?>
      <a href="<?= htmlspecialchars($item['url']) ?>"
         class="sidebar-link<?= $isActive ? ' is-active' : '' ?>"
         data-nav="<?= htmlspecialchars($item['id']) ?>"
         <?= $isActive ? 'aria-current="page"' : '' ?>
         aria-label="<?= htmlspecialchars($item['aria']) ?>">
        <span class="sidebar-icon" aria-hidden="true"><?= hub_icon($item['icon'], 'sidebar-icon-svg') ?></span>
        <span class="sidebar-label"><?= htmlspecialchars($item['label']) ?></span>
      </a>
    <?php endforeach; ?>
  </nav>
</aside>

<style>
/* Sidebar icon styling for Lucide SVGs */
.sidebar-icon-svg {
    width: 20px;
    height: 20px;
    flex-shrink: 0;
    transition: color var(--transition-fast);
}

.sidebar-link {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    padding: var(--space-sm) var(--space-md);
    color: var(--color-text-secondary);
    text-decoration: none;
    border-radius: var(--radius-md);
    transition: all var(--transition-fast);
}

.sidebar-link:hover {
    background: var(--color-bg-hover);
    color: var(--color-text-primary);
}

.sidebar-link.is-active {
    background: var(--color-accent-bg);
    color: var(--color-accent);
}

.sidebar-link.is-active .sidebar-icon-svg {
    color: var(--color-accent);
}

.sidebar-icon {
    display: flex;
    align-items: center;
    justify-content: center;
}

.sidebar-label {
    font-size: var(--text-sm);
    font-weight: var(--weight-medium);
}
</style>

<?php
/**
 * TheHUB V3.5 - Sidebar Navigation
 * Uses Lucide-style SVG icons
 * Includes admin navigation for admin users
 */
require_once HUB_V3_ROOT . '/components/icons.php';

$currentPage = $pageInfo['page'] ?? 'dashboard';
$currentSection = $pageInfo['section'] ?? '';
$isAdminUser = hub_is_admin();
$isAdminSection = strpos($_SERVER['REQUEST_URI'], '/admin') === 0;

// Admin navigation items
$adminNav = [
    ['id' => 'admin-dashboard', 'label' => 'Dashboard', 'icon' => 'layout-dashboard', 'url' => '/admin/dashboard', 'aria' => 'Admin Dashboard'],
    ['id' => 'admin-events', 'label' => 'Events', 'icon' => 'calendar', 'url' => '/admin/events', 'aria' => 'Hantera events'],
    ['id' => 'admin-series', 'label' => 'Serier', 'icon' => 'trophy', 'url' => '/admin/series', 'aria' => 'Hantera serier'],
    ['id' => 'admin-riders', 'label' => 'Deltagare', 'icon' => 'users', 'url' => '/admin/riders', 'aria' => 'Hantera deltagare'],
    ['id' => 'admin-clubs', 'label' => 'Klubbar', 'icon' => 'building', 'url' => '/admin/clubs', 'aria' => 'Hantera klubbar'],
    ['id' => 'admin-classes', 'label' => 'Klasser', 'icon' => 'layers', 'url' => '/admin/classes', 'aria' => 'Hantera klasser'],
    ['id' => 'admin-import', 'label' => 'Import', 'icon' => 'upload', 'url' => '/admin/import', 'aria' => 'Importera data'],
    ['id' => 'admin-ranking', 'label' => 'Ranking', 'icon' => 'bar-chart-2', 'url' => '/admin/ranking', 'aria' => 'Hantera ranking'],
    ['id' => 'admin-settings', 'label' => 'Inställningar', 'icon' => 'settings', 'url' => '/admin/settings', 'aria' => 'Systeminställningar'],
];

// Check if current admin page is active
function isAdminPageActive($itemId, $requestUri) {
    $page = str_replace('admin-', '', $itemId);
    return strpos($requestUri, '/admin/' . $page) !== false;
}
?>
<aside class="sidebar" role="navigation" aria-label="Huvudnavigering">
  <nav class="sidebar-nav">
    <?php if ($isAdminSection && $isAdminUser): ?>
      <!-- Admin Navigation -->
      <div class="sidebar-section">
        <div class="sidebar-section-title">Admin</div>
        <?php foreach ($adminNav as $item): ?>
          <?php $isActive = isAdminPageActive($item['id'], $_SERVER['REQUEST_URI']); ?>
          <a href="<?= htmlspecialchars($item['url']) ?>"
             class="sidebar-link<?= $isActive ? ' is-active' : '' ?>"
             data-nav="<?= htmlspecialchars($item['id']) ?>"
             <?= $isActive ? 'aria-current="page"' : '' ?>
             aria-label="<?= htmlspecialchars($item['aria']) ?>">
            <span class="sidebar-icon" aria-hidden="true"><?= hub_icon($item['icon'], 'sidebar-icon-svg') ?></span>
            <span class="sidebar-label"><?= htmlspecialchars($item['label']) ?></span>
          </a>
        <?php endforeach; ?>
      </div>

      <!-- Divider -->
      <div class="sidebar-divider"></div>

      <!-- Back to Public Site -->
      <div class="sidebar-section">
        <div class="sidebar-section-title">Publik</div>
    <?php endif; ?>

    <!-- Public Navigation -->
    <?php foreach (HUB_NAV as $item): ?>
      <?php $isActive = !$isAdminSection && hub_is_nav_active($item['id'], $currentPage); ?>
      <a href="<?= htmlspecialchars($item['url']) ?>"
         class="sidebar-link<?= $isActive ? ' is-active' : '' ?>"
         data-nav="<?= htmlspecialchars($item['id']) ?>"
         <?= $isActive ? 'aria-current="page"' : '' ?>
         aria-label="<?= htmlspecialchars($item['aria']) ?>">
        <span class="sidebar-icon" aria-hidden="true"><?= hub_icon($item['icon'], 'sidebar-icon-svg') ?></span>
        <span class="sidebar-label"><?= htmlspecialchars($item['label']) ?></span>
      </a>
    <?php endforeach; ?>

    <?php if ($isAdminSection && $isAdminUser): ?>
      </div>
    <?php endif; ?>

    <?php if (!$isAdminSection && $isAdminUser): ?>
      <!-- Admin Link for non-admin pages -->
      <div class="sidebar-divider"></div>
      <a href="/admin/dashboard"
         class="sidebar-link sidebar-link--admin"
         data-nav="admin"
         aria-label="Gå till admin">
        <span class="sidebar-icon" aria-hidden="true"><?= hub_icon('settings', 'sidebar-icon-svg') ?></span>
        <span class="sidebar-label">Admin</span>
      </a>
    <?php endif; ?>
  </nav>
</aside>

<style>
/* Sidebar icon styling for Lucide SVGs - uses compact vertical layout from layout.css */
.sidebar-icon-svg {
    width: 20px;
    height: 20px;
    color: var(--color-text-secondary);
    stroke: currentColor;
}

/* Active state for is-active class */
.sidebar-link.is-active {
    background: var(--color-accent-light);
    color: var(--color-accent-text);
}

.sidebar-link.is-active .sidebar-icon-svg {
    color: var(--color-accent-text);
}

/* Ensure all sidebar icons have proper color */
.sidebar-link {
    color: var(--color-text-secondary);
}

.sidebar-link:hover {
    color: var(--color-text-primary);
    background: var(--color-bg-hover);
}

.sidebar-link:hover .sidebar-icon-svg {
    color: var(--color-text-primary);
}

.sidebar-icon svg {
    color: inherit;
    stroke: currentColor;
}

/* Sidebar Sections */
.sidebar-section {
    margin-bottom: var(--space-xs);
}

.sidebar-section-title {
    font-size: 9px;
    font-weight: var(--weight-semibold);
    color: var(--color-text-muted);
    text-transform: uppercase;
    letter-spacing: 0.05em;
    padding: var(--space-xs) var(--space-sm);
    text-align: center;
}

.sidebar-divider {
    height: 1px;
    background: var(--color-border);
    margin: var(--space-xs) var(--space-sm);
}

/* Admin link styling */
.sidebar-link--admin {
    color: var(--color-accent-text) !important;
}
</style>

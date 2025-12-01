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
    ['id' => 'admin-users', 'label' => 'Användare', 'icon' => 'user-cog', 'url' => '/admin/users', 'aria' => 'Hantera användare'],
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
             data-tooltip="<?= htmlspecialchars($item['label']) ?>"
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
         data-tooltip="<?= htmlspecialchars($item['label']) ?>"
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
         data-tooltip="Admin"
         aria-label="Gå till admin">
        <span class="sidebar-icon" aria-hidden="true"><?= hub_icon('settings', 'sidebar-icon-svg') ?></span>
        <span class="sidebar-label">Admin</span>
      </a>
    <?php endif; ?>
  </nav>
</aside>

<style>
/* ========================================================================
   SIDEBAR - Icon-only with tooltips (70px wide)
   ======================================================================== */

/* Sidebar icon styling for Lucide SVGs */
.sidebar-icon-svg {
    width: 22px;
    height: 22px;
    color: inherit;
    stroke: currentColor;
}

/* Sidebar link - icon only */
.sidebar-link {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 48px;
    height: 48px;
    margin: 0 auto;
    color: var(--color-text-secondary);
    text-decoration: none;
    border-radius: var(--radius-md);
    transition: all 0.2s ease;
    position: relative;
}

.sidebar-link:hover {
    color: var(--color-text-primary);
    background: var(--color-bg-hover);
}

.sidebar-link:hover .sidebar-icon-svg {
    color: var(--color-text-primary);
}

/* Active state for is-active class */
.sidebar-link.is-active {
    background: var(--color-accent-light);
    color: var(--color-accent-text);
}

.sidebar-link.is-active .sidebar-icon-svg {
    color: var(--color-accent-text);
}

/* Active indicator bar */
.sidebar-link.is-active::before {
    content: '';
    position: absolute;
    left: -12px;
    top: 50%;
    transform: translateY(-50%);
    width: 3px;
    height: 24px;
    background: var(--color-accent);
    border-radius: 0 2px 2px 0;
}

.sidebar-icon svg {
    color: inherit;
    stroke: currentColor;
}

/* Hide label - show only icon */
.sidebar-label {
    display: none;
}

/* Tooltip on hover */
.sidebar-link::after {
    content: attr(data-tooltip);
    position: absolute;
    left: 100%;
    top: 50%;
    transform: translateY(-50%);
    margin-left: 12px;
    padding: var(--space-xs) var(--space-sm);
    background: var(--color-bg-elevated, #1a1a2e);
    color: var(--color-text-primary);
    font-size: var(--text-sm);
    font-weight: var(--weight-medium);
    white-space: nowrap;
    border-radius: var(--radius-md);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
    opacity: 0;
    visibility: hidden;
    transition: all 0.15s ease;
    z-index: 1000;
    pointer-events: none;
}

.sidebar-link:hover::after {
    opacity: 1;
    visibility: visible;
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

/* ========================================================================
   MOBILE - Show labels, hide tooltips
   ======================================================================== */
@media (max-width: 899px) {
    .sidebar-link {
        flex-direction: row;
        justify-content: flex-start;
        width: 100%;
        height: auto;
        gap: var(--space-md);
        padding: var(--space-md) var(--space-lg);
        margin: 0;
    }

    .sidebar-label {
        display: block;
        font-size: var(--text-sm);
    }

    .sidebar-link::after {
        display: none;
    }

    .sidebar-link.is-active::before {
        left: 0;
    }
}
</style>

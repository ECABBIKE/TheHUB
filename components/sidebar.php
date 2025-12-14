<?php
/**
 * TheHUB V3.5 - Sidebar Navigation
 * Uses Lucide-style SVG icons
 * Includes admin navigation for admin users
 */

// Ensure v3-config is loaded
if (!defined('HUB_V3_ROOT')) {
    $v3Config = __DIR__ . '/../v3-config.php';
    if (file_exists($v3Config)) {
        require_once $v3Config;
    } else {
        // Fallback definition
        define('HUB_V3_ROOT', dirname(__DIR__));
    }
}

require_once __DIR__ . '/icons.php';

$currentPage = $pageInfo['page'] ?? 'dashboard';
$currentSection = $pageInfo['section'] ?? '';
$isAdminUser = function_exists('hub_is_admin') ? hub_is_admin() : false;
$isAdminSection = strpos($_SERVER['REQUEST_URI'], '/admin') === 0;

// Fallback for hub_is_nav_active if not defined
if (!function_exists('hub_is_nav_active')) {
    function hub_is_nav_active($navId, $currentPage) {
        return $navId === $currentPage;
    }
}

// Fallback nav items if HUB_NAV not defined
$sidebarNav = defined('HUB_NAV') ? HUB_NAV : [
    ['id' => 'calendar', 'label' => 'Kalender', 'icon' => 'calendar', 'url' => '/calendar', 'aria' => 'Kalender'],
    ['id' => 'results', 'label' => 'Resultat', 'icon' => 'flag', 'url' => '/results', 'aria' => 'Resultat'],
    ['id' => 'series', 'label' => 'Serier', 'icon' => 'trophy', 'url' => '/series', 'aria' => 'Serier'],
    ['id' => 'database', 'label' => 'Databas', 'icon' => 'search', 'url' => '/database', 'aria' => 'Databas'],
    ['id' => 'ranking', 'label' => 'Ranking', 'icon' => 'trending-up', 'url' => '/ranking', 'aria' => 'Ranking'],
];

// Load admin tabs config to get navigation from single source of truth
require_once __DIR__ . '/../includes/config/admin-tabs-config.php';

// Build admin navigation from config - show only main groups
$adminNav = [
    ['id' => 'admin-dashboard', 'label' => 'Dashboard', 'icon' => 'layout-dashboard', 'url' => '/admin/dashboard', 'aria' => 'Admin Dashboard'],
];

// Add main groups from ADMIN_TABS config
foreach ($ADMIN_TABS as $groupId => $group) {
    // Skip super_admin_only groups for non-super admins
    if (isset($group['super_admin_only']) && $group['super_admin_only']) {
        if (!function_exists('hasRole') || !hasRole('super_admin')) {
            continue;
        }
    }

    // Get first tab's URL as the group URL
    $firstTabUrl = $group['tabs'][0]['url'] ?? '/admin/';

    $adminNav[] = [
        'id' => 'admin-' . $groupId,
        'label' => $group['title'],
        'icon' => $group['icon'],
        'url' => $firstTabUrl,
        'aria' => $group['title'],
        'pages' => get_pages_in_group($groupId) // All pages in this group
    ];
}

// Check if current admin page is active - using pages array from config
function isAdminPageActive($item, $requestUri) {
    // Get current page name from URI
    $currentPage = basename(parse_url($requestUri, PHP_URL_PATH));

    // If item has pages array, check if current page is in it
    if (isset($item['pages']) && is_array($item['pages'])) {
        return in_array($currentPage, $item['pages']);
    }

    // Fallback: check by URL prefix
    $groupId = str_replace('admin-', '', $item['id']);
    return strpos($requestUri, '/admin/' . $groupId) !== false;
}
?>
<aside class="sidebar" role="navigation" aria-label="Huvudnavigering">
  <nav class="sidebar-nav">
    <?php if ($isAdminSection && $isAdminUser): ?>
      <!-- Admin Navigation -->
      <div class="sidebar-section">
        <div class="sidebar-section-title">Admin</div>
        <?php foreach ($adminNav as $item): ?>
          <?php $isActive = isAdminPageActive($item, $_SERVER['REQUEST_URI']); ?>
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
    <?php foreach ($sidebarNav as $item): ?>
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

/* Sidebar link - icon with label below */
.sidebar-link {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 2px;
    padding: var(--space-sm) var(--space-xs);
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

/* Label under icon */
.sidebar-label {
    display: block;
    font-size: 10px;
    font-weight: var(--weight-medium);
    line-height: 1.2;
    text-align: center;
    margin-top: 2px;
}

/* Tooltip disabled - labels are visible */
.sidebar-link::after {
    display: none;
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
   MOBILE PORTRAIT - Slide-out menu with horizontal links
   ======================================================================== */
@media (max-width: 899px) and (orientation: portrait) {
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

/* ========================================================================
   MOBILE/TABLET LANDSCAPE - Compact icon-only sidebar
   ======================================================================== */
@media (max-width: 1023px) and (orientation: landscape) {
    .sidebar-link {
        flex-direction: column !important;
        padding: 6px 4px !important;
        gap: 1px !important;
        margin: 0 !important;
        width: auto !important;
    }

    .sidebar-label {
        display: none !important;
    }

    .sidebar-icon-svg {
        width: 20px !important;
        height: 20px !important;
    }

    .sidebar-link.is-active::before {
        left: -4px !important;
        height: 20px !important;
    }
}
</style>

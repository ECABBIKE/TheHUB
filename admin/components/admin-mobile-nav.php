<?php
/**
 * TheHUB Admin Mobile Navigation
 * Horizontally scrollable bottom nav for admin pages
 */
require_once __DIR__ . '/../../v3-config.php';
require_once __DIR__ . '/../../components/icons.php';

// Admin navigation items (same as sidebar)
$adminNav = [
    ['id' => 'dashboard', 'label' => 'Start', 'icon' => 'layout-dashboard', 'url' => '/admin/dashboard'],
    ['id' => 'events', 'label' => 'Events', 'icon' => 'calendar', 'url' => '/admin/events'],
    ['id' => 'series', 'label' => 'Serier', 'icon' => 'trophy', 'url' => '/admin/series'],
    ['id' => 'riders', 'label' => 'Ryttare', 'icon' => 'users', 'url' => '/admin/riders'],
    ['id' => 'clubs', 'label' => 'Klubbar', 'icon' => 'building', 'url' => '/admin/clubs'],
    ['id' => 'classes', 'label' => 'Klasser', 'icon' => 'layers', 'url' => '/admin/classes'],
    ['id' => 'import', 'label' => 'Import', 'icon' => 'upload', 'url' => '/admin/import'],
    ['id' => 'ranking', 'label' => 'Ranking', 'icon' => 'bar-chart-2', 'url' => '/admin/ranking'],
    ['id' => 'users', 'label' => 'Användare', 'icon' => 'user-cog', 'url' => '/admin/users'],
    ['id' => 'settings', 'label' => 'Inst.', 'icon' => 'settings', 'url' => '/admin/settings'],
];

// Determine active page from URL
$requestUri = $_SERVER['REQUEST_URI'] ?? '';
function isAdminNavActive($id, $uri) {
    // Special case for dashboard - must be exact match
    if ($id === 'dashboard') {
        return preg_match('#^/admin/(dashboard)?$#', parse_url($uri, PHP_URL_PATH));
    }
    return strpos($uri, '/admin/' . $id) !== false;
}
?>
<nav class="admin-mobile-nav" role="navigation" aria-label="Admin navigering">
    <div class="admin-mobile-nav-inner">
        <?php foreach ($adminNav as $item): ?>
            <?php $isActive = isAdminNavActive($item['id'], $requestUri); ?>
            <a href="<?= htmlspecialchars($item['url']) ?>"
               class="admin-mobile-nav-link<?= $isActive ? ' active' : '' ?>"
               <?= $isActive ? 'aria-current="page"' : '' ?>>
                <span class="admin-mobile-nav-icon"><?= hub_icon($item['icon']) ?></span>
                <span class="admin-mobile-nav-label"><?= htmlspecialchars($item['label']) ?></span>
            </a>
        <?php endforeach; ?>
    </div>
</nav>

<style>
/* Admin Mobile Navigation - Scrollable */
.admin-mobile-nav {
    display: none;
    position: fixed;
    bottom: 0;
    left: 0;
    right: 0;
    height: var(--mobile-nav-height, 64px);
    padding-bottom: env(safe-area-inset-bottom);
    background: var(--color-bg-surface);
    border-top: 1px solid var(--color-border);
    z-index: var(--z-fixed, 1000);
}

.admin-mobile-nav-inner {
    display: flex;
    align-items: center;
    height: 100%;
    overflow-x: auto;
    overflow-y: hidden;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: none; /* Firefox */
    -ms-overflow-style: none; /* IE/Edge */
    padding: 0 var(--space-xs);
    gap: var(--space-2xs);
}

.admin-mobile-nav-inner::-webkit-scrollbar {
    display: none; /* Chrome/Safari */
}

.admin-mobile-nav-link {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 2px;
    min-width: 56px;
    padding: var(--space-xs) var(--space-sm);
    color: var(--color-text-muted);
    font-size: 10px;
    font-weight: var(--weight-medium);
    text-decoration: none;
    border-radius: var(--radius-md);
    transition: color 0.15s ease, background 0.15s ease;
    flex-shrink: 0;
}

.admin-mobile-nav-link:hover {
    color: var(--color-text-secondary);
    background: var(--color-bg-hover);
}

.admin-mobile-nav-link.active {
    color: var(--color-accent-text);
    background: var(--color-accent-light);
}

.admin-mobile-nav-icon {
    display: flex;
    align-items: center;
    justify-content: center;
}

.admin-mobile-nav-icon svg {
    width: 20px;
    height: 20px;
    stroke-width: 2;
}

.admin-mobile-nav-label {
    white-space: nowrap;
    text-overflow: ellipsis;
    overflow: hidden;
    max-width: 52px;
}

/* Show on mobile/tablet */
@media (max-width: 899px) {
    .admin-mobile-nav {
        display: block;
    }
}

/* Even more compact on very small screens */
@media (max-width: 400px) {
    .admin-mobile-nav-link {
        min-width: 48px;
        padding: var(--space-2xs) var(--space-xs);
        font-size: 9px;
    }

    .admin-mobile-nav-icon svg {
        width: 18px;
        height: 18px;
    }

    .admin-mobile-nav-label {
        max-width: 44px;
    }
}

/* Scroll indicator gradient */
.admin-mobile-nav::before,
.admin-mobile-nav::after {
    content: '';
    position: absolute;
    top: 0;
    bottom: env(safe-area-inset-bottom);
    width: 24px;
    pointer-events: none;
    z-index: 1;
}

.admin-mobile-nav::before {
    left: 0;
    background: linear-gradient(to right, var(--color-bg-surface), transparent);
    opacity: 0;
    transition: opacity 0.2s;
}

.admin-mobile-nav::after {
    right: 0;
    background: linear-gradient(to left, var(--color-bg-surface), transparent);
}

/* Hide right gradient when scrolled to end (JS controlled) */
.admin-mobile-nav.scrolled-start::before {
    opacity: 0;
}

.admin-mobile-nav.scrolled-end::after {
    opacity: 0;
}

.admin-mobile-nav.can-scroll-left::before {
    opacity: 1;
}

.admin-mobile-nav.can-scroll-right::after {
    opacity: 1;
}
</style>

<script>
// Add scroll indicators
document.addEventListener('DOMContentLoaded', function() {
    const nav = document.querySelector('.admin-mobile-nav');
    const inner = document.querySelector('.admin-mobile-nav-inner');

    if (!nav || !inner) return;

    function updateScrollIndicators() {
        const canScrollLeft = inner.scrollLeft > 10;
        const canScrollRight = inner.scrollLeft < (inner.scrollWidth - inner.clientWidth - 10);

        nav.classList.toggle('can-scroll-left', canScrollLeft);
        nav.classList.toggle('can-scroll-right', canScrollRight);
    }

    inner.addEventListener('scroll', updateScrollIndicators);
    window.addEventListener('resize', updateScrollIndicators);

    // Initial check
    setTimeout(updateScrollIndicators, 100);

    // Scroll active item into view on load
    const activeLink = inner.querySelector('.admin-mobile-nav-link.active');
    if (activeLink) {
        activeLink.scrollIntoView({ behavior: 'smooth', inline: 'center', block: 'nearest' });
    }
});
</script>

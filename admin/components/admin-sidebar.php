<?php
/**
 * Admin Sidebar Navigation
 * Narrow 64px sidebar with icons only (like public site)
 * Text appears in tooltip on hover
 *
 * Role-based access:
 * - promotor (role=2): Only events and sponsors
 * - admin (role=3): Everything except system settings
 * - super_admin (role=4): Full access
 */

// Get current user's role
$currentAdminRole = $_SESSION['admin_role'] ?? 'admin';
$isPromotor = ($currentAdminRole === 'promotor');
$isAdmin = in_array($currentAdminRole, ['admin', 'super_admin']);
$isSuperAdmin = ($currentAdminRole === 'super_admin');

// Define navigation structure - ENDAST: Dashboard, Tävlingar, Serier, Databas, Import, System
// 'min_role': 'promotor' = all, 'admin' = admin+super, 'super_admin' = super only
$admin_nav = [
    [
        'id' => 'dashboard',
        'label' => 'Dashboard',
        'icon' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg>',
        'url' => '/admin/dashboard',
        'active' => $current_admin_page === 'dashboard',
        'min_role' => 'admin'
    ],
    [
        'id' => 'events',
        'label' => 'Tävlingar',
        'icon' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/></svg>',
        'url' => '/admin/events',
        'active' => in_array($current_admin_page, ['events', 'event-create', 'event-edit', 'edit-results', 'results', 'classes']),
        'min_role' => 'promotor'
    ],
    [
        'id' => 'series',
        'label' => 'Serier',
        'icon' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M4 22h16"/><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"/><path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"/><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/></svg>',
        'url' => '/admin/series',
        'active' => in_array($current_admin_page, ['series', 'series-edit', 'series-events', 'series-pricing', 'series-brands', 'point-scales']),
        'min_role' => 'admin'
    ],
    [
        'id' => 'riders',
        'label' => 'Databas',
        'icon' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
        'url' => '/admin/riders',
        'active' => in_array($current_admin_page, ['riders', 'rider-edit', 'clubs', 'club-edit', 'find-duplicates', 'cleanup-duplicates', 'cleanup-clubs']),
        'min_role' => 'admin'
    ],
    [
        'id' => 'import',
        'label' => 'Import',
        'icon' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" x2="12" y1="3" y2="15"/></svg>',
        'url' => '/admin/import',
        'active' => strpos($current_admin_page, 'import') !== false,
        'min_role' => 'admin'
    ],
    [
        'id' => 'settings',
        'label' => 'System',
        'icon' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg>',
        'url' => '/admin/settings',
        'active' => in_array($current_admin_page, ['settings', 'public-settings', 'global-texts', 'tools', 'system-settings', 'role-permissions', 'pricing-templates', 'rebuild-stats', 'role-management', 'club-admins', 'sponsors', 'media', 'ranking', 'payment-recipients']),
        'min_role' => 'admin'
    ]
];

// Filter navigation based on user role
$roleHierarchy = ['promotor' => 1, 'admin' => 2, 'super_admin' => 3];
$userRoleLevel = $roleHierarchy[$currentAdminRole] ?? 0;

$admin_nav = array_filter($admin_nav, function($item) use ($roleHierarchy, $userRoleLevel, $currentAdminRole) {
    // If only_role is set, only that specific role can see it
    if (isset($item['only_role'])) {
        return $currentAdminRole === $item['only_role'];
    }
    // Otherwise use hierarchical min_role check
    $requiredLevel = $roleHierarchy[$item['min_role'] ?? 'admin'] ?? 2;
    return $userRoleLevel >= $requiredLevel;
});

// Public navigation items (visible to all)
$public_nav = [
    [
        'id' => 'calendar',
        'label' => 'Kalender',
        'icon' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/></svg>',
        'url' => '/calendar'
    ],
    [
        'id' => 'results',
        'label' => 'Resultat',
        'icon' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" x2="4" y1="22" y2="15"/></svg>',
        'url' => '/results'
    ],
    [
        'id' => 'series',
        'label' => 'Serier',
        'icon' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M4 22h16"/><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"/><path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"/><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/></svg>',
        'url' => '/series'
    ],
    [
        'id' => 'database',
        'label' => 'Databas',
        'icon' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>',
        'url' => '/database'
    ],
    [
        'id' => 'ranking',
        'label' => 'Ranking',
        'icon' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/></svg>',
        'url' => '/ranking'
    ]
];

?>

<aside class="admin-sidebar" id="adminSidebar">
    <nav class="admin-nav">
        <?php foreach ($admin_nav as $item): ?>
            <a
                href="<?= htmlspecialchars($item['url']) ?>"
                class="nav-item <?= !empty($item['active']) ? 'active' : '' ?>"
                data-tooltip="<?= htmlspecialchars($item['label']) ?>"
            >
                <?= $item['icon'] ?>
                <span class="nav-label"><?= htmlspecialchars($item['label']) ?></span>
            </a>
        <?php endforeach; ?>

        <!-- Separator -->
        <div class="nav-separator"></div>

        <!-- Public navigation -->
        <?php foreach ($public_nav as $item): ?>
            <a
                href="<?= htmlspecialchars($item['url']) ?>"
                class="nav-item nav-item--public"
                data-tooltip="<?= htmlspecialchars($item['label']) ?>"
            >
                <?= $item['icon'] ?>
                <span class="nav-label"><?= htmlspecialchars($item['label']) ?></span>
            </a>
        <?php endforeach; ?>
    </nav>
</aside>

<!-- Sidebar overlay for mobile -->
<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeAdminSidebar()"></div>

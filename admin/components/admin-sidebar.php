<?php
/**
 * Admin Sidebar Navigation
 * Narrow 64px sidebar with icons only (like public site)
 * Text appears in tooltip on hover
 */

// Define navigation structure - simple flat list
$admin_nav = [
    [
        'id' => 'dashboard',
        'label' => 'Dashboard',
        'icon' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg>',
        'url' => '/admin/dashboard',
        'active' => $current_admin_page === 'dashboard'
    ],
    [
        'id' => 'events',
        'label' => 'Events',
        'icon' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/></svg>',
        'url' => '/admin/events',
        'active' => in_array($current_admin_page, ['events', 'event-create', 'event-edit', 'edit-results', 'results', 'classes'])
    ],
    [
        'id' => 'series',
        'label' => 'Serier',
        'icon' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M4 22h16"/><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"/><path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"/><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/></svg>',
        'url' => '/admin/series',
        'active' => in_array($current_admin_page, ['series', 'series-events', 'series-pricing', 'point-scales'])
    ],
    [
        'id' => 'riders',
        'label' => 'Deltagare',
        'icon' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
        'url' => '/admin/riders',
        'active' => in_array($current_admin_page, ['riders', 'rider-edit', 'find-duplicates', 'cleanup-duplicates'])
    ],
    [
        'id' => 'clubs',
        'label' => 'Klubbar',
        'icon' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 22V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v18Z"/><path d="M6 12H4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h2"/><path d="M18 9h2a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-2"/><path d="M10 6h4"/><path d="M10 10h4"/><path d="M10 14h4"/><path d="M10 18h4"/></svg>',
        'url' => '/admin/clubs',
        'active' => in_array($current_admin_page, ['clubs', 'club-edit', 'cleanup-clubs'])
    ],
    // Classes moved under Events - accessed via tabs
    [
        'id' => 'import',
        'label' => 'Import',
        'icon' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" x2="12" y1="3" y2="15"/></svg>',
        'url' => '/admin/import',
        'active' => strpos($current_admin_page, 'import') !== false
    ],
    [
        'id' => 'ranking',
        'label' => 'Ranking',
        'icon' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" x2="18" y1="20" y2="10"/><line x1="12" x2="12" y1="20" y2="4"/><line x1="6" x2="6" y1="20" y2="14"/></svg>',
        'url' => '/admin/ranking',
        'active' => strpos($current_admin_page, 'ranking') !== false
    ],
    [
        'id' => 'media',
        'label' => 'Media',
        'icon' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/></svg>',
        'url' => '/admin/media',
        'active' => $current_admin_page === 'media'
    ],
    [
        'id' => 'sponsors',
        'label' => 'Sponsorer',
        'icon' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.42 4.58a5.4 5.4 0 0 0-7.65 0l-.77.78-.77-.78a5.4 5.4 0 0 0-7.65 0C1.46 6.7 1.33 10.28 4 13l8 8 8-8c2.67-2.72 2.54-6.3.42-8.42z"/></svg>',
        'url' => '/admin/sponsors',
        'active' => $current_admin_page === 'sponsors'
    ],
    // Venues temporarily hidden - TODO: Re-enable when venues functionality is complete
    // [
    //     'id' => 'venues',
    //     'label' => 'Banor',
    //     'icon' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>',
    //     'url' => '/admin/venues',
    //     'active' => $current_admin_page === 'venues'
    // ],
    [
        'id' => 'settings',
        'label' => 'Inställningar',
        'icon' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg>',
        'url' => '/admin/settings',
        'active' => in_array($current_admin_page, ['settings', 'public-settings', 'global-texts', 'tools', 'system-settings', 'role-permissions', 'pricing-templates', 'rebuild-stats'])
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
    </nav>

    <!-- Sidebar Footer -->
    <div class="sidebar-footer">
        <a href="/" class="sidebar-link" data-tooltip="Tillbaka till sajten">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m12 19-7-7 7-7"/><path d="M19 12H5"/></svg>
            <span class="nav-label">Tillbaka</span>
        </a>
    </div>
</aside>

<!-- Sidebar overlay for mobile -->
<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeAdminSidebar()"></div>

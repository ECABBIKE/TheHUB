<?php
/**
 * Flik-konfiguration för admin-grupper
 * Definierar flikar för varje huvudgrupp i Admin 2.0
 *
 * Struktur:
 * 1. Tävlingar - Events, resultat, venues, biljetter
 * 2. Serier - Serier, ranking, klubbpoäng
 * 3. Deltagare - Deltagare och klubbar
 * 4. Konfiguration - Klasser, licenser, poängskalor, regler, texter
 * 5. Import - All dataimport
 * 6. System - Användarhantering och systemadmin (super admin)
 */

$ADMIN_TABS = [

    // ========================================
    // TÄVLINGAR
    // ========================================
    'competitions' => [
        'title' => 'Tävlingar',
        'icon' => 'calendar-check',
        'tabs' => [
            [
                'id' => 'events',
                'label' => 'Events',
                'icon' => 'calendar',
                'url' => '/admin/events.php',
                'pages' => ['events.php', 'event-create.php', 'event-edit.php', 'event-delete.php']
            ],
            [
                'id' => 'results',
                'label' => 'Resultat',
                'icon' => 'trophy',
                'url' => '/admin/results.php',
                'pages' => ['results.php', 'edit-results.php', 'recalculate-results.php', 'clear-event-results.php', 'reset-results.php']
            ],
            [
                'id' => 'venues',
                'label' => 'Venues',
                'icon' => 'mountain',
                'url' => '/admin/venues.php',
                'pages' => ['venues.php']
            ],
            [
                'id' => 'ticketing',
                'label' => 'Biljetter',
                'icon' => 'ticket',
                'url' => '/admin/ticketing.php',
                'pages' => ['ticketing.php', 'event-pricing.php', 'event-tickets.php', 'event-ticketing.php', 'refund-requests.php', 'pricing-templates.php']
            ],
            [
                'id' => 'payments',
                'label' => 'Betalningar',
                'icon' => 'credit-card',
                'url' => '/admin/orders.php',
                'pages' => ['orders.php', 'payment-settings.php']
            ]
        ]
    ],

    // ========================================
    // SERIER
    // ========================================
    'standings' => [
        'title' => 'Serier',
        'icon' => 'medal',
        'tabs' => [
            [
                'id' => 'series',
                'label' => 'Serier',
                'icon' => 'award',
                'url' => '/admin/series.php',
                'pages' => ['series.php', 'series-events.php', 'series-pricing.php']
            ],
            [
                'id' => 'ranking',
                'label' => 'Ranking',
                'icon' => 'trending-up',
                'url' => '/admin/ranking.php',
                'pages' => ['ranking.php', 'ranking-debug.php', 'ranking-minimal.php', 'setup-ranking-system.php']
            ],
            [
                'id' => 'club-points',
                'label' => 'Klubbpoäng',
                'icon' => 'building-2',
                'url' => '/admin/club-points.php',
                'pages' => ['club-points.php', 'club-points-detail.php']
            ]
        ]
    ],

    // ========================================
    // DELTAGARE (Riders only - has own sidebar entry)
    // ========================================
    'riders' => [
        'title' => 'Deltagare',
        'icon' => 'users',
        'single_page' => true, // Don't show tabs for single-page sections
        'tabs' => [
            [
                'id' => 'riders',
                'label' => 'Deltagare',
                'icon' => 'user',
                'url' => '/admin/riders.php',
                'pages' => ['riders.php', 'rider-edit.php', 'rider-delete.php', 'find-duplicates.php', 'cleanup-duplicates.php']
            ]
        ]
    ],

    // ========================================
    // KLUBBAR (Clubs only - has own sidebar entry)
    // ========================================
    'clubs' => [
        'title' => 'Klubbar',
        'icon' => 'building-2',
        'single_page' => true, // Don't show tabs for single-page sections
        'tabs' => [
            [
                'id' => 'clubs',
                'label' => 'Klubbar',
                'icon' => 'building-2',
                'url' => '/admin/clubs.php',
                'pages' => ['clubs.php', 'club-edit.php', 'cleanup-clubs.php']
            ]
        ]
    ],

    // ========================================
    // KONFIGURATION
    // ========================================
    'config' => [
        'title' => 'Konfiguration',
        'icon' => 'sliders',
        'tabs' => [
            [
                'id' => 'classes',
                'label' => 'Klasser',
                'icon' => 'layers',
                'url' => '/admin/classes.php',
                'pages' => ['classes.php', 'reassign-classes.php', 'reset-classes.php', 'move-class-results.php']
            ],
            [
                'id' => 'license-matrix',
                'label' => 'Licenser',
                'icon' => 'grid-3x3',
                'url' => '/admin/license-class-matrix.php',
                'pages' => ['license-class-matrix.php']
            ],
            [
                'id' => 'point-scales',
                'label' => 'Poängskalor',
                'icon' => 'calculator',
                'url' => '/admin/point-scales.php',
                'pages' => ['point-scales.php', 'point-scale-edit.php', 'point-templates.php']
            ],
            [
                'id' => 'rules',
                'label' => 'Regler',
                'icon' => 'shield-check',
                'url' => '/admin/registration-rules.php',
                'pages' => ['registration-rules.php']
            ],
            [
                'id' => 'public',
                'label' => 'Publikt',
                'icon' => 'globe',
                'url' => '/admin/public-settings.php',
                'pages' => ['public-settings.php']
            ],
            [
                'id' => 'global-texts',
                'label' => 'Texter',
                'icon' => 'file-text',
                'url' => '/admin/global-texts.php',
                'pages' => ['global-texts.php']
            ]
        ]
    ],

    // ========================================
    // IMPORT
    // ========================================
    'import' => [
        'title' => 'Import',
        'icon' => 'upload',
        'tabs' => [
            [
                'id' => 'overview',
                'label' => 'Översikt',
                'icon' => 'layout-grid',
                'url' => '/admin/import.php',
                'pages' => ['import.php']
            ],
            [
                'id' => 'riders',
                'label' => 'Deltagare',
                'icon' => 'user-plus',
                'url' => '/admin/import-riders.php',
                'pages' => ['import-riders.php', 'import-riders-flexible.php', 'import-riders-extended.php', 'import-gravity-id.php']
            ],
            [
                'id' => 'results',
                'label' => 'Resultat',
                'icon' => 'file-spreadsheet',
                'url' => '/admin/import-results.php',
                'pages' => ['import-results.php', 'import-results-preview.php']
            ],
            [
                'id' => 'events',
                'label' => 'Events',
                'icon' => 'calendar-plus',
                'url' => '/admin/import-events.php',
                'pages' => ['import-events.php', 'import-series.php', 'import-classes.php', 'import-clubs.php']
            ],
            [
                'id' => 'uci',
                'label' => 'UCI',
                'icon' => 'globe',
                'url' => '/admin/import-uci-simple.php',
                'pages' => ['import-uci-preview.php', 'import-uci-simple.php']
            ],
            [
                'id' => 'history',
                'label' => 'Historik',
                'icon' => 'history',
                'url' => '/admin/import-history.php',
                'pages' => ['import-history.php']
            ]
        ]
    ],

    // ========================================
    // SYSTEM (Super Admin)
    // ========================================
    'settings' => [
        'title' => 'System',
        'icon' => 'settings',
        'super_admin_only' => true,
        'tabs' => [
            [
                'id' => 'users',
                'label' => 'Användare',
                'icon' => 'users',
                'url' => '/admin/users.php',
                'pages' => ['users.php', 'user-edit.php', 'user-events.php', 'user-rider.php']
            ],
            [
                'id' => 'permissions',
                'label' => 'Behörigheter',
                'icon' => 'shield',
                'url' => '/admin/role-permissions.php',
                'pages' => ['role-permissions.php']
            ],
            [
                'id' => 'system',
                'label' => 'Databas',
                'icon' => 'server',
                'url' => '/admin/system-settings.php',
                'pages' => ['system-settings.php', 'settings.php', 'setup-database.php', 'run-migrations.php']
            ],
            [
                'id' => 'debug',
                'label' => 'Debug',
                'icon' => 'bug',
                'url' => '/admin/debug.php',
                'pages' => ['debug.php']
            ]
        ]
    ]
];

/**
 * Hämta aktiv flik baserat på nuvarande sida
 *
 * @param string $group Grupp-ID
 * @param string $current_page Nuvarande sidnamn
 * @return string|null Flik-ID eller null
 */
function get_active_tab($group, $current_page) {
    global $ADMIN_TABS;

    if (!isset($ADMIN_TABS[$group])) {
        return null;
    }

    foreach ($ADMIN_TABS[$group]['tabs'] as $tab) {
        if (in_array($current_page, $tab['pages'])) {
            return $tab['id'];
        }
    }

    // Default till första fliken
    return $ADMIN_TABS[$group]['tabs'][0]['id'];
}

/**
 * Hämta grupp för en sida
 *
 * @param string $current_page Nuvarande sidnamn
 * @return string|null Grupp-ID eller null
 */
function get_group_for_page($current_page) {
    global $ADMIN_TABS;

    foreach ($ADMIN_TABS as $group_id => $group) {
        foreach ($group['tabs'] as $tab) {
            if (in_array($current_page, $tab['pages'])) {
                return $group_id;
            }
        }
    }

    return null;
}

/**
 * Hämta alla sidor i en grupp
 *
 * @param string $group Grupp-ID
 * @return array Lista med sidnamn
 */
function get_pages_in_group($group) {
    global $ADMIN_TABS;

    if (!isset($ADMIN_TABS[$group])) {
        return [];
    }

    $pages = [];
    foreach ($ADMIN_TABS[$group]['tabs'] as $tab) {
        $pages = array_merge($pages, $tab['pages']);
    }

    return $pages;
}
?>

<?php
/**
 * TheHUB V2 Router (Modular SPA Architecture)
 * Based on V3's router - handles URL routing for clean URLs and AJAX content loading
 */

// Define root path
if (!defined('HUB_ROOT')) {
    define('HUB_ROOT', __DIR__);
}

/**
 * Get current page info from URL
 * @return array Page info with 'page', 'section', 'params', 'file' keys
 */
function hub_get_current_page(): array {
    $raw = trim($_GET['page'] ?? '', '/');

    // Home/Dashboard
    if ($raw === '' || $raw === 'index.php' || $raw === 'home') {
        return [
            'page' => 'home',
            'section' => 'home',
            'params' => [],
            'file' => HUB_ROOT . '/pages/home.php',
            'title' => 'Hem'
        ];
    }

    $segments = explode('/', $raw);
    $section = $segments[0];
    $subpage = $segments[1] ?? null;
    $id = null;

    // Section routes - maps URL segments to page files
    // Uses /pages/ for new modules, root level for legacy pages
    $sectionRoutes = [
        'calendar' => [
            'index' => '/events.php',           // Legacy: root level
            'event' => '/event.php',            // Legacy: root level
            'title' => 'Kalender'
        ],
        'events' => [
            'index' => '/events.php',           // Legacy: root level
            'title' => 'Kalender'
        ],
        'results' => [
            'index' => '/results.php',          // Legacy: root level
            'event' => '/event-results.php',    // Legacy: root level
            'title' => 'Resultat'
        ],
        'series' => [
            'index' => '/series.php',           // Legacy: root level
            'show' => '/series-standings.php',  // Legacy: root level
            'title' => 'Serier'
        ],
        'database' => [
            'index' => '/riders.php',           // Legacy: root level
            'rider' => '/rider.php',            // Legacy: root level
            'club' => '/club.php',              // Legacy: root level
            'title' => 'Databas'
        ],
        'riders' => [
            'index' => '/riders.php',           // Legacy: root level
            'title' => 'Deltagare'
        ],
        'clubs' => [
            'index' => '/clubs/leaderboard.php', // Legacy: in clubs folder
            'leaderboard' => '/clubs/leaderboard.php',
            'detail' => '/club.php',            // Legacy: root level
            'title' => 'Klubbar'
        ],
        'ranking' => [
            'index' => '/ranking/index.php',    // Legacy: in ranking folder
            'rider' => '/ranking/rider.php',
            'title' => 'Ranking'
        ],
        'profile' => [
            'index' => '/profile.php',          // Legacy: root level
            'login' => '/rider-login.php',      // Legacy: root level
            'register' => '/rider-register.php', // Legacy: root level
            'title' => 'Min Profil'
        ]
    ];

    // Check if this is a section route
    if (isset($sectionRoutes[$section])) {
        $routeInfo = $sectionRoutes[$section];
        $title = $routeInfo['title'] ?? ucfirst($section);

        // If second segment is numeric, it's an ID
        if (isset($segments[1]) && is_numeric($segments[1])) {
            $id = (int)$segments[1];
            // Determine detail page based on section
            $detailPages = [
                'calendar' => 'event',
                'events' => 'event',
                'results' => 'event',
                'series' => 'show',
                'database' => 'rider',
                'riders' => 'rider',
                'clubs' => 'detail',
                'ranking' => 'rider'
            ];
            $subpage = $detailPages[$section] ?? 'index';
        } elseif (isset($segments[1]) && !is_numeric($segments[1])) {
            $subpage = $segments[1];
            $id = isset($segments[2]) && is_numeric($segments[2]) ? (int)$segments[2] : null;
        } else {
            $subpage = 'index';
        }

        $file = HUB_ROOT . ($routeInfo[$subpage] ?? $routeInfo['index']);

        return [
            'page' => $section . ($subpage !== 'index' ? '-' . $subpage : ''),
            'section' => $section,
            'subpage' => $subpage,
            'params' => $id !== null ? ['id' => $id] : [],
            'file' => $file,
            'title' => $title
        ];
    }

    // Legacy single-item pages (rider/123, event/456, club/789)
    $singlePages = [
        'rider' => ['section' => 'database', 'file' => '/rider.php', 'title' => 'Cyklist'],
        'event' => ['section' => 'results', 'file' => '/event-results.php', 'title' => 'Event'],
        'club' => ['section' => 'clubs', 'file' => '/club.php', 'title' => 'Klubb'],
        'event-results' => ['section' => 'results', 'file' => '/event-results.php', 'title' => 'Resultat'],
        'series-standings' => ['section' => 'series', 'file' => '/series-standings.php', 'title' => 'Serieställning']
    ];

    if (isset($singlePages[$section])) {
        $info = $singlePages[$section];
        $id = isset($segments[1]) && is_numeric($segments[1]) ? (int)$segments[1] : null;

        return [
            'page' => $section,
            'section' => $info['section'],
            'params' => $id !== null ? ['id' => $id] : [],
            'file' => HUB_ROOT . $info['file'],
            'title' => $info['title']
        ];
    }

    // Admin routes - redirect to admin folder
    if ($section === 'admin') {
        header('Location: /admin/' . ($subpage ?? 'dashboard.php'));
        exit;
    }

    // 404 - page not found
    return [
        'page' => '404',
        'section' => null,
        'params' => ['requested' => $raw],
        'file' => HUB_ROOT . '/pages/404.php',
        'title' => 'Sidan hittades inte'
    ];
}

/**
 * Check if current request is AJAX
 * @return bool
 */
function hub_is_ajax(): bool {
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

/**
 * Check if navigation item is active
 * @param string $navId Navigation ID to check
 * @param string $currentSection Current section from page info
 * @return bool
 */
function hub_is_nav_active(string $navId, string $currentSection): bool {
    if ($navId === $currentSection) return true;

    // Alias mappings
    $aliases = [
        'calendar' => ['events'],
        'results' => ['event-results'],
        'series' => ['series-standings'],
        'database' => ['riders', 'rider', 'clubs', 'club']
    ];

    if (isset($aliases[$navId]) && in_array($currentSection, $aliases[$navId])) {
        return true;
    }

    return false;
}

/**
 * Generate URL for a page
 * @param string $page Page/section name
 * @param array $params URL parameters
 * @return string Clean URL
 */
function hub_url(string $page, array $params = []): string {
    $url = '/' . ltrim($page, '/');

    if (!empty($params['id'])) {
        $url .= '/' . $params['id'];
        unset($params['id']);
    }

    if (!empty($params)) {
        $url .= '?' . http_build_query($params);
    }

    return $url;
}

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
    $sectionRoutes = [
        'calendar' => [
            'index' => '/pages/calendar/index.php',
            'event' => '/pages/calendar/event.php',
            'title' => 'Kalender'
        ],
        'events' => [
            'index' => '/pages/events.php',
            'title' => 'Kalender'
        ],
        'results' => [
            'index' => '/pages/results.php',
            'event' => '/pages/event-results.php',
            'title' => 'Resultat'
        ],
        'series' => [
            'index' => '/pages/series.php',
            'show' => '/pages/series-standings.php',
            'title' => 'Serier'
        ],
        'database' => [
            'index' => '/pages/database/index.php',
            'rider' => '/pages/rider.php',
            'club' => '/pages/club.php',
            'title' => 'Databas'
        ],
        'riders' => [
            'index' => '/pages/riders.php',
            'title' => 'Deltagare'
        ],
        'clubs' => [
            'index' => '/pages/clubs/index.php',
            'leaderboard' => '/pages/clubs/leaderboard.php',
            'detail' => '/pages/clubs/detail.php',
            'title' => 'Klubbar'
        ],
        'ranking' => [
            'index' => '/pages/ranking/index.php',
            'rider' => '/pages/ranking/rider.php',
            'title' => 'Ranking'
        ],
        'profile' => [
            'index' => '/pages/profile.php',
            'login' => '/pages/rider-login.php',
            'register' => '/pages/rider-register.php',
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
        'rider' => ['section' => 'database', 'file' => '/pages/rider.php', 'title' => 'Cyklist'],
        'event' => ['section' => 'results', 'file' => '/pages/event.php', 'title' => 'Event'],
        'club' => ['section' => 'clubs', 'file' => '/pages/club.php', 'title' => 'Klubb'],
        'event-results' => ['section' => 'results', 'file' => '/pages/event-results.php', 'title' => 'Resultat'],
        'series-standings' => ['section' => 'series', 'file' => '/pages/series-standings.php', 'title' => 'Serieställning']
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

<?php
/**
 * TheHUB V3.5 Router
 * Handles URL routing for the SPA structure
 *
 * AUTHENTICATION: All pages require login except welcome and login
 */

// Ensure v3-config is loaded
$v3ConfigPath = __DIR__ . '/v3-config.php';
if (file_exists($v3ConfigPath)) {
    require_once $v3ConfigPath;
}

// Fallback for hub_is_logged_in if not defined
if (!function_exists('hub_is_logged_in')) {
    function hub_is_logged_in() {
        return isset($_SESSION['rider_id']) && $_SESSION['rider_id'] > 0;
    }
}

// Ensure HUB_V3_ROOT is defined
if (!defined('HUB_V3_ROOT')) {
    define('HUB_V3_ROOT', __DIR__);
}

/**
 * Check if the current page requires authentication
 * Returns true if user should be redirected to login
 */
function hub_requires_auth(string $page): bool {
    // Public pages that don't require authentication
    $publicPages = [
        // Auth pages
        '', 'welcome', 'login', 'logout', 'forgot-password', 'reset-password', 'index.php',
        // Public content sections (viewable without login)
        'calendar', 'results', 'series', 'database', 'ranking',
        // Legacy public pages
        'rider', 'club', 'event', 'riders', 'clubs'
    ];
    return !in_array($page, $publicPages);
}

function hub_get_current_page(): array {
    $raw = trim($_GET['page'] ?? '', '/');
    $section = explode('/', $raw)[0] ?? '';

    // =========================================================================
    // AUTHENTICATION CHECK - Require login for all pages except public ones
    // =========================================================================
    if (hub_requires_auth($section) && !hub_is_logged_in()) {
        // Store the requested URL for redirect after login
        $redirect = '/' . $raw;
        header('Location: /login?redirect=' . urlencode($redirect));
        exit;
    }

    // Root route - show welcome for unauthenticated users
    if ($raw === '' || $raw === 'index.php') {
        if (!hub_is_logged_in()) {
            // Show welcome page for visitors
            return ['page' => 'welcome', 'section' => null, 'params' => [], 'file' => HUB_V3_ROOT . '/pages/welcome.php'];
        }
        // Logged in users go to dashboard
        return ['page' => 'dashboard', 'section' => 'dashboard', 'params' => [], 'file' => HUB_V3_ROOT . '/pages/dashboard.php'];
    }

    $segments = explode('/', $raw);
    $section = $segments[0];
    $subpage = $segments[1] ?? 'index';
    $id = $segments[2] ?? ($segments[1] ?? null);

    // Simple pages (login, logout, password reset, etc.)
    $simplePages = [
        'login' => '/pages/login.php',
        'logout' => '/pages/logout.php',
        'forgot-password' => '/pages/forgot-password.php',
        'reset-password' => '/pages/reset-password.php',
    ];

    if (isset($simplePages[$section])) {
        return [
            'page' => $section,
            'section' => $section,
            'params' => [],
            'file' => HUB_V3_ROOT . $simplePages[$section]
        ];
    }

    // New V3.5 section-based routing
    $sectionRoutes = [
        'calendar' => [
            'index' => '/pages/calendar/index.php',
            'event' => '/pages/calendar/event.php'
        ],
        'results' => [
            'index' => '/pages/results.php',  // Legacy - will move to /pages/results/index.php
            'event' => '/pages/event.php'     // Legacy
        ],
        'series' => [
            'index' => '/pages/series/index.php',
            'show' => '/pages/series/show.php'
        ],
        'database' => [
            'index' => '/pages/database/index.php',
            'rider' => '/pages/rider.php',    // Legacy - will move
            'club' => '/pages/club.php'       // Legacy
        ],
        'ranking' => [
            'index' => '/pages/ranking.php',  // Legacy
            'riders' => '/pages/ranking.php',
            'clubs' => '/pages/ranking.php',
            'events' => '/pages/ranking.php'
        ],
        'profile' => [
            'index' => '/pages/profile/index.php',
            'edit' => '/pages/profile/edit.php',
            'children' => '/pages/profile/children.php',
            'club-admin' => '/pages/profile/club-admin.php',
            'registrations' => '/pages/profile/registrations.php',
            'results' => '/pages/profile/results.php',
            'receipts' => '/pages/profile/receipts.php',
            'login' => '/pages/profile/login.php'
        ]
    ];

    // Check if this is a V3.5 section route
    if (isset($sectionRoutes[$section])) {
        // If second segment is numeric, it's an ID
        if (isset($segments[1]) && is_numeric($segments[1])) {
            $id = $segments[1];
            // Determine detail page based on section
            $detailPages = [
                'calendar' => 'event',
                'results' => 'event',
                'series' => 'show',
                'database' => 'rider',
                'ranking' => 'riders'
            ];
            $subpage = $detailPages[$section] ?? 'index';
        } elseif (isset($segments[1]) && !is_numeric($segments[1])) {
            $subpage = $segments[1];
            $id = $segments[2] ?? null;
        } else {
            $subpage = 'index';
        }

        $file = HUB_V3_ROOT . ($sectionRoutes[$section][$subpage] ?? $sectionRoutes[$section]['index']);

        return [
            'page' => $section . '-' . $subpage,
            'section' => $section,
            'subpage' => $subpage,
            'params' => $id ? ['id' => $id] : [],
            'file' => $file
        ];
    }

    // Legacy single-item pages (rider/123, event/456, club/789)
    $singlePages = ['rider', 'event', 'club'];
    if (in_array($section, $singlePages) && isset($segments[1])) {
        return [
            'page' => $section,
            'section' => $section === 'rider' || $section === 'club' ? 'database' : 'results',
            'params' => ['id' => $segments[1]],
            'file' => HUB_V3_ROOT . '/pages/' . $section . '.php'
        ];
    }

    // Legacy list pages
    $legacyPages = [
        'riders' => ['section' => 'database', 'file' => '/pages/riders.php'],
        'clubs' => ['section' => 'database', 'file' => '/pages/clubs.php'],
        'results' => ['section' => 'results', 'file' => '/pages/results.php'],
        'ranking' => ['section' => 'ranking', 'file' => '/pages/ranking.php']
    ];

    if (isset($legacyPages[$section])) {
        return [
            'page' => $section,
            'section' => $legacyPages[$section]['section'],
            'params' => [],
            'file' => HUB_V3_ROOT . $legacyPages[$section]['file']
        ];
    }

    // Dashboard
    if ($section === 'dashboard') {
        return ['page' => 'dashboard', 'section' => 'dashboard', 'params' => [], 'file' => HUB_V3_ROOT . '/pages/dashboard.php'];
    }

    // 404
    return ['page' => '404', 'section' => null, 'params' => ['requested' => $raw], 'file' => HUB_V3_ROOT . '/pages/404.php'];
}

function hub_is_ajax(): bool {
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

if (!function_exists('hub_is_nav_active')) {
    function hub_is_nav_active(string $navId, string $currentPage): bool {
        // Get section from page info
        global $pageInfo;
        $section = $pageInfo['section'] ?? null;

        if ($section === $navId) return true;

        // Legacy mappings
        if ($navId === 'calendar' && in_array($currentPage, ['calendar', 'calendar-event', 'calendar-index'])) return true;
        if ($navId === 'results' && in_array($currentPage, ['results', 'event', 'results-event'])) return true;
        if ($navId === 'series' && in_array($currentPage, ['series', 'series-index', 'series-show'])) return true;
        if ($navId === 'database' && in_array($currentPage, ['database', 'riders', 'rider', 'clubs', 'club', 'database-rider', 'database-club'])) return true;
        if ($navId === 'ranking' && str_starts_with($currentPage, 'ranking')) return true;
        if ($navId === 'profile' && str_starts_with($currentPage, 'profile')) return true;

        return false;
    }
}

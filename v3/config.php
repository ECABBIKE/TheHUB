<?php
/**
 * TheHUB V3 Configuration
 *
 * Parallel evaluation structure using the same database as production
 */

// Enable error reporting for debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', '1');

// ============================================================================
// DATABASE CONNECTION (uses parent config)
// ============================================================================
// Include the main config to get database connection
$parentConfig = dirname(__DIR__) . '/config.php';
if (file_exists($parentConfig)) {
    require_once $parentConfig;
} else {
    die('Error: Parent config.php not found at: ' . $parentConfig);
}

// Verify database connection exists
if (!isset($GLOBALS['pdo'])) {
    die('Error: Database connection not established');
}

// ============================================================================
// V3.5 VERSION INFO
// ============================================================================
if (!defined('HUB_VERSION')) define('HUB_VERSION', '3.5.0');
if (!defined('CSS_VERSION')) define('CSS_VERSION', '3.5.0');
if (!defined('JS_VERSION')) define('JS_VERSION', '3.5.0');

if (!defined('HUB_V3_ROOT')) define('HUB_V3_ROOT', __DIR__);
if (!defined('HUB_V3_URL')) define('HUB_V3_URL', '/v3');
if (!defined('HUB_V2_ROOT')) define('HUB_V2_ROOT', dirname(__DIR__));

// WooCommerce integration
if (!defined('WC_CHECKOUT_URL')) define('WC_CHECKOUT_URL', '/checkout');

// ============================================================================
// V3.5 NAVIGATION (5 main sections)
// ============================================================================
if (!defined('HUB_NAV')) {
    define('HUB_NAV', [
        ['id' => 'calendar', 'label' => 'Kalender', 'icon' => 'calendar', 'url' => '/v3/calendar', 'aria' => 'Visa eventkalender'],
        ['id' => 'results', 'label' => 'Resultat', 'icon' => 'flag', 'url' => '/v3/results', 'aria' => 'Visa tävlingsresultat'],
        ['id' => 'database', 'label' => 'Databas', 'icon' => 'search', 'url' => '/v3/database', 'aria' => 'Sök åkare och klubbar'],
        ['id' => 'ranking', 'label' => 'Ranking', 'icon' => 'trending-up', 'url' => '/v3/ranking', 'aria' => 'Visa ranking'],
        ['id' => 'profile', 'label' => 'Mitt', 'icon' => 'user', 'url' => '/v3/profile', 'aria' => 'Min profil']
    ]);
}

// Valid pages/routes
if (!defined('HUB_VALID_PAGES')) {
    define('HUB_VALID_PAGES', [
        'dashboard',
        // Calendar
        'calendar', 'calendar-event',
        // Results
        'results', 'results-event', 'results-series',
        // Database
        'database', 'database-rider', 'database-club',
        // Ranking
        'ranking', 'ranking-riders', 'ranking-clubs', 'ranking-events',
        // Profile
        'profile', 'profile-edit', 'profile-children', 'profile-club-admin',
        'profile-registrations', 'profile-results', 'profile-receipts', 'profile-login',
        // Legacy support
        'series', 'series-single', 'event', 'riders', 'rider', 'clubs', 'club',
        '404'
    ]);
}

// ============================================================================
// HELPER FUNCTIONS
// ============================================================================
if (!function_exists('hub_get_theme')) {
    function hub_get_theme(): string {
        $theme = $_COOKIE['hub_theme'] ?? 'auto';
        return in_array($theme, ['light', 'dark', 'auto']) ? $theme : 'auto';
    }
}

if (!function_exists('hub_asset')) {
    function hub_asset(string $path): string {
        $version = (strpos($path, '.css') !== false) ? CSS_VERSION : JS_VERSION;
        return HUB_V3_URL . '/assets/' . $path . '?v=' . $version;
    }
}

if (!function_exists('hub_db')) {
    /**
     * Get database connection (PDO)
     * @return PDO
     */
    function hub_db(): PDO {
        return $GLOBALS['pdo'];
    }
}

// ============================================================================
// AUTHENTICATION FUNCTIONS
// ============================================================================

if (!function_exists('hub_is_logged_in')) {
    /**
     * Check if user is logged in (via WooCommerce/WordPress or session)
     */
    function hub_is_logged_in(): bool {
        if (function_exists('is_user_logged_in')) {
            return is_user_logged_in();
        }
        return isset($_SESSION['hub_user_id']) && $_SESSION['hub_user_id'] > 0;
    }
}

if (!function_exists('hub_current_user')) {
    /**
     * Get current logged in user's rider profile
     */
    function hub_current_user(): ?array {
        if (!hub_is_logged_in()) return null;

        if (function_exists('wp_get_current_user')) {
            $wp_user = wp_get_current_user();
            return hub_get_rider_by_email($wp_user->user_email);
        }

        return isset($_SESSION['hub_user_id'])
            ? hub_get_rider_by_id($_SESSION['hub_user_id'])
            : null;
    }
}

if (!function_exists('hub_get_rider_by_id')) {
    function hub_get_rider_by_id(int $id): ?array {
        $stmt = hub_db()->prepare("SELECT * FROM riders WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

if (!function_exists('hub_get_rider_by_email')) {
    function hub_get_rider_by_email(string $email): ?array {
        $stmt = hub_db()->prepare("SELECT * FROM riders WHERE email = ?");
        $stmt->execute([$email]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

// ============================================================================
// PARENT/CHILD & PERMISSIONS
// ============================================================================

if (!function_exists('hub_is_parent_of')) {
    function hub_is_parent_of(int $parentId, int $childId): bool {
        $stmt = hub_db()->prepare("SELECT 1 FROM rider_parents WHERE parent_rider_id = ? AND child_rider_id = ?");
        $stmt->execute([$parentId, $childId]);
        return (bool) $stmt->fetch();
    }
}

if (!function_exists('hub_get_linked_children')) {
    function hub_get_linked_children(int $parentId): array {
        $stmt = hub_db()->prepare("
            SELECT r.* FROM riders r
            JOIN rider_parents rp ON r.id = rp.child_rider_id
            WHERE rp.parent_rider_id = ?
        ");
        $stmt->execute([$parentId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('hub_can_edit_profile')) {
    function hub_can_edit_profile(int $profileId): bool {
        $user = hub_current_user();
        if (!$user) return false;
        if ($user['id'] === $profileId) return true;
        if (hub_is_parent_of($user['id'], $profileId)) return true;
        return false;
    }
}

if (!function_exists('hub_can_edit_club')) {
    function hub_can_edit_club(int $clubId): bool {
        $user = hub_current_user();
        if (!$user) return false;

        $stmt = hub_db()->prepare("SELECT 1 FROM club_admins WHERE rider_id = ? AND club_id = ?");
        $stmt->execute([$user['id'], $clubId]);
        return (bool) $stmt->fetch();
    }
}

if (!function_exists('hub_get_admin_clubs')) {
    function hub_get_admin_clubs(int $riderId): array {
        $stmt = hub_db()->prepare("
            SELECT c.* FROM clubs c
            JOIN club_admins ca ON c.id = ca.club_id
            WHERE ca.rider_id = ?
        ");
        $stmt->execute([$riderId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// ============================================================================
// DATE FORMATTING (strftime is deprecated in PHP 8.1+)
// ============================================================================

if (!function_exists('hub_month_short')) {
    /**
     * Get short Swedish month name (jan, feb, mar, etc.)
     */
    function hub_month_short($date): string {
        $months = ['jan', 'feb', 'mar', 'apr', 'maj', 'jun', 'jul', 'aug', 'sep', 'okt', 'nov', 'dec'];
        $timestamp = is_numeric($date) ? $date : strtotime($date);
        return $months[(int)date('n', $timestamp) - 1];
    }
}

if (!function_exists('hub_month_full')) {
    /**
     * Get full Swedish month name (januari, februari, etc.)
     */
    function hub_month_full($date): string {
        $months = ['januari', 'februari', 'mars', 'april', 'maj', 'juni', 'juli', 'augusti', 'september', 'oktober', 'november', 'december'];
        $timestamp = is_numeric($date) ? $date : strtotime($date);
        return $months[(int)date('n', $timestamp) - 1];
    }
}

if (!function_exists('hub_day_short')) {
    /**
     * Get short Swedish day name (mån, tis, ons, etc.)
     */
    function hub_day_short($date): string {
        $days = ['sön', 'mån', 'tis', 'ons', 'tor', 'fre', 'lör'];
        $timestamp = is_numeric($date) ? $date : strtotime($date);
        return $days[(int)date('w', $timestamp)];
    }
}

if (!function_exists('hub_format_month_year')) {
    /**
     * Format date as "Januari 2024"
     */
    function hub_format_month_year($date): string {
        $timestamp = is_numeric($date) ? $date : strtotime($date);
        return ucfirst(hub_month_full($timestamp)) . ' ' . date('Y', $timestamp);
    }
}

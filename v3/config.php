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
// ROLE CONSTANTS (must be defined before functions that use them)
// ============================================================================
if (!defined('ROLE_RIDER')) {
    define('ROLE_RIDER', 1);
    define('ROLE_PROMOTOR', 2);
    define('ROLE_ADMIN', 3);
    define('ROLE_SUPER_ADMIN', 4);

    define('ROLE_NAMES', [
        ROLE_RIDER => 'Rider',
        ROLE_PROMOTOR => 'Promotor',
        ROLE_ADMIN => 'Admin',
        ROLE_SUPER_ADMIN => 'Super Admin'
    ]);
}

// ============================================================================
// V3.5 NAVIGATION (6 main sections)
// ============================================================================
if (!defined('HUB_NAV')) {
    define('HUB_NAV', [
        ['id' => 'calendar', 'label' => 'Kalender', 'icon' => 'calendar', 'url' => '/v3/calendar', 'aria' => 'Visa eventkalender'],
        ['id' => 'results', 'label' => 'Resultat', 'icon' => 'flag', 'url' => '/v3/results', 'aria' => 'Visa tävlingsresultat'],
        ['id' => 'series', 'label' => 'Serier', 'icon' => 'trophy', 'url' => '/v3/series', 'aria' => 'Visa tävlingsserier'],
        ['id' => 'database', 'label' => 'Databas', 'icon' => 'search', 'url' => '/v3/database', 'aria' => 'Sök åkare och klubbar'],
        ['id' => 'ranking', 'label' => 'Ranking', 'icon' => 'trending-up', 'url' => '/v3/ranking', 'aria' => 'Visa ranking'],
        // 'Mitt' borttagen - nu i header istället
    ]);
}

// Valid pages/routes
if (!defined('HUB_VALID_PAGES')) {
    define('HUB_VALID_PAGES', [
        'dashboard',
        // Calendar
        'calendar', 'calendar-event',
        // Results
        'results', 'results-event',
        // Series
        'series', 'series-index', 'series-show',
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
        // Check V3 session
        if (isset($_SESSION['hub_user_id']) && $_SESSION['hub_user_id'] > 0) {
            return true;
        }
        // Check V2 rider session (backwards compatibility)
        if (isset($_SESSION['rider_id']) && $_SESSION['rider_id'] > 0) {
            return true;
        }
        return false;
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

        // Check V3 session first
        if (isset($_SESSION['hub_user_id'])) {
            return hub_get_rider_by_id($_SESSION['hub_user_id']);
        }

        // Check V2 rider session
        if (isset($_SESSION['rider_id'])) {
            return hub_get_rider_by_id($_SESSION['rider_id']);
        }

        return null;
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

if (!function_exists('hub_is_admin')) {
    /**
     * Check if current user is an admin (role level 3+)
     */
    function hub_is_admin(?int $userId = null): bool {
        // Check session role first
        if ($userId === null && isset($_SESSION['hub_user_role'])) {
            return $_SESSION['hub_user_role'] >= ROLE_ADMIN;
        }

        // Check database role
        if ($userId !== null || isset($_SESSION['hub_user_id'])) {
            $checkId = $userId ?? $_SESSION['hub_user_id'];
            return hub_has_role(ROLE_ADMIN, $checkId);
        }

        $user = hub_current_user();
        if (!$user) return false;

        // Check role_id in riders table
        if (isset($user['role_id']) && $user['role_id'] >= ROLE_ADMIN) {
            return true;
        }

        // Fallback: check is_admin flag in riders table (legacy)
        if (isset($user['is_admin']) && $user['is_admin']) {
            return true;
        }

        // Check WordPress role if available
        if (function_exists('current_user_can')) {
            return current_user_can('manage_options') || current_user_can('edit_others_posts');
        }

        return false;
    }
}

if (!function_exists('hub_attempt_login')) {
    /**
     * Attempt to log in a user with email/password
     * Works with the riders table using password_hash
     */
    function hub_attempt_login(string $email, string $password): array {
        $pdo = hub_db();

        // Find rider by email
        $stmt = $pdo->prepare("
            SELECT id, email, password, firstname, lastname, is_admin, role_id
            FROM riders
            WHERE email = ? AND active = 1
            LIMIT 1
        ");
        $stmt->execute([$email]);
        $rider = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$rider) {
            return ['success' => false, 'error' => 'Ogiltig e-post eller lösenord.'];
        }

        // Check if rider has a password set
        if (empty($rider['password'])) {
            return ['success' => false, 'error' => 'Du har inte satt ett lösenord ännu. Klicka på "Glömt lösenord" för att skapa ett.'];
        }

        // Verify password (bcrypt)
        if (!password_verify($password, $rider['password'])) {
            return ['success' => false, 'error' => 'Ogiltig e-post eller lösenord.'];
        }

        // Login successful - create session
        hub_set_user_session($rider);

        // Update last login
        $stmt = $pdo->prepare("UPDATE riders SET last_login = NOW() WHERE id = ?");
        $stmt->execute([$rider['id']]);

        return ['success' => true, 'user' => $rider];
    }
}

if (!function_exists('hub_set_user_session')) {
    /**
     * Set user session after successful login
     */
    function hub_set_user_session(array $user): void {
        $_SESSION['hub_user_id'] = $user['id'];
        $_SESSION['hub_user_email'] = $user['email'];
        $_SESSION['hub_user_name'] = ($user['firstname'] ?? '') . ' ' . ($user['lastname'] ?? '');
        $_SESSION['hub_user_role'] = (int) ($user['role_id'] ?? ROLE_RIDER);
        $_SESSION['hub_logged_in_at'] = time();

        // Backwards compatibility - is_admin based on role
        $_SESSION['hub_is_admin'] = $_SESSION['hub_user_role'] >= ROLE_ADMIN;

        // Also set rider_* for backwards compatibility with V2 code
        $_SESSION['rider_id'] = $user['id'];
        $_SESSION['rider_email'] = $user['email'];
        $_SESSION['rider_name'] = $_SESSION['hub_user_name'];
    }
}

if (!function_exists('hub_logout')) {
    /**
     * Log out the current user
     */
    function hub_logout(): void {
        unset($_SESSION['hub_user_id']);
        unset($_SESSION['hub_user_email']);
        unset($_SESSION['hub_user_name']);
        unset($_SESSION['hub_user_role']);
        unset($_SESSION['hub_is_admin']);
        unset($_SESSION['hub_logged_in_at']);

        // Also clear V2 rider session
        unset($_SESSION['rider_id']);
        unset($_SESSION['rider_email']);
        unset($_SESSION['rider_name']);
    }
}

if (!function_exists('hub_require_login')) {
    /**
     * Require user to be logged in, redirect to login if not
     */
    function hub_require_login(?string $redirect = null): void {
        if (!hub_is_logged_in()) {
            $redirect = $redirect ?? $_SERVER['REQUEST_URI'];
            header('Location: ' . HUB_V3_URL . '/login?redirect=' . urlencode($redirect));
            exit;
        }
    }
}

if (!function_exists('hub_require_admin')) {
    /**
     * Require user to be admin, redirect if not
     */
    function hub_require_admin(): void {
        hub_require_login();

        if (!hub_is_admin()) {
            header('Location: ' . HUB_V3_URL . '/?error=access_denied');
            exit;
        }
    }
}

// ============================================================================
// ROLE-BASED PERMISSION SYSTEM
// ============================================================================

if (!function_exists('hub_get_user_role')) {
    /**
     * Get user's role level
     */
    function hub_get_user_role(?int $userId = null): int {
        if ($userId === null) {
            return $_SESSION['hub_user_role'] ?? ROLE_RIDER;
        }

        static $cache = [];
        if (!isset($cache[$userId])) {
            $stmt = hub_db()->prepare("SELECT role_id FROM riders WHERE id = ?");
            $stmt->execute([$userId]);
            $cache[$userId] = (int) ($stmt->fetchColumn() ?: ROLE_RIDER);
        }

        return $cache[$userId];
    }
}

if (!function_exists('hub_get_role_name')) {
    /**
     * Get role display name
     */
    function hub_get_role_name(int $roleId): string {
        return ROLE_NAMES[$roleId] ?? 'Okänd';
    }
}

if (!function_exists('hub_has_role')) {
    /**
     * Check if user has at least a certain role level
     */
    function hub_has_role(int $requiredRole, ?int $userId = null): bool {
        $userRole = hub_get_user_role($userId);
        return $userRole >= $requiredRole;
    }
}

if (!function_exists('hub_is_role')) {
    /**
     * Check if user is exactly a certain role
     */
    function hub_is_role(int $role, ?int $userId = null): bool {
        return hub_get_user_role($userId) === $role;
    }
}

if (!function_exists('hub_is_promotor')) {
    /**
     * Check if user is promotor or higher
     */
    function hub_is_promotor(?int $userId = null): bool {
        return hub_has_role(ROLE_PROMOTOR, $userId);
    }
}

if (!function_exists('hub_is_super_admin')) {
    /**
     * Check if user is super admin
     */
    function hub_is_super_admin(?int $userId = null): bool {
        return hub_has_role(ROLE_SUPER_ADMIN, $userId);
    }
}

if (!function_exists('hub_can_manage_event')) {
    /**
     * Check if user can manage a specific event
     */
    function hub_can_manage_event(int $eventId, ?int $userId = null): bool {
        $userId = $userId ?? ($_SESSION['hub_user_id'] ?? 0);

        if (!$userId) return false;

        // Admin and Super Admin can manage all events
        if (hub_has_role(ROLE_ADMIN, $userId)) {
            return true;
        }

        // Promotor - check specific assignment
        if (hub_is_promotor($userId)) {
            $pdo = hub_db();

            // Check direct event assignment
            $stmt = $pdo->prepare("SELECT 1 FROM promotor_events WHERE rider_id = ? AND event_id = ?");
            $stmt->execute([$userId, $eventId]);
            if ($stmt->fetchColumn()) {
                return true;
            }

            // Check series assignment
            $stmt = $pdo->prepare("
                SELECT 1 FROM promotor_series ps
                JOIN events e ON e.series_id = ps.series_id
                WHERE ps.rider_id = ? AND e.id = ?
            ");
            $stmt->execute([$userId, $eventId]);
            if ($stmt->fetchColumn()) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('hub_can_manage_series')) {
    /**
     * Check if user can manage a specific series
     */
    function hub_can_manage_series(int $seriesId, ?int $userId = null): bool {
        $userId = $userId ?? ($_SESSION['hub_user_id'] ?? 0);

        if (!$userId) return false;

        // Admin and Super Admin can manage all series
        if (hub_has_role(ROLE_ADMIN, $userId)) {
            return true;
        }

        // Promotor - check specific assignment
        if (hub_is_promotor($userId)) {
            $stmt = hub_db()->prepare("SELECT 1 FROM promotor_series WHERE rider_id = ? AND series_id = ?");
            $stmt->execute([$userId, $seriesId]);
            return (bool) $stmt->fetchColumn();
        }

        return false;
    }
}

if (!function_exists('hub_get_promotor_events')) {
    /**
     * Get all events a promotor can manage
     */
    function hub_get_promotor_events(int $userId): array {
        $pdo = hub_db();

        if (hub_has_role(ROLE_ADMIN, $userId)) {
            // Admin sees all events
            return $pdo->query("
                SELECT e.*, s.name as series_name
                FROM events e
                LEFT JOIN series s ON e.series_id = s.id
                WHERE e.date >= DATE_SUB(NOW(), INTERVAL 1 YEAR)
                ORDER BY e.date DESC
            ")->fetchAll(PDO::FETCH_ASSOC);
        }

        // Promotor sees assigned events
        $stmt = $pdo->prepare("
            SELECT DISTINCT e.*, s.name as series_name,
                   CASE WHEN pe.id IS NOT NULL THEN 'event' ELSE 'series' END as assigned_via
            FROM events e
            LEFT JOIN series s ON e.series_id = s.id
            LEFT JOIN promotor_events pe ON pe.event_id = e.id AND pe.rider_id = ?
            LEFT JOIN promotor_series ps ON ps.series_id = e.series_id AND ps.rider_id = ?
            WHERE (pe.id IS NOT NULL OR ps.id IS NOT NULL)
            ORDER BY e.date DESC
        ");
        $stmt->execute([$userId, $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('hub_require_role')) {
    /**
     * Require specific role level, redirect if not authorized
     */
    function hub_require_role(int $role, ?string $redirect = null): void {
        hub_require_login();

        if (!hub_has_role($role)) {
            $redirect = $redirect ?? HUB_V3_URL . '/?error=access_denied';
            header('Location: ' . $redirect);
            exit;
        }
    }
}

if (!function_exists('hub_require_event_access')) {
    /**
     * Require access to specific event
     */
    function hub_require_event_access(int $eventId): void {
        hub_require_login();

        if (!hub_can_manage_event($eventId)) {
            header('Location: ' . HUB_V3_URL . '/admin?error=no_access');
            exit;
        }
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

// ============================================================================
// IMAGE HANDLING
// ============================================================================

if (!function_exists('hub_get_image')) {
    /**
     * Get image URL with theme variant support
     * Returns the appropriate image URL for the current theme, or data attributes
     * for JavaScript to handle theme switching.
     *
     * @param string $type Type of image (series, club, sponsor, site)
     * @param int $entityId ID of the entity
     * @param string $fallback Fallback URL if no image found
     * @return string Image URL or data attributes
     */
    function hub_get_image(string $type, int $entityId, string $fallback = ''): string {
        static $cache = [];
        $cacheKey = "{$type}-{$entityId}";

        if (!isset($cache[$cacheKey])) {
            try {
                $stmt = hub_db()->prepare("
                    SELECT variant, filename FROM images
                    WHERE type = ? AND entity_id = ?
                ");
                $stmt->execute([$type, $entityId]);
                $cache[$cacheKey] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            } catch (Exception $e) {
                $cache[$cacheKey] = [];
            }
        }

        $images = $cache[$cacheKey];
        if (empty($images)) {
            return $fallback;
        }

        $default = $images['default'] ?? '';
        $light = $images['light'] ?? $default;
        $dark = $images['dark'] ?? $default;

        // If only one image or same for both, return simple URL
        if ($light === $dark && $default) {
            return HUB_V3_URL . '/uploads/images/' . $default;
        }

        // Return the default/light version as src, with data attributes for JS
        $src = HUB_V3_URL . '/uploads/images/' . ($light ?: $default);
        return $src;
    }
}

if (!function_exists('hub_get_image_attrs')) {
    /**
     * Get image attributes for theme-aware images
     * Use this when you need data attributes for JavaScript theme switching
     *
     * @param string $type Type of image
     * @param int $entityId ID of the entity
     * @param string $fallback Fallback URL
     * @return array Array with 'src', 'light', 'dark' keys
     */
    function hub_get_image_attrs(string $type, int $entityId, string $fallback = ''): array {
        static $cache = [];
        $cacheKey = "{$type}-{$entityId}";

        if (!isset($cache[$cacheKey])) {
            try {
                $stmt = hub_db()->prepare("
                    SELECT variant, filename FROM images
                    WHERE type = ? AND entity_id = ?
                ");
                $stmt->execute([$type, $entityId]);
                $cache[$cacheKey] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            } catch (Exception $e) {
                $cache[$cacheKey] = [];
            }
        }

        $images = $cache[$cacheKey];
        if (empty($images)) {
            return ['src' => $fallback, 'light' => '', 'dark' => ''];
        }

        $baseUrl = HUB_V3_URL . '/uploads/images/';
        $default = isset($images['default']) ? $baseUrl . $images['default'] : $fallback;
        $light = isset($images['light']) ? $baseUrl . $images['light'] : $default;
        $dark = isset($images['dark']) ? $baseUrl . $images['dark'] : $default;

        return [
            'src' => $light ?: $default,
            'light' => $light,
            'dark' => $dark
        ];
    }
}

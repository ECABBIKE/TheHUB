<?php
/**
 * Authentication and session management
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    // Configure secure session parameters
    session_set_cookie_params([
        'lifetime' => defined('SESSION_LIFETIME') ? SESSION_LIFETIME : 86400,
        'path' => '/',
        'domain' => '',
        // Auto-detect HTTPS - secure flag only if using SSL
        'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
        'httponly' => true,   // Prevent JavaScript access
        'samesite' => 'Lax' // CSRF protection (Lax allows same-site navigation)
    ]);

    if (defined('SESSION_NAME')) {
        session_name(SESSION_NAME);
    } else {
        session_name('thehub_session');
    }

    session_start();

    // Regenerate session ID on login to prevent fixation attacks
    if (isset($_SESSION['admin_logged_in']) && !isset($_SESSION['session_regenerated'])) {
        session_regenerate_id(true);
        $_SESSION['session_regenerated'] = true;
    }
}

/**
 * Check if user is logged in
 */
function isLoggedIn() {
    return isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
}

/**
 * Require login
 */
function requireLogin() {
    // Prevent caching of admin pages
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Cache-Control: post-check=0, pre-check=0", false);
    header("Pragma: no-cache");

    if (!isLoggedIn()) {
        redirect('/admin/login.php');
    }
}

/**
 * Login user
 */
function login($username, $password) {
    // Check default admin credentials from config
    // WARNING: Change these in .env file for production!
    $defaultUsername = defined('DEFAULT_ADMIN_USERNAME') ? DEFAULT_ADMIN_USERNAME : 'admin';
    $defaultPassword = defined('DEFAULT_ADMIN_PASSWORD') ? DEFAULT_ADMIN_PASSWORD : 'admin';

    if ($username === $defaultUsername && $password === $defaultPassword) {
        // Regenerate session ID to prevent session fixation
        session_regenerate_id(true);

        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_id'] = 1;
        $_SESSION['admin_username'] = 'admin';
        $_SESSION['admin_role'] = 'super_admin';
        $_SESSION['admin_name'] = 'Administrator';
        $_SESSION['session_regenerated'] = true;
        return true;
    }

    // Try database authentication
    global $pdo;
    
    if (!$pdo) {
        return false;
    }

    try {
        $sql = "SELECT id, username, password_hash, email, full_name, role, active
                FROM admin_users
                WHERE username = ? AND active = 1
                LIMIT 1";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if (!$user) {
            return false;
        }

        if (!password_verify($password, $user['password_hash'])) {
            return false;
        }

        // Regenerate session ID to prevent session fixation
        session_regenerate_id(true);

        // Set session
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_id'] = $user['id'];
        $_SESSION['admin_username'] = $user['username'];
        $_SESSION['admin_role'] = $user['role'];
        $_SESSION['admin_name'] = $user['full_name'] ?? $user['username'];
        $_SESSION['session_regenerated'] = true;

        // Update last login
        $stmt = $pdo->prepare("UPDATE admin_users SET last_login = NOW() WHERE id = ?");
        $stmt->execute([$user['id']]);

        return true;
    } catch (PDOException $e) {
        error_log("Login error: " . $e->getMessage());
        return false;
    }
}

/**
 * Logout user
 */
function logout() {
    $_SESSION = [];

    // Delete session cookie
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }

    // Destroy session
    session_destroy();
}

/**
 * Get current admin user
 */
function getCurrentAdmin() {
    if (!isLoggedIn()) {
        return null;
    }

    return [
        'id' => $_SESSION['admin_id'] ?? null,
        'username' => $_SESSION['admin_username'] ?? null,
        'role' => $_SESSION['admin_role'] ?? null,
        'name' => $_SESSION['admin_name'] ?? null
    ];
}

/**
 * Check if user has role
 */
function hasRole($role) {
    if (!isLoggedIn()) {
        return false;
    }

    $currentRole = $_SESSION['admin_role'] ?? null;

    $roles = ['editor' => 1, 'admin' => 2, 'super_admin' => 3];

    return ($roles[$currentRole] ?? 0) >= ($roles[$role] ?? 999);
}

// ==============================================
// LEGACY FUNCTION ALIASES
// For backward compatibility with old code
// ==============================================

/**
 * Alias for requireLogin()
 */
function require_admin() {
    return requireLogin();
}

/**
 * Alias for isLoggedIn()
 */
function is_admin() {
    return isLoggedIn();
}

/**
 * Alias for login()
 */
function login_admin($username, $password) {
    return login($username, $password);
}

/**
 * Alias for logout()
 */
function logout_admin() {
    return logout();
}

/**
 * Alias for getCurrentAdmin()
 */
function get_admin_user() {
    return getCurrentAdmin();
}

/**
 * Alias for hasRole()
 */
function has_admin_role($role) {
    return hasRole($role);
}

// ==============================================
// CSRF PROTECTION FUNCTIONS
// ==============================================

/**
 * Generate CSRF token
 */
function generate_csrf_token() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify CSRF token
 */
function verify_csrf_token($token) {
    if (!isset($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Check CSRF token from POST request
 */
function check_csrf() {
    $token = $_POST['csrf_token'] ?? '';
    
    if (!verify_csrf_token($token)) {
        die('CSRF token validation failed');
    }
}

/**
 * Generate CSRF field for forms
 */
function csrf_field() {
    $token = generate_csrf_token();
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token) . '">';
}

/**
 * Get CSRF token (for AJAX)
 */
function get_csrf_token() {
    return generate_csrf_token();
}

// ==============================================
// FLASH MESSAGE FUNCTIONS
// ==============================================

/**
 * Set flash message
 */
function set_flash($type, $message) {
    if (!isset($_SESSION['flash'])) {
        $_SESSION['flash'] = [];
    }
    $_SESSION['flash'][$type] = $message;
}

/**
 * Get and clear flash message
 */
function get_flash($type) {
    if (!isset($_SESSION['flash'][$type])) {
        return null;
    }
    
    $message = $_SESSION['flash'][$type];
    unset($_SESSION['flash'][$type]);
    
    return $message;
}

/**
 * Check if flash message exists
 */
function has_flash($type) {
    return isset($_SESSION['flash'][$type]);
}

/**
 * Get all flash messages and clear them
 */
function get_all_flashes() {
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flashes;
}
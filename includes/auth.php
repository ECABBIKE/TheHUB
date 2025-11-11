<?php
/**
 * Authentication and session management
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    if (defined('SESSION_NAME')) {
        session_name(SESSION_NAME);
    } else {
        session_name('thehub_session');
    }
    session_start();
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
    if (!isLoggedIn()) {
        redirect('/admin/login.php');
    }
}

/**
 * Login user
 */
function login($username, $password) {
    // Check hardcoded admin credentials first
    if ($username === 'admin' && $password === 'admin') {
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_id'] = 1;
        $_SESSION['admin_username'] = 'admin';
        $_SESSION['admin_role'] = 'super_admin';
        $_SESSION['admin_name'] = 'Administrator';
        return true;
    }

    // Try database authentication
    $db = getDB();

    $sql = "SELECT id, username, password_hash, email, full_name, role, active
            FROM admin_users
            WHERE username = ? AND active = 1
            LIMIT 1";

    $user = $db->getRow($sql, [$username]);

    if (!$user) {
        return false;
    }

    if (!password_verify($password, $user['password_hash'])) {
        return false;
    }

    // Set session
    $_SESSION['admin_logged_in'] = true;
    $_SESSION['admin_id'] = $user['id'];
    $_SESSION['admin_username'] = $user['username'];
    $_SESSION['admin_role'] = $user['role'];
    $_SESSION['admin_name'] = $user['full_name'] ?? $user['username'];

    // Update last login
    $db->update('admin_users', ['last_login' => date('Y-m-d H:i:s')], 'id = ?', [$user['id']]);

    return true;
}

/**
 * Logout user
 */
function logout() {
    $_SESSION = [];
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

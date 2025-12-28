<?php
/**
 * Rider Authentication System
 * Handles login, logout, password reset for riders
 */

/**
 * Check if rider login is rate limited
 */
function is_rider_login_rate_limited($email) {
    $key = 'rider_login_' . md5($email . $_SERVER['REMOTE_ADDR']);

    if (!isset($_SESSION[$key])) {
        $_SESSION[$key] = ['count' => 0, 'first_attempt' => time()];
    }

    $attempts = &$_SESSION[$key];

    // Reset after 15 minutes
    if (time() - $attempts['first_attempt'] > 900) {
        $attempts = ['count' => 0, 'first_attempt' => time()];
    }

    // Allow max 5 attempts per 15 minutes
    return $attempts['count'] >= 5;
}

/**
 * Record a failed rider login attempt
 */
function record_failed_rider_login($email) {
    $key = 'rider_login_' . md5($email . $_SERVER['REMOTE_ADDR']);

    if (!isset($_SESSION[$key])) {
        $_SESSION[$key] = ['count' => 0, 'first_attempt' => time()];
    }

    $_SESSION[$key]['count']++;
}

/**
 * Clear rider login attempts after successful login
 */
function clear_rider_login_attempts($email) {
    $key = 'rider_login_' . md5($email . $_SERVER['REMOTE_ADDR']);
    unset($_SESSION[$key]);
}

/**
 * Check if rider is logged in
 */
function is_rider_logged_in() {
    return isset($_SESSION['rider_id']) && !empty($_SESSION['rider_id']);
}

/**
 * Get current logged-in rider
 */
function get_current_rider() {
    if (!is_rider_logged_in()) {
        return null;
    }

    $db = getDB();
    $rider = $db->getRow(
        "SELECT r.*, c.name as club_name
         FROM riders r
         LEFT JOIN clubs c ON r.club_id = c.id
         WHERE r.id = ?",
        [$_SESSION['rider_id']]
    );

    return $rider ?: null;
}

/**
 * Require rider authentication
 */
function require_rider() {
    if (!is_rider_logged_in()) {
        $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
        header('Location: /rider-login.php');
        exit;
    }
}

/**
 * Login rider with email and password
 */
function rider_login($email, $password) {
    // Check rate limiting first
    if (is_rider_login_rate_limited($email)) {
        return ['success' => false, 'message' => 'För många inloggningsförsök. Vänta 15 minuter och försök igen.'];
    }

    $db = getDB();

    // Find rider by email
    $rider = $db->getRow(
        "SELECT * FROM riders WHERE email = ? AND active = 1 LIMIT 1",
        [$email]
    );

    if (!$rider) {
        record_failed_rider_login($email);
        return ['success' => false, 'message' => 'Ogiltig e-post eller lösenord'];
    }

    // Check if rider has a password set
    if (empty($rider['password'])) {
        return ['success' => false, 'message' => 'Du har inte satt ett lösenord ännu. Klicka på "Glömt lösenord" för att skapa ett.'];
    }

    // Verify password
    if (!password_verify($password, $rider['password'])) {
        record_failed_rider_login($email);
        return ['success' => false, 'message' => 'Ogiltig e-post eller lösenord'];
    }

    // Login successful - regenerate session ID to prevent session fixation
    session_regenerate_id(true);

    // Create session
    $_SESSION['rider_id'] = $rider['id'];
    $_SESSION['rider_name'] = $rider['firstname'] . ' ' . $rider['lastname'];
    $_SESSION['rider_email'] = $rider['email'];

    // Clear rate limiting
    clear_rider_login_attempts($email);

    // Update last login
    $db->update('riders', ['last_login' => date('Y-m-d H:i:s')], 'id = ?', [$rider['id']]);

    return ['success' => true, 'rider' => $rider];
}

/**
 * Logout rider
 */
function rider_logout() {
    unset($_SESSION['rider_id']);
    unset($_SESSION['rider_name']);
    unset($_SESSION['rider_email']);
    session_destroy();
}

/**
 * Register rider account (set password for existing rider with email)
 */
function rider_register($email, $password) {
    $db = getDB();

    // Find rider by email
    $rider = $db->getRow(
        "SELECT * FROM riders WHERE email = ? LIMIT 1",
        [$email]
    );

    if (!$rider) {
        return ['success' => false, 'message' => 'Ingen deltagare med denna e-postadress hittades. Kontakta administratören.'];
    }

    // Check if password already set
    if (!empty($rider['password'])) {
        return ['success' => false, 'message' => 'Ett konto finns redan. Använd inloggningsformuläret.'];
    }

    // Hash password and save
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    $db->update('riders', ['password' => $hashedPassword], 'id = ?', [$rider['id']]);

    // Auto-login
    $_SESSION['rider_id'] = $rider['id'];
    $_SESSION['rider_name'] = $rider['firstname'] . ' ' . $rider['lastname'];
    $_SESSION['rider_email'] = $rider['email'];

    return ['success' => true, 'message' => 'Konto skapat! Du är nu inloggad.'];
}

/**
 * Request password reset
 */
function rider_request_password_reset($email) {
    $db = getDB();

    // Find rider by email
    $rider = $db->getRow(
        "SELECT * FROM riders WHERE email = ? LIMIT 1",
        [$email]
    );

    if (!$rider) {
        // Don't reveal if email exists or not
        return ['success' => true, 'message' => 'Om e-postadressen finns i systemet kommer du få ett mail med instruktioner.'];
    }

    // Generate reset token
    $token = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

    // Save token
    $db->update('riders', [
        'password_reset_token' => $token,
        'password_reset_expires' => $expires
    ], 'id = ?', [$rider['id']]);

    // TODO: Send email with reset link
    // For now, log the link for admin to manually send
    $resetLink = SITE_URL . '/rider-reset-password.php?token=' . $token;

    // SECURITY: Only log email, never the full link in production
    error_log("Password reset requested for: {$email}");

    // PRODUCTION: Return success without exposing token
    // Admin can access token from database if needed for manual password reset
    return [
        'success' => true,
        'message' => 'Om e-postadressen finns i systemet kommer du få instruktioner för återställning. Kontakta admin om du inte får något mail inom 10 minuter.'
    ];
}

/**
 * Reset password with token
 */
function rider_reset_password($token, $newPassword) {
    $db = getDB();

    // Find rider with valid token
    $rider = $db->getRow(
        "SELECT * FROM riders
         WHERE password_reset_token = ?
         AND password_reset_expires > NOW()
         LIMIT 1",
        [$token]
    );

    if (!$rider) {
        return ['success' => false, 'message' => 'Ogiltig eller utgången återställningslänk'];
    }

    // Hash and save new password
    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
    $db->update('riders', [
        'password' => $hashedPassword,
        'password_reset_token' => null,
        'password_reset_expires' => null
    ], 'id = ?', [$rider['id']]);

    return ['success' => true, 'message' => 'Lösenord återställt! Du kan nu logga in.'];
}

/**
 * Change rider password (when logged in)
 */
function rider_change_password($riderId, $currentPassword, $newPassword) {
    $db = getDB();

    $rider = $db->getRow("SELECT * FROM riders WHERE id = ?", [$riderId]);

    if (!$rider) {
        return ['success' => false, 'message' => 'Deltagare hittades inte'];
    }

    // Verify current password
    if (!empty($rider['password']) && !password_verify($currentPassword, $rider['password'])) {
        return ['success' => false, 'message' => 'Nuvarande lösenord är felaktigt'];
    }

    // Hash and save new password
    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
    $db->update('riders', ['password' => $hashedPassword], 'id = ?', [$riderId]);

    return ['success' => true, 'message' => 'Lösenord ändrat!'];
}

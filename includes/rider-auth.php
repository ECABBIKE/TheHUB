<?php
/**
 * Rider Authentication System
 * Handles login, logout, password reset for riders
 */

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

    if (!$rider) {
        return null;
    }

    // Check rider_club_seasons for current year - takes precedence over riders.club_id
    $currentYear = (int)date('Y');
    $seasonClub = $db->getRow(
        "SELECT rcs.club_id, c.name as club_name
         FROM rider_club_seasons rcs
         JOIN clubs c ON rcs.club_id = c.id
         WHERE rcs.rider_id = ? AND rcs.season_year = ?
         LIMIT 1",
        [$rider['id'], $currentYear]
    );

    if ($seasonClub) {
        $rider['club_id'] = $seasonClub['club_id'];
        $rider['club_name'] = $seasonClub['club_name'];
    }

    return $rider;
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
 * @param bool $rememberMe If true, creates a persistent remember token
 */
function rider_login($email, $password, $rememberMe = false) {
    $db = getDB();

    // Find rider by email that has a password set (the "primary" account)
    $rider = $db->getRow(
        "SELECT * FROM riders WHERE email = ? AND active = 1 AND password IS NOT NULL AND password != '' LIMIT 1",
        [$email]
    );

    if (!$rider) {
        // Try finding any rider with this email (might not have password set)
        $anyRider = $db->getRow(
            "SELECT * FROM riders WHERE email = ? AND active = 1 LIMIT 1",
            [$email]
        );

        if ($anyRider && empty($anyRider['password'])) {
            return ['success' => false, 'message' => 'Du har inte satt ett lösenord ännu. Klicka på "Glömt lösenord" för att skapa ett.'];
        }
        return ['success' => false, 'message' => 'Ogiltig e-post eller lösenord'];
    }

    // Verify password
    if (!password_verify($password, $rider['password'])) {
        return ['success' => false, 'message' => 'Ogiltig e-post eller lösenord'];
    }

    // Login successful - create session
    $_SESSION['rider_id'] = $rider['id'];
    $_SESSION['rider_name'] = $rider['firstname'] . ' ' . $rider['lastname'];
    $_SESSION['rider_email'] = $rider['email'];
    $_SESSION['rider_remember_me'] = $rememberMe;

    // Find ALL profiles with the same email (for "Mina profiler" feature)
    $allProfiles = $db->getAll(
        "SELECT id, firstname, lastname, birth_year, gender FROM riders WHERE email = ? AND active = 1 ORDER BY birth_year DESC",
        [$email]
    );
    $_SESSION['rider_all_profiles'] = $allProfiles;
    $_SESSION['rider_profile_count'] = count($allProfiles);

    // If remember me, extend session cookie and create remember token
    if ($rememberMe) {
        rider_set_remember_token($rider['id']);
    }

    // Update last login
    $db->update('riders', ['last_login' => date('Y-m-d H:i:s')], 'id = ?', [$rider['id']]);

    return ['success' => true, 'rider' => $rider, 'profile_count' => count($allProfiles)];
}

/**
 * Set remember token cookie for persistent login
 */
function rider_set_remember_token($riderId) {
    $db = getDB();
    $lifetime = 30 * 24 * 60 * 60; // 30 days

    // Generate secure token
    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $expires = date('Y-m-d H:i:s', time() + $lifetime);

    // Store hashed token in database
    $db->query(
        "UPDATE riders SET remember_token = ?, remember_token_expires = ? WHERE id = ?",
        [$tokenHash, $expires, $riderId]
    );

    // Set cookie with the original (unhashed) token
    $secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
    setcookie(
        'rider_remember',
        $riderId . ':' . $token,
        [
            'expires' => time() + $lifetime,
            'path' => '/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax'
        ]
    );

    // Also extend session cookie
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        session_id(),
        [
            'expires' => time() + $lifetime,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Lax'
        ]
    );
}

/**
 * Check for remember token and auto-login if valid
 * Call this at the start of pages that require authentication
 */
function rider_check_remember_token() {
    // Already logged in
    if (is_rider_logged_in()) {
        return true;
    }

    // No remember cookie
    if (empty($_COOKIE['rider_remember'])) {
        return false;
    }

    // Parse cookie
    $parts = explode(':', $_COOKIE['rider_remember'], 2);
    if (count($parts) !== 2) {
        rider_clear_remember_token();
        return false;
    }

    list($riderId, $token) = $parts;
    $riderId = (int)$riderId;

    if ($riderId <= 0 || empty($token)) {
        rider_clear_remember_token();
        return false;
    }

    $db = getDB();

    // Find rider with valid remember token
    $tokenHash = hash('sha256', $token);
    $rider = $db->getRow(
        "SELECT * FROM riders
         WHERE id = ? AND remember_token = ? AND remember_token_expires > NOW() AND active = 1",
        [$riderId, $tokenHash]
    );

    if (!$rider) {
        rider_clear_remember_token();
        return false;
    }

    // Valid token - log the user in
    $_SESSION['rider_id'] = $rider['id'];
    $_SESSION['rider_name'] = $rider['firstname'] . ' ' . $rider['lastname'];
    $_SESSION['rider_email'] = $rider['email'];
    $_SESSION['rider_remember_me'] = true;

    // Refresh the remember token (rotate for security)
    rider_set_remember_token($rider['id']);

    return true;
}

/**
 * Clear remember token cookie and database entry
 */
function rider_clear_remember_token($riderId = null) {
    // Clear cookie
    setcookie('rider_remember', '', [
        'expires' => time() - 3600,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    // Clear database entry if rider ID provided
    if ($riderId) {
        $db = getDB();
        $db->query(
            "UPDATE riders SET remember_token = NULL, remember_token_expires = NULL WHERE id = ?",
            [$riderId]
        );
    }
}

/**
 * Logout rider
 */
function rider_logout() {
    // Clear remember token if logged in
    if (isset($_SESSION['rider_id'])) {
        rider_clear_remember_token($_SESSION['rider_id']);
    }

    unset($_SESSION['rider_id']);
    unset($_SESSION['rider_name']);
    unset($_SESSION['rider_email']);
    unset($_SESSION['rider_remember_me']);
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
    // For now, return the token (in production, this should be emailed)
    $resetLink = SITE_URL . '/rider-reset-password.php?token=' . $token;

    error_log("Password reset link for {$email}: {$resetLink}");

    return [
        'success' => true,
        'message' => 'Återställningslänk skickad! (Kontrollera server-loggen för länken - e-post kommer implementeras senare)',
        'token' => $token, // Remove in production
        'link' => $resetLink // Remove in production
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

/**
 * Get clubs that the current rider can manage
 * Checks both rider_profiles.can_manage_club and club_admins table
 */
function get_rider_managed_clubs() {
    if (!is_rider_logged_in()) {
        return [];
    }

    $riderId = $_SESSION['rider_id'];
    $db = getDB();

    $clubs = [];

    // Check rider_profiles for can_manage_club permission
    $riderProfile = $db->getRow(
        "SELECT rp.*, r.club_id
         FROM rider_profiles rp
         JOIN riders r ON rp.rider_id = r.id
         WHERE rp.rider_id = ? AND rp.can_manage_club = 1",
        [$riderId]
    );

    if ($riderProfile && $riderProfile['club_id']) {
        $club = $db->getRow(
            "SELECT c.*, 1 as can_edit_profile, 1 as can_upload_logo
             FROM clubs c WHERE c.id = ?",
            [$riderProfile['club_id']]
        );
        if ($club) {
            $clubs[] = $club;
        }
    }

    // Also check club_admins table via linked admin_user
    if ($riderProfile && $riderProfile['user_id']) {
        $adminClubs = $db->getAll(
            "SELECT c.*, ca.can_edit_profile, ca.can_upload_logo
             FROM club_admins ca
             JOIN clubs c ON ca.club_id = c.id
             WHERE ca.user_id = ?",
            [$riderProfile['user_id']]
        );
        foreach ($adminClubs as $club) {
            // Avoid duplicates
            $found = false;
            foreach ($clubs as $existing) {
                if ($existing['id'] == $club['id']) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $clubs[] = $club;
            }
        }
    }

    return $clubs;
}

/**
 * Get all profiles linked to the current user's email
 * Returns array of rider profiles that share the same email
 */
function get_rider_linked_profiles() {
    if (!is_rider_logged_in()) {
        return [];
    }

    // Return cached profiles from session if available
    if (isset($_SESSION['rider_all_profiles']) && !empty($_SESSION['rider_all_profiles'])) {
        return $_SESSION['rider_all_profiles'];
    }

    // Otherwise fetch from database
    $email = $_SESSION['rider_email'] ?? null;
    if (!$email) {
        return [];
    }

    $db = getDB();
    $profiles = $db->getAll(
        "SELECT id, firstname, lastname, birth_year, gender FROM riders WHERE email = ? AND active = 1 ORDER BY birth_year DESC",
        [$email]
    );

    $_SESSION['rider_all_profiles'] = $profiles;
    $_SESSION['rider_profile_count'] = count($profiles);

    return $profiles;
}

/**
 * Switch to a different profile (must be linked to same email)
 * @param int $riderId The rider ID to switch to
 * @return bool True if switch successful
 */
function rider_switch_profile($riderId) {
    if (!is_rider_logged_in()) {
        return false;
    }

    $email = $_SESSION['rider_email'] ?? null;
    if (!$email) {
        return false;
    }

    $db = getDB();

    // Verify that this rider has the same email (security check)
    $rider = $db->getRow(
        "SELECT * FROM riders WHERE id = ? AND email = ? AND active = 1",
        [$riderId, $email]
    );

    if (!$rider) {
        return false;
    }

    // Switch to this profile
    $_SESSION['rider_id'] = $rider['id'];
    $_SESSION['rider_name'] = $rider['firstname'] . ' ' . $rider['lastname'];

    return true;
}

/**
 * Check if current user can manage a specific rider profile
 * Returns true if the profile shares the same email or is linked via rider_parents
 * @param int $riderId The rider ID to check
 * @return bool
 */
function can_manage_rider_profile($riderId) {
    if (!is_rider_logged_in()) {
        return false;
    }

    // Same rider = can manage
    if ($_SESSION['rider_id'] == $riderId) {
        return true;
    }

    $email = $_SESSION['rider_email'] ?? null;
    if (!$email) {
        return false;
    }

    $db = getDB();

    // Check if rider has the same email
    $rider = $db->getRow(
        "SELECT id FROM riders WHERE id = ? AND email = ? AND active = 1",
        [$riderId, $email]
    );

    if ($rider) {
        return true;
    }

    // Also check rider_parents table (for explicitly linked children)
    try {
        $linked = $db->getRow(
            "SELECT 1 FROM rider_parents WHERE parent_rider_id = ? AND child_rider_id = ?",
            [$_SESSION['rider_id'], $riderId]
        );
        if ($linked) {
            return true;
        }
    } catch (PDOException $e) {
        // Table might not exist
    }

    return false;
}

/**
 * Get the count of profiles the current user can manage
 */
function get_rider_profile_count() {
    return $_SESSION['rider_profile_count'] ?? 1;
}

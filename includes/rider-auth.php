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
 */
function rider_login($email, $password) {
    $db = getDB();

    // Find rider by email
    $rider = $db->getRow(
        "SELECT * FROM riders WHERE email = ? AND active = 1 LIMIT 1",
        [$email]
    );

    if (!$rider) {
        return ['success' => false, 'message' => 'Ogiltig e-post eller lösenord'];
    }

    // Check if rider has a password set
    if (empty($rider['password'])) {
        return ['success' => false, 'message' => 'Du har inte satt ett lösenord ännu. Klicka på "Glömt lösenord" för att skapa ett.'];
    }

    // Verify password
    if (!password_verify($password, $rider['password'])) {
        return ['success' => false, 'message' => 'Ogiltig e-post eller lösenord'];
    }

    // Login successful - create session
    $_SESSION['rider_id'] = $rider['id'];
    $_SESSION['rider_name'] = $rider['firstname'] . ' ' . $rider['lastname'];
    $_SESSION['rider_email'] = $rider['email'];

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

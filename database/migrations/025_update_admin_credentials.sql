-- Migration: Update admin credentials
-- Description: Replace default admin user with new credentials
-- Date: 2025-11-23

-- Delete the old admin user
DELETE FROM admin_users WHERE username = 'admin';

-- Insert new admin user (or update if exists)
INSERT INTO admin_users (username, password_hash, email, full_name, role, active, created_at, updated_at)
VALUES (
    'roger',
    '$2y$12$Chd19PIKV6soorPEpY9WAOnCW3HiIRb3KBZTw0Wf.mEPyThZcnvwe',
    'Roger@ecab.bike',
    'Roger',
    'super_admin',
    1,
    NOW(),
    NOW()
)
ON DUPLICATE KEY UPDATE
    password_hash = '$2y$12$Chd19PIKV6soorPEpY9WAOnCW3HiIRb3KBZTw0Wf.mEPyThZcnvwe',
    email = 'Roger@ecab.bike',
    full_name = 'Roger',
    role = 'super_admin',
    active = 1,
    updated_at = NOW();

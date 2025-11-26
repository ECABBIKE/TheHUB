-- Migration: Add admin user Tobias
-- Date: 2025-11-26
-- Description: Creates a new super_admin user for Tobias

INSERT INTO admin_users (username, password_hash, email, full_name, role, active, created_at, updated_at)
VALUES (
    'tobias',
    '$2y$12$GoJSI8kPulTxQAjIINRLjuG0L1XmyQi6pXEbZZe1quDNLDkyvbYEa',
    'tobias@ecab.bike',
    'Tobias',
    'super_admin',
    1,
    NOW(),
    NOW()
)
ON DUPLICATE KEY UPDATE
    password_hash = '$2y$12$u7TzZ9lgHnuH.amaksCJoOm/kTyVTdyW0sNpGztPZaDKRHWrMTfOS',
    role = 'super_admin',
    active = 1,
    updated_at = NOW();

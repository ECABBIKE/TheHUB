-- Migration 035: User Accounts System
-- Separates user identity from rider profiles.
-- Creates a proper user_accounts table that owns authentication,
-- while rider profiles link to user accounts via user_account_id.
--
-- Benefits:
-- 1. A "user" is no longer conflated with a "primary rider"
-- 2. Profiles can be split/reassigned between user accounts
-- 3. Password and auth tokens live on user_accounts, not riders
-- 4. Explicit linking instead of implicit email-based grouping
--
-- Backward compatibility:
-- - riders.password, riders.email etc. are NOT removed yet
-- - Auth system continues to work with existing columns during transition
-- - user_account_id is nullable (NULL = not yet migrated)

-- Create user_accounts table
CREATE TABLE IF NOT EXISTS user_accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL,
    password_hash VARCHAR(255) DEFAULT NULL,
    remember_token VARCHAR(255) DEFAULT NULL,
    remember_token_expires DATETIME DEFAULT NULL,
    password_reset_token VARCHAR(255) DEFAULT NULL,
    password_reset_expires DATETIME DEFAULT NULL,
    last_login DATETIME DEFAULT NULL,
    status ENUM('active', 'disabled', 'pending') DEFAULT 'active',
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_email (email),
    INDEX idx_status (status),
    INDEX idx_reset_token (password_reset_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='User accounts - owns authentication. Riders link here via user_account_id.';

-- Add user_account_id to riders table
ALTER TABLE riders ADD COLUMN IF NOT EXISTS user_account_id INT DEFAULT NULL AFTER email;

-- Add index for the foreign key
ALTER TABLE riders ADD INDEX IF NOT EXISTS idx_user_account_id (user_account_id);

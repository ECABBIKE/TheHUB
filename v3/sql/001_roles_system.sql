-- ============================================================================
-- TheHUB V3.5 - Role-Based Permission System
-- Run this migration to add the role system
-- ============================================================================

-- Create roles table
CREATE TABLE IF NOT EXISTS roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE,
    level INT NOT NULL DEFAULT 1,
    description VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default roles
INSERT INTO roles (id, name, level, description) VALUES
(1, 'rider', 1, 'Vanlig anvandare - kan se profil och anmala sig'),
(2, 'promotor', 2, 'Arrangor - kan hantera tilldelade events'),
(3, 'admin', 3, 'Administrator - kan hantera allt innehall'),
(4, 'super_admin', 4, 'Super Admin - full systematkomst')
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- Add role_id column to riders table (if not exists)
-- First check if column exists
SET @column_exists = (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
    AND table_name = 'riders'
    AND column_name = 'role_id'
);

SET @sql = IF(@column_exists = 0,
    'ALTER TABLE riders ADD COLUMN role_id INT DEFAULT 1',
    'SELECT "role_id column already exists"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add role update tracking columns
SET @column_exists = (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
    AND table_name = 'riders'
    AND column_name = 'role_updated_at'
);

SET @sql = IF(@column_exists = 0,
    'ALTER TABLE riders ADD COLUMN role_updated_at TIMESTAMP NULL',
    'SELECT "role_updated_at column already exists"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @column_exists = (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
    AND table_name = 'riders'
    AND column_name = 'role_updated_by'
);

SET @sql = IF(@column_exists = 0,
    'ALTER TABLE riders ADD COLUMN role_updated_by INT NULL',
    'SELECT "role_updated_by column already exists"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Create index for faster role lookups
CREATE INDEX IF NOT EXISTS idx_riders_role ON riders(role_id);

-- Migrate existing admins (is_admin = 1 becomes Super Admin)
UPDATE riders SET role_id = 4 WHERE is_admin = 1 AND (role_id IS NULL OR role_id = 1);

-- ============================================================================
-- Promotor assignment tables
-- ============================================================================

-- Promotor-event assignments
CREATE TABLE IF NOT EXISTS promotor_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    rider_id INT NOT NULL,
    event_id INT NOT NULL,
    can_edit_results TINYINT(1) DEFAULT 1,
    can_edit_registrations TINYINT(1) DEFAULT 1,
    can_edit_event TINYINT(1) DEFAULT 0,
    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    assigned_by INT NULL,
    UNIQUE KEY unique_promotor_event (rider_id, event_id),
    KEY idx_promotor_events_rider (rider_id),
    KEY idx_promotor_events_event (event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Promotor-series assignments
CREATE TABLE IF NOT EXISTS promotor_series (
    id INT AUTO_INCREMENT PRIMARY KEY,
    rider_id INT NOT NULL,
    series_id INT NOT NULL,
    can_edit_results TINYINT(1) DEFAULT 1,
    can_edit_registrations TINYINT(1) DEFAULT 1,
    can_edit_events TINYINT(1) DEFAULT 0,
    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    assigned_by INT NULL,
    UNIQUE KEY unique_promotor_series (rider_id, series_id),
    KEY idx_promotor_series_rider (rider_id),
    KEY idx_promotor_series_series (series_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- Done!
-- ============================================================================

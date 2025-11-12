-- Migration 002: Add License Fields to Cyclists Table
-- This migration will:
-- 1. Create the cyclists table if it doesn't exist (with license fields included)
-- 2. Add license fields to existing table if they don't already exist

-- Set charset
SET NAMES utf8mb4;
SET CHARACTER SET utf8mb4;

-- ============================================================================
-- STEP 1: Create clubs table if it doesn't exist (required foreign key)
-- ============================================================================
CREATE TABLE IF NOT EXISTS clubs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    short_name VARCHAR(50),
    region VARCHAR(100),
    city VARCHAR(100),
    country VARCHAR(100) DEFAULT 'Sverige',
    website VARCHAR(255),
    active BOOLEAN DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_name (name),
    INDEX idx_city (city),
    INDEX idx_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- STEP 2: Create cyclists table WITH license fields if it doesn't exist
-- ============================================================================
CREATE TABLE IF NOT EXISTS cyclists (
    id INT AUTO_INCREMENT PRIMARY KEY,
    firstname VARCHAR(100) NOT NULL,
    lastname VARCHAR(100) NOT NULL,
    birth_year INT,
    gender ENUM('M', 'F', 'Other') DEFAULT 'M',
    club_id INT,
    license_number VARCHAR(50),
    license_type VARCHAR(50) COMMENT 'License type: Elite, Youth, Hobby, etc',
    license_category VARCHAR(50) COMMENT 'License category: Elite Men, Master Women 35+, etc',
    discipline VARCHAR(50) COMMENT 'Discipline: MTB, Road, Track, etc',
    license_valid_until DATE COMMENT 'License expiry date',
    email VARCHAR(255),
    phone VARCHAR(20),
    city VARCHAR(100),
    active BOOLEAN DEFAULT 1,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (club_id) REFERENCES clubs(id) ON DELETE SET NULL,
    INDEX idx_name (lastname, firstname),
    INDEX idx_club (club_id),
    INDEX idx_license (license_number),
    INDEX idx_active (active),
    INDEX idx_license_type (license_type),
    INDEX idx_license_category (license_category),
    INDEX idx_discipline (discipline),
    INDEX idx_license_valid (license_valid_until),
    UNIQUE KEY unique_license (license_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- STEP 3: Add license fields to existing table (if table already existed)
-- ============================================================================
-- Note: These will fail silently if columns already exist (from CREATE TABLE above)
-- We use a stored procedure to check and add columns only if they don't exist

DELIMITER $$

CREATE PROCEDURE add_license_fields()
BEGIN
    -- Add license_type if it doesn't exist
    IF NOT EXISTS (
        SELECT * FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'cyclists'
        AND COLUMN_NAME = 'license_type'
    ) THEN
        ALTER TABLE cyclists
        ADD COLUMN license_type VARCHAR(50) COMMENT 'License type: Elite, Youth, Hobby, etc' AFTER license_number;
        ALTER TABLE cyclists ADD INDEX idx_license_type (license_type);
    END IF;

    -- Add license_category if it doesn't exist
    IF NOT EXISTS (
        SELECT * FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'cyclists'
        AND COLUMN_NAME = 'license_category'
    ) THEN
        ALTER TABLE cyclists
        ADD COLUMN license_category VARCHAR(50) COMMENT 'License category: Elite Men, Master Women 35+, etc' AFTER license_type;
        ALTER TABLE cyclists ADD INDEX idx_license_category (license_category);
    END IF;

    -- Add discipline if it doesn't exist
    IF NOT EXISTS (
        SELECT * FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'cyclists'
        AND COLUMN_NAME = 'discipline'
    ) THEN
        ALTER TABLE cyclists
        ADD COLUMN discipline VARCHAR(50) COMMENT 'Discipline: MTB, Road, Track, etc' AFTER license_category;
        ALTER TABLE cyclists ADD INDEX idx_discipline (discipline);
    END IF;

    -- Add license_valid_until if it doesn't exist
    IF NOT EXISTS (
        SELECT * FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'cyclists'
        AND COLUMN_NAME = 'license_valid_until'
    ) THEN
        ALTER TABLE cyclists
        ADD COLUMN license_valid_until DATE COMMENT 'License expiry date' AFTER discipline;
        ALTER TABLE cyclists ADD INDEX idx_license_valid (license_valid_until);
    END IF;
END$$

DELIMITER ;

-- Execute the procedure
CALL add_license_fields();

-- Drop the procedure (cleanup)
DROP PROCEDURE IF EXISTS add_license_fields;

-- ============================================================================
-- VERIFICATION
-- ============================================================================
-- Show the updated table structure
SHOW COLUMNS FROM cyclists;

-- Success message
SELECT 'Migration 002 completed successfully! License fields added to cyclists table.' AS status;

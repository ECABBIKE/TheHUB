-- Migration 079: Club admins + Venue admins
-- Creates/recreates club_admins and new venue_admins tables
-- Both link admin_users to clubs/venues they can manage

-- Recreate club_admins (may exist from archived migration, ensure correct structure)
CREATE TABLE IF NOT EXISTS club_admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL COMMENT 'References admin_users.id',
    club_id INT NOT NULL COMMENT 'References clubs.id',
    can_edit_profile TINYINT(1) DEFAULT 1,
    can_upload_logo TINYINT(1) DEFAULT 1,
    can_manage_members TINYINT(1) DEFAULT 0,
    granted_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_user_club (user_id, club_id),
    INDEX idx_user_id (user_id),
    INDEX idx_club_id (club_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Create venue_admins (destination admins)
CREATE TABLE IF NOT EXISTS venue_admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL COMMENT 'References admin_users.id',
    venue_id INT NOT NULL COMMENT 'References venues.id',
    can_edit_profile TINYINT(1) DEFAULT 1,
    can_upload_media TINYINT(1) DEFAULT 1,
    granted_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_user_venue (user_id, venue_id),
    INDEX idx_user_id (user_id),
    INDEX idx_venue_id (venue_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Add logo_url to clubs if not exists
ALTER TABLE clubs ADD COLUMN IF NOT EXISTS logo_url VARCHAR(500) NULL AFTER website;

-- Ensure admin_users role supports all role types
-- Add venue_admin to role enum if not already there
ALTER TABLE admin_users MODIFY COLUMN role ENUM('super_admin', 'admin', 'editor', 'promotor', 'photographer', 'club_admin', 'venue_admin', 'rider') DEFAULT 'editor';

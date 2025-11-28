-- Migration: 043_parent_child_and_club_admin.sql
-- Add rider_parents table for parent-child relationships
-- Add club_admins table for club management permissions

-- Parent-Child relationships (for registration of minors)
CREATE TABLE IF NOT EXISTS rider_parents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    parent_rider_id INT NOT NULL,
    child_rider_id INT NOT NULL,
    relationship ENUM('parent', 'guardian', 'coach') DEFAULT 'parent',
    can_register TINYINT(1) DEFAULT 1 COMMENT 'Can register child for events',
    can_edit_profile TINYINT(1) DEFAULT 1 COMMENT 'Can edit child profile',
    verified TINYINT(1) DEFAULT 0 COMMENT 'Relationship verified by admin',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_parent_child (parent_rider_id, child_rider_id),
    FOREIGN KEY (parent_rider_id) REFERENCES riders(id) ON DELETE CASCADE,
    FOREIGN KEY (child_rider_id) REFERENCES riders(id) ON DELETE CASCADE,
    INDEX idx_parent (parent_rider_id),
    INDEX idx_child (child_rider_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_swedish_ci;

-- Club administrators
CREATE TABLE IF NOT EXISTS club_admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    rider_id INT NOT NULL,
    club_id INT NOT NULL,
    role ENUM('admin', 'manager', 'contact') DEFAULT 'admin',
    can_edit_club TINYINT(1) DEFAULT 1 COMMENT 'Can edit club info',
    can_manage_members TINYINT(1) DEFAULT 1 COMMENT 'Can add/remove members',
    can_register_members TINYINT(1) DEFAULT 1 COMMENT 'Can register members for events',
    granted_by INT NULL COMMENT 'Admin user who granted access',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_rider_club (rider_id, club_id),
    FOREIGN KEY (rider_id) REFERENCES riders(id) ON DELETE CASCADE,
    FOREIGN KEY (club_id) REFERENCES clubs(id) ON DELETE CASCADE,
    INDEX idx_rider (rider_id),
    INDEX idx_club (club_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_swedish_ci;

-- Add phone field to riders if not exists
ALTER TABLE riders ADD COLUMN IF NOT EXISTS phone VARCHAR(20) NULL AFTER email;

-- Add index for email lookups (for WooCommerce integration)
ALTER TABLE riders ADD INDEX IF NOT EXISTS idx_email (email);

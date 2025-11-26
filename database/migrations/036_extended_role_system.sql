-- Migration: Extended Role System
-- Date: 2025-11-26
-- Description: Implements comprehensive role-based access control with 4 user types

-- ============================================================================
-- STEP 1: Update admin_users role ENUM to include new roles
-- ============================================================================
ALTER TABLE admin_users
MODIFY COLUMN role ENUM('super_admin', 'admin', 'promotor', 'rider') DEFAULT 'rider';

-- ============================================================================
-- STEP 2: Create permissions table for granular access control
-- ============================================================================
CREATE TABLE IF NOT EXISTS permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    description VARCHAR(255),
    category VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- STEP 3: Create role_permissions table (which roles have which permissions)
-- ============================================================================
CREATE TABLE IF NOT EXISTS role_permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    role ENUM('super_admin', 'admin', 'promotor', 'rider') NOT NULL,
    permission_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE,
    UNIQUE KEY unique_role_permission (role, permission_id),
    INDEX idx_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- STEP 4: Create promotor_events table (which events a promotor can manage)
-- ============================================================================
CREATE TABLE IF NOT EXISTS promotor_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    event_id INT NOT NULL,
    can_edit BOOLEAN DEFAULT 1,
    can_manage_results BOOLEAN DEFAULT 1,
    can_manage_registrations BOOLEAN DEFAULT 1,
    granted_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES admin_users(id) ON DELETE CASCADE,
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    FOREIGN KEY (granted_by) REFERENCES admin_users(id) ON DELETE SET NULL,
    UNIQUE KEY unique_promotor_event (user_id, event_id),
    INDEX idx_user (user_id),
    INDEX idx_event (event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- STEP 5: Create rider_profiles table (links admin_users to riders table)
-- ============================================================================
CREATE TABLE IF NOT EXISTS rider_profiles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    rider_id INT NOT NULL UNIQUE,
    can_edit_profile BOOLEAN DEFAULT 1,
    can_manage_club BOOLEAN DEFAULT 0,
    approved_by INT,
    approved_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES admin_users(id) ON DELETE CASCADE,
    FOREIGN KEY (rider_id) REFERENCES riders(id) ON DELETE CASCADE,
    FOREIGN KEY (approved_by) REFERENCES admin_users(id) ON DELETE SET NULL,
    INDEX idx_user (user_id),
    INDEX idx_rider (rider_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- STEP 6: Create admin_pages table (which pages an admin can access)
-- ============================================================================
CREATE TABLE IF NOT EXISTS admin_pages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    page_key VARCHAR(50) NOT NULL UNIQUE,
    page_name VARCHAR(100) NOT NULL,
    page_url VARCHAR(255),
    description VARCHAR(255),
    min_role ENUM('super_admin', 'admin', 'promotor', 'rider') DEFAULT 'admin',
    is_active BOOLEAN DEFAULT 1,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_page_key (page_key),
    INDEX idx_min_role (min_role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- STEP 7: Create user_page_access table (specific page access overrides)
-- ============================================================================
CREATE TABLE IF NOT EXISTS user_page_access (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    page_id INT NOT NULL,
    has_access BOOLEAN DEFAULT 1,
    granted_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES admin_users(id) ON DELETE CASCADE,
    FOREIGN KEY (page_id) REFERENCES admin_pages(id) ON DELETE CASCADE,
    FOREIGN KEY (granted_by) REFERENCES admin_users(id) ON DELETE SET NULL,
    UNIQUE KEY unique_user_page (user_id, page_id),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- STEP 8: Insert default permissions
-- ============================================================================
INSERT IGNORE INTO permissions (name, description, category) VALUES
-- System permissions
('system.settings', 'Access system settings', 'system'),
('system.users', 'Manage users', 'system'),
('system.roles', 'Manage roles and permissions', 'system'),

-- Event permissions
('events.view', 'View events', 'events'),
('events.create', 'Create events', 'events'),
('events.edit', 'Edit events', 'events'),
('events.delete', 'Delete events', 'events'),
('events.results', 'Manage event results', 'events'),
('events.registrations', 'Manage registrations', 'events'),

-- Series permissions
('series.view', 'View series', 'series'),
('series.create', 'Create series', 'series'),
('series.edit', 'Edit series', 'series'),
('series.delete', 'Delete series', 'series'),

-- Rider permissions
('riders.view', 'View riders', 'riders'),
('riders.create', 'Create riders', 'riders'),
('riders.edit', 'Edit riders', 'riders'),
('riders.delete', 'Delete riders', 'riders'),
('riders.own_profile', 'Edit own rider profile', 'riders'),

-- Club permissions
('clubs.view', 'View clubs', 'clubs'),
('clubs.create', 'Create clubs', 'clubs'),
('clubs.edit', 'Edit clubs', 'clubs'),
('clubs.delete', 'Delete clubs', 'clubs'),
('clubs.own_club', 'Manage own club', 'clubs'),

-- Import/Export permissions
('import.riders', 'Import riders', 'import'),
('import.results', 'Import results', 'import'),
('export.data', 'Export data', 'export');

-- ============================================================================
-- STEP 9: Assign default permissions to roles
-- ============================================================================

-- Super Admin: All permissions
INSERT IGNORE INTO role_permissions (role, permission_id)
SELECT 'super_admin', id FROM permissions;

-- Admin: Most permissions except system settings
INSERT IGNORE INTO role_permissions (role, permission_id)
SELECT 'admin', id FROM permissions
WHERE name NOT IN ('system.settings', 'system.roles');

-- Promotor: Event-related permissions only
INSERT IGNORE INTO role_permissions (role, permission_id)
SELECT 'promotor', id FROM permissions
WHERE category IN ('events') OR name IN ('riders.view', 'clubs.view');

-- Rider: Limited to own profile
INSERT IGNORE INTO role_permissions (role, permission_id)
SELECT 'rider', id FROM permissions
WHERE name IN ('riders.own_profile', 'clubs.own_club', 'events.view', 'riders.view', 'clubs.view');

-- ============================================================================
-- STEP 10: Insert default admin pages
-- ============================================================================
INSERT IGNORE INTO admin_pages (page_key, page_name, page_url, description, min_role, sort_order) VALUES
('dashboard', 'Dashboard', '/admin/dashboard.php', 'Main dashboard', 'rider', 1),
('events', 'Events', '/admin/events.php', 'Manage events', 'promotor', 10),
('event_edit', 'Edit Event', '/admin/event-edit.php', 'Edit event details', 'promotor', 11),
('results', 'Results', '/admin/results.php', 'Manage results', 'promotor', 12),
('series', 'Series', '/admin/series.php', 'Manage series', 'admin', 20),
('riders', 'Riders', '/admin/riders.php', 'Manage riders', 'admin', 30),
('clubs', 'Clubs', '/admin/clubs.php', 'Manage clubs', 'admin', 40),
('import', 'Import', '/admin/import.php', 'Import data', 'admin', 50),
('users', 'Users', '/admin/users.php', 'Manage users', 'super_admin', 90),
('settings', 'Settings', '/admin/settings.php', 'System settings', 'super_admin', 100);

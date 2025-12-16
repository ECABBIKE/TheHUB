-- Migration: 068_create_promotor_events_table
-- Description: Create promotor_events table for event-user assignments
-- Date: 2025-12-16

-- Create promotor_events table
CREATE TABLE IF NOT EXISTS promotor_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    event_id INT NOT NULL,
    can_edit TINYINT(1) DEFAULT 1,
    can_manage_results TINYINT(1) DEFAULT 1,
    can_manage_registrations TINYINT(1) DEFAULT 1,
    can_manage_payments TINYINT(1) DEFAULT 0,
    granted_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY unique_user_event (user_id, event_id),
    INDEX idx_user_id (user_id),
    INDEX idx_event_id (event_id),

    FOREIGN KEY (user_id) REFERENCES admin_users(id) ON DELETE CASCADE,
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    FOREIGN KEY (granted_by) REFERENCES admin_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create promotor_series table for series-level assignments
CREATE TABLE IF NOT EXISTS promotor_series (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    series_id INT NOT NULL,
    can_edit TINYINT(1) DEFAULT 1,
    can_manage_results TINYINT(1) DEFAULT 1,
    can_manage_registrations TINYINT(1) DEFAULT 1,
    granted_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY unique_user_series (user_id, series_id),
    INDEX idx_user_id (user_id),
    INDEX idx_series_id (series_id),

    FOREIGN KEY (user_id) REFERENCES admin_users(id) ON DELETE CASCADE,
    FOREIGN KEY (series_id) REFERENCES series(id) ON DELETE CASCADE,
    FOREIGN KEY (granted_by) REFERENCES admin_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migration 087: Festival Activity Groups
-- Adds grouping for festival activities so they can be displayed
-- as clickable rows on the festival page with their own detail pages

-- Activity groups table
CREATE TABLE IF NOT EXISTS festival_activity_groups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    festival_id INT NOT NULL,
    name VARCHAR(200) NOT NULL,
    description TEXT,
    short_description VARCHAR(500),
    activity_type ENUM('clinic','lecture','groupride','workshop','social','other') DEFAULT 'other',
    date DATE,
    start_time TIME,
    end_time TIME,
    location_detail VARCHAR(200),
    instructor_name VARCHAR(150),
    instructor_info TEXT,
    image_media_id INT DEFAULT NULL,
    sort_order INT DEFAULT 0,
    active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (festival_id) REFERENCES festivals(id) ON DELETE CASCADE,
    INDEX idx_festival_active (festival_id, active),
    INDEX idx_festival_date (festival_id, date, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add group_id to festival_activities
ALTER TABLE festival_activities
    ADD COLUMN group_id INT DEFAULT NULL AFTER festival_id,
    ADD INDEX idx_group (group_id),
    ADD FOREIGN KEY (group_id) REFERENCES festival_activity_groups(id) ON DELETE SET NULL;

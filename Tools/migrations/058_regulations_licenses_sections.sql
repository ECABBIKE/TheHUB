-- Migration 058: Add Regelverk and Licenser sections
-- Adds two new info sections to events with global text support and multi-link capabilities

-- 1. Add section column to event_info_links (supports multiple link sections per event)
ALTER TABLE event_info_links ADD COLUMN section VARCHAR(30) NOT NULL DEFAULT 'general' AFTER event_id;

-- 2. Add Regelverk fields to events
ALTER TABLE events ADD COLUMN regulations_info TEXT NULL;
ALTER TABLE events ADD COLUMN regulations_global_type VARCHAR(20) NULL;
ALTER TABLE events ADD COLUMN regulations_hidden TINYINT NOT NULL DEFAULT 0;

-- 3. Add Licenser fields to events
ALTER TABLE events ADD COLUMN license_info TEXT NULL;
ALTER TABLE events ADD COLUMN license_use_global TINYINT NOT NULL DEFAULT 0;
ALTER TABLE events ADD COLUMN license_hidden TINYINT NOT NULL DEFAULT 0;

-- 4. Create global_text_links table (links associated with global texts)
CREATE TABLE IF NOT EXISTS global_text_links (
    id INT AUTO_INCREMENT PRIMARY KEY,
    field_key VARCHAR(100) NOT NULL,
    link_url VARCHAR(500) NOT NULL,
    link_text VARCHAR(255) NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_gtl_field_key (field_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. Seed global texts for Regelverk and Licenser
INSERT INTO global_texts (field_key, field_name, field_category, content, sort_order, is_active)
VALUES
    ('regulations_sportmotion', 'Regelverk - sportMotion', 'rules', '', 1, 1),
    ('regulations_competition', 'Regelverk - Tävling', 'rules', '', 2, 1),
    ('license_info', 'Licenser', 'rules', '', 3, 1);

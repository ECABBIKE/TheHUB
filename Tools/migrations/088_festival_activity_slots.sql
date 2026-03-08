-- Migration 088: Festival activity time slots + events in festival pass
-- Skapad: 2026-03-08
-- Syfte:
--   1. Tidspass per aktivitet (istället för att skapa kopior)
--   2. Tävlingsevent kan inkluderas i festivalpass

-- ============================================================
-- 1. Tidspass-tabell
-- ============================================================
CREATE TABLE IF NOT EXISTS festival_activity_slots (
    id INT AUTO_INCREMENT PRIMARY KEY,
    activity_id INT NOT NULL,
    date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NULL,
    max_participants INT NULL,
    sort_order INT DEFAULT 0,
    active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_activity_id (activity_id),
    INDEX idx_date (date),

    CONSTRAINT fk_slot_activity FOREIGN KEY (activity_id)
        REFERENCES festival_activities(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 2. Koppla registreringar till specifika tidspass
-- ============================================================
ALTER TABLE festival_activity_registrations
    ADD COLUMN slot_id INT NULL AFTER activity_id,
    ADD INDEX idx_slot_id (slot_id);

-- ============================================================
-- 3. Tävlingsevent kan inkluderas i festivalpass
-- ============================================================
ALTER TABLE festival_events
    ADD COLUMN included_in_pass TINYINT(1) DEFAULT 0 AFTER sort_order;

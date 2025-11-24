-- Migration: Ranking System
-- Description: Creates tables for the 24-month rolling ranking system with Enduro/Downhill/Gravity rankings
-- Date: 2025-11-24

-- Add event_level field to events table for national/sportmotion classification
ALTER TABLE events ADD COLUMN IF NOT EXISTS event_level ENUM('national', 'sportmotion') DEFAULT 'national';

-- Ranking settings table for configurable multipliers and decay
CREATE TABLE IF NOT EXISTS ranking_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(50) NOT NULL UNIQUE,
    setting_value TEXT NOT NULL,
    description VARCHAR(255),
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by INT NULL
);

-- Ranking points per event/class for each rider
CREATE TABLE IF NOT EXISTS ranking_points (
    id INT AUTO_INCREMENT PRIMARY KEY,
    rider_id INT NOT NULL,
    event_id INT NOT NULL,
    class_id INT NOT NULL,
    discipline VARCHAR(50) NOT NULL,
    original_points DECIMAL(10,2) NOT NULL DEFAULT 0,
    field_size INT NOT NULL DEFAULT 1,
    field_multiplier DECIMAL(5,4) NOT NULL DEFAULT 0.7500,
    event_level_multiplier DECIMAL(5,4) NOT NULL DEFAULT 1.0000,
    ranking_points DECIMAL(10,2) NOT NULL DEFAULT 0,
    event_date DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY unique_rider_event_class (rider_id, event_id, class_id),
    INDEX idx_rider (rider_id),
    INDEX idx_discipline (discipline),
    INDEX idx_event_date (event_date),
    INDEX idx_event_class (event_id, class_id),

    FOREIGN KEY (rider_id) REFERENCES riders(id) ON DELETE CASCADE,
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE
);

-- Monthly ranking snapshots for tracking position changes (per discipline)
CREATE TABLE IF NOT EXISTS ranking_snapshots (
    id INT AUTO_INCREMENT PRIMARY KEY,
    rider_id INT NOT NULL,
    discipline VARCHAR(50) NOT NULL,
    snapshot_date DATE NOT NULL,
    total_ranking_points DECIMAL(10,2) NOT NULL DEFAULT 0,
    points_last_12_months DECIMAL(10,2) NOT NULL DEFAULT 0,
    points_months_13_24 DECIMAL(10,2) NOT NULL DEFAULT 0,
    events_count INT NOT NULL DEFAULT 0,
    ranking_position INT NULL,
    previous_position INT NULL,
    position_change INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY unique_rider_discipline_snapshot (rider_id, discipline, snapshot_date),
    INDEX idx_snapshot_date (snapshot_date),
    INDEX idx_discipline_ranking (discipline, snapshot_date, ranking_position),

    FOREIGN KEY (rider_id) REFERENCES riders(id) ON DELETE CASCADE
);

-- Historical ranking data for trend analysis (per discipline)
CREATE TABLE IF NOT EXISTS ranking_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    rider_id INT NOT NULL,
    discipline VARCHAR(50) NOT NULL,
    month_date DATE NOT NULL,
    ranking_position INT NOT NULL,
    total_points DECIMAL(10,2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY unique_rider_discipline_month (rider_id, discipline, month_date),
    INDEX idx_rider_history (rider_id, discipline, month_date DESC),

    FOREIGN KEY (rider_id) REFERENCES riders(id) ON DELETE CASCADE
);

-- Insert default settings
INSERT INTO ranking_settings (setting_key, setting_value, description) VALUES
('field_multipliers', '{"1":0.75,"2":0.77,"3":0.79,"4":0.81,"5":0.83,"6":0.85,"7":0.86,"8":0.87,"9":0.88,"10":0.89,"11":0.90,"12":0.91,"13":0.92,"14":0.93,"15":0.94,"16":0.95,"17":0.95,"18":0.96,"19":0.96,"20":0.97,"21":0.97,"22":0.98,"23":0.98,"24":0.99,"25":0.99,"26":1.00}', 'Field size multipliers (1-26+ riders)'),
('time_decay', '{"months_1_12":1.00,"months_13_24":0.50,"months_25_plus":0.00}', 'Time decay multipliers by period'),
('event_level_multipliers', '{"national":1.00,"sportmotion":0.50}', 'Multipliers for event level (national vs sportmotion)'),
('last_calculation', '{"date":null,"riders_processed":0,"events_processed":0}', 'Last calculation run info')
ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP;

-- Migration: Restore ranking_points table
-- Description: Recreates the ranking_points table to store pre-calculated weighted points
-- Date: 2025-11-25
--
-- This table stores ranking points with all multipliers applied, allowing:
-- 1. Fast retrieval of ranking data without recalculation
-- 2. Display of calculation breakdown (original_points × field_multiplier × event_level_multiplier × time_multiplier)
-- 3. Historical tracking of how points were calculated

-- Create ranking_points table to store pre-calculated weighted points
CREATE TABLE IF NOT EXISTS ranking_points (
    id INT AUTO_INCREMENT PRIMARY KEY,
    rider_id INT NOT NULL,
    event_id INT NOT NULL,
    class_id INT NOT NULL,
    discipline VARCHAR(50) NOT NULL,

    -- Original points from results table
    original_points DECIMAL(10,2) NOT NULL DEFAULT 0,

    -- Position in the event
    position INT NULL,

    -- Field size and multiplier
    field_size INT NOT NULL DEFAULT 1,
    field_multiplier DECIMAL(5,4) NOT NULL DEFAULT 0.7500,

    -- Event level multiplier (national = 1.0, sportmotion = 0.5)
    event_level_multiplier DECIMAL(5,4) NOT NULL DEFAULT 1.0000,

    -- Time decay multiplier (1.0 for 0-12 months, 0.5 for 13-24 months)
    time_multiplier DECIMAL(5,4) NOT NULL DEFAULT 1.0000,

    -- Final calculated ranking points (original × field × event_level × time)
    ranking_points DECIMAL(10,2) NOT NULL DEFAULT 0,

    -- Event date for time-based filtering and decay calculation
    event_date DATE NOT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Ensure one record per rider/event/class combination
    UNIQUE KEY unique_rider_event_class (rider_id, event_id, class_id),

    -- Indexes for fast queries
    INDEX idx_rider_discipline (rider_id, discipline),
    INDEX idx_discipline_date (discipline, event_date DESC),
    INDEX idx_event_date (event_date DESC),
    INDEX idx_rider_date (rider_id, event_date DESC),

    -- Foreign keys
    FOREIGN KEY (rider_id) REFERENCES riders(id) ON DELETE CASCADE,
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add index for fast ranking calculations
CREATE INDEX idx_ranking_calculation ON ranking_points (discipline, event_date DESC, ranking_points DESC);

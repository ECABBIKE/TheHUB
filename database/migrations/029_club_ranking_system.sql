-- Migration: Club Ranking System
-- Description: Creates table for 24-month rolling club ranking based on aggregated rider ranking points
-- Date: 2025-11-24

-- Club ranking snapshots (per discipline)
CREATE TABLE IF NOT EXISTS club_ranking_snapshots (
    id INT AUTO_INCREMENT PRIMARY KEY,
    club_id INT NOT NULL,
    discipline VARCHAR(50) NOT NULL,
    snapshot_date DATE NOT NULL,
    total_ranking_points DECIMAL(10,2) NOT NULL DEFAULT 0,
    points_last_12_months DECIMAL(10,2) NOT NULL DEFAULT 0,
    points_months_13_24 DECIMAL(10,2) NOT NULL DEFAULT 0,
    riders_count INT NOT NULL DEFAULT 0,
    events_count INT NOT NULL DEFAULT 0,
    ranking_position INT NULL,
    previous_position INT NULL,
    position_change INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY unique_club_discipline_snapshot (club_id, discipline, snapshot_date),
    INDEX idx_snapshot_date (snapshot_date),
    INDEX idx_discipline_ranking (discipline, snapshot_date, ranking_position),

    FOREIGN KEY (club_id) REFERENCES clubs(id) ON DELETE CASCADE
);

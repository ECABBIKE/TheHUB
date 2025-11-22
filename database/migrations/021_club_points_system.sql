-- Migration 021: Club Points System
-- Creates tables for tracking club standings in series
-- Points are calculated per event, with top 2 riders per club/class scoring

-- ============================================================================
-- CLUB STANDINGS CACHE TABLE
-- Stores aggregated club points per series for fast lookup
-- ============================================================================
CREATE TABLE IF NOT EXISTS club_standings_cache (
    id INT AUTO_INCREMENT PRIMARY KEY,
    club_id INT NOT NULL,
    series_id INT NOT NULL,
    total_points DECIMAL(10, 2) DEFAULT 0,
    total_participants INT DEFAULT 0,
    events_count INT DEFAULT 0,
    best_event_points INT DEFAULT 0,
    ranking INT DEFAULT 0,
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (club_id) REFERENCES clubs(id) ON DELETE CASCADE,
    FOREIGN KEY (series_id) REFERENCES series(id) ON DELETE CASCADE,

    UNIQUE KEY unique_club_series (club_id, series_id),
    INDEX idx_series_ranking (series_id, ranking),
    INDEX idx_series_points (series_id, total_points DESC),
    INDEX idx_club (club_id),
    INDEX idx_last_updated (last_updated)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- CLUB EVENT POINTS TABLE
-- Stores per-event breakdown of club points
-- ============================================================================
CREATE TABLE IF NOT EXISTS club_event_points (
    id INT AUTO_INCREMENT PRIMARY KEY,
    club_id INT NOT NULL,
    event_id INT NOT NULL,
    series_id INT NOT NULL,
    total_points DECIMAL(10, 2) DEFAULT 0,
    participants_count INT DEFAULT 0,
    scoring_riders INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (club_id) REFERENCES clubs(id) ON DELETE CASCADE,
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    FOREIGN KEY (series_id) REFERENCES series(id) ON DELETE CASCADE,

    UNIQUE KEY unique_club_event (club_id, event_id),
    INDEX idx_event (event_id),
    INDEX idx_series (series_id),
    INDEX idx_club_series (club_id, series_id),
    INDEX idx_points (total_points DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- CLUB RIDER POINTS TABLE
-- Stores individual rider contributions to club points
-- ============================================================================
CREATE TABLE IF NOT EXISTS club_rider_points (
    id INT AUTO_INCREMENT PRIMARY KEY,
    club_id INT NOT NULL,
    event_id INT NOT NULL,
    rider_id INT NOT NULL,
    class_id INT,
    original_points INT DEFAULT 0,
    club_points INT DEFAULT 0,
    rider_rank_in_club INT DEFAULT 0,
    percentage_applied INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (club_id) REFERENCES clubs(id) ON DELETE CASCADE,
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    FOREIGN KEY (rider_id) REFERENCES riders(id) ON DELETE CASCADE,

    UNIQUE KEY unique_club_event_rider (club_id, event_id, rider_id),
    INDEX idx_event (event_id),
    INDEX idx_rider (rider_id),
    INDEX idx_class (class_id),
    INDEX idx_club_event (club_id, event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

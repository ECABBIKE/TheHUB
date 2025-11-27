-- ============================================================================
-- Migration 037: Create series_results table for independent series standings
-- Description: Separates series points from ranking points completely
-- Created: 2025-01-27
-- ============================================================================

-- Drop table if exists (for clean re-runs during development)
DROP TABLE IF EXISTS series_results;

-- Create series_results table
-- This stores calculated series points SEPARATELY from results.points (ranking)
CREATE TABLE series_results (
    id INT AUTO_INCREMENT PRIMARY KEY,

    -- Foreign keys
    series_id INT NOT NULL,
    event_id INT NOT NULL,
    cyclist_id INT NOT NULL,
    class_id INT NULL,

    -- Result data (copied from results for reference)
    position INT NULL COMMENT 'Position in class for this event',
    status ENUM('finished', 'dnf', 'dns', 'dq') DEFAULT 'finished',

    -- Series-specific points (calculated from series_events.template_id)
    points INT DEFAULT 0 COMMENT 'Points calculated using series template',

    -- Audit trail
    template_id INT NULL COMMENT 'Template used for calculation (for debugging)',
    calculated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    -- Constraints
    UNIQUE KEY unique_series_result (series_id, event_id, cyclist_id, class_id),

    -- Foreign keys
    FOREIGN KEY (series_id) REFERENCES series(id) ON DELETE CASCADE,
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    FOREIGN KEY (cyclist_id) REFERENCES riders(id) ON DELETE CASCADE,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE SET NULL,
    FOREIGN KEY (template_id) REFERENCES qualification_point_templates(id) ON DELETE SET NULL,

    -- Indexes for performance
    INDEX idx_series_cyclist (series_id, cyclist_id),
    INDEX idx_series_class (series_id, class_id),
    INDEX idx_series_event (series_id, event_id),
    INDEX idx_points (points)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Stores series-specific points, separate from ranking points';

-- ============================================================================
-- IMPORTANT: After running this migration, run the PHP script to populate
-- existing data: /admin/migrations/populate-series-results.php
-- ============================================================================

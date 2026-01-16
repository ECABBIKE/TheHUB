-- ============================================================================
-- Migration 011: Brand Dimension for Journey Analysis
-- Version: 3.1.1
-- Created: 2026-01-16
-- Fixed: 2026-01-16 - Removed DELIMITER/stored procedures for PHP compatibility
--
-- PURPOSE:
-- Adds brand filtering capability to First Season Journey and Longitudinal
-- Journey analysis. Enables comparison across up to 12 brands.
--
-- BACKGROUND:
-- - brands table already exists
-- - brand_series_map links series_id to brand_id
-- - Riders can participate in multiple brands over time
--
-- NOTE: Stored procedure sp_populate_brand_from_series removed
--       Brand population logic is now in KPICalculator.php
--
-- CHANGES:
-- - rider_first_season: +first_brand_id
-- - rider_journey_years: +primary_brand_id
-- - cohort_longitudinal_aggregates: uses segment_type='brand'
-- ============================================================================

-- ============================================================================
-- ALTER: rider_first_season - Add brand tracking
-- Wrapped in procedure to handle "column exists" gracefully
-- ============================================================================

-- Check if column exists before adding (MySQL doesn't support IF NOT EXISTS for columns)
-- Using a simple approach: the ALTER will fail silently if column already exists
SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'rider_first_season'
     AND COLUMN_NAME = 'first_brand_id') = 0,
    'ALTER TABLE rider_first_season ADD COLUMN first_brand_id INT NULL AFTER club_id',
    'SELECT 1'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'rider_first_season'
     AND COLUMN_NAME = 'first_series_id') = 0,
    'ALTER TABLE rider_first_season ADD COLUMN first_series_id INT NULL AFTER first_brand_id',
    'SELECT 1'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add indexes (ignore if exists)
SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'rider_first_season'
     AND INDEX_NAME = 'idx_first_brand') = 0,
    'ALTER TABLE rider_first_season ADD INDEX idx_first_brand (first_brand_id)',
    'SELECT 1'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'rider_first_season'
     AND INDEX_NAME = 'idx_cohort_brand') = 0,
    'ALTER TABLE rider_first_season ADD INDEX idx_cohort_brand (cohort_year, first_brand_id)',
    'SELECT 1'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================================
-- ALTER: rider_journey_years - Add brand tracking per year
-- ============================================================================
SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'rider_journey_years'
     AND COLUMN_NAME = 'primary_brand_id') = 0,
    'ALTER TABLE rider_journey_years ADD COLUMN primary_brand_id INT NULL AFTER primary_discipline',
    'SELECT 1'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'rider_journey_years'
     AND COLUMN_NAME = 'primary_series_id') = 0,
    'ALTER TABLE rider_journey_years ADD COLUMN primary_series_id INT NULL AFTER primary_brand_id',
    'SELECT 1'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'rider_journey_years'
     AND INDEX_NAME = 'idx_brand') = 0,
    'ALTER TABLE rider_journey_years ADD INDEX idx_brand (primary_brand_id)',
    'SELECT 1'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'rider_journey_years'
     AND INDEX_NAME = 'idx_cohort_offset_brand') = 0,
    'ALTER TABLE rider_journey_years ADD INDEX idx_cohort_offset_brand (cohort_year, year_offset, primary_brand_id)',
    'SELECT 1'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================================
-- ALTER: rider_journey_summary - Add first brand reference
-- ============================================================================
SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'rider_journey_summary'
     AND COLUMN_NAME = 'fs_first_brand_id') = 0,
    'ALTER TABLE rider_journey_summary ADD COLUMN fs_first_brand_id INT NULL AFTER fs_activity_pattern',
    'SELECT 1'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================================
-- VIEW: brand_journey_comparison
-- Pre-aggregated view for brand comparison (respects GDPR min 10)
-- ============================================================================
CREATE OR REPLACE VIEW brand_journey_comparison AS
SELECT
    b.id AS brand_id,
    b.name AS brand_name,
    b.short_code,
    b.color_primary,
    rfs.cohort_year,
    COUNT(*) AS rookie_count,
    AVG(rfs.total_starts) AS avg_starts,
    AVG(rfs.total_events) AS avg_events,
    AVG(rfs.result_percentile) AS avg_percentile,
    AVG(rfs.engagement_score) AS avg_engagement,
    AVG(rfs.returned_year2) AS return_rate_y2,
    AVG(rfs.returned_year3) AS return_rate_y3,
    AVG(rfs.total_career_seasons) AS avg_career_seasons,
    CASE WHEN COUNT(*) >= 10 THEN 1 ELSE 0 END AS is_displayable
FROM rider_first_season rfs
JOIN brands b ON rfs.first_brand_id = b.id
GROUP BY b.id, b.name, b.short_code, b.color_primary, rfs.cohort_year
ORDER BY rfs.cohort_year DESC, rookie_count DESC;

-- ============================================================================
-- VIEW: brand_retention_funnel
-- Retention funnel per brand (Years 1-4)
-- ============================================================================
CREATE OR REPLACE VIEW brand_retention_funnel AS
SELECT
    b.id AS brand_id,
    b.name AS brand_name,
    b.short_code,
    b.color_primary,
    rjy.cohort_year,
    rjy.year_offset,
    COUNT(*) AS total_in_cohort,
    SUM(rjy.was_active) AS active_count,
    SUM(rjy.was_active) / COUNT(*) AS retention_rate,
    AVG(CASE WHEN rjy.was_active = 1 THEN rjy.total_starts END) AS avg_starts,
    AVG(CASE WHEN rjy.was_active = 1 THEN rjy.result_percentile END) AS avg_percentile,
    CASE WHEN COUNT(*) >= 10 THEN 1 ELSE 0 END AS is_displayable
FROM rider_journey_years rjy
JOIN rider_first_season rfs ON rjy.rider_id = rfs.rider_id AND rjy.cohort_year = rfs.cohort_year
JOIN brands b ON rfs.first_brand_id = b.id
GROUP BY b.id, b.name, b.short_code, b.color_primary, rjy.cohort_year, rjy.year_offset
ORDER BY rjy.cohort_year DESC, b.name, rjy.year_offset;

-- ============================================================================
-- VIEW: journey_pattern_by_brand
-- Journey pattern distribution per brand
-- ============================================================================
CREATE OR REPLACE VIEW journey_pattern_by_brand AS
SELECT
    b.id AS brand_id,
    b.name AS brand_name,
    b.short_code,
    rjs.cohort_year,
    rjs.journey_pattern,
    COUNT(*) AS rider_count,
    COUNT(*) * 100.0 / SUM(COUNT(*)) OVER (PARTITION BY b.id, rjs.cohort_year) AS percentage,
    CASE WHEN COUNT(*) >= 10 THEN 1 ELSE 0 END AS is_displayable
FROM rider_journey_summary rjs
JOIN rider_first_season rfs ON rjs.rider_id = rfs.rider_id AND rjs.cohort_year = rfs.cohort_year
JOIN brands b ON rfs.first_brand_id = b.id
GROUP BY b.id, b.name, b.short_code, rjs.cohort_year, rjs.journey_pattern
HAVING COUNT(*) >= 10
ORDER BY rjs.cohort_year DESC, b.name, rider_count DESC;

-- ============================================================================
-- TABLE: brand_journey_aggregates
-- Pre-calculated brand-level aggregates for fast reporting
-- ============================================================================
CREATE TABLE IF NOT EXISTS brand_journey_aggregates (
    id INT AUTO_INCREMENT PRIMARY KEY,

    brand_id INT NOT NULL,
    cohort_year YEAR NOT NULL,
    year_offset TINYINT NOT NULL,              -- 1-4

    -- Population
    total_riders INT NOT NULL,
    active_riders INT NOT NULL,
    retention_rate DECIMAL(5,4) DEFAULT NULL,

    -- Activity
    avg_starts DECIMAL(6,2) DEFAULT NULL,
    avg_events DECIMAL(6,2) DEFAULT NULL,
    avg_dnf_rate DECIMAL(5,4) DEFAULT NULL,

    -- Results
    avg_percentile DECIMAL(5,2) DEFAULT NULL,
    pct_with_podium DECIMAL(5,4) DEFAULT NULL,

    -- Journey Patterns (only for year_offset=4 or max available)
    pct_continuous_4yr DECIMAL(5,4) DEFAULT NULL,
    pct_one_and_done DECIMAL(5,4) DEFAULT NULL,
    pct_gap_returner DECIMAL(5,4) DEFAULT NULL,

    -- Cross-brand
    pct_multi_brand DECIMAL(5,4) DEFAULT NULL,  -- Riders in 2+ brands

    -- Metadata
    calculated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    snapshot_id INT DEFAULT NULL,

    INDEX idx_brand_cohort (brand_id, cohort_year),
    INDEX idx_cohort_offset (cohort_year, year_offset),
    INDEX idx_snapshot (snapshot_id),

    UNIQUE KEY uk_brand_cohort_offset (brand_id, cohort_year, year_offset, snapshot_id),

    FOREIGN KEY (brand_id) REFERENCES brands(id) ON DELETE CASCADE,
    FOREIGN KEY (snapshot_id) REFERENCES analytics_snapshots(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- Migration complete marker
-- ============================================================================
INSERT INTO analytics_system_config (config_key, config_value, description)
VALUES
    ('journey_brand_dimension_version', '3.1.1', 'Journey brand dimension version'),
    ('journey_max_brand_comparison', '12', 'Maximum brands for comparison'),
    ('journey_brand_enabled', 'true', 'Enable brand filtering for journey analysis')
ON DUPLICATE KEY UPDATE config_value = VALUES(config_value);

-- Record migration
INSERT INTO analytics_kpi_audit (kpi_key, old_definition, new_definition, change_type, changed_by, rationale)
VALUES ('_migration_011', NULL, 'Brand dimension for Journey Analysis', 'migration', 'system', 'Migration 011 completed')
ON DUPLICATE KEY UPDATE changed_at = NOW();

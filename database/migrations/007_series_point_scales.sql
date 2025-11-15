-- ============================================================================
-- Migration: Add Point Scale support to Series
-- Description: Link series to point scales and add scoring flexibility
-- Created: 2025-01-14
-- ============================================================================

-- Add point scale to series
ALTER TABLE `series`
ADD COLUMN `point_scale_id` INT DEFAULT NULL AFTER `discipline`,
ADD COLUMN `count_best_results` INT DEFAULT NULL COMMENT 'Number of best results to count (NULL = all)',
ADD FOREIGN KEY (`point_scale_id`) REFERENCES `point_scales`(`id`) ON DELETE SET NULL;

-- Index for performance
CREATE INDEX `idx_series_point_scale` ON `series`(`point_scale_id`);

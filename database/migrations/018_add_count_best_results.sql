-- ============================================================================
-- Migration: Add count_best_results to series
-- Description: Simple migration to add count_best_results without dependencies
-- Created: 2025-11-22
-- ============================================================================

-- Add count_best_results column to series table
ALTER TABLE `series`
ADD COLUMN `count_best_results` INT DEFAULT NULL COMMENT 'Number of best results to count (NULL = all)';
